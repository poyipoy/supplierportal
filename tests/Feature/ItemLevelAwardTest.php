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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ItemLevelAwardTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;
    private User $supplierA;
    private User $supplierB;
    private PrItemAwardService $awardService;
    private ExchangeRate $exchangeRate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplierA = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->supplierB = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->awardService = app(PrItemAwardService::class);

        $this->exchangeRate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now()->subDay(),
            'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_can_award_pr_item_to_winning_quotation_item(): void
    {
        $pr = $this->createRequisition(2);
        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.5);

        $prItem = $pr->items->first();
        $qItem = $qA->items->where('pr_item_id', $prItem->id)->first();

        $award = $this->awardService->awardItem($prItem, $qItem, $this->purchasing);

        $this->assertDatabaseHas('pr_item_awards', [
            'id' => $award->id,
            'pr_id' => $pr->id,
            'pr_item_id' => $prItem->id,
            'quotation_id' => $qA->id,
            'quotation_item_id' => $qItem->id,
            'supplier_id' => $this->supplierA->id,
            'awarded_by' => $this->purchasing->id,
        ]);

        $this->assertTrue($prItem->fresh()->award->is($award));
        $this->assertTrue($qItem->fresh()->award->is($award));
        $this->assertNull($award->purchase_order_id);
    }

    public function test_different_pr_items_can_be_awarded_to_different_suppliers(): void
    {
        $pr = $this->createRequisition(2);
        $item1 = $pr->items[0];
        $item2 = $pr->items[1];

        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);
        $qB = $this->createSubmittedQuotation($pr, $this->supplierB, 1.8);

        $qItemA1 = $qA->items->firstWhere('pr_item_id', $item1->id);
        $qItemB2 = $qB->items->firstWhere('pr_item_id', $item2->id);

        $awards = $this->awardService->awardBatch($pr, [
            $item1->id => $qItemA1->id,
            $item2->id => $qItemB2->id,
        ], $this->purchasing);

        $this->assertCount(2, $awards);

        $this->assertDatabaseHas('pr_item_awards', [
            'pr_item_id' => $item1->id,
            'supplier_id' => $this->supplierA->id,
        ]);
        $this->assertDatabaseHas('pr_item_awards', [
            'pr_item_id' => $item2->id,
            'supplier_id' => $this->supplierB->id,
        ]);

        $coverage = $this->awardService->getCoverage($pr);
        $this->assertSame(2, $coverage['total_items']);
        $this->assertSame(2, $coverage['awarded_items']);
        $this->assertSame(0, $coverage['unawarded_items']);
        $this->assertTrue($coverage['is_fully_awarded']);
        $this->assertEquals(100.0, $coverage['coverage_percentage']);
    }

    public function test_unavailable_quotation_item_cannot_be_awarded(): void
    {
        $pr = $this->createRequisition(1);
        $prItem = $pr->items->first();

        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.5);
        $qItem = $qA->items->first();
        $qItem->update(['is_available' => false]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unavailable');

        $this->awardService->awardItem($prItem, $qItem, $this->purchasing);
    }

    public function test_all_unavailable_quotation_cannot_be_awarded(): void
    {
        $pr = $this->createRequisition(1);
        $prItem = $pr->items->first();

        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.5);
        $qA->update(['status' => Quotation::STATUS_ALL_UNAVAILABLE]);
        $qItem = $qA->items->first();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('all unavailable');

        $this->awardService->awardItem($prItem, $qItem, $this->purchasing);
    }

    public function test_cannot_award_item_already_assigned_to_purchase_order(): void
    {
        $pr = $this->createRequisition(1);
        $prItem = $pr->items->first();

        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.5);
        $qB = $this->createSubmittedQuotation($pr, $this->supplierB, 2.0);

        $qItemA = $qA->items->first();
        $qItemB = $qB->items->first();

        $award = $this->awardService->awardItem($prItem, $qItemA, $this->purchasing);

        $po = PurchaseOrder::create([
            'supplier_id' => $this->supplierA->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->exchangeRate->id,
            'po_number' => 'PO/09/2026/001',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
        ]);

        $award->update(['purchase_order_id' => $po->id]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already been assigned to Purchase Order');

        $this->awardService->awardItem($prItem, $qItemB, $this->purchasing);
    }

    public function test_database_unique_constraint_prevents_duplicate_pr_item_awards(): void
    {
        $pr = $this->createRequisition(1);
        $prItem = $pr->items->first();
        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.5);
        $qB = $this->createSubmittedQuotation($pr, $this->supplierB, 2.0);

        PrItemAward::create([
            'pr_id' => $pr->id,
            'pr_item_id' => $prItem->id,
            'quotation_id' => $qA->id,
            'quotation_item_id' => $qA->items->first()->id,
            'supplier_id' => $this->supplierA->id,
            'awarded_by' => $this->purchasing->id,
            'awarded_at' => now(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        PrItemAward::create([
            'pr_id' => $pr->id,
            'pr_item_id' => $prItem->id,
            'quotation_id' => $qB->id,
            'quotation_item_id' => $qB->items->first()->id,
            'supplier_id' => $this->supplierB->id,
            'awarded_by' => $this->purchasing->id,
            'awarded_at' => now(),
        ]);
    }

    public function test_supplier_grouping_for_po_generation(): void
    {
        $pr = $this->createRequisition(3);
        $item1 = $pr->items[0];
        $item2 = $pr->items[1];
        $item3 = $pr->items[2];

        $qA = $this->createSubmittedQuotation($pr, $this->supplierA, 2.0);
        $qB = $this->createSubmittedQuotation($pr, $this->supplierB, 1.8);

        // Supplier A wins item 1 and item 3; Supplier B wins item 2
        $this->awardService->awardBatch($pr, [
            $item1->id => $qA->items->firstWhere('pr_item_id', $item1->id)->id,
            $item2->id => $qB->items->firstWhere('pr_item_id', $item2->id)->id,
            $item3->id => $qA->items->firstWhere('pr_item_id', $item3->id)->id,
        ], $this->purchasing);

        $groups = $this->awardService->getSupplierGrouping($pr);

        $this->assertCount(2, $groups);
        $this->assertTrue($groups->has($this->supplierA->id));
        $this->assertTrue($groups->has($this->supplierB->id));

        $this->assertCount(2, $groups->get($this->supplierA->id));
        $this->assertCount(1, $groups->get($this->supplierB->id));
    }

    public function test_cannot_award_item_from_ineligible_quotation_statuses(): void
    {
        $pr = $this->createRequisition(1);
        $prItem = $pr->items->first();

        $ineligibleStatuses = [
            Quotation::STATUS_DRAFT,
            Quotation::STATUS_REVISION_REQUESTED,
            Quotation::STATUS_REJECTED,
            Quotation::STATUS_ALL_UNAVAILABLE,
        ];

        foreach ($ineligibleStatuses as $status) {
            $quotation = Quotation::create([
                'pr_id' => $pr->id,
                'supplier_id' => $this->supplierA->id,
                'currency' => 'USD',
                'exchange_rate_id' => $this->exchangeRate->id,
                'status' => $status,
                'estimated_delivery' => now()->addDays(14),
                'payment_terms' => 'TT 30 Days',
                'validity_period' => now()->addDays(30),
            ]);

            $qItem = $quotation->items()->create([
                'pr_item_id' => $prItem->id,
                'is_available' => true,
                'price_per_kg' => 2.5,
                'amount' => 2.5 * $prItem->total_weight,
            ]);

            try {
                $this->awardService->awardItem($prItem, $qItem, $this->purchasing);
                $this->fail("Expected awardItem to fail for quotation status '{$status}'");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString("eligible", $e->getMessage());
            }

            try {
                $this->awardService->awardBatch($pr, [$prItem->id => $qItem->id], $this->purchasing);
                $this->fail("Expected awardBatch to fail for quotation status '{$status}'");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString("eligible", $e->getMessage());
            }
        }

        // Verify eligible statuses succeed: submitted and accepted
        foreach ([Quotation::STATUS_SUBMITTED, Quotation::STATUS_ACCEPTED] as $status) {
            $validQuotation = Quotation::create([
                'pr_id' => $pr->id,
                'supplier_id' => $this->supplierA->id,
                'currency' => 'USD',
                'exchange_rate_id' => $this->exchangeRate->id,
                'status' => $status,
                'estimated_delivery' => now()->addDays(14),
                'payment_terms' => 'TT 30 Days',
                'validity_period' => now()->addDays(30),
            ]);

            $validQItem = $validQuotation->items()->create([
                'pr_item_id' => $prItem->id,
                'is_available' => true,
                'price_per_kg' => 2.5,
                'amount' => 2.5 * $prItem->total_weight,
            ]);

            $award = $this->awardService->awardItem($prItem, $validQItem, $this->purchasing);
            $this->assertNotNull($award);
            $this->assertEquals($validQItem->id, $award->quotation_item_id);
        }
    }

    private function createRequisition(int $itemCount = 2): PurchaseRequisition
    {
        $period = Period::create([
            'name' => 'Award Test Period',
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
            'notes' => 'Award test requisition',
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
