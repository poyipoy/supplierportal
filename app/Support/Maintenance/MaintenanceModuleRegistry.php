<?php

namespace App\Support\Maintenance;

use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\ConversationMessageController;
use App\Http\Controllers\PortalLegacyPathController;
use App\Models\Attachment;
use App\Models\LocalPurchaseOrder;
use App\Models\MaterialClaim;
use App\Models\Message;
use App\Models\PurchaseOrder;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\SupplierAudit;
use App\Models\SupplierOverpaymentRefund;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/**
 * Fixed inventory of maintenance modules and the routes each one owns, for every
 * role except admin. A module is a business area, so it covers the supplier page
 * and the staff page of the same area. Keys are stored in
 * maintenance_record_modules, so never rename one; add a new key instead.
 * tests/Feature/Maintenance/MaintenanceModuleRegistryTest.php fails when a route a
 * non-admin role can reach is not classified here.
 */
final class MaintenanceModuleRegistry
{
    public const SCOPE_IMPORT = 'import';

    public const SCOPE_LOCAL = 'local';

    /** Module key => core scope, in sidebar order. */
    public const MODULES = [
        'import.quotations' => self::SCOPE_IMPORT,
        'import.purchase_requests' => self::SCOPE_IMPORT,
        'import.purchase_orders' => self::SCOPE_IMPORT,
        'import.shipments' => self::SCOPE_IMPORT,
        'import.chat' => self::SCOPE_IMPORT,
        'import.material_claims' => self::SCOPE_IMPORT,
        'import.price_history' => self::SCOPE_IMPORT,
        'import.export_history' => self::SCOPE_IMPORT,
        'import.adasi_information' => self::SCOPE_IMPORT,
        'import.company_profile' => self::SCOPE_IMPORT,
        'local.invoices' => self::SCOPE_LOCAL,
        'local.purchase_orders' => self::SCOPE_LOCAL,
        'local.vendor_profile' => self::SCOPE_LOCAL,
        'local.supplier_audit' => self::SCOPE_LOCAL,
        'local.adasi_information' => self::SCOPE_LOCAL,
    ];

    /**
     * Cores each staff role works in. A module only blocks a role that works in its
     * core, so a shared route (exports, attachments) never stops the other core.
     * Suppliers are not listed: they hold their cores through supplier_scopes.
     */
    private const ROLE_SCOPES = [
        'purchasing' => [self::SCOPE_IMPORT, self::SCOPE_LOCAL],
        'qc' => [self::SCOPE_IMPORT],
        'finance' => [self::SCOPE_LOCAL],
        'accounting' => [self::SCOPE_LOCAL],
        'ga' => [self::SCOPE_LOCAL],
    ];

    /**
     * Routes whose owner differs from their URL prefix. An export dispatch needs
     * both its list module and the export pipeline, so either one blocks it.
     */
    private const EXACT_ROUTES = [
        'local-supplier.purchase-orders.search' => ['local.invoices'],
        'supplier.export.quotations' => ['import.quotations', 'import.export_history'],
        'supplier.export.quotations.detail' => ['import.quotations', 'import.export_history'],
        'supplier.export.purchase-orders' => ['import.purchase_orders', 'import.export_history'],
        'supplier.export.purchase-orders.detail' => ['import.purchase_orders', 'import.export_history'],
        'supplier.price-history.export' => ['import.price_history', 'import.export_history'],
        'purchasing.export.requisitions' => ['import.purchase_requests', 'import.export_history'],
        'purchasing.export.requisitions.detail' => ['import.purchase_requests', 'import.export_history'],
        'purchasing.export.quotations' => ['import.quotations', 'import.export_history'],
        'purchasing.export.quotations.detail' => ['import.quotations', 'import.export_history'],
        'purchasing.export.purchase-orders' => ['import.purchase_orders', 'import.export_history'],
        'purchasing.export.purchase-orders.detail' => ['import.purchase_orders', 'import.export_history'],
        'purchasing.export.shipments' => ['import.shipments', 'import.export_history'],
        'purchasing.export.supplier-audits.detail' => ['local.supplier_audit'],
        'shared.pdf.purchase-order' => ['import.purchase_orders'],
        'local-supplier.information' => ['local.adasi_information'],
    ];

    /** First match wins, so a specific pattern must come before a broader one. */
    private const ROUTE_PATTERNS = [
        // Supplier side.
        'supplier.quotations.*' => 'import.quotations',
        'supplier.requests.*' => 'import.purchase_requests',
        'supplier.purchase-orders.*' => 'import.purchase_orders',
        'supplier.shipments.*' => 'import.shipments',
        'supplier.conversations.*' => 'import.chat',
        'conversations.*' => 'import.chat',
        'supplier.claims.*' => 'import.material_claims',
        'supplier.price-history.*' => 'import.price_history',
        'exports.*' => 'import.export_history',
        'export-presets.*' => 'import.export_history',
        'supplier.announcements.*' => 'import.adasi_information',
        'supplier.company-profile.*' => 'import.company_profile',
        'local-supplier.invoices.*' => 'local.invoices',
        'local-invoice-documents.*' => 'local.invoices',
        'local-supplier.purchase-orders.*' => 'local.purchase_orders',
        'local-supplier.vendor-profile.*' => 'local.vendor_profile',
        'supplier-master-documents.*' => 'local.vendor_profile',
        'local-supplier.supplier-audits.*' => 'local.supplier_audit',

        // Staff side of the same business areas.
        'purchasing.requisitions.*' => 'import.purchase_requests',
        'purchasing.pr-items.*' => 'import.purchase_requests',
        'purchasing.periods.*' => 'import.purchase_requests',
        'purchasing.quotations.*' => 'import.quotations',
        'purchasing.comparison.historical*' => 'import.price_history',
        'purchasing.comparison.vs-best*' => 'import.price_history',
        'purchasing.comparison.*' => 'import.quotations',
        'purchasing.purchase-orders.*' => 'import.purchase_orders',
        'purchasing.po-documents.*' => 'import.purchase_orders',
        'purchasing.shipments.*' => 'import.shipments',
        'purchasing.claims.*' => 'import.material_claims',
        'purchasing.conversations.*' => 'import.chat',
        'purchasing.import-suppliers.*' => 'import.company_profile',
        'purchasing.supplier-audits.*' => 'local.supplier_audit',
        'purchasing.local-vendors.*' => 'local.vendor_profile',
        'finance.vendor-master.*' => 'local.vendor_profile',
        'finance.vendor-change-requests.*' => 'local.vendor_profile',
        'purchasing.local-invoices.*' => 'local.invoices',
        'finance.invoices.*' => 'local.invoices',
        'finance.master-invoices*' => 'local.invoices',
        'accounting.invoices.*' => 'local.invoices',
        'accounting.physical-verification' => 'local.invoices',
        'accounting.payment-schedule' => 'local.invoices',
        'accounting.reports*' => 'local.invoices',
        'purchasing.local-procurement.*' => 'local.purchase_orders',
        'finance.local-procurement.*' => 'local.purchase_orders',
    ];

    /** Unnamed routes are classified by controller. */
    private const ACTION_MODULES = [
        ConversationMessageController::class => 'import.chat',
    ];

    /** attachments.show belongs to the module of the attachable it serves. */
    private const ATTACHMENT_MODULES = [
        Message::class => 'import.chat',
        Quotation::class => 'import.quotations',
        QuotationItem::class => 'import.quotations',
        PurchaseOrder::class => 'import.purchase_orders',
        Shipment::class => 'import.shipments',
        ShipmentDocument::class => 'import.shipments',
        MaterialClaim::class => 'import.material_claims',
        QcInspection::class => 'import.material_claims',
        LocalPurchaseOrder::class => 'local.purchase_orders',
        SupplierOverpaymentRefund::class => 'local.invoices',
        SupplierAudit::class => 'local.supplier_audit',
    ];

    /** Sign-in, account security, public receipts and public registration never stop for maintenance. */
    private const ALWAYS_ALLOWED_ROUTES = [
        'login', 'login.store', 'logout',
        'password.*', 'two-factor.*', 'sso.*', 'passport.*', 'locale.*', 'session.*',
        'profile.security', 'profile.two-factor.*', 'profile.sessions.revoke', 'profile.logout-other-devices',
        'supplier.register', 'supplier.register.*', 'supplier.registration.*',
        'receipts.verify-*',
    ];

    private const ALWAYS_ALLOWED_ACTIONS = [
        ConfirmablePasswordController::class,
        BroadcastController::class,
        PortalLegacyPathController::class,
    ];

    /** Portal navigation: blocked by Global Maintenance only. */
    private const PORTAL_ROUTES = [
        'dashboard', 'supplier.dashboard', 'local-supplier.dashboard',
        'supplier-context.*', 'portal.page', 'notifications.*', 'profile.*',
    ];

    /**
     * Staff areas with no module of their own (payments, QC, GA, master data,
     * registration review). Global Maintenance blocks them; modules never do. Keep
     * this after every module pattern so new routes under a module prefix follow
     * their module first.
     */
    private const STAFF_PORTAL_ROUTES = [
        'purchasing.*', 'qc.*', 'finance.*', 'ga.*', 'accounting.*',
        'supplier-registrations.*', 'ga-claim-documents.*', 'shared.pdf.*',
    ];

    public function keys(): array
    {
        return array_keys(self::MODULES);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, self::MODULES);
    }

    public function scopeOf(string $key): ?string
    {
        return self::MODULES[$key] ?? null;
    }

    public function label(string $key): string
    {
        return $this->has($key) ? __('maintenance.modules.'.$key) : $key;
    }

    public function scopeLabel(string $scope): string
    {
        return __('maintenance.scopes.'.$scope);
    }

    /** @return array<string, list<string>> scope => module keys */
    public function grouped(): array
    {
        $groups = [self::SCOPE_IMPORT => [], self::SCOPE_LOCAL => []];
        foreach (self::MODULES as $key => $scope) {
            $groups[$scope][] = $key;
        }

        return $groups;
    }

    /**
     * Cores whose modules can block this user. A role outside the map (or a
     * supplier without scopes) is never blocked by a module, but Global
     * Maintenance still blocks it.
     *
     * @return list<string>
     */
    public function scopesFor(User $user): array
    {
        if ($user->isSupplier()) {
            return array_values(array_filter(
                [self::SCOPE_IMPORT, self::SCOPE_LOCAL],
                fn (string $scope): bool => $user->hasSupplierScope($scope),
            ));
        }

        return self::ROLE_SCOPES[$user->role] ?? [];
    }

    public function classify(Request $request): RouteClassification
    {
        $route = $request->route();

        return $route instanceof Route ? $this->classifyRoute($route) : RouteClassification::unmapped();
    }

    public function classifyRoute(Route $route): RouteClassification
    {
        $name = (string) $route->getName();
        $controller = $this->controllerClass($route);

        if ($name !== '' && Str::is(self::ALWAYS_ALLOWED_ROUTES, $name)) {
            return RouteClassification::alwaysAllowed();
        }
        if ($name === '' && in_array($controller, self::ALWAYS_ALLOWED_ACTIONS, true)) {
            return RouteClassification::alwaysAllowed();
        }
        // The unnamed root closure only redirects to the login page.
        if ($name === '' && $route->uri() === '/') {
            return RouteClassification::alwaysAllowed();
        }

        if ($name === 'attachments.show') {
            $attachment = $route->hasParameters() ? $route->parameter('attachment') : null;
            $module = $attachment instanceof Attachment ? (self::ATTACHMENT_MODULES[$attachment->attachable_type] ?? null) : null;

            return $module === null ? RouteClassification::portal() : RouteClassification::modules([$module]);
        }

        if (isset(self::EXACT_ROUTES[$name])) {
            return RouteClassification::modules(self::EXACT_ROUTES[$name]);
        }

        if ($name !== '') {
            foreach (self::ROUTE_PATTERNS as $pattern => $module) {
                if (Str::is($pattern, $name)) {
                    return RouteClassification::modules([$module]);
                }
            }
        }

        if ($controller !== null && isset(self::ACTION_MODULES[$controller])) {
            return RouteClassification::modules([self::ACTION_MODULES[$controller]]);
        }

        if ($name !== '' && (Str::is(self::PORTAL_ROUTES, $name) || Str::is(self::STAFF_PORTAL_ROUTES, $name))) {
            return RouteClassification::portal();
        }

        return RouteClassification::unmapped();
    }

    private function controllerClass(Route $route): ?string
    {
        $action = $route->getActionName();
        if ($action === 'Closure' || $action === '') {
            return null;
        }

        return Str::before($action, '@');
    }
}
