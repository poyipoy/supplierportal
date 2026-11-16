<?php

namespace App\Services\FileSecurity;

use InvalidArgumentException;

/**
 * Pure PHP replacement for the standalone `inode-guard` binary.
 *
 * Sweeps only whitelisted temporary storage/cache directories with segment-level
 * symlink checks, canonical root containment guards, dotfile preservation,
 * minimum age enforcement (>= 1 hour), and bounded recursion depth (32).
 */
final class InodeGuardCleanupService
{
    public const MIN_AGE_HOURS = 1;

    public const MAX_SWEEP_DEPTH = 32;

    /** @var list<string> */
    public const ALLOWED_SUBDIRS = [
        'storage/app/private/imports/reader-cache',
        'storage/app/private/native-security/xml',
        'storage/framework/cache/data',
        'storage/framework/cache/import-previews',
        'storage/framework/cache/laravel-excel',
        'storage/app/private/po-documents/staging',
    ];

    /**
     * Sweep aged temporary files across whitelisted subdirectories under `$root`.
     *
     * @param  null|callable(string): bool  $linkDetector
     * @return array{
     *   effective_age_hours: int,
     *   deleted_files: int,
     *   freed_bytes: int,
     *   skipped_symlinks: int,
     *   skipped_dotfiles: int,
     *   skipped_outside_whitelist: int,
     *   entries: list<string>,
     *   warnings: list<string>
     * }
     */
    public function sweep(
        string $root,
        int $maxAgeHours = 2,
        bool $dryRun = false,
        ?int $nowTimestamp = null,
        ?callable $linkDetector = null,
    ): array {
        $isLink = $linkDetector ?? static fn (string $path): bool => is_link($path);
        $trimmed = trim($root);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Application root path cannot be empty.');
        }

        clearstatcache(true);
        $rootCanon = realpath($trimmed);
        if ($rootCanon === false || ! is_dir($rootCanon)) {
            throw new InvalidArgumentException("Invalid application root directory: {$trimmed}");
        }

        $effectiveAgeHours = max(self::MIN_AGE_HOURS, $maxAgeHours);
        $cutoffTimestamp = ($nowTimestamp ?? time()) - ($effectiveAgeHours * 3600);

        $report = [
            'effective_age_hours' => $effectiveAgeHours,
            'deleted_files' => 0,
            'freed_bytes' => 0,
            'skipped_symlinks' => 0,
            'skipped_dotfiles' => 0,
            'skipped_outside_whitelist' => 0,
            'entries' => [],
            'warnings' => [],
        ];

        foreach (self::ALLOWED_SUBDIRS as $subdir) {
            $baseCanon = $this->resolveSafeSubdir($rootCanon, $subdir, $isLink, $report);
            if ($baseCanon !== null) {
                $this->sweepDirectory($baseCanon, $baseCanon, 0, $cutoffTimestamp, $dryRun, $isLink, $report);
            }
        }

        return $report;
    }

    /**
     * @param  callable(string): bool  $isLink
     * @param  array<string, mixed>  $report
     */
    private function resolveSafeSubdir(string $rootCanon, string $subdir, callable $isLink, array &$report): ?string
    {
        $cur = $rootCanon;

        foreach (explode('/', $subdir) as $segment) {
            $cur .= DIRECTORY_SEPARATOR.$segment;
            clearstatcache(true, $cur);

            if ($isLink($cur)) {
                $report['skipped_symlinks']++;
                $report['warnings'][] = "[SECURITY-SKIP] Symlink detected along whitelist path: {$cur}";

                return null;
            }
            if (! file_exists($cur)) {
                return null;
            }
            $segCanon = realpath($cur);
            if ($segCanon === false || ! $this->isWithinDirectory($segCanon, $rootCanon) || ! $this->pathsMatch($segCanon, $cur)) {
                $report['skipped_outside_whitelist']++;
                $report['warnings'][] = "[SECURITY-SKIP] Path redirected or escaped root along whitelist segment: {$cur}";

                return null;
            }
        }

        clearstatcache(true, $cur);
        if ($isLink($cur) || ! is_dir($cur)) {
            return null;
        }

        $baseCanon = realpath($cur);
        if ($baseCanon === false || ! $this->isWithinDirectory($baseCanon, $rootCanon) || ! $this->pathsMatch($baseCanon, $cur)) {
            $report['skipped_outside_whitelist']++;
            $report['warnings'][] = "[SECURITY-SKIP] Canonicalized path redirected or escaped root: {$cur}";

            return null;
        }

        return $baseCanon;
    }

    /**
     * @param  callable(string): bool  $isLink
     * @param  array<string, mixed>  $report
     */
    private function sweepDirectory(
        string $baseCanon,
        string $currentDir,
        int $depth,
        int $cutoffTimestamp,
        bool $dryRun,
        callable $isLink,
        array &$report,
    ): void {
        if ($depth >= self::MAX_SWEEP_DEPTH) {
            $report['skipped_outside_whitelist']++;
            $report['warnings'][] = '[SECURITY-SKIP] Maximum sweep depth ('.self::MAX_SWEEP_DEPTH.") reached at: {$currentDir}";

            return;
        }

        $items = @scandir($currentDir, SCANDIR_SORT_NONE);
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (str_starts_with($name, '.')) {
                $report['skipped_dotfiles']++;

                continue;
            }

            $entryPath = $currentDir.DIRECTORY_SEPARATOR.$name;
            clearstatcache(true, $entryPath);

            if ($isLink($entryPath)) {
                $report['skipped_symlinks']++;
                $report['warnings'][] = "[SECURITY-SKIP] Symlink detected and ignored: {$entryPath}";

                continue;
            }

            if (is_dir($entryPath)) {
                $dirCanon = realpath($entryPath);
                if ($dirCanon === false || ! $this->isWithinDirectory($dirCanon, $baseCanon) || ! $this->pathsMatch($dirCanon, $entryPath)) {
                    $report['skipped_outside_whitelist']++;
                    $report['warnings'][] = "[SECURITY-SKIP] Subdirectory redirected or escaped whitelist: {$entryPath}";

                    continue;
                }

                $this->sweepDirectory($baseCanon, $dirCanon, $depth + 1, $cutoffTimestamp, $dryRun, $isLink, $report);

                continue;
            }

            $this->processRegularFile($baseCanon, $entryPath, $cutoffTimestamp, $dryRun, $report);
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function processRegularFile(
        string $baseCanon,
        string $entryPath,
        int $cutoffTimestamp,
        bool $dryRun,
        array &$report,
    ): void {
        if (! is_file($entryPath)) {
            $report['skipped_outside_whitelist']++;

            return;
        }

        $fileCanon = realpath($entryPath);
        if ($fileCanon === false || ! $this->isWithinDirectory($fileCanon, $baseCanon) || ! $this->pathsMatch($fileCanon, $entryPath)) {
            $report['skipped_outside_whitelist']++;
            $report['warnings'][] = "[SECURITY-SKIP] File redirected or escaped whitelist: {$entryPath}";

            return;
        }

        $mtime = @filemtime($entryPath);
        $bytes = @filesize($entryPath);
        if (! is_int($mtime) || ! is_int($bytes) || $mtime > $cutoffTimestamp) {
            return;
        }

        if ($dryRun) {
            $report['entries'][] = "[DRY-RUN] Would delete: {$fileCanon} ({$bytes} bytes)";
            $report['deleted_files']++;
            $report['freed_bytes'] += $bytes;

            return;
        }

        if (@unlink($entryPath)) {
            $report['entries'][] = $fileCanon;
            $report['deleted_files']++;
            $report['freed_bytes'] += $bytes;

            return;
        }

        $report['warnings'][] = "[WARN] Failed to remove {$fileCanon}";
    }

    private function pathsMatch(string $candidateCanon, string $expectedPath): bool
    {
        return $this->normalizePath($candidateCanon) === $this->normalizePath($expectedPath);
    }

    private function normalizePath(string $path): string
    {
        $normalized = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        return DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($normalized) : $normalized;
    }

    private function isWithinDirectory(string $candidateCanon, string $parentCanon): bool
    {
        $normalizedCandidate = $this->normalizePath($candidateCanon);
        $normalizedParent = $this->normalizePath($parentCanon);

        return $normalizedCandidate !== $normalizedParent
            && str_starts_with($normalizedCandidate, $normalizedParent.DIRECTORY_SEPARATOR);
    }
}
