# Auth Hardening — Phase 2 V2
## Repository-Reconciled Implementation Plan

**Status:** FINAL — requirement decisions locked  
**Repository:** `poyipoy/supplierportal`  
**Baseline branch:** `master`  
**Baseline commit:** `f3dc527772c89aa673b31cd5f52a259242021338`  
**Baseline commit message:** `feat(auth): harden login validation, multi-device sessions, and password reset`  
**Framework baseline:** Laravel `12.66.0`, PHP `^8.2`  
**Supersedes:** `AUTH-HARDENING-PHASE2-PLAN.md`  
**Scope:** Authentication hardening only. Do not alter MFA business flow, role model, supplier business modules, export implementation, or unrelated UI.

---

# 1. Executive Summary

Implementation plan lama tidak boleh dieksekusi langsung karena `master` sudah berubah pada commit `f3dc527`. Commit tersebut sudah mengimplementasikan sebagian Phase 2, tetapi beberapa implementasi berbeda dari requirement final dan ada dua gap security yang harus diremediasi.

V2 ini menyelaraskan implementation plan dengan codebase aktual dan keputusan final:

1. Maksimum **3 concurrent sessions** per account.
2. Login dari device yang **belum pernah digunakan oleh account tersebut** harus memicu:
   - in-app notification, dan
   - email security notification.
3. Account `is_active = false`:
   - tidak menerima email reset password,
   - tidak boleh menyelesaikan reset password.
4. Production queue worker `exports,default` dianggap sudah aktif.
5. Login protection harus mencakup **distributed credential stuffing dari banyak IP**.
6. Tidak mengubah atau menambah MFA.
7. Existing architecture dipertahankan; perubahan harus sekecil mungkin dan hanya membuat abstraction baru jika memang dipakai lintas beberapa call site.

V2 memiliki empat tujuan utama:

- menghapus custom timing equalizer yang redundant dan berpotensi menciptakan timing asymmetry baru;
- menambah global failed-login velocity brake tanpa global lockout;
- mengganti heuristic “new device = tidak ada active session IP+UA” menjadi persistent known-device registry;
- menutup account enumeration di `POST /reset-password`.

---

# 2. Requirement Decisions — Locked

Keputusan berikut dianggap final dan tidak perlu ditanyakan ulang saat implementasi.

| Area | Keputusan |
|---|---|
| Concurrent session limit | Maksimum **3** active sessions |
| New device alert | **In-app + email** |
| Definisi new device | Account belum pernah berhasil login dari browser/device identity tersebut |
| Device recognition | Persistent first-party device identifier; bukan IP/User-Agent fingerprint |
| Inactive account reset email | Tidak dikirim |
| Inactive account password reset | Ditolak |
| Queue production | `database`, worker `exports,default` aktif |
| Distributed credential stuffing | Harus dilindungi |
| Global anomaly response | Force Turnstile; **jangan** global 429/lockout |
| MFA | Tidak diubah |
| Password policy | Tidak diubah |
| Existing session versioning | Dipertahankan |

---

# 3. Verified Repository Baseline

Baseline yang harus dianggap sebagai titik awal implementasi:

## 3.1 Auth / framework

- Laravel framework terkunci pada `v12.66.0`.
- `LoginRequest` menggunakan `Auth::guard('web')->once(...)`.
- Laravel `SessionGuard::once()` menggunakan `validate()`.
- Laravel 12 `SessionGuard::validate()` sudah memiliki framework `Timebox` default 200 ms untuk failed validation path.
- Current repo menambahkan `App\Support\Auth\TimingSafeAuth` sesudah failed `Auth::once()` untuk unknown/inactive account.
- Custom equalizer tersebut tidak diperlukan dan harus dihapus.

## 3.2 Login rate limiting

Current `LoginRateLimiter` sudah mempunyai:

- email + IP combination limiter;
- email limiter;
- IP limiter;
- Turnstile trigger;
- distinct-email-per-IP tracker;
- repeated lockout alert.

Gap:

- distinct-email-per-IP hanya menangkap wide attack dari satu IP;
- belum ada application-wide failed-login brake untuk distributed credential stuffing dari banyak IP;
- distinct-email tracker saat ini direkam sebelum hasil authentication diketahui, sehingga successful login ikut berkontribusi.

## 3.3 Sessions

Current repo:

- `SESSION_DRIVER=database` adalah production baseline;
- tabel `sessions` menyimpan:
  - `id`
  - `user_id`
  - `ip_address`
  - `user_agent`
  - `payload`
  - `last_activity`;
- profile sudah memiliki Active Sessions list;
- user dapat revoke single other session;
- `SessionRevocationService` memiliki concurrent-session enforcement;
- max concurrent sessions config default sudah `3`.

Gap:

- query session masih tersebar di controller/service;
- sebagian query hardcode `DB::table('sessions')`;
- Active Sessions tidak memfilter row yang secara idle lifetime sudah expired;
- new-device detection saat ini memakai active session IP+User-Agent, bukan persistent known-device history.

## 3.4 Login completion / MFA integration

`CompleteLoginService` dipanggil:

- langsung setelah password auth untuk account tanpa MFA;
- **setelah MFA challenge berhasil** untuk account dengan MFA.

Implikasi:

- known-device registration harus dilakukan di `CompleteLoginService`;
- device tidak boleh ditandai known sebelum MFA selesai.

## 3.5 Password reset

Current repo sudah:

- normalize email pada `/forgot-password`;
- return generic response dari `/forgot-password`;
- queue reset email (`AdasiResetPasswordNotification implements ShouldQueue`);
- tidak mengirim reset notification untuk inactive account;
- menolak inactive account saat menyelesaikan reset.

Gap yang masih terbuka:

`NewPasswordController` masih membedakan:

- `Password::INVALID_TOKEN`
- `Password::INVALID_USER`

dengan message berbeda.

Ini masih memungkinkan account enumeration melalui `POST /reset-password`.

## 3.6 Notification infrastructure

Existing `SystemNotification` sudah menggunakan:

- `database`
- `broadcast`

dan merupakan mechanism existing untuk in-app notification.

Current `NewDeviceLoginNotification` menggunakan:

- `mail`
- `ShouldQueue`

Jangan mengganti infrastructure ini. Gunakan keduanya pada new-device event.

## 3.7 Production runtime

`.env.example` dan cPanel runtime menggunakan:

- `CACHE_STORE=database`
- `SESSION_DRIVER=database`
- `QUEUE_CONNECTION=database`

Queue cron worker memproses:

- `exports`
- `default`

sehingga mail security notification dapat tetap menggunakan default queue.

---

# 4. Security Invariants

Seluruh implementation harus mempertahankan invariant berikut.

## 4.1 Authentication

1. Unknown email, inactive account, dan wrong password tetap menghasilkan generic login error yang sama.
2. Jangan menambahkan custom bcrypt/dummy hash setelah `Auth::once()` gagal.
3. Jangan membypass Laravel guard.
4. Jangan mengubah MFA.
5. Jangan mengubah remember-me semantics.
6. Turnstile global brake tidak boleh menjadi global lockout.

## 4.2 Device recognition

1. Device identifier bukan authentication credential.
2. Device identifier tidak boleh dapat digunakan untuk login.
3. Device identifier tidak boleh disimpan raw di database.
4. Device identifier tidak boleh ditulis ke log, audit metadata, exception context, atau notification.
5. IP dan User-Agent tidak boleh menjadi device primary identity.
6. New device baru diregister setelah complete authenticated login.
7. MFA account baru diregister setelah MFA sukses.
8. Known-device history tidak dibatasi oleh concurrent-session limit.
9. Logout tidak menghapus known-device identity.
10. Password change/reset tidak otomatis menghapus known-device identity.

## 4.3 Session isolation

1. User A tidak boleh membaca/revoke session User B.
2. Current session tidak boleh direvoke lewat endpoint single-session revoke.
3. Session cap harus menghitung session yang belum idle-expired saja.
4. Global `auth_session_version` tetap dipakai untuk revoke massal.
5. Selective revoke tetap menggunakan deletion row session, bukan version bump.

## 4.4 Password reset

1. Forgot-password response tidak membocorkan account existence.
2. Reset-password invalid-user dan invalid-token response harus sama.
3. Inactive account tidak menerima reset email.
4. Inactive account tidak dapat mengganti password walaupun memiliki stale valid token.
5. Reset mail tetap queued.
6. Jangan menambahkan manual controller Timebox 800 ms.

---

# 5. Scope Classification

## 5.1 KEEP — tidak diubah kecuali komentar/test terkait

- `app/Services/Auth/TwoFactorService.php`
- `app/Http/Middleware/EnsurePendingTwoFactorChallenge.php`
- `app/Http/Controllers/Auth/ProfileTwoFactorController.php`
- existing MFA routes
- existing password policy
- existing `auth_session_version`
- `LogoutOtherDevicesController`
- `SystemNotification`
- `AdasiResetPasswordNotification`
- `PasswordResetLinkController` behavior
- `User::sendPasswordResetNotification()` inactive-account guard
- `routes/auth.php` existing single-session route
- repeated lockout alert feature
- existing `AuthSecurityEvent` listener architecture

## 5.2 DELETE

### `app/Support/Auth/TimingSafeAuth.php`

Reason:

Laravel 12.66.0 sudah memberi failed-path Timebox di `SessionGuard::validate()`. Custom dummy bcrypt sesudah failed `Auth::once()` redundant dan dapat membuat unknown/inactive path lebih lambat daripada wrong-password path.

### `tests/Unit/Support/Auth/TimingSafeAuthTest.php`

Reason:

Class yang diuji dihapus. Jangan mengganti dengan wall-clock timing test yang flaky.

## 5.3 NEW

1. `database/migrations/2026_08_28_000001_create_auth_known_devices_table.php`
2. `app/Services/Auth/KnownDeviceService.php`
3. `app/Services/Auth/SessionInventoryService.php`
4. `tests/Feature/Auth/KnownDeviceSecurityTest.php`

## 5.4 MODIFY

- `app/Http/Requests/Auth/LoginRequest.php`
- `app/Services/Auth/LoginRateLimiter.php`
- `config/auth_security.php`
- `app/Models/AuthAuditLog.php`
- `app/Services/Auth/CompleteLoginService.php`
- `app/Services/Auth/SessionRevocationService.php`
- `app/Http/Controllers/Auth/RevokeSessionController.php`
- `app/Http/Controllers/ProfileController.php`
- `app/Notifications/NewDeviceLoginNotification.php`
- `resources/views/profile/partials/active-sessions.blade.php`
- `app/Http/Controllers/Auth/NewPasswordController.php`
- `.env.example`
- `tests/Feature/Auth/LoginSecurityTest.php`
- `tests/Feature/Auth/AuthRateLimitingTest.php`
- `tests/Feature/Auth/PasswordResetTest.php`
- `tests/Feature/Auth/SessionSecurityTest.php`
- `docs/guides/AUTH-SECURITY-DEPLOYMENT.md`
- `docs/guides/CPANEL-PERFORMANCE-RUNTIME.md`

---

# 6. Phase 0 — Remove Incorrect Timing Equalizer

## Objective

Kembalikan login failed-path timing responsibility ke Laravel framework dan hapus code custom yang redundant.

## 6.1 Modify `LoginRequest`

Remove:

```php
use App\Support\Auth\TimingSafeAuth;
```

Remove seluruh block:

```php
if (! User::where('email', $email)->where('is_active', true)->exists()) {
    TimingSafeAuth::equalize();
}
```

Do not replace it with:

- `Hash::check()` dummy;
- `usleep`;
- custom `Timebox`;
- extra Eloquent existence query.

Flow harus kembali menjadi:

1. normalize email;
2. rate-limit / Turnstile checks;
3. `Auth::guard('web')->once(...)`;
4. failed attempt accounting;
5. generic validation error.

## 6.2 Delete helper

Delete:

`app/Support/Auth/TimingSafeAuth.php`

## 6.3 Tests

Delete tests yang memastikan dummy `Hash::check()` terjadi pada:

- unknown account;
- inactive account.

Pertahankan / tambah functional invariant test bahwa error message untuk:

- unknown email;
- inactive account;
- registered account + wrong password

identik.

### Do not write

Wall-clock assertion seperti:

```php
$this->assertLessThan(50, abs($a - $b));
```

Test seperti itu machine-dependent dan flaky.

---

# 7. Phase 1 — Distributed Credential-Stuffing Protection

## Objective

Tambahkan global failed-login velocity brake yang menangkap attack pattern:

- banyak email,
- banyak IP,
- masing-masing berada di bawah per-identity threshold.

Brake **tidak boleh** menghasilkan application-wide 429.

## 7.1 Config

Modify `config/auth_security.php`.

Tambahkan di bawah `login`:

```php
'global' => [
    'attempts' => (int) env('AUTH_GLOBAL_FAILED_LOGIN_THRESHOLD', 200),
    'decay_seconds' => (int) env('AUTH_GLOBAL_FAILED_LOGIN_WINDOW', 300),
],
```

Keep:

- `combination`
- `email`
- `ip`
- `distinct_email`
- `repeated_lockout_alert`

Default V2:

- threshold: `200`
- window: `300 seconds`

Ini adalah conservative starting point. Calibration production dapat dilakukan kemudian dari auth audit volume tanpa mengubah architecture.

## 7.2 Change distinct-email semantics

Current behavior merekam distinct email sebelum authentication outcome.

Ubah sehingga distinct-email tracker hanya di-update pada failed attempt.

Recommended structure:

```text
LoginRequest
  -> ensureNotLimited()
  -> requiresTurnstile()
  -> validate Turnstile if needed
  -> Auth::once()
      -> success: clear normal identity counters as today
      -> failure: LoginRateLimiter::hit()
```

`LoginRateLimiter::hit()` harus menjadi satu tempat untuk failed-attempt accounting:

1. hit combination limiter;
2. hit email limiter;
3. hit IP limiter;
4. record distinct failed email for IP;
5. hit global failed-login counter;
6. optionally emit global anomaly audit event once/window.

Dengan struktur ini:

- successful login tidak menambah distinct-email set;
- successful login tidak menambah global failed-login counter.

## 7.3 Global counter isolation

Global definition **jangan** dimasukkan ke `definitions()` yang dipakai `ensureNotLimited()`.

Reason:

`ensureNotLimited()` menghasilkan 429.

Global threshold hanya boleh mempengaruhi:

`requiresTurnstile()`.

Pseudo-contract:

```php
public function requiresTurnstile(Request $request, string $email): bool
{
    return perEmailThresholdExceeded
        || perIpThresholdExceeded
        || distinctFailedEmailThresholdExceeded
        || globalFailedLoginThresholdExceeded;
}
```

## 7.4 Success behavior

`clearAfterSuccess()`:

- clear combination;
- clear email;
- jangan clear IP;
- jangan clear distinct-failed-email cache;
- jangan clear global failed-login counter.

Satu successful attacker credential tidak boleh menghapus global anomaly state.

## 7.5 Global anomaly audit

Add event to `AuthAuditLog::EVENTS`:

```text
global_login_anomaly_detected
```

Ketika global threshold pertama kali terlampaui dalam satu window:

- emit satu `AuthSecurityEvent`;
- metadata minimal:
  - `count`.

Gunakan cache marker / `Cache::add()` dengan TTL yang sama agar tidak menulis audit row pada setiap request selama brake aktif.

Do not:

- send email ke seluruh admin pada setiap threshold;
- log attempted password;
- log Turnstile token;
- global-lock accounts.

## 7.6 Behavior when Turnstile is not configured

Existing fail-open semantics tetap dipertahankan.

Global brake tidak boleh tiba-tiba membuat login impossible jika Cloudflare keys kosong.

Operational requirement production:

Turnstile keys harus tetap divalidasi lewat existing deployment process.

---

# 8. Phase 2 — Persistent Known-Device Registry

## Objective

Definisi “new device” harus berubah dari:

> tidak ada active session dengan IP + User-Agent yang sama

menjadi:

> browser/device identity ini belum pernah sukses digunakan untuk login ke account tersebut.

IP dan User-Agent hanya metadata.

---

# 9. Known-Device Identity Design

## 9.1 Identifier

Gunakan random first-party identifier:

- 256-bit random token;
- recommended representation: `bin2hex(random_bytes(32))`;
- length 64 hex chars.

Cookie example name:

```text
adasi_known_device
```

Cookie properties:

- first-party;
- HttpOnly = true;
- Secure = mengikuti secure session / production HTTPS;
- SameSite = `lax`;
- Path = `/`;
- Domain = mengikuti `SESSION_DOMAIN`;
- JavaScript tidak boleh membaca cookie;
- Laravel cookie encryption middleware tetap digunakan;
- sliding lifetime: **400 days**.

400 days dipilih sebagai long-lived browser identity window dan direfresh pada successful login.

### Limitation yang harus didokumentasikan

Tidak ada privacy-safe browser identifier yang bisa menjamin “device ini selamanya” jika user:

- clear cookies;
- memakai private/incognito profile;
- reinstall browser;
- membuat browser profile baru;
- browser menghapus cookie karena policy.

Dalam kasus tersebut device diperlakukan sebagai new device lagi. Ini acceptable dan security-safe.

Jangan mengganti mekanisme ini dengan aggressive browser fingerprinting.

## 9.2 Database storage

Raw token **tidak disimpan**.

Database menyimpan:

```text
SHA-256(raw device token)
```

Table:

`auth_known_devices`

Recommended schema:

```php
Schema::create('auth_known_devices', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')
        ->constrained()
        ->cascadeOnDelete();

    $table->char('device_hash', 64);

    $table->string('last_ip_address', 45)->nullable();
    $table->string('last_user_agent', 512)->nullable();

    $table->timestamp('first_seen_at');
    $table->timestamp('last_seen_at');

    $table->unique(['user_id', 'device_hash']);
});
```

No:

- raw token;
- password;
- MFA secret;
- session payload;
- reset token.

Known-device record bersifat **per account**.

Browser token yang sama dapat digunakan oleh User A dan User B, tetapi masing-masing mempunyai row sendiri karena uniqueness:

```text
(user_id, device_hash)
```

## 9.3 Migration naming

Create:

`database/migrations/2026_08_28_000001_create_auth_known_devices_table.php`

Migration harus additive.

`down()` boleh drop table untuk local/test rollback, tetapi production rollback setelah table terpakai sebaiknya meninggalkan table.

## 9.4 No historical backfill

Jangan mencoba fingerprint existing `sessions` menjadi known-device record.

Session lama tidak memiliki persistent browser token yang dapat dipercaya.

Expected rollout behavior:

- successful login pertama dari setiap browser setelah V2 rollout dapat memicu one-time new-device alert;
- setelah cookie + DB record terbentuk, login berikutnya dari browser tersebut tidak alert.

Ini harus disebutkan di deployment notes agar one-time alert tidak dianggap incident.

---

# 10. `KnownDeviceService`

Create:

`app/Services/Auth/KnownDeviceService.php`

## Responsibilities

Hanya bertanggung jawab untuk:

1. membaca valid device cookie;
2. membuat token jika cookie missing/invalid;
3. hash token;
4. determine known/new untuk current user;
5. create/touch known-device row;
6. refresh queued device cookie;
7. return whether this successful login is new device.

Recommended API:

```php
public function registerOrTouch(Request $request, User $user): bool;
```

Return:

- `true` = device baru untuk account;
- `false` = device sudah pernah digunakan.

## 10.1 Processing

Pseudo-flow:

```text
read cookie
    |
    +-- missing / malformed
    |      -> generate random token
    |
    +-- valid
           -> reuse token

device_hash = sha256(token)

lookup auth_known_devices
where user_id = current user
and device_hash = hash

if exists:
    update last_seen_at
    update last_ip_address
    update last_user_agent
    is_new = false
else:
    insert row
    first_seen_at = now
    last_seen_at = now
    metadata = current request
    is_new = true

queue same/new cookie with sliding lifetime

return is_new
```

## 10.2 Race safety

Database unique constraint wajib menjadi final protection against duplicate `(user_id, device_hash)`.

Implementation jangan melakukan:

```text
exists()
then insert()
```

tanpa handling race.

Gunakan database-safe create-or-first / insert-ignore pattern yang tetap dapat menentukan apakah record benar-benar baru.

Jika menggunakan query builder, pastikan duplicate race tidak menyebabkan login gagal.

## 10.3 Failure behavior

Known-device instrumentation **tidak boleh mengubah valid credentials menjadi login failure** karena secondary alert subsystem error.

Recommended behavior:

- unexpected known-device persistence error:
  - log server-side exception class/context tanpa raw token;
  - login tetap dapat selesai;
  - jangan menandai known secara palsu.

Namun migration/table availability harus divalidasi pre-deployment. Fail-open ini bukan pengganti deployment discipline.

## 10.4 Token logging prohibition

Never log:

- raw cookie token;
- device hash;
- encrypted cookie value.

Audit hanya menyimpan normal request IP/User-Agent melalui `AuthAuditLogger`.

---

# 11. Integrate Known Device into `CompleteLoginService`

Modify:

`app/Services/Auth/CompleteLoginService.php`

## 11.1 Remove current heuristic

Delete:

- `hasMatchingActiveSession()`;
- direct `DB::table('sessions')` lookup untuk IP+UA;
- comment yang mendefinisikan new device dari matching active session.

## 11.2 Inject services

Recommended dependencies:

```php
public function __construct(
    private readonly KnownDeviceService $knownDevices,
    private readonly SessionInventoryService $sessions,
) {}
```

`SessionRevocationService` tidak perlu dipakai oleh CompleteLoginService setelah concurrent-limit logic dipindahkan ke SessionInventoryService.

## 11.3 Required order

Inside `complete()`:

```text
1. mark auth_security.login_completed
2. Auth::guard('web')->login(...)
3. session()->regenerate()
4. store auth_session_version
5. store auth_absolute_started_at
6. register/touch known device
7. enforce concurrent active-session cap = 3
8. audit concurrent eviction if any
9. if device is new:
      a. audit new_device_login
      b. send in-app SystemNotification
      c. queue NewDeviceLoginNotification mail
```

Do not register device before steps 1-5.

Critical MFA reason:

`CompleteLoginService` is reached only after valid MFA for MFA-enabled accounts.

---

# 12. New-Device Notification — Both Channels

## 12.1 In-app

Reuse existing:

`App\Notifications\SystemNotification`

Do not create a second database/broadcast notification abstraction.

Suggested content:

Title:

```text
New sign-in detected
```

Message:

```text
Your account was signed in on a device that has not been used with this account before.
```

URL:

```text
/profile#active-sessions
```

Icon:

use an existing verified icon such as:

```text
monitor
```

In-app notification behavior:

- database notification is created;
- existing broadcast mechanism continues to drive notification bell/realtime behavior.

## 12.2 Email

Keep and update:

`app/Notifications/NewDeviceLoginNotification.php`

Keep:

- `ShouldQueue`;
- mail channel;
- current queue default.

Change copy so it no longer says device/location heuristic.

Email should state:

- a device not previously used by this account signed in;
- IP address;
- User-Agent/device string;
- timestamp;
- link to Profile / Active Sessions;
- recommendation to sign out suspicious session and change password.

IP is informational only and must not be described as device identity.

## 12.3 Audit

Keep event:

```text
new_device_login
```

Emit once per newly-created account/device association.

Do not audit again on every login from a known device.

---

# 13. Phase 3 — Session Inventory Reconciliation

## Objective

Retain current Active Sessions functionality but centralize database-session semantics and ignore idle-expired rows.

## 13.1 Create `SessionInventoryService`

Create:

`app/Services/Auth/SessionInventoryService.php`

Responsibilities:

```php
activeSessionsFor(User $user, ?string $currentSessionId): Collection;
revoke(User $user, string $sessionId): bool;
enforceConcurrentLimit(User $user, ?string $currentSessionId = null): int;
```

## 13.2 Session query source

Do not hardcode table/connection.

Use:

```php
DB::connection(config('session.connection'))
    ->table(config('session.table', 'sessions'));
```

This preserves Laravel session config.

## 13.3 Active cutoff

Compute:

```text
now - config('session.lifetime') minutes
```

Only rows:

```text
last_activity >= cutoff
```

are considered active for:

- profile list;
- concurrent cap.

Do not decode encrypted session payload.

Absolute-timeout middleware remains the final authority when a session makes its next request.

## 13.4 `activeSessionsFor`

Return most recent first.

Current display contract can stay:

- `id`
- `is_current`
- `ip_address`
- `user_agent`
- `last_active_at`

Keep existing limit `20` as defensive display cap, walaupun normal state maksimum 3.

## 13.5 `revoke`

Delete only:

```text
where user_id = authenticated user
and id = requested session id
```

No cross-user lookup.

Current-session rejection tetap di controller sebelum service revoke.

## 13.6 Concurrent limit

Final max:

```text
3
```

Config key tetap:

```text
auth_security.session.max_concurrent_sessions
```

Algorithm saat new login:

- current regenerated session counts as one slot;
- current database session row biasanya belum persisted sampai end-of-request;
- therefore keep maximum `limit - 1` active old sessions;
- evict active old sessions ordered by oldest `last_activity`;
- ignore expired session rows;
- do not bump `auth_session_version`.

For limit 3:

```text
new login = 1 slot
keep 2 newest active old sessions
evict the rest
```

Return number evicted.

## 13.7 Refactor existing `SessionRevocationService`

Keep:

```text
revokeOtherSessions()
```

Move/remove:

```text
enforceConcurrentLimit()
```

from this service once `SessionInventoryService` owns selective database-session operations.

Reason:

- `SessionRevocationService` remains mass version-based revoke;
- `SessionInventoryService` handles per-row database session inventory.

Do not leave duplicate enforcement methods.

---

# 14. Refactor Session Consumers

## 14.1 `ProfileController`

Replace private `DB::table('sessions')` implementation with injected `SessionInventoryService`.

Expected `edit()`:

```text
activeSessions = sessionInventory.activeSessionsFor(
    current user,
    current session id
)
```

Remove no-longer-needed:

- `DB` import;
- `Carbon` import;
- `Collection` import if no longer used.

## 14.2 `RevokeSessionController`

Keep current-session protection:

```php
hash_equals($request->session()->getId(), $sessionId)
```

Replace direct table delete with:

```text
SessionInventoryService::revoke()
```

Keep:

- auth user scoping;
- current audit event `session_revoked`;
- existing redirect/status semantics.

## 14.3 `CompleteLoginService`

Use:

```text
SessionInventoryService::enforceConcurrentLimit()
```

If evicted > 0:

keep existing audit event:

```text
concurrent_session_limit_enforced
```

with metadata count.

---

# 15. Active Sessions UI

Modify:

`resources/views/profile/partials/active-sessions.blade.php`

## Keep

- current styling system;
- `tw-` Tailwind prefix;
- existing card/list layout;
- “This device” indicator;
- sign-out button;
- text that maximum 3 sessions can stay active.

## Add

Root anchor:

```html
<section id="active-sessions">
```

so both email and in-app notification can link directly to the list.

## Do not add

- historical known-device list;
- device fingerprint UI;
- revoke-known-device feature;
- location lookup service;
- external User-Agent package.

These are non-goals for Phase 2 V2.

---

# 16. Phase 4 — Password Reset Enumeration Fix

## Objective

Make `POST /reset-password` failure indistinguishable between:

- unknown email;
- invalid token;
- expired token;
- inactive account with blocked reset.

## 16.1 Modify `NewPasswordController`

Current distinct errors must be replaced.

Target:

```php
$message = match ($status) {
    Password::PASSWORD_RESET => 'Password has been reset successfully.',
    default => 'This password reset link is invalid or has expired.',
};
```

Keep success behavior exactly as current.

Keep inactive-account logic exactly as final policy:

- callback does not change password;
- status is forced to failed generic state;
- audit event remains;
- stale valid token is consumed/deleted by broker flow.

## 16.2 Do not change forgot-password controller timing

Do not add:

```text
AUTH_PASSWORD_RESET_TIMEBOX_MS
```

Do not inject controller-level `Timebox`.

Laravel 12.66.0 `PasswordBroker::sendResetLink()` already has framework Timebox.

Reset notification remains queued, so SMTP round-trip is not on request path with production database queue.

## 16.3 Keep email normalization

Existing forgot-password normalization stays.

No need to repeat same normalization implementation elsewhere unless currently missing.

---

# 17. Phase 5 — Config and Environment

## 17.1 `config/auth_security.php`

Final relevant config:

```php
'login' => [
    'combination' => [...],
    'email' => [...],
    'ip' => [...],

    'distinct_email' => [
        'threshold' => (int) env('AUTH_DISTINCT_EMAIL_THRESHOLD', 5),
        'window_seconds' => (int) env('AUTH_DISTINCT_EMAIL_WINDOW', 300),
    ],

    'global' => [
        'attempts' => (int) env('AUTH_GLOBAL_FAILED_LOGIN_THRESHOLD', 200),
        'decay_seconds' => (int) env('AUTH_GLOBAL_FAILED_LOGIN_WINDOW', 300),
    ],

    'repeated_lockout_alert' => [...],
],

'session' => [
    'absolute_timeout_minutes' => ...,
    'max_concurrent_sessions' => (int) env('AUTH_MAX_CONCURRENT_SESSIONS', 3),
],

'known_device' => [
    'cookie_name' => env('AUTH_KNOWN_DEVICE_COOKIE', 'adasi_known_device'),
    'lifetime_days' => (int) env('AUTH_KNOWN_DEVICE_LIFETIME_DAYS', 400),
],
```

Do not introduce password-reset custom Timebox config.

## 17.2 `.env.example`

Add explicit production-documentation variables:

```dotenv
AUTH_MAX_CONCURRENT_SESSIONS=3

AUTH_DISTINCT_EMAIL_THRESHOLD=5
AUTH_DISTINCT_EMAIL_WINDOW=300

AUTH_GLOBAL_FAILED_LOGIN_THRESHOLD=200
AUTH_GLOBAL_FAILED_LOGIN_WINDOW=300

AUTH_REPEATED_LOCKOUT_ALERT_THRESHOLD=3

AUTH_KNOWN_DEVICE_COOKIE=adasi_known_device
AUTH_KNOWN_DEVICE_LIFETIME_DAYS=400
```

Keep queue:

```dotenv
QUEUE_CONNECTION=database
```

No secret value is introduced by known-device config.

Raw device token is browser-generated server-side per client and must never be placed in `.env`.

---

# 18. Audit Events

`AuthAuditLog::EVENTS` final additions/retentions relevant to V2:

Keep:

```text
new_device_login
concurrent_session_limit_enforced
session_revoked
password_reset_blocked_inactive_account
repeated_lockouts_detected
```

Add:

```text
global_login_anomaly_detected
```

No new migration required for audit table.

`AuthAuditLogger` already:

- captures request IP;
- captures request User-Agent;
- sanitizes metadata;
- catches audit write failures.

No raw device identifier should be added to allowed metadata.

---

# 19. Detailed Test Plan

Tests are mandatory. Current commit has no CI status evidence, so local/staging test evidence must be produced before production deployment.

---

# 20. Login Security Tests

Modify:

`tests/Feature/Auth/LoginSecurityTest.php`

## Required cases

### L1 — generic login failure

Given:

- inactive user;
- unknown user;
- active user + wrong password.

Assert:

- exact same visible error;
- none authenticated;
- no remember cookie on failure.

### L2 — remove dummy-hash expectation

Delete tests:

- `test_hash_check_runs_even_when_the_account_does_not_exist`
- `test_hash_check_runs_even_for_a_deactivated_account`

or equivalent tests added by `TimingSafeAuth`.

### L3 — normal Turnstile still works

Existing test remains:

- per-account threshold reached;
- valid Turnstile token permits valid login.

### L4 — distributed global brake

Set:

```text
global threshold = 3
```

Generate failures across:

- different emails;
- different IPs.

Assert:

- each per-email/per-IP limiter remains under 429 threshold;
- global threshold triggers `requiresTurnstile()`.

### L5 — global brake is not 429

With global brake active:

- use valid credentials;
- valid Turnstile token.

Assert:

- login succeeds;
- response is not 429.

### L6 — successful login does not increment global counter

Perform valid login before threshold.

Assert global attempts unchanged.

### L7 — successful distinct users behind same IP do not trigger distinct-email defense

Login successfully using several valid accounts from same IP.

Assert:

- no distinct-failed-email threshold side effect;
- Turnstile is not forced only because many legitimate users share NAT IP.

### L8 — global anomaly audit de-duplication

Cross threshold multiple times in same window.

Assert:

- `global_login_anomaly_detected` audit event written once/window.

---

# 21. Rate Limiting Tests

Modify:

`tests/Feature/Auth/AuthRateLimitingTest.php`

Keep existing named-limiter tests.

Add explicit invariant:

Global counter:

- is not returned in `ensureNotLimited()` blocking definitions;
- cannot independently produce 429.

Existing:

- password-reset-link limiter;
- password-reset submit limiter;
- credentials limiter;
- MFA limiter;
- security-action limiter

must remain unchanged.

---

# 22. Known Device Security Tests

Create:

`tests/Feature/Auth/KnownDeviceSecurityTest.php`

Use `RefreshDatabase`.

## KD1 — first successful login registers device

Given user with no known device.

Successful login.

Assert:

- one `auth_known_devices` row;
- `first_seen_at` populated;
- `last_seen_at` populated;
- last IP/UA saved;
- device cookie queued.

## KD2 — raw token is not stored

Capture device cookie token in test context.

Assert database contains:

```text
sha256(token)
```

and not raw token.

## KD3 — first device triggers both notifications

On first successful login:

assert notification to user:

- `SystemNotification`;
- `NewDeviceLoginNotification`.

Assert audit:

```text
new_device_login
```

## KD4 — second login from same device does not alert

Reuse same device cookie.

Successful second login.

Assert:

- known-device row count remains 1;
- no new-device notifications;
- `last_seen_at` updated.

## KD5 — IP change does not make device new

Reuse same device token from different IP.

Assert:

- no new-device alert;
- metadata last IP updates.

## KD6 — User-Agent change does not automatically make device new

Reuse same device token with changed UA.

Assert:

- still known;
- metadata updates.

This proves IP/UA are not identity.

## KD7 — same User-Agent without known cookie is new

Use same UA but no/replaced device cookie.

Assert new device.

## KD8 — same browser token is per account

Use same token:

- User A logs in -> A known;
- User B logs in -> B still new.

Assert separate rows.

## KD9 — inactive user cannot create known-device row

Failed inactive login.

Assert no known-device record.

## KD10 — MFA pending does not register device

For MFA-enabled user:

- password step succeeds and redirects to MFA challenge.

Before MFA completion:

- no known-device row;
- no new-device alert.

After valid MFA:

- row created;
- both alerts sent once.

## KD11 — malformed cookie is replaced

Provide malformed/oversized known-device cookie.

Assert:

- ignored;
- new secure token issued;
- no exception;
- device treated new.

## KD12 — cookie security attributes

Inspect `Set-Cookie`.

Assert production-equivalent:

- HttpOnly;
- Secure when secure config true;
- SameSite=Lax;
- expected Path;
- expected lifetime/max-age.

---

# 23. Session Security Tests

Modify:

`tests/Feature/Auth/SessionSecurityTest.php`

Keep existing:

- session version revocation;
- absolute timeout;
- logout other devices;
- password change revokes other sessions;
- concurrent cap;
- single session revoke.

Add/adjust:

## S1 — cap = 3

Create:

- 3 active old sessions.

Perform new login.

Expected after request:

- current new session;
- 2 newest old sessions;
- oldest old active session evicted.

## S2 — expired row does not consume cap

Insert session row older than:

```text
session.lifetime
```

Assert:

- it is ignored for cap calculation;
- valid active sessions are not incorrectly evicted.

## S3 — Active Sessions hides idle-expired rows

Profile view:

- active row visible;
- expired row not visible.

## S4 — cross-user revoke blocked

User A authenticated.

Attempt DELETE using session id owned by User B.

Assert:

- redirect remains safe;
- User B row still exists;
- no session_revoked audit for User B.

## S5 — current session protected

Keep current test.

## S6 — selective revoke does not bump session version

Revoke one other session.

Assert current user's `auth_session_version` unchanged.

## S7 — mass revoke still bumps version

Keep existing Logout Other Devices test.

---

# 24. Password Reset Tests

Modify:

`tests/Feature/Auth/PasswordResetTest.php`

## P1 — forgot-password generic response

Keep existing test.

## P2 — no notification unregistered

Keep.

## P3 — no notification inactive

Keep.

## P4 — inactive valid reset cannot change password

Keep.

## P5 — invalid token vs invalid user must be identical

Create registered user.

POST `/reset-password`:

Case A:

```text
registered email + invalid token
```

Case B:

```text
unregistered email + same invalid token
```

Use same valid password payload.

Assert:

- same field error;
- same visible message;
- generic:

```text
This password reset link is invalid or has expired.
```

## P6 — inactive blocked reset uses same generic message

Inactive account with valid token.

Assert message same as P5.

## P7 — valid reset still works

Keep existing success test.

## P8 — reset notification remains queued class

No need to run actual SMTP in test.

`Notification::fake()` remains appropriate.

---

# 25. UI / Notification Validation

Manual or browser smoke:

1. Login first time after V2:
   - notification bell gets new sign-in notification;
   - email job enters/processes default queue.
2. Open notification:
   - Profile page opens;
   - `#active-sessions` anchor resolves.
3. Login again same browser:
   - no duplicate new-device alert.
4. Login from second browser:
   - both alerts.
5. Profile lists active sessions only.
6. Sign out second browser from first browser:
   - second session invalid on next request.
7. Fourth simultaneous session:
   - oldest active session evicted.

---

# 26. Documentation Changes

## 26.1 `docs/guides/AUTH-SECURITY-DEPLOYMENT.md`

Current statement that password-reset mail is synchronous is stale.

Update to say:

- reset password mail is queued;
- new-device security email is queued;
- `QUEUE_CONNECTION=database`;
- default queue must be processed;
- production worker must listen `exports,default`;
- known-device migration is required;
- known-device cookie is HttpOnly and long-lived;
- first login per browser after rollout may produce one initial device alert;
- max concurrent sessions = 3;
- global login brake forces Turnstile, not global lockout.

Add smoke test:

- forgot password known/unknown generic;
- reset password invalid-user/invalid-token generic;
- first new device -> in-app + mail;
- same device -> no repeat;
- fourth session -> oldest evicted.

## 26.2 `docs/guides/CPANEL-PERFORMANCE-RUNTIME.md`

Keep current queue cron.

Clarify that `default` queue now also processes:

- password-reset mail;
- new-device alert mail;
- repeated-lockout alert mail if queued implementation applies.

Do not change worker priority:

```text
exports,default
```

unless performance measurements later justify a separate security/mail queue.

---

# 27. Implementation Sequence

Implement in this exact order.

## Step 1 — Safety remediation

1. Remove `TimingSafeAuth` call/import.
2. Delete helper.
3. Delete TimingSafeAuth unit test.
4. Fix generic reset-password failure.
5. Add reset enumeration regression test.
6. Run focused login/password tests.

This step closes current correctness/security issues before adding new behavior.

## Step 2 — Global velocity brake

1. Add config.
2. Rework failed-only distinct email accounting.
3. Add global counter.
4. Add global Turnstile condition.
5. Add anomaly audit event.
6. Add tests proving no global 429.
7. Run login/rate-limit suite.

## Step 3 — Known-device persistence

1. Add migration.
2. Add `KnownDeviceService`.
3. Integrate with `CompleteLoginService`.
4. Remove active-session IP+UA new-device heuristic.
5. Add in-app `SystemNotification`.
6. Keep/update queued email notification.
7. Add known-device test suite.

## Step 4 — Session inventory cleanup

1. Add `SessionInventoryService`.
2. Move selective session DB operations.
3. Refactor ProfileController.
4. Refactor RevokeSessionController.
5. Move concurrent enforcement out of SessionRevocationService.
6. Filter expired sessions.
7. Add session tests.

## Step 5 — UI/documentation/config

1. Add active sessions anchor.
2. Update `.env.example`.
3. Update auth deployment guide.
4. Update cPanel runtime guide.

## Step 6 — Full regression

Run complete test/build/route validation.

---

# 28. File-by-File Plan

| File | Action | Detail |
|---|---|---|
| `app/Support/Auth/TimingSafeAuth.php` | DELETE | Framework already handles failed auth Timebox |
| `tests/Unit/Support/Auth/TimingSafeAuthTest.php` | DELETE | Removed helper |
| `app/Http/Requests/Auth/LoginRequest.php` | MODIFY | Remove custom timing equalizer; record security counters only on failure |
| `app/Services/Auth/LoginRateLimiter.php` | MODIFY | Failed-only distinct tracker; global brake; no global 429 |
| `config/auth_security.php` | MODIFY | Global login + known-device config; keep max session=3 |
| `app/Models/AuthAuditLog.php` | MODIFY | Add global anomaly event |
| `database/migrations/2026_08_28_000001_create_auth_known_devices_table.php` | NEW | Persistent per-account device hashes |
| `app/Services/Auth/KnownDeviceService.php` | NEW | Cookie/hash/register/touch logic |
| `app/Services/Auth/SessionInventoryService.php` | NEW | Central active-session DB operations |
| `app/Services/Auth/CompleteLoginService.php` | MODIFY | Known device + session cap + dual alert |
| `app/Services/Auth/SessionRevocationService.php` | MODIFY | Retain mass revoke only; remove moved selective enforcement |
| `app/Http/Controllers/Auth/RevokeSessionController.php` | MODIFY | Use SessionInventoryService |
| `app/Http/Controllers/ProfileController.php` | MODIFY | Use SessionInventoryService |
| `app/Notifications/NewDeviceLoginNotification.php` | MODIFY | Persistent-device wording; mail remains queued |
| `resources/views/profile/partials/active-sessions.blade.php` | MODIFY | Add anchor; keep current styling |
| `app/Http/Controllers/Auth/NewPasswordController.php` | MODIFY | Generic failure message |
| `.env.example` | MODIFY | Document new/current auth config values |
| `tests/Feature/Auth/LoginSecurityTest.php` | MODIFY | Remove dummy hash tests; global brake tests |
| `tests/Feature/Auth/AuthRateLimitingTest.php` | MODIFY | Assert global brake never 429 |
| `tests/Feature/Auth/KnownDeviceSecurityTest.php` | NEW | Persistent identity/notification/MFA tests |
| `tests/Feature/Auth/SessionSecurityTest.php` | MODIFY | Expiry filter/cap/cross-user tests |
| `tests/Feature/Auth/PasswordResetTest.php` | MODIFY | Enumeration regression |
| `docs/guides/AUTH-SECURITY-DEPLOYMENT.md` | MODIFY | Queue + device + global brake deployment |
| `docs/guides/CPANEL-PERFORMANCE-RUNTIME.md` | MODIFY | Default queue security mail responsibilities |

### Explicitly no change

- `app/Services/Auth/TwoFactorService.php`
- `app/Http/Controllers/Auth/ProfileTwoFactorController.php`
- MFA middleware
- MFA database fields
- MFA routes
- role authorization
- business modules
- export jobs behavior
- notification database schema
- auth audit database schema

---

# 29. Validation Commands

Focused first:

```bash
php artisan test tests/Feature/Auth/LoginSecurityTest.php
php artisan test tests/Feature/Auth/AuthRateLimitingTest.php
php artisan test tests/Feature/Auth/PasswordResetTest.php
php artisan test tests/Feature/Auth/KnownDeviceSecurityTest.php
php artisan test tests/Feature/Auth/SessionSecurityTest.php
```

Then:

```bash
composer test
```

Code style:

```bash
vendor/bin/pint --test
```

Route verification:

```bash
php artisan route:list --path=login
php artisan route:list --path=profile
php artisan route:list --path=reset-password
```

Migration verification in safe non-production environment:

```bash
php artisan migrate:status
php artisan migrate --pretend
```

Do not use `migrate:fresh` against any shared/staging/production database containing useful data.

Frontend sanity because Blade is modified:

```bash
npm run build
```

---

# 30. Deployment Plan — cPanel Production

## 30.1 Pre-deployment

1. Confirm current production DB backup.
2. Confirm `APP_KEY` is unchanged.
3. Confirm queue cron is active and processing:
   - `exports`
   - `default`.
4. Check:

```bash
php artisan queue:failed
```

5. Confirm Turnstile production keys/config.
6. Add env values before rebuilding config cache:

```dotenv
AUTH_MAX_CONCURRENT_SESSIONS=3
AUTH_GLOBAL_FAILED_LOGIN_THRESHOLD=200
AUTH_GLOBAL_FAILED_LOGIN_WINDOW=300
AUTH_KNOWN_DEVICE_COOKIE=adasi_known_device
AUTH_KNOWN_DEVICE_LIFETIME_DAYS=400
```

Optional explicit existing values:

```dotenv
AUTH_DISTINCT_EMAIL_THRESHOLD=5
AUTH_DISTINCT_EMAIL_WINDOW=300
AUTH_REPEATED_LOCKOUT_ALERT_THRESHOLD=3
```

## 30.2 Deploy

For non-atomic single-directory cPanel deployment, brief maintenance mode is safest because new code references a new table.

Recommended:

```bash
php artisan down --retry=60
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan up
```

If deployment uses atomic release directories and migration can be run before switching release, maintenance mode may not be required.

## 30.3 Post-deployment smoke

1. Active account normal login.
2. MFA account login + challenge.
3. Inactive account rejected.
4. First browser/device:
   - new known-device row;
   - in-app alert;
   - email queued/delivered.
5. Same browser login again:
   - no new alert.
6. Second browser:
   - new alert.
7. 4th active session:
   - oldest active session removed.
8. Single session revoke:
   - target device signs out;
   - current device remains.
9. Forgot password:
   - known vs unknown same response.
10. Reset invalid token:
    - known vs unknown same message.
11. Inactive account:
    - no reset email;
    - cannot reset.
12. Check:
    - `storage/logs/laravel.log`
    - queue-worker log
    - `failed_jobs`
    - auth audit page.

---

# 31. Rollback Plan

## 31.1 Application rollback

Because `auth_known_devices` is additive:

- rollback application code if required;
- **leave the migration/table in place** after production has used it.

Old code ignores the extra table.

Do not drop the table during routine rollback.

## 31.2 Queue compatibility

Before rolling back code that removes notification classes:

1. pause queue cron;
2. ensure running worker finishes;
3. inspect pending `default` jobs;
4. drain compatible security notification jobs or keep notification class compatibility in rollback release;
5. switch release;
6. clear/rebuild caches;
7. resume queue cron.

Reason:

queued notification jobs serialize class references. Removing a class while old jobs are pending can create failed jobs.

## 31.3 Cookie rollback

`adasi_known_device` cookie is harmless if rollback code no longer reads it.

No emergency cookie deletion is required.

## 31.4 Global rate-limit rollback

Global limiter keys live in cache and expire automatically.

No DB rollback.

---

# 32. Risks and Mitigations

| Risk | Mitigation |
|---|---|
| First login after rollout alerts many users | Document expected one-time per-browser bootstrap alert |
| User clears browser cookie | Treat as new device again; safe behavior |
| Shared NAT creates false new-device alerts | Device identity is token, not IP |
| User-Agent changes after browser update | Device token remains primary identity |
| Cookie copied to another machine | Cookie-based identity cannot defend against full client-state theft; still never acts as auth credential |
| Global brake abused for DoS | Brake only forces Turnstile, never global 429 |
| Legit high failure spike triggers CAPTCHA | Threshold 200/5m, calibrate from audit data later |
| Session garbage rows cause false cap | Filter by `last_activity` against session lifetime |
| Queue cron stops | Monitor failed/pending jobs; in-app database alert still created independently |
| Known-device table unavailable | Predeploy migrate; service must not convert valid login into auth failure |
| Rollback with queued notification jobs | Pause/drain queue before removing notification classes |

---

# 33. Non-Goals

Do not implement in this phase:

- WebAuthn/passkeys;
- device fingerprinting library;
- geo-IP service;
- “trusted device bypasses MFA”;
- MFA remember-device changes;
- admin device management;
- historical known-device list UI;
- user-facing “forget this known device” action;
- IP allowlist;
- Redis requirement;
- queue architecture migration;
- new roles/statuses;
- changes to supplier/purchasing/QC business flow;
- changes to export implementation;
- password policy redesign.

Known-device identity **never** bypasses MFA.

---

# 34. Acceptance Criteria

Phase 2 V2 is complete only when all criteria pass.

## Login

- [ ] No `TimingSafeAuth` class remains.
- [ ] Unknown/inactive/wrong-password return identical generic login failure.
- [ ] Existing per-email/per-IP limiters still work.
- [ ] Distinct-email tracker counts failed attempts only.
- [ ] Distributed many-IP failures can activate global brake.
- [ ] Global brake forces Turnstile.
- [ ] Global brake never independently returns 429.
- [ ] Successful login does not increment global failure counter.
- [ ] Global anomaly is audited once/window.

## Device

- [ ] `auth_known_devices` migration exists.
- [ ] Raw device token never stored.
- [ ] Cookie is HttpOnly.
- [ ] Production cookie is Secure.
- [ ] Cookie uses SameSite=Lax.
- [ ] Known device persists independent of session row.
- [ ] Same device with changed IP remains known.
- [ ] Same device with changed User-Agent remains known.
- [ ] Same browser token is tracked separately per account.
- [ ] MFA-pending login does not register device.
- [ ] MFA-success login registers device.
- [ ] New device sends in-app notification.
- [ ] New device sends queued email.
- [ ] Known device does not repeat alerts.

## Session

- [ ] Concurrent limit is exactly 3.
- [ ] Fourth active login evicts oldest active session.
- [ ] Expired rows do not consume cap.
- [ ] Profile does not show idle-expired rows.
- [ ] User cannot revoke another user's session.
- [ ] User cannot single-revoke current session.
- [ ] Selective revoke does not invalidate unrelated sessions.
- [ ] Logout Other Devices still works via session version.

## Password reset

- [ ] Unknown and registered forgot-password requests have same response.
- [ ] Inactive account receives no reset mail.
- [ ] Inactive account cannot reset password.
- [ ] INVALID_USER and INVALID_TOKEN expose identical message.
- [ ] Valid reset still succeeds.
- [ ] Reset email remains queued.
- [ ] No custom 800 ms controller Timebox added.

## Deployment

- [ ] `.env.example` documents auth config.
- [ ] Auth deployment runbook no longer says reset mail is synchronous.
- [ ] cPanel runbook documents default queue security notifications.
- [ ] Migration passes.
- [ ] Focused auth tests pass.
- [ ] Full `composer test` passes.
- [ ] Pint check passes.
- [ ] `npm run build` passes.
- [ ] Queue smoke passes.
- [ ] Manual new-device smoke passes.

---

# 35. Definition of Done

Implementation is considered DONE only when:

1. code matches `master@f3dc527` conventions;
2. no unrelated refactor is introduced;
3. all security decisions above are implemented;
4. all new tests pass;
5. entire existing test suite passes;
6. deployment docs match runtime behavior;
7. migration is additive and production-safe;
8. queue worker processing is verified;
9. no sensitive device token is logged/stored raw;
10. MFA behavior remains unchanged;
11. implementation diff is reviewed specifically for:
    - auth enumeration,
    - session ownership,
    - cookie flags,
    - queue behavior,
    - race conditions,
    - cross-user access,
    - rollback compatibility.

---

# 36. Coding-Agent Execution Rules

When this plan is handed to Codex / Claude Code / Gemini or another coding agent:

1. Read the repository at current HEAD first.
2. Verify HEAD is still compatible with baseline `f3dc527`; if HEAD changed, inspect the delta before coding.
3. Do not blindly apply code snippets from the old Phase 2 plan.
4. Do not reintroduce `TimingSafeAuth`.
5. Do not alter MFA.
6. Do not replace existing notification infrastructure.
7. Do not add packages for User-Agent parsing or browser fingerprinting.
8. Do not change unrelated business code.
9. Make changes incrementally in the implementation sequence above.
10. Run focused tests after each phase.
11. Run full test suite before marking complete.
12. Report:
    - files changed,
    - migrations added,
    - tests added/changed,
    - commands executed,
    - test results,
    - any deviations from this plan and exact reason.

If repository facts conflict with this plan because HEAD moved after `f3dc527`, stop only the conflicting subtask, inspect the new implementation, and adapt with the smallest compatible change. Do not overwrite newer correct repository behavior merely to match this document.
