<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$user=App\Models\User::where('role','Super Admin')->firstOrFail();Illuminate\Support\Facades\Auth::setUser($user);
$req=function($path,$method='GET',$data=[])use($user){$r=Illuminate\Http\Request::create($path,$method,$data);$r->setUserResolver(fn()=>$user);return $r;};
(new App\Http\Controllers\ShiftController)->create($req('/shifts/create?station_id=1'))->render();echo "OK shift-create-view\n";
(new App\Http\Controllers\ShiftController)->index($req('/shifts'))->render();echo "OK shift-list-and-assign-view\n";
(new App\Http\Controllers\RateController)->index($req('/rates'))->render();echo "OK single-entry-rate-view\n";
Illuminate\Support\Facades\DB::beginTransaction();App\Models\Shift::query()->update(['status'=>'Approved']);$date=Illuminate\Support\Facades\DB::table('daily_rates')->where('fuel_type_id',1)->value('rate_date');$payload=['station_id'=>1,'shift_type_id'=>2,'operator_id'=>2,'shift_date'=>$date,'nozzle_ids'=>[1],'openings'=>[1=>1200]];(new App\Http\Controllers\ShiftController)->store($req('/shifts','POST',$payload));$shift=App\Models\Shift::latest('id')->firstOrFail();echo "OK create-shift\n";$assign=['nozzle_id'=>2,'operator_id'=>2,'opening_reading'=>2300];(new App\Http\Controllers\ShiftController)->assignNozzle($req('/shifts/'.$shift->id.'/assign-nozzle','POST',$assign),$shift);if($shift->fresh()->readings()->count()!==2)throw new RuntimeException('Nozzle assignment did not persist');echo "OK assign-nozzle\n";Illuminate\Support\Facades\DB::rollBack();
