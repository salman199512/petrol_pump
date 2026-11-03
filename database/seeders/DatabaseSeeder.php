<?php
namespace Database\Seeders;
use App\Models\{FuelType,Nozzle,Role,Shift,ShiftReading,ShiftType,Station,User};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $station=Station::firstOrCreate(['name'=>'Banas CNG Pump'],['address'=>'Banas, India','active'=>true]);
        $adminRole=Role::where('name','Super Admin')->first();$operatorRole=Role::where('name','Operator')->first();
        $admin=User::firstOrCreate(['email'=>'admin@banaspump.local'],['name'=>'Station Admin','mobile'=>'9000000001','role'=>'Super Admin','role_id'=>$adminRole?->id,'active'=>true,'password'=>Hash::make('ChangeMe123!')]);
        $operator=User::firstOrCreate(['email'=>'operator@banaspump.local'],['name'=>'Demo Operator','mobile'=>'9000000002','role'=>'Operator','role_id'=>$operatorRole?->id,'active'=>true,'password'=>Hash::make('ChangeMe123!')]);
        if($adminRole)$admin->update(['role_id'=>$adminRole->id]);if($operatorRole)$operator->update(['role_id'=>$operatorRole->id]);
        $station->users()->syncWithoutDetaching([$admin->id,$operator->id]);
        $cng=FuelType::firstOrCreate(['name'=>'CNG'],['unit'=>'kg','tax_rate'=>0,'active'=>true]);
        foreach(['Petrol','Diesel'] as $name) FuelType::firstOrCreate(['name'=>$name],['unit'=>'L','tax_rate'=>0,'active'=>true]);
        $dispenser=\Illuminate\Support\Facades\DB::table('dispensers')->where(['station_id'=>$station->id,'name'=>'Dispenser 1'])->first();
        $dispenserId=$dispenser?->id ?? \Illuminate\Support\Facades\DB::table('dispensers')->insertGetId(['station_id'=>$station->id,'name'=>'Dispenser 1','created_at'=>now(),'updated_at'=>now()]);
        for($i=1;$i<=4;$i++) Nozzle::firstOrCreate(['station_id'=>$station->id,'name'=>"CNG Nozzle {$i}"],['dispenser_id'=>$dispenserId,'fuel_type_id'=>$cng->id,'active'=>true]);
        foreach([['Shift 1','06:00','14:00'],['Shift 2','14:00','22:00'],['Shift 3','22:00','06:00']] as [$name,$from,$to]) ShiftType::firstOrCreate(['station_id'=>$station->id,'name'=>$name],['starts_at'=>$from,'ends_at'=>$to]);
        \Illuminate\Support\Facades\DB::table('daily_rates')->updateOrInsert(['station_id'=>$station->id,'fuel_type_id'=>$cng->id,'rate_date'=>now()->toDateString()],['rate'=>90,'created_by'=>$admin->id,'updated_by'=>$admin->id,'created_at'=>now(),'updated_at'=>now()]);
        $type=ShiftType::where('station_id',$station->id)->orderBy('id')->first();
        $shift=Shift::firstOrCreate(['station_id'=>$station->id,'shift_type_id'=>$type->id,'operator_id'=>$operator->id,'shift_date'=>now()->toDateString()],['status'=>'Open']);
        foreach($station->nozzles()->where('active',true)->get() as $nozzle) ShiftReading::firstOrCreate(['shift_id'=>$shift->id,'nozzle_id'=>$nozzle->id],['fuel_type_id'=>$cng->id,'opening_reading'=>0,'rate'=>90,'quantity'=>0,'amount'=>0]);
        \Illuminate\Support\Facades\DB::table('stations')->where('id',$station->id)->update(['updated_at'=>now()]);
    }
}
