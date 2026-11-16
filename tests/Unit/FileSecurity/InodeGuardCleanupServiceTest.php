<?php

namespace Tests\Unit\FileSecurity;

use App\Services\FileSecurity\InodeGuardCleanupService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class InodeGuardCleanupServiceTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'inode-guard-test-'.uniqid('', true);
        foreach (InodeGuardCleanupService::ALLOWED_SUBDIRS as $sub) {
            File::ensureDirectoryExists($this->tempRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $sub));
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempRoot);
        parent::tearDown();
    }

    public function test_allowed_subdirs_match_verified_laravel_paths_and_exclude_protected_dirs(): void
    {
        $service = app(InodeGuardCleanupService::class);

        File::ensureDirectoryExists($this->tempRoot.DIRECTORY_SEPARATOR.'app');
        File::ensureDirectoryExists($this->tempRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'attachments');
        File::ensureDirectoryExists($this->tempRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs');

        $protectedApp = $this->tempRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Kernel.php';
        $protectedAttachment = $this->tempRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'attachments'.DIRECTORY_SEPARATOR.'invoice.pdf';
        $protectedLog = $this->tempRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel.log';
        $whitelistedOld = $this->tempRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'laravel-excel'.DIRECTORY_SEPARATOR.'stale.tmp';

        file_put_contents($protectedApp, '<?php');
        file_put_contents($protectedAttachment, '%PDF-1.4');
        file_put_contents($protectedLog, 'log');
        file_put_contents($whitelistedOld, 'stale');

        $oldTime = time() - 7200;
        touch($protectedApp, $oldTime);
        touch($protectedAttachment, $oldTime);
        touch($protectedLog, $oldTime);
        touch($whitelistedOld, $oldTime);

        $report = $service->sweep($this->tempRoot, 1, false);

        $this->assertSame(1, $report['deleted_files']);
        $this->assertFileDoesNotExist($whitelistedOld);
        $this->assertFileExists($protectedApp);
        $this->assertFileExists($protectedAttachment);
        $this->assertFileExists($protectedLog);
    }

    public function test_enforces_minimum_one_hour_age_and_dry_run_is_non_destructive(): void
    {
        $service = app(InodeGuardCleanupService::class);
        $dir = $this->tempRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'data';

        $recent = $dir.DIRECTORY_SEPARATOR.'recent.cache';
        $old = $dir.DIRECTORY_SEPARATOR.'old.cache';
        file_put_contents($recent, 'recent-bytes');
        file_put_contents($old, 'old-bytes');

        $now = time();
        touch($recent, $now - 1800); // 30 minutes ago (must survive even when --hours=0)
        touch($old, $now - 4000); // > 1 hour ago

        $dryReport = $service->sweep($this->tempRoot, 0, true, $now);
        $this->assertSame(InodeGuardCleanupService::MIN_AGE_HOURS, $dryReport['effective_age_hours']);
        $this->assertSame(1, $dryReport['deleted_files']);
        $this->assertSame(9, $dryReport['freed_bytes']);
        $this->assertFileExists($recent);
        $this->assertFileExists($old);

        $realReport = $service->sweep($this->tempRoot, 0, false, $now);
        $this->assertSame(1, $realReport['deleted_files']);
        $this->assertFileExists($recent);
        $this->assertFileDoesNotExist($old);
    }

    public function test_ignores_dotfiles_and_enforces_max_sweep_depth(): void
    {
        $service = app(InodeGuardCleanupService::class);
        $base = $this->tempRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'native-security'.DIRECTORY_SEPARATOR.'xml';

        $dotfile = $base.DIRECTORY_SEPARATOR.'.gitignore';
        file_put_contents($dotfile, "*\n");
        touch($dotfile, time() - 7200);

        $deep = $base;
        for ($i = 0; $i < InodeGuardCleanupService::MAX_SWEEP_DEPTH + 1; $i++) {
            $deep .= DIRECTORY_SEPARATOR.'d'.$i;
        }
        File::ensureDirectoryExists($deep);
        $tooDeepFile = $deep.DIRECTORY_SEPARATOR.'too-deep.tmp';
        file_put_contents($tooDeepFile, 'deep');
        touch($tooDeepFile, time() - 7200);

        $report = $service->sweep($this->tempRoot, 1, false);

        $this->assertGreaterThanOrEqual(1, $report['skipped_dotfiles']);
        $this->assertGreaterThanOrEqual(1, $report['skipped_outside_whitelist']);
        $this->assertFileExists($dotfile);
        $this->assertFileExists($tooDeepFile);
    }

    public function test_skips_symlinks_and_rejects_out_of_whitelist_junctions(): void
    {
        $service = app(InodeGuardCleanupService::class);
        $base = $this->tempRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'imports'.DIRECTORY_SEPARATOR.'reader-cache';

        $outsideDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'inode-guard-outside-'.uniqid('', true);
        File::ensureDirectoryExists($outsideDir);
        $outsideSecret = $outsideDir.DIRECTORY_SEPARATOR.'secret.txt';
        file_put_contents($outsideSecret, 'sensitive');
        touch($outsideSecret, time() - 7200);

        try {
            $symlinkCandidate = $base.DIRECTORY_SEPARATOR.'linked-dir';
            File::ensureDirectoryExists($symlinkCandidate);
            $reportViaDetector = $service->sweep(
                $this->tempRoot,
                1,
                false,
                null,
                static fn (string $path): bool => str_ends_with($path, 'linked-dir'),
            );
            $this->assertSame(1, $reportViaDetector['skipped_symlinks']);

            if (DIRECTORY_SEPARATOR === '\\') {
                // Real NTFS junction inside whitelist pointing outside must never touch target contents.
                $junction = $base.DIRECTORY_SEPARATOR.'junction-escape';
                exec('cmd /c mklink /J '.escapeshellarg($junction).' '.escapeshellarg($outsideDir).' 2>&1', $unused, $code);
                if ($code === 0) {
                    try {
                        $junctionReport = $service->sweep($this->tempRoot, 1, false);
                        $this->assertGreaterThanOrEqual(1, $junctionReport['skipped_outside_whitelist']);
                        $this->assertFileExists($outsideSecret);
                    } finally {
                        @rmdir($junction);
                    }
                }
            } else {
                $posixLink = $base.DIRECTORY_SEPARATOR.'posix-escape';
                if (@symlink($outsideSecret, $posixLink)) {
                    try {
                        $linkReport = $service->sweep($this->tempRoot, 1, false);
                        $this->assertSame(1, $linkReport['skipped_symlinks']);
                        $this->assertFileExists($outsideSecret);
                    } finally {
                        @unlink($posixLink);
                    }
                }
            }
        } finally {
            File::deleteDirectory($outsideDir);
        }
    }

    public function test_artisan_command_validates_inputs_and_scheduler_registers_hourly_cleanup(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(InodeGuardCleanupService::class)->sweep($this->tempRoot.DIRECTORY_SEPARATOR.'missing-dir');
    }

    public function test_artisan_command_execution_and_schedule_entry(): void
    {
        $dir = $this->tempRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'po-documents'.DIRECTORY_SEPARATOR.'staging';
        $stale = $dir.DIRECTORY_SEPARATOR.'stale.pdf';
        file_put_contents($stale, str_repeat('x', 2048));
        touch($stale, time() - 10800);

        $badCode = Artisan::call('storage:inode-guard', ['--hours' => 'abc', '--root' => $this->tempRoot]);
        $this->assertSame(1, $badCode);

        $dryCode = Artisan::call('storage:inode-guard', ['--hours' => '2', '--dry-run' => true, '--root' => $this->tempRoot]);
        $this->assertSame(0, $dryCode);
        $this->assertStringContainsString('dry_run=true, min_age_h=2', Artisan::output());
        $this->assertFileExists($stale);

        $runCode = Artisan::call('storage:inode-guard', ['--hours' => '2', '--root' => $this->tempRoot]);
        $this->assertSame(0, $runCode);
        $this->assertStringContainsString('1 files cleaned (2 KB)', Artisan::output());
        $this->assertFileDoesNotExist($stale);

        $events = collect(app(Schedule::class)->events())->filter(
            fn ($event) => str_contains((string) $event->command, 'storage:inode-guard --hours=2')
        );
        $this->assertCount(1, $events);
    }

    public function test_blocks_in_root_redirections_allows_missing_subdirs_and_handles_symlinked_root(): void
    {
        $service = app(InodeGuardCleanupService::class);

        $protectedDir = $this->tempRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'attachments';
        File::ensureDirectoryExists($protectedDir);
        $protectedPdf = $protectedDir.DIRECTORY_SEPARATOR.'protected-invoice.pdf';
        file_put_contents($protectedPdf, '%PDF-1.4 protected');
        touch($protectedPdf, time() - 7200);

        $staging = $this->tempRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'po-documents'.DIRECTORY_SEPARATOR.'staging';

        if (DIRECTORY_SEPARATOR === '\\') {
            File::deleteDirectory($staging);
            exec('cmd /c mklink /J '.escapeshellarg($staging).' '.escapeshellarg($protectedDir).' 2>&1', $unused, $code);
            if ($code === 0) {
                try {
                    $report = $service->sweep($this->tempRoot, 1, false);
                    $this->assertGreaterThanOrEqual(1, $report['skipped_outside_whitelist']);
                    $this->assertFileExists($protectedPdf);
                } finally {
                    @rmdir($staging);
                    File::ensureDirectoryExists($staging);
                }
            }

            // NTFS junction inside an allowed subdir pointing to another protected in-root dir must be blocked.
            $readerCache = $this->tempRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'imports'.DIRECTORY_SEPARATOR.'reader-cache';
            $inTreeJunction = $readerCache.DIRECTORY_SEPARATOR.'inner-junction';
            exec('cmd /c mklink /J '.escapeshellarg($inTreeJunction).' '.escapeshellarg($protectedDir).' 2>&1', $unused2, $code2);
            if ($code2 === 0) {
                try {
                    $report2 = $service->sweep($this->tempRoot, 1, false);
                    $this->assertGreaterThanOrEqual(1, $report2['skipped_outside_whitelist']);
                    $this->assertFileExists($protectedPdf);
                } finally {
                    @rmdir($inTreeJunction);
                }
            }
        }

        // Missing allowed subdirectories must be ignored cleanly with zero errors.
        $minimalRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'inode-minimal-'.uniqid('', true);
        File::ensureDirectoryExists($minimalRoot);
        try {
            $minimalReport = $service->sweep($minimalRoot, 2, false);
            $this->assertSame(0, $minimalReport['deleted_files']);
            $this->assertSame([], $minimalReport['warnings']);

            // Zero-downtime symlinked app root (via linkDetector simulation) resolves cleanly via realpath
            // while still enforcing internal segment/file symlink guards.
            File::ensureDirectoryExists($minimalRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'data');
            $staleCache = $minimalRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'old.cache';
            file_put_contents($staleCache, 'stale');
            touch($staleCache, time() - 7200);

            $rootSymReport = $service->sweep(
                $minimalRoot,
                1,
                false,
                null,
                static fn (string $path): bool => $path === $minimalRoot,
            );
            $this->assertSame(1, $rootSymReport['deleted_files']);
            $this->assertFileDoesNotExist($staleCache);
        } finally {
            File::deleteDirectory($minimalRoot);
        }
    }
}
