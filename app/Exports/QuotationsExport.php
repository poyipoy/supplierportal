<?php

namespace App\Exports;

use App\Contracts\TracksExportProgress;
use App\Exports\Concerns\InteractsWithExportProgress;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Support\SpreadsheetCellSanitizer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithCustomQuerySize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class QuotationsExport implements FromQuery, TracksExportProgress, WithColumnWidths, WithCustomChunkSize, WithCustomQuerySize, WithHeadings, WithMapping
{
    use InteractsWithExportProgress;

    public function __construct(
        private readonly array $filters = [],
        private readonly ?int $forcedSupplierId = null,
        private readonly bool $includeDrafts = false
    ) {}

    public function query(): Builder
    {
        $statuses = [
            Quotation::STATUS_SUBMITTED,
            Quotation::STATUS_REVISION_REQUESTED,
            Quotation::STATUS_ACCEPTED,
            Quotation::STATUS_REJECTED,
            Quotation::STATUS_ALL_UNAVAILABLE,
        ];

        if ($this->includeDrafts) {
            array_unshift($statuses, Quotation::STATUS_DRAFT);
        }

        $query = QuotationItem::query()->with([
            'quotation.supplier.supplier',
            'quotation.purchaseRequisition.period',
            'quotation.exchange_rate',
            'prItem',
        ])->whereHas('quotation', fn (Builder $quotation) => $quotation->whereIn('status', $statuses));

        if (($this->filters['status'] ?? null) === 'unresponded') {
            return $query->whereRaw('1 = 0');
        }

        if ($this->forcedSupplierId !== null) {
            $query->whereHas('quotation', fn (Builder $quotation) => $quotation->where('supplier_id', $this->forcedSupplierId));
        } elseif (! empty($this->filters['supplier_id'])) {
            $query->whereHas('quotation', fn (Builder $quotation) => $quotation->where('supplier_id', $this->filters['supplier_id']));
        }

        if ($prNumber = trim((string) ($this->filters['pr_number'] ?? ''))) {
            $query->whereHas('quotation.purchaseRequisition', fn (Builder $pr) => $pr->where('pr_number', 'like', '%'.$prNumber.'%'));
        }

        if (! empty($this->filters['period_id'])) {
            $query->whereHas('quotation.purchaseRequisition', fn (Builder $pr) => $pr->where('period_id', $this->filters['period_id']));
        }

        if (! empty($this->filters['date_from'])) {
            $dateFrom = Carbon::createFromFormat('Y-m', $this->filters['date_from'])->startOfMonth();
            $query->whereHas('quotation', fn (Builder $quotation) => $quotation->where('submitted_at', '>=', $dateFrom));
        }

        if (! empty($this->filters['date_to'])) {
            $dateToExclusive = Carbon::createFromFormat('Y-m', $this->filters['date_to'])->startOfMonth()->addMonth();
            $query->whereHas('quotation', fn (Builder $quotation) => $quotation->where('submitted_at', '<', $dateToExclusive));
        }

        if (! empty($this->filters['status'])) {
            $query->whereHas('quotation', fn (Builder $quotation) => $quotation->where('status', $this->filters['status']));
        }

        if (! empty($this->filters['currency'])) {
            $query->whereHas('quotation', fn (Builder $quotation) => $quotation->where('currency', $this->filters['currency']));
        }

        if ($search = trim((string) ($this->filters['search'] ?? ''))) {
            $query->whereHas('quotation.purchaseRequisition', function (Builder $pr) use ($search) {
                $pr->where(function (Builder $searchQuery) use ($search) {
                    $searchQuery->where('pr_number', 'like', '%'.$search.'%')
                        ->orWhere('updated_at', 'like', '%'.$search.'%');
                });
            });
        }

        return $query->orderByDesc('quotation_id')->orderBy('id');
    }

    public function map($item): array
    {
        $quotation = $item->quotation;
        $supplierName = $quotation?->supplier?->supplier?->company_name
            ?: $quotation?->supplier?->name;
        $rate = (float) ($quotation?->exchange_rate?->rate_to_idr ?? 0);
        $prItem = $item->prItem;
        $requestedAmount = $item->requested_amount;
        $offerAmount = $item->offer_amount;
        $amount = $offerAmount ?? 0.0;
        $pricePerKg = $item->price_per_kg === null ? null : (float) $item->price_per_kg;
        $requestedDimensions = $prItem?->dimension_label;
        $offeredDimensions = $item->available_dimension_label;

        return [
            SpreadsheetCellSanitizer::text($quotation?->purchaseRequisition?->pr_number),
            SpreadsheetCellSanitizer::text($quotation?->purchaseRequisition?->period?->display_label ?? $quotation?->purchaseRequisition?->period?->name),
            SpreadsheetCellSanitizer::text($supplierName),
            SpreadsheetCellSanitizer::text(strtoupper((string) $quotation?->currency)),
            SpreadsheetCellSanitizer::text($prItem?->material_name),
            SpreadsheetCellSanitizer::text($prItem?->hs_code),
            $prItem?->quantity_value,
            SpreadsheetCellSanitizer::text($requestedDimensions !== '-' ? $requestedDimensions : null),
            $item->available_qty,
            SpreadsheetCellSanitizer::text($offeredDimensions !== '-' ? $offeredDimensions : null),
            $pricePerKg,
            $amount,
            $rate,
            $offerAmount === null ? null : $offerAmount * $rate,
            SpreadsheetCellSanitizer::text($item->notes),
            SpreadsheetCellSanitizer::text($quotation?->statusLabel()),
            $quotation?->submitted_at?->format('Y-m-d H:i:s') ?? '-',
            $item->is_available ? 'Available' : 'Not Available',
            $item->available_length_display !== '-' ? $item->available_length_display : null,
            $item->offered_weight_per_unit === null ? null : (float) $item->offered_weight_per_unit,
            SpreadsheetCellSanitizer::text($item->offered_weight_source),
            $item->offered_total_weight,
            $requestedAmount,
            $offerAmount,
        ];
    }

    public function collection(): Collection
    {
        return $this->query()->get()->map(fn (QuotationItem $item) => $this->map($item));
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function progressTotalRows(): int
    {
        return $this->querySize();
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
            'Availability',
            'Offered Length',
            'Offer Weight/Unit',
            'Offer Weight Source',
            'Offer Total Weight',
            'Requested Amount',
            'Offer Amount',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 22,
            'B' => 18,
            'C' => 25,
            'D' => 12,
            'E' => 30,
            'F' => 16,
            'G' => 19,
            'H' => 38,
            'I' => 18,
            'J' => 38,
            'K' => 16,
            'L' => 16,
            'M' => 16,
            'N' => 18,
            'O' => 30,
            'P' => 19,
            'Q' => 21,
            'R' => 18,
            'S' => 18,
            'T' => 18,
            'U' => 18,
            'V' => 18,
            'W' => 18,
            'X' => 18,
        ];
    }
}
