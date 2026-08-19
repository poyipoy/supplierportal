<?php

namespace App\Exports;

use App\Models\PurchaseOrder;
use App\Support\SpreadsheetCellSanitizer;
use App\Support\StatusHelper;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;

class PurchaseOrderDetailExport implements FromCollection, WithColumnWidths, WithHeadings
{
    public function __construct(
        private readonly int $purchaseOrderId,
        private readonly ?int $forcedSupplierId = null,
    ) {}

    public function collection(): Collection
    {
        $claimRelation = function ($query) {
            if ($this->forcedSupplierId !== null) {
                $query->where('supplier_id', $this->forcedSupplierId);
            }
        };

        $query = PurchaseOrder::with([
            'supplier.supplier',
            'exchangeRate',
            'quotations.purchaseRequisition.period',
            'quotations.exchange_rate',
            'quotations.items.prItem',
            'documents',
            'qcInspections',
            'materialClaims' => $claimRelation,
        ])->whereKey($this->purchaseOrderId);

        if ($this->forcedSupplierId !== null) {
            $query->where('supplier_id', $this->forcedSupplierId);
        }

        $po = $query->firstOrFail();
        $supplierName = $po->supplier?->supplier?->company_name ?: $po->supplier?->name;
        $latestInspection = $po->qcInspections->sortByDesc('inspected_at')->first();
        $latestClaim = $po->materialClaims->sortByDesc('created_at')->first();
        $documents = $po->documents
            ->groupBy('doc_type')
            ->map(fn (Collection $docs) => $docs->sortByDesc('updated_at')->first());

        $documentStatus = function (string $type) use ($documents): string {
            $document = $documents->get($type);

            return $document
                ? SpreadsheetCellSanitizer::text(StatusHelper::docLabel((string) $document->status))
                : '-';
        };

        return $po->quotations->flatMap(function ($quotation) use (
            $po,
            $supplierName,
            $latestInspection,
            $latestClaim,
            $documentStatus
        ) {
            $rate = (float) ($quotation->exchange_rate?->rate_to_idr
                ?? $po->exchangeRate?->rate_to_idr
                ?? 0);
            $poStatus = SpreadsheetCellSanitizer::text(
                StatusHelper::poLabel($po->status, $po->is_overdue)
            );
            $qcStatus = $latestInspection
                ? SpreadsheetCellSanitizer::text(StatusHelper::qcLabel((string) $latestInspection->status))
                : '-';
            $claimStatus = $latestClaim
                ? SpreadsheetCellSanitizer::text(StatusHelper::claimLabel((string) $latestClaim->status))
                : '-';

            return $quotation->items->map(function ($item) use (
                $quotation,
                $po,
                $supplierName,
                $rate,
                $poStatus,
                $qcStatus,
                $claimStatus,
                $latestInspection,
                $latestClaim,
                $documentStatus
            ) {
                $prItem = $item->prItem;
                $amount = (float) $item->amount;

                return [
                    SpreadsheetCellSanitizer::text($po->po_number),
                    SpreadsheetCellSanitizer::text($quotation->purchaseRequisition?->pr_number),
                    SpreadsheetCellSanitizer::text($supplierName),
                    SpreadsheetCellSanitizer::text(strtoupper((string) ($quotation->currency ?: $po->currency))),
                    SpreadsheetCellSanitizer::text($prItem?->material_name),
                    SpreadsheetCellSanitizer::text($prItem?->hs_code),
                    $prItem?->quantity_value,
                    (float) ($prItem?->weight_needed ?? 0),
                    (float) ($prItem?->total_weight ?? 0),
                    (float) $item->price_per_kg,
                    $amount,
                    $rate,
                    $amount * $rate,
                    $poStatus,
                    $po->created_at?->format('Y-m-d H:i:s') ?? '-',
                    $po->estimated_arrival?->format('Y-m-d') ?? '-',
                    $po->actual_arrival?->format('Y-m-d') ?? '-',
                    SpreadsheetCellSanitizer::text($po->notes),
                    $documentStatus('invoice'),
                    $documentStatus('bl'),
                    $documentStatus('packing_list'),
                    $documentStatus('form_e'),
                    $qcStatus,
                    $latestInspection?->inspected_at?->format('Y-m-d H:i:s') ?? '-',
                    $claimStatus,
                    $latestClaim?->updated_at?->format('Y-m-d H:i:s') ?? '-',
                ];
            });
        });
    }

    public function headings(): array
    {
        return [
            'PO Number',
            'PR Number',
            'Supplier',
            'Currency',
            'Material',
            'HS Code',
            'Requested Quantity',
            'Weight/Unit',
            'Total Weight',
            'Price per Kg',
            'Amount',
            'Exchange Rate',
            'Total IDR',
            'PO Status',
            'Created At',
            'Estimated Arrival',
            'Actual Arrival',
            'Remark',
            'Invoice Status',
            'BL Status',
            'Packing List Status',
            'Form-E Status',
            'QC Status',
            'QC Inspected At',
            'Claim Status',
            'Claim Updated At',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 22,
            'B' => 22,
            'C' => 25,
            'D' => 12,
            'E' => 30,
            'F' => 16,
            'G' => 19,
            'H' => 15,
            'I' => 15,
            'J' => 16,
            'K' => 16,
            'L' => 16,
            'M' => 18,
            'N' => 17,
            'O' => 21,
            'P' => 18,
            'Q' => 18,
            'R' => 30,
            'S' => 17,
            'T' => 17,
            'U' => 20,
            'V' => 17,
            'W' => 17,
            'X' => 21,
            'Y' => 17,
            'Z' => 21,
        ];
    }
}
