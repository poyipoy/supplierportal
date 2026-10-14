<?php

namespace App\Console\Commands;

use App\Models\User;
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
    protected $signature = 'employee:import-excel {--file= : Path spesifik ke berkas TARIKAN TRANSFER.xlsx} {--actor= : ID user GA atau administrator untuk audit perubahan rekening existing}';

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
            $actor = null;
            if ($this->option('actor') !== null) {
                $actorId = (string) $this->option('actor');
                $actor = ctype_digit($actorId) ? User::find($actorId) : null;
                if (! $actor || ! $actor->is_active || (! $actor->isGa() && ! $actor->isAdmin())) {
                    throw new \InvalidArgumentException(__('finance_controls.import_actor'));
                }
            }
            $result = $service->import($filePath, actor: $actor);

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
