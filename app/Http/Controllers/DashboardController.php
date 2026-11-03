<?php
namespace App\Http\Controllers;
use App\Models\Shift;
use App\Models\FuelType;
use App\Models\Station;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user=$request->user();abort_unless($user->hasPermission('dashboard.view'),403);$stationId=$request->integer('station_id');
        $stationQuery=Station::query()->where('active',true); if($user->role!=='Super Admin') $stationQuery->whereIn('id',$user->stations()->select('stations.id'));
        $stations=$stationQuery->orderBy('name')->get();
        if(!$stations->contains('id',$stationId)) $stationId=$stations->first()?->id;
        $day=$request->date('date')?->toDateString()??now()->toDateString();
        $shifts=Shift::with(['type','operator','readings.fuelType','readings.nozzle'])->when($stationId,fn($q)=>$q->where('station_id',$stationId))->where('status','<>','Voided')->whereDate('shift_date',$day)->when(!$user->isManagement(),fn($q)=>$q->where('operator_id',$user->id))->latest()->get();
        $readings=$shifts->flatMap->readings;
        $sales=(float)$readings->sum('amount'); $collected=(float)$shifts->sum(fn($s)=>$s->cash_collected+$s->upi_collected+$s->bank_collected+$s->card_collected);
        $fuelSummary=FuelType::where('active',true)->orderBy('id')->get()->map(function($fuel)use($readings,$stationId,$day){$fuelReadings=$readings->where('fuel_type_id',$fuel->id);$appliedRate=$fuelReadings->first()?->rate;if($appliedRate===null&&$stationId)$appliedRate=DB::table('daily_rates')->where('station_id',$stationId)->where('fuel_type_id',$fuel->id)->where('rate_date',$day)->value('rate');return ['fuel'=>$fuel->name,'unit'=>$fuel->unit,'rate'=>(float)($appliedRate??0),'quantity'=>(float)$fuelReadings->sum('quantity'),'amount'=>(float)$fuelReadings->sum('amount')];});
        $totals=['sales'=>$sales,'collected'=>$collected,'expenses'=>(float)DB::table('expenses')->whereNull('deleted_at')->whereDate('expense_date',$day)->when($stationId,fn($q)=>$q->where('station_id',$stationId))->sum('amount'),'shifts'=>$shifts->count(),'pending'=>$shifts->where('status','Submitted')->count(),'draft'=>$shifts->where('status','Draft')->count(),'net'=>$sales-(float)DB::table('expenses')->whereNull('deleted_at')->whereDate('expense_date',$day)->when($stationId,fn($q)=>$q->where('station_id',$stationId))->sum('amount')];
        return view('dashboard',compact('stations','stationId','day','shifts','fuelSummary','totals'));
    }
}
