<?php

namespace Tests\Feature\DocumentChain;

use App\Http\Middleware\EnforceAuthSessionSecurity;
use App\Models\ExchangeRate;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalInvoiceVoucher;
use App\Models\LocalPurchaseOrder;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Models\PurchaseOrder;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\DocumentChain\DocumentChainResolverService;
use App\Services\FileSecurity\FileInspectionService;
use App\Support\BusinessTime;
use App\Support\Security\InlinePreviewResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

class SmartPanelIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $finance;

    private User $importSupplierA;

    private User $importSupplierB;

    private User $localSupplierA;

    private User $localSupplierB;

    private ExchangeRate $rate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true, 'account_status' => 'ACTIVE']);
        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true, 'account_status' => 'ACTIVE']);

        $this->importSupplierA = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'account_status' => 'ACTIVE']);
        SupplierScope::query()->updateOrCreate(['supplier_id' => $this->importSupplierA->id, 'scope' => 'import']);

        $this->importSupplierB = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'account_status' => 'ACTIVE']);
        SupplierScope::query()->updateOrCreate(['supplier_id' => $this->importSupplierB->id, 'scope' => 'import']);

        $this->localSupplierA = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'account_status' => 'ACTIVE']);
        SupplierScope::query()->where('supplier_id', $this->localSupplierA->id)->delete();
        SupplierScope::query()->create(['supplier_id' => $this->localSupplierA->id, 'scope' => 'local']);

        $this->localSupplierB = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'account_status' => 'ACTIVE']);
        SupplierScope::query()->where('supplier_id', $this->localSupplierB->id)->delete();
        SupplierScope::query()->create(['supplier_id' => $this->localSupplierB->id, 'scope' => 'local']);

        $this->rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => BusinessTime::today()->toDateString(),
            'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_import_po_smart_panel_enforces_supplier_and_domain_isolation(): void
    {
        $poA = PurchaseOrder::create([
            'supplier_id' => $this->importSupplierA->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->rate->id,
            'po_number' => 'PO/SP/001',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => BusinessTime::today()->addDays(14)->toDateString(),
        ]);

        // Owner Import Supplier A can access
        $this->actingAs($this->importSupplierA)
            ->getJson(route('context-panel.show', ['entityType' => 'import-po', 'hash' => $poA->hashid]))
            ->assertOk()
            ->assertJsonPath('title', 'PO/SP/001')
            ->assertJsonPath('entity_type', 'import-po');

        // Purchasing can access
        $this->actingAs($this->purchasing)
            ->getJson(route('context-panel.show', ['entityType' => 'import-po', 'hash' => $poA->hashid]))
            ->assertOk();

        // Other Import Supplier B gets 403
        $this->actingAs($this->importSupplierB)
            ->getJson(route('context-panel.show', ['entityType' => 'import-po', 'hash' => $poA->hashid]))
            ->assertForbidden();

        // Local Supplier A gets 403 (domain boundary)
        $this->actingAs($this->localSupplierA)
            ->getJson(route('context-panel.show', ['entityType' => 'import-po', 'hash' => $poA->hashid]))
            ->assertForbidden();

        // Finance gets 403 on Import PO
        $this->actingAs($this->finance)
            ->getJson(route('context-panel.show', ['entityType' => 'import-po', 'hash' => $poA->hashid]))
            ->assertForbidden();
    }

    public function test_local_invoice_and_drp_smart_panel_enforces_supplier_isolation(): void
    {
        $localPo = LocalPurchaseOrder::create([
            'po_number' => 'LPO/SP/001',
            'supplier_id' => $this->localSupplierA->id,
            'total_amount' => '15000000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'po_date' => BusinessTime::today()->toDateString(),
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $this->finance->id,
        ]);

        $gr = LocalGoodsReceipt::create([
            'local_purchase_order_id' => $localPo->id,
            'gr_number' => 'GR-SP-001',
            'gr_date' => BusinessTime::today()->toDateString(),
            'qty' => '10.00',
            'uom' => 'PCS',
            'status' => LocalGoodsReceipt::STATUS_RESERVED,
            'source' => 'MANUAL',
            'created_by' => $this->finance->id,
        ]);

        $invoiceA = LocalInvoice::create([
            'submission_number' => 'SUB/SP/001',
            'supplier_id' => $this->localSupplierA->id,
            'local_purchase_order_id' => $localPo->id,
            'po_number' => $localPo->po_number,
            'submitted_at' => BusinessTime::now(),
            'payment_term_days_snapshot' => 30,
            'invoice_number' => 'INV-SP-001',
            'invoice_date' => BusinessTime::today()->toDateString(),
            'transaction_type' => 'BARANG',
            'invoice_amount' => '5000000.00',
            'tax_amount' => '550000.00',
            'status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
            'revision_number' => 1,
        ]);

        $invoiceA->goodsReceiptHistories()->create([
            'local_purchase_order_id' => $localPo->id,
            'local_goods_receipt_id' => $gr->id,
            'gr_number_snapshot' => $gr->gr_number,
            'gr_qty_snapshot' => $gr->qty,
            'gr_uom_snapshot' => $gr->uom,
            'state' => 'RESERVED',
        ]);

        // Local PO Smart Panel SLA summary shows operational metrics, never PO Acknowledgement
        $localPoPanelRes = $this->actingAs($this->localSupplierA)
            ->getJson(route('context-panel.show', ['entityType' => 'local-po', 'hash' => $localPo->hashid]))
            ->assertOk()
            ->assertJsonPath('entity_type', 'local-po');
        $slaLabels = collect($localPoPanelRes->json('sla_summary'))->pluck('label')->all();
        $this->assertNotContains(__('purchasing.po_ack.title'), $slaLabels);

        // Local Supplier A can access their own invoice chain
        $this->actingAs($this->localSupplierA)
            ->getJson(route('context-panel.show', ['entityType' => 'local-invoice', 'hash' => $invoiceA->hashid]))
            ->assertOk()
            ->assertJsonPath('title', 'INV-SP-001')
            ->assertJsonCount(3, 'nodes'); // LOCAL_PO, GR, INVOICE

        // Local Supplier B gets 403
        $this->actingAs($this->localSupplierB)
            ->getJson(route('context-panel.show', ['entityType' => 'local-invoice', 'hash' => $invoiceA->hashid]))
            ->assertForbidden();

        // Import Supplier A gets 403
        $this->actingAs($this->importSupplierA)
            ->getJson(route('context-panel.show', ['entityType' => 'local-invoice', 'hash' => $invoiceA->hashid]))
            ->assertForbidden();

        // Payment Batch (DRP) is accessible to Finance/Purchasing, forbidden to Supplier
        $batch = PaymentBatch::create([
            'batch_number' => 'DRP/SP/001',
            'batch_type' => 'SUPPLIER',
            'status' => 'DRAFT',
            'created_by' => $this->finance->id,
        ]);

        $this->actingAs($this->finance)
            ->getJson(route('context-panel.show', ['entityType' => 'payment-batch', 'hash' => $batch->hashid]))
            ->assertOk()
            ->assertJsonPath('title', 'DRP/SP/001');

        $this->actingAs($this->localSupplierA)
            ->getJson(route('context-panel.show', ['entityType' => 'payment-batch', 'hash' => $batch->hashid]))
            ->assertForbidden();
    }

    public function test_smart_panel_documents_stream_inline_with_sameorigin_framing_and_preserve_download_default(): void
    {
        Storage::fake('private');

        $poA = PurchaseOrder::create([
            'supplier_id' => $this->importSupplierA->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->rate->id,
            'po_number' => 'PO/SP/PDF/001',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => BusinessTime::today()->addDays(14)->toDateString(),
        ]);

        $storedPoPdf = app(FileInspectionService::class)->storeUpload(
            NativeFileFixtures::upload('signed-po.pdf'),
            'po_pdf',
            'attachments/smart-panel/signed-po.pdf'
        );

        $attachment = $poA->attachments()->create([
            'file_path' => $storedPoPdf['file_path'],
            'file_name' => 'signed-po.pdf',
            'file_type' => $storedPoPdf['file_type'],
            'file_inspection_id' => $storedPoPdf['file_inspection_id'],
            'uploaded_by' => $this->purchasing->id,
        ]);

        $panelResponse = $this->actingAs($this->purchasing)
            ->getJson(route('context-panel.show', ['entityType' => 'import-po', 'hash' => $poA->hashid]))
            ->assertOk();

        $poDocUrl = $panelResponse->json('documents.0.url');
        $poDownloadUrl = $panelResponse->json('documents.0.download_url');
        $attDocUrl = $panelResponse->json('documents.1.url');

        $this->assertStringContainsString('inline=1', (string) $poDocUrl);
        $this->assertStringNotContainsString('inline=1', (string) $poDownloadUrl);

        // Inline PO PDF streams inline and allows same-origin iframe embedding
        $inlinePoRes = $this->actingAs($this->importSupplierA)->get($poDocUrl);
        $inlinePoRes->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
        $this->assertStringStartsWith('inline;', strtolower((string) $inlinePoRes->headers->get('Content-Disposition')));

        // Default PO PDF route without inline=1 stays attachment download
        $downloadPoRes = $this->actingAs($this->importSupplierA)->get(route('shared.pdf.purchase-order', $poA, false));
        $downloadPoRes->assertOk();
        $this->assertStringStartsWith('attachment;', strtolower((string) $downloadPoRes->headers->get('Content-Disposition')));

        // PO attachment allows same-origin iframe embedding
        $attRes = $this->actingAs($this->importSupplierA)->get($attDocUrl);
        $attRes->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
        $this->assertStringStartsWith('inline;', strtolower((string) $attRes->headers->get('Content-Disposition')));

        // Local Invoice documents: preview inline with SAMEORIGIN, default stays attachment download
        $localPo = LocalPurchaseOrder::create([
            'po_number' => 'LPO/SP/PDF/001',
            'supplier_id' => $this->localSupplierA->id,
            'total_amount' => '15000000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'po_date' => BusinessTime::today()->toDateString(),
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $this->finance->id,
        ]);

        $invoiceA = LocalInvoice::create([
            'submission_number' => 'SUB/SP/PDF/001',
            'supplier_id' => $this->localSupplierA->id,
            'local_purchase_order_id' => $localPo->id,
            'po_number' => $localPo->po_number,
            'submitted_at' => BusinessTime::now(),
            'payment_term_days_snapshot' => 30,
            'invoice_number' => 'INV-SP-PDF-001',
            'invoice_date' => BusinessTime::today()->toDateString(),
            'transaction_type' => 'BARANG',
            'invoice_amount' => '5000000.00',
            'tax_amount' => '550000.00',
            'status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
            'revision_number' => 1,
        ]);

        $revision = $invoiceA->revisions()->create([
            'revision_number' => 1,
            'invoice_number' => $invoiceA->invoice_number,
            'invoice_date' => $invoiceA->invoice_date,
            'po_source' => 'INTERNAL',
            'po_number' => $localPo->po_number,
            'internal_po_reference' => $localPo->po_number,
            'invoice_amount' => $invoiceA->invoice_amount,
            'tax_amount' => $invoiceA->tax_amount,
            'ppn_scheme' => 'PPN_11',
            'submitted_ppn_amount' => $invoiceA->tax_amount,
            'has_po_discrepancy' => false,
        ]);

        $storedInvPdf = app(FileInspectionService::class)->storeUpload(
            NativeFileFixtures::upload('invoice-doc.pdf'),
            'invoice',
            'local-invoices/smart-panel/invoice-doc.pdf'
        );

        $invDoc = $revision->documents()->create([
            'local_invoice_id' => $invoiceA->id,
            'document_type' => 'invoice',
            'file_path' => $storedInvPdf['file_path'],
            'original_filename' => $storedInvPdf['file_name'],
            'mime_type' => $storedInvPdf['file_type'],
            'file_size' => $storedInvPdf['file_size'],
            'file_inspection_id' => $storedInvPdf['file_inspection_id'],
            'uploaded_by' => $this->localSupplierA->id,
        ]);

        $invPanelRes = $this->actingAs($this->localSupplierA)
            ->getJson(route('context-panel.show', ['entityType' => 'local-invoice', 'hash' => $invoiceA->hashid]))
            ->assertOk();

        $invPreviewUrl = $invPanelRes->json('documents.0.url');
        $invDownloadUrl = $invPanelRes->json('documents.0.download_url');

        $this->assertStringContainsString('inline=1', (string) $invPreviewUrl);
        $this->assertStringNotContainsString('inline=1', (string) $invDownloadUrl);

        $invInlineRes = $this->actingAs($this->localSupplierA)->get($invPreviewUrl);
        $invInlineRes->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
        $this->assertStringStartsWith('inline;', strtolower((string) $invInlineRes->headers->get('Content-Disposition')));

        // Direct link stays download
        $invDownloadRes = $this->actingAs($this->localSupplierA)->get(route('local-invoice-documents.show', $invDoc, false));
        $invDownloadRes->assertOk();
        $this->assertStringStartsWith('attachment;', strtolower((string) $invDownloadRes->headers->get('Content-Disposition')));

        // Attachment download_url forces attachment disposition
        $attDownloadRes = $this->actingAs($this->importSupplierA)->get($panelResponse->json('documents.1.download_url'));
        $attDownloadRes->assertOk();
        $this->assertStringStartsWith('attachment;', strtolower((string) $attDownloadRes->headers->get('Content-Disposition')));

        // Unsafe MIME types never render inline inside a browser frame
        $this->assertTrue(InlinePreviewResponse::canRenderInline('application/pdf; charset=utf-8'));
        $this->assertTrue(InlinePreviewResponse::canRenderInline('image/png'));
        $this->assertFalse(InlinePreviewResponse::canRenderInline('image/svg+xml'));
        $this->assertFalse(InlinePreviewResponse::canRenderInline('text/html'));
        $this->assertFalse(InlinePreviewResponse::canRenderInline('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));

        // Other supplier still blocked on inline document preview
        $this->actingAs($this->localSupplierB)->get($invPreviewUrl)->assertForbidden();
        $this->actingAs($this->localSupplierB)->get($poDocUrl)->assertForbidden();
    }

    public function test_smart_panel_component_renders_accessible_tabs_and_spacious_preview_frame(): void
    {
        $this->actingAs($this->purchasing);
        $html = Blade::render('<x-ui.smart-panel />');

        // WAI-ARIA tabpanel wiring & live regions
        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringContainsString('id="smart-panel-tab-preview"', $html);
        $this->assertStringContainsString('aria-controls="smart-panel-pane-preview"', $html);
        $this->assertStringContainsString('role="tabpanel"', $html);
        $this->assertStringContainsString('aria-labelledby="smart-panel-tab-preview"', $html);
        $this->assertStringContainsString('id="smart-panel-expand-toggle"', $html);
        $this->assertStringContainsString('aria-pressed="false"', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('role="alert"', $html);

        // Spacious responsive frame layout (no fixed 62vh cap or narrow mobile gutter)
        $this->assertStringNotContainsString('height: 62vh;', $html);
        $this->assertStringContainsString('tw:ps-0 tw:sm:ps-6', $html);
        $this->assertStringContainsString('tw:flex-1', $html);
    }

    public function test_inactive_user_is_denied_access_to_context_panel(): void
    {
        $inactivePurchasing = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => false,
            'account_status' => 'ACTIVE',
        ]);

        $po = PurchaseOrder::create([
            'supplier_id' => $this->importSupplierA->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->rate->id,
            'po_number' => 'PO/SP/INACTIVE/001',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => BusinessTime::today()->addDays(14)->toDateString(),
        ]);

        $this->actingAs($inactivePurchasing)
            ->getJson(route('context-panel.show', ['entityType' => 'import-po', 'hash' => $po->hashid]))
            ->assertUnauthorized();

        $this->withoutMiddleware(EnforceAuthSessionSecurity::class)
            ->actingAs($inactivePurchasing)
            ->getJson(route('context-panel.show', ['entityType' => 'import-po', 'hash' => $po->hashid]))
            ->assertForbidden();

        $this->expectException(AccessDeniedHttpException::class);
        app(DocumentChainResolverService::class)->resolve($inactivePurchasing, 'import-po', $po->hashid);
    }

    public function test_accounting_role_does_not_receive_voucher_nodes_or_purchasing_urls_in_payment_batch(): void
    {
        $accounting = User::factory()->create([
            'role' => 'accounting',
            'is_active' => true,
            'account_status' => 'ACTIVE',
        ]);

        $batch = PaymentBatch::create([
            'batch_number' => 'DRP/ACC/001',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'created_by' => $this->finance->id,
        ]);

        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->localSupplierA->id,
            'payee_name' => 'PT Supplier Local A',
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'PT Supplier Local A',
            'subtotal_amount' => 5000000,
            'bank_fee' => 0,
            'net_payment_amount' => 5000000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);

        $invoice = LocalInvoice::create([
            'submission_number' => 'SUB/ACC/001',
            'supplier_id' => $this->localSupplierA->id,
            'po_number' => 'PO-ACC-001',
            'invoice_number' => 'INV-ACC-001',
            'invoice_date' => BusinessTime::today()->toDateString(),
            'due_date' => BusinessTime::today()->addDays(30)->toDateString(),
            'transaction_type' => 'BARANG',
            'invoice_amount' => '5000000.00',
            'tax_amount' => '0.00',
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'submitted_by' => $this->localSupplierA->id,
            'submitted_at' => BusinessTime::now(),
            'revision_number' => 1,
        ]);

        $item = $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $invoice->id,
            'amount' => 5000000,
            'subtotal_amount' => 5000000,
            'tax_amount' => 0,
            'total_amount' => 5000000,
            'item_reference' => $invoice->invoice_number,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        LocalInvoiceVoucher::create([
            'voucher_number' => 'VCH-ACC-001',
            'voucher_date' => BusinessTime::today()->toDateString(),
            'payment_method' => 'BANK',
            'local_invoice_id' => $invoice->id,
            'payment_batch_id' => $batch->id,
            'payment_group_id' => $group->id,
            'payment_item_id' => $item->id,
            'dpp_snapshot' => 5000000,
            'ppn_snapshot' => 0,
            'pph_snapshot' => 0,
            'net_payable_snapshot' => 5000000,
            'amount' => 5000000,
            'bank_name_snapshot' => 'BCA',
            'bank_account_snapshot' => '1234567890',
            'bank_account_holder_snapshot' => 'PT Supplier Local A',
            'supplier_name_snapshot' => 'PT Supplier Local A',
            'invoice_number_snapshot' => $invoice->invoice_number,
            'po_number_snapshot' => 'PO-ACC-001',
            'gr_references_snapshot' => 'GR-001',
            'terbilang_snapshot' => 'Lima Juta Rupiah',
            'status' => LocalInvoiceVoucher::STATUS_FINAL,
            'finalized_by' => $this->finance->id,
            'finalized_at' => BusinessTime::now(),
        ]);

        $resolver = app(DocumentChainResolverService::class);

        $financeNodes = collect($resolver->resolve($this->finance, 'payment-batch', $batch->hashid)['nodes']);
        $this->assertTrue($financeNodes->contains('type', 'VOUCHER'));

        $accountingNodes = collect($resolver->resolve($accounting, 'payment-batch', $batch->hashid)['nodes']);
        $this->assertFalse($accountingNodes->contains('type', 'VOUCHER'));

        $invoiceNode = $accountingNodes->firstWhere('type', 'INVOICE');
        $this->assertNotNull($invoiceNode);
        $this->assertNull($invoiceNode['url']);
    }

    public function test_document_chain_resolver_rejects_raw_numeric_string_identifier(): void
    {
        $po = PurchaseOrder::create([
            'supplier_id' => $this->importSupplierA->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->rate->id,
            'po_number' => 'PO/SP/RAWNUM/001',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => BusinessTime::today()->addDays(14)->toDateString(),
        ]);

        $this->expectException(NotFoundHttpException::class);
        app(DocumentChainResolverService::class)->resolve($this->purchasing, 'import-po', (string) $po->id);
    }
}
