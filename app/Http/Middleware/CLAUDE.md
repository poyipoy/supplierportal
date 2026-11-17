# app/Http/Middleware

Registration lives in `bootstrap/app.php`, not in a Kernel.

- Web group append order (`bootstrap/app.php`): `ApplyUserLocale`, `ShareAuthSplashState`, `DecodeHashids`, `EnforceAuthSessionSecurity`, `EnsureSsoSession`, `EnforcePasswordChange`, `AddSecurityHeaders`, `EnforceSupplierDomain`, `EnforceMaintenance`, `LimitOperationalRequests`, `WrapWorkspaceShell`. Priority list places `ApplyUserLocale` and `ShareAuthSplashState` immediately after `StartSession`. These run on every web request, so any change here affects every role.
- Aliases: `role`, `supplier.scope`, `password.confirm`, `purchasing.navigation`, `mfa.pending`, `no-store`, `registration.session`.

## DecodeHashids
- Decodes only route parameter names listed in `HASHED_PARAM_KEYS`. A new route with a new hashed parameter name must be added there, or the controller receives the raw hash string.
- A raw digit string returns `abort(404)` on purpose; `tests/Feature/HashidUrlSecurityTest.php` guards this.
- Parameters already bound by implicit binding (model objects) are skipped.
- Plain-integer models are opted out by route-name prefix in `PLAIN_ROUTE_PREFIXES`. Don't put `attachments.` there: Attachment uses hashid implicit binding.

## EnforceSupplierDomain
- Keeps the Import and Local cores apart on shared endpoints. It is not an authorization layer; policies still apply.
- The `attachments.show` exceptions for `SupplierOverpaymentRefund`, `LocalPurchaseOrder`, and `SupplierAudit` are intentional. Keep all three.

## EnforceMaintenance
- Maintenance gate for every signed-in role except admin (AGENTS.md, "Pemeliharaan Portal"). It runs before `role`/`supplier.scope`, so a module only blocks roles that work in its core: `MaintenanceModuleRegistry::scopesFor()` (suppliers: held scopes; purchasing: import + local; qc: import; finance, accounting, ga: local). Admin, guests and background work are never blocked.
- Route ownership lives in `App\Support\Maintenance\MaintenanceModuleRegistry`. A new route reachable by any non-admin role must be classified there (module pattern, `STAFF_PORTAL_ROUTES` for Global-only areas, or always-allowed); `MaintenanceModuleRegistryTest` fails otherwise.

## Role and scope
- `RoleMiddleware`: exact match against the listed roles. Admin gets no bypass, and denials are logged.
- `SupplierScopeMiddleware` (`supplier.scope:import|local`): 403 without the scope; it then writes `session('supplier_context')`, which `App\Support\PortalContext` reads.

## ShareAuthSplashState
- Reads/consumes one-time `ui.auth_splash` session markers only on real GET document navigations (`isDocumentNavigation`), keeping polling/XHR requests untouched.
- On `X-Auth-Splash-Client` JSON login/2FA handoffs, `bindNewMarker()` reads `redirect_url` from the successful `JsonResponse` fallback when `FormRequest` attribute bags are cloned away from the outer request, binding `ui.auth_splash.target` directly to the resolved role dashboard.

## WrapWorkspaceShell
- Wraps eligible authenticated top-level HTML GET requests in `layouts.workspace-shell` when `WORKSPACE_SHELL_ENABLED=true`, excluding `top_level_security` and `non_shell_direct` routes (`WorkspaceShell::isShellEligibleRoute()`).
- Top-level wrap candidates render as embedded (`isWorkspaceEmbed = true`) once and store HTML + originating `CspNonce` in a 15s single-use cache (`WorkspaceShell::prerenderCacheKey()`). The immediate `?_embed=1` iframe request pulls that entry via `Cache::pull()` and rewrites `nonce="..."` to its own fresh `CspNonce`, skipping duplicate controller/view execution.

## LimitOperationalRequests
- Maps routes to per-user limiter buckets by route-name pattern (`exports`, `pdf`, `search`, ...). Renaming routes can silently move them between buckets.

Tests: `HashidUrlSecurityTest`, `SupplierDataIsolationTest`, `AttachmentAuthorizationBoundaryTest`, `WorkspaceShellSecurityTest`, `WorkspaceShellRouteClassificationTest`, `tests/Feature/Auth/`.
