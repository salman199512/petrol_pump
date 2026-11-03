<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Nozzle extends Model { protected $guarded=[]; public function fuelType(){return $this->belongsTo(FuelType::class);} }
