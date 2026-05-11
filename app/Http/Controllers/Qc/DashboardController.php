<?php

namespace App\Http\Controllers\Qc;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function dashboard()
    {
        $m = now()->month;
        $y = now()->year;
        $inspThisMonth = QcInspection::whereMonth('inspected_at', $m)->whereYear('inspected_at', $y)->get();
        $totalInspections = $inspThisMonth->count();
        $totalOk = $inspThisMonth->where('status', 'ok')->count();
        $totalNg = $inspThisMonth->where('status', 'ng')->count();
        $waitingInspections = PurchaseOrder::where('status', 'waiting_qc')->count();
        $recentInspections = QcInspection::with(['purchaseOrder.quotation.supplier', 'inspector'])
            ->orderBy('inspected_at', 'desc')->take(5)->get();

        // Tren OK vs NG (3 bulan terakhir)
        $trendData = [];
        for ($i = 2; $i >= 0; $i--) {
            $d = Carbon::now()->subMonths($i);
            $mi = QcInspection::whereYear('inspected_at', $d->year)->whereMonth('inspected_at', $d->month)->get();
            $trendData[] = ['label' => $d->format('M Y'), 'ok' => $mi->where('status', 'ok')->count(), 'ng' => $mi->where('status', 'ng')->count()];
        }

        $firstWaitingPo = PurchaseOrder::where('status', 'waiting_qc')->orderBy('actual_arrival', 'asc')->first();

        return view('qc.dashboard', compact(
            'totalInspections', 'totalOk', 'totalNg', 'waitingInspections',
            'recentInspections', 'trendData', 'firstWaitingPo'
        ));
    }
}
