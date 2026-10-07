<?php

namespace Tests\Feature\Timezone;

use App\Exports\InspectionsExport;
use App\Exports\LocalInvoicesExport;
use App\Exports\PurchaseOrderDetailExport;
use App\Exports\QuotationDetailExport;
use App\Exports\QuotationsExport;
use App\Exports\RequisitionsExport;
use App\Models\AuthAuditLog;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\User;
use App\Support\BusinessTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresentationLayerTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_headings_include_business_timezone_label(): void
    {
        $label = BusinessTime::label(); // "WIB"

        $reqHeadings = (new RequisitionsExport)->headings();
        $this->assertContains("Date Created ({$label})", $reqHeadings);

        $quotHeadings = (new QuotationsExport)->headings();
        $this->assertContains("Submitted At ({$label})", $quotHeadings);

        $quotDetailHeadings = (new QuotationDetailExport(1))->headings();
        $this->assertContains("Submitted At ({$label})", $quotDetailHeadings);

        $poDetailHeadings = (new PurchaseOrderDetailExport(1))->headings();
        $this->assertContains("Created At ({$label})", $poDetailHeadings);
        $this->assertContains("QC Inspected At ({$label})", $poDetailHeadings);
        $this->assertContains("Claim Updated At ({$label})", $poDetailHeadings);

        $inspHeadings = (new InspectionsExport)->headings();
        $this->assertContains("Inspection Date ({$label})", $inspHeadings);

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $invHeadings = (new LocalInvoicesExport($admin->id))->headings();
        $this->assertContains("Submitted ({$label})", $invHeadings);
        $this->assertContains("Approved ({$label})", $invHeadings);
        $this->assertContains("Completed ({$label})", $invHeadings);
    }

    public function test_requisitions_export_maps_created_at_in_business_timezone(): void
    {
        $user = User::factory()->create(['role' => 'purchasing']);
        $period = Period::create([
            'name' => 'Period Timezone Export',
            'year' => 2026,
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        $pr = PurchaseRequisition::create([
            'pr_number' => 'REQ/09/2026/099',
            'period_id' => $period->id,
            'created_by' => $user->id,
            'status' => 'submitted',
        ]);
        $pr->forceFill(['created_at' => Carbon::parse('2026-09-28 01:45:00', 'UTC')])->saveQuietly();

        $item = PrItem::create([
            'pr_id' => $pr->id,
            'material_name' => 'Steel Rod',
            'shape' => 'round',
            'quantity' => 10,
            'weight_needed' => 50,
        ]);

        $item->setRelation('purchaseRequisition', $pr);

        $export = new RequisitionsExport($period->id);
        $row = $export->map($item);

        // Column 10 is 'Date Created'
        $this->assertSame('2026-09-28 08:45:00', $row[10]);
    }

    public function test_quotations_export_maps_submitted_at_in_business_timezone(): void
    {
        $supplierUser = User::factory()->create(['role' => 'supplier']);
        $period = Period::create([
            'name' => 'Period Quotation Export',
            'year' => 2026,
            'status' => 'open',
            'created_by' => $supplierUser->id,
        ]);

        $pr = PurchaseRequisition::create([
            'pr_number' => 'REQ/09/2026/100',
            'period_id' => $period->id,
            'created_by' => $supplierUser->id,
            'status' => 'bidding',
        ]);

        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplierUser->id,
            'currency' => 'USD',
            'status' => 'submitted',
            'submitted_at' => Carbon::parse('2026-09-28 02:00:00', 'UTC'), // 09:00 WIB
        ]);

        $prItem = PrItem::create([
            'pr_id' => $pr->id,
            'material_name' => 'Tool Steel',
            'shape' => 'flat',
            'quantity' => 5,
            'weight_needed' => 100,
        ]);

        $quotationItem = $quotation->items()->create([
            'pr_item_id' => $prItem->id,
            'price_per_kg' => 10.5,
            'available_qty' => 5,
        ]);

        $quotationItem->setRelation('quotation', $quotation);
        $quotationItem->setRelation('prItem', $prItem);

        $export = new QuotationsExport;
        $row = $export->map($quotationItem);

        // Column 16 is 'Submitted At'
        $this->assertSame('2026-09-28 09:00:00', $row[16]);
    }

    public function test_qc_inspection_pdf_and_po_pdf_render_business_time_without_hardcoded_wib(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 03:00:00', 'UTC')); // 10:00 WIB

        $supplier = User::factory()->create(['role' => 'supplier', 'name' => 'PT Baja Bersama']);
        $qcUser = User::factory()->create(['role' => 'qc', 'name' => 'Inspector Budi']);

        $po = PurchaseOrder::create([
            'po_number' => 'PO/09/2026/001',
            'supplier_id' => $supplier->id,
            'currency' => 'IDR',
            'status' => 'completed',
            'created_by' => $supplier->id,
        ]);
        $po->forceFill(['created_at' => Carbon::parse('2026-10-20 01:00:00', 'UTC')])->saveQuietly();

        $inspection = QcInspection::create([
            'po_id' => $po->id,
            'inspected_by' => $qcUser->id,
            'status' => 'ok',
            'inspected_at' => Carbon::parse('2026-10-25 04:30:00', 'UTC'), // 11:30 WIB
        ]);

        $inspection->setRelation('purchaseOrder', $po);
        $inspection->setRelation('inspector', $qcUser);

        $qcView = view('pdf.qc-inspection-pdf', ['inspection' => $inspection])->render();
        $this->assertStringContainsString('25 October 2026, 11:30', $qcView);
        $this->assertStringContainsString('28 October 2026, 10:00 WIB', $qcView);
        $this->assertStringNotContainsString('WIB WIB', $qcView);

        $poView = view('pdf.po-pdf', ['po' => $po])->render();
        $this->assertStringContainsString('20 October 2026', $poView);
        $this->assertStringContainsString('28 October 2026, 10:00 WIB', $poView);
        $this->assertStringNotContainsString('WIB WIB', $poView);

        $this->actingAs($qcUser);
        $qcUser->preference()->updateOrCreate([], [...config('user_preferences.defaults'), 'timezone' => 'Asia/Jakarta', 'date_format' => 'iso', 'time_format' => '12h']);
        app()->setLocale('id');
        $qcViewId = view('pdf.qc-inspection-pdf', ['inspection' => $inspection])->render();
        $poViewId = view('pdf.po-pdf', ['po' => $po])->render();
        $this->assertStringContainsString('25 Oktober 2026, 11:30', $qcViewId);
        $this->assertStringContainsString('20 Oktober 2026', $poViewId);
        $this->assertStringNotContainsString('2026-10-20', $poViewId, 'The fixed PDF date pattern must not inherit a user ISO preference.');
        $this->assertStringContainsString('28 Oktober 2026, 10:00 WIB', $poViewId);
        app()->setLocale('en');
    }

    public function test_auth_audit_log_datatables_formats_created_at_in_business_timezone(): void
    {
        $this->travelTo(Carbon::parse('2026-09-28 01:00:00', 'UTC')); // 08:00 WIB

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        AuthAuditLog::create([
            'user_id' => $admin->id,
            'event' => 'login_succeeded',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'created_at' => Carbon::parse('2026-09-28 01:00:00', 'UTC'),
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('admin.auth-audit-logs.data'));

        $response->assertOk();
        $json = $response->json();
        $this->assertNotEmpty($json['data']);
        $this->assertSame('28 Sep 2026 08:00:00 WIB', $json['data'][0]['created_at']);
    }
}
