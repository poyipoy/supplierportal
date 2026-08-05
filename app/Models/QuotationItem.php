<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class QuotationItem extends Model
{
    public const AVAILABILITY_TOLERANCE = 0.0001;

    protected $fillable = [
        'quotation_id',
        'pr_item_id',
        'price_per_kg',
        'amount',
        'available_qty',
        'available_thickness',
        'available_d_inner',
        'available_d_outer',
        'available_width',
        'available_length',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'price_per_kg' => 'decimal:4',
            'amount' => 'decimal:4',
            'available_qty' => 'integer',
            'available_thickness' => 'decimal:4',
            'available_d_inner' => 'decimal:4',
            'available_d_outer' => 'decimal:4',
            'available_width' => 'decimal:4',
            'available_length' => 'decimal:4',
        ];
    }

    /**
     * Normalize supplier availability using the requested item's fixed shape.
     * Non-relevant dimensions must never be persisted from a tampered request.
     */
    public static function sanitizeAvailabilityData(array $item, PrItem $prItem): array
    {
        $availability = [
            'available_qty' => self::nullableInteger($item['available_qty'] ?? null),
            'available_thickness' => self::nullableValue($item['available_thickness'] ?? null),
            'available_d_inner' => self::nullableValue($item['available_d_inner'] ?? null),
            'available_d_outer' => self::nullableValue($item['available_d_outer'] ?? null),
            'available_width' => self::nullableValue($item['available_width'] ?? null),
            'available_length' => self::nullableValue($item['available_length'] ?? null),
        ];

        $relevantFields = PrItem::relevantDimensionFields($prItem->shape);
        foreach (PrItem::DIMENSION_FIELDS as $field) {
            if (! in_array($field, $relevantFields, true)) {
                $availability['available_'.$field] = null;
            }
        }

        return $availability;
    }

    public function getAvailableDimensionLabelAttribute(): string
    {
        $prItem = $this->prItem;
        if (! $prItem) {
            return '-';
        }

        $dimensions = [
            'thickness' => $this->available_thickness,
            'd_inner' => $this->available_d_inner,
            'd_outer' => $this->available_d_outer,
            'width' => $this->available_width,
            'length' => $this->available_length,
        ];
        $relevantFields = PrItem::relevantDimensionFields($prItem->shape);

        if ($relevantFields === [] || collect($relevantFields)->every(fn (string $field) => $dimensions[$field] === null)) {
            return '-';
        }

        return PrItem::formatDimensionLabel($prItem->shape, $dimensions);
    }

    /**
     * Comparison data for Purchasing review. Presentation classes remain in the view.
     *
     * @return array{quantity: array{code: string, label: string}, specification: array{code: string, label: string}}
     */
    public function getAvailabilityComparisonAttribute(): array
    {
        $prItem = $this->prItem;
        if (! $prItem) {
            return [
                'quantity' => self::status('not_specified', 'Quantity Not Specified'),
                'specification' => self::status('not_specified', 'Not Specified'),
            ];
        }

        $quantity = match (true) {
            $this->available_qty === null => self::status('not_specified', 'Quantity Not Specified'),
            $this->available_qty < $prItem->quantity_value => self::status('shortage', 'Quantity Shortage'),
            $this->available_qty > $prItem->quantity_value => self::status('surplus', 'Quantity Surplus'),
            default => self::status('match', 'Quantity Match'),
        };

        $fields = PrItem::relevantDimensionFields($prItem->shape);
        $availableDimensions = collect($fields)
            ->mapWithKeys(fn (string $field) => [$field => $this->{'available_'.$field}]);

        if ($fields === [] || $availableDimensions->every(fn ($value) => $value === null)) {
            $specification = self::status('not_specified', 'Not Specified');
        } elseif ($availableDimensions->contains(fn ($value) => $value === null)) {
            $specification = self::status('different', 'Different Specification');
        } else {
            $isExact = $availableDimensions->every(function ($value, string $field) use ($prItem) {
                return abs((float) $value - (float) $prItem->{$field}) <= self::AVAILABILITY_TOLERANCE;
            });

            $specification = $isExact
                ? self::status('exact', 'Exact Match')
                : self::status('different', 'Different Specification');
        }

        return compact('quantity', 'specification');
    }

    private static function nullableInteger(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private static function nullableValue(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }

    private static function status(string $code, string $label): array
    {
        return compact('code', 'label');
    }

    // ─── Relationships ───

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function prItem(): BelongsTo
    {
        return $this->belongsTo(PrItem::class, 'pr_item_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
