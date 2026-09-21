<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\LocalInvoice;
use App\Models\PaymentBatch;
use App\Services\LocalInvoice\InvoiceQuery;
use App\Services\Payment\PaymentForecastService;
use Illuminate\Http\Request;

class FinanceDashboardController extends Controller
{
    public function dashboard(InvoiceQuery $query, PaymentForecastService $forecastService)
    {
        $dashboardData = $query->dashboard();

        $kpis = [
            'waiting_physical' => LocalInvoice::where('status', LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT)->count(),
            'under_verification' => LocalInvoice::whereIn('status', [LocalInvoice::STATUS_UNDER_VERIFICATION, 'UNDER_REVIEW'])->count(),
            'need_revision' => LocalInvoice::where('status', LocalInvoice::STATUS_NEED_REVISION)->count(),
            'ready_to_pay' => LocalInvoice::whereIn('status', [LocalInvoice::STATUS_READY_TO_PAY, 'APPROVED', 'PAYMENT_SCHEDULED'])->count(),
            'paid' => LocalInvoice::whereIn('status', [LocalInvoice::STATUS_PAID, 'COMPLETED'])->count(),
            'expired' => LocalInvoice::where('status', LocalInvoice::STATUS_EXPIRED)->count(),
            'overdue' => $dashboardData['overdue'] ?? 0,
        ];

        $weeklyForecast = $forecastService->getWeeklyForecast();
        $monthlyForecast = $forecastService->getMonthlyForecast();

        $recentBatches = PaymentBatch::with('creator')->latest('id')->limit(5)->get();
        $recentInvoices = $dashboardData['invoices'] ?? LocalInvoice::latest('id')->limit(5)->get();

        return view('finance.dashboard', array_merge($dashboardData, [
            'kpis' => $kpis,
            'weeklyForecast' => $weeklyForecast,
            'monthlyForecast' => $monthlyForecast,
            'recentBatches' => $recentBatches,
            'recentInvoices' => $recentInvoices,
        ]));
    }

    public function forecast(PaymentForecastService $forecastService)
    {
        return response()->json([
            'weekly' => $forecastService->getWeeklyForecast(),
            'monthly' => $forecastService->getMonthlyForecast(),
        ]);
    }
}
