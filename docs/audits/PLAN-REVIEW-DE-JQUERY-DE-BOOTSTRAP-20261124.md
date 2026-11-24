# Adversarial Plan Review: Master Implementation Plan De-jQuery & De-Bootstrap 5

- **Dokumen yang Direview:** `docs/plans/IMPLEMENTATION-PLAN-DE-JQUERY-DE-BOOTSTRAP-20261124.md` (`659` baris)
- **Repositori:** `poyipoy/supplierportal` (ADASI Supplier Portal)
- **Branch / Baseline Terverifikasi:** `migrate-jquery-DT` pada `HEAD ee6992d9` (`feat(ui,deps): upgrade jQuery to 4.1.0 and DataTables to 3.1.5`) `[Verified]`
- **Tanggal Audit:** 2026-11-24
- **Mode Eksekusi:** `STRICT READ-ONLY` (0 perubahan pada kode produksi, Blade, CSS, JS, test, konfigurasi, database, maupun dokumen plan sumber)

---

## 1. Verdict

**`REVISE PLAN`**

### Ringkasan Eksekutif
Struktur makro `docs/plans/IMPLEMENTATION-PLAN-DE-JQUERY-DE-BOOTSTRAP-20261124.md` sudah memiliki fondasi yang kuat: pembagian 2 Mega-Wave logis, penangkapan mekanisme event internal DataTables `3.1.5` (`We`/`Pe`/`U` pada biner `dataTables-3.1.5.min.js`) akurat, konfirmasi penutupan seluruh temuan audit `ee6992d9` (`[HIGH-01..02]`, `[MEDIUM-01..03]`, `[LOW-01..02]`) benar, dan analisis penyebab plugin PostCSS `vite.config.js` tepat sasaran.

Namun, stress-test adversarial lintas runtime browser, urutan eksekusi ESM vs skrip klasik, cakupan `app/`, dan kontrak suite pengujian membuktikan **2 temuan `CRITICAL`**, **5 temuan `HIGH`**, **4 temuan `MEDIUM`**, dan **2 temuan `LOW`** yang **pasti memicu UI deadlock di produksi, meninggalkan HTML tanpa style, atau mematahkan gate CI di tengah sub-fase** apabila plan dieksekusi tanpa revisi terlebih dahulu:

1. **`[CRITICAL-01]` Deadlock Top-Layer Browser (`<dialog>.showModal()`):** Memigrasikan 30 modal ke native `<dialog>.showModal()` tanpa arsitektur Top-Layer pendamping membuat **5 subsistem ber-`z-index` yang dirender di `document.body`** (`AdasiAlert`/SweetAlert2, `<x-ui.session-expired-modal>`, popup kalender `<x-ui.date-picker>` di 12 modal, `AdasiToast`, dan `#adasiLoader`) tertutup di belakang `::backdrop` sekaligus **terkunci `inert` (tidak bisa diklik/difokus)** oleh browser engine.
2. **`[CRITICAL-02]` Kontradiksi Batasan Scope `app/` (Bagian 1.6) vs Pencabutan `bootstrap.min.css` (2C):** Bagian 1.6 mengunci edit di `app/` hanya pada dropdown di 5 controller + `StatusHelper.php:881` + `AddSecurityHeaders.php:76`, padahal **9 controller DataTables tambahan** (termasuk `VsBestPriceComparisonController` [20 hits] dan `Purchasing\ShipmentController` [6 hits]) serta baris non-dropdown pada ke-5 controller tersebut memancarkan kelas utilitas/tipografi Bootstrap mentah (`d-flex`, `d-inline-flex`, `fw-bold`, `text-muted`, `small`, `ms-1`, `me-1`) langsung ke JSON DataTables.
3. **`[HIGH-01]` Race Urutan Eksekusi Classic `<script>` vs Deferred `@vite` ESM (`top-level await`) & Crash Halaman Non-DataTables:** Skrip inline `layouts/app.blade.php:499-653` dan `@stack('scripts')` dieksekusi sebelum modul `resources/js/app.js` selesai (apalagi `app.js` memiliki `await Promise.allSettled(pageComponents)`), serta ~80% halaman tidak memuat `dataTables.min.js` (`window.DataTable === undefined`).
4. **`[HIGH-02]` Penghapusan Prematur Interceptor `jQuery.ajax` di Sub-Phase 1A:** Mencabut `$(document).ajaxComplete` (`session-sync.js`), `$.ajaxPrefilter` (`adasi-loader.js` & `layouts/app.blade.php`), dan `$.ajaxSetup` di **1A** sebelum `14` panggilan `$.ajax` di 5 Blade berat dimigrasikan pada **1B** memutus deteksi sesi `401`/`419`, CSRF, header Ngrok, dan loader selama masa transisi maupun saat rollback parsial.
5. **`[HIGH-03]` Desinkronisasi Gate Test Lintas Sub-Fase & 7 Test Kontrak Terlewat:** `FrontendAssetLoadingTest` patah seketika di **1A** (dan lagi di **2C**), serta 7 file test (`PrItemRemarkTest`, `InforTableNameStripingContractTest`, `LocalInvoiceScopeIsolationTest`, `preferences.test.mjs` dark overlay ladder & preset hover, `StatusHelperPoArrivalMetaTest`, `LocalizationImportRenderingTest`, `PoItemMaterialProgressTest`) luput dari Tabel 5.2.
6. **`[HIGH-04]` Tabrakan DOM ID `popovertarget` di Halaman Multi-Tabel + Hook Bootstrap Terlewat:** Risiko salah aksi hapus/toggle antar `#materialsTable` dan `#rulesTable` di `admin/material-hs-code`, serta terlewatnya `workspace-shell.js:5162` (`show.bs.dropdown`), `navbar.blade.php:272,280,310`, `layouts/app.blade.php:477,704,821,1052`, `overlay-modality.js:354`, dan `quotation-availability-guard.js:72,82,85`.
7. **`[HIGH-05]` Celah Reset `<th>` & Background di `app.css` (2C) serta Flex Layout DT3 (2A):** Menyalin reset `auth.css:225-302` tanpa `th { text-align: inherit; text-align: -webkit-match-parent; }` membuat seluruh header tabel ERP rata tengah (default UA browser), dan menghapus kelas `.row` dari `datatables-skin.js` di 2A tanpa `display: flex` eksplisit pada `.dt-layout-row` membuat toolbar/footer DataTables bertumpuk vertikal.

### Matriks Evaluasi 6 Dimensi Audit

| Dimensi Audit | Status | Ringkasan Bukti `[Verified]` |
| :--- | :---: | :--- |
| **1. Akurasi Inventaris & Fase 0 (`ee6992d9`)** | **PARTIAL** | Penutupan Fase 0 (`[HIGH-01..02]`, `[MEDIUM-01..03]`, `[LOW-01..02]`) **100% akurat**. Namun ditemukan selisih di inventaris: initializer DT aktual `16` file (`1` JS + `15` Blade, bukan `18`), file API/event BS JS aktual `26` (bukan `25`; `quotation-availability-guard.js` luput), `9` controller PHP tambahan ber-class Bootstrap di `app/`, dan `receipts/verify-*.blade.php` luput dari sub-batch 2B. |
| **2. Runtime & Top-Layer Edge Cases (MW 1)** | **FAIL (`CRITICAL-01`, `HIGH-01..02`, `HIGH-04`)** | `DataTable.Dom.s(target).on/off` dan `F.ajax` (`pe()`) terverifikasi mendukung `complete` & tanpa jQuery, dan `dataTables.bootstrap5.min.js` aman tanpa jQuery di 1D. **Namun** Top-Layer `<dialog>.showModal()` memblokir SweetAlert2, session-expired modal, date-picker, dan toast; serta ada race urutan ESM vs classic script. |
| **3. `modern-web-guidance`, WCAG 2.2 AA & Fallback** | **PARTIAL (`MEDIUM-02..03`)** | Guardrail `closedby`, `position-area` `@supports`, dan non-`role="menu"` (`UserAccountNavigationTest.php:44`) sudah tepat. Perlu perbaikan pada `adasi-tooltip.js` (isolasi dokumen iframe `WorkspaceShell` + preservasi token `aria-describedby` field error) dan pencegahan `Escape` ganda saat `SweetAlert2` terbuka di atas `<dialog>`. |
| **4. Pure-Token DT3 Skin, 181 Blade, & Reset CSS (MW 2)** | **FAIL (`CRITICAL-02`, `HIGH-05`)** | Cakupan `app/` melarang pembersihan 14 controller DataTables; base reset `auth.css` kehilangan `th { text-align: inherit }` dan berisiko mengubah warna background workspace; `.dt-layout-row` butuh `display: flex` eksplisit di 2A. |
| **5. Urutan Sub-Fase & Kontrak Test** | **FAIL (`HIGH-03`)** | `FrontendAssetLoadingTest` patah di 1A (ditunda ke 1D) dan di 2C (ditunda ke 2D). 7 suite test yang meng-assert selector/kelas Bootstrap belum terdaftar di Tabel 5.2. |
| **6. Scope & Rollback Safety** | **PARTIAL (`HIGH-02`)** | Bebas migrasi DB dan bebas paket baru. Namun pencabutan `ajaxComplete`/`ajaxPrefilter` di 1A merusak halaman 1B dan menggagalkan rollback parsial antar 1A-1B. |

---

## 2. Daftar Temuan (Diurutkan `CRITICAL` -> `HIGH` -> `MEDIUM` -> `LOW`)

---

### `[CRITICAL-01]` Top-Layer `<dialog>.showModal()` Memblokir & Menutup Interaksi `AdasiAlert` (`SweetAlert2`), `<x-ui.session-expired-modal>`, `<x-ui.date-picker>`, `AdasiToast`, dan `#adasiLoader`

- **Severity:** `CRITICAL`
- **Status Bukti:** `[Verified]`
- **Bagian Plan Terkait:** Bagian 1.3 (Keputusan 2 `:67-73`), Bagian 1.4 (`:99`), Sub-Phase 1C.1 (`:283-299`)
- **Bukti Codebase (`file:baris`):**
  1. **`AdasiAlert` / SweetAlert2 (`public/assets/js/adasi-alert.js:87,114,157,211-216`):** memanggil `SweetAlert.fire(...)` tanpa opsi `target`, sehingga `.swal2-container` di-mount ke `document.body` (`z-index: 1060`). Baris `:211-216` mengandalkan `document.addEventListener('focusin', ..., true)` yang hanya bekerja untuk focus trap berbasis JS milik Bootstrap Modal, **tidak berlaku** terhadap modalitas native Top Layer browser. Terverifikasi **14 file Blade/JS** memanggil `AdasiAlert`/`Swal` dari dalam modal aktif (a.l. `resources/js/local-procurement-import.js:221` pada `#localPoImportModal`/`#localGrImportModal`, `finance/drp/{paid,show}.blade.php`, `finance/vendors/index.blade.php`, `ga/employees/index.blade.php`, `purchasing/po/show.blade.php`, `purchasing/pr/_import.blade.php`, `purchasing/quotations/show.blade.php`, `purchasing/reference-prices/index.blade.php`, `purchasing/shipments/show.blade.php`, `supplier/quotations/create.blade.php`).
  2. **`<x-ui.session-expired-modal>` (`resources/views/components/ui/session-expired-modal.blade.php:64-70`):** memakai `<template x-teleport="body"><div x-show="open" class="ui-session-modal tw:fixed tw:inset-0 ...">` berbasis `z-index`, bukan `<dialog>` atau `[popover]`.
  3. **`<x-ui.date-picker>` / `<x-ui.date-range-picker>` (`resources/js/calendar.js:44,738-745`):** `movePanelToLayer()` menjalankan `document.body.append(this.panel)` dan `document.body.append(mobileScrim)` (`z-index: 1070`). Terverifikasi **12 Blade modal** merender date-picker di dalam modal (`admin/exchange-rates/index.blade.php`, `components/export/advanced-modal.blade.php`, `finance/drp/{paid,show}.blade.php`, `finance/local-procurement/{show,_gr_modals}.blade.php`, `finance/overpayments/index.blade.php`, `purchasing/{quotations/show,reference-prices/index,shipments/show}.blade.php`, `supplier/{po/show,quotations/create}.blade.php`).
  4. **`AdasiToast` (`resources/views/components/ui/toast-container.blade.php:5-9`, `layouts/app.blade.php:450`) & `#adasiLoader` (`resources/js/adasi-loader.js:645`):** keduanya berada di `document.body` dengan `z-index: 1080` dan `9999`.
- **Penjelasan Celah / Risiko Runtime:**
  Sesuai spesifikasi WHATWG HTML, pemanggilan `dialogElement.showModal()` mengangkat `<dialog>` ke **Browser Top Layer** dan menandai seluruh node di luar `<dialog>` (termasuk seluruh anak langsung `document.body` ber-`z-index: 2147483647`) sebagai **inert / blocked by modal dialog**. Akibatnya:
  - Saat operator membatalkan import di `#localPoImportModal` (`local-procurement-import.js:221`), popup konfirmasi `AdasiAlert.confirmDanger` muncul **di belakang backdrop gelap `<dialog>`** dan tombol *Lanjutkan / Batal* **100% mati (tidak menerima klik maupun fokus keyboard)**, menyebabkan Promise menggantung permanen.
  - Saat sesi habis (`401`/`419`/idle) ketika modal terbuka, `<x-ui.session-expired-modal>` tersembunyi di belakang modal dan tombol **Sambungkan Kembali** tidak dapat diklik sama sekali.
  - Saat operator mengklik `<x-ui.date-picker>` di `#addGrModal` atau `#rateModal`, panel kalender dipindah ke `document.body` di belakang `<dialog>` dan tidak bisa dipilih.
  - Selain itu, menekan tombol `Escape` saat `SweetAlert2` terbuka di dalam `<dialog>` akan memicu event `cancel` native pada `<dialog>` dan menutup modal induk secara paksa kecuali diintersep.
- **Rekomendasi Perbaikan Konkret:**
  Tambahkan spesifikasi **Top-Layer Stacking Contract** di Sub-Phase 1C.1:
  1. **`public/assets/js/adasi-alert.js`:** deteksi modal Top-Layer teratas yang sedang terbuka (`const topDialog = [...document.querySelectorAll('dialog[open]')].at(-1)`), lalu kirim opsi resmi SweetAlert2 `target: topDialog || document.body` pada `baseOptions()`! Karena `.swal2-container` di-mount di dalam `<dialog open>` aktif, SweetAlert2 otomatis berada di dalam subtree non-inert di atas konten modal, dan di `adasi-modal.js` cegah event `cancel` (`e.preventDefault()`) selama `window.Swal?.isVisible?.()` bernilai `true`. (Tambahkan `public/assets/js/adasi-alert.js` ke daftar file yang diizinkan diubah).
  2. **`resources/js/calendar.js:44,738-745`:** ubah `movePanelToLayer()` & `ensureMobileScrim()` agar me-mount ke `this.wrapper.closest('dialog[open]') || document.body` (serta cegah `cancel` pada `<dialog>` ketika `this.isOpen` menangkap `Escape`).
  3. **`<x-ui.session-expired-modal>`:** konversi wrapper teleport `<div data-session-expired-modal>` menjadi native `<dialog>` (`showModal()`) atau tambahkan `popover="manual"` + `showPopover()` saat dibuka (dan re-invoke `showPopover()` jika ada `<dialog>` lain dibuka) agar selalu berada di urutan paling atas Top Layer browser.
  4. **`<x-ui.toast-container>` & `#adasiLoader`:** promosikan `.adasi-toast-region` dan `.adasi-loader-overlay` menggunakan `popover="manual"` (`showPopover()` tanpa mencuri fokus / non-modal top-layer) sehingga selalu tergambar di atas `<dialog open>` sesuai urutan pemanggilan `showPopover()`.

---

### `[CRITICAL-02]` Kontradiksi Batasan Scope `app/` (Bagian 1.6) Meninggalkan 14 Controller & Presenter PHP Memancarkan Kelas Bootstrap Mentah Pasca-2C

- **Severity:** `CRITICAL`
- **Status Bukti:** `[Verified]`
- **Bagian Plan Terkait:** Bagian 1.6 (`:119-122`), Sub-Phase 2B (`:433-476`), Sub-Phase 2C (`:486-503`)
- **Bukti Codebase (`file:baris`):**
  Bagian 1.6 melarang keras perubahan di `app/` selain dropdown pada 5 controller, `StatusHelper.php:881`, dan `AddSecurityHeaders.php:76`. Audit penuh terhadap `app/` membuktikan:
  1. **9 Controller DataTables/HTML tambahan di luar daftar 5 controller** memancarkan kelas Bootstrap mentah ke kolom HTML JSON:
     - `app/Http/Controllers/Purchasing/VsBestPriceComparisonController.php:143-205` (**20 baris**: `fw-bold`, `text-muted small`, `small text-primary text-decoration-none`, `fw-bold text-success`, `text-danger`)
     - `app/Http/Controllers/Purchasing/ShipmentController.php:52,61,68,79,86` (`fw-bold text-primary text-decoration-none`, `me-1`, `text-success fw-semibold`)
     - `app/Http/Controllers/Purchasing/PurchaseOrderController.php:152,158` (`d-flex flex-column align-items-start gap-1`, `d-inline-flex gap-1 justify-content-end flex-wrap`)
     - `app/Http/Controllers/Purchasing/MaterialClaimController.php:101` (`d-flex flex-column align-items-start gap-1`)
     - `app/Http/Controllers/Supplier/ClaimController.php:46` (`d-flex flex-column align-items-start gap-1`)
     - `app/Http/Controllers/Supplier/QuotationController.php:215,228` (`d-inline-flex gap-1 justify-content-end flex-wrap`)
     - `app/Http/Controllers/Supplier/SupplierPriceHistoryController.php:296-297` (`fw-bold text-primary`, `small text-muted`)
     - `app/Http/Controllers/Supplier/SupplierPurchaseOrderController.php:140` (`d-inline-flex gap-1 justify-content-end flex-wrap`)
     - `app/Http/Controllers/SupplierRegistrationReviewController.php:69` (`text-muted`)
  2. **Baris non-dropdown pada ke-5 controller yang diizinkan** juga memancarkan kelas utilitas Bootstrap di luar blok dropdown:
     - `Admin/UserController.php:36,38,63` (`fw-semibold text-body`, `small text-muted mt-1`, `d-inline-flex align-items-center gap-1`)
     - `Admin/HsCodeRuleController.php:65,69` & `Admin/MaterialMasterController.php:62,66` (`d-inline-flex align-items-center gap-1`, `text-danger`)
     - `Purchasing/PurchaseRequisitionController.php:119,154,157` (`ms-1`, `d-inline-flex justify-content-end`, `align-items-center gap-1`)
     - `Supplier/SupplierShipmentController.php:85,93,101,112,147,150` (`fw-bold text-primary text-decoration-none`, `me-1`, `d-inline-flex justify-content-end`)
  3. **Metode Badge Legacy & String Ikon Notifikasi di `app/`:**
     - `StatusHelper.php` (`prBadge`, `poBadge`, `poArrivalMeta`, `claimBadge`, `claimDeadlineMeta`, `docBadge`, `documentProgressMeta`, `qcBadge`, `shipmentBadge`, `materialProgressBadge`, `shipmentDocBadge`), `Quotation.php:181-187`, `Conversation.php:180-190`, `ConversationPresenter.php:214-248`, dan `PriceComparisonSharedTrait.php:186-212` mengembalikan string `'bg-success'`, `'bg-warning text-dark'`, `'bg-light text-muted border'`, dll.
     - 12 service/controller pengirim notifikasi (mis. `MaterialClaimController.php:200,279`, `PoAcknowledgementService.php:110,182,261,337,397`, `ShipmentService.php:778,892,1297`) menyimpan string ikon seperti `'octagon-alert text-danger'` ke payload notifikasi database.
- **Penjelasan Celah / Risiko Runtime:**
  Begitu `bootstrap.min.css` dan bridge legacy di `app.css` dihapus pada Sub-Phase 2C, seluruh kolom aksi dan sel metrik di 14 controller DataTables kehilangan `display: flex`/`inline-flex`, `gap`, `justify-content-end`, warna semantik, dan hierarki tipografi karena Bagian 1.6 melarang menyentuh file-file `app/` tersebut. Selain itu, notifikasi historis di tabel `notifications` yang sudah terlanjur menyimpan string ikon `'... text-danger'` / `'... text-primary'` di database akan kehilangan warna ikon jika pemetaan kelas/komponen di `partials/notification-panel.blade.php` tidak menormalkan token `text-*` legacy tersebut.
- **Rekomendasi Perbaikan Konkret:**
  1. Perbarui Bagian 1.6 agar mengizinkan **perubahan murni kelas CSS presentasi HTML (`d-*` -> `tw:*`, `fw-*` -> `tw:font-*`, `text-*` -> `tw:text-*`, tanpa menyentuh query/validasi/otorisasi/bisnis)** pada ke-14 controller DataTables di Sub-Phase 2B (masukkan masing-masing ke sub-batch domainnya `2B-2..2B-5`).
  2. **JANGAN** mengubah nilai kembalian string `'bg-*'` pada `StatusHelper::*Badge()` / `*Meta()` maupun `PriceComparisonSharedTrait` karena dikunci langsung oleh `StatusHelperPoArrivalMetaTest.php:33-87`, `LocalizationImportRenderingTest.php:138,141`, dan `PoItemMaterialProgressTest.php:1061`, serta sudah dikonversi otomatis ke `.ui-status-chip--{tone}` oleh `StatusHelper::badge()` (`:868-874`) dan `<x-status-badge>` (`components/status-badge.blade.php:44-56`). Cukup migrasikan pemanggil Blade yang masih mencetak `<span class="badge {{ StatusHelper::shipmentBadge(...) }}">` (mis. `purchasing/shipments/index.blade.php` & `supplier/shipments/index.blade.php`) agar memanggil `StatusHelper::badge(...)` atau `<x-ui.status-chip>`.
  3. Di `partials/notification-panel.blade.php` (dan `layouts/app.blade.php` Pusher builder), petakan suffix `text-primary|success|warning|danger` dari data notifikasi historis ke kelas `tw:text-*` yang sesuai tanpa mengubah data DB.

---

### `[HIGH-01]` Race Urutan Eksekusi Classic `<script>` vs Deferred `@vite` ESM (`top-level await`) di `layouts/app.blade.php`, `@stack('scripts')`, & Sub-Phase 2A (`datatables-skin.js`)

- **Severity:** `HIGH`
- **Status Bukti:** `[Verified]`
- **Bagian Plan Terkait:** Sub-Phase 1A.1 (`:180`), Sub-Phase 1A.2 (`:186`), Sub-Phase 2A (`:401-414`)
- **Bukti Codebase (`file:baris`):**
  - `resources/views/layouts/app.blade.php:371` memuat `@vite(['resources/css/app.css', 'resources/js/app.js'])` sebagai `<script type="module">` (deferred).
  - `tests/js/app-lazy-modules.test.mjs:15-18` membuktikan `resources/js/app.js` menjalankan **`top-level await Promise.allSettled(pageComponents)`** sebelum mengeksekusi sisa body modul.
  - `resources/views/layouts/app.blade.php:494-498` hanya memuat `dataTables.min.js` di dalam `@hasSection('uses-datatables')` (~80% halaman tidak mendefinisikan `window.DataTable`).
  - `resources/views/layouts/app.blade.php:499-579` dan `:586-653` adalah **classic `<script>` sinkron** yang dieksekusi saat parser HTML membaca `<body>` — **SEBELUM `app.js` dieksekusi**!
  - `@stack('scripts')` di `:1554` mendaftarkan listener `DOMContentLoaded` (pengganti `$(document).ready(...)` pada 15 Blade DataTables) **sebelum** listener `DOMContentLoaded` yang didaftarkan setelah `await Promise.allSettled(pageComponents)` di `app.js`.
- **Penjelasan Celah / Risiko Runtime:**
  1. Jika skrip inline `layouts/app.blade.php:500-653` memanggil `window.AdasiDataTableEvents.on(...)` seperti diizinkan di 1A.1 (`:180`), terjadi `TypeError: Cannot read properties of undefined (reading 'on')` karena `app.js` belum jalan saat body diparse.
  2. Jika `layouts/app.blade.php:503,641` memanggil `window.DataTable.Dom.s(document)` tanpa guard opsional, halaman non-DataTables (`purchasing.dashboard`, seluruh form/detail) langsung melempar `TypeError` karena `window.DataTable` bernilai `undefined`.
  3. Di Sub-Phase 2A (`:401`), jika `datatables-skin.js` diletakkan setelah `await Promise.allSettled(pageComponents)` di `app.js` atau ditunda ke `DOMContentLoaded`, maka handler `DOMContentLoaded` milik `@stack('scripts')` (yang terdaftar lebih dulu saat body parsing) akan menjalankan `new DataTable(...)` **sebelum** `DataTable.defaults.renderer = 'adasi'` dan `DataTable.ext.classes` tersetel!
- **Rekomendasi Perbaikan Konkret:**
  1. Di `layouts/app.blade.php:503` dan `:641`, gunakan guard ketat `if (window.DataTable?.Dom?.s)` secara langsung (jangan memanggil `window.AdasiDataTableEvents` dari skrip klasik sinkron di `layouts/app.blade.php`).
  2. Di `resources/js/app.js`, import dan jalankan `applyAdasiDataTablesSkin(window.DataTable)` dari `datatables-skin.js` **secara sinkron di bagian paling atas `app.js` SEBELUM `await Promise.allSettled(pageComponents)`** (karena classic `<script src="dataTables.min.js">` di `:496` sudah selesai dieksekusi sebelum modul ESM mulai dievaluasi, dan bagian sinkron sebelum `top-level await` pertama dijamin selesai sebelum event `DOMContentLoaded` dipancarkan ke `@stack('scripts')`!).
  3. Pastikan seluruh 15 Blade DataTables mengganti `$(function() { ... })` dengan `const boot = () => { ... }; if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();` (jangan memanggil `new DataTable(...)` telanjang secara sinkron di `@push('scripts')` sebelum `app.js` sempat mendaftarkan listener `preXhr.dt` di `session-sync.js`, `adasi-loader.js`, dan `embed-readiness.js` — yang juga wajib diregistrasikan **sebelum** `await Promise.allSettled(pageComponents)` di `app.js`!).

---

### `[HIGH-02]` Pencabutan Prematur `$(document).ajaxComplete`, `$.ajaxPrefilter`, & `$.ajaxSetup` di Sub-Phase 1A Memutus Keamanan Sesi `401`/`419`, CSRF, & Loader untuk 14 Panggilan `$.ajax` Selama 1A-1B

- **Severity:** `HIGH`
- **Status Bukti:** `[Verified]`
- **Bagian Plan Terkait:** Sub-Phase 1A.2 (`:186,188,189`), Bagian 5.4 Rollback (`:653`)
- **Bukti Codebase (`file:baris`):**
  - `resources/js/session-sync.js:340-342` (`win.jQuery(doc).on('ajaxComplete', ...)`), `resources/js/adasi-loader.js:658-662` (`$.ajaxPrefilter`), dan `resources/views/layouts/app.blade.php:635-639` (`$.ajaxPrefilter` Ngrok) saat ini melindungi **14 panggilan `$.ajax` / `$.get`** pada 5 Blade berat (`_material_shape_script` x4, `material-hs-code/_script` x6, `quotations/create` x1, `po/show` x3, `pr/_import` x1).
  - Plan 1A.2 (`:188`) memerintahkan: *"Hapus `$(document).ajaxComplete` (seluruh `$.ajax` akan pindah ke `fetch` di 1B)"*.
- **Penjelasan Celah / Risiko Runtime:**
  Antara commit Sub-Phase **1A** dan selesainya seluruh PR bertahap Sub-Phase **1B** (atau jika salah satu PR 1B di-revert sesuai Rollback Plan `:653`), ke-14 endpoint AJAX kritis tersebut kehilangan deteksi sesi mati `401`/`419` (`session-sync.js`), kehilangan bypass header Ngrok di non-prod, dan kehilangan indikator loading.
- **Rekomendasi Perbaikan Konkret:**
  Pada Sub-Phase **1A**, hanya ubah guard `if (typeof win.jQuery !== 'function') return;` di `session-sync.js:323`, `adasi-loader.js:655`, dan `embed-readiness.js:379` agar blok observer DataTables (`onDataTableEvent(...)`) berjalan **independen dari `win.jQuery`**, tetapi **PERTAHANKAN** blok `if (win.jQuery) { $(doc).on('ajaxComplete', ...); $.ajaxPrefilter(...); }` tetap hidup hingga Sub-Phase **1D** (setelah Gate 1B membuktikan `0` `$.ajax` di seluruh repo). `handledXhrs = new WeakSet()` yang sudah ada di `session-sync.js:324` otomatis mencegah double-handling.

---

### `[HIGH-03]` Gate Test Patah di Tengah Sub-Fase (`1A`, `1C`, `2B`, `2C`) & 7 Suite Test Kontrak Luput dari Tabel 5.2

- **Severity:** `HIGH`
- **Status Bukti:** `[Verified]`
- **Bagian Plan Terkait:** Sub-Phase 1A.4 (`:226-233`), Sub-Phase 1D (`:377-380`), Sub-Phase 2C (`:506-512`), Sub-Phase 2D (`:523-530`), Tabel 5.2 (`:607-636`)
- **Bukti Codebase (`file:baris`):**
  1. **Patah di Sub-Phase 1A:** `tests/Feature/FrontendAssetLoadingTest.php:188,208` memindai `/\.(?:DataTable|dataTable)\s*\(/` dan meng-assert `assertCount(15, $initializers)`. Plan memigrasikan ke-15 Blade ke `new DataTable(` di **1A**, namun baru mengupdate `FrontendAssetLoadingTest.php:188,208` di **1D (`:380`)** -> `FrontendAssetLoadingTest` gagal (`0 !== 15`) sepanjang 1A, 1B, dan 1C!
  2. **Patah di Sub-Phase 2C:** `FrontendAssetLoadingTest.php:135-150` menghitung **11 URL CDN unik di seluruh `resources/views/`** (`chart.umd.min.js`, `bootstrap.min.css`, `dataTables.bootstrap5.min.css`, `sweetalert2.min.css`, `bootstrap.bundle.min.js`, `jquery-4.1.0.min.js`, `dataTables.min.js`, `dataTables.bootstrap5.min.js`, `sweetalert2.all.min.js`, `pusher.min.js`, `echo.iife.js`). Pencabutan `bootstrap.min.css` dilakukan di **2C (`:487`)** (menurunkan count `7 -> 6`), tetapi update `FrontendAssetLoadingTest.php` baru dijadwalkan di **2D (`:524`)** -> Gate Tier 2 gagal pada 2C!
  3. **7 Suite Test Kontrak yang Luput Sama Sekali dari Tabel 5.2:**
     - `tests/Feature/PrItemRemarkTest.php:142`: `$this->assertStringContainsString('dropdown-menu', $action)` -> **gagal di 1C.2 / 2B** saat `PurchaseRequisitionController` berpindah dari `.dropdown-menu` ke Popover!
     - `tests/Feature/Architecture/InforTableNameStripingContractTest.php:26-27`: meng-assert string literal `:where(.ui-data-table table, .table):where(:not(.ui-quotation-readonly)...` di `app.css` -> **gagal di 2C** bila `.table` dihapus dari selector striping!
     - `tests/Feature/LocalInvoice/LocalInvoiceScopeIsolationTest.php:443`: meng-assert `/id="supplier-section"[^>]*class="[^"]*d-none/` -> **gagal di 2B-2** saat `admin/users/edit.blade.php` mengganti `d-none` ke `tw:hidden`!
     - `tests/js/preferences.test.mjs:425-429` & `:589-596`: meng-assert keberadaan dark adapter untuk `.card`, `.modal-content`, `.dropdown-menu`, `.popover`, `.table-light`, `.btn-outline-secondary`, `:is(.bg-light, .bg-white)`, `.badge.bg-secondary`, `.badge.bg-warning`, `.text-dark:not(.badge):not(.btn)`, `.btn-outline-primary`, serta hover `.btn-primary` untuk `indigo`, `teal`, `violet`! Plan 2C (`:501`) memerintahkan penghapusan selector tersebut tetapi `:510` hanya menyebut baris `:51,445-450`!
     - `tests/Unit/Support/StatusHelperPoArrivalMetaTest.php:33-87`, `tests/Feature/LocalizationImportRenderingTest.php:138,141`, dan `tests/Feature/PoItemMaterialProgressTest.php:1061`: meng-assert string `'bg-*'` pada `StatusHelper` & `PriceComparisonSharedTrait`.
- **Rekomendasi Perbaikan Konkret:**
  - Pindahkan pembaruan regex `FrontendAssetLoadingTest.php:188,208` ke **Sub-Phase 1A.4**, dan pembaruan count CDN `7 -> 6` (+ negative assertions) ke **Sub-Phase 2C**.
  - Tambahkan ketujuh suite test di atas ke Tabel 5.2 dan ke gate sub-fase yang bersangkutan (`1C.2`, `2B-2`, `2C`).

---

### `[HIGH-04]` Risiko Tabrakan DOM ID `popovertarget` pada Halaman Multi-Tabel + Hook Bootstrap JS/DOM yang Terlewat (`workspace-shell.js`, `navbar`, `layouts/app`, `overlay-modality`, `quotation-availability-guard`)

- **Severity:** `HIGH`
- **Status Bukti:** `[Verified]`
- **Bagian Plan Terkait:** Sub-Phase 1C.1, 1C.2 (`:283-324`)
- **Bukti Codebase (`file:baris`):**
  1. **Tabrakan ID `popovertarget`:** Di `admin/material-hs-code/index.blade.php`, `#materialsTable` (`MaterialMasterController.php:64`) dan `#rulesTable` (`HsCodeRuleController.php:67`) hidup berdampingan pada satu dokumen DOM. Jika helper `RowAction::overflowMenu($id, ...)` menghasilkan ID generik (mis. `row-action-5`) tanpa namespace entitas, `material_masters.id = 5` dan `hs_code_rules.id = 5` memiliki `id` identik, sehingga tombol aksi Material #5 membuka menu hapus/nonaktifkan milik HS Code Rule #5! Hal serupa berlaku pada halaman bertab 2 DataTables (`purchasing/claims/index`, `qc/inspections/index`).
  2. **Salah Klasifikasi & Hook Terlewat di `resources/js/workspace-shell.js:5162-5169`:** Plan 1C.1 (`:284`) memasukkan `workspace-shell.js` ke daftar Modal, padahal baris `:5162` adalah **`document.addEventListener('show.bs.dropdown', ...)`** yang menutup context menu tab shell, menutup kalender aktif (`AdasiCalendar.closeActive()`), dan memanggil `dismissEmbedOverlays()` lintas iframe!
  3. **Hook Lazy Notification & Badge di `partials/navbar.blade.php:272-284,310` & `layouts/app.blade.php:704`:** pemuatan ringkasan notifikasi bergantung pada `show.bs.dropdown` dan `[data-bs-toggle="dropdown"]`, sedangkan `updateBadges()` memperbarui `aria-label` via `badge.closest('button[data-bs-toggle="dropdown"]')`.
  4. **Hook Modal & Navigasi di `layouts/app.blade.php:477,821,1052`:**
     - `:477`: `document.addEventListener('show.bs.modal', ...)` menghapus `.ui-page-fade` pada ancestor.
     - `:821`: guard `return_url` Purchasing memeriksa `link.hasAttribute('data-bs-toggle')`.
     - `:1052`: shortcut global (`Alt+D`, `Alt+[`, `Alt+]`, `?`) memeriksa `if (document.querySelector('.modal.show')) return;`. Tanpa diupdate ke `dialog[open], [data-ui-dialog]`, menekan `?` saat fokus berada di tombol dalam modal akan mengeksekusi shortcut di belakang modal!
  5. **Cleanup Swap `AdasiServerTabs` di `resources/js/overlay-modality.js:354`:** `releaseOverlaysWithin()` menutup `.modal.show` milik Bootstrap saat fragmen tab diganti; wajib diganti agar menutup `dialog.ui-native-dialog[open]` dan `[popover]:popover-open` di dalam container yang di-swap!
  6. **File ke-26 yang Luput (`resources/js/quotation-availability-guard.js:72,82,85`):** memakai `[data-bs-target="#quotationImportModal"]`, `data-bs-dismiss`, dan `windowRef?.bootstrap?.Modal?.getInstance?.(importModal)?.hide?.()` saat PR/undangan dibatalkan realtime!
- **Rekomendasi Perbaikan Konkret:**
  - Wajibkan format ID `popover` ber-namespace eksplisit `<scope>-<hashOrId>` (mis. `dd-mat-{$material->id}`, `dd-hs-{$rule->id}`, `dd-user-{$user->hash}`, `dd-pr-{$pr->hash}`, `dd-shp-{$shp->hash}`).
  - Pancarkan custom event `adasi:dropdown-show` (`bubbles: true`) dari `adasi-dropdown.js` saat `beforetoggle` (`newState === 'open'`), lalu ikat `workspace-shell.js:5162` dan `navbar.blade.php:272` ke `adasi:dropdown-show`.
  - Masukkan keenam lokasi di atas secara eksplisit ke checklist Sub-Phase 1C.1 & 1C.2.

---

### `[HIGH-05]` Celah Base Reset `th { text-align }` & Background `body` di `app.css` (2C), Serta Hilangnya Flex `.dt-layout-row` di Skin DT3 (2A)

- **Severity:** `HIGH`
- **Status Bukti:** `[Verified]`
- **Bagian Plan Terkait:** Sub-Phase 2A (`:401-419`), Sub-Phase 2C (`:490-499`)
- **Bukti Codebase (`file:baris`):**
  1. **`resources/css/auth.css:225-302` tidak memiliki aturan `th`:** Stylesheet bawaan semua browser (UA stylesheet) menetapkan `th { text-align: center; font-weight: bold; }`. Selama ini perataan kiri header tabel di seluruh portal berasal dari Bootstrap Reboot (`th { text-align: inherit; text-align: -webkit-match-parent; }`). Jika 2C menyalin `auth.css:225-302` tanpa reset `th`, **seluruh `<th>` pada 200+ tabel operasional berubah menjadi rata tengah** saat `bootstrap.min.css` dicabut!
  2. **Warna latar `body` (`auth.css:239` vs `app.css`):** `auth.css:239` menyetel `background-color: var(--md-surface)` (`#FFFFFF`), sedangkan shell aplikasi membutuhkan `var(--md-background)` / `var(--ui-workspace-bg)` (`DESIGN.md:69-70`).
  3. **Layout `.dt-layout-row` & `.dt-column-order` di Sub-Phase 2A:** Inspeksi `resources/css/app.css` (`35` aturan `.dt-*`) membuktikan `app.css` belum mendefinisikan `display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;` pada `.dt-layout-row` (selama ini bergantung pada kelas Bootstrap `row` yang disuntikkan `dataTables.bootstrap5.min.js` + `dataTables.bootstrap5.min.css`) maupun `width: 100%` pada `table.dataTable` dan positioning absolut `span.dt-column-order` pada `th.dt-orderable-*`.
- **Rekomendasi Perbaikan Konkret:**
  - Di Sub-Phase **2A**, wajibkan penambahan aturan eksplisit di `app.css` untuk: `table.dataTable { width: 100%; border-collapse: collapse; }`, `.dt-layout-row` (flex horizontal + gap + responsive wrap `< 768px`), `.dt-layout-start` / `.dt-layout-end`, `.dt-scroll`, serta positioning `th:is(.dt-orderable-asc, .dt-orderable-desc) { position: relative; }` + `span.dt-column-order`.
  - Di Sub-Phase **2C**, tambahkan `th { text-align: inherit; text-align: -webkit-match-parent; }`, `ul, ol { margin-top: 0; margin-bottom: 1rem; }`, `caption`, dan kunci `body { background-color: var(--md-background); }`.

---

### `[MEDIUM-01]` Ketidakakuratan Angka Inventaris & File Terlewat (`16` vs `18` DT Init, `26` vs `25` BS JS, `purchasing/claims` Tab, `receipts/` di 2B, & Inline JS Builder `layouts/app`)

- **Severity:** `MEDIUM`
- **Status Bukti:** `[Verified]`
- **Bagian Plan Terkait:** Bagian 1.1 (`:20-26`), Sub-Phase 1A.3 (`:199,206-224`), Sub-Phase 1C.4 (`:342-346`), Sub-Phase 2B.1 (`:454-473`)
- **Bukti Codebase (`file:baris`):**
  1. **DataTables Initializers:** Plan 1A.3 mengklaim `18` file (`1` JS + `17` Blade) dan mencantumkan `#17 purchasing/pr/_supplier_picker_modal.blade.php` serta `#18 purchasing/po/show.blade.php`. Hasil scan aktual: **kedua file tersebut memiliki `0` panggilan DataTables**! Jumlah file initializer sebenarnya adalah **`16` file** (`1` JS `resources/js/local-po-gr-table.js` + `15` Blade yang dikunci persis oleh `FrontendAssetLoadingTest.php:208`, dengan total `19` instance tabel karena `material-hs-code/_script`, `purchasing/claims/index`, dan `qc/inspections/index` masing-masing menginisialisasi 2 tabel), ditambah `2` file pembaca API (`advanced-export.js` & `public/assets/js/async-export.js`).
  2. **Bootstrap JS API/Event Files:** Jumlah aktual adalah **`26` file** (`11` JS + `15` Blade), bukan `25` (`resources/js/quotation-availability-guard.js:85` terlewat; sementara di Blade, `purchasing/claims/index.blade.php:97` `$('button[data-bs-target="#history"]').on('shown.bs.tab', ...)` perlu dicantumkan eksplisit di 1C.4 agar `columns.adjust()` tidak hilang).
  3. **Direktori `resources/views/receipts/` Luput dari Sub-Batch 2B.1:** `receipts/verify-ga.blade.php` dan `receipts/verify-supplier.blade.php` (beserta `receipts/partials/verified-card.blade.php`) meng-extend `layouts.public-document` (yang **sudah tidak memuat `bootstrap.min.css`**!) namun masih mengandung kelas Bootstrap (`hasBs=true`) dan belum dimasukkan ke sub-batch mana pun di 2B.1.
  4. **Inline JS DOM Builders di `resources/views/layouts/app.blade.php`:** baris `:1103-1130` (`table table-borderless table-sm mb-0` pada dialog `?`) dan `:1253-1390` (`bg-light`, `d-flex gap-3`, `text-primary`, `flex-shrink-0`, `flex-grow-1`, `fw-semibold small text-truncate`, `text-muted small`, `.text-center.text-muted.py-5` pada Pusher Echo notification item builder) tidak terdeteksi oleh scanner atribut statis Blade dan wajib dirinci di `2B-1`.
- **Rekomendasi Perbaikan Konkret:**
  Koreksi angka dan daftar file pada Bagian 1.1, 1A.3, 1C.4, dan 2B-1 sesuai rincian di atas (masukkan `receipts/*` ke Sub-Batch `2B-6` atau `2B-1`).

---

### `[MEDIUM-02]` Singleton `adasi-tooltip.js` Gagal di Dalam Iframe `WorkspaceShell` (`_embed=1`) & Menimpa Token `aria-describedby` Milik Validasi Form

- **Severity:** `MEDIUM`
- **Status Bukti:** `[Verified]`
- **Bagian Plan Terkait:** Sub-Phase 1C.3 (`:329-338`)
- **Bukti Codebase (`file:baris`):**
  - `resources/views/layouts/app.blade.php:447-452` membungkus komponen overlay global (`toast-container`, `chat-drawer`, `smart-panel`, `image-lightbox`) di dalam `@if(!($isWorkspaceEmbed ?? false))`. Jika `<div id="adasiTooltipPopover">` ditaruh di blok yang sama pada Blade, seluruh halaman di dalam iframe `WorkspaceShell` (`?_embed=1`) tidak akan memiliki elemen `#adasiTooltipPopover`!
  - `resources/js/exchange-rates.js:81-85` dan `<x-ui.input>` memakai `aria-describedby` berformat daftar ID terpisah spasi untuk pesan error validasi. Memanggil `trigger.setAttribute('aria-describedby', 'adasiTooltipPopover')` secara langsung akan menghapus asosiasi pesan error validasi bagi pengguna screen reader.
- **Rekomendasi Perbaikan Konkret:**
  1. Buat elemen `<div id="adasiTooltipPopover" role="tooltip" popover="manual" class="ui-tooltip">` **secara lazy via JavaScript per `document`** di dalam `adasi-tooltip.js` (bukan di dalam blok `@if(!$isWorkspaceEmbed)` Blade) sehingga bekerja baik di top shell, iframe embed, maupun layout `auth`/`guest`.
  2. Kelola `aria-describedby` sebagai `Set` token terpisah spasi: tambahkan `'adasiTooltipPopover'` saat tampil dan hapus hanya token `'adasiTooltipPopover'` saat sembunyi.

---

### `[MEDIUM-03]` Pengikatan `anchor-name: --dd-<id>` Dinamis per Baris di 5 Controller Menambah Beban Markup Inline yang Tidak Perlu

- **Severity:** `MEDIUM`
- **Status Bukti:** `[Verified]`
- **Bagian Plan Terkait:** Sub-Phase 1C.2 (`:318-320`)
- **Bukti Codebase (`file:baris`):**
  5 controller DataTables merender hingga 100 baris per halaman. Menulis `--dd-<id>` unik membutuhkan atribut `style="anchor-name: ..."` inline pada setiap tombol dan menu di tiap baris JSON, karena `app.css` statis tidak mengetahui ID database tiap baris.
- **Rekomendasi Perbaikan Konkret:**
  Gunakan **satu variabel anchor aktif tunggal** `--adasi-active-dropdown-anchor` di `app.css` (`[data-ui-dropdown-open="true"] { anchor-name: --adasi-active-dropdown-anchor; }` dan `.ui-dropdown-popover:popover-open { position-anchor: --adasi-active-dropdown-anchor; }`) yang di-toggle oleh `adasi-dropdown.js` pada event `beforetoggle` (karena `popover="auto"` menjamin hanya 1 dropdown non-nested terbuka pada satu saat), atau manfaatkan `popover.showPopover({ source: trigger })` + implicit anchor. Hasilnya: **0 inline `style="..."`** di ke-5 PHP controller!

---

### `[MEDIUM-04]` Fallback Multi-Event `datatables-events.js` & Ketergantungan Properti Privat `._isTransitioning` pada `local-import-browser-check.mjs`

- **Severity:** `MEDIUM`
- **Status Bukti:** `[Verified]`
- **Bagian Plan Terkait:** Sub-Phase 1A.1 (`:179`), Sub-Phase 1D (`:382`)
- **Bukti Codebase (`file:baris`):**
  1. `resources/js/embed-readiness.js:410` memanggil `.on('draw.dt error.dt dt-error.dt', ...)` (beberapa nama event dipisah spasi dalam satu string). Deskripsi fallback Node test di 1A.1 (`:179`) menulis *"pecah `eventNames` menjadi `{ baseName, namespace }`"* (tunggal) — jika tidak di-`split(/\s+/)` terlebih dahulu, listener multi-event gagal terpanggil di unit test Node!
  2. `tests/Support/local-import-browser-check.mjs:260,268` mengecek `!window.bootstrap.Modal.getInstance(...)._isTransitioning`. Saat diganti ke `window.AdasiModal` di 1D, asersi tersebut harus diubah menjadi `document.getElementById('editPoGrModal').open === true` (tanpa `_isTransitioning`).
- **Rekomendasi Perbaikan Konkret:**
  Pastikan `datatables-events.js` melakukan `eventNames.trim().split(/\s+/).filter(Boolean)` baik untuk `.on()` maupun `.off()`, dan catat penghapusan `._isTransitioning` pada `local-import-browser-check.mjs:260,268` di Sub-Phase 1D.

---

### `[LOW-01]` Opsi Mati `responsive: true` pada `#vsBestTable` (`purchasing/comparison/_scripts.blade.php`)

- **Severity:** `LOW`
- **Status Bukti:** `[Verified]`
- **Bagian Plan Terkait:** Sub-Phase 1A.3 / Sub-Phase 2A
- **Bukti Codebase (`file:baris`):**
  Pemindaian seluruh opsi DataTables pada ke-16 file initializer menunjukkan `resources/views/purchasing/comparison/_scripts.blade.php` mengirim opsi `responsive: true`, padahal ekstensi DataTables Responsive tidak pernah dimuat di CDN.
- **Rekomendasi Perbaikan Konkret:**
  Hapus properti mati `responsive: true` saat migrasi `new DataTable(...)` di Sub-Phase 1A.3 agar tidak menimbulkan kebingungan saat membangun Pure-Token Skin di Sub-Phase 2A.

---

### `[LOW-02]` Perintah Gate Tier 1 `node --test tests/js/*.test.mjs` Rawan Gagal Glob di PowerShell Windows

- **Severity:** `LOW`
- **Status Bukti:** `[Verified]`
- **Bagian Plan Terkait:** Bagian 3 (`:359`), Bagian 4 (`:457`), Bagian 5.1 Tier 1 (`:545`)
- **Bukti Codebase (`file:baris`):**
  `CLAUDE.md:68`, `AGENTS.md:967`, dan `DESIGN.md:350` memperingatkan bahwa ekspansi argumen file eksplisit diperlukan di Windows PowerShell.
- **Rekomendasi Perbaikan Konkret:**
  Gunakan perintah PowerShell deterministik `node --test (Get-ChildItem tests/js/*.test.mjs).FullName` pada contoh skrip PowerShell di Bagian 5.1.

---

## 3. Daftar Lengkap File, Selector, & Edge Case Terlewat

### 3.1 File Kode Sumber yang Terlewat atau Terblokir Scope di Plan

| File `[Verified]` | Lokasi Baris | Peran / Masalah yang Terlewat di Plan | Sub-Phase Seharusnya |
| :--- | :---: | :--- | :---: |
| `public/assets/js/adasi-alert.js` | `:87,114,157,212` | `SweetAlert.fire` mount ke `document.body` (tertutup & `inert` di belakang `<dialog>.showModal()`); perlu `target: topOpenDialog \|\| document.body`. | **1C.1** |
| `resources/js/calendar.js` | `:44,738-745` | `movePanelToLayer()` mount kalender ke `document.body` (tertutup & `inert` pada 12 modal ber-datepicker); perlu mount ke `closest('dialog[open]') \|\| document.body`. | **1C.1** |
| `resources/views/components/ui/session-expired-modal.blade.php` | `:64-70` | `x-teleport="body"` `<div>` tertutup di belakang `<dialog>.showModal()` saat sesi mati; perlu `<dialog>` / `popover="manual"`. | **1C.1** |
| `resources/views/components/ui/toast-container.blade.php` & `adasi-loader.js` | `:5-9` / `:645` | Berbasis `z-index` biasa di bawah Top Layer `<dialog>`; perlu promosi `popover="manual"` non-modal. | **1C.1** |
| `resources/js/quotation-availability-guard.js` | `:72,82,85,125` | File Bootstrap JS API ke-26 (`bootstrap?.Modal?.getInstance(importModal)?.hide()`, `[data-bs-target]`, `data-bs-dismiss`, `d-none`). | **1C.1** & **2B-1** |
| `resources/js/workspace-shell.js` | `:5162-5169` | Listener `show.bs.dropdown` (bukan Modal!) untuk menutup context menu tab, kalender aktif, dan overlay embed. | **1C.2** |
| `resources/js/overlay-modality.js` | `:354` | `releaseOverlaysWithin()` harus menutup `dialog.ui-native-dialog[open]` & `[popover]:popover-open` saat swap `AdasiServerTabs`. | **1C.1 / 1C.2** |
| `resources/views/partials/navbar.blade.php` | `:272,280,304-310` | `show.bs.dropdown` lazy loader `notifications.summary` + `button[data-bs-toggle="dropdown"]` `aria-label` sync + `d-none`. | **1C.2** & **2B-1** |
| `resources/views/layouts/app.blade.php` | `:477,700-704,738-741,821,1052,1103-1130,1253-1390` | `show.bs.modal` fade strip, `button[data-bs-toggle="dropdown"]` badge sync, `data-bs-toggle` link guard, `.modal.show` shortcut guard, serta HTML Bootstrap di dialog `?` & Pusher Echo builder. | **1C.1/1C.2** & **2B-1** |
| `resources/views/purchasing/claims/index.blade.php` | `:97` | `$('button[data-bs-target="#history"]').on('shown.bs.tab', ...)` belum disebut di 1C.4. | **1C.4** |
| `resources/views/receipts/{verify-ga,verify-supplier}.blade.php` & `partials/verified-card.blade.php` | `layouts.public-document` | Mengandung kelas Bootstrap padahal `public-document` tidak memuat `bootstrap.min.css`, dan luput dari sub-batch 2B.1. | **2B-1** |
| **9 PHP Controller DataTables tambahan** (`VsBestPriceComparison`, `Purchasing\Shipment`, `Purchasing\PurchaseOrder`, `Purchasing\MaterialClaim`, `Supplier\Claim`, `Supplier\Quotation`, `SupplierPriceHistory`, `SupplierPurchaseOrder`, `SupplierRegistrationReview`) + baris non-dropdown di 5 controller | Lihat `[CRITICAL-02]` | Memancarkan kelas utilitas/tipografi Bootstrap mentah (`d-flex`, `d-inline-flex`, `fw-bold`, `text-muted`, `small`, `ms-1`, `me-1`) tetapi terblokir aturan Bagian 1.6. | **2B-2..2B-5** |

### 3.2 Suite Pengujian Kontrak yang Luput dari Tabel 5.2

| File Test `[Verified]` | Baris | Asersi yang Akan Patah | Sub-Phase Wajib Update |
| :--- | :---: | :--- | :---: |
| `tests/Feature/FrontendAssetLoadingTest.php` | `:188,208` & `:150` | Regex `.DataTable(` patah di **1A** (ditulis 1D); count CDN `7 -> 6` patah di **2C** (ditulis 2D). | **1A**, **1D**, **2A**, **2C** |
| `tests/Feature/PrItemRemarkTest.php` | `:142` | `assertStringContainsString('dropdown-menu', $action)` pada kolom aksi PR. | **1C.2** |
| `tests/Feature/LocalInvoice/LocalInvoiceScopeIsolationTest.php` | `:443` | Regex `/id="supplier-section"[^>]*class="[^"]*d-none/` pada `admin/users/edit`. | **2B-2** |
| `tests/Feature/Architecture/InforTableNameStripingContractTest.php` | `:26-27` | String literal `:where(.ui-data-table table, .table)...` di `resources/css/app.css`. | **2C** |
| `tests/js/preferences.test.mjs` | `:425-429,589-596` | Dark overlay adapters (`.card`, `.modal-content`, `.dropdown-menu`, `.popover`, `.btn-outline-*`, `.badge.bg-*`, `.bg-light`) & `.btn-primary` hover di `indigo`/`teal`/`violet`. | **2C** |
| `tests/Unit/Support/StatusHelperPoArrivalMetaTest.php`, `tests/Feature/LocalizationImportRenderingTest.php`, `tests/Feature/PoItemMaterialProgressTest.php` | `:33-87` / `:138,141` / `:1061` | Mengunci kembalian string `'bg-*'` pada `StatusHelper` & `PriceComparisonSharedTrait` (jangan ubah metode PHP-nya; ubah pemanggil Blade ke `StatusHelper::badge()` / `<x-ui.status-chip>`). | **Guardrail 2B/2C** |
| `tests/Support/local-import-browser-check.mjs` | `:260,268` | Mengecek properti internal Bootstrap `._isTransitioning`. | **1D** |

---

## 4. Exact Plan Patch Instructions (Wajib Diterapkan ke `IMPLEMENTATION-PLAN-DE-JQUERY-DE-BOOTSTRAP-20261124.md` Sebelum Sesi 1)

Terapkan 8 amandemen spesifik berikut ke dokumen plan sebelum memulai eksekusi kode apa pun:

1. **Patch Bagian 1.1 & 1A.3 (Koreksi Inventaris Eksak):**
   - Ubah klaim `25 file` Bootstrap JS API/event menjadi **`26 file` (`11` JS + `15` Blade, menambahkan `resources/js/quotation-availability-guard.js`)**.
   - Ubah klaim `18 file` inisialisasi DataTables menjadi **`16 file initializer` (`1` JS `local-po-gr-table.js` + `15` Blade yang dikunci `FrontendAssetLoadingTest.php:208`, menginisialisasi `19` tabel) + `2 file pembaca API` (`advanced-export.js`, `public/assets/js/async-export.js`)**; keluarkan `purchasing/pr/_supplier_picker_modal.blade.php` dan `purchasing/po/show.blade.php` dari daftar 1A.3 (tetap di 1B), serta hapus opsi mati `responsive: true` di `purchasing/comparison/_scripts.blade.php`.
2. **Patch Bagian 1.6 (Buka Kunci Presentasi HTML Murni di `app/` & `adasi-alert.js`):**
   - Perluas pengecualian edit `app/` secara ketat hanya untuk **penggantian kelas CSS presentasi Bootstrap -> `tw:*` / `.ui-*` pada string HTML kolom DataTables** di 14 controller (`HsCodeRuleController`, `MaterialMasterController`, `UserController`, `PurchaseRequisitionController`, `SupplierShipmentController`, `VsBestPriceComparisonController`, `Purchasing\ShipmentController`, `Purchasing\PurchaseOrderController`, `Purchasing\MaterialClaimController`, `Supplier\ClaimController`, `Supplier\QuotationController`, `SupplierPriceHistoryController`, `SupplierPurchaseOrderController`, `SupplierRegistrationReviewController`), tetap **0 perubahan** pada query, perhitungan, validasi, atau otorisasi.
   - Tambahkan larangan eksplisit mengubah string kembalian `'bg-*'` di `StatusHelper::*Badge()` / `*Meta()` & `PriceComparisonSharedTrait` (karena dikunci unit test dan sudah dipetakan ke tone oleh `StatusHelper::badge()` & `<x-status-badge>`).
   - Izinkan penyesuaian `target: topDialog || document.body` pada `public/assets/js/adasi-alert.js` (tanpa meng-upgrade versi `SweetAlert2 11.7.32`).
3. **Patch Sub-Phase 1A.1, 1A.2, & 1A.4 (Urutan Eksekusi ESM, Preservasi `$.ajax` Interim, & Sinkronisasi Test):**
   - Wajibkan `datatables-events.js` mem-parsing multi-event terpisah spasi (`eventNames.trim().split(/\s+/)`) dan mendaftarkan seluruh global DT listener di `app.js` **sebelum** `await Promise.allSettled(pageComponents)`.
   - Di `layouts/app.blade.php:503,641`, gunakan guard langsung `if (window.DataTable?.Dom?.s)` (bukan `window.AdasiDataTableEvents`).
   - **Tunda** penghapusan `$(document).ajaxComplete` (`session-sync.js:340`), `$.ajaxPrefilter` (`adasi-loader.js:658` & `layouts/app.blade.php:635`), dan `$.ajaxSetup` dari **1A** ke **1D** (setelah `0` `$.ajax` terverifikasi di 1B).
   - Pindahkan pembaruan regex `FrontendAssetLoadingTest.php:188,208` (`new DataTable(`) dari **1D** ke **1A.4**.
4. **Patch Sub-Phase 1C.1 (Top-Layer Stacking Contract & Modal Guards):**
   - Tambahkan **Top-Layer Stacking Contract**: (a) `adasi-alert.js` menyetel `target: activeOpenDialog || document.body` + `adasi-modal.js` membatalkan event `cancel` (`Escape`) saat `Swal.isVisible()`; (b) `calendar.js:44,738-745` me-mount panel & scrim ke `wrapper.closest('dialog[open]') || document.body`; (c) `<x-ui.session-expired-modal>` memakai `<dialog>.showModal()` atau `popover="manual"` yang dipromosikan ulang ke puncak Top Layer; (d) `<x-ui.toast-container>` & `#adasiLoader` memakai `popover="manual"` non-modal agar selalu tampil di atas `<dialog open>`.
   - Tambahkan ke daftar edit 1C.1: `quotation-availability-guard.js:72,82,85`, `overlay-modality.js:354` (`releaseOverlaysWithin` menutup `dialog[open]` & `[popover]:popover-open`), dan `layouts/app.blade.php:477,821,1052` (`.modal.show` -> `dialog[open], [data-ui-dialog]`).
5. **Patch Sub-Phase 1C.2, 1C.3, & 1C.4 (Dropdown ID Namespace, Single Active Anchor, Tooltip Iframe/ARIA, & Claims Tab):**
   - Wajibkan ID `popover` ber-namespace entitas (`dd-mat-`, `dd-hs-`, `dd-user-`, `dd-pr-`, `dd-shp-`) dan gunakan satu variabel CSS `--adasi-active-dropdown-anchor` pada elemen yang sedang terbuka (tanpa inline `style="..."` per baris).
   - Pancarkan `adasi:dropdown-show` dan migrasikan `workspace-shell.js:5162`, `navbar.blade.php:272,280,310`, `layouts/app.blade.php:704`, serta update `PrItemRemarkTest.php:142`.
   - Buat `#adasiTooltipPopover` secara lazy per `document` di JS (agar aktif di iframe `WorkspaceShell`) dan kelola `aria-describedby` sebagai token list tanpa menghapus ID error validasi.
   - Tambahkan `purchasing/claims/index.blade.php:97` (`shown.bs.tab` -> `adasi:tab-shown`) ke 1C.4.
6. **Patch Sub-Phase 2A (Urutan Boot `datatables-skin.js` & Aturan Flex/Sort Eksplisit):**
   - Import & jalankan `datatables-skin.js` di baris sinkron teratas `resources/js/app.js` **sebelum** `await Promise.allSettled(pageComponents)`.
   - Wajibkan penambahan aturan `table.dataTable { width: 100%; }`, `.dt-layout-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; }`, dan positioning `span.dt-column-order` di `app.css` pada Sub-Phase **2A**.
7. **Patch Sub-Phase 2B & 2C (Cakupan `receipts/`, Inline JS `layouts/app`, Reset `th` & `body`, serta Sinkronisasi Test 2C):**
   - Masukkan `resources/views/receipts/*`, inline JS builder `layouts/app.blade.php:700-741,1103-1130,1253-1390`, dan normalisasi token warna ikon notifikasi historis `partials/notification-panel.blade.php` ke **Sub-Batch 2B-1**, serta ke-14 controller PHP DataTables ke sub-batch domain masing-masing (`2B-2..2B-5`). Update `LocalInvoiceScopeIsolationTest.php:443` di **2B-2**.
   - Di **2C**, tambahkan `th { text-align: inherit; text-align: -webkit-match-parent; }` dan kunci `body { background-color: var(--md-background); }` pada base reset `app.css`.
   - Pindahkan update `FrontendAssetLoadingTest.php:150` (`7 -> 6`) ke **2C**, dan tambahkan update `InforTableNameStripingContractTest.php:26-27` serta `preferences.test.mjs:425-429,589-596` ke langkah 2C.5.
8. **Patch Bagian 5.1 & 5.2 (Perintah PowerShell & Registri Test Lengkap):**
   - Ganti `node --test tests/js/*.test.mjs` di blok PowerShell menjadi `node --test (Get-ChildItem tests/js/*.test.mjs).FullName`, perbarui `local-import-browser-check.mjs:260,268` (`._isTransitioning`) di 1D, dan lengkapi Tabel 5.2 dengan ke-7 suite test dari Bagian 3.2 laporan ini.
