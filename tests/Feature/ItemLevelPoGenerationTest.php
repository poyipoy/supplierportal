<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ItemLevelPoGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;
    private User $supplierA;
    private User $supplierB;
    private User $supplierC;
    private PrItemAwardService $awardService;
    private PurchaseOrderGenerationService $poService;
    private ExchangeRate $exchangeRate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplierA = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->supplierB = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->supplierC = User::factory()->create(['role' => 'supplier', 'is_active' => true]);

        $this->awardService = app(PrItemAwardService::class);
        $this->poService = app(PurchaseOrderGenerationService::class);

        $this->exchangeRate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now()->subDay(),
            'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_one_pr_one_winning_supplier_creates_one_po(): void
    {
        $pr = $this->createRequisition(2);
        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);

        $awards = $this->awardService->awardBatch($pr, [
            $pr->items[0]->id => $qA->items[0]->id,
            $pr->items[1]->id => $qA->items[1]->id,
        ], $this->purchasing);

        $pos = $this->poService->generateFromAwards($awards, $this->purchasing);

        $this->assertCount(1, $pos);
        $po = $pos->first();

        $this->assertSame($this->supplierA->id, $po->supplier_id);
        $this->assertSame('active', $po->status);
        $this->assertTrue($po->quotations->contains($qA));
        $this->assertCount(2, $po->awards);

        // PR should be fully awarded and marked completed
        $this->assertSame('completed', $pr->fresh()->status);
        $this->assertSame(Quotation::STATUS_ACCEPTED, $qA->fresh()->status);
    }

    public function test_one_pr_two_winning_suppliers_creates_two_separate_pos(): void
    {
        $pr = $this->createRequisition(2);
        $item1 = $pr->items[0];
        $item2 = $pr->items[1];

        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);
        $qB = $this->createSubmittedQuotation($pr, $this->supplierB, 1.8);

        // Item 1 -> Supplier A; Item 2 -> Supplier B
        $awards = $this->awardService->awardBatch($pr, [
            $item1->id => $qA->items->firstWhere('pr_item_id', $item1->id)->id,
            $item2->id => $qB->items->firstWhere('pr_item_id', $item2->id)->id,
        ], $this->purchasing);

        $pos = $this->poService->generateFromAwards($awards, $this->purchasing);

        $this->assertCount(2, $pos);

        $poA = $pos->firstWhere('supplier_id', $this->supplierA->id);
        $poB = $pos->firstWhere('supplier_id', $this->supplierB->id);

        $this->assertNotNull($poA);
        $this->assertNotNull($poB);
        $this->assertNotSame($poA->id, $poB->id);

        // Invariant: One PO = Exactly One Supplier
        $this->assertSame($this->supplierA->id, $poA->supplier_id);
        $this->assertSame($this->supplierB->id, $poB->supplier_id);

        // Traceability
        $this->assertCount(1, $poA->awards);
        $this->assertSame($item1->id, $poA->awards->first()->pr_item_id);

        $this->assertCount(1, $poB->awards);
        $this->assertSame($item2->id, $poB->awards->first()->pr_item_id);

        // Both participating quotations become accepted
        $this->assertSame(Quotation::STATUS_ACCEPTED, $qA->fresh()->status);
        $this->assertSame(Quotation::STATUS_ACCEPTED, $qB->fresh()->status);

        // PR fully awarded
        $this->assertSame('completed', $pr->fresh()->status);
    }

    public function test_one_pr_three_winning_suppliers_creates_three_pos(): void
    {
        $pr = $this->createRequisition(3);

        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);
        $qB = $this->createSubmittedQuotation($pr, $this->supplierB, 1.8);
        $qC = $this->createSubmittedQuotation($pr, $this->supplierC, 1.9);

        $awards = $this->awardService->awardBatch($pr, [
            $pr->items[0]->id => $qA->items->firstWhere('pr_item_id', $pr->items[0]->id)->id,
            $pr->items[1]->id => $qB->items->firstWhere('pr_item_id', $pr->items[1]->id)->id,
            $pr->items[2]->id => $qC->items->firstWhere('pr_item_id', $pr->items[2]->id)->id,
        ], $this->purchasing);

        $pos = $this->poService->generateFromAwards($awards, $this->purchasing);

        $this->assertCount(3, $pos);
        $this->assertSame([$this->supplierA->id, $this->supplierB->id, $this->supplierC->id], $pos->pluck('supplier_id')->sort()->values()->all());
    }

    public function test_same_supplier_winning_multiple_items_is_grouped_into_one_po(): void
    {
        $pr = $this->createRequisition(3);
        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);
        $qB = $this->createSubmittedQuotation($pr, $this->supplierB, 1.8);

        // Supplier A wins item 0 and item 2; Supplier B wins item 1
        $awards = $this->awardService->awardBatch($pr, [
            $pr->items[0]->id => $qA->items->firstWhere('pr_item_id', $pr->items[0]->id)->id,
            $pr->items[1]->id => $qB->items->firstWhere('pr_item_id', $pr->items[1]->id)->id,
            $pr->items[2]->id => $qA->items->firstWhere('pr_item_id', $pr->items[2]->id)->id,
        ], $this->purchasing);

        $pos = $this->poService->generateFromAwards($awards, $this->purchasing);

        $this->assertCount(2, $pos);
        $poA = $pos->firstWhere('supplier_id', $this->supplierA->id);
        $this->assertCount(2, $poA->awards);
    }

    public function test_partial_award_leaves_pr_in_bidding_and_does_not_reject_competing_quotations(): void
    {
        $pr = $this->createRequisition(3);
        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);
        $qB = $this->createSubmittedQuotation($pr, $this->supplierB, 1.8);

        // Only 2 of 3 items awarded
        $awards = $this->awardService->awardBatch($pr, [
            $pr->items[0]->id => $qA->items->firstWhere('pr_item_id', $pr->items[0]->id)->id,
            $pr->items[1]->id => $qA->items->firstWhere('pr_item_id', $pr->items[1]->id)->id,
        ], $this->purchasing);

        $pos = $this->poService->generateFromAwards($awards, $this->purchasing);

        $this->assertCount(1, $pos);

        // PR must NOT be completed because item 2 is unresolved
        $this->assertSame('bidding', $pr->fresh()->status);

        // Supplier B's quotation must NOT be rejected because item 2 is still open!
        $this->assertSame(Quotation::STATUS_SUBMITTED, $qB->fresh()->status);
    }

    public function test_full_award_rejects_quotations_with_zero_winning_items(): void
    {
        $pr = $this->createRequisition(2);
        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);
        $qB = $this->createSubmittedQuotation($pr, $this->supplierB, 2.5); // Lost all items

        // Supplier A wins both items
        $awards = $this->awardService->awardBatch($pr, [
            $pr->items[0]->id => $qA->items[0]->id,
            $pr->items[1]->id => $qA->items[1]->id,
        ], $this->purchasing);

        $this->poService->generateFromAwards($awards, $this->purchasing);

        // PR completed
        $this->assertSame('completed', $pr->fresh()->status);
        // Supplier A quotation accepted
        $this->assertSame(Quotation::STATUS_ACCEPTED, $qA->fresh()->status);
        // Supplier B quotation rejected
        $this->assertSame(Quotation::STATUS_REJECTED, $qB->fresh()->status);
    }

    public function test_same_supplier_multi_pr_consolidation(): void
    {
        $pr1 = $this->createRequisition(1);
        $pr2 = $this->createRequisition(1);

        $qA1 = $this->createSubmittedQuotation($pr1, $this->supplierA, 2.0);
        $qA2 = $this->createSubmittedQuotation($pr2, $this->supplierA, 2.2);

        $award1 = $this->awardService->awardItem($pr1->items[0], $qA1->items[0], $this->purchasing);
        $award2 = $this->awardService->awardItem($pr2->items[0], $qA2->items[0], $this->purchasing);

        // Consolidate both PR awards into 1 PO
        $pos = $this->poService->generateFromAwards(collect([$award1, $award2]), $this->purchasing);

        $this->assertCount(1, $pos);
        $po = $pos->first();

        $this->assertSame($this->supplierA->id, $po->supplier_id);
        $this->assertCount(2, $po->quotations);
        $this->assertCount(2, $po->awards);

        $this->assertSame('completed', $pr1->fresh()->status);
        $this->assertSame('completed', $pr2->fresh()->status);
    }

    private function createRequisition(int $itemCount = 2): PurchaseRequisition
    {
        $period = Period::create([
            'name' => 'PO Gen Period '.rand(100, 9999),
            'month' => 9,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);

        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/09/2026/'.str_pad((string) rand(1, 999), 3, '0', STR_PAD_LEFT),
            'status' => 'bidding',
            'notes' => 'PO Gen requisition',
        ]);

        for ($i = 1; $i <= $itemCount; $i++) {
            PrItem::create([
                'pr_id' => $pr->id,
                'hs_code' => '7209.16.00',
                'material_name' => "Material {$i}",
                'quantity' => 2,
                'shape' => PrItem::SHAPE_FLAT,
                'thickness' => 1.5,
                'width' => 100,
                'length' => 200,
                'weight_needed' => 10,
            ]);
        }

        return $pr->fresh(['items']);
    }

    private function createSubmittedQuotation(PurchaseRequisition $pr, User $supplier, float $price): Quotation
    {
        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->exchangeRate->id,
            'status' => Quotation::STATUS_SUBMITTED,
            'estimated_delivery' => now()->addDays(14),
            'payment_terms' => 'TT 30 Days',
            'validity_period' => now()->addDays(30),
            'submitted_at' => now(),
        ]);

        foreach ($pr->items as $item) {
            $quotation->items()->create([
                'pr_item_id' => $item->id,
                'is_available' => true,
                'price_per_kg' => $price,
                'amount' => $price * $item->total_weight,
            ]);
        }

        return $quotation->fresh(['items']);
    }
}
