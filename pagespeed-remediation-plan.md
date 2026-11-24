# Remediation Implementation Plan — Optimasi PageSpeed `/oauth/authorize`

**Tanggal Audit:** 2026-11-23  
**Acuan Spesifikasi:** `rencana_implementasi_optimasi_pagespeed_adasi_supplier_portal.md`  
**Verdict Audit:** `FAIL — NEEDS REMEDIATION`  
**Status Dokumen:** Siap dieksekusi bertahap (tanpa merusak OAuth2/PKCE, CSRF, CSP, SRI, maupun halaman internal dashboard)

---

## 1. Ringkasan Prioritas Perbaikan

| ID Temuan | Severity | Prioritas | File Target | Ringkasan Masalah | Estimasi Dampak |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **MAJ-01** | MAJOR | **P0** | `resources/views/layouts/auth.blade.php` | `.auth-card.ui-page-fade` memulai render elemen Mobile LCP (`<h2>Sign in to your account</h2>`) dari `opacity: 0` selama `500ms ease-in` (`backwards`), menahan initial paint & LCP eligibility. | Hilangkan penundaan LCP & Speed Index `~100–500 ms` di Mobile. |
| **MAJ-02** | MAJOR | **P0** | `resources/css/auth.css` | Shorthand `border-style: solid` di baris `272–275` (tanpa Preflight) menimbulkan phantom border `3px` (`medium`) pada sisi non-target untuk `tw:border-t`/`tw:border-b`/`tw:border-e`, sekaligus membuat `tw:border-s-4` pada `<x-ui.alert>` hilang sama sekali. | Pulihkan tampilan visual `<x-ui.alert>` (validation/rate-limit), dialog header/footer, & `supplier-register`. |
| **MAJ-03** | MAJOR | **P0** | `resources/css/auth.css`, `tests/Feature/SurfaceHierarchyTest.php` | `Tests\Feature\SurfaceHierarchyTest` **FAIL** (baris 27): CSS `.auth-form-panel` pindah dari `layouts/auth.blade.php` ke `resources/css/auth.css` dan background berubah ke `--ui-workspace-bg` (bukan `--md-background`). | Kembalikan suite PHPUnit menjadi **100% GREEN** dan jaga kontrak surface hierarchy. |
| **MAJ-04** | MAJOR | **P1** | `resources/views/partials/i18n-bootstrap.blade.php` | Cabang `@else` (saat `adasi-i18n.js` tidak terbaca/hilang) memanggil `filemtime($adasiI18nPath)` tanpa guard (`ErrorException` → HTTP 500 di semua layout) dan menambahkan `defer` yang memutus kontrak sinkron `window.AdasiI18n`. | Hilangkan risiko site-wide HTTP 500 & race condition i18n saat fallback. |
| **MAJ-05** | MAJOR | **P1** | `vite.config.js`, `resources/js/auth.js`, `resources/js/app.js`, `resources/js/toast-center.js` (ekstrak dari duplikasi `auth-toast.js`) | Multi-entry Rollup memecah `app.js` dari 1 file (`~55.5 KiB` gz) menjadi **14 file statis** (`74,460 B` gz, +18.9 KiB gz & +13 request di dashboard) dan `/oauth/authorize` memicu **8 request JS** (6 static + 2 waterfall dynamic import + chunk `_i18n` 74 byte). | Pangkas request JS `/oauth/authorize` dari **8 → 2–3 request** (0 waterfall) dan pulihkan dashboard dari **14 → 5–6 chunk** (hemat ~12–18 KiB gz). |
| **MAJ-06** | MAJOR | **P1** | `resources/js/auth-alert-bridge.js` | `injectStylesheet()` & `injectScript()` tidak menghapus tag `<link>`/`<script>` yang gagal load, sehingga retry berikutnya mentok di `if (existing) return Promise.resolve(true)` dan SweetAlert2 rusak permanen hingga full reload. | Pulihkan reliabilitas lazy-load SweetAlert2 saat koneksi seluler sempat putus. |
| **MAJ-07** | MAJOR | **P1** | `public/.htaccess` | `Header append Vary Accept-Encoding` global menyebabkan duplikasi `Vary: Accept-Encoding, Accept-Encoding` bersama `mod_brotli`/`mod_deflate` dan menempelkannya ke aset biner (`avif`/`webp`/`png`/`jpg`) & HTML dinamis. | Perbaiki kepatuhan HTTP header & cache-key proxy/CDN di Apache cPanel. |
| **MIN-01** | MINOR | **P2** | `resources/views/layouts/auth.blade.php`, `tests/Feature/FrontendAssetLoadingTest.php` | Celah `0.02px` antara `media="(max-width: 1023.98px)"` dan `media="(min-width: 1024px)"` berisiko mengunduh fallback `adasi-login-bg.jpg` (`386.8 KiB`) pada fractional zoom. | Eliminasi 100% risiko unduh JPEG 387 KiB di viewport non-desktop. |
| **MIN-02** | MINOR | **P2** | `resources/views/layouts/auth.blade.php` | Atribut `data-splash-webp`, `data-splash-gif`, `data-splash-wordmark` di `<body>` baris 33 adalah dead markup (tidak dibaca oleh JS mana pun). | Bersihkan payload HTML initial document. |
| **MIN-03** | MINOR | **P2** | `resources/css/auth.css` | `.ui-dialog-open` ditulis dengan `overflow: hidden` lalu langsung ditimpa `overflow: visible` (baris 754–761), tidak konsisten dengan `app.css` & `modern-web-guidance-guardrails.test.mjs`. | Jaga konsistensi scroll-lock guardrail lintas layout. |
| **MIN-04** | MINOR | **P2** | `resources/js/auth-alert-bridge.js` | `nativeFallback()` memakai `t('js.toast.update')` untuk semua tipe alert (`success`/`error`/`warning`/`info`) alih-alih judul spesifik per tipe seperti `adasi-alert.js`. | Samakan UX & lokalisasi fallback alert dengan `public/assets/js/adasi-alert.js`. |
| **MIN-05** | MINOR | **P2** | `resources/js/auth-splash.js`, `resources/js/auth-login-splash.js`, `tests/js/auth-splash.test.mjs` | Masih ada pola DOM Write → sinkron `.focus()` (forced layout read) pada `release()` (`auth-splash.js:126-141`) dan error 422 AJAX (`auth-login-splash.js:87-102`). | Hilangkan sisa forced reflow pada transisi post-login/post-logout & login error INP. |
| **MIN-06** | MINOR | **P2** | `resources/css/auth.css`, `public/assets/images/logo-adasi.webp` | `auth.css` memuat 15 deklarasi `@font-face` non-Latin yang tak dipakai (`~5 KiB` raw CSS); `logo-adasi.webp` (`96.7 KiB`, `336×134`) masih membawa flag `VP8X alpha=True` dan melebihi target `272×108`. | Pangkas ~5 KiB raw CSS auth dan perkecil splash animated WebP untuk transisi post-login/post-logout. |

---

## 2. Urutan Eksekusi Aman (Berdasarkan Dependensi)

Jalankan perbaikan dalam 4 batch terurut agar tidak saling bentrok dan mudah diverifikasi:

1. **Batch 1 — P0 Visual, LCP & Test Contract (`MAJ-01`, `MAJ-02`, `MAJ-03`)**  
   Perbaiki CSS auth, `.ui-page-fade` pada initial login/OAuth render, serta kontrak `SurfaceHierarchyTest`.
2. **Batch 2 — P1 Runtime Reliability & Server Headers (`MAJ-04`, `MAJ-06`, `MAJ-07`, `MIN-04`)**  
   Amankan fallback `i18n-bootstrap.blade.php`, retry-safety `auth-alert-bridge.js`, dan `Vary` header `public/.htaccess`.
3. **Batch 3 — P1 Vite/Rollup Bundle & Chunk Optimization (`MAJ-05`, `MIN-06` CSS font subset)**  
   Konsolidasi `toast-center.js`, static-import modul inti login di `auth.js`, atur `manualChunks` di `vite.config.js`, lalu rebuild `npm.cmd run build`.
4. **Batch 4 — P2 Polish & Residual Reflow (`MIN-01`, `MIN-02`, `MIN-03`, `MIN-05`, `MIN-06` WebP)**  
   Tutup celah `<picture>`, hapus dead `data-splash-*`, rapikan `.ui-dialog-open`, pindahkan `.focus()` ke `rAF`, dan (opsional) re-encode `logo-adasi.webp`.

---

## 3. Detail Rencana Perbaikan per Temuan

### MAJ-01 (P0) — Eliminasi `opacity: 0` Initial Paint pada Mobile LCP (`auth-card.ui-page-fade`)

**Akar Masalah:**
- Di `resources/views/layouts/auth.blade.php:100`, elemen `<div class="auth-card ui-page-fade">` membungkus elemen LCP Mobile (`<h2>Sign in to your account</h2>`).
- Di `resources/css/auth.css:840-846`, `.ui-page-fade` menjalankan `@keyframes ui-page-fade-in { from { opacity: 0; } to { opacity: 1; } }` selama `500ms ease-in` dengan `animation-fill-mode: backwards`.
- Chromium tidak menganggap node ber-`opacity: 0` sebagai kandidat LCP yang valid pada frame pertama, dan kurva `ease-in` menahan keterbacaan visual konten selama ratusan milidetik meski splash sudah dimatikan (`login_page_enabled = false`).

**Langkah Perbaikan Konkret:**
1. Ubah `resources/views/layouts/auth.blade.php` di sekitar baris `100` agar kelas `ui-page-fade` hanya dipasang ketika splash benar-benar dirender (misalnya transisi `post-logout`) atau bukan pada initial load `/login` & `/oauth/authorize`:
   ```blade
   @php
       $shouldFadeAuthCard = (bool) request()->attributes->get('ui.auth_splash_rendered')
           || ! request()->routeIs('login', 'passport.authorizations.authorize');
   @endphp
   <div class="auth-card{{ $shouldFadeAuthCard ? ' ui-page-fade' : '' }}">
       @include('partials.alerts')
       @yield('content')
   </div>
   ```
2. Pertahankan `@include('partials.loader-logo')` di `<head>` agar kontrak `Tests\Feature\FrontendAssetLoadingTest::test_navigation_fade_and_loader_thresholds_render_across_layouts` (baris 278–302) tetap lulus.
3. Tambahkan assertion di `tests/Feature/FrontendAssetLoadingTest.php` (`test_auth_login_page_eliminates_render_blocking_assets_and_optimizes_lcp_and_logos`) bahwa saat `login_page_enabled = false`, `.auth-card` pada `/login` tidak membawa kelas `ui-page-fade`.

---

### MAJ-02 (P0) — Perbaikan Border Utility Tanpa Preflight (`tw:border-s-4` & Directional Borders)

**Akar Masalah:**
- `vite.config.js:40-43` menghapus deklarasi `border*-style: var(--tw-border-style)` dari seluruh utility border Tailwind v4 (karena Tailwind Preflight dimatikan dan `tailwind-build.test.mjs:82` mengunci perilaku ini).
- Setelah Bootstrap dihapus dari `layouts/auth.blade.php`, `resources/css/auth.css:272-275` menambahkan:
  ```css
  :where(.tw\:border, .tw\:border-t, .tw\:border-b, .tw\:border-s, .tw\:border-e, .tw\:border-x, .tw\:border-y) {
      border-style: solid;
      border-color: var(--md-outline);
  }
  ```
- Ada **dua cacat** pada aturan ini:
  1. `.tw\:border-s-4` (dipakai `<x-ui.alert>` di `resources/views/components/ui/alert.blade.php:16` untuk pesan validasi login & rate-limit) **tidak tercakup**, sehingga `border-inline-start-style` tetap `none` dan aksen border kiri 4px hilang total.
  2. Karena Preflight mati, nilai awal CSS `border-*-width` adalah `medium` (`3px`). Menerapkan shorthand `border-style: solid` ke kelas satu sisi seperti `.tw\:border-t`, `.tw\:border-b`, `.tw\:border-e` (yang dipakai di `supplier-register.blade.php:56,188,364`, `dialog.blade.php:104,117`, `maintenance/_notices.blade.php:8`) memunculkan **phantom border solid 3px pada ketiga sisi lain**.

**Langkah Perbaikan Konkret:**
1. Ganti blok baris `272–275` di `resources/css/auth.css` menjadi aturan border-style per sisi dengan specificity nol (`:where(...)`), sehingga kelas warna (`tw:border-error`, `tw:border-outline-variant`) dan gaya (`tw:border-dashed`) tetap bisa override dengan bersih:
   ```css
   :where(.tw\:border, .tw\:border-2, .tw\:border-4) {
       border-style: solid;
       border-color: var(--md-outline);
   }
   :where(.tw\:border-t, .tw\:border-t-2, .tw\:border-t-4) {
       border-top-style: solid;
       border-top-color: var(--md-outline);
   }
   :where(.tw\:border-b, .tw\:border-b-2, .tw\:border-b-4) {
       border-bottom-style: solid;
       border-bottom-color: var(--md-outline);
   }
   :where(.tw\:border-s, .tw\:border-s-2, .tw\:border-s-4) {
       border-inline-start-style: solid;
       border-inline-start-color: var(--md-outline);
   }
   :where(.tw\:border-e, .tw\:border-e-2, .tw\:border-e-4) {
       border-inline-end-style: solid;
       border-inline-end-color: var(--md-outline);
   }
   :where(.tw\:border-x, .tw\:border-x-2, .tw\:border-x-4) {
       border-inline-style: solid;
       border-inline-color: var(--md-outline);
   }
   :where(.tw\:border-y, .tw\:border-y-2, .tw\:border-y-4) {
       border-block-style: solid;
       border-block-color: var(--md-outline);
   }
   ```
2. Verifikasi di hasil kompilasi `public/build/assets/auth-*.css` bahwa aturan side-specific tersebut ada dan `npm.cmd run test:js` (`tests/js/tailwind-build.test.mjs`) tetap lulus 100%.

---

### MAJ-03 (P0) — Pemulihan Kontrak `Tests\Feature\SurfaceHierarchyTest`

**Akar Masalah:**
- `tests/Feature/SurfaceHierarchyTest.php:16,27` membaca `resources/views/layouts/auth.blade.php` dan meng-assert `background: var(--md-background);` untuk memastikan perubahan token workspace internal tidak mengubah background halaman auth.
- Setelah inline `<style>` dipindahkan ke `resources/css/auth.css`, file `layouts/auth.blade.php` tidak lagi memuat CSS tersebut, dan `resources/css/auth.css:545` memakai `background: var(--ui-workspace-bg);` alih-alih `background: var(--md-background);`.

**Langkah Perbaikan Konkret:**
1. Periksa apakah perubahan `--ui-workspace-bg` pada `.auth-form-panel` disengaja oleh desain sistem terbaru atau terbawa tidak sengaja:
   - **Default (mengikuti kontrak eksplisit test `"without changing auth background"`):** Ubah `resources/css/auth.css:545` dari:
     ```css
     background: var(--ui-workspace-bg);
     ```
     menjadi:
     ```css
     background: var(--md-background);
     ```
2. Perbarui `tests/Feature/SurfaceHierarchyTest.php:16,27` agar memeriksa sumber stylesheet auth yang sebenarnya (`resources/css/auth.css`):
   ```php
   $authStyles = file_get_contents(resource_path('css/auth.css'));
   // ...
   $this->assertStringContainsString('background: var(--md-background);', $authStyles);
   ```
   *(Catatan: Jika pemilik produk secara eksplisit memang menghendaki `--ui-workspace-bg` di halaman auth, konfirmasi dulu sebelum mengubah nilai ekspektasi test.)*

---

### MAJ-04 (P1) — Perbaikan Fallback HTTP 500 & Race Condition di `partials/i18n-bootstrap.blade.php`

**Akar Masalah:**
- Pada `resources/views/partials/i18n-bootstrap.blade.php:13-17`:
  ```blade
  @if (is_string($adasiI18nInline) && $adasiI18nInline !== '')
      <script data-adasi-i18n-runtime>{!! $adasiI18nInline !!}</script>
  @else
      <script src="{{ asset('assets/js/adasi-i18n.js') }}?v={{ filemtime($adasiI18nPath) }}" defer></script>
  @endif
  ```
- Cabang `@else` hanya dieksekusi jika `adasi-i18n.js` tidak terbaca/tidak ada/kosong — memanggil `filemtime($adasiI18nPath)` tanpa `is_file()`/`file_exists()` melempar `ErrorException` (**HTTP 500** di seluruh portal).
- Atribut `defer` pada fallback juga memutus kontrak sinkron bagi inline script yang langsung memakai `window.AdasiI18n` (contoh: `resources/views/auth/rate-limited.blade.php:40` dan skrip klasik di `layouts/app.blade.php`).

**Langkah Perbaikan Konkret:**
1. Ubah `resources/views/partials/i18n-bootstrap.blade.php` menjadi:
   ```blade
   @php
       $adasiI18nPath = public_path('assets/js/adasi-i18n.js');
       $adasiI18nInline = null;
       $adasiI18nVersion = null;
       try {
           if (\Illuminate\Support\Facades\File::isReadable($adasiI18nPath)) {
               $adasiI18nInline = \Illuminate\Support\Facades\File::get($adasiI18nPath);
           }
       } catch (\Throwable) {
           $adasiI18nInline = null;
       }
       if (! is_string($adasiI18nInline) || $adasiI18nInline === '') {
           $adasiI18nVersion = is_file($adasiI18nPath) ? @filemtime($adasiI18nPath) : null;
       }
   @endphp
   <script id="adasi-i18n" type="application/json">{!! json_encode(\App\Support\JsTranslations::payload(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!}</script>
   @if (is_string($adasiI18nInline) && $adasiI18nInline !== '')
       <script data-adasi-i18n-runtime>{!! $adasiI18nInline !!}</script>
   @else
       <script src="{{ asset('assets/js/adasi-i18n.js') }}{{ $adasiI18nVersion ? '?v='.$adasiI18nVersion : '' }}"></script>
   @endif
   ```
2. Tambahkan unit/feature test di `tests/Feature/FrontendAssetLoadingTest.php` yang mem-mock `File::isReadable(public_path('assets/js/adasi-i18n.js')) => false` dan memastikan rendering `partials.i18n-bootstrap` tidak melempar exception dan merender `<script src="...adasi-i18n.js">` secara aman tanpa `defer`.

---

### MAJ-05 (P1) — Konsolidasi Chunk Vite/Rollup (Hapus Regresi 14 Chunk Dashboard & 8-Request Waterfall Login)

**Akar Masalah:**
- Berdasarkan `public/build/manifest.json`:
  - `resources/js/app.js` terpecah dari 1 file (`~55.5 KiB` gz) menjadi **14 file statis** (`220,045 B` raw / `74,460 B` gz, **+18.9 KiB gz & +13 HTTP request** di setiap halaman dashboard) karena 6 modul (`password-assistance`, `locale-switcher`, `overlay-modality`, `async-form-submit`, `file-upload`, `searchable-select`) di-`import()` dinamis oleh `auth.js` sementara di-`import` statis oleh `app.js`.
  - `/oauth/authorize` memicu **6 `<link rel="modulepreload">` statis** (termasuk `_i18n-D-8MAZCA.js` berukuran **74 byte**!) ditambah **2 dynamic import waterfall langsung** saat `DOMContentLoaded` (`auth-guest-session.js` `1.08 kB` & `auth-login-splash.js` `2.71 kB`) = **8 request JS** pada Slow 4G.
  - `resources/js/auth-toast.js` (428 baris) menyalin seluruh logika toast dari `resources/js/app.js:86-597` sehingga menciptakan risiko *code drift* dan menggandakan ukuran bundle.

**Langkah Perbaikan Konkret:**
1. **Static-import modul inti login di `resources/js/auth.js`:**
   - Ubah `auth-guest-session` dan `auth-login-splash` menjadi static import (hanya `~1.75 KiB` gz gabungan, selalu dibutuhkan di `/oauth/authorize` & `/login`), tetap jalankan kondisional berdasarkan `hasMarker(...)` saat `bootDeferredAuthModules()`:
     ```js
     import { bootAuthGuestSession } from './auth-guest-session';
     import { bootAuthLoginSplashForms } from './auth-login-splash';
     ```
2. **Ekstrak shared `resources/js/toast-center.js`:**
   - Pindahkan implementasi `registerToastCenter(Alpine, { navigateUrl })` (yang menerima callback `navigateUrl` untuk `app.js` agar `auth.js` tidak menarik `workspace-shell` / `workspace-navigation`) menjadi 1 modul bersama; hapus duplikasi di `app.js` dan hapus `auth-toast.js`.
3. **Atur `build.rollupOptions.output.manualChunks` di `vite.config.js`:**
   - Kelompokkan modul inti bersama ke satu chunk cacheable `portal-core` dan 6 modul form bersama ke satu chunk `portal-forms`:
     ```js
     build: {
         rollupOptions: {
             output: {
                 manualChunks(id) {
                     const normalized = id.replace(/\\/g, '/');
                     if (
                         normalized.includes('/node_modules/alpinejs/')
                         || normalized.endsWith('/resources/js/i18n.js')
                         || normalized.endsWith('/resources/js/origin-validator.js')
                         || normalized.endsWith('/resources/js/form-a11y.js')
                         || normalized.endsWith('/resources/js/asset-protection.js')
                         || normalized.endsWith('/resources/js/submit-guard.js')
                         || normalized.endsWith('/resources/js/toast-center.js')
                     ) {
                         return 'portal-core';
                     }
                     if (
                         normalized.endsWith('/resources/js/password-assistance.js')
                         || normalized.endsWith('/resources/js/locale-switcher.js')
                         || normalized.endsWith('/resources/js/overlay-modality.js')
                         || normalized.endsWith('/resources/js/async-form-submit.js')
                         || normalized.endsWith('/resources/js/file-upload.js')
                         || normalized.endsWith('/resources/js/searchable-select.js')
                     ) {
                         return 'portal-forms';
                     }
                 },
             },
         },
     },
     ```
4. **Dampak Terukur yang Diharapkan:**
   - `/oauth/authorize`: turun dari **8 request JS** (6 static + 2 waterfall) → **2–3 request statis** (`auth-*.js`, `portal-core-*.js`, `preload-helper-*.js`), **0 dynamic waterfall** di halaman login, dan **0 byte** `portal-forms` di halaman login.
   - Dashboard (`app.js`): turun dari **14 file statis** → **5–6 file statis** (`app-*.js`, `portal-core-*.js`, `portal-forms-*.js`, `dashboard-layout-core-*.js`, `splitter-*.js`, `preload-helper-*.js`), dengan `portal-core-*.js` sudah ter-cache hangat dari halaman login!
5. Verifikasi dengan `npm.cmd run build` dan cek `public/build/manifest.json` + `npm.cmd run test:js`.

---

### MAJ-06 (P1) — Retry-Safe Lazy Loader pada `resources/js/auth-alert-bridge.js` (+ `MIN-04`)

**Akar Masalah:**
- Di `resources/js/auth-alert-bridge.js:15-53`:
  - `injectStylesheet()` dan `injectScript()` menambahkan tag `<link>`/`<script>` ke `document.head`, tetapi **tidak menghapusnya saat event `error`** dan tidak menandai status siap (`dataset.adasiLoaded = '1'`).
  - Saat `ensureAlertRuntime()` gagal dan me-reset `alertAssetsPromise = null`, percobaan pemanggilan berikutnya menemukan tag lama di `document.head`, langsung masuk `if (existing) return Promise.resolve(true)`, sehingga `!window.Swal` gagal permanen hingga reload halaman.
- *(Sekalian `MIN-04`)*: Di baris `101`, `nativeFallback()` memakai `t('js.toast.update')` untuk semua tipe alert (`success`/`error`/`warning`/`info`), tidak selaras dengan `public/assets/js/adasi-alert.js:153`.

**Langkah Perbaikan Konkret:**
1. Perbarui `injectStylesheet()` dan `injectScript()` di `resources/js/auth-alert-bridge.js` agar:
   - Hanya men-short-circuit jika elemen eksisting sudah selesai dimuat (`existing.dataset.adasiLoaded === '1'`).
   - Jika elemen eksisting masih loading (`dataset.adasiLoading === '1'`), ikut menunggu event `load`/`error`-nya.
   - Saat event `error`, **hapus elemen `<link>`/`<script>` dari DOM (`el.remove()`)** sebelum `resolve(false)` agar retry berikutnya benar-benar melakukan request baru:
   ```js
   function injectStylesheet(href, integrity) {
       if (!href) return Promise.resolve(true);
       const existing = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
           .find((link) => link.getAttribute('href') === href || link.href === href);
       if (existing?.dataset.adasiLoaded === '1') return Promise.resolve(true);
       if (existing && existing.dataset.adasiLoading !== '1') {
           existing.remove();
       } else if (existing) {
           return new Promise((resolve) => {
               existing.addEventListener('load', () => resolve(true), { once: true });
               existing.addEventListener('error', () => resolve(false), { once: true });
           });
       }

       return new Promise((resolve) => {
           const link = document.createElement('link');
           link.rel = 'stylesheet';
           link.href = href;
           link.dataset.adasiLoading = '1';
           if (integrity) {
               link.integrity = integrity;
               link.crossOrigin = 'anonymous';
           }
           link.addEventListener('load', () => {
               delete link.dataset.adasiLoading;
               link.dataset.adasiLoaded = '1';
               resolve(true);
           }, { once: true });
           link.addEventListener('error', () => {
               link.remove();
               resolve(false);
           }, { once: true });
           document.head.appendChild(link);
       });
   }

   function injectScript(src, integrity) {
       if (!src) return Promise.resolve(false);
       const existing = Array.from(document.querySelectorAll('script[src]'))
           .find((script) => script.getAttribute('src') === src || script.src === src);
       if (existing?.dataset.adasiLoaded === '1') return Promise.resolve(true);
       if (existing && existing.dataset.adasiLoading !== '1') {
           existing.remove();
       } else if (existing) {
           return new Promise((resolve) => {
               existing.addEventListener('load', () => resolve(true), { once: true });
               existing.addEventListener('error', () => resolve(false), { once: true });
           });
       }

       return new Promise((resolve) => {
           const script = document.createElement('script');
           script.src = src;
           script.async = true;
           script.dataset.adasiLoading = '1';
           if (integrity) {
               script.integrity = integrity;
               script.crossOrigin = 'anonymous';
           }
           script.addEventListener('load', () => {
               delete script.dataset.adasiLoading;
               script.dataset.adasiLoaded = '1';
               resolve(true);
           }, { once: true });
           script.addEventListener('error', () => {
               script.remove();
               resolve(false);
           }, { once: true });
           document.head.appendChild(script);
       });
   }
   ```
2. Selaraskan judul `nativeFallback()` (`MIN-04`) di baris `88–103`:
   ```js
   const fallbackTitleKeys = {
       success: 'js.toast.completed',
       error: 'js.toast.failed',
       warning: 'js.toast.attention',
       info: 'js.toast.update',
   };
   const fallbackTitle = title || t(fallbackTitleKeys[method] || 'js.toast.update');
   window.alert(`${fallbackTitle}\n\n${text}`);
   ```
3. Tambahkan unit test di `tests/js/` untuk memverifikasi retry sukses setelah error pertama pada `injectScript` dan `injectStylesheet`.

---

### MAJ-07 (P1) — Scope & Merge Header `Vary: Accept-Encoding` di `public/.htaccess`

**Akar Masalah:**
- Baris `12–16` `public/.htaccess`:
  ```apache
  <IfModule mod_headers.c>
      Header set Cache-Control "public, max-age=86400, must-revalidate" env=adasi_public_asset
      Header set Cache-Control "public, max-age=31536000, immutable" env=adasi_vite_asset
      Header append Vary Accept-Encoding
  </IfModule>
  ```
- `Header append Vary Accept-Encoding` berlaku global untuk semua file (termasuk AVIF/WebP/PNG/JPG dan HTML PHP) dan menghasilkan duplikasi `Vary: Accept-Encoding, Accept-Encoding` ketika `mod_brotli`/`mod_deflate` juga mengeluarkan header `Vary: Accept-Encoding`.

**Langkah Perbaikan Konkret:**
1. Ganti baris `12–16` `public/.htaccess` dengan `Header merge` yang hanya mencakup aset teks terkompresi:
   ```apache
   <IfModule mod_headers.c>
       Header set Cache-Control "public, max-age=86400, must-revalidate" env=adasi_public_asset
       Header set Cache-Control "public, max-age=31536000, immutable" env=adasi_vite_asset
       <FilesMatch "\.(?:css|js|mjs|json|svg|xml|txt)$">
           Header merge Vary Accept-Encoding
       </FilesMatch>
   </IfModule>
   ```
2. Pertahankan seluruh blok `<IfModule mod_rewrite.c>` (baris 28–53) dan blok `<FilesMatch>` deny dotfiles (baris 1–3) tanpa perubahan.

---

### MIN-01 (P2) — Tutup Celah `0.02px` pada `<picture>` Background Desktop

**Akar Masalah:**
- `resources/views/layouts/auth.blade.php:44-49` menggunakan `media="(max-width: 1023.98px)"` dan `media="(min-width: 1024px)"`. Pada browser zoom fraksional (`1023.99px`), tidak ada `<source>` yang cocok sehingga fallback `<img src="adasi-login-bg.jpg">` (`386.8 KiB`) terunduh.

**Langkah Perbaikan Konkret:**
1. Ubah `resources/views/layouts/auth.blade.php:44-49` agar seluruh format desktop (AVIF, WebP, dan JPEG fallback) memakai `media="(min-width: 1024px)"`, dan elemen `<img>` menggunakan 1×1 transparent GIF `data:` URI (yang sudah diizinkan CSP `img-src 'self' data: blob:`):
   ```blade
   <picture class="auth-brand-panel__picture">
       <source media="(min-width: 1024px)" srcset="{{ asset('assets/images/adasi-login-bg.avif') }}" type="image/avif">
       <source media="(min-width: 1024px)" srcset="{{ asset('assets/images/adasi-login-bg.webp') }}" type="image/webp">
       <source media="(min-width: 1024px)" srcset="{{ asset('assets/images/adasi-login-bg.jpg') }}" type="image/jpeg">
       <img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" alt="" width="1600" height="828" loading="eager" decoding="async" fetchpriority="high" draggable="false">
   </picture>
   ```
2. Perbarui assertion di `tests/Feature/FrontendAssetLoadingTest.php:329` dari `media="(max-width: 1023.98px)"` menjadi pengecekan `media="(min-width: 1024px)"` pada ketiga `<source>` (AVIF, WebP, JPEG) dan `src="data:image/gif;base64,` pada `<img>`.

---

### MIN-02 (P2) — Hapus Dead `data-splash-*` Attributes di `<body>`

**Akar Masalah:**
- `resources/views/layouts/auth.blade.php:33` menuliskan `data-splash-webp`, `data-splash-gif`, dan `data-splash-wordmark` di `<body>`, padahal tidak ada satu pun file JS di repo yang membaca atribut tersebut (`git grep` = 0 reader).

**Langkah Perbaikan Konkret:**
1. Sederhanakan `resources/views/layouts/auth.blade.php:33` menjadi:
   ```blade
   <body class="tw:m-0 tw:min-h-screen tw:bg-surface tw:font-sans tw:text-on-surface tw:antialiased">
   ```

---

### MIN-03 (P2) — Rapikan `.ui-dialog-open` di `resources/css/auth.css`

**Akar Masalah:**
- `resources/css/auth.css:754-761` menulis `.ui-dialog-open, .ui-scrim { overflow: hidden; overscroll-behavior: contain; }` lalu langsung menimpa `.ui-dialog-open { overflow: visible; }` — tidak konsisten dengan `app.css` & guardrail `modern-web-guidance-guardrails.test.mjs:109-112`.

**Langkah Perbaikan Konkret:**
1. Pisahkan aturan di `resources/css/auth.css:754-764`:
   ```css
   .ui-dialog-open {
       overscroll-behavior: contain;
   }
   .ui-scrim {
       background: var(--ui-dialog-scrim);
       overflow: hidden;
       overscroll-behavior: contain;
   }
   ```

---

### MIN-05 (P2) — Eliminasi Residual Forced Reflow `.focus()` pada `auth-splash.js` & `auth-login-splash.js`

**Akar Masalah:**
- `resources/js/auth-splash.js:126-141` (`release()`) melakukan mutasi DOM/style (`restoreRoots()`, `setAttribute`, `style.pointerEvents = 'none'`, `classList.add('is-leaving')`) lalu memanggil `target.focus({ preventScroll: true })` secara sinkron di tick yang sama.
- `resources/js/auth-login-splash.js:87-102` juga menulis `errorAlert.hidden = false` & atribut `aria-*` lalu langsung memanggil `.focus()` sinkron saat HTTP 422 AJAX login error.

**Langkah Perbaikan Konkret:**
1. Di `resources/js/auth-splash.js:134-141`, pindahkan pemulihan fokus ke `requestAnimationFrame` (dan batalkan via `restoreFocusFrame` bila `removeVisual()` berjalan atau user sudah memindahkan fokus secara manual):
   ```js
   if (ownedFocus) {
       const fallback = mode === 'native-login' ? doc.querySelector('#email') : doc.querySelector('#main-content');
       const target = returnFocus?.isConnected && returnFocus !== doc.body ? returnFocus : fallback;
       if (target?.isConnected && !target.inert) {
           focusFrame = win.requestAnimationFrame(safe(() => {
               focusFrame = null;
               if (!target.isConnected || target.inert) return;
               if (doc.activeElement && doc.activeElement !== doc.body && !overlay?.contains(doc.activeElement)) return;
               restoringFocus = true;
               try { target.focus({ preventScroll: true }); } finally { restoringFocus = false; }
           }));
       }
   }
   ```
   *(Catatan: Pastikan `removeVisual()` tetap tidak membatalkan `focusFrame` yang dijadwalkan untuk pemulihan fokus setelah `immediate` release, atau gunakan variabel terpisah `restoreFocusFrame`.)*
2. Perbarui `tests/js/auth-splash.test.mjs:625` dengan satu frame tambahan (`e.advance(48)`) setelah `release()` dan tambahkan assertion bahwa `release()` tidak memanggil `target.focus()` secara sinkron.
3. Di `resources/js/auth-login-splash.js:102`, bungkus `form.querySelector('input[name="password"], input[name="code"]')?.focus?.()` dalam `window.requestAnimationFrame(...)`.

---

### MIN-06 (P2) — Trim Non-Latin Font `@font-face` di `auth.css` & Optimasi Ulang `logo-adasi.webp`

**Akar Masalah:**
- `resources/css/auth.css:3-5` mengimpor `@fontsource/source-sans-pro/{400,600,700}.css` yang menghasilkan 21 blok `@font-face` (`7,335 B` raw CSS; 15 di antaranya Cyrillic/Greek/Vietnamese yang tidak dipakai portal EN/ID).
- `public/assets/images/logo-adasi.webp` (`99,018 B` / `96.70 KiB`, `336×134`) masih memakai `VP8X alpha=True` pada background putih solid dan lebih besar dari target `272×108` (2× Retina dari `136×54`).

**Langkah Perbaikan Konkret:**
1. Ubah baris `3–5` `resources/css/auth.css` menjadi subset Latin saja (cukup untuk Bahasa Indonesia & Inggris):
   ```css
   @import "@fontsource/source-sans-pro/latin-400.css";
   @import "@fontsource/source-sans-pro/latin-600.css";
   @import "@fontsource/source-sans-pro/latin-700.css";
   ```
   *(Verifikasi di `node_modules/@fontsource/source-sans-pro/` bahwa file `latin-{400,600,700}.css` tersedia — sudah terverifikasi tersedia di v5.3.0.)*
2. Re-encode `public/assets/images/logo-adasi.webp` ke `272×108` mode RGB opaque (tanpa alpha channel, lossy quality ~75–80) agar ukuran splash turun ke `< 45 KiB` saat muncul di transisi `post-login`/`post-logout`:
   ```bash
   # Menggunakan ffmpeg + libwebp (atau Pillow RGB mode)
   ffmpeg -i public/assets/images/logo-adasi.gif -vf "fps=15,scale=272:108:flags=lanczos" -c:v libwebp -lossless 0 -q:v 75 -loop 0 -preset default -an -vsync 0 public/assets/images/logo-adasi.webp
   ```
   *(Atau via Python Pillow: konversi tiap frame ke `RGB` di atas latar `#FFFFFF`, resize `272×108` `LANCZOS`, simpan `format='WEBP', save_all=True, lossless=False, quality=75, method=6, minimize_size=True` dengan tetap menjaga total durasi `5000ms` dan `loop=0`.)*

---

## 4. Guardrails Perbaikan (Wajib Dijaga)

1. **OAuth2 / PKCE & CSRF Integrity:** Jangan mengubah route `oauth/authorize`, `login.store`, `sso/callback`, hidden `@csrf`, atribut `name="email"`, `name="password"`, `name="remember"`, maupun integrasi Cloudflare Turnstile.
2. **CSP & SRI Compliance:**
   - Pertahankan SHA-384 `integrity` dan `crossorigin="anonymous"` pada seluruh aset CDN (`sweetalert2@11.7.32`).
   - Pastikan inline `<script data-adasi-i18n-runtime>` tetap menerima atribut `nonce` otomatis dari middleware CSP dan tetap berada sebelum `<link>` di ketiga layout (`auth`, `app`, `guest`).
3. **Zero Regression pada Dashboard / Internal (`layouts/app.blade.php`):**
   - Jangan mengubah atau menghapus Bootstrap 5, DataTables opt-in, SweetAlert2, `app.css`, maupun `app.js` pada `layouts/app.blade.php`.
   - Jangan menyentuh perubahan uncommitted pre-existing milik `modern-web-guidance` (seperti `splitter.js`, `dom-sanitize.js`, `modern-web-guidance-guardrails.test.mjs`).
4. **Kompatibilitas cPanel (Apache + PHP 8.3 + Linux Case-Sensitive):**
   - Pertahankan seluruh aturan `mod_rewrite` Laravel dan blok proteksi dotfiles di `public/.htaccess`.
   - Semua path file di `/assets/` dan `/build/` wajib menggunakan huruf kecil konsisten dan diverifikasi eksistensinya di disk.
5. **Single-Process PHPUnit Safety:** Jangan menjalankan beberapa perintah `php artisan test` secara paralel terhadap database testing `adasi_portal_test`.

---

## 5. Checklist Verifikasi (*Definition of Done*)

Remediasi dianggap **SELESAI** hanya jika seluruh pemeriksaan di bawah ini dieksekusi dan lulus:

- [ ] **Build & Bundle Budget:**
  - `npm.cmd run build` exit code `0` tanpa warning Rollup/PostCSS baru.
  - Inspeksi `public/build/manifest.json`:
    - `resources/css/auth.css` output `< 66 KiB` raw (`< 12 KiB` gzip).
    - `resources/js/auth.js` static import count `≤ 3` file (termasuk `auth.js` & `portal-core.js`), **0** chunk mikro `< 500 B` (tidak ada lagi `_i18n` 74 B), dan **0** dynamic import waterfall saat `/oauth/authorize` dimuat.
    - `resources/js/app.js` static import count turun dari `14` menjadi `≤ 6` file dengan total gzip lebih rendah dari kondisi pra-remediasi (`74.5 KiB` gz).
- [ ] **Compiled CSS Verification:**
  - `auth-*.css` mengandung aturan side-specific border-style (termasuk `.tw\:border-s-4` dengan `border-inline-start-style:solid`, tanpa shorthand `border-style:solid` pada `.tw\:border-t`/`.tw\:border-b`/`.tw\:border-e`).
  - `auth-*.css` tidak mengandung `.ui-dialog-open{overflow:hidden...}` dan tidak mengandung `@font-face` Cyrillic/Greek/Vietnamese.
- [ ] **Automated Test Suites (100% GREEN):**
  - `npm.cmd run test:js` — seluruh test (`≥ 866` test) **PASS**, termasuk `tailwind-build.test.mjs`, `auth-splash.test.mjs`, `modern-web-guidance-guardrails.test.mjs`, dan test baru retry `auth-alert-bridge`.
  - `php artisan test tests/Feature/SurfaceHierarchyTest.php tests/Feature/FrontendAssetLoadingTest.php tests/Feature/Auth/AuthSplashTest.php tests/Feature/Auth/SsoAuthorizationFlowTest.php tests/Feature/Auth/AuthenticationTest.php tests/Feature/Auth/PasswordResetTest.php tests/Feature/Auth/TwoFactorAuthenticationTest.php tests/Feature/Auth/PasswordConfirmationTest.php` — **0 failed**.
  - `php -l` pada seluruh file PHP yang diubah: `No syntax errors detected`.
- [ ] **Rendered HTML & Security Verification (`/oauth/authorize`):**
  - Respons `GET /oauth/authorize?...` (unauthenticated) memiliki:
    - 0 tag `bootstrap.min.css`, `bootstrap.bundle.min.js`, `sweetalert2`, `adasi-alert.css`, `adasi-alert.js`, `app-*.css`, `app-*.js`, `data-auth-splash`, `data-splash-*`, dan `ui-page-fade` pada `.auth-card`.
    - `<script nonce="..." data-adasi-i18n-runtime>` hadir sebelum `<link>`.
    - `<picture>` desktop background memakai `media="(min-width: 1024px)"` untuk AVIF/WebP/JPEG dan `data:image/gif` pada `<img>` (0 celah `0.02px`).
- [ ] **Post-Deploy cPanel Verification (Staging/Production):**
  - `curl -sI -H "Accept-Encoding: br, gzip" https://adasi-supplierportal.my.id/assets/js/adasi-alert.js` mengembalikan tepat satu `Vary: Accept-Encoding` (tanpa duplikasi) dan `Cache-Control: public, max-age=86400, must-revalidate`.
  - `curl -sI https://adasi-supplierportal.my.id/assets/images/adasi-login-bg.avif` mengembalikan HTTP `200`, `Content-Type: image/avif`, tanpa `Vary: Accept-Encoding`.
  - PageSpeed Insights ulang (Mobile & Desktop) mengonfirmasi Mobile LCP `< 2.5s`, *Element Render Delay* `< 500ms`, CLS `0`, dan 0 *Forced Reflow*.
