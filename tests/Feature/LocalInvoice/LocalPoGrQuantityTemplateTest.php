<?php

namespace Tests\Feature\LocalInvoice;

use App\Exports\LocalPoGrImportTemplateExport;
use App\Imports\LocalPoGrImport;
use App\Support\SpreadsheetImportReader;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class LocalPoGrQuantityTemplateTest extends TestCase
{
    public function test_combined_template_workbook_parses_quantity_and_unit(): void
    {
        $content = Excel::raw(new LocalPoGrImportTemplateExport, \Maatwebsite\Excel\Excel::XLSX);
        $file = UploadedFile::fake()->createWithContent('combined.xlsx', $content);
        $parser = new LocalPoGrImport;
        SpreadsheetImportReader::import($parser, $file);
        $preview = $parser->preview();

        $this->assertTrue($preview['success']);
        $this->assertSame(10.125, $preview['rows'][0]['qty']);
        $this->assertSame('kg', $preview['rows'][0]['uom']);
        $this->assertArrayNotHasKey('gr_amount', $preview['rows'][0]);
    }
}
