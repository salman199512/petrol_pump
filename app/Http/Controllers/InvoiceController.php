<?php
namespace App\Http\Controllers;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class InvoiceController extends Controller
{
    private function stationIds(Request $request)
    {
        return $request->user()->role==='Super Admin' ? DB::table('stations')->where('active',true)->pluck('id') : $request->user()->stations()->where('stations.active',true)->pluck('stations.id');
    }
    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('invoices.view'),403);
        $invoices=DB::table('invoices')->leftJoin('customers','customers.id','=','invoices.customer_id')->join('stations','stations.id','=','invoices.station_id')->whereIn('invoices.station_id',$this->stationIds($request))->orderByDesc('invoice_date')->select('invoices.*','customers.name as customer_name','stations.name as station_name')->paginate(20);
        return view('invoices.index',compact('invoices'));
    }
    public function create(Request $request)
    {
        abort_unless($request->user()->hasPermission('invoices.manage'),403); $ids=$this->stationIds($request);
        return view('invoices.create',['stations'=>DB::table('stations')->whereIn('id',$ids)->get(),'fuels'=>DB::table('fuel_types')->where('active',true)->get(),'customers'=>DB::table('customers')->whereIn('station_id',$ids)->where('active',true)->get()]);
    }
    public function store(Request $request)
    {
        abort_unless($request->user()->hasPermission('invoices.manage'),403);
        $data=$request->validate(['station_id'=>'required|integer','customer_id'=>'nullable|integer','invoice_date'=>'required|date','vehicle_number'=>'nullable|string|max:30','payment_mode'=>'required|in:Cash,UPI,Bank,Card,Credit','paid_amount'=>'required|numeric|min:0','items'=>'required|array|min:1','items.*.fuel_type_id'=>'required|exists:fuel_types,id','items.*.quantity'=>'required|numeric|gt:0','items.*.rate'=>'required|numeric|min:0','items.*.tax_rate'=>'required|numeric|min:0|max:100']);
        abort_unless($this->stationIds($request)->contains((int)$data['station_id']),403);
        if($data['customer_id']) abort_unless(DB::table('customers')->where('id',$data['customer_id'])->where('station_id',$data['station_id'])->exists(),403);
        if($data['payment_mode']==='Credit' && !$data['customer_id']) throw ValidationException::withMessages(['customer_id'=>'Select a customer for credit sales.']);
        $id=DB::transaction(function()use($request,$data){
            DB::table('stations')->where('id',$data['station_id'])->lockForUpdate()->first();
            $items=[]; $subtotal=BigDecimal::zero(); $taxTotal=BigDecimal::zero();
            foreach($data['items'] as $item){$base=BigDecimal::of($item['quantity'])->multipliedBy((string)$item['rate'])->toScale(2,RoundingMode::HALF_UP); $tax=$base->multipliedBy((string)$item['tax_rate'])->dividedBy('100',2,RoundingMode::HALF_UP); $subtotal=$subtotal->plus($base); $taxTotal=$taxTotal->plus($tax); $items[]=[...$item,'base'=>$base,'tax'=>$tax,'total'=>$base->plus($tax)];}
            $grand=$subtotal->plus($taxTotal)->toScale(2,RoundingMode::HALF_UP); $paid=BigDecimal::of($data['paid_amount'])->toScale(2,RoundingMode::HALF_UP);
            if($paid->isGreaterThan($grand)) throw ValidationException::withMessages(['paid_amount'=>'Paid amount cannot exceed the invoice total.']);
            if($data['payment_mode']==='Credit' && $paid->isGreaterThan(0)) throw ValidationException::withMessages(['paid_amount'=>'Credit invoices cannot record a payment in the credit payment mode.']);
            if($data['customer_id'] && $grand->isGreaterThan($paid)){ $customer=DB::table('customers')->where('id',$data['customer_id'])->lockForUpdate()->first(); $ledger=DB::table('customer_ledgers')->where('customer_id',$customer->id)->selectRaw('COALESCE(SUM(debit-credit),0) as balance, COUNT(*) as entries')->first(); $balance=BigDecimal::of($ledger->entries?$ledger->balance:$customer->opening_balance); if($balance->plus($grand->minus($paid))->isGreaterThan($customer->credit_limit)) throw ValidationException::withMessages(['customer_id'=>'This invoice would exceed the customer credit limit.']); }
            $count=DB::table('invoices')->where('station_id',$data['station_id'])->whereYear('invoice_date',substr($data['invoice_date'],0,4))->count()+1; $number='INV-'.$data['station_id'].'-'.date('Y',strtotime($data['invoice_date'])).'-'.str_pad((string)$count,5,'0',STR_PAD_LEFT);
            $invoiceId=DB::table('invoices')->insertGetId(['station_id'=>$data['station_id'],'customer_id'=>$data['customer_id']??null,'created_by'=>$request->user()->id,'invoice_number'=>$number,'invoice_date'=>$data['invoice_date'],'vehicle_number'=>$data['vehicle_number']??null,'payment_mode'=>$data['payment_mode'],'subtotal'=>(string)$subtotal->toScale(2,RoundingMode::HALF_UP),'tax_total'=>(string)$taxTotal->toScale(2,RoundingMode::HALF_UP),'grand_total'=>(string)$grand,'paid_amount'=>(string)$paid,'status'=>'Issued','created_at'=>now(),'updated_at'=>now()]);
            foreach($items as $item) DB::table('invoice_items')->insert(['invoice_id'=>$invoiceId,'fuel_type_id'=>$item['fuel_type_id'],'quantity'=>$item['quantity'],'rate'=>$item['rate'],'tax_rate'=>$item['tax_rate'],'tax_amount'=>(string)$item['tax'],'amount'=>(string)$item['total'],'created_at'=>now(),'updated_at'=>now()]);
            if($data['customer_id']){DB::table('customer_ledgers')->insert(['customer_id'=>$data['customer_id'],'entry_date'=>$data['invoice_date'],'reference'=>$number,'debit'=>(string)$grand,'credit'=>'0.00','created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]); if($paid->isGreaterThan(0)){DB::table('customer_ledgers')->insert(['customer_id'=>$data['customer_id'],'entry_date'=>$data['invoice_date'],'reference'=>$number.' payment','debit'=>'0.00','credit'=>(string)$paid,'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);}}
            if($paid->isGreaterThan(0)) DB::table('payments')->insert(['station_id'=>$data['station_id'],'customer_id'=>$data['customer_id']??null,'created_by'=>$request->user()->id,'payment_date'=>$data['invoice_date'],'mode'=>$data['payment_mode'],'amount'=>(string)$paid,'reference'=>$number,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('audit_logs')->insert(['user_id'=>$request->user()->id,'station_id'=>$data['station_id'],'action'=>'invoice_issued','entity_type'=>'invoice','entity_id'=>$invoiceId,'details'=>json_encode(['invoice_number'=>$number,'total'=>(string)$grand]),'created_at'=>now(),'updated_at'=>now()]);
            return $invoiceId;
        });
        return redirect()->route('invoices.show',$id)->with('success','Invoice saved. Use Print / Save as PDF to generate a copy.');
    }
    public function show(Request $request,int $invoice)
    {
        abort_unless($request->user()->hasPermission('invoices.view'),403);
        $ids=$this->stationIds($request); $record=DB::table('invoices')->join('stations','stations.id','=','invoices.station_id')->leftJoin('customers','customers.id','=','invoices.customer_id')->where('invoices.id',$invoice)->whereIn('invoices.station_id',$ids)->select('invoices.*','stations.name as station_name','stations.address as station_address','stations.gst_number as station_gst','stations.mobile as station_mobile','customers.name as customer_name','customers.mobile as customer_mobile','customers.address as customer_address','customers.gst_number as customer_gst')->first(); abort_unless($record,404);
        $items=DB::table('invoice_items')->join('fuel_types','fuel_types.id','=','invoice_items.fuel_type_id')->where('invoice_id',$invoice)->select('invoice_items.*','fuel_types.name as fuel_name','fuel_types.unit')->get();
        return view('invoices.show',compact('record','items'));
    }
    public function void(Request $request,int $invoice)
    {
        abort_unless($request->user()->hasPermission('invoices.manage'),403);
        $data=$request->validate(['void_reason'=>'required|string|max:500']);$ids=$this->stationIds($request);
        DB::transaction(function()use($request,$invoice,$ids,$data){$record=DB::table('invoices')->where('id',$invoice)->whereIn('station_id',$ids)->lockForUpdate()->first();abort_unless($record,404);if($record->status!=='Issued')throw ValidationException::withMessages(['invoice'=>'Only issued invoices can be voided.']);DB::table('invoices')->where('id',$invoice)->update(['status'=>'Voided','voided_at'=>now(),'void_reason'=>$data['void_reason'],'updated_at'=>now()]);if($record->customer_id){DB::table('customer_ledgers')->insert(['customer_id'=>$record->customer_id,'entry_date'=>now()->toDateString(),'reference'=>$record->invoice_number.' · void reversal','debit'=>'0.00','credit'=>$record->grand_total,'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);if((float)$record->paid_amount>0)DB::table('customer_ledgers')->insert(['customer_id'=>$record->customer_id,'entry_date'=>now()->toDateString(),'reference'=>$record->invoice_number.' · payment reversal','debit'=>$record->paid_amount,'credit'=>'0.00','created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);}DB::table('payments')->where('station_id',$record->station_id)->where('reference',$record->invoice_number)->whereNull('voided_at')->update(['voided_at'=>now(),'void_reason'=>$data['void_reason'],'updated_at'=>now()]);DB::table('audit_logs')->insert(['user_id'=>$request->user()->id,'station_id'=>$record->station_id,'action'=>'invoice_voided','entity_type'=>'invoice','entity_id'=>$invoice,'details'=>json_encode(['reason'=>$data['void_reason'],'invoice_number'=>$record->invoice_number]),'created_at'=>now(),'updated_at'=>now()]);});
        return back()->with('success','Invoice voided with an audit trail and ledger reversal.');
    }
}
