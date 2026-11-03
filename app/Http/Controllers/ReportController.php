<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    private array $reports = [
        'daily-sales'=>'Daily Sales','shift-report'=>'Shift Report','nozzle-sales'=>'Nozzle-wise Sales','operator-performance'=>'Operator Performance',
        'payment-collection'=>'Payment Collection','expense-report'=>'Expense Report','profit-loss'=>'Profit and Loss','rate-history'=>'Rate History',
        'invoice-register'=>'Invoice Register','customer-outstanding'=>'Customer Outstanding','cash-short-excess'=>'Cash Short/Excess','audit-log'=>'Audit Log',
    ];

    private function scope(Request $request)
    {
        abort_unless($request->user()->hasAnyPermission(array_map(fn($key)=>$key==='audit-log'?'reports.audit-log':"reports.{$key}",array_keys($this->reports))),403);
        return $request->user()->role==='Super Admin'
            ? DB::table('stations')->where('active',true)->pluck('id')
            : $request->user()->stations()->where('stations.active',true)->pluck('stations.id');
    }

    private function query(string $report, Request $request, $stationIds)
    {
        abort_unless(isset($this->reports[$report]),404);
        abort_unless($request->user()->hasPermission($report==='audit-log'?'reports.audit-log':"reports.{$report}"),403);
        $from=$request->input('from',now()->startOfMonth()->toDateString());
        $to=$request->input('to',now()->toDateString());
        $station=$request->integer('station_id');
        if($station) abort_unless($stationIds->contains($station),403);
        $ids=$station?$stationIds->filter(fn($id)=>(int)$id===$station):$stationIds;
        $salesRows=DB::table('shifts')->where('status','<>','Voided')->whereIn('station_id',$ids)->selectRaw('station_id, shift_date as report_date, SUM(sales_total) as sales, 0 as expenses')->groupBy('station_id','shift_date');
        $expenseRows=DB::table('expenses')->whereNull('deleted_at')->whereIn('station_id',$ids)->selectRaw('station_id, expense_date as report_date, 0 as sales, SUM(amount) as expenses')->groupBy('station_id','expense_date');
        $profitRows=$salesRows->unionAll($expenseRows);
        $q=match($report){
            'daily-sales'=>DB::table('shifts')->join('stations','stations.id','=','shifts.station_id')->where('shifts.status','<>','Voided')->whereIn('shifts.station_id',$ids)->selectRaw("shifts.shift_date as date, stations.name as station, COUNT(*) as shifts, SUM(shifts.sales_total) as sales, SUM(shifts.cash_collected + shifts.upi_collected + shifts.bank_collected + shifts.card_collected) as collected, SUM(shifts.credit_sales) as credit, SUM(shifts.short_excess) as short_excess")->groupBy('shifts.shift_date','stations.name')->orderByDesc('shifts.shift_date'),
            'shift-report'=>DB::table('shifts')->join('stations','stations.id','=','shifts.station_id')->join('shift_types','shift_types.id','=','shifts.shift_type_id')->join('users','users.id','=','shifts.operator_id')->where('shifts.status','<>','Voided')->whereIn('shifts.station_id',$ids)->select('shifts.shift_date as date','stations.name as station','shift_types.name as shift','users.name as operator','shifts.status','shifts.sales_total as sales','shifts.cash_collected','shifts.upi_collected','shifts.bank_collected','shifts.card_collected','shifts.credit_sales','shifts.short_excess')->orderByDesc('shifts.shift_date'),
            'nozzle-sales'=>DB::table('shift_readings')->join('shifts','shifts.id','=','shift_readings.shift_id')->join('stations','stations.id','=','shifts.station_id')->join('nozzles','nozzles.id','=','shift_readings.nozzle_id')->join('fuel_types','fuel_types.id','=','shift_readings.fuel_type_id')->where('shifts.status','<>','Voided')->whereIn('shifts.station_id',$ids)->select('shifts.shift_date as date','stations.name as station','nozzles.name as nozzle','fuel_types.name as fuel','fuel_types.unit','shift_readings.quantity','shift_readings.rate','shift_readings.amount as sales')->orderByDesc('shifts.shift_date'),
            'operator-performance'=>DB::table('shifts')->join('stations','stations.id','=','shifts.station_id')->join('users','users.id','=','shifts.operator_id')->where('shifts.status','<>','Voided')->whereIn('shifts.station_id',$ids)->selectRaw("users.name as operator, stations.name as station, COUNT(*) as shifts, SUM(shifts.sales_total) as sales, SUM(shifts.short_excess) as short_excess")->groupBy('users.name','stations.name')->orderByRaw('SUM(shifts.sales_total) DESC'),
            'payment-collection'=>DB::table('payments')->join('stations','stations.id','=','payments.station_id')->whereNull('payments.voided_at')->whereIn('payments.station_id',$ids)->select('payments.payment_date as date','stations.name as station','payments.mode','payments.reference','payments.amount')->orderByDesc('payments.payment_date'),
            'expense-report'=>DB::table('expenses')->join('stations','stations.id','=','expenses.station_id')->join('expense_categories','expense_categories.id','=','expenses.category_id')->whereNull('expenses.deleted_at')->whereIn('expenses.station_id',$ids)->select('expenses.expense_date as date','stations.name as station','expense_categories.name as category','expenses.payment_mode','expenses.amount','expenses.remarks')->orderByDesc('expenses.expense_date'),
            'profit-loss'=>DB::query()->fromSub($profitRows,'daily_rows')->join('stations','stations.id','=','daily_rows.station_id')->selectRaw('daily_rows.report_date as date, stations.name as station, SUM(daily_rows.sales) as sales, SUM(daily_rows.expenses) as expenses, SUM(daily_rows.sales-daily_rows.expenses) as net')->groupBy('daily_rows.report_date','stations.name')->orderByDesc('daily_rows.report_date'),
            'rate-history'=>DB::table('daily_rates')->join('stations','stations.id','=','daily_rates.station_id')->join('fuel_types','fuel_types.id','=','daily_rates.fuel_type_id')->join('users','users.id','=','daily_rates.updated_by')->whereIn('daily_rates.station_id',$ids)->select('daily_rates.rate_date as date','stations.name as station','fuel_types.name as fuel','fuel_types.unit','daily_rates.rate','users.name as updated_by')->orderByDesc('daily_rates.rate_date'),
            'invoice-register'=>DB::table('invoices')->join('stations','stations.id','=','invoices.station_id')->leftJoin('customers','customers.id','=','invoices.customer_id')->whereIn('invoices.station_id',$ids)->select('invoices.invoice_date as date','stations.name as station','invoices.invoice_number','customers.name as customer','invoices.grand_total as total','invoices.paid_amount as paid','invoices.payment_mode')->orderByDesc('invoices.invoice_date'),
            'customer-outstanding'=>DB::table('customers')->leftJoin('customer_ledgers','customers.id','=','customer_ledgers.customer_id')->join('stations','stations.id','=','customers.station_id')->whereIn('customers.station_id',$ids)->where('customers.active',true)->groupBy('customers.id','stations.name')->select('stations.name as station','customers.name as customer','customers.mobile','customers.credit_limit',DB::raw('COALESCE(SUM(customer_ledgers.debit-customer_ledgers.credit),customers.opening_balance) as outstanding'))->orderByDesc('outstanding'),
            'cash-short-excess'=>DB::table('shifts')->join('stations','stations.id','=','shifts.station_id')->join('users','users.id','=','shifts.operator_id')->where('shifts.status','<>','Voided')->whereIn('shifts.station_id',$ids)->select('shifts.shift_date as date','stations.name as station','users.name as operator','shifts.sales_total as sales','shifts.cash_collected','shifts.upi_collected','shifts.bank_collected','shifts.card_collected','shifts.credit_sales','shifts.short_excess','shifts.status')->orderByDesc('shifts.shift_date'),
            'audit-log'=>DB::table('audit_logs')->leftJoin('stations','stations.id','=','audit_logs.station_id')->leftJoin('users','users.id','=','audit_logs.user_id')->where(function($w)use($ids,$request){$w->whereIn('audit_logs.station_id',$ids);if($request->user()->role==='Super Admin')$w->orWhereNull('audit_logs.station_id');})->select('audit_logs.created_at as date','stations.name as station','users.name as user','audit_logs.action','audit_logs.entity_type','audit_logs.entity_id','audit_logs.details')->orderByDesc('audit_logs.created_at'),
        };
        if($report!=='customer-outstanding'){
            $dateColumn=match($report){'payment-collection'=>'payments.payment_date','expense-report'=>'expenses.expense_date','rate-history'=>'daily_rates.rate_date','invoice-register'=>'invoices.invoice_date','audit-log'=>'audit_logs.created_at','profit-loss'=>'daily_rows.report_date',default=>'shifts.shift_date'};
            if($from) $q->whereDate($dateColumn,'>=',$from);
            if($to) $q->whereDate($dateColumn,'<=',$to);
        }
        if($report==='nozzle-sales' && $request->integer('fuel_id')) $q->where('fuel_types.id',$request->integer('fuel_id'));
        return $q;
    }

    public function index(Request $request)
    {
        $ids=$this->scope($request);$stations=DB::table('stations')->whereIn('id',$ids)->orderBy('name')->get();$report=$request->input('report');$rows=collect();$title=null;
        $availableReports=array_filter($this->reports,fn($label,$key)=>$request->user()->hasPermission($key==='audit-log'?'reports.audit-log':"reports.{$key}"),ARRAY_FILTER_USE_BOTH);
        if($report){$title=$this->reports[$report]??abort(404);$query=$this->query($report,$request,$ids);if($request->ajax())return DataTables::query($query)->toJson();$rows=$query->paginate(25)->withQueryString();}
        $fuels=DB::table('fuel_types')->where('active',true)->orderBy('name')->get();
        return view('reports.index',compact('stations','fuels','report','rows','title','availableReports'));
    }

    public function export(Request $request,string $report): StreamedResponse
    {
        $ids=$this->scope($request);$rows=$this->query($report,$request,$ids)->get();$filename=$report.'-'.$request->input('from',now()->toDateString()).'.csv';
        return response()->streamDownload(function()use($rows){$out=fopen('php://output','w');fputcsv($out,$rows->isEmpty()?[]:array_keys((array)$rows->first()));foreach($rows as $row)fputcsv($out,array_values((array)$row));fclose($out);},$filename,['Content-Type'=>'text/csv; charset=UTF-8']);
    }
}
