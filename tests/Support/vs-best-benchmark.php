<?php

/*
 * Guarded vs-best (Best Price Comparison) benchmark on the isolated test database.
 *
 *   php tests/Support/vs-best-benchmark.php seed <quotation-items>
 *   php tests/Support/vs-best-benchmark.php measure <label> [runs]
 *   php tests/Support/vs-best-benchmark.php cleanup
 *
 * The fixture is synthetic, deterministic (fixed random seed) and owned by the
 * VSB-BENCH markers below. "measure" never writes; "cleanup" removes only
 * rows carrying those markers. Never point this at application data.
 */

use App\Http\Controllers\Purchasing\PriceComparisonController;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$benchmarkDatabase = getenv('VS_BEST_BENCH_DB') ?: 'adasi_portal_test';
if (! in_array($benchmarkDatabase, ['adasi_portal_test', 'adasi_import_formats_test'], true)) {
    throw new RuntimeException('Unsafe benchmark database name.');
}
foreach (['APP_ENV' => 'testing', 'DB_DATABASE' => $benchmarkDatabase, 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::selectOne('SELECT DATABASE() AS db')->db !== $benchmarkDatabase) {
    throw new RuntimeException('Unsafe benchmark database.');
}

const VSB_PERIOD = 'VSB-BENCH';
const VSB_EMAIL = 'vsb-bench-%@bench.test';
$metaPath = storage_path('framework/testing/vs-best-bench.json');
$mode = $argv[1] ?? '';

$benchUsers = fn () => User::where('email', 'like', VSB_EMAIL);

if ($mode === 'seed') {
    $items = (int) ($argv[2] ?? 4300);
    if ($items < 100 || $items > 50000) {
        throw new RuntimeException('Quotation item count must be 100..50000.');
    }
    if ($benchUsers()->exists()) {
        throw new RuntimeException('A VSB-BENCH fixture already exists; run cleanup first.');
    }
    mt_srand(42);
    $started = microtime(true);
    DB::transaction(function () use ($items, $metaPath) {
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true, 'email' => 'vsb-bench-purchasing@bench.test']);
        $suppliers = [];
        for ($s = 0; $s < 20; $s++) {
            $suppliers[] = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'name' => 'VSB Supplier '.$s, 'email' => 'vsb-bench-supplier-'.$s.'@bench.test'])->id;
        }
        $periodId = DB::table('periods')->insertGetId(['name' => VSB_PERIOD, 'month' => 1, 'year' => 2026, 'status' => 'open', 'created_by' => $purchasing->id, 'created_at' => now(), 'updated_at' => now()]);
        // USD has an old and a current benchmark; JPY current only; CNY has no rate
        // at all (missing-rate path); IDR is the identity currency.
        $rates = [];
        foreach ([['USD', '15000.0000', '2024-01-01'], ['USD', '16250.0000', '2026-01-01'], ['JPY', '105.5000', '2026-01-01']] as [$currency, $rate, $from]) {
            $rates[$currency] = DB::table('exchange_rates')->insertGetId(['currency' => $currency, 'rate_to_idr' => $rate, 'valid_from' => $from, 'created_by' => $purchasing->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $materials = max(10, intdiv($items, 10));
        $prCount = max(1, intdiv($items, 10)); // 5 items x 2 quotations per PR
        $poSequence = 0;
        for ($p = 0; $p < $prCount; $p++) {
            $prDeleted = mt_rand(1, 100) <= 2 ? '2026-09-01 00:00:00' : null;
            $prId = DB::table('purchase_requisitions')->insertGetId(['period_id' => $periodId, 'created_by' => $purchasing->id, 'pr_number' => sprintf('VSB/%05d', $p),
                'status' => 'bidding', 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $prDeleted]);
            $itemIds = [];
            for ($i = 0; $i < 5; $i++) {
                $itemIds[] = DB::table('pr_items')->insertGetId(['pr_id' => $prId, 'material_name' => sprintf('MAT-%04d', mt_rand(1, $materials)), 'quantity' => mt_rand(1, 6),
                    'weight_needed' => mt_rand(10, 500) / 10, 'shape' => 'Flat', 'hs_code' => '7209.16.00', 'created_at' => now(), 'updated_at' => now()]);
            }
            $picked = (array) array_rand(array_flip($suppliers), 2);
            foreach ($picked as $supplierId) {
                $roll = mt_rand(1, 100);
                $status = $roll <= 45 ? 'accepted' : ($roll <= 85 ? 'submitted' : ($roll <= 93 ? 'rejected' : 'draft'));
                $currencyRoll = mt_rand(1, 100);
                $currency = $currencyRoll <= 60 ? 'USD' : ($currencyRoll <= 80 ? 'IDR' : ($currencyRoll <= 90 ? 'JPY' : 'CNY'));
                $submitted = sprintf('%d-%02d-%02d 09:00:00', mt_rand(2025, 2026), mt_rand(1, 12), mt_rand(1, 28));
                $quoteId = DB::table('quotations')->insertGetId(['pr_id' => $prId, 'supplier_id' => $supplierId, 'currency' => $currency,
                    'exchange_rate_id' => $rates[$currency] ?? null, 'status' => $status, 'submitted_at' => $status === 'draft' ? null : $submitted,
                    'created_at' => now(), 'updated_at' => now(), 'deleted_at' => mt_rand(1, 100) <= 2 ? '2026-09-01 00:00:00' : null]);
                $rows = [];
                foreach ($itemIds as $itemId) {
                    $availability = mt_rand(1, 100);
                    // is_available is NOT NULL (default 1) in the current schema.
                    $available = $availability <= 5 ? 0 : 1;
                    // Half-unit price steps create ties inside a material.
                    $price = $available === 0 ? null : (mt_rand(1, 100) <= 2 ? 0 : mt_rand(10, 60) / 2);
                    $rows[] = ['quotation_id' => $quoteId, 'pr_item_id' => $itemId, 'price_per_kg' => $price, 'amount' => $price === null ? 0 : $price * 10,
                        'is_available' => $available, 'created_at' => now(), 'updated_at' => now()];
                }
                DB::table('quotation_items')->insert($rows);
                if ($status === 'accepted') {
                    $poSequence++;
                    $poId = DB::table('purchase_orders')->insertGetId(['supplier_id' => $supplierId, 'currency' => $currency, 'exchange_rate_id' => $rates[$currency] ?? null,
                        'po_number' => sprintf('VSB-PO-%05d', $poSequence), 'status' => 'completed', 'created_by' => $purchasing->id,
                        'created_at' => $submitted, 'updated_at' => $submitted, 'deleted_at' => mt_rand(1, 100) <= 1 ? '2026-09-01 00:00:00' : null]);
                    DB::table('po_quotations')->insert(['po_id' => $poId, 'quotation_id' => $quoteId, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
        file_put_contents($metaPath, json_encode(['items' => $items, 'purchasing_id' => $purchasing->id], JSON_THROW_ON_ERROR));
    });
    $counts = [
        'quotation_items' => DB::table('quotation_items')->join('quotations', 'quotations.id', '=', 'quotation_items.quotation_id')->whereIn('quotations.supplier_id', $benchUsers()->select('id'))->count(),
        'history_items' => DB::table('quotation_items')->join('po_quotations', 'po_quotations.quotation_id', '=', 'quotation_items.quotation_id')->join('purchase_orders', 'purchase_orders.id', '=', 'po_quotations.po_id')->where('purchase_orders.po_number', 'like', 'VSB-PO-%')->count(),
        'materials' => DB::table('pr_items')->join('purchase_requisitions', 'purchase_requisitions.id', '=', 'pr_items.pr_id')->where('purchase_requisitions.pr_number', 'like', 'VSB/%')->distinct()->count('material_name'),
    ];
    echo json_encode(['mode' => 'seed', 'seconds' => round(microtime(true) - $started, 2)] + $counts, JSON_PRETTY_PRINT).PHP_EOL;
    exit(0);
}

if ($mode === 'measure') {
    $label = $argv[2] ?? 'run';
    $runs = max(1, (int) ($argv[3] ?? 20));
    $meta = json_decode((string) @file_get_contents($metaPath), true);
    if (! $meta || ! $benchUsers()->exists()) {
        throw new RuntimeException('Seed the VSB-BENCH fixture first.');
    }
    $user = User::findOrFail($meta['purchasing_id']);
    Auth::guard('web')->setUser($user);
    $columns = array_map(fn ($c) => ['data' => $c[0], 'name' => $c[1], 'searchable' => $c[2], 'orderable' => false, 'search' => ['value' => '', 'regex' => false]], [
        ['material_display', 'current_pr_items.material_name', true], ['current_price_display', 'current_price_idr', false],
        ['best_price_display', 'best_price_idr', false], ['diff_display', 'diff_idr_per_kg', false],
        ['potential_difference_display', 'potential_difference_idr', false], ['status_badge', 'diff_percent', false], ['action', 'action', false],
    ]);
    $call = function (array $extra, int $length) use ($columns): array {
        Cache::flush(); // the unfiltered summary is cached for 5 minutes; measure the uncached path
        $request = Request::create('/purchasing/comparison/vs-best/data', 'GET', array_merge(['draw' => 1, 'start' => 0, 'length' => $length,
            'search' => ['value' => '', 'regex' => false], 'columns' => $columns, 'order' => []], $extra), [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $request->setUserResolver(fn () => Auth::user());
        app()->instance('request', $request);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        $response = app(PriceComparisonController::class)->vsBestPriceData($request);
        $ms = (hrtime(true) - $started) / 1e6;
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return ['ms' => $ms, 'queries' => count($log), 'sql_ms' => array_sum(array_column($log, 'time')), 'content' => $response->getContent(), 'log' => $log];
    };
    $scenarios = [
        'default' => [[], 25],
        'date_2026' => [['date_from' => '2026-01', 'date_to' => '2026-12'], 25],
        'keyword' => [['search' => ['value' => 'MAT-00', 'regex' => false]], 25],
    ];
    $result = ['label' => $label, 'items' => $meta['items'], 'runs' => $runs, 'mysql' => DB::selectOne('select version() v')->v];
    foreach ($scenarios as $name => [$extra, $length]) {
        $call($extra, $length); // warm-up
        $samples = [];
        $last = null;
        for ($r = 0; $r < $runs; $r++) {
            $last = $call($extra, $length);
            $samples[] = $last['ms'];
        }
        sort($samples);
        $pct = fn (float $p) => round($samples[max(0, (int) ceil($p * count($samples)) - 1)], 1);
        // Full output (every row, every column) for equivalence checks.
        $full = $call($extra, -1);
        $decoded = json_decode($full['content'], true);
        unset($decoded['input'], $decoded['queries']);
        $result['scenarios'][$name] = ['p50_ms' => $pct(0.5), 'p95_ms' => $pct(0.95), 'queries' => $last['queries'], 'sql_ms' => round($last['sql_ms'], 1),
            'bytes_page' => strlen($last['content']), 'rows' => $decoded['recordsFiltered'] ?? null, 'summary' => $decoded['summary'] ?? null,
            'full_output_sha256' => hash('sha256', json_encode([$decoded['data'] ?? null, $decoded['summary'] ?? null, $decoded['recordsTotal'] ?? null, $decoded['recordsFiltered'] ?? null]))];
        if ($name !== 'keyword') {
            foreach ($last['log'] as $index => $query) {
                if (str_contains($query['query'], 'ROW_NUMBER()')) {
                    $plan = DB::select('EXPLAIN ANALYZE '.$query['query'], $query['bindings']);
                    $result['scenarios'][$name]['explain'][$index] = ['sql_ms' => round($query['time'], 1), 'plan' => array_values((array) $plan[0])[0]];
                }
            }
        }
    }
    $result['peak_php_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

if ($mode === 'cleanup') {
    $userIds = $benchUsers()->pluck('id')->all();
    DB::transaction(function () use ($userIds) {
        $poIds = DB::table('purchase_orders')->where('po_number', 'like', 'VSB-PO-%')->pluck('id');
        DB::table('po_quotations')->whereIn('po_id', $poIds)->delete();
        DB::table('purchase_orders')->whereIn('id', $poIds)->delete();
        $quoteIds = DB::table('quotations')->whereIn('supplier_id', $userIds)->pluck('id');
        DB::table('quotation_items')->whereIn('quotation_id', $quoteIds)->delete();
        DB::table('quotations')->whereIn('id', $quoteIds)->delete();
        $prIds = DB::table('purchase_requisitions')->where('pr_number', 'like', 'VSB/%')->pluck('id');
        DB::table('pr_items')->whereIn('pr_id', $prIds)->delete();
        DB::table('purchase_requisitions')->whereIn('id', $prIds)->delete();
        DB::table('periods')->where('name', VSB_PERIOD)->delete();
        DB::table('exchange_rates')->whereIn('created_by', $userIds)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
    });
    @unlink($metaPath);
    echo json_encode(['mode' => 'cleanup', 'users_removed' => count($userIds)]).PHP_EOL;
    exit(0);
}

fwrite(STDERR, "Usage: seed <items> | measure <label> [runs] | cleanup\n");
exit(1);
