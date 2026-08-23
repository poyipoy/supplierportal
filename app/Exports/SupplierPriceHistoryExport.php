<?php

namespace App\Exports;

use App\Contracts\TracksExportProgress;
use App\Exports\Concerns\InteractsWithExportProgress;
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

class SupplierPriceHistoryExport implements FromCollection, TracksExportProgress, WithColumnWidths, WithHeadings, WithStyles, WithTitle
{
    use InteractsWithExportProgress;

    private ?Collection $cachedRows = null;

    public function __construct(
        private readonly int $supplierId,
        private readonly string $view,
        private readonly string $materialName,
        private readonly ?string $dateFromIso,
        private readonly array $dimensionFilters = [],
        private readonly ?string $currency = null,
    ) {}

    public function collection(): Collection
    {
        return $this->cachedRows ??= $this->buildRows();
    }

    private function buildRows(): Collection
    {
        $dateFrom = $this->dateFromIso !== null ? Carbon::parse($this->dateFromIso) : null;
        [, $data] = app(SupplierPriceHistoryBuilder::class)->build(
            $this->supplierId,
            $this->materialName,
            $this->view,
            $dateFrom,
            $this->dimensionFilters,
            $this->currency,
        );

        if ($this->view === 'yearly') {
            return $data->map(fn (array $row) => [
                $row['period'],
                $row['price_per_kg'],
                $row['min_price'],
                $row['max_price'],
                $row['currency'],
                $row['change_pct'] !== null ? number_format($row['change_pct'], 2).'%' : '-',
            ]);
        }

        return $data->map(fn (array $row) => [
            $row['pr_number'] ?? '-',
            $row['submitted_at_display'] ?? 'Draft',
            $row['status_label'],
            $row['price_per_kg'],
            $row['currency'],
            $row['change_pct'] !== null ? number_format($row['change_pct'], 2).'%' : '-',
        ]);
    }

    /**
     * Keep the in-process row cache out of every serialized queue-chain job.
     * Maatwebsite already serializes each data chunk separately.
     *
     * @return list<string>
     */
    public function __sleep(): array
    {
        return array_values(array_diff(array_keys(get_object_vars($this)), ['cachedRows']));
    }

    public function headings(): array
    {
        if ($this->view === 'yearly') {
            return [
                'Year',
                'Average Price/Kg',
                'Lowest Price/Kg',
                'Highest Price/Kg',
                'Currency',
                '% Change',
            ];
        }

        return [
            'No. PR',
            'PO Date',
            'Status',
            'Price/Kg',
            'Currency',
            '% Change',
        ];
    }

    public function progressTotalRows(): int
    {
        return $this->collection()->count();
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
                'E' => 12,
                'F' => 14,
            ];
        }

        return [
            'A' => 22,
            'B' => 20,
            'C' => 20,
            'D' => 16,
            'E' => 12,
            'F' => 14,
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
