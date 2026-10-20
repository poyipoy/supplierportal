# Hasil implementasi URL gaya Infor — 20 Oktober 2026

Branch: `feature/infor-style-urls`, dari master `9eaa70a` sesudah `git pull --ff-only`. Status implementasi Phase 0/B/A/C1: selesai. Verifikasi akhir direkam pada bagian 4; batas cakupan browser, staging, dan produksi disebutkan secara terpisah.

## 1. Ringkasan dan aktivasi

Portal sekarang mendukung prefix tetap dari env (default `v2/ADASI_PRD`), Hashids panjang dengan transisi dua arah, dan 65 UUID v4 tetap untuk tautan sidebar/Quick Access. Contoh detail: `/purchasing/purchase-orders/<hash pendek>` → `/v2/ADASI_PRD/purchasing/purchase-orders/<hash panjang>`. Contoh menu PO: `/v2/ADASI_PRD/7c05c87f-d8ae-4f7d-8003-06b36d2ae324` → 302 ke daftar PO.

Aktifkan berurutan `PORTAL_LONG_HASHIDS_ENABLED=true`, `PORTAL_PATH_PREFIX_ENABLED=true`, lalu `PORTAL_PAGE_REGISTRY_ENABLED=true`; semua default `false`. `PORTAL_LONG_HASHIDS_LENGTH=24` mempunyai batas 16–40. Setiap tahap membutuhkan `config:cache` lalu `route:cache`. [Panduan operasional](../guides/INFOR-STYLE-URLS.md) mencakup rollback, prefix historis, registry, dan troubleshooting.

Tidak ada migrasi, package baru, perubahan salt, perubahan policy/role/scope, atau penulisan ulang notifikasi. UUID/prefix/padding bukan kontrol akses.

## 2. Verifikasi API vendor dan perbedaan terhadap rencana

Inspeksi vendor terpasang: Laravel 12.69.3, vinkla/hashids 13.0.0, hashids/hashids 5.0.2; runtime CLI PHP 8.2.30.

- `Router::fallback()` mendaftarkan GET, lalu constructor `Route` menambah HEAD. Metode mutasi tidak masuk fallback.
- `RouteCollection::match(Request)` dan `CompiledRouteCollection::match(Request)` tersedia; mencocokkan dan mengikat parameter scalar tanpa menjalankan controller/middleware tujuan. Hasil `isFallback` wajib ditolak supaya URL tidak dikenal tidak menimbulkan loop.
- Prefix diterapkan saat registrasi URI dan dibekukan dalam route cache. `route:cache` mem-boot aplikasi baru; pemeriksaan CLI memakai env proses sementara dan config cache, kemudian keduanya dibersihkan.
- Route statis tetap mendahului parameter; fallback ditempatkan terakhir oleh collection. `whereUuid()` memeriksa bentuk UUID, sehingga konsistensi config diuji dengan regex v4 tambahan.
- `Hashids::connection('long')` didukung melalui manager/factory. Manager menyimpan instance koneksi. Library decode melakukan re-encode; kedua koneksi diperlukan untuk menerima format pendek/panjang.
- `SubstituteBindings` berjalan sebelum middleware append proyek. `Route::originalParameters()` mempertahankan hash sebelum binding. Kanonikalisasi juga memeriksa bound model yang memakai `HasHashids`, termasuk nama parameter di luar allowlist seperti `attachment`, `referencePrice`, dan `supplierAudit`; allowlist tidak diperluas.
- Tidak ditemukan helper tes yang me-refresh seluruh web routes sesudah config berubah. Ditambahkan `ReloadsPortalRoutes`, yang mempertahankan health, OAuth token, dan broadcasting dari luar `web.php`.
- Fallback kompatibilitas tidak memakai group `web`: fallback hanya mencocokkan path, sehingga sesi SSO belum terverifikasi/forced-password-change tidak mendahului keputusan 301/404. Seluruh keamanan tujuan tetap berjalan pada permintaan berikutnya.
- Fallback GET juga memengaruhi pencarian metode alternatif Laravel: GET ke endpoint POST-only dapat berubah 405→404, sedangkan metode lain ke path tidak dikenal berubah 404→405 (OPTIONS dapat menjadi 200). Koreksi kompatibilitas mempertahankan 404/405/Allow dan OPTIONS melalui inspeksi route nonfallback, tanpa redirect atau menjalankan aksi mutasi.
- Prefix valid yang sama dengan segmen operasional memakai pencocokan kandidat untuk notifikasi/bookmark. Bila path juga merupakan route aktif, route aktif menang sebelum fallback; misalnya prefix `purchasing` membuat `/purchasing/dashboard` menjadi shared dashboard. Gunakan prefix khusus seperti `v2/ADASI_PRD` untuk menghindari ambiguitas ini.
- Catatan riset lama menyebut aksi keamanan profile tetap di root; spesifikasi utama meminta route profile terautentikasi mengikuti prefix kecuali daftar root eksplisit. Implementasi mengikuti spesifikasi utama. `/sso/callback`, QR receipt, dan seluruh endpoint root yang diwajibkan dipertahankan.

Referensi source vendor: `Illuminate/Routing/{Router,Route,RouteCollection,CompiledRouteCollection,RouteUrlGenerator}.php`, `Routing/Matching` dan `CreatesRegularExpressionRouteConstraints`, `Foundation/Console/RouteCacheCommand.php`, `vinkla/hashids/src/{HashidsManager,HashidsFactory}.php`, `graham-campbell/manager/src/AbstractManager.php`, dan `hashids/hashids/src/Hashids.php`.

## 3. File dan commit per phase

### Phase 0 - `9f7d432`

- `.env.example`
- `.env.testing.example`
- `app/Http/Middleware/EnsureSsoSession.php`
- `app/Services/Auth/TwoFactorService.php`
- `app/Support/PortalPath.php`
- `app/Support/PurchasingNavigation.php`
- `bootstrap/app.php`
- `config/portal_urls.php`
- `phpunit.xml`
- `public/assets/js/async-export.js`
- `resources/views/layouts/app.blade.php`
- `resources/views/purchasing/po/show.blade.php`
- `tests/Unit/PortalPathTest.php`
- `tests/js/async-export-prefix.test.mjs`

### Phase B - `1300a93`

- `app/Http/Controllers/Finance/FinanceDrpController.php`
- `app/Http/Controllers/Purchasing/PriceComparisonController.php`
- `app/Http/Controllers/Purchasing/PurchaseOrderController.php`
- `app/Http/Controllers/Supplier/SupplierPurchaseOrderController.php`
- `app/Http/Middleware/DecodeHashids.php`
- `app/Providers/AppServiceProvider.php`
- `app/Support/HashidCodec.php`
- `app/Traits/HasHashids.php`
- `config/hashids.php`
- `tests/Feature/LongHashidTransitionTest.php`
- `tests/Unit/HashidCodecTest.php`

### Phase A - `8173827`

- `app/Http/Controllers/PortalLegacyPathController.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/Auth/Sso/SsoTransactionStore.php`
- `app/Services/NotificationUrlResolver.php`
- `routes/web.php`
- `tests/Feature/NotificationLegacyUrlTest.php`
- `tests/Feature/PortalPathPrefixTest.php`
- `tests/Support/ReloadsPortalRoutes.php`

### Phase C1 - `3962760`

- `app/Http/Controllers/PortalPageController.php`
- `app/Services/QuickAccessService.php`
- `app/Support/PageRegistry.php`
- `config/page_registry.php`
- `resources/views/partials/sidebar.blade.php`
- `routes/web.php`
- `tests/Feature/PageRegistryTest.php`

### Koreksi hasil verifikasi - `91a11ca`

- `app/Http/Controllers/PortalLegacyPathController.php`
- `bootstrap/app.php`
- `routes/web.php`
- `tests/Feature/PortalPathPrefixTest.php`

Commit koreksi terpisah mempertahankan 404/405/OPTIONS sesudah regresi ditemukan dalam verifikasi akhir; urutan empat commit phase tetap dipertahankan.

### Koreksi rollback UUID - `113218e`

- `routes/web.php`
- `tests/Feature/PageRegistryTest.php`

Alias tetap terdaftar ketika pembentukan menu UUID dinonaktifkan, sehingga bookmark yang sudah dipublikasikan tetap berfungsi setelah rollback.

### Dokumentasi

- `docs/guides/INFOR-STYLE-URLS.md`
- `AGENTS.md`
- `docs/plans/IMPLEMENTATION-PLAN-INFOR-STYLE-URLS-20261020.md`
- `docs/results/INFOR-STYLE-URLS-RESULT-20261020.md`

`CLAUDE.md` tidak diubah.


## 4. Hasil verifikasi

### Diverifikasi lewat eksekusi

Seluruh perintah pada matriks berikut benar-benar dijalankan secara serial pada MySQL `adasi_portal_test`. Baris `auth` memakai pengulangan terakhir sesudah koreksi rollback; baris `prefix` memakai hasil pengulangan sesudah koreksi fallback. Sesudah koreksi rollback terbaru, seluruh tes URL juga diulang dalam satu filter gabungan, sebagaimana dicatat di bawah.

| Filter/suite | Hasil | Output ringkas |
|---|---|---|
| `safety` | PASS | Tests:    2 passed (4 assertions) / Duration: 1.46s |
| `portal-path` | PASS | Tests:    33 passed (81 assertions) / Duration: 4.43s |
| `hashid-codec` | PASS | Tests:    2 passed (84 assertions) / Duration: 1.04s |
| `prefix` | PASS | Tests:    12 passed (1442 assertions) / Duration: 22.99s |
| `hashid-transition` | PASS | Tests:    5 passed (68 assertions) / Duration: 17.68s |
| `registry` | PASS | 6 tes lulus dalam pengulangan gabungan terbaru; run awal 5 passed / 423 assertions |
| `notification` | PASS | Tests:    3 passed (28 assertions) / Duration: 40.68s |
| `hashid-security` | PASS | Tests:    6 passed (130 assertions) / Duration: 46.74s |
| `supplier-isolation` | PASS | Tests:    11 passed (13 assertions) / Duration: 24.49s |
| `local-isolation` | PASS | Tests:    30 passed (103 assertions) / Duration: 29.68s |
| `auth` | FAIL baseline | Tests:    1 failed, 319 passed (2637 assertions) / Duration: 73.44s |
| `sso` | PASS | Tests:    48 passed (555 assertions) / Duration: 32.24s |
| `server-tabs` | PASS | Tests:    24 passed (143 assertions) / Duration: 25.58s |
| `async-export` | PASS | Tests:    24 passed (382 assertions) / Duration: 25.67s |
| `advanced-export` | PASS | Tests:    81 passed (2704 assertions) / Duration: 743.18s |
| `translation` | PASS | Tests:    3 passed (40733 assertions) / Duration: 3.76s |
| `business-time` | PASS | Tests:    2 passed (2 assertions) / Duration: 1.22s |
| `assets` | PASS | Tests:    12 passed (169 assertions) / Duration: 18.13s |

- PHP lint: 33 file changed/new, PASS. Scoped Pint `--test`: PASS.
- Node: 64 passed, mencakup server tabs, async-export-prefix, serta seluruh advanced-export JS tests.
- `php artisan view:cache`, `npm.cmd run build`, dan `git diff --check`: PASS.
- Config cache + route cache flag off/on: PASS. HTTP pada collection hasil cache memverifikasi 404/405/Allow, OPTIONS, dan alias301 dengan query; nol query SQL pada smoke ini. Sesudahnya cache route/config dibersihkan.
- Kontrak 426 route lama tetap identik ketika flag off (method/URI/nama; nama internal `generated::*` pada route anonim dinormalisasi). Endpoint baru adalah alias UUID serta fallback kompatibilitas GET/HEAD dan OPTIONS.
- Benchmark export: 50.000 baris CSV, 100 chunks, 666,51 detik untuk dispatch/generasi terukur, peak PHP 234 MiB; berada di bawah budget tes existing 512 MiB. Ini pengukuran fixture lokal, bukan SLA produksi.

### Browser lokal yang dieksekusi

Konektor Chrome DevTools tersedia meskipun discovery Computer Use awal tidak menemukan browser. Pemeriksaan dilakukan pada Laragon dengan akun seed lokal Purchasing, Finance, dan Supplier (scope lokal), dalam konteks browser terisolasi. Tidak ada password/token/PII yang disimpan pada evidence browser.

- Login SSO Purchasing berakhir pada dashboard berprefix; menu sidebar/Quick Access memakai UUID dan membuka daftar PO sebenarnya.
- Daftar dan detail PO tampil; ID detail 24 karakter. Bookmark tanpa prefix/hash pendek mengikuti redirect ke detail panjang yang tepat dan mempertahankan query.
- Server Tabs Supplier Audit mengganti antrean ke `?queue=late`; active tab, URL History, dan selesai loading diamati pada DOM browser.
- Panel notifikasi dimuat dari endpoint berprefix; klik satu notifikasi membuka halaman Security berprefix. Route indeks notifikasi memang redirect ke dashboard menurut controller existing.
- Advanced Export dari browser menerima 202; polling status berprefix menerima 200. Antrean lokal awal berisi satu job ekspor baru ini; worker `exports --stop-when-empty` menyelesaikan chain, status menjadi `completed`, dan unduhan CSV berprefix menerima 200.
- Finance membuka daftar dan detail invoice lokal dengan hash 24 karakter; Supplier membuka invoice lokal miliknya. Percobaan GET Supplier ke invoice lain menerima 403.
- `.env` lokal diaktifkan sementara untuk empat flag/nilai URL, kemudian dikembalikan byte-for-byte. Runtime akhir: tiga flag `false`; config/route cache dibersihkan. Reload detail panjang berprefix berhasil kembali ke path tanpa prefix/hash 8 karakter; menu tidak lagi memakai UUID.
- Bookmark UUID berprefix tetap membuka daftar invoice Supplier setelah semua flag dimatikan: browser mengikuti redirect ke `/local-supplier/invoices` dan menerima 200. Pemeriksaan jalurnya mengonfirmasi 301 prefix lalu 302 alias; route tujuan role lain tetap menolak Supplier dengan 403.

Evidence ringkas tanpa teks record sensitif: `storage/logs/infor-style-urls-20261020/browser-evidence.json`. Pemeriksaan ini mengamati perilaku URL dan interaksi di atas, bukan audit visual/accessibility menyeluruh.

### Full suite serial dan pengulangan akhir

`composer test` selesai pada detached snapshot fitur `91a11ca`: **4 failed, 2945 passed (239899 assertions), 2413,25 detik**, exit 2. Ini hasil nonhijau, bukan PASS. Run awal di working tree utama berhenti dengan exit -1 tanpa ringkasan terminal dan dianggap **tidak konklusif**; snapshot terpisah dipakai setelah muncul perubahan pengguna di luar scope.

| Kegagalan full suite | Pembanding / tindak lanjut |
|---|---|
| `LocalProcurementStreamingReaderTest::test_real_infor_files_keep_positions_physical_rows_and_blank_uom_header` | Checkout bersih tidak mempunyai workbook fixture lokal yang diabaikan Git. Fixture disalin dari workspace dengan hash identik; seluruh 6 tes pembaca, termasuk kasus ini, kemudian PASS. |
| `UserFacingCopyInventoryTest::test_final_semantic_ledger_covers_every_current_copy_candidate_with_a_reason` | Assertion fingerprint yang sama FAIL pada checkout master bersih; rincian pembanding di bagian 5. |
| `AuthRateLimitingTest::test_mfa_code_challenge_is_limited_by_pending_user` | FAIL sebelum edit pertama dan tetap FAIL dengan pesan sama pada pengulangan akhir. |
| `LocalizationPerformanceTest::test_js_payload_is_bounded_to_the_declared_ui_domains_and_plain_data` | Kamus JS 16.462 byte melampaui batas tes 16.384 byte; pembanding master dicatat di bagian 5. |

Sesudah full suite selesai, dua file koreksi rollback UUID disalin ke snapshot dan hash source dibandingkan dengan working tree: identik. Filter gabungan `PageRegistryTest|PortalPathPrefixTest|HashidUrlSecurityTest|NotificationLegacyUrlTest|LongHashidTransitionTest|RouteContractTest|LocalProcurementStreamingReaderTest` menghasilkan **45 passed (2147 assertions), 67,52 detik**. Pengulangan Auth terakhir menghasilkan **319 passed, 1 failed (2637 assertions), 73,44 detik**, sama dengan kegagalan baseline. Jadi koreksi `113218e` sudah diuji melalui filter tersebut; full suite tidak diulang sesudah koreksi dua file ini.

Filter `TestingEnvironmentDatabaseSafetyTest|LocalizationPerformanceTest` kemudian dijalankan serial pada master bersih dan snapshot fitur: keduanya **3 passed, 1 failed (1127 assertions)**, masing-masing 30,49 dan 16,72 detik. Pesan kegagalannya identik: `16462 is less than 16384`. Setelah fixture dipulihkan, tiga kegagalan yang tersisa mempunyai pembanding baseline/master; kriteria seluruh tes lama hijau tetap belum terpenuhi.

Log command lokal berada di `storage/logs/infor-style-urls-20261020/` (ignored), termasuk baseline, matriks akhir, hasil route cache, lint, Node, dan full suite. Tidak ada output secret `.env` yang disalin ke laporan.

### Diinspeksi

Routes HTTP lengkap, bootstrap/middleware stack, codec/trait, pemanggil Hashids, resolver notifikasi, intended URL SSO/MFA, navigasi Purchasing, ServerTabsResponse/server-tabs.js, shell/sidebar, Quick Access, async export, config, dan tests terkait. Pencarian path literal juga menemukan profiler CLI yang memanggil controller secara langsung serta view welcome yang tidak aktif; keduanya tidak perlu diubah untuk URL runtime. `EnforceSupplierDomain` tetap berbasis route name; substring domain notifikasi dan lightbox tetap cocok di bawah prefix.

### Inferensi

Named route meneruskan bentuk URL baru ke pemanggil existing dan ekspor/job sesudah konfigurasi worker diperbarui. Hasil HTTP/JS lokal mendukung kontrak ini, tetapi tidak membuktikan konfigurasi worker/proxy di deployment.

### Belum diverifikasi

Browser role Admin/QC/GA/Accounting serta seluruh kombinasi scope/MFA, keyboard/AT, browser lain, dan audit visual menyeluruh belum dilakukan. Staging, produksi, QR fisik tercetak, reverse proxy/WAF, serta salt produksi tidak diakses. QR signed root dan SSO/MFA memiliki coverage otomatis yang disebutkan pada matriks, tanpa klaim QA fisik/produksi.

## 5. Perbandingan baseline

Baseline diambil dari source tanpa modifikasi setelah branch dibuat:

| Suite | Baseline |
|---|---|
| Database safety | 2 passed / 4 assertions |
| HashidUrlSecurity | 6 passed / 130 assertions |
| Auth | 319 passed, 1 failed / 2.637 assertions |
| ServerTabs PHP | 24 passed / 143 assertions |
| ServerTabs JS | 7 passed |

Kegagalan Auth baseline: `AuthRateLimitingTest::test_mfa_code_challenge_is_limited_by_pending_user`, baris 53, error session `code` tidak tersedia; respons memuat error kredensial. Ini diamati sebelum edit pertama, sehingga tidak dinyatakan hijau atau diperbaiki lewat perubahan fitur URL. Implementasi URL tidak mengubah file tes lama; perubahan Vendor Master yang muncul terpisah tetap dipertahankan. Ringkasan hasil akhir dan pembanding kegagalan lain, bila ada, berada di bagian 4.

Full suite juga menemukan assertion semantic ledger pada `UserFacingCopyInventoryTest`. Assertion fingerprint yang sama dieksekusi ulang melalui helper audit pada detached checkout master bersih `9eaa70a` (tanpa Laravel/SQL): **FAIL**, 23.898 record source dibanding 22.756 record ledger, dengan 7.471 fingerprint belum tercatat dan 6.329 fingerprint stale. Konfirmasi langsung PHPUnit untuk method tersebut pada master bersih juga menghasilkan **1 failed (2 assertions), 138,05 detik**, pada assertion fingerprint yang sama. Ini pembanding tes spesifik, bukan klaim full suite master dijalankan. Snapshot `UI-REDESIGN-RESULT/PHASE-7-COPY-AUDIT.jsonl` tetap dipertahankan sesuai larangan `AGENTS.md` untuk tidak meregenerasi audit historis sebagai efek samping task lain. Perubahan source fitur juga dapat menggeser line/offset snapshot; kegagalan tersebut tidak disamarkan sebagai PASS.

Kegagalan `LocalizationPerformanceTest` dikonfirmasi melalui PHPUnit pada master bersih `9eaa70a` dan snapshot fitur sesudah koreksi terakhir: ukuran kamus identik 16.462 byte, melampaui batas existing 16.384 byte. Source `JsTranslations` dan berkas locale tidak diubah oleh commit fitur URL. Batas tes tidak dinaikkan dan kamus aplikasi tidak dipangkas sebagai efek samping perubahan URL.

## 6. Keputusan pelaksanaan

- Semua flag dipaksa false pada `phpunit.xml` agar `.env` lokal yang dipakai untuk aktivasi tidak mengubah baseline tes lama. `.env.testing.example` juga mendapat default false. Ini konfigurasi tes; tidak ada method/assertion tes lama yang diubah.
- Koneksi `main` dan `HASHIDS_SALT` existing dipertahankan. Guard salt pendek hanya warning; guard kosong/default/tes produksi existing tetap berlaku.
- Query mentah dipertahankan untuk 301, termasuk encoding dan parameter berulang. Redirect kompatibilitas memakai `Location` relatif dan tidak pernah memilih host dari input.
- Registry memuat union target menu yang tersedia, tanpa parameter wajib; entry Quick Access legacy `ga.drp-draft` tidak mempunyai route dan tetap diabaikan, tanpa membersihkan konfigurasi yang tidak terkait.
- `PageRegistry::url()` menerima fallback opsional agar filter URL Purchasing existing tetap identik saat flag off. Saat registry aktif, menu menuju route dasar sesuai desain C1.
- Route alias UUID tetap terdaftar ketika flag registry dimatikan. Flag tersebut mengendalikan pembentukan link menu; mempertahankan alias memenuhi kontrak rollback bookmark yang sudah dipublikasikan. Tes baru dan browser memeriksa rantai 301 prefix lalu 302 ke route tujuan, termasuk penolakan role tujuan.
- Pemeriksaan static source dan review independen dilakukan tiap phase. Dua temuan P2 Phase A diperbaiki dan diberi tes regresi sebelum commit.
- Pint hanya menyentuh file PHP dalam scope; dua blank line berlebih pada controller perbandingan ikut dinormalisasi untuk memenuhi pemeriksaan format yang diminta. Tidak ada perubahan business logic dari formatting.
- `.rnd` sudah untracked sejak awal dan dipertahankan; tidak dimasukkan commit. Tidak ada reset, clean, stash, push, atau PR. Database aplikasi tidak dimigrasi dan tidak menerima DML ad hoc; login/logout, penandaan notifikasi dibaca, dan job ekspor/queue adalah efek simulasi browser yang diminta. Feature tests berjalan serial pada `adasi_portal_test` setelah guard live lulus.
- Saat full suite berjalan, muncul perubahan Vendor Master/test/terjemahan lain di working tree. Perubahan itu dipertahankan dan tidak di-stage. Pemeriksaan penuh lanjutan dijalankan dari detached snapshot commit fitur dengan source bersih, dependency terpasang yang sama, serta environment/database tes terisolasi dari database aplikasi.
- Kedua checkout temporer khusus tugas dihapus setelah seluruh pemeriksaan pembanding selesai. Log hasil tetap berada pada direktori ignored yang disebutkan di atas.

## 7. Risiko tersisa dan deploy

Aktifkan per flag di staging lebih dahulu, perbarui config/route cache dan worker, periksa seluruh role/scope, serta cek aturan proxy/WAF yang mungkin masih mengenali path lama. Bookmark lama dapat memerlukan dua hop 301. Form yang sudah terbuka harus dimuat ulang setelah prefix berubah karena path mutasi tidak di-redirect. Pertahankan salt, alfabet, UUID registry, dan daftar prefix historis. C1 menambah redirect menu; address bar akhirnya tetap URI route sebenarnya. Keterbatasan dan hasil nonhijau di bagian 4/5 harus tetap dipertimbangkan saat review/deploy.

Panjang kedua koneksi Hashids juga harus tetap sesudah publikasi; hanya format `main` dan `long` terkonfigurasi yang di-decode. Menonaktifkan registry menghentikan pembentukan menu UUID, sedangkan alias UUID tetap terdaftar agar bookmark yang sudah dipublikasikan tetap berlaku melalui auth dan 302 ke tujuan. Rollback mempertahankan hash record, UUID halaman, serta prefix dikenal.
