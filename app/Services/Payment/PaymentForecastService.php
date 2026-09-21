<?php

namespace App\Services\Payment;

use App\Models\GaClaim;
use App\Models\LocalInvoice;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class PaymentForecastService
{
    /**
     * Get weekly payment forecast (next 4 weeks).
     */
    public function getWeeklyForecast(): array
    {
        $weeks = [];
        $startDate = Carbon::now()->startOfWeek();

        // Preload all active DRP items and their groups/batches
        $activeDrpItems = $this->getActiveDrpItems();
        $batchedInvoiceIds = $activeDrpItems->where('payable_type', LocalInvoice::class)->pluck('payable_id')->unique()->all();
        $batchedClaimIds = $activeDrpItems->where('payable_type', GaClaim::class)->pluck('payable_id')->unique()->all();

        for ($i = 0; $i < 4; $i++) {
            $weekStart = (clone $startDate)->addWeeks($i)->startOfDay();
            $weekEnd = (clone $weekStart)->endOfWeek()->endOfDay();

            $forecast = $this->calculatePeriodForecast(
                $weekStart,
                $weekEnd,
                $activeDrpItems,
                $batchedInvoiceIds,
                $batchedClaimIds
            );

            $weeks[] = array_merge([
                'week' => 'Week '.($i + 1),
                'week_number' => $i + 1,
                'label' => 'Week '.($i + 1).' ('.$weekStart->format('d M').' - '.$weekEnd->format('d M').')',
                'start' => $weekStart->toDateString(),
                'start_date' => $weekStart->toDateString(),
                'end' => $weekEnd->toDateString(),
                'end_date' => $weekEnd->toDateString(),
            ], $forecast);
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

        // Preload all active DRP items and their groups/batches
        $activeDrpItems = $this->getActiveDrpItems();
        $batchedInvoiceIds = $activeDrpItems->where('payable_type', LocalInvoice::class)->pluck('payable_id')->unique()->all();
        $batchedClaimIds = $activeDrpItems->where('payable_type', GaClaim::class)->pluck('payable_id')->unique()->all();

        for ($i = 0; $i < 6; $i++) {
            $monthStart = (clone $startMonth)->addMonths($i)->startOfDay();
            $monthEnd = (clone $monthStart)->endOfMonth()->endOfDay();

            $forecast = $this->calculatePeriodForecast(
                $monthStart,
                $monthEnd,
                $activeDrpItems,
                $batchedInvoiceIds,
                $batchedClaimIds
            );

            $months[] = array_merge([
                'month' => $monthStart->format('F Y'),
                'label' => $monthStart->format('M Y'),
                'start' => $monthStart->toDateString(),
                'start_date' => $monthStart->toDateString(),
                'end' => $monthEnd->toDateString(),
                'end_date' => $monthEnd->toDateString(),
                'amount' => $forecast['total'],
            ], $forecast);
        }

        return $months;
    }

    /**
     * Load active items belonging to unpaid groups in active DRP batches.
     */
    protected function getActiveDrpItems()
    {
        return PaymentItem::with(['group.batch'])
            ->where('status', PaymentItem::STATUS_ACTIVE)
            ->whereHas('group', function ($g) {
                $g->where('status', PaymentGroup::STATUS_UNPAID)
                    ->whereHas('batch', fn ($b) => $b->whereIn('status', PaymentBatch::ACTIVE_STATUSES));
            })
            ->get();
    }

    /**
     * Calculate period forecast ensuring canonical precedence and preventing double-counting:
     * 1. If payable is in active DRP: forecast using DRP planned/payment date.
     * 2. Else if payable is READY_TO_PAY: forecast using due_date (LocalInvoice) or ready_to_pay_at / claim_date (GaClaim).
     * 3. Exclude UNDER_VERIFICATION and legacy states.
     */
    protected function calculatePeriodForecast(
        CarbonInterface $start,
        CarbonInterface $end,
        $activeDrpItems,
        array $batchedInvoiceIds,
        array $batchedClaimIds
    ): array {
        $supplierAmount = 0.0;
        $gaAmount = 0.0;
        $drpAmount = 0.0;
        $count = 0;

        // 1. Active DRP items falling into this period
        foreach ($activeDrpItems as $item) {
            $group = $item->group;
            if (! $group) {
                continue;
            }

            // Planned/payment date: transfer_date if set, otherwise group created_at
            $itemDate = $group->transfer_date
                ? Carbon::parse($group->transfer_date)
                : Carbon::parse($group->created_at);

            if ($itemDate->betweenIncluded($start, $end)) {
                $amount = (float) $item->amount;
                $drpAmount += $amount;
                $count++;

                if ($item->payable_type === LocalInvoice::class || $group->batch?->batch_type === PaymentBatch::TYPE_SUPPLIER) {
                    $supplierAmount += $amount;
                } else {
                    $gaAmount += $amount;
                }
            }
        }

        // 2. Unbatched Local Invoices in READY_TO_PAY status
        $readyInvoices = LocalInvoice::with('currentVerification')
            ->eligibleForPaymentBatch()
            ->whereNotIn('id', $batchedInvoiceIds)
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->get();

        foreach ($readyInvoices as $inv) {
            $verification = $inv->currentVerification;
            $amount = $verification
                ? $verification->calculateNetPayable((float) $inv->invoice_amount)
                : ((float) $inv->invoice_amount + (float) $inv->tax_amount);

            $supplierAmount += $amount;
            $count++;
        }

        // 3. Unbatched GA Claims in READY_TO_PAY status
        $readyClaims = GaClaim::where('status', GaClaim::STATUS_READY_TO_PAY)
            ->whereNotIn('id', $batchedClaimIds)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('ready_to_pay_at', [$start->toDateTimeString(), $end->toDateTimeString()])
                    ->orWhere(function ($sub) use ($start, $end) {
                        $sub->whereNull('ready_to_pay_at')
                            ->whereBetween('claim_date', [$start->toDateString(), $end->toDateString()]);
                    });
            })
            ->get();

        foreach ($readyClaims as $claim) {
            $gaAmount += (float) $claim->amount;
            $count++;
        }

        $supplierAmount = round($supplierAmount, 2);
        $gaAmount = round($gaAmount, 2);
        $drpAmount = round($drpAmount, 2);
        $total = round($supplierAmount + $gaAmount, 2);

        return [
            'count' => $count,
            'supplier_amount' => $supplierAmount,
            'supplier_net_payable' => $supplierAmount,
            'ga_amount' => $gaAmount,
            'ga_payable' => $gaAmount,
            'drp_amount' => $drpAmount,
            'total' => $total,
            'total_amount' => $total,
        ];
    }
}
