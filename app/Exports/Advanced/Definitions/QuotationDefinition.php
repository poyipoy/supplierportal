<?php

namespace App\Exports\Advanced\Definitions;

use App\Exports\Advanced\ColumnType;
use App\Exports\Advanced\ExportColumn;
use App\Exports\Advanced\ExportDefinition;
use App\Exports\QuotationsExport;
use App\Http\Requests\Export\Filters\QuotationExportFilters;
use App\Models\User;
use App\Support\BusinessTime;

final class QuotationDefinition implements ExportDefinition
{
    private static ?array $catalog = null;

    public function __construct(private readonly string $exportKey) {}

    public function key(): string
    {
        return $this->exportKey;
    }

    public function exportClass(): string
    {
        return QuotationsExport::class;
    }

    public function authorize(User $user): bool
    {
        return $user->is_active && ($this->exportKey === 'supplier.quotations' ? $user->isImportEligible() : $user->role === 'purchasing');
    }

    public function filterSchema(): array
    {
        return QuotationExportFilters::schema($this->exportKey === 'supplier.quotations');
    }

    public static function columns(): array
    {
        return self::$catalog ??= [
            new ExportColumn('pr_number', 'pr_number', fn ($i) => $i->quotation?->purchaseRequisition?->pr_number, 18, required: true, with: ['quotation.purchaseRequisition']),
            new ExportColumn('period', 'period', fn ($i) => $i->quotation?->purchaseRequisition?->period?->display_label, 16, with: ['quotation.purchaseRequisition.period']),
            new ExportColumn('supplier', 'supplier', fn ($i) => $i->quotation?->supplier?->supplier?->company_name ?: $i->quotation?->supplier?->name, 22, with: ['quotation.supplier.supplier'], wrapText: true),
            new ExportColumn('currency', 'currency', fn ($i) => strtoupper((string) $i->quotation?->currency), 10, with: ['quotation']),
            new ExportColumn('material', 'material', fn ($i) => $i->prItem?->material_name, 22, with: ['prItem'], wrapText: true),
            new ExportColumn('hs_code', 'hs_code', fn ($i) => $i->prItem?->hs_code, 14, with: ['prItem']),
            new ExportColumn('requested_quantity', 'requested_quantity', fn ($i) => $i->prItem?->quantity_value, 12, ColumnType::Number, with: ['prItem']),
            new ExportColumn('requested_dimensions', 'requested_dimensions', fn ($i) => $i->prItem?->dimension_label !== '-' ? $i->prItem?->dimension_label : null, 24, with: ['prItem'], wrapText: true),
            new ExportColumn('offered_quantity', 'offered_quantity', fn ($i) => $i->available_qty, 12, ColumnType::Number),
            new ExportColumn('offered_dimensions', 'offered_dimensions', fn ($i) => $i->available_dimension_label !== '-' ? $i->available_dimension_label : null, 24, with: ['prItem'], wrapText: true),
            new ExportColumn('price_per_kg', 'price_per_kg', fn ($i) => $i->price_per_kg === null ? null : (float) $i->price_per_kg, 18, ColumnType::Money),
            new ExportColumn('amount', 'amount', fn ($i) => $i->offer_amount ?? 0.0, 18, ColumnType::Money, with: ['prItem']),
            new ExportColumn('exchange_rate', 'exchange_rate', fn ($i) => (float) ($i->quotation?->exchange_rate?->rate_to_idr ?? 0), 16, ColumnType::Number, with: ['quotation.exchange_rate']),
            new ExportColumn('total_idr', 'total_idr', fn ($i) => $i->offer_amount === null ? null : $i->offer_amount * (float) ($i->quotation?->exchange_rate?->rate_to_idr ?? 0), 18, ColumnType::Money, with: ['prItem', 'quotation.exchange_rate']),
            new ExportColumn('item_notes', 'item_notes', fn ($i) => $i->notes, 24, wrapText: true),
            new ExportColumn('status', 'status', fn ($i) => $i->quotation?->statusLabel(), 18, with: ['quotation'], wrapText: true),
            new ExportColumn('submitted_at', 'submitted_at', fn ($i) => $i->quotation?->submitted_at ? BusinessTime::format($i->quotation->submitted_at, 'Y-m-d H:i:s', false) : '-', 20, ColumnType::Date, with: ['quotation'], headingSuffix: fn () => ' ('.BusinessTime::label().')'),
            new ExportColumn('availability', 'availability', fn ($i) => $i->is_available ? __('status.availability.available') : __('status.availability.not_available'), 18),
            new ExportColumn('offered_length', 'offered_length', fn ($i) => $i->available_length_display !== '-' ? $i->available_length_display : null, 16, ColumnType::Number),
            new ExportColumn('offer_weight_unit', 'offer_weight_unit', fn ($i) => $i->offered_weight_per_unit === null ? null : (float) $i->offered_weight_per_unit, 16, ColumnType::Number),
            new ExportColumn('offer_weight_source', 'offer_weight_source', fn ($i) => $i->offered_weight_source, 18),
            new ExportColumn('offer_total_weight', 'offer_total_weight', fn ($i) => $i->offered_total_weight, 16, ColumnType::Number, with: ['prItem']),
            new ExportColumn('requested_amount', 'requested_amount', fn ($i) => $i->requested_amount, 18, ColumnType::Money, with: ['prItem']),
            new ExportColumn('offer_amount', 'offer_amount', fn ($i) => $i->offer_amount, 18, ColumnType::Money, with: ['prItem']),
        ];
    }
}
