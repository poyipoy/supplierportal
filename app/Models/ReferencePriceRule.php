<?php

namespace App\Models;

use App\Support\NumberFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferencePriceRule extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'category_key',
        'category_name',
        'dimension_min',
        'dimension_max',
        'sort_order',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'dimension_min' => 'integer',
            'dimension_max' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    public function covers(int $dimension): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $dimension >= $this->dimension_min
            && $dimension <= $this->dimension_max;
    }

    /**
     * Resolve the single key dimension (Thickness for Flat; Diameter for Round/Hollow)
     * and match it against active ReferencePriceRule ranges using CEIL(dimension).
     *
     * @param  iterable<int, self>|null  $activeRules
     * @return array{
     *     shape: ?string,
     *     key_dimension: ?float,
     *     dimension_display: string,
     *     range_classification: string,
     *     category_key: ?string,
     *     category_name: ?string
     * }
     */
    public static function resolveDimensionClassification(
        ?string $shape,
        float|string|null $thickness,
        float|string|null $dOuter,
        ?iterable $activeRules = null
    ): array {
        $canonicalShape = PrItem::canonicalShape($shape) ?? ($shape ? ucfirst(strtolower(trim($shape))) : null);
        $keyDim = null;
        $dimensionDisplay = '-';

        if ($canonicalShape === PrItem::SHAPE_FLAT) {
            if ($thickness !== null && $thickness !== '' && is_numeric($thickness) && (float) $thickness > 0) {
                $keyDim = (float) $thickness;
                $dimensionDisplay = NumberFormat::maxDecimals($keyDim, 4).' mm';
            }
        } elseif ($canonicalShape === PrItem::SHAPE_ROUND || $canonicalShape === PrItem::SHAPE_HOLLOW) {
            if ($dOuter !== null && $dOuter !== '' && is_numeric($dOuter) && (float) $dOuter > 0) {
                $keyDim = (float) $dOuter;
                $dimensionDisplay = 'Ø '.NumberFormat::maxDecimals($keyDim, 4).' mm';
            }
        }

        $matchedRule = null;
        if ($keyDim !== null && $keyDim > 0) {
            $lookupDimension = (int) ceil($keyDim);
            $rules = $activeRules !== null
                ? collect($activeRules)
                : self::query()->active()->orderBy('sort_order')->orderBy('dimension_min')->get();

            $matchedRule = $rules->first(fn (self $rule) => $rule->covers($lookupDimension));
        }

        $rangeLabel = '-';
        if ($matchedRule instanceof self) {
            $rangeSpan = "{$matchedRule->dimension_min}-{$matchedRule->dimension_max} mm";
            $rangeLabel = str_contains($matchedRule->category_name, (string) $matchedRule->dimension_min)
                ? $matchedRule->category_name
                : "{$matchedRule->category_name} ({$rangeSpan})";
        }

        return [
            'shape' => $canonicalShape,
            'key_dimension' => $keyDim,
            'dimension_display' => $dimensionDisplay,
            'range_classification' => $rangeLabel,
            'category_key' => $matchedRule?->category_key,
            'category_name' => $matchedRule?->category_name,
        ];
    }
}
