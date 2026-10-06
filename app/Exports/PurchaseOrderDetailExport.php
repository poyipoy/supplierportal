<?php

namespace App\Exports;

use App\Contracts\TracksExportProgress;
use App\Exports\Concerns\InteractsWithExportProgress;
use App\Models\PurchaseOrder;
use App\Support\SpreadsheetCellSanitizer;
use App\Support\StatusHelper;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;

class PurchaseOrderDetailExport implements \Illuminate\Contracts\Translation\HasLocalePreference, FromCollection, TracksExportProgress, WithColumnWidths, WithHeadings
{
    use InteractsWithExportProgress;

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
            'awards',
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

        return $po->commercialQuotations()->flatMap(function ($quotation) use (
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
                $amount = $item->resolved_amount;
                $requestedAmount = $item->requested_amount;
                $offerAmount = $item->offer_amount;
                $pricePerKg = $item->price_per_kg === null ? null : (float) $item->price_per_kg;

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
                    $pricePerKg,
                    $amount,
                    $rate,
                    $amount * $rate,
                    $poStatus,
                    $po->created_at ? \App\Support\BusinessTime::format($po->created_at, 'Y-m-d H:i:s', false) : '-',
                    $po->estimated_arrival?->format('Y-m-d') ?? '-',
                    $po->actual_arrival?->format('Y-m-d') ?? '-',
                    SpreadsheetCellSanitizer::text($po->notes),
                    $documentStatus('invoice'),
                    $documentStatus('bl'),
                    $documentStatus('packing_list'),
                    $documentStatus('form_e'),
                    $qcStatus,
                    $latestInspection?->inspected_at ? \App\Support\BusinessTime::format($latestInspection->inspected_at, 'Y-m-d H:i:s', false) : '-',
                    $claimStatus,
                    $latestClaim?->updated_at ? \App\Support\BusinessTime::format($latestClaim->updated_at, 'Y-m-d H:i:s', false) : '-',
                    $item->is_available ? __('status.availability.available') : __('status.availability.not_available'),
                    $item->offered_quantity,
                    SpreadsheetCellSanitizer::text($item->offered_dimension_label !== '-' ? $item->offered_dimension_label : null),
                    $item->available_length_display !== '-' ? $item->available_length_display : null,
                    $item->offered_weight_per_unit === null ? null : (float) $item->offered_weight_per_unit,
                    SpreadsheetCellSanitizer::text($item->offered_weight_source),
                    $item->offered_total_weight,
                    $requestedAmount,
                    $offerAmount,
                ];
            });
        });
    }

    public function headings(): array
    {
        return [
            __('exports.headings.po_number'),
            __('exports.headings.pr_number'),
            __('exports.headings.supplier'),
            __('exports.headings.currency'),
            __('exports.headings.material'),
            __('exports.headings.hs_code'),
            __('exports.headings.requested_quantity'),
            __('exports.headings.weight_unit'),
            __('exports.headings.total_weight'),
            __('exports.headings.price_per_kg'),
            __('exports.headings.amount'),
            __('exports.headings.exchange_rate'),
            __('exports.headings.total_idr'),
            __('exports.headings.po_status'),
            __('exports.headings.created_at').' ('.\App\Support\BusinessTime::label().')',
            __('exports.headings.estimated_arrival'),
            __('exports.headings.actual_arrival'),
            __('exports.headings.remark'),
            __('exports.headings.invoice_status'),
            __('exports.headings.bl_status'),
            __('exports.headings.packing_list_status'),
            __('exports.headings.form_e_status'),
            __('exports.headings.qc_status'),
            __('exports.headings.qc_inspected_at').' ('.\App\Support\BusinessTime::label().')',
            __('exports.headings.claim_status'),
            __('exports.headings.claim_updated_at').' ('.\App\Support\BusinessTime::label().')',
            __('exports.headings.availability'),
            __('exports.headings.offered_quantity'),
            __('exports.headings.offered_dimensions'),
            __('exports.headings.offered_length'),
            __('exports.headings.offer_weight_unit'),
            __('exports.headings.offer_weight_source'),
            __('exports.headings.offer_total_weight'),
            __('exports.headings.requested_amount'),
            __('exports.headings.offer_amount'),
        ];
    }

    public function progressTotalRows(): int
    {
        $query = PurchaseOrder::query()
            ->select(['id', 'supplier_id'])
            ->whereKey($this->purchaseOrderId);

        if ($this->forcedSupplierId !== null) {
            $query->where('supplier_id', $this->forcedSupplierId);
        }

        $purchaseOrder = $query->firstOrFail();

        return $purchaseOrder->commercialQuotationItems()->count();
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
            'AA' => 18,
            'AB' => 18,
            'AC' => 18,
            'AD' => 18,
            'AE' => 18,
            'AF' => 18,
            'AG' => 18,
            'AH' => 18,
            'AI' => 18,
        ];
    }
}
