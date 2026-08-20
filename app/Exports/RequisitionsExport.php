<?php

namespace App\Exports;

use App\Models\PrItem;
use App\Support\SpreadsheetCellSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class RequisitionsExport implements FromQuery, WithColumnWidths, WithCustomChunkSize, WithHeadings, WithMapping
{
    protected $periodId;

    protected $status;

    protected $search;

    public function __construct($periodId = null, $status = null, $search = null)
    {
        $this->periodId = $periodId;
        $this->status = $status;
        $this->search = $search;
    }

    public function query(): Builder
    {
        $q = PrItem::query()->with([
            'purchaseRequisition.period',
            'purchaseRequisition.creator',
            'purchaseRequisition.items',
        ]);

        if ($this->periodId) {
            $q->whereHas('purchaseRequisition', fn (Builder $pr) => $pr->where('period_id', $this->periodId));
        }

        if ($this->status) {
            $q->whereHas('purchaseRequisition', fn (Builder $pr) => $pr->where('status', $this->status));
        }

        if ($search = trim((string) $this->search)) {
            $q->whereHas('purchaseRequisition', function (Builder $pr) use ($search) {
                $pr->where(function (Builder $query) use ($search) {
                    $query->where('pr_number', 'like', '%'.$search.'%')
                        ->orWhereHas('period', fn (Builder $period) => $period->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('creator', fn (Builder $creator) => $creator->where('name', 'like', '%'.$search.'%'))
                        ->orWhere('created_at', 'like', '%'.$search.'%');
                });
            });
        }

        return $q->orderByDesc('pr_id')->orderBy('id');
    }

    public function map($item): array
    {
        $pr = $item->purchaseRequisition;
        $prTotalKg = $pr?->items?->sum(fn (PrItem $prItem) => $prItem->total_weight) ?? 0;
        $spec = collect([
            $item->shape,
            $item->dimension_label !== '-' ? $item->dimension_label : null,
        ])->filter()->implode(' | ');

        return [
            SpreadsheetCellSanitizer::text($pr?->pr_number, 'DRAFT'),
            SpreadsheetCellSanitizer::text($pr?->period?->display_label ?? $pr?->period?->name),
            SpreadsheetCellSanitizer::text($item->material_name),
            SpreadsheetCellSanitizer::text($spec),
            $item->quantity_value,
            (float) $item->weight_needed,
            (float) $item->total_weight,
            (float) $prTotalKg,
            SpreadsheetCellSanitizer::text($item->remark),
            SpreadsheetCellSanitizer::text(strtoupper((string) $pr?->status)),
            $pr?->created_at?->format('Y-m-d H:i:s') ?? '-',
        ];
    }

    public function collection(): Collection
    {
        return $this->query()->get()->map(fn (PrItem $item) => $this->map($item));
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function headings(): array
    {
        return ['PR Number', 'Period', 'Material Name', 'Specification', 'Qty', 'Weight/Unit', 'Total Weight', 'PR Total KG', 'Remark', 'Status', 'Date Created'];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 22,
            'B' => 18,
            'C' => 30,
            'D' => 38,
            'E' => 10,
            'F' => 15,
            'G' => 15,
            'H' => 15,
            'I' => 30,
            'J' => 15,
            'K' => 21,
        ];
    }
}
