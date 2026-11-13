<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalPurchaseOrder;
use App\Services\LocalInvoice\ThreeWayMatchCalculator;
use Database\Seeders\ThreeWayMatchDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThreeWayMatchDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_way_match_demo_seeder_populates_all_scenarios_and_is_idempotent(): void
    {
        // 1. Run seeder
        $this->seed(ThreeWayMatchDemoSeeder::class);

        // 2. Verify POs created
        $exactPo = LocalPurchaseOrder::where('po_number', 'PO-LOC-3WAY-001')->first();
        $tolPo = LocalPurchaseOrder::where('po_number', 'PO-LOC-3WAY-002')->first();
        $varPo = LocalPurchaseOrder::where('po_number', 'PO-LOC-3WAY-003')->first();
        $ceilPo = LocalPurchaseOrder::where('po_number', 'PO-LOC-3WAY-004')->first();
        $tryPo = LocalPurchaseOrder::where('po_number', 'PO-LOC-3WAY-005')->first();

        $this->assertNotNull($exactPo, 'PO-LOC-3WAY-001 must exist');
        $this->assertNotNull($tolPo, 'PO-LOC-3WAY-002 must exist');
        $this->assertNotNull($varPo, 'PO-LOC-3WAY-003 must exist');
        $this->assertNotNull($ceilPo, 'PO-LOC-3WAY-004 must exist');
        $this->assertNotNull($tryPo, 'PO-LOC-3WAY-005 must exist');

        // 3. Verify scenario 1: EXACT_MATCH
        $exactInv = LocalInvoice::where('invoice_number', 'INV-3WAY-EXACT-001')->first();
        $this->assertNotNull($exactInv, 'INV-3WAY-EXACT-001 must exist');
        $this->assertSame(ThreeWayMatchCalculator::STATUS_EXACT_MATCH, $exactInv->three_way_match_status);
        $this->assertSame('30000000.00', (string) $exactInv->expected_gr_dpp_amount);
        $this->assertSame('30000000.00', (string) $exactInv->invoice_amount);
        $this->assertSame('0.00', (string) $exactInv->gr_dpp_variance_amount);
        $this->assertSame(LocalInvoice::STATUS_UNDER_VERIFICATION, $exactInv->status);

        // 4. Verify scenario 2: WITHIN_TOLERANCE
        $tolInv = LocalInvoice::where('invoice_number', 'INV-3WAY-TOLERANCE-002')->first();
        $this->assertNotNull($tolInv, 'INV-3WAY-TOLERANCE-002 must exist');
        $this->assertSame(ThreeWayMatchCalculator::STATUS_WITHIN_TOLERANCE, $tolInv->three_way_match_status);
        $this->assertSame('15000000.00', (string) $tolInv->expected_gr_dpp_amount);
        $this->assertSame('15000050.00', (string) $tolInv->invoice_amount);
        $this->assertSame('50.00', (string) $tolInv->gr_dpp_variance_amount);
        $this->assertSame(LocalInvoice::STATUS_UNDER_VERIFICATION, $tolInv->status);

        // 5. Verify scenario 3: VARIANCE_DETECTED
        $varInv = LocalInvoice::where('invoice_number', 'INV-3WAY-VARIANCE-003')->first();
        $this->assertNotNull($varInv, 'INV-3WAY-VARIANCE-003 must exist');
        $this->assertSame(ThreeWayMatchCalculator::STATUS_VARIANCE_DETECTED, $varInv->three_way_match_status);
        $this->assertSame('20000000.00', (string) $varInv->expected_gr_dpp_amount);
        $this->assertSame('22500000.00', (string) $varInv->invoice_amount);
        $this->assertSame('2500000.00', (string) $varInv->gr_dpp_variance_amount);
        $this->assertTrue($varInv->hasThreeWayVariance());
        $this->assertSame(LocalInvoice::STATUS_UNDER_VERIFICATION, $varInv->status);

        // 6. Verify scenario 4: PO_CEILING_ONLY
        $ceilInv = LocalInvoice::where('invoice_number', 'INV-3WAY-CEILING-004')->first();
        $this->assertNotNull($ceilInv, 'INV-3WAY-CEILING-004 must exist');
        $this->assertSame(ThreeWayMatchCalculator::STATUS_PO_CEILING_ONLY, $ceilInv->three_way_match_status);
        $this->assertNull($ceilInv->expected_gr_dpp_amount);
        $this->assertNull($ceilInv->gr_dpp_variance_amount);

        // 7. Verify scenario 5: Open PO with available GRs for interactive testing
        $this->assertSame(LocalPurchaseOrder::STATUS_OPEN, $tryPo->status);
        $availableGrs = $tryPo->goodsReceipts()->where('status', LocalGoodsReceipt::STATUS_AVAILABLE)->get();
        $this->assertCount(2, $availableGrs);
        $this->assertSame('10000000.00', (string) $availableGrs[0]->receipt_amount);
        $this->assertSame('15000000.00', (string) $availableGrs[1]->receipt_amount);

        // 8. Test idempotency: re-seeding must not throw duplicate exceptions
        $this->seed(ThreeWayMatchDemoSeeder::class);
        $this->assertSame(1, LocalInvoice::where('invoice_number', 'INV-3WAY-EXACT-001')->count());
        $this->assertSame(1, LocalInvoice::where('invoice_number', 'INV-3WAY-VARIANCE-003')->count());
    }
}
