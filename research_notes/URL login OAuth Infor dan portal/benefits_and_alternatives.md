# Long / OAuth-style Login URLs vs. Real Login Hardening for a Single Laravel Portal (ADASI Portal Supplier)

Scope note: this file covers whether a long or obscured login URL helps a single-app portal, which OAuth/OIDC parameters carry real security value, when a separate IdP or SSO pays off, and which login hardening measures can be built inside the Laravel monolith without third-party services. Codebase facts marked "(repo)" were inspected in `/home/user/supplierportal` on 2026-10-19. They are not web-sourced and are listed so the report writer can tell what already exists.

## 1. Does a long/random login URL or obscured login path add security?

### Takeaway
No, not in any meaningful way. Security must rest on secrets (credentials, keys, authenticators) and on controls such as throttling, MFA and breached-password checks, not on the attacker not knowing the URL. A hidden or random login path only cuts automated bot noise in the logs. It is a "speed bump", and OWASP/NIST guidance never lists it as a control. A long OAuth URL is not long for secrecy: its length comes from protocol parameters that are public to anyone who starts the flow.

### Cited Findings
- The "open design" principle (Kerckhoffs) says a system's security should not depend on keeping its protection mechanisms secret. It should depend on possessing secrets such as passwords and keys, not on the attacker being ignorant of the design. — [Open Design (Security) notes](https://notes.lukasl.dev/Knowledge/Open-Design-(Security))
- Secondary sources attribute this wording to NIST SP 800-123: "system security should not depend on the secrecy of the implementation or its components". I could not confirm it against the NIST PDF itself (see Gaps). — [eFront blog on the obscurity fallacy](https://efrontlearning.com/blog/2012/04/open-source-and-the-security-through-obscurity-fallacy.html)
- Academic support for Kerckhoffs' principle is broad, though "there is no agreement" on the related open-source question. — [arXiv 0801.3924, "Increased security through open source"](https://arxiv.org/pdf/0801.3924)
- The OWASP Authentication Cheat Sheet does not mention hidden or obscured login URLs at all. Its login-endpoint controls are:
  - TLS on the login page and all authenticated pages;
  - a failed-login counter "associated with the account itself, rather than the source IP address";
  - generic error messages with consistent status code and timing;
  - a minimum password length (8 with MFA, 15 without), with a maximum of at least 64;
  - breached-password blocking;
  - MFA ("by far the best defense against the majority of password-related attacks");
  - re-authentication before sensitive changes;
  - CAPTCHA only as "a defense-in-depth control".
  — [OWASP Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html)
- OWASP ASVS 5.0.0 (released 30 May 2025) chapter V6 has no requirement about URL secrecy either. Its relevant requirements are:
  - 6.2.1 (L1): minimum 8 characters, 15 strongly recommended;
  - 6.2.4 (L1): check against at least the top 3,000 passwords;
  - 6.2.12 (L2): check against breached passwords;
  - 6.2.7 (L1): allow paste and password managers;
  - 6.3.1 (L1): controls against credential stuffing and brute force;
  - 6.3.3 (L2 MFA; L3 hardware-based, phishing-resistant);
  - 6.3.5 (L3): notify users of suspicious authentication attempts;
  - 6.3.7 (L3): notify after changes to authentication details;
  - 6.3.8 (L3): no user enumeration, including in registration and forgot-password;
  - 6.3.2 (L1): no default accounts.
  — [OWASP ASVS 5.0.0 V6 Authentication (GitHub)](https://raw.githubusercontent.com/OWASP/ASVS/v5.0.0/5.0/en/0x15-V6-Authentication.md); release date from [Smithery ASVS summary](https://smithery.ai/skills/Zate/asvs-requirements)
- NIST SP 800-63B-4 (the revision 4 text at pages.nist.gov) relies on these controls:
  - single-factor passwords at least 15 characters; at least 8 when used only inside MFA;
  - a mandatory blocklist of "commonly used, expected, or compromised" passwords;
  - "SHALL NOT impose other composition rules";
  - no periodic rotation;
  - consecutive failed attempts limited to "no more than 100";
  - at AAL2, verifiers "SHALL offer at least one phishing-resistant authentication option".

  It treats URL secrecy as nothing. — [NIST SP 800-63B-4](https://pages.nist.gov/800-63-4/sp800-63b.html)
- The WordPress "hide login URL" debate is the clearest real-world parallel:
  - A Wordfence support response compares hiding the login page to a key under the doormat. It says that with 2FA, strong passwords and brute-force protection, it is "not going to really add any further value". (This is a vendor forum reply, not formal policy.)
  - Hosting vendors call such techniques "speed bumps, not roadblocks" whose main value is reducing bot "noise".
  — [securityscientist.net Q&A](https://www.securityscientist.net/blog/12-questions-and-answers-about-hide-wp-login-or-change-login-url/); [Verpex: WordPress security by obscurity](https://verpex.com/blog/privacy-security/wordpress-security-through-obscurity); [Raidboxes: Hide WordPress Admin](https://raidboxes.io/en/blog/security/hide-wordpress-admin/)
- Secrets in URLs leak. OWASP warns that IDs in URLs can be disclosed through "web links and logs, web browser history and bookmarks", "the Referer header or search engines". A "secret" login path or token in a URL therefore does not stay secret. — [OWASP Session Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html)
- RFC 9700 §4.2 also lists Referer-header leakage of codes, `state` and tokens as an attack class. — [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html)

### Inferences
- A random login path such as `/login-8f3a…` gives no protection against credential stuffing, phishing or password reuse. The path can be found through links, emails, history, logs, the Referer header and shoulder-surfing. Every supplier must know it, so it is effectively public for an external-supplier portal.
- The only real benefit is less bot traffic and log noise. ADASI already handles that with identity-based rate limiting, optional Turnstile, auth audit logs and generic responses (repo). The marginal value is near zero.
- Obscurity has real costs. It breaks bookmarks and password-manager URL matching. It increases support calls ("what is the login link?"). It can create false confidence that leads to weaker real controls.

### Gaps
- I could not fetch the NIST SP 800-123 PDF to confirm the exact "secrecy of implementation" wording. Cite it as attributed, or use the Kerckhoffs/open-design principle instead.
- I found no controlled study measuring attack reduction from hidden login URLs. Evidence is vendor commentary only.

## 2. Which OAuth/OIDC parameters carry real security value, and do those threats exist in a single-app session login?

### Takeaway
OAuth URLs are long because they carry protocol parameters. Each parameter defends against threats that exist only when a browser hands an authorization result between two parties (a client and a separate authorization server):
- `state` / `nonce` / PKCE defend against CSRF on the callback, code injection, code interception and replay.
- Exact `redirect_uri` matching defends against code or token exfiltration.

A single Laravel monolith with a same-origin POST form and a session cookie has no authorization code, no callback endpoint and no token to intercept. That threat class is mostly absent. Its analogues are login CSRF (handled by the CSRF token), session fixation (handled by session regeneration) and open redirects via the return URL (handled by allowlisting).

### Cited Findings
- RFC 9700 (BCP 240, January 2025):
  - "Clients MUST prevent Cross-Site Request Forgery (CSRF)". They may rely on PKCE or the OIDC `nonce`. Otherwise "one-time use CSRF tokens carried in the `state` parameter that are securely bound to the user agent MUST be used".
  - "Public clients MUST use PKCE". PKCE is "RECOMMENDED" for confidential clients, and "Authorization servers MUST support PKCE".
  - PKCE "ensures that an attacker cannot redeem a stolen authorization code" without the verifier.
  - "nonce does not protect authorization codes of public clients".
  — [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html)
- RFC 9700 on redirect URIs and open redirectors:
  - Authorization servers "MUST utilize exact string matching" for redirect URIs, except localhost ports for native apps.
  - "Clients and authorization servers MUST NOT expose URLs that forward the user's browser to arbitrary URIs". Open redirectors "can enable exfiltration of authorization codes and access tokens".
  - Exact matching also helps detect mix-up attacks.
  — [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html)
- RFC 9700 threat catalogue:
  - redirect URI validation flaws (4.1);
  - Referer leakage (4.2);
  - mix-up (4.4);
  - code injection (4.5);
  - CSRF on the redirection endpoint (4.7);
  - PKCE downgrade (4.8);
  - open redirection (4.11).

  All of these assume a client ⇄ authorization-server redirect exchange. — [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html)
- RFC 6749 §4.1.1 describes `state` as "an opaque value used by the client to maintain state between the request and callback" that "SHOULD be used for preventing cross-site request forgery". §3.1.2.2 notes that without redirect URI registration an attacker can use the authorization endpoint "as an open redirector". — [RFC 6749](https://www.rfc-editor.org/rfc/rfc6749)
- Laravel's documented login pattern is `Auth::attempt()`, then `$request->session()->regenerate()` "to prevent session fixation", then `redirect()->intended('dashboard')`. At logout it calls `session()->invalidate()` and `regenerateToken()` (CSRF). — [Laravel 12 Authentication docs](https://laravel.com/docs/12.x/authentication)
- OWASP's session analogues:
  - "The session ID must be renewed or regenerated by the web application after any privilege level change".
  - Use a "different session ID or token name" before and after authentication.
  - Never accept a session ID from a URL parameter.
  - Cookies should be `Secure`, `HttpOnly`, `SameSite=Strict (preferred) or Lax`, with a `__Host-` prefix "Recommended for session IDs".
  — [OWASP Session Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html)
- Return-URL handling:
  - OWASP recommends mapping server-side IDs to targets, or using "a framework helper that rejects non-local destinations".
  - For external targets, compare parsed "scheme, canonical host, and effective port against an explicit allowlist", with no prefix/suffix string matching.
  - Its cited vulnerable example is a login action redirecting to an unvalidated `returnUrl`.
  — [OWASP Unvalidated Redirects and Forwards Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html)

### Inferences
Mapping each OAuth parameter to a single-app session login:

| OAuth/OIDC parameter | Threat it defends | Exists in ADASI's single-app login? | Equivalent control already in the monolith |
|---|---|---|---|
| `state` | CSRF / login-CSRF on the callback | Partly: login CSRF exists (attacker logs victim into attacker's account) | Laravel CSRF token on the POST `/login` form; session-bound pending-MFA state |
| PKCE (`code_challenge`) | Stolen authorization-code redemption | No: there is no authorization code | n/a |
| `nonce` | ID-token replay / injection | No: there is no ID token | n/a |
| `redirect_uri` exact match | Code/token exfiltration via redirects | Analogue: open redirect via post-login `intended` URL | (repo) `TwoFactorService::safeIntendedUrl()` rejects foreign host/scheme/port and protocol-relative `//` URLs, and denies internal JSON/polling paths |
| `response_mode`, `prompt`, `max_age`, `acr_values` | Auth-context negotiation between parties | `max_age` analogue only (step-up / recent auth) | `password.confirm` middleware (Laravel docs); `throttle:6,1` example |

- Copying OAuth-style long URLs into a single-app login brings OAuth's URL-leakage problems: Referer, logs and history (RFC 9700 §4.2; OWASP session cheat sheet). It brings none of the protections, unless the values are also bound to the user agent, which the session cookie already does.

### Gaps
- I could not read RFC 6749 §10.12 and §10.15 verbatim because the fetch was truncated. The cited content comes from §4.1.1 and §3.1.2.2, which point to those sections.

## 3. When does a separate identity provider / SSO pay off, and when is it overkill?

### Takeaway
An IdP (Keycloak, Entra ID, Google Workspace, etc.) pays off when identity is shared. Examples:
- several applications need single sign-on;
- mobile, SPA or API clients need tokens;
- staff should sign in with the corporate directory (joiner/mover/leaver handled centrally);
- MFA, conditional access and audit must be centralized across many systems;
- there are many tenants or federated partners.

For one server-rendered monolith with its own user table, which already has TOTP, throttling, device tracking and audit, an IdP adds an extra critical component and attack surface and solves no problem the app has today. It becomes worth revisiting if ADASI staff should log in with the corporate directory, or if more internal apps appear.

### Cited Findings
- On a single application: "If your application consists of a single application with just one client in the Keycloak realm, you won't benefit from Single Sign-On (SSO) capabilities". For applications "solely reliant on a pure user database, Keycloak could be overkill". — [DEV Community: Introduction to Keycloak](https://dev.to/jankaritech/introduction-to-keycloak-5803)
- A self-hosted IdP is recommended mainly "whenever identities must stay in-house" (sovereignty). Keycloak "comes at the cost of an additional component to maintain (updates, high availability)". (Vendor blog; treat as opinion.) — [agence-scroll: Auth0 vs Keycloak](https://agence-scroll.com/en/blog/auth0-vs-keycloak)
- Community thread "Is Keycloak overkill for our use case?". Practitioners weigh IdP weight against needs such as plugging into existing enterprise SSO. — [Keycloak forum](https://forum.keycloak.org/t/is-keycloak-overkill-for-our-use-case/1030)
- If an IdP is adopted, RFC 9700 requirements apply in full: PKCE, exact redirect matching, no open redirectors and CSRF protection. Doing OAuth correctly creates new obligations. — [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html)

### Inferences
- Decision triggers for ADASI that would justify an IdP or federation:
  1. internal ADASI staff should use corporate credentials (e.g. Entra ID) so offboarding is automatic;
  2. a second or third internal app (ERP add-ons, QC system) needs SSO with the portal;
  3. a native mobile app or third-party API consumers need OAuth tokens;
  4. auditors require centralized MFA and conditional-access policy.
- Supplier users are external and would need either local accounts in the IdP or B2B federation. An IdP does not remove supplier-account lifecycle work: registration review, scopes and activation (repo).
- A middle path inside the monolith: a single "Sign in with company account" OIDC client used only for internal staff, with suppliers kept on local auth. This would require a third-party IdP, which is outside this research's no-third-party constraint, so it is noted only as a future option.

### Gaps
- I found no authoritative standards-body guidance (NIST/OWASP) giving a decision rule for "adopt an IdP". The available material is practitioner and vendor opinion.

## 4. Practical login hardening inside a Laravel monolith (no third-party service), ranked by value; and the "login transaction ID" pattern

### Takeaway
The highest-value next steps, in order:
1. Phishing-resistant passkeys (WebAuthn) as an option, especially for privileged roles (finance, admin, purchasing). NIST rev 4 says AAL2 verifiers SHALL offer a phishing-resistant option. TOTP, which ADASI has, is not phishing-resistant.
2. Breached-password checking that does not call an external API: a self-hosted HIBP dataset, or a local top-N blocklist.
3. User-facing notifications for security events: new device (exists), password/MFA changes, repeated failures.
4. Hardened cookie and session settings plus timeouts.
5. Signed, expiring URLs for every emailed link.
6. Step-up re-authentication on sensitive actions (largely exists).

A URL-visible "login transaction ID" has real but modest benefits: per-attempt binding, expiry, multi-tab correctness and telemetry correlation. It must still be bound to the session cookie. ADASI already gets most of the benefit from session-bound pending-MFA state.

### Cited Findings — passkeys / WebAuthn
- NIST SP 800-63B-4:
  - At AAL2, verifiers "SHALL offer at least one phishing-resistant authentication option".
  - Phishing resistance "requires single- or multi-factor cryptographic authentication". Manually entered OTPs do not qualify.
  - WebAuthn/FIDO2 is the example of verifier-name binding.
  - Syncable authenticators (passkeys with exportable keys) are permitted at AAL2 but "SHALL NOT be used at AAL3".
  — [NIST SP 800-63B-4](https://pages.nist.gov/800-63-4/sp800-63b.html)
- ASVS 5.0 6.3.3 (L3) asks for a hardware-based mechanism with "impersonation resistance against phishing attacks". — [ASVS 5.0 V6](https://raw.githubusercontent.com/OWASP/ASVS/v5.0.0/5.0/en/0x15-V6-Authentication.md)
- WebAuthn origin binding: "the private key never leaves the device", and "Passkeys only work on the server domain … or subdomain". A lookalike phishing domain therefore cannot use them. — [Laragear/WebAuthn README](https://github.com/Laragear/WebAuthn)
- Laravel package status, 2026:
  - Laragear/WebAuthn (PHP 8.3+, Laravel 12+) is archived and "no longer maintained due to an official implementation", and points to `laravel/passkeys`. — [Laragear/WebAuthn on GitHub](https://github.com/Laragear/WebAuthn)
  - A third-party directory lists `laravel/passkeys` v0.1.0 (22 Apr 2026) to v0.2.1 (18 May 2026), supporting PHP 8.2–8.5 and Laravel 11–13. This is unconfirmed against official Laravel docs and is pre-1.0. — [laraplugins.io: laravel/passkeys](https://laraplugins.io/plugins/laravel/passkeys)
  - spatie/laravel-passkeys (released May 2025) provides Livewire/Blade components and builds on `web-auth/webauthn-lib`. Directory listings disagree on the latest version (1.6.3 vs 1.7.0 dated 2026-04-08). — [Spatie blog](https://spatie.be/blog/a-package-to-handle-passkeys-in-laravel); [Packagist](https://packagist.org/packages/spatie/laravel-passkeys); [laraplugins.io: spatie/laravel-passkeys](https://laraplugins.io/plugins/spatie/laravel-passkeys)
  - misakstvanu/laravel-fortify-passkeys is beta. — [laraplugins.io](https://laraplugins.io/plugins/misakstvanu/laravel-fortify-passkeys)

  All of these run in-process. WebAuthn needs no external service.

### Cited Findings — breached passwords without third parties
- Laravel `Password::uncompromised()` uses "the k-Anonymity model to determine if a password has been leaked via the haveibeenpwned.com service". The default threshold is 1 appearance and can be configured, e.g. `uncompromised(3)`. Because it calls an external API, it does not meet a strict no-third-party constraint. — [Laravel 12 Validation docs](https://laravel.com/docs/12.x/validation)
- (repo) `AuthSecurityServiceProvider` already enables `uncompromised(threshold 3)` in production for `Password::defaults()` (internal accounts and password change). This means a runtime dependency on api.pwnedpasswords.com. The supplier registration rule intentionally has no leaked-password check (AGENTS.md).
- Offline alternatives:
  - The official HIBP `PwnedPasswordsDownloader` saves all hash ranges locally "so you no longer need the k-anonymity API".
  - The ordered SHA-1 archive is reported as about 15 GB compressed and about 25–35 GB extracted.
  - A Bloom filter of about 512 MiB is an option.
  - Sources differ on dataset version and sizes.
  — [HIBP PwnedPasswordsDownloader](https://github.com/HaveIBeenPwned/PwnedPasswordsDownloader); [pwnedpasswords-offline (PyPI)](https://pypi.org/project/pwnedpasswords-offline); [passbolt community guide](https://community.passbolt.com/t/how-to-create-an-offline-self-hosted-haveibeenpwned-api-service/5541)
- ASVS 5.0 6.2.4 (L1) only requires a top-3,000 list. 6.2.12 (L2) requires breached-password checks. — [ASVS 5.0 V6](https://raw.githubusercontent.com/OWASP/ASVS/v5.0.0/5.0/en/0x15-V6-Authentication.md)

### Cited Findings — sessions, redirects, signed links, step-up, notifications
- Session regeneration after login and on privilege change; cookie flags; server-side idle and absolute timeouts. OWASP gives "2-5 minutes for high-value applications and 15-30 minutes for low risk applications" as common idle ranges. — [OWASP Session Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html)
- NIST rev 4 AAL2 timeouts: overall timeout "SHOULD be no more than 24 hours", inactivity "SHOULD be no more than 1 hour". — [NIST SP 800-63B-4](https://pages.nist.gov/800-63-4/sp800-63b.html)
- Laravel signed URLs append a signature so Laravel can "verify that the URL has not been modified since it was created". Options:
  - `temporarySignedRoute()` adds expiry;
  - the `signed` middleware returns 403;
  - `signed:relative` covers domain-agnostic signing;
  - `hasValidSignatureWhileIgnoring()` exists, with the caution that "ignoring parameters allows anyone to modify those parameters".
  — [Laravel 12 URL Generation docs](https://laravel.com/docs/12.x/urls)
- Step-up: OWASP requires current credentials "before updating sensitive account information" and after suspicious activity or account recovery, then invalidating sessions and rotating tokens. Laravel provides the `password.confirm` middleware and `Auth::logoutOtherDevices($currentPassword)`. — [OWASP Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html); [Laravel 12 Authentication docs](https://laravel.com/docs/12.x/authentication)
- Throttling: Laravel starter kits throttle per "username / email address and their IP address". OWASP prefers per-account counters. NIST caps consecutive failures at 100 per authenticator. — [Laravel docs](https://laravel.com/docs/12.x/authentication); [OWASP](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html); [NIST](https://pages.nist.gov/800-63-4/sp800-63b.html)
- Notifications: ASVS 6.3.5 / 6.3.7 (L3) require telling users about suspicious attempts and changes to authentication details. — [ASVS 5.0 V6](https://raw.githubusercontent.com/OWASP/ASVS/v5.0.0/5.0/en/0x15-V6-Authentication.md)
- (repo) `CompleteLoginService` already raises `new_device_login` events and in-app notifications through `KnownDeviceService`.

### Cited Findings — "login transaction ID" pattern
- OAuth's `state` is the conceptual model: an opaque value tying request to callback, which must be "one-time use" and "securely bound to the user agent". — [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html); [RFC 6749](https://www.rfc-editor.org/rfc/rfc6749)
- Tokens in URLs leak through Referer, logs, history and bookmarks. The session cheat sheet says a session ID should never be accepted from a URL. — [OWASP Session Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html); [RFC 9700 §4.2](https://www.rfc-editor.org/rfc/rfc9700.html)
- Server-side mapping of an ID to a target URL is OWASP's preferred redirect design, with a caution against enumeration. — [OWASP Unvalidated Redirects Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html)
- (repo) ADASI's current multi-step login is already transaction-like, but keyed by session rather than URL:
  - `TwoFactorService::beginLoginChallenge()` regenerates the session (or invalidates it and rotates the token when rotating the remember cookie).
  - It forgets `url.intended` and stores `{user_id, remember, intended (sanitized), started_at}` under a pending key.
  - `EnsurePendingTwoFactorChallenge` (`mfa.pending`) rejects the challenge when `started_at + lifetime` has passed.
  - `/two-factor-challenge` is `no-store` and throttled (`throttle:auth.mfa-code`).
  - The final redirect re-runs `safeIntendedUrl()`.

### Inferences — ranked improvement list (no external service)
Value is judged for ADASI's risk profile: external suppliers, finance and payment data, internal staff.

1. **Passkeys (WebAuthn) as an MFA/primary option, required or encouraged for finance/admin/purchasing.** This is the only measure here that defeats real-time phishing proxies that relay passwords and TOTP. Keep TOTP and recovery codes as fallback. Choose the package carefully: Laragear is archived; `laravel/passkeys` is official but pre-1.0 (verify); Spatie's is mature but built around Livewire, while ADASI uses Blade, jQuery and Alpine. Cost: a migration (credentials table), enrollment UI, recovery flow and a decision on whether syncable passkeys are acceptable (NIST allows them at AAL2).
2. **Remove the runtime HIBP dependency if "no third party" is a hard requirement.** Options:
   - a local top-N or breached blocklist (ASVS L1: top 3,000; L2: breached);
   - a periodically refreshed local Bloom filter or table built from the HIBP downloader dataset (cost: about 0.5 GB filter or tens of GB raw, plus a refresh job).

   Also reconsider whether supplier registration should get at least a local top-N check. AGENTS.md says it intentionally has none; that is a business decision.
3. **Security-event notifications by email, in addition to in-app.** Cover new-device login (exists in-app), password change, MFA enable/disable or recovery-code regeneration, a recovery code used, and repeated failed attempts or a lockout. Include a "this wasn't me" link that is a `temporarySignedRoute` to revoke sessions (session versioning already exists in the repo).
4. **Session hardening review.** Check the following (these are checks, not verified repo facts):
   - `SESSION_SECURE_COOKIE`, `http_only`, `same_site=lax` or `strict`;
   - consider a `__Host-` cookie name;
   - an idle timeout appropriate for finance roles (OWASP: minutes for high-value apps; NIST AAL2: ≤1 h idle, ≤24 h absolute);
   - an absolute timeout independent of activity;
   - regenerate on every privilege change, including supplier scope/context switches.
5. **Signed, expiring URLs for every emailed action link.** Use `temporarySignedRoute` with a short TTL, `signed` middleware, one-time-use by storing a nonce or used-at marker, and no secrets beyond the signature. Avoid `hasValidSignatureWhileIgnoring` on security links.
6. **Keep and test the return-URL allowlist.** `safeIntendedUrl()` already follows OWASP. Remaining work:
   - add tests for edge cases: backslash-prefixed (`/\evil.com`), encoded `%2F%2F`, userinfo `https://host@evil`, mixed-case scheme;
   - never accept `?redirect=`/`?returnUrl=` query parameters on `/login`. Keep the destination server-side in the session, which is what `url.intended` does.
7. **Step-up re-auth.** `password.confirm` already covers admin actions, payment term changes and 2FA management (repo). Make sure bank-account changes, payee changes and DRP finalization are covered, and once passkeys exist allow a passkey assertion as step-up.
8. **Throttling semantics.** Ensure per-account counters (OWASP) in addition to IP. Generic responses with uniform timing for unknown accounts, inactive or pending accounts, and locked accounts (ASVS 6.3.8) also matter here, because supplier `PENDING`/`REJECTED` states could leak through different messages.

### Inferences — "login transaction ID" (`/login?challenge=<random>`): benefits and costs
- **Real benefits, if implemented as an opaque, random, server-side record bound to the session cookie:**
  - Explicit binding of steps 2..n (TOTP, passkey, forced password change) to one specific attempt, with its own expiry and single use.
  - Correct behaviour with several tabs or concurrent attempts. The current single session key is overwritten by a second attempt.
  - A tamper-proof return URL: the target is stored server-side and referenced by ID, which is OWASP's "map ID to URL" pattern.
  - Telemetry: correlate audit-log rows (password ok, MFA failed, completed or abandoned) under one attempt ID and measure funnel drop-off.
  - Clean cancellation and replay prevention: the ID is consumed on completion.
- **What it does not give:** no secrecy (the ID is visible in the URL bar and Referer) and no phishing resistance, because a proxy relays it.
- **Costs and risks:**
  - It must be bound to the session or user agent. An unbound ID in a URL is a bearer token, and anyone holding the link could continue a half-authenticated attempt. This is the same requirement RFC 9700 places on `state`.
  - URL leakage needs `Referrer-Policy: no-referrer` or `same-origin` on auth pages, exclusion from logs, and `no-store`.
  - It adds storage and cleanup (table or cache plus a TTL).
  - It changes route contracts, which matters given the repo's hashid/route conventions and tests.
  - It makes the visible URL long, with the UX downsides in section 5.
- **Recommendation for ADASI:** keep the attempt ID out of the URL. Hold it as a server-side record or a session sub-key keyed by a random ID in a hidden form field (`<input type="hidden" name="login_attempt">`), checked against the session. This gives binding, expiry, multi-tab safety and telemetry while the URL stays `/login` and `/two-factor-challenge`. The existing `started_at` lifetime check is already a partial implementation, so the increment is an attempt ID plus single-use consumption plus audit correlation.

### Gaps
- I could not confirm `laravel/passkeys` in official Laravel docs or on GitHub; only a third-party directory listing was found. Verify on Packagist before recommending it.
- I did not verify ADASI's session cookie settings, idle/absolute timeout values, per-account vs per-IP throttle keys, or which sensitive routes lack `password.confirm`. These are listed as checks, not findings.
- I found no source on email security notifications as an explicit OWASP Authentication Cheat Sheet requirement. The cheat sheet covers internal logging; ASVS L3 covers user notification.

## 5. UX / user perception: do long login URLs confuse users, hurt phishing recognition, or break bookmarks and password managers?

### Takeaway
Users already read URLs poorly, and complexity makes it worse. A long, parameter-heavy login URL trains users to accept opaque URLs on a credential page, which is what phishing kits imitate. The only reliable identity signal is the registrable domain. Best practice is a short, memorable, stable entry URL (e.g. `https://portal.<company>.co.id/login`, or just the root redirecting to it) on the company's own domain. Long protocol URLs should exist only as transient internal redirects, never as the address users bookmark, type or are told to trust.

### Cited Findings
- Reynolds et al. (Google, CHI 2020):
  - "Only the fully qualified domain name in the URL gives users the unfalsifiable identity information".
  - Users "are confident in their ability to learn website identity from URLs", yet they identified only "an average of 58% of URLs" when obfuscation techniques were used.
  - Flawed heuristics include "scanning for familiar names, trusting all https links" and trusting the word "secure".
  — [Google Research: Measuring Identity Confusion with URLs](https://research.google/pubs/measuring-identity-confusion-with-uniform-resource-locators/)
- Albakry, Vaniea and Wolters, "What is this URL's Destination?" (CHI 2020, DOI 10.1145/3313831.3376168), as summarized by secondary sources:
  - only about 8% of participants consistently identified the correct domain;
  - a 2025 Edinburgh thesis extension reports 91.7% struggled with even simple URLs, often confusing subdomains containing brand names with domains.

  These numbers are secondhand. — [Edinburgh ERA thesis](https://era.ed.ac.uk/items/8de853d8-602c-4a79-9123-e687ef670d60/full); [Waterloo usable-security lecture slides citing the study](https://ece.uwaterloo.ca/~kvaniea/teaching/usec/S2025/lectures/07-survey/07-survey-design.pdf)
- OWASP requires allowing paste and password managers (ASVS 6.2.7 L1). It advises standard HTML username and password fields so password managers work. — [ASVS 5.0 V6](https://raw.githubusercontent.com/OWASP/ASVS/v5.0.0/5.0/en/0x15-V6-Authentication.md); [OWASP Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html)
- Passkeys and password managers bind credentials to the domain, not the path. "Passkeys only work on the server domain … or subdomain". — [Laragear/WebAuthn README](https://github.com/Laragear/WebAuthn)
- URL-embedded values persist in "browser history and bookmarks". A bookmarked long URL with a one-time or expiring value (OAuth `state`, signed-URL expiry, challenge ID) fails on reuse. — [OWASP Session Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html); [Laravel signed URL expiry](https://laravel.com/docs/12.x/urls)

### Inferences
- Bookmarks: users bookmark whatever is in the address bar. If the login page URL carries an expiring or one-time parameter, the bookmark later lands on an error, such as Laravel's 403 for an expired signature, unless the app degrades gracefully by issuing a fresh attempt and redirecting.
- Password managers match on the origin (scheme + host + port), so path length rarely breaks autofill itself. But a random or obscured path can break "open login page" shortcuts and stored login URLs, and multiple login hosts fragment saved credentials.
- Phishing recognition: a short, consistent, domain-anchored URL lets awareness training say "only enter your password on `portal.<company>…/login`". Long, variable URLs remove any stable pattern to teach. Passkeys remove the need for users to judge URLs at all, since the browser enforces the origin.
- Mirroring large-vendor portals (e.g. Infor-style): the user-facing entry stays short and stable, and the long OAuth/SAML URL is an automatic internal redirect between that entry and the IdP. For ADASI without an IdP there is no such hop, so the right design is short `/login` → server-side session state → `/two-factor-challenge` → intended page. Any attempt or transaction ID goes in a hidden field or the session, not the URL.
- Practical rules:
  1. Publish one canonical entry URL (root → `/login`) on the company's own domain, never a shortener.
  2. Keep auth pages free of user-visible query parameters.
  3. Set `Referrer-Policy` and `no-store` on auth pages (`no-store` already exists in the repo).
  4. Make expired-attempt URLs restart cleanly instead of erroring.
  5. Put the same domain in every system email so suppliers learn one pattern.

### Gaps
- I could not access the full text of either CHI 2020 paper. The Reynolds figures come from the official abstract page; the Albakry figures come from secondary summaries.
- I found no empirical study specifically on long login URLs and password-manager failure rates. The bookmark and password-manager points are reasoned from documented origin-matching behaviour.
- I did not research Infor's actual portal URL structure. The "short entry, long protocol redirect" description is the general pattern, not verified against Infor.
