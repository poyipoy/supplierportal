<?php

namespace App\Models;

use App\Casts\SupplierChangeRequestStatusCast;
use App\Enums\SupplierChangeRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierChangeRequest extends Model
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_CANCELLED = 'CANCELLED';

    /** Full Vendor Master change proposed from the Local portal. */
    public const TYPE_PROFILE = 'profile';

    /** Bank account change proposed from the Local portal. */
    public const TYPE_BANK_ACCOUNT = 'bank_account';

    /** Contact-person-only change proposed from the Import portal; reviewed by Purchasing. */
    public const TYPE_CONTACT = 'contact';

    public const CONTACT_FIELDS = ['pic_name', 'pic_email', 'pic_phone'];

    /** Company details an Import supplier may propose next to the contact person; blank means unchanged. */
    public const COMPANY_FIELDS = ['address', 'phone', 'category'];

    protected $fillable = [
        'supplier_id',
        'change_type',
        'current_data_snapshot',
        'proposed_data',
        'status',
        'requested_by',
        'requested_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => SupplierChangeRequestStatusCast::class,
            'current_data_snapshot' => 'array',
            'proposed_data' => 'array',
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function statusEnum(): SupplierChangeRequestStatus
    {
        return SupplierChangeRequestStatus::tryFromWithLegacy($this->status ?? self::STATUS_PENDING);
    }

    // ─── Relationships ───

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ─── Scopes ───

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeContact(Builder $query): Builder
    {
        return $query->where('change_type', self::TYPE_CONTACT);
    }

    public function scopeExcludingContact(Builder $query): Builder
    {
        return $query->where('change_type', '!=', self::TYPE_CONTACT);
    }

    public function isContactChange(): bool
    {
        return $this->change_type === self::TYPE_CONTACT;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
