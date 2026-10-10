<?php

namespace App\Console\Commands;

use App\Models\SupplierAudit;
use App\Services\SupplierAudit\SupplierAuditNotifier;
use Illuminate\Console\Command;

/**
 * D15: beri tahu supplier saat pengajuan invoice baru diblokir karena deadline Supplier Audit lewat.
 * Hanya mengirim notifikasi; blok sendiri dihitung saat request (SupplierAuditInvoiceGate).
 * Idempoten: event key memuat due_date sehingga satu notifikasi per audit per deadline.
 */
class NotifySupplierAuditInvoiceBlocked extends Command
{
    protected $signature = 'supplier-audits:notify-invoice-blocked';

    protected $description = 'Notify local suppliers whose new invoice submission is blocked by an overdue Supplier Audit';

    public function handle(SupplierAuditNotifier $notifier): int
    {
        $count = 0;

        SupplierAudit::query()
            ->late()
            ->with('supplier.supplier')
            ->chunkById(100, function ($audits) use ($notifier, &$count) {
                foreach ($audits as $audit) {
                    $notifier->invoiceBlocked($audit);
                    $count++;
                }
            });

        $this->info("Processed {$count} overdue supplier audit(s).");

        return self::SUCCESS;
    }
}
