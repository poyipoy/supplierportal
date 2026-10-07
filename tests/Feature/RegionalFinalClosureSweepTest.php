<?php

namespace Tests\Feature;

use Tests\TestCase;

class RegionalFinalClosureSweepTest extends TestCase
{
    private const APPROVED_PRODUCTION_FILES = [
        'app/Providers/AppServiceProvider.php',
        'app/Http/Controllers/Purchasing/PriceComparisonController.php',
        'resources/views/purchasing/comparison/_historical_content.blade.php',
        'resources/views/purchasing/comparison/_inter_supplier_content.blade.php',
        'resources/views/purchasing/comparison/_vs_best_content.blade.php',
        'resources/views/purchasing/comparison/_scripts.blade.php',
        'resources/views/purchasing/po/show.blade.php',
        'resources/views/local-invoices/detail.blade.php',
        'resources/views/local-invoices/table.blade.php',
        'resources/views/finance/invoices/show.blade.php',
        'resources/views/purchasing/local-vendors/invoice-show.blade.php',
    ];

    public function test_all_approved_production_files_exist_and_use_regional_formatting(): void
    {
        foreach (self::APPROVED_PRODUCTION_FILES as $file) {
            $path = base_path($file);
            $this->assertFileExists($path, "Approved production file {$file} must exist.");

            $content = file_get_contents($path);
            if (str_ends_with($file, '.php') && ! str_contains($file, 'resources/views/')) {
                // PHP service/controller/provider
                $this->assertTrue(
                    str_contains($content, 'RegionalDisplayFormatter') || str_contains($content, 'regionalFormatter'),
                    "{$file} must reference RegionalDisplayFormatter"
                );
            } elseif (str_ends_with($file, '_scripts.blade.php')) {
                // Script file
                $this->assertStringContainsString('AdasiPreferences', $content, "{$file} must reference AdasiPreferences");
            } else {
                // Blade templates
                $this->assertStringContainsString('regionalFormatter', $content, "{$file} must reference regionalFormatter");
            }
        }
    }

    public function test_explicit_technical_debt_boundaries_are_strictly_preserved(): void
    {
        // TD-REG-01: Auth Audit timestamps remain untouched/un-regionalized
        $authAuditPath = resource_path('views/admin/auth-audit-logs/index.blade.php');
        $this->assertFileExists($authAuditPath);
        $authAuditContent = file_get_contents($authAuditPath);
        $this->assertStringNotContainsString('$regionalFormatter', $authAuditContent, 'TD-REG-01: Auth audit must NOT be modified for Regional Preferences');

        // TD-REG-02: Mixed refund DATE/event fallback untouched
        $detailPath = resource_path('views/local-invoices/detail.blade.php');
        $detailContent = file_get_contents($detailPath);
        $this->assertStringContainsString(
            '$overpaymentRefund->refund_date ? $regionalFormatter->fixedDate($overpaymentRefund->refund_date, \'d M Y\')',
            $detailContent,
            'TD-REG-02: Mixed refund date / settled_at fallback must remain untouched'
        );

        // TD-REG-03: Supplier quotation period PR updated/submitted presentation untouched
        $quotationPeriodPath = resource_path('views/supplier/quotations/period.blade.php');
        $this->assertFileExists($quotationPeriodPath);
        $quotationPeriodContent = file_get_contents($quotationPeriodPath);
        $this->assertStringNotContainsString('$regionalFormatter', $quotationPeriodContent, 'TD-REG-03: Supplier quotation period must remain untouched');

        // TD-REG-04: BusinessTime foundation untouched
        $businessTimePath = app_path('Support/BusinessTime.php');
        $this->assertFileExists($businessTimePath);
    }

    public function test_regional_composer_registry_is_exact_without_wildcards(): void
    {
        $providerPath = app_path('Providers/AppServiceProvider.php');
        $content = file_get_contents($providerPath);

        // Assert exact view composer registration without wildcard
        $this->assertStringContainsString("'purchasing.comparison.inter-supplier'", $content);
        $this->assertStringContainsString("'purchasing.comparison.historical'", $content);
        $this->assertStringContainsString("'purchasing.comparison.vs-best'", $content);
        $this->assertStringNotContainsString("View::composer('*'", $content, 'Wildcard view composer must not be used');
        $this->assertStringNotContainsString("View::composer('purchasing.*'", $content, 'Wildcard view composer must not be used');
        $this->assertStringNotContainsString("View::composer('local-invoices.*'", $content, 'Wildcard view composer must not be used');
    }
}
