<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\Attachment;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalPurchaseOrder;
use App\Models\SupplierScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;
use ZipArchive;

class LocalGrDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $supplier;

    private LocalPurchaseOrder $po;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->supplier = $this->localSupplier();
        $this->po = $this->po($this->supplier, 'GRDOC-PO-1');
    }

    public function test_single_pdf_named_after_gr_number_is_linked_with_inspection_and_audit(): void
    {
        $gr = $this->gr($this->po, 'GR-DOC-001');

        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.upload-gr'), [
            'purchase_order' => $this->po->hash,
            'file' => $this->pdf('gr-doc-001.pdf'),
        ])->assertOk()->assertJsonPath('status', 'COMPLETED');

        $attachment = $gr->attachments()->firstOrFail();
        $this->assertSame($gr->id, $gr->latestGrDocument()->attachable_id);
        $this->assertDatabaseHas('file_inspections', ['id' => $attachment->file_inspection_id, 'status' => 'VALIDATED']);
        Storage::disk('private')->assertExists($attachment->file_path);
        $this->assertDatabaseHas('local_finance_audit_logs', [
            'auditable_type' => LocalGoodsReceipt::class, 'auditable_id' => $gr->id,
            'action' => 'gr_document_uploaded', 'actor_id' => $this->finance->id,
        ]);
    }

    public function test_gr_of_another_supplier_is_not_matched(): void
    {
        $other = $this->localSupplier();
        $foreign = $this->gr($this->po($other, 'GRDOC-PO-OTHER'), 'GR-FOREIGN-1');

        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.upload-gr'), [
            'purchase_order' => $this->po->hash,
            'file' => $this->pdf('GR-FOREIGN-1.pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(0, $foreign->attachments()->count());
    }

    public function test_header_only_pdf_is_rejected_without_attachment_or_audit(): void
    {
        $gr = $this->gr($this->po, 'GR-DOC-002');

        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.upload-gr'), [
            'purchase_order' => $this->po->hash,
            'file' => UploadedFile::fake()->createWithContent('GR-DOC-002.pdf', "%PDF-1.4\nspoof"),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(0, $gr->attachments()->count());
        $this->assertDatabaseMissing('local_finance_audit_logs', ['action' => 'gr_document_uploaded']);
        $this->assertSame([], Storage::disk('private')->allFiles('attachments'));
    }

    public function test_cancelled_gr_cannot_receive_a_document(): void
    {
        $gr = $this->gr($this->po, 'GR-DOC-003', 'CANCELLED');

        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.upload-gr'), [
            'purchase_order' => $this->po->hash,
            'file' => $this->pdf('GR-DOC-003.pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.goods-receipts.document', $gr), [
            'file' => $this->pdf('anything.pdf'),
        ])->assertUnprocessable();

        $this->assertSame(0, $gr->attachments()->count());
    }

    public function test_gr_under_cancelled_po_cannot_receive_a_document(): void
    {
        $cancelledPo = $this->po($this->supplier, 'GRDOC-PO-CANCELLED');
        $gr = $this->gr($cancelledPo, 'GR-DOC-PO-CANC');
        $cancelledPo->update(['status' => LocalPurchaseOrder::STATUS_CANCELLED]);

        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.upload-gr'), [
            'purchase_order' => $cancelledPo->hash,
            'file' => $this->pdf('GR-DOC-PO-CANC.pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('purchase_order');

        $this->assertSame(0, $gr->attachments()->count());
    }

    public function test_gr_of_a_sibling_po_of_the_same_supplier_is_not_matched(): void
    {
        $sibling = $this->gr($this->po($this->supplier, 'GRDOC-PO-SIBLING'), 'GR-SIBLING-1');

        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.upload-gr'), [
            'purchase_order' => $this->po->hash,
            'file' => $this->pdf('GR-SIBLING-1.pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(0, $sibling->attachments()->count());
    }

    public function test_zip_mixing_grs_of_two_pos_is_rejected_entirely(): void
    {
        $own = $this->gr($this->po, 'GR-MIX-OWN');
        $sibling = $this->gr($this->po($this->supplier, 'GRDOC-PO-MIX'), 'GR-MIX-SIBLING');

        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.upload-gr'), [
            'purchase_order' => $this->po->hash,
            'file' => $this->zip(['GR-MIX-OWN.pdf' => NativeFileFixtures::pdf('a'), 'GR-MIX-SIBLING.pdf' => NativeFileFixtures::pdf('b')]),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(0, $own->attachments()->count() + $sibling->attachments()->count());
        $this->assertSame([], Storage::disk('private')->allFiles('attachments'));
    }

    public function test_purchase_order_reference_must_be_a_known_canonical_hash(): void
    {
        $this->gr($this->po, 'GR-HASH-001');

        foreach ([(string) $this->po->id, 'not-a-hash', str_repeat('a', 80), ''] as $reference) {
            $this->actingAs($this->finance)->postJson(route('finance.local-procurement.upload-gr'), [
                'purchase_order' => $reference,
                'file' => $this->pdf('GR-HASH-001.pdf'),
            ])->assertUnprocessable()->assertJsonValidationErrors('purchase_order');
        }
        $this->assertSame(0, Attachment::count());
    }

    public function test_supplier_is_derived_from_the_po_and_a_stale_supplier_id_is_ignored(): void
    {
        $gr = $this->gr($this->po, 'GR-DERIVE-001');
        $other = $this->localSupplier();

        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.upload-gr'), [
            'purchase_order' => $this->po->hash,
            'supplier_id' => $other->id,
            'file' => $this->pdf('GR-DERIVE-001.pdf'),
        ])->assertOk();

        $this->assertSame(1, $gr->attachments()->count());
    }

    public function test_successful_upload_redirects_to_the_po_detail(): void
    {
        $this->gr($this->po, 'GR-REDIR-001');

        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.upload-gr'), [
            'purchase_order' => $this->po->hash,
            'file' => $this->pdf('GR-REDIR-001.pdf'),
        ])->assertOk()->assertJsonPath('redirect', route('finance.local-procurement.show', $this->po));
    }

    public function test_po_suggestions_are_searchable_by_number_pr_and_supplier_and_exclude_cancelled_and_ineligible(): void
    {
        $this->supplier->update(['name' => 'PT Suggest Maju']);
        $match = $this->po($this->supplier, 'SUG-PO-100');
        $match->update(['pr_number' => 'PR-SUG-777']);
        $this->po($this->localSupplier(), 'OTHER-PO-200');
        $this->po($this->supplier, 'SUG-PO-CANC')->update(['status' => LocalPurchaseOrder::STATUS_CANCELLED]);

        $inactiveSupplier = $this->localSupplier();
        $inactiveSupplier->update(['is_active' => false]);
        $this->po($inactiveSupplier, 'SUG-PO-INACTIVE');

        $importOnlySupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->po($importOnlySupplier, 'SUG-PO-IMPORT');

        foreach (['SUG-PO', 'PR-SUG-777', 'Suggest Maju'] as $term) {
            $rows = $this->actingAs($this->finance)->getJson(route('finance.local-procurement.po-suggestions', ['q' => $term]))
                ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
            $labels = array_column($rows, 'label');
            $this->assertContains('SUG-PO-100', $labels);
            $this->assertNotContains('SUG-PO-CANC', $labels);
            $this->assertNotContains('SUG-PO-INACTIVE', $labels);
            $this->assertNotContains('SUG-PO-IMPORT', $labels);
        }
        $row = collect($rows)->firstWhere('label', 'SUG-PO-100');
        $this->assertSame($match->hash, $row['value']);
        $this->assertSame('PT Suggest Maju', $row['sublabel']);
        $this->assertSame('OPEN', $row['badge']);
        $this->assertStringContainsString('pr-sug-777', $row['searchKeywords']);
    }

    public function test_po_of_inactive_or_non_local_supplier_is_rejected_and_not_preloaded(): void
    {
        $inactive = $this->localSupplier();
        $inactivePo = $this->po($inactive, 'PO-INACT-1');
        $this->gr($inactivePo, 'GR-INACT-1');
        $inactive->update(['is_active' => false]);

        $importOnly = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $importPo = $this->po($importOnly, 'PO-IMP-1');
        $this->gr($importPo, 'GR-IMP-1');

        foreach ([[$inactivePo, 'GR-INACT-1.pdf'], [$importPo, 'GR-IMP-1.pdf']] as [$blockedPo, $filename]) {
            $this->actingAs($this->finance)->postJson(route('finance.local-procurement.upload-gr'), [
                'purchase_order' => $blockedPo->hash,
                'file' => $this->pdf($filename),
            ])->assertUnprocessable()->assertJsonValidationErrors('purchase_order');
        }

        $preloaded = array_column(
            $this->actingAs($this->finance)->get(route('finance.local-procurement.index'))->assertOk()->viewData('grPoOptions'),
            'label'
        );
        $this->assertNotContains('PO-INACT-1', $preloaded);
        $this->assertNotContains('PO-IMP-1', $preloaded);
    }

    public function test_po_suggestions_escape_wildcards_and_require_two_characters(): void
    {
        $this->po($this->supplier, 'WILD-PO-1');

        $this->actingAs($this->finance)->getJson(route('finance.local-procurement.po-suggestions', ['q' => '%']))
            ->assertOk()->assertJsonPath('data', []);
        $this->actingAs($this->finance)->getJson(route('finance.local-procurement.po-suggestions', ['q' => 'W']))
            ->assertOk()->assertJsonPath('data', []);
        $this->actingAs($this->finance)->getJson(route('finance.local-procurement.po-suggestions', ['q' => str_repeat('x', 101)]))
            ->assertUnprocessable();
    }

    public function test_po_suggestions_are_limited_and_restricted_to_operators(): void
    {
        foreach (range(1, 25) as $i) {
            $this->po($this->supplier, sprintf('LIM-PO-%03d', $i));
        }

        $this->assertCount(20, $this->actingAs($this->finance)->getJson(route('finance.local-procurement.po-suggestions', ['q' => 'LIM-PO']))->json('data'));
        $this->actingAs(User::factory()->create(['role' => 'purchasing', 'is_active' => true]))
            ->getJson(route('purchasing.local-procurement.po-suggestions', ['q' => 'LIM-PO']))->assertOk();
        $this->actingAs($this->supplier)->getJson(route('finance.local-procurement.po-suggestions', ['q' => 'LIM-PO']))->assertForbidden();
    }

    public function test_index_preloads_at_most_100_non_cancelled_po_options(): void
    {
        foreach (range(1, 105) as $i) {
            $this->po($this->supplier, sprintf('PRE-PO-%03d', $i));
        }
        $this->po($this->supplier, 'PRE-PO-CANC')->update(['status' => LocalPurchaseOrder::STATUS_CANCELLED]);

        $options = $this->actingAs($this->finance)->get(route('finance.local-procurement.index'))->assertOk()->viewData('grPoOptions');

        $this->assertCount(100, $options);
        $this->assertNotContains('PRE-PO-CANC', array_column($options, 'label'));
        $this->assertSame('PRE-PO-105', $options[0]['label']);
    }

    public function test_accounting_can_view_gr_document_attachment(): void
    {
        $accounting = User::factory()->create(['role' => 'accounting', 'is_active' => true]);
        $gr = $this->gr($this->po, 'GR-ACCT-001');

        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.upload-gr'), [
            'purchase_order' => $this->po->hash,
            'file' => $this->pdf('GR-ACCT-001.pdf'),
        ])->assertOk();

        $attachment = $gr->attachments()->firstOrFail();
        $this->actingAs($accounting)->get(route('attachments.show', $attachment))->assertOk();
    }

    public function test_single_pdf_exceeding_5mb_is_rejected_with_5mb_message(): void
    {
        $gr = $this->gr($this->po, 'GR-TOO-BIG');

        $response = $this->actingAs($this->finance)->postJson(route('finance.local-procurement.upload-gr'), [
            'purchase_order' => $this->po->hash,
            'file' => UploadedFile::fake()->create('GR-TOO-BIG.pdf', 6000, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(__('local_procurement.gr_documents.pdf_size'), $response->json('errors.file.0'));
        $this->assertSame(0, $gr->attachments()->count());
    }

    public function test_mismatched_extension_and_mime_payload_is_rejected_by_form_request(): void
    {
        $gr = $this->gr($this->po, 'GR-MISMATCH');

        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.upload-gr'), [
            'purchase_order' => $this->po->hash,
            'file' => UploadedFile::fake()->createWithContent('GR-MISMATCH.zip', NativeFileFixtures::pdf('fake-zip')),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(0, $gr->attachments()->count());
    }

    public function test_zip_with_only_ignored_entries_is_rejected(): void
    {
        $gr = $this->gr($this->po, 'GR-IGNORED-ONLY');
        $zip = $this->zip(['__MACOSX/._GR-IGNORED-ONLY.pdf' => NativeFileFixtures::pdf('macos-junk')]);

        try {
            $this->actingAs($this->finance)->postJson(route('finance.local-procurement.upload-gr'), [
                'purchase_order' => $this->po->hash,
                'file' => $zip,
            ])->assertUnprocessable()->assertJsonValidationErrors('file');
        } finally {
            @unlink($zip->getPathname());
        }

        $this->assertSame(0, $gr->attachments()->count());
    }

    public function test_zip_links_every_document_in_one_publication(): void
    {
        $first = $this->gr($this->po, 'GR-ZIP-001');
        $second = $this->gr($this->po, 'GR-ZIP-002');
        $zip = $this->zip(['GR-ZIP-001.pdf' => NativeFileFixtures::pdf('one'), 'gr-zip-002.pdf' => NativeFileFixtures::pdf('two')]);

        try {
            $this->actingAs($this->finance)->postJson(route('finance.local-procurement.upload-gr'), [
                'purchase_order' => $this->po->hash, 'file' => $zip,
            ])->assertOk()->assertJsonPath('status', 'COMPLETED');
        } finally {
            @unlink($zip->getPathname());
        }

        $this->assertSame(1, $first->attachments()->count());
        $this->assertSame(1, $second->attachments()->count());
        $this->assertSame(2, Attachment::where('attachable_type', LocalGoodsReceipt::class)->count());
        $this->assertSame(2, DB::table('local_finance_audit_logs')->where('action', 'gr_document_uploaded')->count());
    }

    public function test_zip_with_an_unmatched_entry_publishes_nothing_and_leaves_no_files(): void
    {
        $known = $this->gr($this->po, 'GR-ZIP-010');
        $zip = $this->zip(['GR-ZIP-010.pdf' => NativeFileFixtures::pdf('known'), 'GR-UNKNOWN.pdf' => NativeFileFixtures::pdf('unknown')]);

        try {
            $this->actingAs($this->finance)->postJson(route('finance.local-procurement.upload-gr'), [
                'purchase_order' => $this->po->hash, 'file' => $zip,
            ])->assertUnprocessable()->assertJsonValidationErrors('file');
        } finally {
            @unlink($zip->getPathname());
        }

        $this->assertSame(0, $known->attachments()->count());
        $this->assertSame([], Storage::disk('private')->allFiles('attachments'));
        $this->assertSame([], Storage::disk('private')->allFiles('gr-documents'));
    }

    public function test_zip_with_two_entries_resolving_to_the_same_gr_is_rejected(): void
    {
        $gr = $this->gr($this->po, 'GR-ZIP-020');
        $zip = $this->zip(['GR-ZIP-020.pdf' => NativeFileFixtures::pdf('a'), 'GR-ZIP-020 .pdf' => NativeFileFixtures::pdf('b')]);

        try {
            $this->actingAs($this->finance)->postJson(route('finance.local-procurement.upload-gr'), [
                'purchase_order' => $this->po->hash, 'file' => $zip,
            ])->assertUnprocessable()->assertJsonValidationErrors('file');
        } finally {
            @unlink($zip->getPathname());
        }

        $this->assertSame(0, $gr->attachments()->count());
    }

    public function test_supplier_and_other_roles_cannot_upload_gr_documents(): void
    {
        $gr = $this->gr($this->po, 'GR-DOC-004');
        $qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);

        foreach ([$this->supplier, $qc] as $actor) {
            $this->actingAs($actor)->postJson(route('finance.local-procurement.upload-gr'), [
                'purchase_order' => $this->po->hash, 'file' => $this->pdf('GR-DOC-004.pdf'),
            ])->assertForbidden();
            $this->actingAs($actor)->postJson(route('finance.local-procurement.goods-receipts.document', $gr), [
                'file' => $this->pdf('GR-DOC-004.pdf'),
            ])->assertForbidden();
        }

        $this->assertSame(0, $gr->attachments()->count());
    }

    public function test_purchasing_attaches_a_document_to_a_specific_gr_regardless_of_filename(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $gr = $this->gr($this->po, 'GR-DOC-005');

        $this->actingAs($purchasing)->postJson(route('purchasing.local-procurement.goods-receipts.document', $gr), [
            'file' => $this->pdf('scan-0001.pdf'),
        ])->assertOk()->assertJsonPath('status', 'COMPLETED');

        $this->assertSame('scan-0001.pdf', $gr->attachments()->firstOrFail()->file_name);
        $this->assertDatabaseHas('local_finance_audit_logs', ['auditable_id' => $gr->id, 'action' => 'gr_document_uploaded', 'actor_id' => $purchasing->id]);
    }

    public function test_row_upload_rejects_non_pdf_content(): void
    {
        $gr = $this->gr($this->po, 'GR-DOC-006');

        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.goods-receipts.document', $gr), [
            'file' => UploadedFile::fake()->createWithContent('payload.pdf', '<?php echo 1;'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.goods-receipts.document', $gr), [
            'file' => UploadedFile::fake()->createWithContent('payload.zip', 'PK'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(0, $gr->attachments()->count());
    }

    public function test_add_gr_with_document_creates_both_or_neither(): void
    {
        $payload = ['gr_number' => 'GR-NEW-001', 'gr_date' => '2026-10-01', 'qty' => '2', 'uom' => 'pcs', 'description' => 'Bolt'];

        $this->actingAs($this->finance)->post(route('finance.local-procurement.goods-receipts.store', $this->po), $payload + [
            'document' => UploadedFile::fake()->createWithContent('bad.pdf', "%PDF-1.4\nspoof"),
        ])->assertSessionHasErrors('document');
        $this->assertDatabaseMissing('local_goods_receipts', ['gr_number' => 'GR-NEW-001']);
        $this->assertSame([], Storage::disk('private')->allFiles('attachments'));

        $this->actingAs($this->finance)->post(route('finance.local-procurement.goods-receipts.store', $this->po), $payload + [
            'document' => $this->pdf('GR-NEW-001.pdf'),
        ])->assertSessionHasNoErrors();
        $gr = LocalGoodsReceipt::where('gr_number', 'GR-NEW-001')->firstOrFail();
        $this->assertSame(1, $gr->attachments()->count());
        $this->assertSame('AVAILABLE', $gr->status);
    }

    public function test_add_gr_without_document_keeps_existing_behaviour(): void
    {
        $this->actingAs($this->finance)->post(route('finance.local-procurement.goods-receipts.store', $this->po), [
            'gr_number' => 'GR-NEW-002', 'gr_date' => '2026-10-01', 'qty' => '1', 'uom' => 'pcs',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, LocalGoodsReceipt::where('gr_number', 'GR-NEW-002')->firstOrFail()->attachments()->count());
    }

    public function test_document_access_follows_supplier_ownership_and_gr_state(): void
    {
        $gr = $this->gr($this->po, 'GR-DOC-007');
        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.goods-receipts.document', $gr), [
            'file' => $this->pdf('GR-DOC-007.pdf'),
        ])->assertOk();
        $attachment = $gr->attachments()->firstOrFail();
        $stranger = $this->localSupplier();
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);

        $this->actingAs($this->supplier)->get(route('attachments.show', $attachment))->assertOk();
        $this->actingAs($this->finance)->get(route('attachments.show', $attachment))->assertOk();
        $this->actingAs($purchasing)->get(route('attachments.show', $attachment))->assertOk();
        $this->actingAs($stranger)->get(route('attachments.show', $attachment))->assertForbidden();

        $gr->update(['status' => 'CANCELLED']);
        $this->actingAs($this->supplier)->get(route('attachments.show', $attachment))->assertForbidden();
        $this->actingAs($this->finance)->get(route('attachments.show', $attachment))->assertOk();
    }

    public function test_gr_table_feed_exposes_document_link_to_operator_and_owner_supplier(): void
    {
        $withDocument = $this->gr($this->po, 'GR-FEED-001', 'AVAILABLE', '2026-10-02');
        $this->gr($this->po, 'GR-FEED-002', 'AVAILABLE', '2026-10-01');
        $this->actingAs($this->finance)->postJson(route('finance.local-procurement.goods-receipts.document', $withDocument), [
            'file' => $this->pdf('GR-FEED-001.pdf'),
        ])->assertOk();
        $query = '?draw=1&start=0&length=25&order[0][column]=2&order[0][dir]=desc';

        foreach ([[$this->finance, 'finance.local-procurement'], [$this->supplier, 'local-supplier.purchase-orders']] as [$actor, $prefix]) {
            $rows = $this->actingAs($actor)->getJson(route($prefix.'.goods-receipts.data', $this->po).$query)->assertOk()->json('data');
            $this->assertStringContainsString(route('attachments.show', $withDocument->attachments()->firstOrFail()), $rows[0]['document']);
            $this->assertStringNotContainsString('/attachments/', $rows[1]['document']);
        }
    }

    public function test_row_upload_control_is_offered_to_operators_only(): void
    {
        $gr = $this->gr($this->po, 'GR-FEED-010');
        $url = fn (string $prefix) => route($prefix.'.goods-receipts.data', $this->po).'?draw=1&start=0&length=25';

        $operator = $this->actingAs($this->finance)->getJson($url('finance.local-procurement'))->json('data.0.actions');
        $this->assertStringContainsString('data-gr-document', $operator);
        $this->assertStringContainsString(route('finance.local-procurement.goods-receipts.document', $gr), $operator);

        $this->actingAs($this->supplier);
        $this->assertSame('', $this->getJson($url('local-supplier.purchase-orders'))->json('data.0.actions'));
    }

    private function localSupplier(): User
    {
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);

        return $supplier;
    }

    private function po(User $supplier, string $number): LocalPurchaseOrder
    {
        return LocalPurchaseOrder::create(['supplier_id' => $supplier->id, 'po_number' => $number,
            'po_date' => '2026-10-01', 'total_amount' => '1000000.00', 'currency' => 'IDR', 'status' => 'OPEN', 'source' => 'MANUAL']);
    }

    private function gr(LocalPurchaseOrder $po, string $number, string $status = 'AVAILABLE', string $date = '2026-10-01'): LocalGoodsReceipt
    {
        return LocalGoodsReceipt::create(['local_purchase_order_id' => $po->id, 'gr_number' => $number, 'gr_date' => $date,
            'qty' => '1.000', 'uom' => 'pcs', 'description' => 'Material', 'status' => $status, 'source' => 'MANUAL']);
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, NativeFileFixtures::pdf($name));
    }

    private function zip(array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'gr-doc-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return new UploadedFile($path, 'gr-documents.zip', 'application/zip', null, true);
    }
}
