<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftCheckpoint extends Model
{
    protected $guarded = [];

    protected $casts = [
        'checkpoint_at' => 'datetime',
    ];

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function readings()
    {
        return $this->hasMany(ShiftCheckpointReading::class, 'checkpoint_id');
    }
}
