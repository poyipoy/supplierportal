<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\Attachment;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Local PO lists link each row's latest PO PDF. They used to query attachments once or twice per row; the latest
 * attachment is now eager-loaded, so the count stays constant while the linked document stays the newest one.
 */
class LocalPoLatestDocumentListTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_link_the_latest_document_per_row_with_one_attachment_query(): void
    {
        $finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $supplierA = $this->localSupplier('PT Alpha');
        $supplierB = $this->localSupplier('PT Beta');
        $masters = app(LocalProcurementMasterService::class);

        $latest = [];
        $older = [];
        foreach ([[$supplierA, 'PO-A-1'], [$supplierA, 'PO-A-2'], [$supplierA, 'PO-A-3'], [$supplierB, 'PO-B-1']] as $i => [$supplier, $number]) {
            $po = $masters->createPurchaseOrder($finance, ['supplier_id' => $supplier->id, 'po_number' => $number, 'po_date' => '2026-09-1'.$i, 'total_amount' => '1000.00']);
            $older[$number] = $this->attach($po, $finance, 'old');
            $latest[$number] = $this->attach($po, $finance, 'new');
        }
        $this->attach(LocalPurchaseOrder::where('po_number', 'PO-A-1')->first(), $finance, 'newest');
        $latest['PO-A-1'] = Attachment::query()->latest('id')->first();
        $withoutDocument = $masters->createPurchaseOrder($finance, ['supplier_id' => $supplierA->id, 'po_number' => 'PO-A-NONE', 'po_date' => '2026-09-20', 'total_amount' => '1000.00']);

        $html = $this->countingAttachmentQueries(fn () => $this->actingAs($finance)->get(route('finance.local-procurement.index'))->assertOk()->getContent(), 1);
        foreach ($latest as $number => $attachment) {
            $this->assertStringContainsString(route('attachments.show', $attachment), $html, $number);
            $this->assertStringNotContainsString(route('attachments.show', $older[$number]), $html, $number);
        }
        $this->assertStringContainsString($withoutDocument->po_number, $html);

        $html = $this->countingAttachmentQueries(fn () => $this->actingAs($supplierA)->get(route('local-supplier.purchase-orders.index'))->assertOk()->getContent(), 1);
        foreach (['PO-A-1', 'PO-A-2', 'PO-A-3'] as $number) {
            $this->assertStringContainsString(route('attachments.show', $latest[$number]), $html, $number);
        }
        $this->assertStringNotContainsString(route('attachments.show', $latest['PO-B-1']), $html, 'Another supplier\'s document must never be linked.');
        $this->assertStringNotContainsString('PO-B-1', $html);
    }

    public function test_latest_document_is_read_once_per_instance_and_null_without_attachments(): void
    {
        $finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $po = app(LocalProcurementMasterService::class)->createPurchaseOrder($finance, ['supplier_id' => $this->localSupplier('PT Gamma')->id, 'po_number' => 'PO-G-1', 'po_date' => '2026-09-10', 'total_amount' => '10.00']);
        $fresh = LocalPurchaseOrder::findOrFail($po->id);

        $this->countingAttachmentQueries(function () use ($fresh) {
            $this->assertNull($fresh->latestPoDocument());
            $this->assertNull($fresh->latestPoDocument());
        }, 1);

        $this->attach($po, $finance, 'one');
        $second = $this->attach($po, $finance, 'two');
        $this->assertTrue($second->is(LocalPurchaseOrder::findOrFail($po->id)->latestPoDocument()));
    }

    private function localSupplier(string $name): User
    {
        $user = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'account_status' => User::ACCOUNT_STATUS_ACTIVE]);
        SupplierScope::firstOrCreate(['supplier_id' => $user->id, 'scope' => 'local']);
        Supplier::create(['user_id' => $user->id, 'company_name' => $name, 'category' => 'Parts', 'is_pkp' => true, 'payment_term_days' => 30]);

        return $user;
    }

    private function attach(LocalPurchaseOrder $po, User $uploader, string $label): Attachment
    {
        return $po->attachments()->create([
            'file_path' => "local-po-documents/{$po->id}-{$label}.pdf",
            'file_name' => "{$po->po_number}-{$label}.pdf",
            'file_type' => 'application/pdf',
            'uploaded_by' => $uploader->id,
        ]);
    }

    private function countingAttachmentQueries(callable $callback, int $expected): mixed
    {
        $count = 0;
        DB::listen(function ($query) use (&$count) {
            if (preg_match('/\bfrom [`"]?attachments[`"]?/i', $query->sql)) {
                $count++;
            }
        });
        $result = $callback();
        $this->assertSame($expected, $count, 'attachments queries');

        return $result;
    }
}
