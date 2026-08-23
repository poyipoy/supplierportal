<?php

namespace App\Console\Commands;

use App\Http\Controllers\ConversationMessageController;
use App\Http\Controllers\Purchasing\MaterialClaimController;
use App\Http\Controllers\Purchasing\PriceComparisonController;
use App\Http\Controllers\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Purchasing\PurchaseRequisitionController;
use App\Http\Controllers\Purchasing\PurchasingController;
use App\Http\Controllers\Purchasing\QuotationListController;
use App\Http\Controllers\Qc\QcInspectionController;
use App\Http\Controllers\Supplier\SupplierController;
use App\Models\User;
use App\Services\NotificationSummaryService;
use Illuminate\Console\Command;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class ProfileCriticalPaths extends Command
{
    protected $signature = 'performance:profile-critical
        {--json : Emit machine-readable JSON}
        {--length=25 : DataTable page size}';

    protected $description = 'Profile representative read-only application paths in a local environment';

    public function handle(NotificationSummaryService $notificationSummary): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This profiler is restricted to local and testing environments.');

            return self::FAILURE;
        }

        $purchasing = User::query()
            ->where('role', 'purchasing')
            ->where('is_active', true)
            ->first();
        $supplier = User::query()
            ->where('role', 'supplier')
            ->where('is_active', true)
            ->first();
        $qc = User::query()
            ->where('role', 'qc')
            ->where('is_active', true)
            ->first();

        if (! $purchasing || ! $supplier || ! $qc) {
            $this->error('Active purchasing, supplier, and QC users are required.');

            return self::FAILURE;
        }

        $length = max(1, min(100, (int) $this->option('length')));
        $profiles = [];

        // Direct controller rendering does not pass through ShareErrorsFromSession.
        ViewFacade::share('errors', new ViewErrorBag);

        $profiles[] = $this->profile('purchasing.dashboard', $purchasing, function () {
            return app(PurchasingController::class)->dashboard();
        });

        $profiles[] = $this->profile('supplier.dashboard', $supplier, function () {
            return app(SupplierController::class)->dashboard();
        });

        $profiles[] = $this->profile('purchasing.requisitions.data', $purchasing, function () use ($length) {
            $request = $this->dataTableRequest('/purchasing/requisitions', [
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
            ], $length);

            return app(PurchaseRequisitionController::class)->index($request);
        });

        $profiles[] = $this->profile('purchasing.purchase-orders.data', $purchasing, function () use ($length) {
            $request = $this->dataTableRequest('/purchasing/purchase-orders', [
                ['data' => 'po_number_display', 'name' => 'po_number'],
                ['data' => 'supplier_name', 'name' => 'supplier_name', 'orderable' => false],
                ['data' => 'period_name', 'name' => 'period_name', 'orderable' => false],
                ['data' => 'pr_reference', 'name' => 'pr_reference', 'orderable' => false],
                ['data' => 'remark_display', 'name' => 'remark_display', 'orderable' => false],
                ['data' => 'total_idr', 'name' => 'total_idr', 'searchable' => false, 'orderable' => false],
                ['data' => 'status_badge', 'name' => 'status'],
                ['data' => 'estimated_date', 'name' => 'estimated_arrival'],
                ['data' => 'action', 'name' => 'action', 'searchable' => false, 'orderable' => false],
            ], $length);

            return app(PurchaseOrderController::class)->index($request);
        });

        $profiles[] = $this->profile('purchasing.comparison.inter-supplier', $purchasing, function () {
            $request = $this->request('/purchasing/comparison/inter-supplier');

            return app(PriceComparisonController::class)->interSupplier($request);
        });

        $historicalMaterial = DB::table('pr_items')
            ->join('quotation_items', 'quotation_items.pr_item_id', '=', 'pr_items.id')
            ->join('quotations', 'quotations.id', '=', 'quotation_items.quotation_id')
            ->join('po_quotations', 'po_quotations.quotation_id', '=', 'quotations.id')
            ->where('quotations.supplier_id', $supplier->getKey())
            ->whereNull('quotations.deleted_at')
            ->orderBy('pr_items.id')
            ->value('pr_items.material_name');

        $profiles[] = $this->profile('purchasing.comparison.historical', $purchasing, function () use ($supplier, $historicalMaterial) {
            $request = $this->request('/purchasing/comparison/historical', array_filter([
                'supplier_id' => $supplier->getRouteKey(),
                'material_name' => $historicalMaterial,
                'period_view' => 'monthly',
                'range' => 'all',
            ]));

            return app(PriceComparisonController::class)->historical($request);
        });

        $profiles[] = $this->profile('purchasing.comparison.vs-best.data', $purchasing, function () use ($length) {
            $request = $this->dataTableRequest('/purchasing/comparison/vs-best/data', [
                ['data' => 'material_display', 'name' => 'current_pr_items.material_name'],
                ['data' => 'current_price_display', 'name' => 'current_price_idr'],
                ['data' => 'best_price_display', 'name' => 'best_price_idr'],
                ['data' => 'diff_display', 'name' => 'diff_idr_per_kg'],
                ['data' => 'potential_difference_display', 'name' => 'potential_difference_idr'],
                ['data' => 'status_badge', 'name' => 'status_badge', 'searchable' => false],
                ['data' => 'action', 'name' => 'action', 'searchable' => false, 'orderable' => false],
            ], $length, [
                'date_from' => '2020-01',
                'date_to' => '2035-12',
            ]);

            return app(PriceComparisonController::class)->vsBestPriceData($request);
        });

        $profiles[] = $this->profile('purchasing.quotations.index', $purchasing, function () {
            return app(QuotationListController::class)->index(
                $this->request('/purchasing/quotations'),
            );
        });

        $profiles[] = $this->profile('qc.inspections.history.data', $qc, function () use ($length) {
            $request = $this->dataTableRequest('/qc/inspections/data-history', [
                ['data' => 'po_number', 'name' => 'purchaseOrder.po_number'],
                ['data' => 'supplier_name', 'name' => 'purchaseOrder.supplier.name'],
                ['data' => 'inspected_date', 'name' => 'inspected_at'],
                ['data' => 'status_badge', 'name' => 'status'],
                ['data' => 'inspector_name', 'name' => 'inspector.name'],
                ['data' => 'action', 'name' => 'action', 'searchable' => false, 'orderable' => false],
            ], $length);

            return app(QcInspectionController::class)->dataHistory($request);
        });

        $profiles[] = $this->profile('purchasing.claims.history.data', $purchasing, function () use ($length) {
            $request = $this->dataTableRequest('/purchasing/claims/data-history', [
                ['data' => 'claim_id', 'name' => 'id'],
                ['data' => 'po_number', 'name' => 'purchaseOrder.po_number'],
                ['data' => 'supplier_name', 'name' => 'purchaseOrder.supplier.name'],
                ['data' => 'created_date', 'name' => 'created_at'],
                ['data' => 'deadline_display', 'name' => 'deadline'],
                ['data' => 'status_badge', 'name' => 'status'],
                ['data' => 'action', 'name' => 'action', 'searchable' => false, 'orderable' => false],
            ], $length);

            return app(MaterialClaimController::class)->dataHistory($request);
        });

        $profiles[] = $this->profile('notifications.counts', $purchasing, function () use ($notificationSummary, $purchasing) {
            return response()->json($notificationSummary->countsForUser($purchasing));
        });

        $profiles[] = $this->profile('notifications.summary', $purchasing, function () use ($notificationSummary, $purchasing) {
            return response()->json($notificationSummary->forUser($purchasing));
        });

        $profiles[] = $this->profile('conversations.unread-count', $purchasing, function () {
            return app(ConversationMessageController::class)->unreadCount();
        });

        $payload = [
            'environment' => [
                'app_env' => app()->environment(),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'database' => DB::connection()->getDatabaseName(),
            ],
            'dataset' => $this->datasetCounts(),
            'profiles' => $profiles,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Path', 'Queries', 'SQL ms', 'Response ms', 'Peak MB', 'Bytes', 'Status'],
            array_map(fn (array $profile): array => [
                $profile['path'],
                $profile['query_count'],
                $profile['sql_ms'],
                $profile['response_ms'],
                $profile['peak_mb'],
                $profile['response_bytes'],
                $profile['status'],
            ], $profiles),
        );

        return collect($profiles)->contains(fn (array $profile): bool => $profile['status'] !== 'ok')
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function profile(string $path, User $user, callable $operation): array
    {
        Auth::guard('web')->setUser($user);

        DB::flushQueryLog();
        DB::enableQueryLog();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        $startedAt = hrtime(true);
        $status = 'ok';
        $responseBytes = 0;
        $error = null;

        try {
            $result = $operation();
            $responseBytes = strlen($this->responseContent($result));
        } catch (Throwable $exception) {
            $status = 'error';
            $error = $exception::class.': '.$exception->getMessage();
        }

        $responseMs = round((hrtime(true) - $startedAt) / 1_000_000, 2);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        usort($queries, fn (array $left, array $right): int => ($right['time'] ?? 0) <=> ($left['time'] ?? 0));

        return [
            'path' => $path,
            'query_count' => count($queries),
            'sql_ms' => round((float) collect($queries)->sum('time'), 2),
            'response_ms' => $responseMs,
            'peak_mb' => round(memory_get_peak_usage(true) / 1_048_576, 2),
            'response_bytes' => $responseBytes,
            'status' => $status,
            'error' => $error,
            'slowest_queries' => array_map(fn (array $query): array => [
                'time_ms' => round((float) ($query['time'] ?? 0), 2),
                'sql' => $query['query'] ?? '',
            ], array_slice($queries, 0, 3)),
        ];
    }

    private function request(string $uri, array $parameters = []): Request
    {
        $request = Request::create($uri, 'GET', $parameters);
        $request->setUserResolver(fn () => Auth::user());
        app()->instance('request', $request);

        return $request;
    }

    private function dataTableRequest(string $uri, array $columns, int $length, array $parameters = []): Request
    {
        $parameters = array_merge([
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

        $request = Request::create($uri, 'GET', $parameters, [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $request->setUserResolver(fn () => Auth::user());
        app()->instance('request', $request);

        return $request;
    }

    private function responseContent(mixed $result): string
    {
        if ($result instanceof View) {
            return $result->render();
        }

        if ($result instanceof SymfonyResponse) {
            return (string) $result->getContent();
        }

        if ($result instanceof Response) {
            return (string) $result->getContent();
        }

        return is_string($result) ? $result : (string) json_encode($result);
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
            'notifications',
            'conversations',
            'messages',
            'material_claims',
            'qc_inspections',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
