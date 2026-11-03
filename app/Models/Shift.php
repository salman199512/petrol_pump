<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Shift extends Model { protected $guarded=[]; protected $casts=['shift_date'=>'date','submitted_at'=>'datetime']; public function station(){return $this->belongsTo(Station::class);} public function type(){return $this->belongsTo(ShiftType::class,'shift_type_id');} public function operator(){return $this->belongsTo(User::class,'operator_id');} public function readings(){return $this->hasMany(ShiftReading::class);} }
