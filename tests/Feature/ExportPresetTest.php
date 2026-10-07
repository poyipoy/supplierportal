<?php

namespace Tests\Feature;

use App\Models\ExportJob;
use App\Models\ExportPreset;
use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExportPresetTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'purchasing']);
        $this->other = User::factory()->create(['role' => 'purchasing']);
    }

    private function input(string $name = 'My columns', array $extra = []): array
    {
        return [...['export_key' => 'purchasing.po', 'name' => $name, 'columns' => ['status', 'po_number'], 'format' => 'csv', 'filters' => []], ...$extra];
    }

    private function create(string $name = 'My columns', array $extra = []): ExportPreset
    {
        $response = $this->actingAs($this->owner)->postJson(route('export-presets.store'), $this->input($name, $extra))->assertCreated();
        $preset = ExportPreset::query()->where('user_id', $this->owner->id)->where('name', $name)->firstOrFail();
        $this->assertSame($preset->getRouteKey(), $response->json('id'));
        $this->assertNotSame((string) $preset->id, $response->json('id'));
        $this->assertArrayNotHasKey('user_id', $response->json());

        return $preset;
    }

    public function test_definition_catalog_is_authenticated_authorized_and_localized(): void
    {
        $this->getJson(route('exports.definitions.show', 'purchasing.po'))->assertUnauthorized();
        $this->actingAs($this->owner)->getJson(route('exports.definitions.show', 'purchasing.po'))->assertOk()->assertJsonCount(10, 'columns')->assertJsonPath('columns.0.key', 'po_number')->assertJsonPath('columns.0.required', true);
        $this->getJson(route('exports.definitions.show', 'unknown.po'))->assertNotFound();
        $supplier = User::factory()->create(['role' => 'supplier']);
        $supplier->preference()->create([...config('user_preferences.defaults'), 'locale' => 'id']);
        $this->actingAs($supplier)->getJson(route('exports.definitions.show', 'purchasing.po'))->assertNotFound();
        $response = $this->getJson(route('exports.definitions.show', 'supplier.po'))->assertOk()->assertJsonPath('columns.0.label', 'Nomor PO');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_crud_keeps_personal_scope_hashids_and_column_order(): void
    {
        $preset = $this->create();
        $this->getJson(route('export-presets.index', ['export_key' => 'purchasing.po']))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.columns', ['status', 'po_number']);
        $this->putJson(route('export-presets.update', $preset), $this->input('Renamed', ['columns' => ['po_number', 'remark'], 'format' => 'xlsx']))->assertOk()->assertJsonPath('name', 'Renamed');
        $this->assertSame('xlsx', $preset->fresh()->format);
        $this->deleteJson(route('export-presets.destroy', $preset))->assertNoContent();
        $this->assertDatabaseMissing('export_presets', ['id' => $preset->id]);
    }

    public function test_foreign_preset_update_delete_default_and_listing_are_blocked(): void
    {
        $preset = $this->create();
        $this->actingAs($this->other)->getJson(route('export-presets.index', ['export_key' => 'purchasing.po']))->assertOk()->assertJsonCount(0, 'data');
        $this->putJson(route('export-presets.update', $preset), $this->input('Foreign edit'))->assertForbidden();
        $this->deleteJson(route('export-presets.destroy', $preset))->assertForbidden();
        $this->postJson(route('export-presets.default', $preset))->assertForbidden();
        $this->assertSame('My columns', $preset->fresh()->name);
        $this->actingAs($this->owner)->putJson('/export-presets/'.$preset->id, $this->input('Plain'))->assertNotFound();
        $this->deleteJson('/export-presets/'.$preset->id)->assertNotFound();
        $this->postJson('/export-presets/'.$preset->id.'/default')->assertNotFound();
    }

    public function test_limit_duplicate_names_and_one_default_are_enforced_per_owner_key(): void
    {
        config(['exports.max_presets_per_key' => 2]);
        $first = $this->create('First', ['is_default' => true]);
        $second = $this->create('Second', ['is_default' => true]);
        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $this->postJson(route('export-presets.default', $first))->assertOk();
        $this->assertSame(1, ExportPreset::where('user_id', $this->owner->id)->where('is_default', true)->count());
        $this->postJson(route('export-presets.store'), $this->input('Third'))->assertUnprocessable();
        $this->putJson(route('export-presets.update', $second), $this->input('First'))->assertUnprocessable();
        $this->actingAs($this->other)->postJson(route('export-presets.store'), $this->input('First'))->assertCreated();
    }

    public function test_supplier_filters_stay_hashes_and_relative_filters_are_resolved_on_dispatch(): void
    {
        Queue::fake();
        $supplier = User::factory()->create(['role' => 'supplier']);
        $preset = $this->create('Relative', ['filters' => ['supplier_id' => $supplier->hash, 'date_range' => ['mode' => 'relative', 'days' => 30]]]);
        $this->assertSame($supplier->hash, $preset->filters['supplier_id']);
        $this->assertSame('relative', $preset->filters['date_range']['mode']);
        $this->assertSame(30, $preset->filters['date_range']['days']);
        $this->assertArrayNotHasKey('start_date', $preset->filters);
        $this->postJson(route('purchasing.export.purchase-orders'), [...$preset->filters, 'options' => ['columns' => $preset->columns, 'format' => $preset->format]])->assertAccepted();
        $job = ExportJob::sole();
        $this->assertSame($supplier->id, $job->export_args[0]);
        $this->assertSame(BusinessTime::today()->toDateString(), $job->export_args[2]);
        $this->postJson(route('export-presets.store'), $this->input('Raw', ['filters' => ['supplier_id' => (string) $supplier->id]]))->assertNotFound();
        $this->postJson(route('export-presets.store'), $this->input('Invalid', ['filters' => ['supplier_id' => 'invalid-hash']]))->assertNotFound();
        $this->postJson(route('export-presets.store'), $this->input('Unknown', ['filters' => ['sql' => 'arbitrary']]))->assertUnprocessable();
    }

    public function test_stale_columns_are_adjusted_with_a_warning_and_required_key_restored(): void
    {
        $preset = $this->create();
        $preset->update(['columns' => ['retired_column', 'status']]);
        $response = $this->getJson(route('export-presets.index', ['export_key' => 'purchasing.po']))->assertOk();
        $response->assertJsonPath('data.0.columns', ['po_number', 'status'])->assertJsonCount(1, 'data.0.warnings');
        $preset->update(['columns' => ['retired_column']]);
        $this->getJson(route('export-presets.index', ['export_key' => 'purchasing.po']))->assertOk()->assertJsonCount(10, 'data.0.columns');
    }

    public function test_supplier_cannot_save_internal_unknown_columns_or_change_a_preset_key(): void
    {
        $supplier = User::factory()->create(['role' => 'supplier']);
        $this->actingAs($supplier)->postJson(route('export-presets.store'), $this->input('Wrong audience'))->assertNotFound();
        $this->postJson(route('export-presets.store'), $this->input('Internal', ['export_key' => 'supplier.po', 'columns' => ['po_number', 'internal_note']]))->assertUnprocessable();
        $preset = $this->create();
        $this->putJson(route('export-presets.update', $preset), $this->input('New key', ['export_key' => 'supplier.po']))->assertUnprocessable();
    }
}
