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
            ->assertSee('data-column-up', false)->assertSee('data-column-down', false);
    }

    #[DataProvider('audiences')]
    public function test_modal_catalog_preset_and_post_request_produce_202_with_selected_options(string $role, string $locale): void
    {
        Queue::fake();
        $actor = User::factory()->create(['role' => $role]);
        $actor->preference()->create([...config('user_preferences.defaults'), 'locale' => $locale]);
        $this->actingAs($actor)->getJson(route('exports.definitions.show', $role.'.po'))->assertOk()->assertJsonCount(10, 'columns');
        $preset = $this->postJson(route('export-presets.store'), ['export_key' => $role.'.po', 'name' => 'UI preset', 'columns' => ['remark', 'po_number'], 'format' => 'csv', 'filters' => ['status' => 'active'], 'is_default' => true])->assertCreated()->json();
        $this->getJson(route('export-presets.index', ['export_key' => $role.'.po']))->assertOk()->assertJsonPath('data.0.is_default', true);
        $this->postJson(route($role.'.export.purchase-orders'), [...$preset['filters'], 'options' => ['columns' => $preset['columns'], 'format' => $preset['format']]])->assertAccepted();
        $job = ExportJob::sole();
        $this->assertSame(['remark', 'po_number'], $job->export_options['columns']);
        $this->assertSame($role, $job->export_options['audience']);
        $this->assertSame('csv', $job->format);
    }
}
