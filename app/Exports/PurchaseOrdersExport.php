<?php

namespace App\Exports;

use App\Contracts\TracksExportProgress;
use App\Exports\Concerns\InteractsWithExportProgress;
use App\Models\PurchaseOrder;
use App\Support\SpreadsheetCellSanitizer;
use App\Support\StatusHelper;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class PurchaseOrdersExport implements FromQuery, TracksExportProgress, WithColumnWidths, WithCustomChunkSize, WithHeadings, WithMapping
{
    use InteractsWithExportProgress;

    protected $supplierId;

    protected $startDate;

    protected $endDate;

    protected $poNumber;

    protected $status;

    protected $search;

    public function __construct(
        $supplierId = null,
        $startDate = null,
        $endDate = null,
        $poNumber = null,
        $status = null,
        $search = null
    ) {
        $this->supplierId = $supplierId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->poNumber = $poNumber;
        $this->status = $status;
        $this->search = $search;
    }

    public function query(): Builder
    {
        $q = PurchaseOrder::query()->with([
            'supplier',
            'quotations.purchaseRequisition.period',
            'quotations.items.prItem',
            'quotations.exchange_rate',
        ]);

        if ($this->supplierId) {
            $q->where('supplier_id', $this->supplierId);
        }

        if ($this->startDate) {
            $q->where('created_at', '>=', Carbon::parse($this->startDate)->startOfDay());
        }

        if ($this->endDate) {
            $q->where('created_at', '<', Carbon::parse($this->endDate)->addDay()->startOfDay());
        }

        if ($poNumber = trim((string) $this->poNumber)) {
            $q->where('po_number', 'like', '%'.$poNumber.'%');
        }

        if ($this->status === 'overdue') {
            $q->where(function (Builder $query) {
                $query->where('status', 'overdue')
                    ->orWhere(function (Builder $activeQuery) {
                        $activeQuery->where('status', 'active')
                            ->whereNotNull('estimated_arrival')
                            ->where('estimated_arrival', '<', today()->startOfDay())
                            ->whereNull('actual_arrival');
                    });
            });
        } elseif ($this->status) {
            $q->where('status', $this->status);
        }

        if ($search = trim((string) $this->search)) {
            $q->where(function (Builder $query) use ($search) {
                $query->where('po_number', 'like', '%'.$search.'%')
                    ->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('quotations.purchaseRequisition.period', fn (Builder $period) => $period->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('quotations.purchaseRequisition', fn (Builder $pr) => $pr->where('pr_number', 'like', '%'.$search.'%'))
                    ->orWhere('notes', 'like', '%'.$search.'%')
                    ->orWhere('status', 'like', '%'.$search.'%')
                    ->orWhere('estimated_arrival', 'like', '%'.$search.'%');
            });
        }

        return $q->orderByDesc('id');
    }

    public function map($po): array
    {
        $prNumbers = $po->pr_reference;
        $materials = $po->quotations
            ->flatMap(fn ($quotation) => $quotation->items->map(fn ($item) => $item->prItem?->material_name))
            ->filter()
            ->implode(', ') ?: '-';
        $totalAmount = (float) $po->quotations->sum(fn ($quotation) => $quotation->items->sum(fn ($item) => $item->resolved_amount));
        $currency = $po->currency ?? '-';
        $totalIdr = 0.0;

        foreach ($po->quotations as $quotation) {
            $rate = (float) ($quotation->exchange_rate?->rate_to_idr ?? 0);
            foreach ($quotation->items as $item) {
                $totalIdr += $item->resolved_amount * $rate;
            }
        }

        return [
            SpreadsheetCellSanitizer::text($po->po_number),
            SpreadsheetCellSanitizer::text($prNumbers),
            SpreadsheetCellSanitizer::text($po->supplier?->name),
            SpreadsheetCellSanitizer::text($materials),
            SpreadsheetCellSanitizer::text($currency),
            $totalAmount,
            $totalIdr,
            $po->estimated_arrival?->format('Y-m-d') ?? '-',
            SpreadsheetCellSanitizer::text($po->notes),
            SpreadsheetCellSanitizer::text(StatusHelper::poLabel($po->status, $po->is_overdue)),
        ];
    }

    public function collection(): Collection
    {
        return $this->query()->get()->map(fn (PurchaseOrder $po) => $this->map($po));
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function progressTotalRows(): int
    {
        return $this->query()->count();
    }

    public function headings(): array
    {
        return ['PO Number', 'PR Number', 'Supplier', 'Material', 'Currency', 'Total Amount', 'Total IDR', 'Est. Arrival', 'Remark', 'Status'];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 22,
            'B' => 28,
            'C' => 25,
            'D' => 40,
            'E' => 12,
            'F' => 16,
            'G' => 18,
            'H' => 16,
            'I' => 30,
            'J' => 16,
        ];
    }
}
