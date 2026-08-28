# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Working rules

1. Understand the requested mission before editing. Restate the scope if it is ambiguous.
2. Read the affected files, their callers, and their tests before changing them. This repo has non-obvious conventions; assuming a stock Laravel convention will be wrong.
3. Follow the pattern already used in the module you are touching instead of introducing a new one.
4. Preserve existing business logic unless the mission explicitly asks to change it.
5. Keep changes scoped to the mission. No opportunistic refactoring, no cleanup of unrelated modules, no renaming that ripples outward.
6. Do not add abstraction layers (new base classes, repositories, extra service indirection) unless the mission requires them.
7. Maintain backward compatibility for routes, route names, DB columns, and public model methods. Existing views and JS depend on them.
8. Do not replace working architecture because a different architecture looks cleaner.
9. State what you verified after implementing, and what you could not.

## AGENTS.md and its stale sections

[AGENTS.md](AGENTS.md) is the domain contract (Indonesian): roles, workflow order, upload rules, prohibited patterns. Read it. But **it has drifted from the schema** — where they disagree, the code wins:

| AGENTS.md says | Repository actually has |
|---|---|
| `purchase_requirements` table | renamed to `purchase_requisitions` (`2026_06_08_160000`), model `PurchaseRequisition` |
| currency `USD\|JPY` | enum `USD, JPY, IDR, CNY` ([ExchangeRate::CURRENCIES](app/Models/ExchangeRate.php)) |
| PR/PO number via `count() + 1` | atomic `document_sequences` table, `lockForUpdate()` inside a transaction — use `PurchaseRequisition::generatePrNumber()` / `PurchaseOrder::generatePoNumber()`, never re-derive |
| `IDR = price_per_kg × weight_needed × rate` | amount uses `PrItem::total_weight` (`weight_needed × quantity_value`) via [QuotationItem::calculateAmount()](app/Models/QuotationItem.php); IDR conversion multiplies by the rate |
| always take the latest rate | quotations/POs store `exchange_rate_id`; historical comparisons join that snapshot, not the current rate. `ExchangeRate::latestRate()` is cached 60 min and invalidated on insert |
| `purchase_orders.quotation_id` (one PO per quotation) | consolidated: `po_quotations` pivot (`PurchaseOrder::quotations()` is `belongsToMany`), with `supplier_id`, `currency`, `exchange_rate_id` denormalized onto `purchase_orders` (`2026_05_22_000001`) |

Do not "fix" AGENTS.md as a side effect of a code task.

## Commands

```bash
composer setup              # install, .env, key:generate, migrate, npm install, npm run build
composer dev                # serve + queue:listen + pail + vite concurrently
npm run dev                 # vite only
npm run build               # production assets

composer test               # config:clear then artisan test
php artisan test --testsuite=Unit
php artisan test --filter=SupplierDataIsolationTest
php artisan test tests/Feature/Auth/LoginSecurityTest.php
vendor/bin/pint             # formatter; no pint.json, Laravel preset defaults

php artisan queue:work database --queue=exports,default   # required or exports never finish
php artisan optimize:clear
```

## Verification

Run the smallest relevant check first, then widen:

1. `php artisan test --filter=<TestName>` for the tests covering what you touched.
2. The suite for that area (`tests/Feature/Auth/`, `tests/Unit/Materials/`, `SupplierDataIsolationTest`, `HashidUrlSecurityTest`).
3. `composer test` before declaring a cross-cutting change done.
4. `npm run build` after touching Blade classes, `resources/css/app.css`, `resources/js/app.js`, or [tailwind.config.js](tailwind.config.js).

Tests run against a **real MySQL database** named `adasi_portal_test` ([phpunit.xml](phpunit.xml)) — not SQLite. It must exist locally, and most feature tests use `RefreshDatabase`.

**Known pre-existing baseline:** 211 tests pass with 15 risky "did not close its own output buffers" warnings (mostly `tests/Feature/Auth/`, plus `HashidUrlSecurityTest`) — see [phpunit-results.log](phpunit-results.log). Those are not your regression. Distinguish pre-existing failures from ones your change introduced, and say which is which.

Only `UserFactory` exists in [database/factories/](database/factories/). Tests build every other record explicitly via `Model::create([...])` — follow that, don't add factories unless the mission needs them.

## Architecture facts that change how you implement

Laravel 12 / PHP 8.2, server-rendered Blade. No API layer — the only JSON endpoints are DataTables feeds, export status polls, notification/chat badge polls, and a few search/preview endpoints.

### Role partitioning is structural

Roles are a MySQL enum on `users`: `admin`, `purchasing`, `supplier`, `qc` (plus `is_active`). Checked by [RoleMiddleware](app/Http/Middleware/RoleMiddleware.php) with `role:a,b`. No permissions package.

The partition runs through the whole stack — put new work in the matching slot:

- Route group per role in [routes/web.php](routes/web.php): `->prefix('<role>')->name('<role>.')`. Cross-role routes list every role (`role:qc,purchasing`).
- Controllers namespaced per role: [Purchasing/](app/Http/Controllers/Purchasing/), `Supplier/`, `Qc/`, `Admin/`. Never one controller for several roles.
- Views under `resources/views/{purchasing,supplier,qc,admin}/`.
- Route names are `role.resource.action`. Post-login redirect resolves by role in [bootstrap/app.php](bootstrap/app.php).

### Supplier isolation

`quotations.supplier_id`, `purchase_orders.supplier_id`, and `material_claims.supplier_id` are FKs to **`users.id`**, not `suppliers.id` — `suppliers` is a profile table keyed by `user_id`. So supplier scoping is `->where('supplier_id', auth()->id())`, and every supplier-facing query needs it. Ownership checks on already-loaded models compare `(int) $model->supplier_id === (int) auth()->id()`. Covered by `SupplierDataIsolationTest` — run it after touching supplier queries.

### Hashids in URLs

Models using [HasHashids](app/Traits/HasHashids.php) (`PurchaseRequisition`, `Quotation`, `PurchaseOrder`, `QcInspection`, `MaterialClaim`, `Conversation`, `ExportJob`, `User`) return an encoded hash from `getRouteKey()`, so implicit binding decodes automatically and `resolveRouteBinding()` rejects plain integers.

Routes that take a raw `{id}`/`{pr_id}`/`{po_id}` instead of a bound model are handled by [DecodeHashids](app/Http/Middleware/DecodeHashids.php), driven by two allowlists:

- `HASHED_PARAM_KEYS` — param names that get decoded. **A new route with a new hashed param name must be added here**, or the controller receives a hash string where it expects an int.
- `PLAIN_ROUTE_PREFIXES` / `PLAIN_ROUTE_NAMES` — routes whose IDs stay plain integers (Attachment, Period, Notification, Announcement, ExchangeRate have no `HasHashids`).

Passing a plain integer to a hashed param `abort(404)`s deliberately. In views use `$model->hash` or pass the model to `route()` — never `$model->id` in a URL. For hashed values arriving as **query filters**, follow the `resolveSupplierFilter()` pattern in [Purchasing/ExportController.php](app/Http/Controllers/Purchasing/ExportController.php): reject digits, `resolveRouteBinding()`, then assert the expected role. `HashidUrlSecurityTest` guards this.

### Materials / HS code / weight pipeline

The densest logic in the app, deliberately out of controllers. Extend the services; do not inline this into a controller.

- [app/Services/Materials/](app/Services/Materials/): `PrItemProcessor` orchestrates `MaterialResolver` → `HsCodeResolver` → `MaterialWeightCalculator`; `PurchaseRequisitionItemSynchronizer` keeps PR items consistent; `HsCodeRuleConflictDetector` guards the admin rule editor.
- [app/Data/Materials/](app/Data/Materials/): immutable result objects (`ProcessedPrItemResult`, `HsCodeResolutionResult`, `WeightCalculationResult`) returned instead of arrays.
- Dimensions are shape-dependent: `PrItem::SHAPES`, `PrItem::DIMENSION_FIELDS`, `PrItem::relevantDimensionFields($shape)` decide which apply; irrelevant ones are nulled. `QuotationItem::sanitizeAvailabilityData()` applies the same rule to supplier input so a tampered request cannot persist off-shape dimensions. Keep that guarantee.
- Supplier-submitted `amount` is never trusted; it is recomputed by `QuotationItem::calculateAmount()`.

Tests: [tests/Unit/Materials/](tests/Unit/Materials/), `MaterialCalculationTest`, `PurchaseRequisitionMaterialAutomationTest`.

### Validation

Both styles exist and both are current: dedicated FormRequests in [app/Http/Requests/](app/Http/Requests/) for the material/HS-code/requisition paths, and inline `$request->validate([...])` in ~35 controllers elsewhere. Match the file you are editing rather than converting one style to the other.

### Async Excel exports

Exports never run inline. Controllers call [ExportDispatcher::dispatch()](app/Support/ExportDispatcher.php), which validates the class against a hardcoded allowlist, requires JSON-serializable scalar args (IDs and filter values, never models), creates an `ExportJob` row, and queues [ProcessExportJob](app/Jobs/ProcessExportJob.php) via `->onQueue('exports')`. The browser polls through [public/assets/js/async-export.js](public/assets/js/async-export.js); download goes through [ExportDownloadController](app/Http/Controllers/ExportDownloadController.php); files live on the `private` disk. **A new export class must be added to `SUPPORTED_EXPORT_CLASSES` or dispatch throws.** Without a running worker, jobs stay `queued` — cron setup in [README.md](README.md). `exports:cleanup` prunes after three days.

### Notifications and realtime

[NotificationService](app/Services/NotificationService.php) sends `SystemNotification` via `['database', 'broadcast']` using a **deterministic UUIDv5 id** from `User::class:{id}:{eventKey}`, so re-sending the same logical event is idempotent; `$replace` lets a newer notification supersede older ones. Preserve that keying. Categories are constrained to `chat`, `quotation`, `document`, `other` by [NotificationCategory](app/Support/NotificationCategory.php); anything else falls back to `other`. Links come from [NotificationUrlResolver](app/Services/NotificationUrlResolver.php); the navbar is fed by [NotificationSummaryService](app/Services/NotificationSummaryService.php) through a `View::composer('partials.navbar')` in [AppServiceProvider](app/Providers/AppServiceProvider.php).

The browser Echo client in [layouts/app.blade.php](resources/views/layouts/app.blade.php) is gated on `broadcasting.default === 'pusher'` **plus** a filled pusher key and cluster — it does not activate for the `reverb` connection even though `laravel/reverb` is installed and configured. The always-on delivery path is a 30-second `setInterval` poll of the unread-count endpoints. Treat polling as the baseline and realtime as an enhancement.

### Auth hardening

Beyond Breeze: 2FA (google2fa + recovery codes), Cloudflare Turnstile, per-identity rate limiters, session revocation, password-confirmation continuation, and an `auth_audit_logs` trail. Services in [app/Services/Auth/](app/Services/Auth/); middleware aliases `mfa.pending`, `password.confirm`, plus always-on `EnforceAuthSessionSecurity`, `AddSecurityHeaders`, `NoStoreResponse`. Password rules and named rate limiters (`auth.*`) are registered in [AuthSecurityServiceProvider](app/Providers/AuthSecurityServiceProvider.php), tuned by [config/auth_security.php](config/auth_security.php).

**Event auto-discovery is off** (`withEvents(discover: false)` in [bootstrap/app.php](bootstrap/app.php)) — a new listener must be registered explicitly via `Event::listen`. Providers are listed in [bootstrap/providers.php](bootstrap/providers.php).

Run [tests/Feature/Auth/](tests/Feature/Auth/) after any change in the auth path. Deployment notes: [docs/guides/AUTH-SECURITY-DEPLOYMENT.md](docs/guides/AUTH-SECURITY-DEPLOYMENT.md).

## Database safety

Schema changes are high-impact here: 48 migrations, historical data, a table rename already applied, soft deletes on legal documents, and reporting queries that join snapshot rows.

- Never guess structure. Read the relevant migration in [database/migrations/](database/migrations/) and the model's `$fillable` / `casts()` / relations before writing a query.
- Do not create a migration unless the mission requires a schema change. Prefer working within the existing schema.
- Soft deletes are on `purchase_requisitions`, `quotations`, `purchase_orders`, `qc_inspections`, `material_claims`, `announcements`. Queries must stay soft-delete aware; don't add hard deletes there.
- Never run destructive operations casually: no `migrate:fresh`, `migrate:rollback`, `db:wipe`, truncate, or bulk delete/update against a dev or production database without explicit user instruction. Destructive verification belongs in the test database.
- Consider existing rows: a new non-nullable column needs a default or a backfill, mirroring the backfill migrations already in the repo.
- Enum columns (`users.role`, currency columns) are altered with raw `DB::statement` and must include a working `down()`.
- File attachments go through the polymorphic `attachments` table on the `private` disk — never a new per-table file column, never `public/`.

## Frontend

Hybrid and mid-migration by design. Don't "clean up" one layer by breaking another.

- **Tailwind is prefixed `tw-`** with `preflight` disabled ([tailwind.config.js](tailwind.config.js)), because Bootstrap 5 (CDN) is still load-bearing for DataTables, modals, dropdowns, offcanvas, and `data-bs-*`. An unprefixed Tailwind class silently does nothing.
- CDN-loaded and not bundled: Bootstrap 5.3.3, jQuery 3.7.1, DataTables 1.13.6, SweetAlert2, Chart.js (per-page), Pusher + Echo. Vite bundles only `resources/css/app.css` and `resources/js/app.js` (Alpine + toast + shell).
- Design tokens are CSS custom properties (`--md-*`, `--ui-*`) in [resources/css/app.css](resources/css/app.css) `@layer base`, surfaced through the Tailwind theme. Add tokens there; no hex literals or arbitrary colors in Blade.
- Reuse [resources/views/components/ui/](resources/views/components/ui/) (`x-ui.button`, `x-ui.data-table`, `x-ui.page-header`, `x-ui.status-chip`, `x-ui.toolbar`, `x-ui.drawer`, …) before writing new markup, and before creating a new component.
- Icons only via `<x-ui.icon name="...">`, which maps legacy `bi-*` names onto Lucide and falls back to `circle-help`. Never `<x-lucide-*>` or `bi-*` directly.
- `window.AdasiToast` (defined in [resources/js/app.js](resources/js/app.js)) for transient feedback; `window.AdasiAlert` ([public/assets/js/adasi-alert.js](public/assets/js/adasi-alert.js)) only for blocking confirm/prompt.
- Status badges and labels come from [StatusHelper](app/Support/StatusHelper.php) — extend those arrays instead of adding `match()` blocks in views or controllers.
- List pages use **server-side** yajra DataTables (`DataTables::eloquent(...)->addColumn(...)->rawColumns([...])`). Classes emitted by those presenters are invisible to Tailwind's scanner — that's why `safelist` exists in the config; add there if you emit new server-rendered classes.

### UI judgment

Target: sharp, dense enterprise UI familiar to ERP operators. The binding rules are in `ADASI-UI-REDESIGN-PHASE2-MISSIONS/ADASI-UI-REDESIGN-PHASE2-MISSIONS/REDESIGN-PHASE2-GLOBAL-CONTRACT.md`; attach it plus the relevant mission file for Phase 2 UI work, and don't relocate those files.

Do not introduce: gradients, glassmorphism or decorative backdrop blur, large-radius floating cards, deep decorative shadows, KPI-card walls above operational tables, decorative badges or pills without semantic meaning, decorative icon circles, oversized hero/marketing sections, emoji, playful spring animation, pastel or rainbow palettes, or generic AI-SaaS dashboard compositions.

Do prefer: typography-led hierarchy, borders before shadows, small radii, compact desktop-first density, high-frequency filters visible with secondary filters behind "More filters", visible primary row action with secondary actions in an overflow menu, sectioned forms with a sticky action bar, semantic color only for real state, accessible focus/hover/disabled states, and layouts that stay usable on tablet and mobile.

Never trade working business functionality for visual improvement. A redesign preserves behavior, data contracts, and workflow familiarity.

## Dependencies

Don't add a package unless existing capabilities are genuinely insufficient, it directly serves the mission, and you have considered integration impact (bundle, CDN vs Vite, provider registration in [bootstrap/providers.php](bootstrap/providers.php), config publishing). Prefer what's already here: Laravel Excel, dompdf, yajra DataTables, hashids, google2fa, blade-lucide-icons, Alpine, Chart.js, SweetAlert2. Pin exact versions if a package is truly needed, and say why.

## Documentation layout

Root holds only `README.md`, `AGENTS.md`, and this file. Non-UI work goes in [docs/audits/](docs/audits/), [docs/guides/](docs/guides/), [docs/plans/](docs/plans/), [docs/results/](docs/results/); UI reports and checkpoints in [UI-REDESIGN-RESULT/](UI-REDESIGN-RESULT/). Don't duplicate a report between root and its destination, and update relative paths after moving files.

## Language

Code, comments, UI copy, and route/variable names are English. Some domain docs, test docblocks, migration comments, and export labels are Indonesian — match the surrounding file rather than normalizing it.
