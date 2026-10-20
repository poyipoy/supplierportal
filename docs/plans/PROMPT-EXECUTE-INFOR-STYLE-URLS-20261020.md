# Prompt Eksekusi (One-Shot) — URL Halaman Gaya Infor

> Salin seluruh blok di bawah ini ke Claude Code di proyek lokal. Prompt ini ditulis lengkap agar agent menyelesaikan pekerjaan dari awal sampai akhir tanpa berhenti untuk bertanya.

---

```text
Kamu mengimplementasikan fitur "URL halaman gaya Infor" di ADASI Portal Supplier: prefix path dari env (default v2/ADASI_PRD), Hashids 24 karakter dengan masa transisi, dan page registry UUID untuk link menu. Kerjakan SAMPAI SELESAI dalam satu sesi. Rencana sudah disetujui, jadi jangan berhenti untuk minta persetujuan. Berhenti dan tanya HANYA bila terjadi salah satu kondisi di bagian "STOP CONDITIONS".

## 0. Wajib dibaca dulu (berurutan)
1. AGENTS.md (seluruhnya) beserta file yang dirujuknya untuk inisialisasi konteks.
2. docs/plans/IMPLEMENTATION-PLAN-INFOR-STYLE-URLS-20261020.md. Ini SPESIFIKASI UTAMA. Ikuti flag (§2), Phase 0/B/A/C1 (§3–§6), invarian (§7), tes (§9), urutan commit (§10), dan kriteria penerimaan (§11).
3. reports/URL halaman gaya Infor di portal.md dan research_notes/URL halaman gaya Infor di portal/adasi_impact_and_design.md. Keduanya konteks dan daftar hotspot, bukan spesifikasi.
4. Kode yang disentuh beserta tesnya:
   - routes/web.php (seluruhnya), bootstrap/app.php, config/hashids.php
   - app/Traits/HasHashids.php
   - app/Http/Middleware/{DecodeHashids,EnsureSsoSession,EnforceSupplierDomain}.php
   - app/Services/Auth/TwoFactorService.php, app/Services/Auth/Sso/SsoTransactionStore.php
   - app/Support/{PurchasingNavigation,ServerTabsResponse}.php
   - app/Services/NotificationUrlResolver.php, app/Providers/AppServiceProvider.php
   - resources/views/layouts/app.blade.php, resources/views/partials/sidebar.blade.php
   - config/quick_access.php, app/Services/QuickAccessService.php
   - public/assets/js/async-export.js, resources/js/server-tabs.js
   - tests/Feature/HashidUrlSecurityTest.php, tests/Feature/Auth/SsoAuthorizationFlowTest.php
Nomor baris di rencana adalah perkiraan dari master 255a9fd. Cocokkan dengan kode terbaru. Bila berbeda, kode/migrasi/tes yang menang; sesuaikan dan catat di laporan akhir.

## 1. Persiapan
- `git status`. Pertahankan perubahan user yang sudah ada; jangan reset/clean/stash milik user.
- `git checkout master && git pull`, lalu buat branch `feature/infor-style-urls`.
- Pastikan database tes aman: `php artisan test --filter=TestingEnvironmentDatabaseSafetyTest`.
- Rekam baseline sebelum mengubah apa pun dan simpan hasilnya untuk dibandingkan:
  `php artisan test --filter=HashidUrlSecurityTest`
  `php artisan test tests/Feature/Auth --compact`
  `php artisan test --filter=ServerTabs`
  `node --test tests/js/server-tabs.test.mjs`

## 2. Verifikasi API vendor (jangan mengandalkan ingatan)
Baca source di vendor/ untuk versi yang terpasang:
- Laravel routing:
  - apakah Route::fallback hanya mendaftarkan GET/HEAD;
  - cara mencocokkan path ke route (RouteCollection::match pada Request sintetis);
  - perilaku Route::prefix bersama route:cache;
  - urutan route statis vs parameter;
  - whereUuid.
- vinkla/hashids dan hashids/hashids:
  - cara mendefinisikan dan memanggil koneksi kedua (Hashids::connection('long'));
  - perilaku decode yang melakukan re-encode/validasi.
- Mekanisme tes yang mengubah config sebelum route terdaftar. Cari pola yang sudah ada di tests/ untuk me-refresh route dengan config berbeda. Bila tidak ada, buat helper tes kecil yang memuat ulang routes/web.php setelah config()->set(...).
Catat temuan yang berbeda dari asumsi rencana (§12 / bagian asumsi).

## 3. Implementasi (urutan wajib: Phase 0 → B → A → C1 → dokumentasi; satu commit per phase, pesan commit sesuai §10 rencana)
Ikuti §3–§6 rencana secara persis. Aturan keras:
- SEMUA flag default false (.env.example dan lingkungan tes). Saat semua flag off, perilaku dan seluruh tes lama harus identik. Tes lama tidak boleh diubah kecuali benar-benar menguji path literal yang dipindahkan Phase 0; jelaskan setiap perubahan tes lama di laporan.
- Nama route TIDAK berubah. Jangan memakai parameter {tenant}; prefix adalah string tetap dari config.
- Tetap di root: /, login, login.store, two-factor-challenge*, logout, forgot-password, confirm-password, password.update, oauth/*, sso/callback, verify-receipt/*, registrasi supplier publik, locale/*, up, /oauth/token. Verifikasi daftar ini terhadap routes/web.php yang sebenarnya.
- Validasi prefix saat boot: regex ^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+){0,3}$, maksimal 64 karakter, segmen pertama tidak bentrok dengan route root maupun folder public/. Bila tidak valid → RuntimeException dengan pesan jelas.
- Kompatibilitas: 301 dua arah hanya untuk GET/HEAD, query dipertahankan, tidak pernah ke host lain. POST/PUT/PATCH/DELETE tidak di-redirect.
- HashidCodec menjadi satu-satunya pintu encode/decode:
  - decode selalu menerima kedua format (8 dan 24 karakter) dengan round-trip check;
  - GET/HEAD dengan hash non-kanonik → 301 ke hash kanonik;
  - string berisi digit saja → null/404;
  - jangan mengganti HASHIDS_SALT;
  - jangan menambah pageUuid ke HASHED_PARAM_KEYS;
  - HASHED_PARAM_KEYS dan PLAIN_ROUTE_PREFIXES tidak berubah.
- Guard panjang salt produksi: jangan memblokir boot bila itu bisa mematikan produksi yang sudah berjalan. Cukup log warning, kecuali kamu bisa memastikan salt produksi sudah memenuhi syarat (kamu tidak bisa, jadi pakai warning).
- JS tidak boleh menulis prefix sendiri. Prefix disuntikkan dari server (window.AdasiPortal.pathPrefix / atribut data).
- NotificationUrlResolver harus me-resolve URL lama (tanpa prefix / hash 8 karakter) tanpa menulis ulang data notifikasi di DB.
- UUID page registry dibuat SEKALI (v4), ditulis ke config/page_registry.php, dan di-commit. Jangan membangkitkannya saat runtime.
- Otorisasi tidak boleh bergantung pada prefix, panjang hash, atau UUID. Route registry hanya melakukan 302; middleware/policy route tujuan tetap menegakkan akses.
- Tidak ada migrasi, tidak ada package baru, dan tidak ada refactor di luar daftar file rencana.
- Teks UI baru (bila ada) wajib di lang/en dan lang/id. Logika tanggal mengikuti BusinessTime.
- JANGAN menjalankan migrate/migrate:fresh/rollback/db:wipe terhadap database aplikasi.

## 4. Tes yang wajib dibuat (lihat §9 rencana)
- tests/Unit/PortalPathTest.php
- tests/Unit/HashidCodecTest.php
- tests/Feature/PortalPathPrefixTest.php
- tests/Feature/LongHashidTransitionTest.php
- tests/Feature/PageRegistryTest.php
- tests/Feature/NotificationLegacyUrlTest.php
- tes JS untuk async-export.js, dengan dan tanpa prefix
Wajib mencakup:
- flag on/off;
- 301 dua arah untuk path dan hash;
- POST tidak di-redirect;
- raw integer 404;
- isolasi supplier tetap (hash milik supplier lain tetap ditolak);
- /sso/callback dan alur SSO tidak berubah saat prefix on;
- QR verify-receipt tetap di root;
- prefix tidak valid/bentrok menolak boot;
- konsistensi registry (route ada, UUID unik dan valid v4).

## 5. Verifikasi (jalankan semuanya, perbaiki sampai hijau)
php artisan test --filter=TestingEnvironmentDatabaseSafetyTest
php artisan test --filter=PortalPathTest
php artisan test --filter=HashidCodecTest
php artisan test --filter=PortalPathPrefixTest
php artisan test --filter=LongHashidTransitionTest
php artisan test --filter=PageRegistryTest
php artisan test --filter=NotificationLegacyUrlTest
php artisan test --filter=HashidUrlSecurityTest
php artisan test --filter=SupplierDataIsolationTest
php artisan test tests/Feature/LocalInvoice/LocalInvoiceScopeIsolationTest.php
php artisan test tests/Feature/Auth --compact
php artisan test --filter=SsoAuthorizationFlowTest
php artisan test --filter=ServerTabs
php artisan test --filter=AsyncExportQueueTest
php artisan test --filter=AdvancedExport
php artisan test tests/Unit/TranslationParityTest.php
php artisan test --filter=BusinessTimeGuardTest
php artisan test --filter=FrontendAssetLoadingTest
node --test tests/js/server-tabs.test.mjs
node --test <tes JS async-export baru>
node --test tests/js/advanced-export-format.test.mjs
php -l <setiap file PHP yang diubah/dibuat>
php vendor/bin/pint --test <setiap file PHP yang diubah/dibuat>
php artisan view:cache
npm.cmd run build
git diff --check
Uji route cache untuk dua kondisi:
- flag off: php artisan route:cache && php artisan route:list --no-ansi | findstr /I "sso/callback verify-receipt" && php artisan route:clear
- prefix on (set env sementara): ulangi, lalu pastikan route terautentikasi berprefix dan route root tetap di root. Kembalikan env, lalu jalankan route:clear dan config:clear.
Setelah semua di atas hijau, jalankan `composer test` sekali secara serial (jangan paralel) dan bandingkan dengan baseline. Kegagalan hanya boleh disebut pre-existing bila juga gagal di baseline atau di checkout bersih master.

Simulasi manual di browser (Laragon):
1. Set di .env lokal: PORTAL_LONG_HASHIDS_ENABLED=true, PORTAL_PATH_PREFIX_ENABLED=true, PORTAL_PATH_PREFIX=v2/ADASI_PRD, PORTAL_PAGE_REGISTRY_ENABLED=true. Jalankan config:clear dan route:clear.
2. Login (SSO bila aktif), lalu buka dashboard, daftar PO, detail PO, invoice lokal, notifikasi, ekspor async, dan server tabs.
3. Cek URL: berbentuk /v2/ADASI_PRD/... dengan hash 24 karakter, dan menu berbentuk /v2/ADASI_PRD/<uuid>.
4. Buka URL lama (/purchasing/purchase-orders/<hash 8>): harus 301 ke bentuk baru.
5. Kembalikan semua flag ke false, lalu config:clear dan route:clear.

## 6. Dokumentasi
- docs/guides/INFOR-STYLE-URLS.md, isinya:
  - tujuan dan contoh URL;
  - env/flag dan aturan prefix;
  - urutan aktivasi;
  - config:cache dan route:cache;
  - rollback;
  - larangan mengganti HASHIDS_SALT;
  - cara menambah halaman ke registry;
  - troubleshooting 301/404.
- AGENTS.md:
  - perbarui bagian Hashids pada URL (codec, dua format, 301 kanonik);
  - perbarui bagian Definisi Route (prefix dari env, daftar route root);
  - tambahkan tes baru ke tabel coverage.
  Tulis satu kali saja, tanpa duplikasi, dan jangan mengubah CLAUDE.md.
- Ubah status di docs/plans/IMPLEMENTATION-PLAN-INFOR-STYLE-URLS-20261020.md menjadi "diimplementasikan".
- Tulis laporan hasil ke docs/results/INFOR-STYLE-URLS-RESULT-<tanggal>.md.

## 7. Commit
Commit per phase di branch feature/infor-style-urls (lihat §10 rencana). JANGAN push dan JANGAN membuat PR; user akan me-review dulu.

## STOP CONDITIONS (hanya ini yang boleh membuatmu berhenti dan bertanya)
- Database tes tidak aman atau tidak tersedia.
- API Laravel/vinkla yang terpasang membuat desain tidak mungkin dijalankan (mis. koneksi hashids kedua tidak didukung) dan tidak ada alternatif setara tanpa package baru atau migrasi.
- Mempertahankan /sso/callback, QR receipt, atau isolasi supplier tidak bisa dilakukan bersamaan dengan prefix.
- Perubahan menuntut modifikasi logika bisnis domain (Impor, Local Supplier, payment), bukan sekadar pembentukan URL.
Untuk hambatan lain, pilih opsi yang paling aman dan paling kecil, lalu catat di laporan.

## 8. Laporan akhir (format wajib)
1. Ringkasan: yang dibangun, contoh URL sebelum dan sesudah, cara mengaktifkan setiap flag.
2. Temuan verifikasi API vendor dan perbedaan dari rencana.
3. Daftar file dibuat/diubah, dikelompokkan per phase.
4. Hasil verifikasi, dipisah menjadi: diverifikasi lewat eksekusi (dengan output ringkas), diinspeksi, inferensi, dan belum diverifikasi (browser lintas role, staging, produksi, proxy/WAF).
5. Perbandingan dengan baseline tes.
6. Keputusan yang diambil sendiri beserta alasannya, termasuk setiap tes lama yang diubah.
7. Risiko tersisa dan langkah deploy/aktivasi untuk user.
```
