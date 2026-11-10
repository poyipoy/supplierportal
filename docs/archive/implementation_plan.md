# Direct PO Generation from Quotation Detail

Memfasilitasi penerbitan Purchase Order (PO) langsung dari halaman **Detail Quotation** (`/purchasing/quotations/{id}`) melalui modal konfirmasi interaktif, yang secara atomik menetapkan item-level awards dan menerbitkan PO tanpa mewajibkan staf Purchasing membuka tabel komparasi antar supplier secara manual.

---

## User Review Required

> [!IMPORTANT]
> **Keputusan Desain Hasil Interview:**
> 1. **Alur Langsung via Modal**: Halaman Detail Quotation akan memiliki tombol utama **"Generate Purchase Order"** yang membuka modal konfirmasi langsung.
> 2. **Otomatisasi Status**: Aksi ini valid baik saat Quotation berstatus `submitted` maupun `accepted`. Jika masih `submitted`, sistem secara otomatis mengesahkan penawaran menjadi `accepted` ketika PO berhasil diterbitkan.
> 3. **Item Scope**: Modal menampilkan rincian item penawaran yang valid (tersedia & belum memiliki PO), total harga penawaran (dalam valuta asli & IDR), serta penanda transparan bila ada item yang dilewati (unavailable atau sudah memiliki PO sebelumnya).
> 4. **Tautan Sekunder**: Opsi komparasi lengkap tetap dipertahankan melalui tombol sekunder *"Open Inter-Supplier Comparison"* menuju `/purchasing/comparison/inter-supplier`.
> 5. **Post-Action Navigation**: Setelah PO berhasil dibuat, pengguna langsung di-redirect ke halaman detail Purchase Order baru (`/purchasing/purchase-orders/{id}`) dengan notifikasi sukses.

---

## Proposed Changes

### 1. Routing & Middleware Layer

#### [MODIFY] [routes/web.php](file:///c:/laragon/www/adasi_portal_supplier/routes/web.php)
* Daftarkan route baru di dalam grup `purchasing`:
  ```php
  Route::post('/quotations/{id}/generate-po', [QuotationListController::class, 'generatePo'])
      ->name('quotations.generate-po');
  ```
* Parameter `{id}` secara otomatis di-decode dari hashid oleh middleware `DecodeHashids` (karena `id` telah terdaftar di `HASHED_PARAM_KEYS`).

---

### 2. Controller & Backend Security Layer

#### [MODIFY] [app/Http/Controllers/Purchasing/QuotationListController.php](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Purchasing/QuotationListController.php)
* Tambahkan method `generatePo(Request $request, $id, PrItemAwardService $awardService, PurchaseOrderGenerationService $poService)`:
  * **Input Validation**:
    * `estimated_arrival`: `nullable|date`
    * `notes`: `nullable|string|max:1000`
  * **Domain Invariants & Concurrency Locks**:
    * Mengambil `$quotation` menggunakan `lockForUpdate()` dalam transaksi DB.
    * Memverifikasi:
      * Quotation belum expired (`!$quotation->isExpired()`).
      * PR berelasi belum `completed`.
      * Status quotation berada dalam `Quotation::AWARD_ELIGIBLE_STATUSES` (`submitted` atau `accepted`).
      * Memiliki item tersedia (`is_available = true`).
    * Memfilter item yang valid untuk di-award:
      * Mengambil semua item quotation yang `is_available = true`.
      * Memeriksa tabel `pr_item_awards` agar tidak menimpa item yang sudah memiliki PO aktif (`purchase_order_id !== null`).
      * Jika tidak ada item yang memenuhi syarat, batalkan dengan pesan error yang jelas.
    * Menjalankan `$awardService->awardBatch($lockedPr, $selections, auth()->user())`.
    * Menjalankan `$poService->generateFromAwards($awards, auth()->user(), [...])`.
    * Jika quotation berstatus `submitted`, `$poService` otomatis meng-update status quotation menjadi `accepted`.
  * **Redirect & Notification**:
    * Redirect ke `route('purchasing.purchase-orders.show', $po)` dengan flash alert sukses: `"Purchase Order {$po->po_number} successfully created from this quotation!"`.

---

### 3. Frontend / Blade UI Layer

#### [MODIFY] [resources/views/purchasing/quotations/show.blade.php](file:///c:/laragon/www/adasi_portal_supplier/resources/views/purchasing/quotations/show.blade.php)
* **Sidebar Actions**:
  * Ketika `$canCreatePo` bernilai `true`:
    * Tombol Utama (Primary):
      ```blade
      <x-ui.button type="button" size="sm" class="tw-w-full tw-mb-2" data-bs-toggle="modal" data-bs-target="#generatePoModal">
          <x-slot:leading><x-ui.icon name="receipt" /></x-slot:leading>
          Generate Purchase Order
      </x-ui.button>
      ```
    * Tombol Sekunder (Outline):
      ```blade
      <x-ui.button :href="\App\Support\PurchasingNavigation::toRoute('purchasing.comparison.show', $quotation->purchaseRequisition)" variant="outline" size="sm" class="tw-w-full tw-mb-2.5">
          <x-ui.icon name="chart-column" size="sm" />
          <span>Open Inter-Supplier Comparison</span>
      </x-ui.button>
      ```
* **Modal Konfirmasi `#generatePoModal`**:
  * Menggunakan modal Bootstrap 5 yang selaras dengan design system ADASI (`resources/views/components/ui/`).
  * **Modal Header**: Judul *"Generate Purchase Order"*, subjudul nomor PR dan nama supplier.
  * **Modal Body**:
    * Ringkasan dokumen: PR Number, Supplier, Mata Uang, Snapshot Kurs IDR.
    * Tabel ringkas item yang akan dimuat ke dalam PO:
      * Nama Material, Kuantitas, Berat Total, Harga/Kg, Jumlah Nominal.
      * Indikator jika ada item yang berstatus *Unavailable* (diberi badge error dan dikecualikan dari PO).
    * Total Nilai PO (dalam Valuta Asli & IDR).
    * Form inputs:
      * **Target Estimated Arrival Date**: input type `date` dengan default `now()->addDays(14)->format('Y-m-d')`.
      * **PO Notes / Remarks**: input text / textarea opsional untuk instruksi khusus.
  * **Modal Footer**:
    * Tombol *Batal* (`data-bs-dismiss="modal"`).
    * Tombol *Confirm & Generate PO* (tombol submit dengan Adasi loading spinner saat diproses).

---

## Verification Plan

### Automated Tests
Jalankan test suite PHPUnit untuk memastikan seluruh skenario dan invariant keamanan bekerja sempurna:
1. Buat file test baru `tests/Feature/QuotationDirectPoGenerationTest.php` untuk memvalidasi:
   * Pembuatan PO berhasil dari Quotation berstatus `submitted`.
   * Pembuatan PO berhasil dari Quotation berstatus `accepted`.
   * Quotation otomatis menjadi `accepted` dan PR menjadi `completed` (jika seluruh item ter-award).
   * Menolak pembuatan PO jika Quotation expired.
   * Menolak pembuatan PO jika PR sudah berstatus `completed`.
   * Menolak item yang berstatus `is_available = false`.
   * Proteksi otorisasi: Role `supplier` dan `qc` ditolak aksesnya (HTTP 403 / redirect).
2. Jalankan test regresi yang sudah ada:
   * `vendor/bin/phpunit tests/Feature/PurchaseOrderCreationConcurrencyAndInvariantTest.php`
   * `vendor/bin/phpunit tests/Feature/SupplierDataIsolationTest.php`

### Manual Verification
1. Buka browser dan login sebagai Purchasing.
2. Buka salah satu Quotation berstatus `submitted` atau `accepted`.
3. Klik tombol **"Generate Purchase Order"**.
4. Periksa apakah modal terbuka dengan rincian item, total nominal, serta input tanggal estimasi tiba terisi default +14 hari.
5. Klik **"Confirm & Generate PO"**.
6. Pastikan halaman otomatis ter-redirect ke halaman detail Purchase Order baru (`/purchasing/purchase-orders/{id}`) dan PO number terbuat secara berurutan.
