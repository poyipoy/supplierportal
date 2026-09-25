<?php

namespace App\Services\Employee;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class EmployeeExcelImportService
{
    /**
     * Resolve the default path to TARIKAN TRANSFER.xlsx
     */
    public function resolveDefaultFilePath(): string
    {
        $candidatePaths = [
            base_path('TARIKAN TRANSFER.xlsx'),
            database_path('data/TARIKAN TRANSFER.xlsx'),
            storage_path('app/imports/TARIKAN TRANSFER.xlsx'),
        ];

        foreach ($candidatePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return base_path('TARIKAN TRANSFER.xlsx');
    }

    /**
     * Import employee records from the specified Excel file and sheet.
     *
     * @return array{total: int, created: int, updated: int}
     */
    public function import(?string $filePath = null, ?string $sheetName = null): array
    {
        $path = $filePath ?: $this->resolveDefaultFilePath();

        if (! file_exists($path)) {
            throw new InvalidArgumentException("File [{$path}] tidak ditemukan.");
        }

        $spreadsheet = IOFactory::load($path);

        // Find sheet: prioritize "Form Responses 1" or "Form Response 1"
        $targetSheet = null;
        if ($sheetName) {
            $targetSheet = $spreadsheet->getSheetByName($sheetName);
        } else {
            foreach ($spreadsheet->getSheetNames() as $name) {
                if (in_array(strtolower(trim($name)), ['form responses 1', 'form response 1'], true)) {
                    $targetSheet = $spreadsheet->getSheetByName($name);
                    break;
                }
            }
        }

        if (! $targetSheet) {
            $targetSheet = $spreadsheet->getActiveSheet();
        }

        $highestRow = $targetSheet->getHighestRow();
        $createdCount = 0;
        $updatedCount = 0;
        $totalProcessed = 0;

        DB::transaction(function () use ($targetSheet, $highestRow, &$createdCount, &$updatedCount, &$totalProcessed) {
            for ($row = 2; $row <= $highestRow; $row++) {
                $name = trim((string) $targetSheet->getCell('C'.$row)->getValue());
                $dept = trim((string) $targetSheet->getCell('D'.$row)->getValue());
                $bank = trim((string) $targetSheet->getCell('E'.$row)->getValue());
                $holder = trim((string) $targetSheet->getCell('F'.$row)->getValue());
                $rawAccount = trim((string) $targetSheet->getCell('G'.$row)->getValue());

                // Skip empty row
                if ($name === '' && $rawAccount === '') {
                    continue;
                }

                if ($name === '') {
                    continue;
                }

                // Clean account number: remove non-digits (quotes, hyphens, parentheses, spaces)
                $cleanAccount = preg_replace('/[^0-9]/', '', $rawAccount);

                // Standardize bank name to uppercase
                $cleanBank = strtoupper(trim($bank));

                // Account holder name defaults to employee name if empty
                $cleanHolder = $holder !== '' ? $holder : $name;

                $employee = Employee::updateOrCreate(
                    ['name' => $name],
                    [
                        'department' => $dept !== '' ? $dept : 'GENERAL',
                        'bank_name' => $cleanBank !== '' ? $cleanBank : 'BCA',
                        'account_number' => $cleanAccount,
                        'account_holder_name' => $cleanHolder,
                        'is_active' => true,
                    ]
                );

                if ($employee->wasRecentlyCreated) {
                    $createdCount++;
                } else {
                    $updatedCount++;
                }

                $totalProcessed++;
            }
        });

        return [
            'total' => $totalProcessed,
            'created' => $createdCount,
            'updated' => $updatedCount,
        ];
    }
}
