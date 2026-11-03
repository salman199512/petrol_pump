<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'mobile',
        'role',
        'role_id',
        'active',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function stations() { return $this->belongsToMany(Station::class); }

    public function roleRecord() { return $this->belongsTo(Role::class, 'role_id'); }

    public function hasPermission(string $permission): bool
    {
        if ($this->role === 'Super Admin') return true;
        return $this->roleRecord()->whereHas('permissions', fn ($query) => $query->where('permissions.slug', $permission))->exists();
    }

    public function hasAnyPermission(array $permissions): bool
    {
        if ($this->role === 'Super Admin') return true;
        return $this->roleRecord()->whereHas('permissions', fn ($query) => $query->whereIn('permissions.slug', $permissions))->exists();
    }

    public function isManagement(): bool { return $this->role === 'Super Admin' || $this->hasAnyPermission(['rates.view','invoices.view','customers.view','expenses.view','reports.daily-sales','reports.shift-report','reports.nozzle-sales','reports.operator-performance','reports.payment-collection','reports.expense-report','reports.profit-loss','reports.rate-history','reports.invoice-register','reports.customer-outstanding','reports.cash-short-excess','reports.audit-log']); }
}
