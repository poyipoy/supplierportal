<?php

namespace Tests\Feature\LocalInvoice;

use App\Enums\LocalInvoiceStatus;
use App\Events\LocalInvoiceStatusTransitioned;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\LocalInvoice;
use App\Models\User;
use App\Services\LocalInvoice\LocalInvoiceStateEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalInvoiceTransitionMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return iterable<string, array{0: LocalInvoiceStatus, 1: LocalInvoiceStatus, 2: bool}>
     */
    public static function stateMatrixProvider(): iterable
    {
        $validMap = [
            LocalInvoiceStatus::WAITING_PHYSICAL_DOCUMENT->value => [
                LocalInvoiceStatus::UNDER_VERIFICATION,
                LocalInvoiceStatus::NEED_REVISION,
                LocalInvoiceStatus::REJECTED,
                LocalInvoiceStatus::CANCELLED,
                LocalInvoiceStatus::EXPIRED,
            ],
            LocalInvoiceStatus::UNDER_VERIFICATION->value => [
                LocalInvoiceStatus::UNDER_VERIFICATION,
                LocalInvoiceStatus::READY_TO_PAY,
                LocalInvoiceStatus::NEED_REVISION,
                LocalInvoiceStatus::REJECTED,
            ],
            LocalInvoiceStatus::NEED_REVISION->value => [
                LocalInvoiceStatus::WAITING_PHYSICAL_DOCUMENT,
                LocalInvoiceStatus::REJECTED,
                LocalInvoiceStatus::CANCELLED,
                LocalInvoiceStatus::EXPIRED,
            ],
            LocalInvoiceStatus::CANCELLED->value => [
                LocalInvoiceStatus::WAITING_PHYSICAL_DOCUMENT,
            ],
            LocalInvoiceStatus::READY_TO_PAY->value => [
                LocalInvoiceStatus::PAID,
            ],
            LocalInvoiceStatus::PAID->value => [],
            LocalInvoiceStatus::EXPIRED->value => [],
            LocalInvoiceStatus::REJECTED->value => [],
        ];

        foreach (LocalInvoiceStatus::cases() as $from) {
            foreach (LocalInvoiceStatus::cases() as $to) {
                $isAllowed = in_array($to, $validMap[$from->value], true);
                yield "{$from->value} -> {$to->value}" => [$from, $to, $isAllowed];
            }
        }
    }

    #[DataProvider('stateMatrixProvider')]
    public function test_state_transition_matrix_cell(
        LocalInvoiceStatus $from,
        LocalInvoiceStatus $to,
        bool $shouldSucceed
    ): void {
        Event::fake([LocalInvoiceStatusTransitioned::class]);

        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $actor = User::factory()->create(['role' => 'finance', 'is_active' => true]);

        $invoice = LocalInvoice::create([
            'submission_number' => 'SUB-MAT-001',
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-MAT-001',
            'invoice_date' => now()->toDateString(),
            'po_number' => 'PO-MAT-001',
            'dpp_amount' => '500000.00',
            'invoice_amount' => '500000.00',
            'tax_amount' => '55000.00',
            'total_amount' => '555000.00',
            'status' => $from->value,
            'revision_number' => 1,
            'submitted_by' => $supplier->id,
            'submitted_at' => now(),
        ]);

        $engine = app(LocalInvoiceStateEngine::class);

        if ($shouldSucceed) {
            $updated = $engine->transition(
                model: $invoice,
                target: $to,
                actor: $actor,
                reason: "Matrix transition {$from->value} -> {$to->value}",
                metadata: ['send_notification' => false]
            );

            $this->assertSame($to->value, $updated->status);
            $this->assertSame($to, $updated->statusEnum());
            $this->assertDatabaseHas('local_invoice_status_histories', [
                'local_invoice_id' => $invoice->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_id' => $actor->id,
                'notes' => "Matrix transition {$from->value} -> {$to->value}",
            ]);

            Event::assertDispatched(LocalInvoiceStatusTransitioned::class, function (LocalInvoiceStatusTransitioned $e) use ($invoice, $from, $to) {
                return $e->invoice->id === $invoice->id
                    && $e->fromStatus === $from
                    && $e->toStatus === $to;
            });
        } else {
            try {
                $engine->transition(
                    model: $invoice,
                    target: $to,
                    actor: $actor,
                    reason: "Illegal transition {$from->value} -> {$to->value}",
                    metadata: ['send_notification' => false]
                );
                $this->fail("Expected InvalidStateTransitionException for {$from->value} -> {$to->value}");
            } catch (InvalidStateTransitionException $e) {
                $this->assertSame($from->value, $e->fromStatus);
                $this->assertSame($to->value, $e->toStatus);
            }

            $this->assertSame($from->value, $invoice->fresh()->status);
            $this->assertDatabaseCount('local_invoice_status_histories', 0);
            Event::assertNotDispatched(LocalInvoiceStatusTransitioned::class);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: LocalInvoiceStatus}>
     */
    public static function legacySynonymProvider(): iterable
    {
        yield 'SUBMITTED -> WAITING_PHYSICAL_DOCUMENT' => ['SUBMITTED', LocalInvoiceStatus::WAITING_PHYSICAL_DOCUMENT];
        yield 'UNDER_REVIEW -> UNDER_VERIFICATION' => ['UNDER_REVIEW', LocalInvoiceStatus::UNDER_VERIFICATION];
        yield 'APPROVED -> READY_TO_PAY' => ['APPROVED', LocalInvoiceStatus::READY_TO_PAY];
        yield 'PAYMENT_SCHEDULED -> READY_TO_PAY' => ['PAYMENT_SCHEDULED', LocalInvoiceStatus::READY_TO_PAY];
        yield 'COMPLETED -> PAID' => ['COMPLETED', LocalInvoiceStatus::PAID];
    }

    #[DataProvider('legacySynonymProvider')]
    public function test_legacy_synonyms_resolve_deterministically(string $legacyValue, LocalInvoiceStatus $expected): void
    {
        $this->assertSame($expected, LocalInvoiceStatus::tryFromWithLegacy($legacyValue));
        $this->assertSame($expected, LocalInvoiceStatus::tryFromWithLegacyOrNull($legacyValue));
    }
}
