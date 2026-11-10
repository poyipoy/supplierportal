<?php

namespace Tests\Feature\Architecture;

use Tests\TestCase;

class ContextFreshnessTest extends TestCase
{
    public function test_dead_routes_and_legacy_files_remain_absent(): void
    {
        $router = app('router');

        $this->assertNull($router->getRoutes()->getByName('finance.drp.mark-paid'), 'Route finance.drp.mark-paid must not exist');
        $this->assertNull($router->getRoutes()->getByName('register'), 'Route register must not exist');
        $this->assertNull($router->getRoutes()->getByName('ga.drp-draft'), 'Route ga.drp-draft must not exist');

        $this->assertFileDoesNotExist(app_path('Http/Controllers/Auth/RegisteredUserController.php'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Accounting/InvoiceWorkflowController.php'));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Qc/QcController.php'));
        $this->assertFileDoesNotExist(resource_path('views/auth/register.blade.php'));
    }

    public function test_security_defaults_are_strictly_enforced(): void
    {
        $this->assertTrue(
            (bool) config('auth_security.headers.csp_enforce'),
            'CSP enforcement must default to true in config/auth_security.php'
        );
    }

    public function test_context_document_remains_reconciled(): void
    {
        $contextPath = base_path('context.md');
        $this->assertFileExists($contextPath);

        $content = file_get_contents($contextPath);

        // Ensure old open discrepancy language is removed
        $this->assertStringNotContainsString(
            'points to `FinanceDrpController::markPaid`, which does not exist',
            $content,
            'context.md must not report finance.drp.mark-paid as an open bug'
        );

        // Ensure reconciled markers are present
        $this->assertStringContainsString('Corrected in v2.2', $content);
        $this->assertStringContainsString('Ready-to-Pay Realization Contract', $content);
    }
}
