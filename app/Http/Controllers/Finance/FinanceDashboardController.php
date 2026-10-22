<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\LocalInvoice;
use App\Models\PaymentBatch;
use App\Services\LocalInvoice\InvoiceQuery;
use App\Services\Payment\PaymentForecastService;
use App\Support\BusinessTime;
use Illuminate\Http\Request;

class FinanceDashboardController extends Controller
{
    public function dashboard(Request $request, InvoiceQuery $query, PaymentForecastService $forecastService)
    {
        $dashboardData = $query->dashboard();

        // Same unscoped table and status lists as the former per-KPI counts,
        // taken from the status breakdown the dashboard already loads.
        $statusCount = fn (array $statuses): int => (int) collect($dashboardData['counts'])->only($statuses)->sum();
        $kpis = [
            'waiting_physical' => $statusCount([LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT]),
            'under_verification' => $statusCount([LocalInvoice::STATUS_UNDER_VERIFICATION, 'UNDER_REVIEW']),
            'need_revision' => $statusCount([LocalInvoice::STATUS_NEED_REVISION]),
            'ready_to_pay' => $statusCount([LocalInvoice::STATUS_READY_TO_PAY, 'APPROVED', 'PAYMENT_SCHEDULED']),
            'paid' => $statusCount([LocalInvoice::STATUS_PAID, 'COMPLETED']),
            'expired' => $statusCount([LocalInvoice::STATUS_EXPIRED]),
            'overdue' => $dashboardData['overdue'] ?? 0,
        ];

        $selectedMonth = $request->query('month', BusinessTime::now()->format('Y-m'));
        $availableMonths = $forecastService->getAvailableMonths();
        ['weekly' => $weeklyForecast, 'monthly' => $monthlyForecast] = $forecastService->getDashboardForecasts($selectedMonth);
        $readyToPaySummary = $forecastService->getCurrentReadyToPaySummary();

        $recentBatches = PaymentBatch::with('creator')->latest('id')->limit(5)->get();
        $recentInvoices = $dashboardData['invoices'] ?? LocalInvoice::latest('id')->limit(5)->get();

        return view('finance.dashboard', array_merge($dashboardData, [
            'kpis' => $kpis,
            'selectedMonth' => $selectedMonth,
            'availableMonths' => $availableMonths,
            'weeklyForecast' => $weeklyForecast,
            'monthlyForecast' => $monthlyForecast,
            'readyToPaySummary' => $readyToPaySummary,
            'recentBatches' => $recentBatches,
            'recentInvoices' => $recentInvoices,
        ]));
    }

    public function forecast(Request $request, PaymentForecastService $forecastService)
    {
        $selectedMonth = $request->query('month', BusinessTime::now()->format('Y-m'));

        ['weekly' => $weekly, 'monthly' => $monthly] = $forecastService->getDashboardForecasts($selectedMonth);

        return response()->json([
            'selected_month' => $selectedMonth,
            'available_months' => $forecastService->getAvailableMonths(),
            'weekly' => $weekly,
            'monthly' => $monthly,
            'summary' => $forecastService->getCurrentReadyToPaySummary(),
        ]);
    }
}
