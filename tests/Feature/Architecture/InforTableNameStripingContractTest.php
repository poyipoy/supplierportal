<?php

namespace Tests\Feature\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class InforTableNameStripingContractTest extends TestCase
{
    public function test_css_defines_hybrid_index_row_striping_and_detail_column_band_tokens(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertNotFalse($css);

        $utilitiesPos = strpos($css, '@import "tailwindcss/utilities.css"');
        $colRulePos = strpos($css, '.ui-col-data-name');
        $this->assertNotFalse($utilitiesPos);
        $this->assertNotFalse($colRulePos);
        $this->assertLessThan($utilitiesPos, $colRulePos, '.ui-col-data-name rules must precede Tailwind utilities');

        foreach (['--ui-table-row-stripe-bg', '--ui-table-col-name-bg', '--ui-table-row-hover-bg'] as $token) {
            $this->assertStringContainsString($token.':', $css);
        }

        $this->assertStringContainsString(':where(.ui-data-table table, .table):where(:not(.ui-quotation-readonly):not(.table-striped):not(:has(.ui-col-data-name))) > tbody > tr:where(:not(:has(> td[colspan]:only-child)):not(:has(> td.dataTables_empty)):not(.bg-primary-subtle):not(.table-primary):not(.table-light)):nth-of-type(even) > :is(td, th)', $css);
        $this->assertStringContainsString(':where(.ui-data-table table, .table) > tbody > tr:where(:not(:has(> td[colspan]:only-child)):not(:has(> td.dataTables_empty)):not(.bg-primary-subtle):not(.table-primary):not(.table-light)) > :is(td, th).ui-col-data-name', $css);
        $this->assertStringContainsString('background-color: var(--ui-table-cell-bg, var(--md-surface));', $css);
        $this->assertMatchesRegularExpression('/@media print\s*\{[^}]*--ui-table-row-stripe-bg:\s*var\(--md-surface\);[^}]*--ui-table-col-name-bg:\s*var\(--md-surface\);/s', $css);
    }

    public function test_index_queue_and_dashboard_views_use_pure_full_row_striping_without_col_data_name(): void
    {
        $indexFiles = [
            'resources/views/purchasing/po/index.blade.php',
            'resources/views/purchasing/pr/index.blade.php',
            'resources/views/purchasing/shipments/index.blade.php',
            'resources/views/purchasing/claims/index.blade.php',
            'resources/views/purchasing/periods/index.blade.php',
            'resources/views/supplier/po/index.blade.php',
            'resources/views/supplier/price-history/index.blade.php',
            'resources/views/qc/inspections/index.blade.php',
            'resources/views/admin/users/index.blade.php',
            'resources/views/admin/auth-audit-logs/index.blade.php',
            'resources/views/admin/material-hs-code/_script.blade.php',
            'resources/views/purchasing/quotations/index.blade.php',
            'resources/views/purchasing/conversations/index.blade.php',
            'resources/views/purchasing/dashboard.blade.php',
            'resources/views/purchasing/local-vendors/index.blade.php',
            'resources/views/purchasing/import-suppliers/_queue_content.blade.php',
            'resources/views/purchasing/supplier-audits/_queue_content.blade.php',
            'resources/views/local-invoices/table.blade.php',
            'resources/views/payment-vouchers/index.blade.php',
            'resources/views/finance/local-procurement/index.blade.php',
            'resources/views/finance/vendors/index.blade.php',
            'resources/views/finance/overpayments/index.blade.php',
            'resources/views/finance/ga-claims/index.blade.php',
            'resources/views/finance/drp/ga.blade.php',
            'resources/views/finance/drp/supplier.blade.php',
            'resources/views/finance/dashboard.blade.php',
            'resources/views/ga/claims/index.blade.php',
            'resources/views/ga/dashboard.blade.php',
            'resources/views/ga/employees/index.blade.php',
            'resources/views/local-supplier/supplier-audits/index.blade.php',
            'resources/views/supplier/quotations/index.blade.php',
            'resources/views/supplier/requests/index.blade.php',
            'resources/views/supplier/conversations/index.blade.php',
            'resources/views/supplier/goods-locations/index.blade.php',
            'resources/views/supplier/dashboard.blade.php',
            'resources/views/shared/outstanding-materials/_table.blade.php',
            'resources/views/admin/announcements/index.blade.php',
            'resources/views/admin/maintenance/index.blade.php',
            'resources/views/qc/dashboard.blade.php',
            'resources/views/supplier-registrations/_attempts_content.blade.php',
            'resources/views/exports/index.blade.php',
        ];

        foreach ($indexFiles as $relativePath) {
            $contents = file_get_contents(base_path($relativePath));
            $this->assertNotFalse($contents, "Missing file [$relativePath]");
            $this->assertStringNotContainsString('ui-col-data-name', $contents, "Index/Queue/Dashboard table [$relativePath] must use pure full-row alternating striping without .ui-col-data-name");
        }
    }

    public function test_detail_satellite_and_comparison_tables_mark_per_column_data_names_while_excluded_matrices_stay_flat(): void
    {
        $requiredDetailFiles = [
            'resources/views/purchasing/po/show.blade.php',
            'resources/views/supplier/po/show.blade.php',
            'resources/views/purchasing/pr/show.blade.php',
            'resources/views/admin/requisitions/show.blade.php',
            'resources/views/supplier/requests/show.blade.php',
            'resources/views/quotations/_items_readonly_table.blade.php',
            'resources/views/purchasing/quotations/show.blade.php',
            'resources/views/purchasing/comparison/_inter_supplier_content.blade.php',
            'resources/views/purchasing/comparison/_historical_content.blade.php',
            'resources/views/purchasing/comparison/_historical_pr_mode.blade.php',
            'resources/views/purchasing/comparison/_price_indicators.blade.php',
            'resources/views/purchasing/comparison/_scripts.blade.php',
            'resources/views/purchasing/shipments/show.blade.php',
            'resources/views/supplier/shipments/show.blade.php',
            'resources/views/purchasing/drp/show.blade.php',
            'resources/views/finance/drp/show.blade.php',
            'resources/views/finance/invoices/show.blade.php',
            'resources/views/finance/vendors/show.blade.php',
            'resources/views/purchasing/local-vendors/show.blade.php',
            'resources/views/local-supplier/vendor-profile/show.blade.php',
            'resources/views/local-supplier/purchase-orders/show.blade.php',
            'resources/views/local-invoices/detail.blade.php',
            'resources/views/qc/inspections/show.blade.php',
            'resources/views/purchasing/import-suppliers/show.blade.php',
            'resources/views/admin/maintenance/show.blade.php',
            'resources/views/partials/vendor-change-request-details.blade.php',
            'resources/views/supplier-audits/_answers-readonly.blade.php',
            'resources/views/procurement/_reference_snapshot_panel.blade.php',
            'resources/views/shipments/_mtc_section.blade.php',
            'resources/views/supplier-contact/_history.blade.php',
            'resources/views/purchasing/po/_mmt_satellite_content.blade.php',
            'resources/views/finance/local-procurement/_mmt_satellite_content.blade.php',
            'resources/views/finance/local-procurement/_gr_table.blade.php',
            'resources/js/local-po-gr-table.js',
        ];

        foreach ($requiredDetailFiles as $relativePath) {
            $contents = file_get_contents(base_path($relativePath));
            $this->assertNotFalse($contents, "Missing file [$relativePath]");
            $this->assertStringContainsString('ui-col-data-name', $contents, "Expected .ui-col-data-name in Detail/Satellite/Comparison file [$relativePath]");
        }

        foreach (['resources/views/purchasing/po/show.blade.php', 'resources/views/supplier/po/show.blade.php'] as $multiPrFile) {
            $contents = file_get_contents(base_path($multiPrFile));
            $this->assertNotFalse($contents);
            $this->assertStringContainsString('<tbody class="ui-po-group-banner">', $contents, "Multi-PR banner row in [$multiPrFile] must live in its own tbody");
        }

        $excludedFiles = [
            'resources/views/purchasing/pr/create.blade.php',
            'resources/views/purchasing/pr/edit.blade.php',
            'resources/views/supplier/quotations/create.blade.php',
            'resources/views/admin/exchange-rates/_annual_matrix.blade.php',
            'resources/views/finance/local-procurement/_import_gr_modal.blade.php',
            'resources/views/finance/local-procurement/_import_po_modal.blade.php',
            'resources/views/purchasing/pr/_import.blade.php',
            'resources/views/layouts/app.blade.php',
            'resources/views/profile/partials/density-preview.blade.php',
        ];

        foreach ($excludedFiles as $relativePath) {
            $contents = file_get_contents(base_path($relativePath));
            $this->assertNotFalse($contents);
            $this->assertStringNotContainsString('ui-col-data-name', $contents, "Excluded form/layout/preview table [$relativePath] must not carry .ui-col-data-name");
        }
    }

    public function test_blade_templates_never_use_col_data_name_with_legacy_tailwind_palettes(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));
        $offenders = [];

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if ($contents === false || ! str_contains($contents, 'ui-col-data-name')) {
                continue;
            }
            foreach (explode("\n", $contents) as $lineNo => $line) {
                if (! str_contains($line, 'ui-col-data-name')) {
                    continue;
                }
                if (preg_match('/tw:(?:bg|border)-(?:white|slate-\d+)/', $line)) {
                    $offenders[] = $file->getFilename().':'.($lineNo + 1).' '.$line;
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
