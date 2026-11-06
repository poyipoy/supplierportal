<?php

namespace Tests\Feature;

use App\Models\ExportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdvancedExportUiTest extends TestCase
{
    use RefreshDatabase;

    public static function audiences(): array
    {
        return ['purchasing-en' => ['purchasing', 'en'], 'purchasing-id' => ['purchasing', 'id'], 'supplier-en' => ['supplier', 'en'], 'supplier-id' => ['supplier', 'id']];
    }

    #[DataProvider('audiences')]
    public function test_po_modal_renders_accessible_structure_calendar_and_localized_copy(string $role, string $locale): void
    {
        $actor = User::factory()->create(['role' => $role]);
        $actor->preference()->create([...config('user_preferences.defaults'), 'locale' => $locale]);
        $this->actingAs($actor)->get(route($role.'.purchase-orders.index'))->assertOk()
            ->assertSeeText($locale === 'en' ? 'Advanced Export' : 'Ekspor Lanjutan')
            ->assertSee('data-advanced-export-form', false)->assertSee('data-export-quick', false)
            ->assertSee('aria-modal="true"', false)->assertSee('aria-labelledby="advanced-export-'.$role.'-po-title"', false)
            ->assertSee('data-adasi-date-picker', false)->assertSee('advanced-export-'.$role.'-po-start_date', false)
            ->assertSee('ui-export-designer-modal', false)
            ->assertSee('role="listbox"', false)->assertSee('aria-orientation="horizontal"', false)
            ->assertSee('data-column-scroll', false)->assertSee('data-column-empty-rows', false)
            ->assertSee('data-column-menu', false)->assertSee('data-column-unhide', false)
            ->assertSee('data-column-command="move-left"', false)->assertSee('data-column-command="move-right"', false)
            ->assertDontSee('data-column-up', false)->assertDontSee('data-column-check', false);
    }

    #[DataProvider('audiences')]
    public function test_modal_catalog_preset_and_post_request_produce_202_with_selected_options(string $role, string $locale): void
    {
        Queue::fake();
        $actor = User::factory()->create(['role' => $role]);
        $actor->preference()->create([...config('user_preferences.defaults'), 'locale' => $locale]);
        // 10 original columns + 2 opt-in logistics columns (Mission 04-W, Q7).
        $this->actingAs($actor)->getJson(route('exports.definitions.show', $role.'.po'))->assertOk()->assertJsonCount(12, 'columns');
        $preset = $this->postJson(route('export-presets.store'), ['export_key' => $role.'.po', 'name' => 'UI preset', 'columns' => ['remark', 'po_number'], 'format' => 'csv', 'filters' => ['status' => 'active'], 'is_default' => true])->assertCreated()->json();
        $this->getJson(route('export-presets.index', ['export_key' => $role.'.po']))->assertOk()->assertJsonPath('data.0.is_default', true);
        $this->postJson(route($role.'.export.purchase-orders'), [...$preset['filters'], 'options' => ['columns' => $preset['columns'], 'format' => $preset['format']]])->assertAccepted();
        $job = ExportJob::sole();
        $this->assertSame(['remark', 'po_number'], $job->export_options['columns']);
        $this->assertSame($role, $job->export_options['audience']);
        $this->assertSame('csv', $job->format);
    }

    public function test_shipment_modal_unifies_search_with_accessible_hint_and_preserves_legacy_preset_compatibility(): void
    {
        Queue::fake();
        $actor = User::factory()->create(['role' => 'purchasing']);

        foreach (['en', 'id'] as $locale) {
            $actor->preference()->updateOrCreate(['user_id' => $actor->id], [...config('user_preferences.defaults'), 'locale' => $locale]);

            $expectedPlaceholder = __('exports.advanced.filters.placeholders.shipment_search', [], $locale);
            $expectedHint = __('exports.advanced.filters.hints.shipment_search', [], $locale);

            $this->actingAs($actor)->get(route('purchasing.shipments.index'))
                ->assertOk()
                ->assertSee('data-filter-name="search"', false)
                ->assertDontSee('data-filter-name="shipment_number"', false)
                ->assertSee('placeholder="'.e($expectedPlaceholder).'"', false)
                ->assertSee('aria-describedby="advanced-export-purchasing-shipments-search-hint"', false)
                ->assertSee('id="advanced-export-purchasing-shipments-search-hint"', false)
                ->assertSeeText($expectedHint);

            $definition = $this->actingAs($actor)->getJson(route('exports.definitions.show', 'purchasing.shipments'))
                ->assertOk()
                ->json('filters');

            $filterNames = array_column($definition, 'name');
            $this->assertContains('search', $filterNames);
            $this->assertNotContains('shipment_number', $filterNames);

            $searchFilter = collect($definition)->firstWhere('name', 'search');
            $this->assertSame($expectedPlaceholder, $searchFilter['placeholder'] ?? null);
            $this->assertSame($expectedHint, $searchFilter['hint'] ?? null);
        }

        // Legacy preset compatibility: storing and presenting a preset with shipment_number
        $this->actingAs($actor)->postJson(route('export-presets.store'), [
            'export_key' => 'purchasing.shipments',
            'name' => 'Legacy Shipment Filter',
            'columns' => ['shipment_number'],
            'format' => 'xlsx',
            'filters' => ['shipment_number' => 'SHP/2026/001'],
        ])->assertCreated();

        $presented = $this->actingAs($actor)->getJson(route('export-presets.index', ['export_key' => 'purchasing.shipments']))
            ->assertOk()
            ->json('data.0.filters');

        $this->assertSame('SHP/2026/001', $presented['search'] ?? null);
        $this->assertArrayNotHasKey('shipment_number', $presented);

        // Direct export with legacy shipment_number filter still succeeds
        $this->actingAs($actor)->postJson(route('purchasing.export.shipments'), [
            'shipment_number' => 'SHP/2026/001',
            'options' => ['columns' => ['shipment_number'], 'format' => 'csv'],
        ])->assertAccepted();
    }
}
