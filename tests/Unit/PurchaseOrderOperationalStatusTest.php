<?php

namespace Tests\Unit;

use App\Models\PurchaseOrder;
use Tests\TestCase;

class PurchaseOrderOperationalStatusTest extends TestCase
{
    public function test_completed_po_is_not_demoted_by_operational_reconciliation(): void
    {
        $po = new PurchaseOrder;
        $po->status = 'completed';

        $result = $po->reconcileOperationalStatus();

        $this->assertSame('completed', $result);
        $this->assertSame('completed', $po->status);
    }

    public function test_cancelled_po_is_not_modified_by_operational_reconciliation(): void
    {
        $po = new PurchaseOrder;
        $po->status = 'cancelled';

        $result = $po->reconcileOperationalStatus();

        $this->assertSame('cancelled', $result);
        $this->assertSame('cancelled', $po->status);
    }
}
