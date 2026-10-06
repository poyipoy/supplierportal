<?php

namespace App\Exports;

use App\Contracts\TracksExportProgress;
use App\Exports\Concerns\InteractsWithExportProgress;
use App\Models\Quotation;
use App\Support\SpreadsheetCellSanitizer;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class QuotationDetailExport implements \Illuminate\Contracts\Translation\HasLocalePreference, FromCollection, TracksExportProgress, WithColumnWidths, WithHeadings
{
    use InteractsWithExportProgress;

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
            $pricePerKg = $item->price_per_kg === null ? null : (float) $item->price_per_kg;
            $requestedAmount = $item->requested_amount;
            $offerAmount = $item->offer_amount;
            $amount = $offerAmount ?? 0.0;

            $row = [
                SpreadsheetCellSanitizer::text($pr?->pr_number),
                SpreadsheetCellSanitizer::text($pr?->period?->display_label ?? $pr?->period?->name),
                SpreadsheetCellSanitizer::text($supplierName),
                SpreadsheetCellSanitizer::text(strtoupper((string) $quotation->currency)),
                SpreadsheetCellSanitizer::text($quotation->statusLabel()),
                $quotation->submitted_at ? \App\Support\BusinessTime::format($quotation->submitted_at, 'Y-m-d H:i:s', false) : '-',
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
                $pricePerKg === null ? null : $pricePerKg * $rate,
                $offerAmount === null ? null : $offerAmount * $rate,
                SpreadsheetCellSanitizer::text($item->notes),
                $item->attachments->count(),
                $item->is_available ? __('status.availability.available') : __('status.availability.not_available'),
                $item->offered_weight_per_unit === null ? null : (float) $item->offered_weight_per_unit,
                SpreadsheetCellSanitizer::text($item->offered_weight_source),
                $item->offered_total_weight,
                $requestedAmount,
                $offerAmount,
            ]);

            return $row;
        });
    }

    public function headings(): array
    {
        $headings = [
            __('exports.headings.pr_number'),
            __('exports.headings.period'),
            __('exports.headings.supplier'),
            __('exports.headings.currency'),
            __('exports.headings.status'),
            __('exports.headings.submitted_at').' ('.\App\Support\BusinessTime::label().')',
            __('exports.closure.estimated_delivery'),
            __('exports.closure.valid_until'),
            __('exports.closure.payment_terms'),
            __('exports.closure.general_notes'),
        ];

        if ($this->includeReviewerNotes) {
            $headings[] = __('exports.closure.reviewer_notes');
        }

        return array_merge($headings, [
            __('exports.headings.material'),
            __('exports.headings.hs_code'),
            __('exports.headings.requested_quantity'),
            __('exports.headings.requested_dimensions'),
            __('exports.headings.offered_quantity'),
            __('exports.headings.offered_dimensions'),
            __('exports.headings.weight_unit'),
            __('exports.headings.total_weight'),
            __('exports.headings.price_per_kg'),
            __('exports.headings.amount'),
            __('exports.closure.price_kg_idr'),
            __('exports.closure.amount_idr'),
            __('exports.headings.item_notes'),
            __('exports.closure.mtc_count'),
            __('exports.headings.availability'),
            __('exports.headings.offer_weight_unit'),
            __('exports.headings.offer_weight_source'),
            __('exports.headings.offer_total_weight'),
            __('exports.headings.requested_amount'),
            __('exports.headings.offer_amount'),
        ]);
    }

    public function progressTotalRows(): int
    {
        $query = Quotation::query()
            ->select('id')
            ->whereKey($this->quotationId);

        if ($this->forcedSupplierId !== null) {
            $query->where('supplier_id', $this->forcedSupplierId);
        }

        return $query->firstOrFail()->items()->count();
    }

    public function columnWidths(): array
    {
        $widths = [
            'A' => 22,
            'B' => 18,
            'C' => 25,
            'D' => 12,
            'E' => 19,
            'F' => 21,
            'G' => 18,
            'H' => 18,
            'I' => 24,
            'J' => 32,
        ];

        $itemWidths = [30, 16, 19, 38, 18, 38, 15, 15, 16, 16, 18, 18, 30, 20, 18, 18, 18, 18, 18, 18];
        $columnIndex = $this->includeReviewerNotes ? 12 : 11;

        if ($this->includeReviewerNotes) {
            $widths['K'] = 30;
        }

        foreach ($itemWidths as $width) {
            $widths[Coordinate::stringFromColumnIndex($columnIndex)] = $width;
            $columnIndex++;
        }

        return $widths;
    }
}
