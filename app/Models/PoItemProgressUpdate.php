<?php

namespace App\Models;

use App\Support\StatusHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoItemProgressUpdate extends Model
{
    public const STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation';

    public const STATUS_ORDER_CONFIRMED = 'order_confirmed';

    public const STATUS_MATERIAL_PREPARATION = 'material_preparation';

    public const STATUS_ON_PRODUCTION = 'on_production';

    public const STATUS_READY_TO_SHIP = 'ready_to_ship';

    public const STAGE_AWAITING_CONFIRMATION = self::STATUS_AWAITING_CONFIRMATION;

    public const STAGE_ORDER_CONFIRMED = self::STATUS_ORDER_CONFIRMED;

    public const STAGE_MATERIAL_PREPARATION = self::STATUS_MATERIAL_PREPARATION;

    public const STAGE_ON_PRODUCTION = self::STATUS_ON_PRODUCTION;

    public const STAGE_READY_TO_SHIP = self::STATUS_READY_TO_SHIP;

    public const STATUSES = [
        self::STATUS_AWAITING_CONFIRMATION,
        self::STATUS_ORDER_CONFIRMED,
        self::STATUS_MATERIAL_PREPARATION,
        self::STATUS_ON_PRODUCTION,
        self::STATUS_READY_TO_SHIP,
    ];

    public const STAGE_RANKS = [
        self::STATUS_AWAITING_CONFIRMATION => 10,
        self::STATUS_ORDER_CONFIRMED => 20,
        self::STATUS_MATERIAL_PREPARATION => 30,
        self::STATUS_ON_PRODUCTION => 40,
        self::STATUS_READY_TO_SHIP => 50,
    ];

    protected $fillable = [
        'pr_item_award_id',
        'status',
        'supplier_controlled_qty_snapshot',
        'estimated_ready_date',
        'note',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'supplier_controlled_qty_snapshot' => 'integer',
            'estimated_ready_date' => 'date',
        ];
    }

    // ─── Relationships ───

    public function prItemAward(): BelongsTo
    {
        return $this->belongsTo(PrItemAward::class, 'pr_item_award_id');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ─── Accessors / Helpers ───

    public function getStatusLabelAttribute(): string
    {
        return StatusHelper::materialProgressLabel($this->status);
    }

    public function getStatusBadgeAttribute(): string
    {
        return StatusHelper::materialProgressBadge($this->status);
    }

    public function getStatusToneAttribute(): string
    {
        return StatusHelper::materialProgressTone($this->status);
    }
}
