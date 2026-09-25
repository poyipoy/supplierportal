<?php

namespace App\Services\Payment;

use App\Models\LocalInvoice;
use App\Support\Money;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class PaymentForecastService
{
    public const DEFAULT_WEEKLY_PERIODS = 12;

    public const DEFAULT_MONTHLY_PERIODS = 6;

    /**
     * Get available months for the forecast month selector.
     * Defaults to last 5 months, current month, and 1 month ahead (total 7 months).
     */
    public function getAvailableMonths(int $pastMonths = 5, int $futureMonths = 1, ?CarbonInterface $referenceDate = null): array
    {
        $ref = $referenceDate ? Carbon::instance($referenceDate) : Carbon::now();
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
        if (is_int($month)) {
            $ref = $referenceDate ? Carbon::instance($referenceDate) : Carbon::now();
        } elseif ($month instanceof CarbonInterface) {
            $ref = Carbon::instance($month);
        } elseif (is_string($month) && ! empty($month)) {
            $ref = Carbon::parse($month)->startOfMonth();
        } else {
            $ref = $referenceDate ? Carbon::instance($referenceDate) : Carbon::now();
        }

        return $this->aggregateWeeklyByMonth($ref);
    }

    /**
     * Aggregate weekly Ready-to-Pay events for a specific calendar month.
     */
    protected function aggregateWeeklyByMonth(Carbon $ref): array
    {
        $targetMonth = (clone $ref)->startOfMonth();
        $daysInMonth = $targetMonth->daysInMonth;
        $monthStart = (clone $targetMonth)->startOfDay();
        $monthEnd = (clone $targetMonth)->endOfMonth()->endOfDay();

        // 1. Fetch invoices within this month
        $invoices = LocalInvoice::with('currentVerification')
            ->where(function ($q) use ($monthStart, $monthEnd) {
                $q->whereBetween('ready_to_pay_at', [$monthStart, $monthEnd])
                    ->orWhere(function ($sub) use ($monthStart, $monthEnd) {
                        $sub->whereNull('ready_to_pay_at')
                            ->whereBetween('approved_at', [$monthStart, $monthEnd]);
                    });
            })
            ->whereNotIn('status', [
                LocalInvoice::STATUS_REJECTED,
                LocalInvoice::STATUS_CANCELLED,
            ])
            ->get();

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

            $label = sprintf('Minggu %d (%02d %s - %02d %s %s)', $weekNumber, $def['start_day'], $monthName, $def['end_day'], $monthName, $year);
            $shortLabel = sprintf('W%d (%02d-%02d %s)', $weekNumber, $def['start_day'], $def['end_day'], $monthName);
            $periodName = 'Minggu '.$weekNumber;

            $periodInvoices = $invoices->filter(function ($inv) use ($periodStart, $periodEnd) {
                $eventAt = $inv->ready_to_pay_at ?? $inv->approved_at;
                if (! $eventAt) {
                    return false;
                }
                $eventDate = $eventAt instanceof CarbonInterface ? $eventAt : Carbon::parse($eventAt);

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
    protected function aggregateMonthlyForecast(int $periodCount, ?CarbonInterface $referenceDate = null): array
    {
        $ref = $referenceDate ? Carbon::instance($referenceDate) : Carbon::now();
        $windowStart = (clone $ref)->startOfMonth()->subMonths($periodCount - 1)->startOfDay();
        $windowEnd = (clone $ref)->endOfMonth()->endOfDay();

        $invoices = LocalInvoice::with('currentVerification')
            ->where(function ($q) use ($windowStart, $windowEnd) {
                $q->whereBetween('ready_to_pay_at', [$windowStart, $windowEnd])
                    ->orWhere(function ($sub) use ($windowStart, $windowEnd) {
                        $sub->whereNull('ready_to_pay_at')
                            ->whereBetween('approved_at', [$windowStart, $windowEnd]);
                    });
            })
            ->whereNotIn('status', [
                LocalInvoice::STATUS_REJECTED,
                LocalInvoice::STATUS_CANCELLED,
            ])
            ->get();

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
                $eventDate = $eventAt instanceof CarbonInterface ? $eventAt : Carbon::parse($eventAt);

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
