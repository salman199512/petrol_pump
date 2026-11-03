<?php
namespace App\Http\Controllers;
use App\Models\FuelType;
use App\Models\Station;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class RateController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('rates.view'),403);
        $stations=Station::query()->when($request->user()->role!=='Super Admin',fn($q)=>$q->whereIn('id',$request->user()->stations()->select('stations.id')))->orderBy('name')->get();
        $stationId=$request->integer('station_id') ?: $stations->first()?->id;
        abort_unless(!$stationId || $stations->contains('id',$stationId),403);
        $date=$request->date('date')?->toDateString()??now()->toDateString();
        $fuels=FuelType::where('active',true)->orderBy('id')->get();
        $rates=DB::table('daily_rates')->join('fuel_types','fuel_types.id','=','daily_rates.fuel_type_id')->leftJoin('users as author','author.id','=','daily_rates.updated_by')->where('daily_rates.station_id',$stationId)->orderByDesc('rate_date')->limit(300)->select('daily_rates.*','fuel_types.name as fuel_name','fuel_types.unit','author.name as author')->get();
        $rateGroups=$rates->groupBy(fn($rate)=>\Illuminate\Support\Carbon::parse($rate->rate_date)->toDateString());
        $currentRates=DB::table('daily_rates')->where('station_id',$stationId)->where('rate_date',$date)->pluck('rate','fuel_type_id');
        return view('rates.index',compact('stations','stationId','date','rates','rateGroups','fuels','currentRates'));
    }
    public function store(Request $request)
    {
        abort_unless($request->user()->hasPermission('rates.manage'),403);
        $data=$request->validate(['station_id'=>'required|exists:stations,id','rate_date'=>'required|date','rates'=>'required|array','rates.*'=>'required|numeric|min:0|max:99999999.99']);
        abort_unless($request->user()->role==='Super Admin' || $request->user()->stations()->where('stations.id',$data['station_id'])->exists(),403);
        foreach($data['rates'] as $fuelId=>$rate){ abort_unless(FuelType::where('active',true)->whereKey($fuelId)->exists(),422); $key=['station_id'=>$data['station_id'],'fuel_type_id'=>$fuelId,'rate_date'=>$data['rate_date']]; $existing=DB::table('daily_rates')->where($key)->exists(); DB::table('daily_rates')->updateOrInsert($key,['rate'=>$rate,...($existing?['updated_by'=>$request->user()->id]:['created_by'=>$request->user()->id,'updated_by'=>$request->user()->id]),'updated_at'=>now(),'created_at'=>now()]); }
        return redirect()->route('rates.index',['station_id'=>$data['station_id'],'date'=>$data['rate_date']])->with('success','Daily rates saved. Existing shift readings keep their original applied rate.');
    }
}
