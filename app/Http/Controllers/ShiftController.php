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
        $q=Shift::with(['station','type','operator','readings.nozzle','readings.fuelType'])->latest('shift_date');
        if($request->user()->role!=='Super Admin') $q->whereIn('station_id',$request->user()->stations()->select('stations.id'));
        else if($request->integer('station_id')) $q->where('station_id',$request->integer('station_id'));
        $canAssign=$this->mayManageShifts($request);$assignmentShifts=collect();$assignmentNozzles=collect();$assignmentOperators=collect();
        if($canAssign){$stationIds=$this->stationsFor($request)->pluck('id');$assignmentShifts=Shift::with(['station','type','operator'])->whereIn('station_id',$stationIds)->where('status','Open')->orderByDesc('shift_date')->orderBy('shift_type_id')->get();$openAssignments=DB::table('shift_readings')->join('shifts','shifts.id','=','shift_readings.shift_id')->whereIn('shifts.station_id',$stationIds)->where('shifts.status','Open')->select('shift_readings.nozzle_id','shifts.id as shift_id','shifts.shift_date')->get();$rateDates=DB::table('daily_rates')->whereIn('station_id',$stationIds)->get(['station_id','fuel_type_id','rate_date']);$lastReadings=DB::table('shift_readings')->join('shifts','shifts.id','=','shift_readings.shift_id')->whereIn('shifts.station_id',$stationIds)->whereNotNull('shift_readings.closing_reading')->orderByDesc('shifts.shift_date')->orderByDesc('shift_readings.id')->get(['shift_readings.nozzle_id','shift_readings.closing_reading'])->unique('nozzle_id')->keyBy('nozzle_id');$assignmentNozzles=Nozzle::with('fuelType')->whereIn('station_id',$stationIds)->where('active',true)->orderBy('station_id')->orderBy('name')->get()->map(function($nozzle)use($openAssignments,$rateDates,$lastReadings){$nozzle->busy_dates=$openAssignments->where('nozzle_id',$nozzle->id)->map(fn($item)=>\Illuminate\Support\Carbon::parse($item->shift_date)->toDateString().':'.$item->shift_id)->join(',');$nozzle->rate_dates=$rateDates->where('station_id',$nozzle->station_id)->where('fuel_type_id',$nozzle->fuel_type_id)->map(fn($item)=>\Illuminate\Support\Carbon::parse($item->rate_date)->toDateString())->join(',');$nozzle->opening_suggestion=$lastReadings->get($nozzle->id)?->closing_reading??'0.000';return $nozzle;});$assignmentOperators=User::where('active',true)->whereHas('roleRecord.permissions',fn($q)=>$q->where('permissions.slug','shifts.close'))->whereHas('stations',fn($q)=>$q->whereIn('stations.id',$stationIds))->with('stations')->orderBy('name')->get();}
        return view('shifts.index',array_merge(['shifts'=>$q->paginate(50)->withQueryString()],compact('canAssign','assignmentShifts','assignmentNozzles','assignmentOperators')));
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
