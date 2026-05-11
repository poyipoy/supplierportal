<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequirement;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\PrItem;
use App\Models\User;
use App\Models\ExchangeRate;
use Illuminate\Http\Request;

class PriceComparisonController extends Controller
{
    /**
     * View 1: Antar Supplier — Side-by-side per PR + grafik batang.
     */
    public function interSupplier(Request $request)
    {
        // Ambil PR yang punya ≥2 quotation submitted/accepted
        $eligiblePrs = PurchaseRequirement::with('period')
            ->whereHas('quotations', function ($q) {
                $q->whereIn('status', ['submitted', 'accepted', 'rejected']);
            }, '>=', 2)
            ->orderByDesc('created_at')
            ->get();

        $comparison = null;
        $chartData = null;
        $selectedPr = null;

        if ($request->filled('pr_id')) {
            $selectedPr = PurchaseRequirement::with(['items', 'period'])->find($request->pr_id);

            if ($selectedPr) {
                $quotations = Quotation::with(['supplier', 'items.prItem', 'exchange_rate'])
                    ->where('pr_id', $selectedPr->id)
                    ->whereIn('status', ['submitted', 'accepted', 'rejected'])
                    ->get();

                // Build comparison matrix: rows = pr_items, columns = suppliers
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
                    foreach ($quotations as $q) {
                        $qItem = $q->items->where('pr_item_id', $item->id)->first();
                        $rate = $q->exchange_rate;
                        $pricePerKg = $qItem ? (float) $qItem->price_per_kg : null;
                        $priceIdr = ($pricePerKg && $rate) ? $pricePerKg * $rate->rate_to_idr : null;

                        $row['prices'][$q->id] = [
                            'price_per_kg' => $pricePerKg,
                            'price_idr' => $priceIdr,
                            'amount' => $qItem ? (float) $qItem->amount : null,
                            'currency' => $q->currency,
                        ];
                    }
                    $matrix[] = $row;
                }

                $comparison = [
                    'suppliers' => $suppliers,
                    'matrix' => $matrix,
                    'quotations' => $quotations,
                ];

                // Chart data: per item, bar per supplier (IDR price_per_kg)
                $chartLabels = $selectedPr->items->pluck('material_name')->toArray();
                $chartDatasets = [];
                $colors = ['#1F5FA6', '#C0392B', '#27AE60', '#F39C12', '#8E44AD', '#16A085'];
                foreach ($quotations as $idx => $q) {
                    $data = [];
                    foreach ($selectedPr->items as $item) {
                        $qItem = $q->items->where('pr_item_id', $item->id)->first();
                        $rate = $q->exchange_rate;
                        $data[] = ($qItem && $rate) ? round((float) $qItem->price_per_kg * $rate->rate_to_idr, 0) : 0;
                    }
                    $chartDatasets[] = [
                        'label' => $q->supplier->name,
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
            'eligiblePrs', 'comparison', 'chartData', 'selectedPr'
        ));
    }

    /**
     * View 2: Historis — Grafik garis harga material dari satu supplier lintas periode.
     */
    public function historical(Request $request)
    {
        $suppliers = User::where('role', 'supplier')->orderBy('name')->get();

        // Ambil semua material unik
        $materials = PrItem::select('material_name')
            ->distinct()
            ->orderBy('material_name')
            ->pluck('material_name');

        $chartData = null;
        $tableData = [];

        if ($request->filled('supplier_id') && $request->filled('material_name')) {
            // Cari semua quotation_items dari supplier ini untuk material ini
            $items = QuotationItem::with(['quotation.purchaseRequirement.period', 'quotation.exchange_rate'])
                ->whereHas('quotation', function ($q) use ($request) {
                    $q->where('supplier_id', $request->supplier_id)
                      ->whereIn('status', ['submitted', 'accepted', 'rejected']);
                })
                ->whereHas('prItem', function ($q) use ($request) {
                    $q->where('material_name', $request->material_name);
                })
                ->get()
                ->sortBy(fn($i) => optional(optional($i->quotation->purchaseRequirement)->period)->year . '-' .
                    str_pad(optional(optional($i->quotation->purchaseRequirement)->period)->month, 2, '0', STR_PAD_LEFT));

            $labels = [];
            $prices = [];
            $pricesIdr = [];

            foreach ($items as $item) {
                $period = optional(optional($item->quotation->purchaseRequirement)->period);
                $label = $period->name ?? 'Unknown';
                $labels[] = $label;
                $prices[] = (float) $item->price_per_kg;

                $rate = $item->quotation->exchange_rate;
                $pricesIdr[] = $rate ? round((float) $item->price_per_kg * $rate->rate_to_idr, 0) : 0;

                $tableData[] = [
                    'period' => $label,
                    'price_per_kg' => (float) $item->price_per_kg,
                    'currency' => $item->quotation->currency,
                    'price_idr' => $rate ? round((float) $item->price_per_kg * $rate->rate_to_idr, 0) : null,
                    'submitted_at' => optional($item->quotation->submitted_at)->format('d M Y'),
                ];
            }

            $chartData = [
                'labels' => $labels,
                'prices' => $prices,
                'pricesIdr' => $pricesIdr,
            ];
        }

        return view('purchasing.comparison.historical', compact(
            'suppliers', 'materials', 'chartData', 'tableData'
        ));
    }

    /**
     * View 3: vs Harga Terbaik — Harga saat ini vs MIN(price_per_kg) histori.
     */
    public function vsBestPrice(Request $request)
    {
        // Ambil periode aktif (open) atau filter
        $periods = \App\Models\Period::orderByDesc('year')->orderByDesc('month')->get();
        $selectedPeriodId = $request->input('period_id', $periods->first()?->id);

        $data = [];

        if ($selectedPeriodId) {
            // Ambil PR dari periode ini
            $prs = PurchaseRequirement::where('period_id', $selectedPeriodId)
                ->with(['items.quotationItems.quotation.supplier', 'items.quotationItems.quotation.exchange_rate'])
                ->get();

            foreach ($prs as $pr) {
                foreach ($pr->items as $item) {
                    // Harga saat ini (quotation items dari periode ini)
                    $currentQuotationItems = $item->quotationItems->filter(function ($qi) {
                        return in_array($qi->quotation->status, ['submitted', 'accepted', 'rejected']);
                    });

                    foreach ($currentQuotationItems as $qi) {
                        $currentPrice = (float) $qi->price_per_kg;
                        $currentCurrency = $qi->quotation->currency;
                        $currentRate = $qi->quotation->exchange_rate;
                        $currentPriceIdr = $currentRate ? $currentPrice * $currentRate->rate_to_idr : null;

                        // Harga terbaik histori (semua quotation_items untuk material_name yang sama)
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
                        $bestPriceIdr = ($bestPrice && $bestRate) ? $bestPrice * $bestRate->rate_to_idr : null;
                        $bestSupplier = $bestItem ? $bestItem->quotation->supplier->name : '-';

                        $diffPercent = ($currentPriceIdr && $bestPriceIdr && $bestPriceIdr > 0)
                            ? round((($currentPriceIdr - $bestPriceIdr) / $bestPriceIdr) * 100, 1)
                            : null;

                        $data[] = [
                            'material_name' => $item->material_name,
                            'supplier' => $qi->quotation->supplier->name,
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
}
