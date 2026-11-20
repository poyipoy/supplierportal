<?php

namespace Tests\Feature\Architecture;

use App\Http\Middleware\DecodeHashids;
use App\Models\MaintenanceRecord;
use App\Models\SupplierAudit;
use App\Models\User;
use App\Support\PortalContextCodec;
use App\Support\WorkspaceShell;
use App\Traits\HasHashids;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\Support\ReloadsPortalRoutes;
use Tests\TestCase;

class PortalSessionRegistryCompletenessTest extends TestCase
{
    use ReloadsPortalRoutes;

    private const FORBIDDEN_PII_KEYS = [
        'email',
        'npwp',
        'bank_account',
        'account_number',
        'password',
        'token',
        'secret',
        'phone',
        'address',
        'name',
        'return_url',
        '_embed',
        'favoriteContext',
        'LogicalId',
    ];

    private const DIRECTORY_ROUTES_WITHOUT_FREE_TEXT_SEARCH = [
        'admin.users.index',
        'admin.auth-audit-logs.index',
        'ga.employees.index',
        'finance.vendor-master.index',
        'finance.ga-claims.index',
        'purchasing.import-suppliers.index',
        'purchasing.local-vendors.index',
        'supplier-registrations.index',
        'purchasing.conversations.index',
        'supplier.conversations.index',
    ];

    public function test_all_shell_routes_and_registry_entries_are_completely_mapped_with_and_without_prefix_and_compiled_cache(): void
    {
        foreach ([false, true] as $prefixEnabled) {
            $this->reloadPortalRoutes([
                'portal_urls.prefix.enabled' => $prefixEnabled,
                'portal_urls.prefix.value' => 'v2/ADASI_PRD',
                'portal_urls.context_urls.enabled' => true,
            ]);

            $this->assertRegistryCompleteness('uncompiled prefix='.($prefixEnabled ? 'on' : 'off'));

            /** @var Router $router */
            $router = app('router');
            $router->setCompiledRoutes($router->getRoutes()->compile());

            $this->assertRegistryCompleteness('compiled route:cache prefix='.($prefixEnabled ? 'on' : 'off'));
        }
    }

    private function assertRegistryCompleteness(string $context): void
    {
        $sessions = config('portal_sessions', []);
        $this->assertIsArray($sessions, $context);
        $this->assertNotEmpty($sessions, $context);

        $appUuid = strtolower((string) config('portal_urls.context_urls.app_uuid'));
        $pageRegistry = config('page_registry', []);
        $this->assertArrayNotHasKey($appUuid, $pageRegistry, "App UUID must not collide with page_registry ({$context})");

        $routes = Route::getRoutes();
        $mappedRoutes = [];
        $allShellRoutes = [];

        foreach ($routes->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            if (WorkspaceShell::classifyRoute($route) === WorkspaceShell::CLASS_SHELL_PAGE) {
                $routeName = (string) $route->getName();
                $allShellRoutes[] = $routeName;
            }
        }

        foreach ($sessions as $action => $spec) {
            $this->assertIsString($action, $context);
            $this->assertMatchesRegularExpression('/\A[a-z0-9._-]{3,32}\z/', $action, "Invalid action code {$action} ({$context})");
            $this->assertIsArray($spec, "{$action} ({$context})");

            $routeName = $spec['route'] ?? null;
            $this->assertIsString($routeName, "Missing route for {$action} ({$context})");
            $this->assertArrayNotHasKey($routeName, $mappedRoutes, "Duplicate session mapping for route {$routeName} ({$context})");
            $mappedRoutes[$routeName] = $action;

            $route = $routes->getByName($routeName);
            $this->assertNotNull($route, "Route {$routeName} does not exist ({$context})");
            $this->assertSame(
                WorkspaceShell::CLASS_SHELL_PAGE,
                WorkspaceShell::classifyRoute($route),
                "Session target {$routeName} must be CLASS_SHELL_PAGE ({$context})"
            );

            $expectedParams = $route->parameterNames();
            $this->assertSame($expectedParams, $spec['params'] ?? null, "Params mismatch for {$routeName} ({$context})");

            $allowlist = $spec['query_allowlist'] ?? null;
            $this->assertIsArray($allowlist, "query_allowlist must be array for {$routeName} ({$context})");

            foreach ($allowlist as $allowedKey) {
                $this->assertIsString($allowedKey);
                $this->assertNotContains(
                    $allowedKey,
                    self::FORBIDDEN_PII_KEYS,
                    "Forbidden key {$allowedKey} in {$routeName} ({$context})"
                );
            }

            if (in_array($routeName, self::DIRECTORY_ROUTES_WITHOUT_FREE_TEXT_SEARCH, true)) {
                $this->assertNotContains('search', $allowlist, "Free-text search disallowed on directory route {$routeName}");
                $this->assertNotContains('q', $allowlist, "Free-text q disallowed on directory route {$routeName}");
            }
        }

        foreach ($allShellRoutes as $shellRoute) {
            $this->assertArrayHasKey(
                $shellRoute,
                $mappedRoutes,
                "Unmapped CLASS_SHELL_PAGE route missing from config/portal_sessions.php: {$shellRoute} ({$context})"
            );
        }

        foreach ($pageRegistry as $uuid => $routeName) {
            if ($routeName === 'dashboard') {
                continue;
            }
            $this->assertArrayHasKey(
                $routeName,
                $mappedRoutes,
                "page_registry route {$uuid} => {$routeName} missing from portal_sessions ({$context})"
            );
        }

        // Verify non-plain route path params are enforced by DecodeHashids::HASHED_PARAM_KEYS or HasHashids implicit model binding.
        $reflection = new \ReflectionClass(DecodeHashids::class);
        $hashedKeys = $reflection->getConstant('HASHED_PARAM_KEYS');
        $this->assertIsArray($hashedKeys);

        $implicitHashedModels = [
            'supplierAudit' => SupplierAudit::class,
            'vendor' => User::class,
            'maintenance' => MaintenanceRecord::class,
        ];

        foreach ($sessions as $action => $spec) {
            foreach ($spec['params'] ?? [] as $param) {
                if (in_array($param, PortalContextCodec::PLAIN_INT_KEYS, true)) {
                    continue;
                }
                if (isset($implicitHashedModels[$param])) {
                    $this->assertContains(
                        HasHashids::class,
                        class_uses_recursive($implicitHashedModels[$param]),
                        "Implicit route model {$param} must use HasHashids"
                    );

                    continue;
                }
                $this->assertContains($param, $hashedKeys, "Hashed route param {$param} in {$action} missing from DecodeHashids::HASHED_PARAM_KEYS");
            }
        }
    }
}
