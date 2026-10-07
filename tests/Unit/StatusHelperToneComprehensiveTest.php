<?php

namespace Tests\Unit;

use App\Models\GaClaim;
use App\Models\PaymentBatch;
use App\Models\SupplierRegistrationAttempt;
use App\Support\StatusHelper;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class StatusHelperToneComprehensiveTest extends TestCase
{
    public function test_local_finance_tone_mappings(): void
    {
        // Success
        $this->assertSame('success', StatusHelper::localFinanceTone('APPROVED'));
        $this->assertSame('success', StatusHelper::localFinanceTone('VERIFIED'));
        $this->assertSame('success', StatusHelper::localFinanceTone('PAID'));
        $this->assertSame('success', StatusHelper::localFinanceTone('ACTIVE'));
        $this->assertSame('success', StatusHelper::localFinanceTone('CONSUMED'));
        $this->assertSame('success', StatusHelper::localFinanceTone('FINALIZED'));
        $this->assertSame('success', StatusHelper::localFinanceTone('SETTLED'));

        // Warning
        $this->assertSame('warning', StatusHelper::localFinanceTone('UNPAID'));
        $this->assertSame('warning', StatusHelper::localFinanceTone('PENDING'));
        $this->assertSame('warning', StatusHelper::localFinanceTone('PARTIALLY_PAID'));
        $this->assertSame('warning', StatusHelper::localFinanceTone('RESERVED'));
        $this->assertSame('warning', StatusHelper::localFinanceTone('CORRECTION_REQUIRED'));

        // Info
        $this->assertSame('info', StatusHelper::localFinanceTone('INVOICED'));
        $this->assertSame('info', StatusHelper::localFinanceTone('CLOSED'));
        $this->assertSame('info', StatusHelper::localFinanceTone('DRAFT'));

        // Error
        $this->assertSame('error', StatusHelper::localFinanceTone('CANCELLED'));
        $this->assertSame('error', StatusHelper::localFinanceTone('REJECTED'));
        $this->assertSame('error', StatusHelper::localFinanceTone('REMOVED'));
        $this->assertSame('error', StatusHelper::localFinanceTone('EXPIRED'));

        // Neutral
        $this->assertSame('neutral', StatusHelper::localFinanceTone('RELEASED'));
        $this->assertSame('neutral', StatusHelper::localFinanceTone('INACTIVE'));
        $this->assertSame('neutral', StatusHelper::localFinanceTone('UNKNOWN'));
    }

    public function test_payment_batch_tone_mappings(): void
    {
        $this->assertSame('success', StatusHelper::paymentBatchTone(PaymentBatch::STATUS_PAID));
        $this->assertSame('info', StatusHelper::paymentBatchTone(PaymentBatch::STATUS_FINALIZED));
        $this->assertSame('warning', StatusHelper::paymentBatchTone(PaymentBatch::STATUS_PARTIALLY_PAID));
        $this->assertSame('warning', StatusHelper::paymentBatchTone('UNPAID'));
        $this->assertSame('neutral', StatusHelper::paymentBatchTone(PaymentBatch::STATUS_DRAFT));
        $this->assertSame('error', StatusHelper::paymentBatchTone(PaymentBatch::STATUS_CANCELLED));
        $this->assertSame('neutral', StatusHelper::paymentBatchTone('UNKNOWN'));
    }

    public function test_ga_claim_tone_mappings(): void
    {
        $this->assertSame('success', StatusHelper::gaClaimTone(GaClaim::STATUS_PAID));
        $this->assertSame('success', StatusHelper::gaClaimTone(GaClaim::STATUS_READY_TO_PAY));
        $this->assertSame('info', StatusHelper::gaClaimTone(GaClaim::STATUS_SUBMITTED));
        $this->assertSame('info', StatusHelper::gaClaimTone(GaClaim::STATUS_BASIC_VERIFIED));
        $this->assertSame('warning', StatusHelper::gaClaimTone(GaClaim::STATUS_NEED_REVISION));
        $this->assertSame('error', StatusHelper::gaClaimTone(GaClaim::STATUS_CANCELLED));
        $this->assertSame('error', StatusHelper::gaClaimTone('REJECTED'));
        $this->assertSame('neutral', StatusHelper::gaClaimTone('DRAFT'));
        $this->assertSame('neutral', StatusHelper::gaClaimTone('UNKNOWN'));
    }

    public function test_registration_tone_mappings(): void
    {
        $this->assertSame('success', StatusHelper::registrationTone(SupplierRegistrationAttempt::STATUS_APPROVED));
        $this->assertSame('success', StatusHelper::registrationTone('ACTIVE'));
        $this->assertSame('warning', StatusHelper::registrationTone(SupplierRegistrationAttempt::STATUS_PENDING));
        $this->assertSame('info', StatusHelper::registrationTone(SupplierRegistrationAttempt::STATUS_REVISION));
        $this->assertSame('error', StatusHelper::registrationTone(SupplierRegistrationAttempt::STATUS_REJECTED));
        $this->assertSame('neutral', StatusHelper::registrationTone('UNKNOWN'));
    }

    public function test_local_finance_label_translations(): void
    {
        App::setLocale('id');
        $this->assertSame('Disetujui', StatusHelper::localFinanceLabel('approved'));
        $this->assertSame('Disetujui', StatusHelper::localFinanceLabel('APPROVED'));
        $this->assertSame('Belum Dibayar', StatusHelper::localFinanceLabel('unpaid'));
        $this->assertSame('Dibayar Sebagian', StatusHelper::localFinanceLabel('partially_paid'));
        $this->assertSame('Terverifikasi', StatusHelper::localFinanceLabel('verified'));

        App::setLocale('en');
        $this->assertSame('Approved', StatusHelper::localFinanceLabel('approved'));
        $this->assertSame('Approved', StatusHelper::localFinanceLabel('APPROVED'));
        $this->assertSame('Unpaid', StatusHelper::localFinanceLabel('unpaid'));
        $this->assertSame('Partially Paid', StatusHelper::localFinanceLabel('partially_paid'));
        $this->assertSame('Verified', StatusHelper::localFinanceLabel('verified'));
    }

    public function test_registration_and_ga_labels(): void
    {
        App::setLocale('id');
        $this->assertSame('Verifikasi Awal Selesai', StatusHelper::gaClaimLabel('basic_verified'));
        $this->assertSame('Menunggu', StatusHelper::registrationLabel('pending'));
        $this->assertSame('Perlu Revisi', StatusHelper::registrationLabel('revision'));
        $this->assertSame('Disetujui', StatusHelper::registrationLabel('approved'));
        $this->assertSame('Ditolak', StatusHelper::registrationLabel('rejected'));

        App::setLocale('en');
        $this->assertSame('Pending', StatusHelper::registrationLabel('pending'));
        $this->assertSame('Revision Required', StatusHelper::registrationLabel('revision'));
        $this->assertSame('Approved', StatusHelper::registrationLabel('approved'));
        $this->assertSame('Rejected', StatusHelper::registrationLabel('rejected'));
    }
}
