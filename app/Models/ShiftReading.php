<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ShiftReading extends Model { protected $guarded=[]; protected $casts=['opening_reading'=>'decimal:3','closing_reading'=>'decimal:3','quantity'=>'decimal:3','rate'=>'decimal:2','amount'=>'decimal:2']; public function nozzle(){return $this->belongsTo(Nozzle::class);} public function fuelType(){return $this->belongsTo(FuelType::class);} }
