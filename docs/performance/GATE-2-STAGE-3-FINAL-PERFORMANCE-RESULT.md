# Gate 2 — Stage 3 Final Performance Result

Worktree `C:\laragon\www\adasi_perf_stage1`, branch `perf/gate2-stage1`, base `7323047`. All changes are uncommitted. Nothing was deployed, no production system or database was touched, and no Cron entry was changed.

Inputs: [Gate 1 plan](GATE-1-SUPPLIER-PORTAL-PERFORMANCE-IMPLEMENTATION-PLAN.md) §8 Stage 3 and §12, [Stage 1 result](GATE-2-STAGE-1-PERFORMANCE-RESULT.md), [Stage 2 result](GATE-2-STAGE-2-PERFORMANCE-RESULT.md) §9.

## 1. Summary

| Area | Before | After | Evidence |
|---|---|---|---|
| Entry `app.js` | 207,394 B raw / 63,773 B gzip | 113,723 B raw / 37,546 B gzip (−45 % / −41 %) | build output, gzip −9 |
| JS loaded on the supplier dashboard (entry + static chunks) | 63,773 B gzip | 38,817 B gzip (−39 %) | headless Chrome network log |
| JS per page, 11 pages | 63,773–73,557 B gzip | 38,817–65,521 B gzip (−11 % to −39 %) | §3.1 |
| `x-ui.icon` × 81 (supplier dashboard count) | 27.7–32.9 ms | 4.5–4.7 ms | micro-benchmark, 30 warm runs, median |
| `x-ui.icon` HTML | — | byte-identical in 205 recorded cases | `UiIconComponentTest` |
| `performance:profile-critical` | 13 paths, 3 broken | 20 paths, 0 errors, P50/P95 over 20 runs | §3.4 |
| Finance/Purchasing Local PO list (14 rows) | 26 queries (one or two per row) | 12 queries, constant | §3.5 |
| "Load earlier messages" (page and drawer) | not browser-verified (Stage 2) | 50 → 100 → 120 messages, scroll anchor 0–1 px, no console errors | §4 |
| Deployment commands | `route:cache` missing from the Cron guide; no `icons:cache`; no release/rollback order for these changes | one command block, read-only host evidence, release and rollback order | §6 |

## 2. Changes

### 3A Frontend

- **Bundle split** (`resources/js/app.js`). Modules used on one page, or on a few, are now dynamic imports gated by a DOM marker, following the existing `data-local-import` pattern:
  - `calendar` (+ `cally`) when `[data-adasi-date-picker]` or `[data-adasi-date-range]` is present;
  - `advanced-export` (+ column designer) for `[data-advanced-export]` or `[data-format-export-form]`;
  - `dashboard-editor` for `[data-dashboard-audience] [data-editor-config]`;
  - `customization-form` (+ `regional-sample`), `notification-preferences` and `supplier-audit-form` for their `x-data` roots.

  Those three are Alpine components. Their imports are awaited (`Promise.allSettled`) before `Alpine.start()`, so component names and markup are unchanged; a failed chunk leaves only its own component unregistered. Pages without these markers reach `Alpine.start()` synchronously, exactly as before (the top-level `await` is only reached when a marker exists).

  `window.AdasiCalendar` is now a stand-in until `calendar.js` loads: `initialize(root)` loads the module when `root` contains a date picker (server-tab fragments and the comparison page call it), and `calendar.js` replaces the stand-in with the real API when it runs. `reset` and `closeActive` are no-ops before load, when no calendar can be open.

  Kept eager on purpose: Alpine, the toast/shell runtime, `server-tabs`, `chart-theme`, `unsaved-changes`, `async-form-submit`, `file-upload`, `searchable-select` and `po-document-upload`. Inline page scripts call `AdasiServerTabs`/`AdasiChart` synchronously, and the shared Alpine components can arrive later through server-tab fragments. `po-document-upload` is 2 KB and coordinates with `async-form-submit` on the same form, so lazy loading would add an ordering risk for a negligible gain.

- **`x-ui.icon`.** The component is now the class `App\View\Components\Ui\Icon`, which renders HTML directly instead of rendering a Blade view per icon. The alias map moved to `Icon::ALIASES`, and Lucide name resolution (`preg_match` + `is_file`) is memoized per process.

  The constructor deliberately has no parameters. Laravel resolves nested class components with the parent component's attributes merged into the constructor arguments, so a `size` constructor parameter would silently inherit `size` from an enclosing component. A test pins this.

  `icon.blade.php` became a one-line delegate to `Icon::html()`, so views compiled by an older release keep rendering identical HTML until `view:clear`.

- **CSS.** Inspected, not changed. Of 400 hand-written class selectors, 395 are referenced by markup or JavaScript; dynamic names (`adasi-toast--${type}`, DataTables `sorting*`, `role-badge-{role}`) account for most of the apparent misses. The five truly unreferenced primitives (`.no-drag`, `.ui-surface`, `.ui-surface-raised`, `.chat-template-strip`, `.ui-density-stack`) total about 0.4 KB (< 0.3 %) and are design-system primitives, so removing them has no measurable benefit.

  The 188 KB build is 24 KB of `:root` tokens, 53 KB of Tailwind utilities and 111 KB of component and dark-mode rules that are in use. The rebuilt CSS lost two duplicate dark-mode variant selectors (`.even\:bg-white`, `.dark\:bg-white`). Tailwind had generated them from the words "evenodd"/"calendar-event" in the old icon template; no markup uses those classes, and the base `.bg-light`/`.bg-white` dark adapter is unchanged.

### 3B Benchmark and QA

- **`performance:profile-critical` repaired.** It now sends real GET requests through the HTTP kernel in-process, so middleware, route binding, policies, Hashids and view composers are part of every measurement, and controller signature changes can no longer break it (root cause of D7). Each request runs inside a transaction that is rolled back, sessions use the array driver, and the SSO session proof is switched off for the run, as in tests. It also has `--runs` (one warm-up plus N measured runs, P50/P95) and `--only`.
  - Paths added: Finance dashboard, Finance invoice list, Local PO list, GR feed, Supplier local invoices, notification unread count, chat drawer, and vs-best with a keyword.
  - Paths are skipped, not failed, when a role or dataset is missing.
  - It is still restricted to `local` and `testing`.
- **N+1 found by the profiler and fixed.** `LocalPurchaseOrder::latestPoDocument()` queried attachments on every call, and the list views call it up to twice per row.
  - New relation `latestPoAttachment()` (`morphOne`, `latestOfMany('id')`, i.e. the same "highest id" rule); `latestPoDocument()` now loads it once per instance.
  - `Finance\LocalProcurementController@index` (Finance and Purchasing) and `LocalSupplier\PurchaseOrderController@index` eager-load it. The supplier list previously eager-loaded every attachment and still queried per row.
  - The supplier query keeps `where('supplier_id', auth id)`; attachment access still goes through `attachments.show` and `AttachmentPolicy`.
- **Browser QA** of the bundle split and of "Load earlier messages" (§4).

### 3C Deployment readiness

- [CPANEL-PERFORMANCE-RUNTIME.md](../guides/CPANEL-PERFORMANCE-RUNTIME.md):
  - §3: one deployment block with `config:cache`, `route:cache`, `view:cache`, `icons:cache` and `queue:restart`, plus why `view:cache` is mandatory for this release and its rollback.
  - §3.1: rollback with the same caches.
  - §3.2 (new): read-only host evidence — OPcache, `ulimit -u`, Cron lines, database version and limits through an option file, and a `manifest.json` consistency check.
  - §3.3 (new): Gate 2 release order (pause imports, upload the matching build, caches, `queue:restart`, smoke, optional Cron split) and its rollback.
  - §10 rollback step and six new rows in the §11 evidence checklist.
- [PRODUCTION-CRON-COMMANDS-20261012.md](../guides/PRODUCTION-CRON-COMMANDS-20261012.md) §5: added the missing `route:cache` and `icons:cache`, and a pointer to the full order.
- `README.md` (build set and `icons:cache`), `AGENTS.md` (lazy-module convention and icon class), `CLAUDE.md` (pointer), `resources/views/components/ui/CLAUDE.md` (icon location).

## 3. Measurements

Local Laragon, PHP 8.2 CLI, MySQL, Windows; development database with small data (19 users, 9 PRs, 14 Local POs, 1,366 GRs, 5 local invoices, 59 notifications, 5 messages). Timings are shape checks, not capacity numbers (Gate 1 §2.3).

### 3.1 JavaScript per page (gzip −9 of the files the browser requested)

"Before" is the old single entry (63,773 B) plus `cally` (9,784 B) where the page has a date picker.

| Page | Before | After | Change | Chunks loaded after the entry |
|---|---:|---:|---:|---|
| Chat page | 63,773 | 38,817 | −39.1 % | — |
| Profile notifications | 63,773 | 40,733 | −36.1 % | notification-preferences |
| Supplier dashboard | 63,773 | 41,884 | −34.3 % | dashboard-editor (after start) |
| Purchasing dashboard | 63,773 | 41,884 | −34.3 % | dashboard-editor |
| Finance dashboard | 63,773 | 41,884 | −34.3 % | dashboard-editor |
| Supplier audit form | 63,773 | 41,909 | −34.3 % | supplier-audit-form |
| Profile customization | 63,773 | 43,060 | −32.5 % | customization-form, regional-sample |
| vs-best comparison | 73,557 | 57,052 | −22.4 % | calendar, cally |
| Local invoice create | 73,557 | 57,052 | −22.4 % | calendar, cally |
| Purchasing PO list | 73,557 | 65,521 | −10.9 % | advanced-export, calendar, cally |

Every page also loads three small static chunks split out of the entry: `preload-helper` (655 B), `i18n` (92 B) and `dashboard-layout-core` (524 B, imported by the eager `preferences.js`). Laravel emits `modulepreload` links for all three.

Remaining entry content: Alpine 46 KB raw (40 %), `app.js` itself 10 KB, `server-tabs` 9 KB, `async-form-submit` 7 KB, `unsaved-changes` 6 KB, `chart-theme`, `file-upload`, `adasi-loader` and `searchable-select` about 4–5 KB each.

### 3.2 `x-ui.icon`

81 icons (`<x-ui.icon>` with mixed names, sizes, classes and `aria-label`), median of 30 warm runs in one process: **27.65–32.92 ms before, 4.48–4.69 ms after** (three runs each). For reference, 81 bare `svg()` calls take 0.9–1.1 ms. The remaining cost is Laravel's class-component lifecycle.

### 3.3 CSS

`app.css` 188,518 → 188,292 B raw (30.97 → 30.95 KB gzip); see §2 3A.

### 3.4 Critical paths (`performance:profile-critical --runs=20`)

The previous profiler, run on the same database before the repair, returned errors for `purchasing.dashboard`, `purchasing.purchase-orders.data` and `purchasing.claims.history.data`. Its timings are not comparable with the table below because it called controllers directly, without middleware.

| Path | Role | Queries | SQL ms | P50 ms | P95 ms | Bytes |
|---|---|---:|---:|---:|---:|---:|
| purchasing.dashboard | purchasing | 7 | 3.29 | 92.92 | 122.56 | 313,783 |
| supplier.dashboard | supplier (import) | 11 | 5.70 | 65.20 | 72.66 | 252,234 |
| purchasing.requisitions.data | purchasing | 5 | 3.04 | 16.61 | 21.59 | 10,426 |
| purchasing.purchase-orders.data | purchasing | 7 | 3.02 | 19.33 | 22.38 | 12,388 |
| purchasing.comparison.inter-supplier | purchasing | 5 | 1.81 | 47.52 | 51.82 | 304,988 |
| purchasing.comparison.historical | purchasing | 6 | 5.71 | 54.06 | 58.45 | 314,258 |
| purchasing.comparison.vs-best.data | purchasing | 3 | 8.75 | 26.68 | 30.33 | 69,725 |
| purchasing.comparison.vs-best.data.keyword | purchasing | 4 | 11.39 | 24.47 | 28.29 | 42,123 |
| purchasing.quotations.index | purchasing | 11 | 4.35 | 76.57 | 96.56 | 339,107 |
| qc.inspections.data-history | qc | 7 | 2.68 | 14.97 | 16.74 | 8,953 |
| purchasing.claims.data-history | purchasing | 6 | 1.93 | 12.37 | 13.60 | 4,799 |
| finance.dashboard | finance | 14 | 5.52 | 71.95 | 77.88 | 260,576 |
| finance.invoices.index | finance | 9 | 4.07 | 48.36 | 53.54 | 202,902 |
| finance.local-procurement.index | finance | 26 → **12** | 17.73 | 82.05 | 93.09 | 347,344 |
| finance.local-procurement.goods-receipts.data | finance | 4 | 3.02 | 67.93 | 75.09 | 73,102 |
| local-supplier.invoices.index | supplier (local) | 9 | 3.22 | 42.43 | 50.21 | 192,835 |
| notifications.unread-count | purchasing | 3 | 1.41 | 6.28 | 7.28 | 191 |
| notifications.summary | purchasing | 3 | 1.49 | 17.98 | 20.19 | 84,394 |
| conversations.unread-count | purchasing | 2 | 0.73 | 3.43 | 4.18 | 11 |
| conversations.drawer.show | purchasing | 30 | 8.18 | 19.86 | 22.66 | 2,733 |

The `finance.local-procurement.index` row (SQL ms, P50, P95) was measured before the N+1 fix; after the fix the same page issues 12 queries (§3.5). Peak memory was 48–58 MB across the run (one process). The drawer's 30 queries include the full middleware stack and the read-marking update; Stage 2 measured the drawer controller at 17 queries, constant from 5 to 500 messages.

Single-run outliers appear when a path's first measured run follows a cold cache (Finance dashboard 4.2 s in a one-run pass, 72 ms P50 over 20 runs). Use `--runs` of 5 or more for any comparison.

### 3.5 Local PO list attachments

| Page | Baseline `7323047` | This branch |
|---|---:|---:|
| Finance Local PO list, 14 rows (dev DB) | 26 queries (15 on `attachments`) | 12 queries (1 on `attachments`) |
| Supplier Local PO list | per-row `attachments` query plus an eager load of every attachment | 1 query on `attachments`, from the test with 4 POs and 7 attachments (the dev supplier has no POs) |

The supplier list on the dev DB went from 18 to 4 queries, but that supplier has no POs; the difference there comes from the Stage 2 scope memo.

## 4. Browser verification

Headless Chrome 155 over CDP against a throwaway loopback server (`php -S`).
- **Requests:** GET only, each in a rolled-back transaction, with the user simulated by the router. Behaviour checks only, no screenshots.
- **Data:** read-only on the development database. The supplier audit and chat checks used a fixture of 120 messages and one assigned audit in `adasi_portal_test`, removed afterwards.
- **Cleanup:** the harness, the browser and the fixture rows were removed.

| Page | Result |
|---|---|
| Supplier dashboard | Alpine initialized; layout editor enters edit mode |
| Purchasing dashboard | Chart.js and `AdasiChart` present, 2 canvases; editor available |
| Purchasing PO list | advanced export modal opens and renders its columns; 2/2 date pickers enhanced |
| vs-best comparison | 1/1 date picker enhanced; `AdasiServerTabs` present; real `AdasiCalendar` replaced the stand-in |
| Profile customization | `customizationForm` initialized; regional sample "08 Oct 2026 7:30 AM · 1,250,000.50"; 5 wireframe tiles |
| Profile notifications | `notificationPreferencesForm` initialized, 58 toggles |
| Finance dashboard | chart rendered |
| Local invoice create | 2/2 date pickers (Wednesday rule) enhanced |
| Supplier audit form | `supplierAuditForm` initialized, 16 sections |
| Chat page, 120 messages | 50 → 100 → 120 after two clicks; oldest visible message moved −1 px and 0 px; ids ascending and unique; button hidden at the end |
| Chat drawer, 120 messages | 50 → 100 → 120; ids ascending and unique; control removed at the end |

All 11 pages: zero console errors or warnings, zero uncaught exceptions, and zero failed requests (`POST /broadcasting/auth` is excluded, because the harness only serves GET). All `x-data` roots were initialized.

## 5. Tests

| Check | Result |
|---|---|
| `node --test tests/js/*.test.mjs` | 537 passed (533 existing + 4 in the new `app-lazy-modules.test.mjs`) |
| Targeted PHPUnit: `FrontendAssetLoadingTest`, `UserDashboardLayoutEditorTest`, `UserDashboardLayoutEndpointTest`, `TranslationParityTest`, `CalendarComponentTest`, `RenderedComponentTest`, `SidebarShellTest`, `CustomAdasiToastTest`, `DashboardSmartSpanTest`, `SupplierDataIsolationTest`, `LocalInvoiceScopeIsolationTest`, `ConversationHistoryPaginationTest` and the three new test classes | 126 passed (42,262 assertions) |
| Local PO document suites (`LocalPurchaseOrderDocumentTest`, `LocalPoDocumentSecurityTest`, `LocalPoDocumentBatchTest`, `FileAccessGuardTest`) | 31 passed |
| `vendor/bin/pint --test` on every changed PHP file | passed |
| `npm.cmd run build` (`vite build`) | passed |
| `config:cache`, `route:cache`, `view:cache`, `icons:cache` on the Stage 3 code | all succeeded, then cleared |
| `git diff --check` | clean |
| Full suite, once (`composer test:parallel`: Parallel suite, then Serial suite) | **3,003 passed, 3 failed** of 3,006. Parallel: 2,869 tests, 2 failures (599 s). Serial: 136 passed, 1 failed (983 s). The three failures are the known baseline failures recorded in Stage 2, unchanged: `UserFacingCopyInventoryTest` (stale copy ledger), `AuthRateLimitingTest::test_mfa_code_challenge_is_limited_by_pending_user`, `LocalizationPerformanceTest` (JS dictionary 16,462 > 16,384 B; Stage 3 adds no translation key). Composer stops after a failing phase, so the Serial suite was started by hand right after the Parallel suite, with no change in between |

New tests:
- `tests/Feature/UiIconComponentTest.php`:
  - 205 recorded cases render byte-identical HTML;
  - every alias is covered and points at an existing icon;
  - `size` is never inherited from an enclosing component;
  - views compiled against the old anonymous component still render the same HTML.
- `tests/Feature/ProfileCriticalPathsCommandTest.php`: all 20 paths respond 200 or are skipped, no row persists, and the command refuses to run outside local/testing. The old command would fail it (inferred: it has 13 paths and exited 1 on the development database).
- `tests/Feature/LocalInvoice/LocalPoLatestDocumentListTest.php`: both lists link the newest document per row with one attachment query, never link another supplier's document, and `latestPoDocument()` is read once per instance.
- `tests/js/app-lazy-modules.test.mjs`:
  - the gated modules are not static imports;
  - the Alpine components are registered before `Alpine.start()`;
  - every marker selector still matches its Blade markup;
  - the calendar stand-in keeps the `AdasiCalendar` contract.

## 6. Deployment readiness

| Item | Status |
|---|---|
| Deployment and rollback order for Gate 2 | written (guide §3, §3.1, §3.3, §10); no migration and no `.env` change in Gate 2 |
| Cache commands on the release code | verified locally (all four build) |
| Build set | `public/build` from the release commit; manifest check in §3.2 (verified locally: "manifest OK (16 entries)") |
| Host evidence (§3.2, §11) | NOT MEASURED — needs the hosting account. Recorded so far: MariaDB `11.4.13-MariaDB-cll-lve` and the three current Cron lines (owner screenshot, 21 October 2026) |
| Cron split `exports,default` → `exports` + `default` | prepared (guide §5.2, Cron guide B/B2); not applied; depends on PCNTL and the process budget from §3.2 |
| Staging and HTTP load test | not available ("tidak ada staging"); not run |

The release is **ready to deploy from the repository side**: code, assets, tests, commands, release order and rollback are complete. It is not "production-verified": the host checks in §3.2 and the smoke checks in §9 of the guide have to run on cPanel.

## 7. Security and invariants

- No route, route name, middleware, policy or authorization rule changed. The supplier Local PO list keeps its `supplier_id` filter, and the eager load follows the filtered rows only (a test asserts another supplier's document is never linked).
- The profiler can only run in `local`/`testing`, writes nothing (rolled-back transactions, array sessions; a test compares row counts), and disables the SSO session proof only inside its own process.
- No financial calculation, workflow, audit history, migration, index or dependency changed. No FULLTEXT and no new index.
- Icon HTML is byte-identical, including escaping of attribute values (`&amp;`, `&lt;`, quotes) and the decorative `aria-hidden` rule.
- JavaScript contracts kept: component names, `data-*` selectors, `window.AdasiCalendar.{initialize,reset,closeActive}`, `window.AdasiRegionalSample`, and the auth-splash bridge (its test slices `app.js` and still passes).

## 8. Risks and limitations

- **Short loading window.** On pages with the advanced export or the dashboard editor, a click within the few milliseconds before the chunk arrives does nothing (the opener) or submits the format form as a normal POST (the same no-JS fallback as before). Date pickers keep the native input until the calendar chunk arrives, which is the existing progressive-enhancement base.
- **Top-level `await` in the entry** (only reached on pages with gated Alpine components) needs Chrome/Edge 89, Firefox 89 or Safari 15; Vite 7's default build target already requires newer browsers, and the build succeeded with it.
- **Stale compiled views.** After a deploy without `view:cache`/`view:clear`, old compiled views still render icons correctly through the delegate. A rollback without recompiling views fails (documented in §3 and §10 of the guide).
- **`latestPoDocument()` is memoized per model instance.** No code path adds a PO document and reads it back through the same instance in one request (uploads redirect).
- **Not done from Gate 1 3B** (documented, not started; no new Gate):
  - the consolidated synthetic dataset generator (Stages 1–2 used dedicated, cleaned-up scripts in `tests/Support/` and `%TEMP%`);
  - the route × role matrix test (the existing isolation suites cover the boundaries changed here);
  - Lighthouse/Core Web Vitals (no `lighthouse` package without a new dependency);
  - the staging HTTP load test (no staging).
- **Provisional target missed.** Gate 1 proposed ≤ 35,000 B gzip of initial JS on the supplier dashboard; the result is 38,817 B (−39 %). The rest is Alpine and modules that inline scripts call synchronously; splitting them changes contracts with page scripts and was not justified.

## 9. Rollback

Restore the previous release's source and its `public/build` set, then follow guide §3.1/§10: `optimize:clear`, `config:cache`, `route:cache`, `view:cache`, `icons:cache`, `queue:restart`. No database step is needed. In the repository, the Stage 3 changes are the files listed in §10 on top of the Stage 1–2 working tree.

## 10. Files changed in Stage 3

Application:
- `resources/js/app.js`
- `app/View/Components/Ui/Icon.php` (new)
- `resources/views/components/ui/icon.blade.php`
- `app/Console/Commands/ProfileCriticalPaths.php`
- `app/Models/LocalPurchaseOrder.php`
- `app/Http/Controllers/Finance/LocalProcurementController.php`
- `app/Http/Controllers/LocalSupplier/PurchaseOrderController.php`

Tests (new):
- `tests/js/app-lazy-modules.test.mjs`
- `tests/Feature/UiIconComponentTest.php`
- `tests/Fixtures/ui-icon-snapshots.json`
- `tests/Feature/ProfileCriticalPathsCommandTest.php`
- `tests/Feature/LocalInvoice/LocalPoLatestDocumentListTest.php`

Documentation:
- `docs/guides/CPANEL-PERFORMANCE-RUNTIME.md`
- `docs/guides/PRODUCTION-CRON-COMMANDS-20261012.md`
- `README.md`
- `AGENTS.md`
- `CLAUDE.md`
- `resources/views/components/ui/CLAUDE.md`
- this report

Measurement scripts (outside the repository, `%TEMP%\adasi-gate2s3`): bundle attribution from source maps, icon benchmark and snapshot recorder, SQL-shape probe, loopback router, test-DB fixture and CDP driver.

**STATUS: GATE 2 STAGE 3 COMPLETE — PENDING USER REVIEW**
