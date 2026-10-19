<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Dashboard\DashboardWidgetService;
use Tests\TestCase;

class DashboardSmartSpanTest extends TestCase
{
    public function test_php_and_javascript_share_smart_span_golden_cases(): void
    {
        $cases = json_decode(file_get_contents(base_path('tests/Fixtures/dashboard-smart-span-cases.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($cases);

        foreach ($cases as $case) {
            $this->assertSame($case['expected'], DashboardWidgetService::computeEffectiveSpans($case['items']), $case['name']);
        }
    }

    public function test_every_registered_widget_declares_a_supported_span_and_kind(): void
    {
        foreach (config('dashboard_widgets.audiences') as $audience => $definition) {
            foreach ($definition['widgets'] as $key => $widget) {
                $this->assertContains($widget['span'] ?? null, DashboardWidgetService::SPANS, "$audience:$key span");
                $this->assertContains($widget['kind'] ?? null, DashboardWidgetService::KINDS, "$audience:$key kind");
            }
        }
    }

    public function test_default_layouts_keep_the_widths_the_dashboards_had_before_smart_span(): void
    {
        // Widths previously hard-coded as `class="lg:tw-col-span-N"` on the view slots; everything else was 12.
        $legacy = [
            'purchasing' => ['purchasing.recent_requisitions' => 5, 'purchasing.arrivals' => 7],
            'admin' => ['admin.rates' => 8, 'admin.shortcuts' => 4],
            'supplier.import' => ['supplier.import.orders' => 8, 'supplier.import.updates' => 4],
        ];

        foreach (config('dashboard_widgets.audiences') as $audience => $definition) {
            $items = [];
            foreach ($definition['widgets'] as $key => $widget) {
                $items[] = ['key' => $key, 'span' => $widget['span'], 'visible' => true];
            }

            $expected = [];
            foreach (array_keys($definition['widgets']) as $key) {
                $expected[$key] = $legacy[$audience][$key] ?? 12;
            }

            $this->assertSame($expected, DashboardWidgetService::computeEffectiveSpans($items), $audience);
        }
    }

    public function test_layout_rows_expose_span_kind_and_rebalance_around_hidden_widgets(): void
    {
        $service = app(DashboardWidgetService::class);
        $admin = User::factory()->make(['role' => 'admin']);

        $default = array_column($service->layoutFor($admin, []), null, 'key');
        $this->assertSame(8, $default['admin.rates']['effective_span']);
        $this->assertSame(4, $default['admin.shortcuts']['effective_span']);
        $this->assertSame('table', $default['admin.rates']['kind']);
        $this->assertSame(8, $default['admin.rates']['span']);

        $rebalanced = array_column($service->layoutFor($admin, ['admin' => ['hidden' => ['admin.shortcuts'], 'order' => []]]), null, 'key');
        $this->assertFalse($rebalanced['admin.shortcuts']['visible']);
        $this->assertSame(12, $rebalanced['admin.rates']['effective_span'], 'the survivor fills the row');
        $this->assertSame(4, $rebalanced['admin.shortcuts']['effective_span'], 'a hidden row keeps its natural span for the wireframe');
        $this->assertSame(8, $rebalanced['admin.rates']['span'], 'the natural span is unchanged');
    }

    public function test_only_the_matching_audience_is_owned(): void
    {
        $service = app(DashboardWidgetService::class);
        $admin = User::factory()->make(['role' => 'admin']);
        $finance = User::factory()->make(['role' => 'finance']);
        $accounting = User::factory()->make(['role' => 'accounting']);

        $this->assertTrue($service->ownsAudience($admin, 'admin'));
        $this->assertFalse($service->ownsAudience($admin, 'finance'));
        $this->assertFalse($service->ownsAudience($admin, 'ga'));
        $this->assertTrue($service->ownsAudience($finance, 'finance'));
        $this->assertFalse($service->ownsAudience($finance, 'accounting'));
        $this->assertTrue($service->ownsAudience($accounting, 'accounting'));
        $this->assertFalse($service->ownsAudience($accounting, 'finance'));
        $this->assertFalse($service->ownsAudience($admin, 'does-not-exist'));
    }
}
