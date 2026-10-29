<?php

namespace App\Exports;

use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\QuotationItem;
use App\Support\Materials\DimensionRange;
use App\Support\SpreadsheetCellSanitizer;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * GEN-03 Mode 1 (Gate 1 §3-F): the entered, unsaved quotation form in the
 * import template's columns, one row per PR item in PR item order. Material
 * and requested dimension come from the database, never the request. Writes
 * nothing; the controller downloads it directly.
 */
class QuotationFormExport extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithColumnFormatting, WithCustomValueBinder, WithHeadings
{
    /** @param array<int, array<string, mixed>> $items validated by ExportQuotationFormRequest */
    public function __construct(
        private readonly PurchaseRequisition $requisition,
        private readonly array $items,
    ) {}

    public function array(): array
    {
        $entered = collect($this->items)->keyBy(fn (array $row) => (int) $row['pr_item_id']);

        return $this->requisition->items->map(function (PrItem $prItem) use ($entered) {
            $row = $entered->get((int) $prItem->id, []);
            $available = QuotationItem::normalizeAvailabilityState($row['is_available'] ?? true);
            $cells = array_fill_keys(QuotationImportTemplateExport::CANONICAL_HEADINGS, null);
            $cells['pr_item_id'] = (int) $prItem->id;
            $cells['material_name'] = SpreadsheetCellSanitizer::text($prItem->material_name);
            $cells['requested_dimension'] = SpreadsheetCellSanitizer::text($prItem->dimension_label);
            $cells['availability'] = $available ? 'Available' : 'Not Available';
            if (! $available) {
                return array_values($cells);
            }

            $cells['available_qty'] = self::blank($row['available_qty'] ?? null) ? null : (int) $row['available_qty'];
            foreach (['thickness', 'width', 'd_outer', 'd_inner'] as $field) {
                $cells['available_'.$field] = self::decimal($row['available_'.$field] ?? null);
            }
            $length = DimensionRange::parse($row['available_length_input'] ?? ($row['available_length'] ?? null));
            $cells['available_length'] = match (true) {
                $length === null => null,
                $length->isRange() => $length->display(),
                default => round($length->exact, 4),
            };
            $cells['offered_weight_per_unit'] = self::decimal($row['offered_weight_per_unit'] ?? null);
            $cells['price_per_kg'] = self::decimal($row['price_per_kg'] ?? null);
            $notes = $row['notes'] ?? null;
            $cells['notes'] = self::blank($notes) ? null : SpreadsheetCellSanitizer::text($notes);

            return array_values($cells);
        })->all();
    }

    public function headings(): array
    {
        return QuotationImportTemplateExport::CANONICAL_HEADINGS;
    }

    public function columnFormats(): array
    {
        $formats = [];
        foreach (QuotationImportTemplateExport::CANONICAL_HEADINGS as $index => $heading) {
            $format = match ($heading) {
                'available_qty' => '#,##0',
                'available_thickness', 'available_width', 'available_d_outer', 'available_d_inner',
                'available_length', 'offered_weight_per_unit', 'price_per_kg' => '#,##0.0000',
                default => null,
            };
            if ($format !== null) {
                $formats[chr(ord('A') + $index)] = $format;
            }
        }

        return $formats;
    }

    /** Strings stay text (ranges, notes, names); numbers stay numeric. */
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    private static function decimal(mixed $value): ?float
    {
        return self::blank($value) ? null : round((float) $value, 4);
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
