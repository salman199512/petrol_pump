<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftCollection extends Model
{
    protected $guarded = [];

    protected $casts = [
        'collected_at' => 'datetime',
        'denominations' => 'array',
        'cash_amount' => 'decimal:2',
        'upi_amount' => 'decimal:2',
        'bank_amount' => 'decimal:2',
        'card_amount' => 'decimal:2',
        'credit_amount' => 'decimal:2',
    ];

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function collector()
    {
        return $this->belongsTo(User::class, 'collected_by');
    }
}
