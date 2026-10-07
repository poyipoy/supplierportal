<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserDashboardRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_dashboard_hides_optional_panels_but_keeps_required_workflow_and_navigation(): void
    {
        $preferenceLookups = 0;
        DB::listen(function ($query) use (&$preferenceLookups): void {
            if (str_contains(strtolower($query->sql), 'user_preferences')) {
                $preferenceLookups++;
            }
        });
        $cases = [
            ['admin', 'admin', 'admin.dashboard', 'summary', 'rates', 'Administrative Attention', 'Manage Users'],
            ['purchasing', 'purchasing', 'purchasing.dashboard', 'metrics', 'exceptions', 'Operational Action Queue', 'Create Requisition'],
            ['finance', 'finance', 'finance.dashboard', 'batches', 'forecast', 'Payment Forecast', 'Finance AP Dashboard'],
            ['accounting', 'accounting', 'accounting.dashboard', 'lifecycle', 'invoices', 'Recent Submissions', 'Invoice Register'],
            ['qc', 'qc', 'qc.dashboard', 'charts', 'queue', 'Recent Inspection Activity', 'View Full History'],
            ['ga', 'ga', 'ga.dashboard', 'claims', 'statuses', __('ga.verification.basic_label', [], 'en'), 'DRP GA Draft'],
            ['supplier', 'supplier.import', 'supplier.dashboard', 'metrics', 'orders', 'Latest Purchase Orders', 'Supplier Dashboard'],
            ['supplier', 'supplier.local', 'local-supplier.dashboard', 'company', 'invoices', __('finance.labels.invoices_recent', [], 'en'), __('local_invoice.actions.submit', [], 'en')],
        ];
        foreach ($cases as [$role, $audience, $route, $hidden, $required, $requiredTitle, $action]) {
            $user = User::factory()->create(['role' => $role]);
            if ($role === 'supplier') {
                $user->supplierScopes()->delete();
                $scope = str_ends_with($audience, '.local') ? 'local' : 'import';
                DB::table('supplier_scopes')->insert(['supplier_id' => $user->id, 'scope' => $scope, 'created_at' => now(), 'updated_at' => now()]);
            }
            $user->preference()->create([
                ...array_diff_key(config('user_preferences.defaults'), ['sidebar_revision' => true]),
                'dashboard_preferences' => [$audience => ['hidden' => [$audience.'.'.$hidden, $audience.'.'.$required], 'order' => []]],
            ]);
            $preferenceLookups = 0;
            $response = $this->actingAs($user)->get(route($route))->assertOk();
            $this->assertSame(1, $preferenceLookups, 'One preference query for '.$audience);
            $response->assertDontSee('data-dashboard-widget="'.$audience.'.'.$hidden.'"', false);
            $response->assertSee('data-dashboard-widget="'.$audience.'.'.$required.'"', false);
            $response->assertSee($requiredTitle)->assertSee($action);
            $response->assertSee('id="sidebar"', false);
        }
    }

    public function test_saved_order_renders_on_dashboard_with_one_preference_lookup(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->preference()->create([
            ...array_diff_key(config('user_preferences.defaults'), ['sidebar_revision' => true]),
            'dashboard_preferences' => ['admin' => ['hidden' => [], 'order' => ['admin.summary', 'admin.rates']]],
        ]);
        $lookups = 0;
        DB::listen(function ($query) use (&$lookups): void {
            if (str_contains(strtolower($query->sql), 'user_preferences')) {
                $lookups++;
            }
        });
        $response = $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();
        $this->assertSame(1, $lookups);
        $html = $response->getContent();
        $this->assertLessThan(strpos($html, 'data-dashboard-widget="admin.rates"'), strpos($html, 'data-dashboard-widget="admin.summary"'));
    }
}
