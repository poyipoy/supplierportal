<?php

namespace Tests\Unit;

use App\Imports\QuotationItemsImport;
use App\Support\SpreadsheetImportReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SpreadsheetNativeAdmissionTest extends TestCase
{
    public function test_renamed_non_workbook_is_rejected_before_the_excel_parser(): void
    {
        Excel::shouldReceive('import')->never();
        $file = UploadedFile::fake()->createWithContent('spoof.xlsx', '<?php echo "not a workbook";');
        $this->expectException(ValidationException::class);
        SpreadsheetImportReader::import(new \stdClass, $file);
    }

    public static function oversizedSheets(): array
    {
        return [
            'xlsx row overflow' => ['xlsx', 'A1002', 'purchasing.imports.max_rows', 1000],
            'xls row overflow' => ['xls', 'A1002', 'purchasing.imports.max_rows', 1000],
            'xlsx column overflow' => ['xlsx', 'BM2', 'file_security_resources.sheet_columns', 64],
            'xls column overflow' => ['xls', 'BM2', 'file_security_resources.sheet_columns', 64],
        ];
    }

    #[DataProvider('oversizedSheets')]
    public function test_sparse_sheet_dimensions_are_rejected_before_the_excel_parser(string $extension, string $address, string $message, int $count): void
    {
        Excel::shouldReceive('import')->never();
        $path = tempnam(sys_get_temp_dir(), 'adasi-preview-');
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'pr_item_id');
        $spreadsheet->getActiveSheet()->setCellValue($address, 'oversized');
        ($extension === 'xls' ? new Xls($spreadsheet) : new Xlsx($spreadsheet))->save($path);
        try {
            $import = new QuotationItemsImport(collect());
            SpreadsheetImportReader::import($import, new UploadedFile($path, 'preview.'.$extension, null, null, true));
            $this->assertTrue($import->hasFileErrors());
            $this->assertContains(__($message, ['count' => $count]), array_column($import->errors, 'message'));
        } finally {
            $spreadsheet->disconnectWorksheets();
            unlink($path);
        }
    }

    public function test_maximum_dimensions_remain_available_to_the_bounded_read_filter(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'adasi-preview-');
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'pr_item_id');
        $spreadsheet->getActiveSheet()->setCellValue('BL1001', 'last allowed cell');
        (new Xlsx($spreadsheet))->save($path);
        $import = new QuotationItemsImport(collect());
        Excel::shouldReceive('import')->once()->withArgs(function ($received, $temporary) use ($import) {
            $this->assertSame($import, $received);
            $this->assertFileExists($temporary);
            $filter = $received->readFilter();
            $this->assertTrue($filter->readCell('BL', 1001));
            $this->assertFalse($filter->readCell('BM', 2));
            $this->assertFalse($filter->readCell('A', 1002));

            return true;
        });
        try {
            SpreadsheetImportReader::import($import, new UploadedFile($path, 'preview.xlsx', null, null, true));
            $this->assertFalse($import->hasFileErrors());
        } finally {
            $spreadsheet->disconnectWorksheets();
            unlink($path);
        }
    }

    public function test_csv_blank_rows_count_toward_the_preview_dimension_limit(): void
    {
        Excel::shouldReceive('import')->never();
        $file = UploadedFile::fake()->createWithContent('preview.csv', "pr_item_id,price_per_kg\n".str_repeat("\n", 1001));
        $import = new QuotationItemsImport(collect());
        SpreadsheetImportReader::import($import, $file);
        $this->assertTrue($import->hasFileErrors());
    }
}
