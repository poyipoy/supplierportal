# Implementation Plan — Advanced Export v2 (Opsi 1–5): Streaming OpenSpout, Export Center, Live Preview, Excel/CSV Ergonomics & Shared Team Presets

*Tanggal: 23 November 2026 | Target: ADASI Supplier Portal (Laravel 12 + MySQL + cPanel Single-Server, Tanpa Redis/Octane) | Cakupan: Opsi 1, 2, 3, 4, 5 | Basis: Evidence-First dari Codebase & Deep Research*

## 1. Tujuan & Hard Invariants (Dilarang Dilanggar)

Meningkatkan performa ekspor di cPanel, mempercepat alur kerja pengguna sebelum dan sesudah ekspor, serta menghadirkan kolaborasi preset internal — **tanpa** menambah dependency baru (`openspout/openspout: 4.28.5` sudah terpasang di `composer.json`) dan **tanpa** merusak kontrak ekspor existing.

### Hard Invariants
1. **Atomic Database Queue Handoff Tetap Utuh**: `ExportDispatcher::assertAtomicQueueConfiguration()` tetap memakai `database` driver pada koneksi yang sama dengan `after_commit=false`.
2. **Backward Compatibility Total**:
   - Seluruh GET legacy export, constructor positional export class, dan record `export_jobs.export_options` / `export_presets` lama (tanpa `csv_delimiter` atau `scope`) wajib tetap terbaca valid tanpa error.
   - Workbook khusus (`PaymentBatchDrpExport`, `PaymentBatchTransferExport`, `PrFormExport`, `QuotationFormExport`, `*DetailExport`, `*ImportTemplateExport`) tetap menggunakan jalur existing.
3. **Zero Formula Injection & Strict Tenant Isolation**:
   - Seluruh sel teks pada writer OpenSpout maupun PhpSpreadsheet tetap melewati `SpreadsheetCellSanitizer::text()`.
   - `export_key` dan `audience` selalu ditetapkan oleh server/controller, bukan input browser.
   - **Shared Team Presets (`scope = team`) hanya tersedia untuk user internal** per `export_key` yang diotorisasi; **supplier tidak pernah** bisa membuat, melihat, atau berbagi preset lintas supplier/tenant.
4. **UI, Terjemahan & Database Safety**:
   - Tailwind CSS v4 berprefix `tw:` dengan token `--md-*`/`--ui-*` (tanpa hex mentah), seluruh copy berpasangan di `lang/en/` & `lang/id/`, dan seluruh PHPUnit test berjalan strictly pada `adasi_portal_test` (dilarang menyentuh `adasi_portal`).

---

## 2. Arsitektur Perubahan per Gelombang (3 Waves)

### Wave 1 — Export Center `/exports` + Excel/CSV Ergonomics (Opsi 2 & 4)
*Risiko paling rendah, dampak UX paling cepat terasa.*

1. **Upgrade Export Center (`app/Http/Controllers/ExportDownloadController.php` + `resources/views/exports/index.blade.php`)**:
   - **Progress & Row Counter**: Render progress bar (`progress%`) dan progres baris (`processed_rows / total_rows`) untuk job berstatus `queued`/`processing` pada render Blade awal maupun polling JS (field sudah tersedia di `ExportDownloadController::serialize()`).
   - **Tombol Batalkan (Cancel)**: Render tombol Cancel pada job pending yang memanggil endpoint existing `POST /exports/{exportJob}/cancel` (`exports.cancel`) dengan CSRF token, busy state, dan refresh baris otomatis.
   - **1-Click Jalankan Ulang (Re-run / Clone)**:
     - Tambah route `POST /exports/{exportJob}/rerun` (`exports.rerun`) di `routes/web.php`.
     - Di `ExportDownloadController::rerun()`: verifikasi `authorizeDomain()`, ownership `user_id`, validasi ulang `ExportOptionsResolver::authorizeStored()` (bila `export_options !== null`), lalu dispatch job baru lewat `ExportDispatcher::dispatch()` sehingga batas `max_rows` (100.000) dan `max_concurrent_per_user` (5) selalu ditegakkan ulang secara segar.
     - Render tombol **Jalankan Ulang** untuk job berstatus `completed` (khususnya yang sudah expired), `failed`, atau `cancelled`.
   - **Filter & Safe Failure Message**: Tambahkan filter `?status=` dan `?format=` di `/exports`, serta tampilkan pesan gagal terlokalisasi yang aman (tanpa stack trace mentah) pada job `failed`.

2. **Ergonomi XLSX & Delimiter CSV Lokal Indonesia (`ExportOptions`, `StylesOperationalWorkbook`, `advanced-modal.blade.php`)**:
   - **Backward-Compatible `csvDelimiter` di `app/Support/Export/ExportOptions.php`**:
     - Tambah properti opsional `public string $csvDelimiter = ','` (hanya izinkan `','` atau `';'`).
     - `ExportOptions::fromArray()` tetap menerima payload lama tanpa key `csv_delimiter` dengan default `','`.
   - **Validasi & UI Selector**:
     - Perbarui `AdvancedExportRequest` (`options.csv_delimiter` → `Rule::in([',', ';'])`), `ExportPresetController`, `ExportPresetService`, dan `UsesColumnCatalog::getCsvSettings()` agar memakai delimiter dari `$options->csvDelimiter` (dengan `use_bom = true`).
     - Di `resources/views/components/export/advanced-modal.blade.php` & `resources/js/advanced-export.js`: tampilkan dropdown **Pemisah CSV** (`Koma (,)` / `Titik koma (;) — Excel Indonesia`) hanya saat format `CSV` dipilih; default otomatis `';'` bila locale user `id` (tetap bisa diubah manual & disimpan di preset).
   - **Freeze Header, AutoFilter & Number Formatting pada XLSX**:
     - Di `app/Exports/Concerns/StylesOperationalWorkbook.php` (dan di writer OpenSpout pada Wave 2): aktifkan `$sheet->freezePane('A2')` dan `$sheet->setAutoFilter('A1:'.$lastColumn.'1')` saat `$this->workbookPresentationEnabled()` aktif.
     - Petakan `ColumnType::Money` (`#,##0.00`) dan `ColumnType::Number` (`#,##0.##`) secara konsisten pada ekspor katalog kolom (tanpa mengubah output legacy non-options agar golden test lama tetap lulus).

---

### Wave 2 — Pre-Export Row Count Preview + Shared Team Presets & Column Quick-Search (Opsi 3 & 5)

1. **Pre-Export Row Count Preview (`POST /exports/preview-count`)**:
   - **Backend (`app/Http/Controllers/ExportPreviewController.php` + `routes/web.php`)**:
     - Daftarkan `POST /exports/preview-count` (`exports.preview-count`) dengan middleware `throttle:30,1,exports.preview-count` dan `no-store`.
     - Validasi `export_key` → otorisasi lewat `ExportDefinitions::get($key)->authorize($user)` → bangun query filter menggunakan class `*ExportFilters::validated()` yang sama persis dengan controller export (dan paksa `supplier_id = $user->id` untuk audience `supplier.*`) → panggil `$export->progressTotalRows()`.
     - Kembalikan JSON: `{ total_rows, max_rows, exceeds_limit, is_empty }`.
   - **Frontend (`advanced-modal.blade.php` + `resources/js/advanced-export.js`)**:
     - Panggil preview secara debounced (400 ms) memakai `AbortController` + `{ priority: 'low' }` saat modal dibuka dan setiap kali filter berubah.
     - Tampilkan ringkasan langsung di footer/header modal (contoh: `1.240 baris cocok · 12 kolom · XLSX`), tampilkan peringatan ramah jika `0` baris, dan nonaktifkan tombol Export disertai pesan jelas bila `exceeds_limit === true`.

2. **Shared Team Presets (Internal Only) & Column Quick-Search**:
   - **Migrasi Non-Destruktif (`database/migrations/YYYY_MM_DD_HHMMSS_add_scope_and_delimiter_to_export_presets_table.php`)**:
     - Tambah kolom `scope` (`string(16)->default('personal')`) dan `csv_delimiter` (`string(4)->default(',')`) pada `export_presets`.
     - Tambah indeks komposit `(export_key, scope)` untuk query preset tim yang cepat.
   - **Kebijakan & Layanan (`ExportPresetPolicy.php` + `ExportPresetService.php` + `ExportPresetController.php`)**:
     - **Isolasi Mutlak**: Audience `supplier.*` hanya boleh `scope = 'personal'` (server menolak `scope = 'team'` dengan 403/422).
     - Untuk internal (`purchasing.*`, `qc.*`, `finance.*`, `accounting.*`): `ExportPresetController::index()` mengembalikan dua grup (`personal` milik user + `team` untuk `export_key` yang sama).
     - Pembuatan/edit/hapus preset `team` dibatasi pada role berwenang (`admin` / manager internal melalui `ExportPresetPolicy`).
     - Tambah endpoint/aksi **Duplikasi ke Preset Pribadi** agar user internal bisa memodifikasi preset tim tanpa menimpa standar milik tim ([Humbleteam B2B Saved Views Governance](https://humbleteam.com/blog/b2b-saas-data-table-filters-saved-views)).
   - **Column Quick-Search (`advanced-modal.blade.php` + `resources/js/export-column-designer.js`)**:
     - Tambah input pencarian kolom (`Cari kolom...`) dan tombol cepat (`Default`, `Semua`, `Minimal`) di ribbon Column Designer tanpa merusak navigasi keyboard, drag-and-drop, atau pengumuman `aria-live`.

---

### Wave 3 — OpenSpout Single-Pass Streaming Writer untuk List Exports di cPanel (Opsi 1)

*Mengganti pola reopen-per-chunk PhpSpreadsheet ([Fast Excel vs Laravel Excel Benchmark](https://medium.com/codex/fast-excel-vs-laravel-excel-which-one-survives-a-million-row-export-62c265a1c76a), [Spreadsheet Streaming Analysis](https://turgutahmet.dev/blogs/en/we-taught-a-spreadsheet-to-answer-queries)) memakai library [OpenSpout 4.28.5](https://github.com/openspout/openspout) yang sudah terpasang di `composer.json`.*

1. **Konfigurasi Driver (`config/exports.php`)**:
   - Tambah `'list_writer' => env('EXPORTS_LIST_WRITER', 'openspout')` (pilihan: `'openspout'` | `'maatwebsite'`), sehingga rollback ke `Excel::queue` bisa dilakukan instan lewat `.env` bila diperlukan.
2. **Service Streaming (`app/Services/Export/OpenSpoutListWriter.php`)**:
   - Mendukung semua `LIST_EXPORT_CLASSES` berbasis `FromQuery` (`PurchaseOrdersExport`, `QuotationsExport`, `RequisitionsExport`, `ShipmentsExport`, `InspectionsExport`, `LocalInvoicesExport`); `SupplierPriceHistoryExport` (`FromCollection`) tetap berjalan bersih melalui koleksi/fallback.
   - **Query Deterministik**: Menggunakan `chunkById()` / chunk terurut per `500`–`1.000` baris (mencegah *offset drift* saat data berubah, sesuai [Kirschbaum Laravel CSV Export](https://kirschbaumdevelopment.com/insights/how-to-build-a-csv-export-system-with-laravel)).
   - **Progress & Cancellation per Chunk**: Di setiap selesai 1 chunk, periksa status `ExportJob::STATUS_CANCELLED` (hentikan & hapus temp file segera jika dibatalkan) dan panggil `$progress->recordChunk($exportJobId, "stream-{$chunkIndex}", $chunkCount)`.
   - **Output XLSX (OpenSpout `Writer\XLSX\Writer`)**:
     - Header row tebal putih dengan latar `#9C4A0F`, lebar kolom dari `catalogWidths()`, format angka `ColumnType::Money`/`Number`, **Freeze Header Row (`SheetView::setFreezeRow(2)`)**, dan **AutoFilter** pada baris header.
   - **Output CSV (OpenSpout `Writer\CSV\Writer`)**:
     - `FIELD_DELIMITER` dari `ExportOptions::$csvDelimiter`, `SHOULD_ADD_BOM = true`, UTF-8, single-pass tanpa reopen file.
   - **Atomic File Store**: Tulis ke temp file lokal (`storage/app/private/exports/.tmp/...`) lalu pindahkan secara atomik ke `$path` akhir sebelum memanggil `FinalizeExportJob`.
3. **Integrasi Worker (`app/Jobs/StreamListExportJob.php` + `app/Jobs/ProcessExportJob.php`)**:
   - Di `ProcessExportJob::handle()`: jika `config('exports.list_writer') === 'openspout'` dan export termasuk `FromQuery` list export, handoff ke `StreamListExportJob` (diikuti `FinalizeExportJob`) alih-alih `Excel::queue`.
   - Jaga `timeout = 600` (lokal) dan kompatibilitas penuh dengan cron worker cPanel (`queue:work --queue=exports,imports,default --stop-when-empty --max-time=50`).

---

## 3. Oracle Verifikasi & Kriteria Selesai (Wajib Exit Code 0)

1. **Contract, Security & Tenant Isolation Tests (`php artisan test` di `adasi_portal_test`)**:
   - Seluruh suite existing lulus: `TestingEnvironmentDatabaseSafetyTest`, `AsyncExportQueueTest`, `DetailExportSecurityTest`, `AdvancedExport*Test`, `ExportPresetTest`, `LocalizationExportTest`, `TranslationParityTest`.
   - Test baru wajib lulus:
     - `ExportCenterRerunAndCancelTest`: uji cancel job pending, re-run job expired/failed, anti-IDOR lintas user & lintas domain (Local vs Import), serta penegakan ulang `max_rows` & `max_concurrent_per_user`.
     - `ExportPreviewCountTest`: uji akurasi hitungan filter, paksaan scope `supplier_id`, deteksi `exceeds_limit`, dan rate limit.
     - `SharedExportPresetTest`: uji preset `team` terlihat oleh rekan internal di `export_key` yang sama, **ditolak 403/422 untuk supplier**, duplikasi ke `personal`, dan kompatibilitas preset lama tanpa `scope`/`csv_delimiter`.
     - `OpenSpoutStreamingExportTest`: uji output XLSX (frozen pane `A2`, AutoFilter, formula injection `=`, `+`, `-`, `@` ter-escape, format angka) dan CSV (delimiter `,` vs `;`, UTF-8 BOM tepat 1×, multi-chunk urutan deterministik, pembatalan di tengah chunk membersihkan temp file).
2. **Frontend & Column Designer Tests (`node --test`)**:
   - `node --test tests/js/advanced-export-columns.test.mjs tests/js/advanced-export-format.test.mjs tests/js/advanced-export-sdk.test.mjs tests/js/advanced-export-csv-timeout.test.mjs tests/js/export-column-state.test.mjs tests/js/regional-export-history.test.mjs` lulus, termasuk test baru untuk pencarian kolom, selector delimiter CSV, dan debounced preview count.
3. **Build, View Cache & Benchmark Memori Lokal**:
   - `npm.cmd run build` dan `php artisan view:cache && php artisan view:clear` lulus (`exit 0`).
   - Benchmark pembanding lokal (mis. 10.000 baris): catat `peak_memory_mb` dan durasi `openspout` vs `maatwebsite` untuk membuktikan penurunan memori signifikan sebelum deploy via FileZilla + cPanel Cron.
