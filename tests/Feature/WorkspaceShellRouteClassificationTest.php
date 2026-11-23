<?php

namespace Tests\Feature;

use App\Support\PortalPath;
use App\Support\WorkspaceShell;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceShellRouteClassificationTest extends TestCase
{
    public function test_every_get_route_is_explicitly_classified_and_has_no_accidental_binary_or_security_shell_wrapping(): void
    {
        $routes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => in_array('GET', $route->methods(), true));

        $this->assertGreaterThan(200, $routes->count());

        $unclassified = [];

        foreach ($routes as $route) {
            $classification = WorkspaceShell::classifyRoute($route);

            if (! in_array($classification, [
                WorkspaceShell::CLASS_SHELL_PAGE,
                WorkspaceShell::CLASS_TOP_LEVEL_SECURITY,
                WorkspaceShell::CLASS_NON_SHELL_DIRECT,
            ], true)) {
                $unclassified[] = ($route->getName() ?? '<unnamed>').' ('.$route->uri().')';
            }

            // Password-confirmation routes must never be framed or wrapped.
            if (in_array('password.confirm', $route->gatherMiddleware(), true)) {
                $this->assertSame(
                    WorkspaceShell::CLASS_TOP_LEVEL_SECURITY,
                    $classification,
                    'Route with password.confirm middleware must stay top-level-only: '.($route->getName() ?? $route->uri()),
                );
                $this->assertFalse(WorkspaceShell::isShellEligibleRoute($route));
                $this->assertFalse(WorkspaceShell::isFrameableRoute($route));
            }
        }

        $this->assertSame([], $unclassified, 'All GET routes must have an explicit workspace classification.');
    }

    public function test_sensitive_security_routes_stay_non_frameable_and_non_shell(): void
    {
        $topLevelRoutes = [
            'login',
            'password.request',
            'password.confirm',
            'two-factor.challenge',
            'passport.authorizations.authorize',
            'sso.callback',
            'supplier.register',
            'supplier.registration.access-form',
            'supplier.registration.edit',
            'supplier.registration.status',
            'supplier.registration.success',
            'supplier.registration.document.download',
            'receipts.verify-ga',
            'receipts.verify-supplier',
            'profile.security',
            'profile.two-factor.setup',
            'supplier-context.index',
            'admin.users.create',
            'admin.users.edit',
            'finance.vendor-master.payment-term.edit',
            'locale.switch',
        ];

        foreach ($topLevelRoutes as $name) {
            $route = RouteFacade::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Expected route [{$name}] to exist.");
            $this->assertSame(WorkspaceShell::CLASS_TOP_LEVEL_SECURITY, WorkspaceShell::classifyRoute($route), $name);
            $this->assertFalse(WorkspaceShell::isShellEligibleRoute($route), $name);
            $this->assertFalse(WorkspaceShell::isFrameableRoute($route), $name);
        }
    }

    public function test_binary_json_data_export_drawer_and_redirect_routes_are_excluded_from_shell(): void
    {
        $nonShellDirectRoutes = [
            'dashboard',
            'portal.page',
            'session.status',
            'attachments.show',
            'local-invoice-documents.show',
            'ga-claim-documents.show',
            'supplier-master-documents.show',
            'supplier-registrations.document',
            'context-panel.show',
            'conversations.drawer.index',
            'conversations.drawer.show',
            'conversations.unread-count',
            'conversations.messages.latest',
            'conversations.messages.older',
            'export-presets.index',
            'exports.definitions.show',
            'exports.download',
            'exports.status',
            'notifications.summary',
            'notifications.unread-count',
            'shared.pdf.purchase-order',
            'shared.pdf.qc-inspection',
            'finance.drp.export',
            'finance.master-invoices.export',
            'finance.payment-vouchers.documents.show',
            'finance.payment-vouchers.print',
            'finance.vouchers.print',
            'finance.invoices.receipt.print',
            'accounting.invoices.receipt.print',
            'ga.claims.receipt.print',
            'local-supplier.invoices.receipt.print',
            'purchasing.payment-vouchers.documents.show',
            'purchasing.payment-vouchers.print',
            'purchasing.vouchers.print',
            'purchasing.export.purchase-orders',
            'purchasing.export.quotations',
            'purchasing.export.requisitions',
            'purchasing.export.shipments',
            'supplier.export.purchase-orders',
            'supplier.export.quotations',
            'qc.export.inspections',
            'admin.auth-audit-logs.data',
            'admin.hs-code-rules.data',
            'admin.material-masters.data',
            'purchasing.claims.data-action',
            'purchasing.claims.data-history',
            'purchasing.comparison.historical.materials',
            'purchasing.comparison.vs-best.data',
            'purchasing.comparison.show',
            'purchasing.material-masters.search',
            'purchasing.purchase-orders.item-progress.history',
            'qc.inspections.data-history',
            'qc.inspections.data-waiting',
            'supplier.price-history.export',
            'supplier.price-history.materials',
            'supplier.purchase-orders.item-progress.history',
            'supplier.goods-locations.options',
        ];

        foreach ($nonShellDirectRoutes as $name) {
            $route = RouteFacade::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Expected route [{$name}] to exist.");
            $this->assertSame(WorkspaceShell::CLASS_NON_SHELL_DIRECT, WorkspaceShell::classifyRoute($route), $name);
            $this->assertFalse(WorkspaceShell::isShellEligibleRoute($route), $name);
            $this->assertFalse(WorkspaceShell::isFrameableRoute($route), $name);
        }
    }

    public function test_authenticated_portal_pages_are_classified_as_shell_pages(): void
    {
        $shellPages = [
            'admin.dashboard',
            'admin.users.index',
            'purchasing.dashboard',
            'purchasing.shipments.index',
            'purchasing.requisitions.index',
            'purchasing.comparison.historical',
            'supplier.dashboard',
            'supplier.requests.index',
            'supplier.goods-locations.index',
            'supplier.price-history.historical',
            'local-supplier.dashboard',
            'local-supplier.invoices.index',
            'local-supplier.invoices.receipt',
            'finance.dashboard',
            'accounting.dashboard',
            'qc.dashboard',
            'ga.dashboard',
            'ga.claims.receipt',
            'profile.edit',
            'profile.customization',
            'profile.notifications',
            'notifications.index',
            'exports.index',
        ];

        foreach ($shellPages as $name) {
            $route = RouteFacade::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Expected route [{$name}] to exist.");
            $this->assertSame(WorkspaceShell::CLASS_SHELL_PAGE, WorkspaceShell::classifyRoute($route), $name);
            $this->assertTrue(WorkspaceShell::isShellEligibleRoute($route), $name);
            $this->assertTrue(WorkspaceShell::isFrameableRoute($route), $name);
        }
    }

    public function test_embed_query_parameter_helpers_preserve_queries_and_fragments(): void
    {
        $this->assertSame(
            'https://example.test/v2/ADSI_PROD/purchasing/shipments?status=open&_embed=1#rows',
            WorkspaceShell::appendEmbedParam('https://example.test/v2/ADSI_PROD/purchasing/shipments?status=open#rows')
        );

        $this->assertSame(
            'https://example.test/v2/ADSI_PROD/purchasing/shipments?status=open&_embed=1',
            WorkspaceShell::appendEmbedParam('https://example.test/v2/ADSI_PROD/purchasing/shipments?status=open&_embed=1')
        );

        $this->assertSame(
            'https://example.test/v2/ADSI_PROD/purchasing/shipments?status=open#rows',
            WorkspaceShell::stripEmbedParam('https://example.test/v2/ADSI_PROD/purchasing/shipments?status=open&_embed=1#rows')
        );

        $this->assertSame(
            'https://example.test/v2/ADSI_PROD/purchasing/shipments',
            WorkspaceShell::stripEmbedParam('https://example.test/v2/ADSI_PROD/purchasing/shipments?_embed=1')
        );
    }

    public function test_goods_locations_index_maps_to_shipments_module_and_menu_label(): void
    {
        $route = RouteFacade::getRoutes()->getByName('supplier.goods-locations.index');
        $this->assertNotNull($route);
        $this->assertSame('shipments', WorkspaceShell::moduleKeyForRoute($route));
        $this->assertSame(__('shipments.preshipment.locations_title'), WorkspaceShell::menuLabelForRoute($route));
    }

    public function test_frontend_top_level_and_download_classifiers_cover_all_user_navigable_non_shell_get_routes(): void
    {
        $coreSource = (string) file_get_contents(resource_path('js/workspace-shell-core.js'));
        preg_match('/const TOP_LEVEL_SUFFIX_RE = \/(.+?)\/i;/', $coreSource, $topMatch);
        $this->assertNotEmpty($topMatch[1] ?? null, 'TOP_LEVEL_SUFFIX_RE must be readable from workspace-shell-core.js');
        $topLevelRegex = '#'.$topMatch[1].'#i';

        // Non-HTML JSON/AJAX/drawer/internal endpoints never rendered as user-clickable page links.
        $nonNavigableDirectPatterns = [
            'session.status',
            'context-panel.*',
            'conversations.drawer.*',
            'conversations.unread-count',
            'conversations.messages.*',
            'notifications.summary',
            'notifications.unread-count',
            'exports.definitions.*',
            'exports.status',
            'purchasing.comparison.show',
            '*.data',
            '*.data-*',
            '*.materials',
            '*.search',
            '*.po-suggestions',
            '*.pr-suggestions',
            '*.status',
            '*.errors',
            '*.records',
            '*.history',
            '*.satellite',
            'supplier.goods-locations.options',
        ];

        $uncovered = [];
        $prefix = trim(PortalPath::prefix(), '/');
        $prefixSlash = $prefix !== '' ? '/'.$prefix : '';

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $class = WorkspaceShell::classifyRoute($route);
            if ($class === WorkspaceShell::CLASS_SHELL_PAGE) {
                continue;
            }

            $name = (string) ($route->getName() ?? '');
            $uri = '/'.ltrim($route->uri(), '/');

            if ($name === '' || in_array($uri, ['/', '/up', '/broadcasting/auth'], true) || str_contains($uri, '{fallbackPlaceholder}')) {
                continue;
            }

            $skip = false;
            foreach ($nonNavigableDirectPatterns as $pattern) {
                if (Str::is($pattern, $name)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            // Substitute route parameters with realistic tokens (UUID for pageUuid, hashid otherwise).
            $samplePath = (string) preg_replace_callback('/\{([^}]+)\}/', static function (array $m): string {
                return str_contains(strtolower($m[1]), 'uuid')
                    ? '934f47ee-06a4-4f2d-a619-e7cf35e9d91b'
                    : 'SAMPLEHASH123';
            }, $uri);

            $relative = ($prefixSlash !== '' && str_starts_with($samplePath, $prefixSlash.'/'))
                ? substr($samplePath, strlen($prefixSlash))
                : $samplePath;
            $singleSeg = ltrim($relative, '/');
            $isBareDashboard = $relative === '/dashboard';
            $isUuidSegment = $singleSeg !== '' && ! str_contains($singleSeg, '/')
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $singleSeg) === 1;

            if (! $isBareDashboard && ! $isUuidSegment && preg_match($topLevelRegex, $samplePath) !== 1) {
                $uncovered[] = "{$name} ({$uri})";
            }
        }

        $this->assertSame(
            [],
            $uncovered,
            'Every user-navigable non-shell GET route must be caught by workspace-shell-core.js top-level/download classifiers.'
        );
    }
}
