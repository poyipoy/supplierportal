<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class WaveThreeStatusMutationGuardTest extends TestCase
{
    public function test_no_controller_or_service_mutates_wave_three_statuses_directly(): void
    {
        $appPath = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'app';
        $allowedFiles = [
            realpath($appPath.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'Procurement'.DIRECTORY_SEPARATOR.'PurchaseOrderStateEngine.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'Procurement'.DIRECTORY_SEPARATOR.'ShipmentStateEngine.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'Procurement'.DIRECTORY_SEPARATOR.'QcInspectionStateEngine.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'Procurement'.DIRECTORY_SEPARATOR.'MaterialClaimStateEngine.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'Procurement'.DIRECTORY_SEPARATOR.'PurchaseRequisitionStateEngine.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'Procurement'.DIRECTORY_SEPARATOR.'QuotationStateEngine.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Casts'.DIRECTORY_SEPARATOR.'PurchaseOrderStatusCast.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Casts'.DIRECTORY_SEPARATOR.'ShipmentStatusCast.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Casts'.DIRECTORY_SEPARATOR.'QcInspectionStatusCast.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Casts'.DIRECTORY_SEPARATOR.'MaterialClaimStatusCast.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Casts'.DIRECTORY_SEPARATOR.'PurchaseRequisitionStatusCast.php'),
            realpath($appPath.DIRECTORY_SEPARATOR.'Casts'.DIRECTORY_SEPARATOR.'QuotationStatusCast.php'),
        ];

        $violations = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appPath));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $realPath = $file->getRealPath();
            if (in_array($realPath, $allowedFiles, true)) {
                continue;
            }

            // Exclude Local Finance files (covered by Wave 1/2/2B guards)
            if (str_contains($realPath, 'LocalInvoice')
                || str_contains($realPath, 'LocalSupplier')
                || str_contains($realPath, 'Finance')
                || str_contains($realPath, 'Payment')
                || str_contains($realPath, 'Ga')) {
                continue;
            }

            $contents = (string) file_get_contents($realPath);

            // Only inspect files that reference Wave 3 Import Core models
            if (! str_contains($contents, 'PurchaseOrder')
                && ! str_contains($contents, 'Shipment')
                && ! str_contains($contents, 'QcInspection')
                && ! str_contains($contents, 'MaterialClaim')
                && ! str_contains($contents, 'PurchaseRequisition')
                && ! str_contains($contents, 'Quotation')) {
                continue;
            }

            $patterns = [
                '/\$(?:po|purchaseOrder|shipment|lockedShipment|inspection|claim|pr|lockedPr|quotation|lockedQuotation)\s*->\s*status\s*=\s*[^;=]/',
                '/\$(?:po|purchaseOrder|shipment|lockedShipment|inspection|claim|pr|lockedPr|quotation|lockedQuotation)\s*->\s*(?:update|forceFill|fill)\s*\(\s*\[[^\]]*[\'"]status[\'"]\s*=>/s',
                '/DB::table\(\s*[\'"](?:purchase_orders|shipments|qc_inspections|material_claims|purchase_requisitions|quotations)[\'"]\s*\)[^;]*->\s*update\s*\(\s*\[[^\]]*[\'"]status[\'"]\s*=>/s',
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
            "Direct Wave 3 status mutations found outside StateEngines:\n".implode("\n", $violations)
        );
    }
}
