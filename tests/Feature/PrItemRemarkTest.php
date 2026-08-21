<?php

namespace Tests\Feature;

use App\Models\MaterialMaster;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\User;
use Database\Seeders\MaterialHsCodeMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrItemRemarkTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private Period $period;

    private MaterialMaster $material;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MaterialHsCodeMasterSeeder::class);
        $this->material = MaterialMaster::query()->where('material_code', 'S45C')->firstOrFail();

        $this->purchasing = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
        ]);

        $this->period = Period::create([
            'name' => 'Mission 1 Period',
            'month' => 8,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_pr_item_remarks_are_sanitized_and_kept_separate_from_header_notes(): void
    {
        $response = $this->actingAs($this->purchasing)->post(route('purchasing.requisitions.store'), [
            'period_id' => $this->period->id,
            'action' => 'draft',
            'supplier_selection_present' => 1,
            'notes' => 'Header note remains separate',
            'items' => [
                $this->materialPayload('Material A', '  Material-specific remark  '),
                $this->materialPayload('Material B', '   '),
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $pr = PurchaseRequisition::query()->where('notes', 'Header note remains separate')->firstOrFail();
        $items = $pr->items()->orderBy('id')->get();

        $this->assertSame('Header note remains separate', $pr->notes);
        $this->assertSame('Material-specific remark', $items[0]->remark);
        $this->assertNull($items[1]->remark);

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.requisitions.show', $pr))
            ->assertOk()
            ->assertSeeText('Remark')
            ->assertSeeText('Material-specific remark');
    }

    public function test_quick_submit_payload_preserves_existing_item_remark(): void
    {
        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'notes' => 'Header note',
            'status' => 'draft',
        ]);
        $item = $pr->items()->create($this->materialPayload('Quick Submit Material', 'Keep this remark'));

        $response = $this->actingAs($this->purchasing)
            ->put(route('purchasing.requisitions.submit', $pr));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $pr->refresh();
        $this->assertSame('submitted', $pr->status);
        $this->assertNotNull($pr->pr_number);
        $this->assertSame('Header note', $pr->notes);
        $this->assertSame('Keep this remark', $pr->items()->sole()->remark);
    }

    public function test_draft_list_action_is_centered_and_submits_only_the_creator_draft(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);
        $otherPurchasing = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
        ]);
        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'notes' => 'Submit from requisition list',
            'status' => 'draft',
        ]);
        $item = $pr->items()->create($this->materialPayload('List Submit Material', 'Keep this remark'));

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.requisitions.index'))
            ->assertOk()
            ->assertSee('<thead class="table-light text-center">', false)
            ->assertSee('<th scope="col">Action</th>', false)
            ->assertSee("className: 'text-center'", false);

        $dataResponse = $this->actingAs($this->purchasing)
            ->get(route('purchasing.requisitions.index'), [
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->assertOk();
        $action = collect($dataResponse->json('data'))
            ->first(fn (array $row) => str_contains(
                $row['action'],
                route('purchasing.requisitions.submit', $pr)
            ))['action'];

        $this->assertStringContainsString('btn-submit-draft', $action);
        $this->assertStringContainsString('Submit', $action);
        $this->assertStringContainsString('ui-data-action--primary', $action);
        $this->assertSame(1, substr_count($action, 'ui-data-action--primary'));
        $this->assertStringContainsString('dropdown-menu', $action);
        $this->assertStringContainsString('View details', $action);
        $this->assertStringContainsString('Edit draft', $action);
        $this->assertStringContainsString('Delete requisition', $action);

        $this->actingAs($otherPurchasing)
            ->put(route('purchasing.requisitions.submit', $pr))
            ->assertRedirect();
        $this->assertSame('draft', $pr->fresh()->status);

        $this->actingAs($this->purchasing)
            ->put(route('purchasing.requisitions.submit', $pr))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $pr->refresh();
        $this->assertSame('submitted', $pr->status);
        $this->assertNotNull($pr->pr_number);
        $this->assertSame('Submit from requisition list', $pr->notes);
        $this->assertSame('Keep this remark', $pr->items()->sole()->remark);
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame($item->id, $pr->items()->sole()->id);

        $this->actingAs($this->purchasing)
            ->put(route('purchasing.requisitions.submit', $pr))
            ->assertRedirect();
        $this->assertSame(1, $admin->notifications()->count());
    }

    public function test_remark_validation_and_individual_item_endpoints(): void
    {
        $tooLong = str_repeat('x', 2001);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.requisitions.store'), [
                'period_id' => $this->period->id,
                'action' => 'draft',
                'items' => [$this->materialPayload('Invalid Bulk Material', $tooLong)],
            ])
            ->assertSessionHasErrors('items.0.remark');

        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'status' => 'draft',
        ]);

        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.pr-items.store'), ['pr_id' => $pr->id] + $this->materialPayload('Invalid Individual Material', $tooLong))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('remark');

        $item = $pr->items()->create($this->materialPayload('Editable Material', 'Initial remark'));

        $this->actingAs($this->purchasing)
            ->putJson(route('purchasing.pr-items.update', $item), $this->materialPayload('Editable Material', '  Updated remark  '))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('Updated remark', $item->fresh()->remark);
    }

    private function materialPayload(string $name, ?string $remark): array
    {
        return [
            'material_master_id' => $this->material->id,
            'hs_code' => '7209.16.00',
            'material_name' => $name,
            'quantity' => 2,
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 5,
            'd_inner' => null,
            'd_outer' => null,
            'width' => 700,
            'length' => 2000,
            'weight_needed' => 150.5,
            'remark' => $remark,
        ];
    }
}
