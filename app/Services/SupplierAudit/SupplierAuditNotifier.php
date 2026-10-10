<?php

namespace App\Services\SupplierAudit;

use App\Models\Attachment;
use App\Models\SupplierAudit;
use App\Models\SupplierAuditStatusHistory;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\NotificationCategory;
use App\Support\NotificationDomain;

/**
 * Tujuh event Supplier Audit. Event ke supplier memakai domain LOCAL; event ke Purchasing
 * memakai GLOBAL karena Purchasing tidak menerima domain LOCAL (NotificationDomain::allowedDomainsForUser).
 */
class SupplierAuditNotifier
{
    private const ICON = 'clipboard-check';

    public function __construct(private readonly NotificationService $notifications) {}

    public function assigned(SupplierAudit $audit): void
    {
        $this->toSupplier($audit, 'assigned', 'supplier-audit:'.$audit->id.':assigned',
            route('local-supplier.supplier-audits.show', $audit, absolute: false), true);
    }

    public function revisionRequested(SupplierAudit $audit, SupplierAuditStatusHistory $history): void
    {
        $this->toSupplier($audit, 'revision_requested', 'supplier-audit:'.$audit->id.':revision:'.$history->id,
            route('local-supplier.supplier-audits.edit', $audit, absolute: false), true);
    }

    public function resultPublished(SupplierAudit $audit, Attachment $attachment): void
    {
        $this->toSupplier($audit, 'result_published', 'supplier-audit:'.$audit->id.':result:'.$attachment->id,
            route('local-supplier.supplier-audits.show', $audit, absolute: false));
    }

    public function cancelled(SupplierAudit $audit): void
    {
        $this->toSupplier($audit, 'cancelled', 'supplier-audit:'.$audit->id.':cancelled',
            route('local-supplier.supplier-audits.show', $audit, absolute: false));
    }

    public function deadlineChanged(SupplierAudit $audit, SupplierAuditStatusHistory $history): void
    {
        $copyKey = $audit->due_date === null ? 'deadline_removed' : 'deadline_changed';

        $this->toSupplier($audit, $copyKey, 'supplier-audit:'.$audit->id.':deadline:'.$history->id,
            route('local-supplier.supplier-audits.show', $audit, absolute: false), true, 'deadline_changed');
    }

    public function invoiceBlocked(SupplierAudit $audit): void
    {
        $this->toSupplier($audit, 'invoice_blocked', 'supplier-audit:'.$audit->id.':invoice-blocked:'.$audit->due_date?->toDateString(),
            route('local-supplier.supplier-audits.edit', $audit, absolute: false), true, category: NotificationCategory::INVOICE);
    }

    public function submitted(SupplierAudit $audit, SupplierAuditStatusHistory $history): void
    {
        $recipients = User::where('role', 'purchasing')->where('is_active', true)->get();

        $this->notifications->send(
            $recipients,
            'supplier_audit.submitted',
            'supplier-audit:'.$audit->id.':submitted:'.$history->id,
            'supplier_audit.notify.submitted.title',
            'supplier_audit.notify.submitted.body',
            route('purchasing.supplier-audits.show', $audit, absolute: false),
            self::ICON,
            [
                'category' => NotificationCategory::OTHER,
                'domain' => NotificationDomain::GLOBAL,
                'supplier_audit_id' => $audit->id,
            ],
            $this->replace($audit),
        );
    }

    /**
     * @param  string  $copyKey  key copy di supplier_audit.notify.*
     * @param  string|null  $sourceEvent  event preferensi; default sama dengan $copyKey
     */
    private function toSupplier(
        SupplierAudit $audit,
        string $copyKey,
        string $eventKey,
        string $url,
        bool $withDate = false,
        ?string $sourceEvent = null,
        string $category = NotificationCategory::OTHER,
    ): void {
        $audit->loadMissing('supplier.supplier');

        $this->notifications->send(
            $audit->supplier,
            'supplier_audit.'.($sourceEvent ?? $copyKey),
            $eventKey,
            'supplier_audit.notify.'.$copyKey.'.title',
            'supplier_audit.notify.'.$copyKey.'.body',
            $url,
            self::ICON,
            [
                'category' => $category,
                'domain' => NotificationDomain::LOCAL,
                'supplier_audit_id' => $audit->id,
            ],
            $this->replace($audit),
            $withDate && $audit->due_date !== null ? ['date' => '@date:'.$audit->due_date->toDateString()] : [],
        );
    }

    /** @return array<string, string> */
    private function replace(SupplierAudit $audit): array
    {
        return [
            'supplier' => $audit->supplierName(),
            'period' => $audit->period_label,
            'reason' => (string) ($audit->cancel_reason ?? ''),
            'date' => '-',
        ];
    }
}
