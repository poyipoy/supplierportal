<?php

namespace App\Exports\Advanced\Definitions;

use App\Exports\Advanced\ColumnType;
use App\Exports\Advanced\ExportColumn;
use App\Exports\Advanced\ExportDefinition;
use App\Exports\PurchasingHistoricalPriceExport;
use App\Http\Requests\Export\Filters\PurchasingPriceHistoryExportFilters;
use App\Models\User;

final class PurchasingPriceHistoryDefinition implements ExportDefinition
{
    private static ?array $catalog = null;

    public function __construct(private readonly string $exportKey) {}

    public function key(): string
    {
        return $this->exportKey;
    }

    public function exportClass(): string
    {
        return PurchasingHistoricalPriceExport::class;
    }

    public function authorize(User $user): bool
    {
        return $user->is_active && $user->role === 'purchasing';
    }

    public function filterSchema(): array
    {
        return PurchasingPriceHistoryExportFilters::schema();
    }

    public static function columns(): array
    {
        $audiences = ['purchasing'];

        return self::$catalog ??= [
            new ExportColumn('material_name', 'material_name', fn (array $row) => $row['material_name'] ?? '-', 24, ColumnType::Text, default: true, required: true, audiences: $audiences, wrapText: true),
            new ExportColumn('shape', 'shape', fn (array $row) => $row['shape'] ?? '-', 12, ColumnType::Text, default: true, audiences: $audiences),
            new ExportColumn('period', 'period', fn (array $row) => $row['period'] ?? '-', 14, ColumnType::Text, default: true, audiences: $audiences),
            new ExportColumn('po_date', 'po_date', fn (array $row) => $row['po_date'] ?? '-', 14, ColumnType::Date, default: true, audiences: $audiences),
            new ExportColumn('pr_number', 'pr_number', fn (array $row) => $row['pr_number'] ?? '-', 20, ColumnType::Text, default: true, audiences: $audiences),
            new ExportColumn('po_number', 'po_number', fn (array $row) => $row['po_number'] ?? '-', 20, ColumnType::Text, default: true, audiences: $audiences),
            new ExportColumn('dimensions', 'dimensions', fn (array $row) => $row['dimensions'] ?? '-', 22, ColumnType::Text, default: true, audiences: $audiences),
            new ExportColumn('category_name', 'category_name', fn (array $row) => $row['category_name'] ?? '-', 20, ColumnType::Text, default: true, audiences: $audiences, wrapText: true),
            new ExportColumn('supplier', 'supplier', fn (array $row) => $row['supplier'] ?? '-', 24, ColumnType::Text, default: true, audiences: $audiences, wrapText: true),
            new ExportColumn('po_count', 'po_count', fn (array $row) => $row['po_count'] ?? null, 12, ColumnType::Number, default: true, audiences: $audiences),
            new ExportColumn('ordered_weight', 'ordered_weight', fn (array $row) => $row['ordered_weight'] ?? null, 16, ColumnType::Number, default: true, audiences: $audiences),
            new ExportColumn('source_currency', 'source_currency', fn (array $row) => $row['source_currency'] ?? '-', 12, ColumnType::Text, default: true, audiences: $audiences),
            new ExportColumn('source_price_per_kg', 'source_price_per_kg', fn (array $row) => $row['source_price_per_kg'] ?? null, 18, ColumnType::Money, default: true, audiences: $audiences),
            new ExportColumn('annual_rate_year', 'annual_rate_year', fn (array $row) => $row['annual_rate_year'] ?? null, 14, ColumnType::Number, default: true, audiences: $audiences),
            new ExportColumn('target_currency', 'target_currency', fn (array $row) => $row['target_currency'] ?? '-', 14, ColumnType::Text, default: true, audiences: $audiences),
            new ExportColumn('converted_price_per_kg', 'converted_price_per_kg', fn (array $row) => $row['converted_price_per_kg'] ?? null, 20, ColumnType::Money, default: true, audiences: $audiences),
            new ExportColumn('lowest_price_kg', 'lowest_price_kg', fn (array $row) => $row['lowest_price_kg'] ?? null, 18, ColumnType::Money, default: true, audiences: $audiences),
            new ExportColumn('highest_price_kg', 'highest_price_kg', fn (array $row) => $row['highest_price_kg'] ?? null, 18, ColumnType::Money, default: true, audiences: $audiences),
            new ExportColumn('converted_total', 'converted_total', fn (array $row) => $row['converted_total'] ?? null, 20, ColumnType::Money, default: true, audiences: $audiences),
            new ExportColumn('period_change_pct', 'change', fn (array $row) => $row['period_change_pct'] ?? null, 14, ColumnType::Number, default: true, audiences: $audiences),
        ];
    }
}
