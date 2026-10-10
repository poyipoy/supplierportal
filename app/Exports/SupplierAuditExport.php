<?php

namespace App\Exports;

use App\Contracts\GeneratesWorkbook;
use App\Contracts\TracksExportProgress;
use App\Exports\Concerns\InteractsWithExportProgress;
use App\Models\SupplierAudit;
use App\Models\SupplierAuditAnswer;
use App\Models\User;
use App\Support\SpreadsheetCellSanitizer;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Export jawaban Supplier Audit meniru template "FORM CHECKLIST AUDIT SUPPLIER & VENDOR".
 * Dibangun dari snapshot jawaban (bukan dari file template) dan dipecah 4 sheet sesuai
 * sheet_number_snapshot (D16). Kolom Point dan Temuan/Catatan sengaja kosong (D6);
 * baris Sub Total berisi rumus SUM kolom Point untuk penilaian offline Purchasing.
 */
class SupplierAuditExport implements GeneratesWorkbook, HasLocalePreference, TracksExportProgress
{
    use InteractsWithExportProgress;

    private const CHECK = '✓';

    /** Lebar kolom template Excel asli. */
    private const COLUMN_WIDTHS = ['A' => 4.3, 'B' => 2.9, 'C' => 96.4, 'D' => 5.4, 'E' => 4.9, 'F' => 14, 'G' => 27, 'H' => 21.4];

    public function __construct(
        public readonly int $actorId,
        public readonly int $supplierAuditId,
    ) {}

    public function generateWorkbook(string $path, string $disk): void
    {
        $audit = $this->authorizedAudit();
        $spreadsheet = $this->render($audit);

        $tempPath = tempnam(sys_get_temp_dir(), 'supplier_audit_');
        if ($tempPath === false) {
            throw new RuntimeException('Failed to create temporary file for supplier audit export.');
        }

        try {
            (new Xlsx($spreadsheet))->save($tempPath);

            $stream = fopen($tempPath, 'r');
            try {
                Storage::disk($disk)->put($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    public function progressTotalRows(): int
    {
        return SupplierAuditAnswer::where('supplier_audit_id', $this->supplierAuditId)->count();
    }

    public function render(SupplierAudit $audit): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        $sheets = $audit->answerSections()->groupBy('sheet', preserveKeys: false);
        $vendorLine = SpreadsheetCellSanitizer::text(__('supplier_audit.export.vendor_header', [
            'supplier' => $audit->supplierName(),
            'period' => $audit->period_label,
        ]), '');

        foreach ($sheets->values() as $index => $sections) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle((string) ($index + 1));
            $this->renderSheet($sheet, $sections, $index === 0, (string) $audit->template?->title, $vendorLine);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function renderSheet(Worksheet $sheet, Collection $sections, bool $first, string $title, string $vendorLine): void
    {
        foreach (self::COLUMN_WIDTHS as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $row = 1;
        if ($first) {
            $sheet->mergeCells('A1:H4');
            $sheet->setCellValue('A1', SpreadsheetCellSanitizer::text($title, ''));
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(20);
            $sheet->getStyle('A1')->getAlignment()->setWrapText(true)
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $this->border($sheet, 'A1:H4');
            $row = 5;
        }

        $sheet->mergeCells("A{$row}:H{$row}");
        $sheet->setCellValue("A{$row}", $vendorLine);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $this->border($sheet, "A{$row}:H{$row}");
        $row++;

        $headerRow = $row;
        $sheet->setCellValue("A{$row}", __('supplier_audit.export.columns.no'));
        $sheet->mergeCells("B{$row}:C{$row}");
        $sheet->setCellValue("B{$row}", __('supplier_audit.export.columns.criterion'));
        $sheet->setCellValue("D{$row}", __('supplier_audit.export.columns.yes'));
        $sheet->setCellValue("E{$row}", __('supplier_audit.export.columns.no_answer'));
        $sheet->setCellValue("F{$row}", __('supplier_audit.export.columns.score'));
        $sheet->setCellValue("G{$row}", __('supplier_audit.export.columns.point'));
        $sheet->setCellValue("H{$row}", __('supplier_audit.export.columns.finding'));
        $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:H{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $this->border($sheet, "A{$row}:H{$row}");
        $row++;

        $lastParent = null;
        foreach ($sections as $section) {
            $parentCode = $section['parent_code'];

            if ($parentCode !== null && $parentCode !== $lastParent) {
                $row = $this->heading($sheet, $row, $parentCode, (string) $section['parent_title']);
                $lastParent = $parentCode;
            } elseif ($parentCode === null) {
                $lastParent = $section['code'];
            }

            $row = $this->heading($sheet, $row, $section['code'], $section['title']);

            $firstCriterionRow = $row;
            foreach ($section['answers'] as $answer) {
                $sheet->setCellValue("B{$row}", $answer->criterion_number_snapshot);
                $sheet->setCellValue("C{$row}", SpreadsheetCellSanitizer::text($answer->criterion_text_snapshot, ''));
                $sheet->setCellValue("D{$row}", $answer->answer === SupplierAuditAnswer::ANSWER_YES ? self::CHECK : '');
                $sheet->setCellValue("E{$row}", $answer->answer === SupplierAuditAnswer::ANSWER_NO ? self::CHECK : '');
                if ($answer->score !== null) {
                    $sheet->setCellValue("F{$row}", (int) $answer->score);
                }
                $sheet->getStyle("C{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
                $sheet->getStyle("D{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_TOP);
                $this->border($sheet, "A{$row}:H{$row}");
                $row++;
            }
            $lastCriterionRow = $row - 1;

            $sheet->mergeCells("A{$row}:E{$row}");
            $sheet->setCellValue("A{$row}", __('supplier_audit.export.sub_total'));
            $sheet->setCellValue("G{$row}", "=SUM(G{$firstCriterionRow}:G{$lastCriterionRow})");
            $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);
            $this->border($sheet, "A{$row}:H{$row}");
            $sheet->getStyle("A{$row}:H{$row}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);
            $row += 2;
        }

        $sheet->getPageSetup()
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);
    }

    private function heading(Worksheet $sheet, int $row, string $code, string $title): int
    {
        $sheet->setCellValueExplicit("A{$row}", $code, DataType::TYPE_STRING);
        $sheet->mergeCells("B{$row}:H{$row}");
        $sheet->setCellValue("B{$row}", SpreadsheetCellSanitizer::text($title, ''));
        $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);
        $this->border($sheet, "A{$row}:H{$row}");

        return $row + 1;
    }

    private function border(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    /** Diulang di worker: actor harus Purchasing aktif dan audit masih exportable. */
    private function authorizedAudit(): SupplierAudit
    {
        $actor = User::find($this->actorId);
        if ($actor === null || ! $actor->is_active || ! $actor->isPurchasing()) {
            throw new RuntimeException('Unauthorized actor for supplier audit export.');
        }

        $audit = SupplierAudit::with(['supplier.supplier', 'template', 'answers'])->find($this->supplierAuditId);
        if ($audit === null || ! $audit->isExportable()) {
            throw new RuntimeException('Supplier audit is not exportable.');
        }

        return $audit;
    }
}
