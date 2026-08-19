<?php

namespace App\Exports;

use App\Support\SupplierPriceHistoryBuilder;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SupplierPriceHistoryExport implements FromCollection, WithColumnWidths, WithHeadings, WithStyles, WithTitle
{
    public function __construct(
        private readonly int $supplierId,
        private readonly string $view,
        private readonly string $materialName,
        private readonly ?string $dateFromIso,
        private readonly array $dimensionFilters = [],
    ) {}

    public function collection(): Collection
    {
        $dateFrom = $this->dateFromIso !== null ? Carbon::parse($this->dateFromIso) : null;
        [, $data] = app(SupplierPriceHistoryBuilder::class)->build(
            $this->supplierId,
            $this->materialName,
            $this->view,
            $dateFrom,
            $this->dimensionFilters,
        );

        if ($this->view === 'yearly') {
            return $data->map(fn (array $row) => [
                $row['period'],
                $row['price_idr'],
                $row['min_idr'],
                $row['max_idr'],
                $row['change_pct'] !== null ? number_format($row['change_pct'], 2).'%' : '-',
            ]);
        }

        return $data->map(fn (array $row) => [
            $row['pr_number'] ?? '-',
            $row['submitted_at_display'] ?? 'Draft',
            $row['status_label'],
            $row['price_per_kg'],
            $row['currency'],
            $row['price_idr'],
            $row['change_pct'] !== null ? number_format($row['change_pct'], 2).'%' : '-',
        ]);
    }

    public function headings(): array
    {
        if ($this->view === 'yearly') {
            return [
                'Year',
                'Average Price (IDR/Kg)',
                'Lowest Price (IDR)',
                'Highest Price (IDR)',
                '% Change',
            ];
        }

        return [
            'No. PR',
            'Date Submitted',
            'Status',
            'Price/Kg',
            'Currency',
            'IDR Price',
            '% Change',
        ];
    }

    public function title(): string
    {
        return 'Price History - '.substr(str_replace(['/', '\\', '?', '*', ':', '[', ']'], '_', $this->materialName), 0, 15);
    }

    public function columnWidths(): array
    {
        if ($this->view === 'yearly') {
            return [
                'A' => 14,
                'B' => 24,
                'C' => 20,
                'D' => 20,
                'E' => 14,
            ];
        }

        return [
            'A' => 22,
            'B' => 20,
            'C' => 20,
            'D' => 16,
            'E' => 12,
            'F' => 20,
            'G' => 14,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1F5FA6'],
                ],
            ],
        ];
    }
}
