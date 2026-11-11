<?php

namespace App\Services\VendorMaster;

use App\Data\VendorMaster\SupplierScorecardData;
use App\Enums\PoAcknowledgementStatus;
use App\Models\LocalInvoice;
use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\SupplierAudit;
use App\Models\SupplierAuditAnswer;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class SupplierScorecardService
{
    /**
     * Build a supplier performance scorecard for the requested domain ('import' or 'local').
     * Always queries operational tables by `supplier_id = $supplierUser->id` (`users.id`).
     */
    public function forSupplier(User $supplierUser, ?string $preferredScope = null): SupplierScorecardData
    {
        $scope = $this->resolveScope($supplierUser, $preferredScope);

        return $scope === 'import'
            ? $this->buildImportScorecard($supplierUser)
            : $this->buildLocalScorecard($supplierUser);
    }

    public function buildImportScorecard(User $supplierUser): SupplierScorecardData
    {
        $supplierId = (int) $supplierUser->id;
        $today = BusinessTime::today()->toDateString();

        // 1. On-Time Delivery (OTD %)
        $pos = PurchaseOrder::query()
            ->where('supplier_id', $supplierId)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->get(['id', 'status', 'estimated_arrival', 'supplier_promised_date', 'actual_arrival', 'acknowledgement_status']);

        $otdNumerator = 0;
        $otdDenominator = 0;

        foreach ($pos as $po) {
            $targetDate = ($po->acknowledgementStatusEnum() === PoAcknowledgementStatus::ACCEPTED_CHANGE && $po->supplier_promised_date !== null)
                ? $po->supplier_promised_date->format('Y-m-d')
                : ($po->estimated_arrival?->format('Y-m-d') ?? $po->supplier_promised_date?->format('Y-m-d'));

            if ($po->actual_arrival !== null) {
                $otdDenominator++;
                $actualDate = $po->actual_arrival->format('Y-m-d');
                if ($targetDate === null || $actualDate <= $targetDate) {
                    $otdNumerator++;
                }
            } elseif ($po->status === 'overdue' || ($targetDate !== null && $targetDate < $today && in_array($po->status, ['active', 'waiting_qc', 'claim_needed'], true))) {
                $otdDenominator++;
            }
        }

        // 2. Quality Acceptance %
        $inspections = QcInspection::query()
            ->whereHas('purchaseOrder', fn ($q) => $q->where('supplier_id', $supplierId))
            ->get(['id', 'status']);

        $qcDenominator = $inspections->count();
        $qcNumerator = $inspections->where('status', 'ok')->count();

        // 3. Responsiveness % (PR invitation quotation responses + PO acknowledgements)
        $invitedPrCount = (int) DB::table('purchase_requisition_suppliers')
            ->where('supplier_id', $supplierId)
            ->count();

        $respondedQuotationCount = (int) Quotation::query()
            ->where('supplier_id', $supplierId)
            ->whereIn('status', ['submitted', 'accepted', 'rejected', 'all_unavailable'])
            ->distinct('pr_id')
            ->count('pr_id');

        $ackEligibleCount = $pos->count();
        $acknowledgedCount = $pos->filter(fn (PurchaseOrder $po) => $po->isAcknowledged())->count();

        $respDenominator = $invitedPrCount + $ackEligibleCount;
        $respNumerator = min($invitedPrCount, $respondedQuotationCount) + $acknowledgedCount;

        $metrics = [
            'otd' => $this->formatMetric('otd', 'scorecard.metrics.otd', $otdNumerator, $otdDenominator),
            'quality' => $this->formatMetric('quality', 'scorecard.metrics.quality', $qcNumerator, $qcDenominator),
            'responsiveness' => $this->formatMetric('responsiveness', 'scorecard.metrics.responsiveness', $respNumerator, $respDenominator),
        ];

        return $this->assembleScorecard($supplierId, 'import', $metrics);
    }

    public function buildLocalScorecard(User $supplierUser): SupplierScorecardData
    {
        $supplierId = (int) $supplierUser->id;

        $invoices = LocalInvoice::query()
            ->where('supplier_id', $supplierId)
            ->whereNotIn('status', [LocalInvoice::STATUS_CANCELLED])
            ->get([
                'id',
                'status',
                'revision_number',
                'scheduled_physical_delivery_date',
                'cashier_received_at',
            ]);

        // 1. Wednesday Physical Delivery Punctuality %
        $wedDenominator = 0;
        $wedNumerator = 0;

        foreach ($invoices as $invoice) {
            if ($invoice->cashier_received_at !== null && $invoice->scheduled_physical_delivery_date !== null) {
                $wedDenominator++;
                $receivedDate = BusinessTime::toBusiness($invoice->cashier_received_at)->toDateString();
                $scheduledDate = $invoice->scheduled_physical_delivery_date->format('Y-m-d');
                if ($receivedDate <= $scheduledDate) {
                    $wedNumerator++;
                }
            }
        }

        // 2. Invoice First-Time-Right (FTR) %
        $ftrDenominator = $invoices->count();
        $ftrNumerator = $invoices->filter(function (LocalInvoice $invoice): bool {
            return (int) $invoice->revision_number <= 1
                && $invoice->status !== LocalInvoice::STATUS_REJECTED;
        })->count();

        // 3. Supplier Audit Score %
        $latestAudit = SupplierAudit::query()
            ->where('supplier_id', $supplierId)
            ->whereIn('status', [
                SupplierAudit::STATUS_SUBMITTED,
                SupplierAudit::STATUS_RESULT_PUBLISHED,
            ])
            ->latest('id')
            ->first();

        $auditNumerator = 0;
        $auditDenominator = 0;

        if ($latestAudit !== null) {
            $answers = $latestAudit->answers()->get(['answer', 'score']);
            $totalCriteria = $answers->count();
            if ($totalCriteria > 0) {
                $auditDenominator = $totalCriteria * SupplierAuditAnswer::SCORE_MAX;
                $auditNumerator = (int) $answers
                    ->where('answer', SupplierAuditAnswer::ANSWER_YES)
                    ->sum(fn (SupplierAuditAnswer $a) => max(0, min(SupplierAuditAnswer::SCORE_MAX, (int) ($a->score ?? 0))));
            }
        }

        $metrics = [
            'wednesday_punctuality' => $this->formatMetric('wednesday_punctuality', 'scorecard.metrics.wednesday_punctuality', $wedNumerator, $wedDenominator),
            'invoice_ftr' => $this->formatMetric('invoice_ftr', 'scorecard.metrics.invoice_ftr', $ftrNumerator, $ftrDenominator),
            'audit_score' => $this->formatMetric('audit_score', 'scorecard.metrics.audit_score', $auditNumerator, $auditDenominator),
        ];

        return $this->assembleScorecard($supplierId, 'local', $metrics);
    }

    private function resolveScope(User $supplierUser, ?string $preferredScope): string
    {
        if (in_array($preferredScope, ['import', 'local'], true)) {
            return $preferredScope;
        }

        $scopes = $supplierUser->relationLoaded('supplierScopes')
            ? $supplierUser->supplierScopes->pluck('scope')->all()
            : $supplierUser->supplierScopes()->pluck('scope')->all();

        if (in_array('import', $scopes, true) && ! in_array('local', $scopes, true)) {
            return 'import';
        }

        if (in_array('local', $scopes, true)) {
            return 'local';
        }

        return 'import';
    }

    /**
     * @return array{key: string, label_key: string, percentage: string, numerator: int, denominator: int, has_data: bool}
     */
    private function formatMetric(string $key, string $labelKey, int $numerator, int $denominator): array
    {
        if ($denominator <= 0) {
            return [
                'key' => $key,
                'label_key' => $labelKey,
                'percentage' => '100.00',
                'numerator' => 0,
                'denominator' => 0,
                'has_data' => false,
            ];
        }

        $safeNumerator = max(0, min($denominator, $numerator));
        $scaled = bcmul((string) $safeNumerator, '100', 6);
        $percentage = Money::normalize(bcdiv($scaled, (string) $denominator, 6), 2);

        return [
            'key' => $key,
            'label_key' => $labelKey,
            'percentage' => $percentage,
            'numerator' => $safeNumerator,
            'denominator' => $denominator,
            'has_data' => true,
        ];
    }

    /**
     * @param  array<string, array{key: string, label_key: string, percentage: string, numerator: int, denominator: int, has_data: bool}>  $metrics
     */
    private function assembleScorecard(int $supplierId, string $scope, array $metrics): SupplierScorecardData
    {
        $activeMetrics = array_values(array_filter($metrics, fn (array $m): bool => $m['has_data']));
        $hasData = count($activeMetrics) > 0;

        if (! $hasData) {
            $compositeScore = '100.00';
        } else {
            $sum = '0.00';
            foreach ($activeMetrics as $metric) {
                $sum = Money::add($sum, $metric['percentage'], 4);
            }
            $compositeScore = Money::normalize(bcdiv($sum, (string) count($activeMetrics), 6), 2);
        }

        $grade = $this->resolveGrade($compositeScore);

        return new SupplierScorecardData(
            supplierUserId: $supplierId,
            scope: $scope,
            compositeScore: $compositeScore,
            grade: $grade,
            hasData: $hasData,
            metrics: $metrics,
        );
    }

    public function resolveGrade(string $compositeScore): string
    {
        if (Money::compare($compositeScore, '85.00', 2) >= 0) {
            return 'A';
        }

        if (Money::compare($compositeScore, '70.00', 2) >= 0) {
            return 'B';
        }

        if (Money::compare($compositeScore, '55.00', 2) >= 0) {
            return 'C';
        }

        return 'D';
    }
}
