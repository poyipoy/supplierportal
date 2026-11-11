<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalPurchaseOrder;
use App\Support\Money;
use Illuminate\Support\Collection;

class ThreeWayMatchCalculator
{
    public const STATUS_EXACT_MATCH = 'EXACT_MATCH';

    public const STATUS_WITHIN_TOLERANCE = 'WITHIN_TOLERANCE';

    public const STATUS_VARIANCE_DETECTED = 'VARIANCE_DETECTED';

    public const STATUS_PO_CEILING_ONLY = 'PO_CEILING_ONLY';

    /**
     * Resolve the effective DPP value of a single Goods Receipt row if price/amount data is available.
     */
    public function resolveReceiptAmount(LocalGoodsReceipt $receipt): ?string
    {
        return $receipt->effectiveReceiptAmount();
    }

    /**
     * Evaluate 3-Way Match between PO Ceiling, selected Goods Receipts, and Invoice DPP.
     *
     * @param  Collection<int, LocalGoodsReceipt>  $receipts
     * @return array{
     *     po_total_amount: string,
     *     expected_gr_dpp_amount: string|null,
     *     invoice_dpp_amount: string,
     *     gr_dpp_variance_amount: string|null,
     *     three_way_match_status: string,
     *     has_line_values: bool,
     *     tolerance_limit_idr: string|null
     * }
     */
    public function evaluate(LocalPurchaseOrder $po, Collection $receipts, string|int|float $invoiceDppAmount): array
    {
        $poTotal = Money::normalize($po->total_amount ?? Money::ZERO);
        $invoiceDpp = Money::normalize($invoiceDppAmount);

        if ($receipts->isEmpty()) {
            return [
                'po_total_amount' => $poTotal,
                'expected_gr_dpp_amount' => null,
                'invoice_dpp_amount' => $invoiceDpp,
                'gr_dpp_variance_amount' => null,
                'three_way_match_status' => self::STATUS_PO_CEILING_ONLY,
                'has_line_values' => false,
                'tolerance_limit_idr' => null,
            ];
        }

        $resolvedAmounts = [];
        foreach ($receipts as $receipt) {
            $amount = $this->resolveReceiptAmount($receipt);
            if ($amount === null) {
                return [
                    'po_total_amount' => $poTotal,
                    'expected_gr_dpp_amount' => null,
                    'invoice_dpp_amount' => $invoiceDpp,
                    'gr_dpp_variance_amount' => null,
                    'three_way_match_status' => self::STATUS_PO_CEILING_ONLY,
                    'has_line_values' => false,
                    'tolerance_limit_idr' => null,
                ];
            }
            $resolvedAmounts[] = $amount;
        }

        $expectedGrDpp = Money::sum($resolvedAmounts);
        $variance = Money::subtract($invoiceDpp, $expectedGrDpp);
        $absVariance = str_starts_with($variance, '-') ? substr($variance, 1) : $variance;

        $tolIdr = Money::normalize((string) config('finance.three_way_match.tolerance_idr', '100.00'));
        $tolPct = (string) config('finance.three_way_match.tolerance_percent', '0.50');
        $pctAllowance = Money::compare($expectedGrDpp, Money::ZERO) > 0
            ? Money::multiply(Money::multiply($expectedGrDpp, $tolPct, 6), '0.01')
            : Money::ZERO;
        $maxAllowedVariance = Money::compare($tolIdr, $pctAllowance) >= 0 ? $tolIdr : $pctAllowance;

        if (Money::compare($absVariance, Money::ZERO) === 0) {
            $status = self::STATUS_EXACT_MATCH;
        } elseif (Money::compare($absVariance, $maxAllowedVariance) <= 0) {
            $status = self::STATUS_WITHIN_TOLERANCE;
        } else {
            $status = self::STATUS_VARIANCE_DETECTED;
        }

        return [
            'po_total_amount' => $poTotal,
            'expected_gr_dpp_amount' => $expectedGrDpp,
            'invoice_dpp_amount' => $invoiceDpp,
            'gr_dpp_variance_amount' => $variance,
            'three_way_match_status' => $status,
            'has_line_values' => true,
            'tolerance_limit_idr' => $maxAllowedVariance,
        ];
    }

    /**
     * Evaluate or read snapshot 3-Way Match for an existing LocalInvoice.
     *
     * @return array{
     *     po_total_amount: string,
     *     expected_gr_dpp_amount: string|null,
     *     invoice_dpp_amount: string,
     *     gr_dpp_variance_amount: string|null,
     *     three_way_match_status: string,
     *     has_line_values: bool
     * }
     */
    public function summaryForInvoice(LocalInvoice $invoice): array
    {
        $poTotal = Money::normalize($invoice->localPurchaseOrder?->total_amount ?? $invoice->po_value_snapshot ?? Money::ZERO);
        $invoiceDpp = Money::normalize($invoice->invoice_amount ?? Money::ZERO);
        $status = (string) ($invoice->three_way_match_status ?: self::STATUS_PO_CEILING_ONLY);
        $expected = $invoice->expected_gr_dpp_amount !== null ? Money::normalize($invoice->expected_gr_dpp_amount) : null;
        $variance = $invoice->gr_dpp_variance_amount !== null ? Money::normalize($invoice->gr_dpp_variance_amount) : null;

        return [
            'po_total_amount' => $poTotal,
            'expected_gr_dpp_amount' => $expected,
            'invoice_dpp_amount' => $invoiceDpp,
            'gr_dpp_variance_amount' => $variance,
            'three_way_match_status' => $status,
            'has_line_values' => $expected !== null && $status !== self::STATUS_PO_CEILING_ONLY,
        ];
    }
}
