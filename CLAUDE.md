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

## The app is two cores, not one

This started as an **import procurement** portal and now carries a second, independently-shaped domain bolted onto the same auth/UI shell:

- **Core 1 — Import procurement:** Period → PR → supplier invitation → Quotation → PO consolidation → Shipment → QC inspection → Material claim. Foreign-currency, exchange-rate snapshots, HS codes, weight math.
- **Core 2 — Local supplier & disbursement:** Local PO/GR master → local supplier Invoice submission → cashier physical reception → two-section tax verification → DRP payment batch → Voucher Bayar → settlement. IDR-only, Indonesian tax rules (NSFP, PPN, PPh), bank transfer execution. GA employee reimbursement claims feed the same payment engine.

The two cores share `users`, notifications, exports, the design system, and little else. **Before touching Core 2, read [context.md](context.md)** — it is the canonical, current architecture/domain contract for the local supplier, GA claim, and unified payment engine (state machines, formulas, invariants). It is far more specific than this file and is kept up to date. This file covers repo conventions; `context.md` covers domain rules.

## AGENTS.md and its stale sections

[AGENTS.md](AGENTS.md) is the *original* domain contract (Indonesian) for Core 1: roles, workflow order, upload rules, prohibited patterns. Read it for import procurement. It says nothing about Core 2. It has also **drifted from the schema** — where they disagree, the code wins:

| AGENTS.md says | Repository actually has |
|---|---|
| `purchase_requirements` table | renamed to `purchase_requisitions` (`2026_06_08_160000`), model `PurchaseRequisition` |
| currency `USD\|JPY` | enum `USD, JPY, IDR, CNY` ([ExchangeRate::CURRENCIES](app/Models/ExchangeRate.php)) |
| PR/PO number via `count() + 1` | atomic `document_sequences` table, `lockForUpdate()` inside a transaction — use `PurchaseRequisition::generatePrNumber()` / `PurchaseOrder::generatePoNumber()`, never re-derive |
| `IDR = price_per_kg × weight_needed × rate` | amount uses `PrItem::total_weight` (`weight_needed × quantity_value`) via [QuotationItem::calculateAmount()](app/Models/QuotationItem.php); IDR conversion multiplies by the rate |
| always take the latest rate | quotations/POs store `exchange_rate_id`; historical comparisons join that snapshot, not the current rate. `ExchangeRate::latestRate()` is cached 60 min and invalidated on insert |
| `purchase_orders.quotation_id` (one PO per quotation) | consolidated: `po_quotations` pivot (`PurchaseOrder::quotations()` is `belongsToMany`), with `supplier_id`, `currency`, `exchange_rate_id` denormalized onto `purchase_orders` (`2026_05_22_000001`) |
| roles `admin\|purchasing\|supplier\|qc` | plus `finance` and `ga`; `accounting` is a retained legacy alias (`2026_09_11_000001`) — see "Role partitioning" below |
| one flat supplier population | suppliers are partitioned by `supplier_scopes` (`import` / `local`); the two populations see different portals |

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
php artisan schedule:run     # drives export cleanup + local invoice delivery reminders
php artisan optimize:clear

node --test tests/js/calendar.test.mjs   # calendar-core.js unit tests; pass the FILE, not the dir (dir glob fails on Windows)

php artisan local-invoices:reconcile          # read-only integrity report: local PO/GR/invoice/settlement/refund anomalies
php artisan local-invoices:reconcile --json
```

`local-invoices:reconcile` is the fastest way to confirm a Core 2 change did not break a payment invariant — it is read-only and safe to run against dev data. Run it after touching local PO/GR, invoice settlement, voucher, or refund code.

## Verification

Run the smallest relevant check first, then widen:

1. `php artisan test --filter=<TestName>` for the tests covering what you touched.
2. The suite for that area:
   - auth → `tests/Feature/Auth/`
   - materials/HS code → `tests/Unit/Materials/`, `MaterialCalculationTest`
   - supplier isolation → `SupplierDataIsolationTest`, `LocalInvoiceScopeIsolationTest`
   - hashid URLs → `HashidUrlSecurityTest`
   - local invoice lifecycle → `tests/Feature/LocalInvoice/`, `LocalInvoiceSubmissionV2Test`, `FinanceVerificationV2Test`, `CashierReceiptAndExpiryTest`
   - payment engine → `UnifiedPaymentEngineTest`, `FinanceDrpPaidTest`, `PaymentForecastAndReportingTest`
   - GA / vendor master → `GaClaimWorkflowTest`, `VendorMasterV2Test`, `VendorMasterViewsTest`
3. `composer test` before declaring a cross-cutting change done. The full suite takes **~5 minutes**.
4. `npm run build` after touching Blade classes, `resources/css/app.css`, `resources/js/app.js`, or [tailwind.config.js](tailwind.config.js).

Tests run against a **real MySQL database** named `adasi_portal_test` ([phpunit.xml](phpunit.xml)) — not SQLite. It must exist locally, and most feature tests use `RefreshDatabase`.

**Known pre-existing baseline (verified 2026-09-17 on `local-supplier-update`):** `713 passed, 2 failed` (6054 assertions, ~317s). The two failures are pre-existing and **not** your regression:

- `Tests\Feature\LocalInvoice\LocalInvoiceScopeIsolationTest > admin create and edit user renders general supplier label and conditional scopes`
- `Tests\Feature\PriceComparisonItemAwardTest > comparison view displays item award selection and coverage`

Both are view-assertion failures (expected copy no longer matches rendered markup). `phpunit-results.log` and `test-results.log` at the repo root are **stale snapshots from an older tree** — do not use them as the baseline. Always distinguish pre-existing failures from ones your change introduced, and say which is which.

A few tests spawn real worker subprocesses through [tests/Support/](tests/Support/) (`local-gr-reservation-worker.php`, `local-invoice-concurrency-worker.php`) to prove row-lock behaviour under concurrency. They are slow and genuinely exercise MySQL locking — don't "simplify" them into single-process tests.

Only `UserFactory` exists in [database/factories/](database/factories/). Tests build every other record explicitly via `Model::create([...])` — follow that, don't add factories unless the mission needs them.

## Architecture facts that change how you implement

Laravel 12 / PHP 8.2, server-rendered Blade. No API layer — the only JSON endpoints are DataTables feeds, export status polls, notification/chat badge polls, and a few search/preview endpoints.

### Role partitioning is structural

Roles are a MySQL enum on `users` (plus `is_active`), checked by [RoleMiddleware](app/Http/Middleware/RoleMiddleware.php) with `role:a,b`. No permissions package.

`admin`, `purchasing`, `supplier`, `qc`, `accounting`, `finance`, `ga`

`accounting` is **legacy**: `2026_09_11_000001` migrated every existing accounting user to `finance` and kept the enum value only for compatibility. It is still assignable in the admin user form and still appears in `role:` lists, and `User::isLocalOperator()` returns true for both. Write new work against `finance`; keep `accounting` accepted wherever it already is.

`routes/web.php` `require`s four sibling files at the top — put new routes in the matching one, not in `web.php`:

| File | Group |
|---|---|
| [routes/web.php](routes/web.php) | admin, purchasing, supplier (import), qc, shared (profile, attachments, exports, notifications, conversations, shared PDF) |
| [routes/supplier-local.php](routes/supplier-local.php) | `local-supplier.*`, supplier context switcher, the three signed document controllers |
| [routes/finance.php](routes/finance.php) | `finance.*` — invoice verification, DRP, vouchers, settlements, local procurement, vendor master |
| [routes/accounting.php](routes/accounting.php) | `accounting.*` — legacy read-oriented invoice views |
| [routes/ga.php](routes/ga.php) | `ga.*` — employee master, claims, GA DRP draft |

The partition runs through the whole stack — put new work in the matching slot:

- Route group per role: `->prefix('<role>')->name('<role>.')`. Cross-role routes list every role (`role:qc,purchasing`).
- Controllers namespaced per role: `Purchasing/`, `Supplier/`, `LocalSupplier/`, `Qc/`, `Admin/`, `Finance/`, `Accounting/`, `Ga/`.
- Views under `resources/views/{purchasing,supplier,local-supplier,qc,admin,finance,accounting,ga}/`.
- Route names are `role.resource.action`. Post-login redirect goes through `PortalContext::dashboard()` in [bootstrap/app.php](bootstrap/app.php), not a flat role match.

**Two deliberate exceptions exist in Core 2 — follow them, don't "fix" them:**

- `resources/views/local-invoices/` is a **shared, role-neutral** view namespace (`detail`, `table`, `filters`, `form`, `scripts`, `partials/_documents_grid`). Local-supplier, accounting, finance, and purchasing views all `@include` it so one invoice renders identically to every audience. Changing it affects four portals at once.
- `Finance\LocalProcurementController` is mounted under **both** `finance.local-procurement.*` and `purchasing.local-procurement.*`. Local PO/GR is one master dataset maintained by two departments; it was not duplicated.

### Supplier isolation and supplier scopes

`supplier_id` on `quotations`, `purchase_orders`, `material_claims`, `local_invoices`, and `purchase_requisition_suppliers` is an FK to **`users.id`**, not `suppliers.id` — `suppliers` is a profile table keyed by `user_id`. So supplier scoping is `->where('supplier_id', auth()->id())`, and every supplier-facing query needs it. Ownership checks on already-loaded models compare `(int) $model->supplier_id === (int) auth()->id()`. Covered by `SupplierDataIsolationTest` and `LocalInvoiceScopeIsolationTest`.

On top of that, a supplier user is partitioned into one or both **scopes** via the `supplier_scopes` table (`import` / `local`), which decides which portal they get:

- `supplier.scope:import` / `supplier.scope:local` ([SupplierScopeMiddleware](app/Http/Middleware/SupplierScopeMiddleware.php)) gate the `supplier.*` and `local-supplier.*` route groups, and **write the resolved scope into `session('supplier_context')`** as a side effect.
- [PortalContext](app/Support/PortalContext.php) resolves the post-login landing page. A dual-scope supplier is sent to `supplier-context.index` to choose; a single-scope supplier goes straight through. `PortalContext::isLocal()` is what views (notably [partials/sidebar.blade.php](resources/views/partials/sidebar.blade.php)) use to decide which menu to render.
- [EnforceSupplierDomain](app/Http/Middleware/EnforceSupplierDomain.php) is **always on** in the web stack and blocks cross-core leakage: local-only suppliers cannot reach `exports.*`; finance/accounting users cannot reach `attachments.*`, `conversations.*`, or `shared.pdf.*`; import-shell endpoints require the `import` scope.
- Query helpers: `User::scopeImportEligible()` / `scopeLocalEligible()`, and `isImportEligible()` / `isLocalEligible()` / `hasSupplierScope()`. Use these instead of re-deriving `where('role', 'supplier')`.

### Authorization: policies in Core 2, inline checks in Core 1

Core 1 controllers do inline role/ownership checks. Core 2 uses **Laravel's auto-discovered policies** — there is no `$policies` array and no `Gate::policy()` call, so a policy must be named `App\Policies\<Model>Policy` to bind. Nine exist ([app/Policies/](app/Policies/)), including `LocalInvoicePolicy`, `LocalInvoiceDocumentPolicy`, `LocalInvoiceReceiptPolicy`, `GaClaimDocumentPolicy`, `SupplierMasterDocumentPolicy`. Controllers call `Gate::authorize('view', $invoice)`. When you add a Core 2 action, add the policy method rather than an inline `abort_unless`.

### Local supplier & payment engine (Core 2)

Read [context.md](context.md) for the full state machines and formulas. The parts that most often get broken by a naive edit:

- **Services own the transitions, not controllers.** [app/Services/LocalInvoice/](app/Services/LocalInvoice/) (submission, verification, physical receipt, expiry, workflow, GR reservation, PO/GR master, import) and [app/Services/Payment/](app/Services/Payment/) (batch, voucher, execution, forecast, overpayment). Controllers validate and delegate.
- **GR reservation is a state machine with row locks.** `LocalGoodsReceipt` is `AVAILABLE → RESERVED → INVOICED`, with release back to `AVAILABLE` on rejection/cancellation/expiry. `LocalGrReservationService` does this inside `lockForUpdate()` transactions and writes a `LocalFinanceAuditLog` entry for every transition. Settlement is **whole-GR only** — there is no partial allocation, and the sum of selected GR amounts must exactly equal invoice DPP.
- **Payment container is `payment_batches → payment_groups → payment_items`**, with `PaymentItem` polymorphic (`payable` = `LocalInvoice` or `GaClaim`). One invoice gets one Voucher Bayar and one settlement; a short transfer is a *correction event on the same settlement*, never a second settlement. An overpayment becomes a separate `SupplierOverpaymentRefund` receivable.
- **`LocalPoProviderInterface` is bound in [AppServiceProvider](app/Providers/AppServiceProvider.php)** to `DatabaseLocalPoProvider`; `FakeLocalPoProvider` exists for tests. This is the one intentional interface indirection in Core 2.
- Status strings are `UPPER_SNAKE` consts on the models (`LocalInvoice::STATUS_*`, `PaymentBatch::STATUS_*`, `LocalGoodsReceipt::STATUS_*`, `LocalPurchaseOrder::STATUS_*`). Use the consts; several legacy values (`APPROVED`, `PAYMENT_SCHEDULED`, `COMPLETED`, `UNDER_REVIEW`) are still accepted in queries for historical rows.
- Local invoice list/filter logic is centralized in `LocalInvoice\InvoiceQuery::filtered()`, shared by the supplier, accounting, and finance indexes. Add filters there, not per-controller.

### Hashids in URLs

Models using [HasHashids](app/Traits/HasHashids.php) return an encoded hash from `getRouteKey()`, so implicit binding decodes automatically and `resolveRouteBinding()` rejects plain integers. All 22 of them (`grep -rl "App.Traits.HasHashids" app/Models/` to re-check):

> Core 1 — `PurchaseRequisition`, `Quotation`, `PurchaseOrder`, `PrItemAward`, `Shipment`, `QcInspection`, `MaterialClaim`, `Conversation`, `ExportJob`, `User`
> Core 2 — `LocalInvoice`, `LocalInvoiceDocument`, `LocalInvoicePayment`, `LocalInvoiceVoucher`, `LocalPurchaseOrder`, `LocalGoodsReceipt`, `PaymentBatch`, `PaymentGroup`, `SupplierOverpaymentRefund`, `SupplierMasterDocument`, `GaClaim`, `GaClaimDocument`

Routes that take a raw `{id}`/`{pr_id}`/`{po_id}` instead of a bound model are handled by [DecodeHashids](app/Http/Middleware/DecodeHashids.php), driven by two allowlists:

- `HASHED_PARAM_KEYS` — param names that get decoded. **A new route with a new hashed param name must be added here**, or the controller receives a hash string where it expects an int. Note this list now also covers Core 2 *implicit-binding* param names (`invoice`, `document`, `purchaseOrder`, `goodsReceipt`, `voucher`, `payment`, `refund`, `batch`) — they are listed not because the controller needs an int, but so a crafted raw-integer URL 404s before it can reach model binding.
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

Exports never run inline. Controllers call [ExportDispatcher::dispatch()](app/Support/ExportDispatcher.php), which validates the class against a hardcoded allowlist, requires JSON-serializable scalar args (IDs and filter values, never models), creates an `ExportJob` row, and queues [ProcessExportJob](app/Jobs/ProcessExportJob.php) via `->onQueue('exports')`. The browser polls through [public/assets/js/async-export.js](public/assets/js/async-export.js); download goes through [ExportDownloadController](app/Http/Controllers/ExportDownloadController.php); files live on the `private` disk. **A new export class must be added to `SUPPORTED_EXPORT_CLASSES` or dispatch throws.** Without a running worker, jobs stay `queued` — cron setup in [README.md](README.md).

Three jobs run on the scheduler ([routes/console.php](routes/console.php)), so a second cron entry for `schedule:run` is also required in production: `model:prune` on `AuthAuditLog` (02:10), `exports:cleanup` (02:20, three-day retention), and `local-invoices:send-delivery-reminders` (08:00, which nudges local suppliers toward their Wednesday physical-delivery slot and drives the missed-delivery → `EXPIRED` path).

### Notifications and realtime

[NotificationService](app/Services/NotificationService.php) sends `SystemNotification` via `['database', 'broadcast']` using a **deterministic UUIDv5 id** from `User::class:{id}:{eventKey}`, so re-sending the same logical event is idempotent; `$replace` lets a newer notification supersede older ones. Preserve that keying. Categories are constrained to `chat`, `quotation`, `document`, `other` by [NotificationCategory](app/Support/NotificationCategory.php); anything else falls back to `other`. Links come from [NotificationUrlResolver](app/Services/NotificationUrlResolver.php). The navbar dropdown is **lazy** — [partials/navbar.blade.php](resources/views/partials/navbar.blade.php) fetches `notifications.summary` on first open and caches it in a `data-notification-summary-state` attribute; [NotificationSummaryService](app/Services/NotificationSummaryService.php) backs that endpoint. There is no view composer for it. The only `View::composer` in [AppServiceProvider](app/Providers/AppServiceProvider.php) supplies the local-supplier dropdown to `accounting.invoices.index` / `accounting.reports.index`.

The browser Echo client in [layouts/app.blade.php](resources/views/layouts/app.blade.php) is gated on `broadcasting.default === 'pusher'` **plus** a filled pusher key and cluster — it does not activate for the `reverb` connection even though `laravel/reverb` is installed and configured. The always-on delivery path is a 30-second `setInterval` poll of the unread-count endpoints. Treat polling as the baseline and realtime as an enhancement.

### Auth hardening

Beyond Breeze: 2FA (google2fa + recovery codes), Cloudflare Turnstile, per-identity rate limiters, session revocation, password-confirmation continuation, and an `auth_audit_logs` trail. Services in [app/Services/Auth/](app/Services/Auth/); middleware aliases `mfa.pending`, `password.confirm`, plus always-on `EnforceAuthSessionSecurity`, `AddSecurityHeaders`, `NoStoreResponse`. Password rules and named rate limiters (`auth.*`) are registered in [AuthSecurityServiceProvider](app/Providers/AuthSecurityServiceProvider.php), tuned by [config/auth_security.php](config/auth_security.php).

**Event auto-discovery is off** (`withEvents(discover: false)` in [bootstrap/app.php](bootstrap/app.php)) — a new listener must be registered explicitly via `Event::listen`. Providers are listed in [bootstrap/providers.php](bootstrap/providers.php).

Run [tests/Feature/Auth/](tests/Feature/Auth/) after any change in the auth path. Deployment notes: [docs/guides/AUTH-SECURITY-DEPLOYMENT.md](docs/guides/AUTH-SECURITY-DEPLOYMENT.md).

## Database safety

Schema changes are high-impact here: 76 migrations, historical data, a table rename already applied, a role-enum rewrite, soft deletes on legal documents, and reporting queries that join snapshot rows.

- Never guess structure. Read the relevant migration in [database/migrations/](database/migrations/) and the model's `$fillable` / `casts()` / relations before writing a query.
- Do not create a migration unless the mission requires a schema change. Prefer working within the existing schema.
- Soft deletes are on `purchase_requisitions`, `quotations`, `purchase_orders`, `qc_inspections`, `material_claims`, `announcements`. Queries must stay soft-delete aware; don't add hard deletes there.
- Never run destructive operations casually: no `migrate:fresh`, `migrate:rollback`, `db:wipe`, truncate, or bulk delete/update against a dev or production database without explicit user instruction. Destructive verification belongs in the test database.
- Consider existing rows: a new non-nullable column needs a default or a backfill, mirroring the backfill migrations already in the repo.
- Enum columns (`users.role`, currency columns, Core 2 status columns) are altered with raw `DB::statement` and must include a working `down()`. Some `down()` methods deliberately `throw` when data would be lost (see `2026_09_08_000001`) — keep that guard rather than silently dropping rows.
- Core 2 migrations lean on **composite foreign keys and unique constraints as business invariants** (e.g. `local_invoice_documents` keys on `[revision_id, invoice_id]`; `2026_09_14_000001_harden_local_invoice_and_payment_invariants`). These are load-bearing, not decoration — do not drop one to make an insert pass.
- File storage depends on the core:
  - **Core 1** uses the polymorphic `attachments` table on the `private` disk — never a new per-table file column, never `public/`.
  - **Core 2** uses dedicated per-domain tables — `local_invoice_documents`, `ga_claim_documents`, `supplier_master_documents` — each with its own controller and policy, also on the `private` disk. They carry per-document metadata (`document_type`, revision linkage, uploader) that the generic `attachments` row cannot express. Follow whichever the module you're in already uses; never `public/`.

## Frontend

Hybrid and mid-migration by design. Don't "clean up" one layer by breaking another.

- **Tailwind is prefixed `tw-`** with `preflight` disabled ([tailwind.config.js](tailwind.config.js)), because Bootstrap 5 (CDN) is still load-bearing for DataTables, modals, dropdowns, offcanvas, and `data-bs-*`. An unprefixed Tailwind class silently does nothing.
- CDN-loaded and not bundled: Bootstrap 5.3.3, jQuery 3.7.1, DataTables 1.13.6, SweetAlert2, Chart.js (per-page), Pusher + Echo. Vite bundles only `resources/css/app.css` and `resources/js/app.js` (Alpine + toast + shell).
- Design tokens are CSS custom properties (`--md-*`, `--ui-*`) in [resources/css/app.css](resources/css/app.css) `@layer base`, surfaced through the Tailwind theme. Add tokens there; no hex literals or arbitrary colors in Blade.
- Reuse [resources/views/components/ui/](resources/views/components/ui/) (`x-ui.button`, `x-ui.data-table`, `x-ui.page-header`, `x-ui.status-chip`, `x-ui.toolbar`, `x-ui.drawer`, …) before writing new markup, and before creating a new component.
- **Date Pickers & Calendars:** Always use the custom design system calendar components `<x-ui.date-picker>` (for single date) and `<x-ui.date-range-picker>` (for date ranges). **NEVER** hand-write a browser-native `<input type="date">` in any form, modal, or filter (inconsistent across browsers/OS and breaks the design system). Always provide a unique `id` attribute when using inside loops or modals. The components themselves *do* render a native `type="date"` input as the progressive-enhancement base, then upgrade it in [resources/js/calendar.js](resources/js/calendar.js) — that is intentional, and `CalendarComponentTest` asserts it. Date math lives in [resources/js/calendar-core.js](resources/js/calendar-core.js), which is unit-tested by `node --test tests/js/calendar.test.mjs`; keep those pure helpers free of DOM access. `<x-ui.date-picker allowed-days-of-week>` is what enforces the Wednesday-only physical delivery rule in Core 2.
- Icons only via `<x-ui.icon name="...">`, which maps legacy `bi-*` names onto Lucide and falls back to `circle-help`. Never `<x-lucide-*>` or `bi-*` directly.
- `window.AdasiToast` (defined in [resources/js/app.js](resources/js/app.js)) for transient feedback; `window.AdasiAlert` ([public/assets/js/adasi-alert.js](public/assets/js/adasi-alert.js)) only for blocking confirm/prompt.
- Status badges and labels come from [StatusHelper](app/Support/StatusHelper.php) — extend those arrays/`match()` blocks instead of adding new ones in views or controllers. Core 1 helpers return Bootstrap badge classes (`prBadge()`); Core 2 helpers return design-system *tones* consumed by `x-ui.status-chip` (`localInvoiceTone()`, `localFinanceTone()`) and Indonesian labels (`localInvoiceLabel()`). Match the pair used by the module you're in.
- List pages use **server-side** yajra DataTables (`DataTables::eloquent(...)->addColumn(...)->rawColumns([...])`). Classes emitted by those presenters are invisible to Tailwind's scanner — that's why `safelist` exists in the config; add there if you emit new server-rendered classes.

### UI judgment

Target: sharp, dense enterprise UI familiar to ERP operators. The binding rules are in `ADASI-UI-REDESIGN-PHASE2-MISSIONS/ADASI-UI-REDESIGN-PHASE2-MISSIONS/REDESIGN-PHASE2-GLOBAL-CONTRACT.md`; attach it plus the relevant mission file for Phase 2 UI work, and don't relocate those files.

Do not introduce: gradients, glassmorphism or decorative backdrop blur, large-radius floating cards, deep decorative shadows, KPI-card walls above operational tables, decorative badges or pills without semantic meaning, decorative icon circles, oversized hero/marketing sections, emoji, playful spring animation, pastel or rainbow palettes, or generic AI-SaaS dashboard compositions.

Do prefer: typography-led hierarchy, borders before shadows, small radii, compact desktop-first density, high-frequency filters visible with secondary filters behind "More filters", visible primary row action with secondary actions in an overflow menu, sectioned forms with a sticky action bar, semantic color only for real state, accessible focus/hover/disabled states, and layouts that stay usable on tablet and mobile.

Never trade working business functionality for visual improvement. A redesign preserves behavior, data contracts, and workflow familiarity.

## Dependencies

Don't add a package unless existing capabilities are genuinely insufficient, it directly serves the mission, and you have considered integration impact (bundle, CDN vs Vite, provider registration in [bootstrap/providers.php](bootstrap/providers.php), config publishing). Prefer what's already here: Laravel Excel, dompdf, yajra DataTables, hashids, google2fa, blade-lucide-icons, Alpine, Chart.js, SweetAlert2, and `cally` (dynamically `import()`ed inside `calendar.js`, so it stays out of the main bundle — preserve that lazy import). Pin exact versions if a package is truly needed, and say why.

## Documentation layout

New docs you write go in [docs/audits/](docs/audits/), [docs/guides/](docs/guides/), [docs/plans/](docs/plans/), [docs/results/](docs/results/); UI reports and checkpoints in [UI-REDESIGN-RESULT/](UI-REDESIGN-RESULT/). **Do not add new `.md` files to the repository root.**

The root currently also holds `context.md` (canonical, keep it there) plus roughly a dozen historical implementation-plan and mission files that predate this rule. They are referenced by past work — leave them where they are. Don't bulk-relocate them as a side effect of a code task, and don't duplicate a report between root and `docs/`. Update relative paths if you do move a file deliberately.

## Language

Code, comments, UI copy, and route/variable names are English. Some domain docs, test docblocks, migration comments, and export labels are Indonesian — match the surrounding file rather than normalizing it.
