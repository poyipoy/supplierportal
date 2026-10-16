<?php

namespace App\Services\Pricing;

use App\Models\MaterialReferencePrice;
use App\Models\QuotationItem;
use App\Support\Money;
use App\Support\Pricing\EvaluationContext;
use App\Support\Pricing\ReferencePriceResult;
use Illuminate\Support\Facades\Log;

/**
 * Read side of the Master Reference Price (§12.2, Q6A). Matches on the
 * OFFERED key dimension only (thickness for Flat, outer diameter for
 * Round/Hollow) with no PR fallback. Never writes and never blocks.
 */
class ReferencePriceResolver
{
    private const SCALE = 4;

    public function __construct(private readonly ExchangeRateResolver $rates) {}

    public function resolve(QuotationItem $line, EvaluationContext $context, string $targetCurrency): ReferencePriceResult
    {
        $date = $context->dateString();
        $targetCurrency = strtoupper($targetCurrency);
        $prItem = $line->prItem;
        $offer = $line->price_per_kg === null ? null : Money::normalize((string) $line->price_per_kg, self::SCALE);
        $field = MaterialReferencePrice::DIMENSION_FIELD_BY_SHAPE[$prItem?->shape] ?? null;
        $miss = fn (string $reason, ?string $value = null) => ReferencePriceResult::notAvailable($reason, $date, $targetCurrency, $field, $value, $offer);

        if ($prItem?->material_master_id === null || $field === null) {
            return $miss(ReferencePriceResult::MATERIAL_UNMAPPED);
        }

        $raw = $line->{'available_'.$field};
        if ($raw === null || ! is_numeric($raw) || (float) $raw <= 0) {
            return $miss(ReferencePriceResult::OFFERED_DIMENSION_MISSING);
        }
        $value = Money::normalize((string) $raw, self::SCALE);

        $rows = MaterialReferencePrice::query()
            ->where('material_master_id', $prItem->material_master_id)
            ->where('shape', $prItem->shape)
            ->where('status', MaterialReferencePrice::STATUS_ACTIVE)
            ->get();

        if ($rows->isEmpty()) {
            return $miss(ReferencePriceResult::NO_PRICE_FOR_MATERIAL, $value);
        }

        // Bands are entered as whole numbers, so a fractional offer is matched
        // on its ceiling (10.5 -> band 11-20); the raw value stays in the result.
        $matchValue = $this->ceiling($value);
        $covering = $rows->filter(fn (MaterialReferencePrice $row) => Money::compare($row->dimension_min, $matchValue, self::SCALE) <= 0
            && Money::compare($matchValue, $row->dimension_max, self::SCALE) <= 0);
        if ($covering->isEmpty()) {
            return $miss(ReferencePriceResult::DIMENSION_NOT_COVERED, $value);
        }

        $effective = $covering->filter(fn (MaterialReferencePrice $row) => $row->valid_from->toDateString() <= $date
            && ($row->valid_until === null || $row->valid_until->toDateString() >= $date))->values();
        if ($effective->isEmpty()) {
            return $miss(ReferencePriceResult::PERIOD_NOT_EFFECTIVE, $value);
        }

        // The write rule forbids overlaps within one currency; prefer the
        // target currency, otherwise a single row in another currency.
        $inTarget = $effective->where('currency', $targetCurrency)->values();
        $candidates = $inTarget->isNotEmpty() ? $inTarget : $effective;
        if ($candidates->count() !== 1) {
            Log::warning('Ambiguous reference price configuration.', [
                'quotation_item_id' => $line->id,
                'reference_price_ids' => $candidates->pluck('id')->all(),
            ]);

            return $miss(ReferencePriceResult::AMBIGUOUS_CONFIGURATION, $value);
        }
        $reference = $candidates->first();

        $converted = $this->rates->convert((string) $reference->price_per_kg, $reference->currency, $targetCurrency, $date);
        if ($converted === null) {
            return $miss(ReferencePriceResult::EXCHANGE_RATE_UNAVAILABLE, $value);
        }

        $diff = $offer === null ? null : Money::normalize(bcsub($offer, $converted, 8), self::SCALE);
        $diffPercent = $diff === null || Money::compare($converted, '0', self::SCALE) === 0
            ? null
            : Money::normalize(bcdiv(bcmul($diff, '100', 8), $converted, 8), self::SCALE);

        return ReferencePriceResult::matched(
            $reference, $date, $targetCurrency, $field, $value, $offer, $converted,
            $this->rates->rateAsOf($reference->currency, $date)?->exchangeRateId,
            $this->rates->rateAsOf($targetCurrency, $date)?->exchangeRateId,
            $diff, $diffPercent,
        );
    }

    /**
     * Smallest whole number >= a positive decimal string, without floats.
     */
    private function ceiling(string $value): string
    {
        $whole = bcadd($value, '0', 0);

        return bccomp($value, $whole, self::SCALE) > 0 ? bcadd($whole, '1', 0) : $whole;
    }
}
