<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class GaClaim extends Model
{
    use HasHashids;

    public const STATUS_SUBMITTED = 'SUBMITTED';

    public const STATUS_BASIC_VERIFIED = 'BASIC_VERIFIED';

    public const STATUS_UNDER_VERIFICATION = 'UNDER_VERIFICATION';

    public const STATUS_NEED_REVISION = 'NEED_REVISION';

    public const STATUS_READY_TO_PAY = 'READY_TO_PAY';

    public const STATUS_PAID = 'PAID';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const TYPE_ENTERTAINMENT = 'Entertainment';

    public const TYPE_BUSINESS_TRAVEL = 'Business Travel';

    public const TYPE_REIMBURSE_CLAIM = 'Reimburse/Claim';

    public const CLAIM_TYPES = [
        self::TYPE_ENTERTAINMENT,
        self::TYPE_BUSINESS_TRAVEL,
        self::TYPE_REIMBURSE_CLAIM,
    ];

    public static function claimTypeLabel(string $type): string
    {
        $key = match ($type) {
            self::TYPE_ENTERTAINMENT => 'ga.types.entertainment',
            self::TYPE_BUSINESS_TRAVEL => 'ga.types.business_travel',
            self::TYPE_REIMBURSE_CLAIM => 'ga.types.reimbursement',
            default => null,
        };

        return $key === null ? $type : __($key);
    }

    public static function eventLabel(string $event): string
    {
        return match ($event) {
            'submitted' => __('ga.events.submitted'),
            'resubmitted' => __('ga.events.resubmitted'),
            'basic_verified' => __('ga.events.basic_verified'),
            'approved' => __('ga.events.approved'),
            'revision_requested' => __('ga.events.revision_requested'),
            'paid' => __('ga.events.paid'),
            default => __('common.unknown'),
        };
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'claim_date' => 'date',
            'amount' => 'decimal:2',
            'revision_number' => 'integer',
            'submitted_at' => 'datetime',
            'basic_verified_at' => 'datetime',
            'finance_verified_at' => 'datetime',
            'ready_to_pay_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(GaClaimReceipt::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(GaClaimDocument::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(GaClaimStatusHistory::class);
    }

    public function paymentItem(): MorphOne
    {
        return $this->morphOne(PaymentItem::class, 'payable');
    }

    public function isReadyToPay(): bool
    {
        return $this->status === self::STATUS_READY_TO_PAY;
    }

    public function scopeEligibleForPaymentBatch($query)
    {
        return $query->where('status', self::STATUS_READY_TO_PAY)
            ->whereDoesntHave('paymentItem', function ($q) {
                $q->where('status', PaymentItem::STATUS_ACTIVE)
                    ->whereHas('group', fn ($g) => $g->where('status', PaymentGroup::STATUS_UNPAID)
                        ->whereHas('batch', fn ($b) => $b->whereIn('status', PaymentBatch::ACTIVE_STATUSES))
                    );
            });
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
