<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrItem extends Model
{
    public const HS_SOURCE_AUTO = 'auto';

    public const HS_SOURCE_MANUAL = 'manual';

    public const HS_SOURCE_LEGACY = 'legacy';

    public const HS_SOURCES = [self::HS_SOURCE_AUTO, self::HS_SOURCE_MANUAL, self::HS_SOURCE_LEGACY];

    public const WEIGHT_STATUS_CALCULATED = 'calculated';

    public const WEIGHT_STATUS_MANUAL = 'manual';

    public const WEIGHT_STATUS_INCOMPLETE = 'incomplete';

    public const WEIGHT_STATUS_INVALID = 'invalid';

    public const WEIGHT_STATUS_LEGACY = 'legacy';

    public const SHAPE_FLAT = 'Flat';

    public const SHAPE_ROUND = 'Round';

    public const SHAPE_HOLLOW = 'Hollow';

    public const SHAPES = [
        self::SHAPE_FLAT,
        self::SHAPE_ROUND,
        self::SHAPE_HOLLOW,
    ];

    public const DIMENSION_FIELDS = [
        'thickness',
        'd_inner',
        'd_outer',
        'width',
        'length',
    ];

    public const DIMENSION_LABELS = [
        'thickness' => 'Thickness',
        'd_inner' => 'Inner D.',
        'd_outer' => 'Outer D.',
        'width' => 'Width',
        'length' => 'Length',
    ];

    public const FIXED_DIMENSION_ORDER = [
        'thickness',
        'width',
        'd_outer',
        'd_inner',
        'length',
    ];

    public const RELEVANT_DIMENSIONS = [
        self::SHAPE_FLAT => ['thickness', 'width', 'length'],
        self::SHAPE_ROUND => ['d_outer', 'length'],
        self::SHAPE_HOLLOW => ['d_inner', 'd_outer', 'length'],
    ];

    /**
     * Display order is intentionally separate from canonical relevance order.
     * RELEVANT_DIMENSIONS remains the source of truth for sanitization and
     * weight calculation; this contract only describes human-facing order.
     */
    public const PRESENTATION_DIMENSIONS = [
        self::SHAPE_FLAT => ['thickness', 'width', 'length'],
        self::SHAPE_ROUND => ['d_outer', 'length'],
        self::SHAPE_HOLLOW => ['d_outer', 'd_inner', 'length'],
    ];

    protected $fillable = [
        'pr_id',
        'material_master_id',
        'hs_code',
        'hs_code_rule_id',
        'hs_code_source',
        'hs_code_resolution_status',
        'hs_code_manual_selected_by',
        'hs_code_manual_selected_at',
        'material_name',
        'quantity',
        'shape',
        'thickness',
        'd_inner',
        'd_outer',
        'width',
        'length',
        'weight_needed',
        'weight_calculation_status',
        'weight_formula_key',
        'weight_factor',
        'weight_calculated_at',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'thickness' => 'decimal:4',
            'd_inner' => 'decimal:4',
            'd_outer' => 'decimal:4',
            'width' => 'decimal:4',
            'length' => 'decimal:4',
            'weight_needed' => 'decimal:4',
            'weight_factor' => 'decimal:6',
            'quantity' => 'integer',
            'hs_code_manual_selected_at' => 'datetime',
            'weight_calculated_at' => 'datetime',
        ];
    }

    // ─── Relationships ───

    public static function relevantDimensionFields(?string $shape): array
    {
        return self::RELEVANT_DIMENSIONS[$shape] ?? [];
    }

    public static function presentationDimensionFields(?string $shape): array
    {
        return self::PRESENTATION_DIMENSIONS[$shape] ?? [];
    }

    public static function sanitizeMaterialData(array $item): array
    {
        $shape = $item['shape'] ?? null;
        $shape = is_string($shape) && $shape !== '' ? $shape : null;
        $relevantFields = self::relevantDimensionFields($shape);

        $data = [
            'hs_code' => self::nullableString($item['hs_code'] ?? null),
            'material_name' => $item['material_name'] ?? null,
            'quantity' => self::positiveInteger($item['quantity'] ?? null),
            'shape' => $shape,
            'thickness' => self::nullableValue($item['thickness'] ?? null),
            'd_inner' => self::nullableValue($item['d_inner'] ?? null),
            'd_outer' => self::nullableValue($item['d_outer'] ?? null),
            'width' => self::nullableValue($item['width'] ?? null),
            'length' => self::nullableValue($item['length'] ?? null),
            'weight_needed' => $item['weight_needed'] ?? null,
            'remark' => self::nullableString($item['remark'] ?? null),
        ];

        foreach (self::DIMENSION_FIELDS as $field) {
            if (! in_array($field, $relevantFields, true)) {
                $data[$field] = null;
            }
        }

        return $data;
    }

    public function getQuantityValueAttribute(): int
    {
        return max(1, (int) ($this->attributes['quantity'] ?? 1));
    }

    public function getTotalWeightAttribute(): float
    {
        return round((float) $this->weight_needed * $this->quantity_value, 4, PHP_ROUND_HALF_UP);
    }

    public function getDimensionLabelAttribute(): string
    {
        return self::formatDimensionLabel($this->shape, [
            'thickness' => $this->thickness,
            'd_inner' => $this->d_inner,
            'd_outer' => $this->d_outer,
            'width' => $this->width,
            'length' => $this->length,
        ]);
    }

    public static function formatDimensionLabel(?string $shape, array $dimensions): string
    {
        return match ($shape) {
            self::SHAPE_FLAT => implode(' × ', [
                self::formatDimensionValue($dimensions['thickness'] ?? null),
                self::formatDimensionValue($dimensions['width'] ?? null),
                self::formatDimensionValue($dimensions['length'] ?? null),
            ]),
            self::SHAPE_ROUND => 'Ø '.self::formatDimensionValue($dimensions['d_outer'] ?? null)
                .' × '.self::formatDimensionValue($dimensions['length'] ?? null),
            self::SHAPE_HOLLOW => 'Ø '.self::formatDimensionValue($dimensions['d_outer'] ?? null)
                .' × Ø '.self::formatDimensionValue($dimensions['d_inner'] ?? null)
                .' × '.self::formatDimensionValue($dimensions['length'] ?? null),
            default => '-',
        };
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function nullableValue(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }

    private static function positiveInteger(mixed $value): ?int
    {
        if ($value === null) {
            return 1;
        }

        if ($value === '') {
            return null;
        }

        return (int) $value;
    }

    public static function formatDimensionValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        $formatted = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    public function purchaseRequisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'pr_id');
    }

    public function materialMaster(): BelongsTo
    {
        return $this->belongsTo(MaterialMaster::class);
    }

    public function hsCodeRule(): BelongsTo
    {
        return $this->belongsTo(HsCodeRule::class);
    }

    public function hsCodeManualSelector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hs_code_manual_selected_by');
    }

    public function quotationItems(): HasMany
    {
        return $this->hasMany(QuotationItem::class, 'pr_item_id');
    }

    public function qcItems(): HasMany
    {
        return $this->hasMany(QcItem::class, 'pr_item_id');
    }
}
