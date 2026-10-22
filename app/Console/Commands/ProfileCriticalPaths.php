<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\LocalPurchaseOrder;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Sends real GET requests through the HTTP kernel in-process (middleware, route binding, policies, view composers),
 * so controller signature changes cannot break the profiler. Every request runs inside a transaction that is rolled
 * back, so GET side effects such as marking messages read never persist.
 */
class ProfileCriticalPaths extends Command
{
    protected $signature = 'performance:profile-critical
        {--json : Emit machine-readable JSON}
        {--length=25 : DataTable page size}
        {--runs=1 : Measured runs per path after one warm-up run; P50/P95 are taken over these}
        {--only= : Comma-separated path names to profile}';

    protected $description = 'Profile representative read-only application paths in a local environment';

    public function handle(HttpKernel $kernel): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This profiler is restricted to local and testing environments.');

            return self::FAILURE;
        }

        // Sessions stay in memory; nothing is written to the session store. The profiled user is already signed in,
        // so the SSO proof that a real session carries is out of scope (tests run with SSO off as well).
        config(['session.driver' => 'array', 'sso.enabled' => false]);

        $users = [
            'purchasing' => $this->activeUser('purchasing'),
            'qc' => $this->activeUser('qc'),
            'finance' => $this->activeUser('finance'),
            'supplier_import' => User::query()->importEligible()->orderBy('id')->first(),
            'supplier_local' => User::query()->localEligible()->orderBy('id')->first(),
        ];
        $length = max(1, min(100, (int) $this->option('length')));
        $runs = max(1, min(100, (int) $this->option('runs')));
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));

        $profiles = [];
        foreach ($this->paths($users, $length) as $name => [$role, $resolve]) {
            if ($only && ! in_array($name, $only, true)) {
                continue;
            }
            $profiles[] = $this->profile($kernel, $name, $role, $users[$role] ?? null, $resolve, $runs);
        }

        $payload = [
            'environment' => [
                'app_env' => app()->environment(),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'database' => DB::connection()->getDatabaseName(),
                'runs_per_path' => $runs,
            ],
            'dataset' => $this->datasetCounts(),
            'profiles' => $profiles,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['Path', 'Role', 'Queries', 'SQL ms', 'P50 ms', 'P95 ms', 'Peak MB', 'Bytes', 'Status'],
                array_map(fn (array $profile): array => [
                    $profile['path'], $profile['role'], $profile['query_count'], $profile['sql_ms'], $profile['p50_ms'],
                    $profile['p95_ms'], $profile['peak_mb'], $profile['response_bytes'], $profile['status'],
                ], $profiles),
            );
            foreach ($profiles as $profile) {
                if ($profile['error']) {
                    $this->warn("{$profile['path']}: {$profile['error']}");
                }
            }
        }

        return collect($profiles)->contains(fn (array $profile): bool => $profile['status'] === 'error')
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * Path name => [role, resolver]. A resolver returns [url, query, ajax] or a string explaining why the path has
     * no data to profile in this dataset.
     *
     * @return array<string, array{0: string, 1: callable(): (array{0: string, 1: array, 2: bool}|string)}>
     */
    private function paths(array $users, int $length): array
    {
        $page = fn (string $route, array $parameters = [], array $query = []) => fn () => [route($route, $parameters), $query, false];
        $table = fn (string $route, array $columns, array $query = [], array $parameters = []) => fn () => [route($route, $parameters), $this->dataTableQuery($columns, $length, $query), true];
        $json = fn (string $route) => fn () => [route($route), [], true];
        $vsBestColumns = [
            ['data' => 'material_display', 'name' => 'current_pr_items.material_name'],
            ['data' => 'current_price_display', 'name' => 'current_price_idr'],
            ['data' => 'best_price_display', 'name' => 'best_price_idr'],
            ['data' => 'diff_display', 'name' => 'diff_idr_per_kg'],
            ['data' => 'potential_difference_display', 'name' => 'potential_difference_idr'],
            ['data' => 'status_badge', 'name' => 'status_badge', 'searchable' => false],
            ['data' => 'action', 'name' => 'action', 'searchable' => false, 'orderable' => false],
        ];
        $vsBestRange = ['date_from' => '2020-01', 'date_to' => '2035-12'];

        return [
            'purchasing.dashboard' => ['purchasing', $page('purchasing.dashboard')],
            'supplier.dashboard' => ['supplier_import', $page('supplier.dashboard')],
            'purchasing.requisitions.data' => ['purchasing', $table('purchasing.requisitions.index', [
                ['data' => 'DT_RowIndex', 'name' => 'DT_RowIndex', 'searchable' => false, 'orderable' => false],
                ['data' => 'pr_number_display', 'name' => 'pr_number'],
                ['data' => 'period_name', 'name' => 'period.name'],
                ['data' => 'creator_name', 'name' => 'creator.name'],
                ['data' => 'supplier_count', 'name' => 'invited_suppliers_count', 'searchable' => false],
                ['data' => 'item_count', 'name' => 'item_count', 'searchable' => false],
                ['data' => 'total_kg', 'name' => 'total_kg', 'searchable' => false],
                ['data' => 'status_badge', 'name' => 'status', 'searchable' => false],
                ['data' => 'created_date', 'name' => 'created_at'],
                ['data' => 'action', 'name' => 'action', 'searchable' => false, 'orderable' => false],
            ])],
            'purchasing.purchase-orders.data' => ['purchasing', $table('purchasing.purchase-orders.index', [
                ['data' => 'po_number_display', 'name' => 'po_number'],
                ['data' => 'supplier_name', 'name' => 'supplier_name', 'orderable' => false],
                ['data' => 'period_name', 'name' => 'period_name', 'orderable' => false],
                ['data' => 'pr_reference', 'name' => 'pr_reference', 'orderable' => false],
                ['data' => 'remark_display', 'name' => 'remark_display', 'orderable' => false],
                ['data' => 'total_idr', 'name' => 'total_idr', 'searchable' => false, 'orderable' => false],
                ['data' => 'status_badge', 'name' => 'status'],
                ['data' => 'estimated_date', 'name' => 'estimated_arrival'],
                ['data' => 'action', 'name' => 'action', 'searchable' => false, 'orderable' => false],
            ])],
            'purchasing.comparison.inter-supplier' => ['purchasing', $page('purchasing.comparison.inter-supplier')],
            'purchasing.comparison.historical' => ['purchasing', function () use ($users) {
                $supplier = $users['supplier_import'];
                $material = $supplier ? DB::table('pr_items')
                    ->join('quotation_items', 'quotation_items.pr_item_id', '=', 'pr_items.id')
                    ->join('quotations', 'quotations.id', '=', 'quotation_items.quotation_id')
                    ->join('po_quotations', 'po_quotations.quotation_id', '=', 'quotations.id')
                    ->where('quotations.supplier_id', $supplier->getKey())
                    ->whereNull('quotations.deleted_at')
                    ->orderBy('pr_items.id')
                    ->value('pr_items.material_name') : null;

                return [route('purchasing.comparison.historical'), array_filter([
                    'supplier_id' => $supplier?->getRouteKey(),
                    'material_name' => $material,
                    'period_view' => 'monthly',
                    'range' => 'all',
                ]), false];
            }],
            'purchasing.comparison.vs-best.data' => ['purchasing', $table('purchasing.comparison.vs-best.data', $vsBestColumns, $vsBestRange)],
            'purchasing.comparison.vs-best.data.keyword' => ['purchasing', function () use ($table, $vsBestColumns, $vsBestRange) {
                $material = (string) DB::table('pr_items')->whereNotNull('material_name')->orderBy('id')->value('material_name');
                $keyword = mb_substr(trim($material), 0, 4);

                return $keyword === ''
                    ? 'no material name to search for'
                    : $table('purchasing.comparison.vs-best.data', $vsBestColumns, $vsBestRange + ['search' => ['value' => $keyword, 'regex' => false]])();
            }],
            'purchasing.quotations.index' => ['purchasing', $page('purchasing.quotations.index')],
            'qc.inspections.data-history' => ['qc', $table('qc.inspections.data-history', [
                ['data' => 'po_number', 'name' => 'purchaseOrder.po_number'],
                ['data' => 'supplier_name', 'name' => 'purchaseOrder.supplier.name'],
                ['data' => 'inspected_date', 'name' => 'inspected_at'],
                ['data' => 'status_badge', 'name' => 'status'],
                ['data' => 'inspector_name', 'name' => 'inspector.name'],
                ['data' => 'action', 'name' => 'action', 'searchable' => false, 'orderable' => false],
            ])],
            'purchasing.claims.data-history' => ['purchasing', $table('purchasing.claims.data-history', [
                ['data' => 'claim_id', 'name' => 'id'],
                ['data' => 'po_number', 'name' => 'purchaseOrder.po_number'],
                ['data' => 'supplier_name', 'name' => 'purchaseOrder.supplier.name'],
                ['data' => 'created_date', 'name' => 'created_at'],
                ['data' => 'deadline_display', 'name' => 'deadline'],
                ['data' => 'status_badge', 'name' => 'status'],
                ['data' => 'action', 'name' => 'action', 'searchable' => false, 'orderable' => false],
            ])],
            'finance.dashboard' => ['finance', $page('finance.dashboard')],
            'finance.invoices.index' => ['finance', $page('finance.invoices.index')],
            'finance.local-procurement.index' => ['finance', $page('finance.local-procurement.index')],
            'finance.local-procurement.goods-receipts.data' => ['finance', function () {
                $purchaseOrder = LocalPurchaseOrder::query()->withCount('goodsReceipts')->orderByDesc('goods_receipts_count')->orderBy('id')->first();

                return $purchaseOrder
                    ? [route('finance.local-procurement.goods-receipts.data', $purchaseOrder), ['draw' => 1, 'start' => 0, 'length' => 25], true]
                    : 'no local purchase order';
            }],
            'local-supplier.invoices.index' => ['supplier_local', $page('local-supplier.invoices.index')],
            'notifications.unread-count' => ['purchasing', $json('notifications.unread-count')],
            'notifications.summary' => ['purchasing', $json('notifications.summary')],
            'conversations.unread-count' => ['purchasing', $json('conversations.unread-count')],
            'conversations.drawer.show' => ['purchasing', function () use ($users) {
                $conversation = Conversation::query()
                    ->where('purchasing_user_id', $users['purchasing']?->getKey())
                    ->withCount('messages')
                    ->orderByDesc('messages_count')
                    ->orderBy('id')
                    ->first();

                return $conversation
                    ? [route('conversations.drawer.show', $conversation), [], true]
                    : 'no conversation for the purchasing user';
            }],
        ];
    }

    private function profile(HttpKernel $kernel, string $path, string $role, ?User $user, callable $resolve, int $runs): array
    {
        $result = ['path' => $path, 'role' => $role, 'query_count' => 0, 'sql_ms' => 0.0, 'response_ms' => 0.0, 'p50_ms' => 0.0,
            'p95_ms' => 0.0, 'runs' => 0, 'peak_mb' => 0.0, 'response_bytes' => 0, 'http_status' => null, 'status' => 'ok',
            'error' => null, 'slowest_queries' => []];

        if (! $user) {
            return ['status' => 'skipped', 'error' => "no active {$role} user"] + $result;
        }

        try {
            Auth::guard('web')->setUser($user);
            $target = $resolve();
        } catch (Throwable $exception) {
            return ['status' => 'error', 'error' => $exception::class.': '.$exception->getMessage()] + $result;
        }
        if (is_string($target)) {
            return ['status' => 'skipped', 'error' => $target] + $result;
        }

        [$url, $query, $ajax] = $target;
        $timings = [];
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        for ($run = 0; $run <= $runs; $run++) {
            Auth::guard('web')->setUser($user);
            DB::beginTransaction();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $startedAt = hrtime(true);

            try {
                $request = Request::create($url, 'GET', $query, [], [], $ajax
                    ? ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']
                    : ['HTTP_ACCEPT' => 'text/html']);
                $response = $kernel->handle($request);
                $content = (string) $response->getContent();
                $kernel->terminate($request, $response);
            } catch (Throwable $exception) {
                $response = null;
                $content = '';
                $result['status'] = 'error';
                $result['error'] = $exception::class.': '.$exception->getMessage();
            } finally {
                $elapsed = (hrtime(true) - $startedAt) / 1_000_000;
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
                DB::rollBack();
            }

            if ($run === 0) {
                continue; // Warm-up: view compilation, config and opcache.
            }

            $timings[] = $elapsed;
            usort($queries, fn (array $left, array $right): int => ($right['time'] ?? 0) <=> ($left['time'] ?? 0));
            $result['query_count'] = count($queries);
            $result['sql_ms'] = round((float) collect($queries)->sum('time'), 2);
            $result['response_bytes'] = strlen($content);
            $result['http_status'] = $response?->getStatusCode();
            $result['slowest_queries'] = array_map(fn (array $query): array => [
                'time_ms' => round((float) ($query['time'] ?? 0), 2),
                'sql' => $query['query'] ?? '',
            ], array_slice($queries, 0, 3));

            if ($response && $response->getStatusCode() !== SymfonyResponse::HTTP_OK) {
                $result['status'] = 'error';
                $result['error'] = 'HTTP '.$response->getStatusCode()
                    .($response->headers->has('Location') ? ' to '.$response->headers->get('Location') : '')
                    .(($exception = property_exists($response, 'exception') ? $response->exception : null) ? ': '.$exception::class.': '.$exception->getMessage() : '');
            }
        }

        sort($timings);

        return array_merge($result, [
            'runs' => count($timings),
            'response_ms' => round($timings[intdiv(count($timings) - 1, 2)], 2),
            'p50_ms' => round($timings[intdiv(count($timings) - 1, 2)], 2),
            'p95_ms' => round($timings[(int) ceil(0.95 * count($timings)) - 1], 2),
            'peak_mb' => round(memory_get_peak_usage(true) / 1_048_576, 2),
        ]);
    }

    private function activeUser(string $role): ?User
    {
        return User::query()
            ->where('role', $role)
            ->where('is_active', true)
            ->where('account_status', User::ACCOUNT_STATUS_ACTIVE)
            ->orderBy('id')
            ->first();
    }

    private function dataTableQuery(array $columns, int $length, array $parameters = []): array
    {
        return array_merge([
            'draw' => 1,
            'start' => 0,
            'length' => $length,
            'search' => ['value' => '', 'regex' => false],
            'columns' => array_map(fn (array $column): array => [
                'data' => $column['data'],
                'name' => $column['name'],
                'searchable' => $column['searchable'] ?? true,
                'orderable' => $column['orderable'] ?? true,
                'search' => ['value' => '', 'regex' => false],
            ], $columns),
            'order' => [],
        ], $parameters);
    }

    private function datasetCounts(): array
    {
        return collect([
            'users',
            'purchase_requisitions',
            'pr_items',
            'quotations',
            'quotation_items',
            'purchase_orders',
            'po_quotations',
            'local_purchase_orders',
            'local_goods_receipts',
            'local_invoices',
            'notifications',
            'conversations',
            'messages',
            'material_claims',
            'qc_inspections',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
