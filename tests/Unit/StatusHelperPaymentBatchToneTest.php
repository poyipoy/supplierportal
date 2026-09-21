<?php

namespace Tests\Unit;

use App\Models\PaymentBatch;
use App\Support\StatusHelper;
use PHPUnit\Framework\TestCase;

class StatusHelperPaymentBatchToneTest extends TestCase
{
    public function test_it_maps_payment_batch_statuses_to_canonical_tones(): void
    {
        $this->assertSame('info', StatusHelper::paymentBatchTone(PaymentBatch::STATUS_FINALIZED));
        $this->assertSame('success', StatusHelper::paymentBatchTone(PaymentBatch::STATUS_PAID));
        $this->assertSame('warning', StatusHelper::paymentBatchTone(PaymentBatch::STATUS_PARTIALLY_PAID));
        $this->assertSame('neutral', StatusHelper::paymentBatchTone(PaymentBatch::STATUS_DRAFT));
        $this->assertSame('error', StatusHelper::paymentBatchTone(PaymentBatch::STATUS_CANCELLED));
        $this->assertSame('neutral', StatusHelper::paymentBatchTone('UNKNOWN_STATUS'));
    }
}
