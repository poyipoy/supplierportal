# Prompt Eksekusi (One-Shot) — Login Gaya Infor dengan Laravel Passport

> Salin seluruh blok di bawah ini ke Claude Code di proyek lokal. Prompt ini sengaja ditulis lengkap agar agent bisa menyelesaikan pekerjaan dari awal sampai akhir tanpa berhenti untuk bertanya.

---

```text
Kamu mengimplementasikan fitur "Login gaya Infor" (OAuth 2.0 Authorization Code + PKCE, in-app, Laravel Passport) di ADASI Portal Supplier. Kerjakan SAMPAI SELESAI dalam satu sesi. Jangan berhenti untuk minta persetujuan rencana; rencananya sudah disetujui. Berhenti dan tanya HANYA jika ada kondisi pada bagian "STOP CONDITIONS".

## 0. Wajib dibaca dulu (berurutan)
1. AGENTS.md (seluruhnya) beserta file yang dirujuknya untuk inisialisasi konteks.
2. docs/plans/IMPLEMENTATION-PLAN-SSO-OAUTH-LOGIN-20261019.md. Ini spesifikasi utama. Ikuti desain, daftar file, urutan kerja (bagian 6), kriteria penerimaan (bagian 7), dan verifikasi (bagian 8).
3. reports/URL login OAuth Infor dan portal.md. Konteks riset saja, bukan spesifikasi.
4. File yang akan disentuh beserta tesnya: routes/web.php (section auth ≈L682–738), bootstrap/app.php, app/Http/Controllers/Auth/AuthenticatedSessionController.php, app/Http/Requests/Auth/LoginRequest.php, app/Http/Controllers/Auth/TwoFactorChallengeController.php, app/Services/Auth/* (terutama CompleteLoginService, TwoFactorService::safeIntendedUrl/beginLoginChallenge, AuthAuditLogger), app/Http/Middleware/{EnforceAuthSessionSecurity,EnforcePasswordChange,ShareAuthSplashState,AddSecurityHeaders,DecodeHashids}.php, app/Models/User.php, config/{auth,session,auth_security}.php, resources/views/auth/login.blade.php, layouts/auth.blade.php, lang/{en,id}/auth.php, tests/Feature/Auth/*.
Cocokkan rencana dengan kode terbaru. Bila berbeda, kode/migrasi/tes yang menang; sesuaikan implementasi dan catat perbedaannya di laporan akhir.

## 1. Persiapan
- Jalankan `git status`. Pertahankan perubahan user yang sudah ada; jangan reset/clean/stash milik user.
- Buat branch kerja baru dari branch saat ini: `feature/sso-oauth-login`.
- Pastikan database tes aman: `php artisan test --filter=TestingEnvironmentDatabaseSafetyTest`.
- Rekam baseline sebelum mengubah apa pun: `php artisan test tests/Feature/Auth --compact`. Simpan hasilnya untuk dibandingkan di akhir.

## 2. Passport: pakai API versi yang benar-benar terpasang
- `composer require laravel/passport` dengan versi major yang mendukung Laravel 12 dan PHP 8.2. Setelah terpasang, PIN versi EKSAK di composer.json (tanpa ^ atau ~) sesuai aturan AGENTS.md.
- JANGAN mengandalkan ingatan untuk API Passport. Baca source di vendor/laravel/passport untuk versi yang terpasang, minimal:
  - cara menonaktifkan auto-route (ignoreRoutes atau properti setara) dan daftar route bawaannya;
  - AuthorizationController::authorize() serta cara menangani guest dan skip consent;
  - signature Client::skipsAuthorization(...) dan Passport::useClientModel;
  - trait HasApiTokens dan contract yang diwajibkan pada model User (mis. OAuthenticatable);
  - ClientRepository: cara membuat public client dengan authorization code + redirect URI;
  - tokensCan, tokensExpireIn, refreshTokensExpireIn, perintah passport:keys, passport:purge, publish tag migrasi;
  - cara Passport membangun PSR-7 request dan memanggil League AuthorizationServer (validateAuthorizationRequest / respondToAccessTokenRequest).
- Publish migrasi Passport ke database/migrations. Migrasinya additive dan tidak boleh mengubah tabel lain.
- Tidak membuat database baru. Semua tabel oauth_* masuk ke database portal yang sama.

## 3. Implementasi
Ikuti bagian 3–4 rencana secara persis:
- config/sso.php + .env.example (SSO_LOGIN_ENABLED=false, SSO_CLIENT_ID=, SSO_STATE_PREFIX=ADASI).
- Konfigurasi Passport di AppServiceProvider.
- Client model first-party: skip consent HANYA untuk config('sso.client_id').
- User + HasApiTokens.
- SsoTransactionStore.
- SsoEntryController, SsoAuthorizeController, SsoCallbackController.
- Route manual: /oauth/token di luar web group.
- Middleware EnsureSsoSession (append setelah EnforceAuthSessionSecurity).
- ShareAuthSplashState dan allow-list audit.
- View auth/sso-error.
- Terjemahan en+id.
- Command idempoten `sso:install-client`.
- passport:purge terjadwal (business timezone, withoutOverlapping).
- docs/guides/SSO-OAUTH-LOGIN.md.

Aturan keras:
- Flag OFF = perilaku login identik dengan sebelumnya. Route bernama `login` dan `login.store` tetap ada dan tetap bernama sama.
- GET /oauth/authorize memvalidasi request SEBELUM menampilkan form. Invalid (client tidak dikenal, redirect_uri tidak persis, tanpa PKCE, method `plain`, response_type selain `code`) → 400 halaman error, TANPA header Location.
- Guest di /oauth/authorize: render view auth.login yang sama, set url.intended = fullUrl, form tetap POST ke login.store (Turnstile, rate limit, dummy hash tetap berlaku).
- Penukaran code di callback dilakukan IN-PROCESS lewat League AuthorizationServer, tanpa HTTP ke diri sendiri. Lalu:
  - verifikasi `sub` == auth()->id() dan `aud` == client id;
  - revoke access token dan refresh token;
  - set session sso.verified_at dan sso.client_id;
  - audit sso_authorization_completed;
  - redirect via TwoFactorService::safeIntendedUrl.
- state dan code_verifier dibuat dengan random_bytes. State dibandingkan dengan hash_equals, sekali pakai, ber-TTL (config), dengan batas transaksi pending. Format state `PREFIX~<acak base64url>`.
- Jangan pernah menulis code, state, code_verifier, atau token ke log, audit, atau respons JSON.
- Semua kontrol existing tidak boleh melemah: LoginRequest, 2FA, CompleteLoginService, known devices, batas sesi bersamaan, EnforceAuthSessionSecurity, EnforcePasswordChange, CSP, no-store.
- Gunakan BusinessTime sesuai AGENTS.md; instant UTC yang sah diberi anotasi `// biz-time:ignore <alasan>`.
- Teks UI baru wajib ada di lang/en dan lang/id dengan placeholder identik. Jangan menulis teks langsung di Blade.
- Kunci Passport (storage/*.key) TIDAK BOLEH di-commit. Pastikan .gitignore mencakupnya.
- JANGAN menjalankan migrate/migrate:fresh/rollback/db:wipe terhadap database aplikasi. Migrasi cukup dijalankan oleh tes (RefreshDatabase) di adasi_portal_test.
- Untuk tes, siapkan kunci Passport sementara secara otomatis di setUp tes SSO (generate pasangan RSA ke direktori temp atau set config passport.private_key/public_key). Jangan bergantung pada file kunci lokal developer.
- Jangan memperbaiki temuan sampingan `POST confirm-password` yang tidak bernama (lihat bagian 2 rencana). Cukup laporkan.
- Tidak ada refactor oportunistik di luar daftar file rencana.

## 4. Tes yang wajib dibuat
- tests/Unit/.../SsoTransactionStoreTest:
  - format state, challenge = BASE64URL(SHA256(verifier));
  - sekali pakai, TTL (travel), batas pending;
  - intended disaring safeIntendedUrl.
- tests/Feature/Auth/SsoAuthorizationFlowTest (flag ON via config), mencakup SEMUA skenario di bagian 8 rencana:
  - alur penuh guest → authorize → login → callback → dashboard;
  - deep link dipertahankan;
  - user 2FA;
  - remember-cookie re-challenge;
  - authorize invalid (5 variasi) → 400 tanpa Location;
  - callback dengan state palsu, state reuse, state expired, code reuse, error=access_denied, sub mismatch;
  - gate restart untuk sesi tanpa sso.verified_at dan 401 untuk JSON;
  - tidak ada token aktif setelah callback;
  - must_change_password;
  - flag OFF: GET /login tetap 200 dengan form lama.

## 5. Verifikasi (jalankan semuanya, perbaiki sampai hijau)
php artisan test --filter=TestingEnvironmentDatabaseSafetyTest
php artisan test tests/Feature/Auth --compact
php artisan test --filter=SsoTransactionStoreTest
php artisan test --filter=SsoAuthorizationFlowTest
php artisan test --filter=HashidUrlSecurityTest
php artisan test --filter=SupplierDataIsolationTest
php artisan test tests/Feature/SupplierRegistration --compact
php artisan test tests/Unit/TranslationParityTest.php
php artisan test --filter=TranslationContentTest
php artisan test --filter=UserFacingCopyInventoryTest
php artisan test --filter=BusinessTimeGuardTest
php artisan test --filter=FrontendAssetLoadingTest
php -l <setiap file PHP yang diubah/dibuat>
php vendor/bin/pint --test <setiap file PHP yang diubah/dibuat>
php artisan route:list --path=oauth --no-ansi
php artisan route:list --path=sso --no-ansi
php artisan view:cache
git diff --check
Lalu jalankan full suite `composer test` sekali (serial, jangan paralel). Bandingkan kegagalan dengan baseline. Kegagalan hanya boleh disebut pre-existing bila juga gagal di baseline atau di checkout bersih.

Simulasi manual: aktifkan flag di .env lokal, jalankan `php artisan passport:keys`, lalu `php artisan sso:install-client` dan isi SSO_CLIENT_ID. Buka /login di browser dan pastikan URL form login berbentuk /oauth/authorize?client_id=…&response_type=code&redirect_uri=…/sso/callback&scope=openid&state=ADASI~…&code_challenge=…&code_challenge_method=S256. Login (dengan dan tanpa 2FA), pastikan berakhir di dashboard. Setelah selesai, kembalikan flag ke false.

## 6. Dokumentasi
- docs/guides/SSO-OAUTH-LOGIN.md: tujuan, alur, env, pembuatan kunci (produksi via env / file 600), install client, migrasi (backup dulu, uji di staging), scheduler purge, rollback (flag off + config:clear), troubleshooting.
- AGENTS.md: tambahkan ringkasan singkat di bagian "Auth dan Konfigurasi" (flag, route, tabel oauth_*, aturan token dicabut, larangan log rahasia) dan tambahkan tes baru ke tabel coverage. Satu kali saja, tanpa duplikasi.
- Ubah status di docs/plans/IMPLEMENTATION-PLAN-SSO-OAUTH-LOGIN-20261019.md menjadi "diimplementasikan".

## 7. Commit
Commit bertahap per langkah bagian 6 rencana dengan pesan yang jelas, di branch feature/sso-oauth-login. JANGAN push dan JANGAN membuat PR. User akan me-review dulu.

## STOP CONDITIONS (hanya ini yang boleh membuatmu berhenti dan bertanya)
- Versi Passport yang kompatibel dengan Laravel 12 / PHP di mesin ini tidak bisa dipasang, atau `composer require` gagal karena konflik dependency.
- Database tes tidak aman atau tidak tersedia (guard testing menolak).
- Desain rencana terbukti mustahil dengan API Passport yang terpasang dan tidak ada alternatif yang setara tanpa melemahkan keamanan.
- Implementasi menuntut perubahan di luar daftar file rencana yang menyentuh domain lain (Impor, Local Supplier, payment).
Untuk hambatan lain, putuskan sendiri dengan opsi yang paling aman dan paling kecil, lalu catat di laporan.

## 8. Laporan akhir (format wajib)
1. Ringkasan: apa yang dibangun dan cara mengaktifkannya.
2. Versi Passport yang terpasang dan perbedaan API dari rencana.
3. Daftar file dibuat/diubah.
4. Hasil verifikasi, dipisah menjadi: diverifikasi lewat eksekusi (dengan output ringkas), diinspeksi, inferensi, dan belum diverifikasi (browser lintas role, staging/migrasi produksi, HTTPS).
5. Perbandingan dengan baseline tes.
6. Keputusan yang diambil sendiri dan alasannya.
7. Temuan sampingan, termasuk route POST confirm-password tanpa nama.
8. Langkah deploy yang harus dilakukan user.
```
