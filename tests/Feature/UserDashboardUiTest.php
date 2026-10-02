<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDashboardUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_customization_has_named_native_accent_visibility_and_reorder_controls(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->get(route('profile.customization'))->assertOk();
        $response->assertSee('Accent Color')->assertSee('Dashboard Layout')->assertSee('Always shown');
        $response->assertSee('name="accent"', false)->assertSee('name="dashboard[hidden][]"', false);
        $response->assertSee('data-dashboard-move="up"', false)->assertSee('data-dashboard-move="down"', false);
        $response->assertSee('name="dashboard[order][]"', false)->assertSee('role="status"', false);
    }

    public function test_validation_preserves_trusted_order_and_hidden_selection_without_rendering_forged_metadata(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->withSession(['_old_input' => [
            'accent' => 'slate',
            'dashboard' => [
                'order' => ['admin.summary', 'evil/<script>', 'admin.notifications'],
                'hidden' => ['admin.notifications'],
            ],
        ]]);
        $response = $this->get(route('profile.customization'))->assertOk();
        $html = $response->getContent();
        $this->assertStringNotContainsString('evil/', $html);
        $this->assertLessThan(strpos($html, 'data-widget-key="admin.notifications"'), strpos($html, 'data-widget-key="admin.summary"'));
        $response->assertSee('value="slate" checked', false);
        $response->assertSee('name="dashboard[hidden][]" value="admin.notifications" checked', false);
    }

    public function test_supplier_without_context_gets_appearance_but_no_operational_dashboard_controls(): void
    {
        $user = User::factory()->create(['role' => 'supplier']);
        $user->supplierScopes()->delete();
        $response = $this->actingAs($user)->get(route('profile.customization'))->assertOk();
        $response->assertSee('Accent Color')->assertSee('Choose a supplier portal');
        $response->assertDontSee('data-dashboard-controls', false);
    }

    public function test_customization_renders_drag_handle_and_draggable_attributes(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->get(route('profile.customization'))->assertOk();
        $response->assertSee('draggable="true"', false);
        $response->assertSee('data-dashboard-handle', false);
        $response->assertSee('data-dashboard-move="up"', false);
        $response->assertSee('data-dashboard-move="down"', false);
        $response->assertSee('data-dashboard-status', false);
    }

    public function test_dashboard_layout_component_renders_customize_affordance_link(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();
        $response->assertSee('data-dashboard-customize-link', false);
        $response->assertSee('Customize Layout');
        $response->assertSee(route('profile.customization') . '#dashboard-layout-title', false);
    }

    public function test_granular_dashboard_reset_button_is_rendered(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->get(route('profile.customization'))->assertOk();
        $response->assertSee('Reset Layout to Default');
        $response->assertSee('form="resetDashboardLayout"', false);
        $response->assertSee('name="scope" value="dashboard"', false);
    }
}
