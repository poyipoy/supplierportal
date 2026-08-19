<?php

namespace App\Console\Commands;

use App\Models\ExportJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CleanupExpiredExports extends Command
{
    protected $signature = 'exports:cleanup';

    protected $description = 'Delete expired generated export files and their records.';

    public function handle(): int
    {
        $removed = 0;
        $retained = 0;

        ExportJob::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($jobs) use (&$removed, &$retained): void {
                foreach ($jobs as $job) {
                    if ($job->file_path !== null && ! $job->hasSafeFilePath()) {
                        Log::warning('Expired export has an unsafe file path.', [
                            'export_job_id' => $job->getKey(),
                        ]);
                        $retained++;

                        continue;
                    }

                    if ($job->file_path !== null) {
                        try {
                            $disk = Storage::disk($job->disk);
                            $deleted = ! $disk->exists($job->file_path) || $disk->delete($job->file_path);
                        } catch (Throwable $exception) {
                            $deleted = false;

                            Log::warning('Expired export file cleanup failed.', [
                                'export_job_id' => $job->getKey(),
                                'exception_class' => $exception::class,
                            ]);
                        }

                        if (! $deleted) {
                            $retained++;

                            continue;
                        }
                    }

                    $job->delete();
                    $removed++;
                }
            });

        $this->info("Expired exports removed: {$removed}; retained for retry: {$retained}.");

        return self::SUCCESS;
    }
}
