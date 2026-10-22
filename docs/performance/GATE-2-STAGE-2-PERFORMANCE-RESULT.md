# Gate 2 — Stage 2 Performance Result (with Stage 1 closure)

Date: 21 October 2026. Worktree `c:\laragon\www\adasi_perf_stage1`, branch `perf/gate2-stage1` (continues the Stage 1 changes; nothing committed). Inputs: [Gate 1 plan](GATE-1-SUPPLIER-PORTAL-PERFORMANCE-IMPLEMENTATION-PLAN.md), [Stage 1 result](GATE-2-STAGE-1-PERFORMANCE-RESULT.md).

Labels: **MEASURED** (executed here), **CODE** (from source), **ENV-UNKNOWN** (production not verified).

---

## 1. Stage 1 closure

| Item | Finding | Action |
|---|---|---|
| A. PO conflict messages | Stage 1 made confirm report only the first registered-PO conflict; the old flow reported up to ten | `createImportBatch()` now collects cancelled / identity / PR conflicts for the whole batch from the PO map it has already locked, then throws once with the first ten in row order, using the same translation keys and the file's PO number. No extra lookup. New test `test_every_conflict_in_a_batch_is_reported_together` passes on the **unchanged `master` code** (copied into the baseline worktree and removed afterwards) and on the new code, which proves the original behavior is back |
| B. Import lock order | Supplier-before-PO locking kept; transaction unchanged | Waiting during large imports is documented in `LOCAL-PROCUREMENT-IMPORTS.md` (Stage 1) |
| C. Known failures | The 3 baseline failures from Stage 1 | Treated as known; see §5 |
| D. Production runtime | Queue/Cron recommendation unchanged | Production not touched; open items in §7 |

---

## 2. Stage 2 implementation summary

| Area | Change | Result |
|---|---|---|
| **2A Supplier scope** | `User::hasSupplierScope()` reads `supplierScopeValues()`, memoized per model instance. `forgetSupplierScopes()` and `refresh()` clear it. The two scope writers (`Admin\UserController` store/update, `SupplierRegistrationService` approval) clear it after writing. `PortalContext::resolve()` uses the memo | Supplier dashboard 30 → 11 queries; scope lookups 14 → 1. Rendered HTML identical apart from the chat-drawer script (2D) and asset cache-busting timestamps |
| **2B Finance dashboard** | Six KPI counts derived from the existing `GROUP BY status` result. `whereDate()` on the DATE column `due_date` replaced by a plain comparison (dashboard overdue and the `overdue` filter). New `PaymentForecastService::getDashboardForecasts()` builds the weekly and monthly forecasts from **one** invoice read (only the six columns the calculation uses, plus `currentVerification`); the dashboard and the forecast JSON endpoint use it. The old public methods are unchanged | 22 → 14 queries; rendered HTML byte-identical |
| **2C Notifications** | `NotificationSummaryService::summary()` classifies each notification once (previously once per category, each time decoding its JSON) | `countsForUser()` p50 104 → 68 ms at 100 unread and 792 → 328 ms at 2,000 unread (10,000 per user); output identical to the per-category reference for four roles |
| **2D Chat** | Newest 50 messages on open (drawer and full page); earlier pages of 50 through the new `GET conversations/{id}/messages/older?before=<id>` (route `conversations.messages.older`, same middleware and `ConversationPolicy::view` as `latest`). Keyset by id, with `has_more_messages` / `has_more` flags. Duplicate eager loads removed; `latest` eager-loads attachments and casts `after` to an integer. A "Load earlier messages" control (EN/ID) inserts older pages above and keeps the scroll position. Full-page polling skips hidden tabs (the drawer already did) | Drawer, 500 messages: 148 → 25 ms, 208 KB → 22 KB. Full page: 316 → 97 ms, 899 KB → 303 KB. `latest`: 55 → 7 queries |
| **2E Search / DataTables** | Measured only; no change (§4.5) | — |

Deliberately not changed:

- Adaptive notification polling. `markAsRead()` and mark-all-read do not broadcast, so other tabs learn about read changes only through the 30 s poll; slowing it while Pusher is connected would delay cross-tab badges. The 30 s polling is kept.
- A notification index. SQL is 42–53 ms of the totals above even at 10,000 rows per user; the cost was PHP classification. No migration.
- The inter-supplier history aggregate per PR item (§4.5) and the duplicate `periods` query on the quotation list (one constant query issued from the Blade view).
- The vs-best rewrite rejected in Stage 1 was not reopened.

---

## 3. Modified files (Stage 2 and the Stage 1 closure)

Application: `app/Models/User.php`, `app/Models/Conversation.php`, `app/Support/PortalContext.php`, `app/Http/Controllers/Admin/UserController.php`, `app/Services/SupplierRegistrationService.php`, `app/Http/Controllers/Finance/FinanceDashboardController.php`, `app/Services/LocalInvoice/InvoiceQuery.php`, `app/Services/Payment/PaymentForecastService.php`, `app/Services/NotificationSummaryService.php`, `app/Http/Controllers/ConversationMessageController.php`, `app/Http/Controllers/Purchasing/ConversationController.php`, `app/Http/Controllers/Supplier/ConversationController.php`, `app/Services/LocalInvoice/LocalProcurementMasterService.php`, `routes/web.php` (one route), `resources/views/partials/chat-drawer.blade.php`, `resources/views/conversations/show.blade.php`, `lang/en/common.php`, `lang/id/common.php` (`chat.load_older`, `chat.load_older_error`).

New tests: `tests/Feature/SupplierScopeMemoTest.php`, `tests/Feature/Finance/FinanceDashboardEquivalenceTest.php`, `tests/Feature/NotificationSummaryEquivalenceTest.php`, `tests/Feature/ConversationHistoryPaginationTest.php`; extended `tests/Feature/LocalInvoice/LocalProcurementCommitRevalidationTest.php`.

Test infrastructure (owner request, §5.2): `composer.json` / `composer.lock` (dev dependency `brianium/paratest` 7.8.5 and the `test:parallel` script), `phpunit.xml` (two opt-in suites), `app/Providers/AppServiceProvider.php` (migration guard accepts parallel test databases; scope-memo reset listeners).

No migration, index, runtime configuration, production package, Cron or production change.

---

## 4. Before/after measurements

### 4.1 Pages (same script on both trees)

`pages.php` ran unchanged on `adasi_perf_baseline` (`master` at `7323047`) and on this worktree. Dashboards render against the local application database (read-only). Chat runs against `adasi_portal_test` with 500 messages and 100 attachments inside a rolled-back transaction. Seven warm runs per cell; query counts include the per-request user and preference reads.

| Path | Queries | SQL ms | p50 ms | Peak MiB | Bytes |
|---|---|---|---|---|---|
| Supplier dashboard | 30 → **11** | 12.6 → 5.3 | 112.0 → 101.3 | 42 → 40 | 247,640 → 251,130 (drawer script only) |
| Finance dashboard | 22 → **14** | 10.5 → 5.6 | 117.3 → 128.3 | 44 → 42 | 259,400 → 259,400 |
| Chat drawer, 500 messages | 19 → 17 | 13.1 → 6.9 | 148.3 → **25.5** | 42 → 36 | 208,116 → **21,732** |
| Chat page, 500 messages | 18 → 16 | 15.0 → 7.8 | 316.5 → **97.3** | 52 → 42 | 898,731 → **302,634** |
| Chat `latest` (50 new, 10 attachments) | 55 → **7** | 21.4 → 5.0 | 51.4 → 21.5 | 48 → 40 | 27,710 → 27,560 |

The local application data is small (5 invoices), so the Finance figure shows the statement count, not volume behavior; p50 differences of ±10 ms there are noise. The forecast change removes one invoice read plus one verification read per dashboard load; with many invoices the saving grows with the number of invoices in the window.

### 4.2 Notifications (synthetic, test DB, rolled back)

10,000 notifications for one user; 10 warm runs.

| Unread | p50 before → after | SQL ms before / after | Plan |
|---|---|---|---|
| 100 | 104.1 → 67.9 ms | 42.5 / 47.2 | `ref` on `notifications_notifiable_type_notifiable_id_index` + filesort |
| 2,000 | 791.6 → 327.8 ms | 53.0 / 154.7 | same |

Peak memory is unchanged (46 / 96 MiB): every unread row is still hydrated, because legacy rows need PHP classification for exact counts.

### 4.3 Supplier scope

Query log of the supplier dashboard: 14 `supplier_scopes` queries → 1. Each remaining query shape now runs once.

### 4.4 Finance equivalence

`FinanceDashboardEquivalenceTest` (clock 15 Oct 2026 10:00 WIB) asserts `getDashboardForecasts($m) === [getWeeklyForecast($m), getMonthlyForecast()]` for 2026-10, -09, -11 (outside the rolling window), -05 and -04. The fixture covers decimal amounts with PPh 23 withholding, 00:00 WIB month boundaries (one second either side), `approved_at` used only when `ready_to_pay_at` is null, legacy statuses, rejected/cancelled exclusions and a 31-day month. A sanity check pins week 1 of October at `3010679.11`. KPIs and the overdue filter equal the former per-status queries.

### 4.5 Search and DataTables (2E)

Profiled on the synthetic vs-best fixture (4,300 quotation items):

- **Inter-supplier comparison:** 23–26 queries for a PR with 5 items. The repeated shape is the `PurchaseHistoryPriceService` aggregate, issued once per PR item (5 ×, SQL 21–30 ms in total). Batching it would mean rewriting the weighted multi-currency price-history calculation, which this stage must not change, to save a few milliseconds. **Not changed**; the per-item count grows with PR size and is the candidate if large PRs prove slow.
- **Purchasing quotations list:** 11 queries on page 1 and on page 2 — no growth with rows. One `periods` query is issued twice, once from the Blade view; constant cost. Not changed.
- **Invoice search:** `InvoiceQuery::filtered()` eager-loads its relations; no N+1 seen. Leading-wildcard `LIKE` is unchanged (no FULLTEXT, no speculative index).

---

## 5. Test outcomes

| Run | Result |
|---|---|
| Stage 1 closure test on unchanged `master` / on this tree | passed / passed |
| `LocalProcurementCommitRevalidationTest` | 12 passed |
| `SupplierScopeMemoTest` + `SupplierDataIsolationTest` + `LocalInvoiceScopeIsolationTest` + `SupplierPortalContextV2Test` + `tests/Feature/SupplierRegistration` | 4 new passed; 150 existing passed |
| `FinanceDashboardEquivalenceTest` + `PaymentForecastAndReportingTest` | 16 passed |
| `NotificationSummaryEquivalenceTest` + `NotificationControllerTest` + `NotificationSilentDeliveryTest` + `NotificationCategoryOrderTest` | 22 passed |
| `ConversationHistoryPaginationTest` + `ConversationUnreadCountTest` + `ChatDrawerDraftIsolationTest` + `UiManagedSubmitAndChatDrawerButtonTest` + `NotificationConversationMuteTest` + `TranslationParityTest` | 36 passed |
| `LocalizationImportRenderingTest` + `SharedLocalizationRenderingTest` + `TranslationContentTest`; `node --test tests/js/localization-interactions.test.mjs` | 29 passed; 6/6 passed |
| `php -l`, `vendor/bin/pint --test` on every changed PHP file; `git diff --check` | passed (Pint fixed docblock imports in `Conversation.php`) |
| **Full suite, first run** (`config:clear` + `php artisan test`, serial, 52 min) | 2,989 passed, 8 failed: the 3 known baseline failures, 1 transient `MySQL server has gone away` (`AuthSplashTest`, passes on re-run on both trees), and **4 real regressions from 2A** (see §5.1) |
| Re-run of the 8 tests after the 2A fix, plus `SupplierScopeMemoTest` (new same-instance test) | 71 passed |
| **Full suite, final run** (`composer test:parallel` equivalent: Parallel suite on 4 processes, then Serial suite) | **2,995 passed, 3 failed** of 2,998 (Parallel: 2,861 tests, 2 failed, 11.2 min; Serial: 137 tests, 1 failed, 16.3 min). The 3 failures are exactly the known baseline failures below. PHPUnit also reported 6 deprecations (test metadata notices, no failures) |

Known baseline failures (identical on unchanged `master`, see Stage 1 §6): `UserFacingCopyInventoryTest` (stale copy ledger), `AuthRateLimitingTest::test_mfa_code_challenge_is_limited_by_pending_user`, and `LocalizationPerformanceTest` (JS dictionary 16,462 bytes > 16,384). Stage 2 adds no JS translation key; the new chat strings are server-rendered.

Not run: browser checks of the "Load earlier messages" control and of the drawer (no browser session in this run); staging (none exists).

### 5.1 Regression found by the full suite and fixed

The first 2A version memoized scopes per `User` instance only. In tests, `actingAs($user)` reuses one instance across several requests, so a scope revoked between two requests was still seen as granted: `LocalInvoiceTest` (2 tests) and `RegionalMaterialClaimDisplayTest` got 200 instead of 403, and `SupplierQuotationIndexPerformanceTest` saw different query counts per request. Production resolves a new instance per request, but the code did not guarantee that, so this was a real gap rather than a test artifact.

Fix: the memo now also carries a generation number. `User::resetSupplierScopeMemos()` bumps it on `RouteMatched` (every request) and `JobProcessing` (every queued job), both registered in `AppServiceProvider`, so a memo never outlives a request or job even when the instance is reused. `SupplierScopeMemoTest::test_memo_never_outlives_a_request_even_for_the_same_instance` covers it. The dashboards still measure 11 and 14 queries after the fix.

### 5.2 Faster full-suite runner (owner request)

- Added `brianium/paratest` **7.8.5** (exact pin, dev only; brings `fidry/cpu-core-counter` 1.4.1 and `jean85/pretty-package-versions` 2.1.1). Composer audit reports two existing advisories (`symfony/http-foundation`, `symfony/yaml`), unrelated to paratest.
- `phpunit.xml`: `defaultTestSuite="Unit,Feature"` keeps `composer test` / `php artisan test` exactly as before. Two opt-in suites were added: `Parallel` (Unit + Feature minus 32 files) and `Serial` (those 32 files: subprocess concurrency workers, migration/rollback tests, the database safety test and locale/preference tests that are pinned to `adasi_portal_test`).
- `AppServiceProvider` migration guard now also accepts Laravel's per-process databases (`adasi_portal_test_test_N`); `adasi_portal` and any other name are still rejected.
- New `composer test:parallel`: `php artisan test --parallel --processes=4 --testsuite=Parallel`, then `php artisan test --testsuite=Serial`.
- Measured: 27.6 min (11.2 parallel + 16.3 serial) against 52.2 min for the serial run, same 2,998 tests and the same results. The Serial phase is now the longest part; making its workers accept a per-process database would be the next step.

---

## 6. Security and financial invariants

| Invariant | Evidence |
|---|---|
| Supplier isolation and scope decisions | The memo is per `User` instance and is reset at the start of every request (`RouteMatched`) and job (`JobProcessing`), so it never outlives either, even for a reused instance (§5.1). Scope writers clear the memo. Tests: dual scope, grant, revocation, non-supplier, middleware 403 after revocation on the next request with a new or the same instance; isolation suites and the full suite pass |
| Conversation authorization | `older` uses `ConversationPolicy::view` like `latest`; non-member → 403 (test); `before` must be a positive integer (422 otherwise) |
| Attachment permissions | URLs still go through `attachments.show` (policy-checked); only the eager loading changed |
| Read receipts and mark-read | Opening still marks every unread partner message read, including ones not on the first page (test); the receipt payload is unchanged |
| Exact money, Money/BCMath, legacy statuses, business timezone, fallback dates | Forecast equivalence test (strings compared with `assertSame`); no float arithmetic added |
| Notification counts, silent exclusion, domain/role filtering, legacy JSON | Equivalence test against the per-category reference for four roles |
| Import atomicity and lock order | Unchanged since Stage 1; the closure change only defers the conflict exception until the batch is scanned, before any write of that batch |

---

## 7. Remaining limitations and production checks

- **Production Cron** (carried from Stage 1): split `exports,default` into `exports` and a separate `default` worker. Check PCNTL (`/usr/local/bin/ea-php83 -m | grep pcntl`) and the account process limit first; use the `default,exports` fallback if a fourth process is not allowed. Not applied.
- Production runs MariaDB 11.4.13; all measurements here are on local MySQL 8.0.30. The query shapes changed in Stage 2 are standard SQL; no hint or index was added.
- The unread notification backlog per user in production is unknown; the remaining cost scales with it (hydration).
- Chat pages are ordered by message id; the full history was previously ordered by `created_at`. Ids follow insertion order, so pages stay chronological, and rows that share one `created_at` second now have a deterministic order (covered by the test fixture).
- Browser QA of the chat "Load earlier messages" control is pending.

---

## 8. Rollback

All changes are in the uncommitted worktree. To roll back one area, revert its files listed in §3; there is no migration, data change or configuration change. The new route `conversations.messages.older` is additive; reverting the views and the controller together removes its only caller. The chat change must ship with its matching Blade views and translations in the same release (no build step; the scripts are inline Blade).

---

## 9. Readiness for Stage 3

Stage 3 (frontend bundle, `x-ui.icon` render cost, profiler repair, deployment readiness) does not depend on further backend work. Inputs ready for it: the measurement scripts used here, the known bundle regression (Gate 1 §6.3) and the icon micro-benchmark. Stage 3 should also fix `performance:profile-critical` (three paths broken) so the dashboards measured here can be tracked with the project tool.

**STATUS: GATE 2 STAGE 2 COMPLETE — PENDING USER REVIEW FOR STAGE 3**
