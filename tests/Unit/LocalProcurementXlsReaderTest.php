<?php

namespace Tests\Unit;

use App\Services\LocalInvoice\LocalProcurementStreamingReader;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Shared\OLE;
use PhpOffice\PhpSpreadsheet\Shared\OLE\PPS\File;
use PhpOffice\PhpSpreadsheet\Shared\OLE\PPS\Root;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Tests\TestCase;

class LocalProcurementXlsReaderTest extends TestCase
{
    public function test_binary_xls_keeps_positions_first_sheet_formulas_and_calendar(): void
    {
        $previous = Date::getExcelCalendar();
        $path = storage_path('framework/cache/xls-reader-'.uniqid().'.xls');
        Date::setExcelCalendar(1904);
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Infor');
        $sheet->setCellValue('J1', 'Receipt');
        $sheet->setCellValue('L1', 'Order Line');
        $sheet->setCellValue('P1', 'Received Quantity');
        $sheet->setCellValue('T1', 'Actual Receipt Date');
        $sheet->setCellValueExplicit('J3', '00000123', DataType::TYPE_STRING);
        $sheet->setCellValue('L3', 'PO-001');
        $sheet->setCellValue('P3', '=1+1');
        $sheet->getCell('P3')->setCalculatedValue(2);
        $sheet->setCellValue('Q3', 'KG');
        $sheet->setCellValue('T3', 1);
        $book->createSheet()->setTitle('Ignored');
        $book->setActiveSheetIndex(1);
        $writer = new Xls($book);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        $book->disconnectWorksheets();
        unset($book, $writer, $sheet);
        Date::setExcelCalendar($previous);
        try {
            $reader = app(LocalProcurementStreamingReader::class);
            $rows = iterator_to_array($reader->rows($path, 'GR'));
            $this->assertCount(1, $rows);
            $this->assertSame(3, $rows[0]['_row']);
            $this->assertSame('00000123', $rows[0]['gr_number']);
            $this->assertSame('KG', $rows[0]['uom']);
            $this->assertContains('qty', $rows[0]['_formula_columns']);
            $this->assertSame('1904-01-02', $rows[0]['gr_date']);
            $this->assertSame($previous, Date::getExcelCalendar());
            $this->assertCount(1, $reader->warnings);
        } finally {
            unlink($path);
        }
    }

    public function test_renamed_html_and_corrupt_ole_are_rejected(): void
    {
        foreach (['<html><table><tr><td>Order</td></tr></table></html>', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1corrupt"] as $contents) {
            $path = storage_path('framework/cache/invalid-xls-'.uniqid().'.xls');
            file_put_contents($path, $contents);
            try {
                iterator_to_array(app(LocalProcurementStreamingReader::class)->rows($path, 'PO'));
                $this->fail('Expected invalid XLS rejection');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            } finally {
                unlink($path);
            }
        }
    }

    public function test_xls_shared_string_continuations_and_second_read_window(): void
    {
        $path = storage_path('framework/cache/xls-window-'.uniqid().'.xls');
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        for ($i = 0; $i < 2001; $i++) {
            foreach (['E' => 'PO-'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'G' => 'Supplier 日本語 '.$i, 'J' => '2026-10-08', 'K' => '999999999999.99'] as $column => $value) {
                $sheet->setCellValueExplicit($column.($i + 2), $value, DataType::TYPE_STRING);
            }
        }
        (new Xls($book))->save($path);
        $book->disconnectWorksheets();
        unset($book, $sheet);
        $handler = set_error_handler(fn () => false);
        restore_error_handler();
        try {
            $count = 0;
            foreach (app(LocalProcurementStreamingReader::class)->rows($path, 'PO') as $row) {
                $this->assertSame('PO-'.str_pad((string) $count, 8, '0', STR_PAD_LEFT), $row['po_number']);
                $this->assertSame('999999999999.99', $row['po_amount']);
                $count++;
            }
            $this->assertSame(2001, $count);
            $after = set_error_handler(fn () => false);
            restore_error_handler();
            $this->assertSame($handler, $after);
        } finally {
            unlink($path);
        }
    }

    public function test_filepass_record_is_rejected_before_any_decryption_attempt(): void
    {
        $path = storage_path('framework/cache/encrypted-'.uniqid().'.xls');
        $stream = new File(OLE::ascToUcs('Workbook'));
        $stream->append(pack('vv', 0x0809, 4).pack('vv', 0x0600, 0x0005).pack('vv', 0x002F, 54).str_repeat("\0", 54));
        $file = fopen($path, 'wb');
        try {
            (new Root(null, null, [$stream]))->save($file);
        } finally {
            fclose($file);
        }
        try {
            $this->expectException(ValidationException::class);
            iterator_to_array(app(LocalProcurementStreamingReader::class)->rows($path, 'PO'));
        } finally {
            unlink($path);
        }
    }
}
