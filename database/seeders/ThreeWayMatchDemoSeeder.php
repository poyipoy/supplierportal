<?php

namespace Database\Seeders;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\InvoicePhysicalReceiptService;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;

class ThreeWayMatchDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command?->info('Seeding 3-Way Match showcase data for ADASI Supplier Portal...');

        // Allow batch file processing in CLI seeder execution
        config(['native_file_security.requests.max_files' => 200]);

        // 1. Ensure Finance User
        $finance = User::firstOrCreate(
            ['email' => 'finance@adasi.com'],
            [
                'name' => 'Finance & Accounting ADASI',
                'password' => Hash::make('password'),
                'role' => 'finance',
                'is_active' => true,
                'account_status' => 'ACTIVE',
            ]
        );
        if ($finance->account_status !== 'ACTIVE' || ! $finance->is_active) {
            $finance->update(['account_status' => 'ACTIVE', 'is_active' => true]);
        }

        // 2. Ensure Local Supplier User
        $supplierUser = User::firstOrCreate(
            ['email' => 'supplier.local@adasi.com'],
            [
                'name' => 'PT. Adasi Baja Perkasa',
                'password' => Hash::make('password'),
                'role' => 'supplier',
                'is_active' => true,
                'account_status' => 'ACTIVE',
            ]
        );
        if ($supplierUser->account_status !== 'ACTIVE' || ! $supplierUser->is_active) {
            $supplierUser->update(['account_status' => 'ACTIVE', 'is_active' => true]);
        }

        SupplierScope::firstOrCreate([
            'supplier_id' => $supplierUser->id,
            'scope' => 'local',
        ]);

        Supplier::updateOrCreate(
            ['user_id' => $supplierUser->id],
            [
                'company_name' => 'PT. Adasi Baja Perkasa',
                'address' => 'Kawasan Industri KIIC Lot C-4, Karawang Barat',
                'phone' => '0267-8451234',
                'npwp' => '01.345.678.9-432.000',
                'category' => 'Barang',
                'vendor_category' => 'Barang',
                'is_pkp' => true,
                'payment_term_days' => 30,
            ]
        );

        SupplierBankAccount::firstOrCreate(
            ['supplier_id' => $supplierUser->id, 'account_number' => '8730129845'],
            [
                'bank_name' => 'BCA',
                'account_holder_name' => 'PT ADASI BAJA PERKASA',
                'status' => 'VERIFIED',
                'verified_by' => $finance->id,
                'verified_at' => now(),
                'activated_at' => now(),
            ]
        );

        // 3. Clean up only existing 3-Way demo records (idempotent re-seed)
        $this->cleanExistingDemoRecords();

        // 4. Resolve delivery Wednesday (today if Wed, or next Wed)
        $targetWed = BusinessTime::today();
        if ($targetWed->dayOfWeek !== CarbonImmutable::WEDNESDAY) {
            $targetWed = $targetWed->next(CarbonImmutable::WEDNESDAY);
        }
        $wedDate = $targetWed->toDateString();
        $todayDate = BusinessTime::today()->toDateString();

        $submitService = app(InvoiceSubmissionService::class);
        $receiptService = app(InvoicePhysicalReceiptService::class);

        // ─────────────────────────────────────────────────────────────────────────
        // SKENARIO 1: EXACT_MATCH (Sesuai Nilai GR - Selisih Rp 0)
        // ─────────────────────────────────────────────────────────────────────────
        $po1 = LocalPurchaseOrder::create([
            'po_number' => 'PO-LOC-3WAY-001',
            'supplier_id' => $supplierUser->id,
            'total_amount' => '50000000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'po_date' => $todayDate,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'description' => 'Pengadaan Plat Baja Karbon SPCC (Demo 3-Way Exact Match)',
            'created_by' => $finance->id,
        ]);

        $gr1_1 = LocalGoodsReceipt::create([
            'local_purchase_order_id' => $po1->id,
            'gr_number' => 'GR-3WAY-001-01',
            'gr_date' => $todayDate,
            'qty' => '10.0000',
            'uom' => 'PCS',
            'unit_price' => '1500000.00',
            'receipt_amount' => '15000000.00',
            'po_line_reference' => 'LN-SPCC-01',
            'description' => 'Plat Baja SPCC 1.2mm batch 1',
            'status' => LocalGoodsReceipt::STATUS_AVAILABLE,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $finance->id,
            'updated_by' => $finance->id,
        ]);

        $gr1_2 = LocalGoodsReceipt::create([
            'local_purchase_order_id' => $po1->id,
            'gr_number' => 'GR-3WAY-001-02',
            'gr_date' => $todayDate,
            'qty' => '10.0000',
            'uom' => 'PCS',
            'unit_price' => '1500000.00',
            'receipt_amount' => '15000000.00',
            'po_line_reference' => 'LN-SPCC-02',
            'description' => 'Plat Baja SPCC 1.2mm batch 2',
            'status' => LocalGoodsReceipt::STATUS_AVAILABLE,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $finance->id,
            'updated_by' => $finance->id,
        ]);

        // GR 3 remains available on PO 1
        LocalGoodsReceipt::create([
            'local_purchase_order_id' => $po1->id,
            'gr_number' => 'GR-3WAY-001-03',
            'gr_date' => $todayDate,
            'qty' => '10.0000',
            'uom' => 'PCS',
            'unit_price' => '2000000.00',
            'receipt_amount' => '20000000.00',
            'po_line_reference' => 'LN-SPCC-03',
            'description' => 'Plat Baja SPCC 2.0mm batch 3',
            'status' => LocalGoodsReceipt::STATUS_AVAILABLE,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $finance->id,
            'updated_by' => $finance->id,
        ]);

        // Expected GR total: 15.000.000 + 15.000.000 = 30.000.000. Invoice DPP: 30.000.000 (diff 0)
        $inv1 = $submitService->submit(
            $supplierUser,
            [
                'invoice_number' => 'INV-3WAY-EXACT-001',
                'invoice_date' => $todayDate,
                'local_purchase_order_id' => $po1->id,
                'goods_receipt_ids' => [$gr1_1->id, $gr1_2->id],
                'invoice_amount' => 30000000.00,
                'tax_amount' => 3300000.00,
                'ppn_scheme' => '11%',
                'tax_invoice_number' => '010.001-26.33330001',
                'scheduled_physical_delivery_date' => $wedDate,
            ],
            $this->createDummyFiles('INV-3WAY-EXACT-001', 1)
        );

        $signedUrl1 = URL::signedRoute('receipts.verify-supplier', [
            'receipt' => $inv1->receipt->receipt_number,
            'revision' => $inv1->revision_number,
        ]);
        $receiptService->recordReceipt($finance, $inv1, 'Dokumen fisik lengkap diterima kasir loket.', $signedUrl1);

        // ─────────────────────────────────────────────────────────────────────────
        // SKENARIO 2: WITHIN_TOLERANCE (Dalam Toleransi - Selisih Rp 50 di bawah Rp 100 / 0.5%)
        // ─────────────────────────────────────────────────────────────────────────
        $po2 = LocalPurchaseOrder::create([
            'po_number' => 'PO-LOC-3WAY-002',
            'supplier_id' => $supplierUser->id,
            'total_amount' => '30000000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'po_date' => $todayDate,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'description' => 'Pengadaan Baut & Mur High Tensile (Demo 3-Way Within Tolerance)',
            'created_by' => $finance->id,
        ]);

        $gr2_1 = LocalGoodsReceipt::create([
            'local_purchase_order_id' => $po2->id,
            'gr_number' => 'GR-3WAY-002-01',
            'gr_date' => $todayDate,
            'qty' => '100.0000',
            'uom' => 'SET',
            'unit_price' => '150000.00',
            'receipt_amount' => '15000000.00',
            'po_line_reference' => 'LN-BOLT-01',
            'description' => 'Baut & Mur HT M16 x 50mm batch 1',
            'status' => LocalGoodsReceipt::STATUS_AVAILABLE,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $finance->id,
            'updated_by' => $finance->id,
        ]);

        LocalGoodsReceipt::create([
            'local_purchase_order_id' => $po2->id,
            'gr_number' => 'GR-3WAY-002-02',
            'gr_date' => $todayDate,
            'qty' => '100.0000',
            'uom' => 'SET',
            'unit_price' => '150000.00',
            'receipt_amount' => '15000000.00',
            'po_line_reference' => 'LN-BOLT-02',
            'description' => 'Baut & Mur HT M16 x 50mm batch 2',
            'status' => LocalGoodsReceipt::STATUS_AVAILABLE,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $finance->id,
            'updated_by' => $finance->id,
        ]);

        // Expected GR: 15.000.000. Invoice DPP: 15.000.050 (diff: +50)
        $inv2 = $submitService->submit(
            $supplierUser,
            [
                'invoice_number' => 'INV-3WAY-TOLERANCE-002',
                'invoice_date' => $todayDate,
                'local_purchase_order_id' => $po2->id,
                'goods_receipt_ids' => [$gr2_1->id],
                'invoice_amount' => 15000050.00,
                'tax_amount' => 1650005.50,
                'ppn_scheme' => '11%',
                'tax_invoice_number' => '010.001-26.33330002',
                'scheduled_physical_delivery_date' => $wedDate,
            ],
            $this->createDummyFiles('INV-3WAY-TOLERANCE-002', 2)
        );

        $signedUrl2 = URL::signedRoute('receipts.verify-supplier', [
            'receipt' => $inv2->receipt->receipt_number,
            'revision' => $inv2->revision_number,
        ]);
        $receiptService->recordReceipt($finance, $inv2, 'Dokumen fisik lengkap diterima kasir loket.', $signedUrl2);

        // ─────────────────────────────────────────────────────────────────────────
        // SKENARIO 3: VARIANCE_DETECTED (Terdapat Selisih GR - Masuk Dashboard Warning)
        // ─────────────────────────────────────────────────────────────────────────
        $po3 = LocalPurchaseOrder::create([
            'po_number' => 'PO-LOC-3WAY-003',
            'supplier_id' => $supplierUser->id,
            'total_amount' => '40000000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'po_date' => $todayDate,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'description' => 'Pengadaan Oli Pelumas Hidrolik (Demo 3-Way Variance Detected)',
            'created_by' => $finance->id,
        ]);

        $gr3_1 = LocalGoodsReceipt::create([
            'local_purchase_order_id' => $po3->id,
            'gr_number' => 'GR-3WAY-003-01',
            'gr_date' => $todayDate,
            'qty' => '5.0000',
            'uom' => 'DRM',
            'unit_price' => '4000000.00',
            'receipt_amount' => '20000000.00',
            'po_line_reference' => 'LN-OIL-01',
            'description' => 'Oli Mesin Hidrolik ISO VG 68 drum batch 1',
            'status' => LocalGoodsReceipt::STATUS_AVAILABLE,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $finance->id,
            'updated_by' => $finance->id,
        ]);

        LocalGoodsReceipt::create([
            'local_purchase_order_id' => $po3->id,
            'gr_number' => 'GR-3WAY-003-02',
            'gr_date' => $todayDate,
            'qty' => '5.0000',
            'uom' => 'DRM',
            'unit_price' => '4000000.00',
            'receipt_amount' => '20000000.00',
            'po_line_reference' => 'LN-OIL-02',
            'description' => 'Oli Mesin Hidrolik ISO VG 68 drum batch 2',
            'status' => LocalGoodsReceipt::STATUS_AVAILABLE,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $finance->id,
            'updated_by' => $finance->id,
        ]);

        // Expected GR: 20.000.000. Invoice DPP: 22.500.000 (diff: +2.500.000)
        $inv3 = $submitService->submit(
            $supplierUser,
            [
                'invoice_number' => 'INV-3WAY-VARIANCE-003',
                'invoice_date' => $todayDate,
                'local_purchase_order_id' => $po3->id,
                'goods_receipt_ids' => [$gr3_1->id],
                'invoice_amount' => 22500000.00,
                'tax_amount' => 2475000.00,
                'ppn_scheme' => '11%',
                'tax_invoice_number' => '010.001-26.33330003',
                'scheduled_physical_delivery_date' => $wedDate,
            ],
            $this->createDummyFiles('INV-3WAY-VARIANCE-003', 3)
        );

        $signedUrl3 = URL::signedRoute('receipts.verify-supplier', [
            'receipt' => $inv3->receipt->receipt_number,
            'revision' => $inv3->revision_number,
        ]);
        $receiptService->recordReceipt($finance, $inv3, 'Dokumen fisik diterima kasir dengan catatan selisih.', $signedUrl3);

        // ─────────────────────────────────────────────────────────────────────────
        // SKENARIO 4: PO_CEILING_ONLY (Berdasarkan Nilai PO - Data GR Legacy Tanpa Harga)
        // ─────────────────────────────────────────────────────────────────────────
        $po4 = LocalPurchaseOrder::create([
            'po_number' => 'PO-LOC-3WAY-004',
            'supplier_id' => $supplierUser->id,
            'total_amount' => '15000000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'po_date' => $todayDate,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'description' => 'Pengadaan Kawat Las E7018 (Demo 3-Way Legacy PO Ceiling Only)',
            'created_by' => $finance->id,
        ]);

        $gr4_1 = LocalGoodsReceipt::create([
            'local_purchase_order_id' => $po4->id,
            'gr_number' => 'GR-3WAY-004-01',
            'gr_date' => $todayDate,
            'qty' => '10.0000',
            'uom' => 'DUS',
            'unit_price' => null,
            'receipt_amount' => null,
            'po_line_reference' => null,
            'description' => 'Kawat Las E7018 LB-52 3.2mm Dus 20kg (Legacy import tanpa unit price)',
            'status' => LocalGoodsReceipt::STATUS_AVAILABLE,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $finance->id,
            'updated_by' => $finance->id,
        ]);

        // Invoice under legacy GR: evaluates against PO ceiling only
        $inv4 = $submitService->submit(
            $supplierUser,
            [
                'invoice_number' => 'INV-3WAY-CEILING-004',
                'invoice_date' => $todayDate,
                'local_purchase_order_id' => $po4->id,
                'goods_receipt_ids' => [$gr4_1->id],
                'invoice_amount' => 6000000.00,
                'tax_amount' => 660000.00,
                'ppn_scheme' => '11%',
                'tax_invoice_number' => '010.001-26.33330004',
                'scheduled_physical_delivery_date' => $wedDate,
            ],
            $this->createDummyFiles('INV-3WAY-CEILING-004', 4)
        );

        $signedUrl4 = URL::signedRoute('receipts.verify-supplier', [
            'receipt' => $inv4->receipt->receipt_number,
            'revision' => $inv4->revision_number,
        ]);
        $receiptService->recordReceipt($finance, $inv4, 'Dokumen fisik diterima kasir.', $signedUrl4);

        // ─────────────────────────────────────────────────────────────────────────
        // SKENARIO 5: PO TERSEDIA UNTUK DICOBA LIVE OLEH USER (Interactive Demo)
        // ─────────────────────────────────────────────────────────────────────────
        $po5 = LocalPurchaseOrder::create([
            'po_number' => 'PO-LOC-3WAY-005',
            'supplier_id' => $supplierUser->id,
            'total_amount' => '25000000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'po_date' => $todayDate,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'description' => 'Pengadaan Cutting Tool End Mill Carbide (Siap Dicoba di Form Pengajuan Invoice)',
            'created_by' => $finance->id,
        ]);

        LocalGoodsReceipt::create([
            'local_purchase_order_id' => $po5->id,
            'gr_number' => 'GR-3WAY-005-01',
            'gr_date' => $todayDate,
            'qty' => '20.0000',
            'uom' => 'PCS',
            'unit_price' => '500000.00',
            'receipt_amount' => '10000000.00',
            'po_line_reference' => 'LN-TOOL-01',
            'description' => 'End Mill Carbide 4 Flute D10 (Siap ditagihkan)',
            'status' => LocalGoodsReceipt::STATUS_AVAILABLE,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $finance->id,
            'updated_by' => $finance->id,
        ]);

        LocalGoodsReceipt::create([
            'local_purchase_order_id' => $po5->id,
            'gr_number' => 'GR-3WAY-005-02',
            'gr_date' => $todayDate,
            'qty' => '30.0000',
            'uom' => 'PCS',
            'unit_price' => '500000.00',
            'receipt_amount' => '15000000.00',
            'po_line_reference' => 'LN-TOOL-02',
            'description' => 'End Mill Carbide 4 Flute D12 (Siap ditagihkan)',
            'status' => LocalGoodsReceipt::STATUS_AVAILABLE,
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $finance->id,
            'updated_by' => $finance->id,
        ]);

        $this->command?->info('3-Way Match demo showcase seeded successfully:');
        $this->command?->line('  - PO-LOC-3WAY-001 -> INV-3WAY-EXACT-001     (Status: EXACT_MATCH)');
        $this->command?->line('  - PO-LOC-3WAY-002 -> INV-3WAY-TOLERANCE-002 (Status: WITHIN_TOLERANCE)');
        $this->command?->line('  - PO-LOC-3WAY-003 -> INV-3WAY-VARIANCE-003  (Status: VARIANCE_DETECTED, Dashboard Exception)');
        $this->command?->line('  - PO-LOC-3WAY-004 -> INV-3WAY-CEILING-004   (Status: PO_CEILING_ONLY)');
        $this->command?->line('  - PO-LOC-3WAY-005 -> Available for user to try in "Buat Invoice Baru"');
    }

    private function cleanExistingDemoRecords(): void
    {
        $demoPoNumbers = [
            'PO-LOC-3WAY-001',
            'PO-LOC-3WAY-002',
            'PO-LOC-3WAY-003',
            'PO-LOC-3WAY-004',
            'PO-LOC-3WAY-005',
        ];

        $demoInvoiceNumbers = [
            'INV-3WAY-EXACT-001',
            'INV-3WAY-TOLERANCE-002',
            'INV-3WAY-VARIANCE-003',
            'INV-3WAY-CEILING-004',
        ];

        // 1. Detach current_invoice_id from any GRs referencing demo invoices or demo POs
        LocalGoodsReceipt::whereIn('local_purchase_order_id', function ($query) use ($demoPoNumbers) {
            $query->select('id')->from('local_purchase_orders')->whereIn('po_number', $demoPoNumbers);
        })->update(['current_invoice_id' => null]);

        LocalGoodsReceipt::whereIn('current_invoice_id', function ($query) use ($demoInvoiceNumbers) {
            $query->select('id')->from('local_invoices')->whereIn('invoice_number', $demoInvoiceNumbers);
        })->update(['current_invoice_id' => null]);

        // 2. Delete invoice records and associated child rows
        $invoices = LocalInvoice::whereIn('invoice_number', $demoInvoiceNumbers)
            ->orWhere(function ($q) use ($demoPoNumbers) {
                $q->whereHas('localPurchaseOrder', fn ($poQ) => $poQ->whereIn('po_number', $demoPoNumbers));
            })
            ->get();

        foreach ($invoices as $inv) {
            $inv->goodsReceiptHistories()->delete();
            $inv->documents()->delete();
            $inv->revisions()->delete();
            $inv->receipt()->delete();
            $inv->physicalVerifications()->delete();
            $inv->verifications()->delete();
            $inv->statusHistories()->delete();
            $inv->delete();
        }

        // 3. Delete demo GRs and demo POs
        $pos = LocalPurchaseOrder::whereIn('po_number', $demoPoNumbers)->get();
        foreach ($pos as $po) {
            $po->goodsReceipts()->delete();
            $po->delete();
        }
    }

    private function createDummyFiles(string $invoiceNumber, int $suffix): array
    {
        $pdfContent = $this->validPdfContent("ADASI 3-Way Match Demo Invoice: {$invoiceNumber}");

        $tmpInv = tempnam(sys_get_temp_dir(), 'inv_demo_'.$suffix);
        file_put_contents($tmpInv, $pdfContent);
        $tmpTax = tempnam(sys_get_temp_dir(), 'tax_demo_'.$suffix);
        file_put_contents($tmpTax, $pdfContent);
        $tmpPo = tempnam(sys_get_temp_dir(), 'po_demo_'.$suffix);
        file_put_contents($tmpPo, $pdfContent);
        $tmpGr = tempnam(sys_get_temp_dir(), 'gr_demo_'.$suffix);
        file_put_contents($tmpGr, $pdfContent);
        $tmpSj = tempnam(sys_get_temp_dir(), 'sj_demo_'.$suffix);
        file_put_contents($tmpSj, $pdfContent);

        return [
            'invoice' => new UploadedFile($tmpInv, "{$invoiceNumber}.pdf", 'application/pdf', null, true),
            'tax_invoice' => new UploadedFile($tmpTax, "tax_invoice_{$suffix}.pdf", 'application/pdf', null, true),
            'purchase_order' => new UploadedFile($tmpPo, "purchase_order_{$suffix}.pdf", 'application/pdf', null, true),
            'goods_receipt' => [new UploadedFile($tmpGr, "goods_receipt_{$suffix}.pdf", 'application/pdf', null, true)],
            'delivery_note' => new UploadedFile($tmpSj, "surat_jalan_{$suffix}.pdf", 'application/pdf', null, true),
        ];
    }

    private function validPdfContent(string $text = 'ADASI demo document'): string
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $content = "BT /F1 12 Tf 20 100 Td ($text) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($content).">>\nstream\n$content\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n$object\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}
