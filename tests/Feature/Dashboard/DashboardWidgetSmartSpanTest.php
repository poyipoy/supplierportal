<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use App\Services\Dashboard\DashboardWidgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardWidgetSmartSpanTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_operational_widgets_are_registered_as_full_width_rows(): void
    {
        $service = app(DashboardWidgetService::class);

        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true, 'account_status' => 'ACTIVE']);
        $purchasingLayout = array_column($service->layoutFor($purchasing, []), null, 'key');
        $this->assertArrayHasKey('purchasing.po_acknowledgements', $purchasingLayout);
        $this->assertSame(12, $purchasingLayout['purchasing.po_acknowledgements']['span']);
        $this->assertSame(12, $purchasingLayout['purchasing.po_acknowledgements']['effective_span']);

        $finance = User::factory()->create(['role' => 'finance', 'is_active' => true, 'account_status' => 'ACTIVE']);
        $financeLayout = array_column($service->layoutFor($finance, []), null, 'key');
        $this->assertArrayHasKey('finance.three_way_exceptions', $financeLayout);
        $this->assertSame(12, $financeLayout['finance.three_way_exceptions']['span']);
        $this->assertSame(12, $financeLayout['finance.three_way_exceptions']['effective_span']);
    }

    public function test_purchasing_and_finance_dashboards_render_the_new_queues(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true, 'account_status' => 'ACTIVE']);
        $finance = User::factory()->create(['role' => 'finance', 'is_active' => true, 'account_status' => 'ACTIVE']);

        $this->actingAs($purchasing)
            ->get(route('purchasing.dashboard'))
            ->assertOk()
            ->assertSee(__('purchasing.po_ack.queue_empty_title'));

        $this->actingAs($finance)
            ->get(route('finance.dashboard'))
            ->assertOk()
            ->assertSee(__('local_invoice.three_way_match.queue_empty_title'));
    }
}
