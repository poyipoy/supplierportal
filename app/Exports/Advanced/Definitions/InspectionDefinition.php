<?php

namespace App\Exports\Advanced\Definitions;

use App\Exports\Advanced\ColumnType;
use App\Exports\Advanced\ExportColumn;
use App\Exports\Advanced\ExportDefinition;
use App\Exports\InspectionsExport;
use App\Http\Requests\Export\Filters\InspectionExportFilters;
use App\Models\User;
use App\Support\BusinessTime;

final class InspectionDefinition implements ExportDefinition
{
    private static ?array $catalog = null;

    public function __construct(private readonly string $exportKey) {}

    public function key(): string
    {
        return $this->exportKey;
    }

    public function exportClass(): string
    {
        return InspectionsExport::class;
    }

    public function authorize(User $user): bool
    {
        return $user->is_active && $user->role === 'qc';
    }

    public function filterSchema(): array
    {
        return InspectionExportFilters::schema();
    }

    public static function columns(): array
    {
        $audiences = ['qc'];

        return self::$catalog ??= [
            new ExportColumn('po_number', 'po_number', fn ($i) => $i->inspection?->purchaseOrder?->po_number, 22, required: true, audiences: $audiences, with: ['inspection.purchaseOrder']),
            new ExportColumn('supplier', 'supplier', fn ($i) => $i->inspection?->purchaseOrder?->supplier?->name, 25, audiences: $audiences, with: ['inspection.purchaseOrder.supplier']),
            new ExportColumn('material', 'material', fn ($i) => $i->prItem?->material_name, 30, audiences: $audiences, with: ['prItem']),
            new ExportColumn('requested_specification', 'requested_specification', fn ($i) => $i->prItem ? collect([$i->prItem->shape, $i->prItem->dimension_label !== '-' ? $i->prItem->dimension_label : null])->filter()->implode(' | ') : null, 38, audiences: $audiences, with: ['prItem']),
            new ExportColumn('actual_dimensions', 'actual_dimensions', fn ($i) => collect([$i->actual_thickness ? "T:{$i->actual_thickness}" : null, $i->actual_width ? "W:{$i->actual_width}" : null, $i->actual_length ? "L:{$i->actual_length}" : null])->filter()->implode(' | '), 34, audiences: $audiences),
            new ExportColumn('item_status', 'item_status', fn ($i) => strtoupper((string) $i->status), 15, audiences: $audiences),
            new ExportColumn('inspection_status', 'inspection_status', fn ($i) => strtoupper((string) $i->inspection?->status), 18, audiences: $audiences, with: ['inspection']),
            new ExportColumn('inspection_date', 'inspection_date', fn ($i) => $i->inspection?->inspected_at ? BusinessTime::format($i->inspection->inspected_at, 'd/m/Y H:i', false) : '-', 20, ColumnType::Date, audiences: $audiences, with: ['inspection'], headingSuffix: fn () => ' ('.BusinessTime::label().')'),
        ];
    }
}
