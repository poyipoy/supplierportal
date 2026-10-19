# Implementation Plan — Login Gaya Infor (OAuth 2.0 Authorization Code + PKCE, in-app, Laravel Passport)

> Tanggal: 19 Oktober 2026 · Status: **disetujui, belum diimplementasikan**
>
> Agent pelaksana wajib membaca `AGENTS.md` lebih dulu dan mencocokkan rencana ini dengan kode terbaru. Bila berbeda, **kode, migrasi, dan tes yang menang**.
> Riset pendukung: `reports/URL login OAuth Infor dan portal.md`.

## 1. Context

User ingin alur login ADASI Portal Supplier terlihat dan bekerja seperti Infor CloudSuite:

- Entry URL pendek (`https://<host>/` atau `/login`).
- Halaman login tampil di URL authorization yang panjang:
  `/oauth/authorize?client_id=…&response_type=code&redirect_uri=…/sso/callback&scope=openid&state=…&code_challenge=…&code_challenge_method=S256`
- Setelah login, kembali lewat `/sso/callback` lalu masuk dashboard.

Keputusan user:
- (1) **Satu aplikasi yang sama.** Tidak ada server/subdomain login terpisah.
- (2) **Hanya package Laravel**, yaitu `laravel/passport`. Tidak ada IdP pihak ketiga dan tidak ada server open-source tambahan.
- (3) Keluaran berupa dokumen rencana ini.

### Batasan jujur (wajib dipahami)

Karena authorization server dan portal berada di aplikasi dan cookie sesi yang sama, perlindungan khas OAuth tidak memberi lapisan keamanan baru yang besar. Pencurian code antar-aplikasi, mix-up antar-server, dan federasi IdP tidak relevan di arsitektur satu aplikasi.

Manfaat nyata yang **tetap** didapat dan menjadi tujuan desain:
1. **Setiap sesi portal lahir dari satu "authorization transaction" yang tercatat.** `state` sekali pakai dengan TTL, PKCE, `redirect_uri` yang dicocokkan persis, dan audit `sso_authorization_completed`.
2. **Deep link asal disimpan di server**, di-key oleh `state`, bukan di URL atau di input user.
3. **Request authorize divalidasi sebelum form login ditampilkan.** `client_id` dan `redirect_uri` yang tidak terdaftar menghasilkan halaman error, bukan redirect, sehingga tidak ada open redirect.
4. **Fondasi siap pakai.** Bila kelak ada aplikasi ADASI lain, cukup didaftarkan sebagai client Passport baru dan langsung mendapat SSO, tanpa merombak login lagi.

Semua kontrol yang sudah ada tetap berlaku dan **tidak boleh melemah**: `LoginRequest` (rate limiter, Turnstile, dummy hash), 2FA, `CompleteLoginService`, known devices, batas sesi bersamaan, audit, `EnforceAuthSessionSecurity`, dan `EnforcePasswordChange`.

## 2. Kondisi Kode Saat Ini (hasil inspeksi)

- **Route** (`routes/web.php`):
  - `GET /` → `redirect()->route('login')` (≈L295).
  - `GET login` (`login`) dan `POST login` (`login.store`) dengan middleware `guest` + `no-store` (≈L682–689).
  - `two-factor-challenge` (`mfa.pending`), `confirm-password`, `PUT password`, `POST logout`.
  - Tidak ada `routes/auth.php`, reset password, maupun email verification.
- **Login:**
  - `AuthenticatedSessionController::store()` memanggil `LoginRequest::authenticate()`. User dengan 2FA diarahkan ke `TwoFactorService::beginLoginChallenge()`; selain itu `CompleteLoginService::complete()`.
  - Setelah itu redirect ke `TwoFactorService::safeIntendedUrl(session('url.intended'), dashboard)`.
  - `safeIntendedUrl()` (`app/Services/Auth/TwoFactorService.php` ≈L273–319) menerima URL host yang sama dan mempertahankan path+query. Path `oauth/*` dan `sso/*` tidak diblokir.
- **Satu titik akhir login:** `CompleteLoginService::complete()`. Isinya: `Auth::login`, `session()->regenerate()` (data sesi dipertahankan), `auth_session_version`, `auth_absolute_started_at`, known device, batas sesi bersamaan, dan auth splash.
- **Pending 2FA:** `auth.two_factor_pending` menyimpan `{user_id, remember, intended, started_at}` dengan TTL 600 detik. Jalur remember-cookie memanggil `invalidate()`, sehingga data sesi lain hilang.
- **`bootstrap/app.php`:**
  - Web group ditambah `ApplyUserLocale`, `ShareAuthSplashState`, `DecodeHashids`, `EnforceAuthSessionSecurity`, `EnforcePasswordChange`, `AddSecurityHeaders`, `EnforceSupplierDomain`, `LimitOperationalRequests`.
  - `redirectGuestsTo` **tidak diset**, jadi default ke `route('login')`. `redirectUsersTo` mengarah ke dashboard per role.
- **`EnforcePasswordChange`** mengecualikan `profile.security`, `password.update`, `logout`, `password.confirm`, `password.confirm.store`, dan `locale.*`.
- **`AddSecurityHeaders`:** CSP `form-action 'self'` dan `Referrer-Policy: strict-origin-when-cross-origin`. Keduanya cocok untuk redirect dan callback di origin yang sama.
- **`DecodeHashids`:** route Passport dan `/sso/*` tanpa parameter bernama di `HASHED_PARAM_KEYS` tidak terpengaruh.
- **`ShareAuthSplashState`** membaca nama route `login`, `login.store`, `two-factor.challenge(.store)`, dan `logout`.
- **User model:** belum memakai `HasApiTokens`. Belum ada `laravel/passport` dan belum ada tabel `oauth_*`. Guard yang ada hanya `web`.
- **Tes:** 17 file di `tests/Feature/Auth/`, plus ±40 file yang meng-assert redirect ke `route('login')` atau `GET /login`.
- **Temuan sampingan (di luar scope, laporkan saja):** `POST confirm-password` (≈L708) tidak punya nama route. Akibatnya pengecualian `password.confirm.store` di `EnforcePasswordChange` tidak pernah cocok, dan user dengan `must_change_password` kemungkinan diblokir saat submit konfirmasi password. Belum diverifikasi dengan tes.

## 3. Desain Alur

```
Browser                 Portal (aplikasi yang sama)
──────────────────────────────────────────────────────────────────────────
GET /dashboard (tanpa sesi)
  └─ auth → redirect ke route('login') = GET /login
GET /login   [SsoEntryController@start]
  ├─ buat state (PREFIX~acak 64 char base64url), code_verifier (acak 64 byte → base64url)
  ├─ simpan session sso.transactions[state] = {verifier, intended, created_at}
  └─ 302 → /oauth/authorize?client_id&response_type=code&redirect_uri&scope=openid
                         &state&code_challenge=BASE64URL(SHA256(verifier))&code_challenge_method=S256
GET /oauth/authorize   [SsoAuthorizeController@show]
  ├─ validasi authorization request (client, redirect_uri persis, PKCE S256 wajib, scope) → invalid: halaman error 400
  ├─ guest     → RENDER form login di URL panjang ini (url.intended = fullUrl)
  └─ login     → delegasi ke Passport AuthorizationController (skip consent first-party)
                 → 302 /sso/callback?code&state
POST /login  (login.store, tidak berubah) → 2FA bila perlu → CompleteLoginService
  └─ redirect ke url.intended = /oauth/authorize?... (lolos safeIntendedUrl)
GET /sso/callback   [SsoCallbackController]
  ├─ pull state (sekali pakai, TTL), wajib user login
  ├─ tukar code + code_verifier secara in-process (league AuthorizationServer, tanpa HTTP)
  ├─ pastikan token milik auth()->id(), lalu revoke access & refresh token
  ├─ session sso.verified_at, sso.client_id; audit sso_authorization_completed
  └─ 302 → safeIntendedUrl(intended) atau dashboard
```

**Gate sesi:** middleware `EnsureSsoSession` (web group, hanya bila `auth()->check()` dan fitur aktif). Sesi login tanpa `sso.verified_at` diarahkan ke `GET /login`, yang membuat transaksi baru dengan intended = URL saat ini. Karena user sudah login, Passport langsung menerbitkan code tanpa form. Tidak ada loop.

**Feature flag:** `SSO_LOGIN_ENABLED` (default `false`). Saat `false`, perilaku identik dengan sekarang: `GET /login` merender form seperti biasa dan gate nonaktif. Rollout jadi aman dan sebagian besar tes lama tidak perlu diubah.

## 4. Komponen yang Dibuat atau Diubah

### 4.1 Package dan setup Passport
- `composer require laravel/passport:<versi eksak>`. Versi major yang mendukung Laravel 12 (13.x) harus dikonfirmasi saat instalasi; pin versi eksak di `composer.json` sesuai aturan `AGENTS.md`. Alasan menambah package: kemampuan authorization server OAuth tidak ada di dependency yang sekarang.
- Publish migrasi Passport (`vendor:publish --tag=passport-migrations`). Migrasinya additive (tabel `oauth_*`) dan `down()` default Passport.
- `Passport::ignoreRoutes()` di `AppServiceProvider::boot()`, lalu daftarkan route Passport secara manual (lihat 4.3) agar `GET /oauth/authorize` dibungkus controller sendiri.
- `Passport::tokensCan(['openid' => 'Sign in to ADASI Portal'])` agar `scope=openid` valid.
- Masa berlaku token pendek: `tokensExpireIn(now()->addMinutes(10))`, `refreshTokensExpireIn(now()->addMinutes(10))`. Beri anotasi `// biz-time:ignore` sesuai `BusinessTimeGuardTest`.
- Model client custom `App\Models\Passport\Client` (`Passport::useClientModel`) dengan `skipsAuthorization()` bernilai `true` **hanya** untuk client id = `config('sso.client_id')`. Client lain tetap melihat layar consent.
- `User` memakai trait Passport `HasApiTokens` dan contract `OAuthenticatable` bila diwajibkan versi Passport tersebut. `HasHashids` tidak terpengaruh karena auth identifier tetap integer.
- Kunci: dibuat dengan `passport:keys`. Produksi memakai env `PASSPORT_PRIVATE_KEY`/`PASSPORT_PUBLIC_KEY` atau file di `storage/` dengan izin 600. **Jangan di-commit**; pastikan `.gitignore` mencakup `storage/*.key`.
- Client portal: `php artisan passport:client --public --name="ADASI Portal" --redirect_uri="${APP_URL}/sso/callback"`. Hasil `client_id` (UUID) disimpan di env `SSO_CLIENT_ID`. Sediakan juga command idempoten `sso:install-client` agar deploy dan tes konsisten.
- Scheduler: `passport:purge` harian di `routes/console.php`, memakai business timezone dan `withoutOverlapping()`.

### 4.2 Konfigurasi baru `config/sso.php`
`enabled` (`SSO_LOGIN_ENABLED`), `client_id` (`SSO_CLIENT_ID`), `state_prefix` (default `ADASI`, meniru prefix tenant Infor; boleh `ADASI_PRD`), `transaction_ttl_seconds` (600), `max_pending_transactions` (5, untuk multi-tab), `scope` (`openid`). Tambahkan ke `.env.example` dengan placeholder.

### 4.3 Route (`routes/web.php`, section auth)
- `GET /login`:
  - flag on → `SsoEntryController@start` (nama tetap `login`, supaya semua `route('login')` tetap valid);
  - flag off → controller lama.
  - Middleware tetap `guest` + `no-store`. User yang sudah login tetap dilempar `redirectUsersTo` ke dashboard; gate akan memulai transaksi bila perlu.
- `POST /login` (`login.store`): **tidak berubah**.
- `GET /oauth/authorize` → `SsoAuthorizeController@show` (nama `passport.authorizations.authorize`), middleware `web` + `no-store` + `throttle`.
- `POST|DELETE /oauth/authorize`: approve/deny Passport (`auth`), hanya relevan untuk client non-first-party.
- `POST /oauth/token`: Passport `AccessTokenController`, **di luar web group** (tanpa sesi/CSRF), dengan `throttle`.
- `GET /sso/callback` → `SsoCallbackController` (nama `sso.callback`), middleware `web` + `auth` + `no-store`.

### 4.4 Controller dan service baru (`app/Http/Controllers/Auth/`, `app/Services/Auth/Sso/`)
- `SsoTransactionStore`:
  - `begin(Request, ?intended)`: membuat state dan verifier dengan `random_bytes`, menghitung challenge S256, menyimpan transaksi (buang yang kedaluwarsa, batasi `max_pending`), lalu mengembalikan URL authorize.
  - `pull(Request, state)`: mengambil transaksi sekali pakai dengan perbandingan `hash_equals`; transaksi kedaluwarsa dianggap null.
  - `intended` disaring `TwoFactorService::safeIntendedUrl()` saat disimpan.
- `SsoEntryController@start`:
  - Ambil `url.intended` (yang diisi middleware `auth`) atau query `intended`, lalu saring dengan `safeIntendedUrl`.
  - Mulai transaksi dan redirect ke authorize.
- `SsoAuthorizeController@show`:
  - Validasi request lewat `League\OAuth2\Server\AuthorizationServer::validateAuthorizationRequest()` (PSR-7 bridge yang sudah dibawa Passport).
  - Tambahan validasi: `code_challenge_method=S256` wajib dan `response_type=code`. Bila invalid, render `auth.sso-error` (400) **tanpa redirect**.
  - Guest: simpan `url.intended = $request->fullUrl()`, lalu render `auth.login` (view yang sama, form tetap POST ke `login.store`, Turnstile tetap jalan).
  - Sudah login: delegasikan ke `Laravel\Passport\Http\Controllers\AuthorizationController::authorize()`.
- `SsoCallbackController`:
  - `error` dari server (mis. `access_denied`) → kembali ke `/login` dengan pesan.
  - `pull(state)`; null → redirect `/login` dengan `status = auth.feedback.sso_restart`, dan audit `sso_state_rejected`.
  - Tukar code in-process: bangun PSR-7 `POST` dengan `grant_type=authorization_code`, `client_id`, `redirect_uri`, `code`, `code_verifier`, lalu panggil `AuthorizationServer::respondToAccessTokenRequest()`. **Tidak melalui HTTP**, supaya tidak deadlock di `php artisan serve` atau worker tunggal.
  - Parse access token (JWT) dengan public key Passport, lalu cek `sub == auth()->id()` dan `aud == client_id`. Bila tidak cocok → logout, invalidate, dan audit `sso_subject_mismatch`.
  - Revoke access dan refresh token (repositori Passport). Token tidak dipakai lebih jauh karena kredensial portal tetap sesi Laravel.
  - Set `session(['sso.verified_at' => now()->getTimestamp(), 'sso.client_id' => …])`, kirim `AuthSecurityEvent('sso_authorization_completed')`, lalu redirect `safeIntendedUrl(intended, route('dashboard'))`.
- Middleware `EnsureSsoSession` (append ke web group setelah `EnforceAuthSessionSecurity`):
  - Aktif hanya bila flag on dan user login.
  - Pengecualian: `login*`, `two-factor.*`, `passport.*`, `sso.*`, `logout`, `profile.security`, `password.update`, `password.confirm`, `locale.*`, dan route publik/registrasi.
  - Request JSON/polling dibalas 401 JSON, mengikuti pola `bootstrap/app.php` yang sudah ada.

### 4.5 Integrasi dengan kode yang ada
- `AuthAuditLogger` / `AuthSecurityEvent`: tambahkan event `sso_authorization_completed`, `sso_state_rejected`, `sso_subject_mismatch`, `sso_invalid_authorization_request` ke allow-list metadata. Isinya client_id dan alasan, **tanpa** code, state, maupun verifier.
- `ShareAuthSplashState`: perlakukan `passport.authorizations.authorize` (guest) seperti `login`, dan `sso.callback` seperti post-login.
- `TwoFactorService::beginLoginChallenge()`: tidak diubah. Pada jalur remember-cookie (`invalidate()`), transaksi hilang dan callback me-restart alur dengan aman. Ini perilaku yang diterima dan diuji.
- `AuthenticatedSessionController::destroy()`: tidak berubah. Sesi hilang, termasuk `sso.*`, dan token sudah di-revoke saat callback.
- `SessionRevocationService`: tidak perlu diubah karena tidak ada token hidup.
- `resources/views/auth/login.blade.php`: tidak perlu berubah. Pastikan `old()` dan error tetap tampil setelah `back()` kembali ke URL authorize; `previous URL` sesi = URL authorize.
- View baru `resources/views/auth/sso-error.blade.php` memakai layout `auth`.

### 4.6 Terjemahan
Tambahkan key baru di `lang/en/auth.php` dan `lang/id/auth.php`: `feedback.sso_restart`, `sso.error_title`, `sso.invalid_request`, `sso.access_denied`, `sso.back_to_login`. Placeholder harus identik (`TranslationParityTest`).

## 5. Daftar File

| File | Perubahan |
|---|---|
| `composer.json` / `composer.lock` | + `laravel/passport` (versi eksak) |
| `database/migrations/*_oauth_*` | publish Passport (additive) |
| `config/sso.php`, `.env.example` | baru / placeholder |
| `app/Providers/AppServiceProvider.php` | konfigurasi Passport (ignoreRoutes, tokensCan, expiry, client model) |
| `app/Models/User.php` | `HasApiTokens` (+ contract bila diwajibkan) |
| `app/Models/Passport/Client.php` | baru, `skipsAuthorization` first-party |
| `app/Services/Auth/Sso/SsoTransactionStore.php` | baru |
| `app/Http/Controllers/Auth/Sso{Entry,Authorize,Callback}Controller.php` | baru |
| `app/Http/Middleware/EnsureSsoSession.php`, `bootstrap/app.php` | baru + append web group |
| `app/Console/Commands/SsoInstallClient.php`, `routes/console.php` | command client + `passport:purge` |
| `routes/web.php` | route login (flag), oauth, sso callback |
| `app/Http/Middleware/ShareAuthSplashState.php` | nama route baru |
| `app/Services/Auth/AuthAuditLogger.php` (allow-list) | event baru |
| `resources/views/auth/sso-error.blade.php` | baru |
| `lang/{en,id}/auth.php` | key baru |
| `docs/guides/SSO-OAUTH-LOGIN.md` | panduan deploy: keys, client, env, rollback (flag off) |
| `AGENTS.md` | bagian Auth: ringkas alur SSO dan flag (setelah implementasi selesai) |

## 6. Urutan Pengerjaan
1. Instal Passport, publish migrasi, konfigurasi provider, model, dan config `sso.php` dengan flag off. Pastikan seluruh `tests/Feature/Auth/` tetap hijau.
2. `SsoTransactionStore`, lalu unit test (format state, S256, TTL, sekali pakai, batas pending, penyaringan intended).
3. Route Passport manual, `SsoAuthorizeController`, dan view error, lalu feature test validasi authorize.
4. `SsoEntryController`, `SsoCallbackController`, dan exchange in-process, lalu feature test alur penuh.
5. `EnsureSsoSession`, splash, audit, dan terjemahan.
6. Command `sso:install-client`, `passport:purge` terjadwal, `docs/guides/SSO-OAUTH-LOGIN.md`, dan update `AGENTS.md`.

## 7. Kriteria Penerimaan
- [ ] Flag off: perilaku login identik dengan sebelumnya, dan semua tes Auth lama lulus tanpa diubah.
- [ ] Flag on: membuka `/` atau `/login` berakhir di form login dengan URL `/oauth/authorize?client_id=…&response_type=code&redirect_uri=…/sso/callback&scope=openid&state=ADASI~…&code_challenge=…&code_challenge_method=S256`.
- [ ] Login sukses (dengan maupun tanpa 2FA) → `/sso/callback` → deep link asal (atau dashboard).
- [ ] `client_id` atau `redirect_uri` tidak terdaftar, PKCE hilang, atau `plain` → halaman error 400 tanpa redirect.
- [ ] State dipalsukan, dipakai ulang, atau kedaluwarsa → alur di-restart, dengan audit.
- [ ] Code dipakai ulang atau verifier salah → ditolak, sesi tidak menjadi `sso.verified`.
- [ ] Tidak ada access/refresh token aktif tersisa setelah callback.
- [ ] Rate limit, Turnstile, 2FA, known device, batas sesi, dan forced password change tetap berlaku.
- [ ] Tidak ada code, state, verifier, maupun token yang masuk log audit.
- [ ] Teks baru tersedia dalam en dan id.

## 8. Verifikasi

```powershell
php artisan test --filter=TestingEnvironmentDatabaseSafetyTest
php artisan test tests/Feature/Auth --compact            # flag off: wajib tetap hijau
php artisan test --filter=SsoAuthorizationFlowTest       # baru, flag on
php artisan test --filter=SsoTransactionStoreTest        # baru
php artisan test --filter=HashidUrlSecurityTest
php artisan test --filter=SupplierDataIsolationTest
php artisan test tests/Feature/SupplierRegistration --compact
php artisan test tests/Unit/TranslationParityTest.php
php artisan test --filter=BusinessTimeGuardTest
php -l <file php yang diubah>; php vendor/bin/pint --test <file php yang diubah>
php artisan route:list --path=oauth --no-ansi; php artisan route:list --path=sso --no-ansi
php artisan view:cache
git diff --check
```

Skenario baru di `tests/Feature/Auth/SsoAuthorizationFlowTest.php` (flag on lewat `config()`):
- Guest `GET /dashboard` → `/login` → 302 ke authorize dengan parameter lengkap, dan challenge = S256(verifier tersimpan).
- Guest `GET authorize` → 200, form login, header no-store.
- `POST /login` → 302 ke authorize → 302 ke `/sso/callback?code&state` → dashboard.
- Deep link `/profile/customization` dipertahankan.
- User dengan 2FA: alur lewat `two-factor.challenge` lalu callback.
- Remember-cookie re-challenge → callback me-restart dengan aman.
- Authorize: client salah, redirect_uri salah, tanpa PKCE, `plain`, `response_type=token` → 400 tanpa header `Location`.
- Callback: state palsu, dipakai ulang, kedaluwarsa (`travel`), code dipakai ulang, `error=access_denied`, dan `sub` mismatch.
- Gate: sesi login tanpa `sso.verified_at` → restart; request JSON → 401.
- Tidak ada token aktif di `oauth_access_tokens` setelah callback.
- User `must_change_password` → `profile.security`, lalu setelah ganti password alur SSO berlanjut.

**Manual (belum bisa otomatis):** uji di browser nyata (Chrome/Edge) termasuk back button dan multi-tab, Laragon/Apache dan `php artisan serve`, deploy produksi dengan kunci dari env, serta HTTPS dengan cookie `secure`.

## 9. Rollback
Set `SSO_LOGIN_ENABLED=false`, lalu jalankan `php artisan config:clear`. Login kembali ke alur lama tanpa perlu rollback migrasi. Tabel `oauth_*` boleh tetap ada.
