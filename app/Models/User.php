<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\AdasiResetPasswordNotification;
use App\Traits\HasHashids;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

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

    public function supplierScopes(): HasMany
    {
        return $this->hasMany(SupplierScope::class, 'supplier_id');
    }

    public function hasSupplierScope(string $scope): bool
    {
        return $this->isSupplier() && $this->supplierScopes()->where('scope', $scope)->exists();
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

    public function activeSupplierBankAccount(): \Illuminate\Database\Eloquent\Relations\HasOne
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

    // ─── Query Scopes ───

    /**
     * Active suppliers with the 'import' scope.
     */
    public function scopeImportEligible(Builder $query): Builder
    {
        return $query->where('role', 'supplier')
            ->where('is_active', true)
            ->whereHas('supplierScopes', fn (Builder $q) => $q->where('scope', 'import'));
    }

    /**
     * Active suppliers with the 'local' scope.
     */
    public function scopeLocalEligible(Builder $query): Builder
    {
        return $query->where('role', 'supplier')
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
        return $this->isSupplier() && $this->is_active && $this->hasSupplierScope('import');
    }

    public function isLocalEligible(): bool
    {
        return $this->isSupplier() && $this->is_active && $this->hasSupplierScope('local');
    }

    public function hasTwoFactorAuthentication(): bool
    {
        return filled($this->two_factor_secret) && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Send the branded reset-password notification while preserving Laravel's
     * password-broker token and expiration behavior.
     *
     * Deactivated accounts are intentionally skipped: the broker still
     * creates a token (harmless, self-expires), but no email goes out and
     * NewPasswordController separately refuses to honor a token for an
     * inactive account, so a stale credential can't be "revived" this way.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token)
    {
        if (! $this->is_active) {
            return;
        }

        $this->notify(new AdasiResetPasswordNotification($token));
    }

    /**
     * Determine whether this user participates in active or historical procurement
     * records that prevent hard database deletion.
     */
    public function hasBlockingProcurementHistory(): bool
    {
        return DB::table('local_invoices')->where('supplier_id', $this->id)->exists()
            || DB::table('local_invoice_status_histories')->where('actor_id', $this->id)->exists()
            || DB::table('supplier_bank_accounts')->where('supplier_id', $this->id)->exists()
            || DB::table('supplier_change_requests')->where('supplier_id', $this->id)->exists()
            || DB::table('quotations')->where('supplier_id', $this->id)->exists()
            || DB::table('purchase_orders')->where(fn ($q) => $q->where('supplier_id', $this->id)->orWhere('created_by', $this->id))->exists()
            || DB::table('purchase_requisitions')->where('created_by', $this->id)->exists()
            || DB::table('purchase_requisition_suppliers')->where('supplier_id', $this->id)->exists()
            || DB::table('material_claims')->where(fn ($q) => $q->where('supplier_id', $this->id)->orWhere('submitted_by', $this->id))->exists()
            || DB::table('qc_inspections')->where('inspected_by', $this->id)->exists()
            || DB::table('announcements')->where('created_by', $this->id)->exists()
            || DB::table('attachments')->where('uploaded_by', $this->id)->exists()
            || DB::table('claim_attachments')->where('uploaded_by', $this->id)->exists()
            || DB::table('periods')->where('created_by', $this->id)->exists()
            || DB::table('exchange_rates')->where('created_by', $this->id)->exists();
    }
}
