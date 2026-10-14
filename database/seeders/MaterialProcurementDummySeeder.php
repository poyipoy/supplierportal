<?php

namespace Database\Seeders;

use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\ExchangeRate;
use App\Models\FileInspection;
use App\Models\MaterialClaim;
use App\Models\MaterialMaster;
use App\Models\Message;
use App\Models\Period;
use App\Models\PoDocument;
use App\Models\PoItemProgressUpdate;
use App\Models\PrItem;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QcInspection;
use App\Models\QcItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\ShipmentItem;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MaterialProcurementDummySeeder extends Seeder
{
    public const PR_PREFIX = 'REQ/MAT-DUMMY/';
    public const PO_PREFIX = 'PO/MAT-DUMMY/';
    public const SHP_PREFIX = 'SHP/MAT-DUMMY/';
    public const CLM_PREFIX = 'CLM/MAT-DUMMY/';

    private const STORAGE_DIR = 'attachments/dummy_material_procurement';

    public function run(): void
    {
        $this->command?->info('=== Seeding Dummy Material Procurement Transactions (Core 1) ===');

        DB::transaction(function () {
            // 1. Clean previous dummy data for idempotency
            $this->cleanPreviousDummyData();

            // 2. Resolve or prepare base actors
            $admin = $this->ensureUser('admin@adasi.com', 'Administrator', 'admin');
            $purchasing = $this->ensureUser('purchasing@adasi.com', 'Purchasing ADASI', 'purchasing');
            $qc = $this->ensureUser('qc@adasi.com', 'QC Inspector', 'qc');

            $supplier1 = $this->ensureSupplier('supplier1@test.com', 'PT. Supplier Satu', 'Steel', '01.234.567.8-012.000');
            $supplier2 = $this->ensureSupplier('supplier2@test.com', 'PT. Supplier Dua', 'Special Steel & Tube', '09.876.543.2-098.000');
            $supplier3 = $this->ensureSupplier('supplier3@test.com', 'PT. Nippon Steel Trading', 'Tool & Die Steel', '01.234.567.8-901.000');

            // 3. Resolve exchange rates
            $rateUsd = $this->ensureExchangeRate(ExchangeRate::CURRENCY_USD, 16350.00, $admin);
            $rateCny = $this->ensureExchangeRate(ExchangeRate::CURRENCY_CNY, 2270.00, $admin);
            $rateJpy = $this->ensureExchangeRate(ExchangeRate::CURRENCY_JPY, 110.00, $admin);
            $this->ensureExchangeRate(ExchangeRate::CURRENCY_IDR, 1.00, $admin);

            // 4. Resolve or create monthly periods (Aug, Sep, Oct 2026)
            $periodAug = $this->ensurePeriod(2026, 8, 'Kebutuhan Produksi Agustus 2026', 'closed', $admin);
            $periodSep = $this->ensurePeriod(2026, 9, 'Kebutuhan Produksi September 2026', 'closed', $admin);
            $periodOct = $this->ensurePeriod(2026, 10, 'Kebutuhan Produksi Oktober 2026', 'open', $admin);

            // 5. Seed Package 1: Bidding & Multi-Supplier Quotations
            $this->seedPackage1Bidding($periodOct, $purchasing, [$supplier1, $supplier2, $supplier3], [$rateUsd, $rateCny]);

            // 6. Seed Package 2: Split Item-Level Award
            $this->seedPackage2SplitAward($periodOct, $purchasing, $supplier1, $supplier2, $rateUsd);

            // 7. Seed Package 3: PO In-Production Timeline Progress
            $this->seedPackage3PoInProduction($periodSep, $purchasing, $supplier1, $rateUsd);

            // 8. Seed Package 4: PO with Partial Delivery Shipments
            $this->seedPackage4PartialShipment($periodAug, $purchasing, $qc, $supplier2, $rateCny);

            // 9. Seed Package 5: PO Completed (Happy Path)
            $this->seedPackage5Completed($periodAug, $purchasing, $qc, $supplier1, $rateUsd);

            // 10. Seed Package 6: PO Waiting QC / NG & Material Claim
            $this->seedPackage6QcNgAndClaim($periodSep, $purchasing, $qc, $supplier1, $rateUsd);

            $this->command?->info('✓ 6 Material Procurement Packages successfully seeded.');
        });
    }

    // ─────────────────────────────────────────────────────────────
    // Clean Previous Data (Idempotency)
    // ─────────────────────────────────────────────────────────────

    private function cleanPreviousDummyData(): void
    {
        $this->command?->info('Cleaning previous dummy material procurement records...');

        $dummyPrs = PurchaseRequisition::withTrashed()->where('pr_number', 'like', self::PR_PREFIX.'%')->get();
        $dummyPrIds = $dummyPrs->pluck('id')->all();

        $dummyPos = PurchaseOrder::withTrashed()->where('po_number', 'like', self::PO_PREFIX.'%')->get();
        $dummyPoIds = $dummyPos->pluck('id')->all();

        $dummyShipments = Shipment::withTrashed()->where('shipment_number', 'like', self::SHP_PREFIX.'%')->get();
        $dummyShipmentIds = $dummyShipments->pluck('id')->all();

        // Delete Material Claims
        MaterialClaim::whereIn('po_id', $dummyPoIds)
            ->orWhere('description', 'like', '%['.trim(self::CLM_PREFIX, '/').'%')
            ->delete();

        // Delete QC Inspections & Items
        $inspections = QcInspection::withTrashed()
            ->where(function ($query) use ($dummyPoIds, $dummyShipmentIds) {
                $query->whereIn('po_id', $dummyPoIds)
                    ->orWhereIn('shipment_id', $dummyShipmentIds);
            })
            ->get();
        foreach ($inspections as $ins) {
            $this->deleteAttachmentsFor($ins);
            $ins->items()->delete();
            $ins->forceDelete();
        }

        // Delete Shipments & Items & Documents
        foreach ($dummyShipments as $shp) {
            foreach ($shp->documents as $doc) {
                $this->deleteAttachmentsFor($doc);
                $doc->delete();
            }
            $shp->items()->delete();
            $this->deleteAttachmentsFor($shp);
            $shp->forceDelete();
        }

        // Delete POs, PoDocuments, ProgressUpdates, Awards
        foreach ($dummyPos as $po) {
            $this->deleteAttachmentsFor($po);
            $po->documents()->delete();
            $po->quotations()->detach();

            $awards = PrItemAward::where('purchase_order_id', $po->id)->get();
            foreach ($awards as $award) {
                $award->progressUpdates()->delete();
                $award->delete();
            }

            $po->forceDelete();
        }

        // Delete any remaining awards from the PRs
        if (! empty($dummyPrIds)) {
            $remainingAwards = PrItemAward::whereIn('pr_id', $dummyPrIds)->get();
            foreach ($remainingAwards as $award) {
                $award->progressUpdates()->delete();
                $award->delete();
            }

            // Quotations
            $quotations = Quotation::whereIn('pr_id', $dummyPrIds)->get();
            foreach ($quotations as $quotation) {
                $quotation->purchaseOrders()->detach();
                $quotation->items()->delete();
                $quotation->delete();
            }

            // PR Items & PRs
            foreach ($dummyPrs as $pr) {
                $this->deleteAttachmentsFor($pr);
                $pr->invitedSuppliers()->detach();
                $pr->items()->delete();
                $pr->forceDelete();
            }
        }

        // Delete Conversations related to dummy PRs / POs
        $conversations = Conversation::where(function ($query) use ($dummyPrIds, $dummyPoIds) {
            $query->where(function ($q) use ($dummyPrIds) {
                $q->where('conversable_type', PurchaseRequisition::class)
                    ->whereIn('conversable_id', $dummyPrIds);
            })->orWhere(function ($q) use ($dummyPoIds) {
                $q->where('conversable_type', PurchaseOrder::class)
                    ->whereIn('conversable_id', $dummyPoIds);
            });
        })->get();

        foreach ($conversations as $conv) {
            $conv->messages()->delete();
            $conv->delete();
        }

        // Clean storage directory files
        Storage::disk('private')->deleteDirectory(self::STORAGE_DIR);
    }

    private function deleteAttachmentsFor($model): void
    {
        if (method_exists($model, 'attachments')) {
            foreach ($model->attachments as $att) {
                $filePath = $att->file_path;
                $inspectionId = $att->file_inspection_id;

                $att->delete();

                if ($inspectionId) {
                    FileInspection::where('id', $inspectionId)->delete();
                }

                if ($filePath && Storage::disk('private')->exists($filePath)) {
                    Storage::disk('private')->delete($filePath);
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Package 1: Bidding & Multi-Supplier Quotations
    // ─────────────────────────────────────────────────────────────

    private function seedPackage1Bidding(Period $period, User $purchasing, array $suppliers, array $rates): void
    {
        $prDate = Carbon::create(2026, 10, 2, 9, 30);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => self::PR_PREFIX.'2026/001',
            'notes' => 'Pengadaan material baja perkakas dan konstruksi line produksi mesin stamping ADASI.',
            'status' => 'bidding',
            'created_at' => $prDate,
            'updated_at' => $prDate,
        ]);

        $item1 = $this->createPrItem($pr, [
            'material_name' => 'SKD11',
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 30.0,
            'width' => 300.0,
            'length' => 2000.0,
            'quantity' => 4,
            'weight_needed' => 5650.0,
            'hs_code' => '7228.40.00',
            'remark' => 'Toleransi tebal JIS G4404 standar dies.',
        ], $prDate);

        $item2 = $this->createPrItem($pr, [
            'material_name' => 'S45C',
            'shape' => PrItem::SHAPE_ROUND,
            'd_outer' => 60.0,
            'length' => 6000.0,
            'quantity' => 10,
            'weight_needed' => 1332.0,
            'hs_code' => '7228.30.00',
            'remark' => 'Carbon steel round bar untuk shaft penggerak.',
        ], $prDate);

        // Invite suppliers
        foreach ($suppliers as $supplier) {
            $pr->invitedSuppliers()->syncWithoutDetaching([$supplier->id => ['invited_at' => $prDate]]);
        }

        // Supplier 1 Quotation (USD)
        $rateUsd = $rates[0];
        $qDate1 = $prDate->copy()->addDays(2);
        $q1 = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $suppliers[0]->id,
            'currency' => ExchangeRate::CURRENCY_USD,
            'exchange_rate_id' => $rateUsd->id,
            'status' => Quotation::STATUS_SUBMITTED,
            'submitted_at' => $qDate1,
            'estimated_delivery' => $qDate1->copy()->addDays(35)->toDateString(),
            'payment_terms' => 'TT 30 Days after BL',
            'validity_period' => $qDate1->copy()->addDays(30)->toDateString(),
            'general_notes' => 'Penawaran SKD11 dan S45C origin Jepang dengan sertifikat MTC lengkap.',
            'created_at' => $qDate1,
            'updated_at' => $qDate1,
        ]);
        $this->createQuotationItem($q1, $item1, 3.80, 5650.0, 4, $qDate1);
        $this->createQuotationItem($q1, $item2, 1.95, 1332.0, 10, $qDate1);

        // Supplier 2 Quotation (CNY)
        $rateCny = $rates[1];
        $qDate2 = $prDate->copy()->addDays(3);
        $q2 = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $suppliers[1]->id,
            'currency' => ExchangeRate::CURRENCY_CNY,
            'exchange_rate_id' => $rateCny->id,
            'status' => Quotation::STATUS_SUBMITTED,
            'submitted_at' => $qDate2,
            'estimated_delivery' => $qDate2->copy()->addDays(28)->toDateString(),
            'payment_terms' => 'Net 30 Days',
            'validity_period' => $qDate2->copy()->addDays(30)->toDateString(),
            'general_notes' => 'Penawaran kompetitif origin China Baosteel mill.',
            'created_at' => $qDate2,
            'updated_at' => $qDate2,
        ]);
        $this->createQuotationItem($q2, $item1, 26.50, 5650.0, 4, $qDate2);
        $this->createQuotationItem($q2, $item2, 13.20, 1332.0, 10, $qDate2);

        // Supplier 3 Quotation (USD)
        $qDate3 = $prDate->copy()->addDays(4);
        $q3 = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $suppliers[2]->id,
            'currency' => ExchangeRate::CURRENCY_USD,
            'exchange_rate_id' => $rateUsd->id,
            'status' => Quotation::STATUS_SUBMITTED,
            'submitted_at' => $qDate3,
            'estimated_delivery' => $qDate3->copy()->addDays(42)->toDateString(),
            'payment_terms' => 'LC at sight',
            'validity_period' => $qDate3->copy()->addDays(30)->toDateString(),
            'general_notes' => 'High quality alloy grade direct from Nippon Steel.',
            'created_at' => $qDate3,
            'updated_at' => $qDate3,
        ]);
        $this->createQuotationItem($q3, $item1, 3.75, 5650.0, 4, $qDate3);
        $this->createQuotationItem($q3, $item2, 2.10, 1332.0, 10, $qDate3);

        // Chat negotiation between Purchasing and Supplier 1
        $conv = Conversation::create([
            'conversable_type' => PurchaseRequisition::class,
            'conversable_id' => $pr->id,
            'purchasing_user_id' => $purchasing->id,
            'supplier_user_id' => $suppliers[0]->id,
            'status' => Conversation::STATUS_OPEN,
            'created_at' => $qDate1->copy()->addHours(2),
            'updated_at' => $qDate1->copy()->addHours(4),
        ]);
        Message::create([
            'conversation_id' => $conv->id,
            'sender_id' => $purchasing->id,
            'body' => 'Mohon konfirmasi apakah lead time pengiriman untuk item SKD11 bisa dipercepat menjadi 25 hari kerja?',
            'created_at' => $qDate1->copy()->addHours(2),
            'read_at' => $qDate1->copy()->addHours(3),
        ]);
        Message::create([
            'conversation_id' => $conv->id,
            'sender_id' => $suppliers[0]->id,
            'body' => 'Baik Bapak/Ibu Purchasing ADASI, kami sedang mengkoordinasikan dengan pihak pelayaran untuk slot fast-vessel.',
            'created_at' => $qDate1->copy()->addHours(4),
            'read_at' => null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Package 2: Split Item-Level Award
    // ─────────────────────────────────────────────────────────────

    private function seedPackage2SplitAward(Period $period, User $purchasing, User $supplier1, User $supplier2, ExchangeRate $rateUsd): void
    {
        $prDate = Carbon::create(2026, 10, 4, 11, 0);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => self::PR_PREFIX.'2026/002',
            'notes' => 'Pengadaan material pelat SS400 dan as stainless steel SUS304 untuk perakitan fixture.',
            'status' => 'bidding',
            'created_at' => $prDate,
            'updated_at' => $prDate,
        ]);

        $item1 = $this->createPrItem($pr, [
            'material_name' => 'SS400',
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 12.0,
            'width' => 1500.0,
            'length' => 6000.0,
            'quantity' => 5,
            'weight_needed' => 4239.0,
            'hs_code' => '7208.51.00',
            'remark' => 'Plat baja struktural umum untuk base plate.',
        ], $prDate);

        $item2 = $this->createPrItem($pr, [
            'material_name' => 'SUS304',
            'shape' => PrItem::SHAPE_ROUND,
            'd_outer' => 40.0,
            'length' => 3000.0,
            'quantity' => 8,
            'weight_needed' => 2368.0,
            'hs_code' => '7222.20.00',
            'remark' => 'Stainless round bar tahan korosi.',
        ], $prDate);

        $pr->invitedSuppliers()->syncWithoutDetaching([
            $supplier1->id => ['invited_at' => $prDate],
            $supplier2->id => ['invited_at' => $prDate],
        ]);

        // Supplier 1 Quotation (Winner for Item 1)
        $qDate1 = $prDate->copy()->addDays(2);
        $q1 = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier1->id,
            'currency' => ExchangeRate::CURRENCY_USD,
            'exchange_rate_id' => $rateUsd->id,
            'status' => Quotation::STATUS_SUBMITTED,
            'submitted_at' => $qDate1,
            'estimated_delivery' => $qDate1->copy()->addDays(20)->toDateString(),
            'payment_terms' => 'Net 30 Days',
            'validity_period' => $qDate1->copy()->addDays(30)->toDateString(),
            'general_notes' => 'Harga terbaik untuk SS400 dengan ketersediaan stok ready mill.',
            'created_at' => $qDate1,
            'updated_at' => $qDate1,
        ]);
        $qItem1_1 = $this->createQuotationItem($q1, $item1, 1.15, 4239.0, 5, $qDate1); // Winner
        $qItem1_2 = $this->createQuotationItem($q1, $item2, 4.50, 2368.0, 8, $qDate1);

        // Supplier 2 Quotation (Winner for Item 2)
        $qDate2 = $prDate->copy()->addDays(3);
        $q2 = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier2->id,
            'currency' => ExchangeRate::CURRENCY_USD,
            'exchange_rate_id' => $rateUsd->id,
            'status' => Quotation::STATUS_SUBMITTED,
            'submitted_at' => $qDate2,
            'estimated_delivery' => $qDate2->copy()->addDays(25)->toDateString(),
            'payment_terms' => 'Net 30 Days',
            'validity_period' => $qDate2->copy()->addDays(30)->toDateString(),
            'general_notes' => 'Spesialis stainless steel bar dengan sertifikasi SUS304 food-grade.',
            'created_at' => $qDate2,
            'updated_at' => $qDate2,
        ]);
        $qItem2_1 = $this->createQuotationItem($q2, $item1, 1.30, 4239.0, 5, $qDate2);
        $qItem2_2 = $this->createQuotationItem($q2, $item2, 4.10, 2368.0, 8, $qDate2); // Winner

        // Perform Split Award
        $awardDate = Carbon::create(2026, 10, 9, 14, 0);

        PrItemAward::create([
            'pr_id' => $pr->id,
            'pr_item_id' => $item1->id,
            'quotation_id' => $q1->id,
            'quotation_item_id' => $qItem1_1->id,
            'supplier_id' => $supplier1->id,
            'purchase_order_id' => null, // Belum dibuatkan PO
            'awarded_by' => $purchasing->id,
            'awarded_at' => $awardDate,
            'created_at' => $awardDate,
            'updated_at' => $awardDate,
        ]);

        PrItemAward::create([
            'pr_id' => $pr->id,
            'pr_item_id' => $item2->id,
            'quotation_id' => $q2->id,
            'quotation_item_id' => $qItem2_2->id,
            'supplier_id' => $supplier2->id,
            'purchase_order_id' => null, // Belum dibuatkan PO
            'awarded_by' => $purchasing->id,
            'awarded_at' => $awardDate,
            'created_at' => $awardDate,
            'updated_at' => $awardDate,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Package 3: PO In-Production Timeline Progress
    // ─────────────────────────────────────────────────────────────

    private function seedPackage3PoInProduction(Period $period, User $purchasing, User $supplier1, ExchangeRate $rateUsd): void
    {
        $prDate = Carbon::create(2026, 9, 8, 8, 30);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => self::PR_PREFIX.'2026/003',
            'notes' => 'Kebutuhan blok baja cetakan presisi SKD11 untuk proyek tooling Q4.',
            'status' => 'completed',
            'created_at' => $prDate,
            'updated_at' => $prDate,
        ]);

        $item = $this->createPrItem($pr, [
            'material_name' => 'SKD11',
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 50.0,
            'width' => 400.0,
            'length' => 1500.0,
            'quantity' => 6,
            'weight_needed' => 7065.0,
            'hs_code' => '7228.40.00',
            'remark' => 'Heavy duty tool steel block.',
        ], $prDate);

        $pr->invitedSuppliers()->syncWithoutDetaching([$supplier1->id => ['invited_at' => $prDate]]);

        $qDate = $prDate->copy()->addDays(2);
        $q = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier1->id,
            'currency' => ExchangeRate::CURRENCY_USD,
            'exchange_rate_id' => $rateUsd->id,
            'status' => Quotation::STATUS_ACCEPTED,
            'submitted_at' => $qDate,
            'estimated_delivery' => Carbon::create(2026, 10, 25)->toDateString(),
            'payment_terms' => 'Net 30 Days',
            'validity_period' => $qDate->copy()->addDays(30)->toDateString(),
            'general_notes' => 'Penawaran SKD11 diterima.',
            'created_at' => $qDate,
            'updated_at' => $qDate,
        ]);
        $qItem = $this->createQuotationItem($q, $item, 3.85, 7065.0, 6, $qDate);

        $awardDate = $prDate->copy()->addDays(4);
        $poDate = Carbon::create(2026, 9, 14, 10, 0);

        $po = PurchaseOrder::create([
            'supplier_id' => $supplier1->id,
            'currency' => ExchangeRate::CURRENCY_USD,
            'exchange_rate_id' => $rateUsd->id,
            'po_number' => self::PO_PREFIX.'2026/001',
            'status' => 'active',
            'created_by' => $purchasing->id,
            'estimated_arrival' => '2026-10-25',
            'actual_arrival' => null,
            'notes' => 'PO material cetakan presisi. Harap perhatikan toleransi permukaan dan oli anti-karat.',
            'created_at' => $poDate,
            'updated_at' => $poDate,
        ]);
        $po->quotations()->syncWithoutDetaching([$q->id]);

        $award = PrItemAward::create([
            'pr_id' => $pr->id,
            'pr_item_id' => $item->id,
            'quotation_id' => $q->id,
            'quotation_item_id' => $qItem->id,
            'supplier_id' => $supplier1->id,
            'purchase_order_id' => $po->id,
            'awarded_by' => $purchasing->id,
            'awarded_at' => $awardDate,
            'created_at' => $awardDate,
            'updated_at' => $awardDate,
        ]);

        // Default 4 PO Documents
        $this->createDefaultPoDocuments($po, [
            'invoice' => 'pending',
            'packing_list' => 'pending',
            'bl' => 'processing',
            'form_e' => 'pending',
        ], $poDate);

        // Progress updates
        PoItemProgressUpdate::create([
            'pr_item_award_id' => $award->id,
            'status' => PoItemProgressUpdate::STATUS_ORDER_CONFIRMED,
            'supplier_controlled_qty_snapshot' => 6,
            'estimated_ready_date' => '2026-10-20',
            'note' => 'PO telah diterima pabrik dan slot rolling mill telah dijadwalkan.',
            'updated_by' => $supplier1->id,
            'created_at' => Carbon::create(2026, 9, 16, 9, 0),
            'updated_at' => Carbon::create(2026, 9, 16, 9, 0),
        ]);

        PoItemProgressUpdate::create([
            'pr_item_award_id' => $award->id,
            'status' => PoItemProgressUpdate::STATUS_MATERIAL_PREPARATION,
            'supplier_controlled_qty_snapshot' => 6,
            'estimated_ready_date' => '2026-10-20',
            'note' => 'Ingot baja perkakas SKD11 telah disiapkan dan lolos uji spektrometri komposisi kimia.',
            'updated_by' => $supplier1->id,
            'created_at' => Carbon::create(2026, 9, 21, 14, 0),
            'updated_at' => Carbon::create(2026, 9, 21, 14, 0),
        ]);

        PoItemProgressUpdate::create([
            'pr_item_award_id' => $award->id,
            'status' => PoItemProgressUpdate::STATUS_ON_PRODUCTION,
            'supplier_controlled_qty_snapshot' => 6,
            'estimated_ready_date' => '2026-10-20',
            'note' => 'Proses hot forging dan spheroidized annealing selesai 70%. Permukaan sedang di-milling.',
            'updated_by' => $supplier1->id,
            'created_at' => Carbon::create(2026, 9, 29, 16, 30),
            'updated_at' => Carbon::create(2026, 9, 29, 16, 30),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Package 4: PO with Partial Delivery Shipments
    // ─────────────────────────────────────────────────────────────

    private function seedPackage4PartialShipment(Period $period, User $purchasing, User $qc, User $supplier2, ExchangeRate $rateCny): void
    {
        $prDate = Carbon::create(2026, 8, 10, 10, 0);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => self::PR_PREFIX.'2026/004',
            'notes' => 'Pengadaan massal round bar S45C untuk shaft transmisi (Pengiriman bertahap 2 shipment).',
            'status' => 'completed',
            'created_at' => $prDate,
            'updated_at' => $prDate,
        ]);

        $item = $this->createPrItem($pr, [
            'material_name' => 'S45C',
            'shape' => PrItem::SHAPE_ROUND,
            'd_outer' => 80.0,
            'length' => 6000.0,
            'quantity' => 20,
            'weight_needed' => 4736.0,
            'hs_code' => '7228.30.00',
            'remark' => 'Total kebutuhan 20 batang, toleransi diameter h9.',
        ], $prDate);

        $pr->invitedSuppliers()->syncWithoutDetaching([$supplier2->id => ['invited_at' => $prDate]]);

        $qDate = $prDate->copy()->addDays(2);
        $q = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier2->id,
            'currency' => ExchangeRate::CURRENCY_CNY,
            'exchange_rate_id' => $rateCny->id,
            'status' => Quotation::STATUS_ACCEPTED,
            'submitted_at' => $qDate,
            'estimated_delivery' => '2026-10-10',
            'payment_terms' => 'Net 30 Days',
            'validity_period' => $qDate->copy()->addDays(30)->toDateString(),
            'general_notes' => 'Sanggup memenuhi 20 batang dengan 2 jadwal pengiriman kapal.',
            'created_at' => $qDate,
            'updated_at' => $qDate,
        ]);
        $qItem = $this->createQuotationItem($q, $item, 13.50, 4736.0, 20, $qDate);

        $awardDate = $prDate->copy()->addDays(4);
        $poDate = Carbon::create(2026, 8, 16, 11, 0);

        $po = PurchaseOrder::create([
            'supplier_id' => $supplier2->id,
            'currency' => ExchangeRate::CURRENCY_CNY,
            'exchange_rate_id' => $rateCny->id,
            'po_number' => self::PO_PREFIX.'2026/002',
            'status' => 'active',
            'created_by' => $purchasing->id,
            'estimated_arrival' => '2026-10-10',
            'actual_arrival' => null,
            'notes' => 'PO Parsial. Batch 1: 12 pcs tiba September, Batch 2: 8 pcs tiba Oktober.',
            'created_at' => $poDate,
            'updated_at' => $poDate,
        ]);
        $po->quotations()->syncWithoutDetaching([$q->id]);

        $award = PrItemAward::create([
            'pr_id' => $pr->id,
            'pr_item_id' => $item->id,
            'quotation_id' => $q->id,
            'quotation_item_id' => $qItem->id,
            'supplier_id' => $supplier2->id,
            'purchase_order_id' => $po->id,
            'awarded_by' => $purchasing->id,
            'awarded_at' => $awardDate,
            'created_at' => $awardDate,
            'updated_at' => $awardDate,
        ]);

        $this->createDefaultPoDocuments($po, [
            'invoice' => 'received',
            'packing_list' => 'received',
            'bl' => 'verified',
            'form_e' => 'verified',
        ], $poDate);

        // ── Shipment 1: 12 pcs Arrived & QC OK ──
        $shpDate1 = Carbon::create(2026, 8, 25, 9, 0);
        $arrivedDate1 = Carbon::create(2026, 9, 15, 14, 0);

        $shp1 = Shipment::create([
            'shipment_number' => self::SHP_PREFIX.'2026/001',
            'supplier_id' => $supplier2->id,
            'status' => Shipment::STATUS_ARRIVED,
            'shipment_date' => $shpDate1->toDateString(),
            'estimated_arrival_date' => '2026-09-15',
            'actual_arrival_date' => $arrivedDate1->toDateString(),
            'notes' => 'Pengiriman Batch 1: 12 pcs S45C Round Bar.',
            'created_by' => $supplier2->id,
            'submitted_at' => $shpDate1,
            'created_at' => $shpDate1,
            'updated_at' => $arrivedDate1,
        ]);

        $shpItem1 = ShipmentItem::create([
            'shipment_id' => $shp1->id,
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $qItem->id,
            'pr_item_award_id' => $award->id,
            'shipped_qty' => 12,
            'actual_weight_kg' => 2841.6,
            'notes' => '12 batang bundling pita baja.',
            'created_at' => $shpDate1,
            'updated_at' => $shpDate1,
        ]);

        $this->createShipmentDocumentsWithPdf($shp1, $supplier2, $shpDate1);

        // QC Inspection for Shipment 1: OK
        $qc1 = QcInspection::create([
            'po_id' => $po->id,
            'shipment_id' => $shp1->id,
            'inspected_by' => $qc->id,
            'status' => 'ok',
            'inspected_at' => $arrivedDate1->copy()->addDay(),
            'created_at' => $arrivedDate1->copy()->addDay(),
            'updated_at' => $arrivedDate1->copy()->addDay(),
        ]);

        QcItem::create([
            'inspection_id' => $qc1->id,
            'pr_item_id' => $item->id,
            'shipment_item_id' => $shpItem1->id,
            'actual_d_outer' => 80.05,
            'actual_length' => 6005.0,
            'actual_weight' => 2842.0,
            'status' => 'ok',
            'notes' => 'Diameter aktual 80.05mm masih dalam toleransi h9. Permukaan mulus tanpa gores.',
            'created_at' => $qc1->inspected_at,
            'updated_at' => $qc1->inspected_at,
        ]);

        // ── Shipment 2: 8 pcs Submitted (In-Transit) ──
        $shpDate2 = Carbon::create(2026, 9, 28, 10, 0);

        $shp2 = Shipment::create([
            'shipment_number' => self::SHP_PREFIX.'2026/002',
            'supplier_id' => $supplier2->id,
            'status' => Shipment::STATUS_SUBMITTED,
            'shipment_date' => $shpDate2->toDateString(),
            'estimated_arrival_date' => '2026-10-18',
            'actual_arrival_date' => null,
            'notes' => 'Pengiriman Batch 2 (Sisa pemenuhan): 8 pcs S45C Round Bar.',
            'created_by' => $supplier2->id,
            'submitted_at' => $shpDate2,
            'created_at' => $shpDate2,
            'updated_at' => $shpDate2,
        ]);

        ShipmentItem::create([
            'shipment_id' => $shp2->id,
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $qItem->id,
            'pr_item_award_id' => $award->id,
            'shipped_qty' => 8,
            'actual_weight_kg' => 1894.4,
            'notes' => '8 batang pemenuhan akhir.',
            'created_at' => $shpDate2,
            'updated_at' => $shpDate2,
        ]);

        $this->createShipmentDocumentsWithPdf($shp2, $supplier2, $shpDate2);
    }

    // ─────────────────────────────────────────────────────────────
    // Package 5: PO Completed (Happy Path)
    // ─────────────────────────────────────────────────────────────

    private function seedPackage5Completed(Period $period, User $purchasing, User $qc, User $supplier1, ExchangeRate $rateUsd): void
    {
        $prDate = Carbon::create(2026, 8, 1, 9, 0);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => self::PR_PREFIX.'2026/005',
            'notes' => 'Pengadaan plat SS400 tebal 16mm untuk fabrikasi tangki oli hydraulic press.',
            'status' => 'completed',
            'created_at' => $prDate,
            'updated_at' => $prDate,
        ]);

        $item = $this->createPrItem($pr, [
            'material_name' => 'SS400',
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 16.0,
            'width' => 1500.0,
            'length' => 6000.0,
            'quantity' => 4,
            'weight_needed' => 4522.0,
            'hs_code' => '7208.51.00',
            'remark' => 'Plat baja tebal 16mm bebas laminasi.',
        ], $prDate);

        $pr->invitedSuppliers()->syncWithoutDetaching([$supplier1->id => ['invited_at' => $prDate]]);

        $qDate = $prDate->copy()->addDays(2);
        $q = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier1->id,
            'currency' => ExchangeRate::CURRENCY_USD,
            'exchange_rate_id' => $rateUsd->id,
            'status' => Quotation::STATUS_ACCEPTED,
            'submitted_at' => $qDate,
            'estimated_delivery' => '2026-09-05',
            'payment_terms' => 'Net 30 Days',
            'validity_period' => $qDate->copy()->addDays(30)->toDateString(),
            'general_notes' => 'SS400 hot rolled steel plate bersertifikat standar ASTM/JIS.',
            'created_at' => $qDate,
            'updated_at' => $qDate,
        ]);
        $qItem = $this->createQuotationItem($q, $item, 1.18, 4522.0, 4, $qDate);

        $awardDate = $prDate->copy()->addDays(4);
        $poDate = Carbon::create(2026, 8, 8, 10, 0);
        $actualArrival = Carbon::create(2026, 9, 5, 11, 0);

        $po = PurchaseOrder::create([
            'supplier_id' => $supplier1->id,
            'currency' => ExchangeRate::CURRENCY_USD,
            'exchange_rate_id' => $rateUsd->id,
            'po_number' => self::PO_PREFIX.'2026/003',
            'status' => 'completed',
            'created_by' => $purchasing->id,
            'estimated_arrival' => '2026-09-05',
            'actual_arrival' => $actualArrival->toDateString(),
            'notes' => 'PO selesai penuh. Material dan dokumen telah diverifikasi lengkap.',
            'created_at' => $poDate,
            'updated_at' => $actualArrival->copy()->addDays(2),
        ]);
        $po->quotations()->syncWithoutDetaching([$q->id]);

        $award = PrItemAward::create([
            'pr_id' => $pr->id,
            'pr_item_id' => $item->id,
            'quotation_id' => $q->id,
            'quotation_item_id' => $qItem->id,
            'supplier_id' => $supplier1->id,
            'purchase_order_id' => $po->id,
            'awarded_by' => $purchasing->id,
            'awarded_at' => $awardDate,
            'created_at' => $awardDate,
            'updated_at' => $awardDate,
        ]);

        $this->createDefaultPoDocuments($po, [
            'invoice' => 'verified',
            'packing_list' => 'verified',
            'bl' => 'done',
            'form_e' => 'done',
        ], $poDate);

        $shpDate = Carbon::create(2026, 8, 20, 9, 0);
        $shp = Shipment::create([
            'shipment_number' => self::SHP_PREFIX.'2026/003',
            'supplier_id' => $supplier1->id,
            'status' => Shipment::STATUS_ARRIVED,
            'shipment_date' => $shpDate->toDateString(),
            'estimated_arrival_date' => '2026-09-05',
            'actual_arrival_date' => $actualArrival->toDateString(),
            'notes' => 'Pengiriman penuh 4 lembar plat SS400.',
            'created_by' => $supplier1->id,
            'submitted_at' => $shpDate,
            'created_at' => $shpDate,
            'updated_at' => $actualArrival,
        ]);

        $shpItem = ShipmentItem::create([
            'shipment_id' => $shp->id,
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $qItem->id,
            'pr_item_award_id' => $award->id,
            'shipped_qty' => 4,
            'actual_weight_kg' => 4522.0,
            'notes' => '4 lembar plat baja.',
            'created_at' => $shpDate,
            'updated_at' => $shpDate,
        ]);

        $this->createShipmentDocumentsWithPdf($shp, $supplier1, $shpDate);

        // QC Inspection: OK
        $qcIns = QcInspection::create([
            'po_id' => $po->id,
            'shipment_id' => $shp->id,
            'inspected_by' => $qc->id,
            'status' => 'ok',
            'inspected_at' => $actualArrival->copy()->addDay(),
            'created_at' => $actualArrival->copy()->addDay(),
            'updated_at' => $actualArrival->copy()->addDay(),
        ]);

        QcItem::create([
            'inspection_id' => $qcIns->id,
            'pr_item_id' => $item->id,
            'shipment_item_id' => $shpItem->id,
            'actual_thickness' => 16.02,
            'actual_width' => 1502.0,
            'actual_length' => 6005.0,
            'actual_weight' => 4525.0,
            'status' => 'ok',
            'notes' => 'Dimensi aktual memenuhi spesifikasi. Hasil uji tarik dan MTC sesuai grade SS400.',
            'created_at' => $qcIns->inspected_at,
            'updated_at' => $qcIns->inspected_at,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Package 6: PO Waiting QC / NG & Material Claim
    // ─────────────────────────────────────────────────────────────

    private function seedPackage6QcNgAndClaim(Period $period, User $purchasing, User $qc, User $supplier1, ExchangeRate $rateUsd): void
    {
        $prDate = Carbon::create(2026, 9, 1, 9, 0);
        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $purchasing->id,
            'pr_number' => self::PR_PREFIX.'2026/006',
            'notes' => 'Pengadaan plat baja tahan karat SUS304 tebal 20mm untuk komponen dies presisi.',
            'status' => 'completed',
            'created_at' => $prDate,
            'updated_at' => $prDate,
        ]);

        $item = $this->createPrItem($pr, [
            'material_name' => 'SUS304',
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 20.0,
            'width' => 1219.0,
            'length' => 2438.0,
            'quantity' => 5,
            'weight_needed' => 2333.0,
            'hs_code' => '7219.22.00',
            'remark' => 'Plat stainless presisi, toleransi tebal maksimal ±0.3mm.',
        ], $prDate);

        $pr->invitedSuppliers()->syncWithoutDetaching([$supplier1->id => ['invited_at' => $prDate]]);

        $qDate = $prDate->copy()->addDays(2);
        $q = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier1->id,
            'currency' => ExchangeRate::CURRENCY_USD,
            'exchange_rate_id' => $rateUsd->id,
            'status' => Quotation::STATUS_ACCEPTED,
            'submitted_at' => $qDate,
            'estimated_delivery' => '2026-09-28',
            'payment_terms' => 'Net 30 Days',
            'validity_period' => $qDate->copy()->addDays(30)->toDateString(),
            'general_notes' => 'SUS304 cold/hot rolled sheet no. 1 finish.',
            'created_at' => $qDate,
            'updated_at' => $qDate,
        ]);
        $qItem = $this->createQuotationItem($q, $item, 4.40, 2333.0, 5, $qDate);

        $awardDate = $prDate->copy()->addDays(4);
        $poDate = Carbon::create(2026, 9, 8, 10, 0);
        $arrivalDate = Carbon::create(2026, 9, 28, 15, 0);

        $po = PurchaseOrder::create([
            'supplier_id' => $supplier1->id,
            'currency' => ExchangeRate::CURRENCY_USD,
            'exchange_rate_id' => $rateUsd->id,
            'po_number' => self::PO_PREFIX.'2026/004',
            'status' => 'claim_needed',
            'created_by' => $purchasing->id,
            'estimated_arrival' => '2026-09-28',
            'actual_arrival' => $arrivalDate->toDateString(),
            'notes' => 'Status Claim Needed: Hasil inspeksi QC menyatakan material NG (ketebalan di bawah toleransi).',
            'created_at' => $poDate,
            'updated_at' => Carbon::create(2026, 10, 2),
        ]);
        $po->quotations()->syncWithoutDetaching([$q->id]);

        $award = PrItemAward::create([
            'pr_id' => $pr->id,
            'pr_item_id' => $item->id,
            'quotation_id' => $q->id,
            'quotation_item_id' => $qItem->id,
            'supplier_id' => $supplier1->id,
            'purchase_order_id' => $po->id,
            'awarded_by' => $purchasing->id,
            'awarded_at' => $awardDate,
            'created_at' => $awardDate,
            'updated_at' => $awardDate,
        ]);

        $this->createDefaultPoDocuments($po, [
            'invoice' => 'received',
            'packing_list' => 'received',
            'bl' => 'received',
            'form_e' => 'processing',
        ], $poDate);

        $shpDate = Carbon::create(2026, 9, 15, 9, 0);
        $shp = Shipment::create([
            'shipment_number' => self::SHP_PREFIX.'2026/004',
            'supplier_id' => $supplier1->id,
            'status' => Shipment::STATUS_ARRIVED,
            'shipment_date' => $shpDate->toDateString(),
            'estimated_arrival_date' => '2026-09-28',
            'actual_arrival_date' => $arrivalDate->toDateString(),
            'notes' => 'Pengiriman 5 lembar plat SUS304 20mm.',
            'created_by' => $supplier1->id,
            'submitted_at' => $shpDate,
            'created_at' => $shpDate,
            'updated_at' => $arrivalDate,
        ]);

        $shpItem = ShipmentItem::create([
            'shipment_id' => $shp->id,
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $qItem->id,
            'pr_item_award_id' => $award->id,
            'shipped_qty' => 5,
            'actual_weight_kg' => 2200.0,
            'notes' => '5 lembar plat stainless steel.',
            'created_at' => $shpDate,
            'updated_at' => $shpDate,
        ]);

        $this->createShipmentDocumentsWithPdf($shp, $supplier1, $shpDate);

        // QC Inspection: NG
        $qcDate = Carbon::create(2026, 9, 30, 10, 30);
        $qcIns = QcInspection::create([
            'po_id' => $po->id,
            'shipment_id' => $shp->id,
            'inspected_by' => $qc->id,
            'status' => 'ng',
            'inspected_at' => $qcDate,
            'created_at' => $qcDate,
            'updated_at' => $qcDate,
        ]);

        QcItem::create([
            'inspection_id' => $qcIns->id,
            'pr_item_id' => $item->id,
            'shipment_item_id' => $shpItem->id,
            'actual_thickness' => 18.80, // Target: 20mm, Deviasi: -6% (NG)
            'actual_width' => 1218.0,
            'actual_length' => 2435.0,
            'actual_weight' => 2195.0,
            'status' => 'ng',
            'notes' => 'Tebal aktual rata-rata hanya 18.8mm (spesifikasi 20.0mm). Selisih -1.2mm (-6.0%) melebihi toleransi standar JIS G4304.',
            'created_at' => $qcDate,
            'updated_at' => $qcDate,
        ]);

        // Attach Dummy JPEG Image for QC NG Evidence
        $this->createDummyImageAttachment($qcIns, $qc, 'qc-ng-thickness-defect.jpg', 'Foto mikrometer pengukuran tebal aktual plat SUS304 yang menunjukkan 18.8mm.', $qcDate);

        // Material Claim
        $claimDate = Carbon::create(2026, 10, 2, 11, 0);
        $responseDate = Carbon::create(2026, 10, 4, 15, 0);

        MaterialClaim::create([
            'inspection_id' => $qcIns->id,
            'po_id' => $po->id,
            'submitted_by' => $purchasing->id,
            'supplier_id' => $supplier1->id,
            'status' => 'responded',
            'description' => 'Material plat stainless steel SUS304 tebal aktual hanya 18.8mm (spesifikasi yang dipesan 20.0mm). Penyimpangan -6% ini tidak dapat dipakai pada perakitan dies presisi ADASI karena kekuatan struktural tidak terpenuhi.',
            'resolution_expected' => 'Penggantian material 5 lembar sesuai ketebalan 20mm dalam 14 hari kerja atau pengembalian dana penuh untuk item bersangkutan.',
            'deadline' => '2026-10-20',
            'supplier_response' => 'Kami menyampaikan permohonan maaf sebesar-besarnya atas kelalaian sortir di fasilitas pemotongan kami. Kami telah memproses penggantian material 5 lembar tebal 20mm dari gudang transit Singapura dan siap diberangkatkan dalam 7 hari kerja.',
            'created_at' => $claimDate,
            'updated_at' => $responseDate,
        ]);

        // Conversation regarding the claim
        $conv = Conversation::create([
            'conversable_type' => PurchaseOrder::class,
            'conversable_id' => $po->id,
            'purchasing_user_id' => $purchasing->id,
            'supplier_user_id' => $supplier1->id,
            'status' => Conversation::STATUS_WAITING_PURCHASING,
            'created_at' => $claimDate,
            'updated_at' => $responseDate,
        ]);
        Message::create([
            'conversation_id' => $conv->id,
            'sender_id' => $purchasing->id,
            'body' => 'Klaim material untuk PO/MAT-DUMMY/2026/004 telah diajukan. Mohon percepat jadwal pengiriman material pengganti agar line produksi kami tidak terhenti.',
            'created_at' => $claimDate,
            'read_at' => $responseDate,
        ]);
        Message::create([
            'conversation_id' => $conv->id,
            'sender_id' => $supplier1->id,
            'body' => 'Bapak/Ibu Purchasing ADASI, replacement order no. RO-9921 telah masuk ke antrean kapal ekspres per tanggal 10 Oktober 2026.',
            'created_at' => $responseDate,
            'read_at' => null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers: Actors, Items, Quotations, Documents, Files
    // ─────────────────────────────────────────────────────────────

    private function ensureUser(string $email, string $name, string $role): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
                'account_status' => User::ACCOUNT_STATUS_ACTIVE,
            ]
        );
    }

    private function ensureSupplier(string $email, string $companyName, string $category, string $npwp): User
    {
        $user = $this->ensureUser($email, $companyName, 'supplier');
        $user->supplierScopes()->firstOrCreate(['scope' => 'import']);

        Supplier::updateOrCreate(
            ['user_id' => $user->id],
            [
                'company_name' => $companyName,
                'address' => 'Kawasan Industri Internasional, Jababeka / Karawang',
                'phone' => '021-'.rand(1000000, 9999999),
                'npwp' => $npwp,
                'category' => $category,
            ]
        );

        return $user;
    }

    private function ensureExchangeRate(string $currency, float $rateToIdr, User $admin): ExchangeRate
    {
        $today = now()->toDateString();
        $rate = ExchangeRate::where('currency', $currency)
            ->where('valid_from', '<=', $today)
            ->latest('valid_from')
            ->first();

        if (! $rate) {
            $rate = ExchangeRate::create([
                'currency' => $currency,
                'rate_to_idr' => $rateToIdr,
                'valid_from' => $today,
                'created_by' => $admin->id,
            ]);
        }

        return $rate;
    }

    private function ensurePeriod(int $year, int $month, string $name, string $status, User $admin): Period
    {
        return Period::firstOrCreate(
            ['year' => $year, 'month' => $month],
            [
                'name' => $name,
                'status' => $status,
                'created_by' => $admin->id,
            ]
        );
    }

    private function createPrItem(PurchaseRequisition $pr, array $data, Carbon $date): PrItem
    {
        $normalizedCode = strtoupper(str_replace([' ', '-', '_'], '', $data['material_name']));
        $materialMaster = MaterialMaster::where('normalized_code', $normalizedCode)->first();

        return PrItem::create([
            'pr_id' => $pr->id,
            'material_master_id' => $materialMaster?->id,
            'material_name' => $data['material_name'],
            'hs_code' => $data['hs_code'] ?? '7228.40.00',
            'hs_code_source' => PrItem::HS_SOURCE_AUTO,
            'shape' => $data['shape'],
            'thickness' => $data['thickness'] ?? null,
            'd_inner' => $data['d_inner'] ?? null,
            'd_outer' => $data['d_outer'] ?? null,
            'width' => $data['width'] ?? null,
            'length' => $data['length'] ?? null,
            'quantity' => $data['quantity'] ?? 1,
            'weight_needed' => $data['weight_needed'],
            'weight_calculation_status' => PrItem::WEIGHT_STATUS_CALCULATED,
            'remark' => $data['remark'] ?? null,
            'created_at' => $date,
            'updated_at' => $date,
        ]);
    }

    private function createQuotationItem(Quotation $q, PrItem $prItem, float $pricePerKg, float $offeredTotalWeight, int $availQty, Carbon $date): QuotationItem
    {
        $amount = round($pricePerKg * $offeredTotalWeight, 4);

        return QuotationItem::create([
            'quotation_id' => $q->id,
            'pr_item_id' => $prItem->id,
            'is_available' => true,
            'price_per_kg' => $pricePerKg,
            'amount' => $amount,
            'available_qty' => $availQty,
            'available_thickness' => $prItem->thickness,
            'available_d_inner' => $prItem->d_inner,
            'available_d_outer' => $prItem->d_outer,
            'available_width' => $prItem->width,
            'available_length' => $prItem->length,
            'offered_weight_per_unit' => round($offeredTotalWeight / max(1, $availQty), 4),
            'offered_weight_source' => QuotationItem::OFFER_WEIGHT_SOURCE_AUTO,
            'notes' => 'Tersedia stok siap potong sesuai spesifikasi.',
            'created_at' => $date,
            'updated_at' => $date,
        ]);
    }

    private function createDefaultPoDocuments(PurchaseOrder $po, array $statuses, Carbon $date): void
    {
        foreach ($statuses as $type => $status) {
            PoDocument::create([
                'po_id' => $po->id,
                'doc_type' => $type,
                'status' => $status,
                'created_at' => $date,
                'updated_at' => $date,
            ]);
        }
    }

    private function createShipmentDocumentsWithPdf(Shipment $shp, User $supplier, Carbon $date): void
    {
        $types = [
            ShipmentDocument::DOC_TYPE_INVOICE => ['INV-'.$shp->shipment_number.'.pdf', 'Commercial Invoice'],
            ShipmentDocument::DOC_TYPE_PACKING_LIST => ['PL-'.$shp->shipment_number.'.pdf', 'Packing List & Net/Gross Weight'],
            ShipmentDocument::DOC_TYPE_BL => ['BL-'.$shp->shipment_number.'.pdf', 'Bill of Lading Sea Freight'],
            ShipmentDocument::DOC_TYPE_FORM_E => ['FE-'.$shp->shipment_number.'.pdf', 'Certificate of Origin Form E / AK'],
        ];

        foreach ($types as $docType => [$fileName, $docDesc]) {
            $doc = ShipmentDocument::create([
                'shipment_id' => $shp->id,
                'doc_type' => $docType,
                'status' => ShipmentDocument::STATUS_VERIFIED,
                'document_number' => 'DOC-'.strtoupper(Str::random(8)),
                'notes' => $docDesc,
                'created_at' => $date,
                'updated_at' => $date,
            ]);

            $this->createDummyPdfAttachment($doc, $supplier, $fileName, $docDesc, $date);
        }
    }

    private function createDummyPdfAttachment($attachable, User $uploader, string $fileName, string $title, Carbon $date): Attachment
    {
        $pdfContent = "%PDF-1.4\n1 0 obj\n<<\n/Type /Catalog\n/Pages 2 0 R\n>>\nendobj\n2 0 obj\n<<\n/Type /Pages\n/Kids [3 0 R]\n/Count 1\n>>\nendobj\n3 0 obj\n<<\n/Type /Page\n/Parent 2 0 R\n/MediaBox [0 0 612 792]\n/Resources <<>>\n>>\nendobj\nxref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \ntrailer\n<<\n/Size 4\n/Root 1 0 R\n>>\nstartxref\n206\n%%EOF\n";

        $relPath = self::STORAGE_DIR.'/'.$date->format('Y/m').'/'.Str::uuid().'_'.$fileName;
        Storage::disk('private')->put($relPath, $pdfContent);

        $sha256 = hash('sha256', $pdfContent);
        $fileSize = strlen($pdfContent);

        $inspection = FileInspection::create([
            'profile' => 'general_attachment',
            'status' => 'VALIDATED',
            'policy_version' => '1.0',
            'file_path' => $relPath,
            'file_size' => $fileSize,
            'file_type' => 'application/pdf',
            'sha256' => $sha256,
            'inspected_at' => $date,
            'metadata' => [
                'title' => $title,
                'source' => 'MaterialProcurementDummySeeder',
            ],
            'created_at' => $date,
            'updated_at' => $date,
        ]);

        return Attachment::create([
            'attachable_type' => get_class($attachable),
            'attachable_id' => $attachable->id,
            'file_path' => $relPath,
            'file_name' => $fileName,
            'file_type' => 'application/pdf',
            'file_inspection_id' => $inspection->id,
            'uploaded_by' => $uploader->id,
            'created_at' => $date,
            'updated_at' => $date,
        ]);
    }

    private function createDummyImageAttachment($attachable, User $uploader, string $fileName, string $title, Carbon $date): Attachment
    {
        // 1x1 Minimal Valid JPEG
        $jpegContent = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');

        $relPath = self::STORAGE_DIR.'/'.$date->format('Y/m').'/'.Str::uuid().'_'.$fileName;
        Storage::disk('private')->put($relPath, $jpegContent);

        $sha256 = hash('sha256', $jpegContent);
        $fileSize = strlen($jpegContent);

        $inspection = FileInspection::create([
            'profile' => 'general_attachment',
            'status' => 'VALIDATED',
            'policy_version' => '1.0',
            'file_path' => $relPath,
            'file_size' => $fileSize,
            'file_type' => 'image/jpeg',
            'sha256' => $sha256,
            'inspected_at' => $date,
            'metadata' => [
                'title' => $title,
                'source' => 'MaterialProcurementDummySeeder',
            ],
            'created_at' => $date,
            'updated_at' => $date,
        ]);

        return Attachment::create([
            'attachable_type' => get_class($attachable),
            'attachable_id' => $attachable->id,
            'file_path' => $relPath,
            'file_name' => $fileName,
            'file_type' => 'image/jpeg',
            'file_inspection_id' => $inspection->id,
            'uploaded_by' => $uploader->id,
            'created_at' => $date,
            'updated_at' => $date,
        ]);
    }
}
