# Implementation Plan: Upgrade jQuery `3.7.1` → `4.1.0` & DataTables `1.13.6` → `3.1.5`

- **Tanggal Rencana:** 24 November 2026
- **Target Repositori:** ADASI Portal Supplier (`c:\laragon\www\adasi_portal_supplier`)
- **Target Versi:**
  - jQuery: `3.7.1` → `4.1.0` (`https://code.jquery.com/jquery-4.1.0.min.js`)
  - jQuery Migrate (sementara khusus audit dev, wajib dihapus sebelum commit): `4.0.2` (`https://code.jquery.com/jquery-migrate-4.0.2.js`)
  - DataTables Core + Integrasi Bootstrap 5: `1.13.6` → `3.1.5` (`https://cdn.datatables.net/3.1.5/js/dataTables.min.js`, `https://cdn.datatables.net/3.1.5/js/dataTables.bootstrap5.min.js`, `https://cdn.datatables.net/3.1.5/css/dataTables.bootstrap5.min.css`)
- **Kontrak Proyek Terkait:** `AGENTS.md`, `CLAUDE.md`, `DESIGN.md`, `claudes-cognitive-framework-for-laravel-development.md`
- **Batasan Scope Dokumen Ini:**
  - **TIDAK** menghapus jQuery, menjadikan pemuatan jQuery kondisional, atau me-rewrite `$(el).DataTable(...)` / skrip jQuery halaman menjadi vanilla JS/Alpine pada upgrade ini (kandidat pasca-upgrade dicatat hanya sebagai informasi di **Bagian 9**).
  - **TIDAK** meng-upgrade Bootstrap `5.3.3`, SweetAlert2 `11.7.32`, Chart.js `4.5.1`, Tailwind CSS `4.3.3`, Alpine.js `^3.4.2`, atau `yajra/laravel-datatables-oracle ~12.0` (`v12.7.2`).
  - **TIDAK** mengubah query/controller backend DataTables (kompatibilitas `$request->ajax()` ditangani di layer transport frontend `DataTable.ajax`).
  - **TIDAK** menyisakan `jquery-migrate` di konfigurasi produksi atau commit final.

---

## 1. Ringkasan Eksekutif & Matriks Risiko

### 1.1 Ringkasan Kondisi Codebase Saat Ini

Audit statis langsung terhadap repositori dan dokumentasi resmi jQuery `4.0.0`/`4.1.0` serta DataTables `2.x`/`3.1.5` menemukan fakta-fakta berikut:

1. **Pemuatan CDN & Kontrak Keamanan (`[Verified]`):**
   - `resources/views/layouts/app.blade.php:492` memuat `jquery-3.7.1.min.js` secara global; `:357-360` dan `:494-498` memuat DataTables `1.13.6` secara kondisional melalui `@hasSection('uses-datatables')`. Layout lain (`auth.blade.php`, `guest.blade.php`, `workspace-shell.blade.php`) tidak memuat jQuery maupun DataTables.
   - `app/Http/Middleware/AddSecurityHeaders.php:75-76` sudah mengizinkan host `https://code.jquery.com` (`script-src`) dan `https://cdn.datatables.net` (`style-src`, `script-src`), sehingga tidak diperlukan perubahan host CSP untuk upgrade CDN ke `4.1.0` / `3.1.5`.
   - `tests/Feature/FrontendAssetLoadingTest.php:134-174` memvalidasi tepat **11** aset CDN unik lintas `resources/views/` (`:150`), format versi semver eksplisit (`:144`), hash SRI wajib `sha384-<64 karakter Base64>` tanpa padding (`:145`), `crossorigin="anonymous"` (`:146`), serta **15** file Blade yang memanggil `.DataTable(` / `.dataTable(` (`:208`).

2. **Inventaris Halaman, Partial, CSS, JS, dan Backend (`[Verified]`):**
   - **19 halaman Blade** mendeklarasikan `@section('uses-datatables', true)` (catatan: `Finance\LocalProcurementController` melayani prefix route `finance.local-procurement.*` maupun `purchasing.local-procurement.*` melalui satu view bersama `resources/views/finance/local-procurement/show.blade.php`), ditambah **2 script partial** (`admin/material-hs-code/_script.blade.php`, `purchasing/comparison/_scripts.blade.php`), **1 table partial** (`finance/local-procurement/_gr_table.blade.php`), dan **`resources/views/layouts/app.blade.php`**.
   - **8 file Blade** memuat **9 inisialisasi** DataTables dengan opsi `dom:` (`8` bertipe `dom: 'rtip'` dan `1` markup grid Bootstrap kustom pada `#vsBestTable` di `purchasing/comparison/_scripts.blade.php:1878-1880`).
   - **36 file** memanggil `$()` atau `jQuery()` (`9` file JS [`8` di `resources/js/` + `public/assets/js/async-export.js`] + `27` file `.blade.php` [`26` view/partial + `layouts/app.blade.php`]). Pemindaian seluruh 36 file menunjukkan **0 pemakaian** helper statis/prototype jQuery yang dihapus di 4.0 (`$.isArray`, `$.trim`, `$.parseJSON`, `$.isFunction`, `$.isNumeric`, `$.type`, `$.now`, `$.unique`, `$.proxy`, `.size()`, `.bind()`, `.delegate()`, `.push`/`.sort`/`.splice` pada objek jQuery, `.toggleClass(bool)`).
   - **8 modul JS** mereferensikan DataTables: `adasi-loader.js`, `embed-readiness.js`, `session-sync.js`, `locale-switcher.js`, `local-po-gr-table.js`, `advanced-export.js`, `workspace-shell.js`, serta `public/assets/js/async-export.js` (ditambah `workspace-embed-bridge.js` yang memasang `ajaxSuccess` untuk mutasi jQuery non-GET).
   - **`resources/css/app.css`** memiliki selector DT 1.x pada baris `3635-3646`, `3724-3734`, `3805-3911`, `5246-5255`, `5516`, `5727-5884`, `5917`, `6370`, `6843`.
   - **Lokalisasi:** `lang/en/datatables.php` dan `lang/id/datatables.php` memiliki 11 key identik yang masuk ke payload `window.AdasiI18n` (< 16 KiB via `app/Support/JsTranslations.php:12`).
   - **Backend Yajra:** `composer.json:23` dan `composer.lock:9831-9832` mengunci `yajra/laravel-datatables-oracle` pada **`v12.7.2`**. `vendor/yajra/laravel-datatables-oracle/src/Utilities/Request.php:49-250` membaca payload standar (`draw`, `start`, `length`, `search`, `order`, `columns`) yang tetap identik di DataTables 3.1.5. Dari **18 controller `DataTables::`**, **12 controller** memanggil `$request->ajax()` (`11` controller DT utama + `SupplierRegistrationReviewController`).

### 1.2 Matriks Risiko

| ID | Area Risiko | Status Bukti | Dampak Jika Terlewat | Mitigasi dalam Rencana |
|---|---|---|---|---|
| **R-01** | **Transport `DataTable.ajax()` & `$request->ajax()` / Middleware / Interceptor JS** | `[Verified]` (DT 3 docs + 12 controller `$request->ajax()` dari 18 controller `DataTables::` [`11` DT utama + `SupplierRegistrationReviewController`] + 5 middleware + 4 `$.ajaxPrefilter` + `ajaxComplete`); `[Inferred]` perilaku exact XHR vs fetch DT 3.1.5 menunggu inspeksi Fase 0 | DT 3 memutus dependensi jQuery pada `DataTable.ajax()` dan **tidak mengirim `X-Requested-With: XMLHttpRequest` secara default**. Akibatnya `$request->ajax()` bernilai `false` di 12 controller DataTables tersebut (`UserController`, `PurchaseOrderController`, `PurchaseRequisitionController`, `SupplierRegistrationReviewController`, dll.), `WrapWorkspaceShell:28,59,111` mengira feed JSON adalah navigasi HTML, `SupplierScopeMiddleware:14` mengira request latar adalah navigasi, header non-prod `ngrok-skip-browser-warning` (`layouts/app.blade.php:610`) terlewati, dan `session-sync.js:280-313` (`ajaxComplete`/`$.ajaxPrefilter` CSRF/`errMode`) gagal menangkap 401/419 dari tabel. | **Fase 0:** Inspeksi sumber `dataTables.min.js` v3.1.5.<br>**Fase 2:** Setel `DataTable.ajax.defaults.headers['X-Requested-With'] = 'XMLHttpRequest'` (+ header ngrok non-prod), sinkronkan token CSRF pasca-reconnect ke `DataTable.ajax.defaults` & `preXhr.dt`, serta pasang listener `xhr.dt` / `error.dt` di `session-sync.js` (dengan guard anti-duplikasi jika `$.ajax` tetap terpanggil). |
| **R-02** | **Penghapusan `settings.nTableWrapper` & Properti Hungarian Internal** | `[Verified]` (`layouts/app.blade.php:536`, `adasi-loader.js:665`, `embed-readiness.js:289-296`, `locale-switcher.js:257-261`, `local-import-browser-check.mjs:220`) | `settings.nTableWrapper` bernilai `undefined` di DT 3.x sehingga `ui-dt-single-page`, `.ui-dt-loading-bar`/`.is-loading`, deteksi kesiapan iframe `WorkspaceShell`, refresh bahasa di `locale-switcher.js`, dan polling `_bInitComplete` di skrip CDP gagal atau error runtime. | **Fase 2 & 4:** Ganti seluruh 4 akses `settings.nTableWrapper` dengan `api.table().container()`, verifikasi `settings.oLanguage` / `bInitialised` / `jqXHR` pada sumber DT 3.1.5 di Fase 0, dan ganti `_bInitComplete` pada skrip CDP. |
| **R-03** | **Penghapusan Opsi `dom` → `layout`** | `[Verified]` (`8` file Blade / `9` inits + `DataTablesSingleSearchContractTest.php:65-73,181,239,294`) | Opsi `dom` dihapus total di DT 3.0. Inisialisasi `dom: 'rtip'` akan memunculkan search box bawaan ganda (`x-ui.filter-bar` + DT search), sedangkan `#vsBestTable` (`purchasing/comparison/_scripts.blade.php:1878`) akan kehilangan wrapper `.ui-data-table__scroll.ui-table-sticky` sehingga header lengket & kolom sticky rusak. | **Fase 3:** Konversi seluruh `8` inisialisasi `dom: 'rtip'` ke `layout: { topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: 'paging' }` dan `#vsBestTable` ke `layout` dengan wrapper `.ui-data-table__scroll.ui-table-sticky` di `initComplete`; perbarui kontrak regex test di Fase 4. |
| **R-04** | **Migrasi CSS `.dataTables_*` / `.sorting_*` → `.dt-*` & Kepatuhan `DESIGN.md`** | `[Verified]` (`resources/css/app.css:3635-6845`, `DESIGN.md:97-110,175,204-205`) | Selector lama tidak cocok di DT 3.1.5: bar loading `.ui-dt-loading-*` tidak tampil, `.dataTables_processing` bawaan muncul ganda, striping genap/kolom `.ui-col-data-name` mewarnai baris kosong `.dt-empty`, `.ui-dt-single-page` gagal menyembunyikan pager 1 halaman, padding `--ui-density-*` hilang pada kontrol DT, dan ikon sort bertumpuk dengan `span.dt-column-order`. | **Fase 3:** Migrasikan seluruh selector DT di `app.css` dengan mempertahankan token `--md-*`/`--ui-*`/`--ui-density-*`, `tw:` discipline, footer grid `.dt-layout-row`, serta sembunyikan pager 1 halaman & mobile collapse. |
| **R-05** | **Kontrak SRI `sha384-` & Guardrail Aset** | `[Verified]` (`FrontendAssetLoadingTest.php:134-174`, `AddSecurityHeaders.php:75-76`) | Hash SRI yang salah format (mis. hex atau berisi padding `=`, atau panjang ≠ 64 char Base64) atau penambahan tag CDN permanen (seperti menyisakan `jquery-migrate`) membuat `FrontendAssetLoadingTest` gagal (`assertCount(11, $assets)` & regex `sha384-[A-Za-z0-9+/]{64}`) atau browser memblokir eksekusi skrip. | **Fase 0:** Hitung `sha384-` dari byte biner resmi CDN via `HttpClient` / `openssl`.<br>**Fase 1:** Hapus `jquery-migrate-4.0.2.js` sebelum commit. |
| **R-06** | **Keamanan DB Test MySQL `adasi_portal_test` & Lock View Windows** | `[Verified]` (`phpunit.xml:142-149`, `AppServiceProvider.php:245-262`, `AGENTS.md:902,987`) | Menjalankan dua suite PHPUnit bersamaan atau menjalankan skrip CDP ber-DB bersamaan dengan PHPUnit memicu race condition `RefreshDatabase`, deadlock, atau konflik `CompiledViewFilesystem` di Windows. | **Fase 0 & 5:** Awali dengan `TestingEnvironmentDatabaseSafetyTest`; jalankan seluruh PHPUnit dan skrip CDP secara **serial ketat**, setelah regenerasi 4 fixture HTML. |

---

## 2. Matriks Pemetaan Breaking Changes

### 2.1 jQuery `3.7.1` → `4.0.0` → `4.1.0` (Dipetakan ke Audit 36 File Codebase)

| # | Perubahan jQuery `4.0.0` / `4.1.0` (`[Verified]` Dokumen Resmi) | Status di Codebase (`36` File `$()`/`jQuery()`: `27` `.blade.php` + `9` JS) (`[Verified]`) | Tindakan Wajib |
|---|---|---|---|
| **JQ-01** | **Removed Static APIs** (`$.isArray`, `$.parseJSON`, `$.trim`, `$.isFunction`, `$.isNumeric`, `$.isWindow`, `$.type`, `$.now`, `$.camelCase`, `$.nodeName`, `$.cssNumber`, `$.cssProps`, `$.fx.interval`, `$.unique`) | **0 kemunculan.** Codebase sudah menggunakan `Array.isArray()`, `.trim()`, `JSON.parse()`. | Tidak ada edit; verifikasi ulang via `JQMIGRATE` di Fase 1. |
| **JQ-02** | **Removed `$.fn` Array Prototype Methods** (`push`, `sort`, `splice` pada objek jQuery) & API usang (`.size()`, `.andSelf()`, `.bind()`, `.unbind()`, `.delegate()`, `.undelegate()`) | **0 kemunculan.** Seluruh `.push`/`.sort`/`.splice` hanya pada array JS biasa. | Tidak ada edit. |
| **JQ-03** | **`.toggleClass()` Boolean/No-Arg Signatures Removed** | **0 kemunculan.** Seluruh `19` pemanggilan `.toggleClass(...)` mengirim string nama class sebagai argumen pertama (`'d-none'`, `'is-invalid'`, dll.). | Tidak ada edit. |
| **JQ-04** | **Shorthand Event Binding (`.click(handler)`, dll.)** (deprecated sejak 3.0; dipantau oleh Migrate) | **2 kemunculan:** `resources/views/purchasing/pr/create.blade.php:305` dan `resources/views/purchasing/pr/edit.blade.php:327` (`$('#btnAddRow').click(addRow);`). | Ubah menjadi `$('#btnAddRow').on('click', addRow);` pada Fase 1 agar bebas warning `JQMIGRATE`. |
| **JQ-05** | **Strict Quick Selectors & ReDoS Hardening (4.0/4.1)** + **Pseudo-selectors non-CSS** | Pemakaian `:visible` ditemukan di `resources/views/purchasing/po/show.blade.php:1283, 1307` (`$target.is(':visible')`). Tetap valid di jQuery 4.x `$()`, namun **tidak** valid di native `querySelector` / DT 3.x. `:checked` dan `:not(.d-none)` di `po/create`, `_script`, `_supplier_picker_modal`, `qc/inspections/create`, `quotations/create` adalah CSS standar. | Pertahankan `$target.is(':visible')` (atau audit via Migrate di Fase 1); pastikan tidak pernah diteruskan ke API DataTables 3.x. |
| **JQ-06** | **Boolean Attributes `.attr()` vs `.prop()`** (4.0 menghapus shim boolean pada `.attr()`) | `supplier/quotations/create.blade.php:1956-1989` & `purchasing/po/show.blade.php:1590,1593` memakai `.attr('required', 'required')` / `.removeAttr('required')`. `purchasing/pr/_material_shape_script.blade.php:87-89` sudah memakai `.prop('disabled', ...).prop('hidden', ...)`. | `.attr('required', 'required')` dan `.removeAttr('required')` tetap valid untuk atribut HTML `required`, namun uji runtime di Fase 1; pertimbangkan `.prop('required', ...)` hanya bila ditemukan ketidaksesuaian state validasi browser. |
| **JQ-07** | **Native Focus/Blur Event Order** (`blur` → `focusout` → `focus` → `focusin`, menggantikan shim lama IE) | `supplier/quotations/create.blade.php:2658` (`blur` pada `.offer-length-input`), `purchasing/pr/_material_shape_script.blade.php:475, 839` (`focus input`). | Verifikasi urutan validasi & fokus di browser saat Fase 1 QA (tidak mengandalkan cross-element `focusout`/`blur`). |
| **JQ-08** | **`jQuery.ajax` Changes**: `FormData` binary payload didukung langsung di `options.data`; auto-promote JSON→JSONP & auto-exec script tanpa `dataType: "script"` dihapus; 4.1.0 menambah `jqXHR.responseURL` | **0 pemakaian** `jqXHR.responseURL` atau JSONP lintas-domain. `session-sync.js:292-300`, `adasi-loader.js:12-20`, `embed-readiness.js:374-377` sudah memeriksa `typeof options.data === 'string'` sebelum memanggil `.replace()` / regex. | Uji form `FormData` dan AJAX di `supplier/quotations/create.blade.php:1565`, `purchasing/po/show.blade.php:1421,1823,2356`, `purchasing/pr/_import.blade.php:294`, `purchasing/pr/_material_shape_script.blade.php`, dan `admin/material-hs-code/_script.blade.php` pada Fase 1. |
| **JQ-09** | **`.jquery` property, `.serializeArray()` (4.1.0 custom elements), `Object.prototype` keys di `.data()`** | `supplier/quotations/create.blade.php:2131` (`if (button?.jquery)`) tetap truthy (`"4.1.0"`); `:2868` (`$('#quotationForm').serializeArray()`) tetap kompatibel. **0** key `Object.prototype` di `.data()`. | Uji submit & modal di `supplier/quotations/create.blade.php` saat Fase 1. |

### 2.2 DataTables `1.13.6` → `2.x` → `3.1.5` (Dipetakan ke Codebase)

| # | Breaking Change DT `1.13.6` → `3.1.5` | Dampak Eksak di Codebase (`file:baris`) | Solusi Target di Fase 2 / 3 / 4 |
|---|---|---|---|
| **DT-01** | **Rename File CDN Core & BS5** (`jquery.dataTables.min.js` → `dataTables.min.js`) | `layouts/app.blade.php:359,496,497`; `FrontendAssetLoadingTest.php:165,171-173`; `DataTablesPreferenceTest.php:12`; `5` skrip CDP di `tests/Support/*-browser-check.mjs`. | Pakai `cdn.datatables.net/3.1.5/{css/dataTables.bootstrap5.min.css, js/dataTables.min.js, js/dataTables.bootstrap5.min.js}` + SRI `sha384-` hasil Fase 0. |
| **DT-02** | **Zero-Dependency `DataTable.ajax()` Transport** (`X-Requested-With: XMLHttpRequest` tidak otomatis dikirim; `$.ajaxPrefilter` / `ajaxComplete` / `ajaxSuccess` terlewati `[Inferred]`) | 12 controller gating `$request->ajax()` (`11` controller DT utama + `SupplierRegistrationReviewController`, dari 18 controller `DataTables::`), `WrapWorkspaceShell:28,59,111`, `SupplierScopeMiddleware:14`, `layouts/app.blade.php:610` (ngrok header), `session-sync.js:280-313` (401/419 & CSRF), `embed-readiness.js:289,356` (`settings.jqXHR`). | **Verifikasi di Fase 0.** Konfigurasikan `DataTable.ajax.defaults.headers['X-Requested-With'] = 'XMLHttpRequest'` (+ header ngrok non-prod), tambahkan hook `.dt` (`preXhr.dt`, `xhr.dt`, `error.dt`) di `session-sync.js` (hanya jika bypass `$.ajax` atau dengan `WeakSet` anti-duplikasi), dan sesuaikan `guardDataTables()` & `embed-readiness.js`. |
| **DT-03** | **Penghapusan `settings.nTableWrapper` & Properti Hungarian Internal** (`nTable`, `oLanguage`, `bInitialised`, `_bInitComplete`, `oFeatures.bServerSide`, `jqXHR`, `camelToHungarian`) | `layouts/app.blade.php:536`; `adasi-loader.js:665`; `embed-readiness.js:268,289-299,356`; `locale-switcher.js:257-261`; `local-import-browser-check.mjs:220`. | Migrasi seluruh akses wrapper ke `api.table().container()`, dan gunakan API publik (`DataTable.tables({ api: true })`, `api.page.info()`, `api.ready()` / event `.dt`). Verifikasi exact property `settings` tersisa di Fase 0. |
| **DT-04** | **Penghapusan `dom` → `layout` (Key Order BS5)** | 8 view Blade (9 inits) memakai `dom:` (`admin/auth-audit-logs/index`, `admin/material-hs-code/_script` ×2, `admin/users/index`, `purchasing/po/index`, `purchasing/shipments/index`, `supplier/quotations/period`, `supplier/shipments/index`, `purchasing/comparison/_scripts` `#vsBestTable`), serta `DataTablesSingleSearchContractTest.php`. | Konversi 8 init `dom: 'rtip'` ke `layout: { topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: 'paging' }` dan `#vsBestTable` ke `layout` + wrapper `.ui-data-table__scroll.ui-table-sticky` via `initComplete`. Pastikan `topStart` sebelum `topEnd` dan `bottomStart` sebelum `bottomEnd`. |
| **DT-05** | **Rename Seluruh Class CSS & Markup Header/Pager** | `resources/css/app.css:3635-6845`; `layouts/app.blade.php:510,511,518,527,546`; `locale-switcher.js:110,219,263`; `PaginationViewTest.php:149-150`; `CalendarComponentTest.php:167`; `DataTablesLocalizationTest.php:54`; `DataTablesSingleSearchContractTest.php:97`; `InforTableNameStripingContractTest.php:26-27`; `locale-switcher.test.mjs:433`; `text-contrast.test.mjs:191-192`; `modern-web-guidance-browser-check.mjs`; `workspace-shell-browser-check.mjs`. | Pemetaan eksak:<br>- `.dataTables_wrapper` → `.dt-container`<br>- `.dataTables_filter` → `.dt-search`<br>- `.dataTables_paginate` → `.dt-paging` (dan `.paginate_button` → `.dt-paging-button`)<br>- `.dataTables_length` → `.dt-length`<br>- `.dataTables_info` → `.dt-info`<br>- `.dataTables_processing` → `.dt-processing`<br>- `.dataTables_empty` → `.dt-empty`<br>- `.dataTables_scrollHeadInner` → `.dt-scroll-headInner`<br>- `.sorting` / `.sorting_asc` / `.sorting_desc` / `.sorting_*_disabled` → `.dt-orderable-asc` / `.dt-orderable-desc` / `.dt-ordering-asc` / `.dt-ordering-desc` / `.dt-orderable-none` + `<div class="dt-column-header"><span class="dt-column-title">` + `<span class="dt-column-order">`. |
| **DT-06** | **Hapus Sizzle (`querySelector` Native) & Perubahan `this` pada Callback** | Tidak ada `rows(':visible')` di DT API (hanya jQuery `$target.is(':visible')` di `po/show.blade.php`). Callback `initComplete` pada `purchasing/comparison/_scripts.blade.php:1881` sudah memakai `this.api().table().container()`. | Pertahankan aturan dilarang memakai pseudo-selector Sizzle (`:visible`, `:contains`, `:first`) pada selector DataTables; gunakan `this.api()` atau `$(this)` bila perlu metode jQuery pada elemen callback. |
| **DT-07** | **Struktur `language` & Default Pager** | `lang/{en,id}/datatables.php:1-15`; `layouts/app.blade.php:501`; `locale-switcher.js:257`. | Seluruh 11 key `lang/{en,id}/datatables.php` tetap valid (`paginate` tetap `paginate`, bukan `paging`). Pertahankan nilai `'Previous'`/`'Next'` (`'Sebelumnya'`/`'Berikutnya'`) agar `DataTablesLocalizationTest` & chevron CSS tetap selaras. |
| **DT-08** | **Protokol Server-Side `yajra/laravel-datatables-oracle v12.7.2`** | `composer.lock:9831-9832`; `vendor/yajra/laravel-datatables-oracle/src/Utilities/Request.php:49-250`; semua 18 controller `DataTables::` (`12` di antaranya memeriksa `$request->ajax()`: `11` controller DT utama + `SupplierRegistrationReviewController`). | Payload `draw`, `start`, `length`, `search[value]`, `order[i][column/dir]`, `columns[i]` kompatibel 100%; **tidak** perlu perubahan controller backend jika `X-Requested-With: XMLHttpRequest` disetel di frontend. |

---

## 3. Fase 0 — Prasyarat, Inspeksi Sumber CDN & Perhitungan SRI `sha384`

> **Catatan:** Selama penyusunan rencana ini, aset CDN eksternal **tidak** diunduh. Seluruh perintah di bawah dieksekusi pada awal **Fase 0** implementasi.

### 3.1 Persiapan Branch & Pre-Flight Database Safety

1. Pastikan working tree bersih dan buat branch kerja khusus:
   ```powershell
   git status
   git checkout -b chore/upgrade-jquery-4.1.0-datatables-3.1.5
   ```
2. Verifikasi bahwa database pengujian terisolasi ke `adasi_portal_test` sebelum menjalankan tes apa pun (`AGENTS.md:955`):
   ```powershell
   php artisan test --filter=TestingEnvironmentDatabaseSafetyTest
   ```

### 3.2 Perhitungan Hash SRI `sha384-` (Wajib 64 Karakter Base64)

`tests/Feature/FrontendAssetLoadingTest.php:145` mewajibkan regex tepat `/integrity="sha384-[A-Za-z0-9+\/]{64}"/`. Di Windows PowerShell, **jangan** memakai `Get-FileHash` (menghasilkan Hex) atau `(Invoke-WebRequest).Content` (mengubah encoding string UTF-16). Ambil byte mentah via `HttpClient` atau `curl` + `openssl`:

- **Opsi A — PowerShell (`System.Net.Http.HttpClient` + `SHA384`):**
  ```powershell
  $urls = @(
      'https://code.jquery.com/jquery-4.1.0.min.js',
      'https://code.jquery.com/jquery-migrate-4.0.2.js',
      'https://cdn.datatables.net/3.1.5/js/dataTables.min.js',
      'https://cdn.datatables.net/3.1.5/js/dataTables.bootstrap5.min.js',
      'https://cdn.datatables.net/3.1.5/css/dataTables.bootstrap5.min.css'
  )
  $http = [System.Net.Http.HttpClient]::new()
  $sha  = [System.Security.Cryptography.SHA384]::Create()
  foreach ($u in $urls) {
      $bytes = $http.GetByteArrayAsync($u).GetAwaiter().GetResult()
      $b64   = [Convert]::ToBase64String($sha.ComputeHash($bytes))
      Write-Output "$u -> sha384-$b64 (len=$($b64.Length))"
  }
  ```
- **Opsi B — Bash / Git Bash (`curl` + `openssl`):**
  ```bash
  for url in \
    "https://code.jquery.com/jquery-4.1.0.min.js" \
    "https://code.jquery.com/jquery-migrate-4.0.2.js" \
    "https://cdn.datatables.net/3.1.5/js/dataTables.min.js" \
    "https://cdn.datatables.net/3.1.5/js/dataTables.bootstrap5.min.js" \
    "https://cdn.datatables.net/3.1.5/css/dataTables.bootstrap5.min.css"; do
    hash=$(curl -sSfL "$url" | openssl dgst -sha384 -binary | openssl base64 -A)
    echo "$url -> sha384-$hash (len=${#hash})"
  done
  ```

### 3.3 Simpan Cache Lokal CDP & Checklist Inspeksi Sumber DataTables `3.1.5` (Wajib Sebelum Fase 2)

Simpan salinan byte resmi untuk cache skrip CDP dan inspeksi statis langsung ke `storage/framework/testing/` (atau direktori scratch jika hanya untuk inspeksi; `dataTables-3.1.5.min.js` tetap disimpan untuk CDP di Fase 4/5):

```powershell
$http = [System.Net.Http.HttpClient]::new()
[System.IO.File]::WriteAllBytes("storage/framework/testing/dataTables-3.1.5.min.js", $http.GetByteArrayAsync("https://cdn.datatables.net/3.1.5/js/dataTables.min.js").GetAwaiter().GetResult())
[System.IO.File]::WriteAllBytes("storage/framework/testing/dataTables.bootstrap5-3.1.5.min.js", $http.GetByteArrayAsync("https://cdn.datatables.net/3.1.5/js/dataTables.bootstrap5.min.js").GetAwaiter().GetResult())
[System.IO.File]::WriteAllBytes("storage/framework/testing/dataTables.bootstrap5-3.1.5.min.css", $http.GetByteArrayAsync("https://cdn.datatables.net/3.1.5/css/dataTables.bootstrap5.min.css").GetAwaiter().GetResult())
```

*(Catatan: setelah inspeksi selesai, hapus dua file sementara `dataTables.bootstrap5-3.1.5.min.{js,css}` jika tidak dipakai skrip CDP, sementara `storage/framework/testing/dataTables-3.1.5.min.js` dipertahankan untuk 5 skrip CDP.)*

**Checklist Inspeksi Wajib Sumber `dataTables-3.1.5.min.js` & `dataTables.bootstrap5-3.1.5.min.{js,css}`:**

1. **Transport `DataTable.ajax()` vs `jQuery.ajax`:**
   - Periksa implementasi internal fungsi `ajax` pada `dataTables-3.1.5.min.js`: apakah ia menggunakan `XMLHttpRequest` langsung atau `fetch`, apakah ia masih mendelegasikan ke `jQuery.ajax` saat `window.jQuery` ada atau selalu memakai transport native, dan apakah header `X-Requested-With: XMLHttpRequest` dikirim otomatis atau tidak.
   - Pastikan struktur exact `DataTable.ajax.defaults` (apakah properti `headers`, `beforeSend`, `dataType`, `type` ada di sana).
2. **Properti Objek `settings` DataTables 3.1.5:**
   - Pastikan apakah `settings.oLanguage` masih digunakan atau sudah diganti ke `settings.language` / API `tableApi.i18n` (menentukan implementasi `resources/js/locale-switcher.js:257-259`).
   - Pastikan status properti `settings.bInitialised`, `settings._bInitComplete`, `settings.oFeatures?.bServerSide`, dan `settings.jqXHR` (atau properti XHR/Promise penggantinya) untuk `resources/js/embed-readiness.js:268-300,356`, `resources/js/session-sync.js:310`, dan `tests/Support/local-import-browser-check.mjs:220`.
3. **Struktur DOM & CSS `.dt-paging` / `.dt-search` / `<th>` / `.dt-layout-table` pada Bootstrap 5:**
   - Periksa `dataTables.min.js` & `dataTables.bootstrap5-3.1.5.min.js`: catat struktur DOM eksak yang dihasilkan di dalam `<th>` (`.dt-column-header` / `.dt-column-title` / `.dt-column-order`, apakah `.dt-column-header` membungkus `.dt-column-title` dan `.dt-column-order` atau langsung anak `<th>`) — data ini wajib untuk menjaga translasi header di `resources/js/locale-switcher.js` (`syncDataTableWrapperHeaders` & `morphDomTree`).
   - Periksa `dataTables.bootstrap5-3.1.5.min.js`: pastikan apakah `.dt-paging` sudah merender elemen anak `<nav aria-label="...">` di dalamnya (penting agar `layouts/app.blade.php:546-551` tidak memasang `role="navigation"` ganda pada pembungkus luar `<nav>`), serta bagaimana class `ul.pagination > li.dt-paging-button.page-item > button.page-link` (atau `a.page-link`) dibentuk.
   - Periksa `dataTables.bootstrap5-3.1.5.min.css`: pastikan margin pada `table.dataTable`, margin `.mt-2` pada `.dt-layout-row` / `.dt-layout-table` (mis. `.row.mt-2.dt-layout-row.dt-layout-table`), perilaku `.dt-layout-table > .dt-layout-full` (termasuk kelas `d-md-flex` Bootstrap 5 apakah mengganggu sticky scrolling `<table>` pada `#vsBestTable` di `app.css:6370`), padding `.dt-search` / `.dt-length` / `.dt-info` / `.dt-paging`, dan posisi panah `.dt-column-order::before` / `::after` agar aturan override di `resources/css/app.css` menang bersih lewat source order.

---

## 4. Fase 1 — Upgrade jQuery `3.7.1` → `4.1.0` + Dev-Only `jquery-migrate-4.0.2.js` Audit

Fase ini mengisolasi upgrade jQuery terlebih dahulu sebelum menyentuh DataTables, sehingga setiap regresi jQuery 4.x terdeteksi bersih dari perubahan kelas/API DataTables 3.1.5.

### 4.1 Langkah Implementasi Fase 1

1. **Perbarui tag `<script>` jQuery di `resources/views/layouts/app.blade.php:492`:**
   - Ganti `https://code.jquery.com/jquery-3.7.1.min.js` dengan `https://code.jquery.com/jquery-4.1.0.min.js` beserta `integrity="sha384-<HASH_JQUERY_4_1_0>"` dari Fase 0 (`crossorigin="anonymous"` tetap).
2. **Pasang sementara `jquery-migrate-4.0.2.js` KHUSUS dev audit (JANGAN di-commit):**
   - Tepat di bawah baris `:492`, muat secara kondisional hanya pada lokal & di luar PHPUnit (atau sementara saat QA browser, lalu **hapus baris ini sebelum commit**):
     ```blade
     @if(app()->isLocal() && !app()->runningUnitTests())
         <script src="https://code.jquery.com/jquery-migrate-4.0.2.js" integrity="sha384-<HASH_MIGRATE_4_0_2>" crossorigin="anonymous"></script>
     @endif
     ```
   - *Catatan kontrak:* Meskipun di-gate oleh `app()->isLocal()`, `FrontendAssetLoadingTest.php:136-150` memindai file `.php` di `resources/views/` secara statis dan menegaskan `assertCount(11, $assets)`! Oleh karena itu, tag `<script>` `jquery-migrate-4.0.2.js` **WAJIB dihapus sepenuhnya** dari `layouts/app.blade.php` segera setelah audit `JQMIGRATE` selesai (sebelum menjalankan test gate atau commit).
   - Host `https://code.jquery.com` sudah ada di `AddSecurityHeaders.php:76`, sehingga tidak perlu mengubah middleware CSP.
3. **Pembersihan Kode Terkait jQuery 4.x pada 36 File (`27` `.blade.php` + `9` JS):**
   - `resources/views/purchasing/pr/create.blade.php:305`: ganti `$('#btnAddRow').click(addRow);` → `$('#btnAddRow').on('click', addRow);`.
   - `resources/views/purchasing/pr/edit.blade.php:327`: ganti `$('#btnAddRow').click(addRow);` → `$('#btnAddRow').on('click', addRow);`.
   - Periksa hasil `JQMIGRATE` pada seluruh file jQuery berat berikut melalui browser console:
     - `resources/views/purchasing/pr/_material_shape_script.blade.php` (`$.ajax` di `:153,246,742,803`, `.prop()` di `:87-89`, `focus input` di `:475,839`)
     - `resources/views/admin/material-hs-code/_script.blade.php` (`$.ajax` di `:155,177,201,210,307,328`, `:checked` di `:286,288`)
     - `resources/views/supplier/quotations/create.blade.php` (`button?.jquery` di `:2131`, `.serializeArray()` di `:2868`, `.attr('required', 'required')` / `.removeAttr('required')` di `:1956-1989`, `blur` di `:2658`, `$.ajax` di `:1565`)
     - `resources/views/purchasing/po/show.blade.php` (`$target.is(':visible')` di `:1283,1307`, `.attr('required', 'required')` di `:1590,1593`, `.prop('value', ...).attr('value', ...)` di `:1457-1458`, `$.ajax` di `:1421,1823,2356`)
     - `resources/views/purchasing/pr/_import.blade.php` (`$.ajax` `FormData` di `:294`)
   - *Catatan:* DataTables 1.13.6 sendiri mungkin memicu warning `JQMIGRATE` internal (jika ada pemanggilan internal DT 1.x) — bedakan warning yang berasal dari `jquery.dataTables.min.js` (akan hilang di Fase 2) vs warning dari kode portal (`views/*.blade.php` / `resources/js/*.js`, yang wajib diselesaikan pada Fase 1).

### 4.2 Verifikasi & Rollback Fase 1

- **Verifikasi cepat Fase 1:** Buka halaman PR Create/Edit, Quotation Create, PO Show, Material HS Code, dan PR Import di browser dengan DevTools terbuka; pastikan **0** `JQMIGRATE` warning dari skrip portal dan **0** JS error pada `FormData`, `.serializeArray()`, toggle `required`, serta `blur`/`focus`.
- **Hapus tag `jquery-migrate-4.0.2.js`** dari `resources/views/layouts/app.blade.php`.
- **Rollback Fase 1:** Kembalikan `layouts/app.blade.php:492` ke `jquery-3.7.1.min.js` beserta SRI asli (`sha384-1H217gwSVyLSIfaLxHbE7dRb3v4mYCKbpQvzx0cegeju1MVsGrX5xXxAvs/HgeFs`), dan jalankan `php artisan view:cache`.

---

## 5. Fase 2 — Upgrade Core DataTables `1.13.6` → `3.1.5`

Seluruh perubahan di bawah memanfaatkan hasil **Inspeksi Wajib Fase 0 (§3.3)**.

### 5.1 `resources/views/layouts/app.blade.php`

1. **Tag CDN CSS & JS (`:359`, `:496`, `:497`):**
   - `:359`:
     ```blade
     <link rel="stylesheet" href="https://cdn.datatables.net/3.1.5/css/dataTables.bootstrap5.min.css" integrity="sha384-<HASH_DT_BS5_CSS>" crossorigin="anonymous">
     ```
   - `:496-497`:
     ```blade
     <script src="https://cdn.datatables.net/3.1.5/js/dataTables.min.js" integrity="sha384-<HASH_DT_CORE_JS>" crossorigin="anonymous"></script>
     <script src="https://cdn.datatables.net/3.1.5/js/dataTables.bootstrap5.min.js" integrity="sha384-<HASH_DT_BS5_JS>" crossorigin="anonymous"></script>
     ```
2. **Konfigurasi Global `AdasiDataTable`, Transport `DataTable.ajax.defaults`, Aksesibilitas Search & Pager (`:500-553`):**
   - **Defaults & Transport Header (`X-Requested-With` & CSRF):**
     - Pertahankan `window.AdasiDataTable = Object.freeze({ defaults: () => ({ processing: true, pageLength: window.AdasiPreferences?.pageSize || 25, lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]], language: @js(__('datatables')) }) });` (dijaga oleh `DataTablesPreferenceTest.php` & `DataTablesLocalizationTest.php`).
     - Terapkan defaults ke `window.jQuery.fn.dataTable.defaults` (dan/atau `window.DataTable.defaults` jika tersedia).
     - **Wajib (hasil verifikasi Fase 0):** Jika `DataTable.ajax()` tidak otomatis mengirim `X-Requested-With: XMLHttpRequest`, setel secara global pada `window.DataTable?.ajax?.defaults`:
       ```js
       if (window.DataTable?.ajax?.defaults) {
           window.DataTable.ajax.defaults.headers = {
               ...(window.DataTable.ajax.defaults.headers || {}),
               'X-Requested-With': 'XMLHttpRequest',
           };
       }
       ```
       Ini menjamin seluruh 12 controller `$request->ajax()` (`11` controller DT utama + `SupplierRegistrationReviewController`, dari total 18 controller `DataTables::`), `WrapWorkspaceShell`, `SupplierScopeMiddleware`, `ShareAuthSplashState`, dan `EnforceMaintenance` tetap mengenali request DataTables sebagai AJAX tanpa mengubah satu baris pun di controller backend.
   - **Search Input Accessibility (`:506-530`):**
     - Ganti selector `.dataTables_filter` menjadi **`.dt-search`** pada `node.matches('.dt-search')`, `node.querySelectorAll('.dt-search')`, dan `document.querySelectorAll('.dt-search')`.
     - Perbarui derivasi `tableId` (`:518`) karena DT 3 `.dt-search` tidak lagi memiliki `id="<tableId>_filter"` pada wrapper pencarian: tambahkan fallback eksplisit `searchInput.getAttribute('aria-controls')`:
       ```js
       const tableId = wrapper.id?.replace(/_filter$/, '')
           || searchInput.getAttribute('aria-controls')
           || `adasi-data-table-${++generatedSearchId}`;
       ```
       Pertahankan ``if (!searchInput.id) searchInput.id = `${tableId}-search` ``, ``if (!searchInput.name) searchInput.name = `${tableId}_search` ``, dan `aria-label` menggunakan `window.AdasiI18n.t('datatables.search')` sehingga penamaan semantik `<tableId>_search` serta kontrak `DataTablesLocalizationTest.php:55-58` tetap terpenuhi!
   - **Handler `draw.dt order.dt` (`:534-552`):**
     - Hapus `const wrapper = settings?.nTableWrapper;` (`:536`). Ganti dengan API publik DT 3:
       ```js
       const ApiCtor = window.DataTable?.Api || window.jQuery?.fn?.dataTable?.Api;
       if (!ApiCtor || !settings) return;
       const api = new ApiCtor(settings);
       const wrapper = api.table().container();
       if (!wrapper) return;
       ```
     - Hitung `const pages = api.page.info().pages;` dan toggle `wrapper.classList.toggle('ui-dt-single-page', pages <= 1);`.
     - Ganti `wrapper.querySelector('.dataTables_paginate')` (`:546`) menjadi `wrapper.querySelector('.dt-paging')`.
     - **Hindari landmark `<nav>` ganda:** Di DT 2/3 BS5, `.dt-paging` sering berisi elemen anak `<nav aria-label="pagination">`! Gunakan target yang tepat:
       ```js
       const pager = wrapper.querySelector('.dt-paging');
       if (pager) {
           const navTarget = pager.matches('nav') ? pager : pager.querySelector('nav');
           const landmark = navTarget || pager;
           if (!navTarget && !landmark.hasAttribute('role')) {
               landmark.setAttribute('role', 'navigation');
           }
           if (!landmark.hasAttribute('aria-label')) {
               const title = wrapper.closest('.ui-data-table')?.querySelector('.ui-data-table__header h2')?.textContent.trim();
               landmark.setAttribute('aria-label', title ? `${paginationLabel}: ${title}` : paginationLabel);
           }
       }
       ```
       *(Sesuaikan dengan temuan Fase 0 & penjaga `PaginationViewTest.php:157`.)*
3. **Ngrok Header Non-Production (`:609-615`):**
   - Pertahankan `$.ajaxPrefilter` untuk jQuery AJAX biasa, dan jika `DataTable.ajax()` bypass `$.ajax`, tambahkan header `ngrok-skip-browser-warning: 'true'` ke `window.DataTable.ajax.defaults.headers` (atau via `$(document).on('preXhr.dt', ...)` / `beforeSend`) di dalam blok `@unless(app()->isProduction() || config('app.env') === 'production')`.

### 5.2 `resources/js/adasi-loader.js` (`:658-666`)

- Pada handler `processing.dt` (`:664-666`), ganti `settings?.nTableWrapper` menjadi:
  ```js
  $(win.document).on('processing.dt', (event, settings, busy) => {
      const ApiCtor = win.DataTable?.Api || $.fn?.dataTable?.Api;
      const wrapper = settings && ApiCtor ? new ApiCtor(settings).table().container() : null;
      setTableBusy(wrapper, Boolean(busy));
  });
  ```
- Pada `setTableBusy(wrapper, ...)` (`:446-509`), pastikan tidak ada asumsi kelas `.dataTables_wrapper` yang hardcoded selain `.is-loading` / `.ui-data-loading-host` / `:scope > .ui-data-table__header`. (Verifikasi `adasi-loader.js:446-509` sebelum edit.)
- `$.ajaxPrefilter` (`:658-662`) sudah sengaja melewati request DataTables (`isDataTableRequest`), sehingga jika `DataTable.ajax()` tidak memanggil `$.ajax`, perilaku loader penuh-layar tetap aman (tabel tetap menggunakan `processing.dt`).

### 5.3 `resources/js/embed-readiness.js` (`:261-304`, `:343-390`)

- Di `scanActiveTables()` (`:282-304`):
  - **Perbaikan API DT 3 (`tables({ api: true })` vs `settings()`):** `DataTable.tables({ api: true })` mengembalikan instance `Api` yang elemen terindeksnya (`apiTables[i]`) adalah node DOM `HTMLTableElement`, **bukan** objek `settings`! Jangan mengiterasi `apiTables[i]` langsung ke `tableKeyFor()`/`isAjaxDataTable()`. Gunakan:
    ```js
    const ApiCtor = win.DataTable?.Api || win.jQuery?.fn?.dataTable?.Api;
    const apiTables = win.DataTable?.tables?.({ api: true }) || win.jQuery?.fn?.dataTable?.tables?.({ api: true });
    const settingsList = apiTables?.settings?.() || win.DataTable?.settings || win.jQuery?.fn?.dataTable?.settings || [];
    ```
    *(atau iterasi setiap node tabel pada `apiTables` melalui `new ApiCtor(tbl).settings()[0]`)*, lalu ganti pembacaan `settings.nTableWrapper` (`:290, 296`) dengan `const container = ApiCtor ? new ApiCtor(settings).table().container() : settings.nTableWrapper;`.
  - Sesuaikan pengecekan status init & request aktif (`settings.bInitialised`, `settings.oFeatures?.bServerSide`, `settings.jqXHR` pada `:268, 289, 291, 293, 356`) sesuai properti aktual DT 3.1.5 hasil **Inspeksi Wajib Fase 0**:
    - Gunakan `api.ready()` (jika didukung di DT 3) atau cek `!container?.querySelector('.dt-empty')` / status event `preXhr.dt` / `draw.dt` / `error.dt` yang dilacak oleh `controller` (`embed-readiness.js:343-370`).
    - Pastikan jika `settings.jqXHR` tidak lagi ada (karena `DataTable.ajax()` memakai `XMLHttpRequest` / `fetch` native), `scanActiveTables` tidak menganggap XHR selalu selesai lebih dini (`domLoading` / `.is-loading` pada `container` + `preXhr.dt` / `xhr.dt` tetap diobservasi).
  - Tambahkan cakupan unit test untuk `scanActiveTables` pada `tests/js/workspace-shell.test.mjs` (§7.2).

### 5.4 `resources/js/session-sync.js` (`:278-313`)

- **Observasi Event `.dt` untuk Sesi `401`/`419` & Idle Touch (dengan Guard Anti-Duplikasi):**
  - Pasang listener `xhr.dt` / `error.dt` di `observeJQuery` (atau fungsi khusus `observeDataTables(win, doc, callbacks, tokens)`) **HANYA JIKA** inspeksi Fase 0 membuktikan `DataTable.ajax()` melewati `$.ajax` (`ajaxComplete`), **ATAU** lindungi dengan `const handledXhrs = new WeakSet();` (mencatat setiap objek `xhr` yang sudah ditangani) agar `onActionResponse(xhr)` dan `onServerTouch()` **tidak pernah terpanggil dua kali** untuk satu request tabel apabila DT 3.1.5 masih mendelegasikan ke `$.ajax` saat `window.jQuery` tersedia:
    1. Pada event `xhr.dt` (`(event, settings, json, xhr)`): jika `xhr && handledXhrs.has(xhr)` lewati (atau tandai `handledXhrs.add(xhr)`); cek `sessionExpiredByResponse(xhr)` → panggil `onActionResponse(xhr)`; jika status `> 0 && < 400` → panggil `onServerTouch()`.
    2. Pada event `error.dt` (`(event, settings, techNote, message)`): periksa objek XHR pada `settings` (baik `settings?.jqXHR` maupun properti XHR DT 3.1.5 dari Fase 0) melalui `handledXhrs` untuk `401`/`419` → panggil `onActionResponse(...)`.
    3. Saat `applyCsrfToken()` merotasi token pasca-reconnect, perbarui pula `win.DataTable?.ajax?.defaults?.headers` (jika token CSRF dikirim sebagai header oleh konfigurasi/tabel mana pun) serta `preXhr.dt` agar tidak mengirim token usang.
- **Perbarui `guardDataTables(win)` (`:306-313`):**
  - Ambil `const ext = win.DataTable?.ext || win.jQuery?.fn?.dataTable?.ext;` dan dalam callback `ext.errMode` cek objek response dari `settings?.jqXHR` maupun properti XHR hasil Fase 0, agar dialog Reconnect tidak tertumpuk `window.alert('Ajax error')`.

### 5.5 `resources/js/workspace-embed-bridge.js` (`:301-309`)

- `$(document).ajaxSuccess` (`:302`) dipakai hanya untuk mendeteksi mutasi AJAX non-GET/HEAD/OPTIONS (`!['GET', 'HEAD', 'OPTIONS'].includes(method)`).
- Seluruh endpoint DataTables di portal ini bersifat read-only `GET`. Namun sebagai pertahanan berlapis, jika di masa depan ada DataTables `ajax.type: 'POST'` mutasi, `xhr.dt` tidak memicu `ajaxSuccess`; catat pemeriksaan `ajax.type` di Fase 2 (tetap biarkan perilaku saat ini karena seluruh 18 endpoint DT memakai `GET` atau read-only).

### 5.6 `resources/js/locale-switcher.js` (`:101-122, 219, 227-235, 242-282`)

- **Kompatibilitas Header `<th>` pada `syncDataTableWrapperHeaders` (`:101-122`) & `morphDomTree` (`:227-235`):**
  - Pada DT 3.1.5, setiap `<th>` di `liveThead` berisi anak elemen DOM (`.dt-column-title` + `.dt-column-order`, baik langsung atau di dalam `<div class="dt-column-header">`), sedangkan `serverTh` dari HTML SSR berisi node teks polos (`nodeType === 3`). Karena `morphDomTree` (`:232-235`) men-skip `serverChild` non-elemen saat `liveChild.nodeType === 1`, pemanggilan langsung `morphDomTree(liveThead, serverThead)` (`:119`) akan melewati teks terjemahan pada `<th>` DT 3.1.5 sehingga header kolom gagal diterjemahkan dan mematahkan `tests/Support/workspace-shell-browser-check.mjs:1043`.
  - **Solusi wajib (sesuai struktur exact `<th>` hasil Fase 0):** Pada `syncDataTableWrapperHeaders` (atau cabang khusus `liveRoot.tagName === 'TH'` di `morphDomTree`), ketika `liveTh` memiliki `.dt-column-title` dan `serverTh` tidak memilikinya, tetap sinkronkan `TRANSLATED_ATTRIBUTES` pada `<th>` lalu perbarui konten teks `.dt-column-title` dari `serverTh` (`liveTitleEl.textContent = serverTh.textContent` atau morph anak teks `.dt-column-title` dari `serverTh.childNodes`) **tanpa menimpa/menghapus `.dt-column-order` maupun event listener sort DataTables**.
- Line `:110`: ganti `.dataTables_scrollHeadInner table` → `.dt-scroll-headInner table`.
- Line `:219`: ganti `liveChild.classList?.contains?.('dataTables_wrapper')` → `liveChild.classList?.contains?.('dt-container')`.
- Line `:242-282` (`refreshDataTablesLanguage`):
  - Gunakan `const ApiCtor = windowRef.DataTable?.Api || jq?.fn?.dataTable?.Api;` dan `const tables = (windowRef.DataTable?.tables?.({ api: true }) || jq?.fn?.dataTable?.tables?.({ api: true }));`.
  - Konstruksi `const tableApi = new ApiCtor(settings);` sebelum membaca wrapper, dan ganti `settings.nTableWrapper` (`:261`) dengan `tableApi.table().container()`.
  - Ganti `.dataTables_filter input[type="search"]` (`:263`) dengan `.dt-search input[type="search"]`.
  - Untuk update bahasa live (`:257-259`), sesuaikan dengan hasil Fase 0: jika `settings.oLanguage` sudah berubah menjadi `settings.language` / `i18n` pada DT 3.1.5, perbarui key target yang benar (serta `DataTable.defaults.language`) lalu panggil `tableApi.ajax.reload(null, false)` atau `tableApi.draw('page')`, dan sinkronkan label/placeholder pagination & info setelah draw.

### 5.7 `resources/js/local-po-gr-table.js` (`:82-115`), `advanced-export.js` (`:232-234`), `async-export.js` (`:161-181`), `workspace-shell.js` (`:3328-3344`)

1. **`resources/js/local-po-gr-table.js:82-115`:**
   - Pertahankan inisialisasi `$(element).DataTable(...)` dan listener `$(element).on('xhr.dt', ...)`.
   - Verifikasi pada Fase 2 bahwa `$.fn.dataTable.ext.errMode = 'none'` (`:91`) dan `ajax.error` (`:95`) bekerja dengan transport `DataTable.ajax()` v3.1.5 saat endpoint mengembalikan 4xx/5xx (menampilkan `[data-gr-table-error]`).
2. **`resources/js/advanced-export.js:232-234`:**
   - Verifikasi `window.jQuery?.fn?.DataTable?.isDataTable(root.dataset.exportTable)` dan `.DataTable().search().trim()` (atau gunakan fallback `window.DataTable?.isDataTable` bila diperlukan) tetap mengembalikan string pencarian aktif.
3. **`public/assets/js/async-export.js:161-181`:**
   - Verifikasi `window.jQuery.fn.dataTable.isDataTable(table)` dan `window.jQuery(table).DataTable().page.info().recordsDisplay` tetap mengembalikan integer `recordsDisplay` (`CustomAdasiToastTest.php:173-177` memverifikasi baris ini tetap memakai `.recordsDisplay`, bukan `.recordsTotal`).
4. **`resources/js/workspace-shell.js:3328-3344` (`refreshFrameSilently`):**
   - Verifikasi `win?.jQuery?.fn?.dataTable.tables({ api: true })`, `tables.count()`, `tables.ajax?.url()`, dan `tables.ajax.reload(..., false)` tetap berjalan untuk silent refresh antar-tab pada DT 3.1.5.

### 5.8 Lokalisasi (`lang/en/datatables.php` & `lang/id/datatables.php`)

- Pertahankan seluruh **11 key** yang ada di kedua file (`search`, `lengthMenu`, `info`, `infoEmpty`, `infoFiltered`, `emptyTable`, `zeroRecords`, `loadingRecords`, `processing`, `paginate.{first,last,previous,next}`, `aria.{sortAscending,sortDescending}`) agar `TranslationParityTest`, `DataTablesLocalizationTest`, dan batas payload `< 16 KiB` pada `LocalizationPerformanceTest` tetap hijau.
- Cek hasil inspeksi DT 3.1.5 pada Fase 0: jika tombol pagination Bootstrap 5 DT 3 membutuhkan `aria.paginate.{first,previous,next,last}` untuk screen reader (mengingat ikon/teks `Previous`/`Next` di `lang/*/datatables.php` adalah teks penuh, DT 3 BS5 mungkin sudah melabeli atau memakai `aria.paginate`), hanya tambahkan `aria.paginate` secara berpasangan di `lang/en/datatables.php` dan `lang/id/datatables.php` jika diperlukan, lalu verifikasi `LocalizationPerformanceTest` dan `TranslationParityTest`.

---

## 6. Fase 3 — Migrasi CSS Design System (`resources/css/app.css`) & Halaman Blade (`dom` → `layout` / Parity Check)

### 6.1 Pemetaan Eksak `resources/css/app.css`

Semua perubahan CSS wajib mematuhi `DESIGN.md`: tanpa hex mentah di luar blok `--md-ref-*`, gunakan token `--md-*` / `--ui-*` / `--ui-density-*`, dan jangan menghapus aturan `tw:` prefix atau layer di luar selector DT.

| Baris `app.css` (`[Verified]`) | Selector Lama (DT 1.13.6) | Target Migrasi (DT 3.1.5) & Alasan `DESIGN.md` |
|---|---|---|
| `:3635, :3640, :3645` | `:not(:has(> td.dataTables_empty))` | Ganti menjadi **`:not(:has(> td.dt-empty))`**. Menjaga hybrid striping (baris genap penuh pada Index vs kolom vertikal `.ui-col-data-name` pada Detail/Comparison) dan hover tidak mewarnai baris kosong. |
| `:3724` | `.ui-data-table .dataTables_wrapper` | Ganti menjadi **`.ui-data-table .dt-container`** (`padding: 0;`). |
| `:3727-3730` | `.ui-data-table :is(.dataTables_filter, .dataTables_length, .dataTables_info, .dataTables_paginate)` | Ganti menjadi **`.ui-data-table :is(.dt-search, .dt-length, .dt-info, .dt-paging)`**. Mempertahankan warna `--md-on-surface-variant`, `font-size: var(--ui-font-size-sm)`, dan padding default. |
| `:3806` | `div.dataTables_wrapper div.dataTables_paginate ul.pagination` | Ganti menjadi **`div.dt-container div.dt-paging ul.pagination`** (`justify-content: flex-end; margin: 0;`). Pertahankan satu skin pager bersama `resources/views/vendor/pagination/tailwind.blade.php`. |
| `:3811-3813` | `.dataTables_wrapper > .row` | Ganti menjadi **`.dt-container > .row`** (dan `.dt-container > .dt-layout-row` bila BS5 3.1.5 memakainya sesuai Fase 0), `--bs-gutter-x: 0;`. |
| `:3815-3832` | `.dataTables_wrapper > .row:has(.dataTables_info, .dataTables_paginate)` (dan `> *`, `:is(.dataTables_info, .dataTables_paginate)`) | Ganti menjadi **`.dt-container > :is(.row, .dt-layout-row):has(.dt-info, .dt-paging)`** dan anak **`:is(.dt-info, .dt-paging)`**. Mempertahankan footer bar `--md-surface-container-low`, border atas `--md-outline-variant`, dan padding `--ui-density-panel-*`. |
| `:3833-3836` | `.dataTables_wrapper .dataTables_info` | Ganti menjadi **`.dt-container .dt-info`** (`font-size: var(--ui-font-size-xs); font-variant-numeric: tabular-nums;`). |
| `:3838-3842` | `.ui-data-table .dataTables_wrapper table.dataTable, .dataTables_wrapper table.dataTable` | Ganti menjadi **`.ui-data-table .dt-container table.dataTable, .dt-container table.dataTable`** (`margin-top: 0 !important; margin-bottom: 0 !important;`), **plus** nolkan margin atas `.mt-2` Bootstrap 5 pada row tabel DT 3 (`.ui-data-table .dt-container > .dt-layout-table, .dt-container > .dt-layout-row.dt-layout-table { margin-top: 0 !important; }`, sesuai inspeksi `dataTables.bootstrap5-3.1.5.min.css` di Fase 0) agar tabel tidak terdorong ke bawah di dalam `.ui-data-table`. |
| `:3844-3860` | `.dataTables_paginate .page-item:is(.previous, .next) .page-link::before/::after` | Ganti menjadi **`.dt-paging .page-item:is(.previous, .next) .page-link::before/::after`** (atau verifikasi kelas `.previous`/`.next`/`.dt-paging-button` dari Fase 0 bila BS5 DT 3 memakai `data-dt-idx="previous"` / `button.page-link` / `aria-label`). |
| `:3861-3879` | `.dataTables_wrapper:not(:has(> .row))` CSS Grid fallback untuk `dom: 'rtip'` | Di DT 3.1.5 `layout`, semua komponen termasuk `bottomStart: 'info', bottomEnd: 'paging'` dibungkus baris `.row.dt-layout-row` (tidak pernah sibling langsung wrapper). Pertahankan varian row-based footer di atas untuk `:has(.dt-info, .dt-paging)` dan sembunyikan `.dt-container > :is(.row, .dt-layout-row):empty` jika `topStart: null, topEnd: null` menghasilkan row kosong (verifikasi di Fase 0). |
| `:3881-3886` | `.dataTables_wrapper.ui-dt-single-page .dataTables_paginate` (+ `:not(:has(> .row)) > .dataTables_info`) | Ganti menjadi **`.dt-container.ui-dt-single-page .dt-paging { display: none; }`** (dan bila baris footer memakai flex/grid 2 kolom di BS5, pastikan `.dt-info` tetap rata kiri penuh saat `.dt-paging` disembunyikan). |
| `:3894, :3901-3910` | `@media (max-width: 575.98px)`: `div.dataTables_wrapper div.dataTables_paginate ul.pagination`, `.dataTables_wrapper > .row:has(...)`, `.dataTables_wrapper:not(:has(> .row))` | Ganti menjadi **`div.dt-container div.dt-paging ul.pagination`** dan **`.dt-container > :is(.row, .dt-layout-row):has(.dt-info, .dt-paging)`** (plus pertahankan `.pagination:has(> .active) > .page-item:not(.previous, .next, .active, :has(+ .active), .active + .page-item) { display: none; }` di `:3898`). |
| `:5246-5255` | `table.dataTable thead > tr > :is(th, td):is(.sorting, .sorting_asc, .sorting_desc, .sorting_asc_disabled, .sorting_desc_disabled, .sorting_disabled)` + `::before`/`::after` | Ganti ke sistem header DT 2/3: targetkan **`table.dataTable thead > tr > :is(th, td):is(.dt-orderable-asc, .dt-orderable-desc, .dt-ordering-asc, .dt-ordering-desc, .dt-orderable-none)`** untuk `min-width: max-content; padding-inline-end: 2.25rem !important; position: relative;`, dan posisikan ikon sort pada **`span.dt-column-order::before` / `::after`** (atau `right: .75rem` sesuai DOM DT 3.1.5 hasil Fase 0; jangan sampai bertabrakan dengan panah default `dataTables.bootstrap5.min.css`). |
| `:5516` | `:not(:has(> td.dataTables_empty))` pada sticky-col hover | Ganti menjadi **`:not(:has(> td.dt-empty))`**. |
| `:5727, :5743, :5755, :5789, :5820, :5857, :5860, :5865-5866, :5876-5877, :5883` | `.dataTables_wrapper` dan `td.dataTables_empty` pada blok `.ui-dt-loading-bar` / `.ui-dt-loading-pill` / `.is-loading` | Ganti seluruh `.dataTables_wrapper` → **`.dt-container`** dan `td.dataTables_empty` → **`td.dt-empty`**. |
| `:5917` | `div.dataTables_wrapper div.dataTables_processing { display: none !important; }` | Ganti menjadi **`div.dt-container div.dt-processing { display: none !important; }`**. |
| `:6370` | `.ui-table-sticky.ui-data-table__scroll > table.dataTable > thead > tr > th { position: sticky; }` | Pertahankan **persis**; pastikan `#vsBestTable` tetap berada langsung di dalam `.ui-table-sticky.ui-data-table__scroll`. |
| `:6843` | `.ui-data-table :is(.dataTables_filter, .dataTables_length, .dataTables_info, .dataTables_paginate)` (di blok `@media screen` density) | Ganti menjadi **`.ui-data-table :is(.dt-search, .dt-length, .dt-info, .dt-paging)`** agar seluruh 5 tier `--ui-density-*` tetap berlaku. |

### 6.2 Konversi `dom:` → `layout:` pada 8 File Blade (9 Inisialisasi)

Aturan DataTables 3 (`[Verified]`): opsi `dom` dihapus di DT 3.0; pada integrasi Bootstrap 5, urutan key di objek `layout` (`topStart` sebelum `topEnd`, `bottomStart` sebelum `bottomEnd`) wajib dijaga agar alignment flex start/end benar.

| # | File Blade & Baris | Tabel | Konfigurasi Lama (`dom:`) | Konfigurasi Baru DT 3.1.5 (`layout:`) |
|---|---|---|---|---|
| 1 | `resources/views/admin/auth-audit-logs/index.blade.php:98` | `#authAuditTable` | `dom: 'rtip',` | `layout: { topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: 'paging' },` |
| 2 | `resources/views/admin/material-hs-code/_script.blade.php:32` | `#materialsTable` | `dom: 'rtip',` | `layout: { topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: 'paging' },` |
| 3 | `resources/views/admin/material-hs-code/_script.blade.php:61` | `#rulesTable` | `dom: 'rtip',` | `layout: { topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: 'paging' },` |
| 4 | `resources/views/admin/users/index.blade.php:100` | `#usersTable` | `dom: 'rtip',` | `layout: { topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: 'paging' },` |
| 5 | `resources/views/purchasing/po/index.blade.php:108` | `#poTable` | `dom: 'rtip',` | `layout: { topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: 'paging' },` |
| 6 | `resources/views/purchasing/shipments/index.blade.php:182` | `#shipmentTable` | `dom: 'rtip',` | `layout: { topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: 'paging' },` |
| 7 | `resources/views/supplier/quotations/period.blade.php:98` | `#prTable` | `dom: 'rtip',` | `layout: { topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: 'paging' },` |
| 8 | `resources/views/supplier/shipments/index.blade.php:202` | `#supplierShipmentTable` | `dom: 'rtip',` | `layout: { topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: 'paging' },` |
| 9 | `resources/views/purchasing/comparison/_scripts.blade.php:299, 1870, 1878-1886` | `#vsBestTable` | `dom: "<'row'...'l'...'f'>" + "<'row dt-row'<'col-sm-12'<'ui-data-table__scroll ui-table-sticky'tr>>>" + "<'row'...'i'...'p'>"` | Ganti `dom` dengan:<br>`layout: { topStart: 'pageLength', topEnd: 'search', bottomStart: 'info', bottomEnd: 'paging' },`<br>**Wajib tangani siklus `destroy()` / re-init (`:299`, `:1870`) agar tidak bocor wrapper (sesuai temuan Fase 0):**<br>- **Opsi A (Wrap `tableNode` + Unwrap pada `destroy.dt` / pra-reinit):** Di `initComplete`, bungkus `const tableNode = this.api().table().node();` dengan `<div class="ui-data-table__scroll ui-table-sticky">` bila `tableNode.parentElement` belum memiliki `.ui-table-sticky` (pasang `role="region"`, `tabindex="0"`, `aria-label` `@js(__('purchasing.copy.price_benchmark_details'))`), **dan** wajib melakukan *unwrap* wrapper `.ui-data-table__scroll.ui-table-sticky` di sekitar `#vsBestTable` pada listener `destroy.dt` serta sebelum pemanggilan `destroy()` di `:299` dan `:1870` — karena jika dibiarkan, `destroy()` hanya menghapus `.dt-container` sementara `.ui-table-sticky` manual tertinggal membungkus `#vsBestTable`, menyebabkan re-init berikutnya membuat `.dt-container` baru *di dalam* `.ui-table-sticky` (menyeret `pageLength`, `search`, `info`, `paging` ikut scroll horizontal — melanggar PUR-04 — serta menumpuk `.ui-table-sticky` ganda).<br>- **Opsi B (Langsung pada `.dt-layout-table > .dt-layout-full`):** Jika inspeksi Fase 0 membuktikan `.dt-layout-table > .dt-layout-full` (termasuk kelas `d-md-flex` BS5 jika ada) tidak mengganggu scrolling `<table>` maupun `position: sticky` header/kolom (`app.css:6370` membutuhkan `> table.dataTable > thead > tr > th`), pasang `.ui-data-table__scroll.ui-table-sticky` + atribut `role="region"`, `tabindex="0"`, `aria-label` langsung pada `.dt-layout-table > .dt-layout-full` yang otomatis dibersihkan saat `destroy()`, sembari memastikan `pageLength`/`search`/`info`/`paging` tetap di luar area scroll horizontal (PUR-04). |

### 6.3 Checklist Parity Seluruh 19 Halaman `@section('uses-datatables', true)` + 3 Partial

Setiap halaman di bawah wajib diperiksa pasca-Fase 3 untuk: (a) 1 search box saja (`x-ui.filter-bar` pada halaman yang memakainya), (b) sorting & ikon panah tanpa overlap teks header, (c) pagination single-page menyembunyikan tombol page tetapi tetap menampilkan `info`, (d) token density (`compact`/`comfortable`/`dense`) & hybrid striping (termasuk baris kosong `.dt-empty`), dan (e) loading bar `.ui-dt-loading-bar` saat AJAX berjalan:

1. `resources/views/admin/auth-audit-logs/index.blade.php` (`#authAuditTable`)
2. `resources/views/admin/material-hs-code/index.blade.php` + partial `_script.blade.php` (`#materialsTable`, `#rulesTable`)
3. `resources/views/admin/users/index.blade.php` (`#usersTable`)
4. `resources/views/finance/local-procurement/show.blade.php` + partial `_gr_table.blade.php` (`#poGoodsReceiptsTable` via `local-po-gr-table.js`, melayani Finance & Purchasing)
5. `resources/views/local-supplier/purchase-orders/show.blade.php` + partial `_gr_table.blade.php` (`#poGoodsReceiptsTable`, mode `readOnly`)
6. `resources/views/purchasing/claims/index.blade.php` (`#actionTable`, `#historyTable` lazy on `shown.bs.tab`)
7. `resources/views/purchasing/comparison/historical.blade.php` + partial `_scripts.blade.php` (`uses-datatables` untuk transisi `AdasiServerTabs` ke `vs-best`)
8. `resources/views/purchasing/comparison/inter-supplier.blade.php` + partial `_scripts.blade.php` (`uses-datatables` untuk transisi `AdasiServerTabs` ke `vs-best`)
9. `resources/views/purchasing/comparison/vs-best.blade.php` + `_vs_best_content.blade.php` + `_scripts.blade.php` (`#vsBestTable`, sticky header & kolom Material)
10. `resources/views/purchasing/periods/index.blade.php` (`#periodsTable`)
11. `resources/views/purchasing/po/index.blade.php` (`#poTable`, plus cek `draw.dt` clear seleksi MMT di `:135-137`)
12. `resources/views/purchasing/pr/index.blade.php` (`#prTable`)
13. `resources/views/purchasing/shipments/index.blade.php` (`#shipmentTable`, SSR tbody awal + serverSide AJAX)
14. `resources/views/qc/inspections/index.blade.php` (`#waitingTable`, `#historyTable`)
15. `resources/views/supplier/claims/index.blade.php` (`#claimTable`)
16. `resources/views/supplier/po/index.blade.php` (`#poTable`)
17. `resources/views/supplier/price-history/index.blade.php` (`#overviewTable`)
18. `resources/views/supplier/quotations/period.blade.php` (`#prTable`)
19. `resources/views/supplier/shipments/index.blade.php` (`#supplierShipmentTable`, SSR tbody awal + serverSide AJAX)

*(Catatan: `purchasing/conversations/index.blade.php:17` dan `supplier/conversations/index.blade.php:25` hanya memiliki class CSS statis `.datatable` tanpa `@section('uses-datatables', true)` maupun inisialisasi `.DataTable()` — biarkan utuh.)*

---

## 7. Fase 4 — Pembaruan Test Suite & 7+ Skrip CDP Browser Check

### 7.1 Pembaruan PHPUnit Tests

| File Test (`file:baris` `[Verified]`) | Assertion Saat Ini | Pembaruan Eksak Fase 4 |
|---|---|---|
| `tests/Feature/FrontendAssetLoadingTest.php:165-166,171-173` | `assertDontSee('cdn.datatables.net/1.13.6')`, `assertSee('code.jquery.com/jquery-3.7.1.min.js')`, `1.13.6/css/dataTables.bootstrap5.min.css`, `1.13.6/js/jquery.dataTables.min.js`, `1.13.6/js/dataTables.bootstrap5.min.js` | Perbarui ke `cdn.datatables.net/3.1.5`, `code.jquery.com/jquery-4.1.0.min.js`, `3.1.5/css/dataTables.bootstrap5.min.css`, `3.1.5/js/dataTables.min.js`, `3.1.5/js/dataTables.bootstrap5.min.js`. **Pertahankan** kontrak ketat di `:144` (semver), `:145` (`sha384-[A-Za-z0-9+/]{64}`), `:146` (`crossorigin="anonymous"`), `:150` (`assertCount(11, $assets)`), dan `:208` (`assertCount(15, $initializers)`). |
| `tests/Feature/DataTablesPreferenceTest.php:12` | `strpos($layout, 'jquery.dataTables.min.js')` | Ubah substring ke `'dataTables.min.js'`. |
| `tests/Feature/DataTablesLocalizationTest.php:54` | `node.querySelectorAll('.dataTables_filter')` | Ubah menjadi `"node.querySelectorAll('.dt-search')"`. |
| `tests/Feature/PaginationViewTest.php:149-150` | `div.dataTables_wrapper div.dataTables_paginate ul.pagination` dan `.dataTables_wrapper.ui-dt-single-page .dataTables_paginate` | Ubah agar cocok persis dengan selector DT 3.1.5 di `app.css`: `div.dt-container div.dt-paging ul.pagination` dan `.dt-container.ui-dt-single-page .dt-paging`. |
| `tests/Feature/CalendarComponentTest.php:167` | `$this->assertStringContainsString('.sorting_asc_disabled', $styles);` | Ubah ke selector DT 3.1.5 di `app.css` (mis. `.dt-orderable-none` pada aturan `table.dataTable thead > tr > :is(th, td)`). |
| `tests/Feature/Architecture/DataTablesSingleSearchContractTest.php:65-73,97,181,239,294` | Regex menuntut `\bdom\s*:` tanpa `'f'`; `:97` menuntut `.ui-data-table .dataTables_wrapper table.dataTable`; `:181,239,294` menuntut `"dom: 'rtip'"` pada SSR HTML | 1. Pada `:65-73`, ubah validasi blok `.DataTable({...})` untuk memverifikasi `layout:` eksplisit yang menyetel `topStart: null` dan `topEnd: null` (dan menolak `'search'` pada seluruh slot `layout`).<br>2. Pada `:97`, ubah regex CSS menjadi `/\.ui-data-table\s+\.dt-container\s+table\.dataTable[^{]*\{[^}]*margin-top:\s*0\s*!important;[^}]*margin-bottom:\s*0\s*!important;/s`.<br>3. Pada `:181,239,294`, ganti literal `"dom: 'rtip'"` dengan `$this->assertMatchesRegularExpression('/layout:\s*\{[^}]*topStart:\s*null,\s*topEnd:\s*null/s', $ssrHtml)` (atau penyeragaman formatting `layout:` satu baris di 3 view terkait agar tidak rapuh terhadap perubahan spasi/newline). |
| `tests/Feature/Architecture/InforTableNameStripingContractTest.php:26-27` | `:not(:has(> td.dataTables_empty))` pada rule striping & `.ui-col-data-name` | Ubah ke `:not(:has(> td.dt-empty))`. |

*(Suite penjaga tanpa perubahan assertion yang wajib tetap hijau: `TestingEnvironmentDatabaseSafetyTest.php`, `tests/Unit/TranslationParityTest.php`, `tests/Unit/UserFacingCopyInventoryTest.php`, `RegionalDataTablesContractTest.php`, `RegionalMaterialClaimDataTablesContractTest.php`, `Auth/SecurityHeadersTest.php`, `WorkspaceShellSecurityTest.php`, `WorkspaceShellRouteClassificationTest.php`, `CustomAdasiToastTest.php`, `LocalizationPerformanceTest.php`, `SharedLocalizationRenderingTest.php`, `ClaimAndInspectionListLayoutTest.php`, `tests/Feature/Purchasing/MmtWorkspaceTest.php`, `tests/Feature/Purchasing/RecordWindowNavigationTest.php`.)*

### 7.2 Pembaruan Node JS Tests (`tests/js/`)

| File Test (`file:baris` `[Verified]`) | Kondisi Saat Ini | Pembaruan Eksak Fase 4 |
|---|---|---|
| `tests/js/locale-switcher.test.mjs:421-461` | `:422` mem-mock `liveTh` dengan node teks polos `makeTextNode('PO Number')`; `:433` memakai `classes: ['dataTables_wrapper']` | Ubah mock `liveTh` (`:422`) ke struktur DT 3.1.5 yang memiliki `<span class="dt-column-title">PO Number</span>` + `<span class="dt-column-order"></span>` (beserta `classes: ['dt-container']` di `:433` dan cakupan `.dt-scroll-headInner`), lalu verifikasi `.dt-column-title` terupdate ke `'Nomor PO'` tanpa menimpa `.dt-column-order` — menjaga kontrak `tests/Support/workspace-shell-browser-check.mjs:1043`. |
| `tests/js/text-contrast.test.mjs:191-192` | Regex `td.dataTables_empty` pada `resources/css/app.css` | Ubah ke `td.dt-empty`. |
| `tests/js/session-sync.test.mjs:410-448,510-538` | `jqueryStub()` hanya menguji `ajaxComplete`, `$.ajaxPrefilter`, dan `ext.errMode({ jqXHR })` | Pertahankan seluruh skenario jQuery yang ada, **dan tambahkan** test case untuk jalur DataTables 3.1.5 (`xhr.dt` 401/419 & <400, `error.dt`, anti-duplikasi jika `ajaxComplete` + `xhr.dt` sama-sama terpicu, sinkronisasi token CSRF di `DataTable.ajax.defaults.headers` / `preXhr.dt`, serta `ext.errMode` dengan objek XHR DT 3.1.5). |
| `tests/js/workspace-shell.test.mjs` (`embed-readiness.js`), `local-po-gr-recovery.test.mjs:49-54`, `adasi-loader.test.mjs` | `workspace-shell.test.mjs` saat ini menguji `createEmbedReadinessController`, namun belum mencakup `scanActiveTables()` dengan bentuk `Api` DT 3.1.5 | Tambahkan/perbarui unit test `scanActiveTables` pada `tests/js/workspace-shell.test.mjs` (mem-mock `DataTable.tables({ api: true })` yang mengembalikan node `HTMLTableElement` dengan `.settings()` menghasilkan array `settings`, `api.table().container()`, dan status pending tanpa `jqXHR`), serta sesuaikan mock di `adasi-loader.test.mjs` / `local-po-gr-recovery.test.mjs` bila signature adapter Fase 2 berubah. |

### 7.3 Pembaruan Skrip CDP Browser Check (`tests/Support/`) & Regenerasi Fixture

Lima skrip CDP membaca file HTML statis prarendering di `storage/framework/testing/{cancelled-recovery-ui,import-ui,po-pr-ui,manual-pr-ui}/` yang berisi tag CDN hasil render Blade lama. Oleh karena itu, **regenerasi 4 fixture** wajib dilakukan sebelum menjalankan CDP.

1. **Siapkan cache lokal DT 3.1.5:** Pastikan `storage/framework/testing/dataTables-3.1.5.min.js` berisi byte resmi DT `3.1.5` (dari Fase 0) dengan hash `sha384-` baru, dan hapus ketergantungan ke `jquery.dataTables-1.13.6.min.js`.
2. **Pembaruan 5 skrip CDP dengan cache DT hardcoded:**
   - `tests/Support/cancelled-recovery-browser-check.mjs:52-54`: ganti path `jquery.dataTables-1.13.6.min.js` → `dataTables-3.1.5.min.js`, hash `sha384-k5vb...` → `<HASH_DT_CORE_JS>`, dan URL mapping `https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js` → `https://cdn.datatables.net/3.1.5/js/dataTables.min.js`. (Juga pastikan mapping `dataTables.bootstrap5.min.js` & `.css` ter-cache otomatis dari HTML `en-po.html` di loop `:76-90`.)
   - `tests/Support/local-import-browser-check.mjs:86-93,140,220`:
     - `:86-93,140`: perbarui path cache, hash `sha384-`, dan pola `Fetch.enable` ke `dataTables-3.1.5.min.js` / `https://cdn.datatables.net/3.1.5/js/dataTables.min.js`.
     - **`:220` (Fix Properti Internal DT 1.x):** Ganti `window.jQuery('#poGoodsReceiptsTable').DataTable().settings()[0]._bInitComplete` dengan pengecekan publik DT 3.1.5 yang aman, yaitu `(() => { const dt = window.jQuery('#poGoodsReceiptsTable').DataTable(); return Boolean(dt.ready?.() ?? dt.page.info().recordsTotal >= 0); })()` atau pengecekan `dt.table().node().dataset.initialized === 'true' && dt.rows().count() > 0 && !document.querySelector('#poGoodsReceiptsTable')?.closest('.dt-container')?.classList.contains('is-loading')` (verifikasi `dt.ready()` di DT 3.1.5 pada Fase 0).
   - `tests/Support/local-import-ux-browser-check.mjs:62-64`: perbarui path cache, `sha384-`, dan URL `3.1.5/js/dataTables.min.js`.
   - `tests/Support/local-po-pr-browser-check.mjs:75-77`: perbarui path cache, `sha384-`, dan URL `3.1.5/js/dataTables.min.js`.
   - `tests/Support/manual-po-pr-browser-check.mjs:76-78`: perbarui path cache, `sha384-`, dan URL `3.1.5/js/dataTables.min.js`.
3. **Pembaruan 2 skrip CDP Dynamic-SRI (Selector DOM DT) + Helper Density:**
   - `tests/Support/modern-web-guidance-browser-check.mjs:268,818,829`: ganti `.dataTables_wrapper` → **`.dt-container`**.
   - `tests/Support/workspace-shell-browser-check.mjs:374,494,982,987,1018,1371`: ganti seluruh `.dataTables_wrapper` → **`.dt-container`** dan `.dataTables_filter` → **`.dt-search`**.
   - `tests/Support/auth-splash-browser-check.mjs`: sudah dynamic-SRI tanpa `.dataTables_*` (tetap dijalankan di Fase 5).
   - `tests/Support/density-operational-measure.js:5,7,14` & `tests/Support/density-browser-measure.js:31,55`: verifikasi kompatibilitas `window.jQuery.fn.dataTable.tables({ api: true }).page.len()` (atau fallback `window.DataTable.tables({ api: true })`).
4. **Perintah Regenerasi 4 Fixture HTML (dijalankan serial di Fase 4/5):**
   ```powershell
   $env:RECOVERY_UI_FIXTURES="1"; php artisan test tests/Feature/LocalInvoice/CancelledInvoiceRecoveryTest.php; Remove-Item Env:RECOVERY_UI_FIXTURES
   $env:LOCAL_IMPORT_UI_FIXTURES="1"; php artisan test tests/Feature/LocalInvoice/LocalImportUiFixtureTest.php; Remove-Item Env:LOCAL_IMPORT_UI_FIXTURES
   $env:PO_PR_UI_FIXTURES="1"; php artisan test tests/Feature/LocalInvoice/LocalPoPrReferenceTest.php; Remove-Item Env:PO_PR_UI_FIXTURES
   $env:MANUAL_PR_UI_FIXTURES="1"; php artisan test tests/Feature/LocalInvoice/ManualPoPrTest.php; Remove-Item Env:MANUAL_PR_UI_FIXTURES
   ```

---

## 8. Fase 5 — Gerbang Verifikasi Bertingkat (`verification-loop`) & Rollback Plan

> **Aturan Keras Eksekusi Test:** PHPUnit dan beberapa skrip CDP memakai database MySQL `adasi_portal_test`. **DILARANG** menjalankan dua suite PHPUnit secara bersamaan atau menjalankan skrip CDP berbarengan dengan PHPUnit. Seluruh langkah di bawah dijalankan **berurutan (serial)**.

### 8.1 Urutan Gerbang Verifikasi (`PASS/FAIL` Gate)

1. **Gerbang 1 — Keamanan DB Testing & Kebersihan Pre-Commit:**
   ```powershell
   php artisan test --filter=TestingEnvironmentDatabaseSafetyTest
   ```
   Pastikan `layouts/app.blade.php` **tidak** memuat `jquery-migrate-4.0.2.js` lagi.

2. **Gerbang 2 — Build Asset, View Cache, Lint & Diff Check:**
   ```powershell
   npm.cmd run build
   php artisan view:cache
   git diff --check
   ```
   *(Jika ada file `.php` test/app yang berubah, jalankan juga `php vendor/bin/pint --test <changed-php-files>`.)*

3. **Gerbang 3 — Targeted PHPUnit Filter (Kontrak DT, Aset, Lokalisasi, Security, & Shell):**
   ```powershell
   php artisan test --filter="FrontendAssetLoadingTest|DataTablesPreferenceTest|DataTablesLocalizationTest|PaginationViewTest|CalendarComponentTest|DataTablesSingleSearchContractTest|InforTableNameStripingContractTest|RegionalDataTablesContractTest|RegionalMaterialClaimDataTablesContractTest|SecurityHeadersTest|TranslationParityTest|CustomAdasiToastTest|ClaimAndInspectionListLayoutTest|LocalizationPerformanceTest|SharedLocalizationRenderingTest|MmtWorkspaceTest|RecordWindowNavigationTest|WorkspaceShellSecurityTest|WorkspaceShellRouteClassificationTest"
   ```
   *(Menyertakan `TranslationParityTest` di `tests/Unit/`.)*

4. **Gerbang 4 — Node JS Tests (`tests/js/`):**
   Di Windows PowerShell, `node --test tests/js/` gagal pada direktori — panggil `npm.cmd run test:js` atau berikan nama file eksplisit:
   ```powershell
   node --test tests/js/locale-switcher.test.mjs tests/js/text-contrast.test.mjs tests/js/session-sync.test.mjs tests/js/local-po-gr-recovery.test.mjs tests/js/adasi-loader.test.mjs tests/js/workspace-shell.test.mjs tests/js/modern-web-guidance-guardrails.test.mjs tests/js/preferences.test.mjs tests/js/density-contract.test.mjs tests/js/tailwind-build.test.mjs tests/js/app-lazy-modules.test.mjs
   ```

5. **Gerbang 5 — Full PHPUnit Suite (Serial atau `Parallel` lalu `Serial`):**
   ```powershell
   composer test:parallel
   # (atau `composer test` untuk full serial Unit+Feature)
   ```

6. **Gerbang 6 — Regenerasi Fixture & Eksekusi Serial Seluruh 8 Skrip CDP Browser Check:**
   Jalankan 4 perintah regenerasi fixture (§7.3 #4), lalu jalankan satu per satu:
   ```powershell
   node tests/Support/auth-splash-browser-check.mjs
   node tests/Support/modern-web-guidance-browser-check.mjs
   node tests/Support/workspace-shell-browser-check.mjs
   node tests/Support/cancelled-recovery-browser-check.mjs
   node tests/Support/local-import-browser-check.mjs
   node tests/Support/local-import-ux-browser-check.mjs
   node tests/Support/local-po-pr-browser-check.mjs
   node tests/Support/manual-po-pr-browser-check.mjs
   ```
   **Syarat Lulus CDP:** `0` `Runtime.exceptionThrown`, `0` CSP error, `0` failed network/SRI mismatch, `0` axe-core critical/serious violation, serta seluruh tabel DataTables merender baris, paginasi, dan pencarian tanpa error konsol.

7. **Gerbang 7 — Checklist Smoke Test Manual Lintas 6 Role di dalam `WorkspaceShell` (`WorkspaceShell::menuRouteLabelMap()`):**
   Uji pada mode Light & Dark, minimal 2 tier density (`comfortable` & `compact`), dan pastikan **0** error JS di DevTools Console maupun Network `4xx/5xx` tak terduga:

   | Role | Route Uji (`WorkspaceShell`) | Skenario Verifikasi Wajib |
   |---|---|---|
   | **Purchasing** | `purchasing.requisitions.index`, `purchasing.purchase-orders.index`, `purchasing.shipments.index`, `purchasing.comparison.vs-best`, `purchasing.claims.index`, `purchasing.periods.index`, `purchasing.local-procurement.show`, `purchasing.requisitions.create` *(catatan: `purchasing.requisitions.index` adalah route menu `WorkspaceShell`, sedangkan `purchasing.requisitions.create` adalah halaman pembuatan PR yang memuat `_material_shape_script.blade.php`)* | Render DT server-side, search tunggal (`x-ui.filter-bar`), sorting kolom + panah `.dt-column-order`, hiding pager saat ≤1 halaman (`.ui-dt-single-page`), `#vsBestTable` sticky header/kolom saat switch tab `AdasiServerTabs`, lazy tab `#historyTable` di Claims, bar loading `.ui-dt-loading-bar`, tombol Add Row & material shape AJAX di `purchasing.requisitions.create`. |
   | **Supplier (Import)** | `supplier.quotations.period`, `supplier.quotations.create`, `supplier.purchase-orders.index`, `supplier.shipments.index`, `supplier.claims.index`, `supplier.price-history.index` | Render `#prTable`/`#poTable`/`#supplierShipmentTable`/`#claimTable`/`#overviewTable`, form `quotations.create` (`.serializeArray()`, toggle `required`, `blur`/`focus`, `$.ajax`), switcher locale EN⇄ID live (`locale-switcher.js`) tanpa merusak header/placeholder DT. |
   | **QC** | `qc.inspections.index`, `qc.inspections.create` | Tab `#waitingTable` & `#historyTable`, filter status history, update tombol export saat pindah tab, `.dt-paging` & `.ui-dt-single-page`. |
   | **Admin** | `admin.users.index`, `admin.auth-audit-logs.index`, `admin.material-hs-code.index` | Filter multi-kolom `#usersTable` (`table.search(...).column(3).search(...)`), filter tanggal/event `#authAuditTable`, dua tabel `#materialsTable` + `#rulesTable` serta form AJAX modal Material/HS Code. |
   | **Finance** | `finance.local-procurement.index` → `show`, `finance.invoices.index`, `finance.drp.supplier` | `#poGoodsReceiptsTable` (`local-po-gr-table.js`), filter status GR, paginasi & modal edit/cancel GR, silent tab refresh di `WorkspaceShell`, dialog Reconnect 401/419 (`session-sync.js`: pastikan tidak muncul `alert('Ajax error')` ganda). |
   | **Local Supplier** | `local-supplier.purchase-orders.index` → `show`, `local-supplier.invoices.create`, `local-supplier.invoices.index` | `#poGoodsReceiptsTable` mode `readOnly`, upload/import modal & form non-DT tetap bebas regresi jQuery 4.1.0, switch tema & density lintas iframe `WorkspaceShell`. |

### 8.2 Rollback Plan

- **Rollback Parsial Fase 1 (Jika jQuery `4.1.0` bermasalah sebelum Fase 2):**
  Revert `resources/views/layouts/app.blade.php:492` ke `jquery-3.7.1.min.js` + SRI lama (`sha384-1H217gwSVyLSIfaLxHbE7dRb3v4mYCKbpQvzx0cegeju1MVsGrX5xXxAvs/HgeFs`), kembalikan `.on('click', addRow)` jika perlu, lalu jalankan `php artisan view:cache`.
- **Rollback Parsial Fase 2/3 (Jika DT `3.1.5` bermasalah saat jQuery `4.1.0` aman):**
  Revert file fase DT (`layouts/app.blade.php` baris `:359,:496-497,:500-553`, `resources/js/*`, `resources/css/app.css`, dan 8 file Blade `layout:` → `dom:`), sementara `jquery-4.1.0.min.js` dipertahankan bila lolos Fase 1. Wajib jalankan `npm.cmd run build` dan `php artisan view:cache` (`DESIGN.md:326`).
- **Full Rollback (Mengembalikan kondisi `3.7.1` + `1.13.6` secara menyeluruh):**
  ```powershell
  git revert <commit-range>   # atau git checkout <base-branch> -- resources/ lang/ public/assets/js/ tests/
  npm.cmd run build
  php artisan view:cache
  php artisan test --filter="FrontendAssetLoadingTest|DataTablesSingleSearchContractTest|PaginationViewTest"
  ```
  Jangan pernah menjalankan `migrate:rollback` atau mutasi DB saat rollback karena upgrade ini **tidak** menyentuh skema database maupun Composer lockfile.

---

## 9. Catatan Kesiapan Tahap Berikutnya (Post-Upgrade Follow-Up — Informasional Saja)

> **Status Bagian Ini:** **INFORMASIONAL SAJA.** Seluruh item di bawah **TIDAK** termasuk dalam scope upgrade jQuery `4.1.0` + DataTables `3.1.5` ini. Bagian ini merangkum hasil audit kesiapan codebase apabila tim di tahap berikutnya ingin menghapus dependensi jQuery secara bertahap dan mengubah pemuatan jQuery menjadi kondisional (`@hasSection('uses-jquery')`).

### 9.1 Klasifikasi Seluruh 19 Halaman `@section('uses-datatables', true)`

| Kategori | Jumlah | Daftar Halaman Blade (`[Verified]`) | Kesiapan Tahap Berikutnya |
|---|---|---|---|
| **Kandidat Cepat (`new DataTable(...)`, Tanpa Skrip jQuery Custom di Luar Inisialisasi DT)** | **10 halaman** | 1. `admin/auth-audit-logs/index.blade.php`<br>2. `purchasing/po/index.blade.php` *(hanya `$('#filter_*')` di `ajax.data` & `AdasiFilterBar`)*<br>3. `purchasing/shipments/index.blade.php` *(sama)*<br>4. `supplier/quotations/period.blade.php` *(sama)*<br>5. `purchasing/claims/index.blade.php` *(hanya lazy-init `#historyTable` via `shown.bs.tab`)*<br>6. `supplier/claims/index.blade.php`<br>7. `supplier/po/index.blade.php`<br>8. `supplier/price-history/index.blade.php`<br>9. `finance/local-procurement/show.blade.php` *(sudah didelegasikan ke `local-po-gr-table.js`)*<br>10. `local-supplier/purchase-orders/show.blade.php` *(sudah didelegasikan ke `local-po-gr-table.js`)* | Dapat langsung dimigrasi ke `new DataTable('#id', ...)` + `document.getElementById(...)` + event native DOM, sehingga tidak lagi membutuhkan jQuery di halaman terkait. |
| **Halaman dengan Skrip jQuery Custom di Luar Inisialisasi DT** | **9 halaman** | 1. `admin/material-hs-code/index.blade.php` (`_script.blade.php` — `$.ajax`, modal CRUD)<br>2. `admin/users/index.blade.php`<br>3. `purchasing/periods/index.blade.php` (`$(document).on('click', '.btn-edit', ...)` modal)<br>4. `purchasing/pr/index.blade.php` (`$(document).on('click', '.btn-delete' / '.btn-submit-draft')`)<br>5. `supplier/shipments/index.blade.php`<br>6. `qc/inspections/index.blade.php` (`shown.bs.tab` & filter DOM via `$`)<br>7. `purchasing/comparison/historical.blade.php` (`_scripts.blade.php`)<br>8. `purchasing/comparison/inter-supplier.blade.php` (`_scripts.blade.php`)<br>9. `purchasing/comparison/vs-best.blade.php` (`_scripts.blade.php`) | Memerlukan refactor skrip jQuery lokal (`$.ajax` → `fetch`, manipulasi DOM `$()` → native `querySelector`/Alpine) sebelum `@section('uses-jquery')` dapat dilepas. |

*(Selain halaman DataTables di atas, halaman non-DT berat seperti `purchasing/pr/create.blade.php`, `purchasing/pr/edit.blade.php`, `purchasing/pr/_material_shape_script.blade.php`, `purchasing/pr/_import.blade.php`, `purchasing/po/show.blade.php`, dan `supplier/quotations/create.blade.php` juga masih memakai jQuery secara intensif.)*

### 9.2 Modul Global & Lazy JS yang Masih Menahan Pemuatan jQuery Global

Dari total 66 file JS (`resources/js/` + `public/assets/js/`) dan `layouts/app.blade.php`, hanya titik-titik berikut yang menahan pemuatan jQuery secara global (`[Verified]`):

| Lokasi (`file:baris`) | Lingkup | Ketergantungan jQuery Saat Ini | Langkah Lepas jQuery (Tahap Berikutnya) |
|---|---|---|---|
| `resources/views/layouts/app.blade.php:492,503-553,609-615` | Global Layout | Tag `<script>` jQuery global, `jQuery.extend(jQuery.fn.dataTable.defaults, ...)`, `$(document).on('draw.dt order.dt')`, `$.ajaxPrefilter` (ngrok non-prod). | Pindah `DataTable.defaults` & `document.addEventListener('draw.dt', ...)` ke API `window.DataTable`; gate `<script>` jQuery dengan `@hasSection('uses-jquery')`. |
| `resources/js/adasi-loader.js:654-667` | Global JS | `$.ajaxPrefilter` (loading overlay jQuery AJAX), `$(win.document).on('processing.dt')`. | `processing.dt` dapat didengarkan via `win.document.addEventListener('processing.dt', ...)` (DT 3 men-dispatch DOM CustomEvent sekaligus jQuery event jika jQuery ada); `$.ajaxPrefilter` cukup aktif bila `win.jQuery` terdefinisi. |
| `resources/js/embed-readiness.js:283,339-391` | Global Embed JS | `win.jQuery?.fn?.dataTable?.settings`, `$(doc).on('*.dt')`, `$.ajaxPrefilter`. | Gunakan `win.DataTable?.tables?.({ api: true })`, `doc.addEventListener('*.dt')`, dan pasang `$.ajaxPrefilter` hanya jika `win.jQuery` ada. |
| `resources/js/session-sync.js:278-313` | Global JS | `$(doc).on('ajaxComplete')`, `$.ajaxPrefilter`, `jQuery.fn.dataTable.ext`. | Sudah memiliki `observeFetch`; `observeJQuery` sudah aman di-guard `if (typeof win.jQuery !== 'function') return;`; gunakan `win.DataTable?.ext` untuk `guardDataTables`. |
| `resources/js/locale-switcher.js:243-278` | Global JS | `jq.fn.dataTable.tables({ api: true })`, `new jq.fn.dataTable.Api(settings)`. | Ganti ke `windowRef.DataTable?.tables({ api: true })` tanpa `windowRef.jQuery`. |
| `resources/js/workspace-shell.js:3328-3344` | Global Shell JS | `win?.jQuery?.fn?.dataTable` di `refreshFrameSilently()`. | Tambahkan `const dt = win?.DataTable || win?.jQuery?.fn?.dataTable;`. |
| `resources/js/workspace-embed-bridge.js:301-309` | Global Embed JS | `window.jQuery(document).ajaxSuccess(...)`. | Sudah aman di-guard `if (window.jQuery)`. |
| `public/assets/js/async-export.js:161-181` | Shared Asset JS | `window.jQuery.fn.dataTable.isDataTable(table)` & `window.jQuery(table).DataTable()`. | Ganti ke `window.DataTable.isDataTable(table)` & `window.DataTable.Api` / `new DataTable` lookup. |
| `resources/js/local-po-gr-table.js:82-115` *(lazy)* | Lazy Page JS | `$(element).DataTable(...)`, `$(element).on('xhr.dt', ...)`, `$.fn.dataTable.ext`. | Ganti ke `new window.DataTable(element, ...)` & `element.addEventListener('xhr.dt', ...)`. |
| `resources/js/advanced-export.js:232-234` *(lazy)* | Lazy Page JS | `window.jQuery?.fn?.DataTable?.isDataTable(...)`, `.DataTable().search()`. | Ganti ke `window.DataTable?.isDataTable(...)`. |
