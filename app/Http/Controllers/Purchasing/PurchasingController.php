<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequirement;
use Carbon\Carbon;
use Illuminate\Http\Request;

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
        $poTerdekat = PurchaseOrder::with(['quotation.supplier', 'quotation.purchaseRequirement'])
            ->whereIn('status', ['active', 'overdue'])->whereNotNull('estimated_arrival')
            ->orderBy('estimated_arrival', 'asc')->take(5)->get();

        // Kurs
        $kursUsd = ExchangeRate::where('currency', 'USD')->orderBy('valid_from', 'desc')->first();
        $kursJpy = ExchangeRate::where('currency', 'JPY')->orderBy('valid_from', 'desc')->first();

        return view('purchasing.dashboard', compact(
            'prAktif', 'menungguPenawaran', 'poBerjalan', 'materialMingguIni',
            'prPerBulan', 'poStatusDist', 'prTerbaru', 'poTerdekat', 'kursUsd', 'kursJpy'
        ));
    }

    public function updateKurs(Request $request)
    {
        $request->validate([
            'currency' => 'required|in:USD,JPY',
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
