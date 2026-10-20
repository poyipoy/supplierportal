# ADASI Portal: impact map and implementation design for Infor-style page URLs

Scope: read-only inspection of the current `origin/master` worktree
(`/tmp/claude-0/-home-user-supplierportal/ea3de87b-cf7c-506d-839d-81a9d28a95c4/scratchpad/master-tree`, HEAD `255a9fd docs(deploy): add OAuth migration SQL and production schema checks`).
All code paths below are relative to that tree. No files were modified, and no artisan, DB or test commands were run. `vendor/` is not present in the worktree, so `php artisan route:list` could not be executed. Route counts come from static grep of `routes/web.php`.

The master `AGENTS.md` differs from the outdated branch copy. It lists 19 policies, 29 `HasHashids` models, SSO/OAuth login behind `SSO_LOGIN_ENABLED`, master data routes and a dashboard editor. These notes follow the master copy.

Citation convention: `[path:line](path)` points to the master worktree. External framework or library facts link to their primary source.

---

## Q1. Inventory: how URLs are defined, generated, parsed and asserted today

### Takeaway
All 389 route definitions live in a single 798-line `routes/web.php`, split into about 20 top-level groups with role prefixes. URL generation is almost entirely name-based: 1,098 `route()` calls, no `action()` calls and very few `url('/…')` calls. A prefix or longer hashids therefore propagate automatically through generated links.

The work concentrates in a short list of path-string consumers that parse or match literal paths: 3 PHP `is()` checks, 4 anchored regexes, 1 `fnmatch` allowlist, 4 JS spots and 1 hardcoded jQuery URL. Notification URLs are stored as paths in the DB and re-matched against the route table. The hashid round-trip check rejects any hash that was produced with a different `length`.

### Cited Findings

**Routes and groups**
- `routes/web.php` is 798 lines. Static grep counts 389 `Route::get|post|put|patch|delete|match|resource` definitions: 186 GET, 136 POST, 39 PUT/PATCH/DELETE, 17 `match` and 11 `resource`. 198 non-resource definitions contain a `{param}`, and 35 of them use `{id}`. — [routes/web.php](routes/web.php)
- The only other route files are `routes/console.php` and `routes/channels.php`. `bootstrap/app.php` additionally registers `health: '/up'` and a stateless `POST /oauth/token` route named `passport.token` outside the web group. — [bootstrap/app.php:34-56](bootstrap/app.php)
- Top-level groups (start line, definitions until the next group):

  | Line | Group | Definitions |
  |---|---|---|
  | 104 | supplier-context (`auth, role:supplier`) | 2 |
  | 108 | `local-supplier` prefix (`supplier.scope:local`) | 25 |
  | 142 | `accounting` (`role:accounting,finance,admin`) | 8 |
  | 158 / 162 | `finance` (two groups) | 1 + 74 |
  | 276 / 280 | `ga` | 1 + 10 |
  | 296–342 | guest/public: `/`, verify-receipt, locale, supplier/register, supplier/registration/* | 15 |
  | 344 | `supplier-registrations` reviewer | 6 |
  | 361 | shared auth: dashboard, profile, attachments, exports, export-presets, notifications, conversations | 37 |
  | 439 | `admin` | 22 |
  | 480 | `purchasing/master-data` | 17 |
  | 501 | `purchasing` | 106 |
  | 643 | `shared` (pdf) | 2 |
  | 658 | `supplier` (`supplier.scope:import`) | 34 |
  | 709 / 721 | `qc` | 8 + 1 |
  | 730–798 | auth/security: login, oauth/authorize, sso/callback, forgot-password, two-factor-challenge, confirm-password, password, logout, profile/2FA | 20 |

  Source: [routes/web.php:104-798](routes/web.php).
- The 11 resources are employees, users, exchange-rates, announcements, periods, requisitions, pr-items, purchasing shipments, claims, supplier quotations and supplier shipments. — [routes/web.php:291,462,463,466,505,519,520,529,536,671,677](routes/web.php)
- The SSO routes are `GET oauth/authorize` (name `passport.authorizations.authorize`), `GET sso/callback` (name `sso.callback`, middleware `auth`) and `POST/DELETE oauth/authorize`. — [routes/web.php:741-748](routes/web.php)
- The public signed receipt routes are `/verify-receipt/supplier/{receipt}` and `/verify-receipt/ga/{receipt}`, and both check `hasValidSignature()`. — [routes/web.php:304-309](routes/web.php); [app/Http/Controllers/ReceiptVerificationController.php:11-28](app/Http/Controllers/ReceiptVerificationController.php)

**URL generation**
- `route(` appears 362 times in `app/` and 736 times in `resources/views/`. `action([…])` appears 0 times. There are 13 `url(` matches in app and views, and most of them are harmless (`url('/')`, `url()->current()`, CSS `url()`). — grep over [app](app) and [resources/views](resources/views)
- Hardcoded path literals that would bypass a prefix:
  - `url('/notifications/__NOTIFICATION_ID__/read')` — [resources/views/layouts/app.blade.php:798](resources/views/layouts/app.blade.php)
  - jQuery `url: '/purchasing/po-documents/' + docId` with method `PUT` — [resources/views/purchasing/po/show.blade.php:1040](resources/views/purchasing/po/show.blade.php)
  - `url('/locale')` in registration (the locale route is public) — [resources/views/auth/supplier-register.blade.php:1819](resources/views/auth/supplier-register.blade.php)
  - `url('/dashboard')` in the unused welcome view — [resources/views/welcome.blade.php:28](resources/views/welcome.blade.php)
- The sidebar has 55 `route()` and 70 `routeIs()` calls, so it is name-based. — [resources/views/partials/sidebar.blade.php](resources/views/partials/sidebar.blade.php)
- Quick access is driven by `config/quick_access.php`, which has 65 `'route'` entries rendered through `route()` or `PurchasingNavigation::listUrl()`. — [app/Services/QuickAccessService.php:56-67](app/Services/QuickAccessService.php); [config/quick_access.php](config/quick_access.php)
- DataTables `ajax` configs: 18 occurrences in views. No literal-path ajax URL was found other than the PO documents PUT above. The comparison table uses `config.tableDataUrl`. — [resources/views/purchasing/comparison/_scripts.blade.php:1145](resources/views/purchasing/comparison/_scripts.blade.php)

**JS that parses paths**
- `async-export.js` decides whether a link is an async export with `/^\/(purchasing|supplier|qc)\/export\//` or `path === '/supplier/price-history/export'`. — [public/assets/js/async-export.js:27-38](public/assets/js/async-export.js)
- It also has a long anchored regex for the CSV progress clock. — [public/assets/js/async-export.js:1006](public/assets/js/async-export.js)
- The return-URL injector in the layout only acts on `targetUrl.pathname.startsWith('/purchasing/')` and skips `/purchasing/export/`. It compares against `PurchasingNavigation::listRoutePaths()`, which is route-generated and therefore safe. — [resources/views/layouts/app.blade.php:541-555](resources/views/layouts/app.blade.php)
- `server-tabs.js` uses hrefs and form actions taken from the DOM, falling back to `window.location.pathname`. It never builds paths itself. — [resources/js/server-tabs.js:112-156,142](resources/js/server-tabs.js)
- `ServerTabsResponse` returns `url => $request->fullUrl()`. — [app/Support/ServerTabsResponse.php:28-35](app/Support/ServerTabsResponse.php)
- The lightbox matches `a[href*="/attachments/"]`, which is substring-based and so tolerates a prefix. — [resources/views/components/ui/image-lightbox.blade.php:710,722](resources/views/components/ui/image-lightbox.blade.php)
- `unsaved-changes.js` matches `action.endsWith('/logout')`. — [resources/js/unsaved-changes.js:57](resources/js/unsaved-changes.js)

**PHP that parses paths**
- The `AuthenticationException` renderer uses `$request->is('notifications/unread-count', 'conversations/unread-count', 'conversations/*/messages/latest', 'exports/*/status')`. — [bootstrap/app.php:125-129](bootstrap/app.php)
- `EnsureSsoSession` repeats the same `is()` list to return JSON 401 instead of starting SSO. Its exemption list is name-based (`routeIs(...)`). — [app/Http/Middleware/EnsureSsoSession.php:19-24,43-47](app/Http/Middleware/EnsureSsoSession.php)
- `LimitOperationalRequests` checks `is('broadcasting/auth')`. The broadcasting route is registered outside `web.php`, so it is unaffected. — [app/Http/Middleware/LimitOperationalRequests.php:22](app/Http/Middleware/LimitOperationalRequests.php)
- `TwoFactorService::safeIntendedUrl()` uses `fnmatch` against root-relative patterns: `notifications/*`, `conversations/*`, `exports/*`, `attachments/*`, `api/*`, `*/unread-count`, `*/status`. — [app/Services/Auth/TwoFactorService.php:315-358](app/Services/Auth/TwoFactorService.php)
- `SsoTransactionStore::safeIntendedUrl()` rejects intended URLs whose first path segment is `oauth|sso|login|logout|two-factor-challenge|confirm-password`. — [app/Services/Auth/Sso/SsoTransactionStore.php:70-104](app/Services/Auth/Sso/SsoTransactionStore.php)
- `PurchasingNavigation::containsLegacyNumericIdentifier()` uses anchored regexes `~^/purchasing/(?:requisitions|…)…/\d+~` and `~^/(?:purchasing/export/…|shared/pdf/…)/\d+~`. — [app/Support/PurchasingNavigation.php:206-231](app/Support/PurchasingNavigation.php)
- `PurchasingNavigation::isDataEndpointUrl()` is unanchored. — [app/Support/PurchasingNavigation.php:163-175](app/Support/PurchasingNavigation.php)
- `PurchasingNavigation::LIST_ROUTES` holds 19 route names. List URLs are stored in session as full URLs (`purchasing.index_urls.*`, `purchasing.last_list_url`), and `return_url` is a full URL query parameter. — [app/Support/PurchasingNavigation.php:12-56,102-120](app/Support/PurchasingNavigation.php)
- `ExportConfirmation` posts back to `$request->getPathInfo()`. — [app/Support/ExportConfirmation.php:17](app/Support/ExportConfirmation.php)

**Notifications and stored URLs**
- `SystemNotification` persists `'url' => $this->url` in `data`. — [app/Notifications/SystemNotification.php:88,119](app/Notifications/SystemNotification.php)
- `NotificationUrlResolver::resolve()` normalizes the stored URL to path+query+fragment. It then runs `Route::getRoutes()->match(Request::create($url,'GET'))` to get the route name. Access is allowed by role prefix and supplier ownership. The resolver then canonicalizes by regenerating `route($name, $model)` for about 40 known route names. — [app/Services/NotificationUrlResolver.php:59-76,78-110,112-200,223-251,253-309](app/Services/NotificationUrlResolver.php)
- `resolveModel()` uses `ctype_digit` → `find()`, otherwise the model's `resolveRouteBinding()`. — [app/Services/NotificationUrlResolver.php:505-519](app/Services/NotificationUrlResolver.php)
- There is a legacy regex `~^/purchasing/requirements/…~`. — [app/Services/NotificationUrlResolver.php:212](app/Services/NotificationUrlResolver.php)

**Hashids**
- `config/hashids.php` defines a single connection `main`: `salt` from `HASHIDS_SALT`, `length` from `HASHIDS_LENGTH` (default 8), and the alphabet a-zA-Z0-9. — [config/hashids.php:29-37](config/hashids.php)
- In production, `AppServiceProvider` refuses an empty, default or test salt. — [app/Providers/AppServiceProvider.php:84-90](app/Providers/AppServiceProvider.php)
- `HasHashids::getRouteKey()` returns `Hashids::encode(id)`. `resolveRouteBinding()` returns null for non-strings or `ctype_digit`, and returns null unless decode yields exactly one id and `encode(id) === $value`. — [app/Traits/HasHashids.php:16-52](app/Traits/HasHashids.php)
- 29 model files reference `App\Traits\HasHashids`. — grep over [app/Models](app/Models)
- `DecodeHashids::HASHED_PARAM_KEYS` has 30 keys: `procurementImport, poDocumentBatch, invoice, preset, document, purchaseOrder, purchase_order, goodsReceipt, voucher, payment, refund, batch, id, pr_id, po_id, quotation_id, inspection_id, supplier_id, shipment_id, award_id, requisition, quotation, claim, user, shipment, attempt`. `PLAIN_ROUTE_PREFIXES` has 7 entries and `PLAIN_ROUTE_NAMES` is empty. Raw digits trigger `abort(404)`, and a round-trip mismatch triggers 404. A code comment says implicit bindings are already models when the middleware runs. — [app/Http/Middleware/DecodeHashids.php:29-133](app/Http/Middleware/DecodeHashids.php)
- There are 10 direct `Hashids::` calls outside the trait and middleware. 9 are `encode` calls in DataTables HTML. `FinanceDrpController` decodes `batch_ids` without a round-trip check. — [app/Http/Controllers/Finance/FinanceDrpController.php:240-259](app/Http/Controllers/Finance/FinanceDrpController.php); [app/Http/Controllers/Purchasing/PriceComparisonController.php:551-625,1288](app/Http/Controllers/Purchasing/PriceComparisonController.php)
- There are 21 direct `->resolveRouteBinding(` call sites outside the trait, mostly `resolveSupplierFilter()` helpers and the notification resolver. — grep over [app](app)
- The hashids library's `decode()` re-encodes the decoded numbers and returns `[]` when the result differs from the input. `minHashLength` pads the output with guards and alphabet halves. As a result, a hash made with length 8 cannot be decoded by an instance configured with length 24. — [vinkla/hashids src/Hashids.php @5.0.2](https://raw.githubusercontent.com/vinkla/hashids/5.0.2/src/Hashids.php)
- `composer.lock` pins `hashids/hashids` 5.0.2 and `composer.json` requires `vinkla/hashids ^13.0`. — [composer.lock:2143-2144](composer.lock); [composer.json:22](composer.json)
- Tests force `HASHIDS_SALT` in `phpunit.xml` and do not force `HASHIDS_LENGTH`. — [phpunit.xml:22](phpunit.xml)

**SSO**
- `config/sso.php`: `enabled` comes from `SSO_LOGIN_ENABLED` (default false), plus `client_id`, `state_prefix`, TTL 600 and a maximum of 5 pending transactions. — [config/sso.php:3-10](config/sso.php)
- `redirect_uri` is `route('sso.callback')` (absolute) at begin and is compared exactly at callback. `sso:install-client` stores that absolute URL in the Passport client's `redirect_uris`. — [app/Services/Auth/Sso/SsoTransactionStore.php:29-43](app/Services/Auth/Sso/SsoTransactionStore.php); [app/Http/Controllers/Auth/SsoCallbackController.php:43](app/Http/Controllers/Auth/SsoCallbackController.php); [app/Console/Commands/SsoInstallClient.php:21,44](app/Console/Commands/SsoInstallClient.php)
- `SsoAuthorizeController` requires `redirect_uri ∈ $client->redirect_uris`. — [app/Http/Controllers/Auth/SsoAuthorizeController.php:28-33](app/Http/Controllers/Auth/SsoAuthorizeController.php)

**Security headers**
- `Referrer-Policy` is `no-referrer` on `passport.*`/`sso.*` and `strict-origin-when-cross-origin` on other HTML responses. — [app/Http/Middleware/AddSecurityHeaders.php:17-19,39-40](app/Http/Middleware/AddSecurityHeaders.php)
- CSP includes `base-uri 'self'`, `form-action 'self'`, `frame-ancestors 'none'`, and a `connect-src 'self' …pusher…`. None of these directives are path-sensitive. — [app/Http/Middleware/AddSecurityHeaders.php:44-65](app/Http/Middleware/AddSecurityHeaders.php)

**Web stack order**
- The web group appends `ApplyUserLocale, ShareAuthSplashState, DecodeHashids, EnforceAuthSessionSecurity, EnsureSsoSession, EnforcePasswordChange, AddSecurityHeaders, EnforceSupplierDomain, LimitOperationalRequests`. — [bootstrap/app.php:85-95](bootstrap/app.php)
- `EnforceSupplierDomain` is purely name-based (`routeIs`). — [app/Http/Middleware/EnforceSupplierDomain.php:16-34](app/Http/Middleware/EnforceSupplierDomain.php)

**Tests**
- There are 329 PHP test files.
- 287 test requests use literal paths (`->get('/…')` and similar) across 46 files.
- 35 `assertRedirect('/…')` calls use literals and 210 use `assertRedirect(route(…))`.
- There are 95 `Hashids::encode`/`->hash` references.
- `HashidUrlSecurityTest` (453 lines, 6 tests) checks raw-id 404 and `invalid-hash` 404 through `route()`. It also asserts `PurchasingNavigation::isSafeUrl()` on literal `/purchasing/...` paths. — [tests/Feature/HashidUrlSecurityTest.php:136-245](tests/Feature/HashidUrlSecurityTest.php)
- JS tests with literal portal paths include `tests/js/advanced-export-*.test.mjs`, `regional-async-export-counts`, `async-form-submit` and `local-po-gr-recovery`. — [tests/js](tests/js)

**Deployment**
- The Dockerfile uses `richarvey/nginx-php-fpm:3.1.6`. — [Dockerfile:1](Dockerfile)
- `public/.htaccess` sets public cache headers when `Request_URI` matches `(^|/)assets/` and rewrites everything else to `index.php`. — [public/.htaccess:1-45](public/.htaccess)
- `api/index.php` simply requires `public/index.php`. — [api/index.php](api/index.php)

### Inferences
- About 1,098 name-based generation sites mean URL shape can change with very little Blade or PHP churn. The real work is the roughly 13 path-literal consumers listed above, plus stored URLs (notifications, sessions, emails, bookmarks).
- The expanded route count after resources is roughly 415–425: 378 explicit definitions plus about 41 resource actions. Add `/up`, `/oauth/token` and `broadcasting/auth`. This is an estimate, because `route:list` could not be run.

### Gaps
- `route:list` and runtime middleware order were not executed because `vendor/` is absent. Exact expanded route count and actual `SubstituteBindings` vs `DecodeHashids` ordering are inferred from code comments.
- Email templates were not inventoried individually. They use `route()` and so produce absolute URLs that end up in mailboxes.

---

## Q2. Option A: environment/tenant path prefix (`/v2/ADASI_PRD/...`)

### Takeaway
This option is feasible with low to moderate effort if the prefix is a literal string read from config at route-registration time. A `{tenant}` route parameter is the wrong choice here. Laravel passes controller arguments positionally, so an extra `{tenant}` parameter would shift the arguments of about 198 parameterized routes.

Keep these routes outside the prefix:
- auth, OAuth, SSO and 2FA routes
- public registration and locale routes
- signed receipt routes
- `/up`, `/oauth/token` and broadcasting

Add a GET fallback that redirects legacy paths, a prefix-aware upgrade step in `NotificationUrlResolver`, and fixes for the roughly 10 path-literal consumers. Route names stay unchanged, so the 1,098 `route()` sites need no change.

### Cited Findings
- **Positional arguments.** Laravel's `ControllerDispatcher::dispatch()` calls `$controller->{$method}(...array_values($parameters))`, so route parameters reach controllers positionally and keys are discarded. A `{tenant}` prefix parameter would become the first argument of every controller action unless middleware calls `forgetParameter('tenant')`. — [laravel/framework 12.x ControllerDispatcher.php](https://raw.githubusercontent.com/laravel/framework/12.x/src/Illuminate/Routing/ControllerDispatcher.php)
- **URL::defaults.** `URL::defaults([...])` sets request-wide default values for route parameters. The docs say middleware that sets URL defaults must be prioritized before `SubstituteBindings`, because otherwise it can interfere with implicit model binding. — [Laravel 12 URL Generation: Default Values](https://laravel.com/docs/12.x/urls)
- **Named routes.** Named-route generation is decoupled from the URI: "if the route's URL changes, no changes need to be made to your calls to the `route` function". — [Laravel 12 URL Generation: URLs for Named Routes](https://laravel.com/docs/12.x/urls)
- **SSO `redirect_uri`.** The value is the absolute `route('sso.callback')`, compared exactly at callback and stored in the client's `redirect_uris` by `sso:install-client`. Moving `sso/callback` under the prefix would invalidate the registered client until `sso:install-client` is re-run. — [app/Services/Auth/Sso/SsoTransactionStore.php:29](app/Services/Auth/Sso/SsoTransactionStore.php); [app/Http/Controllers/Auth/SsoCallbackController.php:43](app/Http/Controllers/Auth/SsoCallbackController.php); [app/Console/Commands/SsoInstallClient.php:21](app/Console/Commands/SsoInstallClient.php)
- **First-segment checks in `safeIntendedUrl`.** `SsoTransactionStore::safeIntendedUrl()` blocks intended URLs by first segment (`oauth`, `sso`, `login`, …), so auth routes must stay at the root for this check to keep working. — [app/Services/Auth/Sso/SsoTransactionStore.php:90-101](app/Services/Auth/Sso/SsoTransactionStore.php)
- **`fnmatch` deny-list.** `TwoFactorService::safeIntendedUrl()` matches root-relative patterns such as `notifications/*` and `exports/*`. Under a prefix, `v2/ADASI_PRD/notifications/...` would no longer match, so polling or JSON endpoints could become post-login destinations. This is a UX regression, not a cross-origin risk. — [app/Services/Auth/TwoFactorService.php:341-356](app/Services/Auth/TwoFactorService.php)
- **Polling endpoints.** `bootstrap/app.php:126-127` and `EnsureSsoSession.php:44` identify polling endpoints by root-relative `is()` patterns. Under a prefix, an unauthenticated poll that does not send JSON Accept headers would receive an HTML redirect or SSO start instead of a 401 JSON response. — [bootstrap/app.php:125-129](bootstrap/app.php); [app/Http/Middleware/EnsureSsoSession.php:43-47](app/Http/Middleware/EnsureSsoSession.php)
- **Notification resolver.** It matches stored paths against the live route table. A legacy unprefixed path would fail `match()` or hit a fallback route, so `isAllowedRoute()` returns false and the user lands on `fallback()` or the dashboard instead of the target. — [app/Services/NotificationUrlResolver.php:59-76,112-126](app/Services/NotificationUrlResolver.php)
- **Purchasing navigation.** `PurchasingNavigation::containsLegacyNumericIdentifier()` regexes are anchored at `^/purchasing` and `^/shared`. Under a prefix they silently stop flagging raw-id URLs as unsafe, though `DecodeHashids` still 404s them. `HashidUrlSecurityTest` asserts these literals. — [app/Support/PurchasingNavigation.php:206-221](app/Support/PurchasingNavigation.php); [tests/Feature/HashidUrlSecurityTest.php:239-245](tests/Feature/HashidUrlSecurityTest.php)
- **Hardcoded JS and Blade URLs that break under a prefix:**
  - async export detection — [public/assets/js/async-export.js:30-35,1006](public/assets/js/async-export.js)
  - purchasing return-URL injector — [resources/views/layouts/app.blade.php:545-546](resources/views/layouts/app.blade.php)
  - PUT to `/purchasing/po-documents/` — [resources/views/purchasing/po/show.blade.php:1040](resources/views/purchasing/po/show.blade.php)
  - `url('/notifications/__NOTIFICATION_ID__/read')` — [resources/views/layouts/app.blade.php:798](resources/views/layouts/app.blade.php)
- **Signed receipts.** The signature covers the generated URL and verification calls `hasValidSignature()`. QR codes on printed receipts embed these URLs (`URL::signedRoute('receipts.verify-*')`), so moving the routes would invalidate printed QR codes. — [app/Http/Controllers/LocalInvoiceReceiptController.php:19](app/Http/Controllers/LocalInvoiceReceiptController.php); [app/Http/Controllers/Ga/GaController.php:103](app/Http/Controllers/Ga/GaController.php); [app/Http/Controllers/ReceiptVerificationController.php:13,28](app/Http/Controllers/ReceiptVerificationController.php)
- **`ExportConfirmation`.** It posts back to `getPathInfo()`, which already includes any prefix, so it is safe. — [app/Support/ExportConfirmation.php:17](app/Support/ExportConfirmation.php)
- **Unaffected paths.** CSP and Referrer-Policy are path-agnostic. `EnforceSupplierDomain` and the `EnsureSsoSession` exemptions are route-name based. — [app/Http/Middleware/AddSecurityHeaders.php:39-65](app/Http/Middleware/AddSecurityHeaders.php); [app/Http/Middleware/EnforceSupplierDomain.php:16-34](app/Http/Middleware/EnforceSupplierDomain.php); [app/Http/Middleware/EnsureSsoSession.php:19-24](app/Http/Middleware/EnsureSsoSession.php)

### Inferences (design)

**Config.** Add a new file `config/portal_urls.php`:
- `enabled => (bool) env('PORTAL_URL_PREFIX_ENABLED', false)`
- `prefix => env('PORTAL_URL_PREFIX', 'v2/ADASI_PRD')`, validated at boot against `^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$`
- `legacy_redirects => (bool) env('PORTAL_URL_LEGACY_REDIRECTS', true)`

Add a small `App\Support\PortalPath` helper:
- `prefix(): string`, which returns `''` when the flag is off
- `strip(string $path): string`
- `withPrefix(string $path): string`
- `jsBase()`, which feeds JS through a `<meta name="adasi-base-path">` tag or `window.AdasiPortal.basePath`

**Routes.** In `routes/web.php`, wrap the operational blocks in `Route::prefix(PortalPath::prefix())->group(function () { … })`. Lines 104–294 and 344–723 cover local-supplier, accounting, finance, ga, the supplier-registrations reviewer, shared auth, admin, purchasing/master-data, purchasing, shared and supplier/qc.
- Leave these at the root: lines 296–342 (`/`, verify-receipt, locale, public registration) and 725–798 (login, oauth, sso, forgot-password, 2FA, confirm-password, password, logout, profile security actions).
- `Route::prefix('')` is a no-op, so with the flag off the route table is identical. This can be checked by diffing `route:list --json` with the flag off before and after the change.
- Route names, `HASHED_PARAM_KEYS`, `PLAIN_ROUTE_PREFIXES`, policies and middleware are untouched.

**Do not use `{tenant}`.** It would require all of the following:
- `URL::defaults(['tenant' => …])` middleware prioritized before `SubstituteBindings`
- `$request->route()->forgetParameter('tenant')`, because of positional dispatch
- a `where('tenant','ADASI_PRD')` constraint

That is more moving parts for no benefit in a single-company portal.

**Route caching.** `PortalPath::prefix()` is evaluated when routes are loaded. With `route:cache` and `config:cache`, the prefix is baked in, so flipping the flag requires `php artisan config:cache && php artisan route:cache` as part of the deploy. This is standard practice.

**Legacy redirect layer.** Register a `Route::fallback(LegacyPortalPathController::class)` at the end of `web.php`. For GET and HEAD only, it does the following:
- If the flag is on and the path lacks the prefix, it builds `/<prefix><path>`. It checks that the path matches a GET route via `Route::getRoutes()->match(Request::create(...))` and returns a 301 (consider 302 during the pilot) with the query string preserved.
- If the flag is off and the path starts with the stale prefix, it strips the prefix and redirects. This makes rollback safe.
- Otherwise it returns 404.
- It only matches route patterns and never touches models. Authorization stays on the target route, so a raw-int legacy path redirects and then 404s under `DecodeHashids`, which keeps the invariant at the cost of one extra hop.
- Non-GET legacy requests (stale open forms) are not redirected. Fix the one hardcoded PUT URL. Optionally return 308 for POST during a short window.
- Unverified: from framework knowledge (not checked in source this session), `Route::fallback` is registered for GET only and runs under the group it is declared in.

**Path-literal fixes** (all are no-ops while the flag is off):
1. `bootstrap/app.php:126-127` and `EnsureSsoSession.php:44`: replace the `is()` checks with `routeIs('notifications.unread-count','conversations.unread-count','conversations.messages.latest','exports.status')`. Fall back to `is()` on `PortalPath::strip()` only if the route is unresolved, because the exception renderer may run before route resolution for unmatched paths.
2. `TwoFactorService::safeIntendedUrl()`: call `PortalPath::strip()` on `$trimmedPath` before `fnmatch`. `SsoTransactionStore` stays as is, because auth stays at the root.
3. `PurchasingNavigation::containsLegacyNumericIdentifier()`: strip the prefix before the anchored regexes.
4. `NotificationUrlResolver::resolve()`: after `normalize()`, add `$storedUrl = $this->upgradePortalPath($storedUrl)`. This tries `match()` on the URL as stored and, if that fails, with the prefix added or stripped. Then continue with `isAllowedRoute()` and `canonicalizeAllowedUrl()` unchanged, because canonicalization regenerates via `route()` and so emits the current prefix and hash. `resolveLegacyRequirementUrl()` keeps working on unprefixed legacy paths.
5. JS:
   - `async-export.js:30-35,1006`: strip `document.querySelector('meta[name=adasi-base-path]')` from the pathname before testing. The cleaner alternative is to mark export controls with an attribute selector, which already exists as `selector`.
   - `layouts/app.blade.php:545-546`: use `@json(PortalPath::withPrefix('/purchasing/'))`.
   - `po/show.blade.php:1040`: use `route('purchasing.po-documents.update', '__ID__')` with string replace, or a `data-url` attribute.
   - `layouts/app.blade.php:798`: use `route('notifications.read', '__NOTIFICATION_ID__')`. Notifications are a plain route prefix, so this is safe.
   - Update `tests/js/*` path literals where they assert prefix-sensitive logic.

**Effort:**

| Phase | Work | Estimate |
|---|---|---|
| 0 | Prefix-aware helpers and the path-literal fixes above, flag off, including unit tests | about 1.5–2 dev-days |
| 1 | Route wrap, fallback, config, `PortalUrlPrefixTest` (flag on via env and `refreshApplication()`), targeted suites with the flag on | about 2–3 dev-days |

Also budget staging verification: SSO round trip, notifications, exports, server tabs, DataTables and back button.

**Test impact:**
- With the flag off by default (phpunit does not set it), the existing 287 literal-path requests and 35 literal `assertRedirect`s are unaffected.
- New flag-on tests should cover:
  - the route table has a prefix for operational names and none for `sso.callback`, `login` and `receipts.verify-*`
  - legacy GET returns 301 to the prefixed path
  - a raw int under the prefix still returns 404
  - a notification with a stored legacy URL resolves to the canonical prefixed URL
  - `safeIntendedUrl` rejects prefixed polling endpoints
- Run `HashidUrlSecurityTest`, `SupplierDataIsolationTest`, `tests/Feature/Auth/` (SSO) and the export and server-tabs suites with the flag on.

**Invariants preserved:**
- route names and `role.resource.action` naming
- `HASHED_PARAM_KEYS` and `PLAIN_ROUTE_PREFIXES` semantics, which are name-based
- raw-int 404
- policies and `EnforceSupplierDomain`, which are name-based
- `sso.callback` exact and `redirect_uri` unchanged
- signed receipt URLs and printed QR codes still valid
- notification boundary checks: the upgrade happens before `isAllowedRoute()` and never bypasses it

### Gaps
- No runtime confirmation of the expanded route table or of fallback-route middleware behaviour in this codebase.
- Whether the production reverse proxy or APM has path-based rules (allowlists, WAF) was not visible. Only `Dockerfile` and `.htaccess` were inspected.

---

## Q3. Option B: longer per-model hashids (24–32 chars) with dual-decode transition

### Takeaway
Encoding is a config-only change (`HASHIDS_LENGTH=24`). Decoding is the hard part, because both the hashids library and this app reject any hash that does not round-trip under the current config. Every 8-char hash already in circulation would therefore 404 unless a second `legacy` connection with the same salt and alphabet and length 8 is consulted. Old hashes live in notification `data.url`, emails, bookmarks, session `return_url`s and export preset filters.

There are three decode chokepoints:
- `HasHashids::resolveRouteBinding`, which also covers the 21 direct `resolveRouteBinding` callers and `NotificationUrlResolver`
- `DecodeHashids::handle`
- one manual decode in `FinanceDrpController`

Per-model salts are not recommended, because the shared `{id}` parameter in `DecodeHashids` does not carry the model class.

### Cited Findings
- Length comes from env: `'length' => env('HASHIDS_LENGTH', 8)`. — [config/hashids.php:33](config/hashids.php)
- The library's `decode()` re-encodes and returns `[]` on mismatch, and `minHashLength` pads the encoded output. — [vinkla/hashids @5.0.2 Hashids.php](https://raw.githubusercontent.com/vinkla/hashids/5.0.2/src/Hashids.php)
- The app performs its own round-trip check in both decode paths: `Hashids::encode($id) !== $value` returns null in the trait, and `abort_unless(... Hashids::encode(...) === $value, 404)` in the middleware. — [app/Traits/HasHashids.php:45-49](app/Traits/HasHashids.php); [app/Http/Middleware/DecodeHashids.php:111-126](app/Http/Middleware/DecodeHashids.php)
- `NotificationUrlResolver::resolveModel()` delegates to `resolveRouteBinding()`, so legacy hashes inside stored notification URLs would resolve if the trait gains dual decode. `canonicalizeAllowedUrl()` then re-emits `route($name, $model)` with the new hash. — [app/Services/NotificationUrlResolver.php:223-251,505-519](app/Services/NotificationUrlResolver.php)
- `FinanceDrpController` decodes posted `batch_ids` with a bare `Hashids::decode` and no round-trip check. These ids come from the current page, so they would carry new-length hashes. — [app/Http/Controllers/Finance/FinanceDrpController.php:240-259](app/Http/Controllers/Finance/FinanceDrpController.php)
- Export presets persist `filters` as a JSON array. Supplier filters are hashids resolved by `resolveSupplierFilter()` helpers through `resolveRouteBinding`, so presets saved before the switch hold 8-char hashes. — [app/Models/ExportPreset.php:12-16](app/Models/ExportPreset.php); [app/Http/Controllers/Purchasing/ExportController.php:196](app/Http/Controllers/Purchasing/ExportController.php); [app/Http/Controllers/Purchasing/SupplierAuditController.php:229](app/Http/Controllers/Purchasing/SupplierAuditController.php)
- No FormRequest or controller validation caps the length of hashed inputs. A grep for `max:8|10|12|16|20|32` on id-like fields found nothing relevant. — grep over [app/Http](app/Http)
- `DecodeHashids` comments state that implicit bindings are already model objects when it runs, so it skips non-strings. — [app/Http/Middleware/DecodeHashids.php:106-109](app/Http/Middleware/DecodeHashids.php)
- `HASHED_PARAM_KEYS` contains the generic `id`, which is shared by PurchaseOrder, supplier Claim, QcInspection and Conversation routes. — [app/Http/Middleware/DecodeHashids.php:46](app/Http/Middleware/DecodeHashids.php)
- Production salt guard. — [app/Providers/AppServiceProvider.php:84-90](app/Providers/AppServiceProvider.php)
- Tests use whatever length is configured: 95 `Hashids::encode`/`->hash` references, and `HashidUrlSecurityTest` asserts `getRouteKey()` round trips, raw-id 404 and `invalid-hash` 404. — [tests/Feature/HashidUrlSecurityTest.php:136-207](tests/Feature/HashidUrlSecurityTest.php)

### Inferences (design)

**Config.** Add a `legacy` connection to `config/hashids.php`:
- same `HASHIDS_SALT` and alphabet
- `length => env('HASHIDS_LEGACY_LENGTH', 8)`
- `enabled => env('HASHIDS_LEGACY_DECODE', true)` read by the new codec

Then set `HASHIDS_LENGTH=24` (or 32). `encode()` and `getRouteKey()` emit the new length with no code change.

**Codec.** Add `App\Support\HashidCodec::decode(string $value): ?array{id:int, legacy:bool}`:
- reject `ctype_digit`
- try `main`, requiring exactly one id > 0 and an exact round trip
- else, if legacy decode is enabled, try `legacy` with the same rules
- return null otherwise

`Hashids::connection('legacy')` is a vinkla/laravel-hashids manager feature. This is inferred and was not checked in source this session.

**Hook points:**
1. `HasHashids::resolveRouteBinding()` uses the codec. This also fixes the 21 direct callers, the notification resolver and export preset filters.
2. `DecodeHashids::handle()` uses the codec for scalar parameters.
3. Add a canonical redirect in `DecodeHashids`. For GET and HEAD only, if any parameter was a legacy decode, or if an implicitly bound model's original segment (`$route->originalParameters()`, a framework API, unverified here) differs from `$model->getRouteKey()`, respond with a 301 to `route($route->getName(), canonical parameters + current query)`. Hashed query filters (`supplier_id`, `pr_id`, `supplier`, `user_id`) can be canonicalized the same way, following the existing key list in `NotificationUrlResolver::appendCanonicalQueryAndFragment()`.
4. `FinanceDrpController:249` uses the codec, which also adds the missing round-trip check.

**Collision analysis (inference).** A main hash is at least 24 chars and a legacy hash is about 8 chars for realistic ids, so the two cannot coincide. Main is tried first, and both sides require an exact round trip.

**Sunset.** Log a counter for legacy decodes: route name and model class only, never the URL. Keep legacy decode for one or two release cycles, then flip `HASHIDS_LEGACY_DECODE=false`.

**Per-model salt (optional).** Each model would get `salt . '|' . class_basename`.
- Generic `{id}`, `po_id`, `pr_id` and similar in `DecodeHashids` cannot know the model class. That would require a route-name → model map for about 35 `{id}` routes, or migrating them to implicit binding.
- `PriceComparisonController` and other direct `Hashids::encode` calls would need the right model connection.
- Effort is about 3–5 extra days with real risk of 404 regressions. Not recommended for a cosmetic goal.

**Effort:** about 2–3 dev-days for codec, trait, middleware, canonical redirect and tests, plus staging soak.

**Test impact:**
- Existing tests are length-agnostic, because tests do not force `HASHIDS_LENGTH`.
- Add tests that:
  - set length 24 and assert `getRouteKey()` length ≥ 24
  - assert a legacy 8-char hash resolves under `resolveRouteBinding`
  - assert a legacy GET returns 301 to the 24-char URL
  - assert raw ints still return 404 under both connections
  - assert a garbage string returns 404
  - assert the legacy decode switch off makes the legacy hash return 404
  - assert a notification with a stored legacy hash canonicalizes to the new URL

**Invariants preserved:**
- raw-int 404 (DecodeHashids:111, HasHashids:35)
- `HASHED_PARAM_KEYS` / `PLAIN_ROUTE_PREFIXES` unchanged
- policies still authorize after binding
- notification boundary checks unchanged
- production salt guard unchanged, since both connections share the salt

### Gaps
- Not verified in source this session: whether `vinkla/laravel-hashids` ^13 exposes `Hashids::connection('legacy')` exactly as assumed, and the exact name and behaviour of `Route::originalParameters()` in Laravel 12. Both are framework or library knowledge.
- How many users hold bookmarks or emails with old hashes is unknown, so the sunset window is a business decision.

---

## Q4. Option C: page registry (stable UUID per menu page)

### Takeaway
A registry that maps a stable page UUID to a route name fits naturally on top of the existing name-based menus. Two variants exist:
- **C1, alias landing:** `/v2/ADASI_PRD/<uuid>` redirects to the named route. This takes about 1–2 days, but the address bar then shows the real route.
- **C2, real page URIs:** list and dashboard routes are registered at `/<prefix>/<uuid>` under their existing names. The address bar shows the UUID, but `Route::resource` index routes must be split out and path-sensitive JS must be audited. This takes about 3–4 more days.

### Cited Findings
- The sidebar is fully name-based: 55 `route()` and 70 `routeIs()` calls. — [resources/views/partials/sidebar.blade.php](resources/views/partials/sidebar.blade.php)
- Quick access already behaves like a registry. `config/quick_access.php` has 65 `'route'` entries rendered via `route()` / `PurchasingNavigation::listUrl()`, with `active` computed through `routeIs`. — [config/quick_access.php](config/quick_access.php); [app/Services/QuickAccessService.php:56-67](app/Services/QuickAccessService.php)
- `PurchasingNavigation::LIST_ROUTES` (19 names) and `listRoutePaths()` derive list paths from `route()`, so a UUID URI would be picked up automatically. — [app/Support/PurchasingNavigation.php:12-32,177-186](app/Support/PurchasingNavigation.php)
- 11 `Route::resource` registrations generate their `index` URIs implicitly (for example `requisitions`, `periods`, `shipments`). — [routes/web.php:291-677](routes/web.php)
- The layout's return-URL injector decides by `pathname.startsWith('/purchasing/')`, which C2 list pages at `/<prefix>/<uuid>` would not satisfy. Detail pages still would. — [resources/views/layouts/app.blade.php:545-547](resources/views/layouts/app.blade.php)

### Inferences (design)

**Registry.** Create `config/page_registry.php` mapping `'<uuid>' => 'purchasing.requisitions.index'`, plus `App\Support\PageRegistry` with `uuidFor($routeName)`, `routeFor($uuid)` and `url($routeName, $params = [])`.
- Generate the UUIDs once as v4 and commit them, or derive them deterministically as UUIDv5(namespace, route name). Committed v4 values look more Infor-like and survive route renames.
- Candidate pages are the union of sidebar targets and `quick_access` entries (about 65) plus dashboards.

**C1 (alias landing).**
- Route: `GET /{prefix}/{page}` with `where('page', '[0-9a-f-]{36}')`, declared inside the prefixed group after static routes. The controller resolves the route name, returns 404 if unknown and redirects (302) to `route($name)`. The target route's middleware and policies enforce access.
- Menus render `PageRegistry::url(...)`. The `routeIs` active states keep working because the user lands on the real route.
- Downsides: the address bar shows the real route after the redirect, and each menu click costs an extra request.

**C2 (UUID as the real URI).**
- In `web.php`, replace `Route::get('/requisitions', …)->name('purchasing.requisitions.index')` with `Route::get(PageRegistry::uri('purchasing.requisitions.index'), …)`, keeping the same name.
- For resources, use `->except('index')` plus an explicit index route with the same name and controller.
- Detail and action routes keep readable segments with long hashids from Option B. Infor itself mixes page ids and context.
- Changes required:
  - the layout return-URL injector should use a server-provided set of purchasing page paths, as it already does for list paths
  - `async-export` is unaffected, because export URLs stay readable
  - add legacy redirects for old list paths through the Option A fallback, extended with an old-path → route-name map that is generated from the pre-change route table and committed
- Effort is about 3–4 dev-days on top of A, plus regression testing of server tabs and filters on list pages. Those tests use `route()`, so most of them adapt automatically.

**Invariants.** Route names are unchanged and `routeIs()` active states are unchanged. `PurchasingNavigation` list memory still works because it is route-derived. DecodeHashids is not involved, because page UUIDs are not model ids: the `page` parameter name is not in `HASHED_PARAM_KEYS`, and it must never be added.

### Gaps
- There is no existing page registry or menu config besides `quick_access.php` and the hand-written sidebar, so the exact page list must be enumerated during implementation.

---

## Q5. Option D: context token `?ctx=` on every page

### Takeaway
This is technically possible, either through a `UrlGenerator` subclass that appends `ctx` to GET named routes or through a redirect-adding middleware. However, it is purely cosmetic and conflicts with several mechanisms:
- signed receipt URLs
- URL-keyed caches in server tabs
- `return_url` and session list-URL memory
- export polling
- notification canonicalization
- link sharing between users
- log hygiene

It is not recommended. If it is mandated, use a stateless, constant-per-session HMAC token that is ignored on input. Exclude non-GET, JSON, signed, export, attachment and polling routes.

### Cited Findings
- Signed receipt routes are verified with `hasValidSignature()`, and URL signatures are computed over the generated URL. Any generator-level query injection would break verification unless those routes are excluded. — [app/Http/Controllers/ReceiptVerificationController.php:13,28](app/Http/Controllers/ReceiptVerificationController.php); [Laravel 12 Signed URLs](https://laravel.com/docs/12.x/urls)
- Server tabs cache and prefetch keyed by URL (`cacheGet(url, …)`, `prefetchInFlight` set) and push history states with full URLs. A per-render or rotating `ctx` would defeat caching and duplicate history entries. — [resources/js/server-tabs.js:67-68,174-183,197-206,391](resources/js/server-tabs.js)
- `ServerTabsResponse` echoes `fullUrl()` back to the client. — [app/Support/ServerTabsResponse.php:33](app/Support/ServerTabsResponse.php)
- `PurchasingNavigation` stores full URLs in session and in `return_url` (`fullUrlWithoutQuery([return_url])`), so `ctx` would be embedded inside `return_url` values and session list memory. — [app/Support/PurchasingNavigation.php:39-56,102-120](app/Support/PurchasingNavigation.php)
- `NotificationUrlResolver::appendCanonicalQueryAndFragment()` re-validates query keys and would need a `ctx` allowlist rule (drop or regenerate). — [app/Services/NotificationUrlResolver.php:253-309](app/Services/NotificationUrlResolver.php)
- Polling and JSON endpoints are path-identified (unread counts, export status), and DataTables share URLs with page routes (`ServerTabsResponse::wants()` vs `ajax()`). — [bootstrap/app.php:125-129](bootstrap/app.php); [app/Support/ServerTabsResponse.php:11-22](app/Support/ServerTabsResponse.php)
- Referrer-Policy `strict-origin-when-cross-origin` means `ctx` would not leak cross-origin through `Referer`. It would still appear in same-origin referers, server access logs, browser history and screenshots. AGENTS.md forbids logging OAuth URLs and query strings for SSO routes only. — [app/Http/Middleware/AddSecurityHeaders.php:39-40](app/Http/Middleware/AddSecurityHeaders.php); AGENTS.md (master) "Auth dan Konfigurasi" SSO bullets

### Inferences (design, if mandated)

**Generator.** Bind a custom `App\Routing\PortalUrlGenerator extends Illuminate\Routing\UrlGenerator` in a service provider. Override `route()` to append `ctx` only when all of the following hold:
- the flag is on
- the route is GET-only
- the name is on an allowlist (page routes from `PageRegistry`)
- the call is not `signedRoute` or `temporarySignedRoute`

**Token.** Use `ctx = base64url(hmac_sha256(app.key, session_id_hash . '|' . route_name))[0..43]`. This is stable per session and route, so caches and history behave. Middleware never requires `ctx`, never 4xx's on a missing or foreign `ctx` (so shared links keep working), and strips or rewrites a foreign `ctx` silently.

**Server-side state token (alternative).** Store `ctx → {filters,tab}` in session. This adds real state and back-button complexity and does not fit the existing filter-in-query design. Reject it.

**Effort.** About 4–6 dev-days plus broad JS regression risk across server tabs, DataTables, async export and dashboards.

**Recommendation.** Leave D out. Long hashids (B) and page UUIDs (C) already give the opaque look.

### Gaps
- Not researched here: the semantics of Infor's real `ctx` parameter and whether users expect it to round-trip state. Other research notes in this folder cover precedents and token encoding.

---

## Q6. Option E: recommended minimal "Infor look" combination, phased, with invariants

### Takeaway
Recommend **A (literal config prefix) + B (24-char hashids with legacy dual decode) + C1, optionally upgraded to C2 for top-level pages**, leaving D out. Each piece sits behind its own flag, following the `SSO_LOGIN_ENABLED` pattern, so it ships dark and can be enabled and rolled back by env plus `config:cache`/`route:cache`.

Route names, DB schema, policies, `HASHED_PARAM_KEYS`, SSO `redirect_uri` and signed receipts stay unchanged. No migration and no new package is needed.

Total effort is about 7–10 dev-days, plus staging verification. Resulting URLs look like `/v2/ADASI_PRD/purchasing/purchase-orders/Xk3…(24 chars)`, and menus point at `/v2/ADASI_PRD/<uuid>`.

### Cited Findings
- `config/sso.php` already reads an env flag that defaults to false, and AGENTS.md documents it as opt-in. This is the precedent for flag-gated rollout. — [config/sso.php:4](config/sso.php)
- AGENTS.md rules that this design must respect:
  - preserve route names and route compatibility, because views and JS depend on them
  - raw integers on hashed parameters must 404, guarded by `HashidUrlSecurityTest`
  - register new hashed parameter names in `HASHED_PARAM_KEYS`
  - do not add `Attachment` back to `PLAIN_ROUTE_PREFIXES`
  - do not add packages or migrations unless necessary
  - notification links must follow role, scope and context boundaries via `NotificationUrlResolver`

  Source: AGENTS.md (master) sections "Hashids pada URL", "Notifikasi, Pusher, dan Polling", and the work rules near the top.
- Name-based generation sites (1,098 `route()` calls) absorb A, B and C2 automatically. — grep counts, Q1

### Inferences (phased plan)

**Phase 0: no-behaviour-change hardening (flag off; about 1.5–2 days)**
- Add `PortalPath` and replace the path-literal consumers: [bootstrap/app.php:126-127](bootstrap/app.php), [EnsureSsoSession.php:44](app/Http/Middleware/EnsureSsoSession.php), [TwoFactorService.php:341-356](app/Services/Auth/TwoFactorService.php), [PurchasingNavigation.php:206-221](app/Support/PurchasingNavigation.php), [NotificationUrlResolver.php:59-76](app/Services/NotificationUrlResolver.php) (prefix-agnostic match), [async-export.js:30-35,1006](public/assets/js/async-export.js), [layouts/app.blade.php:545-546,798](resources/views/layouts/app.blade.php), [po/show.blade.php:1040](resources/views/purchasing/po/show.blade.php).
- Add the `adasi-base-path` meta tag.
- Ship this phase on its own. The full suite should stay green because behaviour is identical with an empty prefix.

**Phase 1: Option B (about 2–3 days)**
- Add the `legacy` hashids connection, `HashidCodec`, the trait and middleware hooks, the canonical 301 and the FinanceDrp fix.
- Deploy with `HASHIDS_LENGTH=8` first (no-op), then switch to 24 in staging and then production.
- This phase is independent of the URL prefix, so it can go first. It delivers most of the "long opaque identifier" look with the least routing risk.

**Phase 2: Option A (about 2–3 days)**
- Wrap the operational groups in `Route::prefix(PortalPath::prefix())`, add the bidirectional legacy fallback, and set `PORTAL_URL_PREFIX_ENABLED`.
- The deploy runbook must re-run `config:cache` and `route:cache`.
- Verify the SSO round trip: `/sso/callback` stays at the root and `sso:install-client` needs no re-run.
- Verify stored notifications, exports polling, server tabs, DataTables, PDF links and printed receipt QR codes (root, signed).

**Phase 3: Option C1, optionally C2 (C1 about 1–2 days; C2 about 3–4 more days)**
- Add `config/page_registry.php` and `PageRegistry`, and render the sidebar and `quick_access` through `PageRegistry::url()`.
- Then, optionally, move about 19 to 65 top-level list and dashboard routes to UUID URIs with the same names.

**Out of scope: Option D.**

**Invariant checklist for every phase:**
1. Route names unchanged, including the `role.resource.action` names used by `routeIs()`, `EnforceSupplierDomain`, `DecodeHashids::PLAIN_ROUTE_PREFIXES`, `NotificationUrlResolver` prefixes and `PurchasingNavigation::LIST_ROUTES`.
2. Raw integer on a hashed parameter returns 404, also after legacy redirects. The redirect happens before binding, and the target 404s.
3. `HASHED_PARAM_KEYS` stays authoritative. The new `page` parameter is never a hashed key.
4. Policies, `RoleMiddleware`, `supplier.scope`, `EnforceSupplierDomain` and `Gate::authorize()` are unchanged. Redirect layers never resolve models or authorize.
5. Notification links still pass through `isAllowedRoute()` and canonicalization. The legacy-path upgrade only rewrites the path before matching.
6. `sso.callback`, `passport.*`, `login`, 2FA, confirm-password and logout stay at the root, so `redirect_uri` is unchanged and `SsoTransactionStore::safeIntendedUrl` segment checks still work.
7. Signed `receipts.verify-*` URLs stay at the root, so printed QR codes stay valid.
8. No DB migration, no new package, and no change to `export_jobs` or the atomic queue handoff.
9. Per AGENTS.md, document the new env keys (`PORTAL_URL_PREFIX_ENABLED`, `PORTAL_URL_PREFIX`, `HASHIDS_LEGACY_DECODE`, `HASHIDS_LEGACY_LENGTH`) once in `AGENTS.md`, `.env.example` and a guide under `docs/guides/`.

**Main risks:**
- Stale cached routes or config after a flag flip. Mitigation: the deploy runbook.
- Unflagged JS path checks that were missed. Mitigation: grep `pathname`, `'/purchasing`, `'/supplier`, `'/finance` in CI as a guard test, similar to `BusinessTimeGuardTest`.
- Extra 301 hops for old links until users re-bookmark.
- Users or support staff sharing longer URLs. This is harmless.

### Gaps
- Effort figures are engineering estimates from the inspected code surface, not measured. They assume the serial MySQL test suite runs in minutes, as AGENTS.md states.
- Production proxy, WAF and log-redaction configuration was not inspected, and it may need a path allowlist update for `/v2/ADASI_PRD/*`.
