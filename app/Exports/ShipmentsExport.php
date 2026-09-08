<?php

namespace App\Exports;

use App\Contracts\TracksExportProgress;
use App\Exports\Concerns\InteractsWithExportProgress;
use App\Models\Shipment;
use App\Support\NumberFormat;
use App\Support\SpreadsheetCellSanitizer;
use App\Support\StatusHelper;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithCustomQuerySize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ShipmentsExport implements FromQuery, TracksExportProgress, WithColumnWidths, WithCustomChunkSize, WithCustomQuerySize, WithHeadings, WithMapping
{
    use InteractsWithExportProgress;

    public function __construct(
        protected ?int $supplierId = null,
        protected ?string $status = null,
        protected ?string $search = null,
        protected ?string $startDate = null,
        protected ?string $endDate = null
    ) {}

    public function query(): Builder
    {
        $query = Shipment::query()->with([
            'supplier',
            'items.purchaseOrder',
            'items.quotationItem.prItem',
        ]);

        if ($this->supplierId) {
            $query->where('supplier_id', $this->supplierId);
        }

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->startDate) {
            $query->where('shipment_date', '>=', Carbon::parse($this->startDate)->startOfDay());
        }

        if ($this->endDate) {
            $query->where('shipment_date', '<=', Carbon::parse($this->endDate)->endOfDay());
        }

        if ($search = trim((string) $this->search)) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('shipment_number', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('items.purchaseOrder', fn (Builder $po) => $po->where('po_number', 'like', "%{$search}%"))
                    ->orWhereHas('items.quotationItem.prItem', fn (Builder $prItem) => $prItem->where('material_name', 'like', "%{$search}%"));
            });
        }

        return $query->latest('shipment_date');
    }

    public function map($shipment): array
    {
        /** @var Shipment $shipment */
        $pos = $shipment->purchaseOrders()->pluck('po_number')->unique()->implode(', ');
        $totalQty = (int) $shipment->items->sum('shipped_qty');
        $actualWeight = (float) $shipment->items->sum('actual_weight_kg');

        return [
            SpreadsheetCellSanitizer::text($shipment->shipment_number),
            SpreadsheetCellSanitizer::text($shipment->supplier?->name ?? '-'),
            SpreadsheetCellSanitizer::text($pos ?: '-'),
            $shipment->items->count(),
            $totalQty,
            NumberFormat::maxDecimals($actualWeight),
            $shipment->shipment_date?->format('Y-m-d') ?? '-',
            $shipment->estimated_arrival_date?->format('Y-m-d') ?? '-',
            $shipment->actual_arrival_date?->format('Y-m-d') ?? '-',
            SpreadsheetCellSanitizer::text(StatusHelper::shipmentLabel($shipment->status)),
            SpreadsheetCellSanitizer::text($shipment->notes ?? '-'),
        ];
    }

    public function collection(): Collection
    {
        return $this->query()->get()->map(fn (Shipment $shp) => $this->map($shp));
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
            'Shipment Number',
            'Supplier',
            'Consolidated POs',
            'Items Count',
            'Total Qty',
            'Actual Weight (Kg)',
            'Shipment Date',
            'Est. Arrival Date',
            'Actual Arrival Date',
            'Status',
            'Notes / Remarks',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 20,
            'B' => 26,
            'C' => 30,
            'D' => 14,
            'E' => 14,
            'F' => 20,
            'G' => 16,
            'H' => 18,
            'I' => 18,
            'J' => 16,
            'K' => 32,
        ];
    }
}
