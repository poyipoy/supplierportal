<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocalizationRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', config('database.connections.mysql.database'));
        $this->assertSame('adasi_portal_test', DB::selectOne('SELECT DATABASE() AS db')->db);
    }

    public function test_every_dashboard_audience_uses_its_account_language_and_preserves_routes(): void
    {
        foreach (['en', 'id'] as $locale) {
            foreach (['admin', 'purchasing', 'finance', 'accounting', 'qc', 'ga', 'supplier.import', 'supplier.local'] as $audience) {
                $supplier = str_starts_with($audience, 'supplier.');
                $role = $supplier ? 'supplier' : $audience;
                $user = User::factory()->create(['role' => $role]);
                if ($supplier) {
                    $user->supplierScopes()->delete();
                    $user->supplierScopes()->firstOrCreate(['scope' => str_ends_with($audience, '.local') ? 'local' : 'import']);
                }
                $user->preference()->create([...config('user_preferences.defaults'), 'locale' => $locale]);
                $route = $audience === 'supplier.import' ? 'supplier.dashboard' : ($audience === 'supplier.local' ? 'local-supplier.dashboard' : $audience.'.dashboard');
                $response = $this->actingAs($user)->get(route($route))->assertOk();
                $response->assertSee('<html lang="'.$locale.'"', false);
                $response->assertSee('data-dashboard-widget=', false);
                $response->assertSee(route('profile.customization'), false);
                $response->assertSeeText(__('navigation.quick.dashboard', [], $locale));
                if ($audience === 'purchasing') {
                    $response->assertSee('purchasing.js.requisition_count', false);
                    $response->assertSee(__('purchasing.js.requisitions', [], $locale), false);
                    $response->assertSee(__('purchasing.js.requisition_count.one', [], $locale), false);
                }
                if ($audience === 'qc') {
                    $response->assertSee('js.qc.inspection_count', false);
                    $response->assertSee($locale === 'en' ? ':count inspection' : ':count inspeksi', false);
                }
            }
        }
    }

    public function test_dual_scope_navigation_switch_preserves_one_account_language(): void
    {
        $user = User::factory()->create(['role' => 'supplier']);
        foreach (['import', 'local'] as $scope) {
            $user->supplierScopes()->firstOrCreate(['scope' => $scope]);
        }
        $user->preference()->create([...config('user_preferences.defaults'), 'locale' => 'id']);
        foreach (['supplier.dashboard', 'local-supplier.dashboard'] as $route) {
            $this->actingAs($user)->get(route($route))->assertOk()->assertSee('<html lang="id"', false);
        }
        $this->assertSame('id', $user->fresh()->preference->locale);
    }
}
