<?php

namespace App\Exports\Advanced\Definitions;

use App\Exports\Advanced\ColumnType;
use App\Exports\Advanced\ExportColumn;
use App\Exports\Advanced\ExportDefinition;
use App\Exports\ShipmentsExport;
use App\Http\Requests\Export\Filters\ShipmentExportFilters;
use App\Models\User;
use App\Support\CountryCatalog;
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
        return $user->is_active && match ($this->exportKey) {
            'purchasing.shipments' => $user->role === 'purchasing',
            'supplier.shipments' => $user->isImportEligible(),
            default => false,
        };
    }

    public function filterSchema(): array
    {
        return ShipmentExportFilters::schema($this->exportKey === 'supplier.shipments');
    }

    public static function columns(): array
    {
        $both = ['purchasing', 'supplier'];

        return self::$catalog ??= [
            new ExportColumn('shipment_number', 'shipment_number', fn ($s) => $s->shipment_number, 18, required: true, audiences: $both),
            new ExportColumn('supplier', 'supplier', fn ($s) => $s->supplier?->name ?? '-', 22, audiences: ['purchasing'], with: ['supplier'], wrapText: true),
            new ExportColumn('consolidated_pos', 'consolidated_pos', fn ($s) => $s->purchaseOrders()->pluck('po_number')->unique()->implode(', ') ?: '-', 24, audiences: $both, with: ['items.purchaseOrder'], wrapText: true),
            new ExportColumn('items_count', 'items_count', fn ($s) => $s->items->count(), 10, ColumnType::Number, audiences: $both, with: ['items']),
            new ExportColumn('total_qty', 'total_qty', fn ($s) => (int) $s->items->sum('shipped_qty'), 10, ColumnType::Number, audiences: $both, with: ['items']),
            new ExportColumn('actual_weight_kg', 'actual_weight_kg', fn ($s) => NumberFormat::maxDecimals((float) $s->items->sum('actual_weight_kg')), 16, ColumnType::Number, audiences: $both, with: ['items']),
            new ExportColumn('shipment_date', 'shipment_date', fn ($s) => $s->shipment_date?->format('Y-m-d') ?? '-', 14, ColumnType::Date, audiences: $both),
            new ExportColumn('est_arrival_date', 'est_arrival_date', fn ($s) => $s->estimated_arrival_date?->format('Y-m-d') ?? '-', 14, ColumnType::Date, audiences: $both),
            new ExportColumn('actual_arrival_date', 'actual_arrival_date', fn ($s) => $s->actual_arrival_date?->format('Y-m-d') ?? '-', 14, ColumnType::Date, audiences: $both),
            new ExportColumn('status', 'status', fn ($s) => StatusHelper::shipmentLabel($s->status), 18, audiences: $both, wrapText: true),
            new ExportColumn('notes_remarks', 'notes_remarks', fn ($s) => $s->notes ?? '-', 24, audiences: $both, wrapText: true),
            new ExportColumn('invoice_number', 'invoice_number', fn ($s) => $s->invoice_number ?? '-', 18, default: false, audiences: $both),
            new ExportColumn('supplier_reference', 'supplier_reference', fn ($s) => $s->supplier_reference ?? '-', 20, default: false, audiences: $both),
            new ExportColumn('missing_documents', 'missing_documents', fn ($s) => implode(', ', $s->missingDocumentsSummary()['missing_labels']) ?: __('shipments.copy.all_documents_complete'), 28, default: false, audiences: $both, with: ['documents.latestAttachment', 'mtcAttachments'], wrapText: true),
            new ExportColumn('psi_exporter_name', 'psi_exporter_name', fn ($s) => $s->preshipmentInspection?->exporter_name ?? '-', 24, default: false, audiences: $both, with: ['preshipmentInspection'], wrapText: true),
            new ExportColumn('psi_exporter_country', 'psi_exporter_country', fn ($s) => CountryCatalog::label($s->preshipmentInspection?->exporter_country), 16, default: false, audiences: $both, with: ['preshipmentInspection']),
            new ExportColumn('psi_port_of_loading', 'psi_port_of_loading', fn ($s) => $s->preshipmentInspection?->exporter_port_of_loading ?? '-', 20, default: false, audiences: $both, with: ['preshipmentInspection'], wrapText: true),
            new ExportColumn('psi_location_name', 'psi_location_name', fn ($s) => $s->preshipmentInspection?->supplier_name ?? '-', 24, default: false, audiences: $both, with: ['preshipmentInspection'], wrapText: true),
            new ExportColumn('psi_location_city', 'psi_location_city', fn ($s) => $s->preshipmentInspection?->supplier_city ?? '-', 18, default: false, audiences: $both, with: ['preshipmentInspection']),
            new ExportColumn('psi_location_country', 'psi_location_country', fn ($s) => CountryCatalog::label($s->preshipmentInspection?->supplier_country), 16, default: false, audiences: $both, with: ['preshipmentInspection']),
            new ExportColumn('psi_same_as_exporter', 'psi_same_as_exporter', fn ($s) => $s->preshipmentInspection ? ($s->preshipmentInspection->supplier_same_as_exporter ? __('exports.advanced.enabled') : __('exports.advanced.disabled')) : '-', 16, default: false, audiences: $both, with: ['preshipmentInspection']),
        ];
    }
}
