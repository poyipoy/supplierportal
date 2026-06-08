<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\PrItem;
use App\Models\User;
use App\Support\PurchasingNavigation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

class PriceComparisonController extends Controller
{
    /**
     * View 1: Antar Supplier - menampilkan semua penawaran (quotation items) 
     * dalam satu PR secara side-by-side.
     * 
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\View\View|\Illuminate\Http\JsonResponse
     */
    public function interSupplier(Request $request)
    {
        $eligiblePrs = PurchaseRequisition::with(['period', 'quotations' => function ($q) {
                $q->whereIn('status', ['submitted', 'accepted', 'rejected']);
            }])
            ->whereHas('quotations', function ($q) {
                $q->whereIn('status', ['submitted', 'accepted', 'rejected']);
            }, '>=', 2)
            ->orderByDesc('created_at')
            ->get();

        $comparison = null;
        $chartData = null;
        $chartMaterialIds = [];
        $materialOptions = collect();
        $selectedPr = null;
        $selectedPrOption = null;

        $eligiblePrOptions = $eligiblePrs->map(function ($pr) {
            $label = ($pr->pr_number ?? 'DRAFT')
                . ' - '
                . ($pr->period->name ?? '-')
                . ' ('
                . $pr->quotations->count()
                . ' penawaran)';

            $previewMaterials = $pr->items->take(3)->pluck('material_name')->implode(', ');
            if ($pr->items->count() > 3) {
                $previewMaterials .= ' (+' . ($pr->items->count() - 3) . ' lainnya)';
            }

            return [
                'id' => (string) $pr->id,
                'label' => $label,
                'prNumber' => $pr->pr_number ?? 'DRAFT',
                'period' => $pr->period->name ?? '-',
                'quotationCount' => $pr->quotations->count(),
                'previewMaterials' => $previewMaterials,
                'search' => strtolower($label),
            ];
        })->values();

        if ($request->filled('pr_id')) {
            $selectedPr = PurchaseRequisition::with(['items', 'period'])->find($request->pr_id);

            if ($selectedPr) {
                $selectedPrOption = $eligiblePrOptions->firstWhere('id', (string) $selectedPr->id);
                $selectedPrOption ??= [
                    'id' => (string) $selectedPr->id,
                    'label' => ($selectedPr->pr_number ?? 'DRAFT') . ' - ' . ($selectedPr->period->name ?? '-'),
                ];
                $comparisonItems = $selectedPr->items->values();
                $materialOptions = $comparisonItems;
                $quotations = Quotation::with(['supplier', 'items.prItem', 'exchange_rate'])
                    ->where('pr_id', $selectedPr->id)
                    ->whereIn('status', ['submitted', 'accepted', 'rejected'])
                    ->get();

                $suppliers = $quotations->map(fn($q) => [
                    'id' => $q->supplier_id,
                    'name' => $q->supplier->name,
                    'currency' => $q->currency,
                    'status' => $q->status,
                    'quotation_id' => $q->id,
                ]);

                $matrix = [];
                foreach ($comparisonItems as $item) {
                    $row = [
                        'item' => $item,
                        'prices' => [],
                    ];

                    foreach ($quotations as $quotation) {
                        $quotationItem = $quotation->items->where('pr_item_id', $item->id)->first();
                        $rate = $quotation->exchange_rate;
                        $pricePerKg = $quotationItem ? (float) $quotationItem->price_per_kg : null;
                        $priceIdr = ($pricePerKg && $rate)
                            ? $pricePerKg * (float) $rate->rate_to_idr
                            : null;

                        $row['prices'][$quotation->id] = [
                            'quotation_id' => $quotation->id,
                            'quotation_item_id' => $quotationItem?->id,
                            'price_per_kg' => $pricePerKg,
                            'price_idr' => $priceIdr,
                            'amount' => $quotationItem ? (float) $quotationItem->amount : null,
                            'currency' => $quotation->currency,
                            'detail_url' => $quotationItem
                                ? PurchasingNavigation::toRoute('purchasing.quotations.show', $quotation->id)
                                : null,
                        ];
                    }

                    $matrix[] = $row;
                }

                $comparison = [
                    'suppliers' => $suppliers,
                    'matrix' => $matrix,
                    'quotations' => $quotations,
                ];

                $chartLabels = $comparisonItems->pluck('material_name')->toArray();
                $chartMaterialIds = $comparisonItems->pluck('id')->map(fn($id) => (string) $id)->toArray();
                $chartDatasets = [];
                $colors = ['#1F5FA6', '#C0392B', '#27AE60', '#F39C12', '#8E44AD', '#16A085'];

                foreach ($quotations as $idx => $quotation) {
                    $data = [];

                    foreach ($comparisonItems as $item) {
                        $quotationItem = $quotation->items->where('pr_item_id', $item->id)->first();
                        $rate = $quotation->exchange_rate;
                        $data[] = ($quotationItem && $rate)
                            ? round((float) $quotationItem->price_per_kg * (float) $rate->rate_to_idr, 0)
                            : 0;
                    }

                    $chartDatasets[] = [
                        'label' => $quotation->supplier->name,
                        'data' => $data,
                        'backgroundColor' => $colors[$idx % count($colors)],
                    ];
                }

                $chartData = [
                    'labels' => $chartLabels,
                    'datasets' => $chartDatasets,
                ];
            }
        }

        return view('purchasing.comparison.inter-supplier', compact(
            'eligiblePrs',
            'comparison',
            'chartData',
            'chartMaterialIds',
            'materialOptions',
            'selectedPr',
            'eligiblePrOptions',
            'selectedPrOption'
        ));
    }

    /**
     * View 2: Historis - grafik garis harga material dari satu supplier lintas periode.
     */
    public function historical(Request $request)
    {
        $suppliers = User::where('role', 'supplier')->orderBy('name')->get();
        $selectedSupplierId = $request->input('supplier_id', $request->input('supplier'));
        $selectedMaterialName = $request->input('material_name');
        $periodView = $request->input('period_view', 'monthly') === 'yearly' ? 'yearly' : 'monthly';
        $range = $this->normalizeHistoricalRange($periodView, $request->input('range'));
        $monthlyRangeOptions = $this->historicalRangeOptions('monthly');
        $yearlyRangeOptions = $this->historicalRangeOptions('yearly');
        $rangeOptions = $this->historicalRangeOptions($periodView);
        $dateFrom = $this->dateFromRange($range);
        $selectedSupplier = $selectedSupplierId ? $suppliers->firstWhere('id', (int) $selectedSupplierId) : null;

        $materials = $this->historicalMaterialsForSupplier($selectedSupplierId);

        if ($selectedSupplierId && $selectedMaterialName && ! $materials->pluck('name')->contains($selectedMaterialName)) {
            $selectedMaterialName = null;
        }

        $chartData = null;
        $tableData = collect();
        $summary = [
            'average_change_pct' => null,
            'total_change_pct' => null,
        ];
        $dimensionFilters = [];
        foreach (['thickness', 'd_inner', 'd_outer', 'width', 'length'] as $field) {
            $val = $request->input($field);
            if ($val !== null && trim((string)$val) !== '') {
                $dimensionFilters[$field] = trim((string)$val);
            }
        }

        if ($selectedSupplierId && $selectedMaterialName) {
            [$chartData, $tableData] = $periodView === 'yearly'
                ? $this->buildYearlyHistoricalData($selectedSupplierId, $selectedMaterialName, $dateFrom, $dimensionFilters)
                : $this->buildMonthlyHistoricalData($selectedSupplierId, $selectedMaterialName, $dateFrom, $dimensionFilters);

            if ($tableData->isEmpty()) {
                $chartData = null;
            } else {
                $summary = $this->buildHistoricalSummary($tableData);
            }
        }

        $payload = [
            'chartData' => $chartData,
            'tableData' => $tableData->values(),
            'summary' => $summary,
            'periodView' => $periodView,
            'range' => $range,
            'rangeOptions' => $rangeOptions,
            'materialName' => $selectedMaterialName,
            'supplierName' => $selectedSupplier->name ?? '',
        ];

        if ($request->ajax() && ($request->wantsJson() || $request->input('view') === 'json')) {
            return response()->json($payload);
        }

        return view('purchasing.comparison.historical', compact(
            'suppliers',
            'materials',
            'chartData',
            'tableData',
            'summary',
            'selectedSupplierId',
            'selectedMaterialName',
            'periodView',
            'range',
            'rangeOptions',
            'monthlyRangeOptions',
            'yearlyRangeOptions',
            'payload'
        ));
    }

    public function historicalMaterials(Request $request)
    {
        $supplierId = $request->input('supplier_id', $request->input('supplier'));

        return response()->json([
            'materials' => $this->historicalMaterialsForSupplier($supplierId)->values(),
        ]);
    }

    /**
     * View 3: vs Harga Terbaik - harga saat ini vs MIN(price_per_kg) histori.
     */
    public function vsBestPrice(Request $request)
    {
        [$dateFrom, $dateTo] = $this->vsBestDateRange($request);
        $dateFromInput = $request->input('date_from');
        $dateToInput = $request->input('date_to');
        $competitiveThreshold = 2.0;
        $summary = $this->emptyVsBestSummary();

        return view('purchasing.comparison.vs-best', compact(
            'dateFromInput',
            'dateToInput',
            'summary',
            'competitiveThreshold'
        ));
    }

    public function vsBestPriceData(Request $request)
    {
        [$dateFrom, $dateTo] = $this->vsBestDateRange($request);
        $competitiveThreshold = 2.0;

        if (! ($dateFrom && $dateTo)) {
            return response()->json([
                'draw' => (int) $request->input('draw', 0),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'summary' => $this->emptyVsBestSummary(),
            ]);
        }

        $keyword = trim((string) $request->input('search.value', ''));
        $returnUrl = route('purchasing.comparison.vs-best', $request->only([
            'date_from',
            'date_to',
        ]));
        $summaryQuery = $this->applyVsBestKeywordFilter(
            $this->buildVsBestQuery($dateFrom, $dateTo),
            $keyword
        );
        $summary = $this->buildVsBestSummary($summaryQuery, $competitiveThreshold);

        return DataTables::query($this->buildVsBestQuery($dateFrom, $dateTo))
            ->filter(function ($query) use ($keyword) {
                $this->applyVsBestKeywordFilter($query, $keyword);
            })
            ->addColumn('material_display', function ($row) use ($returnUrl) {
                $prUrl = $this->routeWithReturn('purchasing.requirements.show', $row->current_pr_id, $returnUrl);

                return '<div class="fw-bold">' . e($row->material_name) . '</div>'
                    . '<div class="text-muted small">Qty: ' . number_format((int) ($row->quantity ?? 1), 0, ',', '.') . '</div>'
                    . '<div class="text-muted small">Berat/unit: ' . $this->formatNumber($row->weight_needed) . ' kg</div>'
                    . '<div class="text-muted small">Total berat: ' . $this->formatNumber($row->total_weight) . ' kg</div>'
                    . '<a href="' . e($prUrl) . '" class="small text-primary text-decoration-none">'
                    . e($row->current_pr_number ?: '-')
                    . '<i class="bi bi-arrow-right-short ms-1"></i></a>';
            })
            ->addColumn('current_price_display', function ($row) {
                return '<div class="fw-bold text-primary">' . $this->formatRupiah($row->current_price_idr) . '</div>'
                    . '<div class="text-muted small">' . $this->formatNumber($row->current_price) . ' ' . e($row->current_currency) . '/kg</div>'
                    . '<div class="text-muted small">' . e($row->current_supplier ?: '-') . '</div>'
                    . '<div class="text-muted small">' . e($this->formatDate($row->current_submitted_at) ?? 'Draft') . '</div>';
            })
            ->addColumn('best_price_display', function ($row) use ($returnUrl) {
                $html = '<div class="fw-bold text-success">' . $this->formatRupiah($row->best_price_idr) . '</div>'
                    . '<div class="text-muted small">' . $this->formatNumber($row->best_price) . ' ' . e($row->best_currency) . '/kg</div>'
                    . '<div class="text-muted small">' . e($row->best_supplier ?: '-') . '</div>';

                if ($row->best_pr_id) {
                    $bestPrUrl = $this->routeWithReturn('purchasing.requirements.show', $row->best_pr_id, $returnUrl);
                    $html .= '<a href="' . e($bestPrUrl) . '" class="small text-primary text-decoration-none">'
                        . e($row->best_pr_number ?: '-')
                        . '<i class="bi bi-arrow-right-short ms-1"></i></a>';
                } else {
                    $html .= '<div class="text-muted small">PR: -</div>';
                }

                return $html . '<div class="text-muted small">' . e($this->formatDate($row->best_submitted_at) ?? '-') . '</div>';
            })
            ->addColumn('diff_display', function ($row) {
                $diff = $row->diff_idr_per_kg !== null ? (float) $row->diff_idr_per_kg : null;
                $class = $diff > 0 ? 'text-danger' : ($diff < 0 ? 'text-success' : 'text-muted');

                return '<div class="fw-bold ' . $class . '">' . $this->formatSignedRupiah($diff) . '</div>'
                    . '<div class="small ' . $class . '">' . $this->formatPercent($row->diff_percent) . '</div>';
            })
            ->addColumn('potential_difference_display', fn($row) => '<span class="fw-bold">' . $this->formatRupiah($row->potential_difference_idr) . '</span>')
            ->addColumn('status_badge', function ($row) use ($competitiveThreshold) {
                $status = $this->priceCompetitivenessStatus(
                    $row->diff_percent !== null ? (float) $row->diff_percent : null,
                    $competitiveThreshold
                );

                return '<span class="badge ' . $status['class'] . '">'
                    . '<i class="bi ' . $status['icon'] . ' me-1"></i>' . e($status['label'])
                    . '</span><div class="text-muted small mt-1">' . e($status['recommendation']) . '</div>';
            })
            ->addColumn('action', function ($row) use ($returnUrl) {
                $currentQuotationUrl = $this->routeWithReturn('purchasing.quotations.show', $row->current_quotation_id, $returnUrl);
                $html = '<div class="btn-group btn-group-sm" role="group">'
                    . '<a href="' . e($currentQuotationUrl) . '" class="btn btn-outline-primary" title="Lihat penawaran saat ini">'
                    . '<i class="bi bi-file-earmark-text"></i></a>';

                if ($row->best_quotation_id) {
                    $bestQuotationUrl = $this->routeWithReturn('purchasing.quotations.show', $row->best_quotation_id, $returnUrl);
                    $html .= '<a href="' . e($bestQuotationUrl) . '" class="btn btn-outline-success" title="Lihat penawaran terbaik histori">'
                        . '<i class="bi bi-trophy"></i></a>';
                }

                return $html . '</div>';
            })
            ->rawColumns([
                'material_display',
                'current_price_display',
                'best_price_display',
                'diff_display',
                'potential_difference_display',
                'status_badge',
                'action',
            ])
            ->with('summary', $summary)
            ->make(true);
    }

    private function buildVsBestQuery(?Carbon $dateFrom = null, ?Carbon $dateTo = null)
    {
        $historyStatuses = ['submitted', 'accepted', 'rejected'];
        $currentStatuses = ['submitted', 'accepted'];
        $historyPriceIdr = '(history_items.price_per_kg * history_rate.rate_to_idr)';
        $currentPriceIdr = '(current_items.price_per_kg * current_rate.rate_to_idr)';
        $bestPriceIdr = '(best_items.price_per_kg * best_rate.rate_to_idr)';
        $diffIdrPerKg = "($currentPriceIdr - $bestPriceIdr)";
        $diffPercent = "CASE WHEN $bestPriceIdr > 0 AND $currentPriceIdr IS NOT NULL THEN (($diffIdrPerKg / $bestPriceIdr) * 100) ELSE NULL END";
        $currentTotalWeight = '(current_pr_items.weight_needed * COALESCE(current_pr_items.quantity, 1))';
        $potentialDifference = "CASE WHEN $currentPriceIdr IS NOT NULL AND $bestPriceIdr IS NOT NULL THEN GREATEST(0, $diffIdrPerKg) * $currentTotalWeight ELSE NULL END";

        $bestPriceByMaterial = DB::table('quotation_items as history_items')
            ->join('quotations as history_quotes', 'history_items.quotation_id', '=', 'history_quotes.id')
            ->join('exchange_rates as history_rate', 'history_quotes.exchange_rate_id', '=', 'history_rate.id')
            ->join('pr_items as history_pr_items', 'history_items.pr_item_id', '=', 'history_pr_items.id')
            ->whereIn('history_quotes.status', $historyStatuses)
            ->whereNull('history_quotes.deleted_at')
            ->selectRaw('history_pr_items.material_name, MIN(' . $historyPriceIdr . ') as best_price_idr')
            ->groupBy('history_pr_items.material_name');

        $bestItemByMaterial = DB::table('quotation_items as history_items')
            ->join('quotations as history_quotes', 'history_items.quotation_id', '=', 'history_quotes.id')
            ->join('exchange_rates as history_rate', 'history_quotes.exchange_rate_id', '=', 'history_rate.id')
            ->join('pr_items as history_pr_items', 'history_items.pr_item_id', '=', 'history_pr_items.id')
            ->joinSub($bestPriceByMaterial, 'best_price', function ($join) use ($historyPriceIdr) {
                $join->on('best_price.material_name', '=', 'history_pr_items.material_name')
                    ->whereRaw('ABS((' . $historyPriceIdr . ') - best_price.best_price_idr) < 0.0001');
            })
            ->whereIn('history_quotes.status', $historyStatuses)
            ->whereNull('history_quotes.deleted_at')
            ->selectRaw('best_price.material_name, MIN(history_items.id) as best_item_id')
            ->groupBy('best_price.material_name');

        $query = DB::table('quotation_items as current_items')
            ->join('quotations as current_quotes', 'current_items.quotation_id', '=', 'current_quotes.id')
            ->join('pr_items as current_pr_items', 'current_items.pr_item_id', '=', 'current_pr_items.id')
            ->join('purchase_requirements as current_pr', 'current_pr_items.pr_id', '=', 'current_pr.id')
            ->leftJoin('users as current_supplier', 'current_quotes.supplier_id', '=', 'current_supplier.id')
            ->leftJoin('exchange_rates as current_rate', 'current_quotes.exchange_rate_id', '=', 'current_rate.id')
            ->leftJoinSub($bestItemByMaterial, 'best_choice', function ($join) {
                $join->on('best_choice.material_name', '=', 'current_pr_items.material_name');
            })
            ->leftJoin('quotation_items as best_items', 'best_choice.best_item_id', '=', 'best_items.id')
            ->leftJoin('quotations as best_quotes', 'best_items.quotation_id', '=', 'best_quotes.id')
            ->leftJoin('users as best_supplier', 'best_quotes.supplier_id', '=', 'best_supplier.id')
            ->leftJoin('exchange_rates as best_rate', 'best_quotes.exchange_rate_id', '=', 'best_rate.id')
            ->leftJoin('pr_items as best_pr_items', 'best_items.pr_item_id', '=', 'best_pr_items.id')
            ->leftJoin('purchase_requirements as best_pr', 'best_pr_items.pr_id', '=', 'best_pr.id')
            ->whereIn('current_quotes.status', $currentStatuses)
            ->whereNull('current_quotes.deleted_at')
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
                'current_supplier.name as current_supplier',
                'best_items.id as best_item_id',
                'best_items.quotation_id as best_quotation_id',
                'best_items.price_per_kg as best_price',
                'best_quotes.currency as best_currency',
                'best_quotes.submitted_at as best_submitted_at',
                'best_supplier.name as best_supplier',
                'best_pr.id as best_pr_id',
                'best_pr.pr_number as best_pr_number',
            ])
            ->selectRaw($currentPriceIdr . ' as current_price_idr')
            ->selectRaw('(current_items.amount * current_rate.rate_to_idr) as current_total_idr')
            ->selectRaw($currentTotalWeight . ' as total_weight')
            ->selectRaw($bestPriceIdr . ' as best_price_idr')
            ->selectRaw($diffIdrPerKg . ' as diff_idr_per_kg')
            ->selectRaw($diffPercent . ' as diff_percent')
            ->selectRaw($potentialDifference . ' as potential_difference_idr');

        if ($dateFrom && $dateTo) {
            $query->whereBetween('current_quotes.submitted_at', [$dateFrom, $dateTo]);
        }

        return $query->orderByRaw('potential_difference_idr DESC')
            ->orderBy('current_pr_items.material_name');
    }

    private function applyVsBestKeywordFilter($query, string $keyword)
    {
        if ($keyword === '') {
            return $query;
        }

        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword) . '%';

        return $query->where(function ($q) use ($like) {
            $q->where('current_pr_items.material_name', 'like', $like)
                ->orWhere('current_pr.pr_number', 'like', $like)
                ->orWhere('current_supplier.name', 'like', $like)
                ->orWhere('best_supplier.name', 'like', $like)
                ->orWhere('best_pr.pr_number', 'like', $like);
        });
    }

    private function extractDimensionFilters(Request $request): array
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

    private function matchesDimensionFilters(PrItem $item, array $filters): bool
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

    private function vsBestDateRange(Request $request): array
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

    private function buildVsBestSummary($query, float $competitiveThreshold): array
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

    private function emptyVsBestSummary(): array
    {
        return [
            'total_rows' => 0,
            'competitive_count' => 0,
            'above_count' => 0,
            'total_potential_difference_idr' => 0,
            'average_diff_idr_per_kg' => null,
        ];
    }

    private function priceCompetitivenessStatus(?float $diffPercent, float $competitiveThreshold): array
    {
        if ($diffPercent === null) {
            return [
                'label' => 'N/A',
                'class' => 'bg-secondary',
                'icon' => 'bi-dash-circle',
                'recommendation' => 'Safe',
            ];
        }

        if ($diffPercent <= 0) {
            return [
                'label' => 'Best Price',
                'class' => 'bg-success',
                'icon' => 'bi-check-circle',
                'recommendation' => 'Safe',
            ];
        }

        if ($diffPercent <= $competitiveThreshold) {
            return [
                'label' => 'Competitive',
                'class' => 'bg-primary',
                'icon' => 'bi-shield-check',
                'recommendation' => 'Safe',
            ];
        }

        return [
            'label' => 'Above History',
            'class' => 'bg-warning text-dark',
            'icon' => 'bi-info-circle',
            'recommendation' => 'Safe, check context',
        ];
    }

    private function routeWithReturn(string $routeName, mixed $parameters, string $returnUrl): string
    {
        $parameters = is_array($parameters) ? $parameters : [$parameters];
        $parameters[PurchasingNavigation::RETURN_URL_KEY] = $returnUrl;

        return route($routeName, $parameters);
    }

    private function historicalMaterialsForSupplier($supplierId)
    {
        if (! $supplierId) {
            return collect();
        }

        return PrItem::query()
            ->select('material_name', 'shape')
            ->distinct()
            ->whereNotNull('material_name')
            ->where('material_name', '<>', '')
            ->whereHas('quotationItems.quotation', function ($q) use ($supplierId) {
                $q->where('supplier_id', $supplierId)
                    ->whereIn('status', ['submitted', 'accepted', 'rejected']);
            })
            ->orderBy('material_name')
            ->get()
            ->unique('material_name')
            ->map(function ($item) {
                return [
                    'name' => $item->material_name,
                    'shape' => $item->shape,
                ];
            })
            ->values();
    }

    private function formatRupiah($value): string
    {
        return $value !== null
            ? 'Rp ' . number_format((float) $value, 0, ',', '.')
            : '-';
    }

    private function formatSignedRupiah($value): string
    {
        if ($value === null) {
            return '-';
        }

        $value = (float) $value;
        $prefix = $value > 0 ? '+' : ($value < 0 ? '-' : '');

        return $prefix . 'Rp ' . number_format(abs($value), 0, ',', '.');
    }

    private function formatNumber($value, int $decimals = 2): string
    {
        return $value !== null
            ? number_format((float) $value, $decimals, ',', '.')
            : '-';
    }

    private function formatPercent($value): string
    {
        if ($value === null) {
            return '-';
        }

        $value = (float) $value;

        return ($value > 0 ? '+' : '') . number_format($value, 2, ',', '.') . '%';
    }

    private function formatDate($value): ?string
    {
        return $value
            ? \Illuminate\Support\Carbon::parse($value)->format('d M Y')
            : null;
    }

    private function buildMonthlyHistoricalData($supplierId, string $materialName, $dateFrom, array $dimensionFilters = []): array
    {
        $items = QuotationItem::query()
            ->select('quotation_items.*')
            ->join('quotations', 'quotation_items.quotation_id', '=', 'quotations.id')
            ->where('quotations.supplier_id', $supplierId)
            ->whereIn('quotations.status', ['submitted', 'accepted', 'rejected'])
            ->whereHas('prItem', function ($q) use ($materialName, $dimensionFilters) {
                $q->where('material_name', $materialName);
                foreach ($dimensionFilters as $field => $val) {
                    $q->where($field, $val);
                }
            })
            ->with([
                'quotation.supplier',
                'quotation.purchaseRequisition.period',
                'quotation.exchange_rate',
                'prItem.purchaseRequisition' => function ($q) {
                    $q->select('id', 'pr_number');
                },
            ]);

        if ($dateFrom) {
            $items->where('quotations.submitted_at', '>=', $dateFrom);
        }

        $items = $items
            ->orderByRaw('YEAR(quotations.submitted_at) ASC')
            ->orderByRaw('MONTH(quotations.submitted_at) ASC')
            ->orderBy('quotations.submitted_at', 'asc')
            ->orderBy('quotation_items.id', 'asc')
            ->get();

        $tableData = $items->map(function ($item) {
            $period = optional(optional($item->quotation->purchaseRequisition)->period);
            $rate = $item->quotation->exchange_rate;
            $priceIdr = $rate ? round((float) $item->price_per_kg * (float) $rate->rate_to_idr, 0) : null;
            $totalIdr = $rate ? round((float) $item->amount * (float) $rate->rate_to_idr, 0) : null;
            $purchaseRequisition = $item->prItem?->purchaseRequisition;
            $submittedAt = $item->quotation->submitted_at;
            $periodYear = (int) ($period->year ?? 0);
            $periodMonth = (int) ($period->month ?? 0);
            $periodSort = $submittedAt
                ? $submittedAt->format('Y-m-d H:i:s') . '-' . str_pad((string) $item->id, 10, '0', STR_PAD_LEFT)
                : (
                    $periodYear > 0 && $periodMonth > 0
                        ? sprintf('%04d-%02d-00 00:00:00-%010d', $periodYear, $periodMonth, (int) $item->id)
                        : sprintf('9999-99-99 99:99:99-%010d', (int) $item->id)
                );
            $periodLabel = $submittedAt
                ? $submittedAt->format('M Y')
                : ($period->name ?? 'Unknown');

            return [
                'period' => $periodLabel,
                'period_sort' => $periodSort,
                'pr_id' => $purchaseRequisition?->id,
                'pr_number' => $purchaseRequisition?->pr_number ?? '-',
                'pr_url' => $purchaseRequisition
                    ? PurchasingNavigation::toRoute('purchasing.requirements.show', $purchaseRequisition->id)
                    : null,
                'supplier' => $item->quotation->supplier->name ?? '-',
                'price_per_kg' => (float) $item->price_per_kg,
                'currency' => $item->quotation->currency,
                'price_idr' => $priceIdr,
                'total_idr' => $totalIdr,
                'min_idr' => null,
                'max_idr' => null,
                'submitted_at' => $submittedAt?->toIso8601String(),
                'submitted_at_display' => $submittedAt?->format('d M Y'),
            ];
        })->sortBy('period_sort', SORT_NATURAL)->values();

        $tableData = $this->appendChangePercent($tableData);

        return [[
            'type' => 'monthly',
            'labels' => $tableData->pluck('period')->values(),
            'prices' => $tableData->pluck('price_per_kg')->values(),
            'pricesIdr' => $tableData->pluck('price_idr')->map(fn($price) => $price ?? 0)->values(),
        ], $tableData];
    }

    private function buildYearlyHistoricalData($supplierId, string $materialName, $dateFrom, array $dimensionFilters = []): array
    {
        $query = QuotationItem::query()
            ->join('quotations', 'quotation_items.quotation_id', '=', 'quotations.id')
            ->join('exchange_rates', 'quotations.exchange_rate_id', '=', 'exchange_rates.id')
            ->join('pr_items', 'quotation_items.pr_item_id', '=', 'pr_items.id')
            ->where('quotations.supplier_id', $supplierId)
            ->whereIn('quotations.status', ['submitted', 'accepted', 'rejected'])
            ->where('pr_items.material_name', $materialName);

        foreach ($dimensionFilters as $field => $val) {
            $query->where("pr_items.{$field}", $val);
        }

        if ($dateFrom) {
            $query->where('quotations.submitted_at', '>=', $dateFrom);
        }

        $rows = $query
            ->selectRaw('
                YEAR(quotations.submitted_at) as period_year,
                AVG(quotation_items.price_per_kg * exchange_rates.rate_to_idr) as avg_idr,
                MIN(quotation_items.price_per_kg * exchange_rates.rate_to_idr) as min_idr,
                MAX(quotation_items.price_per_kg * exchange_rates.rate_to_idr) as max_idr
            ')
            ->whereNotNull('quotations.submitted_at')
            ->groupByRaw('YEAR(quotations.submitted_at)')
            ->orderByRaw('YEAR(quotations.submitted_at) ASC')
            ->get();

        $tableData = $rows->map(function ($row) {
            return [
                'period' => (string) $row->period_year,
                'period_sort' => (int) $row->period_year,
                'price_per_kg' => null,
                'currency' => 'IDR',
                'price_idr' => round((float) $row->avg_idr, 0),
                'min_idr' => round((float) $row->min_idr, 0),
                'max_idr' => round((float) $row->max_idr, 0),
                'submitted_at' => null,
            ];
        })->sortBy('period_sort')->values();

        $tableData = $this->appendChangePercent($tableData);

        return [[
            'type' => 'yearly',
            'labels' => $tableData->pluck('period')->values(),
            'prices' => [],
            'pricesIdr' => $tableData->pluck('price_idr')->values(),
            'minIdr' => $tableData->pluck('min_idr')->values(),
            'maxIdr' => $tableData->pluck('max_idr')->values(),
        ], $tableData];
    }

    private function appendChangePercent($rows)
    {
        $previous = null;

        return collect($rows)->map(function ($row) use (&$previous) {
            $current = $row['price_idr'] ?? null;
            $row['change_pct'] = null;

            if ($previous !== null && $previous > 0 && $current !== null) {
                $row['change_pct'] = round((($current - $previous) / $previous) * 100, 2);
            }

            if ($current !== null) {
                $previous = $current;
            }

            return $row;
        })->values();
    }

    private function buildHistoricalSummary($rows): array
    {
        $rows = collect($rows);
        $changes = $rows->pluck('change_pct')->filter(fn($change) => $change !== null);
        $prices = $rows->pluck('price_idr')
            ->filter(fn($price) => $price !== null && $price > 0)
            ->values();

        return [
            'average_change_pct' => $changes->count() > 0 ? round($changes->avg(), 2) : null,
            'total_change_pct' => $prices->count() >= 2
                ? round((($prices->last() - $prices->first()) / $prices->first()) * 100, 2)
                : null,
        ];
    }

    private function historicalRangeOptions(string $periodView): array
    {
        if ($periodView === 'yearly') {
            return [
                '1y' => '1 Tahun',
                '2y' => '2 Tahun',
                '5y' => '5 Tahun',
                'all' => 'Semua Tahun',
            ];
        }

        return [
            '3m' => '3 Bulan',
            '6m' => '6 Bulan',
            '12m' => '12 Bulan',
            '24m' => '24 Bulan',
            'all' => 'Semua Bulan',
        ];
    }

    private function normalizeHistoricalRange(string $periodView, ?string $range): string
    {
        $range = $range ?: 'all';

        if ($periodView === 'monthly') {
            $range = match ($range) {
                '1y' => '12m',
                '2y' => '24m',
                default => $range,
            };
        } else {
            $range = match ($range) {
                '3m', '6m', '12m' => '1y',
                '24m' => '2y',
                default => $range,
            };
        }

        return array_key_exists($range, $this->historicalRangeOptions($periodView))
            ? $range
            : 'all';
    }

    private function dateFromRange(string $range)
    {
        return match ($range) {
            '3m' => now()->subMonths(3),
            '6m' => now()->subMonths(6),
            '12m', '1y' => now()->subYear(),
            '24m', '2y' => now()->subYears(2),
            '5y' => now()->subYears(5),
            default => null,
        };
    }
}
