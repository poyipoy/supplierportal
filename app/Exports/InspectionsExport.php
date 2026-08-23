<?php

namespace App\Exports;

use App\Contracts\TracksExportProgress;
use App\Exports\Concerns\InteractsWithExportProgress;
use App\Models\QcInspection;
use App\Models\QcItem;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithCustomQuerySize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class InspectionsExport implements FromQuery, TracksExportProgress, WithColumnWidths, WithCustomChunkSize, WithCustomQuerySize, WithHeadings, WithMapping
{
    use InteractsWithExportProgress;

    protected $startDate;

    protected $endDate;

    protected $status;

    public function __construct($startDate = null, $endDate = null, $status = null)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->status = $status;
    }

    public function query(): Builder
    {
        $query = QcItem::query()->with([
            'inspection.purchaseOrder.supplier',
            'prItem',
        ]);

        if ($this->startDate) {
            $dateFrom = Carbon::parse($this->startDate)->startOfDay();
            $query->whereHas('inspection', fn (Builder $inspection) => $inspection->where('inspected_at', '>=', $dateFrom));
        }

        if ($this->endDate) {
            $dateToExclusive = Carbon::parse($this->endDate)->addDay()->startOfDay();
            $query->whereHas('inspection', fn (Builder $inspection) => $inspection->where('inspected_at', '<', $dateToExclusive));
        }

        if ($this->status) {
            $query->whereHas('inspection', fn (Builder $inspection) => $inspection->where('status', $this->status));
        }

        $inspectionDate = QcInspection::query()
            ->select('inspected_at')
            ->whereColumn('qc_inspections.id', 'qc_items.inspection_id')
            ->limit(1);

        return $query->orderByDesc($inspectionDate)->orderBy('id');
    }

    public function map($item): array
    {
        $inspection = $item->inspection;
        $prItem = $item->prItem;
        $requestedSpecification = $prItem
            ? collect([
                $prItem->shape,
                $prItem->dimension_label !== '-' ? $prItem->dimension_label : null,
            ])->filter()->implode(' | ')
            : '-';
        $actualDimensions = collect([
            $item->actual_thickness ? "T:{$item->actual_thickness}" : null,
            $item->actual_width ? "W:{$item->actual_width}" : null,
            $item->actual_length ? "L:{$item->actual_length}" : null,
        ])->filter()->implode(' | ') ?: '-';

        return [
            $inspection?->purchaseOrder?->po_number ?? '-',
            $inspection?->purchaseOrder?->supplier?->name ?? '-',
            $prItem?->material_name ?? '-',
            $requestedSpecification,
            $actualDimensions,
            strtoupper((string) $item->status),
            strtoupper((string) $inspection?->status),
            $inspection?->inspected_at?->format('d/m/Y H:i') ?? '-',
        ];
    }

    public function collection(): Collection
    {
        return $this->query()->get()->map(fn (QcItem $item) => $this->map($item));
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
        return ['PO Number', 'Supplier', 'Material', 'Requested Specification', 'Actual Dimensions', 'Item Status', 'Inspection Status', 'Inspection Date'];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 22,
            'B' => 25,
            'C' => 30,
            'D' => 38,
            'E' => 34,
            'F' => 15,
            'G' => 18,
            'H' => 20,
        ];
    }
}
