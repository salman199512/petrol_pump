<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftCheckpointReading extends Model
{
    protected $guarded = [];

    protected $casts = [
        'meter_reading' => 'decimal:3',
        'quantity' => 'decimal:3',
        'amount' => 'decimal:2',
    ];

    public function checkpoint()
    {
        return $this->belongsTo(ShiftCheckpoint::class, 'checkpoint_id');
    }

    public function shiftReading()
    {
        return $this->belongsTo(ShiftReading::class, 'shift_reading_id');
    }
}
