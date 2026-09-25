# Implementation Plan: Hapus Seluruh Konteks Amount pada Goods Receipt (GR)

**Domain:** Core 2 — Local Supplier & Disbursement  
**Scope:** Eliminasi permanen seluruh atribut, skema database, validasi, dan tampilan UI terkait Amount/Nominal pada Goods Receipt (GR).

---

## 1. Background & Architecture Decision
Infor ERP `whinh` adalah export data level penerimaan barang fisik yang hanya mencatat kuantitas fisik (`qty`), bukan nilai moneter. Sebelumnya, sistem mempertahankan kolom `received_amount` pada `local_goods_receipts` dan `gr_amount_snapshot` pada `local_invoice_goods_receipts`.

Berdasarkan hasil klarifikasi desain:
1. **Database Schema:** Kolom `received_amount` pada `local_goods_receipts` dan `gr_amount_snapshot` pada `local_invoice_goods_receipts` akan di-drop secara permanen via migrasi database.
2. **Invoice Financial Boundary:** Invoice DPP diinput manual oleh supplier dan divalidasi terhadap sisa pagu finansial PO ($PO\ Total\ Amount - \sum Active\ Invoices$). GR yang dipilih divalidasi harus milik PO tersebut dan berstatus `AVAILABLE`.
3. **Master PO & GR Displays:** Kolom "Realisasi GR" pada daftar PO menampilkan akumulasi kuantitas (Qty) dan jumlah dokumen GR (mis. `500 unit (3 GR)`). Progress bar finansial pada detail PO dialihkan untuk membandingkan Realisasi Invoice vs Nilai PO (Rp).
4. **Supplier Invoice Form UI:** Sidebar summary form invoice menampilkan Dokumen GR Terpilih (Kuantitas & Jumlah GR), Plafon Sisa PO (Rp), DPP Invoice (input manual), PPN, dan Total Tagihan, dengan pengecekan realtime bahwa DPP tidak melampaui sisa pagu PO.
5. **Manual GR Management:** Modal tambah dan edit GR manual di sisi Finance/Purchasing tidak lagi memiliki input nominal/amount, melainkan hanya nomor GR, tanggal, kuantitas (qty), keterangan, dan catatan.

---

## 2. Implementation Steps

### Phase 1: Database Migration
1. Buat migrasi: `database/migrations/2026_09_24_160000_drop_received_amount_from_local_goods_receipts_tables.php`.
   - `Schema::table('local_goods_receipts', fn (Blueprint $table) => $table->dropColumn('received_amount'));`
   - `Schema::table('local_invoice_goods_receipts', fn (Blueprint $table) => $table->dropColumn('gr_amount_snapshot'));`
   - Siapkan rollback method (`down()`) yang mengembalikan kolom sebagai nullable decimal.

### Phase 2: Eloquent Models
1. **`App\Models\LocalGoodsReceipt`**:
   - Hapus `'received_amount'` dari array `$fillable`.
   - Hapus `'received_amount' => 'decimal:2'` dari array `$casts`.
2. **`App\Models\LocalInvoiceGoodsReceipt`**:
   - Hapus `'gr_amount_snapshot'` dari array `$fillable`.
   - Hapus `'gr_amount_snapshot' => 'decimal:2'` dari array `$casts`.
   - Pertahankan `'gr_qty_snapshot' => 'decimal:4'`.

### Phase 3: Domain Services & Requests
1. **`SaveLocalGoodsReceiptRequest`**:
   - Hapus aturan validasi `'received_amount'`.
2. **`LocalProcurementMasterService`**:
   - Hapus pembacaan dan parsing `$data['received_amount']`.
   - Hapus method `assertCap` (pembatasan plafon moneter GR terhadap PO tidak lagi relevan karena GR tidak bermata uang).
   - Pastikan `createGoodsReceipt` dan `updateGoodsReceipt` hanya mengelola `gr_number`, `gr_date`, `qty`, `description`, `notes`.
3. **`LocalGrReservationService`**:
   - Hapus seluruh method/logic `sumAmounts` dan percabangan `$hasAllAmounts`.
   - Terapkan validasi tunggal: DPP Invoice (`invoice_amount`) tidak boleh melebihi sisa pagu PO:
     $$\text{DPP} \le \text{PO Total Amount} - \sum \text{Invoice Aktif Lainnya}$$
   - Dalam snapshot `LocalInvoiceGoodsReceipt::create()`, simpan `gr_number_snapshot` dan `gr_qty_snapshot` (tanpa `gr_amount_snapshot`).
4. **`LocalPoReferenceService` & `InvoiceController`**:
   - Hapus pemanggilan `received_amount` pada response JSON pencarian PO dan detail PO.
   - Sediakan informasi `qty` dan `gr_count` untuk kebutuhan UI.
5. **`ReconcileLocalSupplierInvoices`**:
   - Bersihkan pengecekan anomali rekonsiliasi yang sebelumnya membandingkan `gr_amount_snapshot`.

### Phase 4: UI / Blade Views
1. **`resources/views/finance/local-procurement/index.blade.php`**:
   - Kolom "Realisasi GR": Ganti nilai nominal rupiah dengan akumulasi kuantitas (Qty) & jumlah dokumen GR.
2. **`resources/views/finance/local-procurement/show.blade.php`**:
   - KPI Card Header: Tampilkan "Total Kuantitas Diterima" & "Dokumen GR".
   - Progress Bar: Ganti menjadi "Realisasi Invoice terhadap Nilai PO" ($Total\ Invoiced / PO\ Total \times 100\%$).
   - Tabel GR: Hapus kolom "Nominal (Rp)". Pertahankan No. GR, Tanggal, Kuantitas, Keterangan, Status, Aksi.
   - Modal Tambah & Edit GR: Hapus input `received_amount`.
3. **`resources/views/finance/local-procurement/form.blade.php`**:
   - Bersihkan perhitungan `$activeGrTotal` yang sebelumnya membaca `received_amount`.
4. **`resources/views/local-supplier/purchase-orders/show.blade.php`**:
   - Hapus kolom "Nominal" pada tabel GR PO di sisi supplier.
5. **`resources/views/local-invoices/form.blade.php`**:
   - Opsi GR pada searchable/multi-select menampilkan `No. GR · X unit/pcs · Tanggal: Y`.
   - Sidebar Summary Card:
     - Ganti "Total GR: Rp ..." dengan "Penerimaan Barang: X Dokumen (Total Y pcs)".
     - Tampilkan "Plafon Sisa PO: Rp ...".
     - Validasi realtime: Bila DPP input > Sisa Plafon PO, tampilkan peringatan selisih over-ceiling.
     - PPN dan Grand Total dihitung dari DPP input manual supplier.

### Phase 5: Testing & Verification
1. Jalankan migrasi: `php artisan migrate`.
2. Perbarui test suite:
   - `tests/Feature/LocalInvoice/LocalGoodsReceiptInformationTest.php`: Hapus input `received_amount` pada pembuatan/update GR.
   - `tests/Feature/LocalInvoice/LocalSupplierWholeGrSettlementTest.php`: Hapus referensi `received_amount`, sesuaikan assert ke kuantitas dan pagu PO.
   - `tests/Feature/LocalInvoice/LocalInvoiceSubmissionTest.php`: Pastikan pembuatan invoice berbasis sisa pagu PO dan kuantitas GR teruji.
   - `tests/Feature/LocalInvoice/LocalGrImportTest.php` & `LocalPoImportTest.php`: Pastikan seluruh alur impor ERP tetap 100% hijau.
3. Jalankan seluruh test suite:
   - `php artisan test tests/Feature/LocalInvoice/`
   - `php artisan local-invoices:reconcile --json`
   - `vendor/bin/pint --test`
   - `npm run build`

---

## 3. Key Files Impacted
| File | Aksi | Deskripsi |
|---|---|---|
| `database/migrations/2026_09_24_160000_drop_received_amount_from_local_goods_receipts_tables.php` | Create | Migrasi drop kolom `received_amount` dan `gr_amount_snapshot` |
| `app/Models/LocalGoodsReceipt.php` | Modify | Hapus `received_amount` dari fillable & casts |
| `app/Models/LocalInvoiceGoodsReceipt.php` | Modify | Hapus `gr_amount_snapshot` dari fillable & casts |
| `app/Http/Requests/LocalInvoice/SaveLocalGoodsReceiptRequest.php` | Modify | Hapus validasi `received_amount` |
| `app/Services/LocalInvoice/LocalProcurementMasterService.php` | Modify | Hapus penanganan nominal GR dan `assertCap` |
| `app/Services/LocalInvoice/LocalGrReservationService.php` | Modify | Validasi invoice berdasarkan sisa pagu PO, hapus `sumAmounts` |
| `app/Services/LocalInvoice/LocalPoReferenceService.php` | Modify | Hapus agregasi `received_amount` pada pencarian PO |
| `app/Http/Controllers/LocalSupplier/InvoiceController.php` | Modify | Bersihkan data nominal GR pada controller |
| `app/Console/Commands/ReconcileLocalSupplierInvoices.php` | Modify | Bersihkan pemeriksaan anomali snapshot amount GR |
| `resources/views/finance/local-procurement/index.blade.php` | Modify | Kolom Realisasi GR berbasis Qty & dokumen |
| `resources/views/finance/local-procurement/show.blade.php` | Modify | Header KPI, progress bar invoice vs PO, tabel GR tanpa nominal, modal tanpa input nominal |
| `resources/views/finance/local-procurement/form.blade.php` | Modify | Bersihkan variabel `received_amount` |
| `resources/views/local-supplier/purchase-orders/show.blade.php` | Modify | Tabel GR tanpa kolom nominal |
| `resources/views/local-invoices/form.blade.php` | Modify | UI multi-select GR & Sidebar Summary berbasis Qty dan Plafon PO |
| `tests/Feature/LocalInvoice/*` | Modify | Penyesuaian test fixture dan assertion |
