<?php

namespace Tests\Unit\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class LocalInvoiceStatusMutationGuardTest extends TestCase
{
    /**
     * Only LocalInvoiceStateEngine and initial creation in InvoiceSubmissionService::submit()
     * may assign LocalInvoice status in app/Http/Controllers and app/Services.
     */
    private const ALLOWED_MUTATORS = [
        'app/Services/LocalInvoice/LocalInvoiceStateEngine.php',
        'app/Services/LocalInvoice/InvoiceSubmissionService.php',
    ];

    public function test_no_controller_or_external_service_mutates_local_invoice_status_directly(): void
    {
        $directories = [
            base_path('app/Http/Controllers'),
            base_path('app/Services'),
        ];

        $violations = [];

        foreach ($directories as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relativePath = str_replace('\\', '/', substr($file->getRealPath(), strlen(base_path()) + 1));
                if (in_array($relativePath, self::ALLOWED_MUTATORS, true)) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getRealPath());
                if (! str_contains($contents, 'LocalInvoice')) {
                    continue;
                }

                // Detect direct mutations to LocalInvoice status constants or properties
                $patterns = [
                    '/\$inv(?:oice)?->status\s*=\s*/',
                    '/[\'"]status[\'"]\s*=>\s*LocalInvoice::STATUS_/',
                    '/[\'"]status[\'"]\s*=>\s*LocalInvoiceStatus::/',
                ];

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                        $line = substr_count(substr($contents, 0, $matches[0][1]), "\n") + 1;
                        $violations[] = sprintf('%s:%d matches %s', $relativePath, $line, $pattern);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Direct LocalInvoice status mutations detected outside LocalInvoiceStateEngine:\n".implode("\n", $violations)
        );
    }
}
