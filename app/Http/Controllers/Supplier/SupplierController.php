<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;

class SupplierController extends Controller
{
    public function dashboard()
    {
        $sid = auth()->id();

        // ─── Cached widget counts per supplier (5 menit) ───
        $widgetData = \Illuminate\Support\Facades\Cache::remember(
            'supplier_dashboard_widgets_' . $sid,
            now()->addMinutes(5),
            function () use ($sid) {
                $periodeAktif = Period::where('status', 'open')->count();
                $belumDirespons = PurchaseRequisition::whereIn('status', ['submitted', 'bidding'])
                    ->visibleToSupplier($sid)
                    ->whereHas('period', fn($q) => $q->where('status', 'open'))
                    ->whereDoesntHave('quotations', fn($q) => $q->where('supplier_id', $sid))->count();
                $penawaranTerkirim = Quotation::where('supplier_id', $sid)->where('status', 'submitted')
                    ->whereMonth('submitted_at', now()->month)->whereYear('submitted_at', now()->year)->count();
                $poDiterima = PurchaseOrder::where('supplier_id', $sid)->count();

                return compact('periodeAktif', 'belumDirespons', 'penawaranTerkirim', 'poDiterima');
            }
        );

        extract($widgetData);

        // Quick tables (tidak di-cache agar selalu fresh)
        $prBelumRespons = PurchaseRequisition::with('period', 'items')
            ->whereIn('status', ['submitted', 'bidding'])
            ->visibleToSupplier($sid)
            ->whereHas('period', fn($q) => $q->where('status', 'open'))
            ->whereDoesntHave('quotations', fn($q) => $q->where('supplier_id', $sid))
            ->orderBy('created_at', 'desc')->take(5)->get();

        $poTerbaru = PurchaseOrder::with([
                'quotations.purchaseRequisition.period',
                'materialClaims' => fn($q) => $q->where('supplier_id', $sid)->latest(),
            ])
            ->where('supplier_id', $sid)
            ->orderBy('created_at', 'desc')->take(5)->get();

        $announcements = Announcement::whereNotNull('published_at')
            ->orderBy('published_at', 'desc')->take(3)->get();

        return view('supplier.dashboard', compact(
            'periodeAktif', 'belumDirespons', 'penawaranTerkirim', 'poDiterima',
            'prBelumRespons', 'poTerbaru', 'announcements'
        ));
    }
}
