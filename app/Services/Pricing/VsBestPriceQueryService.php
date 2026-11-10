<?php

namespace App\Services\Pricing;

use App\Http\Controllers\Purchasing\Concerns\PriceComparisonSharedTrait;
use App\Models\PrItem;
use App\Support\Pricing\EvaluationContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class VsBestPriceQueryService
{
    use PriceComparisonSharedTrait;

    public function buildVsBestQuery(?Carbon $dateFrom = null, ?Carbon $dateTo = null, string $currency = 'IDR', ?EvaluationContext $context = null, ?array $material = null)
    {
        $currentStatuses = ['submitted', 'accepted'];
        $context ??= EvaluationContext::current();

        $historyPrice = '('.PurchaseHistoryPriceService::PRICE.')';
        $historyPriceIdr = '('.$historyPrice.' * '.$this->benchmarkFactorSql('q.currency', $currency, $context).')';
        $currentPriceIdr = '(current_items.price_per_kg * '.$this->benchmarkFactorSql('current_quotes.currency', $currency, $context).')';
        $bestPriceIdr = '(best_choice.best_price * '.$this->benchmarkFactorSql('best_quotes.currency', $currency, $context).')';
        $diffIdrPerKg = "($currentPriceIdr - $bestPriceIdr)";
        $diffPercent = "CASE WHEN $bestPriceIdr > 0 AND $currentPriceIdr IS NOT NULL THEN (($diffIdrPerKg / $bestPriceIdr) * 100) ELSE NULL END";
        $currentTotalWeight = '(current_pr_items.weight_needed * CASE WHEN current_pr_items.quantity IS NULL OR current_pr_items.quantity < 1 THEN 1 ELSE current_pr_items.quantity END)';
        $potentialDifference = "CASE WHEN $currentPriceIdr IS NOT NULL AND $bestPriceIdr IS NOT NULL THEN GREATEST(0, $diffIdrPerKg) * $currentTotalWeight ELSE NULL END";

        $rankedHistory = app(PurchaseHistoryPriceService::class)->eligibleLines([])
            ->whereRaw($historyPriceIdr.' IS NOT NULL')
            ->selectRaw("pi.material_name, COALESCE(pi.shape, '') as shape_key, qi.id as best_item_id, po.id as best_po_id, {$historyPrice} as best_price")
            ->selectRaw("ROW_NUMBER() OVER (PARTITION BY pi.material_name, COALESCE(pi.shape, '') ORDER BY {$historyPriceIdr} ASC, qi.id ASC, po.id ASC) as rn");

        $bestItemByMaterial = DB::query()
            ->fromSub($rankedHistory, 'ranked_best')
            ->where('rn', 1)
            ->select(['material_name', 'shape_key', 'best_item_id', 'best_po_id', 'best_price']);

        $query = DB::table('quotation_items as current_items')
            ->join('quotations as current_quotes', 'current_items.quotation_id', '=', 'current_quotes.id')
            ->join('pr_items as current_pr_items', 'current_items.pr_item_id', '=', 'current_pr_items.id')
            ->join('purchase_requisitions as current_pr', 'current_pr_items.pr_id', '=', 'current_pr.id')
            ->leftJoin('users as current_supplier', 'current_quotes.supplier_id', '=', 'current_supplier.id')
            ->leftJoin('suppliers as current_supplier_profile', 'current_supplier_profile.user_id', '=', 'current_supplier.id')
            ->leftJoin('exchange_rates as current_rate', 'current_quotes.exchange_rate_id', '=', 'current_rate.id')
            ->leftJoinSub($bestItemByMaterial, 'best_choice', function ($join) {
                $join->on('best_choice.material_name', '=', 'current_pr_items.material_name')
                    ->whereRaw("best_choice.shape_key = COALESCE(current_pr_items.shape, '')");
            })
            ->leftJoin('quotation_items as best_items', 'best_choice.best_item_id', '=', 'best_items.id')
            ->leftJoin('quotations as best_quotes', 'best_items.quotation_id', '=', 'best_quotes.id')
            ->leftJoin('users as best_supplier', 'best_quotes.supplier_id', '=', 'best_supplier.id')
            ->leftJoin('suppliers as best_supplier_profile', 'best_supplier_profile.user_id', '=', 'best_supplier.id')
            ->leftJoin('purchase_orders as best_pos', 'best_choice.best_po_id', '=', 'best_pos.id')
            ->leftJoin('pr_items as best_pr_items', 'best_items.pr_item_id', '=', 'best_pr_items.id')
            ->leftJoin('purchase_requisitions as best_pr', function ($join) {
                $join->on('best_pr_items.pr_id', '=', 'best_pr.id')
                    ->whereNull('best_pr.deleted_at');
            })
            ->whereIn('current_quotes.status', $currentStatuses)
            ->whereNull('current_quotes.deleted_at')
            ->whereNull('current_pr.deleted_at')
            ->where(function ($query) {
                $query->whereNull('current_items.is_available')
                    ->orWhere('current_items.is_available', true);
            })
            ->select([
                'current_items.id as current_item_id',
                'current_items.quotation_id as current_quotation_id',
                'current_items.price_per_kg as current_price',
                'current_items.amount as current_amount',
                'current_quotes.currency as current_currency',
                'current_quotes.submitted_at as current_submitted_at',
                'current_pr_items.material_name',
                'current_pr_items.quantity',
                'current_pr_items.weight_needed',
                'current_pr.id as current_pr_id',
                'current_pr.pr_number as current_pr_number',
                'best_items.id as best_item_id',
                'best_items.quotation_id as best_quotation_id',
                'best_choice.best_price as best_price',
                'best_quotes.currency as best_currency',
                'best_pos.created_at as best_submitted_at',
                'best_pr.id as best_pr_id',
                'best_pr.pr_number as best_pr_number',
            ])
            ->selectRaw(self::supplierNameSql('current_supplier', 'current_supplier_profile').' as current_supplier')
            ->selectRaw(self::supplierNameSql('best_supplier', 'best_supplier_profile').' as best_supplier')
            ->selectRaw($currentPriceIdr.' as current_price_idr')
            ->selectRaw('('.$currentPriceIdr.' * '.$currentTotalWeight.') as current_total_idr')
            ->selectRaw($currentTotalWeight.' as total_weight')
            ->selectRaw($bestPriceIdr.' as best_price_idr')
            ->selectRaw($diffIdrPerKg.' as diff_idr_per_kg')
            ->selectRaw($diffPercent.' as diff_percent')
            ->selectRaw($potentialDifference.' as potential_difference_idr');

        if ($dateFrom && $dateTo) {
            $query->whereBetween('current_quotes.submitted_at', [$dateFrom, $dateTo]);
        }

        if ($material !== null) {
            $query->where('current_pr_items.material_name', $material['name']);
            $material['shape'] === null
                ? $query->whereNull('current_pr_items.shape')
                : $query->where('current_pr_items.shape', $material['shape']);
        }

        return $query->orderByRaw('potential_difference_idr DESC')
            ->orderBy('current_pr_items.material_name');
    }

    public function vsBestMaterialOptions(): Collection
    {
        $rows = DB::table('quotation_items as current_items')
            ->join('quotations as current_quotes', 'current_items.quotation_id', '=', 'current_quotes.id')
            ->join('pr_items as current_pr_items', 'current_items.pr_item_id', '=', 'current_pr_items.id')
            ->join('purchase_requisitions as current_pr', 'current_pr_items.pr_id', '=', 'current_pr.id')
            ->whereIn('current_quotes.status', ['submitted', 'accepted'])
            ->whereNull('current_quotes.deleted_at')
            ->whereNull('current_pr.deleted_at')
            ->where(fn ($query) => $query->whereNull('current_items.is_available')->orWhere('current_items.is_available', true))
            ->whereNotNull('current_pr_items.material_name')
            ->where('current_pr_items.material_name', '<>', '')
            ->distinct()
            ->orderBy('current_pr_items.material_name')
            ->orderBy('current_pr_items.shape')
            ->get(['current_pr_items.material_name', 'current_pr_items.shape']);

        $nameCounts = $rows->countBy('material_name');

        return $rows->map(fn ($row) => [
            'name' => $row->material_name,
            'shape' => $row->shape,
            'label' => $nameCounts[$row->material_name] > 1 && $row->shape !== null ? $row->material_name.' ('.$row->shape.')' : $row->material_name,
        ])->values();
    }

    public function applyVsBestKeywordFilter($query, string $keyword)
    {
        if ($keyword === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword).'%';

        return $query->where(function ($q) use ($like) {
            $q->where('current_pr_items.material_name', 'like', $like)
                ->orWhere('current_pr.pr_number', 'like', $like)
                ->orWhereRaw(self::supplierNameSql('current_supplier', 'current_supplier_profile').' like ?', [$like])
                ->orWhereRaw(self::supplierNameSql('best_supplier', 'best_supplier_profile').' like ?', [$like])
                ->orWhere('best_pr.pr_number', 'like', $like);
        });
    }

    public function extractDimensionFilters(Request $request): array
    {
        $filters = [];

        foreach (array_merge(['shape'], PrItem::DIMENSION_FIELDS, ['weight_needed']) as $field) {
            $value = $request->input($field);
            if ($value !== null && trim((string) $value) !== '') {
                $filters[$field] = trim((string) $value);
            }
        }

        return $filters;
    }

    public function matchesDimensionFilters(PrItem $item, array $filters): bool
    {
        foreach ($filters as $field => $value) {
            if ($field === 'shape') {
                if ((string) $item->shape !== $value) {
                    return false;
                }

                continue;
            }

            $actual = $item->{$field};
            if ($actual === null || (float) $actual !== (float) $value) {
                return false;
            }
        }

        return true;
    }

    public function vsBestDateRange(Request $request): array
    {
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        if (! $dateFrom || ! $dateTo) {
            return [null, null];
        }

        try {
            $startDate = Carbon::createFromFormat('Y-m', $dateFrom)->startOfMonth();
            $endDate = Carbon::createFromFormat('Y-m', $dateTo)->endOfMonth();

            if ($startDate->greaterThan($endDate)) {
                return [null, null];
            }

            return [$startDate, $endDate];
        } catch (\Exception $e) {
            return [null, null];
        }
    }

    public function buildVsBestSummary($query, float $competitiveThreshold): array
    {
        $row = DB::query()
            ->fromSub($query, 'vs_best_rows')
            ->selectRaw('COUNT(*) as total_rows')
            ->selectRaw('SUM(CASE WHEN diff_percent IS NOT NULL AND diff_percent <= ? THEN 1 ELSE 0 END) as competitive_count', [$competitiveThreshold])
            ->selectRaw('SUM(CASE WHEN diff_percent IS NOT NULL AND diff_percent > ? THEN 1 ELSE 0 END) as above_count', [$competitiveThreshold])
            ->selectRaw('SUM(COALESCE(potential_difference_idr, 0)) as total_potential_difference_idr')
            ->selectRaw('AVG(diff_idr_per_kg) as average_diff_idr_per_kg')
            ->first();

        if (! $row) {
            return $this->emptyVsBestSummary();
        }

        return [
            'total_rows' => (int) $row->total_rows,
            'competitive_count' => (int) $row->competitive_count,
            'above_count' => (int) $row->above_count,
            'total_potential_difference_idr' => round((float) $row->total_potential_difference_idr, 0),
            'average_diff_idr_per_kg' => $row->average_diff_idr_per_kg !== null
                ? round((float) $row->average_diff_idr_per_kg, 0)
                : null,
        ];
    }

    public function emptyVsBestSummary(): array
    {
        return [
            'total_rows' => 0,
            'competitive_count' => 0,
            'above_count' => 0,
            'total_potential_difference_idr' => 0,
            'average_diff_idr_per_kg' => null,
        ];
    }

    public function getCachedDefaultVsBestSummary(float $competitiveThreshold, string $currency, EvaluationContext $context): array
    {
        return Cache::get($this->vsBestSummaryCacheKey($currency, $context), $this->emptyVsBestSummary());
    }

    public function vsBestSummaryCacheKey(string $currency, EvaluationContext $context): string
    {
        return 'purchasing:vs_best_summary_unfiltered:v3:'.$context->dateString().':'.$currency;
    }

    public function vsBestMaterialFilter(Request $request): ?array
    {
        $name = trim((string) $request->input('material_name', ''));
        if ($name === '') {
            return null;
        }
        $shape = trim((string) $request->input('shape', ''));

        return ['name' => $name, 'shape' => in_array($shape, PrItem::SHAPES, true) ? $shape : null];
    }
}
