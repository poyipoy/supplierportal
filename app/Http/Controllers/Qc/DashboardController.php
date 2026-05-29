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
        $allInspections = QcInspection::whereNotNull('inspected_at')->get();
        $totalInspections = $allInspections->count();
        $totalOk = $allInspections->where('status', 'ok')->count();
        $totalNg = $allInspections->where('status', 'ng')->count();
        $waitingInspections = PurchaseOrder::where('status', 'waiting_qc')->count();
        $recentInspections = QcInspection::with(['purchaseOrder.supplier', 'inspector'])
            ->orderBy('inspected_at', 'desc')->take(10)->get();

        $trendData = $allInspections
            ->sortBy('inspected_at')
            ->groupBy(fn ($inspection) => $inspection->inspected_at->format('Y-m'))
            ->map(fn ($items, $period) => [
                'label' => Carbon::parse($period . '-01')->format('M Y'),
                'ok' => $items->where('status', 'ok')->count(),
                'ng' => $items->where('status', 'ng')->count(),
            ])
            ->values()
            ->take(-6)
            ->values()
            ->all();

        $firstWaitingPo = PurchaseOrder::where('status', 'waiting_qc')->orderBy('actual_arrival', 'asc')->first();

        return view('qc.dashboard', compact(
            'totalInspections', 'totalOk', 'totalNg', 'waitingInspections',
            'recentInspections', 'trendData', 'firstWaitingPo'
        ));
    }
}
