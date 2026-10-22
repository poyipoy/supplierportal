<?php

namespace Tests\Feature;

use App\Models\SupplierScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * performance:profile-critical used to call controller methods directly, so three paths broke silently when their
 * signatures changed. It now sends real requests through the HTTP kernel; this keeps every path reachable.
 */
class ProfileCriticalPathsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_path_with_data_responds_ok_and_nothing_persists(): void
    {
        foreach (['purchasing', 'qc', 'finance'] as $role) {
            User::factory()->create(['role' => $role, 'is_active' => true, 'account_status' => User::ACCOUNT_STATUS_ACTIVE]);
        }
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'account_status' => User::ACCOUNT_STATUS_ACTIVE]);
        foreach (['import', 'local'] as $scope) {
            SupplierScope::firstOrCreate(['supplier_id' => $supplier->id, 'scope' => $scope]);
        }
        $rowsBefore = $this->rowCounts();

        $exitCode = Artisan::call('performance:profile-critical', ['--json' => true]);
        $profiles = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['profiles'])->keyBy('path');

        $this->assertSame(0, $exitCode, (string) $profiles->where('status', 'error')->pluck('error', 'path'));
        $this->assertCount(20, $profiles);
        foreach (['purchasing.dashboard', 'supplier.dashboard', 'purchasing.purchase-orders.data', 'purchasing.claims.data-history',
            'finance.dashboard', 'finance.invoices.index', 'local-supplier.invoices.index', 'notifications.summary'] as $path) {
            $this->assertSame('ok', $profiles[$path]['status'], $path.': '.$profiles[$path]['error']);
            $this->assertSame(200, $profiles[$path]['http_status']);
            $this->assertGreaterThan(0, $profiles[$path]['response_bytes']);
        }
        $this->assertSame(['ok', 'skipped'], $profiles->pluck('status')->unique()->sort()->values()->all());
        $this->assertSame($rowsBefore, $this->rowCounts());
    }

    public function test_it_refuses_to_run_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';

        $this->assertSame(1, Artisan::call('performance:profile-critical'));
        $this->assertStringContainsString('restricted to local and testing', Artisan::output());
    }

    private function rowCounts(): array
    {
        return collect(['users', 'supplier_scopes', 'user_preferences', 'notifications', 'messages', 'auth_audit_logs'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
            ->all();
    }
}
