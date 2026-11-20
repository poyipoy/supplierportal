# Implementation Plan (v2 — Deep Architectural & Security Spec): Canonical Infor-Style Context URLs di ADASI Supplier Portal

*Diperbarui: 20 November 2026 | Repositori: `poyipoy/supplierportal` (Laravel 12, PHP 8.2+, `WorkspaceShell`, `PortalPath`, `HashidCodec`, `DecodeHashids`, `AddSecurityHeaders`) | Prinsip: Lebih dalam, bukan lebih panjang — kontrak formal, bahan mentah langsung, dan bukti falsifikasi eksternal.*

## 1. Bahan Mentah (Raw Source of Truth) yang Wajib Digunakan Agen Pelaksana

Agen pelaksana **dilarang** memulai dari ringkasan atau asumsi memori. Semua pemetaan dan keputusan wajib ditarik langsung dari artefak mentah berikut:

1. **Tabel rute runtime Laravel:** keluaran aktual `php artisan route:list --json` (dari 971 baris `routes/web.php`: 219 `Route::get`, 13 `Route::resource`, 479 named routes) — bukan daftar manual.
2. **Kontrak klasifikasi & cache shell mentah:** `app/Support/WorkspaceShell.php` (`TOP_LEVEL_SECURITY_PATTERNS`, `NON_SHELL_DIRECT_PATTERNS`, `isFrameableRoute()`, `moduleLookupMaps()` L507–556, `prerenderCacheKey()` L575–595) dan `app/Http/Middleware/WrapWorkspaceShell.php`.
3. **Header keamanan & batas iframe mentah:** `app/Http/Middleware/AddSecurityHeaders.php` (`X-Frame-Options`, `Content-Security-Policy` `frame-ancestors`, `Referrer-Policy`) dan `app/Http/Middleware/DecodeHashids.php` (`HASHED_PARAM_KEYS`, `PLAIN_ROUTE_PREFIXES`).
4. **Alur intended URL & navigasi mentah:** `app/Services/Auth/TwoFactorService.php` (`safeIntendedUrl()` L316–360), `app/Services/Auth/Sso/SsoTransactionStore.php`, `app/Services/NotificationUrlResolver.php`, `app/Http/Middleware/RememberPurchasingListUrl.php`, serta `resources/js/workspace-shell.js` (`syncHistory()` L2585–2635) dan `resources/js/workspace-embed-bridge.js`.
5. **Sampel payload Infor LN mentah (byte-for-byte):**
   `favoriteContext` Base64-decoded → `scv2{"description":"Sales Orders","application":"erp","type":"session","action":"tdsls4100m000","path":null,"argument":""}` + `LogicalId=lid://infor.ln.ln01` + batas panjang query HTTP cPanel/Apache (`LimitRequestLine` / WAF ≤ 2048 byte untuk `favoriteContext`).

---

## 2. Kontrak Formal Arsitektur (State Machine & Invarian)

### 2.1 Anatomi URL Kanonik & Peta Bijection

```text
Outer Browser URL: /v2/ADASI_PRD/{appUuid}?favoriteContext={scv2Base64}&LogicalId=lid%3A%2F%2Fadasi.portal.{domain}
Inner Iframe URL : /v2/ADASI_PRD/{native_laravel_path}?_embed=1&{validated_query}
```

Definisikan dua fungsi deterministik (PHP `PortalContextCodec` & JS `portal-context-url.js` dengan fixture uji silang yang identik):

* `project(internalUrl, roleSessionMap) → canonicalContextUrl | null`
* `resolve(canonicalContextUrl, user) → { routeName, params, query, internalEmbedUrl } | HttpException(400|403|404)`

**Invarian Formal Wajib:**

1. **Round-Trip Bijection:** Untuk setiap `u` yang termasuk `CLASS_SHELL_PAGE` dan terdaftar di `config/portal_sessions.php`, berlaku `stripEmbedParam(resolve(project(u)).internalEmbedUrl) === canonicalize(u)`.
2. **Anti-Recursive-Shell (`X-Frame-Options: DENY`):** Route `portal.page` (`/{pageUuid}`) **wajib tetap** berada di `WorkspaceShell::NON_SHELL_DIRECT_PATTERNS` sehingga `AddSecurityHeaders` selalu mengirim `X-Frame-Options: DENY` dan `frame-ancestors 'none'` — mustahil terjadi shell bersarang di dalam iframe.
3. **Prerender Cache Alignment (`WrapWorkspaceShell`):** `WorkspaceShell::prerenderCacheKey()` (L575–595) saat ini meng-hash `stripEmbedParam($request->fullUrl())`. Saat `PortalPageController` merender shell langsung pada URL konteks (`/v2/ADASI_PRD/{appUuid}?favoriteContext=...`), ia **tidak boleh** meng-cache prerender di bawah URL luar (karena iframe akan meminta URL Laravel internal `?_embed=1`). Opsi minimal yang aman: `PortalPageController` membiarkan iframe melakukan fetch pertama langsung (tanpa prerender cache mismatch) **atau** menormalisasi key prerender berdasarkan `canonicalInternalUrl` hasil resolusi.
4. **No-Loop History & `popstate`:** `syncHistory()` di `workspace-shell.js` wajib menyimpan `internalUrl` di `history.state.internalUrl` sembari memproyeksikan `canonicalContextUrl` ke address bar. Handler `popstate` membaca `state.internalUrl` (atau `resolveInternalUrl(location.href)` bila cold-start) tanpa memicu `pushState` berulang.
5. **Auth / 2FA / SSO `url.intended` Preservation:** Guest yang mengakses `/v2/ADASI_PRD/{appUuid}?favoriteContext=...&LogicalId=...` lalu melewati `/login` → `/two-factor-challenge` / `/sso/callback` wajib dikembalikan ke URL konteks yang sama oleh `TwoFactorService::safeIntendedUrl()` dan `SsoTransactionStore`, lalu tetap dievaluasi ulang oleh `PortalSessionAuthorizer` pasca-login.
6. **Log & Referrer Hygiene (No PII in URL):** Karena query string tercatat di `access_log` web server (Apache/Nginx/cPanel) dan riwayat browser, `favoriteContext.argument` **hanya boleh memuat** hashid 24-karakter, enum filter ter-whitelist, tanggal ISO, dan flag paginasi — dilarang menyimpan nama user, email, NPWP, nomor rekening, atau token sesi. `Referrer-Policy` pada `AddSecurityHeaders.php` (`strict-origin-when-cross-origin` / `no-referrer`) wajib diverifikasi tidak membocorkan query `favoriteContext` ke domain eksternal.

---

## 3. Matriks Ancaman & Kontrol Keamanan (`@laravel-security-audit`)

Seluruh 8 kelas ancaman berikut wajib memiliki kontrol kode **dan** test negatif (falsification test) yang membuktikan eksploitasi gagal:

| ID | Ancaman / Temuan | Risiko | Bukti / Vektor | Kontrol Wajib di Kode |
|---|---|---|---|---|
| **SEC-01** | **Cross-Role Topology Disclosure** *(eksisting `[Verified]`)* | **Medium** | `WorkspaceShell::moduleLookupMaps()` L507–556 mengirim seluruh 72 UUID & rute `admin.*`/`finance.*` ke `workspaceConfig` Supplier. | Scope `moduleLookupMaps(?User $user)` berdasarkan `$user->role` + `PortalContext::current()`, sertakan keduanya di `$memoKey`. |
| **SEC-02** | **Premature 302 Route Disclosure** *(eksisting `[Verified]`)* | **Low–Med** | `PortalPageController` hanya punya `auth`; UUID milik role lain mengembalikan `302 Location: /v2/ADASI_PRD/admin/users`. | `PortalSessionAuthorizer::canAccess($user, $routeName)` wajib dijalankan sebelum render/redirect; tolak lintas role dengan `404` seragam tanpa header `Location`. |
| **SEC-03** | **Open Redirect / Path Injection (CWE-601)** | **High** | `favoriteContext.path` diisi `//evil.test` atau `action` diarahkan ke `logout` / `oauth/authorize` ([OWASP Redirects](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html)). | Tolak keras bila `path !== null && path !== ''`; `action` wajib allow-list eksak di `config/portal_sessions.php`; rute hasil wajib `CLASS_SHELL_PAGE`. |
| **SEC-04** | **IDOR & Dual-Scope Bypass (CWE-639 / CWE-472)** | **High** | Tamper `argument` (`id=123` raw int atau hash PO supplier lain) atau ubah `LogicalId` ke scope tidak dimiliki ([OWASP IDOR](https://cheatsheetseries.owasp.org/cheatsheets/Insecure_Direct_Object_Reference_Prevention_Cheat_Sheet.html)). | Zero-trust pada `LogicalId` (tidak pernah memutasi `session('supplier_context')`); semua param di `HASHED_PARAM_KEYS` wajib lolos `HashidCodec::decode()` (raw int → `404`); iframe tetap mengeksekusi full middleware + Model Policy. |
| **SEC-05** | **Reflected XSS & Tab Spoofing (CWE-79)** | **Medium** | `favoriteContext.description` berisi `<img onerror=...>` atau teks palsu *"System Admin"*. | Abaikan `description` dari URL untuk rendering UI; label tab selalu diambil dari server `menuLabelForRoute()` / i18n dan dirender via `textContent`. |
| **SEC-06** | **Base64/JSON DoS & Cache Poisoning (CWE-400)** | **Medium** | Payload `favoriteContext` > 2 KB, nested JSON dalam, atau sampah query untuk memenuhi cache prerender. | Hard cap: raw ≤ 2048 byte, decoded ≤ 1536 byte, `base64_decode($raw, true)`, `json_decode(..., depth: 4, JSON_THROW_ON_ERROR)`, maks 12 query param. |
| **SEC-07** | **Recursive Iframe / Clickjacking** | **Medium** | Memuat `/v2/ADASI_PRD/{appUuid}?favoriteContext=...` di dalam iframe `_embed=1` atau origin eksternal. | `portal.page` tetap di `NON_SHELL_DIRECT_PATTERNS` → `X-Frame-Options: DENY` + `frame-ancestors 'none'`; tolak `_embed=1` pada `portal.page`. |
| **SEC-08** | **Query-String PII & Referrer Leak** | **Low–Med** | Filter pencarian bebas atau data sensitif bocor ke `access_log` atau header `Referer` ke eksternal. | Allow-list ketat key `argument` per session di `config/portal_sessions.php`; pertahankan `Referrer-Policy` ketat di `AddSecurityHeaders`. |

---

## 4. Metode & Alat Eksekusi Spesifik per Fase

Seluruh fitur baru dibalik feature flag `PORTAL_CONTEXT_URLS_ENABLED=false` (reversibel 100%, nol migrasi DB, nol package Composer/NPM baru).

### Fase 0 — Hardening Eksisting (SEC-01 & SEC-02) + Inventaris Rute Otomatis
* **Alat:** `php artisan route:list --json` → ekstrak daftar lengkap rute `CLASS_SHELL_PAGE` untuk memverifikasi cakupan `config/portal_sessions.php` secara otomatis (tanpa ketinggalan satu pun rute).
* **Kode:**
  * Perbaiki `WorkspaceShell::moduleLookupMaps(?User $user)` (SEC-01).
  * Buat `App\Support\PortalSessionAuthorizer` dan terapkan di `PortalPageController` (SEC-02).
  * Tambahkan test regresi di `WorkspaceShellSecurityTest` & `PageRegistryTest`.

### Fase 1 — Session Registry, Codec Deterministik, & Cross-Language Parity Fixture
* **Kode:**
  * `config/portal_sessions.php` — peta kanonik `session_code => ['route', 'logical_id', 'params', 'query_allowlist']`.
  * `app/Support/PortalContextCodec.php` — encode/decode `scv2` Base64 dengan seluruh guard SEC-03, SEC-04, SEC-06, SEC-08.
  * `resources/js/portal-context-url.js` — implementasi JS murni tanpa DOM.
  * `tests/fixtures/portal-context-url-cases.json` — **fixture uji bersama** yang dibaca oleh **kedua** `tests/Unit/PortalContextCodecTest.php` (PHP) dan `tests/js/portal-context-url.test.mjs` (Node) untuk menjamin hasil encode/decode PHP dan JS identik byte-per-byte.

### Fase 2 — Server Deep-Link Resolver, Intended URL, & `WorkspaceShell` Integration
* **Kode:**
  * Perbarui `PortalPageController` untuk menerima `app_uuid` + `favoriteContext` + `LogicalId` → otorisasi → render langsung `layouts.workspace-shell` (`200 OK`, tanpa 302 bocor) dengan `initialCleanUrl` = URL Infor kanonik dan `initialEmbedUrl` = rute Laravel internal `?_embed=1`. Pertahankan backward compatibility 72 UUID lama di `config/page_registry.php`.
  * Pastikan `TwoFactorService::safeIntendedUrl()` & `SsoTransactionStore` melestarikan query `favoriteContext` + `LogicalId` melintasi login/2FA/SSO.
  * Pastikan `PageRegistry::url()` dan `RememberPurchasingListUrl` mengonversi remembered filters ke `argument` ter-whitelist saat flag aktif.

### Fase 3 — Client Address Bar Projection (`syncHistory` & `popstate`)
* **Kode:**
  * Ekspor `contextLinks` (`enabled`, `appUuid`, `sessionMap` ter-scope per role) dari `WorkspaceShell::shellViewData()`.
  * Integrasikan `projectShellUrl()` di `syncHistory()` (`resources/js/workspace-shell.js`) dan reverse-resolve aman pada `popstate` (nol infinite loop, nol XSS pada title).

### Fase 4 — Adversarial Security Test Suite & Parity Audit
* **Kode:**
  * `tests/Feature/Architecture/InforContextUrlSecurityTest.php` — seluruh matriks negatif SEC-01 s/d SEC-08 + uji round-trip lintas role (Purchasing, Supplier Import, Local Supplier, Dual-Scope Supplier, Finance, GA, Accounting, QC, Admin).
  * `tests/Feature/Architecture/PortalSessionRegistryCompletenessTest.php` — secara programatik memindai `Route::getRoutes()` dan gagal jika ada rute menu/`PageRegistry` yang hilang dari `config/portal_sessions.php` atau menunjuk rute non-`CLASS_SHELL_PAGE`.

---

## 5. Bukti Falsifikasi Eksternal (Objective Proof of Correctness)

Keberhasilan **tidak dibuktikan** oleh opini atau inspeksi mata, melainkan hanya dinyatakan selesai jika seluruh oracle objektif berikut lolos secara independen:

1. **Cross-Language Parity Oracle (PHP ↔ JS):** `PortalContextCodecTest` (PHPUnit) dan `tests/js/portal-context-url.test.mjs` (Node test runner) lulus terhadap `tests/fixtures/portal-context-url-cases.json` yang sama (encode/decode identik byte-per-byte).
2. **Route-Table Exhaustiveness Oracle:** `PortalSessionRegistryCompletenessTest` memverifikasi 100% entri `config/portal_sessions.php` terhadap `Route::getRoutes()` (termasuk setelah `php artisan route:cache` dalam kondisi flag ON dan OFF).
3. **Adversarial Security Oracle (`InforContextUrlSecurityTest` + `HashidUrlSecurityTest` + `WorkspaceShellSecurityTest`):**
   * Matrix serangan SEC-01 s/d SEC-08 seluruhnya menghasilkan status penolakan eksak (`400`/`403`/`404`) + `assertHeaderMissing('Location')` pada lintas-role + `assertHeader('X-Frame-Options', 'DENY')` pada `portal.page`.
   * `TestingEnvironmentDatabaseSafetyTest` tetap hijau (nol mutasi DB non-testing).
4. **Real Browser / HTTP Network Oracle (DevTools / Playwright / `curl -i`):**
   * Membuka URL panjang hasil copy-paste di tab browser baru (cold start) memuat halaman + record + filter yang sama persis dengan status `200 OK` (0 redirect 302 ke plain path), address bar tetap di `/v2/ADASI_PRD/{appUuid}?favoriteContext=...&LogicalId=...`, dan iframe memuat rute internal `?_embed=1` dengan header `X-Frame-Options: SAMEORIGIN`.
   * Menekan tombol **Back / Forward** browser di dalam `WorkspaceShell` berpindah tab/halaman tanpa loop `pushState` dan tanpa memuat ulang iframe secara berulang.
   * Uji login/2FA: membuka URL panjang saat logged-out → login → mendarat tepat di session + argumen yang diminta.
5. **Hygiene & Build Oracle:**
   ```powershell
   npm.cmd run build
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   node --test tests/js/portal-context-url.test.mjs tests/js/workspace-shell.test.mjs tests/js/server-tabs.test.mjs
   php artisan test --filter="TestingEnvironmentDatabaseSafetyTest|PortalContextCodecTest|PortalSessionRegistryCompletenessTest|InforContextUrlSecurityTest|InforPortalUrlParityTest|PageRegistryTest|PortalPathPrefixTest|WorkspaceShellSecurityTest|WorkspaceShellRouteClassificationTest|HashidUrlSecurityTest|NotificationLegacyUrlTest"
   vendor/bin/pint --test app/ config/ routes/ tests/
   git diff --check
   ```