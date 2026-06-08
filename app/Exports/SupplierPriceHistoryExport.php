<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SupplierPriceHistoryExport implements FromCollection, WithHeadings, WithTitle, ShouldAutoSize, WithStyles
{
    private $data;
    private $view;
    private $materialName;

    public function __construct($data, $view, $materialName)
    {
        $this->data = $data;
        $this->view = $view;
        $this->materialName = $materialName;
    }

    public function collection()
    {
        if ($this->view === 'yearly') {
            return $this->data->map(function ($row) {
                return [
                    $row['period'],
                    $row['price_idr'],
                    $row['min_idr'],
                    $row['max_idr'],
                    $row['change_pct'] !== null ? number_format($row['change_pct'], 2) . '%' : '-',
                ];
            });
        }

        return $this->data->map(function ($row) {
            return [
                $row['pr_number'] ?? '-',
                $row['submitted_at_display'] ?? 'Draft',
                $row['status_label'],
                $row['price_per_kg'],
                $row['currency'],
                $row['price_idr'],
                $row['change_pct'] !== null ? number_format($row['change_pct'], 2) . '%' : '-',
            ];
        });
    }

    public function headings(): array
    {
        if ($this->view === 'yearly') {
            return [
                'Year',
                'Average Price (IDR/Kg)',
                'Lowest Price (IDR)',
                'Highest Price (IDR)',
                '% Change'
            ];
        }
        
        return [
            'No. PR',
            'Date Submitted',
            'Status',
            'Price/Kg',
            'Currency',
            'IDR Price',
            '% Change'
        ];
    }

    public function title(): string
    {
        return 'Price History - ' . substr(str_replace(['/', '\\', '?', '*', ':', '[', ']'], '_', $this->materialName), 0, 15);
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F5FA6']]],
        ];
    }
}
