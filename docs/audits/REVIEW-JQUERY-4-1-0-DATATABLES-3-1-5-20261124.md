# Adversarial Code Review — Upgrade jQuery `3.7.1` → `4.1.0` & DataTables `1.13.6` → `3.1.5`

- **Repositori:** `poyipoy/supplierportal` (ADASI Supplier Portal)
- **Branch:** `migrate-jquery-DT` (uncommitted working tree terhadap `HEAD` `dd5858c7`)
- **Tanggal Audit:** 2026-11-24
- **Mode Eksekusi:** `STRICT READ-ONLY` (0 file kode produksi, Blade, CSS, JS, konfigurasi, maupun test diubah; 0 migrasi DB dijalankan)
- **Cakupan Diff yang Diaudit:** `34` file termodifikasi (`+712` / `-242` baris), bundle hasil build di `public/build/`, byte biner `dataTables-3.1.5.min.js`, serta 20 halaman/partial pengguna `@section('uses-datatables', true)` dan 18 controller pemakai DataTables / `$request->ajax()`.
- **Catatan Kepatuhan `/orch-review` (Fail-Closed Contract):** Sesuai `.agents/workflows/orch-review.md:104-108`, skrip runtime `workflows/orch-review.workflow.js` tidak tersedia di repositori ini (`Test-Path workflows/orch-review.workflow.js = False`). Audit adversarial ini dijalankan secara langsung berbasis bukti kode sumber, inspeksi biner aset vendor, analisis graf (`graphify-out/graph.json`), dan eksekusi serial kelima gerbang verifikasi tanpa mengklaim orkestrasi `orch-review.workflow.js` yang tidak ada.

---

## 1. Verdict

**`REQUEST CHANGES`**

### Ringkasan Eksekutif Keputusan
Fondasi utama upgrade telah dikerjakan dengan baik: keempat aset CDN di `resources/views/layouts/app.blade.php:359,492,496,497` memakai versi eksak (`jquery-4.1.0.min.js` dan `datatables.net/3.1.5`) dengan hash SRI `sha384-` 64 karakter Base64 yang cocok 100% terhadap byte resmi CDN (`[Verified]`), bebas sisa `jquery-migrate` (`[Verified]`), CSP di `app/Http/Middleware/AddSecurityHeaders.php:67-80` tetap ketat (`[Verified]`), migrasi selector CSS `.dataTables_*` / `.sorting_*` → `.dt-*` bersih di seluruh `resources/`, `public/assets/`, `app/`, dan `tests/` (`[Verified]`), dan audit live Chromium + axe-core WCAG 2.2 AA (`modern-web-guidance-browser-check.mjs`) lulus `7/7` skenario termasuk halaman DataTables 3.1.5 (`[Verified]`).

Namun, inspeksi adversarial pada biner `dataTables-3.1.5.min.js` dan eksekusi gerbang verifikasi mengungkap **2 temuan `HIGH`**, **3 temuan `MEDIUM`**, dan **2 temuan `LOW`** yang wajib diperbaiki sebelum merge:
1. **`[HIGH-01]`** Callback `ajax.error` kustom pada `#poGoodsReceiptsTable` (`resources/js/local-po-gr-table.js:95`) dan `#vsBestTable` (`resources/views/purchasing/comparison/_scripts.blade.php:1903`) menimpa `l.error` internal DataTables `3.1.5` (`F.object.assign(l, i)`). Karena DataTables 3 tidak lagi melewati `jQuery.ajax()` / `ajaxComplete`, kegagalan HTTP `401`/`419`/`500` pada kedua tabel tersebut **tidak memicu `xhr.dt`, `dt-error.dt`, maupun `processing.dt` (`busy=false`)** — memutus deteksi sesi expired di `session-sync.js`, mengunci tabel dalam status `.is-loading` (`pointer-events: none`) selamanya di `adasi-loader.js`, dan menggantung readiness iframe `WorkspaceShell` (`embed-readiness.js`) hingga timeout 60 detik.
2. **`[HIGH-02]`** Skrip CDP `tests/Support/local-import-browser-check.mjs` (yang ikut dimodifikasi pada upgrade ini) gagal deterministik (`CDP_EXIT=1`) karena server HTTP fixture di baris `:67-70` tidak me-rewrite hash nama file Vite `/build/assets/app-*.js` & `.css` dari `public/build/manifest.json` setelah `npm run build`, sehingga skenario browser `#poGoodsReceiptsTable` DataTables `3.1.5` di baris `:217-243` tidak pernah tereksekusi.
3. **`[MEDIUM-01]`** Merge *shallow* `u({}, fe, l)` di `DataTable.ajax` berinteraksi buruk dengan handler ngrok `preXhr.dt` non-produksi (`layouts/app.blade.php:643-646`), menghapus objek `window.DataTable.ajax.defaults.headers` pada setiap request di environment non-produksi.
4. **`[MEDIUM-02]`** Sisa fallback properti Hungarian DataTables 1.x (`nTableWrapper`, `nTable`, `oLanguage`, `bInitialised`, `sAjaxSource`, `oFeatures.bServerSide`) masih tertinggal di 4 file produksi (dan terbundel ke `public/build/assets/app-n_kcwg_3.js`), ditambah file yatim `storage/framework/testing/jquery.dataTables-1.13.6.min.js`.
5. **`[MEDIUM-03]`** Pergantian bahasa live (`locale-switcher.js` + `layouts/app.blade.php:542,561-566`) menimpa kembali `aria-label` `<nav>` paginasi dengan bahasa awal saat `draw.dt` dan belum menyinkronkan ulang `.dt-length label` setelah draw ulang `Ke("pageLength")`.

---

## Matriks Bukti 4 Checklist Audit Kritis

| Area Checklist | Status | Bukti Terverifikasi (`file:baris`) |
|---|---|---|
| **1a. Versi CDN Eksak (`4.1.0` & `3.1.5`)** | **PASS** `[Verified]` | `resources/views/layouts/app.blade.php:359` (`3.1.5/css/dataTables.bootstrap5.min.css`), `:492` (`jquery-4.1.0.min.js`), `:496` (`3.1.5/js/dataTables.min.js`), `:497` (`3.1.5/js/dataTables.bootstrap5.min.js`). |
| **1b. Kontrak SRI `sha384-` (64 char Base64)** | **PASS** `[Verified]` | Seluruh 4 tag memakai `sha384-` (64 karakter Base64, bukan `sha256-`) dan diverifikasi identik terhadap SHA-384 byte mentah CDN resmi:<br>- `jquery-4.1.0.min.js` (`78.965 B`): `sha384-UGSi5euAzE1p4sK41+yKs3vtdPcidmaW6fY2NUITZkjIPyMVUUYB/BV4A5Oa8uu2`<br>- `dataTables.bootstrap5.min.css` (`21.555 B`): `sha384-OZKa6QSlaaq/LGR1sBFkYhC0c/nacIFh1chsblhDUxggC9Zb0XLEEMs95i1Kydnt`<br>- `dataTables.min.js` (`123.627 B`): `sha384-RhzIACKRVYIyijzmm7bA1mSofISYiTQ/KsnClfUSGSHu6ZeRsTQVWL4LqPHTrJcT`<br>- `dataTables.bootstrap5.min.js` (`1.466 B`): `sha384-2JCJ65zPWIsFF9Flk2ZSwukEbtRkZygX6+sTerFFqbL7dwPDVsMf2FR3r3XDAub0`<br>`FrontendAssetLoadingTest` lulus `17/17` test. |
| **1c. Zero `jquery-migrate` di Produksi** | **PASS** `[Verified]` | Pemindaian menyeluruh pada `resources/`, `public/`, `config/`, `app/`, dan `tests/` menghasilkan **0** kemunculan `jquery-migrate`. |
| **1d. Disiplin CSP (`AddSecurityHeaders.php`)** | **PASS** `[Verified]` | `app/Http/Middleware/AddSecurityHeaders.php:67-80` tidak disentuh (`0` diff); hanya mengizinkan `https://code.jquery.com`, `https://cdn.datatables.net`, dan `https://cdn.jsdelivr.net`. `SecurityHeadersTest` lulus `8/8`. |
| **2a. `session-sync.js` (401/419 & CSRF Rotation)** | **FAIL (`HIGH-01`, `MEDIUM-01`)** `[Verified]` | Tabel standar terdeteksi via `xhr.dt` & `dt-error.dt` (`resources/js/session-sync.js:300-327`), dan rotasi CSRF terdaftar via `preXhr.dt` (`:235-256`) + `DataTable.ajax.defaults.headers` (`:755-756, 1028-1029`). **NAMUN** tabel dengan `ajax.error` kustom (`#poGoodsReceiptsTable`, `#vsBestTable`) membungkam `xhr.dt`/`dt-error.dt` pada HTTP `401`/`419` (`[HIGH-01]`), dan handler ngrok non-prod menimpa `defaults.headers` (`[MEDIUM-01]`). |
| **2b. `adasi-loader.js` (`processing.dt` & Non-Blocking)** | **FAIL (`HIGH-01`)** `[Verified]` | Request `DataTable.ajax()` menggunakan `XMLHttpRequest` langsung (tidak memicu `ajaxSend` global sehingga **tidak** memicu full-screen blocking overlay — sesuai kontrak), dan non-bubbling `processing.dt` diikat ke setiap `<table>` via `preInit.dt` (`resources/js/adasi-loader.js:675-691`). **NAMUN** saat `ajax.error` kustom menimpa `l.error` (`[HIGH-01]`), `w(r, !1)` tidak dipanggil sehingga `.dt-container.is-loading` macet permanen. |
| **2c. `embed-readiness.js` (`WorkspaceShell` Readiness)** | **FAIL (`HIGH-01`)** `[Verified]` | `bootEmbedReadiness` (`resources/js/embed-readiness.js:285-415`) sudah memantau `xhr.addEventListener('loadend')`, `api.table().container()`, `dt-error.dt`, dan `processing.dt` langsung pada elemen tabel (`workspace-shell.test.mjs:2585` lulus). **NAMUN** pada tabel dengan `ajax.error` kustom (`[HIGH-01]`), `.is-loading` tidak pernah dibersihkan sehingga pengecekan `:320` gagal menandai `onTableSettled`. |
| **2d. Header Ngrok, `$request->ajax()`, & `ServerTabsResponse::wants()`** | **PARTIAL (`MEDIUM-01`)** `[Verified]` | Di biner `dataTables-3.1.5.min.js` (`pe()`), DataTables menyuntikkan `X-Requested-With: XMLHttpRequest` secara otomatis untuk origin yang sama (`!n.headers["X-Requested-With"] && sameOrigin`), sehingga seluruh 18 controller DataTables/`$request->ajax()` (mis. `UserController.php:30`, `PurchaseOrderController.php:100,346`, `VsBestPriceComparisonController.php:61`, `SupplierRegistrationReviewController.php:38`) dan prioritas header eksplisit `X-Adasi-Server-Tabs: 1` di `app/Support/ServerTabsResponse.php` tetap aman. Hanya merge header ngrok di `layouts/app.blade.php:643-646` yang menimpa `defaults.headers` (`[MEDIUM-01]`). |
| **3a. Zero Leftovers DT 1.x & `.dataTables_*` → `.dt-*`** | **FAIL (`MEDIUM-02`)** `[Verified]` | **0** sisa `jquery.dataTables.min.js`, `1.13.6`, `3.7.1`, maupun kelas `.dataTables_*` / `.sorting_*` di `resources/`, `public/assets/`, `app/`, `tests/`. **NAMUN** masih ada 14 referensi fallback `nTableWrapper` / `nTable` / `oLanguage` / `bInitialised` / `sAjaxSource` / `oFeatures` di 4 file aktif (`layouts/app.blade.php`, `adasi-loader.js`, `embed-readiness.js`, `locale-switcher.js`) serta file yatim `storage/framework/testing/jquery.dataTables-1.13.6.min.js`. |
| **3b. Kepatuhan `resources/css/app.css` & `DESIGN.md`** | **PASS dengan Catatan (`LOW-02`)** `[Verified]` | Seluruh selector di `resources/css/app.css:3635-3915, 5218-5245, 5495, 5718-5905, 6352-6405, 6818` memakai token `--md-*` / `--ui-*`, menjaga `.ui-dt-single-page` (`PaginationViewTest` lulus `14/14`), menjaga row/column striping `:not(:has(> td.dt-empty))`, dan menyembunyikan border-triangle bawaan DT 3 demi ikon SVG mask kustom. Catatan minor perataan `.dt-orderable-none` di `[LOW-02]`. |
| **3c. Audit 20 Halaman `@section('uses-datatables')` & 8 Eks-`dom:`** | **PASS** `[Verified]` | Ke-8 view eks-`dom:` (`admin/auth-audit-logs/index.blade.php:91`, `admin/material-hs-code/_script.blade.php:58,151`, `admin/users/index.blade.php:141`, `purchasing/comparison/_scripts.blade.php:1892`, `purchasing/po/index.blade.php:125`, `purchasing/shipments/index.blade.php:108`, `supplier/quotations/period.blade.php:277`, `supplier/shipments/index.blade.php:123`) dimigrasikan ke `layout: { topStart: ..., topEnd: null, bottomStart: 'info', bottomEnd: 'paging' }` secara akurat (`DataTablesSingleSearchContractTest` lulus `4/4`). Tidak ditemukan selector khusus Sizzle (`:eq()`, `:contains()`, `:visible` di dalam API selector DT) maupun `this.<jqueryMethod>()` pada callback DataTables. |
| **4a. Kompatibilitas jQuery `4.1.0`** | **PASS** `[Verified]` | `purchasing/pr/create.blade.php:606` dan `purchasing/pr/edit.blade.php:519` telah mengganti `$.isArray(...)` → `Array.isArray(...)`. Pemindaian seluruh `resources/` dan `public/assets/` memastikan **0** pemakaian API yang dihapus di jQuery 4.0/4.1 (`$.isArray`, `$.trim`, `$.parseJSON`, `$.isFunction`, `$.isNumeric`, `$.isWindow`, `$.camelCase`, `$.type`, `$.now`, `$.fx.interval`, maupun `jQuery.fn.push/sort/splice`). |
| **4b. Disiplin Scope & Simetri Lokalisasi** | **PASS** `[Verified]` | Tidak ada perubahan di luar scope pada `composer.json`, `package.json`, Bootstrap `5.3.3`, SweetAlert2 `11.7.32`, atau Chart.js. `lang/en/datatables.php` dan `lang/id/datatables.php` simetris penuh (`TranslationParityTest` lulus `3/3`, `DataTablesLocalizationTest` lulus `3/3`). |

---

## 2. Daftar Temuan (Diurutkan Berdasarkan Severity)

### `[HIGH-01]` Custom `ajax.error` pada `#poGoodsReceiptsTable` dan `#vsBestTable` Menimpa Handler Internal DataTables `3.1.5`, Memutus Deteksi Sesi `401`/`419`, Mengunci Loader Tabel Selamanya, dan Menggantung `WorkspaceShell`

- **Severity:** `HIGH`
- **Status Bukti:** `[Verified]` (dibuktikan dari kode sumber + biner `dataTables-3.1.5.min.js`)
- **Lokasi File & Baris:**
  - `resources/js/local-po-gr-table.js:94-95`
  - `resources/views/purchasing/comparison/_scripts.blade.php:1893-1909`
  - `resources/js/session-sync.js:300-327`
  - `resources/js/adasi-loader.js:675-685`
  - `resources/js/embed-readiness.js:316-329, 391-405`
- **Bukti Konkret:**
  1. Pada biner `storage/framework/testing/dataTables-3.1.5.min.js` (fungsi internal `It` pembangun request AJAX):
     ```js
     l = {
       url: ...,
       data: e,
       success: t,
       dataType: "json",
       cache: !1,
       type: r.serverMethod,
       error: function(e, t) {
         -1 === U(r, null, "xhr", [r, null, r.jqXHR], !0).indexOf(!1)
           && (... W(r, 0, "Ajax error", 7)),
         w(r, !1)
       }
     };
     F.is.plainObject(i) && F.object.assign(l, i),
     r.ajaxData = e,
     U(r, null, "preXhr", [r, e, l], !0),
     ...
     r.jqXHR = F.ajax(l)
     ```
  2. Di `resources/js/local-po-gr-table.js:94-95` (dipakai pada `finance/local-procurement/show`, `purchasing/local-procurement/show`, dan `local-supplier/purchase-orders/show`):
     ```js
     ajax: { url: root.dataset.ajaxUrl, data: d => { d.status = filter ? filter.value : ''; },
         error: () => window.AdasiToast?.error(t('js.local_import.gr_table_failed', 'Failed to load Goods Receipts.')) },
     ```
  3. Di `resources/views/purchasing/comparison/_scripts.blade.php:1903-1909` (`#vsBestTable`):
     ```js
     error: function(xhr, status, err) {
         window.AdasiDebug?.error('Vs Best DataTable Error:', status, err);
         if (window.AdasiToast) {
             window.AdasiToast.error(vsBestText.failedToLoadDataPleaseRefresh, vsBestText.loadErrorTitle);
         }
     }
     ```
- **Root Cause:**
  Pada DataTables `1.13.6`, seluruh transport melewati `jQuery.ajax(l)`, sehingga `$(document).on('ajaxComplete')` di `session-sync.js:296` selalu terpanggil walaupun `l.error` ditimpa oleh opsi `ajax.error` tabel. Pada DataTables `3.1.5`, `F.ajax(l)` membuat `new XMLHttpRequest()` secara mandiri tanpa jQuery. Saat konfigurasi tabel menyediakan properti `ajax.error`, `F.object.assign(l, i)` **menimpa `l.error` bawaan DataTables**. Akibatnya, ketika server mengembalikan status HTTP `>= 400` (`401`, `419`, `500`) atau koneksi terputus, `F.ajax` hanya memanggil fungsi `ajax.error` kustom tersebut tanpa pernah menjalankan `U(r, null, "xhr", ...)` (`xhr.dt`), `W(...)` (`dt-error.dt`), maupun `w(r, !1)` (`processing.dt` dengan `busy = false`).
- **Dampak Runtime:**
  1. **Bypass Keamanan UX Sesi (`session-sync.js`):** Ketika sesi berakhir (`401` / `419`) saat pengguna memfilter, berpindah halaman, atau me-reload `#poGoodsReceiptsTable` maupun `#vsBestTable`, modal re-autentikasi `<x-ui.session-expired-modal>` tidak muncul; pengguna justru menerima toast error generik sementara halaman tetap tampak aktif padahal sesi sudah mati.
  2. **UI Deadlock pada Tabel (`adasi-loader.js`):** Karena `processing.dt` (`busy = false`) tidak pernah dipancarkan, `.dt-container` tertahan permanen pada kelas `.is-loading.ui-data-loading-host` (`aria-busy="true"`), membuat `tbody` terkunci `pointer-events: none; opacity: 0.55` (`resources/css/app.css:5837-5841`) dan animasi `.ui-dt-loading-bar` + `.ui-dt-loading-pill` berputar selamanya.
  3. **Hang Readiness Iframe (`embed-readiness.js`):** Jika error terjadi saat pembukaan awal tab di dalam `WorkspaceShell`, listener `xhr.addEventListener('loadend', ...)` di `embed-readiness.js:318-321` memeriksa `!latestWrapper?.classList?.contains?.('is-loading')` yang bernilai `false` selamanya (dan `dt-error.dt` di `:392` tidak terpanggil), menggantung transisi readiness iframe sampai batas *fail-open* 60 detik.

---

### `[HIGH-02]` Regresi Gerbang Verifikasi CDP `tests/Support/local-import-browser-check.mjs` Akibat Path Bundle Vite Tidak Di-resolve dari `public/build/manifest.json`

- **Severity:** `HIGH`
- **Status Bukti:** `[Verified]` (gagal saat dieksekusi langsung pada Gerbang 5)
- **Lokasi File & Baris:**
  - `tests/Support/local-import-browser-check.mjs:67-70, 131-140`
- **Bukti Konkret (Output Eksekusi Aktual):**
  ```text
  {"ready":"complete","jq":"function","dt":"undefined",...}
  Error: Browser condition timed out: document.querySelector('[data-local-import=GR]')?.dataset.initialized === 'true' && Boolean(window.bootstrap?.Modal); errors: []
      at until (file:///C:/laragon/www/adasi_portal_supplier/tests/Support/local-import-browser-check.mjs:114:11)
      at async file:///C:/laragon/www/adasi_portal_supplier/tests/Support/local-import-browser-check.mjs:149:17
  CDP_EXIT[tests/Support/local-import-browser-check.mjs]=1
  ```
  Bandingkan baris `:67-70` di `tests/Support/local-import-browser-check.mjs`:
  ```js
  if (url.pathname.startsWith('/fixtures/')) {
      response.setHeader('Content-Type', 'text/html; charset=utf-8');
      response.end(await readFile(path.join(fixtures, path.basename(url.pathname)))); return;
  }
  ```
  dengan `tests/Support/local-import-ux-browser-check.mjs:37-40` (yang lulus `16/16`):
  ```js
  const manifest = JSON.parse(await readFile(path.join(project, 'public/build/manifest.json'), 'utf8'));
  const html = await readFile(path.join(fixtures, path.basename(url.pathname)), 'utf8');
  response.end(html.replace(/\/build\/assets\/app-[\w-]+\.js/g, '/build/'+manifest['resources/js/app.js'].file)
      .replace(/\/build\/assets\/app-[\w-]+\.css/g,'/build/'+manifest['resources/css/app.css'].file));
  ```
- **Root Cause:**
  Upgrade ini memodifikasi `resources/js/*` dan `resources/css/app.css` lalu menjalankan Vite build baru yang mengubah hash bundle dari `app--0bodG4Z.js` menjadi `app-n_kcwg_3.js` (`public/build/manifest.json:288`). Walaupun `tests/Support/local-import-browser-check.mjs` diedit pada diff ini (`:86-93, :140, :220-221`) untuk DataTables `3.1.5`, server HTTP internalnya di `:67-70` tidak ikut me-rewrite referensi `/build/assets/app-*.js` dan `.css` (serta `<link rel="modulepreload">` lama) di HTML fixture `storage/framework/testing/import-ui/*.html`, sehingga browser mendapatkan HTTP `404` saat memuat `app.js` dan modul `local-procurement-import.js` maupun `local-po-gr-table.js` gagal melakukan bootstrap. Selain itu, baris `:131-140` hanya mengintersep `dataTables.min.js` tanpa meng-cache aset CDN SRI lain seperti yang dilakukan `local-import-ux-browser-check.mjs:91-103`.
- **Dampak Runtime / Verifikasi:**
  Gerbang 5 gagal (`CDP_EXIT=1`) sebelum sempat memverifikasi skenario paginasi dan aksi modal `#poGoodsReceiptsTable` DataTables `3.1.5` di baris `:217-243`.

---

### `[MEDIUM-01]` Shallow Merge `u({}, fe, e)` di `DataTable.ajax` + Handler Ngrok `preXhr.dt` Menghapus `window.DataTable.ajax.defaults.headers` di Non-Produksi

- **Severity:** `MEDIUM`
- **Status Bukti:** `[Verified]` (dibuktikan dari kode sumber + biner `dataTables-3.1.5.min.js`)
- **Lokasi File & Baris:**
  - `resources/views/layouts/app.blade.php:631-649`
  - `resources/js/session-sync.js:235-256, 755-756, 1028-1029`
- **Bukti Konkret:**
  1. Biner `dataTables-3.1.5.min.js`: `function u(e,...t)` adalah *shallow copy* properti tingkat pertama (`r[a] = i`), bukan `ae` (`assignDeep`). Fungsi transport `pe(e)` memanggil `let n = u({}, fe, e)` dengan `fe = DataTable.ajax.defaults = { cache: !0, contentType: ..., headers: {}, traditional: !1 }`.
  2. Di `resources/views/layouts/app.blade.php:640-648` (aktif ketika `!app()->environment('production')`):
     ```js
     $(document).on('preXhr.dt', function (_event, _settings, _data, ajaxOpts) {
         if (ajaxOpts && typeof ajaxOpts === 'object') {
             ajaxOpts.headers = {
                 ...(ajaxOpts.headers || {}),
                 [headerName]: headerValue,
             };
         }
     });
     ```
- **Root Cause:**
  Saat `preXhr.dt` dipicu oleh DataTables 3, `ajaxOpts` (`l`) belum memiliki properti `headers` (`undefined`). Blok di `layouts/app.blade.php:643-646` menginisialisasi `ajaxOpts.headers = { 'ngrok-skip-browser-warning': 'true' }` tanpa menyertakan `...(window.DataTable?.ajax?.defaults?.headers || {})`. Ketika `pe(l)` menjalankan *shallow merge* `u({}, fe, l)`, `l.headers` menimpa seluruh objek `fe.headers` (`window.DataTable.ajax.defaults.headers`).
- **Dampak Runtime:**
  Di seluruh environment non-produksi (`local`, `staging`, `testing`), setiap request DataTables menghapus header default yang sebelumnya dipasang pada `window.DataTable.ajax.defaults.headers` (termasuk `X-CSRF-TOKEN` hasil rotasi di `session-sync.js:756, 1029` apabila listener `layouts/app.blade.php` berjalan atau jika tabel mengandalkan `defaults.headers` secara langsung).

---

### `[MEDIUM-02]` Sisa Properti Internal Hungarian Notation DataTables 1.x (`nTableWrapper`, `nTable`, `oLanguage`, `bInitialised`, `sAjaxSource`, `oFeatures`) di 4 File Produksi & Artefak Uji Yatim

- **Severity:** `MEDIUM`
- **Status Bukti:** `[Verified]`
- **Lokasi File & Baris:**
  - `resources/views/layouts/app.blade.php:572` (`settings?.nTableWrapper`)
  - `resources/js/adasi-loader.js:673` (`settings?.nTableWrapper`), `:685` (`settings?.nTable`)
  - `resources/js/embed-readiness.js:263` (`settings.nTable`), `:270` (`settings.sAjaxSource`), `:272` (`settings.oFeatures?.bServerSide`), `:296` (`settings?.nTableWrapper`), `:313` (`settings.bInitialised`), `:383` (`settings?.nTable`)
  - `resources/js/locale-switcher.js:354` (`settings.oLanguage`), `:364` (`settings.nTableWrapper`), `:368` (`settings.oLanguage`), `:381` (`settings.nTable`)
  - `storage/framework/testing/jquery.dataTables-1.13.6.min.js` (file cache biner lama masih ada di disk)
- **Bukti Konkret:**
  Inspeksi bundle hasil kompilasi `public/build/assets/app-n_kcwg_3.js` (`126.824` byte) mengonfirmasi `has nTableWrapper = true`.
- **Root Cause:**
  Saat migrasi ke `api.table().container()` dan properti `settings` DataTables 3 (`settings.table`, `settings.language`, `settings.initialised`, `settings.features.serverSide`), cabang fallback lama untuk test stub DT 1.x tetap dipertahankan di kode produksi alih-alih memperbarui stub pada unit test dan membersihkan kode produksi sesuai kontrak Checklist #3 (*"pastikan tidak ada sisa yatim `nTableWrapper`"*).
- **Dampak Runtime / Maintainability:**
  Cabang kode mati (*dead fallback branches*) di runtime DataTables `3.1.5` yang menyamarkan kontrak struktur `settings` DT 3 dan melanggar gerbang audit kebersihan migrasi.

---

### `[MEDIUM-03]` Pergantian Bahasa Live (`locale-switcher.js`) Ditimpa Ulang oleh `paginationLabel` Statis di `layouts/app.blade.php` dan Closure `Ke("pageLength")` Saat `draw.dt`

- **Severity:** `MEDIUM`
- **Status Bukti:** `[Verified]` (dibuktikan dari kode sumber + biner `dataTables-3.1.5.min.js`)
- **Lokasi File & Baris:**
  - `resources/views/layouts/app.blade.php:542, 561-566`
  - `resources/js/locale-switcher.js:319-331, 374-390`
- **Bukti Konkret:**
  1. Di `resources/views/layouts/app.blade.php:542, 561-566`:
     ```js
     const paginationLabel = @js(__('pagination.navigation'));
     ...
     wrapper.querySelectorAll('.dt-paging nav').forEach((nav) => {
         if (paginationLabel) {
             nav.setAttribute('aria-label', paginationLabel);
         }
     });
     ```
     Variabel `paginationLabel` dibekukan saat halaman pertama kali di-render oleh server dan dipasang ulang pada setiap event `draw.dt`.
  2. Di biner `dataTables-3.1.5.min.js` (`Ke("pageLength")`): teks sekitar `_MENU_` disimpan dalam closure `p` (`{ el: textNode, text: e.textContent }`) saat inisialisasi tabel dan ditulis ulang melalui helper `c(t)` (`e.el.textContent = rr(o, e.text, t)`). Di `resources/js/locale-switcher.js:375-390`, `syncDataTableChromeLabels(wrapper, dtLang)` hanya dipanggil satu kali *sebelum* `tableApi.ajax.reload(null, false)` / `tableApi.draw('page')`, sedangkan hanya `syncInfoElement` yang didaftarkan pada `draw.dt.adasiLocaleInfo` (`:383`).
- **Root Cause:**
  Berbeda dari DataTables `1.13.6`, fitur layout `info` dan `pageLength` di DataTables `3.1.5` meng-cache string template bahasa ke dalam closure saat init, sementara `layouts/app.blade.php` juga meng-cache `paginationLabel` di closure `<script>` inline.
- **Dampak Runtime:**
  Setelah pengguna mengganti bahasa `EN ⇄ ID` secara live tanpa reload halaman penuh: (1) `<nav aria-label="...">` pada `.dt-paging` tertimpa kembali menjadi bahasa lama setiap kali tabel melakukan `draw.dt`, dan (2) label `.dt-length` (`Show _MENU_ entries` / `Tampilkan _MENU_ entri`) tidak terlindungi terhadap penulisan ulang pada event `draw.dt` berikutnya.

---

### `[LOW-01]` Konversi Line-Ending `LF` → `CRLF` pada 5 File Blade yang Dimodifikasi

- **Severity:** `LOW`
- **Status Bukti:** `[Verified]`
- **Lokasi File & Baris:**
  - `resources/views/admin/auth-audit-logs/index.blade.php:1-147`
  - `resources/views/admin/material-hs-code/_script.blade.php:1-319`
  - `resources/views/admin/users/index.blade.php:1-191`
  - `resources/views/purchasing/po/index.blade.php:1-225`
  - `resources/views/purchasing/shipments/index.blade.php:1-189`
- **Bukti Konkret:**
  Perbandingan byte `git show HEAD:<file>` (`CRLF=false`) terhadap working tree (`CRLF=true`) dan peringatan Git:
  `warning: in the working copy of '...', CRLF will be replaced by LF the next time Git touches it`.
- **Root Cause & Dampak:**
  Kelima file disimpan dengan `CRLF` saat pengeditan di Windows padahal seluruh file di `HEAD` memakai `LF`, menimbulkan peringatan Git berulang dan risiko noise line-ending lintas perkakas.

---

### `[LOW-02]` Padding Kanan Paksa `2.25rem !important` pada Header `.dt-orderable-none` Menggeser Perataan Kolom Non-Sortable

- **Severity:** `LOW`
- **Status Bukti:** `[Verified]`
- **Lokasi File & Baris:**
  - `resources/css/app.css:5227-5232`
  - Kontrak uji terkait: `tests/Feature/CalendarComponentTest.php:167-168`
- **Bukti Konkret:**
  ```css
  table.dataTable thead > tr > :is(th, td):is(.dt-orderable-asc, .dt-orderable-desc, .dt-ordering-asc, .dt-ordering-desc, .dt-orderable-none) {
      padding-right: 2.25rem !important;
      padding-inline-end: 2.25rem !important;
  }
  ```
- **Root Cause & Dampak:**
  Kolom non-sortable (`.dt-orderable-none`, seperti kolom `No` selebar `40px` `text-center` dan kolom `Action` `text-end`) tidak memiliki elemen `.dt-column-order` yang diposisikan absolut di sisi kanan (`right: 0.625rem`). Memaksakan `padding-inline-end: 2.25rem !important` membuat teks header `Action` rata kanan menjorok `36px` ke kiri dibanding tombol aksi di dalam `<td>`, serta mempersempit ruang teks kolom `No`. *(Catatan: `CalendarComponentTest.php:167-168` saat ini meng-assert keberadaan string `.dt-orderable-none` di blok CSS tersebut, sehingga perbaikan harus menyelaraskan CSS dan test secara bersamaan).*

---

## 3. Tabel Hasil Verifikasi

Seluruh perintah dieksekusi **satu per satu secara serial** pada sesi review ini tanpa modifikasi kode/database produksi:

| Gerbang | Perintah Eksak yang Dijalankan | Status | Ringkasan Output & Analisis atribusi (`Upgrade` vs `Pre-existing HEAD`) |
|---|---|---|---|
| **Gate 1** | `php artisan test --filter=TestingEnvironmentDatabaseSafetyTest` | **Passed** | `2 passed (4 assertions)`, durasi `2.00s`, exit code `0`. Isolasi DB `adasi_portal_test` terkonfirmasi aman. |
| **Gate 2** | `php artisan test --filter="FrontendAssetLoadingTest\|DataTablesPreferenceTest\|DataTablesLocalizationTest\|PaginationViewTest\|CalendarComponentTest\|DataTablesSingleSearchContractTest\|InforTableNameStripingContractTest\|RegionalDataTablesContractTest\|RegionalMaterialClaimDataTablesContractTest\|SecurityHeadersTest\|TranslationParityTest"` | **Failed** *(Pre-existing di `HEAD`; 8/8 suite target upgrade LULUS)* | `69 passed, 3 failed (51.393 assertions)`, durasi `36.78s`, exit code `1`.<br>- **8 kelas kontrak upgrade LULUS 100%:** `TranslationParityTest` (`3/3`), `DataTablesSingleSearchContractTest` (`4/4`), `SecurityHeadersTest` (`8/8`), `CalendarComponentTest` (`9/9`), `DataTablesLocalizationTest` (`3/3`), `DataTablesPreferenceTest` (`2/2`), `FrontendAssetLoadingTest` (`17/17`), `PaginationViewTest` (`14/14`).<br>- **3 kegagalan terbukti *pre-existing* di `HEAD` (0 kaitan dengan diff upgrade):**<br>  1. `InforTableNameStripingContractTest.php:151` — `resources/views/profile/partials/density-preview.blade.php:34` memiliki `ui-col-data-name` sejak sebelum branch ini (3 test lain termasuk kontrak `.dt-empty` baris `:26-27` LULUS).<br>  2. `RegionalDataTablesContractTest.php:121` — jumlah query `SELECT ... user_preferences` bernilai `0` (bukan `1`) akibat *in-memory preference caching* dari commit sebelumnya (`02890af1`).<br>  3. `RegionalMaterialClaimDataTablesContractTest.php:121` — penyebab identik (`0 !== 1` pada hitungan query `user_preferences`). |
| **Gate 3** | `node --test tests/js/*.test.mjs` *(diverifikasi identik baik via glob native Node v24.14.0 maupun ekspansi PowerShell)* | **Failed** *(Pre-existing di `HEAD`; seluruh test JS terkait upgrade LULUS)* | `896 passed, 1 failed` dari `897` test (`12.34s`, exit code `1`).<br>- Seluruh suite terkait upgrade (`adasi-loader.test.mjs`, `locale-switcher.test.mjs`, `session-sync.test.mjs`, `text-contrast.test.mjs`, `workspace-shell.test.mjs`) **LULUS 100%**.<br>- Satu-satunya kegagalan adalah `tests/js/button-governance.test.mjs:317` (`disallowed ghost: resources/views/components/export/advanced-modal.blade.php:154-155`), yang berasal dari commit `HEAD` `dd5858c7` dan tidak disentuh oleh diff ini. |
| **Gate 4** | `php vendor/bin/pint --test` pada seluruh `18` file `.php`/`.blade.php` di `git diff --name-only HEAD` | **Passed** | `{"tool":"pint","result":"passed"}`, exit code `0`. *(Catatan: Git mengeluarkan warning `CRLF will be replaced by LF` pada 5 file Blade — dicatat sebagai `[LOW-01]`).* |
| **Gate 5a** | Inspeksi sinkronisasi `public/build/manifest.json` terhadap mtime & isi bundle `resources/{css,js}/*` | **Passed** *(dengan temuan `[MEDIUM-02]`)* | `public/build/manifest.json` (`mtime 2026-11-24T14:40:19Z`) lebih baru daripada seluruh file sumber yang diedit (`app.css`, `adasi-loader.js`, `embed-readiness.js`, `locale-switcher.js`, `session-sync.js`). `assets/app-D_aFehaD.css` memuat `.dt-container` & `.dt-ordering-asc` (`0` `.dataTables_wrapper` / `.sorting_asc`); `assets/app-n_kcwg_3.js` memuat `dt-error.dt`, namun masih memuat fallback `nTableWrapper` (`[MEDIUM-02]`). |
| **Gate 5b.1** | `node tests/Support/cancelled-recovery-browser-check.mjs` | **Failed** *(DT 3 LULUS; gagal pre-existing di fixture invoice)* | Seluruh skenario PO & DataTables `3.1.5` `#poGoodsReceiptsTable` (`PASS en light po`, `PASS en light gr`, `PASS en dark po`, `PASS en dark gr`) **LULUS**. Gagal di baris `:154` pada `en-invoice.html` (halaman non-DataTables): `#localInvoiceForm input[name="kept_document_ids[]"].length` (`4 !== 2`, fixture lama). |
| **Gate 5b.2** | `node tests/Support/local-import-browser-check.mjs` | **Failed** **(`HIGH-02` — Regresi Upgrade)** | Exit code `1`. Timeout pada `document.querySelector('[data-local-import=GR]')?.dataset.initialized === 'true'` karena baris `:67-70` tidak me-rewrite hash `/build/assets/app-*.js` & `.css` dari `public/build/manifest.json` pasca-build upgrade. |
| **Gate 5b.3** | `node tests/Support/local-import-ux-browser-check.mjs` | **Passed** | `16 import UX browser scenarios passed` (`en/id` × `light/dark` × `375/1440` × `PO/GR`), exit code `0`. |
| **Gate 5b.4** | `node tests/Support/local-po-pr-browser-check.mjs` | **Passed** | `56 PO PR browser scenarios passed` (termasuk halaman detail ber-DataTables `3.1.5`), exit code `0`. |
| **Gate 5b.5** | `node tests/Support/manual-po-pr-browser-check.mjs` | **Failed** *(Pre-existing fixture non-DT)* | Exit code `1` pada `en-create.html` (halaman tanpa DataTables): fixture HTML statis lama merender Alpine combobox dengan `remoteSearch: false` sehingga `pr-suggestions` tidak dipanggil (`#pr_number-suggestions [role=option]` tetap `1`, bukan `2`). |
| **Gate 5b.6** | `node tests/Support/modern-web-guidance-browser-check.mjs` | **Passed** | `7 browser audit checks passed (8 axe-core WCAG 2.2 AA scans)`, exit code `0` — termasuk audit live `datatables-aria-sort-single-scroll-region-200scale-and-axe` pada `admin/users` dengan DataTables `3.1.5` + jQuery `4.1.0`, `0` pelanggaran CSP, dan `0` runtime exception. |
| **Gate 5b.7** | `node tests/Support/workspace-shell-browser-check.mjs` | **Failed** *(Pre-existing selector stack badge)* | `PASS 1_initial_shell_shortcuts_pinned...` & `PASS 2_multi_instance_duplicate_tabs...`, lalu gagal di baris `:564` (`undefined !== '2'` pada `.workspace-session__stack-count` yang telah dihapus oleh perubahan desain badge grup di commit sebelumnya). |

---

## 4. Remediation Plan (Siap Dieksekusi pada Sesi Remediasi Terpisah)

### Langkah 1 — Perbaiki Bypass Error Lifecycle DataTables `3.1.5` (`[HIGH-01]`)
1. **`resources/js/session-sync.js` (`:235-256` & `:300-327`):**
   - Di dalam handler `$(doc).on('preXhr.dt', (_event, settings, _data, ajaxOpts) => { ... })` (tepat sebelum `F.ajax(l)` dieksekusi oleh DataTables 3), bungkus callback `ajaxOpts.error` (dengan guard flag seperti `ajaxOpts.__adasiSessionWrapped = true` agar idempoten) sehingga apabila `ajaxOpts.error` kustom telah didefinisikan oleh tabel, wrapper tetap memanggil `handleXhrResponse(settings?.jqXHR, ajaxOpts?.url)` terlebih dahulu (dan menekan toast error generik kustom apabila status adalah `401` atau `419`, selaras dengan penekanan alert di `dt-error.dt`), baru meneruskan ke `originalError.apply(this, args)` untuk error non-sesi.
2. **`resources/js/adasi-loader.js` (`:675-691`) & `resources/js/embed-readiness.js` (`:388-395`):**
   - Pada `preXhr.dt` di `adasi-loader.js`, jika `ajaxOpts && typeof ajaxOpts.error === 'function'` (mengindikasikan `l.error` internal DataTables telah ditimpa sehingga `processing.dt` `busy=false` tidak akan terpanggil otomatis saat gagal), bungkus `ajaxOpts.error` agar selalu memanggil `setTableBusy(resolveTableWrapper(settings), false)` di blok `finally`.
   - Pada `preXhr.dt` di `embed-readiness.js`, bungkus `ajaxOpts.error` serupa agar memanggil `controller.onTableProcessing(tableKeyFor(settings), false)` dan `controller.onTableSettled(tableKeyFor(settings))` saat XHR gagal.
3. **Tambahkan Unit Test Regresi:**
   - Di `tests/js/session-sync.test.mjs`, `tests/js/adasi-loader.test.mjs`, dan `tests/js/workspace-shell.test.mjs`, tambahkan kasus uji yang mensimulasikan tabel DataTables `3.1.5` dengan `ajax: { url, error: customErrorFn }` yang gagal dengan HTTP `401`, `419`, dan `500` tanpa memicu `xhr.dt` maupun `processing.dt(..., false)` dari core DT.

### Langkah 2 — Perbaiki `tests/Support/local-import-browser-check.mjs` (`[HIGH-02]`)
1. **`tests/Support/local-import-browser-check.mjs` (`:67-70` & `:130-141`):**
   - Pada blok `if (url.pathname.startsWith('/fixtures/'))`, baca `public/build/manifest.json` dan rewrite `/build/assets/app-[\w-]+\.js` → `'/build/' + manifest['resources/js/app.js'].file` serta `/build/assets/app-[\w-]+\.css` → `'/build/' + manifest['resources/css/app.css'].file` (sama persis dengan `tests/Support/local-import-ux-browser-check.mjs:37-40`).
   - Selaraskan intersepsi aset CDN SRI (`vendor` map dari `en-imports.html` + `en-finance-detail.html` + `dataTables-3.1.5.min.js`) seperti pada `local-import-ux-browser-check.mjs:88-123` agar pemuatan `jquery-4.1.0.min.js`, `dataTables.min.js`, dan `dataTables.bootstrap5.min.{js,css}` deterministik dan bebas gagal jaringan.

### Langkah 3 — Pertahankan `DataTable.ajax.defaults.headers` pada Non-Prod Ngrok (`[MEDIUM-01]`)
1. **`resources/views/layouts/app.blade.php` (`:640-648`):**
   - Setel header pada `window.DataTable.ajax.defaults.headers` saat tersedia **dan** di dalam `preXhr.dt` gabungkan `...(window.DataTable?.ajax?.defaults?.headers || {})` sebelum `...(ajaxOpts.headers || {})`:
     ```js
     $(document).on('preXhr.dt', function (_event, _settings, _data, ajaxOpts) {
         if (ajaxOpts && typeof ajaxOpts === 'object') {
             ajaxOpts.headers = {
                 ...(window.DataTable?.ajax?.defaults?.headers || {}),
                 ...(ajaxOpts.headers || {}),
                 [headerName]: headerValue,
             };
         }
     });
     ```

### Langkah 4 — Bersihkan Seluruh Sisa Fallback Hungarian DataTables 1.x (`[MEDIUM-02]`)
1. Hapus seluruh fallback `settings?.nTableWrapper`, `settings?.nTable`, `settings?.oLanguage`, `settings?.bInitialised`, `settings?.sAjaxSource`, dan `settings?.oFeatures?.bServerSide` dari:
   - `resources/views/layouts/app.blade.php:572` (gunakan `new ApiCtor(settings).table().container()` saja)
   - `resources/js/adasi-loader.js:673, 685` (gunakan `new ApiCtor(settings).table().container()` dan `settings?.table`)
   - `resources/js/embed-readiness.js:263, 270, 272, 296, 313, 383` (gunakan `settings.table`, `settings.ajax`, `settings.features?.serverSide`, `new ApiCtor(settings).table().container()`, `settings.initialised`)
   - `resources/js/locale-switcher.js:354, 364, 368, 381` (gunakan `settings.language`, `tableApi?.table?.()?.container?.()`, `settings.table`)
2. Perbarui stub minimal pada `tests/js/*.test.mjs` jika masih ada yang mengandalkan `nTableWrapper` / `nTable`, jalankan `npm.cmd run build`, lalu pastikan `public/build/assets/app-*.js` menghasilkan `has nTableWrapper === false`.
3. Hapus file cache uji yatim `storage/framework/testing/jquery.dataTables-1.13.6.min.js`.

### Langkah 5 — Perbaiki Persistensi Label Live Locale Switch & Merapikan Line-Ending / Padding (`[MEDIUM-03]`, `[LOW-01]`, `[LOW-02]`)
1. **`resources/views/layouts/app.blade.php:542, 561-566` & `resources/js/locale-switcher.js:319-331, 381-385` (`[MEDIUM-03]`):**
   - Simpan label navigasi paginasi aktif pada atribut data atau kamus runtime (`window.AdasiI18n` / `document.documentElement.dataset.paginationLabel`) yang ikut diperbarui oleh `locale-switcher.js` saat morphing dokumen baru, atau izinkan `syncDataTableChromeLabels` memperbarui `.dt-paging nav[aria-label]`.
   - Di `resources/js/locale-switcher.js:383`, panggil juga `syncDataTableChromeLabels(wrapper, settings.language || dtLang)` di dalam handler `draw.dt.adasiLocaleInfo` sehingga `.dt-length label` dan `.dt-paging nav[aria-label]` tetap konsisten setelah setiap `draw.dt` berikutnya.
2. **Normalisasi `CRLF` → `LF` (`[LOW-01]`):**
   - Konversi kembali line-ending ke `LF` pada kelima file Blade (`resources/views/admin/auth-audit-logs/index.blade.php`, `resources/views/admin/material-hs-code/_script.blade.php`, `resources/views/admin/users/index.blade.php`, `resources/views/purchasing/po/index.blade.php`, `resources/views/purchasing/shipments/index.blade.php`).
3. **Perataan Header `.dt-orderable-none` (`[LOW-02]`):**
   - Pisahkan `.dt-orderable-none` dari aturan `padding-inline-end: 2.25rem !important` di `resources/css/app.css:5227-5232` agar kolom non-sortable (`No` dan `Action`) memakai padding sel tabel standar, lalu sesuaikan asersi string di `tests/Feature/CalendarComponentTest.php:167-168` secara selaras.

### Kriteria Penerimaan & Perintah Verifikasi Ulang Pasca-Remediasi
Setelah langkah 1–5 diterapkan dan `npm.cmd run build` selesai dijalankan, seluruh perintah berikut wajib dieksekusi ulang secara **serial**:
1. `php artisan test --filter=TestingEnvironmentDatabaseSafetyTest` → harus **Passed**.
2. `php artisan test --filter="FrontendAssetLoadingTest|DataTablesPreferenceTest|DataTablesLocalizationTest|PaginationViewTest|CalendarComponentTest|DataTablesSingleSearchContractTest|SecurityHeadersTest|TranslationParityTest"` → harus **Passed 100%** (dan `InforTableNameStripingContractTest::test_css_defines_hybrid_index_row_striping_and_detail_column_band_tokens` lulus).
3. `node --test tests/js/adasi-loader.test.mjs tests/js/locale-switcher.test.mjs tests/js/session-sync.test.mjs tests/js/text-contrast.test.mjs tests/js/workspace-shell.test.mjs` → harus **Passed 100%** termasuk test regresi `ajax.error` kustom (`401`/`419`/`500`).
4. `php vendor/bin/pint --test` pada seluruh file PHP yang berubah & `git status` bebas warning `CRLF will be replaced by LF`.
5. `node tests/Support/local-import-browser-check.mjs`, `node tests/Support/local-import-ux-browser-check.mjs`, `node tests/Support/local-po-pr-browser-check.mjs`, dan `node tests/Support/modern-web-guidance-browser-check.mjs` → keempatnya harus **Passed (`exit 0`)**, serta pemeriksaan string `nTableWrapper` pada `resources/` dan `public/build/assets/*.js` menghasilkan **0** kemunculan.
