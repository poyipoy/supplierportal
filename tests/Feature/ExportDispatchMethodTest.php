<?php

namespace Tests\Feature;

use App\Jobs\ProcessExportJob;
use App\Models\ExportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExportDispatchMethodTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_confirms_without_dispatch_and_post_keeps_legacy_filters(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => 'purchasing']);
        $url = route('purchasing.export.requisitions', ['search' => 'steel & tools', 'status' => 'draft']);

        $response = $this->actingAs($user)->get($url)->assertOk()->assertViewIs('exports.confirm');
        $this->assertContains(['name' => 'search', 'value' => 'steel & tools'], $response->viewData('fields'));
        $response->assertSee('method="POST"', false)->assertSee('name="_token"', false);
        $this->assertDatabaseCount('export_jobs', 0);
        Queue::assertNothingPushed();

        $this->postJson($url)->assertAccepted();
        $job = ExportJob::sole();
        $this->assertSame([null, 'draft', 'steel & tools'], $job->export_args);
        Queue::assertPushed(ProcessExportJob::class);
    }

    public function test_confirmation_preserves_nested_options_without_queueing(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => 'purchasing']);
        $response = $this->actingAs($user)->get(route('purchasing.export.purchase-orders', [
            'options' => ['columns' => ['po_number', 'status'], 'format' => 'csv'],
        ]))->assertOk();

        $this->assertContains(['name' => 'options[columns][0]', 'value' => 'po_number'], $response->viewData('fields'));
        $this->assertContains(['name' => 'options[format]', 'value' => 'csv'], $response->viewData('fields'));
        $this->assertDatabaseCount('export_jobs', 0);
        Queue::assertNothingPushed();
    }
}
