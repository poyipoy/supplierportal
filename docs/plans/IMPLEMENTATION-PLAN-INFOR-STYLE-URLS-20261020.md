# Implementation Plan — URL Halaman Gaya Infor (Prefix Path, Hashids Panjang, Page Registry)

> Tanggal: 20 Oktober 2026 · Status: **disetujui, belum diimplementasikan**
>
> Agent pelaksana wajib membaca `AGENTS.md` lebih dulu dan mencocokkan rencana ini dengan kode terbaru di `master`. Bila ada perbedaan, **kode, migrasi, dan tes yang menang**; laporkan perbedaannya.
>
> Riset pendukung: [`reports/URL halaman gaya Infor di portal.md`](../../reports/URL%20halaman%20gaya%20Infor%20di%20portal.md), beserta catatan di `research_notes/URL halaman gaya Infor di portal/` (terutama `adasi_impact_and_design.md`).

## 1. Context

Pemilik produk ingin URL setiap halaman portal setelah login tampak seperti Infor OS Portal (`…/v2/<TENANT>_PRD/…`). Seluruhnya dikerjakan di dalam aplikasi Laravel, tanpa layanan pihak ketiga. Login gaya Infor (OAuth 2.0 + PKCE in-app) sudah ada di `master`.

**Ini perubahan kosmetik dan konsistensi UX, bukan kontrol keamanan.** Perlindungan data tetap datang dari middleware role/scope, policy, filter `supplier_id`, dan `EnforceSupplierDomain`. Tidak ada otorisasi yang boleh bergantung pada prefix, panjang hashid, atau UUID halaman.

### Hasil akhir (ilustrasi)

| | Sekarang | Sesudah (semua flag aktif) |
|---|---|---|
| Halaman record | `/purchasing/purchase-orders/xK9mQ2pL` | `/v2/ADASI_PRD/purchasing/purchase-orders/Rk7wQ2mZp9LxV3nB8cTyH4dJ` |
| Dashboard | `/purchasing/dashboard` | `/v2/ADASI_PRD/purchasing/dashboard` |
| Link menu | `/purchasing/purchase-orders` | `/v2/ADASI_PRD/3f6c2a9e-8b1d-4c7a-9e2f-5a6b7c8d9e0f` → 302 ke halaman tujuan |
| Bookmark lama | `/purchasing/purchase-orders/xK9mQ2pL` | 301 → URL berprefix dengan hash 24 karakter |
| Login / SSO / QR receipt | `/login`, `/oauth/authorize`, `/sso/callback`, `/verify-receipt/…` | **tidak berubah** |

### Keputusan
- Prefix dapat dikonfigurasi lewat env `PORTAL_PATH_PREFIX` (default `v2/ADASI_PRD`), divalidasi saat boot.
- Cakupan: Phase 0, B, A, dan C1.
- **Di luar cakupan:** C2 (halaman daftar tinggal langsung di URL UUID) dan D (`?ctx=` di setiap halaman, ditolak oleh riset).
- Tanpa migrasi database dan tanpa package baru.

## 2. Flag dan konfigurasi

File baru `config/portal_urls.php`:

| Key | Env | Default | Fungsi |
|---|---|---|---|
| `long_hashids.enabled` | `PORTAL_LONG_HASHIDS_ENABLED` | `false` | Hashid baru 24 karakter, hash lama 8 karakter tetap diterima |
| `long_hashids.length` | `PORTAL_LONG_HASHIDS_LENGTH` | `24` | Panjang hashid baru (16–40) |
| `prefix.enabled` | `PORTAL_PATH_PREFIX_ENABLED` | `false` | Aktifkan prefix pada route terautentikasi |
| `prefix.value` | `PORTAL_PATH_PREFIX` | `v2/ADASI_PRD` | Teks prefix, bebas diatur sesuai aturan validasi |
| `page_registry.enabled` | `PORTAL_PAGE_REGISTRY_ENABLED` | `false` | Link menu memakai URL UUID |

- Semua flag `false` di `.env.example` dan di lingkungan tes, sehingga ±287 request dengan path literal di tes lama tidak berubah.
- Tambahkan placeholder ke `.env.example` beserta komentar singkat.
- Setiap perubahan env di server wajib diikuti `php artisan config:cache` dan `php artisan route:cache`.

**Validasi prefix** (helper `App\Support\PortalPath`), dijalankan di boot untuk semua environment ketika `prefix.enabled = true`:
- Regex `^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+){0,3}$`, maksimal 64 karakter, tanpa `/` di awal atau akhir.
- Segmen pertama tidak boleh sama (case-insensitive) dengan segmen root yang dipertahankan: `login`, `logout`, `two-factor-challenge`, `forgot-password`, `confirm-password`, `password`, `oauth`, `sso`, `verify-receipt`, `supplier`, `locale`, `up`, `storage`, `build`, `assets`, `vendor`, `favicon.ico`, `robots.txt`. Ambil daftar final dari route root yang sebenarnya, ditambah folder di `public/`.
- Bila tidak valid: lempar `RuntimeException` dengan pesan jelas, mengikuti pola guard produksi di `AppServiceProvider`.

## 3. Phase 0 — Perbaikan pemakaian path literal (wajib sebelum Phase A)

Riset menemukan bahwa URL dibangkitkan hampir sepenuhnya lewat nama route (±1.098 `route()`, tanpa `action()`). Jadi prefix dan hashid panjang akan mengalir otomatis. Masalahnya ada pada kode yang **mencocokkan atau menulis path literal**. Hotspot berikut harus diverifikasi ulang dengan grep karena nomor barisnya perkiraan dari `master` 255a9fd:

| Lokasi | Masalah | Perbaikan |
|---|---|---|
| `bootstrap/app.php` ≈L126-127 | `$request->is('notifications/unread-count', …)` | `routeIs(...)` nama route, atau `is()` dengan pola dari `PortalPath::pattern()` |
| `app/Http/Middleware/EnsureSsoSession.php` ≈L44 | `is()` path literal untuk polling | idem |
| `app/Services/Auth/TwoFactorService::safeIntendedUrl` ≈L341-356 | `fnmatch` mengasumsikan path dimulai di root | Hapus prefix aktif sebelum mencocokkan pola, atau bangun pola dari prefix |
| `app/Support/PurchasingNavigation.php` ≈L206-221 | regex `^/purchasing` | Bangun regex dari `PortalPath::path('purchasing')` / `preg_quote` |
| `public/assets/js/async-export.js` ≈L27-38, ≈L1006 | regex `^\/(purchasing\|supplier\|qc)\/export\/` | Terima prefix dari server (`window.AdasiPortal.pathPrefix` atau atribut data), lalu buang prefix sebelum mencocokkan |
| `resources/views/layouts/app.blade.php` ≈L545-546 | `startsWith('/purchasing/')` | Pakai prefix dari server |
| `resources/views/layouts/app.blade.php` ≈L798 | `url('/notifications/__ID__/read')` | `route('notifications.read', '__ID__')` atau setara (cek nama route) |
| `resources/views/purchasing/po/show.blade.php` ≈L1040 | PUT `'/purchasing/po-documents/'` | `route(...)` dengan placeholder |
| `tests/Feature/HashidUrlSecurityTest.php` ≈L239-245 | assert path literal | Tetap valid saat flag off; tambah varian flag on di tes baru |

Lakukan grep menyeluruh untuk menemukan sisa pola, minimal: `is('`, `->is(`, `fnmatch(`, `'/purchasing`, `'/supplier`, `'/local-supplier`, `'/finance`, `'/qc`, `'/ga`, `'/admin`, `'/accounting`, `'/notifications`, `'/profile`, `url('/` di `app/`, `bootstrap/`, `resources/`, dan `public/assets/js/`.

Prinsip:
- JS tidak menulis prefix sendiri; prefix selalu disuntikkan dari server.
- `window.AdasiPortal.pathPrefix` diisi di `layouts.app` (string kosong bila flag off).
- Phase 0 tidak boleh mengubah perilaku apa pun saat semua flag off.

## 4. Phase B — Hashids 24 karakter dengan masa transisi

**Masalah:** library hashids melakukan re-encode saat decode, dan aplikasi menambah round-trip check di `HasHashids` (≈L45-49) serta `DecodeHashids` (≈L111-126). Karena itu, menaikkan `length` saja langsung membuat semua hash 8 karakter lama menjadi 404, termasuk di bookmark, notifikasi tersimpan, dan filter preset ekspor.

**Desain:**
- `config/hashids.php`:
  - koneksi `main` dipertahankan apa adanya (salt global, `HASHIDS_LENGTH` default 8). Inilah format **pendek**.
  - koneksi baru `long`: salt dan alfabet sama, `length` = `PORTAL_LONG_HASHIDS_LENGTH` (24).
- Helper baru `App\Support\HashidCodec` sebagai satu-satunya pintu encode/decode:
  - `encode(int $id): string` memakai `long` bila `long_hashids.enabled`, selain itu `main`.
  - `decode(string $hash): ?int` **selalu** mencoba kedua koneksi (yang aktif dulu), masing-masing dengan round-trip check. Dengan begitu hash pendek tetap diterima saat flag on, dan hash panjang tetap diterima saat flag dimatikan (rollback aman dua arah). String yang hanya berisi digit selalu `null`.
  - `isCanonical(string $hash): bool`: `true` bila hash memakai format yang sedang aktif.
- Titik integrasi:
  - `app/Traits/HasHashids.php` (`getRouteKey`, `resolveRouteBinding`, accessor `hash`): pakai codec.
  - `app/Http/Middleware/DecodeHashids.php`: decode lewat codec. Bila **GET/HEAD** dan parameter tidak kanonik (`! isCanonical`), kirim **301** ke URL kanonik (hash format aktif, query dipertahankan). Metode lain menerima hash non-kanonik tanpa redirect agar form lama tidak gagal. Hal yang sama berlaku untuk implicit binding: `HasHashids::resolveRouteBinding` menerima kedua format, sedangkan redirect kanonik cukup ditangani di satu tempat (middleware) untuk semua parameter hashed.
  - `FinanceDrpController` ≈L249 saat ini decode tanpa round-trip; samakan dengan codec.
  - Semua pemanggil langsung `Hashids::` / `Vinkla\Hashids` (±21 tempat; cari dengan `grep -rn "Hashids::" app`) dimigrasikan ke codec.
  - `NotificationUrlResolver` dan filter preset ekspor yang menyimpan hash: decode lewat codec, sehingga hash lama tetap resolve.
- **Tidak berubah:**
  - raw integer → 404;
  - `HASHED_PARAM_KEYS` dan `PLAIN_ROUTE_PREFIXES`;
  - salt global (jangan ganti salt; riset tidak menyarankan salt per model karena parameter `{id}` dipakai lintas model).
- Hardening tambahan berbiaya kecil: guard produksi di `AppServiceProvider` menolak `HASHIDS_SALT` yang lebih pendek dari 32 karakter. **Jangan** aktifkan ini bila salt produksi saat ini lebih pendek; jadikan peringatan log dan sebutkan di laporan. Mengganti salt merusak semua URL.

## 5. Phase A — Prefix path dari env

- `App\Support\PortalPath`:
  - `enabled()`;
  - `prefix()`: string tervalidasi, atau `''` bila off;
  - `path(string $p)`;
  - `strip(string $path)`: buang prefix aktif dari path;
  - `assertValid()`.
- `routes/web.php`: bungkus **semua group terautentikasi** (semua role, profile, notifikasi, konversasi, ekspor, attachment, dashboard redirect, dll.) dengan `Route::prefix(PortalPath::prefix())`. **Gunakan string tetap, bukan parameter `{tenant}`.** Parameter tambahan akan menggeser argumen posisi di ±198 route berparameter dan memerlukan `URL::defaults` serta penyesuaian urutan binding.
- **Tetap di root (tidak diberi prefix):**
  - `/` (redirect ke login atau dashboard);
  - `login`, `login.store`, `two-factor-challenge*`, `logout`, `forgot-password`, `confirm-password`, `password.update`;
  - `oauth/*` dan **`sso/callback`**: `redirect_uri` tersimpan di client Passport dan harus sama persis; `SsoTransactionStore` juga memeriksa segmen pertama;
  - `verify-receipt/*`: QR receipt yang sudah tercetak harus tetap valid;
  - registrasi supplier publik (`supplier/register*`, `supplier/registration/*`);
  - `locale/*`, `up`, `/oauth/token`.

  Verifikasi daftar ini terhadap `routes/web.php` yang sebenarnya. Route yang memakai middleware `guest` atau publik tetap di root.
- **Nama route tidak berubah.** Semua `route('…')` otomatis menghasilkan URL berprefix.
- **Kompatibilitas 301 (dua arah):**
  - Route fallback GET/HEAD (verifikasi di vendor bahwa `Route::fallback` hanya GET/HEAD; bila tidak, batasi manual).
  - Bila prefix aktif dan path request tanpa prefix cocok dengan route berprefix: buat request sintetis `/<prefix>/<path>`, cocokkan dengan `Route::getRoutes()->match()`, lalu 301 ke URL tersebut dengan query dipertahankan.
  - Bila prefix **nonaktif** dan path diawali prefix yang dikenal (simpan daftar prefix lama di config `prefix.legacy_values`, default `['v2/ADASI_PRD']`): buang prefix lalu 301. Dengan begitu mematikan flag tetap aman.
  - Selain itu 404 seperti biasa. Jangan pernah redirect ke host lain.
- `NotificationUrlResolver`: path tersimpan tanpa prefix (atau dengan prefix lama) harus di-normalisasi lewat `PortalPath::strip()` lalu dicocokkan, sehingga tetap resolve ke route yang benar, bukan jatuh ke dashboard. Jangan menulis ulang data notifikasi di database.
- `TwoFactorService::safeIntendedUrl` dan `SsoTransactionStore` harus menerima intended URL berprefix. Pola blokir (`notifications/*`, `exports/*`, dll.) dicocokkan setelah `PortalPath::strip()`.
- `ServerTabsResponse`, `PurchasingNavigation::LIST_ROUTES`, dan list-URL memory sudah berbasis route; pastikan tetap jalan dengan prefix.
- Uji `php artisan route:cache` dengan flag on dan off.

## 6. Phase C1 — Page registry untuk link menu

- `config/page_registry.php`: map `uuid => route name` untuk setiap item menu di `resources/views/partials/sidebar.blade.php` (±55 `route()`) dan `config/quick_access.php` (±65 entri).
  - UUID v4 dibuat **sekali** dan di-commit. Jangan dibuat ulang saat runtime; UUID adalah identitas permanen halaman.
  - Route tanpa parameter wajib saja yang masuk registry.
- Route baru `GET /<prefix>/{pageUuid}`:
  - nama `portal.page`, constraint `whereUuid('pageUuid')`;
  - didaftarkan setelah route statis berprefix lainnya;
  - middleware `auth`.
- Controller `PortalPageController`:
  - UUID tidak ada di registry → 404;
  - ada → **302** ke `route($name)`. Middleware dan policy route tujuan tetap menegakkan akses; jangan dispatch internal.
- Helper `App\Support\PageRegistry::url(string $routeName): string` mengembalikan URL UUID bila flag aktif dan route terdaftar, selain itu `route($routeName)`. Sidebar dan quick access memakai helper ini. `routeIs()` untuk state aktif menu tidak berubah, karena setelah 302 browser berada di route asli.
- `pageUuid` **tidak** dimasukkan ke `HASHED_PARAM_KEYS`. Pastikan `DecodeHashids` tidak memproses parameter ini.
- Tes konsistensi: setiap nama route di registry harus ada, UUID harus unik dan valid v4, dan tidak boleh ada route ganda.

## 7. Invarian yang tidak boleh berubah

- Nama route, policy/otorisasi, isolasi data supplier, dan boundary core/scope (`EnforceSupplierDomain`).
- `HASHED_PARAM_KEYS`, `PLAIN_ROUTE_PREFIXES`, dan raw integer → 404 (`HashidUrlSecurityTest`).
- `/sso/callback` dan seluruh alur SSO (`SsoAuthorizationFlowTest`).
- QR receipt bertanda tangan yang sudah tercetak.
- Semua perilaku saat **semua flag off** harus identik dengan sebelum perubahan.
- Business time, terjemahan en/id (teks baru, bila ada, di kedua locale), CSP/Referrer-Policy, dan `no-store`.
- Tidak ada migrasi, tidak ada package baru, dan tidak ada penulisan ulang data notifikasi.

## 8. Daftar file (perkiraan)

| File | Perubahan |
|---|---|
| `config/portal_urls.php`, `.env.example` | baru / placeholder flag |
| `config/hashids.php` | koneksi baru `long` (24 karakter), `main` tetap |
| `config/page_registry.php` | baru |
| `app/Support/PortalPath.php`, `app/Support/HashidCodec.php`, `app/Support/PageRegistry.php` | baru |
| `app/Http/Controllers/PortalPageController.php`, `app/Http/Controllers/PortalLegacyPathController.php` (atau closure fallback) | baru |
| `app/Traits/HasHashids.php`, `app/Http/Middleware/DecodeHashids.php` | pakai codec, 301 ke hash kanonik |
| `app/Providers/AppServiceProvider.php` | validasi prefix, guard salt |
| `routes/web.php` | group berprefix, fallback, route registry |
| `bootstrap/app.php`, `app/Http/Middleware/EnsureSsoSession.php`, `app/Services/Auth/TwoFactorService.php`, `app/Support/PurchasingNavigation.php`, `app/Services/NotificationUrlResolver.php` | Phase 0 / A |
| `resources/views/layouts/app.blade.php`, `resources/views/partials/sidebar.blade.php`, `resources/views/purchasing/po/show.blade.php` | Phase 0 / C1 |
| `public/assets/js/async-export.js` | Phase 0 |
| Pemanggil `Hashids::` (mis. `FinanceDrpController`) | codec |
| Tes baru (lihat §9) | baru |
| `docs/guides/INFOR-STYLE-URLS.md`, `AGENTS.md` | dokumentasi |

## 9. Tes

**Tes baru:**
- `tests/Unit/PortalPathTest.php`: validasi regex, panjang, segmen bentrok, `strip()`, `path()`.
- `tests/Unit/HashidCodecTest.php`: encode sesuai flag, decode kedua format saat flag on maupun off, round-trip, digit ditolak, `isCanonical()`.
- `tests/Feature/PortalPathPrefixTest.php` (flag on via `config()` + registrasi ulang route; ikuti pola tes yang ada untuk mengubah route):
  - route berprefix;
  - route root tetap di root;
  - `/sso/callback` tidak berubah;
  - path lama 301 ke berprefix dengan query dipertahankan;
  - prefix lama 301 saat flag off;
  - path tidak dikenal 404;
  - POST ke path lama tidak di-redirect.
- `tests/Feature/LongHashidTransitionTest.php`:
  - URL baru berisi hash 24 karakter;
  - GET hash 8 karakter → 301 kanonik; saat flag off, GET hash 24 karakter → 301 ke hash 8 karakter;
  - POST dengan hash non-kanonik diterima tanpa redirect;
  - raw integer 404;
  - hash milik supplier lain tetap 403/404 sesuai policy.
- `tests/Feature/PageRegistryTest.php`:
  - konsistensi registry;
  - UUID → 302;
  - UUID asing 404;
  - user tanpa role ditolak oleh route tujuan;
  - sidebar memakai URL UUID saat flag on.
- `tests/Feature/NotificationLegacyUrlTest.php`: notifikasi dengan URL lama (tanpa prefix, hash 8 karakter) resolve ke route yang benar.
- Tes JS untuk `async-export.js` (pencocokan dengan dan tanpa prefix), mengikuti pola `tests/js/`.

**Suite yang harus tetap hijau:** `HashidUrlSecurityTest`, `SupplierDataIsolationTest`, `LocalInvoice/LocalInvoiceScopeIsolationTest`, `tests/Feature/Auth`, `SsoAuthorizationFlowTest`, `ServerTabsResponseTest`, `ServerTabsListUrlTest`, `AsyncExportQueueTest`, `AdvancedExport*Test`, `TranslationParityTest`, `BusinessTimeGuardTest`, `FrontendAssetLoadingTest`, `tests/js/server-tabs.test.mjs`, dan `tests/js/advanced-export-*.test.mjs`. Terakhir, jalankan full suite serial.

## 10. Urutan pengerjaan dan commit

1. Phase 0 → commit `refactor(urls): resolve literal path matching via route names and injected prefix`
2. Phase B → commit `feat(urls): long hashids with legacy transition`
3. Phase A → commit `feat(urls): configurable portal path prefix with 301 compatibility`
4. Phase C1 → commit `feat(urls): page registry UUID links for menus`
5. Dokumentasi → commit `docs(urls): guide and AGENTS.md update`

## 11. Kriteria penerimaan

- [ ] Semua flag off: seluruh tes lama lulus tanpa diubah, dan perilaku identik.
- [ ] Flag prefix on: setiap halaman terautentikasi berada di `/<PORTAL_PATH_PREFIX>/…`, sedangkan route root tetap di root.
- [ ] Prefix tidak valid atau bentrok → aplikasi menolak boot dengan pesan jelas.
- [ ] Flag hashid on: URL baru 24 karakter, URL lama 8 karakter (GET) → 301, raw integer → 404.
- [ ] Bookmark lama tanpa prefix → 301 ke URL berprefix; mematikan flag tidak merusak link berprefix.
- [ ] Link notifikasi lama tetap membuka record yang benar.
- [ ] Flag registry on: link menu berupa `/<prefix>/<uuid>` → 302 ke halaman; otorisasi tidak berubah.
- [ ] SSO login, QR receipt, registrasi supplier, dan ganti bahasa tetap berfungsi.
- [ ] `route:cache` berhasil dengan flag on dan off.
- [ ] Tidak ada migrasi dan tidak ada package baru.

## 12. Deploy dan rollback

- **Urutan aktivasi yang disarankan:**
  1. `PORTAL_LONG_HASHIDS_ENABLED=true`, pantau 301;
  2. `PORTAL_PATH_PREFIX_ENABLED=true`;
  3. `PORTAL_PAGE_REGISTRY_ENABLED=true`.

  Setiap langkah diikuti `php artisan config:cache && php artisan route:cache`, lalu uji di staging lebih dulu dengan prefix staging, misalnya `v2/ADASI_TST`.
- **Rollback:** set flag ke `false`, lalu `config:cache` dan `route:cache`. Link berprefix dan hash panjang tetap di-redirect atau diterima, karena codec selalu menerima kedua format dan `prefix.legacy_values` menyimpan prefix lama.
- **Jangan mengganti `HASHIDS_SALT`** setelah produksi berjalan.
