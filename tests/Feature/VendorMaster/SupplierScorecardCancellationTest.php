<?php

namespace Tests\Feature\VendorMaster;

use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\User;
use App\Services\VendorMaster\SupplierScorecardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Procurement\ProcurementFixtures;
use Tests\TestCase;

class SupplierScorecardCancellationTest extends TestCase
{
    use ProcurementFixtures;
    use RefreshDatabase;

    public function test_removed_invitations_and_cancelled_prs_do_not_penalize_supplier_responsiveness(): void
    {
        $this->setUpProcurementActors();
        $second = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        Supplier::create([
            'user_id' => $this->supplier->id,
            'company_name' => 'PT Scorecard Import Supplier',
            'country' => 'Japan',
            'city' => 'Osaka',
        ]);

        // 1. Active PR where supplier submitted a quotation -> 1/1 (100%)
        $activePr = $this->createRequisition([[]], PurchaseRequisition::STATUS_BIDDING);
        $activePr->invitedSuppliers()->attach([
            $this->supplier->id => ['invited_at' => now()],
            $second->id => ['invited_at' => now()],
        ]);
        $this->createQuotation($activePr, [], Quotation::STATUS_SUBMITTED, $this->supplier);

        // 2. PR where supplier was removed (soft-cancelled) -> must NOT count in denominator
        $removedPr = $this->createRequisition([[]], PurchaseRequisition::STATUS_SUBMITTED);
        $removedPr->invitedSuppliers()->attach([
            $this->supplier->id => ['invited_at' => now()],
            $second->id => ['invited_at' => now()],
        ]);
        $this->actingAs($this->purchasing)
            ->delete(route('purchasing.requisitions.suppliers.destroy', [$removedPr, $this->supplier]))
            ->assertSessionHas('success');

        // 3. Whole-PR cancelled while supplier was invited -> must NOT count in denominator
        $cancelledPr = $this->createRequisition([[]], PurchaseRequisition::STATUS_SUBMITTED);
        $cancelledPr->invitedSuppliers()->attach([$this->supplier->id => ['invited_at' => now()]]);
        $this->actingAs($this->purchasing)
            ->postJson(route('purchasing.requisitions.cancel', $cancelledPr), [
                'cancellation_reason' => 'No longer needed by production',
            ])
            ->assertOk();

        $scorecard = app(SupplierScorecardService::class)->buildImportScorecard($this->supplier);
        $responsiveness = $scorecard->metrics['responsiveness'];

        $this->assertSame(1, $responsiveness['numerator']);
        $this->assertSame(1, $responsiveness['denominator']);
        $this->assertSame(100.0, (float) $responsiveness['percentage']);
    }
}
