<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    protected $guarded = [];

    protected $casts = [
        'shift_date' => 'date',
        'submitted_at' => 'datetime',
        'sales_total' => 'decimal:2',
        'cash_collected' => 'decimal:2',
        'upi_collected' => 'decimal:2',
        'bank_collected' => 'decimal:2',
        'card_collected' => 'decimal:2',
        'credit_sales' => 'decimal:2',
        'short_excess' => 'decimal:2',
    ];

    public function station()
    {
        return $this->belongsTo(Station::class);
    }

    public function type()
    {
        return $this->belongsTo(ShiftType::class, 'shift_type_id');
    }

    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function readings()
    {
        return $this->hasMany(ShiftReading::class);
    }

    public function checkpoints()
    {
        return $this->hasMany(ShiftCheckpoint::class);
    }

    public function collections()
    {
        return $this->hasMany(ShiftCollection::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }
}
