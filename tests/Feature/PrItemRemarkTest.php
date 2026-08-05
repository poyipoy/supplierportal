<?php

namespace Tests\Feature;

use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrItemRemarkTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private Period $period;

    protected function setUp(): void
    {
        parent::setUp();

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

        $response = $this->actingAs($this->purchasing)->put(route('purchasing.requisitions.update', $pr), [
            'period_id' => $this->period->id,
            'action' => 'submitted',
            'notes' => 'Header note',
            'items' => [[
                'hs_code' => $item->hs_code,
                'material_name' => $item->material_name,
                'quantity' => $item->quantity,
                'shape' => $item->shape,
                'thickness' => $item->thickness,
                'd_inner' => $item->d_inner,
                'd_outer' => $item->d_outer,
                'width' => $item->width,
                'length' => $item->length,
                'weight_needed' => $item->weight_needed,
                'remark' => $item->remark,
            ]],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $pr->refresh();
        $this->assertSame('submitted', $pr->status);
        $this->assertNotNull($pr->pr_number);
        $this->assertSame('Header note', $pr->notes);
        $this->assertSame('Keep this remark', $pr->items()->sole()->remark);
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
            'hs_code' => '7209.16.00',
            'material_name' => $name,
            'quantity' => 2,
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 2.5,
            'd_inner' => null,
            'd_outer' => null,
            'width' => 1000,
            'length' => 2000,
            'weight_needed' => 150.5,
            'remark' => $remark,
        ];
    }
}
