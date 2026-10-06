<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\MaterialClaim;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Services\RegionalDisplayFormatter;
use App\Support\BusinessTime;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PurchasingController extends Controller
{
    public function dashboard(RegionalDisplayFormatter $regionalFormatter)
    {
        // ─── Cached dashboard widgets (10 menit) ───
        $dashboardData = Cache::remember(
            'purchasing_dashboard_widgets:'.app()->getLocale(),
            now()->addMinutes(10),
            function () use ($regionalFormatter) {
                $prAktif = PurchaseRequisition::whereIn('status', ['submitted', 'bidding'])->count();
                $menungguPenawaran = PurchaseRequisition::where('status', 'submitted')
                    ->whereDoesntHave('quotations')->count();
                $poBerjalan = PurchaseOrder::whereIn('status', ['active', 'overdue', 'waiting_qc'])->count();
                $materialMingguIni = PurchaseOrder::where('status', 'active')
                    ->whereBetween('estimated_arrival', [now(), now()->addDays(7)])->count();

                // Chart: PR by month using one grouped query instead of six separate queries.
                $sixMonthsAgo = Carbon::now()->subMonths(5)->startOfMonth();
                $prCounts = PurchaseRequisition::where('created_at', '>=', $sixMonthsAgo)
                    ->selectRaw('YEAR(created_at) as yr, MONTH(created_at) as mn, COUNT(*) as total')
                    ->groupByRaw('YEAR(created_at), MONTH(created_at)')
                    ->get()
                    ->keyBy(fn ($row) => $row->yr.'-'.$row->mn);

                $prPerBulan = [];
                for ($i = 5; $i >= 0; $i--) {
                    $d = Carbon::now()->subMonths($i);
                    $key = $d->year.'-'.$d->month;
                    $prPerBulan[] = [
                        'label' => $regionalFormatter->monthYear($d),
                        'count' => (int) ($prCounts->get($key)?->total ?? 0),
                    ];
                }

                // Chart: PO status distribution.
                $poStatusDist = PurchaseOrder::selectRaw('status, COUNT(*) as total')
                    ->groupBy('status')->pluck('total', 'status')->toArray();

                // Operational checks
                $documentStatusSubquery = DB::table('purchase_orders')
                    ->leftJoin('po_documents', 'po_documents.po_id', '=', 'purchase_orders.id')
                    ->whereNull('purchase_orders.deleted_at')
                    ->selectRaw('
                        purchase_orders.id,
                        COUNT(po_documents.id) as documents_count,
                        SUM(CASE WHEN po_documents.status IN (?, ?, ?) THEN 1 ELSE 0 END) as completed_documents_count
                    ', ['received', 'verified', 'done'])
                    ->groupBy('purchase_orders.id');

                $poDocumentsIncomplete = DB::query()
                    ->fromSub($documentStatusSubquery, 'po_doc_status')
                    ->where(function ($query) {
                        $query->where('documents_count', '<', 4)
                            ->orWhere('completed_documents_count', '<', 4);
                    })
                    ->count();

                $completedPrWithoutPo = PurchaseRequisition::where('status', 'completed')
                    ->whereDoesntHave('quotations.purchaseOrders')
                    ->count();

                $waitingQcLong = PurchaseOrder::where('status', 'waiting_qc')
                    ->whereNotNull('actual_arrival')
                    ->whereDate('actual_arrival', '<', BusinessTime::today()->subDays(2)->toDateString())
                    ->count();

                $claimsPastDeadline = MaterialClaim::where('status', 'pending')
                    ->whereDate('deadline', '<', BusinessTime::today()->toDateString())
                    ->count();

                return compact(
                    'prAktif', 'menungguPenawaran', 'poBerjalan', 'materialMingguIni',
                    'prPerBulan', 'poStatusDist', 'poDocumentsIncomplete',
                    'completedPrWithoutPo', 'waitingQcLong', 'claimsPastDeadline'
                );
            }
        );

        // Extract cached data
        extract($dashboardData);

        $operationalChecks = [
            [
                'label' => __('purchasing.copy.completed_pr_without_po'),
                'count' => $completedPrWithoutPo,
                'icon' => 'clipboard-x',
                'class' => 'danger',
                'url' => route('purchasing.requisitions.index', ['status' => 'completed']),
                'description' => __('purchasing.copy.completed_pr_records_that_are_not_linked_to_any_po_yet'),
            ],
            [
                'label' => __('purchasing.copy.incomplete_po_documents'),
                'count' => $poDocumentsIncomplete,
                'icon' => 'file-spreadsheet',
                'class' => 'warning',
                'url' => route('purchasing.purchase-orders.index'),
                'description' => __('purchasing.copy.po_records_that_do_not_have_all_4_completed_import_documents_yet'),
            ],
            [
                'label' => __('purchasing.copy.waiting_qc_2_days'),
                'count' => $waitingQcLong,
                'icon' => 'clipboard-check',
                'class' => 'info',
                'url' => route('purchasing.purchase-orders.index', ['status' => 'waiting_qc']),
                'description' => __('purchasing.copy.po_records_that_have_arrived_but_have_not_completed_qc_inspection_for_more_than_2_days'),
            ],
            [
                'label' => __('purchasing.copy.claims_past_deadline'),
                'count' => $claimsPastDeadline,
                'icon' => 'octagon-alert',
                'class' => 'danger',
                'url' => route('purchasing.claims.index'),
                'description' => __('purchasing.copy.pending_claims_that_have_passed_the_supplier_response_deadline'),
            ],
        ];

        // Quick tables remain uncached so the data is always current.
        $prTerbaru = PurchaseRequisition::with('period')->orderBy('created_at', 'desc')->take(5)->get();
        $poTerdekat = PurchaseOrder::with(['supplier', 'quotations.purchaseRequisition'])
            ->whereIn('status', ['active', 'overdue'])->whereNotNull('estimated_arrival')
            ->orderBy('estimated_arrival', 'asc')->take(5)->get();

        // Exchange rates are cached by ExchangeRate::latestRate.
        $latestRates = collect(ExchangeRate::CURRENCIES)
            ->mapWithKeys(fn ($currency) => [$currency => ExchangeRate::latestRate($currency)]);

        return view('purchasing.dashboard', compact(
            'prAktif', 'menungguPenawaran', 'poBerjalan', 'materialMingguIni',
            'prPerBulan', 'poStatusDist', 'prTerbaru', 'poTerdekat', 'latestRates',
            'operationalChecks'
        ));
    }

    public function updateKurs(Request $request)
    {
        $request->validate([
            'currency' => ['required', Rule::in(ExchangeRate::CURRENCIES)],
            'rate_to_idr' => 'required|numeric|min:0.01',
        ]);
        ExchangeRate::create([
            'currency' => $request->currency,
            'rate_to_idr' => $request->rate_to_idr,
            'valid_from' => now(),
            'created_by' => auth()->id(),
        ]);

        return back()->with('success', __('purchasing.feedback.rate_updated', ['currency' => $request->currency]));
    }
}
