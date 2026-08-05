<?php

namespace App\Exports;

use App\Models\Quotation;
use App\Support\SpreadsheetCellSanitizer;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class QuotationsExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    public function __construct(
        private readonly array $filters = [],
        private readonly ?int $forcedSupplierId = null,
        private readonly bool $includeDrafts = false
    ) {}

    public function collection()
    {
        if (($this->filters['status'] ?? null) === 'unresponded') {
            return collect();
        }

        $statuses = [
            Quotation::STATUS_SUBMITTED,
            Quotation::STATUS_REVISION_REQUESTED,
            Quotation::STATUS_ACCEPTED,
            Quotation::STATUS_REJECTED,
        ];

        if ($this->includeDrafts) {
            array_unshift($statuses, Quotation::STATUS_DRAFT);
        }

        $query = Quotation::with([
            'supplier.supplier',
            'purchaseRequisition.period',
            'items.prItem',
            'exchange_rate',
        ])->whereIn('status', $statuses);

        if ($this->forcedSupplierId !== null) {
            $query->where('supplier_id', $this->forcedSupplierId);
        } elseif (! empty($this->filters['supplier_id'])) {
            $query->where('supplier_id', $this->filters['supplier_id']);
        }

        if ($prNumber = trim((string) ($this->filters['pr_number'] ?? ''))) {
            $query->whereHas('purchaseRequisition', fn ($pr) => $pr->where('pr_number', 'like', '%'.$prNumber.'%'));
        }

        if (! empty($this->filters['period_id'])) {
            $query->whereHas('purchaseRequisition', fn ($pr) => $pr->where('period_id', $this->filters['period_id']));
        }

        if (! empty($this->filters['date_from'])) {
            $query->where('submitted_at', '>=', Carbon::createFromFormat('Y-m', $this->filters['date_from'])->startOfMonth());
        }

        if (! empty($this->filters['date_to'])) {
            $query->where('submitted_at', '<=', Carbon::createFromFormat('Y-m', $this->filters['date_to'])->endOfMonth());
        }

        if (! empty($this->filters['status'])) {
            $query->where('status', $this->filters['status']);
        }

        if (! empty($this->filters['currency'])) {
            $query->where('currency', $this->filters['currency']);
        }

        if ($search = trim((string) ($this->filters['search'] ?? ''))) {
            $query->whereHas('purchaseRequisition', function ($pr) use ($search) {
                $pr->where('pr_number', 'like', '%'.$search.'%')
                    ->orWhere('updated_at', 'like', '%'.$search.'%');
            });
        }

        $rows = collect();

        foreach ($query->lazyByIdDesc(500) as $quotation) {
            $supplierName = $quotation->supplier?->supplier?->company_name
                ?: $quotation->supplier?->name;
            $rate = (float) ($quotation->exchange_rate?->rate_to_idr ?? 0);

            foreach ($quotation->items as $item) {
                $prItem = $item->prItem;
                $amount = (float) $item->amount;
                $requestedDimensions = $prItem?->dimension_label;
                $offeredDimensions = $item->available_dimension_label;

                $rows->push([
                    SpreadsheetCellSanitizer::text($quotation->purchaseRequisition?->pr_number),
                    SpreadsheetCellSanitizer::text($quotation->purchaseRequisition?->period?->name),
                    SpreadsheetCellSanitizer::text($supplierName),
                    SpreadsheetCellSanitizer::text(strtoupper((string) $quotation->currency)),
                    SpreadsheetCellSanitizer::text($prItem?->material_name),
                    SpreadsheetCellSanitizer::text($prItem?->hs_code),
                    $prItem?->quantity_value,
                    SpreadsheetCellSanitizer::text($requestedDimensions !== '-' ? $requestedDimensions : null),
                    $item->available_qty,
                    SpreadsheetCellSanitizer::text($offeredDimensions !== '-' ? $offeredDimensions : null),
                    (float) $item->price_per_kg,
                    $amount,
                    $rate,
                    $amount * $rate,
                    SpreadsheetCellSanitizer::text($item->notes),
                    SpreadsheetCellSanitizer::text($quotation->statusLabel()),
                    $quotation->submitted_at?->format('Y-m-d H:i:s') ?? '-',
                ]);
            }
        }

        return $rows;
    }

    public function headings(): array
    {
        return [
            'PR Number',
            'Period',
            'Supplier',
            'Currency',
            'Material',
            'HS Code',
            'Requested Quantity',
            'Requested Dimensions',
            'Offered Quantity',
            'Offered Dimensions',
            'Price per Kg',
            'Amount',
            'Exchange Rate',
            'Total IDR',
            'Item Notes',
            'Status',
            'Submitted At',
        ];
    }
}
