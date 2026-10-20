# Self-hosted options for an Infor-style login flow (OAuth 2.0 Authorization Code + PKCE / OIDC) in a Laravel 12 monolith

Scope note: researched 19 October 2026. "Infor-style" here means the browser is redirected to a separate authorization server, a long `/authorize?client_id=...&redirect_uri=...&response_type=code&scope=openid...&state=...&code_challenge=...&code_challenge_method=S256` URL appears in the address bar, the user logs in there, and the browser comes back to the portal's callback with `?code=...&state=...`. Only self-hosted or free-of-SaaS options are covered.

Repo facts checked locally in `/home/user/supplierportal` (inspected, not web-sourced): `composer.json` requires `laravel/framework ^12.0`, `pragmarx/google2fa ^9.0`, and `laravel/breeze ^2.4` (dev); no `laravel/passport`, `laravel/socialite`, `laravel/sanctum`, or `laravel/fortify`. `routes/web.php` defines `GET/POST login` (`AuthenticatedSessionController`, `no-store`), `two-factor-challenge` behind `mfa.pending`, `confirm-password`, `POST logout`, `profile/logout-other-devices`, per-session revoke, profile 2FA setup, `forgot-password` → `PasswordAssistanceController`, and a separate public `supplier/registration/*` flow with its own `registration.session` and `logout`. `.env.example`: `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`, `SESSION_DOMAIN=null` (host-only cookie).

## 1. Laravel Passport as a self-hosted authorization server: PKCE, OIDC, and architecture

### Takeaway
Passport for Laravel 12 supports Authorization Code with PKCE (public clients via `passport:client --public`, `code_challenge_method=S256`) out of the box, but its docs say nothing about OpenID Connect. ID tokens, discovery, JWKS, and userinfo need a community add-on. The most mature one for Laravel 12 is `jeremy379/laravel-openid-connect` (MIT). Passport is built for issuing API tokens. Using it as an SSO server for a Blade monolith means building a second "auth" app or route group plus a client callback in the portal.

### Cited Findings
- The Passport 12.x docs have a dedicated "Authorization Code Grant With PKCE" section. They describe PKCE as replacing the client secret with a code verifier/challenge, used "when you can't guarantee that the client secret will be stored confidentially or in order to mitigate the threat of having the authorization code intercepted". A PKCE client is created with `php artisan passport:client --public`. — [Laravel 12.x Passport docs](https://laravel.com/docs/12.x/passport)
- The documented PKCE redirect builds `https://passport-app.test/oauth/authorize?` + `client_id`, `redirect_uri`, `response_type=code`, `scope`, `state` (40 random chars stored in session), `code_challenge` (base64url SHA-256 of a 128-char verifier), `code_challenge_method=S256`, and optional `prompt` (`none` / `consent` / `login`). The callback checks `state` and POSTs `grant_type=authorization_code`, `client_id`, `redirect_uri`, `code_verifier`, `code` to `/oauth/token`. — [Laravel 12.x Passport docs](https://laravel.com/docs/12.x/passport)
- Passport registers `/oauth/authorize` and `/oauth/token` itself. The consent screen is supplied by the app through `Passport::authorizationView('auth.oauth.authorize')`, and that view must POST to `passport.authorizations.approve` or DELETE to `passport.authorizations.deny` with `state`, `client_id`, `auth_token`. To skip consent for first-party clients, extend the `Client` model with `skipsAuthorization()` returning `$this->firstParty()`. — [Laravel 12.x Passport docs](https://laravel.com/docs/12.x/passport)
- Installation is `php artisan install:api --passport`. The User model must use `HasApiTokens` and implement `OAuthenticatable`. An `api` guard with driver `passport` is added, and `php artisan passport:keys` generates signing keys at deploy time. — [Laravel 12.x Passport docs](https://laravel.com/docs/12.x/passport)
- By default Passport access tokens are long-lived and "expire after one year". Lifetimes are set with `tokensExpireIn`, `refreshTokensExpireIn`, and `personalAccessTokensExpireIn`. — [Laravel 12.x Passport docs](https://laravel.com/docs/12.x/passport)
- Passport docs: "We no longer recommend using password grant tokens" and "We no longer recommend using implicit grant tokens". The Device Authorization Grant is also supported. — [Laravel 12.x Passport docs](https://laravel.com/docs/12.x/passport)
- Laravel steers SPA/mobile/API-token use cases to Sanctum: "If your application absolutely needs to support OAuth2, then you should use Laravel Passport." — [Laravel 12.x Passport docs](https://laravel.com/docs/12.x/passport)
- The Passport 12.x docs page contains no occurrences of "OpenID" or "OIDC". A text search of the fetched page returned 0 matches. — [Laravel 12.x Passport docs](https://laravel.com/docs/12.x/passport)
- The Laravel 12.x docs page now shows a banner saying it is "an old version of Laravel" and points to 13.x. — [Laravel 12.x Passport docs](https://laravel.com/docs/12.x/passport)
- `jeremy379/laravel-openid-connect`: MIT, forked from `ronvanderheijden/openid-connect`. Requires PHP ^8.2, Laravel 12 (tag 2.7.0 for older Laravel), Passport, `league/oauth2-server ^8.2`, `lcobucci/jwt ^4.0`. Requesting scope `openid` at `oauth/authorize` returns an `id_token` from `oauth/token`. `profile`/`email` scopes add claims. It supports `nonce` (passed to `passport.authorizations.approve`), serves `/.well-known/openid-configuration`, has an optional JWKS URL with a custom `kid` for rotation, and an optional `GET /oauth/userinfo` (disabled by default, Bearer-protected, always returns `sub`). The README does not mention end_session or logout. Setup means disabling Passport auto-discovery in `composer.json` `extra.laravel.dont-discover`, registering the package's `PassportServiceProvider`, and creating an `IdentityEntity` that implements `IdentityEntityInterface::getClaims()`. — [jeremy379/laravel-openid-connect (GitHub)](https://github.com/jeremy379/laravel-openid-connect)
- A registry search describes several alternatives. `ronvanderheijden/openid-connect` is the original, last released as 1.2.1 in July 2024. `admin9/laravel-oidc-server` targets Laravel 11–13 and Passport 12–13 and adds discovery, JWKS, userinfo, introspection, revocation, and RP-initiated logout. `mindtwo/laravel-identity` targets Laravel 13, Passport 13, and PHP 8.4+. `bambamboole/laravel-oidc` is a newer, low-download RS256 id_token and discovery layer. — [Packagist: jeremy379](https://root.packagist.org/packages/jeremy379/laravel-openid-connect); [Packagist: ronvanderheijden](https://root.packagist.org/packages/ronvanderheijden/openid-connect); [Packagist: admin9/laravel-oidc-server](https://root.packagist.org/packages/admin9/laravel-oidc-server); [Packagist: mindtwo/laravel-identity](https://root.packagist.org/packages/mindtwo/laravel-identity); [laraplugins: bambamboole/laravel-oidc](https://laraplugins.io/plugins/bambamboole/laravel-oidc). The version and date details for the last four come from search-result summaries and were not verified on each repo.

### Inferences
- Passport plus an OIDC add-on is the only option that keeps everything in PHP/MySQL on the existing Laragon/Laravel stack, with no new runtime such as a JVM or Go binaries. The trade-off: the team owns the security of a hand-assembled IdP. That includes the consent view, logout semantics, key rotation, token lifetimes (the default one year is far too long for an SSO session), and the gaps in the add-on, such as no documented end_session in jeremy379.
- Architecture A ("split"): a new `auth.<domain>` Laravel app becomes the authorization server. It holds `users`, passwords, google2fa secrets, Turnstile, identity rate limiting, known devices, auth audit logs, and Passport + OIDC. The portal becomes a confidential client with `GET /sso/redirect` → `auth.<domain>/oauth/authorize?...&code_challenge=...` and `GET /sso/callback`. The callback validates `state`, exchanges the code with `code_verifier`, validates the `id_token` (signature, `iss`, `aud`, `exp`, `nonce`), maps `sub` to `users.id`, then calls `Auth::login()` and continues to use the portal's own session.
- Architecture B ("same app, self-client"): install Passport in the portal itself, mount `/oauth/*` on an `auth.` host via route domain groups, and register the portal as a first-party client with `skipsAuthorization()`. This is cheaper and still makes the long URL appear naturally. Security-wise it adds little over the current session login, because the IdP and the RP share the same codebase, database, and session store. It is mainly cosmetic unless other apps (Infor-like ERP, future apps) will also be clients.
- With a single app, Passport adds the `oauth_*` tables, signing keys, and Passport's token guard. The existing `web` session guard stays the primary guard, so policies, `RoleMiddleware`, `EnforceSupplierDomain`, and session revocation continue to work on the portal session after the callback.

### Gaps
- I did not verify the exact current Passport major version for Laravel 12 (12.x vs 13.x) from the GitHub releases page. The Laravel 12 docs page did not state it.
- I did not verify whether `admin9/laravel-oidc-server` is actively maintained, security-reviewed, or OpenID-certified. No Passport add-on was found listed as OpenID Foundation certified.

## 2. Self-hosted open-source IdPs: Keycloak, Authentik, Zitadel, Ory Hydra/Kratos, Authelia

### Takeaway
All five are self-hostable OIDC providers with PKCE. They differ sharply in license, completeness, and footprint:
- Keycloak: full-featured Java server. About 1–2 GB RAM; supports MySQL/MariaDB as well as Postgres.
- authentik: Python + Postgres. Minimum 2 cores / 2 GB; no Redis since 2025.10.
- Zitadel: Go + Postgres, AGPL-3.0 since v3; the v4 stack has 4 containers.
- Ory Hydra: Apache-2.0, certified, headless. You must build the login/consent UI and user management (Kratos or your own app).
- Authelia: OIDC in "open beta", certified for several profiles, but no RP-initiated, front-channel, or back-channel logout and no user registration.

For a small company with one Laravel monolith, Keycloak (it can reuse MySQL) or authentik are the realistic "turnkey" choices. Ory Hydra fits best if you want to keep the existing Laravel login/2FA screens as the login UI.

### Cited Findings
**Keycloak**
- OIDC endpoints: discovery `/realms/{realm-name}/.well-known/openid-configuration`, authorization `/realms/{realm-name}/protocol/openid-connect/auth`, token `.../token`, userinfo `.../userinfo`, logout `.../logout`, JWKS `.../certs`. Calling logout directly with a refresh token is described as a non-standard legacy format and not recommended. RP-initiated logout is documented in the Server Administration Guide. — [Keycloak: OIDC layers](https://www.keycloak.org/securing-apps/oidc-layers)
- PKCE: the client-policy `pkce-enforcer` executor can require S256, for example in the FAPI and OAuth 2.1 profiles. — [Keycloak: OIDC layers](https://www.keycloak.org/securing-apps/oidc-layers)
- Container sizing: always set a memory limit. Minimum about 750 MB; "2 GB for smaller production deployments". JVM heap defaults are `MaxRAMPercentage=70`. Supported DB vendors are mariadb, mssql, mysql, oracle, postgres, and tidb (plus dev-file/dev-mem), set with `KC_DB`. The docs version selector shows 26.8.1. — [Keycloak: Running in a container](https://www.keycloak.org/server/containers)
- A comparison dated 17 Oct 2026, based on vendor-published figures: Keycloak base memory is 1250 MB including caches for 10,000 sessions; a 1 GB container gives about 717 MB heap plus about 300 MB non-heap; 2 containers. Current versions listed: Keycloak 26.8.1, authentik 2026.8.4, Zitadel v4.19.5. — [SSD Nodes: Keycloak vs authentik vs Zitadel](https://www.ssdnodes.com/learn/keycloak-vs-authentik-vs-zitadel)
- Laravel client: `composer require socialiteproviders/keycloak`. Config keys are `client_id`, `client_secret`, `redirect`, `base_url`, `realms`. On Laravel 11+, register `extendSocialite('keycloak', ...)` in `AppServiceProvider::boot`. `Socialite::driver('keycloak')->getLogoutUrl()` builds an RP-initiated logout URL with an optional post-logout redirect URI and `id_token_hint`; Keycloak v18+ needs a configured valid redirect URI. — [SocialiteProviders: Keycloak](https://socialiteproviders.com/Keycloak/)
- Password migration: several sources say Keycloak does not natively verify bcrypt hashes, so a third-party provider (keycloak-bcrypt) or a User Storage SPI / federation provider for lazy migration on first login is used. One vendor guide claims native bcrypt import support, which contradicts the others. — [Keycloak forum: MySQL user migration with bcrypt](https://forum.keycloak.org/t/mysql-user-migration-with-bcrypt-hashing/15345); [DEV: import bcrypt users into Keycloak](https://dev.to/carnewal/import-existing-users-with-bcrypt-hashed-passwords-in-keycloak-17oo); contradicted by [Skycloak guide](https://skycloak.io/blog/migrate-auth0-to-keycloak-step-by-step/)

**authentik**
- Docker Compose requirements: "at least 2 CPU cores and 2 GB of RAM". PostgreSQL is required. Docs version is 2026.8. — [authentik Docker Compose install](https://docs.goauthentik.io/install-config/install/docker-compose/)
- Release 2025.10 removed Redis entirely, moving caching, the embedded outpost, and WebSockets to Postgres. It uses roughly 50% more Postgres connections. — [authentik 2025.10 release notes](https://docs.goauthentik.io/releases/2025.10/); [authentik blog: We removed Redis](https://goauthentik.io/blog/2025-11-13-we-removed-redis/)
- Paid support tiers are Enterprise (ticket support on plans over $1k) and Enterprise Plus (from $20k/yr). The base stack is PostgreSQL + server + worker. — [SSD Nodes comparison](https://www.ssdnodes.com/learn/keycloak-vs-authentik-vs-zitadel)
- A comparison author's opinion: authentik is the safe default for a small VPS because it can also front apps without a login (proxy outpost). Recommend Keycloak when all apps already speak standard protocols and you can spare JVM memory. — [SSD Nodes comparison](https://www.ssdnodes.com/learn/keycloak-vs-authentik-vs-zitadel)
- A separate October 2026 benchmark summary reportedly found authentik spending about 1.2 s CPU per password login versus 72 ms for Keycloak. I saw only a search snippet, and the full table was not retrieved. — [computingforgeeks (search result)](https://computingforgeeks.com/?p=173202)

**Zitadel**
- Zitadel v3 moved from Apache 2.0 to AGPL-3.0. Zitadel says the change does not affect organizations that only use it as an identity platform; the obligation hits those who modify it and offer it as a service. A commercial license is available with Enterprise self-hosting. — [Zitadel v3 announcement](https://zitadel.com/blog/zitadel-v3-announcement); [Zitadel self-hosted enterprise](https://zitadel.com/self-hosted)
- Vendor figures: 2 GB on the compose page. The process needs about 512 MB RAM and under 1 core, but password hashing needs 4 cores. The DB needs about 1 core per 100 req/s and 4 GB RAM per core. The v4 compose stack has 4 containers (Traefik, API, Login UI on Next.js, PostgreSQL) and requires a 32-char `ZITADEL_MASTERKEY`. — [SSD Nodes comparison](https://www.ssdnodes.com/learn/keycloak-vs-authentik-vs-zitadel)

**Ory Hydra / Kratos**
- Hydra is Apache-2.0: "a hardened, OpenID Certified OAuth 2.0 Server and OpenID Connect Provider". It is "a standalone OAuth 2.0 and OpenID Connect server without user management". It "connects to your existing identity provider through a login and consent app". Databases are PostgreSQL, MySQL, and CockroachDB. It is certified for Basic, Implicit, Hybrid, Config (Discovery), and Dynamic profiles. Kratos is the separate "API-first Identity and User Management system". — [ory/hydra (GitHub)](https://github.com/ory/hydra)
- Ory's docs describe the OAuth2 service as a "headless API that doesn't force you to use a specific user management system". — [Ory OAuth2 & OIDC docs](https://www.ory.com/docs/oauth2-oidc)

**Authelia**
- The OIDC provider is offered "as part of an open beta". It is OpenID Certified for Basic, Implicit, Hybrid, Form Post, and Config OP profiles. PKCE is supported (S256 recommended), along with PAR, introspection, revocation, RFC 9207 issuer identification, and JARM. **Not supported:** dynamic client registration, session management, RP-initiated logout, front-channel and back-channel logout, and user registration (`prompt=create`). — [Authelia OIDC introduction](https://www.authelia.com/integration/openid-connect/introduction/)

**Generic PHP clients**
- `jumbojett/OpenID-Connect-PHP` is Apache-2.0 and requires PHP ≥ 7.2 with curl and json. It supports PKCE (`setCodeChallengeMethod('S256')`), verified claims, and back-channel logout helpers (`verifyLogoutToken()`, `getSidFromBackChannel()`, `getSubjectFromBackChannel()`). — [jumbojett/OpenID-Connect-PHP (GitHub)](https://github.com/jumbojett/OpenID-Connect-PHP)
- Laravel Socialite core supports only Facebook, X, LinkedIn, Google, GitHub, GitLab, Bitbucket, and Slack. Other providers come from the community Socialite Providers site. The 12.x Socialite docs page does not mention PKCE or id_token validation. — [Laravel 12.x Socialite docs](https://laravel.com/docs/12.x/socialite)

### Inferences
- License summary: Keycloak and authentik are generally described as Apache-2.0 and MIT (open core with enterprise features) respectively. Neither was confirmed from a primary page in this session (see Gaps). Hydra is Apache-2.0 (confirmed). Zitadel is AGPL-3.0 (confirmed). AGPL is operationally neutral for an internal, unmodified deployment.
- Keycloak is the closest match to an "Infor-like" enterprise SSO: realms, built-in login, OTP/WebAuthn, user federation, admin console, RP-initiated logout, and back-channel logout. It is also the only one of the turnkey three whose docs list MySQL/MariaDB as a production DB, which fits the existing MySQL ops. The cost is a JVM process (about 1–2 GB) and a new operational surface: upgrades, realm export/backup, TLS, and reverse proxy.
- Ory Hydra is the best fit if ADASI wants to keep its existing Laravel login page, google2fa, Turnstile, rate limiting, known-device tracking, and audit logs. Laravel would implement Hydra's login and consent endpoints, and Hydra would handle the OAuth/OIDC protocol and tokens. Hydra supports MySQL. The cost is integration work against Hydra's admin API, plus a Go binary to operate.
- Authelia is a poor fit as the primary IdP for this portal. It has no RP-initiated or back-channel logout, its OIDC is in beta, and it lacks registration. It is aimed at protecting self-hosted apps behind a reverse proxy.
- The Laravel client side is small for any of them: about 2 routes and 1 controller, using `socialiteproviders/keycloak` (Keycloak) or a generic OIDC client (`jumbojett/openid-connect-php`, or `league/oauth2-client` with manual id_token validation) for the others.
- Endpoint paths for authentik (`/application/o/authorize/`), Zitadel (`/oauth/v2/authorize`), Hydra (`/oauth2/auth`), and Authelia (`/api/oidc/authorization`) come from training knowledge and were not verified in this session. In every case the client should read them from `/.well-known/openid-configuration`.

### Gaps
- No primary-source page fetched in this session confirms Keycloak's Apache-2.0 license, authentik's MIT/enterprise split, or which MFA methods (TOTP, WebAuthn/passkeys) each product supports. Verify on each project's LICENSE file and MFA docs.
- No independent, measured idle-RAM comparison was retrieved. The SSD Nodes figures are vendor-published guidance, and the computingforgeeks CPU-per-login numbers came only from a search snippet.
- Kratos licensing, database support, and its 2FA/WebAuthn features were not fetched.

## 3. Building OAuth 2.0 / OIDC from scratch: why it is discouraged

### Takeaway
RFC 9700 (BCP 240, January 2025) and the OWASP OAuth2 Cheat Sheet list many mandatory countermeasures, and each one is a way for a hand-rolled server to fail. Among them: per-transaction PKCE bound to client and user agent, PKCE downgrade rejection, no open redirectors, issuer/mix-up defenses, audience restriction, refresh-token rotation or sender-constraining, and no implicit or ROPC grants. Use a certified server (Hydra, Keycloak, Authelia's certified profiles) or at least a maintained library such as `league/oauth2-server` under Passport, rather than custom code.

### Cited Findings
- RFC 9700 is the IETF OAuth 2.0 Security BCP (BCP 240, Jan 2025). It updates RFC 6749, 6750, and 6819 and deprecates less secure modes. It recommends PKCE even for confidential clients against authorization code injection (§4.5.3.1) and CSRF (§4.7.1). It allows an OIDC `nonce` as an alternative only with extra precautions (§4.5.3.2). The PKCE challenge or nonce must be unique per transaction and bound to the client and user agent. The advice applies to all clients, including web apps. — [RFC 9700](https://datatracker.ietf.org/doc/html/rfc9700)
- OWASP: authorization servers must enforce `code_verifier` when a challenge was sent and must reject verifiers sent without a challenge (downgrade). They must not be open redirectors and must not allow `http` redirect URIs except native loopback. Do not use implicit or ROPC. Use DPoP (RFC 9449) or mTLS (RFC 8705) for sender-constraining. Use refresh-token rotation. Defend against mix-up with `iss`. Audience-restrict tokens. Prefer `private_key_jwt` or mTLS client auth. Do not let clients influence `sub` or `client_id` claims. — [OWASP OAuth 2.0 Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/OAuth2_Cheat_Sheet.html)
- Ory Hydra's OpenID certification was performed with the unmodified reference login/consent app, which shows that certified conformance is a meaningful differentiator. — [ory/hydra (GitHub)](https://github.com/ory/hydra)

### Inferences
- A from-scratch implementation would also need JWT signing and key rotation (JWKS), id_token claim validation, discovery, consent storage, code single-use and expiry, and logout token handling. Passport already provides the OAuth core via `league/oauth2-server` (a listed jeremy379 dependency), so even the "build in Laravel" route should be Passport + add-on, not custom code.

### Gaps
- The OWASP page fetched makes no explicit "do not roll your own" statement. That recommendation is an inference from the number of required controls. I did not retrieve a primary source that says it verbatim.

## 4. Migration effort and risks for the existing session-based ADASI portal

### Takeaway
The portal's security features all live in the current login flow and would have to move to, or be re-implemented in, the IdP: google2fa, Turnstile, identity rate limiting, known devices, auth audit logs, session revocation, password confirmation, forced password change, and PENDING/ACTIVE account gating. The portal keeps its own Laravel session after `/sso/callback`. The highest-risk areas are password hash migration (bcrypt in Keycloak), the TOTP secret migration, single logout, supplier registration and account activation, and the `password.confirm` flows.

### Cited Findings
- Keycloak bcrypt import requires a plugin or a User Storage SPI for lazy migration; sources conflict on native support. — [Keycloak forum](https://forum.keycloak.org/t/mysql-user-migration-with-bcrypt-hashing/15345); [DEV](https://dev.to/carnewal/import-existing-users-with-bcrypt-hashed-passwords-in-keycloak-17oo); contradicted by [Skycloak](https://skycloak.io/blog/migrate-auth0-to-keycloak-step-by-step/)
- Hydra keeps user management and login UI out of scope and delegates to a login/consent app. Existing users, passwords, and 2FA can therefore stay in Laravel. — [ory/hydra (GitHub)](https://github.com/ory/hydra)
- Single logout support varies. Authelia has no RP-initiated, front-channel, or back-channel logout. Keycloak exposes a logout endpoint with RP-initiated logout. Socialite's Keycloak provider builds the logout URL and warns that you must `Auth::logout()` locally first. jumbojett's library can verify back-channel logout tokens. — [Authelia OIDC](https://www.authelia.com/integration/openid-connect/introduction/); [Keycloak OIDC layers](https://www.keycloak.org/securing-apps/oidc-layers); [SocialiteProviders Keycloak](https://socialiteproviders.com/Keycloak/); [jumbojett](https://github.com/jumbojett/OpenID-Connect-PHP)
- Passport's `prompt=login` forces re-authentication even when a session exists. This is a candidate mechanism to replace or augment `password.confirm` via a fresh IdP login, `prompt=none` for silent checks. — [Laravel 12.x Passport docs](https://laravel.com/docs/12.x/passport)
- RFC 9700 requires `state`/PKCE/nonce bound to the user agent. For the portal, `/sso/callback` must check the `state` and `code_verifier` stored in the portal session. The portal's `SESSION_SAME_SITE=lax` lets the top-level GET redirect back from the IdP carry the session cookie. — [RFC 9700](https://datatracker.ietf.org/doc/html/rfc9700); repo `.env.example` (inspected)

### Inferences
- **Users and passwords:** with Passport or Hydra and the Laravel login UI, no hash migration is needed because the same `users` table and bcrypt stay. With Keycloak or authentik, plan bulk import with hashes (plugin) or lazy migration on first login, plus a dual-run period.
- **2FA:** Google2FA secrets are standard TOTP. Moving them to an external IdP requires exporting the encrypted secrets and importing them via the IdP's admin API, or forcing re-enrollment. Recovery codes probably need to be regenerated. Hydra or Passport keeps the existing `TwoFactorChallengeController` as is.
- **Authorization stays in the portal:** `users.role`, `supplier_scopes`, `account_status = ACTIVE`, `is_active`, `RoleMiddleware`, `EnforceSupplierDomain`, and policies must still be enforced after the callback. Map `sub` to `users.id` (or a stored external ID) and re-check `is_active`/`ACTIVE` on every SSO login, so an IdP-active but portal-PENDING supplier cannot get in. This aligns with the AGENTS.md rule that login requires `is_active` and `ACTIVE`.
- **Supplier registration:** the public `supplier/registration/*` flow with reference/access key and `registration.session` is not a login and can stay in the portal. On approval, an external IdP needs a provisioning step that creates the IdP user and enables it, plus a decision on who owns credentials. With Keycloak, registration could also move to the IdP's self-registration, but that would duplicate the existing review workflow and is not recommended.
- **Password reset:** the portal uses an assisted `forgot-password` (`PasswordAssistanceController`). With an external IdP, the IdP's reset flow and the email/SMTP config must move there. Admin-set temporary passwords and forced password change must be replicated (Keycloak has "required actions"; this was not verified here).
- **Sessions:** two sessions exist after the change (IdP SSO session + portal Laravel session). Existing `profile/logout-other-devices` and per-session revoke only kill portal sessions. Without back-channel logout, a revoked portal session can silently re-login via the still-valid IdP session. Pair revocation with an IdP session kill (admin API) or back-channel logout.
- **Cookies across subdomains:** keep `SESSION_DOMAIN=null` (host-only) so the portal and an `auth.` host do not share cookies. OIDC does not need shared cookies; the redirect and code carry state. Setting `.domain` would widen cookie exposure.
- **CSRF:** `/sso/callback` is a GET protected by `state`/PKCE/nonce, not the Laravel CSRF token. Passport's approve/deny form POSTs need normal CSRF. Logout should remain POST in the portal, followed by a redirect to the IdP end-session URL.
- **Tests and ops:** the existing Feature tests under `tests/Feature/Auth/` would need SSO fakes. Socialite offers `Socialite::fake()`. External IdPs add a new service to back up, patch, and monitor; Laragon dev would need Docker or a JVM.
- **Effort (rough, inference):**
  - Passport same-app: smallest.
  - Passport split app, or Hydra with Laravel login/consent: medium.
  - Keycloak/authentik/Zitadel full migration (users, TOTP, reset, registration provisioning, logout): largest, mostly in data migration and ops rather than code.

### Gaps
- I did not verify how Keycloak, authentik, or Zitadel import existing TOTP secrets, or whether they support Laravel's bcrypt `$2y$` prefix natively.
- I did not verify Keycloak back-channel logout configuration specifics or Passport's lack of a logout/end_session endpoint from primary docs. The Passport docs page shows no logout endpoint, but I did not search exhaustively.

## 5. What the login URL looks like with each option

### Takeaway
With every option the long Infor-like URL appears naturally, because Authorization Code + PKCE puts all parameters in the browser's query string on the authorization endpoint.

### Cited Findings
- Passport: `https://<auth-host>/oauth/authorize?client_id=...&redirect_uri=...&response_type=code&scope=...&state=<40 chars>&code_challenge=<base64url SHA-256>&code_challenge_method=S256[&prompt=...]`. Adding the OIDC add-on means `scope=openid profile email` plus `nonce`. — [Laravel 12.x Passport docs](https://laravel.com/docs/12.x/passport); [jeremy379/laravel-openid-connect](https://github.com/jeremy379/laravel-openid-connect)
- Keycloak: `https://<host>/realms/{realm-name}/protocol/openid-connect/auth?client_id=...&redirect_uri=...&response_type=code&scope=openid...&state=...&code_challenge=...&code_challenge_method=S256`. Logout is at `/realms/{realm-name}/protocol/openid-connect/logout`. — [Keycloak OIDC layers](https://www.keycloak.org/securing-apps/oidc-layers)

### Inferences
- Example of a full portal-side flow:
  1. `https://portal.example/login` → 302 to `https://auth.example/realms/adasi/protocol/openid-connect/auth?client_id=supplier-portal&redirect_uri=https%3A%2F%2Fportal.example%2Fsso%2Fcallback&response_type=code&scope=openid%20profile%20email&state=...&nonce=...&code_challenge=...&code_challenge_method=S256`
  2. After login → `https://portal.example/sso/callback?code=...&state=...&session_state=...&iss=...` (Keycloak-specific extra params; unverified).
- Other products follow the same shape on different paths (unverified training knowledge): authentik `/application/o/authorize/`, Zitadel `/oauth/v2/authorize`, Hydra `/oauth2/auth`, Authelia `/api/oidc/authorization`.

### Gaps
- I did not capture live screenshots or real redirect traces for each product. Authorization endpoint paths for authentik, Zitadel, Hydra, and Authelia were not confirmed from primary docs in this session.
