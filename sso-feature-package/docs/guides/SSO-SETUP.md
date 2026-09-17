# SSO (SAML / OIDC) — Setup & Integration Guide

This package adds enterprise SSO federation on top of the existing auth
hardening stack (TOTP MFA, session versioning, known-device detection, audit
log, etc). It does not replace local password login — SSO is an alternative
entry point, and a supplier's SSO connection can optionally be set to
**enforce** (block local password login for that domain) once it's been
tested.

## What's in this package

**New files** (drop into the matching paths in your project):

```
database/migrations/2026_09_17_000001_create_supplier_identity_providers_table.php
app/Models/SupplierIdentityProvider.php
config/sso.php
app/Services/Sso/SsoDomainResolver.php
app/Services/Sso/SamlConnectionService.php
app/Services/Sso/OidcConnectionService.php
app/Services/Sso/SsoLoginService.php
app/Http/Controllers/Auth/SsoAuthController.php
app/Http/Controllers/Admin/SupplierIdentityProviderController.php
app/Http/Requests/Admin/StoreSupplierIdentityProviderRequest.php
app/Http/Requests/Admin/UpdateSupplierIdentityProviderRequest.php
resources/views/auth/sso-login.blade.php
resources/views/admin/sso/index.blade.php
resources/views/admin/sso/create.blade.php
resources/views/admin/sso/edit.blade.php
resources/views/admin/sso/_form.blade.php
```

**Modified files** (full replacement — diff against your current copy before
overwriting, in case other work has touched the same files since the branch
you shared):

```
routes/web.php                                    — admin sso-connections resource routes
routes/auth.php                                    — sso.show / sso.redirect / SAML+OIDC callback routes
app/Http/Requests/Auth/LoginRequest.php             — blocks password login on enforce_sso domains
app/Http/Controllers/Auth/AuthenticatedSessionController.php — passes SsoDomainResolver through
app/Models/AuthAuditLog.php                         — adds sso_login_success / sso_login_failed / sso_login_blocked
resources/views/auth/login.blade.php                — adds "Sign in with company SSO" link
composer.json                                       — adds the 3 packages below
.env.example                                        — adds SSO_* variables
```

## 1. Install packages

This sandbox has no access to Packagist, so the composer.json here already
lists the three packages but you need to actually resolve/install them
yourself:

```sh
composer require onelogin/php-saml:^4.2 league/oauth2-client:^2.8 firebase/php-jwt:^6.10
```

- **onelogin/php-saml** — SAML 2.0 Service Provider library (signature
  validation, AuthnRequest/Response handling).
- **league/oauth2-client** — generic OAuth2 client, used for OIDC's
  authorization-code flow.
- **firebase/php-jwt** — verifies the OIDC `id_token` signature against the
  IdP's published JWKS.

## 2. Environment

```dotenv
SSO_SAML_SP_ENTITY_ID=https://portal.example.com/metadata/saml
SSO_SAML_SP_CERT=
SSO_SAML_SP_PRIVATE_KEY=
SSO_SAML_STRICT=true
SSO_OIDC_DISCOVERY_CACHE_SECONDS=3600
```

Leave `SSO_SAML_SP_CERT` / `SSO_SAML_SP_PRIVATE_KEY` empty to start — most
IdPs only require the **IdP → SP** assertion to be signed, not the outgoing
AuthnRequest. Add a cert/key pair later only if a supplier's IT team
specifically requires signed requests.

## 3. Migrate

```sh
php artisan migrate --force
```

Adds one table, `supplier_identity_providers`. Nothing else in the schema
changes — no new columns on `users`, so this is fully additive and
rollback-safe (`php artisan migrate:rollback` only drops this one table).

## 4. Verify the pieces this depends on

This package assumes (all already true on the `local-supplier-update`
branch I reviewed):

- `App\Traits\HasHashids` exists and is used for route-model-bound IDs.
- `App\Events\AuthSecurityEvent` + the `LogAuthenticationEvent::handleSecurityEvent`
  listener are wired in `AuthSecurityServiceProvider` — SSO events reuse
  this instead of writing to `AuthAuditLog` directly.
- `App\Services\Auth\CompleteLoginService` exists and its `complete()`
  signature is `(Request $request, User $user, bool $remember): void`.
- `layouts.app` and `layouts.auth` Blade layouts, and the `x-ui.*`
  component set (`page-header`, `button`, `data-table`, `status-chip`,
  `icon`) used in `resources/views/admin/announcements/*`.

If any of those have changed since this branch, the corresponding file here
needs a small adjustment.

## 5. Things to double-check before you trust this in production

1. **`@push('scripts')` in `_form.blade.php`.** I couldn't confirm whether
   `layouts.app` declares `@stack('scripts')`. If it doesn't, move the
   "Test Connection" `<script>` block to a plain inline `<script>` at the
   bottom of the form instead of `@push`.

2. **SAML's native-session dependency.** `onelogin/php-saml` reads/writes
   `AuthnRequestID` (replay protection) directly on PHP's native
   `$_SESSION`, independent of Laravel's own session driver.
   `SamlConnectionService::ensureNativeSession()` calls `session_start()`
   before any SAML operation to bridge this — it does not conflict with
   Laravel's session cookie (different cookie name), but it does need the
   PHP session save path to be writable on your host. **Test a full SAML
   round trip on staging before enabling `enforce_sso` for any real
   supplier** — this is the single trickiest integration point.

3. **JIT-provisioned users get role `supplier`.** `SsoLoginService` always
   creates new users with `role = 'supplier'` and links them to the
   `Supplier` record tied to the connection. If you need a different
   default role per connection (e.g. some large suppliers might have
   `accounting`-role users too), that needs a small extension — currently
   out of scope for this pass.

4. **No SAML metadata auto-import.** The admin form takes IdP Entity ID /
   SSO URL / certificate as manual fields rather than fetching the
   supplier's metadata XML/URL automatically. This is the most
   universally-compatible approach (works with any IdP without needing
   CORS-friendly metadata fetching), but means slightly more manual entry
   when onboarding a new supplier.

5. **OIDC redirect URI is only known after saving.** Because the redirect
   URI embeds the connection's hashid (`/auth/sso/oidc/{id}/callback`), you
   create the connection first (inactive), copy the URL shown on the edit
   screen, register it with the supplier's IdP, then paste back the client
   ID/secret they give you and activate.

## 6. Onboarding a new supplier's SSO (operational runbook)

1. Admin → SSO Connections → Add Connection. Pick the supplier, set the
   domain (e.g. `acme-corp.com`), pick SAML or OIDC, leave **Active**
   unchecked.
2. **For SAML:** download the SP metadata from the connection's row
   ("SP Metadata" button) and send it to the supplier's IT team. They
   register it in their IdP (Entra ID / Okta / ADFS) and send back their
   IdP Entity ID, SSO URL, and signing certificate. Fill those into the
   connection and save.
3. **For OIDC:** register a new "Web" application in the supplier's IdP
   using the callback URL shown on the edit screen. They give you a Client
   ID and Secret; fill in Issuer URL + those two fields.
4. Click **Test Connection** (OIDC: confirms discovery document loads;
   SAML: confirms the certificate parses).
5. Check **Active**, save, then do one real login end-to-end with a test
   account before telling the supplier it's ready.
6. Only after a successful real login, come back and check **Enforce SSO**
   if the supplier wants password login disabled for their domain.

## 7. Rollback

Same posture as the existing auth hardening stack: application code can be
rolled back while leaving the `supplier_identity_providers` migration in
place — older code simply ignores the table. Don't roll back the migration
itself once any supplier has real SSO users provisioned through it, since
those users' `Supplier` links depend on rows in this table.
