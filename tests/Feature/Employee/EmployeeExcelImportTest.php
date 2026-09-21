<?php

namespace Tests\Feature\Employee;

use App\Models\Employee;
use App\Services\Employee\EmployeeExcelImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeExcelImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_import_command_successfully_imports_records_from_excel(): void
    {
        $this->assertSame(0, Employee::count());

        $this->artisan('employee:import-excel')
            ->expectsOutputToContain('Berhasil mengimpor')
            ->assertSuccessful();

        $this->assertSame(100, Employee::count());

        // 1. Verify standard row
        $faaiz = Employee::where('name', 'ABDUR RAHMAN AL FAAIZ')->first();
        $this->assertNotNull($faaiz);
        $this->assertSame('LOGISTIC & WAREHOUSE', $faaiz->department);
        $this->assertSame('BCA', $faaiz->bank_name);
        $this->assertSame('5220804200', $faaiz->account_number);
        $this->assertSame('ABDUR RAHMAN AL FAAIZ', $faaiz->account_holder_name);
        $this->assertTrue($faaiz->is_active);

        // 2. Verify cleaned account number with parenthesis (Row 21)
        $row21 = Employee::where('account_number', '1222469666')->first();
        $this->assertNotNull($row21, 'Account number with parenthesis must be cleaned to pure digits');

        // 3. Verify cleaned account number with curly quote (Row 31)
        $row31 = Employee::where('account_number', '4110043657')->first();
        $this->assertNotNull($row31, 'Account number with curly single quote must be cleaned to pure digits');

        // 4. Verify cleaned account number with hyphens (Row 39)
        $row39 = Employee::where('account_number', '9949588278')->first();
        $this->assertNotNull($row39, 'Account number with hyphens must be cleaned to pure digits');

        // 5. Verify cleaned account number with spaces (Row 65)
        $row65 = Employee::where('account_number', '4124432230')->first();
        $this->assertNotNull($row65, 'Account number with spaces must be cleaned to pure digits');

        // 6. Verify standardized uppercase bank name (Row 27: 'jago' -> 'JAGO')
        $jagoEmployee = Employee::where('bank_name', 'JAGO')->first();
        $this->assertNotNull($jagoEmployee, "Lowercase bank 'jago' must be normalized to uppercase 'JAGO'");

        // 7. Verify bank 'Bank NIAGA' -> 'BANK NIAGA'
        $niagaEmployee = Employee::where('bank_name', 'BANK NIAGA')->first();
        $this->assertNotNull($niagaEmployee, "Mixed case bank 'Bank NIAGA' must be normalized to uppercase 'BANK NIAGA'");
    }

    public function test_employee_import_is_idempotent_on_repeated_runs(): void
    {
        $importer = app(EmployeeExcelImportService::class);

        $firstRun = $importer->import();
        $this->assertSame(100, $firstRun['total']);
        $this->assertSame(100, $firstRun['created']);
        $this->assertSame(0, $firstRun['updated']);
        $this->assertSame(100, Employee::count());

        // Second run must update existing records without creating duplicates
        $secondRun = $importer->import();
        $this->assertSame(100, $secondRun['total']);
        $this->assertSame(0, $secondRun['created']);
        $this->assertSame(100, $secondRun['updated']);
        $this->assertSame(100, Employee::count());
    }

    public function test_employee_master_seeder_runs_cleanly(): void
    {
        $this->artisan('db:seed', ['--class' => 'EmployeeMasterSeeder'])
            ->assertSuccessful();

        $this->assertSame(100, Employee::count());
    }
}
