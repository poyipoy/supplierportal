# Rancangan Implementasi Pembaruan Menu Reference Prices

**Proyek:** ADASI Supplier Portal (`supplierportal`)  
**Modul:** Purchasing — Reference Prices (`/purchasing/reference-prices`)  
**Tanggal Dokumen:** 12 November 2026  
**Status:** Final — Menunggu Eksekusi Kode  

---

## Daftar Isi
1. [Ruang Lingkup Pembaruan](#1-ruang-lingkup-pembaruan)
2. [Ringkasan Temuan di Repositori per Poin (1–5)](#2-ringkasan-temuan-di-repositori-per-poin-15)
3. [Keputusan Arsitektur & Desain per Poin (1–5)](#3-keputusan-arsitektur--desain-per-poin-15)
4. [Rancangan Implementasi Bertahap (Tahap 1–5)](#4-rancangan-implementasi-bertahap-tahap-15)
5. [Daftar Lengkap Berkas yang Dibuat, Diubah, dan Dihapus](#5-daftar-lengkap-berkas-yang-dibuat-diubah-dan-dihapus)
6. [Spesifikasi Perubahan Database, API & Strategi Rollback](#6-spesifikasi-perubahan-database-api--strategi-rollback)
7. [Rencana Pengujian & Verifikasi Regresi](#7-rencana-pengujian--verifikasi-regresi)
8. [Matriks Risiko dan Mitigasi](#8-matriks-risiko-dan-mitigasi)

---

## 1. Ruang Lingkup Pembaruan

Pembaruan pada menu **Reference Prices** mencakup 5 poin utama:
1. **Penerapan `server-tabs` (`Poin 1`)**: Mengganti navigasi tab manual (*Rules & Categories*, *Historical Benchmark*, *Legacy Archive*) dengan arsitektur komponen `server-tabs` standar proyek.
2. **Standarisasi Fitur Import (`Poin 2`)**: Menggunakan kembali (*reuse*) komponen dialog dan unggah berkas standar proyek (`<x-ui.dialog>`, `<x-ui.file-upload>`, `<x-ui.alert>`) serta menyederhanakan alur backend import agar memperbarui aturan harga aktif secara langsung tanpa pembuatan versi.
3. **Redesign Action Button Non-Ghost (`Poin 3`)**: Menghapus pemakaian *ghost button* pada seluruh tombol aksi di menu Reference Prices dan menggantinya dengan variant ber-border/surface tegas sesuai fungsi semantik beserta peningkatan aksesibilitas (`aria-label`, `title`, *focus ring*).
4. **Penghapusan Total Fitur Versioning (`Poin 4`)**: Menghapus fitur versioning dari tampilan UI, route, controller, service/resolver, model, hingga skema database dengan mempertahankan data aturan dari versi aktif terakhir.
5. **Penyeragaman Font `Source Sans Pro` (`Poin 5`)**: Menghapus seluruh pemakaian class monospace (`font-mono`) pada menu Reference Prices yang menyebabkan sebagian teks/angka belum merender font standar sistem (`Source Sans Pro`), serta menerapkan `tabular-nums` untuk kerapian angka dan dimensi.

---

## 2. Ringkasan Temuan di Repositori per Poin (1–5)

### 2.1. Poin 1 — Server-Tabs (`server-tabs`)
* **Arsitektur Standar di Repositori**:
  * **Script Frontend**: `resources/js/server-tabs.js` (di-bundle melalui `resources/js/app.js`), bertugas menangani klik tab secara *asynchronous* (`fetch` dengan header `X-Server-Tabs`), memperbarui partial navigasi dan konten tanpa *full page reload*, melakukan *prefetch* saat hover/focus, dan menyinkronkan URL via `History API`.
  * **Responder Backend**: `app/Support/ServerTabsResponse.php`, mendeteksi request `server-tabs` dan mengembalikan payload JSON berisi potongan HTML partial (`nav` dan `content`) atau merender halaman penuh saat diakses langsung.
  * **Komponen UI Blade**: `resources/views/components/ui/tabs.blade.php` (`<x-ui.tabs>`) dan `resources/views/components/ui/tab-list.blade.php` (`<x-ui.tab-list>`).
* **Referensi Implementasi di Menu Lain**:
  * `resources/views/admin/exchange-rates/index.blade.php` (`_tabs.blade.php` + `_content.blade.php` + `ExchangeRateController.php`)
  * `resources/views/purchasing/import-suppliers/index.blade.php` (`_queue_nav.blade.php` + `_queue_content.blade.php` + `ImportSupplierController.php`)
  * `resources/views/purchasing/supplier-audits/index.blade.php` (`_queue_nav.blade.php` + `_queue_content.blade.php` + `SupplierAuditController.php`)
  * `resources/views/finance/master-invoices/index.blade.php` (`_status_nav.blade.php` + `_content.blade.php`)
* **Kondisi Aktual di Menu Reference Prices**:
  * Seluruh tab (*Rules & Categories*, *Historical Benchmark*, *Legacy Archive*) masih ditulis secara monolitik di dalam `resources/views/purchasing/reference-prices/index.blade.php` dengan elemen tautan yang menampilkan garis bawah (`underline`) pada ketiga tab sekaligus dan belum terhubung ke `ServerTabsResponse` maupun `resources/js/server-tabs.js`.

---

### 2.2. Poin 2 — Fitur Import (`Import`)
* **Kondisi Aktual di Menu Reference Prices**:
  * Markup modal dan input file masih dibuat khusus di dalam `resources/views/purchasing/reference-prices/index.blade.php`.
  * Backend ditangani oleh `app/Services/Pricing/ReferencePriceRuleImportService.php` dan `app/Models/ReferencePriceImport.php` menggunakan `app/Support/SpreadsheetImportReader.php` dan `app/Support/SpreadsheetCellSanitizer.php`.
  * Alur konfirmasi import saat ini membuat record versi baru pada `reference_price_rule_sets` dan menyimpan referensinya di `reference_price_imports.confirmed_rule_set_id`.
* **Komponen Shared yang Tersedia untuk Di-reuse**:
  * `resources/views/components/ui/dialog.blade.php` (`<x-ui.dialog>`)
  * `resources/views/components/ui/file-upload.blade.php` (`<x-ui.file-upload>` + `resources/js/file-upload.js`)
  * `resources/views/components/ui/alert.blade.php` (`<x-ui.alert>`)
  * Pola partial import serupa pada `resources/views/purchasing/pr/_import.blade.php`, `resources/views/purchasing/pr/_import_controls.blade.php`, dan `resources/views/finance/local-procurement/_import_po_modal.blade.php`.
* **Perbandingan Implementasi Saat Ini vs Komponen Standar Proyek**:

| Aspek | Implementasi Reference Prices Saat Ini | Komponen Import Standar Proyek | Kebutuhan Penyesuaian |
| :--- | :--- | :--- | :--- |
| **Container Modal** | Markup modal lokal di `index.blade.php` | `<x-ui.dialog>` | Gunakan `<x-ui.dialog>` standar proyek. |
| **Input & Dropzone** | Input file HTML manual | `<x-ui.file-upload>` + `resources/js/file-upload.js` | Ganti input file dengan `<x-ui.file-upload>` agar tampilan dropzone, batas ukuran, dan validasi ekstensi seragam. |
| **Validasi & Error** | Disimpan di `reference_price_imports.validation_errors` | Ditampilkan dengan `<x-ui.alert>` / `<x-ui.field-error>` & tabel preview standar | Pertahankan validasi domain (`dimension_min > 0`, `dimension_max >= dimension_min`, bebas tumpang-tindih), tampilkan dengan komponen alert standar. |
| **Eksekusi Konfirmasi** | Membuat versi baru di `reference_price_rule_sets` | Transactional update/replace langsung pada tabel master | Ubah `ReferencePriceRuleImportService` agar langsung memperbarui `reference_price_rules` di dalam `DB::transaction()` tanpa membuat versi baru. |

---

### 2.3. Poin 3 — Desain Action Button (`Action button`)
* **Komponen UI Proyek**:
  * `resources/views/components/ui/button.blade.php` (`<x-ui.button>`)
  * `resources/views/components/ui/icon-button.blade.php` (`<x-ui.icon-button>`)
  * `resources/views/components/ui/icon.blade.php` (`<x-ui.icon>`) & `app/View/Components/Ui/Icon.php`
* **Inventaris Aksi pada Menu Reference Prices**:
  1. **Header Halaman**:
     * `+ Add Category`: Variant `secondary` / `outline` (sudah non-ghost).
     * `Import Excel`: Variant `primary` / `solid` (sudah non-ghost).
  2. **Kolom `ACTIONS` pada Tabel `Rules & Categories`**:
     * **Toggle Status (`eye-off` Deactivate / `eye` Activate)**: Saat ini *icon-only* dengan style `ghost` (tanpa border dan tanpa background).
     * **Edit Category (`pencil`)**: Saat ini *icon-only* dengan style `ghost` (tanpa border dan tanpa background).
  3. **Tabel pada Tab `Historical Benchmark` & `Legacy Archive`**:
     * Aksi baris seperti **Edit**, **Supersede**, dan **Void** (terkait `SaveMaterialReferencePriceRequest`, `SupersedeMaterialReferencePriceRequest`, `VoidMaterialReferencePriceRequest`) yang menggunakan style `ghost`.

---

### 2.4. Poin 4 — Fitur Versioning (`Versioning`)
Berdasarkan migrasi `2026_11_11_000005_create_reference_price_rules_tables.php` dan struktur direktori aplikasi, fitur versioning terkait dengan komponen berikut:
1. **Tabel Database**:
   * `reference_price_rule_sets`: Menyimpan header versi (`id`, `version_no`, `status`, `import_mode`, `source_file_name`, `source_file_hash`, `source_sheet`, `created_by`, `activated_by`, `activated_at`).
   * `reference_price_settings`: Menyimpan pointer `active_rule_set_id` (FK ke `reference_price_rule_sets`).
   * `reference_price_rules`: Menyimpan kategori dimensi dengan FK `rule_set_id` ke `reference_price_rule_sets`, unique constraint `ref_rules_set_key_unique` (`['rule_set_id', 'category_key']`), index `ref_rules_set_status_dim_idx` (`['rule_set_id', 'status', 'dimension_min', 'dimension_max']`), dan check constraint `ref_rules_dimension_range_check` (`dimension_min > 0 AND dimension_max >= dimension_min`).
   * `reference_price_imports`: Memiliki kolom FK `confirmed_rule_set_id` ke `reference_price_rule_sets`.
2. **Models & Policies**:
   * `app/Models/ReferencePriceRuleSet.php`
   * `app/Models/ReferencePriceSetting.php`
   * `app/Models/ReferencePriceRule.php`
   * `app/Models/ReferencePriceImport.php`
   * `app/Models/AwardReferencePriceSnapshot.php`
   * `app/Policies/ReferencePriceRuleSetPolicy.php` & `app/Policies/ReferencePriceRulePolicy.php`
3. **Services, Support & Controllers**:
   * `app/Services/Pricing/ReferencePriceRuleResolver.php` & `app/Services/Pricing/ReferencePriceRuleResult.php`
   * `app/Services/Pricing/ReferencePriceRuleImportService.php`
   * `app/Services/Pricing/ReferencePriceBenchmarkResolver.php` & `app/Services/Pricing/ReferencePriceBenchmarkResult.php`
   * `app/Services/Pricing/ReferencePriceResolver.php`, `HistoricalReferencePriceService.php`, `MaterialReferencePriceService.php`, dan `app/Support/Pricing/ReferencePriceResult.php`
   * `app/Services/PrItemAwardService.php`
   * `app/Http/Controllers/Purchasing/MaterialReferencePriceController.php`
   * `app/Http/Controllers/Purchasing/HistoricalPriceComparisonController.php` & `app/Http/Controllers/Purchasing/Concerns/PriceComparisonSharedTrait.php`
4. **UI & Bahasa**:
   * `resources/views/purchasing/reference-prices/index.blade.php` (Kartu banner `Version 4 Active` beserta metadata aktivasi dan dropdown pemilih versi).
   * `resources/views/procurement/_reference_snapshot_panel.blade.php`
   * `lang/en/purchasing.php` & `lang/id/purchasing.php`

---

### 2.5. Poin 5 — Font `Source Sans Pro` (`Font`)
* **Kondisi Aktual**:
  * Aplikasi secara global sudah menggunakan font `Source Sans Pro` (`resources/css/app.css` & `resources/views/layouts/app.blade.php`).
  * Namun, pada halaman **Reference Prices** (`resources/views/purchasing/reference-prices/index.blade.php` dan partial terkait), masih terdapat pemakaian class `font-mono` pada kolom `CATEGORY KEY` (`1-15`), dimensi (`1 mm`, `15 mm`), nilai benchmark harga, serta elemen preview import yang memaksa browser merender font monospace alih-alih `Source Sans Pro`.

---

## 3. Keputusan Arsitektur & Desain per Poin (1–5)

### 3.1. Keputusan Poin 1 — Server-Tabs
* **Keputusan**: Gunakan penuh arsitektur `server-tabs` (`<x-ui.tabs>` / `<x-ui.tab-list>` + `resources/js/server-tabs.js` + `app/Support/ServerTabsResponse.php`) dengan memisahkan view menjadi `index.blade.php`, `_tabs.blade.php`, dan `_content.blade.php` untuk 3 tab:
  1. `rules` → **Rules & Categories**
  2. `benchmark` → **Historical Benchmark**
  3. `legacy` → **Legacy Archive**
* **Alasan**: 100% konsisten dengan menu `admin/exchange-rates`, `purchasing/import-suppliers`, dan `purchasing/supplier-audits`, serta menghilangkan tampilan garis bawah ganda pada teks tab.
* **Alternatif yang Ditolak**: Memodifikasi CSS pada tautan tab manual di `index.blade.php` (ditolak karena tidak menggunakan `ServerTabsResponse` dan `server-tabs.js`).
* **Risiko & Mitigasi**: Risiko hilangnya filter query saat berpindah tab dimitigasi dengan meneruskan parameter query tab aktif melalui `ServerTabsResponse`.

### 3.2. Keputusan Poin 2 — Import
* **Keputusan**: Gunakan komponen shared `<x-ui.dialog>`, `<x-ui.file-upload>`, dan `<x-ui.alert>` untuk modal import Reference Prices, serta ubah `ReferencePriceRuleImportService` agar menerapkan hasil konfirmasi import langsung ke tabel `reference_price_rules` tanpa membuat `ReferencePriceRuleSet`.
* **Alasan**: Menjamin keseragaman tampilan dropzone, validasi ukuran/format file, tampilan error baris, dan feedback sukses dengan fitur import lainnya di proyek.
* **Alternatif yang Ditolak**: Membuat komponen Blade import baru khusus Reference Prices (ditolak karena menduplikasi fungsi `<x-ui.file-upload>`).
* **Risiko & Mitigasi**: Risiko kegagalan parsial saat update aturan dimitigasi dengan membungkus seluruh operasi tulis di dalam `DB::transaction()`.

### 3.3. Keputusan Poin 3 — Action Button
* **Keputusan**: Hapus seluruh penggunaan style `ghost` pada action button di menu Reference Prices dan terapkan skema variant berikut:
  1. **Header Actions**:
     * `+ Add Category`: Variant `secondary` / `outline`.
     * `Import Excel`: Variant `primary` / `solid`.
  2. **Row Action — Edit (`pencil`)**:
     * Variant **`secondary` / `outline` (`xs`/`sm`)**: `bg-white border border-slate-300 text-slate-700 hover:bg-slate-100 hover:border-slate-400 focus-visible:ring-2 focus-visible:ring-slate-400 shadow-2xs`.
  3. **Row Action — Toggle Status (`eye-off` Deactivate / `eye` Activate)**:
     * Saat baris `active` (aksi **Deactivate**): Variant **`warning-outline` (`xs`/`sm`)** (`bg-amber-50 border border-amber-200 text-amber-700 hover:bg-amber-100 hover:border-amber-300 focus-visible:ring-2 focus-visible:ring-amber-500`).
     * Saat baris `inactive` (aksi **Activate**): Variant **`success-outline` (`xs`/`sm`)** (`bg-emerald-50 border border-emerald-200 text-emerald-700 hover:bg-emerald-100 hover:border-emerald-300 focus-visible:ring-2 focus-visible:ring-emerald-500`).
  4. **Row Action — Void / Delete / Destruktif**:
     * Pada baris tabel: Variant **`danger-outline` (`xs`/`sm`)** (`bg-red-50 border border-red-200 text-red-600 hover:bg-red-100 hover:border-red-300 focus-visible:ring-2 focus-visible:ring-red-500`).
     * Pada tombol konfirmasi akhir di modal: Variant **`danger` / `solid`** (`bg-red-600 text-white hover:bg-red-700`).
  5. **Standar Aksesibilitas**:
     * Setiap tombol *icon-only* wajib memiliki `title="..."`, `aria-label="..."` spesifik per baris (mis. `aria-label="Edit category 1-15"`), dan ukuran klik minimal `32px x 32px` (`h-8 w-8`).
* **Alasan**: Memberikan petunjuk visual (*affordance*) yang jelas bahwa elemen tersebut adalah tombol interaktif sekaligus membedakan tingkat risiko aksi.
* **Alternatif yang Ditolak**: Mengubah semua tombol baris menjadi tombol `solid` penuh (ditolak karena menimbulkan *visual noise* berlebihan pada tabel).

### 3.4. Keputusan Poin 4 — Versioning
* **Perbandingan 3 Opsi**:
  * **Opsi (a) Hapus di UI saja**: Menyisakan *dead logic* dan pembuatan versi bayangan saat import. *(Ditolak)*
  * **Opsi (b) Hapus di UI + Logic tanpa mengubah DB**: Menyebabkan kegagalan insert karena kolom `rule_set_id` bersifat `NOT NULL` dan memiliki constraint unik komposit. *(Ditolak)*
  * **Opsi (c) Hapus Total (UI + Logic + Skema DB & Migrasi Data)**: Menyederhanakan model domain menjadi satu kumpulan aturan aktif (*single active dataset*) dengan constraint unik langsung pada `category_key`. *(Dipilih)*
* **Keputusan Penanganan Data Eksisting**:
  1. Pertahankan hanya baris `reference_price_rules` yang terikat pada rule set aktif saat ini dengan urutan prioritas:
     * **Prioritas 1**: `reference_price_settings.active_rule_set_id`
     * **Prioritas 2 (Fallback)**: `reference_price_rule_sets.id` dengan `status = 'active'` (`ORDER BY activated_at DESC, version_no DESC, id DESC LIMIT 1`)
     * **Prioritas 3 (Fallback Terakhir)**: `reference_price_rule_sets.id` dengan `ORDER BY version_no DESC, id DESC LIMIT 1`
  2. Hapus baris `reference_price_rules` milik versi non-aktif/arsip lainnya sebelum melepas kolom `rule_set_id`.
  3. Drop kolom `rule_set_id` dari `reference_price_rules`, drop kolom `confirmed_rule_set_id` dari `reference_price_imports`, lalu drop tabel `reference_price_settings` dan `reference_price_rule_sets`.

### 3.5. Keputusan Poin 5 — Font `Source Sans Pro`
* **Keputusan**: Hapus seluruh class `font-mono` (dan class font non-`Source Sans Pro` lainnya) pada seluruh file Blade di menu Reference Prices (`index.blade.php`, `_tabs.blade.php`, `_content.blade.php`, modal, dan partial snapshot), lalu ganti dengan pewarisan font standar sistem (`Source Sans Pro`) ditambah class `tabular-nums` pada angka/dimensi/harga.
* **Alasan**: Sistem utama sudah menerapkan `Source Sans Pro`; penghapusan `font-mono` memastikan 100% elemen di menu Reference Prices merender `Source Sans Pro` tanpa kehilangan kerapian perataan digit angka.

---

## 4. Rancangan Implementasi Bertahap (Tahap 1–5)

### Tahap 1 — Migrasi Database & Pembersihan Model/Policy Versioning (Poin 4)
* **Dependensi**: Persetujuan eksekusi.
* **Langkah Kerja**:
  1. Buat migrasi `database/migrations/2026_11_12_000001_remove_versioning_from_reference_price_rules.php`.
  2. Konsolidasikan data `reference_price_rules` ke rule set aktif, lepas FK & index terkait `rule_set_id`, pasang unique index baru `ref_rules_category_key_unique` (`['category_key']`) dan index `ref_rules_status_dim_idx` (`['status', 'dimension_min', 'dimension_max']`).
  3. Lepas FK & kolom `confirmed_rule_set_id` pada `reference_price_imports`.
  4. Drop tabel `reference_price_settings` dan `reference_price_rule_sets`.
  5. Perbarui `app/Models/ReferencePriceRule.php` dan `app/Models/ReferencePriceImport.php`.
  6. Hapus `app/Models/ReferencePriceRuleSet.php`, `app/Models/ReferencePriceSetting.php`, dan `app/Policies/ReferencePriceRuleSetPolicy.php`, serta bersihkan referensinya di `app/Providers/AppServiceProvider.php` dan `config/hashids.php`.
* **Kriteria Selesai**:
  * `php artisan migrate --pretend` dan `php artisan migrate` berjalan sukses.
  * Seluruh aturan dari versi aktif terakhir tersimpan utuh di `reference_price_rules` dengan `category_key` unik.

### Tahap 2 — Refactoring Service, Resolver & Import Backend (Poin 2 & Poin 4)
* **Dependensi**: Selesai Tahap 1.
* **Langkah Kerja**:
  1. Refactor `app/Services/Pricing/ReferencePriceRuleResolver.php` dan `app/Services/Pricing/ReferencePriceRuleResult.php` agar mengambil aturan langsung dari `ReferencePriceRule::query()->where('status', 'active')->orderBy('sort_order')->orderBy('dimension_min')` tanpa mengecek `ReferencePriceSetting` / `ReferencePriceRuleSet`.
  2. Bersihkan properti versi (`rule_set_id`, `version_no`, `activated_at`, `activated_by`) pada `ReferencePriceBenchmarkResolver.php`, `ReferencePriceBenchmarkResult.php`, `ReferencePriceResolver.php`, `HistoricalReferencePriceService.php`, `app/Support/Pricing/ReferencePriceResult.php`, dan `PrItemAwardService.php`.
  3. Refactor `app/Services/Pricing/ReferencePriceRuleImportService.php` agar saat konfirmasi import langsung melakukan upsert/replace ke tabel `reference_price_rules` di dalam `DB::transaction()` tanpa membuat `ReferencePriceRuleSet`.
* **Kriteria Selesai**:
  * Tidak ada lagi pemanggilan `ReferencePriceRuleSet` atau `ReferencePriceSetting` di seluruh folder `app/Services/` dan `app/Support/`.
  * Alur upload, preview diff, validasi rentang dimensi, dan konfirmasi import berjalan atomik langsung ke `reference_price_rules`.

### Tahap 3 — Refactoring Controller, Routes & Integrasi `server-tabs` + Modal Import Shared (Poin 1, 2, 4)
* **Dependensi**: Selesai Tahap 2.
* **Langkah Kerja**:
  1. Bersihkan route aktivasi/perpindahan versi di `routes/web.php`.
  2. Refactor `app/Http/Controllers/Purchasing/MaterialReferencePriceController.php`:
     * Hapus parameter versi dan query daftar versi.
     * Gunakan `app/Support/ServerTabsResponse.php` pada action `index()` untuk merender tab `rules`, `benchmark`, dan `legacy`.
  3. Buat `resources/views/purchasing/reference-prices/_tabs.blade.php` menggunakan `<x-ui.tabs>` / `<x-ui.tab-list>`.
  4. Buat `resources/views/purchasing/reference-prices/_content.blade.php` berisi konten masing-masing tab tanpa banner versi dan tanpa dropdown versi.
  5. Perbarui `resources/views/purchasing/reference-prices/index.blade.php` agar memuat wrapper `data-server-tabs` serta mengganti modal import lokal dengan `<x-ui.dialog>`, `<x-ui.file-upload>`, dan `<x-ui.alert>`.
* **Kriteria Selesai**:
  * Ketiga tab berpindah secara dinamis menggunakan `resources/js/server-tabs.js` tanpa *full page reload* dan tanpa garis bawah ganda.
  * Kartu informasi versi dan dropdown versi tidak lagi muncul.
  * Modal Import Excel menggunakan komponen `<x-ui.dialog>` dan `<x-ui.file-upload>` standar proyek.

### Tahap 4 — Redesign Action Button Non-Ghost & Aksesibilitas (Poin 3)
* **Dependensi**: Selesai Tahap 3.
* **Langkah Kerja**:
  1. Pastikan `resources/views/components/ui/icon-button.blade.php` mendukung variant `secondary` (outline), `warning-outline` (amber), `success-outline` (emerald), dan `danger-outline` (red) secara *backward-compatible*.
  2. Terapkan variant non-ghost pada seluruh tombol aksi baris di `resources/views/purchasing/reference-prices/_content.blade.php` (Edit Category, Activate/Deactivate Category, serta aksi pada tab *Historical Benchmark* dan *Legacy Archive*).
  3. Tambahkan atribut `title`, `aria-label` kontekstual per baris, dan `focus-visible:ring-2` pada setiap tombol aksi.
* **Kriteria Selesai**:
  * Seluruh tombol aksi di menu Reference Prices memiliki border dan warna background semantik yang jelas (0 tombol *ghost*).
  * Navigasi keyboard (`Tab`) menampilkan *focus ring* dan *screen reader* membaca `aria-label` dengan benar.

### Tahap 5 — Pembersihan `font-mono` ke `Source Sans Pro` (Poin 5)
* **Dependensi**: Selesai Tahap 3 & 4.
* **Langkah Kerja**:
  1. Audit dan hapus seluruh class `font-mono` pada `resources/views/purchasing/reference-prices/index.blade.php`, `_tabs.blade.php`, `_content.blade.php`, dan `resources/views/procurement/_reference_snapshot_panel.blade.php`.
  2. Ganti `font-mono` pada elemen kode kategori (`1-15`), dimensi (`1 mm`, `15 mm`), harga per KG, dan preview import dengan class `tabular-nums` (mewarisi `Source Sans Pro` dari sistem).
  3. Kompilasi ulang aset frontend menggunakan `npm run build`.
* **Kriteria Selesai**:
  * Pencarian `font-mono` pada direktori `resources/views/purchasing/reference-prices/` menghasilkan 0 kecocokan.
  * Pemeriksaan *Computed Style* di browser memastikan seluruh teks dan angka di menu Reference Prices dirender menggunakan `Source Sans Pro`.

---

## 5. Daftar Lengkap Berkas yang Dibuat, Diubah, dan Dihapus

| Tahap | Operasi | Path Berkas | Keterangan |
| :--- | :---: | :--- | :--- |
| **Tahap 1** | `BUAT` | `database/migrations/2026_11_12_000001_remove_versioning_from_reference_price_rules.php` | Migrasi konsolidasi aturan aktif & penghapusan tabel/kolom versi |
| **Tahap 1** | `UBAH` | `app/Models/ReferencePriceRule.php` | Hapus `rule_set_id` & relasi `ruleSet()` |
| **Tahap 1** | `UBAH` | `app/Models/ReferencePriceImport.php` | Hapus `confirmed_rule_set_id` & relasi `confirmedRuleSet()` |
| **Tahap 1** | `HAPUS` | `app/Models/ReferencePriceRuleSet.php` | Model versi dihapus |
| **Tahap 1** | `HAPUS` | `app/Models/ReferencePriceSetting.php` | Model pointer versi aktif dihapus |
| **Tahap 1** | `HAPUS` | `app/Policies/ReferencePriceRuleSetPolicy.php` | Policy versi dihapus |
| **Tahap 1** | `UBAH` | `app/Providers/AppServiceProvider.php` | Hapus registrasi policy `ReferencePriceRuleSet` (jika ada) |
| **Tahap 1** | `UBAH` | `config/hashids.php` | Hapus koneksi Hashids `ReferencePriceRuleSet` (jika ada) |
| **Tahap 2** | `UBAH` | `app/Services/Pricing/ReferencePriceRuleResolver.php` | Query langsung ke `reference_price_rules` aktif |
| **Tahap 2** | `UBAH` | `app/Services/Pricing/ReferencePriceRuleResult.php` | Hapus metadata versi dari DTO hasil |
| **Tahap 2** | `UBAH` | `app/Services/Pricing/ReferencePriceRuleImportService.php` | Upsert/replace langsung ke `reference_price_rules` tanpa versi |
| **Tahap 2** | `UBAH` | `app/Services/Pricing/ReferencePriceBenchmarkResolver.php` | Sesuaikan pembacaan aturan tanpa `rule_set_id` |
| **Tahap 2** | `UBAH` | `app/Services/Pricing/ReferencePriceBenchmarkResult.php` | Bersihkan metadata versi |
| **Tahap 2** | `UBAH` | `app/Services/Pricing/ReferencePriceResolver.php` | Sesuaikan resolusi harga tanpa metadata versi |
| **Tahap 2** | `UBAH` | `app/Support/Pricing/ReferencePriceResult.php` | Bersihkan properti versi pada DTO |
| **Tahap 2** | `UBAH` | `app/Services/Pricing/HistoricalReferencePriceService.php` | Sesuaikan pemanggilan resolver aturan |
| **Tahap 2** | `UBAH` | `app/Services/PrItemAwardService.php` | Pastikan snapshot award tidak membutuhkan `rule_set_id` |
| **Tahap 3** | `UBAH` | `routes/web.php` | Hapus endpoint aktivasi versi Reference Prices |
| **Tahap 3** | `UBAH` | `app/Http/Controllers/Purchasing/MaterialReferencePriceController.php` | Integrasi `ServerTabsResponse` & hapus logic versi |
| **Tahap 3** | `UBAH` | `resources/views/purchasing/reference-prices/index.blade.php` | Wrapper `server-tabs` & modal import `<x-ui.dialog>` + `<x-ui.file-upload>` |
| **Tahap 3** | `BUAT` | `resources/views/purchasing/reference-prices/_tabs.blade.php` | Navigasi 3 tab dengan `<x-ui.tabs>` / `<x-ui.tab-list>` |
| **Tahap 3** | `BUAT` | `resources/views/purchasing/reference-prices/_content.blade.php` | Panel isi 3 tab tanpa kartu/dropdown versi |
| **Tahap 3** | `UBAH` | `lang/en/purchasing.php` & `lang/id/purchasing.php` | Pembaruan label UI & pesan sukses import tanpa nomor versi |
| **Tahap 4** | `UBAH` | `resources/views/components/ui/icon-button.blade.php` | Dukungan variant outline semantik (`secondary`, `warning-outline`, `success-outline`, `danger-outline`) |
| **Tahap 4** | `UBAH` | `resources/views/purchasing/reference-prices/_content.blade.php` | Penerapan action button non-ghost + `aria-label` & `title` |
| **Tahap 5** | `UBAH` | `resources/views/purchasing/reference-prices/*` & `resources/views/procurement/_reference_snapshot_panel.blade.php` | Penghapusan seluruh `font-mono` menjadi `Source Sans Pro` + `tabular-nums` |

---

## 6. Spesifikasi Perubahan Database, API & Strategi Rollback

### 6.1. Rancangan Kode Migrasi (`up()` dan `down()`)

~~~php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tentukan rule_set_id aktif yang akan dipertahankan datanya
        $retainedRuleSetId = null;

        if (Schema::hasTable('reference_price_settings')) {
            $retainedRuleSetId = DB::table('reference_price_settings')
                ->whereNotNull('active_rule_set_id')
                ->orderByDesc('id')
                ->value('active_rule_set_id');
        }

        if ($retainedRuleSetId === null && Schema::hasTable('reference_price_rule_sets')) {
            $retainedRuleSetId = DB::table('reference_price_rule_sets')
                ->where('status', 'active')
                ->orderByDesc('activated_at')
                ->orderByDesc('version_no')
                ->orderByDesc('id')
                ->value('id');
        }

        if ($retainedRuleSetId === null && Schema::hasTable('reference_price_rule_sets')) {
            $retainedRuleSetId = DB::table('reference_price_rule_sets')
                ->orderByDesc('version_no')
                ->orderByDesc('id')
                ->value('id');
        }

        // 2. Bersihkan baris aturan dari versi non-aktif agar category_key unik
        if (Schema::hasTable('reference_price_rules')) {
            if ($retainedRuleSetId !== null) {
                DB::table('reference_price_rules')
                    ->where('rule_set_id', '!=', $retainedRuleSetId)
                    ->delete();
            } else {
                DB::table('reference_price_rules')->delete();
            }

            Schema::table('reference_price_rules', function (Blueprint $table): void {
                $table->dropForeign(['rule_set_id']);
                $table->dropUnique('ref_rules_set_key_unique');
                $table->dropIndex('ref_rules_set_status_dim_idx');
                $table->dropColumn('rule_set_id');

                $table->unique(['category_key'], 'ref_rules_category_key_unique');
                $table->index(['status', 'dimension_min', 'dimension_max'], 'ref_rules_status_dim_idx');
            });
        }

        // 3. Lepas confirmed_rule_set_id dari reference_price_imports
        if (Schema::hasTable('reference_price_imports') && Schema::hasColumn('reference_price_imports', 'confirmed_rule_set_id')) {
            Schema::table('reference_price_imports', function (Blueprint $table): void {
                $table->dropForeign(['confirmed_rule_set_id']);
                $table->dropColumn('confirmed_rule_set_id');
            });
        }

        // 4. Hapus tabel pengaturan versi dan header versi
        Schema::dropIfExists('reference_price_settings');
        Schema::dropIfExists('reference_price_rule_sets');
    }

    public function down(): void
    {
        Schema::create('reference_price_rule_sets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('version_no')->unique();
            $table->string('status', 20)->default('archived');
            $table->string('import_mode', 20)->nullable();
            $table->string('source_file_name', 255)->nullable();
            $table->string('source_file_hash', 64)->nullable();
            $table->string('source_sheet', 50)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reference_price_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('active_rule_set_id')->nullable()->constrained('reference_price_rule_sets')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::table('reference_price_rules', function (Blueprint $table): void {
            $table->dropUnique('ref_rules_category_key_unique');
            $table->dropIndex('ref_rules_status_dim_idx');
            $table->foreignId('rule_set_id')->nullable()->after('id');
        });

        // Pulihkan 1 rule set default jika ada data aturan saat rollback
        $fallbackUserId = DB::table('reference_price_rules')->whereNotNull('created_by')->value('created_by')
            ?? DB::table('users')->orderBy('id')->value('id');

        if ($fallbackUserId !== null) {
            $defaultRuleSetId = DB::table('reference_price_rule_sets')->insertGetId([
                'version_no' => 1,
                'status' => 'active',
                'created_by' => $fallbackUserId,
                'activated_by' => $fallbackUserId,
                'activated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('reference_price_settings')->insert([
                'active_rule_set_id' => $defaultRuleSetId,
                'updated_by' => $fallbackUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('reference_price_rules')->update(['rule_set_id' => $defaultRuleSetId]);
        }

        Schema::table('reference_price_rules', function (Blueprint $table): void {
            $table->unsignedBigInteger('rule_set_id')->nullable(false)->change();
            $table->foreign('rule_set_id')->references('id')->on('reference_price_rule_sets')->cascadeOnDelete();
            $table->unique(['rule_set_id', 'category_key'], 'ref_rules_set_key_unique');
            $table->index(['rule_set_id', 'status', 'dimension_min', 'dimension_max'], 'ref_rules_set_status_dim_idx');
        });

        Schema::table('reference_price_imports', function (Blueprint $table): void {
            $table->foreignId('confirmed_rule_set_id')
                ->nullable()
                ->after('validation_errors')
                ->constrained('reference_price_rule_sets')
                ->nullOnDelete();
        });
    }
};
~~~

### 6.2. Kontrak Route & Endpoint
* `GET /purchasing/reference-prices?tab=rules|benchmark|legacy`
  * Merespons dokumen HTML utuh saat diakses biasa, atau JSON partial `ServerTabsResponse` (`nav` + `content`) saat header `X-Server-Tabs: 1` dikirim oleh `resources/js/server-tabs.js`.
* `POST /purchasing/reference-prices/rules` & `PUT /purchasing/reference-prices/rules/{rule}`
  * Menambah/memperbarui aturan langsung pada `reference_price_rules` tanpa parameter `rule_set_id`.
* `PATCH /purchasing/reference-prices/rules/{rule}/status`
  * Mengubah status `active` / `inactive` pada aturan kategori.
* `POST /purchasing/reference-prices/imports` & `POST /purchasing/reference-prices/imports/{import}/confirm`
  * Mengunggah file via `<x-ui.file-upload>`, memvalidasi baris, dan menerapkan hasil konfirmasi langsung ke `reference_price_rules`.

---

## 7. Rencana Pengujian & Verifikasi Regresi

### Tier 1 — Syntax & Database Migration Check
~~~bash
php -l app/Http/Controllers/Purchasing/MaterialReferencePriceController.php
php -l app/Services/Pricing/ReferencePriceRuleResolver.php
php -l app/Services/Pricing/ReferencePriceRuleImportService.php
php artisan migrate --pretend
php artisan migrate
~~~

### Tier 2 — Verifikasi Fungsional Menu Reference Prices
1. **Navigasi Server-Tabs**:
   * Klik berturut-turut tab **Rules & Categories**, **Historical Benchmark**, dan **Legacy Archive**.
   * Pastikan URL berubah (`?tab=rules`, `?tab=benchmark`, `?tab=legacy`) tanpa reload halaman penuh dan tidak ada garis bawah (`underline`) yang bocor pada label tab.
2. **Alur Import Excel**:
   * Klik tombol **Import Excel**, pastikan dialog `<x-ui.dialog>` dan dropzone `<x-ui.file-upload>` terbuka dengan benar.
   * Unggah file valid dan konfirmasi import; pastikan data di tabel **Rules & Categories** langsung ter-update tanpa membuat versi baru.
   * Unggah file dengan rentang dimensi tidak valid (`dimension_max < dimension_min`); pastikan pesan error tampil melalui `<x-ui.alert>`.
3. **Action Buttons & Aksesibilitas**:
   * Verifikasi tombol **Edit** (`secondary-outline`), **Deactivate** (`warning-outline`), **Activate** (`success-outline`), dan **Void/Delete** (`danger-outline`) tidak lagi menggunakan style *ghost*.
   * Uji navigasi tombol `Tab` untuk memastikan *focus ring* tampil dan atribut `aria-label` serta `title` sesuai dengan baris data.
4. **Verifikasi Penghapusan Versioning**:
   * Pastikan banner `Version 4 Active` dan dropdown pemilih versi sudah hilang sepenuhnya.
   * Uji tambah kategori baru (`+ Add Category`), edit kategori, dan nonaktifkan kategori.
5. **Verifikasi Font `Source Sans Pro`**:
   * Buka DevTools browser (`Elements -> Computed -> Rendered Fonts`) pada kolom `CATEGORY KEY` (`1-15`), `MIN (MM)` (`1 mm`), `MAX (MM)` (`15 mm`), angka benchmark, badge status, dan modal; pastikan 100% merender **`Source Sans Pro`**.

### Tier 3 — Uji Regresi Komponen Shared & Modul Hilir
1. **Regresi `server-tabs`**: Buka menu *Exchange Rates*, *Import Suppliers*, dan *Supplier Audits* untuk memastikan navigasi tab di menu tersebut tetap berjalan normal.
2. **Regresi `<x-ui.file-upload>` & `<x-ui.dialog>`**: Buka modal import pada menu *Purchase Requisition* dan *Local Procurement* untuk memastikan komponen shared tidak terdampak.
3. **Regresi `<x-ui.icon-button>`**: Periksa tabel di menu lain yang menggunakan `<x-ui.icon-button>` untuk memastikan default style mereka tidak berubah.
4. **Regresi Modul Pembaca Reference Prices**: Buka halaman *Historical Price Comparison* (`/purchasing/comparison/historical`) dan *Quotation / PR Evaluation* untuk memastikan kalkulasi harga referensi per KG berjalan normal.

---

## 8. Matriks Risiko dan Mitigasi

| No | Risiko | Tingkat Dampak | Strategi Mitigasi |
| :-: | :--- | :---: | :--- |
| 1 | `reference_price_settings.active_rule_set_id` bernilai `NULL` pada database environment tertentu saat migrasi dijalankan | Tinggi | Gunakan fallback berjenjang di migrasi: cek `reference_price_settings.active_rule_set_id` -> fallback ke `reference_price_rule_sets` dengan `status = 'active'` -> fallback ke `version_no` tertinggi. |
| 2 | Penambahan variant pada `<x-ui.icon-button>` mengubah tampilan tombol di halaman lain | Sedang | Pertahankan nilai default prop `variant` (`ghost`) pada `icon-button.blade.php`, dan hanya aktifkan style ber-border ketika prop `variant` dikirim secara eksplisit dari view Reference Prices. |
| 3 | Event listener JS untuk modal Edit/Import tidak terpanggil setelah konten tab diganti secara dinamis oleh `server-tabs.js` | Sedang | Gunakan *event delegation* pada level `document` atau container statis di luar area yang di-replace oleh `server-tabs.js`. |
| 4 | Masih ada angka atau kode yang merender font monospace karena class `font-mono` pada partial anak | Rendah | Lakukan pencarian menyeluruh (`grep` / audit kode) terhadap string `font-mono` pada seluruh file Blade terkait Reference Prices dan ganti dengan `tabular-nums`. |