<?php

namespace App\Services\Pricing;

use App\Models\ExchangeRate;
use App\Models\HistoricalBenchmarkLine;
use App\Models\PrItem;
use App\Models\QuotationItem;
use App\Models\ReferencePriceRule;
use App\Support\BusinessTime;
use App\Support\HashidCodec;
use App\Support\Money;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
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
        ?int $benchmarkMonth = null,
        bool $allYears = false,
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
            benchmarkMonth: $benchmarkMonth,
            allYears: $allYears,
        );
    }

    public function calculateForQuotationItem(
        QuotationItem $quotationItem,
        string $targetCurrency = 'IDR',
        ?int $benchmarkYear = null,
        DateTimeInterface|string|null $historyAsOfAt = null,
        array $excludePoIds = [],
        ?int $benchmarkMonth = null,
        bool $allYears = false,
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
            benchmarkMonth: $benchmarkMonth,
            allYears: $allYears,
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
        ?int $benchmarkMonth = null,
        bool $allYears = false,
    ): HistoricalCalculationResult {
        $targetCurrency = strtoupper(trim($targetCurrency));
        $cutoffStr = $this->formatCutoff($historyAsOfAt);
        $currentYear = $this->annualRates->year(BusinessTime::now());
        $benchmarkMonth = ($benchmarkMonth !== null && $benchmarkMonth >= 1 && $benchmarkMonth <= 12) ? $benchmarkMonth : null;
        $aggregateAllYears = $allYears && $benchmarkYear === null;
        $resolvedBenchmarkYear = $aggregateAllYears
            ? null
            : ($benchmarkYear ?? ($historyAsOfAt !== null ? $this->annualRates->year($historyAsOfAt) : $currentYear));
        $isCurrentYear = $resolvedBenchmarkYear !== null && $resolvedBenchmarkYear === $currentYear;

        $dimensionField = ReferencePriceRuleResolver::DIMENSION_FIELD_BY_SHAPE[$shape] ?? null;

        $calcKey = self::buildCalculationKey(
            materialMasterId: $materialMasterId,
            shape: $shape,
            ruleId: $rule->id,
            benchmarkYear: $resolvedBenchmarkYear,
            targetCurrency: $targetCurrency,
            basisType: $basisType,
            historyAsOfAt: $cutoffStr,
            benchmarkMonth: $benchmarkMonth,
            allYears: $aggregateAllYears,
            excludePoIds: $excludePoIds,
            rawDimension: $rawDimension,
            roundedDimension: $roundedDimension,
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

        if ($benchmarkMonth !== null) {
            $lines = $lines->filter(function ($line) use ($benchmarkMonth): bool {
                $dt = BusinessTime::toBusiness(new \DateTimeImmutable((string) $line->po_created_at, new \DateTimeZone('UTC')));

                return (int) $dt->month === $benchmarkMonth;
            })->values();
        }

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

        $targetYearSummary = $aggregateAllYears
            ? ($lines->isNotEmpty() ? $this->summarizeYearLines($lines->all(), null, $targetCurrency) : null)
            : ($yearlySummaries[$resolvedBenchmarkYear] ?? null);

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
                benchmarkYear: $resolvedBenchmarkYear,
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
            benchmarkYear: $resolvedBenchmarkYear,
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
    private function summarizeYearLines(array $lines, ?int $year, string $targetCurrency): array
    {
        $sumAmount = '0';
        $sumKg = '0';
        $minPrice = null;
        $maxPrice = null;
        $lastPrice = null;
        $latestPoAt = null;
        $latestSourceRank = -1;
        $latestPoId = 0;
        $latestLineId = 0;
        $poIds = [];
        $lineCount = 0;
        $excludedLineCount = 0;
        $excludedTotalKg = '0';
        $excludedCurrencies = [];
        $sourceLines = [];

        foreach ($lines as $line) {
            $sourceType = (string) ($line->source_type ?? 'portal_po');
            $benchmarkLineId = isset($line->benchmark_line_id) && $line->benchmark_line_id !== null
                ? (int) $line->benchmark_line_id
                : null;
            $poId = $line->po_id !== null ? (int) $line->po_id : null;
            $qiId = $line->quotation_item_id !== null ? (int) $line->quotation_item_id : null;
            $origPrice = (string) $line->price_per_kg;
            $kg = (string) $line->kg;
            $currency = (string) $line->currency;
            $lineYear = $year ?? $this->annualRates->year($line->po_created_at);

            $converted = $this->annualRates->convert($origPrice, $currency, $targetCurrency, $lineYear, self::SCALE);

            if ($converted === null) {
                $excludedLineCount++;
                $excludedTotalKg = bcadd($excludedTotalKg, $kg, self::SCALE);
                $excludedCurrencies[$currency] = true;

                $sourceLines[] = [
                    'source_type' => $sourceType,
                    'benchmark_line_id' => $benchmarkLineId,
                    'quotation_item_id' => $qiId,
                    'po_id' => $poId,
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
            $poKey = $sourceType === 'imported'
                ? 'import:'.HistoricalBenchmarkLine::normalizePoNumber((string) $line->po_number)
                : 'portal:'.(string) $poId;
            $poIds[$poKey] = true;

            if ($minPrice === null || bccomp($converted, $minPrice, self::SCALE) < 0) {
                $minPrice = $converted;
            }
            if ($maxPrice === null || bccomp($converted, $maxPrice, self::SCALE) > 0) {
                $maxPrice = $converted;
            }

            // Stable tie-breaker for last purchase price:
            // 1. po_created_at DESC
            // 2. portal_po (rank 1) before imported (rank 0) on identical timestamp
            // 3. po_id DESC
            // 4. line id (quotation_item_id or benchmark_line_id) DESC
            $sourceRank = $sourceType === 'imported' ? 0 : 1;
            $lineIdentifier = $qiId ?? $benchmarkLineId ?? 0;
            $comparePoId = $poId ?? 0;
            $isNewer = false;
            if ($latestPoAt === null) {
                $isNewer = true;
            } else {
                $cmp = strcmp((string) $line->po_created_at, $latestPoAt);
                if ($cmp > 0) {
                    $isNewer = true;
                } elseif ($cmp === 0) {
                    if ($sourceRank > $latestSourceRank) {
                        $isNewer = true;
                    } elseif ($sourceRank === $latestSourceRank) {
                        if ($comparePoId > $latestPoId) {
                            $isNewer = true;
                        } elseif ($comparePoId === $latestPoId && $lineIdentifier > $latestLineId) {
                            $isNewer = true;
                        }
                    }
                }
            }

            if ($isNewer) {
                $latestPoAt = (string) $line->po_created_at;
                $latestSourceRank = $sourceRank;
                $latestPoId = $comparePoId;
                $latestLineId = $lineIdentifier;
                $lastPrice = $converted;
            }

            $sourceLines[] = [
                'source_type' => $sourceType,
                'benchmark_line_id' => $benchmarkLineId,
                'quotation_item_id' => $qiId,
                'po_id' => $poId,
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

        $portalLines = $query
            ->selectRaw("'portal_po' as source_type")
            ->selectRaw('NULL as benchmark_line_id')
            ->addSelect([
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

        $poNumberExpr = $this->normalizedSqlPoNumberExpression('active_po.po_number');

        $importedQuery = DB::table('historical_benchmark_lines as hbl')
            ->join('material_masters as mm', 'mm.id', '=', 'hbl.material_master_id')
            ->where('hbl.material_master_id', $materialMasterId)
            ->where('hbl.shape', $shape)
            ->where('hbl.rounded_dimension', '>=', $rule->dimension_min)
            ->where('hbl.rounded_dimension', '<=', $rule->dimension_max)
            ->whereNotExists(function ($poExists) use ($poNumberExpr): void {
                $poExists->selectRaw('1')
                    ->from('purchase_orders as active_po')
                    ->whereNull('active_po.deleted_at')
                    ->where('active_po.status', '<>', 'cancelled')
                    ->whereRaw("{$poNumberExpr} = hbl.po_number_normalized");
            });

        if ($historyAsOfAt !== null) {
            $importedQuery->where('hbl.po_datetime_utc', '<=', $this->formatCutoff($historyAsOfAt));
        }

        $importedLines = $importedQuery
            ->selectRaw("'imported' as source_type")
            ->addSelect([
                'hbl.id as benchmark_line_id',
            ])
            ->selectRaw('NULL as quotation_item_id')
            ->selectRaw('NULL as po_id')
            ->addSelect([
                'hbl.po_number',
                'hbl.po_datetime_utc as po_created_at',
                'hbl.currency',
            ])
            ->selectRaw('NULL as pr_id')
            ->addSelect([
                'hbl.pr_number',
                'mm.material_code as material_name',
                'hbl.material_master_id',
                'hbl.shape',
                'hbl.supplier_name',
                'hbl.price_per_kg',
                'hbl.ordered_weight_kg as kg',
                'hbl.key_dimension as raw_dimension',
                'hbl.rounded_dimension',
            ])
            ->orderByDesc('hbl.po_datetime_utc')
            ->orderByDesc('hbl.id')
            ->get();

        if ($importedLines->isEmpty()) {
            return $portalLines;
        }

        if ($portalLines->isEmpty()) {
            return $importedLines;
        }

        return $portalLines
            ->concat($importedLines)
            ->sort(static function (object $a, object $b): int {
                $cmp = strcmp((string) $b->po_created_at, (string) $a->po_created_at);
                if ($cmp !== 0) {
                    return $cmp;
                }

                $rankA = ($a->source_type ?? 'portal_po') === 'imported' ? 0 : 1;
                $rankB = ($b->source_type ?? 'portal_po') === 'imported' ? 0 : 1;
                if ($rankA !== $rankB) {
                    return $rankB <=> $rankA;
                }

                $poCmp = ((int) ($b->po_id ?? 0)) <=> ((int) ($a->po_id ?? 0));
                if ($poCmp !== 0) {
                    return $poCmp;
                }

                $idA = (int) ($a->quotation_item_id ?? $a->benchmark_line_id ?? 0);
                $idB = (int) ($b->quotation_item_id ?? $b->benchmark_line_id ?? 0);

                return $idB <=> $idA;
            })
            ->values();
    }

    /**
     * Paginated combined benchmark table rows (Portal POs + Imported Historical Benchmark lines)
     * for Master Data -> Reference Prices -> Tab 2 (Historical Benchmark).
     *
     * @param  array{search?: ?string, shape?: ?string, po_status?: ?string, source?: ?string}  $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginatedBenchmarkLines(
        array $filters,
        string $targetCurrency = 'IDR',
        int $page = 1,
        int $perPage = 25
    ): array {
        $targetCurrency = strtoupper(trim($targetCurrency));
        if (! in_array($targetCurrency, ExchangeRate::CURRENCIES, true)) {
            $targetCurrency = ExchangeRate::CURRENCY_IDR;
        }

        $sourceFilter = (string) ($filters['source'] ?? 'all');
        $poStatusFilter = (string) ($filters['po_status'] ?? 'completed');
        $shapeFilter = ! empty($filters['shape']) ? (string) $filters['shape'] : null;
        $search = trim((string) ($filters['search'] ?? ''));

        $includePortal = in_array($sourceFilter, ['all', 'portal_po'], true);
        $includeImported = $sourceFilter === 'imported'
            || ($sourceFilter === 'all' && in_array($poStatusFilter, ['completed', 'all'], true));

        $branches = [];

        if ($includePortal) {
            $branches[] = $this->buildPortalBenchmarkBranchQuery($poStatusFilter, $shapeFilter, $search);
        }

        if ($includeImported) {
            $branches[] = $this->buildImportedBenchmarkBranchQuery($shapeFilter, $search);
        }

        if ($branches === []) {
            return ['rows' => [], 'total' => 0];
        }

        $combined = array_shift($branches);
        foreach ($branches as $branch) {
            $combined->unionAll($branch);
        }

        $unionQuery = DB::query()->fromSub($combined, 'bench_lines');
        $total = (clone $unionQuery)->count();

        if ($total === 0) {
            return ['rows' => [], 'total' => 0];
        }

        $offset = (max(1, $page) - 1) * $perPage;
        $rawRows = $unionQuery
            ->orderByDesc('po_created_at')
            ->orderByDesc('source_rank')
            ->orderByDesc('po_id_sort')
            ->orderByDesc('line_id_sort')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $activeRules = ReferencePriceRule::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('dimension_min')
            ->get();

        $rows = [];
        foreach ($rawRows as $row) {
            $sourceType = (string) $row->source_type;
            $price = Money::normalize((string) $row->price_per_kg, self::SCALE);
            $kg = Money::normalize((string) $row->kg, self::SCALE);
            $lineYear = $this->annualRates->year((string) $row->po_created_at);
            $converted = $this->annualRates->convert($price, (string) $row->currency, $targetCurrency, $lineYear, self::SCALE);

            $classification = ReferencePriceRule::resolveDimensionClassification(
                $row->shape ?? null,
                $row->effective_thickness ?? null,
                $row->effective_d_outer ?? null,
                $activeRules
            );

            $benchmarkLineId = $row->benchmark_line_id !== null ? (int) $row->benchmark_line_id : null;

            $rows[] = [
                'source_type' => $sourceType,
                'benchmark_line_id' => $benchmarkLineId,
                'benchmark_line_hash' => $benchmarkLineId !== null ? HashidCodec::encode($benchmarkLineId) : null,
                'quotation_item_id' => $row->quotation_item_id !== null ? (int) $row->quotation_item_id : null,
                'po_id' => $row->po_id !== null ? (int) $row->po_id : null,
                'po_number' => (string) $row->po_number,
                'po_status' => $row->po_status,
                'po_created_at' => (string) $row->po_created_at,
                'currency' => (string) $row->currency,
                'supplier' => (string) ($row->supplier_name ?? '-'),
                'pr_id' => $row->pr_id !== null ? (int) $row->pr_id : null,
                'pr_number' => $row->pr_number,
                'material_name' => (string) ($row->material_name ?? '-'),
                'shape' => $classification['shape'] ?? ($row->shape ?? null),
                'key_dimension' => $classification['key_dimension'],
                'dimension_display' => $classification['dimension_display'],
                'range_classification' => $classification['range_classification'],
                'category_key' => $classification['category_key'],
                'category_name' => $classification['category_name'],
                'price_per_kg' => $price,
                'kg' => $kg,
                'converted_price_per_kg' => $converted,
            ];
        }

        return [
            'rows' => $rows,
            'total' => $total,
        ];
    }

    private function buildPortalBenchmarkBranchQuery(string $poStatusFilter, ?string $shapeFilter, string $search): Builder
    {
        $query = DB::table('po_quotations as pq')
            ->join('purchase_orders as po', 'po.id', '=', 'pq.po_id')
            ->join('quotations as q', 'q.id', '=', 'pq.quotation_id')
            ->join('quotation_items as qi', 'qi.quotation_id', '=', 'q.id')
            ->join('pr_items as pi', 'pi.id', '=', 'qi.pr_item_id')
            ->leftJoin('material_masters as mm_portal', 'mm_portal.id', '=', 'pi.material_master_id')
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
            ->whereRaw('('.PurchaseHistoryPriceService::KG.') > 0');

        if ($poStatusFilter !== '' && $poStatusFilter !== 'all') {
            $query->where('po.status', $poStatusFilter);
        }

        if ($shapeFilter !== null) {
            $query->where('pi.shape', $shapeFilter);
        }

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(function ($sub) use ($like): void {
                $sub->where('pi.material_name', 'like', $like)
                    ->orWhere('mm_portal.material_code', 'like', $like)
                    ->orWhere('po.po_number', 'like', $like)
                    ->orWhere('supplier_profile.company_name', 'like', $like)
                    ->orWhere('supplier.name', 'like', $like);
            });
        }

        return $query
            ->selectRaw("'portal_po' as source_type")
            ->selectRaw('1 as source_rank')
            ->selectRaw('CAST(NULL AS UNSIGNED) as benchmark_line_id')
            ->addSelect([
                'qi.id as quotation_item_id',
                'po.id as po_id',
                'po.id as po_id_sort',
                'qi.id as line_id_sort',
                'po.po_number',
                'po.status as po_status',
                'po.created_at as po_created_at',
                'q.currency',
                'pr.id as pr_id',
                'pr.pr_number',
            ])
            ->selectRaw('COALESCE(mm_portal.material_code, pi.material_name) as material_name')
            ->addSelect(['pi.shape'])
            ->selectRaw('COALESCE(qi.available_thickness, pi.thickness) as effective_thickness')
            ->selectRaw('COALESCE(qi.available_d_outer, pi.d_outer) as effective_d_outer')
            ->selectRaw("COALESCE(NULLIF(TRIM(supplier_profile.company_name), ''), supplier.name) as supplier_name")
            ->selectRaw(PurchaseHistoryPriceService::PRICE.' as price_per_kg')
            ->selectRaw(PurchaseHistoryPriceService::KG.' as kg');
    }

    private function buildImportedBenchmarkBranchQuery(?string $shapeFilter, string $search): Builder
    {
        $poNumberExpr = $this->normalizedSqlPoNumberExpression('active_po.po_number');

        $query = DB::table('historical_benchmark_lines as hbl')
            ->join('material_masters as mm', 'mm.id', '=', 'hbl.material_master_id')
            ->whereNotExists(function ($poExists) use ($poNumberExpr): void {
                $poExists->selectRaw('1')
                    ->from('purchase_orders as active_po')
                    ->whereNull('active_po.deleted_at')
                    ->where('active_po.status', '<>', 'cancelled')
                    ->whereRaw("{$poNumberExpr} = hbl.po_number_normalized");
            });

        if ($shapeFilter !== null) {
            $query->where('hbl.shape', $shapeFilter);
        }

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(function ($sub) use ($like): void {
                $sub->where('mm.material_code', 'like', $like)
                    ->orWhere('hbl.material_code_snapshot', 'like', $like)
                    ->orWhere('hbl.po_number', 'like', $like)
                    ->orWhere('hbl.supplier_name', 'like', $like)
                    ->orWhere('hbl.pr_number', 'like', $like);
            });
        }

        return $query
            ->selectRaw("'imported' as source_type")
            ->selectRaw('0 as source_rank')
            ->addSelect(['hbl.id as benchmark_line_id'])
            ->selectRaw('CAST(NULL AS UNSIGNED) as quotation_item_id')
            ->selectRaw('CAST(NULL AS UNSIGNED) as po_id')
            ->selectRaw('0 as po_id_sort')
            ->addSelect([
                'hbl.id as line_id_sort',
                'hbl.po_number',
            ])
            ->selectRaw('CAST(NULL AS CHAR) as po_status')
            ->addSelect([
                'hbl.po_datetime_utc as po_created_at',
                'hbl.currency',
            ])
            ->selectRaw('CAST(NULL AS UNSIGNED) as pr_id')
            ->addSelect([
                'hbl.pr_number',
                'mm.material_code as material_name',
                'hbl.shape',
            ])
            ->selectRaw("CASE WHEN hbl.shape = 'Flat' THEN hbl.key_dimension ELSE NULL END as effective_thickness")
            ->selectRaw("CASE WHEN hbl.shape IN ('Round', 'Hollow') THEN hbl.key_dimension ELSE NULL END as effective_d_outer")
            ->addSelect([
                'hbl.supplier_name',
                'hbl.price_per_kg',
                'hbl.ordered_weight_kg as kg',
            ]);
    }

    public static function buildCalculationKey(
        int $materialMasterId,
        string $shape,
        int $ruleId,
        ?int $benchmarkYear,
        string $targetCurrency,
        string $basisType = 'requested',
        ?string $historyAsOfAt = null,
        ?int $ruleSetId = null,
        ?int $benchmarkMonth = null,
        bool $allYears = false,
        array $excludePoIds = [],
        ?string $rawDimension = null,
        ?int $roundedDimension = null,
    ): string {
        $cutoff = $historyAsOfAt ? substr($historyAsOfAt, 0, 19) : 'live';
        $yearPart = $benchmarkYear !== null ? (string) $benchmarkYear : ($allYears ? 'all' : 'current');
        $monthPart = $benchmarkMonth !== null ? (string) $benchmarkMonth : 'all';
        $excludeIds = array_values(array_unique(array_map('intval', $excludePoIds)));
        sort($excludeIds);
        $excludePart = $excludeIds === [] ? 'none' : implode(',', $excludeIds);
        $dimPart = ($rawDimension ?? '-').'/'.($roundedDimension ?? '-');

        return "{$materialMasterId}:{$shape}:{$ruleId}:{$yearPart}:{$monthPart}:{$targetCurrency}:{$basisType}:{$cutoff}:{$excludePart}:{$dimPart}";
    }

    private function normalizedSqlPoNumberExpression(string $column): string
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            ? "REGEXP_REPLACE(UPPER(TRIM({$column})), '[[:space:]]+', ' ')"
            : "UPPER(TRIM({$column}))";
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
