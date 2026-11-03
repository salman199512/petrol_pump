<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Station extends Model { protected $guarded=[]; public function users(){return $this->belongsToMany(User::class);} public function nozzles(){return $this->hasMany(Nozzle::class);} public function shifts(){return $this->hasMany(Shift::class);} }
