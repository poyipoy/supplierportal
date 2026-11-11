<?php

namespace App\Services\Procurement;

use App\Enums\PoAcknowledgementStatus;
use App\Models\LocalInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\SlaEscalationLog;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\BusinessTime;
use App\Support\NotificationCategory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

class SlaWatchdogService
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Evaluate all enabled SLA monitor rules and dispatch idempotent reminders/escalations.
     *
     * @return array{dispatched: int, by_rule: array<string, array{reminder: int, escalation: int}>}
     */
    public function evaluateAll(): array
    {
        if (! (bool) config('sla_monitors.enabled', true)) {
            return [
                'dispatched' => 0,
                'by_rule' => [],
            ];
        }

        $summary = [
            'dispatched' => 0,
            'by_rule' => [
                'import_po_unacknowledged' => ['reminder' => 0, 'escalation' => 0],
                'pr_bidding_stale' => ['reminder' => 0, 'escalation' => 0],
                'qc_waiting_inspection' => ['reminder' => 0, 'escalation' => 0],
                'local_invoice_verification_pending' => ['reminder' => 0, 'escalation' => 0],
            ],
        ];

        $this->evaluateImportPoUnacknowledged($summary);
        $this->evaluatePrBiddingStale($summary);
        $this->evaluateQcWaitingInspection($summary);
        $this->evaluateLocalInvoiceVerificationPending($summary);

        return $summary;
    }

    /**
     * @param  array{dispatched: int, by_rule: array<string, array{reminder: int, escalation: int}>}  $summary
     */
    private function evaluateImportPoUnacknowledged(array &$summary): void
    {
        $ruleKey = 'import_po_unacknowledged';
        $config = config("sla_monitors.rules.{$ruleKey}", []);
        if (! ($config['enabled'] ?? true)) {
            return;
        }

        $now = BusinessTime::now();
        $reminderCutoff = $now->subHours(max(1, (int) ($config['reminder_hours'] ?? 48)));
        $escalationCutoff = $now->subHours(max(1, (int) ($config['escalation_hours'] ?? 96)));

        $pos = PurchaseOrder::query()
            ->with(['supplier', 'creator'])
            ->where('status', 'active')
            ->where('acknowledgement_status', PoAcknowledgementStatus::PENDING->value)
            ->where('created_at', '<=', $reminderCutoff)
            ->get();

        $purchasingRecipients = User::query()
            ->where('role', 'purchasing')
            ->where('is_active', true)
            ->get();

        foreach ($pos as $po) {
            if ($po->supplier && $this->claimEscalationStage($ruleKey, PurchaseOrder::class, (int) $po->id, SlaEscalationLog::STAGE_REMINDER, $po->supplier->id, 'supplier', ['po_number' => $po->po_number])) {
                $this->notifications->send(
                    $po->supplier,
                    'sla.import_po_unacknowledged.reminder',
                    "sla.{$ruleKey}.reminder.{$po->id}",
                    'sla.notifications.import_po_reminder_title',
                    'sla.notifications.import_po_reminder_body',
                    route('supplier.purchase-orders.show', $po, false),
                    'clock',
                    [
                        'po_id' => $po->id,
                        'category' => NotificationCategory::DOCUMENT,
                        'domain' => 'import',
                    ],
                    ['number' => $po->po_number],
                );
                $summary['dispatched']++;
                $summary['by_rule'][$ruleKey]['reminder']++;
            }

            if ($po->created_at !== null && $po->created_at->lte($escalationCutoff)) {
                $recipients = $purchasingRecipients
                    ->when($po->creator instanceof User, fn (Collection $c) => $c->push($po->creator))
                    ->unique('id')
                    ->values();

                if ($recipients->isNotEmpty() && $this->claimEscalationStage($ruleKey, PurchaseOrder::class, (int) $po->id, SlaEscalationLog::STAGE_ESCALATION, $po->created_by, 'purchasing', ['po_number' => $po->po_number])) {
                    $this->notifications->send(
                        $recipients,
                        'sla.import_po_unacknowledged.escalation',
                        "sla.{$ruleKey}.escalation.{$po->id}",
                        'sla.notifications.import_po_escalation_title',
                        'sla.notifications.import_po_escalation_body',
                        route('purchasing.purchase-orders.show', $po, false),
                        'alert-triangle',
                        [
                            'po_id' => $po->id,
                            'category' => NotificationCategory::DOCUMENT,
                            'domain' => 'import',
                        ],
                        [
                            'number' => $po->po_number,
                            'supplier' => $po->supplier?->name ?? '—',
                        ],
                    );
                    $summary['dispatched']++;
                    $summary['by_rule'][$ruleKey]['escalation']++;
                }
            }
        }
    }

    /**
     * @param  array{dispatched: int, by_rule: array<string, array{reminder: int, escalation: int}>}  $summary
     */
    private function evaluatePrBiddingStale(array &$summary): void
    {
        $ruleKey = 'pr_bidding_stale';
        $config = config("sla_monitors.rules.{$ruleKey}", []);
        if (! ($config['enabled'] ?? true)) {
            return;
        }

        $now = BusinessTime::now();
        $reminderCutoff = $now->subHours(max(1, (int) ($config['reminder_hours'] ?? 72)));
        $escalationCutoff = $now->subHours(max(1, (int) ($config['escalation_hours'] ?? 120)));

        $prs = PurchaseRequisition::query()
            ->with(['invitedSuppliers', 'quotations', 'creator'])
            ->where('status', 'bidding')
            ->where('created_at', '<=', $reminderCutoff)
            ->get();

        $purchasingRecipients = User::query()
            ->where('role', 'purchasing')
            ->where('is_active', true)
            ->get();

        foreach ($prs as $pr) {
            $respondedSupplierIds = $pr->quotations
                ->whereIn('status', ['submitted', 'accepted', 'rejected', 'all_unavailable'])
                ->pluck('supplier_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $pendingSuppliers = $pr->invitedSuppliers
                ->filter(fn (User $s) => ! in_array((int) $s->id, $respondedSupplierIds, true));

            if ($pendingSuppliers->isNotEmpty() && $this->claimEscalationStage($ruleKey, PurchaseRequisition::class, (int) $pr->id, SlaEscalationLog::STAGE_REMINDER, null, 'supplier', ['pr_number' => $pr->pr_number])) {
                $this->notifications->send(
                    $pendingSuppliers,
                    'sla.pr_bidding_stale.reminder',
                    "sla.{$ruleKey}.reminder.{$pr->id}",
                    'sla.notifications.pr_bidding_reminder_title',
                    'sla.notifications.pr_bidding_reminder_body',
                    route('supplier.quotations.create', ['pr_id' => $pr->hashid], false),
                    'clock',
                    [
                        'pr_id' => $pr->id,
                        'category' => NotificationCategory::QUOTATION,
                        'domain' => 'import',
                    ],
                    ['number' => $pr->pr_number],
                );
                $summary['dispatched']++;
                $summary['by_rule'][$ruleKey]['reminder']++;
            }

            if ($pr->created_at !== null && $pr->created_at->lte($escalationCutoff)) {
                $recipients = $purchasingRecipients
                    ->when($pr->creator instanceof User, fn (Collection $c) => $c->push($pr->creator))
                    ->unique('id')
                    ->values();

                if ($recipients->isNotEmpty() && $this->claimEscalationStage($ruleKey, PurchaseRequisition::class, (int) $pr->id, SlaEscalationLog::STAGE_ESCALATION, $pr->created_by, 'purchasing', ['pr_number' => $pr->pr_number])) {
                    $this->notifications->send(
                        $recipients,
                        'sla.pr_bidding_stale.escalation',
                        "sla.{$ruleKey}.escalation.{$pr->id}",
                        'sla.notifications.pr_bidding_escalation_title',
                        'sla.notifications.pr_bidding_escalation_body',
                        route('purchasing.requisitions.show', $pr, false),
                        'alert-triangle',
                        [
                            'pr_id' => $pr->id,
                            'category' => NotificationCategory::QUOTATION,
                            'domain' => 'import',
                        ],
                        ['number' => $pr->pr_number],
                    );
                    $summary['dispatched']++;
                    $summary['by_rule'][$ruleKey]['escalation']++;
                }
            }
        }
    }

    /**
     * @param  array{dispatched: int, by_rule: array<string, array{reminder: int, escalation: int}>}  $summary
     */
    private function evaluateQcWaitingInspection(array &$summary): void
    {
        $ruleKey = 'qc_waiting_inspection';
        $config = config("sla_monitors.rules.{$ruleKey}", []);
        if (! ($config['enabled'] ?? true)) {
            return;
        }

        $now = BusinessTime::now();
        $reminderCutoff = $now->subHours(max(1, (int) ($config['reminder_hours'] ?? 48)));
        $escalationCutoff = $now->subHours(max(1, (int) ($config['escalation_hours'] ?? 96)));

        $pos = PurchaseOrder::query()
            ->where('status', 'waiting_qc')
            ->where('updated_at', '<=', $reminderCutoff)
            ->get();

        $qcUsers = User::query()
            ->where('role', 'qc')
            ->where('is_active', true)
            ->get();

        $escalationUsers = User::query()
            ->whereIn('role', ['qc', 'purchasing'])
            ->where('is_active', true)
            ->get();

        foreach ($pos as $po) {
            if ($qcUsers->isNotEmpty() && $this->claimEscalationStage($ruleKey, PurchaseOrder::class, (int) $po->id, SlaEscalationLog::STAGE_REMINDER, null, 'qc', ['po_number' => $po->po_number])) {
                $this->notifications->send(
                    $qcUsers,
                    'sla.qc_waiting_inspection.reminder',
                    "sla.{$ruleKey}.reminder.{$po->id}",
                    'sla.notifications.qc_waiting_reminder_title',
                    'sla.notifications.qc_waiting_reminder_body',
                    route('qc.inspections.create', $po, false),
                    'clock',
                    [
                        'po_id' => $po->id,
                        'category' => NotificationCategory::DOCUMENT,
                        'domain' => 'import',
                    ],
                    ['number' => $po->po_number],
                );
                $summary['dispatched']++;
                $summary['by_rule'][$ruleKey]['reminder']++;
            }

            if ($po->updated_at !== null && $po->updated_at->lte($escalationCutoff)) {
                if ($escalationUsers->isNotEmpty() && $this->claimEscalationStage($ruleKey, PurchaseOrder::class, (int) $po->id, SlaEscalationLog::STAGE_ESCALATION, null, 'purchasing', ['po_number' => $po->po_number])) {
                    $this->notifications->send(
                        $escalationUsers,
                        'sla.qc_waiting_inspection.escalation',
                        "sla.{$ruleKey}.escalation.{$po->id}",
                        'sla.notifications.qc_waiting_escalation_title',
                        'sla.notifications.qc_waiting_escalation_body',
                        route('purchasing.purchase-orders.show', $po, false),
                        'alert-triangle',
                        [
                            'po_id' => $po->id,
                            'category' => NotificationCategory::DOCUMENT,
                            'domain' => 'import',
                        ],
                        ['number' => $po->po_number],
                    );
                    $summary['dispatched']++;
                    $summary['by_rule'][$ruleKey]['escalation']++;
                }
            }
        }
    }

    /**
     * @param  array{dispatched: int, by_rule: array<string, array{reminder: int, escalation: int}>}  $summary
     */
    private function evaluateLocalInvoiceVerificationPending(array &$summary): void
    {
        $ruleKey = 'local_invoice_verification_pending';
        $config = config("sla_monitors.rules.{$ruleKey}", []);
        if (! ($config['enabled'] ?? true)) {
            return;
        }

        $now = BusinessTime::now();
        $reminderCutoff = $now->subHours(max(1, (int) ($config['reminder_hours'] ?? 72)));
        $escalationCutoff = $now->subHours(max(1, (int) ($config['escalation_hours'] ?? 120)));

        $invoices = LocalInvoice::query()
            ->where('status', LocalInvoice::STATUS_UNDER_VERIFICATION)
            ->where(function ($query) use ($reminderCutoff): void {
                $query->where('cashier_received_at', '<=', $reminderCutoff)
                    ->orWhere(function ($q) use ($reminderCutoff): void {
                        $q->whereNull('cashier_received_at')
                            ->where('updated_at', '<=', $reminderCutoff);
                    });
            })
            ->get();

        $financeUsers = User::query()
            ->where('role', 'finance')
            ->where('is_active', true)
            ->get();

        $escalationUsers = User::query()
            ->whereIn('role', ['finance', 'admin'])
            ->where('is_active', true)
            ->get();

        foreach ($invoices as $invoice) {
            if ($financeUsers->isNotEmpty() && $this->claimEscalationStage($ruleKey, LocalInvoice::class, (int) $invoice->id, SlaEscalationLog::STAGE_REMINDER, null, 'finance', ['invoice_number' => $invoice->invoice_number])) {
                $this->notifications->send(
                    $financeUsers,
                    'sla.local_invoice_verification_pending.reminder',
                    "sla.{$ruleKey}.reminder.{$invoice->id}",
                    'sla.notifications.local_invoice_reminder_title',
                    'sla.notifications.local_invoice_reminder_body',
                    route('finance.invoices.show', $invoice, false),
                    'clock',
                    [
                        'local_invoice_id' => $invoice->id,
                        'category' => NotificationCategory::INVOICE,
                        'domain' => 'local',
                    ],
                    ['number' => $invoice->invoice_number],
                );
                $summary['dispatched']++;
                $summary['by_rule'][$ruleKey]['reminder']++;
            }

            $referenceTime = $invoice->cashier_received_at ?? $invoice->updated_at;
            if ($referenceTime !== null && $referenceTime->lte($escalationCutoff)) {
                if ($escalationUsers->isNotEmpty() && $this->claimEscalationStage($ruleKey, LocalInvoice::class, (int) $invoice->id, SlaEscalationLog::STAGE_ESCALATION, null, 'finance', ['invoice_number' => $invoice->invoice_number])) {
                    $this->notifications->send(
                        $escalationUsers,
                        'sla.local_invoice_verification_pending.escalation',
                        "sla.{$ruleKey}.escalation.{$invoice->id}",
                        'sla.notifications.local_invoice_escalation_title',
                        'sla.notifications.local_invoice_escalation_body',
                        route('finance.invoices.show', $invoice, false),
                        'alert-triangle',
                        [
                            'local_invoice_id' => $invoice->id,
                            'category' => NotificationCategory::INVOICE,
                            'domain' => 'local',
                        ],
                        ['number' => $invoice->invoice_number],
                    );
                    $summary['dispatched']++;
                    $summary['by_rule'][$ruleKey]['escalation']++;
                }
            }
        }
    }

    /**
     * Atomically record an escalation stage in `sla_escalation_logs`.
     * Returns true only when this call newly created the record (idempotent across runs).
     *
     * @param  array<string, mixed>  $metadata
     */
    private function claimEscalationStage(
        string $ruleKey,
        string $subjectType,
        int $subjectId,
        string $stage,
        ?int $recipientUserId = null,
        ?string $recipientRole = null,
        array $metadata = [],
    ): bool {
        try {
            $log = SlaEscalationLog::query()->firstOrCreate(
                [
                    'rule_key' => $ruleKey,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'stage' => $stage,
                ],
                [
                    'recipient_user_id' => $recipientUserId,
                    'recipient_role' => $recipientRole,
                    'dispatched_at' => BusinessTime::now(),
                    'metadata' => $metadata,
                ],
            );

            return $log->wasRecentlyCreated;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
