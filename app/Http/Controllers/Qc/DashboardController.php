<?php

namespace App\Http\Controllers\Qc;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use App\Services\RegionalDisplayFormatter;
use App\Support\BusinessTime;

class DashboardController extends Controller
{
    public function dashboard(RegionalDisplayFormatter $regionalFormatter)
    {
        $allInspections = QcInspection::whereNotNull('inspected_at')->get();
        $totalInspections = $allInspections->count();
        $totalOk = $allInspections->where('status', 'ok')->count();
        $totalNg = $allInspections->where('status', 'ng')->count();
        $waitingInspections = PurchaseOrder::where('status', 'waiting_qc')->count();
        $recentInspections = QcInspection::with(['purchaseOrder.supplier', 'inspector'])
            ->orderBy('inspected_at', 'desc')->take(10)->get();

        // Six continuous business months ending this month: a month without inspections is 0, not a
        // missing point, and an instant belongs to its Asia/Jakarta month (Business Time Invariant).
        $byMonth = $allInspections->groupBy(fn ($inspection) => BusinessTime::toBusiness($inspection->inspected_at)->format('Y-m'));
        $currentMonth = BusinessTime::today()->startOfMonth();
        $trendData = collect(range(5, 0))
            ->map(function (int $offset) use ($byMonth, $currentMonth, $regionalFormatter) {
                $month = $currentMonth->subMonthsNoOverflow($offset);
                $items = $byMonth->get($month->format('Y-m'), collect());

                return [
                    'label' => $regionalFormatter->monthYear($month),
                    'ok' => $items->where('status', 'ok')->count(),
                    'ng' => $items->where('status', 'ng')->count(),
                ];
            })
            ->all();

        $firstWaitingPo = PurchaseOrder::where('status', 'waiting_qc')->orderBy('actual_arrival', 'asc')->first();

        return view('qc.dashboard', compact(
            'totalInspections', 'totalOk', 'totalNg', 'waitingInspections',
            'recentInspections', 'trendData', 'firstWaitingPo'
        ));
    }
}
