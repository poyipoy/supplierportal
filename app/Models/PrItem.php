<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrItem extends Model
{
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

    public const RELEVANT_DIMENSIONS = [
        self::SHAPE_FLAT => ['thickness', 'width', 'length'],
        self::SHAPE_ROUND => ['d_outer', 'length'],
        self::SHAPE_HOLLOW => ['d_inner', 'd_outer', 'length'],
    ];

    protected $fillable = [
        'pr_id',
        'hs_code',
        'material_name',
        'quantity',
        'shape',
        'thickness',
        'd_inner',
        'd_outer',
        'width',
        'length',
        'weight_needed',
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
            'quantity' => 'integer',
        ];
    }

    // ─── Relationships ───

    public static function relevantDimensionFields(?string $shape): array
    {
        return self::RELEVANT_DIMENSIONS[$shape] ?? [];
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
        return (float) $this->weight_needed * $this->quantity_value;
    }

    public function getDimensionLabelAttribute(): string
    {
        return match ($this->shape) {
            self::SHAPE_FLAT => implode(' × ', [
                $this->formatDimensionValue($this->thickness),
                $this->formatDimensionValue($this->width),
                $this->formatDimensionValue($this->length),
            ]),
            self::SHAPE_ROUND => 'Ø ' . $this->formatDimensionValue($this->d_outer)
                . ' × ' . $this->formatDimensionValue($this->length),
            self::SHAPE_HOLLOW => 'Ø ' . $this->formatDimensionValue($this->d_outer)
                . ' × Ø ' . $this->formatDimensionValue($this->d_inner)
                . ' × ' . $this->formatDimensionValue($this->length),
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

    private function formatDimensionValue(mixed $value): string
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

    public function quotationItems(): HasMany
    {
        return $this->hasMany(QuotationItem::class, 'pr_item_id');
    }

    public function qcItems(): HasMany
    {
        return $this->hasMany(QcItem::class, 'pr_item_id');
    }
}
