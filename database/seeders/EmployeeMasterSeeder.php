<?php

namespace Database\Seeders;

use App\Services\Employee\EmployeeExcelImportService;
use Illuminate\Database\Seeder;

class EmployeeMasterSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(EmployeeExcelImportService $importer): void
    {
        $filePath = $importer->resolveDefaultFilePath();

        if (file_exists($filePath)) {
            $importer->import($filePath);
        }
    }
}
