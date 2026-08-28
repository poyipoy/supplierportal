<?php

namespace App\Models;

use App\Support\Materials\DimensionRange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class QuotationItem extends Model
{
    public const AVAILABILITY_TOLERANCE = 0.0001;

    public const OFFER_WEIGHT_SOURCE_AUTO = 'auto';

    public const OFFER_WEIGHT_SOURCE_ESTIMATED = 'estimated';

    public const OFFER_WEIGHT_SOURCES = [
        self::OFFER_WEIGHT_SOURCE_AUTO,
        self::OFFER_WEIGHT_SOURCE_ESTIMATED,
    ];

    /**
     * Calculate the amount represented by the persisted PR request.
     *
     * This remains as a separate method because it is useful for comparison
     * and historical/legacy rows.  New quotation rows use calculateOfferAmount
     * for the authoritative stored amount.
     */
    public static function calculateRequestedAmount(PrItem $prItem, mixed $pricePerKg): ?float
    {
        if ($pricePerKg === null || $pricePerKg === '' || ! is_numeric($pricePerKg)) {
            return null;
        }

        return round(
            (float) $pricePerKg * $prItem->total_weight,
            4,
            PHP_ROUND_HALF_UP,
        );
    }

    /**
     * Calculate an offer amount from the supplier's offered total KG.
     * Browser-calculated totals are never trusted for persistence.
     */
    public static function calculateOfferAmount(mixed $offerTotalWeight, mixed $pricePerKg): float
    {
        if ($offerTotalWeight === null || $pricePerKg === null
            || $offerTotalWeight === '' || $pricePerKg === ''
            || ! is_numeric($offerTotalWeight) || ! is_numeric($pricePerKg)) {
            return 0.0;
        }

        return round(
            (float) $offerTotalWeight * (float) $pricePerKg,
            4,
            PHP_ROUND_HALF_UP,
        );
    }

    /**
     * Backward-compatible alias. Historically this method calculated the PR
     * requested amount, so keep that meaning for legacy callers.
     */
    public static function calculateAmount(PrItem $prItem, mixed $pricePerKg): float
    {
        return self::calculateRequestedAmount($prItem, $pricePerKg) ?? 0.0;
    }

    /**
     * Keep legacy snapshots intact while providing a safe display fallback
     * for old zero rows. New rows have a stored Offer Amount and return it.
     */
    public function getResolvedAmountAttribute(): float
    {
        $storedAmount = (float) ($this->amount ?? 0);

        if (! $this->isAvailable()) {
            return 0.0;
        }

        if ($storedAmount > 0) {
            return $storedAmount;
        }

        $prItem = $this->prItem;
        if (! $prItem || (float) ($this->price_per_kg ?? 0) <= 0) {
            return $storedAmount;
        }

        // A new available row with offer fields can be repaired/displayed
        // from its authoritative offer inputs. Legacy rows retain the old
        // requested-total fallback when those fields are absent.
        if ($this->offered_total_weight !== null) {
            return self::calculateOfferAmount($this->offered_total_weight, $this->price_per_kg);
        }

        if ($prItem->total_weight <= 0) {
            return $storedAmount;
        }

        return self::calculateRequestedAmount($prItem, $this->price_per_kg) ?? $storedAmount;
    }

    protected $fillable = [
        'quotation_id',
        'pr_item_id',
        'is_available',
        'price_per_kg',
        'amount',
        'available_qty',
        'available_thickness',
        'available_d_inner',
        'available_d_outer',
        'available_width',
        'available_length',
        'available_length_min',
        'available_length_max',
        'offered_weight_per_unit',
        'offered_weight_source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'price_per_kg' => 'decimal:4',
            'amount' => 'decimal:4',
            'available_qty' => 'integer',
            'available_thickness' => 'decimal:4',
            'available_d_inner' => 'decimal:4',
            'available_d_outer' => 'decimal:4',
            'available_width' => 'decimal:4',
            'available_length' => 'decimal:4',
            'available_length_min' => 'decimal:4',
            'available_length_max' => 'decimal:4',
            'offered_weight_per_unit' => 'decimal:4',
        ];
    }

    /**
     * A missing state is treated as available for rows created before the
     * additive migration.  Numeric string "0" must still be false.
     */
    public function getIsAvailableAttribute(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ?? true;
    }

    public function isAvailable(): bool
    {
        return $this->is_available;
    }

    public function getRequestedQuantityAttribute(): ?int
    {
        return $this->prItem?->quantity_value;
    }

    public function getOfferedQuantityAttribute(): ?int
    {
        return $this->isAvailable() ? $this->available_qty : 0;
    }

    public function getRequestedWeightPerUnitAttribute(): ?float
    {
        return $this->prItem ? (float) $this->prItem->weight_needed : null;
    }

    public function getRequestedTotalWeightAttribute(): ?float
    {
        return $this->prItem ? (float) $this->prItem->total_weight : null;
    }

    public function getOfferedTotalWeightAttribute(): ?float
    {
        if (! $this->isAvailable()) {
            return 0.0;
        }

        if ($this->available_qty !== null && $this->offered_weight_per_unit !== null) {
            return round(
                (float) $this->available_qty * (float) $this->offered_weight_per_unit,
                4,
                PHP_ROUND_HALF_UP,
            );
        }

        // Existing rows have no supplier weight field. This fallback is only
        // for display/legacy compatibility and is never used when saving a
        // new available offer.
        if ($this->available_qty !== null && $this->prItem) {
            return round(
                (float) $this->available_qty * (float) $this->prItem->weight_needed,
                4,
                PHP_ROUND_HALF_UP,
            );
        }

        return null;
    }

    /**
     * Alias used by import/detail contracts that call the derived value
     * "offer_total_weight" rather than "offered_total_weight".
     */
    public function getOfferTotalWeightAttribute(): ?float
    {
        return $this->offered_total_weight;
    }

    public function getRequestedAmountAttribute(): ?float
    {
        if ($this->prItem === null || $this->price_per_kg === null) {
            return null;
        }

        return self::calculateRequestedAmount($this->prItem, $this->price_per_kg);
    }

    public function getOfferAmountAttribute(): ?float
    {
        if (! $this->isAvailable()) {
            return 0.0;
        }

        if ($this->price_per_kg === null) {
            return null;
        }

        $storedAmount = (float) ($this->amount ?? 0);
        if ($storedAmount > 0) {
            return $storedAmount;
        }

        if ($this->offered_total_weight !== null) {
            return self::calculateOfferAmount($this->offered_total_weight, $this->price_per_kg);
        }

        return $this->requested_amount;
    }

    public function getIsEstimatedWeightAttribute(): bool
    {
        return $this->offered_weight_source === self::OFFER_WEIGHT_SOURCE_ESTIMATED;
    }

    public function getAvailableLengthDisplayAttribute(): string
    {
        if (! $this->isAvailable()) {
            return '-';
        }

        $range = $this->availableLengthRange();

        return $range?->display() ?? '-';
    }

    public function getOfferedDimensionLabelAttribute(): string
    {
        return $this->available_dimension_label;
    }

    /**
     * Normalize supplier availability using the requested item's fixed shape.
     * Non-relevant dimensions and all commercial/offer fields are cleared for
     * an explicit Not Available row.
     */
    public static function sanitizeAvailabilityData(array $item, PrItem $prItem): array
    {
        $isAvailable = self::normalizeAvailabilityState(
            $item['is_available'] ?? ($item['availability'] ?? true),
        );

        $availability = [
            'is_available' => $isAvailable,
            'available_qty' => self::nullableInteger($item['available_qty'] ?? null),
            'available_thickness' => self::nullableValue($item['available_thickness'] ?? null),
            'available_d_inner' => self::nullableValue($item['available_d_inner'] ?? null),
            'available_d_outer' => self::nullableValue($item['available_d_outer'] ?? null),
            'available_width' => self::nullableValue($item['available_width'] ?? null),
            'available_length' => null,
            'available_length_min' => null,
            'available_length_max' => null,
            'offered_weight_per_unit' => self::nullableValue($item['offered_weight_per_unit'] ?? null),
            'offered_weight_source' => self::normalizeWeightSource($item['offered_weight_source'] ?? null),
        ];

        if (! $isAvailable) {
            return [
                'is_available' => false,
                'available_qty' => null,
                'available_thickness' => null,
                'available_d_inner' => null,
                'available_d_outer' => null,
                'available_width' => null,
                'available_length' => null,
                'available_length_min' => null,
                'available_length_max' => null,
                'offered_weight_per_unit' => null,
                'offered_weight_source' => null,
                'price_per_kg' => null,
            ];
        }

        $lengthValue = array_key_exists('available_length_input', $item)
            ? $item['available_length_input']
            : ($item['available_length'] ?? null);

        if (($lengthValue === null || $lengthValue === '')
            && ($item['available_length_min'] ?? null) !== null
            && ($item['available_length_max'] ?? null) !== null) {
            $lengthValue = (string) $item['available_length_min'].'-'.(string) $item['available_length_max'];
        }

        $length = DimensionRange::parse($lengthValue);
        if ($length !== null) {
            $availability = array_merge($availability, $length->toPersistence());
        }

        $relevantFields = PrItem::relevantDimensionFields($prItem->shape);
        foreach (PrItem::DIMENSION_FIELDS as $field) {
            if (! in_array($field, $relevantFields, true)) {
                $availability['available_'.$field] = null;
            }
        }

        return $availability;
    }

    public static function normalizeAvailabilityState(mixed $value): bool
    {
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['not available', 'unavailable', 'no', 'false', '0'], true)) {
                return false;
            }
            if (in_array($normalized, ['available', 'yes', 'true', '1'], true)) {
                return true;
            }
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        return $value !== null;
    }

    public function availableLengthRange(): ?DimensionRange
    {
        if ($this->available_length_min !== null && $this->available_length_max !== null) {
            return new DimensionRange(
                null,
                (float) $this->available_length_min,
                (float) $this->available_length_max,
            );
        }

        if ($this->available_length !== null) {
            return new DimensionRange((float) $this->available_length, null, null);
        }

        return null;
    }

    public function getAvailableDimensionLabelAttribute(): string
    {
        $prItem = $this->prItem;
        if (! $prItem || ! $this->isAvailable()) {
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
        $hasLengthRange = $this->available_length_min !== null && $this->available_length_max !== null;

        if ($relevantFields === [] || collect($relevantFields)->every(function (string $field) use ($dimensions, $hasLengthRange): bool {
            return $dimensions[$field] === null && ! ($field === 'length' && $hasLengthRange);
        })) {
            return '-';
        }

        if (! $hasLengthRange) {
            return PrItem::formatDimensionLabel($prItem->shape, $dimensions);
        }

        $length = $this->available_length_display;

        return match ($prItem->shape) {
            PrItem::SHAPE_FLAT => implode(' × ', [
                PrItem::formatDimensionValue($dimensions['thickness'] ?? null),
                PrItem::formatDimensionValue($dimensions['width'] ?? null),
                $length,
            ]),
            PrItem::SHAPE_ROUND => 'Ø '.PrItem::formatDimensionValue($dimensions['d_outer'] ?? null)
                .' × '.$length,
            PrItem::SHAPE_HOLLOW => 'Ø '.PrItem::formatDimensionValue($dimensions['d_outer'] ?? null)
                .' × Ø '.PrItem::formatDimensionValue($dimensions['d_inner'] ?? null)
                .' × '.$length,
            default => '-',
        };
    }

    /**
     * Comparison data for Purchasing review. Quantity and specification are
     * intentionally independent so a shortage does not mask a dimension
     * mismatch (and vice versa).
     *
     * @return array{quantity: array{code: string, label: string}, specification: array{code: string, label: string}}
     */
    public function getAvailabilityComparisonAttribute(): array
    {
        $prItem = $this->prItem;
        if (! $this->isAvailable()) {
            $notAvailable = self::status('not_available', 'Not Available');

            return ['quantity' => $notAvailable, 'specification' => $notAvailable];
        }

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
        if ($fields === []) {
            return compact('quantity') + ['specification' => self::status('not_specified', 'Not Specified')];
        }

        $hasAny = false;
        $allSpecified = true;
        $allMatch = true;
        $rangeMatched = false;

        foreach ($fields as $field) {
            if ($field === 'length' && $this->available_length_min !== null && $this->available_length_max !== null) {
                $hasAny = true;
                $rangeMatched = true;
                if ($prItem->length === null || ! (new DimensionRange(
                    null,
                    (float) $this->available_length_min,
                    (float) $this->available_length_max,
                ))->contains((float) $prItem->length)) {
                    $allMatch = false;
                }

                continue;
            }

            $value = $this->{'available_'.$field};
            if ($value !== null) {
                $hasAny = true;
            } else {
                $allSpecified = false;

                continue;
            }

            if ($prItem->{$field} === null
                || abs((float) $value - (float) $prItem->{$field}) > self::AVAILABILITY_TOLERANCE) {
                $allMatch = false;
            }
        }

        if (! $hasAny) {
            $specification = self::status('not_specified', 'Not Specified');
        } elseif (! $allSpecified || ! $allMatch) {
            $specification = self::status('different', 'Different Specification');
        } elseif ($rangeMatched) {
            $specification = self::status('within_range', 'Requested Within Offered Range');
        } else {
            $specification = self::status('exact', 'Exact Match');
        }

        return compact('quantity', 'specification');
    }

    private static function nullableInteger(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private static function nullableValue(mixed $value): mixed
    {
        return $value === null || $value === '' ? null : $value;
    }

    private static function normalizeWeightSource(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : $value;

        return in_array($value, self::OFFER_WEIGHT_SOURCES, true) ? $value : null;
    }

    private static function status(string $code, string $label): array
    {
        return compact('code', 'label');
    }

    // ── Relationships ──

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
