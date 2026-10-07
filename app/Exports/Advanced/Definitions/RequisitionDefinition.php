<?php

namespace App\Exports\Advanced\Definitions;

use App\Exports\Advanced\ColumnType;
use App\Exports\Advanced\ExportColumn;
use App\Exports\Advanced\ExportDefinition;
use App\Exports\RequisitionsExport;
use App\Http\Requests\Export\Filters\RequisitionExportFilters;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\SpreadsheetCellSanitizer;
use App\Support\StatusHelper;

final class RequisitionDefinition implements ExportDefinition
{
    private static ?array $catalog = null;

    public function __construct(private readonly string $exportKey) {}

    public function key(): string
    {
        return $this->exportKey;
    }

    public function exportClass(): string
    {
        return RequisitionsExport::class;
    }

    public function authorize(User $user): bool
    {
        return $user->is_active && $user->role === 'purchasing';
    }

    public function filterSchema(): array
    {
        return RequisitionExportFilters::schema();
    }

    public static function columns(): array
    {
        $audiences = ['purchasing'];

        return self::$catalog ??= [
            new ExportColumn('pr_number', 'pr_number', fn ($i) => SpreadsheetCellSanitizer::text($i->purchaseRequisition?->pr_number, __('status.pr.draft')), 22, required: true, audiences: $audiences, with: ['purchaseRequisition']),
            new ExportColumn('period', 'period', fn ($i) => $i->purchaseRequisition?->period?->display_label, 18, audiences: $audiences, with: ['purchaseRequisition.period']),
            new ExportColumn('material_name', 'material_name', fn ($i) => $i->material_name, 30, audiences: $audiences),
            new ExportColumn('specification', 'specification', fn ($i) => collect([$i->shape, $i->dimension_label !== '-' ? $i->dimension_label : null])->filter()->implode(' | '), 38, audiences: $audiences),
            new ExportColumn('qty', 'qty', fn ($i) => $i->quantity_value, 10, ColumnType::Number, audiences: $audiences),
            new ExportColumn('weight_unit', 'weight_unit', fn ($i) => (float) $i->weight_needed, 15, ColumnType::Number, audiences: $audiences),
            new ExportColumn('total_weight', 'total_weight', fn ($i) => (float) $i->total_weight, 15, ColumnType::Number, audiences: $audiences),
            new ExportColumn('pr_total_kg', 'pr_total_kg', fn ($i) => (float) ($i->purchaseRequisition?->items?->sum(fn ($r) => $r->total_weight) ?? 0), 15, ColumnType::Number, audiences: $audiences, with: ['purchaseRequisition.items']),
            new ExportColumn('remark', 'remark', fn ($i) => $i->remark, 30, audiences: $audiences),
            new ExportColumn('status', 'status', fn ($i) => StatusHelper::prLabel((string) $i->purchaseRequisition?->status), 15, audiences: $audiences, with: ['purchaseRequisition']),
            new ExportColumn('date_created', 'date_created', fn ($i) => $i->purchaseRequisition?->created_at ? BusinessTime::format($i->purchaseRequisition->created_at, 'Y-m-d H:i:s', false) : '-', 21, ColumnType::Date, audiences: $audiences, with: ['purchaseRequisition'], headingSuffix: fn () => ' ('.BusinessTime::label().')'),
        ];
    }
}
