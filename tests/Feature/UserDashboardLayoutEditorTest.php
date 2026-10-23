<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDashboardLayoutEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_gets_the_editor_controls_and_its_config_on_their_own_dashboard(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();

        $response->assertSee('data-dashboard-customize-link', false)
            ->assertSee('data-editor-enter', false)
            ->assertSee('data-editor-actionbar', false)
            ->assertSee('data-editor-strip', false)
            ->assertSee('Customize Dashboard')
            ->assertSee('Save Changes')
            ->assertSee('Reset to Default')
            // Without JavaScript the same affordance falls back to the Customization page.
            ->assertSee('<noscript>', false)
            ->assertSee(route('profile.customization').'#dashboard-layout-title', false);

        $config = $this->editorConfig($response->getContent());
        $this->assertSame(route('profile.dashboard-layout.update'), $config['saveUrl']);
        $this->assertSame(['admin.summary', 'admin.rates', 'admin.shortcuts', 'admin.notifications'], $config['defaultOrder']);
        $this->assertSame(['admin.summary', 'admin.rates', 'admin.shortcuts', 'admin.notifications'], array_column($config['widgets'], 'key'));
        $rates = array_column($config['widgets'], null, 'key')['admin.rates'];
        $this->assertTrue($rates['required']);
        $this->assertSame(8, $rates['span']);
        $this->assertSame('table', $rates['kind']);
        $this->assertSame(':count unsaved changes', $config['copy']['unsaved']);
    }

    public function test_only_optional_panels_get_a_visibility_switch(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $html = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        // summary, shortcuts and notifications are optional; the exchange-rate panel is required.
        $this->assertSame(3, substr_count($html, 'data-editor-visibility="'));
        $this->assertSame(8, substr_count($html, 'data-editor-move='), 'an up and a down control for each of the four panels');
    }

    public function test_viewing_someone_elses_audience_offers_no_editing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $finance = User::factory()->create(['role' => 'finance']);
        $admin->preference()->create([
            ...array_diff_key(config('user_preferences.defaults'), ['sidebar_revision' => true]),
            'dashboard_preferences' => ['finance' => ['hidden' => ['finance.batches'], 'order' => []]],
        ]);

        foreach ([[$admin, 'finance.dashboard'], [$admin, 'ga.dashboard'], [$finance, 'accounting.dashboard']] as [$user, $route]) {
            $this->actingAs($user)->get(route($route))->assertOk()
                ->assertDontSee('data-dashboard-customize-link', false)
                ->assertDontSee('data-editor-', false)
                ->assertDontSee('data-dashboard-placeholder', false)
                ->assertDontSee('Customize Dashboard');
        }

        $this->actingAs($finance)->get(route('finance.dashboard'))->assertOk()->assertSee('data-editor-enter', false);
    }

    public function test_a_hidden_panel_renders_no_content_and_only_the_owner_gets_a_placeholder(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->preference()->create([
            ...array_diff_key(config('user_preferences.defaults'), ['sidebar_revision' => true]),
            'dashboard_preferences' => ['admin' => ['hidden' => ['admin.summary'], 'order' => []]],
        ]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $response->assertDontSee('data-dashboard-widget="admin.summary"', false)
            ->assertDontSee(__('admin.copy.active_accounts'))
            ->assertSee('data-dashboard-placeholder="admin.summary"', false)
            ->assertSee('data-dashboard-widget="admin.rates"', false);

        $widgets = array_column($this->editorConfig($response->getContent())['widgets'], null, 'key');
        $this->assertFalse($widgets['admin.summary']['visible']);
        $this->assertTrue($widgets['admin.shortcuts']['visible']);
    }

    public function test_the_placeholder_is_hidden_until_the_editor_opens(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->preference()->create([
            ...array_diff_key(config('user_preferences.defaults'), ['sidebar_revision' => true]),
            'dashboard_preferences' => ['admin' => ['hidden' => ['admin.notifications'], 'order' => []]],
        ]);

        $html = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<div class="tw:min-w-0 tw:lg:col-span-12" data-dashboard-placeholder="admin\.notifications" hidden>/', $html);
        $this->assertMatchesRegularExpression('/<button type="button"[^>]*data-editor-enter hidden>/s', $html, 'the button stays hidden until the script boots');
    }

    public function test_customization_wireframe_keeps_the_form_contract_and_shows_proportional_tiles(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get(route('profile.customization'))->assertOk();
        $html = $response->getContent();

        foreach ([
            'data-dashboard-controls', 'data-dashboard-choice', 'data-dashboard-handle', 'data-dashboard-status',
            'data-dashboard-order-input', 'data-dashboard-hide-fallback', 'data-dashboard-visibility-toggle',
            'name="dashboard[order][]"', 'name="dashboard[hidden][]"', 'draggable="true"',
        ] as $contract) {
            $response->assertSee($contract, false);
        }

        $this->assertMatchesRegularExpression('/<li class="tw:col-span-12 [^"]*" data-dashboard-choice data-widget-key="admin\.summary"/', $html);
        $this->assertMatchesRegularExpression('/<li class="tw:col-span-8 [^"]*" data-dashboard-choice data-widget-key="admin\.rates"/', $html);
        $this->assertMatchesRegularExpression('/<li class="tw:col-span-4 [^"]*" data-dashboard-choice data-widget-key="admin\.shortcuts"/', $html);
        $this->assertSame(4, substr_count($html, 'data-dashboard-move="up"'), 'every tile, required or not, can be moved without dragging');
        $response->assertSee('data-widget-skeleton="table"', false)->assertSee('data-widget-skeleton="list"', false);
    }

    public function test_customization_wireframe_widens_the_survivor_and_dims_the_hidden_tile(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->preference()->create([
            ...array_diff_key(config('user_preferences.defaults'), ['sidebar_revision' => true]),
            'dashboard_preferences' => ['admin' => ['hidden' => ['admin.shortcuts'], 'order' => []]],
        ]);

        $html = $this->actingAs($admin)->get(route('profile.customization'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<li class="tw:col-span-12 [^"]*" data-dashboard-choice data-widget-key="admin\.rates"/', $html);
        $this->assertMatchesRegularExpression('/<li class="tw:col-span-4 [^"]*tw:border-dashed tw:opacity-70" data-dashboard-choice data-widget-key="admin\.shortcuts"/', $html);
    }

    public function test_customization_offers_to_edit_on_the_dashboard_only_to_users_who_have_one(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('profile.customization'))->assertOk()
            ->assertSee('Edit on Dashboard')
            ->assertSee(route('admin.dashboard', ['customize' => 1]), false);

        $supplier = User::factory()->create(['role' => 'supplier']);
        $supplier->supplierScopes()->delete();
        $this->actingAs($supplier)->get(route('profile.customization'))->assertOk()
            ->assertDontSee('Edit on Dashboard')
            ->assertDontSee('data-dashboard-controls', false);
    }

    private function editorConfig(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" data-editor-config>(.*?)</script>#s', $html, $matches));

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }
}
