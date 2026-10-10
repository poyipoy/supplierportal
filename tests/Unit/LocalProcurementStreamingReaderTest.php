<?php

namespace Tests\Unit;

use App\Models\LocalGoodsReceipt;
use App\Services\LocalInvoice\LocalProcurementStreamingReader;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Tests\TestCase;

class LocalProcurementStreamingReaderTest extends TestCase
{
    private function fixture(array $rows, ?callable $edit = null): string
    {
        $path = storage_path('framework/cache/stream-reader-'.uniqid().'.xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $headings = array_fill(0, 21, '');
        $writer->addRow(Row::fromValues($headings));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();
        if ($edit) {
            $zip = new \ZipArchive;
            $zip->open($path);
            $edit($zip);
            $zip->close();
        }

        return $path;
    }

    public function test_physical_blank_rows_and_gr_positions_are_preserved(): void
    {
        $row = array_fill(0, 21, null);
        $row[9] = 'GR-1';
        $row[11] = 'PO-1';
        $row[15] = '10.125';
        $row[16] = 'KG';
        $row[19] = '2026-10-01';
        $path = $this->fixture([[], $row]);
        try {
            $rows = iterator_to_array(app(LocalProcurementStreamingReader::class)->rows($path, 'GR'));
            $this->assertCount(1, $rows);
            $this->assertSame(3, $rows[0]['_row']);
            $this->assertSame('PO-1', $rows[0]['po_number']);
            $this->assertSame('KG', $rows[0]['uom']);
        } finally {
            unlink($path);
        }
    }

    public function test_cached_formula_and_1904_unstyled_numeric_date_do_not_bypass_contracts(): void
    {
        $row = array_fill(0, 21, null);
        $row[9] = 'GR-1';
        $row[11] = 'PO-1';
        $row[15] = 1;
        $row[16] = 'pcs';
        $row[19] = 1;
        $calendar = Date::getExcelCalendar();
        $path = $this->fixture([$row], function ($zip) {
            $xml = $zip->getFromName('xl/workbook.xml');
            $zip->addFromString('xl/workbook.xml', str_replace('<sheets>', '<workbookPr date1904="1"/><sheets>', $xml));
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $xml = preg_replace('~<c\b[^>]*r="P2"[^>]*>.*?</c>~s', '<c r="P2"><f>1+1</f><v>2</v></c>', $xml);
            $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        });
        try {
            $rows = iterator_to_array(app(LocalProcurementStreamingReader::class)->rows($path, 'GR'));
            $this->assertContains('qty', $rows[0]['_formula_columns']);
            $this->assertSame('1904-01-02', $rows[0]['gr_date']);
            $this->assertSame($calendar, Date::getExcelCalendar());
        } finally {
            unlink($path);
        }
    }

    public function test_shared_strings_and_rich_text_remain_plain_business_text(): void
    {
        $row = array_fill(0, 21, null);
        $row[8] = 'placeholder';
        $row[9] = 'GR-1';
        $row[11] = 'PO-1';
        $row[15] = '10.1256';
        $row[16] = 'pcs';
        $row[19] = '2026-10-01';
        $path = $this->fixture([$row], function ($zip) {
            $xml = preg_replace('~<c\b[^>]*r="I2"[^>]*>.*?</c>~s', '<c r="I2" t="s"><v>0</v></c>', $zip->getFromName('xl/worksheets/sheet1.xml'));
            $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
            $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="1" uniqueCount="1"><si><r><t xml:space="preserve">Material &amp; </t></r><r><t>spesifikasi 日本語</t></r></si></sst>');
            $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
            $zip->addFromString('xl/_rels/workbook.xml.rels', str_replace('</Relationships>', '<Relationship Id="sharedStrings" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>', $rels));
        });
        try {
            $rows = iterator_to_array(app(LocalProcurementStreamingReader::class)->rows($path, 'GR'));
            $this->assertSame('Material & spesifikasi 日本語', $rows[0]['description']);
            $this->assertSame('10.1256', $rows[0]['qty']);
            $this->assertFalse(LocalGoodsReceipt::validQuantity($rows[0]['qty']));
        } finally {
            unlink($path);
        }
    }

    public function test_70001_real_rows_are_rejected_at_the_configured_boundary(): void
    {
        $path = storage_path('framework/cache/stream-limit-'.uniqid().'.xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(array_fill(0, 21, '')));
        $row = array_fill(0, 21, null);
        $row[9] = 'GR';
        $row[11] = 'PO';
        $row[15] = '1';
        $row[16] = 'pcs';
        $row[19] = '2026-10-01';
        for ($index = 0; $index < 70001; $index++) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();
        $seen = 0;
        try {
            foreach (app(LocalProcurementStreamingReader::class)->rows($path, 'GR') as $value) {
                $seen++;
            }
            $this->fail('Expected file limit rejection');
        } catch (ValidationException $exception) {
            $this->assertSame(70000, $seen);
            $this->assertStringContainsString('70000', implode(' ', $exception->validator->errors()->all()));
        } finally {
            unlink($path);
        }
    }

    public function test_real_infor_files_keep_positions_physical_rows_and_blank_uom_header(): void
    {
        $reader = app(LocalProcurementStreamingReader::class);
        $rows = iterator_to_array($reader->rows(base_path('whinh3512m600_0520_20260924-134847_116644.xlsx'), 'GR'));
        $this->assertCount(48, $rows);
        $this->assertSame('pcs', $rows[0]['uom']);
        $this->assertSame(2, $rows[0]['_row']);
        $this->assertNotEmpty($rows[0]['description']);
        $this->assertCount(1, $reader->warnings);
        $poRows = iterator_to_array($reader->rows(base_path('tdpur4100m000_0520_20260924-133401_101112.xlsx'), 'PO'));
        $this->assertCount(4, $poRows);
        $this->assertNotEmpty($poRows[0]['po_number']);
        $this->assertNotEmpty($poRows[0]['supplier_name']);
    }

    public function test_row_limit_counts_meaningful_rows_and_rejects_without_returning_a_ready_file(): void
    {
        config(['local_procurement_imports.max_rows' => 47]);
        $this->expectException(ValidationException::class);
        iterator_to_array(app(LocalProcurementStreamingReader::class)->rows(base_path('whinh3512m600_0520_20260924-134847_116644.xlsx'), 'GR'));
    }
}
