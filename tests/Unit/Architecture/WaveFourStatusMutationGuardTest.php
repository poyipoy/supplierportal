<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class WaveFourStatusMutationGuardTest extends TestCase
{
    /**
     * Ensure no controller or service directly mutates SupplierAudit,
     * SupplierRegistrationAttempt, SupplierChangeRequest, or SupplierBankAccount
     * status bypassing their respective StateEngine.
     */
    public function test_no_controller_or_service_mutates_wave_four_statuses_directly(): void
    {
        $basePath = dirname(__DIR__, 3);
        $scanDirs = [
            $basePath.'/app/Http/Controllers',
            $basePath.'/app/Services',
        ];

        $allowedFiles = [
            'SupplierAuditStateEngine.php',
            'SupplierRegistrationAttemptStateEngine.php',
            'SupplierChangeRequestStateEngine.php',
            'SupplierBankAccountStateEngine.php',
            'LocalInvoiceStateEngine.php',
            'LocalGoodsReceiptStateEngine.php',
            'PaymentBatchStateEngine.php',
            'GaClaimStateEngine.php',
            'LocalPurchaseOrderStateEngine.php',
            'PaymentGroupStateEngine.php',
            'LocalInvoicePaymentStateEngine.php',
            'PurchaseOrderStateEngine.php',
            'ShipmentStateEngine.php',
            'QcInspectionStateEngine.php',
            'MaterialClaimStateEngine.php',
            'PurchaseRequisitionStateEngine.php',
            'QuotationStateEngine.php',
        ];

        $domainKeywords = [
            'SupplierAudit',
            'SupplierRegistrationAttempt',
            'SupplierChangeRequest',
            'SupplierBankAccount',
        ];

        $violations = [];

        foreach ($scanDirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                if (in_array($file->getFilename(), $allowedFiles, true)) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                $touchesDomain = false;
                foreach ($domainKeywords as $keyword) {
                    if (str_contains($contents, $keyword)) {
                        $touchesDomain = true;
                        break;
                    }
                }

                if (! $touchesDomain) {
                    continue;
                }

                $patterns = [
                    '/\$(?:audit|locked|attempt|lastAttempt|changeRequest|req|pendingRequest|bankAccount|account)\s*->\s*status\s*=(?!=)\s*[^;]+;/',
                    '/\$(?:audit|locked|attempt|lastAttempt|changeRequest|req|pendingRequest|bankAccount|account)\s*->\s*(?:update|forceFill)\s*\(\s*\[[^\]]*[\'"]status[\'"]\s*=>/s',
                    '/(?:SupplierAudit|SupplierRegistrationAttempt|SupplierChangeRequest|SupplierBankAccount)::(?:query\(\)\s*->\s*)?where[^\n;]+->\s*update\s*\(\s*\[[^\]]*[\'"]status[\'"]\s*=>/s',
                ];

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $contents, $matches)) {
                        $relativePath = str_replace($basePath.DIRECTORY_SEPARATOR, '', $file->getPathname());
                        $violations[] = sprintf('%s: %s', $relativePath, trim($matches[0]));
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Direct Wave 4 status mutations detected outside StateEngines:\n".implode("\n", $violations)
        );
    }
}
