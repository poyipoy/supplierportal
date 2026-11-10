<?php

namespace Tests\Feature\Maintenance;

use App\Models\Attachment;
use App\Models\LocalPurchaseOrder;
use App\Models\Message;
use App\Models\QcInspection;
use App\Models\SupplierAudit;
use App\Models\SupplierOverpaymentRefund;
use App\Models\User;
use App\Support\Maintenance\MaintenanceModuleRegistry;
use App\Support\Maintenance\RouteClassification;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MaintenanceModuleRegistryTest extends TestCase
{
    private MaintenanceModuleRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = app(MaintenanceModuleRegistry::class);
    }

    public function test_every_route_a_non_admin_role_can_reach_is_classified(): void
    {
        $unmapped = [];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            if (! $this->reachableByNonAdmin($route)) {
                continue;
            }
            $checked++;
            if ($this->registry->classifyRoute($route)->kind === RouteClassification::UNMAPPED) {
                $unmapped[] = implode('|', $route->methods()).' '.$route->uri().' '.($route->getName() ?? $route->getActionName());
            }
        }

        $this->assertGreaterThan(200, $checked);
        $this->assertSame([], $unmapped, 'Classify these routes in MaintenanceModuleRegistry.');
    }

    public function test_every_supplier_module_route_group_maps_to_its_owner(): void
    {
        $expectations = [
            'supplier.quotations.index' => ['import.quotations'],
            'supplier.requests.show' => ['import.purchase_requests'],
            'supplier.purchase-orders.item-progress.update' => ['import.purchase_orders'],
            'shared.pdf.purchase-order' => ['import.purchase_orders'],
            'supplier.shipments.mtc.store' => ['import.shipments'],
            'supplier.conversations.show' => ['import.chat'],
            'conversations.messages.store' => ['import.chat'],
            'supplier.claims.respond' => ['import.material_claims'],
            'supplier.price-history.historical' => ['import.price_history'],
            'exports.download' => ['import.export_history'],
            'export-presets.store' => ['import.export_history'],
            'supplier.announcements.show' => ['import.adasi_information'],
            'supplier.company-profile.contact-requests.store' => ['import.company_profile'],
            'supplier.export.quotations' => ['import.quotations', 'import.export_history'],
            'supplier.export.purchase-orders.detail' => ['import.purchase_orders', 'import.export_history'],
            'supplier.price-history.export' => ['import.price_history', 'import.export_history'],
            'local-supplier.invoices.store' => ['local.invoices'],
            'local-supplier.purchase-orders.search' => ['local.invoices'],
            'local-invoice-documents.show' => ['local.invoices'],
            'local-supplier.purchase-orders.goods-receipts.data' => ['local.purchase_orders'],
            'local-supplier.vendor-profile.documents.upload' => ['local.vendor_profile'],
            'supplier-master-documents.show' => ['local.vendor_profile'],
            'local-supplier.supplier-audits.autosave' => ['local.supplier_audit'],
            'local-supplier.information' => ['local.adasi_information'],
        ];

        $this->assertModules($expectations);
    }

    public function test_staff_routes_map_to_the_module_of_their_area(): void
    {
        $this->assertModules([
            'purchasing.requisitions.index' => ['import.purchase_requests'],
            'purchasing.pr-items.store' => ['import.purchase_requests'],
            'purchasing.periods.index' => ['import.purchase_requests'],
            'purchasing.export.requisitions' => ['import.purchase_requests', 'import.export_history'],
            'purchasing.quotations.accept' => ['import.quotations'],
            'purchasing.export.quotations.detail' => ['import.quotations', 'import.export_history'],
            'purchasing.comparison.inter-supplier' => ['import.quotations'],
            'purchasing.comparison.save-awards' => ['import.quotations'],
            'purchasing.comparison.historical' => ['import.price_history'],
            'purchasing.comparison.vs-best.data' => ['import.price_history'],
            'purchasing.purchase-orders.consolidate-awards' => ['import.purchase_orders'],
            'purchasing.po-documents.update' => ['import.purchase_orders'],
            'purchasing.export.purchase-orders' => ['import.purchase_orders', 'import.export_history'],
            'purchasing.shipments.confirm-arrival' => ['import.shipments'],
            'purchasing.export.shipments' => ['import.shipments', 'import.export_history'],
            'purchasing.claims.resolve' => ['import.material_claims'],
            'purchasing.conversations.show' => ['import.chat'],
            'purchasing.import-suppliers.index' => ['import.company_profile'],
            'purchasing.supplier-audits.index' => ['local.supplier_audit'],
            'purchasing.export.supplier-audits.detail' => ['local.supplier_audit'],
            'purchasing.local-vendors.index' => ['local.vendor_profile'],
            'purchasing.local-invoices.show' => ['local.invoices'],
            'purchasing.local-procurement.import.po.preview' => ['local.purchase_orders'],
            'finance.vendor-master.index' => ['local.vendor_profile'],
            'finance.vendor-change-requests.approve' => ['local.vendor_profile'],
            'finance.invoices.verify-section-a' => ['local.invoices'],
            'finance.master-invoices.export' => ['local.invoices'],
            'finance.local-procurement.goods-receipts.store' => ['local.purchase_orders'],
            'accounting.invoices.index' => ['local.invoices'],
            'accounting.payment-schedule' => ['local.invoices'],
            'accounting.physical-verification' => ['local.invoices'],
            'accounting.reports.export' => ['local.invoices'],
        ]);
    }

    public function test_staff_areas_without_a_module_are_blocked_by_global_maintenance_only(): void
    {
        foreach ([
            'purchasing.dashboard', 'purchasing.drp.supplier', 'purchasing.reports.index', 'purchasing.master-data.exchange-rates.index',
            'finance.dashboard', 'finance.drp.supplier', 'finance.vouchers.show', 'finance.settlements.primary', 'finance.overpayments.index',
            'finance.ga-claims.index', 'ga.dashboard', 'ga.claims.index', 'ga-claim-documents.show',
            'qc.dashboard', 'qc.inspections.show', 'accounting.dashboard',
            'supplier-registrations.index', 'shared.pdf.qc-inspection',
        ] as $name) {
            $this->assertSame(RouteClassification::PORTAL, $this->registry->classifyRoute(Route::getRoutes()->getByName($name))->kind, $name);
        }
    }

    public function test_sign_in_account_security_public_receipts_and_registration_are_always_allowed(): void
    {
        foreach ([
            'login', 'login.store', 'logout', 'password.confirm', 'password.update', 'two-factor.challenge',
            'profile.security', 'profile.two-factor.setup', 'profile.sessions.revoke', 'session.status', 'locale.switch',
            'supplier.register', 'supplier.register.store', 'supplier.registration.status', 'supplier.registration.access',
            'receipts.verify-supplier', 'receipts.verify-ga',
        ] as $name) {
            $this->assertTrue($this->registry->classifyRoute(Route::getRoutes()->getByName($name))->isAlwaysAllowed(), $name);
        }

        $root = collect(Route::getRoutes()->getRoutes())->first(
            fn (RoutingRoute $route): bool => $route->uri() === '/' && $route->getName() === null && in_array('GET', $route->methods(), true),
        );
        $this->assertNotNull($root);
        $this->assertTrue($this->registry->classifyRoute($root)->isAlwaysAllowed());

        foreach (['dashboard', 'supplier.dashboard', 'local-supplier.dashboard', 'supplier-context.store', 'notifications.summary', 'profile.edit'] as $name) {
            $this->assertSame(RouteClassification::PORTAL, $this->registry->classifyRoute(Route::getRoutes()->getByName($name))->kind, $name);
        }
    }

    public function test_attachments_belong_to_the_module_of_their_attachable(): void
    {
        $route = Route::getRoutes()->getByName('attachments.show');
        $cases = [
            Message::class => 'import.chat',
            QcInspection::class => 'import.material_claims',
            LocalPurchaseOrder::class => 'local.purchase_orders',
            SupplierOverpaymentRefund::class => 'local.invoices',
            SupplierAudit::class => 'local.supplier_audit',
        ];

        foreach ($cases as $type => $module) {
            $bound = clone $route;
            $bound->parameters = ['attachment' => new Attachment(['attachable_type' => $type])];
            $this->assertSame([$module], $this->registry->classifyRoute($bound)->modules, $type);
        }

        $this->assertSame(RouteClassification::PORTAL, $this->registry->classifyRoute($route)->kind);
    }

    public function test_module_scopes_follow_the_role_that_works_in_the_core(): void
    {
        $scopes = fn (string $role): array => $this->registry->scopesFor((new User)->forceFill(['role' => $role]));

        $this->assertSame(['import', 'local'], $scopes('purchasing'));
        $this->assertSame(['import'], $scopes('qc'));
        $this->assertSame(['local'], $scopes('finance'));
        $this->assertSame(['local'], $scopes('accounting'));
        $this->assertSame(['local'], $scopes('ga'));
        $this->assertSame([], $scopes('admin'));
    }

    public function test_module_keys_are_scoped_and_labelled_in_both_locales(): void
    {
        $this->assertCount(15, $this->registry->keys());
        $this->assertCount(10, $this->registry->grouped()[MaintenanceModuleRegistry::SCOPE_IMPORT]);
        $this->assertCount(5, $this->registry->grouped()[MaintenanceModuleRegistry::SCOPE_LOCAL]);
        $this->assertNotContains('import.reference_prices', $this->registry->keys());

        foreach ($this->registry->keys() as $key) {
            $this->assertMatchesRegularExpression('/\A(import|local)\.[a-z_]+\z/', $key);
            $this->assertSame(explode('.', $key)[0], $this->registry->scopeOf($key));
            foreach (['en', 'id'] as $locale) {
                $this->assertTrue(Lang::has('maintenance.modules.'.$key, $locale, false), "{$key} [{$locale}]");
            }
        }
    }

    /** @param array<string, list<string>> $expectations route name => module keys */
    private function assertModules(array $expectations): void
    {
        foreach ($expectations as $name => $modules) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $classification = $this->registry->classifyRoute($route);
            $this->assertSame(RouteClassification::MODULE, $classification->kind, $name);
            $this->assertSame($modules, $classification->modules, $name);
        }
    }

    /** Behind auth, and not limited to the admin role (role middleware lists intersect). */
    private function reachableByNonAdmin(RoutingRoute $route): bool
    {
        $name = (string) $route->getName();
        $middleware = $route->gatherMiddleware();
        $roleSets = collect($middleware)
            ->filter(fn ($item) => is_string($item) && str_starts_with($item, 'role:'))
            ->map(fn (string $item): array => explode(',', substr($item, 5)))
            ->values();

        if (str_starts_with($name, 'supplier.') || str_starts_with($name, 'local-supplier.')) {
            return true;
        }
        if ($roleSets->isNotEmpty()) {
            $allowed = $roleSets->reduce(fn (?array $carry, array $set): array => $carry === null ? $set : array_values(array_intersect($carry, $set)));

            return array_diff($allowed, ['admin']) !== [];
        }

        return in_array('auth', $middleware, true);
    }
}
