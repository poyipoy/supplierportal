<?php

namespace App\Services\Payment;

use App\Models\LocalInvoice;
use App\Support\BusinessTime;
use App\Support\Money;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class PaymentForecastService
{
    public const DEFAULT_WEEKLY_PERIODS = 12;

    public const DEFAULT_MONTHLY_PERIODS = 6;

    /**
     * Resolve reference date in business timezone.
     */
    protected function businessRef(?CarbonInterface $referenceDate = null): Carbon
    {
        return Carbon::instance(BusinessTime::toBusiness($referenceDate ?? now()));
    }

    /**
     * Get available months for the forecast month selector.
     * Defaults to last 5 months, current month, and 1 month ahead (total 7 months).
     */
    public function getAvailableMonths(int $pastMonths = 5, int $futureMonths = 1, ?CarbonInterface $referenceDate = null): array
    {
        $ref = $this->businessRef($referenceDate);
        $startMonth = (clone $ref)->startOfMonth()->subMonths($pastMonths);
        $totalMonths = $pastMonths + 1 + $futureMonths;
        $months = [];

        for ($i = 0; $i < $totalMonths; $i++) {
            $m = (clone $startMonth)->addMonths($i);
            $months[] = [
                'key' => $m->format('Y-m'),
                'year' => (int) $m->format('Y'),
                'month' => (int) $m->format('n'),
                'label' => $m->translatedFormat('F Y'),
                'short_label' => $m->translatedFormat('M Y'),
                'is_current' => $m->format('Y-m') === $ref->format('Y-m'),
            ];
        }

        return $months;
    }

    /**
     * Get weekly payment forecast partitioned per month into 7-day blocks:
     * Week 1: 1-7, Week 2: 8-14, Week 3: 15-21, Week 4: 22-28, Week 5: 29-end.
     * Cumulative amount resets at Week 1 of the given month.
     */
    public function getWeeklyForecast(string|CarbonInterface|int|null $month = null, ?CarbonInterface $referenceDate = null): array
    {
        return $this->aggregateWeeklyByMonth($this->weeklyRef($month, $referenceDate));
    }

    /**
     * Weekly forecast for $month plus the rolling monthly forecast, built from a
     * single read of the invoices that fall in either window. Results are the
     * same as getWeeklyForecast($month) and getMonthlyForecast().
     *
     * @return array{weekly: array, monthly: array}
     */
    public function getDashboardForecasts(string|CarbonInterface|int|null $month = null, int $monthsCount = self::DEFAULT_MONTHLY_PERIODS, ?CarbonInterface $referenceDate = null): array
    {
        $weeklyRef = $this->weeklyRef($month, $referenceDate);
        [$weeklyStart, $weeklyEnd] = $this->weeklyWindow($weeklyRef);
        [$monthlyStart, $monthlyEnd] = $this->monthlyWindow($monthsCount, $referenceDate);
        // Each period below filters by its own event date, so a wider fetch window
        // cannot add an invoice to a period it does not belong to.
        $invoices = $this->eventInvoices(
            $weeklyStart->lessThan($monthlyStart) ? $weeklyStart : $monthlyStart,
            $weeklyEnd->greaterThan($monthlyEnd) ? $weeklyEnd : $monthlyEnd,
            ['id', 'status', 'invoice_amount', 'tax_amount', 'ready_to_pay_at', 'approved_at'],
        );

        return [
            'weekly' => $this->aggregateWeeklyByMonth($weeklyRef, $invoices),
            'monthly' => $this->aggregateMonthlyForecast($monthsCount, $referenceDate, $invoices),
        ];
    }

    private function weeklyRef(string|CarbonInterface|int|null $month, ?CarbonInterface $referenceDate): Carbon
    {
        if (is_int($month)) {
            return $this->businessRef($referenceDate);
        }
        if ($month instanceof CarbonInterface) {
            return $this->businessRef($month);
        }
        if (is_string($month) && ! empty($month)) {
            return Carbon::parse($month, BusinessTime::tz())->startOfMonth();
        }

        return $this->businessRef($referenceDate);
    }

    /** @return array{0: Carbon, 1: Carbon} business-time bounds of the weekly forecast month */
    private function weeklyWindow(Carbon $ref): array
    {
        $targetMonth = (clone $ref)->startOfMonth();

        return [(clone $targetMonth)->startOfDay(), (clone $targetMonth)->endOfMonth()->endOfDay()];
    }

    /** @return array{0: Carbon, 1: Carbon} business-time bounds of the rolling monthly forecast */
    private function monthlyWindow(int $periodCount, ?CarbonInterface $referenceDate): array
    {
        $ref = $this->businessRef($referenceDate);

        return [(clone $ref)->startOfMonth()->subMonths($periodCount - 1)->startOfDay(), (clone $ref)->endOfMonth()->endOfDay()];
    }

    /**
     * Invoices whose Ready-to-Pay event (ready_to_pay_at, else approved_at) falls
     * inside the business-time window, excluding rejected and cancelled ones.
     */
    private function eventInvoices(Carbon $windowStart, Carbon $windowEnd, array $columns = ['*'])
    {
        $storageStart = BusinessTime::toStorage($windowStart);
        $storageEnd = BusinessTime::toStorage($windowEnd);

        return LocalInvoice::with('currentVerification')
            ->where(function ($q) use ($storageStart, $storageEnd) {
                $q->whereBetween('ready_to_pay_at', [$storageStart, $storageEnd])
                    ->orWhere(function ($sub) use ($storageStart, $storageEnd) {
                        $sub->whereNull('ready_to_pay_at')
                            ->whereBetween('approved_at', [$storageStart, $storageEnd]);
                    });
            })
            ->whereNotIn('status', [
                LocalInvoice::STATUS_REJECTED,
                LocalInvoice::STATUS_CANCELLED,
            ])
            ->get($columns);
    }

    /**
     * Aggregate weekly Ready-to-Pay events for a specific calendar month.
     */
    protected function aggregateWeeklyByMonth(Carbon $ref, $invoices = null): array
    {
        $targetMonth = (clone $ref)->startOfMonth();
        $daysInMonth = $targetMonth->daysInMonth;

        // 1. Fetch invoices within this month
        $invoices ??= $this->eventInvoices(...$this->weeklyWindow($ref));

        // 2. Define 7-day week intervals
        $weekDefinitions = [
            ['week_number' => 1, 'start_day' => 1, 'end_day' => 7],
            ['week_number' => 2, 'start_day' => 8, 'end_day' => 14],
            ['week_number' => 3, 'start_day' => 15, 'end_day' => 21],
            ['week_number' => 4, 'start_day' => 22, 'end_day' => 28],
        ];

        if ($daysInMonth > 28) {
            $weekDefinitions[] = [
                'week_number' => 5,
                'start_day' => 29,
                'end_day' => $daysInMonth,
            ];
        }

        $periods = [];
        $cumulativeAmount = Money::zero();

        foreach ($weekDefinitions as $idx => $def) {
            $weekNumber = $def['week_number'];
            $periodStart = (clone $targetMonth)->day($def['start_day'])->startOfDay();
            $periodEnd = (clone $targetMonth)->day($def['end_day'])->endOfDay();

            $monthName = $targetMonth->translatedFormat('M');
            $monthFull = $targetMonth->translatedFormat('F');
            $year = $targetMonth->format('Y');

            $label = __('finance.forecast_copy.week_range', [
                'week' => $weekNumber,
                'start' => sprintf('%02d', $def['start_day']),
                'end' => sprintf('%02d', $def['end_day']),
                'month' => $monthName,
                'year' => $year,
            ]);
            $shortLabel = sprintf('W%d (%02d-%02d %s)', $weekNumber, $def['start_day'], $def['end_day'], $monthName);
            $periodName = __('finance.forecast_copy.week', ['week' => $weekNumber]);

            $periodInvoices = $invoices->filter(function ($inv) use ($periodStart, $periodEnd) {
                $eventAt = $inv->ready_to_pay_at ?? $inv->approved_at;
                if (! $eventAt) {
                    return false;
                }
                $eventDate = BusinessTime::toBusiness($eventAt);

                return $eventDate->betweenIncluded($periodStart, $periodEnd);
            });

            $periodAmount = Money::zero();
            $invoiceCount = 0;

            foreach ($periodInvoices as $inv) {
                $payable = $this->getInvoicePayableAmount($inv);
                $periodAmount = Money::add($periodAmount, $payable);
                $invoiceCount++;
            }

            $cumulativeAmount = Money::add($cumulativeAmount, $periodAmount);

            $periods[] = [
                'period_type' => 'week',
                'period_index' => $idx,
                'week_number' => $weekNumber,
                'week' => $periodName,
                'start' => $periodStart->toDateString(),
                'start_date' => $periodStart->toDateString(),
                'end' => $periodEnd->toDateString(),
                'end_date' => $periodEnd->toDateString(),
                'label' => $label,
                'short_label' => $shortLabel,
                'month_key' => $targetMonth->format('Y-m'),
                'month_name' => $monthFull.' '.$year,
                'count' => $invoiceCount,
                'invoice_count' => $invoiceCount,
                'period_amount' => (float) $periodAmount,
                'period_amount_exact' => $periodAmount,
                'period_amount_formatted' => 'Rp '.number_format((float) $periodAmount, 0, ',', '.'),
                'cumulative_amount' => (float) $cumulativeAmount,
                'cumulative_amount_exact' => $cumulativeAmount,
                'cumulative_amount_formatted' => 'Rp '.number_format((float) $cumulativeAmount, 0, ',', '.'),
                // Backward-compatibility keys
                'total' => (float) $periodAmount,
                'total_amount' => (float) $periodAmount,
                'supplier_amount' => (float) $periodAmount,
                'ga_amount' => 0.0,
                'drp_amount' => 0.0,
            ];
        }

        return $periods;
    }

    /**
     * Get monthly payment forecast (rolling last N months including current month).
     */
    public function getMonthlyForecast(int $monthsCount = self::DEFAULT_MONTHLY_PERIODS, ?CarbonInterface $referenceDate = null): array
    {
        return $this->aggregateMonthlyForecast($monthsCount, $referenceDate);
    }

    /**
     * Aggregate monthly payment forecast based on ready_to_pay_at events.
     */
    protected function aggregateMonthlyForecast(int $periodCount, ?CarbonInterface $referenceDate = null, $invoices = null): array
    {
        [$windowStart] = $this->monthlyWindow($periodCount, $referenceDate);

        $invoices ??= $this->eventInvoices(...$this->monthlyWindow($periodCount, $referenceDate));

        $periods = [];
        $cumulativeAmount = Money::zero();

        for ($i = 0; $i < $periodCount; $i++) {
            $periodStart = (clone $windowStart)->addMonths($i)->startOfMonth()->startOfDay();
            $periodEnd = (clone $periodStart)->endOfMonth()->endOfDay();

            $label = $periodStart->translatedFormat('M Y');
            $shortLabel = $periodStart->translatedFormat('M Y');
            $periodName = $periodStart->translatedFormat('F Y');

            $periodInvoices = $invoices->filter(function ($inv) use ($periodStart, $periodEnd) {
                $eventAt = $inv->ready_to_pay_at ?? $inv->approved_at;
                if (! $eventAt) {
                    return false;
                }
                $eventDate = BusinessTime::toBusiness($eventAt);

                return $eventDate->betweenIncluded($periodStart, $periodEnd);
            });

            $periodAmount = Money::zero();
            $invoiceCount = 0;

            foreach ($periodInvoices as $inv) {
                $payable = $this->getInvoicePayableAmount($inv);
                $periodAmount = Money::add($periodAmount, $payable);
                $invoiceCount++;
            }

            $cumulativeAmount = Money::add($cumulativeAmount, $periodAmount);

            $periods[] = [
                'period_type' => 'month',
                'period_index' => $i,
                'month' => $periodName,
                'start' => $periodStart->toDateString(),
                'start_date' => $periodStart->toDateString(),
                'end' => $periodEnd->toDateString(),
                'end_date' => $periodEnd->toDateString(),
                'label' => $label,
                'short_label' => $shortLabel,
                'count' => $invoiceCount,
                'invoice_count' => $invoiceCount,
                'amount' => (float) $periodAmount,
                'period_amount' => (float) $periodAmount,
                'period_amount_exact' => $periodAmount,
                'period_amount_formatted' => 'Rp '.number_format((float) $periodAmount, 0, ',', '.'),
                'cumulative_amount' => (float) $cumulativeAmount,
                'cumulative_amount_exact' => $cumulativeAmount,
                'cumulative_amount_formatted' => 'Rp '.number_format((float) $cumulativeAmount, 0, ',', '.'),
                // Backward-compatibility keys
                'total' => (float) $periodAmount,
                'total_amount' => (float) $periodAmount,
                'supplier_amount' => (float) $periodAmount,
                'ga_amount' => 0.0,
                'drp_amount' => 0.0,
            ];
        }

        return $periods;
    }

    /**
     * Get current operational Ready-to-Pay summary (outstanding invoices awaiting payment).
     */
    public function getCurrentReadyToPaySummary(): array
    {
        $invoices = LocalInvoice::with('currentVerification')
            ->whereIn('status', [
                LocalInvoice::STATUS_READY_TO_PAY,
                'APPROVED',
                'PAYMENT_SCHEDULED',
            ])
            ->get();

        $totalPayable = Money::zero();
        $count = $invoices->count();

        foreach ($invoices as $invoice) {
            $payable = $this->getInvoicePayableAmount($invoice);
            $totalPayable = Money::add($totalPayable, $payable);
        }

        return [
            'count' => $count,
            'invoice_count' => $count,
            'total_amount' => (float) $totalPayable,
            'total_amount_exact' => $totalPayable,
            'total_amount_formatted' => 'Rp '.number_format((float) $totalPayable, 0, ',', '.'),
            'amount' => (float) $totalPayable,
        ];
    }

    /**
     * Authoritative net payable calculation for an invoice matching payment engine.
     */
    public function getInvoicePayableAmount(LocalInvoice $invoice): string
    {
        $verification = $invoice->currentVerification;

        if ($verification) {
            return $verification->netPayableExact($invoice->invoice_amount);
        }

        return Money::add($invoice->invoice_amount, $invoice->tax_amount);
    }
}
