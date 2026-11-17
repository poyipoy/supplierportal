<?php

namespace App\Support;

use App\Models\User;
use App\Services\QuickAccessService;
use App\Services\UserPreferenceService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

class WorkspaceShell
{
    public const EMBED_QUERY_PARAM = '_embed';

    /** @var array<string, array{uuidMap: array<string, array{key: string, url: string}>, pathMap: array<string, array{key: string, url: string}>, menuPathTitles: array<string, string>}> */
    private static array $moduleMapsCache = [];

    /** @var array<string, list<string>> */
    private static array $staticIndexPathsCache = [];

    public const CLASS_SHELL_PAGE = 'shell_page';

    public const CLASS_TOP_LEVEL_SECURITY = 'top_level_security';

    public const CLASS_NON_SHELL_DIRECT = 'non_shell_direct';

    /** @var list<string> */
    private const TOP_LEVEL_SECURITY_PATTERNS = [
        'login',
        'logout',
        'password.*',
        'two-factor.*',
        'passport.*',
        'sso.*',
        'supplier.register',
        'supplier.register.*',
        'supplier.registration.*',
        'receipts.*',
        'profile.security',
        'profile.two-factor.*',
        'supplier-context.*',
        'locale.*',
    ];

    /** @var list<string> */
    private const NON_SHELL_DIRECT_PATTERNS = [
        'dashboard',
        'portal.page',
        'purchasing.comparison.show',
        'session.status',
        'context-panel.*',
        'conversations.drawer.*',
        'conversations.unread-count',
        'conversations.messages.*',
        'notifications.summary',
        'notifications.unread-count',
        'export-presets.*',
        'exports.definitions.*',
        'exports.download',
        'exports.status',
        'attachments.*',
        'local-invoice-documents.*',
        'ga-claim-documents.*',
        'supplier-master-documents.*',
        'supplier-registrations.document',
        'shared.pdf.*',
        '*.print',
        '*.documents.show',
        '*.export',
        '*.export.*',
        'purchasing.export.*',
        'supplier.export.*',
        'qc.export.*',
        '*.import-template',
        '*.import.*.template',
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
    ];

    public static function enabled(): bool
    {
        return (bool) config('ui.workspace_shell.enabled', false);
    }

    public static function maxTabs(): int
    {
        return max(2, min(20, (int) config('ui.workspace_shell.max_tabs', 36)));
    }

    public static function maxLiveFrames(): int
    {
        return max(2, min(12, (int) config('ui.workspace_shell.max_live_frames', 18)));
    }

    public static function staleAfterSeconds(): int
    {
        return max(15, (int) config('ui.workspace_shell.stale_after_seconds', 120));
    }

    public static function classifyRoute(?Route $route): string
    {
        if (! $route) {
            return self::CLASS_NON_SHELL_DIRECT;
        }

        if (! in_array('GET', $route->methods(), true)) {
            return self::CLASS_NON_SHELL_DIRECT;
        }

        $name = (string) ($route->getName() ?? '');
        $middlewares = $route->gatherMiddleware();

        if (
            in_array('password.confirm', $middlewares, true)
            || in_array('guest', $middlewares, true)
            || in_array('mfa.pending', $middlewares, true)
            || self::matchesAnyPattern($name, self::TOP_LEVEL_SECURITY_PATTERNS)
        ) {
            return self::CLASS_TOP_LEVEL_SECURITY;
        }

        if ($name === '' || self::matchesAnyPattern($name, self::NON_SHELL_DIRECT_PATTERNS)) {
            return self::CLASS_NON_SHELL_DIRECT;
        }

        if (! in_array('auth', $middlewares, true)) {
            return self::CLASS_TOP_LEVEL_SECURITY;
        }

        return self::CLASS_SHELL_PAGE;
    }

    public static function isShellEligibleRoute(?Route $route): bool
    {
        return self::classifyRoute($route) === self::CLASS_SHELL_PAGE;
    }

    public static function isFrameableRoute(?Route $route): bool
    {
        return self::classifyRoute($route) === self::CLASS_SHELL_PAGE;
    }

    public static function isEmbedQueryPresent(Request $request): bool
    {
        return $request->query(self::EMBED_QUERY_PARAM) === '1'
            || $request->query(self::EMBED_QUERY_PARAM) === 'true'
            || $request->input(self::EMBED_QUERY_PARAM) === '1';
    }

    public static function isEmbedRequest(Request $request): bool
    {
        if (self::isEmbedQueryPresent($request)) {
            return true;
        }

        return strtolower((string) $request->headers->get('Sec-Fetch-Dest')) === 'iframe';
    }

    public static function isAllowedEmbedRequest(Request $request): bool
    {
        if (! self::enabled() || ! $request->user()) {
            return false;
        }

        if (! self::isEmbedRequest($request)) {
            return false;
        }

        return self::isFrameableRoute($request->route());
    }

    public static function originatedFromEmbed(Request $request): bool
    {
        if (self::isEmbedRequest($request)) {
            return true;
        }

        $referer = (string) $request->headers->get('referer', '');
        if ($referer === '') {
            return false;
        }

        $query = parse_url($referer, PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            return false;
        }

        parse_str($query, $params);

        return ($params[self::EMBED_QUERY_PARAM] ?? null) === '1';
    }

    public static function isEligibleRedirectTarget(Request $request, string $targetUrl): bool
    {
        if ($targetUrl === '' || str_contains($targetUrl, 'signature=')) {
            return false;
        }

        $targetHost = parse_url($targetUrl, PHP_URL_HOST);
        if (is_string($targetHost) && $targetHost !== '' && strcasecmp($targetHost, $request->getHost()) !== 0) {
            return false;
        }

        try {
            $probe = Request::create($targetUrl, 'GET');
            $matched = app('router')->getRoutes()->match($probe);
        } catch (\Throwable) {
            return false;
        }

        return self::isShellEligibleRoute($matched);
    }

    public static function appendEmbedParam(string $url): string
    {
        if ($url === '') {
            return $url;
        }

        [$beforeFragment, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        [$pathPart, $queryPart] = array_pad(explode('?', $beforeFragment, 2), 2, '');

        $params = [];
        if ($queryPart !== '') {
            parse_str($queryPart, $params);
        }

        $params[self::EMBED_QUERY_PARAM] = '1';
        $rebuilt = $pathPart.'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return $fragment !== null && $fragment !== '' ? $rebuilt.'#'.$fragment : $rebuilt;
    }

    public static function stripEmbedParam(string $url): string
    {
        if ($url === '' || ! str_contains($url, self::EMBED_QUERY_PARAM)) {
            return $url;
        }

        [$beforeFragment, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        [$pathPart, $queryPart] = array_pad(explode('?', $beforeFragment, 2), 2, '');

        if ($queryPart === '') {
            return $url;
        }

        parse_str($queryPart, $params);
        unset($params[self::EMBED_QUERY_PARAM]);

        $rebuilt = $params === []
            ? $pathPart
            : $pathPart.'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return $fragment !== null && $fragment !== '' ? $rebuilt.'#'.$fragment : $rebuilt;
    }

    public static function extractPageTitle(string $html, string $fallback = 'ADASI Portal'): string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $raw = html_entity_decode(trim(strip_tags($matches[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $clean = preg_replace('/\s+-\s+ADASI(?:\s+Supplier)?\s+Portal$/i', '', $raw);
            if (is_string($clean) && trim($clean) !== '') {
                return trim($clean);
            }
        }

        return $fallback;
    }

    public static function moduleKeyForRoute(?Route $route, string $path = ''): string
    {
        $name = (string) ($route?->getName() ?? '');

        return self::moduleKeyForRouteName($name, $path);
    }

    public static function moduleKeyForRouteName(string $name, string $path = ''): string
    {
        if ($name !== '') {
            if (str_contains($name, 'dashboard')) {
                return 'dashboard';
            }

            foreach ([
                'drp.ga' => 'drp-ga',
                'drp.supplier' => 'drp-supplier',
                'drp.paid' => 'drp-paid',
                'drp-ga' => 'drp-ga',
                'drp-supplier' => 'drp-supplier',
                'drp-paid' => 'drp-paid',
                'master-data.material-hs-code' => 'master-data-hs-code',
                'master-data.reference-prices' => 'master-data-reference-prices',
                'master-data.exchange-rates' => 'master-data-exchange-rates',
                'master-data.master-data-quality' => 'master-data-quality',
                'supplier-registrations' => 'supplier-registrations',
                'import-suppliers' => 'import-suppliers',
                'purchase-orders' => 'purchase-orders',
                'payment-vouchers' => 'payment-vouchers',
                'payment-schedule' => 'payment-schedule',
                'physical-verification' => 'physical-verification',
                'local-procurement' => 'local-procurement',
                'local-vendors' => 'local-vendors',
                'local-invoices' => 'local-vendors',
                'master-invoices' => 'master-invoices',
                'master-data-quality' => 'master-data-quality',
                'material-hs-code' => 'material-hs-code',
                'reference-prices' => 'reference-prices',
                'exchange-rates' => 'exchange-rates',
                'auth-audit-logs' => 'auth-audit-logs',
                'vendor-master' => 'vendor-master',
                'vendor-profile' => 'vendor-profile',
                'company-profile' => 'company-profile',
                'price-history' => 'price-history',
                'supplier-audits' => 'supplier-audits',
                'ga-claims' => 'ga-claims',
                'requisitions' => 'requisitions',
                'quotations' => 'quotations',
                'comparison' => 'comparison',
                'shipments' => 'shipments',
                'periods' => 'periods',
                'claims' => 'claims',
                'reports' => 'reports',
                'announcements' => 'announcements',
                'maintenance' => 'maintenance',
                'users' => 'users',
                'invoices' => 'invoices',
                'forecast' => 'forecast',
                'overpayments' => 'overpayments',
                'employees' => 'employees',
                'inspections' => 'inspections',
                'requests' => 'requests',
                'conversations' => 'conversations',
                'information' => 'information',
                'notifications' => 'notifications',
                'profile' => 'profile',
                'exports' => 'exports',
                'vouchers' => 'vouchers',
                'drp' => 'drp',
            ] as $needle => $moduleKey) {
                if (str_contains($name, $needle)) {
                    return $moduleKey;
                }
            }
        }

        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return 'dashboard';
        }

        return Str::slug(str_replace('/', '-', $trimmed));
    }

    /**
     * Authoritative sidebar & account route-name -> localized label map so parent workspace tabs
     * keep the exact menu title shown in navigation regardless of verbose page <title> copy.
     *
     * @return array<string, string>
     */
    private static function menuRouteLabelMap(): array
    {
        return [
            'dashboard' => __('navigation.dashboard'),
            'accounting.dashboard' => __('navigation.dashboard'),
            'accounting.invoices.index' => __('navigation.invoice_register'),
            'accounting.physical-verification' => __('navigation.physical_verification'),
            'accounting.payment-schedule' => __('navigation.payment_schedule'),
            'accounting.reports' => __('navigation.reports'),
            'admin.dashboard' => __('navigation.dashboard'),
            'admin.users.index' => __('navigation.users'),
            'admin.exchange-rates.index' => __('navigation.exchange_rates'),
            'admin.reference-prices.index' => __('materials.reference_prices.nav'),
            'admin.material-hs-code.index' => __('navigation.materials'),
            'admin.material-masters.index' => __('navigation.materials'),
            'admin.hs-code-rules.index' => __('navigation.materials'),
            'admin.master-data-quality.index' => __('navigation.materials'),
            'admin.auth-audit-logs.index' => __('navigation.auth_audit'),
            'admin.maintenance.index' => __('navigation.maintenance'),
            'admin.announcements.index' => __('navigation.announcements'),
            'supplier-registrations.index' => __('navigation.supplier_registrations'),
            'exports.index' => __('navigation.export_history'),
            'finance.dashboard' => __('navigation.dashboard'),
            'finance.invoices.index' => __('navigation.invoice_register'),
            'finance.ga-claims.index' => __('navigation.ga_claims'),
            'finance.drp.supplier' => __('navigation.drp_supplier'),
            'finance.drp.ga' => __('navigation.drp_ga'),
            'finance.drp.paid.index' => __('navigation.drp_paid'),
            'finance.payment-vouchers.index' => __('navigation.payment_vouchers'),
            'finance.overpayments.index' => __('navigation.supplier_overpayment'),
            'finance.local-procurement.index' => __('navigation.master_po_gr'),
            'finance.master-invoices' => __('navigation.master_invoices'),
            'finance.vendor-master.index' => __('navigation.master_vendor'),
            'ga.dashboard' => __('navigation.dashboard'),
            'ga.claims.index' => __('navigation.ga_claims'),
            'ga.claims.create' => __('navigation.submit_claim'),
            'ga.employees.index' => __('navigation.employee_master'),
            'local-supplier.dashboard' => __('navigation.dashboard'),
            'local-supplier.invoices.create' => __('navigation.submit_invoice'),
            'local-supplier.invoices.index' => __('navigation.invoice_register'),
            'local-supplier.purchase-orders.index' => __('navigation.purchase_orders'),
            'local-supplier.vendor-profile.show' => __('navigation.vendor_profile'),
            'local-supplier.supplier-audits.index' => __('navigation.supplier_audit'),
            'local-supplier.information' => __('navigation.announcements'),
            'purchasing.dashboard' => __('navigation.dashboard'),
            'purchasing.periods.index' => __('navigation.period_management'),
            'purchasing.requisitions.index' => __('navigation.requisition'),
            'purchasing.import-suppliers.index' => __('navigation.import_suppliers'),
            'purchasing.quotations.index' => __('navigation.quotation'),
            'purchasing.comparison.inter-supplier' => __('navigation.price_comparison'),
            'purchasing.comparison.historical' => __('navigation.price_comparison'),
            'purchasing.comparison.vs-best' => __('navigation.price_comparison'),
            'purchasing.purchase-orders.index' => __('navigation.purchase_order'),
            'purchasing.shipments.index' => __('navigation.shipment_logistics_short'),
            'purchasing.local-vendors.index' => __('navigation.local_vendors'),
            'purchasing.supplier-audits.index' => __('navigation.supplier_audit'),
            'purchasing.local-procurement.index' => __('navigation.local_po_gr'),
            'purchasing.drp.supplier' => __('navigation.drp_supplier'),
            'purchasing.drp.ga' => __('navigation.drp_ga'),
            'purchasing.drp.paid.index' => __('navigation.drp_paid'),
            'purchasing.payment-vouchers.index' => __('navigation.payment_vouchers'),
            'purchasing.master-data.material-hs-code.index' => __('navigation.materials'),
            'purchasing.master-data.material-masters.index' => __('navigation.materials'),
            'purchasing.master-data.hs-code-rules.index' => __('navigation.materials'),
            'purchasing.master-data.master-data-quality.index' => __('navigation.materials'),
            'purchasing.master-data.reference-prices.index' => __('materials.reference_prices.nav'),
            'purchasing.master-data.exchange-rates.index' => __('navigation.exchange_rates'),
            'purchasing.conversations.index' => __('common.chat.title'),
            'purchasing.claims.index' => __('navigation.material_claim'),
            'purchasing.reports.index' => __('navigation.report'),
            'qc.dashboard' => __('navigation.dashboard'),
            'qc.inspections.index' => __('navigation.qc_inspection'),
            'supplier.dashboard' => __('navigation.dashboard'),
            'supplier.quotations.index' => __('navigation.quotation_period'),
            'supplier.requests.index' => __('navigation.purchasing_requests'),
            'supplier.purchase-orders.index' => __('navigation.purchase_order'),
            'supplier.shipments.index' => __('navigation.shipments_deliveries_short'),
            'supplier.conversations.index' => __('common.chat.title'),
            'supplier.claims.index' => __('navigation.material_claim'),
            'supplier.price-history.index' => __('navigation.price_history'),
            'supplier.announcements.index' => __('navigation.adasi_information'),
            'supplier.company-profile.show' => __('navigation.company_profile'),
            'profile.edit' => __('navigation.profile'),
            'profile.customization' => __('navigation.customization'),
            'profile.notifications' => __('common.notification.title'),
            'notifications.index' => __('common.notification.title'),
        ];
    }

    public static function menuLabelForRoute(?Route $route): ?string
    {
        $name = (string) ($route?->getName() ?? '');
        if ($name === '') {
            return null;
        }

        $map = self::menuRouteLabelMap();

        return $map[$name] ?? null;
    }

    /**
     * Build UUID and canonical-path module lookup maps so both PageRegistry UUID links and direct route links
     * resolve deterministically to the same single tab per module.
     *
     * @return array{uuidMap: array<string, array{key: string, url: string}>, pathMap: array<string, array{key: string, url: string}>, menuPathTitles: array<string, string>}
     */
    public static function moduleLookupMaps(): array
    {
        $locale = app()->getLocale();
        $rawPrefix = trim(PortalPath::prefix(), '/');
        $hostKey = app()->runningInConsole() && ! app()->runningUnitTests()
            ? 'cli'
            : (string) request()->getSchemeAndHttpHost();
        $memoKey = $locale.'|'.$rawPrefix.'|'.$hostKey;

        if (isset(self::$moduleMapsCache[$memoKey])) {
            return self::$moduleMapsCache[$memoKey];
        }

        $registry = config('page_registry', []);
        $prefix = $rawPrefix !== '' ? '/'.$rawPrefix : '';
        $uuidMap = [];
        $pathMap = [];
        $menuPathTitles = [];
        $routeLabels = self::menuRouteLabelMap();

        if (is_array($registry)) {
            foreach ($registry as $uuid => $routeName) {
                if (! is_string($uuid) || ! is_string($routeName)) {
                    continue;
                }

                try {
                    $targetUrl = route($routeName);
                } catch (\Throwable) {
                    continue;
                }

                $targetPath = rtrim((string) parse_url($targetUrl, PHP_URL_PATH), '/') ?: '/';
                $key = self::moduleKeyForRouteName($routeName, $targetPath);
                $entry = ['key' => $key, 'url' => $targetUrl];

                $uuidMap[$prefix.'/'.strtolower($uuid)] = $entry;
                $pathMap[$targetPath] = $entry;
                if (isset($routeLabels[$routeName])) {
                    $menuPathTitles[$targetPath] = $routeLabels[$routeName];
                }
            }
        }

        foreach ($routeLabels as $routeName => $label) {
            try {
                $targetUrl = route($routeName);
            } catch (\Throwable) {
                continue;
            }

            $targetPath = rtrim((string) parse_url($targetUrl, PHP_URL_PATH), '/') ?: '/';
            if (! isset($pathMap[$targetPath])) {
                $pathMap[$targetPath] = [
                    'key' => self::moduleKeyForRouteName($routeName, $targetPath),
                    'url' => $targetUrl,
                ];
            }
            $menuPathTitles[$targetPath] = $label;
        }

        return self::$moduleMapsCache[$memoKey] = [
            'uuidMap' => $uuidMap,
            'pathMap' => $pathMap,
            'menuPathTitles' => $menuPathTitles,
        ];
    }

    /**
     * Reset in-process lookup caches (used when routes or page registry config change in tests).
     */
    public static function resetCaches(): void
    {
        self::$moduleMapsCache = [];
        self::$staticIndexPathsCache = [];
    }

    /**
     * Deterministic per-session one-time prerender cache key for handing off the top-level
     * shell request's already-rendered embedded HTML directly to its initial iframe request.
     */
    public static function prerenderCacheKey(Request $request): ?string
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return null;
        }

        $sessionId = $request->hasSession() ? (string) $request->session()->getId() : '';
        $cleanUrl = self::stripEmbedParam($request->fullUrl());
        $fingerprint = implode('|', [
            (string) $user->getKey(),
            (string) ($user->role ?? ''),
            (string) ((int) ($user->auth_session_version ?? 0)),
            (string) (PortalContext::current() ?? ''),
            $sessionId,
            app()->getLocale(),
            $cleanUrl,
        ]);

        return 'workspace_shell.prerender.'.$user->getKey().'.'.sha1($fingerprint);
    }

    /**
     * Collect parameter-free GET shell route paths (excluding create screens) so sibling overview views
     * stay in-place instead of opening duplicate child tabs.
     *
     * @param  array<string, mixed>  $pathMap
     * @return list<string>
     */
    private static function staticIndexPaths(array $pathMap): array
    {
        $rawPrefix = trim(PortalPath::prefix(), '/');
        $routeCount = count(RouteFacade::getRoutes()->getRoutes());
        $memoKey = $rawPrefix.'|'.$routeCount.'|'.count($pathMap);

        if (isset(self::$staticIndexPathsCache[$memoKey])) {
            return self::$staticIndexPathsCache[$memoKey];
        }

        $paths = [];
        foreach (array_keys($pathMap) as $path) {
            if (is_string($path) && $path !== '' && ! str_ends_with($path, '/create')) {
                $paths[$path] = true;
            }
        }

        $scannedRoutes = Cache::remember(
            'workspace_shell.static_index_routes.'.sha1($rawPrefix.'|'.$routeCount),
            now()->addMinutes(10),
            static function (): array {
                $routePaths = [];
                foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
                    if (! in_array('GET', $route->methods(), true) || $route->parameterNames() !== []) {
                        continue;
                    }

                    $name = (string) ($route->getName() ?? '');
                    if ($name === '' || Str::endsWith($name, '.create') || ! self::isShellEligibleRoute($route)) {
                        continue;
                    }

                    try {
                        $relative = route($name, [], false);
                    } catch (\Throwable) {
                        continue;
                    }

                    $normalized = rtrim((string) parse_url($relative, PHP_URL_PATH), '/') ?: '/';
                    if (! str_ends_with($normalized, '/create')) {
                        $routePaths[$normalized] = true;
                    }
                }

                return array_keys($routePaths);
            }
        );

        foreach ($scannedRoutes as $normalized) {
            $paths[$normalized] = true;
        }

        return self::$staticIndexPathsCache[$memoKey] = array_keys($paths);
    }

    /**
     * Build initial view data for the outer workspace shell layout.
     *
     * @return array<string, mixed>
     */
    public static function shellViewData(Request $request, string $innerHtml, ?Route $route = null): array
    {
        $cleanUrl = self::stripEmbedParam($request->fullUrl());
        $embedUrl = self::appendEmbedParam($cleanUrl);
        $moduleKey = self::moduleKeyForRoute($route, $request->path());
        $menuTitle = self::menuLabelForRoute($route);
        $title = $menuTitle ?? self::extractPageTitle($innerHtml);
        $user = $request->user();
        $rawPrefix = trim(PortalPath::prefix(), '/');
        $pathPrefix = $rawPrefix !== '' ? '/'.$rawPrefix : '';
        $maps = self::moduleLookupMaps();
        $indexPaths = self::staticIndexPaths($maps['pathMap']);

        $quickAccessPaths = [];
        if ($user instanceof User) {
            try {
                $preferenceService = app(UserPreferenceService::class);
                $preferences = $preferenceService->for($user);
                $quickAccess = app(QuickAccessService::class);
                $selected = $quickAccess->selectedFor(
                    $user,
                    $preferences['quick_access'] ?? [],
                    $quickAccess->contextFor($user),
                );
                foreach ($selected as $item) {
                    $itemPath = rtrim((string) parse_url((string) ($item['url'] ?? ''), PHP_URL_PATH), '/');
                    if ($itemPath !== '') {
                        $quickAccessPaths[] = $itemPath;
                    }
                }
            } catch (\Throwable) {
                $quickAccessPaths = [];
            }
        }

        $dashboardUrl = $user
            ? match ($user->role) {
                'admin' => route('admin.dashboard'),
                'purchasing' => route('purchasing.dashboard'),
                'supplier', 'accounting', 'finance' => PortalContext::dashboard($user, false),
                'qc' => route('qc.dashboard'),
                'ga' => route('ga.dashboard'),
                default => route('dashboard'),
            }
        : route('login');

        return [
            'isWorkspaceShell' => true,
            'initialCleanUrl' => $cleanUrl,
            'initialEmbedUrl' => $embedUrl,
            'initialModuleKey' => $moduleKey,
            'initialTitle' => $title,
            'isInitialDashboard' => $moduleKey === 'dashboard',
            'dashboardUrl' => $dashboardUrl,
            'workspaceConfig' => [
                'enabled' => true,
                'maxTabs' => self::maxTabs(),
                'maxLiveFrames' => self::maxLiveFrames(),
                'staleAfterMs' => self::staleAfterSeconds() * 1000,
                'slowLoaderThresholdMs' => max(0, (int) config('ui.navigation.slow_loader_threshold_ms', 2500)),
                'pageFadeMs' => max(0, (int) config('ui.navigation.page_fade_ms', 500)),
                'embedParam' => self::EMBED_QUERY_PARAM,
                'pathPrefix' => $pathPrefix,
                'userId' => $user?->getKey(),
                'portalScope' => PortalContext::current() ?? 'import',
                'dashboardUrl' => $dashboardUrl,
                'uuidMap' => $maps['uuidMap'],
                'pathMap' => $maps['pathMap'],
                'menuPathTitles' => $maps['menuPathTitles'],
                'indexPaths' => $indexPaths,
                'quickAccessPaths' => $quickAccessPaths,
                'shortcutsTab' => [
                    'key' => 'shortcuts',
                    'label' => __('navigation.workspace_shell.shortcuts'),
                    'pinned' => true,
                    'closable' => false,
                ],
                'initialTab' => [
                    'key' => $moduleKey,
                    'label' => $title,
                    'url' => $cleanUrl,
                    'embedUrl' => $embedUrl,
                    'pinned' => false,
                    'closable' => true,
                ],
                'i18n' => [
                    'tabsLabel' => __('navigation.workspace_shell.tabs_label'),
                    'shortcuts' => __('navigation.workspace_shell.shortcuts'),
                    'frequentlyUsed' => __('navigation.workspace_shell.frequently_used'),
                    'allModules' => __('navigation.workspace_shell.all_modules'),
                    'searchShortcuts' => __('navigation.workspace_shell.search_shortcuts'),
                    'clearSearch' => __('navigation.workspace_shell.clear_search'),
                    'noShortcutsMatch' => __('navigation.workspace_shell.no_shortcuts_match'),
                    'openInstancesCount' => __('navigation.workspace_shell.open_instances_count'),
                    'refreshTab' => __('navigation.workspace_shell.refresh_tab'),
                    'closeTab' => __('navigation.workspace_shell.close_tab'),
                    'duplicateTab' => __('navigation.workspace_shell.duplicate_tab'),
                    'closeOthers' => __('navigation.workspace_shell.close_others'),
                    'closeRight' => __('navigation.workspace_shell.close_right'),
                    'closeAll' => __('navigation.workspace_shell.close_all'),
                    'closeGroup' => __('navigation.workspace_shell.close_group'),
                    'tabActions' => __('navigation.workspace_shell.tab_actions'),
                    'stackedPagesCount' => __('navigation.workspace_shell.stacked_pages_count'),
                    'hibernatedBadge' => __('navigation.workspace_shell.hibernated_badge'),
                    'confirmCloseDirtyBulk' => __('navigation.workspace_shell.confirm_close_dirty_bulk'),
                    'staleBadge' => __('navigation.workspace_shell.stale_badge'),
                    'staleHint' => __('navigation.workspace_shell.stale_hint'),
                    'unsavedBadge' => __('navigation.workspace_shell.unsaved_badge'),
                    'closeDirtyConfirm' => __('navigation.workspace_shell.close_dirty_confirm'),
                    'evictionBlocked' => __('navigation.workspace_shell.eviction_blocked'),
                    'loadingModule' => __('navigation.workspace_shell.loading_module'),
                    'scrollTabsLeft' => __('navigation.workspace_shell.scroll_tabs_left'),
                    'scrollTabsRight' => __('navigation.workspace_shell.scroll_tabs_right'),
                    'openWorkspaces' => __('navigation.workspace_shell.open_workspaces'),
                    'statusActive' => __('navigation.workspace_shell.status_active'),
                    'statusUnsaved' => __('navigation.workspace_shell.status_unsaved'),
                    'statusStale' => __('navigation.workspace_shell.status_stale'),
                    'statusHibernated' => __('navigation.workspace_shell.status_hibernated'),
                    'statusQueued' => __('navigation.workspace_shell.status_queued'),
                    'queuedAnnouncement' => __('navigation.workspace_shell.queued_announcement'),
                    'readyAnnouncement' => __('navigation.workspace_shell.ready_announcement'),
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $patterns
     */
    private static function matchesAnyPattern(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($value !== '' && Str::is($pattern, $value)) {
                return true;
            }
        }

        return false;
    }
}
