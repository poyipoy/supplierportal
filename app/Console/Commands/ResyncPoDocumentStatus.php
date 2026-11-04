<?php

namespace App\Console\Commands;

use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Models\ShipmentDocument;
use App\Support\BusinessTime;
use App\Support\Procurement\CustomsDocumentationProgressCalculator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bring stored po_documents statuses in line with the derived rule (least
 * advanced active shipment document). Dry-run unless --execute is given;
 * shipment_documents are never changed.
 */
class ResyncPoDocumentStatus extends Command
{
    protected $signature = 'procurement:resync-document-status
        {--execute : Write the derived statuses (default is a dry run)}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Recompute PO document statuses from active shipment documents (dry run by default)';

    public function handle(CustomsDocumentationProgressCalculator $calculator): int
    {
        $execute = (bool) $this->option('execute');
        $byDocType = [];
        $driftedPoCount = 0;
        $written = 0;

        self::shipmentTrackedPurchaseOrders()
            ->with(['documents', 'shipmentItems.shipment.documents'])
            ->chunkById(200, function ($purchaseOrders) use ($calculator, $execute, &$byDocType, &$driftedPoCount, &$written): void {
                $driftedIds = [];

                foreach ($purchaseOrders as $po) {
                    $drift = $calculator->documentStatusDrift($po);
                    if ($drift === []) {
                        continue;
                    }

                    $driftedIds[] = (int) $po->id;
                    foreach ($drift as $docType => $change) {
                        $direction = (PoDocument::STATUS_RANKS[$change['to']] ?? 0) >= (PoDocument::STATUS_RANKS[$change['from']] ?? 0)
                            ? 'up'
                            : 'down';
                        $byDocType[$docType][$direction] = ($byDocType[$docType][$direction] ?? 0) + 1;
                    }
                }

                $driftedPoCount += count($driftedIds);

                if ($execute && $driftedIds !== []) {
                    $written += PoDocument::recomputeForPurchaseOrders($driftedIds);
                }
            });

        $report = [
            'mode' => $execute ? 'execute' : 'dry_run',
            'drifted_po_count' => $driftedPoCount,
            'drift_by_doc_type' => $byDocType,
            'rows_written' => $written,
            // Reported only: legacy shipment rows the old read heuristics masked.
            'pending_shipment_documents_with_file' => ShipmentDocument::query()
                ->where('status', ShipmentDocument::STATUS_PENDING)
                ->whereHas('attachments')
                ->count(),
            // BL/Form-E rows still on the retired issued/processing/done workflow.
            'off_workflow_bl_form_e' => ShipmentDocument::query()
                ->whereIn('doc_type', [ShipmentDocument::DOC_TYPE_BL, ShipmentDocument::DOC_TYPE_FORM_E])
                ->whereIn('status', [ShipmentDocument::STATUS_ISSUED, ShipmentDocument::STATUS_PROCESSING, ShipmentDocument::STATUS_DONE])
                ->count(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode([
                'generated_at' => BusinessTime::now()->toIso8601String(),
                ...$report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $rows = [
            ['mode', $report['mode']],
            ['drifted_po_count', (string) $report['drifted_po_count']],
            ['rows_written', (string) $report['rows_written']],
            ['pending_shipment_documents_with_file', (string) $report['pending_shipment_documents_with_file']],
            ['off_workflow_bl_form_e', (string) $report['off_workflow_bl_form_e']],
        ];
        foreach ($byDocType as $docType => $directions) {
            $rows[] = ["drift.{$docType}", 'up '.($directions['up'] ?? 0).', down '.($directions['down'] ?? 0)];
        }
        $this->table(['Metric', 'Value'], $rows);

        if (! $execute && $driftedPoCount > 0) {
            $this->comment('Dry run: no rows were written. Re-run with --execute to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * POs whose document status is derived from shipments (non-legacy).
     *
     * @return Builder<PurchaseOrder>
     */
    public static function shipmentTrackedPurchaseOrders(): Builder
    {
        return PurchaseOrder::query()
            ->where(fn (Builder $query) => $query->whereHas('shipmentItems')->orWhereHas('awards'));
    }
}
