<?php
namespace App\Http\Controllers;
use App\Models\Shift;
use App\Models\Station;
use App\Models\ShiftType;
use App\Models\Nozzle;
use App\Models\User;
use App\Models\ShiftReading;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\Rule;
class ShiftController extends Controller
{
    private function mayManageShifts(Request $request): bool { return $request->user()->hasPermission('shifts.manage'); }
    private function stationsFor(Request $request){return Station::where('active',true)->when($request->user()->role!=='Super Admin',fn($q)=>$q->whereIn('id',$request->user()->stations()->select('stations.id')))->orderBy('name')->get();}

    public function create(Request $request)
    {
        abort_unless($this->mayManageShifts($request),403);
        $stations=$this->stationsFor($request);$stationId=$request->integer('station_id') ?: $stations->first()?->id;abort_unless(!$stationId||$stations->contains('id',$stationId),403);
        $date=$request->date('shift_date')?->toDateString()??now()->toDateString();$types=collect();$operators=collect();$nozzles=collect();
        if($stationId){$types=ShiftType::where('station_id',$stationId)->where('active',true)->orderBy('starts_at')->get();$operators=User::where('active',true)->whereHas('roleRecord.permissions',fn($q)=>$q->where('permissions.slug','shifts.close'))->whereHas('stations',fn($q)=>$q->where('stations.id',$stationId))->orderBy('name')->get();
            $busy=DB::table('shift_readings')->join('shifts','shifts.id','=','shift_readings.shift_id')->where('shifts.station_id',$stationId)->where('shifts.status','Open')->whereDate('shifts.shift_date',$date)->pluck('shift_readings.nozzle_id')->all();
            $nozzles=Nozzle::with('fuelType')->where('station_id',$stationId)->where('active',true)->orderBy('name')->get()->map(function($nozzle)use($stationId,$date,$busy){$nozzle->already_open=in_array($nozzle->id,$busy);$nozzle->daily_rate=DB::table('daily_rates')->where('station_id',$stationId)->where('fuel_type_id',$nozzle->fuel_type_id)->where('rate_date',$date)->value('rate');$nozzle->opening_suggestion=DB::table('shift_readings')->join('shifts','shifts.id','=','shift_readings.shift_id')->where('shifts.station_id',$stationId)->where('shift_readings.nozzle_id',$nozzle->id)->whereNotNull('shift_readings.closing_reading')->whereDate('shifts.shift_date','<=',$date)->orderByDesc('shifts.shift_date')->orderByDesc('shift_readings.id')->value('shift_readings.closing_reading')??'0.000';return $nozzle;});
        }
        return view('shifts.create',compact('stations','stationId','date','types','operators','nozzles'));
    }

    public function store(Request $request)
    {
        abort_unless($this->mayManageShifts($request),403);
        $data=$request->validate(['station_id'=>'required|integer|exists:stations,id','shift_type_id'=>'required|integer|exists:shift_types,id','operator_id'=>'required|integer|exists:users,id','shift_date'=>'required|date','nozzle_ids'=>'required|array|min:1','nozzle_ids.*'=>'required|integer|distinct|exists:nozzles,id','openings'=>'sometimes|array','openings.*'=>'nullable|numeric|min:0|max:999999999.999']);
        $station=Station::where('active',true)->findOrFail($data['station_id']);$user=$request->user();abort_unless($user->role==='Super Admin'||$user->stations()->where('stations.id',$station->id)->exists(),403);
        abort_unless(ShiftType::where('id',$data['shift_type_id'])->where('station_id',$station->id)->where('active',true)->exists(),422);
        abort_unless(User::where('id',$data['operator_id'])->where('active',true)->whereHas('roleRecord.permissions',fn($q)=>$q->where('permissions.slug','shifts.close'))->whereHas('stations',fn($q)=>$q->where('stations.id',$station->id))->exists(),422);
        DB::transaction(function()use($request,$data,$station){
            $nozzles=Nozzle::where('station_id',$station->id)->where('active',true)->whereIn('id',$data['nozzle_ids'])->orderBy('id')->lockForUpdate()->get();
            if($nozzles->count()!==count($data['nozzle_ids']))throw \Illuminate\Validation\ValidationException::withMessages(['nozzle_ids'=>'Choose active nozzles assigned to this station.']);
            foreach($nozzles as $nozzle)if(!isset($data['openings'][$nozzle->id])||$data['openings'][$nozzle->id]==='')throw \Illuminate\Validation\ValidationException::withMessages(['openings.'.$nozzle->id=>'Enter an opening reading for every selected nozzle.']);
            $busy=DB::table('shift_readings')->join('shifts','shifts.id','=','shift_readings.shift_id')->where('shifts.station_id',$station->id)->whereDate('shifts.shift_date',$data['shift_date'])->where('shifts.status','Open')->whereIn('shift_readings.nozzle_id',$nozzles->pluck('id'))->exists();
            if($busy)throw \Illuminate\Validation\ValidationException::withMessages(['nozzle_ids'=>'One or more selected nozzles already have an open shift for this date.']);
            $rates=DB::table('daily_rates')->where('station_id',$station->id)->where('rate_date',$data['shift_date'])->whereIn('fuel_type_id',$nozzles->pluck('fuel_type_id'))->pluck('rate','fuel_type_id');
            foreach($nozzles as $nozzle)if(!$rates->has($nozzle->fuel_type_id))throw \Illuminate\Validation\ValidationException::withMessages(['nozzle_ids'=>"Set the {$nozzle->fuelType->name} daily rate for ".\Illuminate\Support\Carbon::parse($data['shift_date'])->format('d M Y').'.']);
            $shift=Shift::create(['station_id'=>$station->id,'shift_type_id'=>$data['shift_type_id'],'operator_id'=>$data['operator_id'],'shift_date'=>$data['shift_date'],'status'=>'Open']);
            foreach($nozzles as $nozzle)ShiftReading::create(['shift_id'=>$shift->id,'nozzle_id'=>$nozzle->id,'fuel_type_id'=>$nozzle->fuel_type_id,'opening_reading'=>$data['openings'][$nozzle->id],'rate'=>$rates[$nozzle->fuel_type_id],'quantity'=>0,'amount'=>0]);
            DB::table('audit_logs')->insert(['user_id'=>$request->user()->id,'station_id'=>$station->id,'action'=>'shift_opened','entity_type'=>'shift','entity_id'=>$shift->id,'details'=>json_encode(['operator_id'=>$data['operator_id'],'nozzles'=>$nozzles->pluck('id')]),'created_at'=>now(),'updated_at'=>now()]);
        });
        return redirect()->route('shifts.index')->with('success','Shift opened with the selected nozzle readings.');
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('shifts.view'),403);
        $stations=$this->stationsFor($request);
        $stationId=$request->integer('station_id') ?: $stations->first()?->id;
        abort_unless(!$stationId || $stations->contains('id',$stationId),403);
        $canManage=$this->mayManageShifts($request);
        return view('shifts.index',compact('stations','stationId','canManage'));
    }

    public function day(Request $request,string $date)
    {
        abort_unless($request->user()->hasPermission('shifts.view'),403);
        $parsed=\DateTime::createFromFormat('!Y-m-d',$date);
        abort_unless($parsed && $parsed->format('Y-m-d')===$date,404);
        $stations=$this->stationsFor($request);
        $stationId=$request->integer('station_id') ?: $stations->first()?->id;
        abort_unless($stationId && $stations->contains('id',$stationId),403);
        $station=$stations->firstWhere('id',$stationId);
        $shifts=Shift::with(['type','operator','readings.nozzle','readings.fuelType'])->where('station_id',$stationId)->whereDate('shift_date',$date)->orderBy('shift_type_id')->orderBy('id')->get();
        abort_if($shifts->isEmpty(),404);
        $activeShifts=$shifts->where('status','<>','Voided');
        $readings=$activeShifts->flatMap(fn($shift)=>$shift->readings);
        $fuelTotals=$readings->groupBy('fuel_type_id')->map(function($items){$fuel=$items->first()->fuelType;return (object)['name'=>$fuel->name,'unit'=>$fuel->unit,'quantity'=>(float)$items->sum('quantity'),'amount'=>(float)$items->sum('amount')];})->values();
        $dailyTotals=(object)['sales'=>(float)$activeShifts->sum('sales_total'),'cash'=>(float)$activeShifts->sum('cash_collected'),'upi'=>(float)$activeShifts->sum('upi_collected'),'bank'=>(float)$activeShifts->sum('bank_collected'),'card'=>(float)$activeShifts->sum('card_collected'),'credit'=>(float)$activeShifts->sum('credit_sales'),'collected'=>(float)$activeShifts->sum(fn($shift)=>(float)$shift->cash_collected+(float)$shift->upi_collected+(float)$shift->bank_collected+(float)$shift->card_collected+(float)$shift->credit_sales),'short_excess'=>(float)$activeShifts->sum('short_excess')];
        $canManage=$this->mayManageShifts($request);
        $canCloseIds=$shifts->filter(fn($shift)=>$shift->status==='Open'&&(($shift->operator_id===$request->user()->id&&$request->user()->hasPermission('shifts.close'))||($canManage&&($request->user()->role==='Super Admin'||$request->user()->stations()->where('stations.id',$shift->station_id)->exists()))))->pluck('id');
        return view('shifts.day',compact('date','station','stationId','shifts','fuelTotals','dailyTotals','canManage','canCloseIds'));
    }

    public function edit(Request $request,Shift $shift)
    {
        abort_unless($this->mayManageShifts($request)&&($request->user()->role==='Super Admin'||$request->user()->stations()->where('stations.id',$shift->station_id)->exists()),403);
        abort_unless($shift->status==='Open',422,'Only open shifts can be edited.');
        $shift->load(['station','type','operator','readings.nozzle','readings.fuelType']);
        $types=ShiftType::where('station_id',$shift->station_id)->where('active',true)->orderBy('starts_at')->get();
        $operators=User::where('active',true)->whereHas('roleRecord.permissions',fn($q)=>$q->where('permissions.slug','shifts.close'))->whereHas('stations',fn($q)=>$q->where('stations.id',$shift->station_id))->orderBy('name')->get();
        return view('shifts.edit',compact('shift','types','operators'));
    }

    public function update(Request $request,Shift $shift)
    {
        abort_unless($this->mayManageShifts($request)&&($request->user()->role==='Super Admin'||$request->user()->stations()->where('stations.id',$shift->station_id)->exists()),403);
        abort_unless($shift->status==='Open',422,'Only open shifts can be edited.');
        $data=$request->validate(['shift_type_id'=>'required|integer|exists:shift_types,id','operator_id'=>'required|integer|exists:users,id','shift_date'=>'required|date','readings'=>'required|array','readings.*.opening'=>'required|numeric|min:0|max:999999999.999']);
        abort_unless(ShiftType::where('id',$data['shift_type_id'])->where('station_id',$shift->station_id)->where('active',true)->exists(),422);
        abort_unless(User::where('id',$data['operator_id'])->where('active',true)->whereHas('roleRecord.permissions',fn($q)=>$q->where('permissions.slug','shifts.close'))->whereHas('stations',fn($q)=>$q->where('stations.id',$shift->station_id))->exists(),422);
        $readings=$shift->readings()->get();$readingIds=$readings->pluck('id')->map(fn($id)=>(int)$id)->all();$submittedIds=array_map('intval',array_keys($data['readings']));
        if(array_diff($readingIds,$submittedIds)||array_diff($submittedIds,$readingIds))throw ValidationException::withMessages(['readings'=>'The nozzle readings changed. Reload this form and try again.']);
        $nozzleIds=$readings->pluck('nozzle_id');$busy=DB::table('shift_readings')->join('shifts','shifts.id','=','shift_readings.shift_id')->where('shifts.station_id',$shift->station_id)->whereDate('shifts.shift_date',$data['shift_date'])->where('shifts.status','Open')->where('shifts.id','<>',$shift->id)->whereIn('shift_readings.nozzle_id',$nozzleIds)->exists();
        if($busy)throw ValidationException::withMessages(['shift_date'=>'A selected nozzle already has another open shift on this date.']);
        $rates=DB::table('daily_rates')->where('station_id',$shift->station_id)->where('rate_date',$data['shift_date'])->whereIn('fuel_type_id',$readings->pluck('fuel_type_id'))->pluck('rate','fuel_type_id');
        foreach($readings as $reading)if(!$rates->has($reading->fuel_type_id))throw ValidationException::withMessages(['shift_date'=>'Set every assigned fuel rate for this date before editing the shift.']);
        DB::transaction(function()use($request,$shift,$data,$readings,$rates){$locked=Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();if($locked->status!=='Open')throw ValidationException::withMessages(['shift'=>'Only open shifts can be edited.']);$locked->update(['shift_type_id'=>$data['shift_type_id'],'operator_id'=>$data['operator_id'],'shift_date'=>$data['shift_date']]);foreach($readings as $reading)$reading->update(['opening_reading'=>$data['readings'][$reading->id]['opening'],'rate'=>$rates[$reading->fuel_type_id]]);DB::table('audit_logs')->insert(['user_id'=>$request->user()->id,'station_id'=>$shift->station_id,'action'=>'shift_updated','entity_type'=>'shift','entity_id'=>$shift->id,'details'=>json_encode(['shift_type_id'=>$data['shift_type_id'],'operator_id'=>$data['operator_id'],'shift_date'=>$data['shift_date']]),'created_at'=>now(),'updated_at'=>now()]);});
        return redirect()->route('shifts.day',['date'=>$data['shift_date'],'station_id'=>$shift->station_id])->with('success','Open shift updated.');
    }

    public function assignNozzle(Request $request, Shift $shift)
    {
        abort_unless($this->mayManageShifts($request),403);$user=$request->user();abort_unless($user->role==='Super Admin'||$user->stations()->where('stations.id',$shift->station_id)->exists(),403);abort_unless($shift->status==='Open',422);
        $data=$request->validate(['nozzle_id'=>'required|integer|exists:nozzles,id','operator_id'=>'required|integer|exists:users,id','opening_reading'=>'required|numeric|min:0|max:999999999.999']);
        DB::transaction(function()use($request,$shift,$data){$lockedShift=Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();if($lockedShift->status!=='Open')throw \Illuminate\Validation\ValidationException::withMessages(['shift'=>'Nozzles can only be assigned to an open shift.']);$nozzle=Nozzle::whereKey($data['nozzle_id'])->where('station_id',$lockedShift->station_id)->where('active',true)->lockForUpdate()->first();if(!$nozzle)throw \Illuminate\Validation\ValidationException::withMessages(['nozzle_id'=>'Select an active nozzle at this station.']);if($lockedShift->readings()->where('nozzle_id',$nozzle->id)->exists())throw \Illuminate\Validation\ValidationException::withMessages(['nozzle_id'=>'This nozzle is already assigned to the selected shift.']);$shiftDate=$lockedShift->shift_date->toDateString();$busy=DB::table('shift_readings')->join('shifts','shifts.id','=','shift_readings.shift_id')->where('shifts.station_id',$lockedShift->station_id)->whereDate('shifts.shift_date',$shiftDate)->where('shifts.status','Open')->where('shift_readings.nozzle_id',$nozzle->id)->where('shifts.id','<>',$lockedShift->id)->exists();if($busy)throw \Illuminate\Validation\ValidationException::withMessages(['nozzle_id'=>'This nozzle is already assigned to another open shift on this date.']);$operator=User::whereKey($data['operator_id'])->where('active',true)->whereHas('roleRecord.permissions',fn($q)=>$q->where('permissions.slug','shifts.close'))->whereHas('stations',fn($q)=>$q->where('stations.id',$lockedShift->station_id))->first();if(!$operator)throw \Illuminate\Validation\ValidationException::withMessages(['operator_id'=>'Choose an active shift operator assigned to this station.']);$rate=DB::table('daily_rates')->where('station_id',$lockedShift->station_id)->where('fuel_type_id',$nozzle->fuel_type_id)->where('rate_date',$shiftDate)->value('rate');if($rate===null)throw \Illuminate\Validation\ValidationException::withMessages(['nozzle_id'=>'Set the daily rate for this fuel before assigning the nozzle.']);$lockedShift->update(['operator_id'=>$operator->id]);$lockedShift->readings()->create(['nozzle_id'=>$nozzle->id,'fuel_type_id'=>$nozzle->fuel_type_id,'opening_reading'=>$data['opening_reading'],'rate'=>$rate,'quantity'=>0,'amount'=>0]);DB::table('audit_logs')->insert(['user_id'=>$request->user()->id,'station_id'=>$lockedShift->station_id,'action'=>'nozzle_assigned_to_shift','entity_type'=>'shift','entity_id'=>$lockedShift->id,'details'=>json_encode(['nozzle_id'=>$nozzle->id,'operator_id'=>$operator->id]),'created_at'=>now(),'updated_at'=>now()]);});
        return back()->with('success','Nozzle and operator assigned to the shift.');
    }
    public function close(Request $request, Shift $shift)
    {
        $user=$request->user();
        abort_unless(($shift->operator_id===$user->id && $user->hasPermission('shifts.close')) || ($user->hasPermission('shifts.manage') && ($user->role==='Super Admin'||$user->stations()->where('stations.id',$shift->station_id)->exists())),403);
        if($shift->status!=='Open') throw ValidationException::withMessages(['shift'=>'Only open shifts can be submitted.']);
        $input=$request->validate(['readings'=>'required|array','readings.*.closing'=>'required|numeric|min:0','cash_collected'=>'required|numeric|min:0','upi_collected'=>'required|numeric|min:0','bank_collected'=>'required|numeric|min:0','card_collected'=>'required|numeric|min:0','credit_sales'=>'required|numeric|min:0','remarks'=>'nullable|string|max:2000']);
        DB::transaction(function()use($shift,$input,$request){
            $shift->load('readings'); $sales=0;
            foreach($shift->readings as $reading){ $close=BigDecimal::of($input['readings'][$reading->id]['closing']); $opening=BigDecimal::of($reading->opening_reading); if($close->isLessThan($opening)) throw ValidationException::withMessages(["readings.{$reading->id}.closing"=>'Closing reading cannot be below opening reading.']); $qty=$close->minus($opening)->toScale(3,RoundingMode::HALF_UP); $amount=$qty->multipliedBy((string)$reading->rate)->toScale(2,RoundingMode::HALF_UP); $reading->update(['closing_reading'=>(string)$close->toScale(3,RoundingMode::HALF_UP),'quantity'=>(string)$qty,'amount'=>(string)$amount]); $sales=BigDecimal::of($sales)->plus($amount)->toScale(2,RoundingMode::HALF_UP); }
            $collected=BigDecimal::zero(); foreach(['cash_collected','upi_collected','bank_collected','card_collected','credit_sales'] as $mode){$collected=$collected->plus(BigDecimal::of($input[$mode]));}
            $shift->update([...collect($input)->only(['cash_collected','upi_collected','bank_collected','card_collected','credit_sales','remarks'])->all(),'sales_total'=>(string)$sales,'short_excess'=>(string)$collected->minus($sales)->toScale(2,RoundingMode::HALF_UP),'status'=>'Submitted','submitted_at'=>now()]);
            DB::table('audit_logs')->insert(['user_id'=>$request->user()->id,'station_id'=>$shift->station_id,'action'=>'shift_submitted','entity_type'=>'shift','entity_id'=>$shift->id,'details'=>json_encode(['sales_total'=>$sales]),'created_at'=>now(),'updated_at'=>now()]);
        });
        return back()->with('success','Shift submitted for manager approval.');
    }
    public function decision(Request $request, Shift $shift)
    {
        $user=$request->user(); abort_unless($user->hasPermission('shifts.manage') && ($user->role==='Super Admin'||$user->stations()->where('stations.id',$shift->station_id)->exists()),403);
        $data=$request->validate(['decision'=>'required|in:Approved,Rejected','remarks'=>'nullable|string|max:2000']);
        abort_unless($shift->status==='Submitted',422,'Only submitted shifts can be approved or rejected.');
        DB::transaction(function()use($shift,$data,$request){$shift->update(['status'=>$data['decision'],'remarks'=>$data['remarks']??$shift->remarks,'approved_by'=>$request->user()->id]); DB::table('audit_logs')->insert(['user_id'=>$request->user()->id,'station_id'=>$shift->station_id,'action'=>'shift_'.$data['decision'],'entity_type'=>'shift','entity_id'=>$shift->id,'details'=>json_encode(['remarks'=>$data['remarks']??null]),'created_at'=>now(),'updated_at'=>now()]);});
        return back()->with('success','Shift '.$data['decision'].'.');
    }
    public function void(Request $request,Shift $shift)
    {
        $user=$request->user();abort_unless($user->hasPermission('shifts.manage')&&($user->role==='Super Admin'||$user->stations()->where('stations.id',$shift->station_id)->exists()),403);$data=$request->validate(['reason'=>'required|string|max:500']);
        DB::transaction(function()use($request,$shift,$data){$locked=Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();if($locked->status!=='Open')throw ValidationException::withMessages(['shift'=>'Only an open shift without submitted collections can be voided.']);$locked->update(['status'=>'Voided','remarks'=>trim(($locked->remarks?$locked->remarks."\n":'').'Voided: '.$data['reason'])]);DB::table('audit_logs')->insert(['user_id'=>$request->user()->id,'station_id'=>$locked->station_id,'action'=>'shift_voided','entity_type'=>'shift','entity_id'=>$locked->id,'details'=>json_encode(['reason'=>$data['reason']]),'created_at'=>now(),'updated_at'=>now()]);});
        return back()->with('success','Open shift voided. Readings remain available for audit.');
    }
}
