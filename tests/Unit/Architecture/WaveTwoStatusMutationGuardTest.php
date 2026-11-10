<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class WaveTwoStatusMutationGuardTest extends TestCase
{
    public function test_no_controller_or_external_service_mutates_wave_two_statuses_directly(): void
    {
        $appPath = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'app';
        $allowedFiles = [
            realpath($appPath.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'LocalInvoice'.DIRECTORY_SEPARATOR.'LocalGoodsReceiptStateEngine.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'LocalInvoice'.DIRECTORY_SEPARATOR.'LocalPurchaseOrderStateEngine.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'LocalInvoice'.DIRECTORY_SEPARATOR.'LocalProcurementMasterService.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'LocalInvoice'.DIRECTORY_SEPARATOR.'LocalGrImportService.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'LocalInvoice'.DIRECTORY_SEPARATOR.'LocalPoImportService.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'LocalInvoice'.DIRECTORY_SEPARATOR.'LocalPoDocumentBatchService.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'Payment'.DIRECTORY_SEPARATOR.'PaymentBatchStateEngine.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'Payment'.DIRECTORY_SEPARATOR.'PaymentGroupStateEngine.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'Payment'.DIRECTORY_SEPARATOR.'LocalInvoicePaymentStateEngine.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'Payment'.DIRECTORY_SEPARATOR.'PaymentBatchService.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'Payment'.DIRECTORY_SEPARATOR.'LocalInvoicePaymentService.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'Ga'.DIRECTORY_SEPARATOR.'GaClaimStateEngine.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'Ga'.DIRECTORY_SEPARATOR.'GaClaimService.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Casts'.DIRECTORY_SEPARATOR.'LocalGoodsReceiptStatusCast.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Casts'.DIRECTORY_SEPARATOR.'LocalPurchaseOrderStatusCast.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Casts'.DIRECTORY_SEPARATOR.'PaymentBatchStatusCast.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Casts'.DIRECTORY_SEPARATOR.'PaymentGroupStatusCast.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Casts'.DIRECTORY_SEPARATOR.'LocalInvoicePaymentStatusCast.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Casts'.DIRECTORY_SEPARATOR.'GaClaimStatusCast.php'),
        ];

        $violations = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appPath));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $realPath = $file->getRealPath();
            $contents = (string) file_get_contents($realPath);

            // Only inspect files that reference Wave 2 / 2B domain models
            if (! str_contains($contents, 'LocalGoodsReceipt')
                && ! str_contains($contents, 'LocalPurchaseOrder')
                && ! str_contains($contents, 'PaymentBatch')
                && ! str_contains($contents, 'PaymentGroup')
                && ! str_contains($contents, 'LocalInvoicePayment')
                && ! str_contains($contents, 'GaClaim')) {
                continue;
            }

            if (in_array($realPath, $allowedFiles, true)) {
                // In creation services, only initial creation status assignment is permitted;
                // no ->update(['status' => ...]) on Wave 2 / 2B models
                if (str_ends_with($realPath, 'LocalProcurementMasterService.php')
                    || str_ends_with($realPath, 'PaymentBatchService.php')
                    || str_ends_with($realPath, 'LocalInvoicePaymentService.php')
                    || str_ends_with($realPath, 'GaClaimService.php')) {
                    if (preg_match('/->update\(\s*\[[^\]]*[\'"]status[\'"]\s*=>\s*(?:LocalGoodsReceipt|LocalPurchaseOrder|PaymentBatch|PaymentGroup|LocalInvoicePayment|GaClaim)::/s', $contents)) {
                        $violations[] = $realPath.' (contains direct ->update([\'status\' => ...]) on Wave 2/2B model)';
                    }
                }

                continue;
            }

            $patterns = [
                '/\$(?:receipt|gr|goodsReceipt|po|purchaseOrder|batch|lockedBatch|b|group|grp|payment|lockedPayment|claim|clm)\s*->\s*status\s*=\s*[^;=]/',
                '/\$(?:receipt|gr|goodsReceipt|po|purchaseOrder|batch|lockedBatch|b|group|grp|payment|lockedPayment|claim|clm)\s*->\s*(?:update|forceFill|fill)\s*\(\s*\[[^\]]*[\'"]status[\'"]\s*=>/s',
                '/DB::table\(\s*[\'"](?:local_goods_receipts|local_purchase_orders|payment_batches|payment_groups|local_invoice_payments|ga_claims)[\'"]\s*\)[^;]*->\s*update\s*\(\s*\[[^\]]*[\'"]status[\'"]\s*=>/s',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $contents, $matches)) {
                    $violations[] = $realPath.': '.trim($matches[0]);
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Direct Wave 2 status mutations found outside StateEngines:\n".implode("\n", $violations)
        );
    }
}
