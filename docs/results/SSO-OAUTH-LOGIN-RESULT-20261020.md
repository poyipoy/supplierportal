# Hasil implementasi Login OAuth + PKCE — 20 Oktober 2026

Status: **DIIMPLEMENTASIKAN**. Verifikasi SSO PASS; full suite FAIL pada tiga failure yang
terbukti ada pada checkout awal. QA browser BLOCKED karena konektor browser tidak tersedia.

## 1. Ringkasan dan aktivasi

Login gaya Infor sudah diimplementasikan dalam satu aplikasi Laravel: `/login` →
`/oauth/authorize` → password/MFA existing → `/sso/callback` → deep link/dashboard.
State/verifier acak berada di sesi, TTL 600 detik, maksimum lima pending, sekali pakai,
PKCE S256. Callback menukar code in-process, memverifikasi JWT dan sub/aud, lalu mencabut
access/refresh token secara atomik sebelum memberi bukti SSO pada sesi Laravel.

Fitur default OFF. `.env` lokal ditemukan sudah berisi `SSO_LOGIN_ENABLED=true` dengan
client ID kosong saat inspeksi; flag dikembalikan menjadi **false** sesuai objective.
Tidak ada secret/key/client ID aplikasi yang ditulis ke Git. Aktivasi deployment:
periksa migrasi, buat key, jalankan `sso:install-client`, isi client ID, aktifkan flag,
perbarui config cache. Detail: [panduan deployment](../guides/SSO-OAUTH-LOGIN.md).

Branch: `feature/sso-oauth-login`. Commit bertahap dibuat; tidak ada push atau PR.

## 2. Passport dan perbedaan API/rencana

Terpasang dan dipin **laravel/passport 13.10.0**, PHP CLI **8.2.30**, League OAuth2
Server **9.4.1**, Firebase PHP-JWT **7.2.1**. Instalasi menambah 13 paket tanpa update
paket existing. Source `vendor/laravel/passport` dan bridge PSR Laravel diperiksa langsung;
[dokumentasi resmi Laravel 12](https://laravel.com/docs/12.x/passport) dipakai sebagai rujukan.

- `Passport::ignoreRoutes()` ditempatkan di `register()`, sebelum provider package boot,
  bukan `boot()` seperti contoh rencana. Route didaftarkan manual; token route melalui
  `withRouting(then: ...)` sehingga tidak membawa web/session/CSRF.
- User membutuhkan contract `OAuthenticatable` dan `HasApiTokens`.
  `Client::skipsAuthorization(Authenticatable $user, array $scopes): bool` mengikuti API terpasang.
- Public client dibuat dengan `ClientRepository::createAuthorizationCodeGrantClient(...,
  confidential: false)`, bukan menebak opsi CLI versi lama.
- `TokenRepository` publik pada versi ini tidak menyediakan API revoke lama. Callback
  memakai `Bridge\AccessTokenRepository` dan `Bridge\RefreshTokenRepository`, dengan lookup
  token/relasi dari model Passport; semuanya pada koneksi database portal.
- `AuthorizationController::authorize()` menerima PSR request, Laravel Request,
  PSR response dan `AuthorizationViewResponse`. Passport 13 membutuhkan view consent
  aplikasi; ditambahkan view bilingual `auth.sso-authorize` agar client lain tidak dilewati.
- Lifetime memakai `DateInterval('PT10M')`; transaksi/session proof memakai instant UTC
  dengan anotasi `biz-time:ignore`. Scheduler memakai zona bisnis.
- Rencana mengarahkan sesi authenticated ke guest `/login`, yang akan loop. Gate kini
  memulai transaksi langsung. Forced password change tetap ditegakkan, dan tujuan awal
  disimpan untuk transaksi baru sesudah pergantian password.
- `auth.login` sebelumnya memakai action `route('login')`; diganti `login.store`, URI sama.
  Full URL Laravel menormalkan urutan query; tes membandingkan bentuk kanonik tersebut.
- `AuthAuditLogger` mengizinkan key metadata, bukan nama event. Ditambahkan `client_id`,
  empat event pada katalog audit dan label en/id agar dapat ditinjau pada UI audit.
- Header OAuth/callback diperketat menjadi `no-referrer`, termasuk redirect. Splash baru
  ditawarkan setelah authorize valid; state login/logout tetap mengikuti handoff existing.
- Error endpoint token standar dipertahankan dengan header `no-store, private` dan
  `Pragma: no-cache`; kegagalan konfigurasi yang tidak terduga mengembalikan JSON 503
  `server_error` generik tanpa merender/mencatat exception protokol mentah.
- Validasi URL installer menerima hostname Laragon existing yang berisi underscore;
  tetap menolak scheme non-HTTP, userinfo, control/backslash, query dan fragment.

`openid` di sini adalah scope aplikasi OAuth; implementasi ini tidak menambah ID Token,
OIDC discovery/JWKS, federasi, atau server login terpisah.

## 3. Daftar file

Dependency/configuration:

```text
composer.json
composer.lock
.env.example
config/sso.php
app/Providers/AppServiceProvider.php
app/Models/User.php
app/Models/Passport/Client.php
database/migrations/2026_10_19_172826_create_oauth_auth_codes_table.php
database/migrations/2026_10_19_172827_create_oauth_access_tokens_table.php
database/migrations/2026_10_19_172828_create_oauth_refresh_tokens_table.php
database/migrations/2026_10_19_172829_create_oauth_clients_table.php
database/migrations/2026_10_19_172830_create_oauth_device_codes_table.php
```

Runtime, audit, view dan locale:

```text
app/Services/Auth/Sso/SsoTransactionStore.php
app/Http/Controllers/Auth/SsoEntryController.php
app/Http/Controllers/Auth/SsoAuthorizeController.php
app/Http/Controllers/Auth/SsoCallbackController.php
app/Http/Middleware/EnsureSsoSession.php
app/Http/Middleware/ShareAuthSplashState.php
app/Http/Middleware/AddSecurityHeaders.php
app/Services/Auth/AuthAuditLogger.php
app/Models/AuthAuditLog.php
app/Console/Commands/SsoInstallClient.php
bootstrap/app.php
routes/web.php
routes/console.php
resources/views/auth/login.blade.php
resources/views/auth/sso-error.blade.php
resources/views/auth/sso-authorize.blade.php
resources/views/layouts/auth.blade.php
lang/en/auth.php
lang/id/auth.php
lang/en/security.php
lang/id/security.php
```

Tes dan dokumentasi:

```text
tests/Unit/Auth/SsoTransactionStoreTest.php
tests/Feature/Auth/SsoAuthorizationFlowTest.php
docs/guides/SSO-OAUTH-LOGIN.md
docs/plans/IMPLEMENTATION-PLAN-SSO-OAUTH-LOGIN-20261019.md
docs/results/SSO-OAUTH-LOGIN-RESULT-20261020.md
AGENTS.md
```

`.gitignore` sudah mencakup `/storage/*.key`, sehingga tidak perlu diubah. `.env` lokal
diabaikan Git dan hanya flag SSO yang diubah menjadi false. Dokumen prompt/riset lain
yang sudah staged sebelum task dipertahankan. File helper HTTP/key sementara di luar
repository tidak termasuk implementasi/deployment.

## 4. Hasil verifikasi

### Diverifikasi lewat eksekusi

| Pemeriksaan | Hasil |
|---|---|
| `TestingEnvironmentDatabaseSafetyTest` | PASS: 2 tes, 4 assertion; live connection `adasi_portal_test` |
| Auth sebelum perubahan | 271 PASS, 1 FAIL; 2050 assertion |
| Auth setelah perubahan terakhir | 319 PASS, 1 FAIL yang sama; 2637 assertion; 90,62 detik |
| `SsoTransactionStoreTest` | PASS: 56 tes, 197 assertion |
| `SsoAuthorizationFlowTest` | PASS terakhir: 48 tes, 555 assertion; 34,86 detik |
| `HashidUrlSecurityTest` | PASS: 6 tes, 130 assertion |
| `SupplierDataIsolationTest` | PASS: 11 tes, 13 assertion |
| `tests/Feature/SupplierRegistration` | PASS: 90 tes, 729 assertion |
| `TranslationParityTest` | PASS: 3 tes, 40733 assertion |
| `TranslationContentTest` | PASS: 17 tes, 62144 assertion |
| `UserFacingCopyInventoryTest` | FAIL: 1, PASS: 27; 92112 assertion; ledger copy historis berbeda |
| `BusinessTimeGuardTest` | PASS: 2 tes, 2 assertion |
| `FrontendAssetLoadingTest` | PASS: 12 tes, 169 assertion |
| PHP lint / scoped Pint | PASS untuk 28 file PHP baru/diubah, termasuk migrasi dan tes; empat file Blade juga diperiksa |
| `route:list --path=oauth` | Empat route; token hanya throttle, tanpa web/session/CSRF |
| `route:list --path=sso` | Callback memakai web/auth/no-store/throttle |
| `view:cache` | PASS |
| `npm.cmd run build` | PASS, Vite 7.3.6 |
| `git diff --check` perubahan implementasi | PASS |
| `git diff --cached --check` | FAIL pada whitespace dokumen riset existing, lihat bagian 7 |
| `composer test` (satu kali, serial) | 2885 PASS, 3 FAIL; 237132 assertion; 2244,58 detik |

Full suite menjalankan 47 tes flow SSO sebelum hardening header token terakhir. Sesudah
hardening tersebut, safety guard, seluruh suite Auth dan 48 tes flow SSO dijalankan ulang
secara serial. Tidak ada failure baru pada pemeriksaan itu. Full suite tidak dijalankan
dua kali. Tes export 50000 baris di dalam full suite PASS (692,40 detik).

Log bukti berada di `%TEMP%`: `adasi-sso-auth-baseline-20261020.log`,
`adasi-sso-full-suite.log`, `adasi-sso-clean-copy.log`, `adasi-sso-clean-feature.log`,
`adasi-sso-auth-latest.log`, `adasi-sso-flow-latest.log`, serta `adasi-sso-check-results.json`.
Key/env/vendor sementara checkout pembanding sudah dihapus dan worktree dilepas; folder
kosongnya masih dikunci proses Windows. Key, sesi dan akun fixture HTTP juga dibersihkan.

Flow tests mencakup guest/login/deep link, TOTP, remember-cookie re-challenge, delapan
variasi authorize invalid, consent client lain, state/code replay/expiry, wrong verifier,
denial, sub/aud mismatch, signature invalid, rollback kegagalan revoke, gate HTML/JSON,
forced password, flag OFF, installer idempotent, splash, dan sanitasi audit/log.
RSA sementara di memory dibuat otomatis; tidak bergantung pada key lokal developer.
Tes JWT membuktikan issuance asli dan respons termodifikasi benar-benar terjadi sebelum
memeriksa kegagalan, sehingga exception fixture tidak menghasilkan false-positive.

Smoke HTTP nyata melalui `php -S 127.0.0.1:8876`, pada database tes dan key/akun sementara:

- Password → authorize → callback → dashboard Admin: PASS.
- Password + MFA recovery → authorize → callback → dashboard Admin: PASS.
- CSRF/cookie dan POST logout untuk kedua flow: PASS.
- Setelah kedua callback: active access token **0**, active refresh token **0**, audit
  authorization completed **2**. Fixture database dibersihkan dan server dihentikan.

### Diinspeksi

Routes/middleware, LoginRequest, kedua jalur MFA, CompleteLoginService, User, session/auth
configuration, CSP/no-store, hashid handling, installed Passport/League source dan migrasi.
Review keamanan terpisah berbasis source tidak menemukan P1/P2; ini bukan penetration test.
Tidak ada perubahan controller/service domain Impor, Local Supplier, GA atau payment.

Inspeksi read-only aplikasi mengonfirmasi koneksi `adasi_portal` dan lima migrasi OAuth
batch **50** berstatus Ran; physical tables tercatat dibuat `2026-10-19 17:41:51` UTC.
Tidak ada `migrate`, `migrate:fresh`, rollback atau wipe yang dijalankan agent pada database
aplikasi. **Asal penerapan batch tersebut belum terverifikasi**; jangan menyimpulkan database
aplikasi tidak berubah hanya dari pekerjaan agent atau keberadaan file migrasi.

### Inferensi

Route session blocking menserialisasi konsumsi state antar callback pada deployment dengan
cache locks yang sesuai. Belum ada eksekusi dua HTTP callback simultan; replay serial bukan
bukti konkurensi nyata. Kompatibilitas flag OFF didukung tes yang tersedia, bukan QA semua role.

### Belum diverifikasi / blocked

Browser nyata **BLOCKED**: konektor mengembalikan `Browser is not available: chrome`, daftar
browser kosong, dan `Browser is not available: iab`. HTTP smoke tidak dianggap browser QA.
Chrome/Edge, back/multi-tab, tampilan lintas role, Apache/Laragon, HTTPS/secure cookies,
staging, rotasi key/env produksi, cache locks lintas node dan deployment/migrasi produksi
belum diuji. Tidak ada push/PR/deployment.

## 5. Perbandingan dengan baseline

Baseline Auth direkam sebelum instalasi/kode berubah. Satu failure identik ada sebelum dan
sesudah: `AuthRateLimitingTest::test_mfa_code_challenge_is_limited_by_pending_user`, line 53,
fixture user tidak memiliki MFA, sehingga controller mengembalikan error `email` sementara
tes mengharapkan error `code`. Tes lama dan kontrol runtime tersebut tidak diubah.

Checkout bersih **f3d9a8b** disiapkan pada worktree sementara dengan vendor/APP_KEY testing
terpisah. Semua suite yang memakai database dijalankan serial; copy inventory baseline
berupa PHPUnit murni tidak membuka database.
Checkout bersih menjalankan langsung `vendor/bin/phpunit tests/Unit/UserFacingCopyInventoryTest.php`
tanpa Laravel/database boot: 28 tes, 91909 assertion, satu kegagalan pada method ledger yang
sama, durasi 162,77 detik. Kegagalan copy inventory terbukti sudah ada pada checkout awal.
Perubahan SSO menambah kandidat scanner, tetapi tidak memperbaiki ketidaksinkronan historis
ledger tersebut. Full suite juga menemukan `LocalizationPerformanceTest` pada batas kamus
JS: 16462 byte dibanding batas tetap 16384 byte. Checkout bersih menjalankan ulang
`AuthRateLimitingTest` dan `LocalizationPerformanceTest`: **8 PASS, 2 FAIL, 1204 assertion**,
durasi 39,85 detik; kedua method dan angka ukuran kamus identik. Safety guard pada checkout
bersih juga PASS 2 tes/4 assertion sebelum suite database tersebut.
Tidak ada perubahan pada `JsTranslations` maupun domain kamus JS dalam implementasi SSO.
Ledger historis tidak diregenerasi sebagai efek samping fitur SSO.

## 6. Keputusan yang diambil

Tidak membuka ulang persetujuan rencana/commit yang sudah eksplisit. Perubahan dibatasi pada
auth/SSO dan integrasi yang diperlukan; tambahan di luar tabel file rencana hanya consent
view, header OAuth dan katalog/locale audit untuk mengoperasikan kontrak versi terpasang.
Root route, nama login/store, auth/2FA completion, password enforcement dan domain policy
existing dipertahankan. `prompt` OAuth ditolak karena negosiasi re-login/consent tambahan
tidak termasuk desain flow ini.

Token bukan kredensial portal baru: callback tidak mengirim token/verifier pada JSON aplikasi.
`/oauth/token` tetap endpoint JSON standar protokol Passport. Pengecualian protokol ini
dipisahkan dari larangan membocorkan token dalam respons aplikasi/log.

Browser yang tidak tersedia tidak diganti dengan klaim QA; dilakukan smoke HTTP dengan
fixture terisolasi. Flag aplikasi lokal diakhiri false, bukan true yang ditemukan sebelumnya.
Tidak membuat database baru dan tidak menjalankan migrasi aplikasi untuk simulasi.

## 7. Temuan sampingan

- POST `confirm-password` tetap tidak bernama; pengecualian `password.confirm.store` tidak
  cocok dengan route itu. Tidak diperbaiki, sesuai batas objective.
- `composer require` berhasil resolve/install/package-discover tetapi exit 1 karena audit
  dua paket existing: `symfony/http-foundation` ([CVE-2026-108082](https://symfony.com/cve-2026-108082))
  dan `symfony/yaml` ([CVE-2026-109413](https://symfony.com/cve-2026-109413)). Keduanya sudah ada
  pada lockfile awal dan tidak di-update oleh instalasi Passport. Perlu task maintenance terpisah.
- Ledger copy historis tidak sama dengan source, termasuk pada checkout bersih `f3d9a8b`;
  kegagalan method yang sama terkonfirmasi sebelum perubahan SSO.
- Full suite menemukan kamus JS 16462 byte melebihi batas 16384 byte. Ambang tidak
  dinaikkan; nilai dan kegagalan identik terkonfirmasi pada checkout awal `f3d9a8b`.
- Dokumen riset yang sudah staged sebelum task,
  `research_notes/URL login OAuth Infor dan portal/infor_url_anatomy.md:128`, memiliki trailing
  whitespace. Tidak diubah karena bukan bagian implementasi.
- Provenance migrasi OAuth aplikasi belum terverifikasi, sebagaimana bagian 4.

## 8. Langkah deploy untuk operator

Ikuti [SSO-OAUTH-LOGIN.md](../guides/SSO-OAUTH-LOGIN.md): backup → restored staging → cek
ledger dan semua migrasi pending → Composer install sesuai lock → key/env dengan izin
600/ACL → APP_URL kanonik → `sso:install-client` → client ID → flag/config cache → scheduler.
Jangan menerapkan ulang DDL yang sudah ada tanpa inspeksi ledger/physical schema.
Sensor query/body OAuth pada access log/APM. Jalankan browser lintas role dan HTTPS sebelum
rollout. Rollback perilaku cukup flag false + `config:clear`; tabel tidak perlu dihapus.
