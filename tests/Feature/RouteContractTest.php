<?php

namespace Tests\Feature;

use App\Http\Controllers\Finance\LocalProcurementController;
use App\Http\Controllers\Ga\GaController;
use App\Http\Controllers\LocalInvoiceReceiptController;
use App\Http\Controllers\LocalSupplier\InvoiceController;
use App\Http\Controllers\Purchasing\AwardConsolidationController;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RouteContractTest extends TestCase
{
    #[DataProvider('staticRoutes')]
    public function test_static_urls_match_their_intended_action(string $uri, string $name, string $action): void
    {
        $route = app('router')->getRoutes()->match(Request::create($uri, 'GET'));

        $this->assertSame($name, $route->getName());
        $this->assertSame($action, $route->getActionName());
    }

    public static function staticRoutes(): array
    {
        return [
            'local PO search' => ['/local-supplier/purchase-orders/search', 'local-supplier.purchase-orders.search', InvoiceController::class.'@searchPurchaseOrders'],
            'finance PO template' => ['/finance/local-procurement/import/po/template', 'finance.local-procurement.import.po.template', LocalProcurementController::class.'@poTemplate'],
            'purchasing GR template' => ['/purchasing/local-procurement/import/gr/template', 'purchasing.local-procurement.import.gr.template', LocalProcurementController::class.'@grTemplate'],
            'GA claim creation' => ['/ga/claims/create', 'ga.claims.create', GaController::class.'@create'],
            'award consolidation' => ['/purchasing/purchase-orders/consolidate-awards', 'purchasing.purchase-orders.consolidate-awards', AwardConsolidationController::class.'@create'],
        ];
    }

    public function test_receipts_keep_their_cross_role_access_without_expanding_the_main_groups(): void
    {
        $routes = app('router')->getRoutes();

        $financeReceipt = $routes->getByName('finance.invoices.receipt');
        $this->assertSame(LocalInvoiceReceiptController::class.'@show', $financeReceipt->getActionName());
        $this->assertSame(['web', 'auth', 'role:finance,admin,purchasing'], $financeReceipt->gatherMiddleware());
        $this->assertSame(['web', 'auth', 'role:finance,admin'], $routes->getByName('finance.invoices.show')->gatherMiddleware());

        $gaReceipt = $routes->getByName('ga.claims.receipt');
        $this->assertSame(GaController::class.'@receipt', $gaReceipt->getActionName());
        $this->assertSame(['web', 'auth', 'role:ga,admin,finance'], $gaReceipt->gatherMiddleware());
        $this->assertSame(['web', 'auth', 'role:ga,admin'], $routes->getByName('ga.claims.show')->gatherMiddleware());
    }

    public function test_password_assistance_is_available_without_a_guest_or_auth_guard(): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/forgot-password', 'GET'));

        $this->assertSame('password.request', $route->getName());
        $this->assertSame(['web', 'no-store'], $route->gatherMiddleware());
    }
}
