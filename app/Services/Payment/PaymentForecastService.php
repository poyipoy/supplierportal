<?php

namespace App\Services\Payment;

use App\Models\LocalInvoice;
use App\Models\PaymentGroup;
use Carbon\Carbon;

class PaymentForecastService
{
    /**
     * Get weekly payment forecast (next 4 weeks).
     */
    public function getWeeklyForecast(): array
    {
        $weeks = [];
        $startDate = Carbon::now()->startOfWeek();

        for ($i = 0; $i < 4; $i++) {
            $weekStart = (clone $startDate)->addWeeks($i);
            $weekEnd = (clone $weekStart)->endOfWeek();

            // Supplier invoices with due_date in this week
            $supplierQuery = LocalInvoice::whereIn('status', [LocalInvoice::STATUS_READY_TO_PAY, LocalInvoice::STATUS_UNDER_VERIFICATION, 'APPROVED'])
                ->whereBetween('due_date', [$weekStart->toDateString(), $weekEnd->toDateString()]);
            $supplierAmount = (float) $supplierQuery->sum('invoice_amount');
            $supplierCount = $supplierQuery->count();

            // Unpaid DRP groups with transfer_date or created_at in this week
            $drpAmount = (float) PaymentGroup::where('status', PaymentGroup::STATUS_UNPAID)
                ->whereBetween('created_at', [$weekStart->toDateTimeString(), $weekEnd->toDateTimeString()])
                ->sum('net_payment_amount');

            $total = round($supplierAmount + $drpAmount, 2);

            $weeks[] = [
                'week' => 'Week '.($i + 1),
                'week_number' => $i + 1,
                'label' => 'Week '.($i + 1).' ('.$weekStart->format('d M').' - '.$weekEnd->format('d M').')',
                'start' => $weekStart->toDateString(),
                'start_date' => $weekStart->toDateString(),
                'end' => $weekEnd->toDateString(),
                'end_date' => $weekEnd->toDateString(),
                'count' => $supplierCount,
                'supplier_amount' => $supplierAmount,
                'drp_amount' => $drpAmount,
                'total' => $total,
                'total_amount' => $total,
            ];
        }

        return $weeks;
    }

    /**
     * Get monthly payment forecast (next 6 months).
     */
    public function getMonthlyForecast(): array
    {
        $months = [];
        $startMonth = Carbon::now()->startOfMonth();

        for ($i = 0; $i < 6; $i++) {
            $monthStart = (clone $startMonth)->addMonths($i);
            $monthEnd = (clone $monthStart)->endOfMonth();

            $supplierQuery = LocalInvoice::whereIn('status', [LocalInvoice::STATUS_READY_TO_PAY, LocalInvoice::STATUS_UNDER_VERIFICATION, 'APPROVED'])
                ->whereBetween('due_date', [$monthStart->toDateString(), $monthEnd->toDateString()]);
            $supplierAmount = (float) $supplierQuery->sum('invoice_amount');
            $supplierCount = $supplierQuery->count();

            $total = round($supplierAmount, 2);

            $months[] = [
                'month' => $monthStart->format('F Y'),
                'label' => $monthStart->format('M Y'),
                'start' => $monthStart->toDateString(),
                'start_date' => $monthStart->toDateString(),
                'end' => $monthEnd->toDateString(),
                'end_date' => $monthEnd->toDateString(),
                'count' => $supplierCount,
                'amount' => $total,
                'total' => $total,
                'total_amount' => $total,
            ];
        }

        return $months;
    }
}
