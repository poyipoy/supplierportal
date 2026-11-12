<?php

namespace App\Exports;

use App\Models\MaterialMaster;
use App\Support\SpreadsheetCellSanitizer;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class PrFormExport extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithColumnFormatting, WithCustomValueBinder, WithHeadings
{
    public const HEADINGS = [
        'material_name',
        'shape',
        'thickness',
        'width',
        'd_outer',
        'd_inner',
        'length',
        'quantity',
        'remark',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function __construct(
        private readonly array $items,
    ) {}

    public function array(): array
    {
        $masterIds = collect($this->items)
            ->pluck('material_master_id')
            ->filter(fn ($id) => ! empty($id) && is_numeric($id))
            ->unique();

        $masters = $masterIds->isNotEmpty()
            ? MaterialMaster::whereIn('id', $masterIds)->pluck('material_code', 'id')
            : collect();

        return collect($this->items)->map(function (array $row) use ($masters) {
            $masterId = $row['material_master_id'] ?? null;
            $materialName = $row['material_name'] ?? null;
            if (empty($materialName) && ! empty($masterId) && isset($masters[$masterId])) {
                $materialName = $masters[$masterId];
            }

            return [
                'material_name' => SpreadsheetCellSanitizer::text($materialName ?? '', ''),
                'shape' => SpreadsheetCellSanitizer::text($row['shape'] ?? '', ''),
                'thickness' => self::decimal($row['thickness'] ?? null),
                'width' => self::decimal($row['width'] ?? null),
                'd_outer' => self::decimal($row['d_outer'] ?? null),
                'd_inner' => self::decimal($row['d_inner'] ?? null),
                'length' => self::decimal($row['length'] ?? null),
                'quantity' => self::integer($row['quantity'] ?? null),
                'remark' => self::blank($row['remark'] ?? null) ? null : SpreadsheetCellSanitizer::text($row['remark']),
            ];
        })->map(fn ($row) => array_values($row))->all();
    }

    public function headings(): array
    {
        return self::HEADINGS;
    }

    public function columnFormats(): array
    {
        return [
            'C' => '#,##0.0000',
            'D' => '#,##0.0000',
            'E' => '#,##0.0000',
            'F' => '#,##0.0000',
            'G' => '#,##0.0000',
            'H' => '#,##0',
        ];
    }

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

    private static function integer(mixed $value): ?int
    {
        return self::blank($value) ? null : (int) $value;
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim((string) $value) === '');
    }
}
