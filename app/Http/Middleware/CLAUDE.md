# app/Http/Middleware

Registration lives in `bootstrap/app.php`, not in a Kernel.

- Web group append order: `ApplyUserLocale`, `ShareAuthSplashState`, `DecodeHashids`, `EnforceAuthSessionSecurity`, `EnforcePasswordChange`, `AddSecurityHeaders`, `EnforceSupplierDomain`, `LimitOperationalRequests`. These run on every web request, so any change here affects every role.
- Aliases: `role`, `supplier.scope`, `password.confirm`, `purchasing.navigation`, `mfa.pending`, `no-store`, `registration.session`.

## DecodeHashids
- Decodes only route parameter names listed in `HASHED_PARAM_KEYS`. A new route with a new hashed parameter name must be added there, or the controller receives the raw hash string.
- A raw digit string returns `abort(404)` on purpose; `tests/Feature/HashidUrlSecurityTest.php` guards this.
- Parameters already bound by implicit binding (model objects) are skipped.
- Plain-integer models are opted out by route-name prefix in `PLAIN_ROUTE_PREFIXES`. Don't put `attachments.` there: Attachment uses hashid implicit binding.

## EnforceSupplierDomain
- Keeps the Import and Local cores apart on shared endpoints. It is not an authorization layer; policies still apply.
- The `attachments.show` exceptions for `SupplierOverpaymentRefund`, `LocalPurchaseOrder`, and `SupplierAudit` are intentional. Keep all three.

## Role and scope
- `RoleMiddleware`: exact match against the listed roles. Admin gets no bypass, and denials are logged.
- `SupplierScopeMiddleware` (`supplier.scope:import|local`): 403 without the scope; it then writes `session('supplier_context')`, which `App\Support\PortalContext` reads.

## LimitOperationalRequests
- Maps routes to per-user limiter buckets by route-name pattern (`exports`, `pdf`, `search`, ...). Renaming routes can silently move them between buckets.

Tests: `HashidUrlSecurityTest`, `SupplierDataIsolationTest`, `AttachmentAuthorizationBoundaryTest`, `tests/Feature/Auth/`.
