<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\AdasiResetPasswordNotification;
use App\Traits\HasHashids;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasHashids, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
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
            'is_active' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_step' => 'integer',
            'auth_session_version' => 'integer',
        ];
    }

    // ─── Relationships ───

    public function supplier(): HasOne
    {
        return $this->hasOne(Supplier::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class, 'supplier_id');
    }

    public function purchaseRequisitions(): HasMany
    {
        return $this->hasMany(PurchaseRequisition::class, 'created_by');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'created_by');
    }

    public function qcInspections(): HasMany
    {
        return $this->hasMany(QcInspection::class, 'inspected_by');
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class, 'created_by');
    }

    // ─── Helpers ───

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isPurchasing(): bool
    {
        return $this->role === 'purchasing';
    }

    public function isSupplier(): bool
    {
        return $this->role === 'supplier';
    }

    public function isQc(): bool
    {
        return $this->role === 'qc';
    }

    public function hasTwoFactorAuthentication(): bool
    {
        return filled($this->two_factor_secret) && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Send the branded reset-password notification while preserving Laravel's
     * password-broker token and expiration behavior.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token)
    {
        $this->notify(new AdasiResetPasswordNotification($token));
    }
}
