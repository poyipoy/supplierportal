<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(LocalPurchaseOrder::class, 'local_purchase_order_id');
    }

    public function currentInvoice(): BelongsTo
    {
        return $this->belongsTo(LocalInvoice::class, 'current_invoice_id');
    }

    public function getReceivedDateAttribute()
    {
        return $this->gr_date;
    }
}
