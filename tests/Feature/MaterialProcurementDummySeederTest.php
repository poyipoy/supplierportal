<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\FileInspection;
use App\Models\MaterialClaim;
use App\Models\PoItemProgressUpdate;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QcInspection;
use App\Models\Shipment;
use App\Models\User;
use App\Services\FileSecurity\FileAccessGuard;
use Database\Seeders\MaterialProcurementDummySeeder;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MaterialProcurementDummySeederTest extends TestCase
{
    public function test_seeder_creates_all_six_curated_packages_with_valid_invariants(): void
    {
        $this->seed(MaterialProcurementDummySeeder::class);

        // Package 1: Bidding & Multi-Supplier Quotations
        $pr1 = PurchaseRequisition::where('pr_number', 'REQ/MAT-DUMMY/2026/001')->first();
        $this->assertNotNull($pr1);
        $this->assertSame('bidding', $pr1->status);
        $this->assertCount(2, $pr1->items);
        $this->assertCount(3, $pr1->quotations);
        $this->assertCount(3, $pr1->invitedSuppliers);

        // Package 2: Split Award
        $pr2 = PurchaseRequisition::where('pr_number', 'REQ/MAT-DUMMY/2026/002')->first();
        $this->assertNotNull($pr2);
        $this->assertCount(2, $pr2->awards);
        $supplierIds = $pr2->awards->pluck('supplier_id')->unique()->all();
        $this->assertCount(2, $supplierIds, 'Package 2 must award items to 2 distinct suppliers');

        // Package 3: PO Active & In-Production Progress
        $po1 = PurchaseOrder::where('po_number', 'PO/MAT-DUMMY/2026/001')->first();
        $this->assertNotNull($po1);
        $this->assertSame('active', $po1->status);
        $award1 = $po1->awards->first();
        $this->assertNotNull($award1);
        $updates = $award1->progressUpdates()->orderBy('id')->get();
        $this->assertCount(3, $updates);
        $this->assertSame(PoItemProgressUpdate::STATUS_ON_PRODUCTION, $updates->last()->status);

        // Package 4: PO Active with Partial Shipments
        $po2 = PurchaseOrder::where('po_number', 'PO/MAT-DUMMY/2026/002')->first();
        $this->assertNotNull($po2);
        $this->assertSame('active', $po2->status);

        $shp1 = Shipment::where('shipment_number', 'SHP/MAT-DUMMY/2026/001')->first();
        $this->assertNotNull($shp1);
        $this->assertSame(Shipment::STATUS_ARRIVED, $shp1->status);
        $this->assertCount(4, $shp1->documents);
        $this->assertNotNull($shp1->qcInspections->first());
        $this->assertSame('ok', $shp1->qcInspections->first()->status);

        $shp2 = Shipment::where('shipment_number', 'SHP/MAT-DUMMY/2026/002')->first();
        $this->assertNotNull($shp2);
        $this->assertSame(Shipment::STATUS_SUBMITTED, $shp2->status);

        // Package 5: PO Completed (Happy Path)
        $po3 = PurchaseOrder::where('po_number', 'PO/MAT-DUMMY/2026/003')->first();
        $this->assertNotNull($po3);
        $this->assertSame('completed', $po3->status);
        $shp3 = Shipment::where('shipment_number', 'SHP/MAT-DUMMY/2026/003')->first();
        $this->assertNotNull($shp3);
        $this->assertSame(Shipment::STATUS_ARRIVED, $shp3->status);
        $this->assertNotNull($po3->qcInspections->first());
        $this->assertSame('ok', $po3->qcInspections->first()->status);

        // Package 6: PO Claim Needed, QC NG, Material Claim Responded
        $po4 = PurchaseOrder::where('po_number', 'PO/MAT-DUMMY/2026/004')->first();
        $this->assertNotNull($po4);
        $this->assertSame('claim_needed', $po4->status);

        $shp4 = Shipment::where('shipment_number', 'SHP/MAT-DUMMY/2026/004')->first();
        $this->assertNotNull($shp4);
        $qcNg = QcInspection::where('po_id', $po4->id)->first();
        $this->assertNotNull($qcNg);
        $this->assertSame('ng', $qcNg->status);
        $this->assertNotEmpty($qcNg->attachments);

        $claim = MaterialClaim::where('po_id', $po4->id)->first();
        $this->assertNotNull($claim);
        $this->assertSame('responded', $claim->status);
        $this->assertNotEmpty($claim->supplier_response);
    }

    public function test_attachments_pass_file_access_guard_verification(): void
    {
        $this->seed(MaterialProcurementDummySeeder::class);

        $guard = app(FileAccessGuard::class);
        $attachments = Attachment::where('file_path', 'like', 'attachments/dummy_material_procurement/%')->get();

        $this->assertNotEmpty($attachments, 'Dummy attachments must exist');

        foreach ($attachments as $attachment) {
            $this->assertTrue(
                Storage::disk('private')->exists($attachment->file_path),
                "Physical file missing for: {$attachment->file_path}"
            );

            $classification = $guard->classification($attachment);
            $this->assertSame('VALIDATED', $classification, "Attachment {$attachment->id} classification must be VALIDATED");

            // assertReadable does not throw 404
            $guard->assertReadable($attachment);
        }
    }
}
