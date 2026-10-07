# IMPLEMENTATION PLAN — Advance Export (Supplier Portal)

| | |
|---|---|
| **Repo / branch** | `poyipoy/supplierportal` — branch `language-update` |
| **Tanggal** | 2026-10-07 |
| **Simpan di** | `docs/plans/IMPLEMENTATION-PLAN-ADVANCE-EXPORT-20261007.md` |
| **Status** | Draft siap eksekusi (belum ada kode yang diubah) |
| **Dasar bukti** | Pembacaan statis kode di commit `4c8374e` (shallow clone). **Tidak ada test/runtime yang dijalankan.** Setiap klaim berlabel **[V]** = terverifikasi di kode, **[A]** = asumsi / harus diverifikasi di Fase 0. |

---

## 1. Tujuan & Non-Tujuan

### Tujuan
Memberi pengguna **Purchasing/internal** dan **Supplier** kendali atas export berbentuk daftar:

1. **Filter** (rentang tanggal, status, dll.) sebelum export.
2. **Pilih kolom dan urutan kolom** sendiri.
3. **Preset pribadi** per user (simpan kombinasi kolom + filter + format).
4. **Pilih format XLSX atau CSV** (khusus export Excel/daftar).

### Non-Tujuan (di luar scope)
- PDF (PO, QC inspection, voucher Finance) — dokumen satuan, tetap sinkron dan tetap template tetap **[V]** (`Purchasing/PdfController`, `LocalInvoiceSettlementController`).
- Export detail satu record (`PurchaseOrderDetailExport`, `QuotationDetailExport`, `PurchaseRequisitionDetailExport`).
- Workbook template tetap: `PaymentBatchDrpExport`, `PaymentBatchTransferExport` (kelas `GeneratesWorkbook`), dan semua `*ImportTemplateExport`.
- Preset yang dibagikan antar user (diputuskan: **pribadi saja**).
- Paket Composer baru. Semua dikerjakan dengan `maatwebsite/excel` yang sudah ada.

---

## 2. Keputusan yang Sudah Dikunci

| # | Keputusan | Sumber |
|---|---|---|
| D1 | Fitur: filter, pilih kolom + urutan, preset pribadi, format XLSX/CSV | Wawancara |
| D2 | Pengguna: Purchasing dan Supplier sama pentingnya | Wawancara |
| D3 | Volume terbesar ±1.000–50.000 baris per export | Wawancara |
| D4 | Export sudah async (database queue, queue `exports`) | Wawancara + **[V]** |
| D5 | Preset **pribadi** per user | Wawancara |
| D6 | CSV: delimiter `,` (standar), UTF-8 **dengan BOM** | Keputusan terakhir |
| D7 | PDF di luar scope (asumsi dari temuan kode; dikonfirmasi lewat kelanjutan pekerjaan) | Temuan kode |

---

## 3. Temuan Baseline (Bukti dari Kode)

| # | Temuan | Dampak pada desain | Status |
|---|---|---|---|
| F1 | `ExportDispatcher::dispatch($label, $class, array $args, $fileName)`; `ProcessExportJob` menjalankan `new $exportClass(...$record->export_args)` | Argumen **positional** → jangan tambah opsi ke constructor tiap class. Pakai kolom JSON baru `export_options`. | **[V]** |
| F2 | Heading, `map()`, `columnWidths()` ditulis tetap di tiap class (`PurchaseOrdersExport` = 10 kolom, `LocalInvoicesExport` = 16, `QuotationsExport` ≈ 24) | Perlu **katalog kolom** sebagai sumber tunggal | **[V]** |
| F3 | `ExportDispatcher::safeFileName()` memaksa `.xlsx`; `ExportDownloadController::download()` meng-hardcode `Content-Type` spreadsheet | Format perlu disimpan di `export_jobs` dan dipakai di nama file + header | **[V]** |
| F4 | `Excel::queue($export, $path, $disk)` tanpa writer type; `FinalizeExportJob` dirantai | Tambah writer type; verifikasi perilaku CSV queued | **[V]** / **[A]** |
| F5 | `SUPPORTED_EXPORT_CLASSES` (allowlist) di dispatcher dan `isSupported()` di job | Pertahankan; kelas baru tidak ditambahkan, kelas lama diperluas | **[V]** |
| F6 | `SpreadsheetCellSanitizer::text()` mencegah formula injection (`=`, `+`, `-`, `@`) | Wajib otomatis untuk semua kolom bertipe teks dari katalog, juga pada CSV | **[V]** |
| F7 | Satu class dipakai dua portal: `PurchaseOrdersExport` dipanggil oleh `Purchasing\ExportController` **dan** `Supplier\ExportController`; scope supplier dipaksa lewat argumen `(int) auth()->id()` | Izin kolom harus berbasis **audience yang ditentukan server**, bukan input request | **[V]** |
| F8 | Filter divalidasi terpisah di dua controller (duplikat); `LocalInvoicesExport` memakai `InvoiceFilterRequest` bersama (Finance `master-invoices.export` + Accounting `reports.export`) | Ekstrak filter ke class bersama yang juga dipakai halaman index | **[V]** |
| F9 | Filter supplier di Purchasing memakai hashid dan menolak ID integer (`resolveSupplierFilter`) | Preset **tidak boleh** menyimpan ID integer supplier; simpan hashid dan resolve ulang saat dispatch | **[V]** |
| F10 | Chunk 500 (`WithCustomChunkSize`), progres lewat `TracksExportProgress` + middleware | Dipertahankan | **[V]** |
| F11 | Query PO selalu `with(['supplier','quotations.purchaseRequisition.period','quotations.items.prItem','awards','quotations.exchange_rate'])` | Eager load per kolom (`with`) memangkas beban saat kolom dikurangi | **[V]** |
| F12 | Lint kode memakai penanda `// biz-time:ignore instant filename` pada `now()` di nama file; helper `BusinessTime` untuk tanggal bisnis | Ikuti konvensi ini di semua kode baru | **[V]** |
| F13 | Model memakai `HasHashids` untuk route key (`ExportJob`) | `ExportPreset` memakai pola yang sama, tidak mengekspos ID integer | **[V]** |
| F14 | Test export yang sudah ada: `AsyncExportQueueTest`, `DetailExportSecurityTest`, `LocalizationExportTest`, `MissionFourExportTest`, `RegionalExportHistoryTest`, `ShipmentUiAndExportTest` | Jadi jaring pengaman regresi (Fase 0) | **[V]** |
| F15 | `ExportDownloadController::index` memfilter berdasarkan `LOCAL_EXPORT_CLASSES` per role | Tidak berubah; pastikan format tampil di daftar | **[V]** |
| F16 | Setiap class punya `collection()` yang memanggil `query()->get()->map(map)` | Tidak boleh menyimpang dari `map()` baru; hapus bila tidak dipakai | **[A]** (cek pemakaian) |
| F17 | Maatwebsite `Excel::queue` men-serialize objek export ke job chunk | Katalog **tidak boleh** disimpan sebagai properti objek yang berisi closure | **[A]** (dibuktikan dengan test di Fase 1) |

---

## 4. Matriks Scope & Kunci Export

`export_key` = identitas stabil untuk definisi, preset, dan izin. Format: `<audience>.<modul>`.

| export_key | Class | Audience | Tier | Filter yang ada sekarang |
|---|---|---|---|---|
| `purchasing.po` | `PurchaseOrdersExport` | purchasing | 1 | supplier, start/end date, po_number, status, search |
| `supplier.po` | `PurchaseOrdersExport` | supplier | 1 | start/end date, status, … (scope supplier dipaksa) |
| `purchasing.quotations` | `QuotationsExport` | purchasing | 1 | `$filters` array |
| `supplier.quotations` | `QuotationsExport` | supplier | 1 | `$filters` array + `forcedSupplierId` |
| `purchasing.pr` | `RequisitionsExport` | purchasing | 1 | period_id, status, search |
| `purchasing.shipments` | `ShipmentsExport` | purchasing | 1 | supplier, status, search, start/end date |
| `qc.inspections` | `InspectionsExport` | qc | 1 | start/end date, status |
| `finance.local-invoices` | `LocalInvoicesExport` | finance | 1 | `InvoiceFilterRequest` |
| `accounting.local-invoices` | `LocalInvoicesExport` | accounting | 1 | `InvoiceFilterRequest` + varian `register`/`payments` |
| `supplier.price-history` | `SupplierPriceHistoryExport` | supplier | 2 | filter analisis (material, periode, mata uang, dimensi) — **kolom tetap, hanya format** |

Tier 1 = filter + kolom + preset + format. Tier 2 = filter existing + format saja.

Kolom default PO **[V]** (urutan = urutan sekarang): `po_number, pr_number, supplier, material, currency, total_amount, total_idr, est_arrival, remark, status`.

Kolom default LocalInvoices **[V]**: `submission, receipt, invoice, tax_invoice_number, po_reference, supplier, currency, invoice_amount, ppn, status, submitted, approved, payment_term_days, due_date, scheduled_payment, completed`.

Kolom Quotations/Shipments/PR/QC: turunkan dari `headings()` yang ada saat Fase 5. **Catatan grain:** `QuotationsExport` memiliki satu baris per *item* quotation (kolom bercampur level quotation dan level item).

---

## 5. Arsitektur Target

### 5.1 Komponen baru

```
app/Support/Export/
  ExportOptions.php              # DTO immutable: columns[], format, audience
  ExportOptionsResolver.php      # validasi + normalisasi opsi vs definisi + audience
  ExportDefinitions.php          # registry export_key → definition (allowlist)
app/Exports/Advanced/
  ExportColumn.php               # value object satu kolom
  ExportDefinition.php           # kontrak: key(), exportClass(), columns(), filterSchema(), authorize()
  Concerns/UsesColumnCatalog.php # trait: headings/map/columnWidths dari katalog
  Definitions/PurchaseOrderDefinition.php, ...   # satu per export_key group
app/Contracts/AcceptsExportOptions.php
app/Http/Requests/Export/
  AdvancedExportRequest.php      # options + filters
  Filters/PurchaseOrderExportFilters.php, ...    # filter bersama (index + export)
app/Models/ExportPreset.php
app/Http/Controllers/ExportPresetController.php
app/Http/Controllers/ExportDefinitionController.php   # katalog kolom untuk modal
resources/views/components/export/advanced-modal.blade.php
config/exports.php               # max_rows, preset limits, csv settings
lang/{en,id}/exports.php         # tambah blok `advanced.*`
database/migrations/…            # lihat 5.6
```

### 5.2 Alur end-to-end

```
Modal (filter → kolom → format → [simpan preset])
  → POST /export/{route yang sudah ada}  (route lama dipertahankan; opsi = field tambahan opsional)
  → AdvancedExportRequest: validasi filter (class filter bersama) + options
  → ExportOptionsResolver: audience dari ROUTE/controller (bukan request), saring kolom
        terhadap katalog + izin audience, urutan dipertahankan, min 1 kolom, maks = semua
  → ExportDispatcher::dispatch(label, class, args, fileName, ?ExportOptions)
        - simpan export_jobs.format + export_options
        - nama file mengikuti format
  → ProcessExportJob: new $class(...args) → applyOptions(ExportOptions) → Excel::queue(..., writerType)
  → FinalizeExportJob → exports.index / status polling (tidak berubah)
  → ExportDownloadController::download: Content-Type sesuai format
```

**Kompatibilitas mundur:** jika `ExportOptions` null → perilaku identik dengan sekarang (kolom default = urutan sekarang, XLSX). Tombol export lama tetap berfungsi selama migrasi bertahap.

### 5.3 Katalog kolom

```php
final class ExportColumn
{
    public function __construct(
        public readonly string $key,                 // stabil, snake_case, TIDAK PERNAH diubah (preset mereferensikannya)
        public readonly string $headingKey,          // kunci lang di exports.headings.* (pakai yang sudah ada)
        public readonly \Closure $value,             // fn (mixed $row): mixed
        public readonly float $width = 18,
        public readonly ColumnType $type = ColumnType::Text, // Text|Number|Money|Date
        public readonly bool $default = true,
        public readonly bool $required = false,      // mis. nomor dokumen; tak boleh dihapus
        public readonly array $audiences = ['purchasing', 'supplier'],
        public readonly array $with = [],            // relasi eager load yang dibutuhkan kolom ini
        public readonly ?\Closure $headingSuffix = null, // mis. LocalInvoices: " (WIB)" dari BusinessTime::label()
    ) {}
}
```

Aturan:
1. **Closure tidak boleh menjadi properti objek export** (F17). Katalog dibangun lewat `static columns(): array` dan di-cache di properti `static`. Objek export hanya menyimpan `array $columnKeys`, `string $audience`, `string $format` (skalar, aman diserialisasi).
2. `UsesColumnCatalog::headings()` / `map()` / `columnWidths()` diturunkan dari kolom terpilih. Kolom bertipe `Text` otomatis dibungkus `SpreadsheetCellSanitizer::text()`.
3. `query()` memanggil `applyEagerLoads()` = gabungan `with` dari kolom terpilih (menggantikan `with([...])` hardcoded).
4. Lebar kolom dipakai hanya untuk XLSX; di CSV diabaikan.
5. Kolom internal (mis. catatan internal, harga acuan) diberi `audiences: ['purchasing']` saja. Resolver menyaring **dua kali**: saat request dan lagi di `applyOptions()` (defense in depth, karena opsi tersimpan di DB).
6. Daftar kolom `default: true` + urutan = persis perilaku export saat ini (dipastikan test golden Fase 0).

### 5.4 Format (XLSX/CSV)

- `export_jobs.format` ∈ {`xlsx`,`csv`}; `ExportDispatcher::safeFileName($name, $format)` memakai ekstensi yang sesuai.
- `ProcessExportJob`: `Excel::queue($export, $path, $disk, $format === 'csv' ? Excel::CSV : Excel::XLSX)`.
- CSV via `WithCustomCsvSettings` (kondisional pada format): `delimiter => ','`, `enclosure => '"'`, `use_bom => true`, `input_encoding => 'UTF-8'`.
- Angka tetap angka mentah (tanpa pemisah ribuan); tanggal `Y-m-d` (konvensi export sekarang). Sanitizer tetap aktif pada CSV (Excel membuka CSV, formula injection tetap relevan).
- `ExportDownloadController::download`: peta `Content-Type` per format (`text/csv; charset=UTF-8` untuk CSV); pertahankan `nosniff`, `no-store`.
- Export `GeneratesWorkbook` (DRP/Transfer): **hanya XLSX**; dispatcher menolak `format=csv` untuk kelas ini.
- **Wajib diverifikasi (F4):** pada CSV queued, BOM hanya ditulis **sekali** dan baris heading **sekali** (bukan per chunk).

### 5.5 Filter

- Satu class filter per export (`PurchaseOrderExportFilters`, …) sebagai satu-satunya sumber aturan validasi, dipakai **halaman index dan export** → "yang terlihat = yang diexport".
- `filterSchema()` pada definisi mendeskripsikan field untuk modal (`name`, `type: text|select|date|supplier`, `options`).
- Filter tanggal pada preset punya mode **relatif** (`{"mode":"relative","days":30}`) atau **absolut**; relatif dikonversi ke absolut saat dispatch memakai `BusinessTime::today()` (F12).
- Filter supplier pada preset disimpan sebagai **hashid**, di-resolve ulang lewat logika `resolveSupplierFilter` yang sama (F9); ID integer ditolak.
- Scope supplier (`auth()->id()`) tetap dipaksa server, tidak pernah dari preset/request.

### 5.6 Skema database

```php
// export_jobs (tambahan)
$table->string('format', 8)->default('xlsx');
$table->json('export_options')->nullable();   // {columns:[...], format, audience} → juga jejak audit

// export_presets
$table->id();
$table->foreignId('user_id')->constrained()->cascadeOnDelete();
$table->string('export_key', 64);
$table->string('name', 80);
$table->json('columns');                      // list<string> berurutan
$table->json('filters')->nullable();          // relatif/absolut; supplier = hashid
$table->string('format', 8)->default('xlsx');
$table->boolean('is_default')->default(false);
$table->timestamps();
$table->unique(['user_id', 'export_key', 'name']);
$table->index(['user_id', 'export_key', 'is_default']);
```

- Satu default per `(user_id, export_key)` dijaga **di service dalam transaksi**, bukan lewat partial index (kompatibilitas engine DB belum diverifikasi **[A]**).
- Batas: **20 preset per user per export_key** (`config/exports.php`).
- `ExportPreset` memakai `HasHashids`; semua query di-scope `where('user_id', auth()->id())` (anti-IDOR) dan lewat policy.

### 5.7 Endpoint baru

| Method | Path | Fungsi |
|---|---|---|
| GET | `/exports/definitions/{exportKey}` | Katalog kolom (key, label terlokalisasi, default, required) + `filterSchema` untuk audience user |
| GET | `/export-presets?export_key=` | Daftar preset milik user |
| POST | `/export-presets` | Buat preset |
| PUT | `/export-presets/{preset}` | Ubah nama/kolom/filter/format |
| DELETE | `/export-presets/{preset}` | Hapus |
| POST | `/export-presets/{preset}/default` | Jadikan default |

Semua di grup `auth` yang sudah ada. `exportKey` harus ada di `ExportDefinitions` **dan** lolos `definition->authorize($user)`; selain itu 404.

### 5.8 Keamanan (checklist wajib)

- [ ] Kolom dan filter divalidasi terhadap katalog di **server**; nama kolom dari request tidak pernah dipakai langsung di query.
- [ ] Audience ditentukan oleh route/controller, **bukan** input.
- [ ] Supplier tidak dapat memilih kolom internal; test eksplisit.
- [ ] Preset: tidak bisa membaca/ubah/hapus milik user lain (test IDOR).
- [ ] Preset basi (kolom sudah tidak ada) → kolom diabaikan + peringatan, tidak error.
- [ ] Tidak ada ID integer atau hashid yang bocor ke kolom export kecuali memang kolom bisnis.
- [ ] Sanitizer aktif untuk semua kolom teks (XLSX dan CSV).
- [ ] `export_options` tersimpan sebagai jejak audit; `exports:cleanup` tetap berlaku.
- [ ] Batas baris: `config('exports.max_rows')` default **100.000**; lewat batas → validasi ramah, bukan job gagal. **[Keputusan terbuka O1]**
- [ ] Batas export bersamaan per user: cek apakah sudah ada; bila belum, tambahkan (default 3 `queued/processing`). **[A]**

### 5.9 UI/UX

Satu komponen `<x-export.advanced-modal export-key="purchasing.po" />` menggantikan tombol export di ±17 view (daftar di Lampiran A). Langkah dalam satu modal:

1. **Filter** — terisi dari query string halaman saat ini.
2. **Kolom** — checklist + ubah urutan (tombol naik/turun dan drag native; tanpa dependency baru); kolom `required` terkunci; tombol "Reset ke default".
3. **Format** — XLSX/CSV. Hint teks: "Untuk data besar (>20.000 baris) disarankan CSV."
4. **Preset** — dropdown preset, "Simpan sebagai preset", "Jadikan default".
5. Tombol utama **Export**. Tombol sekunder **Export cepat** = preset default atau konfigurasi default.

Respons dan progres memakai alur yang sudah ada (HTTP 202 + `exports.index` + polling `exports.status`). Semua teks lewat `lang/en` dan `lang/id` (pasangan lengkap; repo punya `tests/Support/user-facing-copy-audit.php`). Aksesibilitas: fokus terkelola, label form, operasi urutan bisa lewat keyboard.

---

## 6. Rencana Eksekusi per Fase (PR terpisah)

### Fase 0 — Baseline & Verifikasi Awal (PR-0, kecil)
**Tujuan:** kunci perilaku sekarang dan jawab semua item **[A]**.
1. Jalankan test export yang ada (F14) dan catat hasil awal. Jangan klaim lulus tanpa menjalankannya.
2. Tulis **golden test** per export Tier 1: dengan fixture tetap, simpan `headings()` + `map()` baris pertama sebagai snapshot. Ini kontrak "kolom default = perilaku sekarang".
3. Verifikasi **[A]**: (a) pemakaian `collection()` di tiap class (grep + test); (b) apakah `CleanupExpiredExports`/`ExportProgressService` mengasumsikan `.xlsx`; (c) engine DB; (d) batas export bersamaan per user yang sudah ada; (e) pola JS frontend untuk export progress (`resources/js`), (f) perilaku BOM/heading pada `Excel::queue` CSV.
4. Output: bagian "Hasil Verifikasi" ditambahkan ke dokumen ini.

**Selesai bila:** golden test hijau, semua [A] terjawab atau dicatat sebagai risiko.

### Fase 1 — Fondasi (PR-1)
1. Migration `export_jobs`: `format`, `export_options`.
2. `ExportOptions`, `AcceptsExportOptions`, `config/exports.php`.
3. `ExportDispatcher::dispatch(..., ?ExportOptions $options = null)`; `safeFileName` sadar format; tolak CSV untuk `GeneratesWorkbook`.
4. `ProcessExportJob`: terapkan opsi (gagal tertutup bila opsi ada tapi export tidak mengimplementasikan `AcceptsExportOptions`); writer type.
5. `ExportDownloadController::download`: Content-Type per format. `exports.index`: badge format.
6. Test: dispatch tanpa opsi identik dengan sekarang; format tersimpan; nama file benar; Content-Type benar; **test serialisasi export (F17)**.

**Selesai bila:** semua test lama + golden hijau; tidak ada perubahan UI.

### Fase 2 — Katalog Kolom + Pilot PO (PR-2, backend)
1. `ExportColumn`, `ColumnType`, `ExportDefinition`, `ExportDefinitions`, `UsesColumnCatalog`, `ExportOptionsResolver`.
2. `PurchaseOrderDefinition` (10 kolom, `with` per kolom, audience; kolom internal hanya purchasing). Refactor `PurchaseOrdersExport` memakai trait; pertahankan constructor lama.
3. `AdvancedExportRequest` + `PurchaseOrderExportFilters`; hubungkan ke `Purchasing\ExportController::purchaseOrders` dan `Supplier\ExportController::purchaseOrders` (field opsi opsional).
4. CSV: `WithCustomCsvSettings`; test BOM tunggal + heading tunggal pada queued CSV.
5. Test: pilih subset + urutan; kolom tak dikenal ditolak; supplier tidak bisa pilih kolom internal; sanitizer pada CSV; filter relatif; eager load hanya relasi yang diperlukan (assert query tidak memuat `awards` bila kolom tak butuh); locale en/id.

**Selesai bila:** PO bisa diexport lewat API dengan kolom/urutan/format pilihan di dua portal; golden PO tetap hijau.

### Fase 3 — Preset (PR-3, backend)
1. Migration `export_presets`, model, policy, `ExportPresetController`, `ExportDefinitionController`, routes.
2. Service preset: default tunggal dalam transaksi; batas 20; validasi kolom terhadap katalog audience; penanganan preset basi.
3. Test: CRUD; IDOR user A vs B; limit; default tunggal; preset berisi supplier hashid valid/invalid/ID integer; kolom basi diabaikan + peringatan.

### Fase 4 — UI Modal untuk PO (PR-4)
1. Komponen Blade + JS modul (pola JS yang diverifikasi di Fase 0), lang en/id.
2. Pasang di `purchasing/po/index` dan `supplier/po/index` (ganti tombol lama).
3. Test fitur: render, endpoint definisi, alur submit 202; uji manual di checklist (Lampiran C).

**Selesai bila:** alur penuh PO (filter → kolom → format → preset → progres → unduh) berjalan di kedua portal.

### Fase 5 — Rollout Export Lain (PR-5a, PR-5b)
Urutan: **Quotations → PR → Shipments → QC → Local Invoices (Finance + Accounting)**. Per export lakukan checklist yang sama: definisi kolom (key stabil, `with`, audience) → refactor class → class filter bersama → controller → pasang modal → golden test → test izin.

Catatan khusus:
- **Quotations:** grain per item; kolom campuran level quotation/item; paling banyak kolom, paling diuntungkan eager load selektif. Supplier memakai `forcedSupplierId` — pertahankan.
- **Local Invoices:** satu class, dua pemanggil (Finance, Accounting) + varian `register`/`payments` (arg ketiga). Bedakan `export_key` per pemanggil; heading memakai `headingSuffix` (label zona waktu `BusinessTime`). Pertahankan pengecekan aktor di `query()`.
- **QC:** filter tidak punya `search`; jangan menambah filter baru di luar scope.
- Kolom sensitif per audience di Quotations/Shipments **perlu ditinjau pemilik bisnis** sebelum merge **[O2]**.

### Fase 6 — Price History + Hardening (PR-6)
1. `supplier.price-history`: hanya format (XLSX/CSV), tanpa pemilih kolom.
2. Dokumentasi: tambahkan "Cara menambah export baru" di `docs/` (langkah mendaftarkan definisi, kolom, filter, test).
3. Audit akhir keamanan memakai checklist 5.8; bersihkan `collection()` yang tidak dipakai.

---

## 7. Rencana Test (ringkas)

| Area | Kasus kunci |
|---|---|
| Regresi | Golden headings/map tiap export Tier 1; test export lama tetap hijau |
| Serialisasi | Export dengan katalog dapat di-serialize/unserialize (F17) |
| Format | XLSX default tak berubah; CSV: BOM sekali, heading sekali, delimiter `,`, Content-Type, ekstensi nama file |
| Kolom | Subset + urutan benar; `required` tak bisa dihapus; kolom tak dikenal → 422; min 1 kolom |
| Izin | Supplier tak bisa pilih kolom `purchasing`-only; scope supplier tetap dipaksa; audience tak bisa dipalsukan lewat request |
| Filter | Parity index vs export; tanggal relatif → absolut; hashid supplier valid; ID integer ditolak |
| Preset | CRUD, IDOR, limit 20, default tunggal, preset basi |
| Keamanan data | Sanitizer pada XLSX **dan** CSV (`=`, `+`, `-`, `@`) |
| Performa | Eager load selektif (assert relasi yang dimuat); export ±50.000 baris fixture berjalan chunked tanpa melebihi memori yang wajar **[diukur, bukan diasumsikan]** |
| Lokalisasi | Heading en/id; lang parity lewat audit copy yang ada |
| Operasional | `exports:cleanup` menangani file `.csv`; daftar export menampilkan format |

---

## 8. Risiko & Mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Closure katalog ikut terserialisasi di queue | Job chunk gagal | Katalog `static` + test serialisasi (Fase 1) |
| BOM/heading CSV berulang per chunk | File CSV rusak di Excel | Test khusus Fase 2; bila perlu tulis CSV lewat jalur `GeneratesWorkbook`-style tunggal |
| Regresi kolom/urutan export lama | Laporan user berubah diam-diam | Golden test Fase 0, default = perilaku sekarang |
| Supplier melihat kolom internal | Kebocoran data | Audience server-side + penyaringan ganda + test eksplisit |
| Preset basi setelah perubahan katalog | Error/hasil aneh | Key kolom stabil; kolom hilang diabaikan + peringatan |
| XLSX 50k baris × ≥24 kolom berat | Memori/waktu | Chunk 500 tetap; eager load selektif; hint CSV; `max_rows` |
| Refactor 6 class besar sekaligus | PR sulit direview | Pilot PO dulu, rollout satu per satu, PR kecil |
| Excel locale ID memecah CSV `,` | Kolom menyatu saat dibuka | Keputusan D6 (standar `,`); dokumentasikan cara impor (Data → From Text/CSV) di hint UI |

---

## 9. Keputusan Terbuka

| # | Pertanyaan | Usulan default |
|---|---|---|
| O1 | Nilai `max_rows` | 100.000 (config) |
| O2 | Kolom sensitif per audience di Quotations/Shipments | Tinjau bersama pemilik bisnis sebelum Fase 5 |
| O3 | Batas export bersamaan per user | 3 (bila belum ada) |
| O4 | Perlu endpoint estimasi jumlah baris di modal? | Ditunda; hint statis cukup |

---

## 10. Panduan Eksekusi untuk Codex CLI

1. **Disiplin bukti:** verifikasi setiap temuan **[A]** terhadap source branch sebelum mengubah kode; jangan mengklaim test/runtime lulus jika tidak dijalankan.
2. **Minimal diff:** jangan refactor di luar scope; DRP/Transfer, template import, PDF, dan export detail **tidak disentuh**.
3. **Backward compatible:** tanpa `ExportOptions`, perilaku harus identik dengan sekarang.
4. **Tanpa dependency baru** (Composer/npm).
5. **Konvensi repo:** `HasHashids` untuk model baru; `BusinessTime` untuk tanggal bisnis; `// biz-time:ignore instant filename` pada `now()` untuk nama file; lang `en` + `id` berpasangan; ikuti formatter/linter yang dipakai repo.
6. Satu fase = satu PR; commit kecil dan bertema; jalankan test relevan sebelum commit (`php artisan test --filter=...`).
7. Jika menemukan kondisi kode yang bertentangan dengan dokumen ini, **hentikan, catat temuan, dan sesuaikan rencana** — jangan menebak.

---

## Lampiran A — View yang Memiliki Tombol Export (diganti bertahap)

`qc/inspections/index`, `finance/master-invoices/index`, `supplier/po/index` (+`show`), `supplier/quotations/{index,period,show}`, `supplier/price-history/historical`, `accounting/reports/index`, `purchasing/po/{index,show}`, `purchasing/quotations/{index,show}`, `purchasing/shipments/index`, `purchasing/pr/{index,show}`, `purchasing/reports/index`.
Tombol di halaman `show` (detail satu record) **tidak** diganti (Tier 3).

## Lampiran B — Sketsa Kode

```php
// ExportDispatcher
public static function dispatch(
    string $label, string $exportClass, array $args, string $fileName,
    ?ExportOptions $options = null,
): ExportJob {
    // ... validasi existing ...
    $format = $options?->format ?? 'xlsx';
    ExportJob::create([
        // ... field existing ...
        'file_name'      => self::safeFileName($fileName, $format),
        'format'         => $format,
        'export_options' => $options?->toArray(),
    ]);
}

// ProcessExportJob::handle (setelah $export = new $exportClass(...))
if ($record->export_options !== null) {
    if (! $export instanceof AcceptsExportOptions) {
        throw new RuntimeException('Export does not accept options.');
    }
    $export->applyOptions(ExportOptions::fromArray($record->export_options));
}
$writer = $record->format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX;
Excel::queue($export, $path, $record->disk, $writer) /* ... chain existing ... */;
```

```php
// Trait (hanya skalar yang disimpan di objek)
trait UsesColumnCatalog
{
    private array $columnKeys = [];
    private string $audience = 'purchasing';
    private string $format = 'xlsx';

    public function applyOptions(ExportOptions $o): void
    {
        $this->audience   = $o->audience;
        $this->format     = $o->format;
        $this->columnKeys = ExportDefinitions::sanitizeKeys(static::definitionKey(), $o->columns, $this->audience); // saring ulang
    }

    public function headings(): array { /* __("exports.headings.{$c->headingKey}") + headingSuffix */ }
    public function map($row): array  { /* value() + SpreadsheetCellSanitizer untuk ColumnType::Text */ }
    public function columnWidths(): array { /* Coordinate::stringFromColumnIndex(i+1) => width */ }
    protected function eagerLoads(): array { /* union with[] dari kolom terpilih */ }
}
```

Kontrak JSON `GET /exports/definitions/purchasing.po`:
```json
{
  "export_key": "purchasing.po",
  "formats": ["xlsx", "csv"],
  "columns": [
    {"key": "po_number", "label": "PO Number", "default": true, "required": true},
    {"key": "supplier",  "label": "Supplier",  "default": true, "required": false}
  ],
  "filters": [
    {"name": "start_date", "type": "date"},
    {"name": "status", "type": "select", "options": ["active", "waiting_qc", "claim_needed", "overdue", "completed", "cancelled"]}
  ]
}
```

## Lampiran C — Checklist Uji Manual (per export yang dimigrasi)

- [ ] Export cepat tanpa mengubah apa pun menghasilkan file identik dengan sebelum fitur ini (kolom, urutan, isi).
- [ ] Pilih 3 kolom, ubah urutan → hasil sesuai.
- [ ] Filter tanggal + status → baris sesuai; sama dengan tampilan halaman.
- [ ] CSV terbuka di Excel tanpa karakter rusak (UTF-8) dan tanpa kolom menyatu.
- [ ] Sel berawalan `=`/`+`/`-`/`@` tidak dieksekusi sebagai formula.
- [ ] Simpan preset, muat ulang halaman, preset tetap ada; jadikan default.
- [ ] Login sebagai supplier: kolom internal tidak tampil dan tidak bisa dipaksa lewat request.
- [ ] Login sebagai user lain: preset pengguna pertama tidak terlihat/terakses.
- [ ] Export besar (±50.000 baris): progres berjalan, file selesai, unduhan berhasil.
- [ ] Setelah masa kedaluwarsa, file dibersihkan oleh `exports:cleanup`.
