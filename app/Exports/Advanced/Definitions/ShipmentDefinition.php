<?php

namespace App\Exports\Advanced\Definitions;

use App\Exports\Advanced\ColumnType;
use App\Exports\Advanced\ExportColumn;
use App\Exports\Advanced\ExportDefinition;
use App\Exports\ShipmentsExport;
use App\Http\Requests\Export\Filters\ShipmentExportFilters;
use App\Models\User;
use App\Support\NumberFormat;
use App\Support\StatusHelper;

final class ShipmentDefinition implements ExportDefinition
{
    private static ?array $catalog = null;

    public function __construct(private readonly string $exportKey) {}

    public function key(): string
    {
        return $this->exportKey;
    }

    public function exportClass(): string
    {
        return ShipmentsExport::class;
    }

    public function authorize(User $user): bool
    {
        return $user->is_active && $user->role === 'purchasing';
    }

    public function filterSchema(): array
    {
        return ShipmentExportFilters::schema();
    }

    public static function columns(): array
    {
        $audiences = ['purchasing'];

        return self::$catalog ??= [
            new ExportColumn('shipment_number', 'shipment_number', fn ($s) => $s->shipment_number, 20, required: true, audiences: $audiences),
            new ExportColumn('supplier', 'supplier', fn ($s) => $s->supplier?->name ?? '-', 26, audiences: $audiences, with: ['supplier']),
            new ExportColumn('consolidated_pos', 'consolidated_pos', fn ($s) => $s->purchaseOrders()->pluck('po_number')->unique()->implode(', ') ?: '-', 30, audiences: $audiences, with: ['items.purchaseOrder']),
            new ExportColumn('items_count', 'items_count', fn ($s) => $s->items->count(), 14, ColumnType::Number, audiences: $audiences, with: ['items']),
            new ExportColumn('total_qty', 'total_qty', fn ($s) => (int) $s->items->sum('shipped_qty'), 14, ColumnType::Number, audiences: $audiences, with: ['items']),
            new ExportColumn('actual_weight_kg', 'actual_weight_kg', fn ($s) => NumberFormat::maxDecimals((float) $s->items->sum('actual_weight_kg')), 20, ColumnType::Number, audiences: $audiences, with: ['items']),
            new ExportColumn('shipment_date', 'shipment_date', fn ($s) => $s->shipment_date?->format('Y-m-d') ?? '-', 16, ColumnType::Date, audiences: $audiences),
            new ExportColumn('est_arrival_date', 'est_arrival_date', fn ($s) => $s->estimated_arrival_date?->format('Y-m-d') ?? '-', 18, ColumnType::Date, audiences: $audiences),
            new ExportColumn('actual_arrival_date', 'actual_arrival_date', fn ($s) => $s->actual_arrival_date?->format('Y-m-d') ?? '-', 18, ColumnType::Date, audiences: $audiences),
            new ExportColumn('status', 'status', fn ($s) => StatusHelper::shipmentLabel($s->status), 16, audiences: $audiences),
            new ExportColumn('notes_remarks', 'notes_remarks', fn ($s) => $s->notes ?? '-', 32, audiences: $audiences),
        ];
    }
}
