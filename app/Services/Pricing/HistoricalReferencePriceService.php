<?php

namespace App\Services\Pricing;

use App\Models\PrItem;
use App\Models\QuotationItem;
use App\Models\ReferencePriceRule;
use App\Support\BusinessTime;
use App\Support\Money;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HistoricalReferencePriceService
{
    private const SCALE = 4;

    private const WORK = 16;

    /**
     * Request-scoped memoization cache.
     *
     * @var array<string, HistoricalCalculationResult>
     */
    private array $memo = [];

    public function __construct(
        private readonly AnnualExchangeRateResolver $annualRates,
        private readonly ReferencePriceRuleResolver $ruleResolver,
    ) {}

    public function calculateForPrItem(
        PrItem $prItem,
        string $targetCurrency = 'IDR',
        ?int $benchmarkYear = null,
        DateTimeInterface|string|null $historyAsOfAt = null,
        array $excludePoIds = [],
    ): HistoricalCalculationResult {
        $targetCurrency = strtoupper(trim($targetCurrency));
        $materialMasterId = $prItem->material_master_id;
        $shape = $prItem->shape;

        if ($materialMasterId === null) {
            return HistoricalCalculationResult::notAvailable(
                reasonCode: 'material_unmapped',
                currency: $targetCurrency,
                shape: $shape,
                benchmarkYear: $benchmarkYear,
                historyAsOfAt: $this->formatCutoff($historyAsOfAt),
            );
        }

        $ruleResult = $this->ruleResolver->resolveForPrItem($prItem);

        if (! $ruleResult->isMatched()) {
            return HistoricalCalculationResult::notAvailable(
                reasonCode: $ruleResult->reasonCode ?? 'dimension_not_covered',
                currency: $targetCurrency,
                materialMasterId: $materialMasterId,
                shape: $shape,
                dimensionField: $ruleResult->dimensionField,
                rawDimension: $ruleResult->rawDimension,
                roundedDimension: $ruleResult->roundedDimension,
                ruleId: $ruleResult->rule?->id,
                categoryKey: $ruleResult->rule?->category_key,
                benchmarkYear: $benchmarkYear,
                historyAsOfAt: $this->formatCutoff($historyAsOfAt),
                rule: $ruleResult->rule,
            );
        }

        return $this->calculate(
            materialMasterId: $materialMasterId,
            shape: $shape,
            rule: $ruleResult->rule,
            targetCurrency: $targetCurrency,
            benchmarkYear: $benchmarkYear,
            historyAsOfAt: $historyAsOfAt,
            excludePoIds: $excludePoIds,
            rawDimension: $ruleResult->rawDimension,
            roundedDimension: $ruleResult->roundedDimension,
            basisType: 'requested',
        );
    }

    public function calculateForQuotationItem(
        QuotationItem $quotationItem,
        string $targetCurrency = 'IDR',
        ?int $benchmarkYear = null,
        DateTimeInterface|string|null $historyAsOfAt = null,
        array $excludePoIds = [],
    ): HistoricalCalculationResult {
        $targetCurrency = strtoupper(trim($targetCurrency));
        $prItem = $quotationItem->prItem;
        $materialMasterId = $prItem?->material_master_id;
        $shape = $prItem?->shape;

        if ($materialMasterId === null) {
            return HistoricalCalculationResult::notAvailable(
                reasonCode: 'material_unmapped',
                currency: $targetCurrency,
                shape: $shape,
                benchmarkYear: $benchmarkYear,
                historyAsOfAt: $this->formatCutoff($historyAsOfAt),
            );
        }

        $ruleResult = $this->ruleResolver->resolveForQuotationItem($quotationItem);

        if (! $ruleResult->isMatched()) {
            return HistoricalCalculationResult::notAvailable(
                reasonCode: $ruleResult->reasonCode ?? 'dimension_not_covered',
                currency: $targetCurrency,
                materialMasterId: $materialMasterId,
                shape: $shape,
                dimensionField: $ruleResult->dimensionField,
                rawDimension: $ruleResult->rawDimension,
                roundedDimension: $ruleResult->roundedDimension,
                ruleId: $ruleResult->rule?->id,
                categoryKey: $ruleResult->rule?->category_key,
                benchmarkYear: $benchmarkYear,
                historyAsOfAt: $this->formatCutoff($historyAsOfAt),
                rule: $ruleResult->rule,
            );
        }

        return $this->calculate(
            materialMasterId: $materialMasterId,
            shape: $shape,
            rule: $ruleResult->rule,
            targetCurrency: $targetCurrency,
            benchmarkYear: $benchmarkYear,
            historyAsOfAt: $historyAsOfAt,
            excludePoIds: $excludePoIds,
            rawDimension: $ruleResult->rawDimension,
            roundedDimension: $ruleResult->roundedDimension,
            basisType: 'supplier_offered',
        );
    }

    public function calculate(
        int $materialMasterId,
        string $shape,
        ReferencePriceRule $rule,
        string $targetCurrency = 'IDR',
        ?int $benchmarkYear = null,
        DateTimeInterface|string|null $historyAsOfAt = null,
        array $excludePoIds = [],
        ?string $rawDimension = null,
        ?int $roundedDimension = null,
        string $basisType = 'requested',
    ): HistoricalCalculationResult {
        $targetCurrency = strtoupper(trim($targetCurrency));
        $cutoffStr = $this->formatCutoff($historyAsOfAt);
        $currentYear = $this->annualRates->year(BusinessTime::now());
        $benchmarkYear ??= ($historyAsOfAt !== null ? $this->annualRates->year($historyAsOfAt) : $currentYear);
        $isCurrentYear = ($benchmarkYear === $currentYear);

        $dimensionField = ReferencePriceRuleResolver::DIMENSION_FIELD_BY_SHAPE[$shape] ?? null;

        $calcKey = self::buildCalculationKey(
            materialMasterId: $materialMasterId,
            shape: $shape,
            ruleId: $rule->id,
            benchmarkYear: $benchmarkYear,
            targetCurrency: $targetCurrency,
            basisType: $basisType,
            historyAsOfAt: $cutoffStr,
        );

        if (isset($this->memo[$calcKey])) {
            return $this->memo[$calcKey];
        }

        $lines = $this->getEligibleHistoricalLines(
            materialMasterId: $materialMasterId,
            shape: $shape,
            rule: $rule,
            historyAsOfAt: $cutoffStr,
            excludePoIds: $excludePoIds,
        );

        // Group lines by calendar year
        $linesByYear = [];
        foreach ($lines as $line) {
            $year = $this->annualRates->year($line->po_created_at);
            $linesByYear[$year][] = $line;
        }

        $yearlySummaries = [];
        krsort($linesByYear); // Sort years descending

        foreach ($linesByYear as $year => $yearLines) {
            $yearlySummaries[$year] = $this->summarizeYearLines($yearLines, $year, $targetCurrency);
        }

        $targetYearSummary = $yearlySummaries[$benchmarkYear] ?? null;

        if ($targetYearSummary === null || $targetYearSummary['status'] !== 'matched') {
            $reasonCode = 'no_eligible_historical_line';
            if ($targetYearSummary !== null && $targetYearSummary['excluded_line_count'] > 0 && $targetYearSummary['line_count'] === 0) {
                $reasonCode = 'exchange_rate_unavailable';
            }

            $result = HistoricalCalculationResult::notAvailable(
                reasonCode: $reasonCode,
                currency: $targetCurrency,
                materialMasterId: $materialMasterId,
                shape: $shape,
                dimensionField: $dimensionField,
                rawDimension: $rawDimension,
                roundedDimension: $roundedDimension,
                ruleId: $rule->id,
                categoryKey: $rule->category_key,
                benchmarkYear: $benchmarkYear,
                isCurrentYear: $isCurrentYear,
                historyAsOfAt: $cutoffStr,
                rateMetadata: $this->annualRates->metadata(),
                yearlySummaries: $yearlySummaries,
                rule: $rule,
                excludedLineCount: $targetYearSummary['excluded_line_count'] ?? 0,
                excludedTotalKg: $targetYearSummary['excluded_total_kg'] ?? '0.0000',
                excludedCurrencies: $targetYearSummary['excluded_currencies'] ?? [],
                sourceLines: $targetYearSummary['source_lines'] ?? [],
            );

            $this->memo[$calcKey] = $result;

            return $result;
        }

        $result = new HistoricalCalculationResult(
            status: HistoricalCalculationResult::STATUS_MATCHED,
            reasonCode: null,
            materialMasterId: $materialMasterId,
            shape: $shape,
            dimensionField: $dimensionField,
            rawDimension: $rawDimension,
            roundedDimension: $roundedDimension,
            ruleSetId: null,
            ruleId: $rule->id,
            categoryKey: $rule->category_key,
            benchmarkYear: $benchmarkYear,
            isCurrentYear: $isCurrentYear,
            currency: $targetCurrency,
            calculationMethod: 'weighted_average_kg',
            weightedAveragePricePerKg: $targetYearSummary['weighted_average_price_per_kg'],
            lowestPricePerKg: $targetYearSummary['lowest_price_per_kg'],
            highestPricePerKg: $targetYearSummary['highest_price_per_kg'],
            lastPurchasePricePerKg: $targetYearSummary['last_purchase_price_per_kg'],
            latestPoAt: $targetYearSummary['latest_po_at'],
            lineCount: $targetYearSummary['line_count'],
            poCount: $targetYearSummary['po_count'],
            totalKg: $targetYearSummary['total_kg'],
            excludedLineCount: $targetYearSummary['excluded_line_count'],
            excludedTotalKg: $targetYearSummary['excluded_total_kg'],
            excludedCurrencies: $targetYearSummary['excluded_currencies'],
            historyAsOfAt: $cutoffStr,
            rateMetadata: $this->annualRates->metadata(),
            sourceLines: $targetYearSummary['source_lines'],
            rule: $rule,
            yearlySummaries: $yearlySummaries,
        );

        $this->memo[$calcKey] = $result;

        return $result;
    }

    /**
     * @param  list<object>  $lines
     * @return array<string, mixed>
     */
    private function summarizeYearLines(array $lines, int $year, string $targetCurrency): array
    {
        $sumAmount = '0';
        $sumKg = '0';
        $minPrice = null;
        $maxPrice = null;
        $lastPrice = null;
        $latestPoAt = null;
        $latestPoId = null;
        $latestQiId = null;
        $poIds = [];
        $lineCount = 0;
        $excludedLineCount = 0;
        $excludedTotalKg = '0';
        $excludedCurrencies = [];
        $sourceLines = [];

        foreach ($lines as $line) {
            $origPrice = (string) $line->price_per_kg;
            $kg = (string) $line->kg;
            $currency = (string) $line->currency;

            $converted = $this->annualRates->convert($origPrice, $currency, $targetCurrency, $year, self::SCALE);

            if ($converted === null) {
                $excludedLineCount++;
                $excludedTotalKg = bcadd($excludedTotalKg, $kg, self::SCALE);
                $excludedCurrencies[$currency] = true;

                $sourceLines[] = [
                    'quotation_item_id' => (int) $line->quotation_item_id,
                    'po_id' => (int) $line->po_id,
                    'po_number' => $line->po_number,
                    'po_created_at' => (string) $line->po_created_at,
                    'supplier_name' => $line->supplier_name,
                    'pr_id' => $line->pr_id ? (int) $line->pr_id : null,
                    'pr_number' => $line->pr_number,
                    'currency' => $currency,
                    'price_per_kg' => Money::normalize($origPrice, self::SCALE),
                    'kg' => Money::normalize($kg, self::SCALE),
                    'converted_price_per_kg' => null,
                    'converted_total' => null,
                    'is_excluded' => true,
                    'exclusion_reason' => 'exchange_rate_missing',
                ];

                continue;
            }

            $lineAmount = bcmul($converted, $kg, self::WORK);
            $sumAmount = bcadd($sumAmount, $lineAmount, self::WORK);
            $sumKg = bcadd($sumKg, $kg, self::WORK);
            $lineCount++;
            $poIds[$line->po_id] = true;

            if ($minPrice === null || bccomp($converted, $minPrice, self::SCALE) < 0) {
                $minPrice = $converted;
            }
            if ($maxPrice === null || bccomp($converted, $maxPrice, self::SCALE) > 0) {
                $maxPrice = $converted;
            }

            // Stable tie-breaker for last purchase price:
            // 1. po_created_at DESC
            // 2. po_id DESC
            // 3. quotation_item_id DESC
            $isNewer = false;
            if ($latestPoAt === null) {
                $isNewer = true;
            } else {
                $cmp = strcmp((string) $line->po_created_at, $latestPoAt);
                if ($cmp > 0) {
                    $isNewer = true;
                } elseif ($cmp === 0) {
                    if ((int) $line->po_id > $latestPoId) {
                        $isNewer = true;
                    } elseif ((int) $line->po_id === $latestPoId && (int) $line->quotation_item_id > $latestQiId) {
                        $isNewer = true;
                    }
                }
            }

            if ($isNewer) {
                $latestPoAt = (string) $line->po_created_at;
                $latestPoId = (int) $line->po_id;
                $latestQiId = (int) $line->quotation_item_id;
                $lastPrice = $converted;
            }

            $sourceLines[] = [
                'quotation_item_id' => (int) $line->quotation_item_id,
                'po_id' => (int) $line->po_id,
                'po_number' => $line->po_number,
                'po_created_at' => (string) $line->po_created_at,
                'supplier_name' => $line->supplier_name,
                'pr_id' => $line->pr_id ? (int) $line->pr_id : null,
                'pr_number' => $line->pr_number,
                'currency' => $currency,
                'price_per_kg' => Money::normalize($origPrice, self::SCALE),
                'kg' => Money::normalize($kg, self::SCALE),
                'converted_price_per_kg' => Money::normalize($converted, self::SCALE),
                'converted_total' => Money::normalize(bcmul($converted, $kg, self::WORK), self::SCALE),
                'is_excluded' => false,
            ];
        }

        if ($lineCount > 0 && bccomp($sumKg, '0', self::SCALE) > 0) {
            $weightedAverage = Money::normalize(bcdiv($sumAmount, $sumKg, self::WORK), self::SCALE);
            $status = 'matched';
            $reasonCode = null;
        } else {
            $weightedAverage = null;
            $status = 'not_available';
            $reasonCode = $excludedLineCount > 0 ? 'exchange_rate_unavailable' : 'no_eligible_historical_line';
        }

        return [
            'year' => $year,
            'status' => $status,
            'reason_code' => $reasonCode,
            'weighted_average_price_per_kg' => $weightedAverage,
            'lowest_price_per_kg' => $minPrice !== null ? Money::normalize($minPrice, self::SCALE) : null,
            'highest_price_per_kg' => $maxPrice !== null ? Money::normalize($maxPrice, self::SCALE) : null,
            'last_purchase_price_per_kg' => $lastPrice !== null ? Money::normalize($lastPrice, self::SCALE) : null,
            'latest_po_at' => $latestPoAt,
            'line_count' => $lineCount,
            'po_count' => count($poIds),
            'total_kg' => Money::normalize($sumKg, self::SCALE),
            'excluded_line_count' => $excludedLineCount,
            'excluded_total_kg' => Money::normalize($excludedTotalKg, self::SCALE),
            'excluded_currencies' => array_keys($excludedCurrencies),
            'source_lines' => $sourceLines,
        ];
    }

    public function getEligibleHistoricalLines(
        int $materialMasterId,
        string $shape,
        ReferencePriceRule $rule,
        DateTimeInterface|string|null $historyAsOfAt = null,
        array $excludePoIds = [],
    ): Collection {
        $dimensionField = ReferencePriceRuleResolver::DIMENSION_FIELD_BY_SHAPE[$shape] ?? null;
        if (! $dimensionField) {
            return collect();
        }

        $spec = "COALESCE(qi.available_{$dimensionField}, pi.{$dimensionField})";

        $query = DB::table('po_quotations as pq')
            ->join('purchase_orders as po', 'po.id', '=', 'pq.po_id')
            ->join('quotations as q', 'q.id', '=', 'pq.quotation_id')
            ->join('quotation_items as qi', 'qi.quotation_id', '=', 'q.id')
            ->join('pr_items as pi', 'pi.id', '=', 'qi.pr_item_id')
            ->leftJoin('pr_item_awards as a', function ($join): void {
                $join->on('a.quotation_item_id', '=', 'qi.id')->on('a.purchase_order_id', '=', 'po.id');
            })
            ->leftJoin('users as supplier', 'supplier.id', '=', 'po.supplier_id')
            ->leftJoin('suppliers as supplier_profile', 'supplier_profile.user_id', '=', 'supplier.id')
            ->leftJoin('purchase_requisitions as pr', 'pr.id', '=', 'pi.pr_id')
            ->whereNull('po.deleted_at')
            ->where('po.status', '<>', 'cancelled')
            ->whereNull('q.deleted_at')
            ->where(fn ($available) => $available->whereNull('qi.is_available')->orWhere('qi.is_available', true))
            ->where(fn ($line) => $line->whereNotNull('a.id')->orWhereNotExists(fn ($awards) => $awards
                ->selectRaw('1')->from('pr_item_awards as po_awards')->whereColumn('po_awards.purchase_order_id', 'po.id')))
            ->whereRaw('('.PurchaseHistoryPriceService::PRICE.') > 0')
            ->whereRaw('('.PurchaseHistoryPriceService::KG.') > 0')
            ->where('pi.material_master_id', $materialMasterId)
            ->where('pi.shape', $shape)
            ->whereRaw("CEIL({$spec}) >= ?", [$rule->dimension_min])
            ->whereRaw("CEIL({$spec}) <= ?", [$rule->dimension_max]);

        if ($historyAsOfAt !== null) {
            $cutoff = $this->formatCutoff($historyAsOfAt);
            $query->where('po.created_at', '<=', $cutoff);
        }

        if (! empty($excludePoIds)) {
            $query->whereNotIn('po.id', $excludePoIds);
        }

        return $query
            ->select([
                'qi.id as quotation_item_id',
                'po.id as po_id',
                'po.po_number',
                'po.created_at as po_created_at',
                'q.currency',
                'pr.id as pr_id',
                'pr.pr_number',
                'pi.material_name',
                'pi.material_master_id',
                'pi.shape',
            ])
            ->selectRaw("COALESCE(NULLIF(TRIM(supplier_profile.company_name), ''), supplier.name) as supplier_name")
            ->selectRaw(PurchaseHistoryPriceService::PRICE.' as price_per_kg')
            ->selectRaw(PurchaseHistoryPriceService::KG.' as kg')
            ->selectRaw("{$spec} as raw_dimension")
            ->selectRaw("CEIL({$spec}) as rounded_dimension")
            ->orderByDesc('po.created_at')
            ->orderByDesc('po.id')
            ->orderByDesc('qi.id')
            ->get();
    }

    public static function buildCalculationKey(
        int $materialMasterId,
        string $shape,
        int $ruleId,
        int $benchmarkYear,
        string $targetCurrency,
        string $basisType = 'requested',
        ?string $historyAsOfAt = null,
        ?int $ruleSetId = null,
    ): string {
        $cutoff = $historyAsOfAt ? substr($historyAsOfAt, 0, 19) : 'live';

        return "{$materialMasterId}:{$shape}:{$ruleId}:{$benchmarkYear}:{$targetCurrency}:{$basisType}:{$cutoff}";
    }

    public function clearMemo(): void
    {
        $this->memo = [];
    }

    private function formatCutoff(DateTimeInterface|string|null $cutoff): ?string
    {
        if ($cutoff === null) {
            return null;
        }

        return $cutoff instanceof DateTimeInterface
            ? $cutoff->format('Y-m-d H:i:s')
            : (string) $cutoff;
    }
}
