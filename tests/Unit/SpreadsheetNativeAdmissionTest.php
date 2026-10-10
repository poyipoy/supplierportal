<?php

namespace Tests\Unit;

use App\Support\SpreadsheetImportReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
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
}
