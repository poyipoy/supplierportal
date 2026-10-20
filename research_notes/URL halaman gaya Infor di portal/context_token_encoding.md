# Encoding page context into long, Infor-like URL tokens (Laravel 12 / PHP 8.2, self-hosted)

Scope: how to make every page URL carry a long opaque `?ctx=` / `favoriteContext`-style value (or a long path token) without third-party services. Covers seven strategies, their properties, measured lengths, key rotation, security guidance, URL limits and leakage, and PHP/Laravel code.

Local environment facts used below (inspected in this repo, not web sources): `config/app.php` sets `'cipher' => 'AES-256-CBC'` and has a `previous_keys` entry; `app/Http/Middleware/AddSecurityHeaders.php` already sends `Referrer-Policy: strict-origin-when-cross-origin`; `composer.lock` pins `laravel/framework` v12.69.3. The repo already has `HasHashids` + `DecodeHashids` for public IDs (per AGENTS.md).

---

## Q1. Which encoding strategies exist, and what are their properties (deterministic, bookmarkable, tamper-proof, confidential)?

### Takeaway
For a bookmarkable, Infor-like long token, the best fit is **base64url(JSON) + HMAC-SHA256 with a dedicated key** (strategy 3): deterministic, so the same page gets the same URL; tamper-evident; about 93–114 chars for a small context. It is **not confidential**, which is acceptable only because the context holds navigation state, not secrets. Use Laravel `Crypt` (strategy 4) only when the context must be hidden and changing URLs are acceptable. Use a server-side state key (strategy 6) when the context is large or sensitive.

### Cited Findings

**Strategy 1 — percent-encoded JSON (Infor favoriteContext style)**
- Infor's Ming.le docs say links into an application should use either `?favoriteContext=` or a `?logicalId=lid://...` link. Infor product docs (Optiva workflow) build links by appending `?favoriteContext=` with URL-encoded values, followed by `&LogicalId=lid://...`. This comes from search-result summaries of Infor docs; the page I fetched did not show the literal example. — [Accessing Infor Ming.le](https://docs.infor.com/mingle/12.0.x/en-us/minolh/lsm1436532946807.html); [Executing workflow events using URLs (Optiva)](https://docs.infor.com/csplmproc/2022.x/en-us/optolh/goi1585847246535.html)
- Ming.le also appends its own URL parameters to launched apps (TenantId, inforThemeName, inforCurrentLocale, inforCurrentLanguage, inforTimeZone). — [Infor Ming.le / Mongoose URL parameters](https://docs.infor.com/csi/10.x/en-us/csbiolh/mgwsolh_cl_sl/lsh1489182792399.html)
- Properties (by construction): deterministic, fully readable, no integrity protection. Anyone can edit the value.

**Strategy 2 — base64url(JSON), no integrity**
- Laravel's own encrypter outputs standard base64 (`+`, `/`, `=`), not URL-safe base64. A hand-written URL token should therefore use the RFC 4648 §5 base64url alphabet (`-`, `_`, no padding). — [Laravel 12 Encrypter.php source](https://raw.githubusercontent.com/laravel/framework/12.x/src/Illuminate/Encryption/Encrypter.php)
- Properties: deterministic and reversible by anyone. Base64 hides nothing (it is encoding, not encryption) and provides no tamper evidence.

**Strategy 3 — base64url(JSON) + HMAC-SHA256 (hand-rolled or `URL::signedRoute`)**
- Laravel signed URLs append a `signature` hash to the query string, "which allows Laravel to verify that the URL has not been modified since it was created". `URL::temporarySignedRoute()` adds an expiry. `absolute: false` excludes the domain from the hash, and must be paired with the `signed:relative` middleware. The `signed` middleware returns 403 on an invalid signature. `hasValidSignatureWhileIgnoring([...])` exempts parameters, but "ignoring parameters allows anyone to modify those parameters". — [Laravel 12 URL Generation – Signed URLs](https://laravel.com/docs/12.x/urls#signed-urls)
- In the framework source, the signature is `hash_hmac('sha256', ...)` (hex, 64 chars) computed with `$key[0]`. Validation tries every key returned by the key resolver. `signature` and `expires` are reserved parameter names. — [UrlGenerator.php (12.x)](https://raw.githubusercontent.com/laravel/framework/12.x/src/Illuminate/Routing/UrlGenerator.php)
- For hand-rolled HMAC verification, use `hash_equals()`: it compares "without leaking information … via the execution time", and "it is important to provide the user-supplied string as the second parameter". — [PHP manual: hash_equals](https://www.php.net/manual/en/function.hash-equals.php)
- Properties: deterministic (same context + same key gives the same URL, so it is bookmarkable and cache-friendly), tamper-evident, **not confidential**.

**Strategy 4 — Laravel `Crypt::encryptString`**
- "All encrypted values are encrypted using OpenSSL and the AES-256-CBC cipher. Furthermore, all encrypted values are signed with a message authentication code (MAC)." Tampered values throw `Illuminate\Contracts\Encryption\DecryptException`. — [Laravel 12 Encryption](https://laravel.com/docs/12.x/encryption)
- Supported ciphers are aes-128-cbc, aes-256-cbc, aes-128-gcm and aes-256-gcm. The IV is `random_bytes(...)` on every call. The payload is `base64_encode(json_encode({iv, value, mac, tag}))`. For CBC, `mac` is HMAC-SHA256 hex; for GCM it is empty and `tag` holds the AEAD tag. — [Encrypter.php (12.x)](https://raw.githubusercontent.com/laravel/framework/12.x/src/Illuminate/Encryption/Encrypter.php)
- Properties: confidential and tamper-proof, but **non-deterministic**: the same page yields a different URL each time, which breaks "same page, same URL" bookmark semantics, link equality and HTTP caching. The base64-of-JSON-of-base64 envelope also adds a lot of overhead.

**Strategy 5 — deterministic / authenticated encryption (AES-SIV, sodium AEAD)**
- RFC 5297 SIV: a single 256/384/512-bit key is split in half (S2V and CTR), and the output begins with a 128-bit synthetic IV that also serves as the authenticator. On nonce reuse, "authenticity is retained and confidentiality is only compromised to the extent that an attacker can determine" that the same plaintext/AD was protected. Deterministic encryption therefore leaks equality of plaintexts. — [RFC 5297](https://www.rfc-editor.org/rfc/rfc5297.html)
- Libsodium in PHP: `sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($message, $additional_data, $nonce, $key)` takes a 24-byte nonce ("large enough bound to generate randomly (i.e. random_bytes())") and a 256-bit key, and returns ciphertext plus tag. The manual calls it "Generally … the best of the provided AEAD modes". Available since PHP 7.2. — [PHP manual: sodium_crypto_aead_xchacha20poly1305_ietf_encrypt](https://www.php.net/manual/en/function.sodium-crypto-aead-xchacha20poly1305-ietf-encrypt.php)
- **AES-SIV is not usable through PHP's openssl extension on PHP 8.3 / OpenSSL 3.0.** `openssl_get_cipher_methods()` lists `aes-*-siv`, but `openssl_cipher_iv_length()` and `openssl_encrypt()` fail with "Unknown cipher algorithm". The php-src issue was closed as fixed in PHP 8.5 only; the maintainer said it is "hard to fix in 8.3 as it can work only with proper provider fetching". — [php-src issue #19994](https://github.com/php/php-src/issues/19994). Reproduced locally on PHP 8.3.6 + OpenSSL 3.0.13: `aes-256-siv` appears in the cipher list, and `openssl_encrypt` returns `false` with "Unknown cipher algorithm" (local experiment).
- Properties: confidential and tamper-proof. Deterministic only if the nonce is derived from the plaintext, which on PHP 8.2 means a home-made SIV-like construction such as nonce = HMAC(k2, plaintext) truncated to 24 bytes, fed to XChaCha20-Poly1305. That is non-standard crypto (see Inferences).

**Strategy 6 — server-side state key (random handle → DB/cache)**
- OWASP ASVS 5.0 14.2.1 bans *sensitive* data in URLs (see Q3). A random handle that maps to server-held state keeps the context itself out of the URL. Laravel's cache and DB are self-hosted, so no third party is needed. I found no fetched primary source on SAP "app-state" semantics (see Gaps).
- Properties: short (22–43 chars), random, confidential (the URL holds no data), tamper-proof (unguessable handle). Bookmarks only live as long as the stored row/TTL, and the approach needs storage plus cleanup.

**Strategy 7 — long public IDs (UUIDv7/ULID, longer Hashids/Sqids)**
- Sqids FAQ: "There is no encryption of any kind". It lists "Any data that's sensitive" under "What is it not good for", and IDs "could be decoded back into numbers" (exposing e.g. total user count). — [Sqids FAQ](https://sqids.org/faq)
- Properties: deterministic per record and bookmarkable. Not tamper-evident (another valid ID is just another record, which authorization must handle). They only identify a record and do not carry page/tab context.

### Inferences
- **Measured token lengths** (local experiment, PHP 8.3.6 / OpenSSL 3.0.13, context `{"page":"purchasing.po.show","po":123,"tab":"items"}` = 52 bytes JSON; script at `/tmp/claude-0/-home-user-supplierportal/ea3de87b-cf7c-506d-839d-81a9d28a95c4/scratchpad/len.php`):

  | # | Strategy | Token chars | Deterministic | Tamper-evident | Confidential |
  |---|---|---|---|---|---|
  | 1 | `rawurlencode(json)` | 86 | yes | no | no |
  | 2 | base64url(json) | 70 | yes | no | no (trivially decoded) |
  | 3 | base64url(json) + "." + base64url(HMAC-SHA256 full 32 B) | 114 | yes | yes | no |
  | 3b | same, HMAC truncated to 128 bits | 93 | yes | yes | no |
  | 3c | Laravel `signedRoute` with params as query string | query params + `&signature=` + 64 hex (~75 extra chars; +`&expires=` ~19 for temporary) | yes (non-temporary) | yes | no |
  | 4 | `Crypt::encryptString` AES-256-CBC (emulated per source) | 288 (std base64; ~292 after percent-encoding `+/=`) | **no** | yes | yes |
  | 4b | same with AES-256-GCM | ~200 | **no** | yes | yes |
  | 5a | XChaCha20-Poly1305, random nonce, base64url(nonce‖ct‖tag) | 123 | no | yes | yes |
  | 5b | XChaCha20-Poly1305, HMAC-derived nonce (SIV-like) | 123 | yes | yes | yes, but leaks equality |
  | 6 | random handle 128-bit / 256-bit base64url (hex 256-bit = 64) | 22 / 43 | no (per save) | yes (unguessable) | yes |
  | 7 | UUID / ULID | 36 / 26 | yes per record | no | no (time-ordered IDs leak creation time) |

  Overhead formulas: base64url ≈ 4/3 × bytes; CBC pads to a 16-byte block and then double-base64s; XChaCha adds 24 B nonce + 16 B tag = 40 B (≈ 54 chars).
- For an "Infor-like long opaque string", strategy 3 (or 3b) already looks long and opaque. An optional cosmetic step is to compress (`gzdeflate`) before base64url when contexts grow, but compression before encryption can leak via length (CRIME-style) when attacker-influenced data is mixed with secrets, so only compress non-secret context.
- "Opaque" ≠ "confidential". Strategies 1–3 and 7 reveal the context to anyone who base64-decodes them. Only 4, 5 and 6 hide it.
- Deterministic encryption (5b) leaks which users/pages share the same context and is a home-made construction on PHP 8.2/8.3. It is rarely worth it: if the context is not secret, use strategy 3; if it is secret, use strategy 6.

### Gaps
- No primary Infor document that specifies `favoriteContext` syntax (percent-encoded JSON vs. key/value string) was retrievable. The Optiva page fetched without the example, and the 2026.10 version returned 404. "Percent-encoded JSON" is an assumption from the brief and search summaries.
- No authoritative benchmark numbers were found. Performance claims below are inference: HMAC-SHA256 and AEAD on ~100-byte inputs cost microseconds, negligible next to a DB query, and a cache/DB lookup for strategy 6 adds one round-trip.

---

## Q2. How does key rotation (APP_KEY / APP_PREVIOUS_KEYS) affect old links for each strategy?

### Takeaway
Laravel 12 supports graceful rotation for both `Crypt` and signed URLs through `APP_PREVIOUS_KEYS`, as long as old keys stay listed. A hand-rolled HMAC token should embed a key ID (`v1.`) and keep a keyring, so rotation does not break bookmarks. Server-side handles (6) and public IDs (7) are unaffected by APP_KEY rotation.

### Cited Findings
- Changing APP_KEY logs everyone out and makes old ciphertext undecryptable. With `APP_PREVIOUS_KEYS` (comma-delimited), "Laravel will always use the 'current' encryption key when encrypting values", and on decrypt tries current then previous keys. — [Laravel 12 Encryption – Gracefully Rotating Encryption Keys](https://laravel.com/docs/12.x/encryption#gracefully-rotating-encryption-keys)
- `Encrypter::getAllKeys()` returns `[$this->key, ...$this->previousKeys]`; decrypt stops at the first key that works. — [Encrypter.php (12.x)](https://raw.githubusercontent.com/laravel/framework/12.x/src/Illuminate/Encryption/Encrypter.php)
- The URL generator's key resolver returns `[$config->get('app.key'), ...($config->get('app.previous_keys') ?? [])]`. Signing uses the first key, and verification accepts any. Signed URLs therefore survive APP_KEY rotation while the old key remains in `APP_PREVIOUS_KEYS`. — [RoutingServiceProvider.php (12.x)](https://raw.githubusercontent.com/laravel/framework/12.x/src/Illuminate/Routing/RoutingServiceProvider.php); [UrlGenerator.php (12.x)](https://raw.githubusercontent.com/laravel/framework/12.x/src/Illuminate/Routing/UrlGenerator.php)
- OWASP: "a single key should be used for only one purpose". Limiting cryptoperiods "restricts the amount of data protected by a single key"; "Establish and document a key rotation schedule". — [OWASP Key Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Key_Management_Cheat_Sheet.html)

### Inferences
- Reusing APP_KEY for URL tokens couples bookmark lifetime to session/cookie key rotation and violates key separation. Prefer a dedicated `URL_CONTEXT_KEY` (plus `URL_CONTEXT_PREVIOUS_KEYS`), or derive one with `hash_hkdf('sha256', $appKey, 32, 'url-ctx-v1')`. HKDF-from-APP_KEY still rotates with APP_KEY, so a fully independent key is better if bookmarks must outlive APP_KEY rotation.
- Strategy 3 (deterministic) changes every URL once the signing key rotates: new links differ from old bookmarks, but both verify while the old key is in the keyring. Retiring an old key permanently breaks the bookmarks signed with it, so decide on a retention window (e.g. ≥ 1 year) and log hits on previous keys before removing them.
- `Crypt` tokens (4) behave the same way on rotation, but already change on every render.
- Strategy 6 handles do not depend on keys. Their lifetime is the row/TTL. Strategy 7 is key-independent, except that Hashids salt rotation breaks all IDs. This repo already treats the Hashids salt as a production secret.

### Gaps
- No Laravel doc statement says explicitly that signed URLs honour `previous_keys`; this is established from framework source only.

---

## Q3. What does security guidance (OWASP ASVS 5.0, cheat sheets) require, and what are the replay/sharing risks?

### Takeaway
ASVS 5.0 14.2.1 forbids sensitive information in URLs, so a URL context token may carry only non-sensitive navigation state (route name, record hashid, tab, filters). Any token, signed or encrypted, is a *locator*, not a *capability*: authorization must still run on every request, because tokens get shared, bookmarked, logged and replayed.

### Cited Findings
- ASVS 5.0 14.2.1 (L1): "Verify that sensitive data is only sent to the server in the HTTP message body or header fields, and that the URL and query string do not contain sensitive information, such as an API key or session token." — [OWASP ASVS 5.0 V14 Data Protection](https://raw.githubusercontent.com/OWASP/ASVS/master/5.0/en/0x23-V14-Data-Protection.md)
- ASVS 5.0 14.2.2 (L2): prevent sensitive data from being cached in server components. 14.3.2 (L2): set anti-caching headers (`Cache-Control: no-store`) for sensitive data. — [OWASP ASVS 5.0 V14](https://raw.githubusercontent.com/OWASP/ASVS/master/5.0/en/0x23-V14-Data-Protection.md)
- Laravel signed URLs are documented for "routes that are publicly accessible yet need a layer of protection against URL manipulation". They guard integrity of parameters and do not replace auth. — [Laravel 12 URL Generation](https://laravel.com/docs/12.x/urls#signed-urls)
- Sqids/Hashids are explicitly "not … a technique to hide sensitive data". — [Sqids FAQ](https://sqids.org/faq)
- Key separation and rotation: [OWASP Key Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Key_Management_Cheat_Sheet.html)

### Inferences
- **Encryption ≠ access control.** If user A shares a valid `ctx` with user B, B's request must still pass `auth`, `role:*`, `supplier.scope:*`, policy and the `supplier_id` ownership checks. The token decodes to "which page and record", and the existing middleware/policies decide whether B may see it. Never put role, user ID or permission flags in the token and trust them.
- **Replay:** a deterministic signed token is replayable forever (until key retirement). That is fine for GET navigation and must never authorize state changes (POST still needs CSRF + policy). For one-time or expiring links, use `temporarySignedRoute` (adds `expires`) or strategy 6 with a TTL.
- **Bind to purpose:** include a purpose/version prefix in the MAC input (e.g. `"ctx.v1|" . $payload`). This stops a token minted for one purpose (e.g. a signed export link) from validating as another.
- **Validate after verifying:** after the MAC checks, still validate the decoded JSON against a whitelist schema (allowed `page` = known route names, IDs must be hashids, which `DecodeHashids` rejects as raw integers). Cap the token length before decoding (e.g. reject > 2048 chars) to avoid DoS via huge inputs.
- The repo's Hashid rule ("raw integer on a hashed parameter → 404") should apply inside the context too. Store `po` as a hashid, not `123`, so a decoded context does not reveal sequential DB IDs (Sqids FAQ notes this leak).

### Gaps
- I did not fetch the OWASP Cryptographic Storage Cheat Sheet. Its algorithm recommendations (AES-GCM/ChaCha20-Poly1305, avoid home-made schemes) are general knowledge here, not quoted.
- The ASVS V8 (authorization) text was not retrieved. The "authorization still mandatory" point is inference plus Laravel's documented intent for signed URLs.

---

## Q4. What are the practical URL limits and leakage channels (servers, browsers, logs, Referer)?

### Takeaway
Keep the full URL well under **2,048 bytes of query string / 4,096 bytes of URL** (the IIS defaults, the strictest common server). Apache and nginx default to about 8 KB request lines, and RFC 9110 recommends supporting at least 8,000 octets. Tokens of 100–300 chars are far inside every limit. Assume every URL lands in access logs, browser history and same-origin Referer headers.

### Cited Findings
- RFC 9110 §4.1 recommends that all senders and recipients support "URIs with lengths of 8000 octets in protocol elements". — [RFC 9110](https://www.rfc-editor.org/rfc/rfc9110.html)
- Apache httpd 2.4: `LimitRequestLine` default **8190** bytes. The request line includes method, URI and protocol version, and "Under normal conditions, the value should not be changed from the default". The value is taken from the first-listed vhost for an IP/port. — [Apache core docs – LimitRequestLine](https://httpd.apache.org/docs/2.4/mod/core.html#limitrequestline)
- nginx: `large_client_header_buffers 4 8k` (default). "A request line cannot exceed the size of one buffer, or the 414 (Request-URI Too Large) error is returned". `client_header_buffer_size` defaults to 1k. — [nginx ngx_http_core_module](https://nginx.org/en/docs/http/ngx_http_core_module.html#large_client_header_buffers)
- IIS request filtering defaults: `maxUrl` **4096** bytes and `maxQueryString` **2048** bytes. Violations return 404 with substatus 404.14 (URL too long) or 404.15 (query string too long). — [IIS requestLimits](https://learn.microsoft.com/en-us/iis/configuration/system.webserver/security/requestfiltering/requestlimits/)
- `Referrer-Policy: strict-origin-when-cross-origin` (the browser default since Nov 2020): "Send the origin, path, and query string when performing a same-origin request. For cross-origin requests send the origin (only) when … HTTPS→HTTPS. Don't send the Referer header to less secure destinations". `same-origin` sends nothing cross-origin, and `no-referrer` sends nothing at all. — [MDN Referrer-Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Referrer-Policy)

### Inferences
- This app already sends `strict-origin-when-cross-origin`. The `ctx` token therefore leaks via Referer only to same-origin requests (to ourselves), and external sites (CDNs, Pusher) see only the origin. Tightening to `same-origin` would also drop the origin cross-origin. That is optional and could affect CDN/analytics expectations; verify before changing.
- Access logs (nginx/Apache/Laravel request logs), browser history, proxy logs and screenshots will capture the token. This is acceptable for strategies 3/7 only because the context is non-sensitive by design (ASVS 14.2.1). Strategy 6 is the choice if the context could ever contain business-sensitive filters (e.g. amounts, supplier names).
- Behind a reverse proxy, the smallest limit in the chain applies (e.g. nginx 8k in front of PHP-FPM; IIS 2k query string on Windows/Laragon if IIS were used). Budget about 1,500 chars for `ctx` at most and keep typical tokens < 300.
- Prefer a query parameter (`?ctx=`) over a path segment for long tokens. Path segments interact with route matching, `DecodeHashids`, and the "static before dynamic" route-ordering rule in this repo. base64url characters (`-`, `_`) are safe in both places, while standard base64 `+`, `/` and `=` are not safe in path segments.

### Gaps
- No authoritative current browser maximum URL lengths were fetched (commonly cited: Chromium 2 MB, old IE 2,083). Server limits dominate anyway.
- The 414 behaviour of Apache was not stated on the fetched page excerpt (it is widely documented as 414).

---

## Q5. Concrete PHP/Laravel implementation guidance

### Takeaway
Implement a small `ContextToken` support class: versioned, base64url JSON plus a truncated HMAC-SHA256 under a dedicated key with a keyring. Mint it in `route()` helpers or a view composer, and verify it in middleware that only *hydrates hints* (tab, filters). Route model binding + policies keep making the access decision. Use `Crypt` (GCM) or a cache-backed handle only where confidentiality is required.

### Cited Findings
- `hash_equals(known, user)` order matters. — [PHP manual: hash_equals](https://www.php.net/manual/en/function.hash-equals.php)
- `URL::signedRoute(...)` / `signed` middleware / `signed:relative`, `hasValidSignatureWhileIgnoring` caveat. — [Laravel 12 URL Generation](https://laravel.com/docs/12.x/urls#signed-urls)
- XChaCha20-Poly1305 API (24-byte nonce, AD parameter). — [PHP manual: sodium XChaCha20-Poly1305](https://www.php.net/manual/en/function.sodium-crypto-aead-xchacha20poly1305-ietf-encrypt.php)
- Laravel encrypter output is standard base64 (needs `rawurlencode` or base64url conversion in URLs). — [Encrypter.php (12.x)](https://raw.githubusercontent.com/laravel/framework/12.x/src/Illuminate/Encryption/Encrypter.php)

### Inferences (code sketches, not executed against the app)

**A. Deterministic signed context (recommended default; strategy 3)**
```php
namespace App\Support;

final class ContextToken
{
    private const PREFIX = 'c1';            // version + purpose
    private const MAX_LEN = 2048;

    /** @param array<string,string> $keys  ['k2' => raw32bytes (current), 'k1' => raw32bytes (previous)] */
    public function __construct(private array $keys, private string $currentKid) {}

    public function encode(array $ctx): string
    {
        ksort($ctx);                                     // canonical → deterministic
        $body = self::b64u(json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $msg  = self::PREFIX.'.'.$this->currentKid.'.'.$body;
        $tag  = substr(hash_hmac('sha256', $msg, $this->keys[$this->currentKid], true), 0, 16); // 128-bit tag
        return $msg.'.'.self::b64u($tag);
    }

    public function decode(string $token): ?array
    {
        if (strlen($token) > self::MAX_LEN) return null;
        $parts = explode('.', $token);
        if (count($parts) !== 4 || $parts[0] !== self::PREFIX) return null;
        [$p, $kid, $body, $tag] = $parts;
        $key = $this->keys[$kid] ?? null;
        if ($key === null) return null;
        $expected = self::b64u(substr(hash_hmac('sha256', "$p.$kid.$body", $key, true), 0, 16));
        if (! hash_equals($expected, $tag)) return null;
        $ctx = json_decode(self::b64uDecode($body), true, 4);
        return is_array($ctx) ? $ctx : null;             // then whitelist-validate keys/values
    }

    private static function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    private static function b64uDecode(string $s): string { return base64_decode(strtr($s, '-_', '+/'), true) ?: ''; }
}
```
- Keys come from env (`URL_CONTEXT_KEYS="k2:base64...,k1:base64..."`), generated with `random_bytes(32)`. They are separate from APP_KEY (key separation). Register the class as a singleton in `AppServiceProvider`.
- Use the token as a *hint* only: middleware decodes `?ctx=`, validates `page` against known route names and `tab` against an allow-list, and passes the result to the view. The real record still comes from route-model binding (hashid) + policy. An invalid token falls back to defaults (ignore it, or redirect to the clean URL); do not 500.

**B. Laravel built-ins without custom crypto**
```php
// Signed (deterministic unless temporary). Signature covers path + all query params.
$url = URL::signedRoute('purchasing.po.show', ['po' => $po, 'tab' => 'items'], absolute: false);
Route::get(...)->middleware('signed:relative');
```
- Signed routes return 403 on mismatch. That is fine for emailed links but harsh for ordinary in-app navigation, where any client-side param change (pagination, DataTables) breaks the signature unless the parameter is explicitly ignored.

**C. Confidential context, URL may change each render (strategy 4)**
```php
$ctx = Crypt::encryptString(json_encode($ctx));        // std base64 → must be URL-encoded
$url = route('purchasing.po.show', ['po' => $po, 'ctx' => $ctx]); // route() encodes it
try { $data = json_decode(Crypt::decryptString($request->query('ctx')), true); }
catch (\Illuminate\Contracts\Encryption\DecryptException) { $data = null; }
```
- Expect about 200 (GCM) to 290 (CBC) chars for a tiny context. Switching the global cipher to GCM affects all app encryption (cookies, sessions), so do not change `config/app.php` for this alone. If GCM is wanted only here, construct a separate `new Encrypter($key, 'aes-256-gcm')` with its own key.

**D. Server-side state handle (strategy 6)**
```php
$id = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='); // 22 chars, 128-bit
Cache::put("ctx:$id", ['user' => auth()->id(), 'ctx' => $ctx], now()->plus(days: 30));
// on read: $row = Cache::get("ctx:$id"); optionally require $row['user'] === auth()->id()
```
- Durable bookmarks need a DB table (`page_contexts`: id char(22) PK, user_id, payload JSON, created_at, last_used_at, expires_at) plus a scheduled prune in `routes/console.php`, following the repo's `withoutOverlapping()` + business-timezone pattern. Use `BusinessTime` for any date maths (repo guardrail). Expiry means old bookmarks degrade to "context expired, open default view", so plan that UX. Dedupe by hashing `(user_id, canonical ctx)` to reuse handles instead of creating rows on every render.

**Decision summary**
- Need same-page-same-URL, bookmarkable, non-secret context → **A** (or B for emailed links).
- Context must be hidden, URLs may vary → **C**, or better **D**.
- Large or sensitive context, revocable, short URL → **D**.
- Only a record identity → existing **Hashids** (7), already in the repo.
- Avoid on PHP 8.2/8.3: AES-SIV via openssl (not functional), home-made deterministic AEAD (5b), unsigned 1/2 for anything the server acts on.

### Gaps
- None of the snippets above were executed inside the Laravel app (vendor not installed in this checkout). Only the raw-PHP length script was run.
- No measured benchmarks. Per-request cost is assumed negligible for HMAC/AEAD (inference).
