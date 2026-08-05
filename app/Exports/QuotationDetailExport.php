<?php

namespace App\Exports;

use App\Models\Quotation;
use App\Support\SpreadsheetCellSanitizer;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class QuotationDetailExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    public function __construct(
        private readonly int $quotationId,
        private readonly ?int $forcedSupplierId = null,
        private readonly bool $includeReviewerNotes = true,
    ) {}

    public function collection(): Collection
    {
        $query = Quotation::with([
            'supplier.supplier',
            'purchaseRequisition.period',
            'items.prItem',
            'items.attachments',
            'exchange_rate',
        ])->whereKey($this->quotationId);

        if ($this->forcedSupplierId !== null) {
            $query->where('supplier_id', $this->forcedSupplierId);
        }

        $quotation = $query->firstOrFail();
        $pr = $quotation->purchaseRequisition;
        $rate = (float) ($quotation->exchange_rate?->rate_to_idr ?? 0);
        $supplierName = $quotation->supplier?->supplier?->company_name
            ?: $quotation->supplier?->name;

        return $quotation->items->map(function ($item) use ($quotation, $pr, $rate, $supplierName) {
            $prItem = $item->prItem;
            $requestedDimensions = $prItem?->dimension_label;
            $offeredDimensions = $item->available_dimension_label;
            $pricePerKg = (float) $item->price_per_kg;
            $amount = (float) $item->amount;

            $row = [
                SpreadsheetCellSanitizer::text($pr?->pr_number),
                SpreadsheetCellSanitizer::text($pr?->period?->name),
                SpreadsheetCellSanitizer::text($supplierName),
                SpreadsheetCellSanitizer::text(strtoupper((string) $quotation->currency)),
                SpreadsheetCellSanitizer::text($quotation->statusLabel()),
                $quotation->submitted_at?->format('Y-m-d H:i:s') ?? '-',
                $quotation->estimated_delivery?->format('Y-m-d') ?? '-',
                $quotation->validity_period?->format('Y-m-d') ?? '-',
                SpreadsheetCellSanitizer::text($quotation->payment_terms),
                SpreadsheetCellSanitizer::text($quotation->general_notes),
            ];

            if ($this->includeReviewerNotes) {
                $row[] = SpreadsheetCellSanitizer::text($quotation->reviewer_notes);
            }

            $row = array_merge($row, [
                SpreadsheetCellSanitizer::text($prItem?->material_name),
                SpreadsheetCellSanitizer::text($prItem?->hs_code),
                $prItem?->quantity_value,
                SpreadsheetCellSanitizer::text($requestedDimensions !== '-' ? $requestedDimensions : null),
                $item->available_qty,
                SpreadsheetCellSanitizer::text($offeredDimensions !== '-' ? $offeredDimensions : null),
                (float) ($prItem?->weight_needed ?? 0),
                (float) ($prItem?->total_weight ?? 0),
                $pricePerKg,
                $amount,
                $pricePerKg * $rate,
                $amount * $rate,
                SpreadsheetCellSanitizer::text($item->notes),
                $item->attachments->count(),
            ]);

            return $row;
        });
    }

    public function headings(): array
    {
        $headings = [
            'PR Number',
            'Period',
            'Supplier',
            'Currency',
            'Status',
            'Submitted At',
            'Estimated Delivery',
            'Valid Until',
            'Payment Terms',
            'General Notes',
        ];

        if ($this->includeReviewerNotes) {
            $headings[] = 'Reviewer Notes';
        }

        return array_merge($headings, [
            'Material',
            'HS Code',
            'Requested Quantity',
            'Requested Dimensions',
            'Offered Quantity',
            'Offered Dimensions',
            'Weight/Unit',
            'Total Weight',
            'Price per Kg',
            'Amount',
            'Price per Kg IDR',
            'Amount IDR',
            'Item Notes',
            'MTC Attachment Count',
        ]);
    }
}
