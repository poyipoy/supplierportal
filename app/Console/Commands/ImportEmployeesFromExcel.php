<?php

namespace App\Console\Commands;

use App\Services\Employee\EmployeeExcelImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportEmployeesFromExcel extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'employee:import-excel {--file= : Path spesifik ke berkas TARIKAN TRANSFER.xlsx}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import master data karyawan dari sheet Form Responses 1 berkas TARIKAN TRANSFER.xlsx ke Employee Master';

    /**
     * Execute the console command.
     */
    public function handle(EmployeeExcelImportService $service): int
    {
        $filePath = $this->option('file');

        $this->info('Memulai proses impor master data karyawan dari Excel...');
        if ($filePath) {
            $this->line("Menggunakan file kustom: <comment>{$filePath}</comment>");
        } else {
            $this->line("Menggunakan file default: <comment>{$service->resolveDefaultFilePath()}</comment>");
        }

        try {
            $result = $service->import($filePath);

            $this->table(
                ['Metrik', 'Jumlah'],
                [
                    ['Total Baris Diproses', $result['total']],
                    ['Karyawan Baru Ditambahkan', $result['created']],
                    ['Karyawan Diperbarui (Existing)', $result['updated']],
                ]
            );

            $this->info("Berhasil mengimpor {$result['total']} data karyawan ke master data Employee.");

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Gagal mengimpor data karyawan: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
