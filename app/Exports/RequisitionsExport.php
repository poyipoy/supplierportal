<?php

namespace App\Exports;

use App\Contracts\AcceptsExportOptions;
use App\Contracts\TracksExportProgress;
use App\Exports\Advanced\Concerns\UsesColumnCatalog;
use App\Exports\Concerns\InteractsWithExportProgress;
use App\Exports\Concerns\StylesOperationalWorkbook;
use App\Http\Requests\Export\Filters\RequisitionExportFilters;
use App\Models\PrItem;
use App\Support\BusinessTime;
use App\Support\SpreadsheetCellSanitizer;
use App\Support\StatusHelper;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithCustomQuerySize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;

class RequisitionsExport implements AcceptsExportOptions, FromQuery, HasLocalePreference, TracksExportProgress, WithColumnWidths, WithCustomChunkSize, WithCustomCsvSettings, WithCustomQuerySize, WithHeadings, WithMapping, WithStyles
{
    use InteractsWithExportProgress;
    use StylesOperationalWorkbook;
    use UsesColumnCatalog;

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
        $q = PrItem::query()->with($this->hasExportOptions() ? $this->catalogEagerLoads() : [
            'purchaseRequisition.period',
            'purchaseRequisition.creator',
            'purchaseRequisition.items',
        ]);
        if ($this->hasExportOptions()) {
            $q->whereHas('purchaseRequisition', fn ($q) => RequisitionExportFilters::apply($q, ['period_id' => $this->periodId, 'status' => $this->status, 'search' => $this->search]));

            return $q->orderByDesc('pr_id')->orderBy('id');
        }

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
        if ($this->hasExportOptions()) {
            return $this->catalogMap($item);
        }
        $pr = $item->purchaseRequisition;
        $prTotalKg = $pr?->items?->sum(fn (PrItem $prItem) => $prItem->total_weight) ?? 0;
        $spec = collect([
            $item->shape,
            $item->dimension_label !== '-' ? $item->dimension_label : null,
        ])->filter()->implode(' | ');

        return [
            SpreadsheetCellSanitizer::text($pr?->pr_number, __('status.pr.draft')),
            SpreadsheetCellSanitizer::text($pr?->period?->display_label ?? $pr?->period?->name),
            SpreadsheetCellSanitizer::text($item->material_name),
            SpreadsheetCellSanitizer::text($spec),
            $item->quantity_value,
            (float) $item->weight_needed,
            (float) $item->total_weight,
            (float) $prTotalKg,
            SpreadsheetCellSanitizer::text($item->remark),
            SpreadsheetCellSanitizer::text(StatusHelper::prLabel((string) $pr?->status)),
            $pr?->created_at ? BusinessTime::format($pr->created_at, 'Y-m-d H:i:s', false) : '-',
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

    public function progressTotalRows(): int
    {
        return $this->querySize();
    }

    public function headings(): array
    {
        if ($this->hasExportOptions()) {
            return $this->catalogHeadings();
        }

        return [__('exports.headings.pr_number'), __('exports.headings.period'), __('exports.headings.material_name'), __('exports.headings.specification'), __('exports.headings.qty'), __('exports.headings.weight_unit'), __('exports.headings.total_weight'), __('exports.headings.pr_total_kg'), __('exports.headings.remark'), __('exports.headings.status'), __('exports.headings.date_created').' ('.BusinessTime::label().')'];
    }

    public function columnWidths(): array
    {
        if ($this->hasExportOptions()) {
            return $this->catalogWidths();
        }

        return [
            'A' => 18,
            'B' => 16,
            'C' => 22,
            'D' => 24,
            'E' => 10,
            'F' => 14,
            'G' => 14,
            'H' => 14,
            'I' => 24,
            'J' => 18,
            'K' => 20,
        ];
    }

    protected function workbookPresentationEnabled(): bool
    {
        return $this->catalogStylesEnabled();
    }

    protected function workbookWrappedColumns(): array
    {
        return $this->hasExportOptions() ? $this->catalogWrappedColumns() : ['C', 'D', 'I', 'J'];
    }
}
