<?php

namespace App\Console\Commands;

use App\Services\FileSecurity\InodeGuardCleanupService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class CleanupTempStorageCommand extends Command
{
    protected $signature = 'storage:inode-guard
        {--hours=2 : Minimum file age in hours (enforced minimum 1)}
        {--dry-run : List eligible files without deleting}
        {--root= : Optional application root directory override}';

    protected $description = 'Safely clean aged temporary cache/staging files across whitelisted storage directories without following symlinks.';

    public function handle(InodeGuardCleanupService $service): int
    {
        $rawHours = (string) $this->option('hours');
        if (! ctype_digit($rawHours)) {
            $this->error('Option --hours must be a non-negative integer.');

            return self::FAILURE;
        }

        $root = (string) ($this->option('root') ?: base_path());
        $dryRun = (bool) $this->option('dry-run');

        try {
            $report = $service->sweep($root, (int) $rawHours, $dryRun);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($report['warnings'] as $warning) {
            $this->warn($warning);
        }
        if ($dryRun) {
            foreach ($report['entries'] as $line) {
                $this->line($line);
            }
        }

        $freedKb = intdiv($report['freed_bytes'], 1024);
        $dryFlag = $dryRun ? 'true' : 'false';
        $this->info(
            "inode-guard done (dry_run={$dryFlag}, min_age_h={$report['effective_age_hours']}): {$report['deleted_files']} files cleaned ({$freedKb} KB), {$report['skipped_symlinks']} symlinks skipped."
        );

        return self::SUCCESS;
    }
}
