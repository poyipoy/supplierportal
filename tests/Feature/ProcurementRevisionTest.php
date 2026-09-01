<?php

namespace Tests\Feature;

use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementRevisionTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
        ]);
    }

    public function test_period_creation_is_annual_and_duplicate_years_are_rejected(): void
    {
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.periods.store'), [
                'year' => 2027,
                'status' => 'open',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $period = Period::where('year', 2027)->firstOrFail();
        $this->assertSame('Period 2027', $period->name);
        $this->assertNull($period->month);
        $this->assertSame('2027', $period->display_label);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.periods.store'), [
                'name' => 'Duplicate Procurement 2027',
                'year' => 2027,
                'status' => 'open',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'An annual period for the selected year already exists.');

        $this->assertSame(1, Period::where('year', 2027)->whereNull('month')->count());
    }

    public function test_bidding_status_shows_distinct_submitted_supplier_count_and_excludes_drafts(): void
    {
        $period = Period::create([
            'name' => 'Procurement 2026',
            'month' => null,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/2026/001',
            'status' => 'bidding',
        ]);
        $pr->items()->create([
            'material_name' => 'Revision Steel',
            'quantity' => 2,
            'weight_needed' => 10,
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 2,
            'width' => 100,
            'length' => 1000,
        ]);

        $suppliers = User::factory()->count(3)->create([
            'role' => 'supplier',
            'is_active' => true,
        ]);

        foreach ($suppliers as $index => $supplier) {
            Quotation::create([
                'pr_id' => $pr->id,
                'supplier_id' => $supplier->id,
                'currency' => 'USD',
                'status' => match ($index) {
                    0 => Quotation::STATUS_SUBMITTED,
                    1 => Quotation::STATUS_ACCEPTED,
                    default => Quotation::STATUS_REJECTED,
                },
                'submitted_at' => now()->subDays($index + 1),
            ]);
        }

        // A second submitted quotation for the same supplier must not inflate the count.
        Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $suppliers[0]->id,
            'currency' => 'USD',
            'status' => Quotation::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);
        Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => User::factory()->create(['role' => 'supplier'])->id,
            'currency' => 'USD',
            'status' => Quotation::STATUS_DRAFT,
            'submitted_at' => null,
        ]);

        $response = $this->actingAs($this->purchasing)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('purchasing.requisitions.index', [
                'draw' => 1,
                'start' => 0,
                'length' => 10,
            ]))
            ->assertOk();

        $statusBadge = $response->json('data.0.status_badge');
        $this->assertStringContainsString('>3</span>', $statusBadge);
        $this->assertStringContainsString('3 supplier quotations submitted', $statusBadge);
        $this->assertSame('20 kg', $response->json('data.0.total_kg'));

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.requisitions.show', $pr))
            ->assertOk()
            ->assertSee('3 supplier quotations submitted')
            ->assertSee('2026');
    }

    public function test_non_bidding_status_does_not_show_supplier_response_chip(): void
    {
        $period = Period::create([
            'name' => 'Procurement 2026',
            'month' => null,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/2026/002',
            'status' => 'submitted',
        ]);

        Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => User::factory()->create(['role' => 'supplier'])->id,
            'currency' => 'USD',
            'status' => Quotation::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $statusBadge = $this->actingAs($this->purchasing)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('purchasing.requisitions.index', [
                'draw' => 1,
                'start' => 0,
                'length' => 10,
            ]))
            ->assertOk()
            ->json('data.0.status_badge');

        $this->assertStringContainsString('>Submitted</span>', $statusBadge);
        $this->assertStringNotContainsString('supplier quotations submitted', $statusBadge);
    }
}
