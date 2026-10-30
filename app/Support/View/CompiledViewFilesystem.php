<?php

namespace App\Support\View;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * Filesystem used only by the Blade compiler (see AppServiceProvider::register()).
 *
 * The framework publishes a compiled view by writing a temp file and rename()ing
 * it over the destination. On Windows that rename fails with "Access is denied
 * (code: 5)" while any other handle is open on the destination, such as a
 * concurrent request, a queue worker or test run including the same compiled
 * view, or a virus scanner. The framework neither retries nor removes the temp
 * file, so one unlucky request returns a 500 and leaves a stray *.tmp behind.
 *
 * Compiled output is a pure function of the view source, so a failed publish is
 * safe to retry, and is already done when the destination holds identical content.
 */
class CompiledViewFilesystem extends Filesystem
{
    private const MAX_ATTEMPTS = 8;

    private const RETRY_DELAY_MICROSECONDS = 25_000;

    /**
     * @param  string  $path
     * @param  string  $content
     * @param  int|null  $mode
     */
    public function replace($path, $content, $mode = null): void
    {
        clearstatcache(true, $path);

        $path = realpath($path) ?: $path;

        $tempPath = tempnam(dirname($path), basename($path));

        @chmod($tempPath, $mode ?? 0777 - umask());

        file_put_contents($tempPath, $content);

        try {
            for ($attempt = 1; ; $attempt++) {
                error_clear_last();

                if ($this->swapIn($tempPath, $path)) {
                    return;
                }

                if ($this->holdsContent($path, $content)) {
                    return;
                }

                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new RuntimeException(sprintf(
                        'Unable to publish compiled view [%s] after %d attempts: %s',
                        $path,
                        $attempt,
                        error_get_last()['message'] ?? 'rename failed'
                    ));
                }

                $this->pause($attempt);
            }
        } finally {
            // A successful rename has already consumed the temp file.
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    protected function swapIn(string $from, string $to): bool
    {
        return @rename($from, $to);
    }

    protected function pause(int $attempt): void
    {
        usleep(self::RETRY_DELAY_MICROSECONDS * $attempt);
    }

    private function holdsContent(string $path, string $content): bool
    {
        return is_file($path) && @hash_file('xxh128', $path) === hash('xxh128', $content);
    }
}
