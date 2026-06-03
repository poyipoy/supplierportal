<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\MaterialClaim;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequirement;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PurchasingController extends Controller
{
    public function dashboard()
    {
        $prAktif = PurchaseRequirement::whereIn('status', ['submitted', 'bidding'])->count();
        $menungguPenawaran = PurchaseRequirement::where('status', 'submitted')
            ->whereDoesntHave('quotations')->count();
        $poBerjalan = PurchaseOrder::whereIn('status', ['active', 'overdue', 'waiting_qc'])->count();
        $materialMingguIni = PurchaseOrder::where('status', 'active')
            ->whereBetween('estimated_arrival', [now(), now()->addDays(7)])->count();

        // Grafik: PR per bulan (6 bulan terakhir)
        $prPerBulan = [];
        for ($i = 5; $i >= 0; $i--) {
            $d = Carbon::now()->subMonths($i);
            $prPerBulan[] = [
                'label' => $d->format('M Y'),
                'count' => PurchaseRequirement::whereYear('created_at', $d->year)
                    ->whereMonth('created_at', $d->month)->count(),
            ];
        }

        // Grafik: Distribusi Status PO
        $poStatusDist = PurchaseOrder::selectRaw('status, COUNT(*) as total')
            ->groupBy('status')->pluck('total', 'status')->toArray();

        // Tabel cepat
        $prTerbaru = PurchaseRequirement::with('period')->orderBy('created_at', 'desc')->take(5)->get();
        $poTerdekat = PurchaseOrder::with(['supplier', 'quotations.purchaseRequirement'])
            ->whereIn('status', ['active', 'overdue'])->whereNotNull('estimated_arrival')
            ->orderBy('estimated_arrival', 'asc')->take(5)->get();

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

        $operationalChecks = [
            [
                'label' => 'PR Completed Tanpa PO',
                'count' => PurchaseRequirement::where('status', 'completed')
                    ->whereDoesntHave('quotations.purchaseOrders')
                    ->count(),
                'icon' => 'bi-clipboard-x',
                'class' => 'danger',
                'url' => route('purchasing.requirements.index', ['status' => 'completed']),
                'description' => 'PR berstatus completed tetapi belum terhubung ke PO.',
            ],
            [
                'label' => 'Dokumen PO Belum Lengkap',
                'count' => $poDocumentsIncomplete,
                'icon' => 'bi-file-earmark-excel',
                'class' => 'warning',
                'url' => route('purchasing.purchase-orders.index'),
                'description' => 'PO yang belum memiliki 4 dokumen impor lengkap.',
            ],
            [
                'label' => 'Waiting QC > 2 Hari',
                'count' => PurchaseOrder::where('status', 'waiting_qc')
                    ->whereNotNull('actual_arrival')
                    ->whereDate('actual_arrival', '<', today()->subDays(2))
                    ->count(),
                'icon' => 'bi-clipboard-pulse',
                'class' => 'info',
                'url' => route('purchasing.purchase-orders.index', ['status' => 'waiting_qc']),
                'description' => 'PO yang sudah tiba tetapi belum selesai inspeksi QC lebih dari 2 hari.',
            ],
            [
                'label' => 'Klaim Lewat Deadline',
                'count' => MaterialClaim::where('status', 'pending')
                    ->whereDate('deadline', '<', today())
                    ->count(),
                'icon' => 'bi-exclamation-octagon',
                'class' => 'danger',
                'url' => route('purchasing.claims.index'),
                'description' => 'Klaim pending yang sudah melewati deadline respons supplier.',
            ],
        ];

        // Kurs
        $latestRates = collect(ExchangeRate::CURRENCIES)
            ->mapWithKeys(fn($currency) => [$currency => ExchangeRate::latestRate($currency)]);

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
        return back()->with('success', 'Kurs ' . $request->currency . ' berhasil diupdate.');
    }
}
