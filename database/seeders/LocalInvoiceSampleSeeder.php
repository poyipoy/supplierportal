<?php

namespace Database\Seeders;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalInvoiceVerification;
use App\Models\LocalPurchaseOrder;
use App\Models\PaymentBatch;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\InvoicePhysicalReceiptService;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\LocalInvoice\InvoiceVerificationService;
use App\Services\Payment\LocalInvoiceVoucherService;
use App\Services\Payment\PaymentBatchService;
use App\Services\Payment\PaymentExecutionService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class LocalInvoiceSampleSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Seeding Local Suppliers, Users, and Invoices...');

        // 1. Finance & GA Users
        $finance = User::firstOrCreate(
            ['email' => 'finance@adasi.com'],
            [
                'name' => 'Finance & Accounting ADASI',
                'password' => Hash::make('password'),
                'role' => 'finance',
                'is_active' => true,
            ]
        );

        $ga = User::firstOrCreate(
            ['email' => 'ga@adasi.com'],
            [
                'name' => 'General Affairs ADASI',
                'password' => Hash::make('password'),
                'role' => 'ga',
                'is_active' => true,
            ]
        );

        // 2. Local Suppliers
        $supplier1 = User::firstOrCreate(
            ['email' => 'supplier.local@adasi.com'],
            [
                'name' => 'PT. Adasi Baja Perkasa',
                'password' => Hash::make('password'),
                'role' => 'supplier',
                'is_active' => true,
            ]
        );
        SupplierScope::firstOrCreate(['supplier_id' => $supplier1->id, 'scope' => 'local']);
        Supplier::updateOrCreate(
            ['user_id' => $supplier1->id],
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
            ['supplier_id' => $supplier1->id, 'account_number' => '8730129845'],
            [
                'bank_name' => 'BCA',
                'account_holder_name' => 'PT ADASI BAJA PERKASA',
                'status' => 'VERIFIED',
                'verified_by' => $finance->id,
                'verified_at' => now(),
                'activated_at' => now(),
            ]
        );

        $supplier2 = User::firstOrCreate(
            ['email' => 'supplier.local2@adasi.com'],
            [
                'name' => 'PT. Sinar Metalindo Mandiri',
                'password' => Hash::make('password'),
                'role' => 'supplier',
                'is_active' => true,
            ]
        );
        SupplierScope::firstOrCreate(['supplier_id' => $supplier2->id, 'scope' => 'local']);
        Supplier::updateOrCreate(
            ['user_id' => $supplier2->id],
            [
                'company_name' => 'PT. Sinar Metalindo Mandiri',
                'address' => 'Jl. Narogong Km 14 No. 88, Cileungsi, Bogor',
                'phone' => '021-82495555',
                'npwp' => '02.456.789.0-412.000',
                'category' => 'Barang',
                'vendor_category' => 'Barang',
                'is_pkp' => true,
                'payment_term_days' => 45,
            ]
        );
        SupplierBankAccount::firstOrCreate(
            ['supplier_id' => $supplier2->id, 'account_number' => '1330018923456'],
            [
                'bank_name' => 'Mandiri',
                'account_holder_name' => 'PT SINAR METALINDO MANDIRI',
                'status' => 'VERIFIED',
                'verified_by' => $finance->id,
                'verified_at' => now(),
                'activated_at' => now(),
            ]
        );

        // 3. Ensure Local POs and GRs
        $this->call(LocalProcurementDummySeeder::class);

        // Assign POs 007 - 010 to Supplier 2 so both suppliers have active POs
        LocalPurchaseOrder::whereIn('po_number', [
            'PO-LOC-2026-007',
            'PO-LOC-2026-008',
            'PO-LOC-2026-009',
            'PO-LOC-2026-010',
        ])->update(['supplier_id' => $supplier2->id]);

        // 4. Safely clean existing test invoices & batches (idempotent re-seed)
        $this->cleanExistingLocalInvoices();

        // 5. Build Sample Invoices
        $this->seedInvoices($supplier1, $supplier2, $finance);

        $this->command?->info('Local Suppliers, Users, and Invoices seeded successfully.');
    }

    private function cleanExistingLocalInvoices(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('local_invoice_payment_transfers')->truncate();
        DB::table('supplier_overpayment_refunds')->truncate();
        DB::table('local_invoice_payments')->truncate();
        DB::table('local_invoice_vouchers')->truncate();
        DB::table('payment_items')->truncate();
        DB::table('payment_groups')->truncate();
        DB::table('payment_batches')->truncate();
        DB::table('payment_voucher_sequences')->truncate();
        DB::table('local_invoice_goods_receipts')->truncate();
        DB::table('local_invoice_physical_verifications')->truncate();
        DB::table('local_invoice_verifications')->truncate();
        DB::table('local_invoice_receipts')->truncate();
        DB::table('local_invoice_documents')->truncate();
        DB::table('local_invoice_revisions')->truncate();
        DB::table('local_invoice_status_histories')->truncate();
        DB::table('local_invoices')->truncate();
        DB::table('local_invoice_sequences')->truncate();

        // Reset GR status to AVAILABLE
        DB::table('local_goods_receipts')->update([
            'status' => LocalGoodsReceipt::STATUS_AVAILABLE,
            'current_invoice_id' => null,
        ]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function seedInvoices(User $supplier1, User $supplier2, User $finance): void
    {
        $submitService = app(InvoiceSubmissionService::class);
        $receiptService = app(InvoicePhysicalReceiptService::class);
        $verifyService = app(InvoiceVerificationService::class);
        $batchService = app(PaymentBatchService::class);
        $voucherService = app(LocalInvoiceVoucherService::class);
        $execService = app(PaymentExecutionService::class);

        $nextWed = Carbon::now();
        while ($nextWed->dayOfWeek !== Carbon::WEDNESDAY) {
            $nextWed->addDay();
        }
        $wedDate = $nextWed->toDateString();

        // ─────────────────────────────────────────────────────────────
        // INVOICE 1 (Supplier 1): WAITING_PHYSICAL_DOCUMENT
        // ─────────────────────────────────────────────────────────────
        $po1 = LocalPurchaseOrder::where('po_number', 'PO-LOC-2026-001')->firstOrFail();
        $gr1_1 = LocalGoodsReceipt::where('gr_number', 'GR-LOC-2026-001-01')->firstOrFail();

        $submitService->submit(
            $supplier1,
            [
                'invoice_number' => 'INV/ABP/2026/09/001',
                'invoice_date' => '2026-09-18',
                'local_purchase_order_id' => $po1->id,
                'goods_receipt_ids' => [$gr1_1->id],
                'invoice_amount' => (float) $gr1_1->received_amount,
                'tax_amount' => round((float) $gr1_1->received_amount * 0.11, 2),
                'ppn_scheme' => '11%',
                'tax_invoice_number' => '010.001-26.12345671',
                'scheduled_physical_delivery_date' => $wedDate,
            ],
            $this->createDummyFiles('001')
        );

        // ─────────────────────────────────────────────────────────────
        // INVOICE 2 (Supplier 1): UNDER_VERIFICATION
        // ─────────────────────────────────────────────────────────────
        $gr1_2 = LocalGoodsReceipt::where('gr_number', 'GR-LOC-2026-001-02')->firstOrFail();
        $gr1_3 = LocalGoodsReceipt::where('gr_number', 'GR-LOC-2026-001-03')->firstOrFail();
        $dpp2 = (float) $gr1_2->received_amount + (float) $gr1_3->received_amount;

        $inv2 = $submitService->submit(
            $supplier1,
            [
                'invoice_number' => 'INV/ABP/2026/09/002',
                'invoice_date' => '2026-09-15',
                'local_purchase_order_id' => $po1->id,
                'goods_receipt_ids' => [$gr1_2->id, $gr1_3->id],
                'invoice_amount' => $dpp2,
                'tax_amount' => round($dpp2 * 0.11, 2),
                'ppn_scheme' => '11%',
                'tax_invoice_number' => '010.001-26.12345672',
                'scheduled_physical_delivery_date' => $wedDate,
            ],
            $this->createDummyFiles('002')
        );

        // Physical receipt by cashier 2 days ago
        $receiptService->recordReceipt($finance, $inv2, 'Dokumen fisik diterima kasir di loket.');
        $verifyService->verifySectionA($inv2, [
            'invoice_check' => LocalInvoiceVerification::CHECK_OK,
            'tax_invoice_check' => LocalInvoiceVerification::CHECK_OK,
            'po_check' => LocalInvoiceVerification::CHECK_OK,
            'delivery_note_check' => LocalInvoiceVerification::CHECK_OK,
            'gr_check' => LocalInvoiceVerification::CHECK_OK,
        ], $finance);

        // ─────────────────────────────────────────────────────────────
        // INVOICE 3 (Supplier 1): NEED_REVISION
        // ─────────────────────────────────────────────────────────────
        $po2 = LocalPurchaseOrder::where('po_number', 'PO-LOC-2026-002')->firstOrFail();
        $gr2_1 = LocalGoodsReceipt::where('gr_number', 'GR-LOC-2026-002-01')->firstOrFail();

        $inv3 = $submitService->submit(
            $supplier1,
            [
                'invoice_number' => 'INV/ABP/2026/09/003',
                'invoice_date' => '2026-09-12',
                'local_purchase_order_id' => $po2->id,
                'goods_receipt_ids' => [$gr2_1->id],
                'invoice_amount' => (float) $gr2_1->received_amount,
                'tax_amount' => round((float) $gr2_1->received_amount * 0.11, 2),
                'ppn_scheme' => '11%',
                'tax_invoice_number' => '010.001-26.12345673',
                'scheduled_physical_delivery_date' => $wedDate,
            ],
            $this->createDummyFiles('003')
        );

        $receiptService->recordReceipt($finance, $inv3, 'Dokumen fisik diterima kasir.');
        $verifyService->requestRevision(
            $inv3,
            'Nomor e-Faktur belum terdaftar atau barcode e-Faktur tidak dapat dipindai. Harap periksa dan upload ulang faktur pajak valid.',
            $finance
        );

        // ─────────────────────────────────────────────────────────────
        // INVOICE 4 (Supplier 1): READY_TO_PAY (Unscheduled)
        // ─────────────────────────────────────────────────────────────
        $gr2_2 = LocalGoodsReceipt::where('gr_number', 'GR-LOC-2026-002-02')->firstOrFail();
        $gr2_3 = LocalGoodsReceipt::where('gr_number', 'GR-LOC-2026-002-03')->firstOrFail();
        $dpp4 = (float) $gr2_2->received_amount + (float) $gr2_3->received_amount;

        $inv4 = $submitService->submit(
            $supplier1,
            [
                'invoice_number' => 'INV/ABP/2026/09/004',
                'invoice_date' => '2026-09-10',
                'local_purchase_order_id' => $po2->id,
                'goods_receipt_ids' => [$gr2_2->id, $gr2_3->id],
                'invoice_amount' => $dpp4,
                'tax_amount' => round($dpp4 * 0.11, 2),
                'ppn_scheme' => '11%',
                'tax_invoice_number' => '010.001-26.12345674',
                'scheduled_physical_delivery_date' => $wedDate,
            ],
            $this->createDummyFiles('004')
        );

        $receiptService->recordReceipt($finance, $inv4, 'Dokumen fisik lengkap dan sesuai.');
        $verifyService->verifySectionA($inv4, [
            'invoice_check' => LocalInvoiceVerification::CHECK_OK,
            'tax_invoice_check' => LocalInvoiceVerification::CHECK_OK,
            'po_check' => LocalInvoiceVerification::CHECK_OK,
            'delivery_note_check' => LocalInvoiceVerification::CHECK_OK,
            'gr_check' => LocalInvoiceVerification::CHECK_OK,
        ], $finance);
        $verifyService->verifySectionB($inv4, [
            'ppn_compliance' => LocalInvoiceVerification::PPN_SESUAI,
            'verified_ppn' => $inv4->tax_amount,
            'pph_23_applicable' => false,
            'pph_4_2_applicable' => false,
            'pph_21_applicable' => false,
        ], $finance);
        $verifyService->lockAndApprove($inv4, $finance);

        // ─────────────────────────────────────────────────────────────
        // INVOICE 5 (Supplier 1): READY_TO_PAY (In Draft DRP Batch)
        // ─────────────────────────────────────────────────────────────
        $po3 = LocalPurchaseOrder::where('po_number', 'PO-LOC-2026-003')->firstOrFail();
        $gr3_1 = LocalGoodsReceipt::where('gr_number', 'GR-LOC-2026-003-01')->firstOrFail();
        $gr3_2 = LocalGoodsReceipt::where('gr_number', 'GR-LOC-2026-003-02')->firstOrFail();
        $dpp5 = (float) $gr3_1->received_amount + (float) $gr3_2->received_amount;

        $inv5 = $submitService->submit(
            $supplier1,
            [
                'invoice_number' => 'INV/ABP/2026/09/005',
                'invoice_date' => '2026-09-08',
                'local_purchase_order_id' => $po3->id,
                'goods_receipt_ids' => [$gr3_1->id, $gr3_2->id],
                'invoice_amount' => $dpp5,
                'tax_amount' => round($dpp5 * 0.11, 2),
                'ppn_scheme' => '11%',
                'tax_invoice_number' => '010.001-26.12345675',
                'scheduled_physical_delivery_date' => $wedDate,
            ],
            $this->createDummyFiles('005')
        );

        $receiptService->recordReceipt($finance, $inv5, 'Dokumen fisik lengkap.');
        $verifyService->verifySectionA($inv5, [
            'invoice_check' => LocalInvoiceVerification::CHECK_OK,
            'tax_invoice_check' => LocalInvoiceVerification::CHECK_OK,
            'po_check' => LocalInvoiceVerification::CHECK_OK,
            'delivery_note_check' => LocalInvoiceVerification::CHECK_OK,
            'gr_check' => LocalInvoiceVerification::CHECK_OK,
        ], $finance);
        $verifyService->verifySectionB($inv5, [
            'ppn_compliance' => LocalInvoiceVerification::PPN_SESUAI,
            'verified_ppn' => $inv5->tax_amount,
            'pph_23_applicable' => false,
            'pph_4_2_applicable' => false,
            'pph_21_applicable' => false,
        ], $finance);
        $verifyService->lockAndApprove($inv5, $finance);

        // Put invoice 5 into a Draft DRP Batch
        $batchService->createSupplierBatch($finance, [$inv5->id], 'Batch Pembayaran Reguler Supplier Lokal - Minggu 3 September');

        // ─────────────────────────────────────────────────────────────
        // INVOICE 6 (Supplier 1): PAID (Settled via DRP Paid)
        // ─────────────────────────────────────────────────────────────
        $po4 = LocalPurchaseOrder::where('po_number', 'PO-LOC-2026-004')->firstOrFail();
        $gr4_1 = LocalGoodsReceipt::where('gr_number', 'GR-LOC-2026-004-01')->firstOrFail();

        $inv6 = $submitService->submit(
            $supplier1,
            [
                'invoice_number' => 'INV/ABP/2026/09/006',
                'invoice_date' => '2026-08-25',
                'local_purchase_order_id' => $po4->id,
                'goods_receipt_ids' => [$gr4_1->id],
                'invoice_amount' => (float) $gr4_1->received_amount,
                'tax_amount' => round((float) $gr4_1->received_amount * 0.11, 2),
                'ppn_scheme' => '11%',
                'tax_invoice_number' => '010.001-26.12345676',
                'scheduled_physical_delivery_date' => $wedDate,
            ],
            $this->createDummyFiles('006')
        );

        $receiptService->recordReceipt($finance, $inv6, 'Dokumen fisik lengkap.');
        $verifyService->verifySectionA($inv6, [
            'invoice_check' => LocalInvoiceVerification::CHECK_OK,
            'tax_invoice_check' => LocalInvoiceVerification::CHECK_OK,
            'po_check' => LocalInvoiceVerification::CHECK_OK,
            'delivery_note_check' => LocalInvoiceVerification::CHECK_OK,
            'gr_check' => LocalInvoiceVerification::CHECK_OK,
        ], $finance);
        $verifyService->verifySectionB($inv6, [
            'ppn_compliance' => LocalInvoiceVerification::PPN_SESUAI,
            'verified_ppn' => $inv6->tax_amount,
            'pph_23_applicable' => false,
            'pph_4_2_applicable' => false,
            'pph_21_applicable' => false,
        ], $finance);
        $verifyService->lockAndApprove($inv6, $finance);

        $paidBatch = $batchService->createSupplierBatch($finance, [$inv6->id], 'Batch Pembayaran Selesai - Minggu 1 September');
        $paidBatch = $batchService->finalizeBatch($paidBatch, $finance);

        foreach ($paidBatch->groups as $grp) {
            foreach ($grp->activeItems as $itm) {
                $voucherService->finalize($itm, [
                    'voucher_date' => '2026-09-02',
                    'payment_method' => 'BANK',
                    'remarks' => 'Pelunasan invoice pengadaan cutting tool end mill carbide',
                ], $finance);
            }
        }

        $execService->markEntireBatchPaid($paidBatch, [
            'transfer_reference' => 'TRF-BCA-20260902-7718',
            'transfer_date' => '2026-09-02',
            'payment_notes' => 'Pembayaran via BCA KlikBisnis berhasil diproses.',
        ], $finance);

        // ─────────────────────────────────────────────────────────────
        // INVOICE 7 (Supplier 2): WAITING_PHYSICAL_DOCUMENT
        // ─────────────────────────────────────────────────────────────
        $po7 = LocalPurchaseOrder::where('po_number', 'PO-LOC-2026-007')->firstOrFail();
        $gr7_1 = LocalGoodsReceipt::where('gr_number', 'GR-LOC-2026-007-01')->firstOrFail();

        $submitService->submit(
            $supplier2,
            [
                'invoice_number' => 'INV/SMM/2026/09/001',
                'invoice_date' => '2026-09-20',
                'local_purchase_order_id' => $po7->id,
                'goods_receipt_ids' => [$gr7_1->id],
                'invoice_amount' => (float) $gr7_1->received_amount,
                'tax_amount' => round((float) $gr7_1->received_amount * 0.11, 2),
                'ppn_scheme' => '11%',
                'tax_invoice_number' => '010.002-26.98765431',
                'scheduled_physical_delivery_date' => $wedDate,
            ],
            $this->createDummyFiles('007')
        );

        // ─────────────────────────────────────────────────────────────
        // INVOICE 8 (Supplier 2): READY_TO_PAY
        // ─────────────────────────────────────────────────────────────
        $po8 = LocalPurchaseOrder::where('po_number', 'PO-LOC-2026-008')->firstOrFail();
        $gr8_1 = LocalGoodsReceipt::where('gr_number', 'GR-LOC-2026-008-01')->firstOrFail();

        $inv8 = $submitService->submit(
            $supplier2,
            [
                'invoice_number' => 'INV/SMM/2026/09/002',
                'invoice_date' => '2026-09-14',
                'local_purchase_order_id' => $po8->id,
                'goods_receipt_ids' => [$gr8_1->id],
                'invoice_amount' => (float) $gr8_1->received_amount,
                'tax_amount' => round((float) $gr8_1->received_amount * 0.11, 2),
                'ppn_scheme' => '11%',
                'tax_invoice_number' => '010.002-26.98765432',
                'scheduled_physical_delivery_date' => $wedDate,
            ],
            $this->createDummyFiles('008')
        );

        $receiptService->recordReceipt($finance, $inv8, 'Dokumen fisik lengkap diterima kasir.');
        $verifyService->verifySectionA($inv8, [
            'invoice_check' => LocalInvoiceVerification::CHECK_OK,
            'tax_invoice_check' => LocalInvoiceVerification::CHECK_OK,
            'po_check' => LocalInvoiceVerification::CHECK_OK,
            'delivery_note_check' => LocalInvoiceVerification::CHECK_OK,
            'gr_check' => LocalInvoiceVerification::CHECK_OK,
        ], $finance);
        $verifyService->verifySectionB($inv8, [
            'ppn_compliance' => LocalInvoiceVerification::PPN_SESUAI,
            'verified_ppn' => $inv8->tax_amount,
            'pph_23_applicable' => false,
            'pph_4_2_applicable' => false,
            'pph_21_applicable' => false,
        ], $finance);
        $verifyService->lockAndApprove($inv8, $finance);
    }

    private function createDummyFiles(string $suffix): array
    {
        $pdfContent = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";

        $tmpInv = tempnam(sys_get_temp_dir(), 'inv_'.$suffix);
        file_put_contents($tmpInv, $pdfContent);
        $tmpTax = tempnam(sys_get_temp_dir(), 'tax_'.$suffix);
        file_put_contents($tmpTax, $pdfContent);
        $tmpSj = tempnam(sys_get_temp_dir(), 'sj_'.$suffix);
        file_put_contents($tmpSj, $pdfContent);

        return [
            'invoice' => new UploadedFile($tmpInv, "invoice_{$suffix}.pdf", 'application/pdf', null, true),
            'tax_invoice' => new UploadedFile($tmpTax, "tax_invoice_{$suffix}.pdf", 'application/pdf', null, true),
            'delivery_note' => new UploadedFile($tmpSj, "surat_jalan_{$suffix}.pdf", 'application/pdf', null, true),
        ];
    }
}
