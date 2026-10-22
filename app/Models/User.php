<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Traits\HasHashids;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable implements OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasHashids, Notifiable;

    /** @var list<string>|null Per-instance memo; see supplierScopeValues(). */
    private ?array $supplierScopeValues = null;

    private int $supplierScopeGeneration = -1;

    /** Bumped at the start of every request and job; see resetSupplierScopeMemos(). */
    private static int $scopeGeneration = 0;

    public const ACCOUNT_STATUS_PENDING = 'PENDING';

    public const ACCOUNT_STATUS_REVISION = 'REVISION';

    public const ACCOUNT_STATUS_ACTIVE = 'ACTIVE';

    public const ACCOUNT_STATUS_REJECTED = 'REJECTED';

    protected static function booted(): void
    {
        static::updating(function (User $user): void {
            if ($user->isDirty('email')) {
                throw ValidationException::withMessages(['email' => __('profile.email_immutable')]);
            }
        });
    }

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
        'account_status',
        'must_change_password',
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
            'must_change_password' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_step' => 'integer',
            'auth_session_version' => 'integer',
        ];
    }

    public function isAuthenticationEligible(): bool
    {
        return $this->is_active && $this->account_status === self::ACCOUNT_STATUS_ACTIVE;
    }

    public function hasSameAuthenticationConfigurationAs(User $verified): bool
    {
        // Proof consumption changes the step/recovery list, not this configuration.
        return $this->getAuthPassword() === $verified->getAuthPassword()
            && (int) $this->auth_session_version === (int) $verified->auth_session_version
            && $this->two_factor_secret === $verified->two_factor_secret
            && $this->two_factor_confirmed_at?->getTimestamp() === $verified->two_factor_confirmed_at?->getTimestamp();
    }

    // ─── Relationships ───

    public function supplier(): HasOne
    {
        return $this->hasOne(Supplier::class);
    }

    public function preference(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    public function supplierScopes(): HasMany
    {
        return $this->hasMany(SupplierScope::class, 'supplier_id');
    }

    public function hasSupplierScope(string $scope): bool
    {
        return $this->isSupplier() && in_array($scope, $this->supplierScopeValues(), true);
    }

    /**
     * Scope values read once per model instance and per request or job. The memo
     * is discarded when a new request or job starts, even if the same instance is
     * reused (as the authenticated user is in tests); code that changes the
     * scopes of an instance it keeps using must call forgetSupplierScopes().
     *
     * @return list<string>
     */
    public function supplierScopeValues(): array
    {
        if ($this->supplierScopeValues === null || $this->supplierScopeGeneration !== self::$scopeGeneration) {
            $this->supplierScopeValues = $this->supplierScopes()->pluck('scope')->all();
            $this->supplierScopeGeneration = self::$scopeGeneration;
        }

        return $this->supplierScopeValues;
    }

    /** Invalidate every instance's scope memo; called when a request or job starts. */
    public static function resetSupplierScopeMemos(): void
    {
        self::$scopeGeneration++;
    }

    public function forgetSupplierScopes(): static
    {
        $this->supplierScopeValues = null;

        return $this;
    }

    public function refresh()
    {
        $this->supplierScopeValues = null;

        return parent::refresh();
    }

    public function isLocalOperator(): bool
    {
        return in_array($this->role, ['finance', 'accounting'], true);
    }

    public function isFinance(): bool
    {
        return $this->role === 'finance';
    }

    public function isGa(): bool
    {
        return $this->role === 'ga';
    }

    public function supplierBankAccounts(): HasMany
    {
        return $this->hasMany(SupplierBankAccount::class, 'supplier_id');
    }

    public function activeSupplierBankAccount(): HasOne
    {
        return $this->hasOne(SupplierBankAccount::class, 'supplier_id')
            ->where('status', SupplierBankAccount::STATUS_VERIFIED);
    }

    public function supplierChangeRequests(): HasMany
    {
        return $this->hasMany(SupplierChangeRequest::class, 'supplier_id');
    }

    public function supplierMasterDocuments(): HasMany
    {
        return $this->hasMany(SupplierMasterDocument::class, 'supplier_id');
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

    public function invitedPurchaseRequisitions(): BelongsToMany
    {
        return $this->belongsToMany(PurchaseRequisition::class, 'purchase_requisition_suppliers', 'supplier_id', 'pr_id')
            ->withPivot('invited_at');
    }

    public function registrationAttempts(): HasMany
    {
        return $this->hasMany(SupplierRegistrationAttempt::class, 'user_id');
    }

    public function latestRegistrationAttempt(): HasOne
    {
        return $this->hasOne(SupplierRegistrationAttempt::class, 'user_id')->latestOfMany();
    }

    public function registrationAccesses(): HasMany
    {
        return $this->hasMany(SupplierRegistrationAccess::class, 'user_id');
    }

    public function registrationAudits(): HasMany
    {
        return $this->hasMany(SupplierRegistrationAudit::class, 'user_id');
    }

    // ─── Query Scopes ───

    /**
     * Active suppliers with the 'import' scope.
     */
    public function scopeImportEligible(Builder $query): Builder
    {
        return $query->where('role', 'supplier')
            ->where('account_status', self::ACCOUNT_STATUS_ACTIVE)
            ->where('is_active', true)
            ->whereHas('supplierScopes', fn (Builder $q) => $q->where('scope', 'import'));
    }

    /**
     * Active suppliers with the 'local' scope.
     */
    public function scopeLocalEligible(Builder $query): Builder
    {
        return $query->where('role', 'supplier')
            ->where('account_status', self::ACCOUNT_STATUS_ACTIVE)
            ->where('is_active', true)
            ->whereHas('supplierScopes', fn (Builder $q) => $q->where('scope', 'local'));
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

    public function isImportEligible(): bool
    {
        return $this->isSupplier()
            && $this->account_status === self::ACCOUNT_STATUS_ACTIVE
            && $this->is_active
            && $this->hasSupplierScope('import');
    }

    public function isLocalEligible(): bool
    {
        return $this->isSupplier()
            && $this->account_status === self::ACCOUNT_STATUS_ACTIVE
            && $this->is_active
            && $this->hasSupplierScope('local');
    }

    public function hasTwoFactorAuthentication(): bool
    {
        return filled($this->two_factor_secret) && $this->two_factor_confirmed_at !== null;
    }
}
