<?php

namespace App\Services\Pricing;

use App\Models\PrItem;
use App\Models\QuotationItem;
use App\Support\BusinessTime;
use App\Support\Money;
use DateTimeInterface;

class ReferencePriceBenchmarkResolver
{
    private const SCALE = 4;

    private const WORK = 16;

    public function __construct(
        private readonly HistoricalReferencePriceService $historicalService,
        private readonly ReferencePriceRuleResolver $ruleResolver,
        private readonly AnnualExchangeRateResolver $annualRates,
    ) {}

    public function resolve(
        PrItem $prItem,
        ?QuotationItem $quotationItem = null,
        string $targetCurrency = 'IDR',
        ?int $benchmarkYear = null,
        DateTimeInterface|string|null $historyAsOfAt = null,
        array $excludePoIds = [],
    ): ReferencePriceBenchmarkResult {
        $targetCurrency = strtoupper(trim($targetCurrency));

        // 1. Main PR-based benchmark
        $mainCalc = $this->historicalService->calculateForPrItem(
            prItem: $prItem,
            targetCurrency: $targetCurrency,
            benchmarkYear: $benchmarkYear,
            historyAsOfAt: $historyAsOfAt,
            excludePoIds: $excludePoIds,
        );

        // 2. Optional Supplier Alternative benchmark
        $alternativeCalc = null;
        $hasAlternative = false;

        if ($quotationItem !== null) {
            $offeredRuleResult = $this->ruleResolver->resolveForQuotationItem($quotationItem);

            if ($offeredRuleResult->isMatched() && $offeredRuleResult->rule?->category_key !== $mainCalc->categoryKey) {
                $hasAlternative = true;
                $alternativeCalc = $this->historicalService->calculateForQuotationItem(
                    quotationItem: $quotationItem,
                    targetCurrency: $targetCurrency,
                    benchmarkYear: $benchmarkYear,
                    historyAsOfAt: $historyAsOfAt,
                    excludePoIds: $excludePoIds,
                );
            }
        }

        // 3. Deltas calculation
        $offeredPrice = null;
        $convertedOffer = null;
        $offeredCurrency = null;
        $mainDelta = null;
        $mainDeltaPercent = null;
        $altDelta = null;
        $altDeltaPercent = null;

        if ($quotationItem !== null && $quotationItem->price_per_kg !== null) {
            $offeredPrice = Money::normalize((string) $quotationItem->price_per_kg, self::SCALE);
            $quotation = $quotationItem->relationLoaded('quotation') ? $quotationItem->quotation : $quotationItem->quotation()->first();
            $offeredCurrency = strtoupper((string) ($quotation?->currency ?? 'IDR'));

            $evalYear = $benchmarkYear ?? $this->annualRates->year(BusinessTime::now());
            $converted = $this->annualRates->convert($offeredPrice, $offeredCurrency, $targetCurrency, $evalYear, self::SCALE);

            if ($converted !== null) {
                $convertedOffer = Money::normalize($converted, self::SCALE);

                if ($mainCalc->isMatched()) {
                    $mainDelta = Money::normalize(bcsub($convertedOffer, $mainCalc->weightedAveragePricePerKg, self::WORK), self::SCALE);
                    $mainDeltaPercent = (float) Money::normalize(
                        bcmul(bcdiv($mainDelta, $mainCalc->weightedAveragePricePerKg, self::WORK), '100', self::WORK),
                        2
                    );
                }

                if ($alternativeCalc !== null && $alternativeCalc->isMatched()) {
                    $altDelta = Money::normalize(bcsub($convertedOffer, $alternativeCalc->weightedAveragePricePerKg, self::WORK), self::SCALE);
                    $altDeltaPercent = (float) Money::normalize(
                        bcmul(bcdiv($altDelta, $alternativeCalc->weightedAveragePricePerKg, self::WORK), '100', self::WORK),
                        2
                    );
                }
            }
        }

        return new ReferencePriceBenchmarkResult(
            main: $mainCalc,
            supplierAlternative: $alternativeCalc,
            offeredPricePerKg: $offeredPrice,
            convertedOfferedPricePerKg: $convertedOffer,
            offeredCurrency: $offeredCurrency,
            targetCurrency: $targetCurrency,
            mainDelta: $mainDelta,
            mainDeltaPercent: $mainDeltaPercent,
            alternativeDelta: $altDelta,
            alternativeDeltaPercent: $altDeltaPercent,
            hasAlternative: $hasAlternative,
        );
    }
}
