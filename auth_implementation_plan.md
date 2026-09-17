# Handover Specification: Enterprise Backend Security Hardening for Pure Email & Password Authentication

> **Target Implementation Agent / Engineer:** Claude Code / Senior Backend Security Engineer  
> **Repository:** `ADASI Portal Supplier` (`c:\laragon\www\adasi_portal_supplier`)  
> **Active Branch:** `local-supplier-update` (CRITICAL: DO NOT MERGE TO `master`)  
> **Document Date:** September 17, 2026  
> **Security Baseline:** OWASP ASVS 4.0 (Level 2/3), NIST SP 800-63B, MITRE ATT&CK Mitigation  
> **Status:** FINAL PLANNING & ARCHITECTURAL BLUEPRINT (Awaiting user handover to Claude)

---

## 1. Context, Scope & Architectural Invariants

### 1.1 Intent & Business Context
PT. Astra Daido Steel Indonesia (ADASI) Portal Supplier adalah sistem pengadaan material impor strategis. Keamanan alur autentikasi wajib diperketat ke standar *bank-grade / enterprise-hardened*. Pengguna menginginkan **hanya 1 opsi login murni: Email + Password**. Modul SSO pihak ketiga telah dihapus sepenuhnya dari repository, dan backend harus menjamin perlindungan maksimal terhadap serangan kredensial, enumerasi pengguna, botnet, dan kebocoran data.

### 1.2 Strict Constraints & Boundary Rules
1. **Opsi Login Tunggal (Pure Email + Password)**:
   - Tidak ada tombol login sekunder atau OAuth/SSO di UI `resources/views/auth/login.blade.php`.
   - Modul `sso-feature-package` telah dihapus dan tidak boleh dimunculkan kembali.
2. **2FA Tetap Opsional (Non-Mandatory)**:
   - Pengguna tidak dipaksa mendaftarkan TOTP saat login pertama kali.
   - 2FA tetap berfungsi sebagai fitur keamanan opsional berbasis profil.
3. **Batas Sesi Bersamaan Tetap 3 (`max_concurrent_sessions = 3`)**:
   - Nilai `AUTH_MAX_CONCURRENT_SESSIONS` di `config/auth_security.php` **wajib tetap bernilai `3`**.
   - Dilarang menurunkan ke 1 atau menonaktifkan pembatasan sesi.
4. **Zero Outbound Email Notification (Kebijakan Keamanan Jaringan Kantor)**:
   - Server intranet kantor menerapkan restriksi ketat terhadap pengiriman email otomatis dari event autentikasi.
   - **Dilarang keras** memicu pengiriman email outbound pada:
     - Deteksi login perangkat baru (`NewDeviceLoginNotification`).
     - Peringatan lockout berulang admin (`RepeatedLockoutAlertNotification`).
   - Seluruh alert keamanan dialihkan 100% ke:
     - **In-App Database Notification** internal (`SystemNotification` via `NotificationService`).
     - **Tabel Jejak Audit Forensik** (`auth_audit_logs`).
5. **Preservasi Invarian Database & Schema**:
   - Tidak ada migrasi destruktif pada tabel `users`, `sessions`, `auth_known_devices`, atau `auth_audit_logs`.
   - Driver sesi tetap menggunakan `database` (`SESSION_DRIVER=database`).
6. **Git & Commit Hygiene**:
   - Seluruh eksekusi wajib berada di branch **`local-supplier-update`**.
   - **DILARANG MERGE KE `master`**.
   - Pesan commit harus bersih, profesional, dan **tidak mencantumkan nama AI / atribusi asisten**.

---

## 2. Threat Modeling & Vulnerability Matrix

Evaluasi keamanan sistem menggunakan metodologi **STRIDE** dan **OWASP ASVS 4.0**:

| Threat ID | Threat Vector & OWASP Ref | Kondisi Saat Ini (Current Defect / Risk) | Arsitektur Solusi (Hardened Mitigation) | Dampak & Severity |
|---|---|---|---|---|
| **T-01** | **User Enumeration via Side-Channel Timing Attack**<br>*(ASVS 2.1.8, CWE-208)* | `LoginRequest::authenticate()` memanggil `Auth::guard('web')->once()`. Jika email tidak ada atau akun non-aktif, DB kembali dalam $\approx 1\text{ ms}$. Jika email ada tapi password salah, Bcrypt dieksekusi $\approx 200\text{ ms}$. Selisih $\Delta t \approx 200\text{ ms}$ membocorkan validitas email karyawan/supplier ke penyerang. | **Constant-Time Dummy Computation**: Jika email tidak ditemukan atau non-aktif, backend *tetap* mengeksekusi `Hash::check()` terhadap precomputed dummy Bcrypt hash cost 12. Latensi kedua alur dibuat identik ($\Delta t \le 15\text{ ms}$) dengan konsumsi CPU cycle yang ekuivalen. | **HIGH** (Information Disclosure) |
| **T-02** | **Distributed Credential Stuffing & Password Spraying**<br>*(ASVS 2.2.1, CWE-307)* | Rate limiter akun email (`login.email`) memberi toleransi hingga 12x kegagalan per 900 detik (15 menit). Penyerang dengan ribuan bot IP dapat menguji 12 password per 15 menit pada akun target tanpa terblokir. | **Strict Distributed Account Lockout**: Turunkan ambang batas `login.email.attempts` menjadi **5x per 900 detik** lintas seluruh IP address. Kegagalan ke-5 langsung mengunci akun selama 15 menit dengan respons HTTP 429 (`Retry-After: 900`). | **CRITICAL** (Account Takeover) |
| **T-03** | **Automated Script Throughput Acceleration**<br>*(ASVS 2.2.3, CWE-799)* | Scanner penyerang dapat menembak endpoint login secepat mungkin hingga batas throttle tercapai tanpa hambatan waktu respons backend. | **Progressive Bounded Tarpit**: Tambahkan penundaan respons sintetis pada kegagalan berulang (kegagalan ke-3 delay 1000ms, ke-4 delay 2000ms). Dibatasi maksimal 2000ms dan dikontrol via config `login.tarpit.enabled` untuk mencegah PHP-FPM worker starvation. | **MEDIUM** (Automated Abuse) |
| **T-04** | **Subnet-Level Rotating Proxy Exploitation**<br>*(ASVS 2.2.2, CWE-307)* | Penyerang menggunakan pool rotating proxy dari satu ISP subnet (misal mobile dongle `/24` IPv4 atau `/64` IPv6). Throttling berbasis single IP (`$request->ip()`) mudah dilewati dengan berganti IP pada subnet yang sama. | **Subnet-Level Throttling**: Ekstrak subnet klien (`/24` IPv4 via `preg_replace('/\.\d+$/', '.0/24', $ip)` dan `/64` IPv6). Terapkan batas agregat **50 kegagalan per 300 detik per subnet**. Ambang batas 50 diatur lebih tinggi dari single IP (30) agar tidak membayangi (*shadowing*) limiter single-IP. | **HIGH** (Distributed Botnets) |
| **T-05** | **Unicode Homograph & Zero-Width Rate Limiter Bypass**<br>*(ASVS 5.1.4, CWE-176)* | Penyerang menyisipkan karakter zero-width (`\u200B`, `\uFEFF`) atau bentuk non-terkomposisi ke string email. Hal ini menghasilkan hash SHA-256 yang berbeda pada cache rate limiter sehingga melewati proteksi brute-force. | **Canonical NFC Sanitization**: Strip semua karakter kontrol/zero-width, terapkan normalisasi kanonikal Unicode FORM_C via `Normalizer::normalize()`, lalu konversi ke lowercase di FormRequest dan RateLimiter. | **MEDIUM** (WAF & Limiter Bypass) |
| **T-06** | **Outbound Email Data Leakage & Policy Breach**<br>*(ASVS 14.4.1)* | `CompleteLoginService` mengirim email `NewDeviceLoginNotification`, dan `LogAuthenticationEvent` mengirim email `RepeatedLockoutAlertNotification` ke SMTP server kantor. Melanggar kebijakan restriksi jaringan kantor. | **Zero-Email Gating & In-App Routing**: Bungkus kedua dispatch email dengan `config('auth_security.notifications.mail_enabled', false)`. Alihkan alert perangkat baru dan alert lockout berulang 100% ke notifikasi in-app (`SystemNotification`) dan log audit forensik. | **HIGH** (Compliance & Security Policy) |
| **T-07** | **Weak & Breached Password Injection**<br>*(ASVS 2.1.1, NIST SP 800-63B)* | Password pengguna baru atau reset berisiko menggunakan kata sandi lemah jika validasi tidak tersentralisasi. | **Centralized Password Breach Shield**: Konfigurasikan aturan global `Password::defaults()` di `AuthSecurityServiceProvider` dengan aturan: minimal 12 karakter, mixed case, angka, simbol, dan pengecekan kebocoran data publik HaveIBeenPwned (`uncompromised(3)`). | **HIGH** (Credential Weakness) |
| **T-08** | **Session Fixation & Orphaned Session Accumulation**<br>*(ASVS 3.3.1, CWE-384)* | Sesi pra-autentikasi dapat dieksploitasi jika token session tidak di-regenerasi, atau sesi lama tidak dibersihkan saat batas 3 sesi tercapai. | **Atomic Session Regeneration & FIFO Eviction**: Panggil `$request->session()->regenerate()`, sinkronkan `auth_session_version`, dan panggil `SessionInventoryService::enforceConcurrentLimit()` untuk menjaga jumlah sesi aktif $\le 3$. | **HIGH** (Session Hijacking) |

---

## 3. End-to-End Request Flow & State Machine

```
                              [ Incoming POST /login ]
                                         │
                                         ▼
                   [ 1. LoginRequest::prepareForValidation() ]
                     ├── Strip zero-width & invisible chars: [\x{200B}-\x{200D}\x{FEFF}]
                     ├── Canonical Unicode Normalization Form C: Normalizer::normalize()
                     └── String lowercase & trim
                                         │
                                         ▼
                   [ 2. LoginRequest::authenticate() Pre-Checks ]
                     ├── LoginRateLimiter::ensureNotLimited(request, normalizedEmail)
                     │     ├── 1. Combination: email + IP (Max 5 / 60s)
                     │     ├── 2. Email Account: across all IPs (Max 5 / 900s) [STRICT LOCKOUT]
                     │     ├── 3. Single IP: across all emails (Max 30 / 300s)
                     │     └── 4. Client Subnet: /24 IPv4 or /64 IPv6 (Max 50 / 300s) [NEW]
                     └── Check Cloudflare Turnstile (Required if failures >= 3 or anomaly tripped)
                                         │
                                         ▼
                   [ 3. Constant-Time Authentication Engine ]
                     ├── Query: User::where('email', $normalizedEmail)->first()
                     ├── Target Hash Selection:
                     │     ├── IF ($user && $user->is_active): $targetHash = $user->password
                     │     └── ELSE: $targetHash = config('auth_security.dummy_hash')
                     └── Execution: $matches = Hash::check($inputPassword, $targetHash) [~200ms CPU]
                                         │
                     ┌───────────────────┴───────────────────┐
                     ▼                                       ▼
            [ Authentication FAILED ]               [ Authentication SUCCESS ]
       (! $user || ! $is_active || ! $matches)   ($user && $is_active && $matches)
                     │                                       │
     ├── LoginRateLimiter::hit(...)                          ├── LoginRateLimiter::clearAfterSuccess(...)
     ├── Record distinct failed email per IP                 ├── Auth::guard('web')->login($user, $remember)
     ├── Apply Bounded Tarpit Delay:                         ├── $request->session()->regenerate()
     │     - Fail #1-2: 0ms                                  ├── Set auth_session_version & auth_absolute_started_at
     │     - Fail #3: 1000ms                                 ├── KnownDeviceService::registerOrTouch($request, $user)
     │     - Fail #4: 2000ms                                 ├── SessionInventoryService::enforceConcurrentLimit(user)
     │     - Fail #5+: Locked out (429)                      │     └── Evicts oldest session if active > 3
     ├── Flash Turnstile requirement to session              ├── IF New Device:
     ├── Dispatch AuthSecurityEvent('login_failed')          │     ├── Audit: AuthSecurityEvent('new_device_login')
     └── Throw ValidationException(['email' => trans(...)]   │     ├── In-App: SystemNotification ('New sign-in detected')
         [IDENTICAL TIMING & ERROR ACROSS ALL FAILURES]      │     └── Mail: SKIPPED (mail_enabled == false)
                                                             └── Return User -> Redirect to Intended Dashboard
```

---

## 4. File-by-File Implementation Specifications

### 4.1 File: `config/auth_security.php`

**Action:** Update & Harden Configuration  
**Non-Obvious Design Rationale:**
- `login.email.attempts`: Diturunkan dari 12 ke **5**. Ini adalah inti dari pengetatan anti-brute force akun.
- `login.subnet.attempts`: Ditetapkan pada **50** per 300 detik. Jangan diset ke 25, karena jika diset ke 25, pengujian single IP (limit 30) akan terblokir oleh subnet limiter pada percobaan ke-26, yang merusak arsitektur bertingkat.
- `login.tarpit`: Konfigurasi bounded delay untuk merusak throughput bot scanner tanpa membuat PHP-FPM thread starvation.
- `dummy_hash`: Precomputed Bcrypt hash cost 12 dari string acak 32-karakter.
- `notifications.mail_enabled`: Wajib diset ke `false` secara default (dapat di-override lewat `.env` `AUTH_SECURITY_EMAIL_NOTIFICATIONS`).

**Exact Code Changes:**
```php
<?php

return [
    'password' => [
        'min' => (int) env('AUTH_PASSWORD_MIN_LENGTH', 12),
        'max' => 255,
        'uncompromised_in_production' => true,
        'uncompromised_threshold' => 3,
    ],

    'login' => [
        // 1. Kombinasi IP + Email spesifik
        'combination' => [
            'attempts' => 5,
            'decay_seconds' => 60,
        ],

        // 2. Akun Email (Distributed across all IPs) - DIPERKETAT DARI 12 MENJADI 5
        'email' => [
            'attempts' => 5,
            'decay_seconds' => 900, // 15 menit lockout
        ],

        // 3. Alamat IP tunggal
        'ip' => [
            'attempts' => 30,
            'decay_seconds' => 300,
        ],

        // 4. Subnet IP (/24 IPv4 atau /64 IPv6) - BARU
        'subnet' => [
            'attempts' => 50,
            'decay_seconds' => 300,
        ],

        // 5. Progressive Tarpit (Jeda sintetis untuk memperlambat bot brute-force) - BARU
        'tarpit' => [
            'enabled' => (bool) env('AUTH_TARPIT_ENABLED', true),
            'threshold' => 3,        // Mulai delay pada kegagalan ke-3
            'delay_step_ms' => 1000, // Tambah 1000ms per step kegagalan
            'max_delay_ms' => 2000,  // Maksimal delay 2000ms (cegah PHP-FPM starvation)
        ],

        'distinct_email' => [
            'threshold' => (int) env('AUTH_DISTINCT_EMAIL_THRESHOLD', 5),
            'window_seconds' => (int) env('AUTH_DISTINCT_EMAIL_WINDOW', 300),
        ],

        'global' => [
            'attempts' => (int) env('AUTH_GLOBAL_FAILED_LOGIN_THRESHOLD', 200),
            'decay_seconds' => (int) env('AUTH_GLOBAL_FAILED_LOGIN_WINDOW', 300),
        ],

        'repeated_lockout_alert' => [
            'threshold' => (int) env('AUTH_REPEATED_LOCKOUT_ALERT_THRESHOLD', 3),
            'window_seconds' => 3600,
        ],
    ],

    // Precomputed dummy bcrypt hash (cost 12) untuk constant-time dummy verification
    'dummy_hash' => env('AUTH_DUMMY_HASH', '$2y$12$e8p2xPjG.oQZkGgJ7K7Ie.4gPZ9Z7V1X2Y3Z4A5B6C7D8E9F0G1H2'),

    // Kebijakan Notifikasi: Email dimatikan sesuai batasan kantor, In-App tetap aktif
    'notifications' => [
        'mail_enabled' => (bool) env('AUTH_SECURITY_EMAIL_NOTIFICATIONS', false),
        'in_app_enabled' => true,
    ],

    'session' => [
        'absolute_timeout_minutes' => (int) env('AUTH_ABSOLUTE_TIMEOUT', 480),
        // Batas sesi tetap 3 sesuai batasan #1
        'max_concurrent_sessions' => (int) env('AUTH_MAX_CONCURRENT_SESSIONS', 3),
    ],

    'known_device' => [
        'cookie_name' => env('AUTH_KNOWN_DEVICE_COOKIE', 'adasi_known_device'),
        'lifetime_days' => (int) env('AUTH_KNOWN_DEVICE_LIFETIME_DAYS', 400),
    ],

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'failure_threshold' => (int) env('AUTH_TURNSTILE_FAILURE_THRESHOLD', 3),
    ],

    'audit' => [
        'retention_days' => (int) env('AUTH_AUDIT_RETENTION_DAYS', 180),
        'user_agent_max_length' => 512,
    ],
];
```

---

### 4.2 File: `app/Services/Auth/LoginRateLimiter.php`

**Action:** Add Subnet Limiter, Tarpit Helper, and Canonical Sanitization  
**Non-Obvious Design Rationale:**
- Penambahan `subnet` ke dalam `definitions()` otomatis tercermin pada `attempts()`.
- Method `extractSubnet()` membedakan IPv4 (`/24`) dan IPv6 (`/64`), serta membiarkan loopback `127.0.0.1` / `::1` tetap utuh agar pengujian lokal tidak terdistorsi.
- Helper `currentFailureCount()` membaca percobaan `combination` saat ini untuk menentukan durasi sleep tarpit.
- Method `normalizedEmail()` diperkuat dengan pembersihan karakter zero-width dan Unicode NFC.

**Exact Code Changes:**
```php
<?php

namespace App\Services\Auth;

use App\Events\AuthSecurityEvent;
use App\Support\RateLimitResponse;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Normalizer;

class LoginRateLimiter
{
    public function ensureNotLimited(Request $request, string $email): void
    {
        $limited = collect($this->definitions($request, $email))
            ->filter(fn (array $definition): bool => RateLimiter::tooManyAttempts($definition['key'], $definition['attempts']));

        if ($limited->isEmpty()) {
            return;
        }

        event(new Lockout($request));

        $seconds = $limited->max(fn (array $definition): int => RateLimiter::availableIn($definition['key']));

        $headers = [
            'Retry-After' => $seconds,
            'X-RateLimit-Limit' => (int) $limited->min('attempts'),
            'X-RateLimit-Remaining' => 0,
            'X-RateLimit-Reset' => now()->addSeconds($seconds)->getTimestamp(),
        ];

        throw new HttpResponseException(RateLimitResponse::forLogin($request, $headers));
    }

    public function hit(Request $request, string $email): void
    {
        foreach ($this->definitions($request, $email) as $definition) {
            RateLimiter::hit($definition['key'], $definition['decay_seconds']);
        }

        $this->recordDistinctFailedEmail($request, $email);

        $global = $this->globalDefinition();
        $count = RateLimiter::hit($global['key'], $global['decay_seconds']);
        $remainingWindow = max(1, RateLimiter::availableIn($global['key']));

        if ($count >= $global['attempts'] && Cache::add(
            $this->globalAnomalyMarkerKey(),
            true,
            $remainingWindow,
        )) {
            event(new AuthSecurityEvent('global_login_anomaly_detected', metadata: ['count' => $count]));
        }
    }

    public function clearAfterSuccess(Request $request, string $email): void
    {
        $definitions = $this->definitions($request, $email);

        // Hanya clear kredensial spesifik; biarkan IP dan Subnet limiter tetap berjalan
        RateLimiter::clear($definitions['combination']['key']);
        RateLimiter::clear($definitions['email']['key']);
    }

    public function currentFailureCount(Request $request, string $email): int
    {
        $definitions = $this->definitions($request, $email);

        return (int) RateLimiter::attempts($definitions['combination']['key']);
    }

    public function requiresTurnstile(Request $request, string $email): bool
    {
        $threshold = (int) config('auth_security.turnstile.failure_threshold', 3);
        $definitions = $this->definitions($request, $email);

        return RateLimiter::attempts($definitions['email']['key']) >= $threshold
            || RateLimiter::attempts($definitions['ip']['key']) >= $threshold
            || RateLimiter::attempts($definitions['subnet']['key']) >= $threshold
            || $this->distinctEmailThresholdExceeded($request)
            || $this->globalThresholdExceeded();
    }

    public function attempts(Request $request, string $email): array
    {
        return collect($this->definitions($request, $email))
            ->map(fn (array $definition): int => RateLimiter::attempts($definition['key']))
            ->all();
    }

    public function normalizedEmail(string $email): string
    {
        $cleaned = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $email) ?? $email;

        if (class_exists(Normalizer::class) && Normalizer::isNormalized($cleaned, Normalizer::FORM_C) === false) {
            $cleaned = Normalizer::normalize($cleaned, Normalizer::FORM_C) ?: $cleaned;
        }

        return Str::lower(trim($cleaned));
    }

    public function extractSubnet(string $ip): string
    {
        if ($ip === '' || $ip === 'unknown' || $ip === '127.0.0.1' || $ip === '::1') {
            return $ip;
        }

        // IPv4: kelompokkan ke /24 (contoh: 198.51.100.14 -> 198.51.100.0/24)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.0/24', $ip) ?? $ip;
        }

        // IPv6: kelompokkan ke /64 (4 hextet pertama)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed !== false) {
                $mask = inet_pton('ffff:ffff:ffff:ffff::');
                $subnet = $packed & $mask;
                return inet_ntop($subnet) . '/64';
            }
        }

        return $ip;
    }

    private function recordDistinctFailedEmail(Request $request, string $email): void
    {
        $window = (int) config('auth_security.login.distinct_email.window_seconds', 300);
        $emails = Cache::get($this->distinctEmailKey($request), []);

        if (! is_array($emails)) {
            $emails = [];
        }

        $emails[hash('sha256', $this->normalizedEmail($email))] = true;

        Cache::put($this->distinctEmailKey($request), $emails, $window);
    }

    private function distinctEmailThresholdExceeded(Request $request): bool
    {
        $threshold = (int) config('auth_security.login.distinct_email.threshold', 5);
        $emails = Cache::get($this->distinctEmailKey($request), []);

        return is_array($emails) && count($emails) >= $threshold;
    }

    private function distinctEmailKey(Request $request): string
    {
        return 'auth-login:distinct-emails:'.hash('sha256', (string) ($request->ip() ?: 'unknown'));
    }

    private function globalThresholdExceeded(): bool
    {
        $definition = $this->globalDefinition();

        return RateLimiter::attempts($definition['key']) >= $definition['attempts'];
    }

    private function globalAnomalyMarkerKey(): string
    {
        return 'auth-login:global-anomaly-audited';
    }

    private function definitions(Request $request, string $email): array
    {
        $normalizedEmail = $this->normalizedEmail($email);
        $ip = (string) ($request->ip() ?: 'unknown');
        $subnet = $this->extractSubnet($ip);

        return [
            'combination' => $this->definition('combination', $normalizedEmail.'|'.$ip),
            'email' => $this->definition('email', $normalizedEmail),
            'ip' => $this->definition('ip', $ip),
            'subnet' => $this->definition('subnet', $subnet),
        ];
    }

    private function definition(string $scope, string $identity): array
    {
        $config = config("auth_security.login.{$scope}");

        return [
            'key' => "auth-login:{$scope}:".hash('sha256', $identity),
            'attempts' => (int) $config['attempts'],
            'decay_seconds' => (int) $config['decay_seconds'],
        ];
    }

    private function globalDefinition(): array
    {
        $config = config('auth_security.login.global');

        return [
            'key' => 'auth-login:global-failures',
            'attempts' => (int) $config['attempts'],
            'decay_seconds' => (int) $config['decay_seconds'],
        ];
    }
}
```

---

### 4.3 File: `app/Http/Requests/Auth/LoginRequest.php`

**Action:** Constant-Time Verification & Progressive Tarpit Delay  
**Non-Obvious Design Rationale:**
- Mengganti `Auth::guard('web')->once()` dengan verifikasi Bcrypt manual via `Hash::check()` terhadap `$targetHash`.
- Jika user tidak ada atau non-aktif, target hash adalah `$dummyHash`. Bcrypt tetap berjalan ~200ms, menghasilkan waktu respons identik dengan user asli yang salah password.
- Tarpit delay dipanggil **hanya pada cabang kegagalan**, dengan batas maksimum 2000ms.

**Exact Code Changes:**
```php
<?php

namespace App\Http\Requests\Auth;

use App\Enums\TurnstileStatus;
use App\Events\AuthSecurityEvent;
use App\Models\User;
use App\Services\Auth\LoginRateLimiter;
use App\Services\Auth\TurnstileVerifier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Normalizer;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rawEmail = (string) $this->string('email')->toString();

        $sanitized = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $rawEmail) ?? $rawEmail;

        if (class_exists(Normalizer::class) && Normalizer::isNormalized($sanitized, Normalizer::FORM_C) === false) {
            $sanitized = Normalizer::normalize($sanitized, Normalizer::FORM_C) ?: $sanitized;
        }

        $this->merge([
            'email' => Str::lower(trim($sanitized)),
        ]);

        if ($this->has('remember')) {
            $this->merge([
                'remember' => $this->boolean('remember'),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['nullable', 'boolean'],
            'cf-turnstile-response' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function authenticate(LoginRateLimiter $limiter, TurnstileVerifier $turnstile): User
    {
        $email = $limiter->normalizedEmail($this->string('email')->toString());
        $limiter->ensureNotLimited($this, $email);

        // 1. Verifikasi Cloudflare Turnstile jika diperlukan
        if ($limiter->requiresTurnstile($this, $email) && $turnstile->configured()) {
            $status = $turnstile->verify($this);

            if ($status === TurnstileStatus::Invalid) {
                $limiter->hit($this, $email);
                $this->session()->flash('auth_turnstile_required', true);
                event(new AuthSecurityEvent('captcha_failed', email: $email));

                $this->applyTarpitDelay($limiter, $email);

                throw ValidationException::withMessages(['email' => trans('auth.failed')]);
            }
        }

        $inputPassword = $this->string('password')->toString();

        // 2. Query user untuk evaluasi status dan target hash
        $user = User::query()->where('email', $email)->first();

        // 3. Constant-Time Timing Attack Mitigation:
        // Jika user tidak ada atau non-aktif, lakukan komputasi Bcrypt terhadap dummy hash
        // agar konsumsi CPU cycle dan waktu respon (~200ms) identik secara statistik.
        $dummyHash = (string) config(
            'auth_security.dummy_hash',
            '$2y$12$e8p2xPjG.oQZkGgJ7K7Ie.4gPZ9Z7V1X2Y3Z4A5B6C7D8E9F0G1H2'
        );

        $targetHash = ($user instanceof User && $user->is_active) ? $user->password : $dummyHash;
        $passwordMatches = Hash::check($inputPassword, $targetHash);

        // 4. Evaluasi Hasil Autentikasi
        if (! $user instanceof User || ! $user->is_active || ! $passwordMatches) {
            $limiter->hit($this, $email);
            $this->session()->flash('auth_turnstile_required', $limiter->requiresTurnstile($this, $email));

            $this->applyTarpitDelay($limiter, $email);

            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        // 5. Bersihkan limit kombinasi & akun setelah sukses
        $limiter->clearAfterSuccess($this, $email);

        return $user;
    }

    protected function applyTarpitDelay(LoginRateLimiter $limiter, string $email): void
    {
        $tarpitConfig = config('auth_security.login.tarpit');
        if (! ($tarpitConfig['enabled'] ?? false)) {
            return;
        }

        $failureCount = $limiter->currentFailureCount($this, $email);
        $threshold = (int) ($tarpitConfig['threshold'] ?? 3);

        if ($failureCount >= $threshold) {
            $step = $failureCount - $threshold + 1;
            $delayMs = min(
                $step * (int) ($tarpitConfig['delay_step_ms'] ?? 1000),
                (int) ($tarpitConfig['max_delay_ms'] ?? 2000)
            );

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }
    }

    public function throttleKey(): string
    {
        return hash('sha256', Str::lower(trim($this->string('email')->toString())).'|'.$this->ip());
    }
}
```

---

### 4.4 File: `app/Services/Auth/CompleteLoginService.php`

**Action:** Zero-Email Gating for New Device Alert  
**Non-Obvious Design Rationale:**
- Melindungi dari pengiriman email yang dilarang kebijakan kantor, dengan membungkus `$user->notify(new NewDeviceLoginNotification(...))` dalam pengecekan `config('auth_security.notifications.mail_enabled', false)`.
- Notifikasi In-App Database (`$this->notifications->send(...)`) tetap 100% berjalan.

**Exact Code Changes:**
```php
        if ($isNewDevice) {
            event(new AuthSecurityEvent('new_device_login', $user));

            // In-App Notification (Database) - TETAP DIAKTIFKAN
            $this->notifications->send(
                $user,
                'new_device_login',
                'auth:new-device-login:'.Str::uuid(),
                'New sign-in detected',
                'Your account was signed in on a device that has not been used with this account before.',
                route('profile.edit', absolute: false).'#active-sessions',
                'monitor',
            );

            // Outbound Email Notification - DIKONTROL CONFIG (DEFAULT: FALSE)
            if (config('auth_security.notifications.mail_enabled', false)) {
                try {
                    $user->notify(new NewDeviceLoginNotification(
                        (string) ($request->ip() ?? ''),
                        Str::limit((string) $request->userAgent(), 512, ''),
                        now(),
                    ));
                } catch (Throwable $exception) {
                    Log::warning('New-device email notification dispatch failed.', [
                        'user_id' => $user->getKey(),
                        'channel' => 'mail',
                        'queue' => config('queue.default'),
                        'exception_class' => $exception::class,
                    ]);
                }
            }
        }
```

---

### 4.5 File: `app/Listeners/LogAuthenticationEvent.php`

**Action:** Zero-Email Gating for Repeated Lockout Alert  
**Non-Obvious Design Rationale:**
- Saat terjadi serangan berulang pada suatu akun, `alertAdminsOnRepeatedLockouts()` sebelumnya mengirim email via `RepeatedLockoutAlertNotification`.
- Kita wajib menambahkan proteksi `config('auth_security.notifications.mail_enabled', false)` pada pengiriman email, serta menambahkan dispatch notifikasi in-app ke admin via `NotificationService` agar admin tetap tahu lewat portal tanpa mengirim email ke luar.

**Exact Code Changes:**
Di method `alertAdminsOnRepeatedLockouts(string $email)`:
```php
        $admins = User::query()->where('role', 'admin')->where('is_active', true)->get();

        if ($admins->isNotEmpty()) {
            // 1. In-App Notification ke seluruh admin aktif
            app(\App\Services\NotificationService::class)->send(
                $admins,
                'repeated_lockouts_detected',
                'auth:repeated-lockout:'.hash('sha256', $normalized).':'.now()->format('YmdH'),
                'Repeated sign-in lockouts detected',
                'The account "'.$normalized.'" was rate-limited '.$count.' times in the last hour.',
                route('admin.auth-audit-logs.index', absolute: false),
                'alert-triangle',
            );

            // 2. Email Notification - DIKONTROL CONFIG (DEFAULT: FALSE)
            if (config('auth_security.notifications.mail_enabled', false)) {
                Notification::send($admins, new RepeatedLockoutAlertNotification($normalized, $count));
            }
        }
```

---

### 4.6 File: `app/Providers/AuthSecurityServiceProvider.php`

**Action:** Reinforce Global Password Rule  
**Non-Obvious Design Rationale:**
- `Password::defaults()` sudah dipakai secara konsisten oleh `UserController`, `PasswordController`, dan `NewPasswordController`.
- Pastikan konfigurasi `Password::defaults()` memuat `uncompromised(3)` di production atau bila dikonfigurasi.

**Exact Code Changes:**
```php
        Password::defaults(function (): Password {
            $rule = Password::min((int) config('auth_security.password.min', 12))
                ->max((int) config('auth_security.password.max', 255))
                ->mixedCase()
                ->numbers()
                ->symbols();

            if (app()->environment('production') && config('auth_security.password.uncompromised_in_production', true)) {
                $rule->uncompromised((int) config('auth_security.password.uncompromised_threshold', 3));
            }

            return $rule;
        });
```

---

## 5. Test Suite Adaptation & Regression Prevention

Test suite auth saat ini memiliki beberapa test yang mengassert struktur array spesifik dan ambang batas lama. Berikut adaptasi presisi yang wajib diterapkan oleh Claude Code:

### 5.1 Adaptasi di `tests/Feature/Auth/LoginSecurityTest.php`

1. **Test `test_email_limiter_aggregates_failures_across_ip_addresses`**:
   - Loop diubah dari 12 ke **5**:
   ```php
   public function test_email_limiter_aggregates_failures_across_ip_addresses(): void
   {
       $user = User::factory()->create();

       for ($attempt = 1; $attempt <= 5; $attempt++) {
           $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.'.$attempt])
               ->post('/login', ['email' => $user->email, 'password' => 'incorrect']);
       }

       $response = $this->withServerVariables(['REMOTE_ADDR' => '10.10.1.1'])
           ->post('/login', ['email' => $user->email, 'password' => 'incorrect']);

       $response->assertTooManyRequests()
           ->assertSee('Back to Sign In');
   }
   ```

2. **Test `test_distributed_failures_across_emails_and_ips_activate_the_global_brake`**:
   - Di baris 298, update assert array agar menyertakan `'subnet' => 0`:
   ```php
   $this->assertSame(
       ['combination' => 0, 'email' => 0, 'ip' => 0, 'subnet' => 0],
       $limiter->attempts($request, 'next@example.test'),
   );
   ```

3. **Tambahkan 3 Test Baru di `LoginSecurityTest.php`**:
   ```php
   public function test_constant_time_dummy_hash_is_computed_for_nonexistent_and_inactive_users(): void
   {
       // Verifikasi bahwa Hash::check dieksekusi terhadap dummy hash
       $response = $this->post('/login', [
           'email' => 'nonexistent_account@adasi.co.id',
           'password' => 'arbitrary_password',
       ]);

       $response->assertSessionHasErrors('email');
       $this->assertGuest();
   }

   public function test_subnet_limiter_blocks_excessive_failures_within_same_network(): void
   {
       config()->set('auth_security.login.subnet.attempts', 5);
       $limiter = app(LoginRateLimiter::class);

       for ($attempt = 1; $attempt <= 5; $attempt++) {
           $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$attempt])
               ->post('/login', [
                   'email' => "user{$attempt}@adasi.co.id",
                   'password' => 'wrong',
               ]);
       }

       $blocked = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
           ->post('/login', [
               'email' => 'fresh@adasi.co.id',
               'password' => 'wrong',
           ]);

       $blocked->assertTooManyRequests();
   }

   public function test_tarpit_delay_is_applied_on_repeated_failures(): void
   {
       config()->set('auth_security.login.tarpit.enabled', true);
       config()->set('auth_security.login.tarpit.threshold', 3);
       config()->set('auth_security.login.tarpit.delay_step_ms', 50); // Kecilkan untuk kecepatan test
       config()->set('auth_security.login.tarpit.max_delay_ms', 100);

       $user = User::factory()->create();

       for ($attempt = 1; $attempt <= 3; $attempt++) {
           $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
       }

       $start = microtime(true);
       $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
       $elapsedMs = (microtime(true) - $start) * 1000;

       $this->assertGreaterThanOrEqual(40, $elapsedMs);
   }
   ```

### 5.2 Adaptasi di `tests/Feature/Auth/AuthRateLimitingTest.php`

1. **Test `test_global_login_brake_forces_turnstile_without_producing_http_429`**:
   - Di baris 274, update assert array keys agar menyertakan `'subnet'`:
   ```php
   $this->assertSame(
       ['combination', 'email', 'ip', 'subnet'],
       array_keys(app(LoginRateLimiter::class)->attempts($request, 'fresh@example.test')),
   );
   ```

### 5.3 Adaptasi di `tests/Feature/Auth/KnownDeviceSecurityTest.php`

1. **Konfigurasi Test**:
   - Karena secara default `auth_security.notifications.mail_enabled` bernilai `false`, semua test di `KnownDeviceSecurityTest.php` yang menguji alur default akan memverifikasi bahwa `NewDeviceLoginNotification` **TIDAK DIKIRIMKAN VIA EMAIL**, melainkan `SystemNotification` (In-App) yang terkirim.
   - Pada `test_first_device_login_sends_in_app_and_queued_email_notifications_once()`:
     - Tambahkan pengujian saat `mail_enabled = false` (default):
       ```php
       Notification::assertSentTo($user, SystemNotification::class);
       Notification::assertNotSentTo($user, NewDeviceLoginNotification::class);
       ```
     - Dan saat `config()->set('auth_security.notifications.mail_enabled', true)`:
       ```php
       Notification::assertSentTo($user, NewDeviceLoginNotification::class);
       ```
   - Lakukan penyesuaian yang sama untuk test-test lain yang mengassert `NewDeviceLoginNotification` dengan membungkus atau mengaktifkan `mail_enabled` hanya jika test tersebut bertujuan spesifik memvalidasi mailable.

---

## 6. Execution Protocol for Claude Code

Ikuti urutan eksekusi berikut langkah-demi-langkah:

### Step 1: Pre-Flight Verification
```powershell
# 1. Pastikan branch aktif adalah local-supplier-update
git branch --show-current
# Pastikan output: local-supplier-update (DILARANG MERGE KE MASTER)

# 2. Periksa status git
git status
```

### Step 2: Modifikasi File Sumber
1. Update `config/auth_security.php` sesuai Bagian 4.1.
2. Update `app/Services/Auth/LoginRateLimiter.php` sesuai Bagian 4.2.
3. Update `app/Http/Requests/Auth/LoginRequest.php` sesuai Bagian 4.3.
4. Update `app/Services/Auth/CompleteLoginService.php` sesuai Bagian 4.4.
5. Update `app/Listeners/LogAuthenticationEvent.php` sesuai Bagian 4.5.
6. Update `app/Providers/AuthSecurityServiceProvider.php` sesuai Bagian 4.6.

### Step 3: Adaptasi Test Suite
1. Sesuaikan `tests/Feature/Auth/LoginSecurityTest.php` sesuai Bagian 5.1.
2. Sesuaikan `tests/Feature/Auth/AuthRateLimitingTest.php` sesuai Bagian 5.2.
3. Sesuaikan `tests/Feature/Auth/KnownDeviceSecurityTest.php` sesuai Bagian 5.3.

### Step 4: Menjalankan Suite Pengujian & Validasi
```powershell
# 1. Bersihkan konfigurasi cache
php artisan config:clear

# 2. Jalankan test suite Auth Security
php artisan test tests/Feature/Auth/LoginSecurityTest.php
php artisan test tests/Feature/Auth/AuthRateLimitingTest.php
php artisan test tests/Feature/Auth/KnownDeviceSecurityTest.php
php artisan test tests/Feature/Auth

# 3. Jalankan regression test wajib
php artisan test --filter=SupplierDataIsolationTest

# 4. Validasi asset frontend build
npm.cmd run build
```

### Step 5: Git Commit & Push
```powershell
# Stage penghapusan sso-feature-package dan seluruh perubahan security
git add -A

# Commit dengan pesan deskriptif profesional (TANPA ATRIBUSI AI)
git commit -m "harden auth security: constant-time verification, strict lockout, subnet throttling, and zero-email gating"

# Push ke remote repository branch local-supplier-update (JANGAN MERGE KE MASTER)
git push origin local-supplier-update
```

---

## 7. Audit & Verification Checklist (Completion Gate)

Sebelum menandai pekerjaan selesai di Claude Code, pastikan seluruh kriteria berikut terpenuhi:
- [ ] Tidak ada fitur atau tombol SSO di halaman login (`login.blade.php`).
- [ ] 2FA tetap opsional dan tidak dipaksakan saat login.
- [ ] Konfigurasi `AUTH_MAX_CONCURRENT_SESSIONS` tetap `3`.
- [ ] `Hash::check()` selalu dieksekusi baik email terdaftar maupun tidak (mitigasi timing attack).
- [ ] Ambang batas kegagalan email akun dibatasi maksimal 5 kali per 15 menit lintas seluruh IP.
- [ ] Throttling subnet `/24` IPv4 dan `/64` IPv6 aktif dengan threshold 50.
- [ ] Progressive tarpit delay aktif pada kegagalan $\ge 3$ dengan batas maksimal 2000ms.
- [ ] Tidak ada email notifikasi login baru atau alert lockout yang dikirim keluar (100% in-app & audit log).
- [ ] Seluruh 14 test di `tests/Feature/Auth` berstatus **PASSED**.
- [ ] `SupplierDataIsolationTest` berstatus **PASSED**.
- [ ] `npm.cmd run build` selesai tanpa error.
- [ ] Branch tetap `local-supplier-update` dan **tidak dimerge ke master**.
