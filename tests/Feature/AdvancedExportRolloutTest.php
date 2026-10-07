<?php

namespace Tests\Feature;

use App\Exports\QuotationsExport;
use App\Exports\RequisitionsExport;
use App\Exports\ShipmentsExport;
use App\Models\ExportJob;
use App\Models\User;
use App\Support\Export\ExportOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdvancedExportRolloutTest extends TestCase
{
    use RefreshDatabase;

    public static function endpoints(): array
    {
        return [
            ['purchasing', 'purchasing.quotations', 'purchasing.export.quotations', 'pr_number'],
            ['supplier', 'supplier.quotations', 'supplier.export.quotations', 'pr_number'],
            ['purchasing', 'purchasing.pr', 'purchasing.export.requisitions', 'pr_number'],
            ['purchasing', 'purchasing.shipments', 'purchasing.export.shipments', 'shipment_number'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_rollout_dispatch_catalog_preset_and_unauthorized_keys(string $role, string $key, string $route, string $required): void
    {
        Queue::fake();
        $actor = User::factory()->create(['role' => $role]);
        $this->actingAs($actor);
        $this->getJson(route('exports.definitions.show', $key))->assertOk();
        $this->postJson(route($route), ['options' => ['columns' => [$required], 'format' => 'csv']])->assertAccepted();
        $record = ExportJob::sole();
        $this->assertSame($role, $record->export_options['audience']);
        if ($role === 'supplier') {
            $this->assertSame($actor->id, $record->export_args[1]);
        }
        $this->postJson(route($route), ['options' => ['columns' => [$required, 'internal_margin']]])->assertUnprocessable();
        $this->postJson(route('export-presets.store'), ['export_key' => $key, 'name' => 'Rollout', 'columns' => [$required], 'format' => 'csv', 'filters' => []])->assertCreated();
    }

    public function test_rollout_subsets_do_not_load_unselected_relations(): void
    {
        foreach ([new QuotationsExport, new RequisitionsExport, new ShipmentsExport] as $export) {
            $required = $export instanceof ShipmentsExport ? 'shipment_number' : 'pr_number';
            $export->applyOptions(new ExportOptions([$required], 'xlsx', 'purchasing'));
            $loads = array_keys($export->query()->getEagerLoads());
            $this->assertNotContains('quotation.supplier', $loads);
            $this->assertNotContains('purchaseRequisition.items', $loads);
            $this->assertNotContains('items', $loads);
        }
    }

    public function test_rollout_modal_pages_render_for_each_audience(): void
    {
        foreach (['purchasing', 'supplier'] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $this->actingAs($actor);
            $routes = $role === 'purchasing' ? ['purchasing.quotations.index', 'purchasing.requisitions.index', 'purchasing.shipments.index'] : ['supplier.quotations.index'];
            foreach ($routes as $route) {
                $this->get(route($route))->assertOk()->assertSee('data-advanced-export-form',false);
            }
        }
    }
}
