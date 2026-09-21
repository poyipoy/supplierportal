<?php

namespace Tests\Unit;

use App\Models\LocalInvoice;
use App\Support\StatusHelper;
use PHPUnit\Framework\TestCase;

class StatusHelperLocalInvoiceToneTest extends TestCase
{
    public function test_it_maps_local_invoice_statuses_to_canonical_tones(): void
    {
        $this->assertSame('warning', StatusHelper::localInvoiceTone(LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT));
        $this->assertSame('info', StatusHelper::localInvoiceTone('UNDER_REVIEW'));
        $this->assertSame('info', StatusHelper::localInvoiceTone(LocalInvoice::STATUS_UNDER_VERIFICATION));
        $this->assertSame('error', StatusHelper::localInvoiceTone(LocalInvoice::STATUS_NEED_REVISION));
        $this->assertSame('error', StatusHelper::localInvoiceTone(LocalInvoice::STATUS_REJECTED));
        $this->assertSame('error', StatusHelper::localInvoiceTone(LocalInvoice::STATUS_CANCELLED));
        $this->assertSame('error', StatusHelper::localInvoiceTone(LocalInvoice::STATUS_EXPIRED));
        $this->assertSame('success', StatusHelper::localInvoiceTone('APPROVED'));
        $this->assertSame('success', StatusHelper::localInvoiceTone(LocalInvoice::STATUS_READY_TO_PAY));
        $this->assertSame('success', StatusHelper::localInvoiceTone(LocalInvoice::STATUS_PAID));
        $this->assertSame('success', StatusHelper::localInvoiceTone('COMPLETED'));
        $this->assertSame('neutral', StatusHelper::localInvoiceTone('UNKNOWN_STATUS'));
    }
}
