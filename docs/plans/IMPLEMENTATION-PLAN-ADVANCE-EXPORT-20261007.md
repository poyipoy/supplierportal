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

## 11. Hasil Verifikasi Fase 0

### 11.1 Lingkup dan checkout

- Tanggal eksekusi: 2026-10-07. Branch kerja: `feature/advance-export-phase-0`, dibuat dari `language-update` (`3a4dc9a17dbc12520e19f2db83639e7e5cc37e26`).
- Saat pemeriksaan awal terbaru, checkout berada di `master` (`bcb29db8c78e14ad5cbd13ce6af2875ccb653ce3`), working tree bersih, dan `git diff --stat language-update` kosong. Pemeriksaan/test awal pada tree itu dibedakan dari run akhir pada branch Fase 0.
- Seluruh `AGENTS.md`, `CLAUDE.md`, framework kognitif Laravel, `context.md`, dan dokumen rencana ini dibaca. Pencarian instruksi tidak menemukan `AGENTS.md` yang lebih spesifik untuk test/dokumentasi.
- Perubahan Fase 0 hanya dua file test baru dan tambahan bagian ini. Tidak ada perubahan kode produksi, dependency, PDF, export detail, DRP/Transfer, atau template import. Tidak menjalankan migrasi terhadap database aplikasi.

### 11.2 Hasil test yang benar-benar dijalankan

Run awal sebelum test baru: sembilan suite berikut dijalankan serial dalam satu proses pada tree awal; **131 passed, 3.591 assertions, 85,62 detik**. Run akhir di branch Fase 0 menjalankan kedua test baru bersama sembilan suite yang sama; **151 passed, 5.803 assertions, 87,25 detik**, exit code 0. Tidak ada test dilewati pada kedua run tersebut.

| Suite | Awal | Akhir | Catatan |
|---|---:|---:|---|
| AsyncExportQueueTest | 24 lulus | 24 lulus | Queue, ownership, progress, cleanup, retry |
| DetailExportSecurityTest | 4 lulus | 4 lulus | Export detail hanya dijalankan; kodenya tidak diubah |
| LocalizationExportTest | 3 lulus | 3 lulus | Locale en/id dan round-trip serialisasi existing |
| MissionFourExportTest | 4 lulus | 4 lulus | Filter, mapping, scope, numeric cells |
| RegionalExportHistoryTest | 4 lulus | 4 lulus | Kontrak timestamp dan polling |
| ShipmentUiAndExportTest | 10 lulus | 10 lulus | Mapping shipment dan supplier isolation |
| PaymentBatchDrpExportTest | 14 lulus | 14 lulus | Workbook tetap; hanya verifikasi regresi |
| PaymentBatchTransferExportTest | 37 lulus | 37 lulus | Workbook tetap; hanya verifikasi regresi |
| LocalInvoiceTest | 31 lulus | 31 lulus | Relevan karena Tier 1 mencakup LocalInvoicesExport |
| AdvancedExportBaselineTest | Belum ada | 18 lulus | Golden enam export en/id, register/payments Finance/Accounting, serialisasi |
| AdvancedExportQueuedCsvBaselineTest | Belum ada | 2 lulus | 1.001 baris, tiga chunk 500, locale en/id |

Safety test database dijalankan sebelum feature tests: **2 passed, 4 assertions**, exit code 0; koneksi hidup diperiksa dengan `SELECT DATABASE()` dan menunjuk `adasi_portal_test` (`tests/Feature/TestingEnvironmentDatabaseSafetyTest.php:20`). Seluruh suite database dijalankan serial.

Run pertama kedua test baru menghasilkan **4 failed, 16 passed, 198 assertions**. Penyebabnya fixture memasukkan `created_at` ke `PurchaseRequisition::create()`, padahal field itu tidak termasuk `$fillable` (`app/Models/PurchaseRequisition.php:24`). Fixture diperbaiki dengan `forceFill()` untuk timestamp tetap; tidak mengubah produksi atau mengganti expected golden agar mengikuti waktu runtime. Run akhir di atas membuktikan perbaikannya.

Golden memakai array literal, bukan expected dari `headings()`, translator, atau model helper saat runtime. Jumlah kolom: PO 10, Quotations 24, PR 11, Shipments 11, Inspections 8, LocalInvoices 16. Seluruh urutan, nilai, tipe scalar, label locale, null fallback, dan konversi tanggal pada baris pertama diperiksa dengan `assertSame()` (`tests/Feature/AdvancedExportBaselineTest.php:94`).

Perintah run akhir (satu proses, tanpa paralel):

```powershell
php artisan test tests/Feature/AdvancedExportBaselineTest.php tests/Feature/AdvancedExportQueuedCsvBaselineTest.php tests/Feature/AsyncExportQueueTest.php tests/Feature/DetailExportSecurityTest.php tests/Feature/LocalizationExportTest.php tests/Feature/MissionFourExportTest.php tests/Feature/RegionalExportHistoryTest.php tests/Feature/ShipmentUiAndExportTest.php tests/Feature/Finance/PaymentBatchDrpExportTest.php tests/Feature/Finance/PaymentBatchTransferExportTest.php tests/Feature/LocalInvoice/LocalInvoiceTest.php --compact
```

PHP lint kedua file baru lulus. Pint dijalankan hanya pada kedua file baru, kemudian `php vendor/bin/pint --test` untuk kedua file menghasilkan `passed`. Percobaan awal menjalankan Pint melalui sandbox gagal sebelum proses dibuat dengan `helper_unknown_error: setup refresh had errors`; run ulang dengan eskalasi tool berhasil. Peringatan PHPUnit tentang metadata doc-comment pada `BankTransferMappingTest` masih muncul pada safety test; file tersebut tidak diubah.

### 11.3 Jawaban item [A] dan pemeriksaan a-f

| Item | Jawaban dan tingkat bukti | Bukti file:baris |
|---|---|---|
| a / F16: pemakaian collection() | **Diinspeksi dan diuji.** PO, Quotations, PR, Inspections, dan Shipments memiliki compatibility method `collection()` dan dipakai test existing. Pencarian `collection\(` di app/Exports, app/Http, app/Services, app/Jobs, dan tests/Feature tidak menemukan caller produksi untuk kelima compatibility method tersebut. Tier 1 diproses sebagai FromQuery oleh vendor. **LocalInvoicesExport tidak memiliki collection()**. Jangan menghapus method yang masih dipakai test tanpa menyesuaikan test di fase yang relevan. | `app/Exports/PurchaseOrdersExport.php:141`; `QuotationsExport.php:141`; `RequisitionsExport.php:89`; `InspectionsExport.php:93`; `ShipmentsExport.php:92`; `LocalInvoicesExport.php:17`; `tests/Feature/AsyncExportQueueTest.php:971-974`; `tests/Feature/ShipmentUiAndExportTest.php:180`; `vendor/maatwebsite/excel/src/QueuedWriter.php:95-98` |
| b: ekstensi cleanup/progress | **Diinspeksi.** CleanupExpiredExports dan ExportProgressService tidak mengasumsikan .xlsx; keduanya memakai disk/path tersimpan. Guard path juga tidak memeriksa ekstensi. Regresi cleanup/progress existing lulus, tetapi cleanup file CSV spesifik belum diuji. Dispatcher dan download masih khusus XLSX dan tetap perlu penyesuaian Fase 1. | `app/Console/Commands/CleanupExpiredExports.php:28-40`; `app/Services/ExportProgressService.php:216-230`; `app/Models/ExportJob.php:108-115`; `app/Support/ExportDispatcher.php:135-152`; `app/Http/Controllers/ExportDownloadController.php:65` |
| c: database | **Konfigurasi dibaca, koneksi test dijalankan.** .env.example menetapkan mysql; phpunit.xml menetapkan mysql/adasi_portal_test. Config mendukung MySQL dan MariaDB, dengan fallback DB_CONNECTION=sqlite bila environment tidak diisi; `engine => null` tidak menetapkan storage engine. Dockerfile hanya image PHP/nginx, bukan definisi server database; pencarian compose tidak menemukan file compose. **Versi server dan engine tabel lokal/staging/produksi tidak terverifikasi**, karena belum menjalankan inspeksi metadata pada lingkungan tersebut. | `.env.example:34-39`; `phpunit.xml:33-37`; `config/database.php:20,45-85`; `Dockerfile:1`; `tests/Feature/TestingEnvironmentDatabaseSafetyTest.php:18-23` |
| d: export bersamaan per user | **Diinspeksi.** Tidak ditemukan limit jumlah queued/processing per user pada dispatcher, controller export/download, route, dan config yang diperiksa. `has_pending` adalah exists untuk tampilan, bukan pembatas; lock/status pada launcher melindungi satu record, bukan membatasi jumlah record user. AUTH_MAX_CONCURRENT_SESSIONS bukan limit export. O3 default 3 masih pekerjaan fase berikutnya, tidak ditambahkan di Fase 0. | `app/Support/ExportDispatcher.php:43-91`; `app/Http/Controllers/ExportDownloadController.php:33-38`; `app/Services/ExportProgressService.php:37-44`; `config/auth_security.php:110-114`; `routes/web.php:495-501,565-568,616` |
| e: JavaScript progres | **Diinspeksi, regresi HTTP existing lulus; browser tidak diuji.** Implementasi utama adalah IIFE public/assets/js/async-export.js, bukan modul resources/js. Poll status 1 detik, timeout 660 detik, AdasiToast, state localStorage, claim lintas-tab, Blob download, dan restore navigasi. Layout memuat public asset dan menghubungkan Echo `.export.progress`. History mempunyai script inline fetch/poll 5 detik. | `public/assets/js/async-export.js:1-25,229-247,793-959`; `resources/views/layouts/app.blade.php:686,1163-1164`; `resources/views/exports/index.blade.php:94-202` |
| f / F4: CSV queued BOM/heading | **Runtime lulus dalam batas probe.** Excel::queue dengan writer CSV eksplisit, helper test-only WithCustomCsvSettings, delimiter koma, BOM/UTF-8, menghasilkan satu BOM dan satu heading untuk 1.001 baris / chunk 500 dalam locale en/id. Seluruh baris, urutan, angka, Unicode, koma, kutip, newline dalam field, dan teks formula-prefixed diperiksa. Vendor membuat heading saat QueueExport membuka sheet; append chunk membuka ulang worksheet dan menulis ulang file dengan mode wb, bukan append byte mentah. | `tests/Feature/AdvancedExportQueuedCsvBaselineTest.php:33-88`; `vendor/maatwebsite/excel/src/Jobs/QueueExport.php:61-82`; `Jobs/AppendQueryToSheet.php:91-106`; `Sheet.php:183-191`; `Writer.php:137-140,164-195`; `vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Writer/BaseWriter.php:121`; `Writer/Csv.php:110-112` |
| F17: serialisasi objek export | **Runtime lulus untuk keenam export existing.** Setelah query size cache, locale, dan scalar progress context diisi, serialize/unserialize mempertahankan headings, mapping, query size, dan preferredLocale. Job vendor menyimpan export sebagai properti. Ini tidak membuktikan katalog yang belum dibuat aman; test harus diperluas saat applyOptions/katalog diperkenalkan. Closure katalog tetap dilarang menjadi properti instance export. | `tests/Feature/AdvancedExportBaselineTest.php:115-123`; `tests/Feature/LocalizationExportTest.php:48-60`; `vendor/maatwebsite/excel/src/Jobs/QueueExport.php:22,39-44`; `Jobs/AppendQueryToSheet.php:44,63-73` |

### 11.4 Penyimpangan dan usulan penyesuaian rencana

1. **F16 terlalu umum:** LocalInvoicesExport tidak memiliki collection(); lima compatibility method lain masih dipakai test. Usulan: hapus hanya method yang benar-benar tidak dibutuhkan setelah caller/test disesuaikan, bukan penghapusan massal di awal.
2. **Tanggal default tidak seragam:** Inspections menggunakan `d/m/Y H:i`; PR/Quotations menggunakan `Y-m-d H:i:s`, dengan business timezone. Usulan: katalog mempertahankan formatter per kolom, jangan memaksakan seluruh tanggal menjadi Y-m-d. Bukti: `app/Exports/InspectionsExport.php:89`, `RequisitionsExport.php:85`, `QuotationsExport.php:130`.
3. **Tipe scalar default tidak seragam:** LocalInvoices map seluruh nilai menjadi string tersanitasi (`LocalInvoicesExport.php:37-42`); actual weight Shipments juga string terformat (`ShipmentsExport.php:79`). Usulan: bedakan metadata tipe kolom dari output legacy default; jangan mengubah nilai menjadi float hanya karena tipe katalog Money/Number.
4. **Risiko keamanan yang membutuhkan keputusan sebelum Fase 5b:** InspectionsExport mengembalikan teks PO/supplier/material tanpa SpreadsheetCellSanitizer (`InspectionsExport.php:81-89`). Golden sengaja merekam `=Baseline Steel` mentah sebagai perilaku sekarang; ini characterization, bukan persetujuan atas keamanan perilaku itu. Aturan sanitizer otomatis semua kolom teks pada 5.3/5.8 akan mengubah baseline tersebut. Usulan: dokumentasikan dan setujui pengecualian backward compatibility untuk perbaikan formula injection, lalu update golden yang terdampak bersamaan dengan regression test keamanan. **Tidak diperbaiki dalam Fase 0.**
5. **Pola frontend:** Fase 4 harus mempertahankan integrasi public asset + inline history + Echo dan kontrak async yang sudah ada, atau menjelaskan alasan pemindahan ke resources/js. Jangan menganggap sudah ada modul Vite export progress.
6. **CSV settings dibuktikan lewat helper test-only:** probe menetapkan input_encoding dan output_encoding UTF-8 eksplisit. Helper tidak ditambahkan ke allowlist atau produksi. Fase berikutnya masih perlu menghubungkan options/writer, MIME, filename, dan CSV settings pada jalur produksi.

### 11.5 Batas verifikasi dan gerbang berikutnya

- Probe menjalankan chain queued Laravel Excel dengan **sync driver** dan private disk fake; bukan worker database terpisah, deployment, storage remote, atau pembukaan file di Microsoft Excel. Database queue atomic handoff tetap diuji oleh AsyncExportQueueTest, tetapi bukan end-to-end CSV pada database worker.
- Tidak menguji browser/UX, Excel locale OS, dataset 50.000 baris, staging/produksi, migrasi baru, atau cleanup CSV spesifik. Tidak ada dependency maupun fitur Advance Export produksi yang dibuat.
- Scope diff terhadap language-update hanya `tests/Feature/AdvancedExportBaselineTest.php`, `tests/Feature/AdvancedExportQueuedCsvBaselineTest.php`, dan dokumen ini. Commit test dan dokumentasi dipisahkan; tidak merge/push.
- O1 100.000 baris, O3 tiga export aktif/user, O4 tanpa estimasi tetap keputusan untuk fase berikutnya. O2 tidak diputuskan oleh Fase 0; kolom sensitif memerlukan tinjauan pemilik bisnis.
- Fase 0 menyelesaikan golden baseline dan mencatat semua [A] beserta keterbatasannya. **Berhenti setelah Fase 0; Fase 1 tidak dimulai.** Temuan sanitizer Inspections pada 11.4 harus menjadi acuan saat menyetujui perubahan perilaku default berikutnya.

### 11.6 Eksekusi Fase 1 — Fondasi

- Branch `feature/advance-export-phase-1` dibuat dari `feature/advance-export-phase-0`. Metadata `format` (default xlsx) dan nullable JSON `export_options`, DTO readonly scalar, kontrak AcceptsExportOptions, writer type eksplisit, MIME per format, serta format pada history ditambahkan. Constructor export, golden default, allowlist, ownership, hashids, private disk, locale, finalizer, dan atomic database handoff dipertahankan.
- Options pada class yang belum mengimplementasikan kontrak gagal tertutup sebelum penghitungan/query dan handoff. Workbook tetap tidak menerima advanced options (termasuk CSV); tidak mengubah kelas DRP/Transfer/detail/template/PDF. Rollback migration menolak penghapusan metadata ketika CSV/options records masih ada.
- Default O1=100.000, O3=3, preset limit=20 ada pada config. Enforcement limit dipasang pada fase integrasi advanced exports; Fase 1 belum mengubah batas dispatch legacy. Tidak ada endpoint estimasi (O4).
- Gerbang test terbaru: **162 passed, 5.845 assertions, 212,40 detik**, exit 0: AdvancedExportFoundationTest 11, seluruh golden 18 dan CSV probe 2, serta sembilan suite export lama 131. Safety database 2 passed/4 assertions. PHP 8.2.30, Laravel 12.66.0, Composer 2.6.0 teramati; composer validate --no-check-publish lulus. view:cache lulus. Test database menerapkan migration baru; database aplikasi/staging/produksi tidak dimigrasikan.
- Penyimpangan kecil: format history ditampilkan pada Fase 1 sesuai langkah 6.1.5, meskipun kriteria ringkas menyebut tanpa perubahan UI. Ini hanya label format pada history, bukan modal. Runtime CSV produksi dengan options belum tersedia sampai export pilot menerapkan kontrak di Fase 2. Browser, Excel, database worker CSV, dataset besar, dan rollback DDL penuh belum diverifikasi.

### 11.7 Eksekusi Fase 2 — Katalog dan pilot PO

- Branch `feature/advance-export-phase-2` dari Fase 1. Registry allowlist `purchasing.po`/`supplier.po`, katalog statis 10 kolom, resolver, FormRequest, filter bersama, dan trait scalar-only ditambahkan. GET lama dipertahankan; POST ditambahkan pada URI/nama route PO yang sama. Scope supplier tetap berasal dari auth; request audience ditolak. Worker mengotorisasi audience tersimpan terhadap owner dan export menyaring ulang keys.
- Katalog Closure awal ditolak automatic approval review karena risiko serialisasi. Probe PHP read-only membuktikan static catalog tidak masuk payload instance; patch diterima setelah bukti itu dan pembatasan state instance dijelaskan. Test warmed serialization PO en/id kemudian lulus. Tidak mengubah arsitektur static catalog dalam rencana dan tidak menaruh ExportColumn/Closure/definition sebagai properti export.
- Request advanced memeriksa O1 sebelum dispatch, O3 di transaksi dengan lock user, serta row limit sekali lagi di worker. Dispatch tanpa options mempertahankan jalur legacy termasuk tidak menerapkan limit baru; enforcement global legacy belum diputuskan dalam fase pilot. O4 tetap tidak dibuat.
- Filter tanggal relative memakai BusinessTime dan snapshot absolut saat dispatch. Index serta query advanced memakai batas business-day yang dikonversi UTC; query export tanpa options tetap mempertahankan interpretasi tanggal legacy. Purchasing/Supplier index sekarang memakai validator/query filter bersama; global DataTables search didelegasikan ke filter bersama tanpa menghapus column search existing.
- Gerbang: **197 passed, 6.065 assertions, 107,61 detik**, exit 0. Termasuk pilot PO 11 test, fondasi 11, golden 18, CSV probe 2, seluruh suite export lama 131, SupplierDataIsolationTest 11, HashidUrlSecurityTest 6, RouteContractTest 7. TranslationParityTest: **3 passed, 35.243 assertions** (memakai extractor user-facing-copy-inventory dari workflow audit copy tanpa menulis ulang snapshot historis). PHP lint semua file fase, scoped Pint check, view:cache, route:list, dan diff check lulus.
- Pilot membuktikan subset/order, fallback/numeric widths sesuai legacy, eager loads kosong untuk kolom scalar saja, locale, penolakan kolom unknown/required/duplicate, metadata audience purchase-only, spoofing request/stored audience, filter hashid, relative day dan parity index, cancel membuka slot limit, CSV PO 501 baris lintas chunk satu BOM/heading, serta dispatcher → ProcessExportJob → finalizer → file CSV menggunakan sync driver.
- Run pilot awal: 2 gagal/8 lulus (fallback material kosong dan test HTTP belum menyertakan header AJAX). Setelah perbaikan, 1 gagal/10 lulus karena width float vs int; katalog disesuaikan agar default width integral tetap int. Run gerbang di atas lulus. PO belum memiliki kolom internal tambahan: seluruh 10 kolom existing memang tersedia pada export supplier lama; key internal/unknown tidak ditambahkan hanya untuk membuat test.
- Belum diverifikasi: browser/modal (belum dibuat), Excel locale OS, CSV database worker terpisah/remote storage, 50.000 baris, concurrency subprocess limit user, dan query-performance staging. Dataset column-specific search DataTables tidak menjadi preset pada pilot; filter schema hanya field bisnis yang terdaftar.

### 11.8 Eksekusi Fase 3 — Preset pribadi

- Branch `feature/advance-export-phase-3` dari Fase 2. Table/model ExportPreset dengan HasHashids, unique nama per user/key, policy owner+definition, service transaksi/lock user, CRUD/default APIs dan katalog definisi terlokalisasi ditambahkan. Routes memakai auth/role group existing dan no-store; `{preset}` ditambahkan pada HASHED_PARAM_KEYS. Response tidak mengirim integer id atau user_id.
- Preset menyimpan order columns, filter supplier hashid (bukan hasil resolve FK integer), relative mode/days mentah untuk dihitung ulang saat dispatch, dan format. Unknown filters, unauthorized keys, duplicate names, perubahan export_key existing, serta preset user lain ditolak. Service mengunci user sebelum cohort/default mutations sehingga tidak mengandalkan partial index MySQL.
- Run gerbang (exit 0) menjalankan ExportPresetTest **7 lulus**, semua test fase sebelumnya, sembilan suite export lama, seluruh golden, CSV probes, SupplierDataIsolationTest, HashidUrlSecurityTest, RouteContractTest, dan TranslationParityTest. PHP lint file fase, Pint check, route inspection, dan diff check lulus. Run awal preset 1 gagal/6 lulus: key objek JSON MySQL diurutkan ulang; test diperbaiki untuk memeriksa nilai/tipe mode dan days, tanpa melemahkan pemeriksaan urutan array columns.
- Tidak menambah sharing antar user. Kolom preset basi disaring terhadap katalog aktif; required key dipulihkan, fallback default bila semuanya hilang, dan warning dikirim tanpa mengekspos nama kolom yang sudah tidak diizinkan. Definisi/field filter untuk modul selanjutnya akan didaftarkan saat fase modulnya.
- Belum diverifikasi: concurrency multi-process default/limit preset, browser, deployment migration/rollback DDL aplikasi, dan staging/produksi. Test memakai database test. Tidak memperbarui snapshot copy-audit historis di luar scope; TranslationParityTest memakai extraction/placeholder workflow yang tersedia.

### 11.9 Eksekusi Fase 4 — Modal PO

- Branch `feature/advance-export-phase-4` dari Fase 3. Komponen modal bersama dipasang di Purchasing/Supplier PO index; filter/page-query/DataTables search, columns order/checklist, format, CRUD preset/default dan quick export terintegrasi. Calendar/button/icon existing digunakan; Bootstrap mengelola modal, live status/error, tombol keyboard naik/turun menjadi alternatif drag, dan fokus dikembalikan saat modal ditutup.
- SDK async public asset diperluas dengan startExport POST JSON/CSRF dan error callback; monitor/toast, deduplikasi, restore navigasi, cancel dan Blob download dipakai kembali. Modal controller dibundel melalui app.js; legacy GET masih memakai SDK yang sama. Test source assertion DetailExportSecurityTest disesuaikan untuk entrypoint baru, tanpa mengubah export detail.
- Gerbang PHP: **215 passed, 41.615 assertions, 101,72 detik** (exit 0), termasuk AdvancedExportUiTest 8/76 dan seluruh suite fase sebelumnya/export/golden. JS SDK + regional export regressions: **35 passed**. Syntax JS, scoped Pint, view:cache dan diff check lulus. Build Vite lulus; logo existing tidak resolve saat build dan tetap runtime-resolved. Percobaan build sandbox EPERM pada esbuild diulang melalui eskalasi tool dan berhasil; dependency tidak berubah.
- Perubahan UI hanya modal PO dan jalur SDK shared yang diperlukan. Format dipilih user, audience tetap server; request 202 dengan preset columns/filter diuji untuk dua portal/en/id. O4 tidak dibuat. Belum ada verifikasi visual/keyboard/screen reader browser; label ARIA/fokus/drag alternatives hanya diinspeksi dan struktur render diuji. Uji manual Lampiran C tetap tertunda: filter→kolom→format→preset→progres→unduh, focus trap/Escape/restore focus, locale Excel desktop, navigasi lintas halaman/tab, dan 50.000 baris.
