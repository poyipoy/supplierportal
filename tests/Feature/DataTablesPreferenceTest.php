<?php

namespace Tests\Feature;

use Tests\TestCase;

class DataTablesPreferenceTest extends TestCase
{
    public function test_datatables_inherit_a_shared_preference_before_page_initializers(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $datatableLibrary = strpos($layout, 'jquery.dataTables.min.js');
        $sharedDefaults = strpos($layout, 'window.AdasiDataTable = Object.freeze');
        $pageInitializers = strrpos($layout, "@stack('scripts')");

        $this->assertNotFalse($datatableLibrary);
        $this->assertNotFalse($sharedDefaults);
        $this->assertNotFalse($pageInitializers);
        $this->assertLessThan($sharedDefaults, $datatableLibrary);
        $this->assertLessThan($pageInitializers, $sharedDefaults);
        $this->assertStringContainsString('pageLength: window.AdasiPreferences?.pageSize || 25', $layout);
        $this->assertStringContainsString('lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]]', $layout);
    }

    public function test_operational_tables_no_longer_override_the_shared_page_length(): void
    {
        $views = [
            'admin/auth-audit-logs/index.blade.php',
            'admin/material-hs-code/_script.blade.php',
            'admin/users/index.blade.php',
            'purchasing/claims/index.blade.php',
            'purchasing/comparison/_scripts.blade.php',
            'purchasing/periods/index.blade.php',
            'purchasing/po/index.blade.php',
            'purchasing/pr/index.blade.php',
            'purchasing/shipments/index.blade.php',
            'qc/inspections/index.blade.php',
            'supplier/claims/index.blade.php',
            'supplier/po/index.blade.php',
            'supplier/price-history/index.blade.php',
            'supplier/quotations/period.blade.php',
            'supplier/shipments/index.blade.php',
        ];

        foreach ($views as $view) {
            $this->assertStringNotContainsString('pageLength:', file_get_contents(resource_path('views/'.$view)), $view);
        }
    }
}
