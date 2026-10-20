# Hasil implementasi logout lintas tab — 20 Oktober 2026

## 1. Ringkasan

Phase A/B/C selesai pada branch `feature/logout-cross-tab`. Implementasi menambahkan
BroadcastChannel dengan fallback storage, redirect polling khusus 401, cleanup
storage dengan allowlist, key draft penawaran per user, serta checkbox opsional
keluar dari semua perangkat. Tidak ada halaman perantara atau pesan untuk menunggu
sebelum menutup tab. Tidak ada migrasi, package baru, push atau PR.

Flag di `config/portal_session.php` / `.env.example`:

| Flag | Default | Aktivasi/nonaktivasi |
|---|---|---|
| `PORTAL_CROSS_TAB_LOGOUT_ENABLED` | `true` | Set `true`/`false` untuk sinkron tab dan redirect polling |
| `PORTAL_STORAGE_CLEANUP_ENABLED` | `true` | Set `true`/`false` untuk cleanup allowlist |
| `PORTAL_LOGOUT_ALL_DEVICES_ENABLED` | `false` | Set `true` untuk checkbox dan pemrosesan backend; `false` mengabaikan parameter |

Bangun ulang cache konfigurasi setelah perubahan flag. Panduan:
[LOGOUT-CROSS-TAB.md](../guides/LOGOUT-CROSS-TAB.md).

## 2. Temuan verifikasi source dan perbedaan dari rencana

- HEAD awal aktual `e38f885`, bukan referensi riset `1c4ab2c`. `master` telah
  di-checkout dan `git pull --ff-only` menyatakan up to date; master lokal memiliki
  satu commit di atas origin. Branch fitur dibuat dari HEAD lokal tersebut.
- Source vendor `SessionGuard::logout()` membersihkan data guard dan memutar
  remember token bila ada. Logout biasa tidak menaikkan versi sesi aktif lain;
  recaller perangkat lain dapat menjadi invalid. Tes baru mengonfirmasi rotasi
  token dan perbedaan versi sesi antara logout biasa dan checkbox.
- `Authenticate` melempar `AuthenticationException`; handler vendor membedakan
  JSON 401 dan redirect HTML. `bootstrap/app.php` secara khusus menjaga polling
  tetap JSON 401. `EnsureSsoSession` juga membalas JSON 401 tanpa redirect untuk
  sesi SSO belum terverifikasi. Tes guest polling on/off SSO, suite auth dan
  `SsoAuthorizationFlowTest` menguji jalur tersebut. Tidak dibutuhkan fallback
  `response.redirected`, perubahan middleware atau semantik server.
- Flag guest dibaca dari penanda login, sedangkan `window.AdasiSession=null`.
  Guest tidak membawa ID user. URL login disuntikkan terpisah dari server.
- PR pada draft existing memakai `$pr->hash`, bukan integer. Key baru
  `quotation_draft_<userId>_<prHash>` mempertahankan hash. Allowlist cleanup tetap
  persis `^quotation_draft_\d+$`: key hash lama menjadi orphan, tidak dibaca atau
  dimigrasikan. Dampak draft lama tidak dipulihkan sekali saat deploy dicatat di panduan.
- Metadata `source` tidak diizinkan `AuthAuditLogger`. Event existing
  `other_device_logout` memakai `reason=logout_checkbox` yang sudah diizinkan.
  Tidak ada perubahan katalog event atau allowlist logger.
- Tidak ada teks JS baru, sehingga `lang/*/js.php` dan `JsTranslations` tidak
  diubah. Satu label checkbox baru ada di kedua `navigation.php`.

## 3. File dan commit per fase

| Fase | Commit | File |
|---|---|---|
| A | `0b1073a` — `feat(auth): cross-tab logout and session-ended redirect on 401` | Baru: `config/portal_session.php`, `resources/js/session-sync.js`, `tests/js/session-sync.test.mjs`, `tests/Feature/Auth/LogoutAllDevicesTest.php`. Ubah: `.env.example`, `resources/js/app.js`, `resources/views/layouts/{app,auth}.blade.php`, `resources/views/auth/login.blade.php`, `resources/views/partials/navbar.blade.php` |
| B | `e496269` — `feat(auth): scope and clear client storage around logout` | `resources/js/session-sync.js`, `public/assets/js/async-export.js`, `resources/views/supplier/quotations/create.blade.php`, kedua file tes baru |
| C | `4f96f29` — `feat(auth): optional sign-out-all-devices on logout` | `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, navbar, `lang/{en,id}/navigation.php`, kedua file tes baru |
| Dokumentasi | `docs(auth): logout cross-tab guide and AGENTS.md update` | Baru: panduan dan laporan ini. Ubah: bagian Auth/coverage `AGENTS.md`, status rencana implementasi |

Perubahan registrasi/invoice yang sudah dirty sebelum task tidak diubah dan
tidak dimasukkan commit. Dua hunk registrasi existing di `AGENTS.md` juga tetap
di working tree dan dikecualikan dari commit dokumentasi. `CLAUDE.md` tidak diubah.

## 4. Verifikasi

### Diverifikasi lewat eksekusi

| Pemeriksaan | Hasil |
|---|---|
| `TestingEnvironmentDatabaseSafetyTest` | PASS — 2 tes, 4 assertions; koneksi nyata `adasi_portal_test` |
| `LogoutAllDevicesTest` | PASS — 11 tes, 105 assertions |
| `tests/Feature/Auth` | FAIL baseline yang sama — 330 passed, 1 failed, 2742 assertions |
| `UserAccountNavigationTest` | PASS — 2 tes, 20 assertions |
| `PortalPathPrefixTest` | PASS — 12 tes, 1445 assertions |
| `SsoAuthorizationFlowTest` | PASS — 48 tes, 555 assertions |
| `TranslationParityTest` | PASS — 3 tes, 40797 assertions |
| `UserFacingCopyInventoryTest` | FAIL — 27 passed, 1 failed, 92811 assertions; kegagalan ledger juga terjadi pada snapshot bersih master |
| `FrontendAssetLoadingTest` | PASS — 12 tes, 169 assertions |
| `session-sync.test.mjs` | PASS — 15 tes |
| `preferences.test.mjs` | PASS — 27 tes |
| `unsaved-changes-confirmed-leave.test.mjs` | PASS — 5 tes |
| Seluruh JS | PASS — 44 file, masing-masing dijalankan terpisah |
| PHP lint | PASS — 10 file PHP/Blade yang dibuat/diubah |
| Scoped Pint `--test` | PASS — seluruh file PHP/Blade yang dibuat/diubah |
| `php artisan view:cache` | PASS |
| `npm.cmd run build` | PASS — 35 modul, 13.21 detik |
| `git diff --check` | PASS |
| `composer test` serial, satu kali | FAIL — 2984 passed, 5 failed, 240431 assertions; 2103.22 detik. Tiga kegagalan terbukti baseline; dua error direktori fixture lulus saat pengulangan terarah dengan izin tulis |
| `MaterialProcurementDummySeederTest`, pengulangan terarah | PASS — 2 tes, 72 assertions, 16.70 detik; didahului safety test 2 passed / 4 assertions |

Semua suite database berjalan serial. Tidak ada migrasi manual pada database
aplikasi. `RefreshDatabase` feature tests berjalan pada database tes yang telah
dibuktikan aman.

Percobaan awal Node/Vite di sandbox terkena `spawn EPERM`. Node memakai
`--experimental-test-isolation=none` per file; menjalankan seluruh file dalam satu
proses tanpa isolation sempat menimbulkan benturan global/module cache antar suite,
sehingga bukan hasil verifikasi yang dipakai. Sebanyak 41 file lulus per file di
sandbox; tiga suite yang memang membuat subprocess (`regional-export-history`,
`po-progress-history`, `calendar`) lulus setelah dijalankan dengan izin subprocess.
Build Vite juga lulus setelah eskalasi proses. Satu run feature awal memperingatkan
cache PHPUnit tidak writable; run berikutnya memakai `--do-not-cache-result`.
Peringatan metadata doc-comment PHPUnit legacy tetap ada.

### Diinspeksi

Routes logout/other-devices/profile sessions dan polling; lifecycle controller;
SessionGuard/Authenticate/handler vendor; SessionRevocationService dan
SessionInventoryService; audit listener/logger; EnforceAuthSessionSecurity,
EnsureSsoSession, EnforcePasswordChange, AddSecurityHeaders dan ShareAuthSplashState;
Vite/import/module tests; Echo/Pusher initialization; draft dan key export;
layout/navbar, locale dan tests terkait.

POST + CSRF, route root, cookie known-device, terminateSession, CSP/no-store dan
bisnis Impor/Local/Payment dipertahankan. Tidak ada perubahan routes, middleware,
schema, dependency lock, atau bisnis domain.

### Inferensi

Transport browser yang tersedia akan mengirim sinyal antartab pada origin yang
sama; tes mock membuktikan handler/fallback/cleanup, bukan pengiriman browser nyata.
Pemutusan Echo dipanggil sekali bila tersedia, tetapi dampak koneksi Pusher nyata
belum diamati. Jika transport diblokir atau tab tersembunyi, deteksi mengandalkan
polling aktif berikutnya.

### Belum diverifikasi

Tool browser mengembalikan `apps=[]`, `browsers=[]`. Belum ada simulasi Laragon dua
tab, dua browser/perangkat, antrean ekspor nyata lalu logout, toggle checkbox
runtime di browser, mode privat, storage lintas jendela, Back/bfcache, Pusher setelah
sesi berakhir, staging, atau produksi. Tidak ada klaim manual-QA/production-ready.
Flag `.env` lokal tidak dinyalakan untuk checkbox; tes mengubah config in-memory.

## 5. Perbandingan baseline

Sebelum edit runtime:

- Safety: 2 passed / 4 assertions.
- Auth: **319 passed, 1 failed / 2637 assertions**, 140.89 detik.
- Navigation: 2 passed / 20 assertions.
- Preferences JS: 27 passed; confirmed-leave JS: 5 passed.

Setelah implementasi, auth menjadi **330 passed, 1 failed / 2742 assertions**.
Penambahan 11 tes/105 assertions berasal dari suite logout baru. Kegagalan yang
sama persis sebelum/sesudah adalah
`AuthRateLimitingTest::test_mfa_code_challenge_is_limited_by_pending_user`, baris 53:
`Session missing error: code`; respons berisi `These credentials do not match our records.`
Tes dan alur MFA itu tidak diubah karena di luar cakupan logout.

Kegagalan copy inventory adalah
`test_final_semantic_ledger_covers_every_current_copy_candidate_with_a_reason`,
baris 165 (`Every changed/new candidate must be reviewed and stale decisions removed.`).
Tes yang sama dijalankan terhadap archive Git bersih `master` pada `e38f885`,
dengan test/scanner/ledger dari archive dan runner vendor lokal: **1 test, 2 assertions,
1 failure** di baris yang sama. Itu membuktikan mismatch ledger sudah ada pada master.
Ledger historis tidak ditulis ulang sebagai efek samping pekerjaan logout.

Full suite dieksekusi sekali secara serial: **2984 passed, 5 failed / 240431
assertions**, 2103.22 detik (sekitar 35 menit), exit 2. Benchmark ekspor 50.000
baris lulus dalam 638.93 detik. Selain dua kegagalan yang sudah dibuktikan di atas:

| Tes full suite | Hasil pembanding bersih master `e38f885` |
|---|---|
| `LocalizationPerformanceTest::test_js_payload_is_bounded_to_the_declared_ui_domains_and_plain_data` | FAIL identik: 16462 byte tidak kurang dari batas 16384 byte, baris 54 |
| `MaterialProcurementDummySeederTest::test_seeder_creates_all_six_curated_packages_with_valid_invariants` | PASS pada storage snapshot terpisah |
| `MaterialProcurementDummySeederTest::test_attachments_pass_file_access_guard_verification` | PASS pada storage snapshot terpisah |

Pembandingan tiga tes ini menghasilkan 2 passed / 1 failed, 1191 assertions,
20.131 detik. Bootstrap mengarahkan autoload App/Tests/Database serta base path
ke archive bersih, memverifikasi lokasi source lewat Reflection, memakai key
testing dummy dan dependency vendor terpasang yang sama. Safety test pada
snapshot lebih dulu lulus 2 tes / 4 assertions terhadap `adasi_portal_test`.

Dua error seeder pada full run adalah `UnableToCreateDirectory` di namespace
fixture `storage/app/private/attachments/dummy_material_procurement/...`.
Keduanya tidak disebut pre-existing karena tidak gagal di master bersih.
Pengulangan terarah pada source branch yang sama dengan izin tulis direktori
fixture menghasilkan **2 passed / 72 assertions**, 16.70 detik. Safety test
diulang dan lulus sebelum pengulangan itu. Tidak ada perubahan source atau tes
untuk meloloskannya. Dua error tersebut terkonfirmasi sebagai batas filesystem
pada run sandbox, bukan regresi source logout. Hasil full suite tetap dilaporkan
FAIL, tidak diubah menjadi PASS berdasarkan pengulangan subset.

Kegagalan ukuran kamus JS adalah baseline yang terkonfirmasi; perubahan logout
tidak menambah teks JS atau mengubah payload `JsTranslations`. Batas 16 KiB dan
tes lama dipertahankan, tidak dinaikkan untuk membuat suite hijau.

## 6. Keputusan implementasi

- Cleanup tetap terbatas pada allowlist meskipun ditemukan key hash legacy.
  Memperluas regex berisiko menghapus draft baru yang wajib bertahan.
- Cleanup juga dijalankan di penerima sesi berakhir; guest login mengulangnya
  setelah redirect. Flag cleanup independen dari sinkron tab.
- Penerima mendengarkan fallback storage walaupun tab itu mendukung channel;
  konstruksi/send BroadcastChannel yang gagal juga jatuh ke storage.
- Navigasi sesi berakhir menonaktifkan permintaan dialog native, sambil tetap
  membiarkan handler halaman menyimpan draft. Modul unsaved-changes tidak diubah.
- Menu checkbox menggunakan `data-bs-auto-close="outside"` hanya saat flag aktif,
  sehingga memilih checkbox tidak langsung menutup menu.
- Akses storage existing yang disentuh pada form quotation juga dibungkus
  `try/catch`; draft JSON malformed tidak menghentikan inisialisasi form.
- **Tidak ada tes lama diubah.** Coverage ditambahkan pada dua file tes baru.
- `security-audit` dipakai sebagai panduan review terarah, bukan audit seluruh
  codebase. Tidak ada full-audit artifact atau klaim cakupan keamanan menyeluruh.

## 7. Risiko tersisa dan deploy

1. Review empat commit lokal dan deploy source beserta asset Vite hasil build.
   Build pada workspace ini juga mencakup perubahan pengguna yang sudah ada;
   build ulang dari commit yang benar-benar dipilih untuk deployment.
2. Tetapkan tiga flag sesuai tabel; default sinkron/cleanup aktif dan all-devices
   mati. Bangun ulang `config:cache` sesuai prosedur deployment. Tidak perlu migrasi.
3. Beri tahu supplier bahwa draft key lama tidak dipulihkan satu kali sesudah
   deploy. Draft baru ber-scope user bertahan. Key hash lama tetap menjadi orphan.
4. Lakukan uji dua tab/perangkat, storage browser, polling revokasi, checkbox,
   known-device, Back/bfcache dan Pusher pada restored staging sebelum rilis.
5. Investigasi kegagalan baseline MFA, ledger copy dan ukuran kamus JS secara terpisah. Hasil automated/static
   tidak menggantikan acceptance browser atau verifikasi produksi.
6. Rollback memakai flag false dan cache konfigurasi; draft yang sudah dihapus
   dan sesi yang sudah dicabut tidak dipulihkan oleh rollback flag.
