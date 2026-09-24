<?php

namespace App\Exports;

use App\Models\LocalInvoice;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Support\SpreadsheetCellSanitizer;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

class PaymentBatchDrpSheetRenderer
{
    public const INDONESIAN_MONTHS = [
        1 => 'JANUARI',
        2 => 'FEBRUARI',
        3 => 'MARET',
        4 => 'APRIL',
        5 => 'MEI',
        6 => 'JUNI',
        7 => 'JULI',
        8 => 'AGUSTUS',
        9 => 'SEPTEMBER',
        10 => 'OKTOBER',
        11 => 'NOVEMBER',
        12 => 'DESEMBER',
    ];

    public const NOMINAL_FORMAT = '_ * #,##0_ ;_ * \-#,##0_ ;_ * "-"??_ ;_ @_ ';

    /**
     * Render the given payment batches into a PhpSpreadsheet workbook using the DRP template.
     *
     * @param  Collection<int, PaymentBatch>  $batches
     */
    public function render(Collection $batches, ?string $templatePath = null): Spreadsheet
    {
        $templatePath = $templatePath ?? resource_path('templates/drp/DRP ADASI.xlsx');

        if (! file_exists($templatePath)) {
            throw new RuntimeException("DRP template file not found at: {$templatePath}");
        }

        if ($batches->isEmpty()) {
            throw new RuntimeException('No batches provided for DRP export.');
        }

        $spreadsheet = IOFactory::load($templatePath);
        $baseTemplateSheet = $spreadsheet->getSheetByName('01');

        if ($baseTemplateSheet === null) {
            $baseTemplateSheet = $spreadsheet->getActiveSheet();
        }

        // Keep a pristine clone of the base template worksheet for cloning multiple sheets
        $prototypeSheet = clone $baseTemplateSheet;

        // Remove any other original template sheets (e.g. 02, 03, 04, 05, 09) to avoid name collisions
        foreach ($spreadsheet->getSheetNames() as $existingSheetName) {
            if ($existingSheetName !== $baseTemplateSheet->getTitle()) {
                $toRemove = $spreadsheet->getSheetByName($existingSheetName);
                if ($toRemove !== null) {
                    $spreadsheet->removeSheetByIndex($spreadsheet->getIndex($toRemove));
                }
            }
        }

        foreach ($batches->values() as $index => $batch) {
            $sheetName = sprintf('%02d', $index + 1);

            if ($index === 0) {
                $sheet = $baseTemplateSheet;
                $sheet->setTitle($sheetName);
            } else {
                $sheet = clone $prototypeSheet;
                $sheet->setTitle($sheetName);
                $spreadsheet->addSheet($sheet);
            }

            $this->populateSheet($sheet, $batch);
        }

        // Set the first sheet as active
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * Populate a single worksheet with a PaymentBatch's data.
     */
    public function populateSheet(Worksheet $sheet, PaymentBatch $batch): void
    {
        // 1. Headers
        $sheet->setCellValue('D1', '  PT ASTRA DAIDO STEEL INDONESIA ');
        $sheet->setCellValue('D2', '  REKAP PEMBAYARAN SUPPLIER');

        $createdDate = $batch->created_at ?? now();
        $monthNum = (int) $createdDate->format('n');
        $monthName = self::INDONESIAN_MONTHS[$monthNum] ?? strtoupper($createdDate->format('F'));
        $sheet->setCellValue('D3', $monthName);

        $day = (int) $createdDate->format('j');
        $week = (int) ceil($day / 7);
        $dateStr = $createdDate->format('d/m/Y');
        $weekLine = "MINGGU KE -{$week}  ({$dateStr} )";
        $sheet->setCellValue('D5', $weekLine);

        // 2. Prepare Data Rows
        $dataRows = $this->buildDataRows($batch);

        if (empty($dataRows)) {
            throw new RuntimeException("Batch {$batch->batch_number} contains no active payment items for export.");
        }

        $rowCount = count($dataRows);

        // Template has 2 initial data rows (row 8 and row 9). Total row starts at row 10.
        if ($rowCount === 1) {
            $sheet->removeRow(9, 1);
        } elseif ($rowCount > 2) {
            $sheet->insertNewRowBefore(10, $rowCount - 2);
        }

        $borderStyle = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ];

        // 3. Write Data Rows
        for ($i = 0; $i < $rowCount; $i++) {
            $r = 8 + $i;
            $row = $dataRows[$i];

            $sheet->getRowDimension($r)->setRowHeight(30);
            $sheet->getStyle("A{$r}:L{$r}")->applyFromArray($borderStyle);

            // Column A: NO
            if ($row['no'] !== null) {
                $sheet->setCellValue("A{$r}", $row['no']);
                $sheet->getStyle("A{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            } else {
                $sheet->setCellValue("A{$r}", '');
            }

            // Column B: KODE (GAP-01 blank)
            $sheet->setCellValue("B{$r}", $row['kode'] ?? '');

            // Column C: REFF (GAP-02 blank)
            $sheet->setCellValue("C{$r}", $row['reff'] ?? '');

            // Column D: NAMA SUPPLIER
            $sheet->setCellValue("D{$r}", $row['supplier'] ?? '');
            $sheet->getStyle("D{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            // Column E: INVOICE
            $sheet->setCellValue("E{$r}", $row['invoice'] ?? '');
            $sheet->getStyle("E{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            // Column F: BANK
            $sheet->setCellValue("F{$r}", $row['bank'] ?? '');
            $sheet->getStyle("F{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Column G: NO ACCOUNT (Must be explicit string to preserve leading zeroes)
            if (! empty($row['account'])) {
                $sheet->setCellValueExplicit("G{$r}", (string) $row['account'], DataType::TYPE_STRING);
            } else {
                $sheet->setCellValue("G{$r}", '');
            }
            $sheet->getStyle("G{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            // Column H: NAMA PENERIMA
            $sheet->setCellValue("H{$r}", $row['payee'] ?? '');
            $sheet->getStyle("H{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            // Column I: NOMINAL (Subtotal amount - gross, NOT net)
            if ($row['nominal'] !== null) {
                $sheet->setCellValueExplicit("I{$r}", $row['nominal'], DataType::TYPE_NUMERIC);
                $sheet->getStyle("I{$r}")->getNumberFormat()->setFormatCode(self::NOMINAL_FORMAT);
                $sheet->getStyle("I{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            } else {
                $sheet->setCellValue("I{$r}", '');
            }

            // Columns J, K, L: CEK, INPUT, APPROVE (blank)
            $sheet->setCellValue("J{$r}", '');
            $sheet->setCellValue("K{$r}", '');
            $sheet->setCellValue("L{$r}", '');
        }

        // 4. Update TOTAL PEMBAYARAN row
        $totalRow = 8 + $rowCount;
        $lastDataRow = 8 + $rowCount - 1;

        $sheet->setCellValue("E{$totalRow}", 'TOTAL PEMBAYARAN ');
        $sheet->setCellValue("I{$totalRow}", "=SUM(I8:I{$lastDataRow})");
        $sheet->getStyle("I{$totalRow}")->getNumberFormat()->setFormatCode(self::NOMINAL_FORMAT);
    }

    /**
     * Build the raw row datasets for a batch, handling multi-invoice continuation rows.
     *
     * @return list<array<string, mixed>>
     */
    public function buildDataRows(PaymentBatch $batch): array
    {
        $rows = [];
        $groupNumber = 1;

        // Filter out cancelled groups
        $activeGroups = $batch->groups
            ->filter(fn ($group) => $group->status !== PaymentGroup::STATUS_CANCELLED)
            ->sortBy('id');

        foreach ($activeGroups as $group) {
            // Include only active local invoice items
            $activeItems = $group->items
                ->filter(fn ($item) => $item->status === PaymentItem::STATUS_ACTIVE && $item->payable_type === LocalInvoice::class)
                ->sortBy('id');

            if ($activeItems->isEmpty()) {
                continue;
            }

            $invoiceNumbers = [];
            foreach ($activeItems as $item) {
                $ref = $item->item_reference ?? ($item->payable?->invoice_number ?? '');
                if ($ref !== '') {
                    $invoiceNumbers[] = $ref;
                }
            }

            $invoiceLines = self::formatInvoiceLines($invoiceNumbers);

            // Primary Row
            $rows[] = [
                'no' => $groupNumber,
                'kode' => null,
                'reff' => null,
                'supplier' => SpreadsheetCellSanitizer::text($group->payee_name, ''),
                'invoice' => SpreadsheetCellSanitizer::text($invoiceLines[0] ?? '-', '-'),
                'bank' => SpreadsheetCellSanitizer::text($group->bank_name, ''),
                'account' => SpreadsheetCellSanitizer::text($group->account_number, ''),
                'payee' => SpreadsheetCellSanitizer::text($group->account_holder_name, ''),
                'nominal' => (float) $group->subtotal_amount, // NOMINAL = subtotal (gross, not net)
            ];

            // Continuation Rows (if any)
            $lineCount = count($invoiceLines);
            for ($k = 1; $k < $lineCount; $k++) {
                $rows[] = [
                    'no' => null,
                    'kode' => null,
                    'reff' => null,
                    'supplier' => null,
                    'invoice' => SpreadsheetCellSanitizer::text($invoiceLines[$k], ''),
                    'bank' => null,
                    'account' => null,
                    'payee' => null,
                    'nominal' => null, // Left empty so it doesn't affect SUM
                ];
            }

            $groupNumber++;
        }

        return $rows;
    }

    /**
     * Format invoice numbers into lines, wrapping into continuation lines if multiple.
     *
     * @param  list<string>  $invoiceNumbers
     * @return list<string>
     */
    public static function formatInvoiceLines(array $invoiceNumbers): array
    {
        if (empty($invoiceNumbers)) {
            return ['-'];
        }

        $lines = [];
        $currentLine = 'I - ';
        $invoicesOnLine = 0;

        foreach ($invoiceNumbers as $inv) {
            $candidate = ($invoicesOnLine === 0) ? $currentLine.$inv : $currentLine.', '.$inv;

            // Overflow to next line if more than 3 invoices or string length exceeds 60
            if ($invoicesOnLine > 0 && ($invoicesOnLine >= 3 || strlen($candidate) > 60)) {
                $lines[] = $currentLine;
                $currentLine = $inv;
                $invoicesOnLine = 1;
            } else {
                $currentLine = $candidate;
                $invoicesOnLine++;
            }
        }

        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }

        return $lines;
    }
}
