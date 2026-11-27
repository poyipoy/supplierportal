<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class HistoricalBenchmarkTemplateExport implements WithMultipleSheets
{
    public const DEFAULT_SHEET_NAME = 'Historical_Benchmark';

    public function sheets(): array
    {
        return [
            new class implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithTitle
            {
                public function title(): string
                {
                    return HistoricalBenchmarkTemplateExport::DEFAULT_SHEET_NAME;
                }

                public function headings(): array
                {
                    return [
                        'po_number',
                        'po_date',
                        'supplier_name',
                        'material_code',
                        'shape',
                        'key_dimension',
                        'ordered_weight_kg',
                        'currency',
                        'price_per_kg',
                        'line_no',
                        'pr_number',
                        'notes',
                    ];
                }

                public function array(): array
                {
                    return [
                        [
                            'PO-HIST-2024-001',
                            '2024-06-15',
                            'DAIDO STEEL CO., LTD.',
                            'SKD11',
                            'Flat',
                            '12.5000',
                            '1500.0000',
                            'USD',
                            '4.8500',
                            1,
                            'PR-HIST-2024-010',
                            'Sample pre-portal historical flat bar purchase',
                        ],
                        [
                            'PO-HIST-2024-002',
                            '2024-09-20',
                            'HITACHI METALS LTD.',
                            'SKD61',
                            'Round',
                            '50.0000',
                            '800.0000',
                            'JPY',
                            '720.0000',
                            1,
                            '',
                            'Sample pre-portal historical round bar purchase',
                        ],
                    ];
                }

                public function columnFormats(): array
                {
                    return [
                        'A' => NumberFormat::FORMAT_TEXT,
                        'B' => NumberFormat::FORMAT_TEXT,
                        'D' => NumberFormat::FORMAT_TEXT,
                        'K' => NumberFormat::FORMAT_TEXT,
                    ];
                }
            },
            new class implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
            {
                public function title(): string
                {
                    return 'Instructions';
                }

                public function headings(): array
                {
                    return [
                        'Column',
                        'Required',
                        'Accepted Values / Format',
                        'Description',
                    ];
                }

                public function array(): array
                {
                    return [
                        ['po_number', 'Yes', 'Text (max 100 chars)', 'Historical Purchase Order number. Must not collide with an active Portal PO number.'],
                        ['po_date', 'Yes', 'YYYY-MM-DD', 'Historical PO date in Asia/Jakarta calendar (not in the future, year >= 2000).'],
                        ['supplier_name', 'Yes', 'Text (max 255 chars)', 'Supplier company name. Auto-links to registered Import Supplier if exact company name matches.'],
                        ['material_code', 'Yes', 'Active Material Master code/alias', 'Must match an active Material Master code or registered alias exactly.'],
                        ['shape', 'Yes', 'Flat | Round | Hollow', 'Material shape. Determines key_dimension meaning (Flat = thickness, Round/Hollow = outer diameter).'],
                        ['key_dimension', 'Yes', 'Positive decimal in mm (> 0)', 'Thickness (mm) for Flat; Outer Diameter (mm) for Round or Hollow. Rounded up via CEIL() for rule matching.'],
                        ['ordered_weight_kg', 'Yes', 'Positive decimal in kg (> 0)', 'Ordered weight in kilograms used as weight in KG-weighted average benchmark calculation.'],
                        ['currency', 'Yes', 'IDR | USD | JPY | CNY', 'Original transaction currency. Converted dynamically via Annual Exchange Rates by PO year.'],
                        ['price_per_kg', 'Yes', 'Positive decimal (> 0)', 'Unit price per kilogram in the original transaction currency.'],
                        ['line_no', 'No', 'Whole integer >= 1 (default: 1)', 'Line sequence number to distinguish multiple lines with identical material, shape, and key_dimension on the same PO.'],
                        ['pr_number', 'No', 'Text (max 100 chars)', 'Optional historical Purchase Requisition number for reference drilldown.'],
                        ['notes', 'No', 'Text (max 1000 chars)', 'Optional internal reference or contract note.'],
                    ];
                }
            },
        ];
    }
}

