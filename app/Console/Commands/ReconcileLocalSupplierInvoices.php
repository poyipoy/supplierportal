<?php

namespace App\Console\Commands;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalInvoiceGoodsReceipt;
use App\Models\LocalInvoicePayment;
use App\Models\LocalInvoicePaymentTransfer;
use App\Models\LocalPurchaseOrder;
use App\Models\SupplierOverpaymentRefund;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ReconcileLocalSupplierInvoices extends Command
{
    protected $signature = 'local-invoices:reconcile {--json : Emit machine-readable JSON}';

    protected $description = 'Report Local Supplier PO, GR, invoice, settlement, and refund integrity anomalies';

    public function handle(): int
    {
        $issues = [];

        // This command is also used during cutover validation. Report a pending
        // migration as an actionable finding instead of leaking an "unknown
        // column" SQL exception against the pre-migration schema.
        $requiredColumns = [
            'local_invoices' => ['local_purchase_order_id'],
            'local_purchase_orders' => ['source', 'created_by', 'updated_by'],
            'local_goods_receipts' => ['status', 'current_invoice_id', 'source', 'created_by', 'updated_by'],
        ];
        foreach ($requiredColumns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $issues[] = $this->issue('schema', 0, "Required table {$table} is missing; run the Local Supplier settlement migration.");

                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $issues[] = $this->issue('schema', 0, "Required column {$table}.{$column} is missing; run the Local Supplier settlement migration.");
                }
            }
        }

        if (Schema::hasTable('local_purchase_orders')) {
            LocalPurchaseOrder::with('supplier')->orderBy('id')->chunkById(500, function ($purchaseOrders) use (&$issues): void {
                foreach ($purchaseOrders as $purchaseOrder) {
                    if (! $purchaseOrder->supplier || ! $purchaseOrder->supplier->isLocalEligible()) {
                        $issues[] = $this->issue('po_supplier', $purchaseOrder->id, 'PO supplier is missing or is not an active Local Supplier.');
                    }
                    if (bccomp((string) $purchaseOrder->total_amount, '0', 2) <= 0) {
                        $issues[] = $this->issue('po_amount', $purchaseOrder->id, 'PO amount is missing or not positive.');
                    }
                }
            });
        }

        if (Schema::hasTable('local_goods_receipts')) {
            LocalGoodsReceipt::with('purchaseOrder')->orderBy('id')->chunkById(500, function ($receipts) use (&$issues): void {
                foreach ($receipts as $receipt) {
                    if (! $receipt->purchaseOrder) {
                        $issues[] = $this->issue('gr_orphan', $receipt->id, 'GR does not reference an existing PO.');
                    }
                    if ($receipt->qty === null || bccomp((string) $receipt->qty, '0', 4) <= 0) {
                        $issues[] = $this->issue('gr_qty', $receipt->id, 'GR quantity is missing or not positive.');
                    }
                }
            });
        }

        if (Schema::hasColumn('local_invoices', 'local_purchase_order_id')
            && Schema::hasTable('local_invoice_goods_receipts')) {
            LocalInvoice::with(['localPurchaseOrder', 'goodsReceiptHistories.goodsReceipt'])->whereNotNull('local_purchase_order_id')->orderBy('id')->chunkById(250, function ($invoices) use (&$issues): void {
                foreach ($invoices as $invoice) {
                    $po = $invoice->localPurchaseOrder;
                    if (! $po) {
                        $issues[] = $this->issue('invoice_po', $invoice->id, 'Authoritative invoice references a missing PO.');

                        continue;
                    }
                    if ((int) $po->supplier_id !== (int) $invoice->supplier_id) {
                        $issues[] = $this->issue('invoice_supplier', $invoice->id, 'Invoice supplier does not match its PO supplier.');
                    }

                    $active = $invoice->goodsReceiptHistories->whereIn('state', [LocalInvoiceGoodsReceipt::STATE_RESERVED, LocalInvoiceGoodsReceipt::STATE_CONSUMED]);
                    if ($active->isEmpty()) {
                        $issues[] = $this->issue('invoice_gr', $invoice->id, 'Authoritative invoice has no active GR history.');

                        continue;
                    }

                    if (bccomp((string) $invoice->invoice_amount, (string) $po->total_amount, 2) > 0) {
                        $issues[] = $this->issue('invoice_po_ceiling', $invoice->id, "Invoice DPP {$invoice->invoice_amount} exceeds PO total amount {$po->total_amount}.");
                    }

                    foreach ($active as $history) {
                        $receipt = $history->goodsReceipt;
                        $expectedStatus = $history->state === LocalInvoiceGoodsReceipt::STATE_CONSUMED
                            ? LocalGoodsReceipt::STATUS_INVOICED
                            : LocalGoodsReceipt::STATUS_RESERVED;
                        if (! $receipt || (int) $receipt->current_invoice_id !== (int) $invoice->id || $receipt->status !== $expectedStatus) {
                            $issues[] = $this->issue('gr_owner_state', $history->id, 'GR current owner/status disagrees with invoice history state.');
                        }
                    }
                }
            });
        }

        if (Schema::hasTable('local_invoice_payments')) {
            $this->duplicateIssues(LocalInvoicePayment::query(), 'local_invoice_id', 'duplicate_payment', $issues);
        }
        if (Schema::hasTable('local_invoice_payment_transfers')) {
            $this->duplicateIssues(LocalInvoicePaymentTransfer::query()->where('transfer_type', LocalInvoicePaymentTransfer::TYPE_PRIMARY), 'local_invoice_payment_id', 'duplicate_primary_transfer', $issues);
        }
        if (Schema::hasTable('supplier_overpayment_refunds')) {
            $this->duplicateIssues(SupplierOverpaymentRefund::query(), 'local_invoice_payment_id', 'duplicate_overpayment', $issues);
        }

        $payload = ['issue_count' => count($issues), 'issues' => $issues];
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ($issues === []) {
            $this->info('No Local Supplier reconciliation issues found.');
        } else {
            $this->table(['Type', 'ID', 'Message'], array_map(fn (array $issue) => [$issue['type'], $issue['id'], $issue['message']], $issues));
            $this->warn(count($issues).' reconciliation issue(s) found.');
        }

        return $issues === [] ? self::SUCCESS : self::FAILURE;
    }

    private function duplicateIssues($query, string $column, string $type, array &$issues): void
    {
        $query->select($column)->groupBy($column)->havingRaw('COUNT(*) > 1')->pluck($column)->each(function ($id) use (&$issues, $type, $column): void {
            $issues[] = $this->issue($type, (int) $id, "More than one row exists for {$column}.");
        });
    }

    private function issue(string $type, int $id, string $message): array
    {
        return compact('type', 'id', 'message');
    }
}
