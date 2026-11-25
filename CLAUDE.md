# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

@AGENTS.md

## Maintenance rule

[AGENTS.md](AGENTS.md), imported above, is the single source of project facts and wins on any conflict. The quick reference below is an English pointer summary for fast orientation: no new facts, no counts or test baselines, links instead of copies. When a fact changes, edit `AGENTS.md` first, then fix the matching line here. Code, migrations, and tests beat both; report discrepancies rather than copying them forward.

## Quick reference

### Stack & commands
- PHP 8.2, Laravel 12, MySQL (Laragon). Blade + Tailwind 4 with the `tw:` prefix + ADASI `x-ui.*` / `.ui-*` design system, Alpine 3.4.2, Vite 7, DataTables 3.1.5 (zero jQuery, zero Bootstrap). Server-rendered; no API layer.
- Install: `composer install` and `npm.cmd install`. Do not use `composer setup` on an existing DB (it runs `migrate --force`).
- Run: `composer dev` (serve + `queue:listen --queue=exports,default` + `queue:listen --queue=imports` + pail + vite).
- Test: `composer test` (Unit+Feature, serial, several minutes), `composer test:parallel` (Parallel suite in 4 processes, then Serial, as CI `deep` does), `php artisan test --filter=Name`, `node --test tests/js/<file>.test.mjs`. CI (`.github/workflows/tests.yml`) runs a regression subset, the core workflow suites, JS tests and the build on PRs and pushes to `master`, and the full suite on MySQL 8.0 and MariaDB 11.4 on `master`, nightly and on demand (AGENTS.md, Workflow Verifikasi).
- Lint: `vendor/bin/pint --test <files>` (Laravel preset). There is no PHPStan/Larastan.
- Build: `npm.cmd run build` after Blade class, CSS, or JS changes. Page-specific JS modules are dynamic imports gated by DOM markers in `resources/js/app.js`, not static imports (AGENTS.md, Panduan UI).
- Seed: `DatabaseSeeder` (calls `LocalInvoiceSampleSeeder`). Accounts and the full check flow are in the `/verify` skill.

### Architecture map
- `routes/web.php`: all HTTP routes, grouped by `role:` and `supplier.scope:`; names are `role.resource.action`.
- `app/Http/Controllers/<Role>/`: Purchasing, Supplier, Qc, Admin, Finance, LocalSupplier, Ga, Accounting, plus `Auth/`; shared document controllers at the root.
- `app/Http/Middleware/`: RBAC, scope, hashids, domain guards, auth splash (`ShareAuthSplashState`), workspace shell (`WrapWorkspaceShell`) ([CLAUDE.md](app/Http/Middleware/CLAUDE.md)).
- `app/Services/`: state transitions and transactions (Materials, Pricing, LocalInvoice, Payment, Ga, VendorMaster, SupplierAudit, FileSecurity, Auth, Dashboard), plus root services such as `PrItemAwardService`, `PurchaseOrderGenerationService`, `ShipmentService`, `PoShipmentDocumentStatusService`.
- `app/Support/`: `BusinessTime`, `Money`, `PortalContext`, `StatusHelper`, `ExportDispatcher`, `JsTranslations`, `HashidCodec`, `PortalPath`, `PageRegistry`, `PortalContextCodec`, `PortalSessionAuthorizer`, `WorkspaceShell`; `Procurement/` holds `OrderedLine`, `LogisticsTerms`, `ProcurementFeatures`.
- `app/Policies/`: auto-discovered `<Model>Policy`; there is no `$policies` array.
- `app/Exports/`, `app/Jobs/`: async export pipeline ([CLAUDE.md](app/Exports/CLAUDE.md)).
- `resources/views/components/ui/`: `x-ui.*` design system ([CLAUDE.md](resources/views/components/ui/CLAUDE.md)). `layouts/workspace-shell.blade.php` and `partials/auth-splash-bootstrap.blade.php` host the multi-tab workspace shell and inline post-login splash bootstrap (`config/ui.php`).
- `lang/{en,id}/`: paired translations. `resources/css/app.css`: `--md-*` / `--ui-*` tokens.
- `tests/Feature`, `tests/Unit` (PHPUnit on MySQL `adasi_portal_test`), `tests/js` (Node test runner).

### Domain flow (Import core, English-first)
1. Period and PR: `Purchasing\PeriodController`, `PurchaseRequisitionController` → `PurchaseRequisition`, `PrItem`.
2. Quotation: `Supplier\QuotationController` (server recomputes amounts) → `Quotation`, `QuotationItem`; reviewed in `Purchasing\QuotationListController` and `PriceComparisonController`.
3. Award and PO: `PriceComparisonController`, `AwardConsolidationController`, `PurchaseOrderController` → `PrItemAward`, `PurchaseOrder` (pivot `po_quotations`) through `PrItemAwardService` and `PurchaseOrderGenerationService`. Read PO qty/KG/amount from `PurchaseOrder::orderedLines()`; PR logistics terms (port of discharge, incoterm) are copied to the PO (AGENTS.md, Item Award dan Shipment Impor).
4. Shipment: `Purchasing\ShipmentController`, `Supplier\SupplierShipmentController` → `Shipment` through `ShipmentService`.
5. QC: `Qc\QcInspectionController` → `QcInspection` (OK/NG).
6. Claims: `Purchasing\MaterialClaimController`, `Supplier\ClaimController` → `MaterialClaim`.
- Dashboards: `PurchasingController@dashboard`, `SupplierController@dashboard`, `Qc\DashboardController`, `Finance\FinanceDashboardController`.
- Local core (Indonesian-first): Local PO/GR → invoice → verification → DRP → voucher → settlement; GA claims share the payment engine. See AGENTS.md (Local Supplier, GA) and [context.md](context.md).

### Critical conventions
- Supplier ownership: `supplier_id` is `users.id`, not `suppliers.id`. Scope supplier-facing queries with `->where('supplier_id', auth()->id())` or the model's scope/policy.
- Dual scope: `supplier_scopes` grants `import` and/or `local`. `supplier.scope:<scope>` (`SupplierScopeMiddleware`) returns 403 without the scope and writes `session('supplier_context')`. `PortalContext` picks the landing dashboard and menu; dual-scope users choose at `supplier-context.index`.
- Hashids: models using `App\Traits\HasHashids` route by hash. In views pass the model or `$model->hash`, never `->id`. `DecodeHashids` decodes only keys in `HASHED_PARAM_KEYS` and returns 404 for raw integers, so register new parameter names there.
- `EnforceSupplierDomain` (web group) blocks cross-core shared endpoints. Local operators get 403 on `attachments.*`, `conversations.*`, and `shared.pdf.*`. Suppliers without `import` scope get 403 there and on `supplier.*` and `exports.*`.
- `RoleMiddleware` checks an explicit role list; admin is not a superuser.
- UI: read [DESIGN.md](DESIGN.md) before UI work (tokens, density, accent, `x-ui.*` contracts, page patterns, motion/splash/workspace shell, known drift). M3-style tokens (`--md-*`, `--ui-*`) mapped to `tw:*` colors in `tailwind.config.js`; no raw hex. Icons only through `<x-ui.icon>` (Lucide; legacy `bi-*` names are aliased, so never write `bi bi-*` classes). Charts go in `<x-ui.chart>` and take colors from `window.AdasiChart` (AGENTS.md, Panduan UI; DESIGN.md §7). Page transitions use `--ui-page-fade-duration` (500ms opacity-only `.ui-page-fade` / `workspace-panel-enter`), and `#adasiLoader` uses `SHOW_DELAY = 2500ms` (`adasi-loader.js`).
- Auth splash & workspace shell: `config/ui.php` defaults `auth_splash` and `workspace_shell` to enabled (`phpunit.xml` forces both `false` for general feature suites). Login/2FA forms send `X-Auth-Splash-Client` so valid logins resolve straight to role dashboards and show `logo-adasi.gif` immediately (`ShareAuthSplashState` + `CompleteLoginService`); `WrapWorkspaceShell` serves initial iframe loads from a 15s single-use prerender cache with fresh `CspNonce` substitution, while tab clicks stage hidden frames before switching. Opt-in Infor-style context URLs (`PORTAL_CONTEXT_URLS_ENABLED=false` in `.env.example`/`phpunit.xml`, `config/portal_sessions.php`) project/resolve `/v2/ADASI_PRD/{appUuid}?favoriteContext=...&LogicalId=...` across `PortalContextCodec`, `PortalSessionAuthorizer`, `PortalPageController`, and `resources/js/portal-context-url.js` while keeping 72 `page_registry` UUIDs and legacy hashes compatible (`AGENTS.md`, Navigasi, Panduan UI, Auth dan Konfigurasi).
- Comparison performance: `ReferencePriceResolver` is request-scoped with `preload()`, `InterSupplierComparisonController` memoizes per-item history summaries, and `VsBestPriceComparisonController` caches filtered summaries for 60s (`AGENTS.md`, Modul Perbandingan Harga).
- Language: locale is per user (`en`/`id`), and every new string needs a key in both `lang/en` and `lang/id` (`TranslationParityTest`). There is no LANGUAGE.md; the policy is "UI Language Policy" at the end of AGENTS.md.
- Exports are async: `ExportDispatcher::dispatch()` creates an `ExportJob`, and `ProcessExportJob` runs it on queue `exports`, so a worker must run. Import templates download directly.
- Dates go through `App\Support\BusinessTime` (UTC storage, Asia/Jakarta business tz). Money goes through `App\Support\Money` / BCMath.
- Purchasing Historical alone uses annual rates by each transaction year; see AGENTS.md, Konversi Kurs. Other comparison pages and document snapshots retain their existing rate contracts.

### Where docs live
- [DESIGN.md](DESIGN.md) (root): the UI design contract. It sits under AGENTS.md; `resources/css/app.css` holds the token values.
- `docs/plans/` (plans, prompts), `docs/guides/` (ops guides such as `ADVANCED-EXPORT-DEVELOPMENT.md`, `LOCAL-PROCUREMENT-IMPORTS.md`, `NATIVE-FILE-SECURITY.md`), `docs/audits/`, `docs/results/`.
- UI Phase 2 contract: `ADASI-UI-REDESIGN-PHASE2-MISSIONS/ADASI-UI-REDESIGN-PHASE2-MISSIONS/`; UI results go in `UI-REDESIGN-RESULT/`.
- Root `*PLAN*.md` and `MISSION-*.md` files are historical inputs, not the current contract. Don't move them.

### Gotchas
- Tests use the real MySQL DB `adasi_portal_test`. Never run two suites at once. `AppServiceProvider` refuses a testing DB whose name doesn't end in `_test` or `_testing`.
- `phpunit-results.log` and `test-results.log` at the root are stale; they are not a baseline.
- After a fresh seed, `LocalInvoiceSampleSeeder` users (finance, ga, supplier.local*) have no `account_status`, and the column default is `PENDING` (migration `2026_10_12_000001`). They can't log in until set to `ACTIVE`.
- Windows: use `npm.cmd`. `node --test tests/js/` (a directory) fails; pass files or the bash glob `tests/js/*.test.mjs`.
- Never `migrate`, `migrate:fresh`, `db:wipe`, or truncate the app DB to verify something; `migrate:status` is read-only.
- Event auto-discovery is off (`withEvents(discover: false)`), so register listeners explicitly in `AppServiceProvider`.
- `.claude/` is gitignored. Files under it that should be tracked need `git add -f`.

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- When graphify-out/graph.json exists, run `graphify query "<question>"` before your first grep, search or source-file read for a codebase question, and put this rule in every subagent prompt that explores code. Read raw files only for lines the graph or an earlier read already located. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
- A committed graphify-out/graph.json is rewritten whole on every build, so a large diff to it in a pull request is expected. Do not flag it as churn or suggest skipping the graph rebuild or its hooks.
