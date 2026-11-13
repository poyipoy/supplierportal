<?php

namespace App\Models;

use App\Casts\LocalGoodsReceiptStatusCast;
use App\Enums\LocalGoodsReceiptStatus;
use App\Support\Money;
use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class LocalGoodsReceipt extends Model
{
    use HasFactory, HasHashids;

    public const STATUS_AVAILABLE = 'AVAILABLE';

    public const STATUS_RESERVED = 'RESERVED';

    public const STATUS_INVOICED = 'INVOICED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const UOMS = ['box', 'doz', 'kg', 'lbr', 'ltr', 'mtr', 'pcs', 'rim', 'rol', 'set', 'unt'];

    public const QUANTITY_PATTERN = '/^\d{1,8}(?:\.\d{1,3})?$/';

    public static function normalizeUom(mixed $value): string
    {
        return is_string($value) ? strtolower(trim($value)) : '';
    }

    public static function isSupportedUom(mixed $value): bool
    {
        return in_array(self::normalizeUom($value), self::UOMS, true);
    }

    public static function validQuantity(mixed $value): bool
    {
        $value = trim((string) $value);

        return preg_match(self::QUANTITY_PATTERN, $value) === 1 && bccomp($value, '0', 3) > 0;
    }

    public static function formatQuantity(mixed $value): string
    {
        return $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 4, ',', '.'), '0'), ',');
    }

    protected $fillable = [
        'gr_number',
        'local_purchase_order_id',
        'gr_date',
        'qty',
        'uom',
        'unit_price',
        'receipt_amount',
        'po_line_reference',
        'description',
        'notes',
        'status',
        'current_invoice_id',
        'source',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'gr_date' => 'date',
        'qty' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'receipt_amount' => 'decimal:2',
        'status' => LocalGoodsReceiptStatusCast::class,
    ];

    public function statusEnum(): LocalGoodsReceiptStatus
    {
        return LocalGoodsReceiptStatus::tryFromWithLegacy((string) $this->status);
    }

    public function isAvailable(): bool
    {
        return LocalGoodsReceiptStatus::tryFromWithLegacyOrNull((string) $this->status) === LocalGoodsReceiptStatus::AVAILABLE;
    }

    public function isReserved(): bool
    {
        return LocalGoodsReceiptStatus::tryFromWithLegacyOrNull((string) $this->status) === LocalGoodsReceiptStatus::RESERVED;
    }

    public function isInvoiced(): bool
    {
        return LocalGoodsReceiptStatus::tryFromWithLegacyOrNull((string) $this->status) === LocalGoodsReceiptStatus::INVOICED;
    }

    public function isCancelled(): bool
    {
        return LocalGoodsReceiptStatus::tryFromWithLegacyOrNull((string) $this->status) === LocalGoodsReceiptStatus::CANCELLED;
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(LocalPurchaseOrder::class, 'local_purchase_order_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function latestGrAttachment(): MorphOne
    {
        return $this->morphOne(Attachment::class, 'attachable')->latestOfMany('id');
    }

    /** Read once per instance; list feeds eager-load `latestGrAttachment` instead of querying per row. */
    public function latestGrDocument(): ?Attachment
    {
        return $this->loadMissing('latestGrAttachment')->latestGrAttachment;
    }

    public function currentInvoice(): BelongsTo
    {
        return $this->belongsTo(LocalInvoice::class, 'current_invoice_id');
    }

    public function getReceivedDateAttribute()
    {
        return $this->gr_date;
    }

    public function effectiveReceiptAmount(): ?string
    {
        if ($this->receipt_amount !== null && trim((string) $this->receipt_amount) !== '') {
            return Money::normalize($this->receipt_amount);
        }

        if ($this->unit_price !== null && trim((string) $this->unit_price) !== '' && $this->qty !== null) {
            return Money::multiply((string) $this->qty, (string) $this->unit_price);
        }

        return null;
    }
}
