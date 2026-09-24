<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Supplier extends Model
{
    protected $fillable = [
        'user_id',
        'company_title',
        'company_name',
        'address',
        'phone',
        'nib',
        'nib_fingerprint',
        'npwp',
        'tax_identity_fingerprint',
        'category',
        'vendor_category',
        'is_pkp',
        'pic_name',
        'pic_email',
        'pic_phone',
        'payment_term_days',
    ];

    protected function casts(): array
    {
        return [
            'is_pkp' => 'boolean',
            'payment_term_days' => 'integer',
        ];
    }

    // ─── Relationships ───

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bankAccounts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SupplierBankAccount::class, 'supplier_id', 'user_id');
    }

    public function activeBankAccount(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SupplierBankAccount::class, 'supplier_id', 'user_id')
            ->where('status', SupplierBankAccount::STATUS_VERIFIED);
    }

    public function changeRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SupplierChangeRequest::class, 'supplier_id', 'user_id');
    }

    public function masterDocuments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SupplierMasterDocument::class, 'supplier_id', 'user_id');
    }

    // ─── Helpers ───

    public function requiresSuratJalan(): bool
    {
        $cat = $this->vendor_category ?: $this->category;
        return strcasecmp(trim((string) $cat), 'Barang') === 0;
    }

    public function requiresFakturPajak(): bool
    {
        return (bool) $this->is_pkp;
    }
}
