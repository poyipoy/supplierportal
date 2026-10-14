<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\LocalInvoiceGoodsReceipt;
use App\Models\LocalInvoiceVerification;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use App\Services\RegionalDisplayFormatter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

class CancelledInvoiceRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $supplier;

    private User $finance;

    private LocalInvoice $invoice;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Notification::fake();
        $this->supplier = User::factory()->create();
        $this->supplier->supplierScopes()->create(['scope' => 'local']);
        Supplier::create(['user_id' => $this->supplier->id, 'company_name' => 'Recovery Vendor',
            'address' => 'Jakarta', 'phone' => '123', 'npwp' => '123', 'category' => 'Parts',
            'vendor_category' => 'Jasa', 'payment_term_days' => 30, 'is_pkp' => true]);
        $this->finance = User::factory()->create(['role' => 'finance']);
        $masters = app(LocalProcurementMasterService::class);
        $po = $masters->createPurchaseOrder($this->finance, ['supplier_id' => $this->supplier->id,
            'po_number' => 'PO-RECOVERY', 'po_date' => '2026-10-01', 'total_amount' => '1000.00']);
        $gr = $masters->createGoodsReceipt($this->finance, $po, ['gr_number' => 'GR-RECOVERY',
            'gr_date' => '2026-10-02', 'qty' => '1', 'uom' => 'pcs']);
        $this->payload = ['invoice_number' => 'INV-RECOVERY', 'invoice_date' => '2026-10-03',
            'local_purchase_order_id' => $po->id, 'goods_receipt_ids' => [$gr->id],
            'invoice_amount' => '100.00', 'tax_amount' => '11.00', 'ppn_scheme' => '11%',
            'tax_invoice_number' => '010.000-26.12345678',
            'recovery_reason' => '  Salah membatalkan pengajuan di portal.  ', 'business_document_valid' => '1'];
        $service = app(InvoiceSubmissionService::class);
        $this->invoice = $service->submit($this->supplier, $this->payload,
            ['invoice' => NativeFileFixtures::upload('invoice.pdf'), 'tax_invoice' => NativeFileFixtures::upload('tax.pdf')]);
        $service->cancel($this->supplier, $this->invoice);
        $this->invoice->refresh();
        $this->payload['kept_document_ids'] = $this->invoice->latestRevision->documents->modelKeys();
    }

    public function test_owner_can_review_and_resubmit_cancelled_invoice_without_changing_identity(): void
    {
        $submission = $this->invoice->submission_number;
        $receipt = $this->invoice->receipt->receipt_number;
        $paths = Storage::disk('private')->allFiles();
        $this->actingAs($this->supplier)->get(route('local-supplier.invoices.revision', $this->invoice))
            ->assertOk()->assertSee('recovery_reason')->assertSee('business_document_valid');
        $this->assertSame('CANCELLED', $this->invoice->fresh()->status);
        $this->postJson(route('local-supplier.invoices.resubmit', $this->invoice), $this->payload)
            ->assertOk()->assertJsonStructure(['redirect']);
        $this->invoice->refresh();
        $this->assertSame('WAITING_PHYSICAL_DOCUMENT', $this->invoice->status);
        $this->assertSame(2, $this->invoice->revision_number);
        $this->assertSame($submission, $this->invoice->submission_number);
        $this->assertSame($receipt, $this->invoice->receipt->receipt_number);
        $this->assertSame('INV-RECOVERY', $this->invoice->invoice_number);
        $this->assertSame(1, LocalInvoice::count());
        $this->assertSame(2, $this->invoice->revisions()->count());
        $this->assertSame(4, $this->invoice->documents()->count());
        $this->assertEqualsCanonicalizing($paths, Storage::disk('private')->allFiles());
        $history = $this->invoice->statusHistories()->latest('id')->first();
        $this->assertSame('CANCELLED', $history->from_status);
        $this->assertSame('resubmitted', $history->event);
        $this->assertSame(trim($this->payload['recovery_reason']), $history->notes);
        $this->assertSame(1, $this->invoice->statusHistories()->where('event', 'cancelled')->count());
        $this->assertSame(1, LocalInvoiceGoodsReceipt::where('local_invoice_id', $this->invoice->id)->where('state', 'RELEASED')->count());
        $this->assertSame(1, LocalInvoiceGoodsReceipt::where('local_invoice_id', $this->invoice->id)->where('state', 'RESERVED')->count());
        $this->assertNull($this->invoice->cashier_received_at);
        $this->assertNull($this->invoice->due_date);
        $this->postJson(route('local-supplier.invoices.resubmit', $this->invoice), $this->payload)->assertUnprocessable();
        $this->assertSame(2, $this->invoice->revisions()->count());
    }

    public static function invalidRecovery(): array
    {
        return [['recovery_reason', ''], ['recovery_reason', '   '], ['recovery_reason', str_repeat('x', 1001)],
            ['business_document_valid', '0']];
    }

    #[DataProvider('invalidRecovery')]
    public function test_invalid_recovery_input_leaves_cancellation_unchanged(string $field, string $value): void
    {
        $this->actingAs($this->supplier)->postJson(route('local-supplier.invoices.resubmit', $this->invoice),
            array_replace($this->payload, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame('CANCELLED', $this->invoice->fresh()->status);
        $this->assertSame(1, $this->invoice->revisions()->count());
    }

    public function test_conflicting_gr_rolls_back_revision_documents_and_status(): void
    {
        $gr = $this->invoice->localPurchaseOrder->goodsReceipts()->first();
        $gr->update(['status' => 'CANCELLED']);
        $paths = Storage::disk('private')->allFiles();
        $this->actingAs($this->supplier)->postJson(route('local-supplier.invoices.resubmit', $this->invoice), $this->payload)
            ->assertUnprocessable()->assertJsonValidationErrors('goods_receipt_ids');
        $this->assertSame('CANCELLED', $this->invoice->fresh()->status);
        $this->assertSame(1, $this->invoice->revisions()->count());
        $this->assertSame(2, $this->invoice->documents()->count());
        $this->assertEqualsCanonicalizing($paths, Storage::disk('private')->allFiles());
    }

    public function test_other_supplier_and_internal_operator_cannot_recover_invoice(): void
    {
        $other = User::factory()->create();
        $other->supplierScopes()->create(['scope' => 'local']);
        foreach ([$other, $this->finance] as $actor) {
            $this->actingAs($actor)->get(route('local-supplier.invoices.revision', $this->invoice))->assertForbidden();
            $this->postJson(route('local-supplier.invoices.resubmit', $this->invoice), $this->payload)->assertForbidden();
        }
        $this->assertSame('CANCELLED', $this->invoice->fresh()->status);
    }

    public function test_new_submission_with_cancelled_identity_directs_owner_to_resubmit(): void
    {
        $response = $this->actingAs($this->supplier)->postJson(route('local-supplier.invoices.store'),
            array_merge($this->payload, ['invoice' => NativeFileFixtures::upload('INV-RECOVERY.pdf'),
                'tax_invoice' => NativeFileFixtures::upload('0100002612345678.pdf')]));
        $response->assertUnprocessable()->assertJsonValidationErrors(['invoice_number', 'tax_invoice_number']);
        $this->assertStringContainsString(__('local_invoice.recovery.duplicate_invoice'), $response->json('errors.invoice_number.0'));
        $this->assertSame(1, LocalInvoice::count());
    }

    public function test_old_verification_is_preserved_but_not_displayed_as_current_after_recovery(): void
    {
        $old = LocalInvoiceVerification::create(['local_invoice_id' => $this->invoice->id, 'revision_number' => 1,
            'is_section_a_passed' => true, 'is_section_b_passed' => true, 'is_locked' => true, 'verified_by' => $this->finance->id]);
        $this->actingAs($this->supplier)->postJson(route('local-supplier.invoices.resubmit', $this->invoice), $this->payload)->assertOk();
        $this->assertTrue($old->fresh()->is_locked);
        $this->actingAs($this->finance)->get(route('finance.invoices.show', $this->invoice))
            ->assertOk()->assertViewHas('verification', null);
        $this->assertDatabaseMissing('local_invoice_verifications', ['local_invoice_id' => $this->invoice->id, 'revision_number' => 2]);
    }

    public function test_active_drp_allocation_blocks_recovery_even_with_cancelled_invoice_status(): void
    {
        $batch = PaymentBatch::create(['batch_number' => 'DRP-RESTORE-GUARD', 'batch_type' => 'SUPPLIER', 'created_by' => $this->finance->id]);
        $group = PaymentGroup::create(['payment_batch_id' => $batch->id, 'payee_type' => 'supplier', 'payee_id' => $this->supplier->id,
            'payee_name' => 'Recovery Vendor', 'bank_name' => 'BCA', 'account_number' => '123', 'account_holder_name' => 'Recovery Vendor']);
        PaymentItem::create(['payment_group_id' => $group->id, 'payable_type' => LocalInvoice::class,
            'payable_id' => $this->invoice->id, 'amount' => '111.00', 'status' => 'ACTIVE']);
        $this->actingAs($this->supplier)->postJson(route('local-supplier.invoices.resubmit', $this->invoice), $this->payload)
            ->assertUnprocessable()->assertJsonValidationErrors('invoice');
        $this->assertSame('CANCELLED', $this->invoice->fresh()->status);
        $this->assertSame(1, $this->invoice->revisions()->count());
    }

    public function test_pending_supplier_cannot_bypass_eligibility_with_an_existing_local_scope(): void
    {
        $this->supplier->update(['account_status' => User::ACCOUNT_STATUS_PENDING]);
        $this->assertFalse($this->supplier->fresh()->can('resubmit', $this->invoice));
        $this->expectException(AuthorizationException::class);
        app(InvoiceSubmissionService::class)->resubmit($this->supplier->fresh(), $this->invoice, $this->payload, []);
    }

    public function test_recovery_views_and_gr_feed_render_in_both_languages(): void
    {
        $po = $this->invoice->localPurchaseOrder;
        $gr = $po->goodsReceipts()->first();
        $gr->update(['status' => 'CANCELLED']);
        foreach (['en', 'id'] as $locale) {
            $pages = [];
            foreach ([$this->supplier, $this->finance] as $actor) {
                $actor->preference()->updateOrCreate([], [...config('user_preferences.defaults'), 'locale' => $locale, 'theme' => 'light']);
                $actor->unsetRelation('preference');
            }
            $this->app->forgetInstance(RegionalDisplayFormatter::class);
            $pages['invoice'] = $this->actingAs($this->supplier)->get(route('local-supplier.invoices.revision', $this->invoice))
                ->assertOk()->assertSee('invoiceRecoveryReason')->assertSee('invoiceBusinessDocumentValid')->getContent();
            $feed = $this->actingAs($this->finance)->getJson(route('finance.local-procurement.goods-receipts.data', $po))->assertOk();
            $this->assertStringContainsString('data-gr-restore', $feed->json('data.0.actions'));
            $pages['gr'] = $this->get(route('finance.local-procurement.show', $po))->assertOk()->assertSee('restorePoGrModal')->getContent();
            $po->update(['status' => 'CANCELLED']);
            $pages['po'] = $this->get(route('finance.local-procurement.show', $po))->assertOk()->assertSee('data-bs-target="#restorePoModal"', false)->getContent();
            $po->update(['status' => 'OPEN']);
            if (getenv('RECOVERY_UI_FIXTURES') === '1') {
                $root = storage_path('framework/testing/cancelled-recovery-ui');
                File::ensureDirectoryExists($root);
                foreach ($pages as $name => $html) {
                    File::put($root.'/'.$locale.'-'.$name.'.html', str_replace([url('/'), config('app.url')], '', $html));
                }
                File::put($root.'/'.$locale.'-feed.json', $feed->getContent());
            }
        }
    }
}
