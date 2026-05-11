<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequirement;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\PrItem;
use App\Models\User;
use Illuminate\Http\Request;

class PriceComparisonController extends Controller
{
    /**
     * View 1: Antar Supplier - side-by-side per PR + grafik batang.
     */
    public function interSupplier(Request $request)
    {
        $eligiblePrs = PurchaseRequirement::with(['period', 'quotations' => function ($q) {
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

        if ($request->filled('pr_id')) {
            $selectedPr = PurchaseRequirement::with(['items', 'period'])->find($request->pr_id);

            if ($selectedPr) {
                $materialOptions = $selectedPr->items;
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
                foreach ($selectedPr->items as $item) {
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
                            'price_per_kg' => $pricePerKg,
                            'price_idr' => $priceIdr,
                            'amount' => $quotationItem ? (float) $quotationItem->amount : null,
                            'currency' => $quotation->currency,
                        ];
                    }

                    $matrix[] = $row;
                }

                $comparison = [
                    'suppliers' => $suppliers,
                    'matrix' => $matrix,
                    'quotations' => $quotations,
                ];

                $chartLabels = $selectedPr->items->pluck('material_name')->toArray();
                $chartMaterialIds = $selectedPr->items->pluck('id')->map(fn($id) => (string) $id)->toArray();
                $chartDatasets = [];
                $colors = ['#1F5FA6', '#C0392B', '#27AE60', '#F39C12', '#8E44AD', '#16A085'];

                foreach ($quotations as $idx => $quotation) {
                    $data = [];

                    foreach ($selectedPr->items as $item) {
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
            'selectedPr'
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
        $range = $request->input('range', 'all');
        $dateFrom = $this->dateFromRange($range);
        $selectedSupplier = $selectedSupplierId ? $suppliers->firstWhere('id', (int) $selectedSupplierId) : null;

        $materialsQuery = PrItem::select('material_name')->distinct();

        if ($selectedSupplierId) {
            $materialsQuery->whereHas('quotationItems.quotation', function ($q) use ($selectedSupplierId) {
                $q->where('supplier_id', $selectedSupplierId)
                    ->whereIn('status', ['submitted', 'accepted', 'rejected']);
            });
        }

        $materials = $materialsQuery->orderBy('material_name')->pluck('material_name');
        $chartData = null;
        $tableData = collect();
        $summary = [
            'average_change_pct' => null,
            'total_change_pct' => null,
        ];

        if ($selectedSupplierId && $selectedMaterialName) {
            [$chartData, $tableData] = $periodView === 'yearly'
                ? $this->buildYearlyHistoricalData($selectedSupplierId, $selectedMaterialName, $dateFrom)
                : $this->buildMonthlyHistoricalData($selectedSupplierId, $selectedMaterialName, $dateFrom);

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
            'materialName' => $selectedMaterialName,
            'supplierName' => $selectedSupplier->name ?? '',
        ];

        if ($request->wantsJson() || $request->input('view') === 'json') {
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
            'payload'
        ));
    }

    /**
     * View 3: vs Harga Terbaik - harga saat ini vs MIN(price_per_kg) histori.
     */
    public function vsBestPrice(Request $request)
    {
        $periods = \App\Models\Period::orderByDesc('year')->orderByDesc('month')->get();
        $selectedPeriodId = $request->input('period_id', $periods->first()?->id);

        $data = [];

        if ($selectedPeriodId) {
            $prs = PurchaseRequirement::where('period_id', $selectedPeriodId)
                ->with(['items.quotationItems.quotation.supplier', 'items.quotationItems.quotation.exchange_rate'])
                ->get();

            foreach ($prs as $pr) {
                foreach ($pr->items as $item) {
                    $currentQuotationItems = $item->quotationItems->filter(function ($quotationItem) {
                        return in_array($quotationItem->quotation->status, ['submitted', 'accepted', 'rejected']);
                    });

                    foreach ($currentQuotationItems as $quotationItem) {
                        $currentPrice = (float) $quotationItem->price_per_kg;
                        $currentCurrency = $quotationItem->quotation->currency;
                        $currentRate = $quotationItem->quotation->exchange_rate;
                        $currentPriceIdr = $currentRate ? $currentPrice * (float) $currentRate->rate_to_idr : null;

                        $bestItem = QuotationItem::whereHas('prItem', function ($q) use ($item) {
                                $q->where('material_name', $item->material_name);
                            })
                            ->whereHas('quotation', function ($q) {
                                $q->whereIn('status', ['submitted', 'accepted', 'rejected']);
                            })
                            ->orderBy('price_per_kg', 'asc')
                            ->with(['quotation.supplier', 'quotation.exchange_rate'])
                            ->first();

                        $bestPrice = $bestItem ? (float) $bestItem->price_per_kg : null;
                        $bestCurrency = $bestItem ? $bestItem->quotation->currency : null;
                        $bestRate = $bestItem ? $bestItem->quotation->exchange_rate : null;
                        $bestPriceIdr = ($bestPrice && $bestRate) ? $bestPrice * (float) $bestRate->rate_to_idr : null;
                        $bestSupplier = $bestItem ? $bestItem->quotation->supplier->name : '-';

                        $diffPercent = ($currentPriceIdr && $bestPriceIdr && $bestPriceIdr > 0)
                            ? round((($currentPriceIdr - $bestPriceIdr) / $bestPriceIdr) * 100, 1)
                            : null;

                        $data[] = [
                            'material_name' => $item->material_name,
                            'supplier' => $quotationItem->quotation->supplier->name,
                            'current_price' => $currentPrice,
                            'current_currency' => $currentCurrency,
                            'current_price_idr' => $currentPriceIdr,
                            'best_price' => $bestPrice,
                            'best_currency' => $bestCurrency,
                            'best_price_idr' => $bestPriceIdr,
                            'best_supplier' => $bestSupplier,
                            'diff_percent' => $diffPercent,
                        ];
                    }
                }
            }
        }

        return view('purchasing.comparison.vs-best', compact('periods', 'selectedPeriodId', 'data'));
    }

    private function buildMonthlyHistoricalData($supplierId, string $materialName, $dateFrom): array
    {
        $items = QuotationItem::with(['quotation.purchaseRequirement.period', 'quotation.exchange_rate'])
            ->whereHas('quotation', function ($q) use ($supplierId, $dateFrom) {
                $q->where('supplier_id', $supplierId)
                    ->whereIn('status', ['submitted', 'accepted', 'rejected']);

                if ($dateFrom) {
                    $q->where('created_at', '>=', $dateFrom);
                }
            })
            ->whereHas('prItem', function ($q) use ($materialName) {
                $q->where('material_name', $materialName);
            })
            ->get()
            ->sortBy(function ($item) {
                $period = optional(optional($item->quotation->purchaseRequirement)->period);
                $year = $period->year ?? 0;
                $month = $period->month ?? 0;
                $submittedAt = optional($item->quotation->submitted_at)->format('YmdHis') ?? '';

                return sprintf('%04d-%02d-%s', $year, $month, $submittedAt);
            })
            ->values();

        $tableData = $items->map(function ($item) {
            $period = optional(optional($item->quotation->purchaseRequirement)->period);
            $rate = $item->quotation->exchange_rate;
            $priceIdr = $rate ? round((float) $item->price_per_kg * (float) $rate->rate_to_idr, 0) : null;

            return [
                'period' => $period->name ?? 'Unknown',
                'price_per_kg' => (float) $item->price_per_kg,
                'currency' => $item->quotation->currency,
                'price_idr' => $priceIdr,
                'min_idr' => null,
                'max_idr' => null,
                'submitted_at' => optional($item->quotation->submitted_at)->format('d M Y'),
            ];
        });

        $tableData = $this->appendChangePercent($tableData);

        return [[
            'type' => 'monthly',
            'labels' => $tableData->pluck('period')->values(),
            'prices' => $tableData->pluck('price_per_kg')->values(),
            'pricesIdr' => $tableData->pluck('price_idr')->map(fn($price) => $price ?? 0)->values(),
        ], $tableData];
    }

    private function buildYearlyHistoricalData($supplierId, string $materialName, $dateFrom): array
    {
        $query = QuotationItem::query()
            ->join('quotations', 'quotation_items.quotation_id', '=', 'quotations.id')
            ->join('exchange_rates', 'quotations.exchange_rate_id', '=', 'exchange_rates.id')
            ->join('pr_items', 'quotation_items.pr_item_id', '=', 'pr_items.id')
            ->where('quotations.supplier_id', $supplierId)
            ->whereIn('quotations.status', ['submitted', 'accepted', 'rejected'])
            ->where('pr_items.material_name', $materialName);

        if ($dateFrom) {
            $query->where('quotations.created_at', '>=', $dateFrom);
        }

        $rows = $query
            ->selectRaw('
                YEAR(quotations.created_at) as period_year,
                AVG(quotation_items.price_per_kg * exchange_rates.rate_to_idr) as avg_idr,
                MIN(quotation_items.price_per_kg * exchange_rates.rate_to_idr) as min_idr,
                MAX(quotation_items.price_per_kg * exchange_rates.rate_to_idr) as max_idr
            ')
            ->groupByRaw('YEAR(quotations.created_at)')
            ->orderByRaw('YEAR(quotations.created_at) ASC')
            ->get();

        $tableData = $rows->map(function ($row) {
            return [
                'period' => (string) $row->period_year,
                'price_per_kg' => null,
                'currency' => 'IDR',
                'price_idr' => round((float) $row->avg_idr, 0),
                'min_idr' => round((float) $row->min_idr, 0),
                'max_idr' => round((float) $row->max_idr, 0),
                'submitted_at' => null,
            ];
        });

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

    private function dateFromRange(string $range)
    {
        return match ($range) {
            '6m' => now()->subMonths(6),
            '1y' => now()->subYear(),
            '2y' => now()->subYears(2),
            default => null,
        };
    }
}
