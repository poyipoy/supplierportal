# Gate 1 — Supplier Portal Performance Implementation Plan

Status: **planning only, pending approval for Gate 2.** No application code, configuration, migration, queue, cron, or application-database state was changed while producing this plan.

Date: 21 October 2026. Branch `master`, HEAD `732304736bb283f65d12eb61bb05febcd2a9cf06` (matches the requested reference baseline).

Evidence labels used throughout:

| Label | Meaning |
|---|---|
| **MEASURED** | Executed during this Gate 1 on the local machine; numbers below |
| **CODE** | Read directly from current source; not executed |
| **HISTORICAL** | Taken from earlier result documents; not re-run |
| **HYPOTHESIS** | Plausible, competing explanations exist; must be tested before a fix |
| **ENV-UNKNOWN** | Depends on staging/cPanel/production facts that are not available |

---

## 1. Executive summary

The earlier PERF-00..09 program is still largely in place. Since its final report, three things have changed, and one earlier blocker no longer reproduces in its original form.

1. **The import transaction failures recorded on 8 October do not reproduce on this revision.** The failing runs were combined PO+GR imports, and that feature has since been removed. On the current revision, on the isolated test database, GR 70k confirm took **22.6 s** with no history and **25.5 s** with 500,000 existing GR; PO 70k confirm took **20.5 s**. Each finished in one delivery under the unchanged 60-second guard (MEASURED). The remaining risk is headroom and contention on production hardware: PO confirm runs four `local_purchase_orders` lookups per 500-row batch, and the single whole-file transaction holds row locks that the supplier invoice flow also takes.
2. **Realtime notifications depend on the `default` queue, which no document describes.** Every `SystemNotification` broadcast is queued as `BroadcastNotificationCreated` (a `ShouldBroadcast` event) on `default`. The documented cPanel worker serves `exports,default` in that order, so a long export (job timeout 600 s) delays realtime pushes. Separately, three guides still contain `--stop-when-empty`, which a 13 October production diagnosis linked to 0–60 s "Queued" latency, while `README.md` forbids it.
3. **Frontend payload and server render cost have regressed.** The production Vite bundle is now 207,353 B JS raw / 63,567 B gzip (PERF final: 51,762 / 18,541) and 187,322 B CSS raw / 30,503 B gzip (PERF final: 51,611 / 10,130). Server-side, Blade rendering now dominates warm page time: the supplier dashboard spends ~15 ms in the controller and ~140 ms rendering 141 views, 81 of them `x-ui.icon`. Each icon costs ~0.47 ms against ~0.02 ms for a direct `svg()` call (MEASURED).
4. **Per-request query counts grew.** Supplier dashboard went from 9 to 24 queries; 14 of those are repeated `supplier_scopes` lookups from the un-memoized `User::hasSupplierScope()`. Inter-supplier went from 3 to 8 and Purchasing quotations from 7 to 11 (MEASURED on small local data; growth not yet attributed).

The plan keeps the program to three stages and preserves every financial, audit, authorization and transactional invariant. Final latency targets stay conditional on a staging baseline that does not exist yet. The largest open blockers are environment facts: the production database engine and version (window functions are now required), PCNTL availability, cPanel process limits, the actual cron lines, and production data shapes (unread-notification backlog, invoice and quotation-history cardinality).

---

## 2. Current repository and environment state

### 2.1 Repository

| Item | Value | Label |
|---|---|---|
| Branch / HEAD | `master` / `7323047` (merge of `feature/logout-cross-tab`) | MEASURED |
| Working tree | 25 modified + 4 untracked files, all in supplier registration/PKP, invoice form, navbar, lang and related tests; plus `.rnd`. None are performance files. They are preserved and are not part of this plan. | MEASURED |
| Laravel / key packages | Laravel 12.69.3, yajra/laravel-datatables-oracle 12.7.2, openspout 4.28.5, blade-icons 1.10.1, blade-lucide-icons 3.168.0 | MEASURED (`composer.lock`) |

### 2.2 Local runtime used for measurements

| Item | Value |
|---|---|
| PHP | 8.2.30 CLI (Windows, Laragon; no PCNTL) |
| MySQL | 8.0.30; buffer pool 128 MiB; redo capacity 100 MiB; `innodb_flush_log_at_trx_commit=1`; binary log ON; `innodb_lock_wait_timeout=50`; `innodb_autoinc_lock_mode=2`; REPEATABLE-READ |
| Laravel caches | config/routes not cached; views cached |
| Running local processes | `artisan serve`, one `imports` worker, **two** concurrent `exports,default` workers (local environment observation, not a code defect) |

### 2.3 Local dataset is not representative

The application database now holds 9 PRs, 17 quotation items, 6 Import POs, 14 local POs, 1,366 GRs, 5 local invoices, 59 notifications and 5 messages (MEASURED). PERF-01 measured against 2,067 PRs and 4,351 quotation items. **Any current timing for price comparison, finance dashboard, notifications or chat is therefore a shape check (query count, render split), not a capacity number.** Stage 3B builds a guarded synthetic dataset on the test database for that reason.

---

## 3. Previous work to retain (PERF-00..09 and later import work)

These must not be reverted or re-done unless fresh measurement shows a regression.

| Area | Retained behavior | Guarding test(s) |
|---|---|---|
| Global request cost | No notification-detail/chat hydration on full page render; lazy `notifications.summary`; aggregate chat counts | `NotificationControllerTest`, `ConversationUnreadCountTest` |
| PR / PO DataTables | Narrow projections, `withCount`, SQL-resolved PO IDR totals with legacy financial fallbacks | `PurchaseOrderReferenceRemarkTest`, `SupplierDataIsolationTest` |
| Price comparison | Inter-supplier constrained eager load; bounded monthly history (50/page) with full chart series; vs-best two queries, Hashids from trusted IDs, legacy JSON fields incl. `best_price_idr` | `PriceComparisonPerformanceRegressionTest` |
| Indexes | Four redundant indexes removed by `2026_08_23_000003` (reversible); later `2026_09_25_000001/000002` performance indexes | `RedundantIndexMigrationTest` |
| Exports | Same-connection atomic `ExportJob` + queue handoff, `after_commit=false` guard, terminal-state row locks, cached counts | `AsyncExportQueueTest`, `AdvancedExport*Test` |
| Imports | Streaming readers, 500-row staging, owner/attempt fencing, whole-file atomic master+audit, 60 s commit guard, `forceIndex('local_purchase_orders_po_number_unique')` | `LocalProcurementBackgroundImportTest`, `LocalProcurementStreamingReaderTest`, `tests/Feature/LocalInvoice/` |
| Frontend | Axios removed; DataTables opt-in per page; lazy `local-procurement-import` and `local-po-gr-table` modules | `FrontendAssetLoadingTest` |
| Static delivery | `.htaccess` immutable cache for `/build/assets/*`, revalidating `/assets/*`, guarded gzip | manual header checks (cPanel guide §7) |

---

## 4. Documentation drift found

| # | Document claim | Current source of truth |
|---|---|---|
| D1 | The Gate 1 request and the 8 Oct large-import result describe a combined PO+GR XLSX import | Removed (routes, service, modal). Only `PO` and `GR` kinds remain (`LocalProcurementImport::KINDS`) |
| D2 | PERF final report: the vs-best window-function rewrite was not made a deployment requirement because the cPanel DB version is unknown | `buildVsBestQuery()` now uses `ROW_NUMBER() OVER (PARTITION BY ...)` (`PriceComparisonController.php:677`). MySQL ≥ 8.0 or MariaDB ≥ 10.2 is now **required** |
| D3 | `README.md`: "Do not add `--stop-when-empty`" | `CPANEL-PERFORMANCE-RUNTIME.md` §5, `AUTH-SECURITY-DEPLOYMENT.md` and `PRODUCTION-CRON-COMMANDS-20261012.md` B/C all include it |
| D4 | `CPANEL-PERFORMANCE-RUNTIME.md` §5: `default` carries password-reset/new-device mail | No queued mail or notification class exists. `default` carries `BroadcastNotificationCreated` jobs (realtime pushes), which is documented nowhere |
| D5 | `README.md`: run `php artisan optimize:clear` once after deployment | That clears config/route/view/icon caches and builds none. The cPanel guide rebuilds them individually but never runs `icons:cache` (blade-icons registers it under `optimize`) |
| D6 | `composer dev` and the AGENTS verification list: `queue:listen --queue=exports,default` | Imports dispatch to `imports`; the dev command never processes them |
| D7 | `performance:profile-critical` is the repeatable baseline tool | 3 of 13 paths now fail with `ArgumentCountError` (Purchasing dashboard, PO DataTable, claims history) after controller signature changes; it covers no Local/Finance path |
| D8 | PERF baseline dataset | No longer present locally (§2.3) |

---

## 5. Evidence-based issue inventory

Severity: P0 = can fail or block a business operation; P1 = measurable cost on every request of a role or on a frequent path; P2 = payload, efficiency or deployment hygiene. Confidence: H = measured or directly proven in code; M = code-supported, impact unmeasured; L = hypothesis.

| ID | Issue | Sev | Conf | Evidence |
|---|---|---|---|---|
| S1-A1 | PO import confirm runs 4 `local_purchase_orders` lookups per 500-row batch (re-validation incl. supplier name match, conflict map, locked map, post-insert reload) | P0 | H | PO 70k confirm: 560 PO selects, 4.8 s of 13.6 s SQL (MEASURED); 26.6 s in the 8 Oct matrix (HISTORICAL) |
| S1-A2 | GR import confirm looks up `local_goods_receipts` by `gr_number` twice per batch (locked existing map + post-insert reload) | P1 | H | GR 70k confirm: 280 GR selects, 4.3 s (0 history) and 3.9 s (500k history, max 34 ms) (MEASURED). The averages fit an index range access, so the PO-style `forceIndex` is not yet justified for GR |
| S1-A3 | The whole-file confirm transaction holds the actor `users` row `FOR UPDATE`, every referenced PO `FOR UPDATE` (GR) and supplier `users` rows `LOCK IN SHARE MODE` (PO) for its whole duration; `InvoiceSubmissionService` locks the same rows | P0 | M | `LocalProcurementImportService::commit()` 290–312, `LocalProcurementMasterService::createImportBatch()` 35/42; `InvoiceSubmissionService.php:67,97`; `LocalGrReservationService.php:27`. With a 50 s lock wait timeout, a supplier invoice submission may wait or fail with error 1205 during a large import. Not measured |
| S1-A4 | A budget overrun is retried as an ordinary failure: `RuntimeException` → 3 tries × 30 s backoff, each re-running the whole transaction and re-holding the locks | P1 | H | `ProcessLocalProcurementImport` `$tries=3`, `$backoff=30`; `deadline()` throws `RuntimeException` |
| S1-A5 | Preview writes each consolidated record twice (insert, then `upsert` of normalized values/errors) and re-reads it; this is the dominant preview cost | P1 | H | GR 70k preview 50.0 s: 15.1 s record inserts/upserts and 17.8 s record selects (MEASURED). The keyset reads themselves are index range scans under 2 ms on an idle 21.7k-row import (MEASURED, EXPLAIN ANALYZE), so the cost is write/IO pressure, not query shape |
| S1-B1 | Realtime push latency is coupled to export duration; the `default` queue is undocumented | P0 | H | `BroadcastNotificationCreated implements ShouldBroadcast`; worker `--queue=exports,default` |
| S1-B2 | `--stop-when-empty` appears in three guides while README forbids it | P1 | H (docs) / ENV-UNKNOWN (prod cron) | D3; production diagnosis of 13 Oct (RUNNING at :02–:04 every minute) recorded in project memory, not re-verified |
| S1-B3 | The dev command does not run `imports` | P2 | H | D6 |
| S1-C1 | vs-best ranks the entire PO-linked quotation history with a window function on every request (twice when filtered: summary + page) and sorts the full current set by a computed expression | P1 | M | `buildVsBestQuery()`; PERF final: 303 ms SQL of 311 ms at 4.3k items, P95 1.53 s at local concurrency 25 (HISTORICAL). Current local data is too small (2 queries, 18–28 ms) |
| S1-C2 | The window function is now a hard DB requirement | P0 for deploy | H | D2; production engine/version ENV-UNKNOWN |
| S2-A1 | Finance dashboard: 21 queries; 6 KPI counts duplicate the grouped status count; three forecast paths each hydrate invoices + `currentVerification`; `whereDate()` on the DATE column `due_date` | P1 | H (shape) | MEASURED query log; `FinanceDashboardController.php:19-27`, `InvoiceQuery::dashboard()`, `PaymentForecastService` |
| S2-B1 | The unread notification count hydrates every unread row (with `data` JSON) and classifies in PHP; it runs every 30 s per visible tab, after every realtime push and after mark-read | P1 | M | `NotificationSummaryService::countsForUser()`; only index is `(notifiable_type, notifiable_id)`; backlog distribution ENV-UNKNOWN |
| S2-B2 | Badge polling continues at 30 s while Pusher is connected; each poll is a full web request (database session read/write in production) | P2 | H (code) | `layouts/app.blade.php:519`; the realtime handler already calls `updateBadges()` |
| S2-C1 | The chat drawer loads `messages.sender` twice and always loads the full history with attachments; full-page chat loads the full history and polls every 10 s without pausing on hidden tabs | P1 | H (code) | `ConversationMessageController::drawerShow()` 79–95; `Purchasing/Supplier ConversationController::show()`; `conversations/show.blade.php:486` |
| S2-D1 | Leading-wildcard `LIKE` in 9 files | P2 | M | No B-tree can serve `%term%` (PERF-05). Optimize only where measured |
| S2-E1 | `User::hasSupplierScope()` queries on every call; 14 of 24 supplier-dashboard queries | P1 | H | MEASURED; `User.php:119-122` |
| S2-E2 | Query-count growth on inter-supplier (3→8) and quotations (7→11) | P2 | M | MEASURED counts; cause not yet attributed (likely Mission 07 indicators) |
| S3-A1 | JS bundle 4.0× and CSS 3.6× raw vs PERF final; page-specific modules imported globally | P1 | H | MEASURED build sizes; `resources/js/app.js` imports 1–24 |
| S3-A2 | Blade component overhead dominates warm render; `x-ui.icon` rebuilds a ~100-entry alias map and calls `is_file()` per render | P1 | H | MEASURED: 81 icons ≈ 38.3 ms vs 1.4 ms direct |
| S3-B1 | Benchmark harness drift and coverage gaps | P1 | H | D7 |
| S3-C1 | Deployment caches: `icons:cache` is never built; the README path builds nothing | P2 | H (docs) | D5. Icon discovery costs only ~1–2 ms per process (MEASURED), so the gain is small |

Adjacent defect found during inspection (not a performance item; reported, not planned here): `InvoiceQuery::filtered()` applies `whereDate('submitted_at', ...)` to a UTC timestamp column with a business-date input. That bypasses `BusinessTime::toStorage()` and can misplace invoices submitted between 00:00 and 06:59 WIB. Raise it as a separate correctness ticket.

---

## 6. Baseline measurements (Gate 1)

### 6.1 Import capacity — current revision, isolated test DB

Command: `php tests/Support/local-procurement-import-benchmark.php <KIND> <ROWS> <HISTORY> 60 new csv`. The script is guarded to `adasi_portal_test` and removes its own fixtures. One serial run each, with no other test process on the test DB. Real database queue processing, budget 60 s.

| Run | Status | Preview s | Confirm s | Confirm deliveries | PHP peak MiB | Confirm SQL (queries / ms) |
|---|---|---:|---:|---:|---:|---|
| GR CSV 20,000, no history | COMPLETED | 10.1 | 6.7 | 1 | 60 | 343 / 4,775 |
| GR CSV 70,000, no history | COMPLETED | 50.0 | 22.6 | 1 | 62 | 1,143 / 17,030 |
| GR CSV 70,000, 500,000 existing GR | COMPLETED | 39.2 | 25.5 | 1 | 62 | 1,143 / 17,386 |
| PO CSV 70,000, no history | COMPLETED | 32.6 | 20.5 | 1 | 58 | 1,702 / 13,630 |

Top confirm costs:

| Run | Shape | ms | n | max ms |
|---|---|---:|---:|---:|
| GR 70k, no history | select `local_goods_receipts` | 4,345 | 280 | 149 |
| GR 70k, no history | insert `local_finance_audit_logs` | 4,251 | 141 | 257 |
| GR 70k, no history | insert `local_goods_receipts` | 4,241 | 140 | 175 |
| GR 70k, 500k history | insert `local_goods_receipts` | 4,300 | 140 | 183 |
| GR 70k, 500k history | select `local_procurement_import_records` | 4,252 | 142 | 203 |
| GR 70k, 500k history | select `local_goods_receipts` | 3,897 | 280 | 34 |
| GR 70k, 500k history | insert `local_finance_audit_logs` | 2,978 | 141 | 41 |
| PO 70k, no history | select `local_purchase_orders` | 4,822 | 560 | 27 |
| PO 70k, no history | insert `local_purchase_orders` | 3,583 | 140 | 37 |
| PO 70k, no history | insert `local_finance_audit_logs` | 2,949 | 141 | 43 |

Interpretation:

- Local headroom against the 60 s guard is 34–43% of budget used. That is a local, idle-database figure; production I/O, buffer pool and concurrent load are ENV-UNKNOWN.
- The 8 October confirm failures (63–64 s) were combined imports. The best-performing earlier single-kind runs (GR XLSX 70k confirm 45.8 s, PO XLSX 70k 43.2 s) were slower than today's CSV runs. Part of that difference is the reader format, and part is the shared, I/O-pressured test DB recorded in that report. Today's runs do not prove production capacity.
- PO history-scale behavior (existing POs) was not re-run in this gate; it is scheduled in Stage 1A-0.

### 6.2 Request-path shape (local, small data, warm, in-process)

`performance:profile-critical --json`, second warm run (MEASURED). These are not capacity numbers (§2.3).

| Path | Queries now | PERF final | SQL ms | Response ms | Bytes |
|---|---:|---:|---:|---:|---:|
| Supplier dashboard | 24 | 9 | 24.5 | 277 | 208,021 |
| PR DataTable | 4 | 4 | 5.2 | 64 | 9,947 |
| Inter-supplier | 8 | 3 | 7.7 | 145 | 300,624 |
| Historical comparison | 6 | 11 | 12.1 | 141 | 309,738 |
| vs-best data | 2 | 2 | 28.2 | 48 | 67,658 |
| Purchasing quotations | 11 | 7 | 11.8 | 229 | 334,105 |
| QC history | 6 | 5 | 4.9 | 24 | 8,764 |
| Notification counts | 2 | 2 | 2.4 | 21 | 191 |
| Chat unread count | 1 | 1 | 1.2 | 6 | 11 |
| Purchasing dashboard, PO DataTable, claims history | — | — | — | error | profiler drift (D7) |

The first run in a fresh process took up to 21 s for the supplier dashboard because views compiled on first render; the table above uses warm runs only.

Additional splits (MEASURED, warm):

- Supplier dashboard: controller 15 ms, Blade render 136–141 ms, 141 view/component renders (81 `x-ui.icon`, 10 `sidebar-item`, 9 `icon-button`).
- `x-ui.icon` ×81: 38.3 ms; equivalent `svg()` ×81: 1.4 ms.
- Finance dashboard (finance user): 21 queries, SQL 12.9 ms, controller 33 ms, render 110 ms.
- blade-icons first `svg()` per process without manifest: 1.3–1.9 ms.
- Staging keyset queries (`recordBatches`, `sourceBatches`) on a real 21.7k-row local import: 0.6 ms and 1.6 ms (EXPLAIN ANALYZE: PRIMARY range scan; `local_import_po_rows` range scan).
- Import `summary()` on that import: 11 queries, 410 ms total.

### 6.3 Frontend build (current `public/build`, MEASURED)

| Asset | Raw now | gzip -9 now | PERF final raw | PERF final gzip |
|---|---:|---:|---:|---:|
| `app-*.js` | 207,353 | 63,567 | 51,762 | 18,541 |
| `app-*.css` | 187,322 | 30,503 | 51,611 | 10,130 |
| lazy `local-procurement-import` | 24,711 | — | — | — |
| lazy `cally` | 24,588 | — | — | — |

Largest eagerly imported sources: `calendar.js` 55 KB, `server-tabs.js` 23 KB, `advanced-export.js` 19 KB, `unsaved-changes.js` 18 KB, `dashboard-editor.js` 17 KB, `customization-form.js` 16 KB, `supplier-audit-form.js` 16 KB, `async-form-submit.js` 13 KB, `notification-preferences.js` 12 KB; Alpine ESM is 106 KB unminified.

### 6.4 Not measured (ENV-UNKNOWN)

Production/staging HTTP P50/P95, cPanel entry processes/CPU/IO/memory, MySQL engine and version, OPcache, PCNTL, actual cron lines, queue age, unread-notification backlog per user, invoice/quotation/message cardinality, Lighthouse/Core Web Vitals.

---

## 7. Root-cause analysis

### 7.1 Import confirm (S1-A1..A4)

Lifecycle: `POST imports confirm` → `LocalProcurementImportService::confirm()` (locks actor and import, sets `IMPORTING`, dispatches in the same transaction) → worker `ProcessLocalProcurementImport::handle()` → `commit()`:

```
BEGIN
  users(actor) FOR UPDATE; local_procurement_imports(id) FOR UPDATE
  for each 500 staged rows by po_key: SELECT po ids FORCE INDEX(po_number_unique)       -- lock sweep
  for each 500 ids: SELECT ... FOR UPDATE ORDER BY id
  for each 500 records:
      PO kind: LocalPoImportService::validate() -> users/suppliers by LOWER(TRIM(company_name)) + PO conflict map
      createImportBatch(): PO map FOR UPDATE; (PO) users/supplier_scopes LOCK IN SHARE MODE
                           (GR) GR map FOR UPDATE
                           INSERT master; SELECT inserted rows again; INSERT audits (full JSON snapshot)
      deadline(60 s) checked after the batch
  audit summary; UPDATE import COMPLETED
COMMIT
```

- **Proven:** PO confirm repeats the PO lookup four times per batch and re-runs the supplier matching that preview already did. GR confirm looks up GR twice per batch. Both costs grow with file size and run inside the locked transaction.
- **Proven:** the commit is one transaction by design; whole-file atomicity is a required invariant. The work inside it is at least *rows × (master insert + audit insert)*, and that floor cannot be removed without changing the invariant.
- **Weakened hypothesis H1 (GR full scan):** at 500k history the GR lookups averaged ~14 ms per 500-key batch with a 34 ms maximum, which fits an index range access. Confirm with `EXPLAIN ANALYZE` in 1A-0 before deciding; do not add `forceIndex` without that plan.
- **Hypothesis H2:** the large per-statement maxima in the 8 October runs (single inserts up to 3–4 s) came from local I/O pressure: 128 MiB buffer pool, 100 MiB redo, binlog with flush=1, and other activity on the shared test DB. Today's quieter runs show maxima of 34–308 ms. Discriminating test: repeat on staging-sized InnoDB with `performance_schema` statement and wait events.
- **Proven:** the queue cannot tell a budget overrun from a transient failure, so the job retries up to three times.

### 7.2 Queue topology (S1-B)

| Queue | Producers | Worker today (docs) | Timeouts |
|---|---|---|---|
| `exports` | `ExportDispatcher`, `ProcessExportJob`, `GenerateWorkbookJob`, `FinalizeExportJob` | `exports,default` | job 600 s, `retry_after` 660 s |
| `default` | `BroadcastNotificationCreated` for every `SystemNotification` (and any dispatch without a queue name) | same worker, after `exports` | Laravel default |
| `imports` | `ProcessLocalProcurementImport` | separate `imports` worker | job 300 s, lease 600 s |
| `po-documents` | `LocalPoDocumentBatchService` | none (`async_enabled=false`) | unset |

Root cause of S1-B1: queue ordering plus a single process means a running export blocks every later broadcast. When `--stop-when-empty` is present, the cron minute adds up to 60 s on top.

### 7.3 Request paths

- **S2-E1:** `hasSupplierScope()` is `supplierScopes()->where()->exists()` with no memoization. It is called by `isImportEligible()`, `isLocalEligible()`, `PortalContext`, `EnforceSupplierDomain`, notification domain resolution and views.
- **S3-A2:** anonymous Blade components carry a fixed per-render cost (attribute bag, prop resolution, `@php` block). `x-ui.icon` adds alias-map construction, a regex and `is_file()` on every call, and icons are the most frequent component on every page.
- **S2-A1:** the KPI counts are the same aggregate as `InvoiceQuery::dashboard()['counts']`. The three forecast reads hydrate overlapping windows separately.
- **S2-B1:** classification needs PHP because legacy rows have no explicit `category`/`domain`; the fallback reads `event`, `local_invoice_id`, `title` and `message`. Exact counts therefore cost O(unread backlog) per poll.
- **S1-C1:** the "best historical item per material" is recomputed from all history on each request, and nothing limits the partition input to materials that appear in the current candidate set.

---

## 8. Proposed changes — exactly three stages

Each item lists evidence, code path, the smallest safe change, invariants, tests, benchmark, and risk/rollback. Items marked **DECISION** need user approval before implementation.

### STAGE 1 — Critical performance and runtime (P0)

#### 1A-0 Measurement first (no behavior change)

- Extend the guarded import benchmark to record per-statement `EXPLAIN ANALYZE` for the four PO lookups and the two GR lookups at 0, 100k and 500k history (PO and GR), and to capture `performance_schema` lock waits.
- Add a contention probe (test DB only, using the subprocess pattern of `local-gr-reservation-worker.php`). It runs a 70k GR confirm while a second process submits an invoice against a PO in the file, and records wait time and any 1205/1213 errors.
- Output: the baseline table for the 1A-1..1A-3 acceptance criteria.

#### 1A-1 Remove redundant lookups inside the commit transaction

- Evidence: S1-A1, S1-A2.
- Code path: `LocalProcurementImportService::commit()`; `LocalProcurementMasterService::createImportBatch()`; `LocalPoImportService::validate()`/`checkDatabaseConflicts()`.
- Smallest change, in order, one commit each, each re-measured:
  1. PO kind: stop calling the full `validate()` in commit and call only the field normalization it needs. Supplier eligibility is already re-checked under lock in `createImportBatch()` (`User::localEligible()` plus a `supplier_scopes` shared lock), and the PO conflict decision is already re-made against the locked PO map. Preview stores the resolved `supplier_id` in staging, so commit should not re-match supplier names. **Precondition:** a characterization test proving commit rejects every case `validate()` rejects today (supplier renamed or made ineligible after preview, conflicting existing PO, cancelled PO, PR conflict).
  2. Post-insert reload: keep reloading only the inserted numbers through the hinted index. Narrow the selected columns only if `recordCreatedBatch()` still produces byte-identical audit snapshots, which the existing audit regression test asserts.
  3. GR: apply `forceIndex('local_goods_receipts_gr_number_unique')` **only if** 1A-0's EXPLAIN shows a non-range access path at history scale (H1 is currently weakened).
- Invariants: whole-file atomicity; no upsert/ignore on masters; locks taken in ascending id order before writes; audit `after_values` byte-identical for the same input; supplier eligibility evaluated under lock at commit time.
- Tests: existing `LocalProcurementBackgroundImportTest` (2,001-row PO/GR, mixed UOM, rollback after a later batch, stale attempt, supplier deactivation) plus the new characterization cases; audit snapshot equality; `local-invoices:reconcile` clean.
- Benchmark: PO and GR 20k/70k at 0 and 500k history; compare confirm seconds, statements per batch and maximum statement time.
- Risk: medium (financial master path). Rollback: revert the individual commit; no schema change.

#### 1A-2 Fail fast on budget overrun

- Evidence: S1-A4.
- Change: throw a dedicated `ImportBudgetExceeded` from `deadline()`. In the job, mark the import `FAILED` with a specific, translated message and call `$this->fail()` instead of letting the queue retry. Transient errors (deadlock, lost connection) keep the existing retry path.
- **DECISION D-1:** retrying a budget overrun can succeed when transient contention caused it. Option A (recommended): fail fast and let the operator recheck and save again explicitly. Option B: keep retries but cap them at 1.
- Invariants: fencing by `owner_job_key`; a late delivery never downgrades a COMPLETED import; zero master/audit rows after rollback.
- Tests: forced budget of 1 s in a test; assert one delivery, FAILED, zero masters/audits, slot released.
- Risk: low. Rollback: revert.

#### 1A-3 Bound lock interference with invoice submission

- Evidence: S1-A3, to be measured by the 1A-0 contention probe.
- Options (**DECISION D-2**, chosen after measurement):
  - A. Document and enforce an operating window: imports above N rows run only outside invoice-submission hours (admission check on row count and business time). No transaction change.
  - B. Reduce lock scope: the actor `users` row lock serializes the actor's own import admission. Take it only in `confirm()` (it is already taken there) and not in `commit()`, after proving that no invariant inside commit depends on it.
  - C. Keep the locks and accept waits; show a clear "PO is being updated by an import" message on error 1205 in invoice submission.
- Do not split the file transaction (whole-file atomicity is a business invariant) and do not raise `commit_seconds`.

#### 1A-4 Preview write amplification (lower priority inside Stage 1)

- Evidence: S1-A5. Preview is not under the 60 s guard (its timeout is 300 s); GR 70k preview took 39–50 s.
- Change: run per-record validation in `consolidate()` before the first insert so each record is written once, or skip the `upsert` for records whose normalized values and errors did not change.
- Invariants: staging content and summary counters identical; `VALIDATION_FAILED` details unchanged.
- Tests: compare staged `values`/`errors` JSON before and after on the 2,001-row fixtures; summary counters equal.
- Risk: low to medium. Rollback: revert.

#### 1A-5 70k decision gate

If, after 1A-1..1A-3 on staging-shaped data, GR or PO 70k confirm still uses more than about 70% of the 60 s budget (provisional headroom rule, to be confirmed with the user), or invoice submission waits beyond an agreed limit, stop and present options instead of marking the feature production-ready: lower `max_rows` for confirm, schedule large imports off-hours, or approve a change to the whole-file atomicity rule. **DECISION D-3.**

#### 1B Queue worker topology for cPanel

- Evidence: S1-B1..B3, §7.2.
- Plan (documentation and cron lines; no code unless D-4 picks option C):
  1. Read-only discovery on the host: actual cron lines, `flock` path, `ps` of `queue:work`, PCNTL, `queue:failed`, oldest pending job per queue.
  2. **DECISION D-4** (realtime latency):
     - A. Three workers: `exports` (timeout 600), `imports` (300, memory 384) and `default` (short timeout, e.g. 60; broadcasts only), with separate lock files and logs. Cost: one more cron entry and process.
     - B. Two workers, reordered to `default,exports`. Broadcasts no longer wait behind *queued* exports, but they still wait behind a *running* export in that process.
     - C. Give `SystemNotification` broadcasts their own queue name and serve it in the imports worker (`imports,broadcasts`). Saves a process, but a running import (≤ 300 s) still blocks.
     - Recommendation: A, if the host's process limit allows four cron processes; otherwise B.
  3. Remove `--stop-when-empty` from all guides and align README (keep `--max-time=50`, `flock -n`, `--sleep=1`). Cost: each worker idles about 50 s per minute and polls `jobs` once per second.
  4. Keep `retry_after` (660) greater than every job timeout. If D-4 A adds a `default` worker with a shorter timeout, `retry_after` stays 660 because it is connection-wide.
  5. Make `composer dev` also listen to `imports` (a separate `queue:listen` entry) and correct the D4/D5 wording.
- Invariants: same-connection atomic handoff and `after_commit=false` for exports and imports; one worker per queue unless concurrency is deliberately designed; graceful `queue:restart` on deploy.
- Staging verification: export lifecycle, import preview/confirm, and a notification push timed from `SystemNotification` creation to browser receipt while a long export runs.
- Risk: operational. Rollback: restore the previous cron lines (record them first).

#### 1C Best price comparison (vs-best)

- Evidence: S1-C1, S1-C2. Current local data cannot reproduce the PERF timing.
- Steps:
  1. Build a synthetic dataset on the test DB (Stage 3B generator) at PERF-01 cardinality ×1 and ×5 (about 4.3k and 22k quotation items, with realistic material-name repetition).
  2. Run `EXPLAIN ANALYZE` on the page query and the summary query; record derived-table materialization time, rows and sort cost.
  3. Candidate change, only if the derived history dominates: restrict the ranked-history input to `material_name IN (SELECT material_name FROM current candidate set)` using the same status, soft-delete, availability and date filters. This is equivalent because ranking is partitioned by material and the outer join only consumes `rn = 1` for materials present in the current set. Prove equivalence with a test that compares the full JSON output (all columns including `best_price_idr`, and tie-breaking by `history_items.id`) before and after, across currencies with missing rates.
  4. When both summary and page are needed (filtered or keyword case), compute the history derived table once per request, provided EXPLAIN shows two materializations and the rewrite keeps Yajra's filter/order contract.
- Do not add indexes unless the plan shows a missing selective access path. Do not change ranking, currency conversion (`benchmarkFactorSql`), soft-delete or availability rules.
- Deployment prerequisite (S1-C2): record the production DB engine and version before Gate 2 deploys anything. If it is MySQL < 8.0 or MariaDB < 10.2, vs-best is already broken in production and that is a separate incident.
- Tests: `PriceComparisonPerformanceRegressionTest` (query count, legacy fields), the new output-equivalence test; `SupplierDataIsolationTest` is unaffected (Purchasing-only route).
- Risk: medium (analytics correctness). Rollback: revert; no schema change.

### STAGE 2 — Backend and database efficiency (P1)

#### 2A Finance dashboard

- Change 1 (no semantic risk): derive the six KPI counts from `InvoiceQuery::dashboard()['counts']` (same table, no scope) by summing the listed statuses, including the legacy statuses `UNDER_REVIEW`, `APPROVED`, `PAYMENT_SCHEDULED` and `COMPLETED`. Removes 6 queries.
- Change 2: replace `whereDate('due_date', '<', $today)` with `where('due_date', '<', $today)` in `InvoiceQuery::dashboard()` and in the `overdue` filter. `due_date` is a DATE column (MEASURED), so the comparison is identical and becomes sargable.
- Change 3 (measure first at synthetic 10k/50k invoices): fetch forecast invoices once for the union of the weekly-month window and the 6-month window, select only the columns that `getInvoicePayableAmount()` and the bucketing need, and reuse that collection for weekly and monthly buckets. BCMath/`Money` computation, the `ready_to_pay_at ?? approved_at` fallback, business-time bucketing and status exclusions stay in PHP unchanged.
- Tests: `PaymentForecastAndReportingTest`, `UnifiedPaymentEngineTest`, and a new test asserting that KPI and forecast arrays are identical (exact strings) before and after, on a fixture covering legacy statuses, null `ready_to_pay_at`, month boundaries at 00:00 WIB, and verification present/absent.
- Benchmark: query count, SQL ms and peak memory at 10k/50k invoices.

#### 2B Notifications

- Step 1 (ENV): a read-only production query of the unread backlog distribution per user (count, P95, max), without reading any content. If P95 is small, stop after Step 3.
- Step 2: narrow the count query projection to `id` plus the JSON fields that classification actually reads (`event`, `category`, `domain`, `silent`, `local_invoice_id`, `title`, `message`, and any other key `resolveDomain()` reads) instead of the whole `data` column. Keep PHP classification so counts remain exact.
- Step 3: evaluate an index on `(notifiable_type, notifiable_id, read_at)` with `EXPLAIN ANALYZE` at the measured backlog size. Add it only if it changes the plan materially (reversible migration, created online).
- Step 4 (**DECISION D-5**): when Echo reports `connected`, slow badge polling from 30 s to 120 s, and return to 30 s on `disconnected`/`unavailable`. Realtime pushes already trigger `updateBadges()`. Trade-off: read state changed in another tab may lag by up to 120 s.
- No caching of counts: invalidation would span `NotificationService`, mark-read, mark-all-read, preference changes and mutes.
- Tests: `NotificationControllerTest`, `NotificationSilentDeliveryTest`, `NotificationCategoryOrderTest`, the domain tests, and a new property-style test comparing old and new counts over a randomized fixture of legacy and explicit-category rows for every role/scope context.

#### 2C Chat

- Change 1: remove the duplicate `messages.sender` eager load in `drawerShow()` and load once with `sender` and `attachments`.
- Change 2 (**DECISION D-6**): bounded history. The initial load returns the latest 50 messages (keyset by `id DESC`, returned ascending). A `before=<id>` parameter on a `conversations.messages.older` route returns the previous 50. Drawer and full page get a "Load older messages" control. Mark-read semantics stay "opening marks all unread partner messages as read".
- Change 3: pause full-page polling when `document.visibilityState === 'hidden'` (the drawer already stops when closed), and validate `after`/`before` as positive integers.
- Invariants: `ConversationPolicy::view` on every endpoint; attachment URLs via `attachments.show` and `AttachmentPolicy`; chronological order; read receipts.
- Tests: `ChatDrawerDraftIsolationTest`, `ConversationUnreadCountTest`, and new tests for pagination boundaries, foreign-conversation 403, older-page ordering and attachments on older pages.
- Benchmark: a synthetic conversation with 2,000 messages and 200 attachments: response bytes, queries, ms.

#### 2D Search / DataTables

- Measure every leading-wildcard search at synthetic volume (Stage 3B) and act only where a search costs more than the agreed threshold. Candidate pattern: replace `orWhereHas` correlated subqueries with a joined or semi-join form where the plan proves it cheaper. Restrict searched columns only with user approval. No FULLTEXT (it changes matching semantics) and no speculative indexes.

#### 2E Request-scoped supplier scope memoization

- Change: memoize scope values on the `User` instance (load `supplierScopes` once per instance; `hasSupplierScope()` reads the loaded relation). Clear the memo wherever scopes are written in the same request (`SupplierRegistrationService` approval, admin user edit, scope sync) with `unsetRelation('supplierScopes')` or `refresh()`.
- Risk check: queue workers re-resolve users per job (`User::find`), so there is no cross-job staleness; Octane is not used.
- Tests: `SupplierDataIsolationTest`, `LocalInvoiceScopeIsolationTest`, supplier-context tests, registration approval tests, and a new test that a scope granted or revoked mid-request is visible to the next check.
- Benchmark: supplier dashboard queries from 24 to about 10.

#### 2F Attribute remaining query growth

- Attribute the inter-supplier (3→8) and quotations (7→11) growth with query logs. Fix only N+1 patterns, not intentional Mission 07 lookups. Update the `PriceComparisonPerformanceRegressionTest` ceilings to the verified counts.

### STAGE 3 — Frontend, benchmark and deployment readiness (P2)

#### 3A Frontend

- Step 1: attribute the bundle by module with `vite build --sourcemap` and a small Node script that sums source-map segment lengths per source file (no new dependency).
- Step 2: move page-specific modules behind DOM-marker dynamic imports, following the existing `data-local-import` pattern: `supplier-audit-form`, `dashboard-editor`, `customization-form`, `notification-preferences`, `regional-sample`, `po-document-upload`, the export column designer/advanced export (if not used on every page), and the non-core parts of `calendar`.
- Alpine constraint: components registered with `Alpine.data()` must exist before `Alpine.start()`. Await the marker-gated imports before `start()` (bounded by the markers present on the page). This keeps all component names and selectors unchanged.
- Step 3: reduce `x-ui.icon` render cost by moving the alias map and name resolution into a PHP class with a per-process static cache (the `is_file` result memoized per name), keeping the Blade output byte-identical. Expected saving is about 0.4 ms per icon (about 30 ms on the supplier dashboard).
- Step 4: inspect CSS growth (`app.css` source is 158 KB of hand-written tokens and adapters) and remove only provably unused rules.
- Tests: all `tests/js/*.test.mjs` files, `FrontendAssetLoadingTest`, `UserDashboardLayoutEditorTest`, `TranslationParityTest`, a new HTML snapshot test for `x-ui.icon` (all aliases, sizes, `aria-*`, classes, the WhatsApp special case), and `npm.cmd run build`.
- Browser verification where a browser is available (loopback harness): no console errors; Alpine components, calendar, server tabs, dashboard editor and advanced export modal initialize.

#### 3B Benchmark protocol and load testing

- Repair `performance:profile-critical` (D7) and add paths: Finance dashboard, Finance invoice list/DataTable, Local PO list and GR feed, Supplier local invoices, notifications summary/counts, chat drawer show, vs-best with keyword. Keep it restricted to local/testing.
- Synthetic dataset generator: an artisan command restricted to `APP_ENV=testing` and database names ending in `_test`, idempotent by prefix, removing only its own rows. Cardinalities: ×1 and ×5 of PERF-01, plus 50k local invoices, 500k GR, users with 0/100/2,000 unread notifications, and a 2,000-message conversation. `StressTestSeeder` and `ProductionDummySeeder` stay unused.
- Per endpoint, record dataset size, query count, SQL ms, P50/P95 (20 warm runs), peak memory, response bytes and error rate; for queue work, waiting time and processing time.
- Roles: Admin, Purchasing, Finance, Accounting, QC, GA, Supplier Import, Supplier Local, plus a dual-scope supplier. Permission boundaries are validated separately per endpoint by a route × role matrix test (allowed → 200, foreign resource → 403/404), reusing the existing isolation tests.
- HTTP load: only on authorized, isolated staging, from a separate client, with stepped concurrency (1/5/10/25/50) and stop conditions (5xx, connection exhaustion, CPU throttling, P95 explosion, queue backlog growth). The tool choice depends on the environment and is not a project dependency.
- Lighthouse and Core Web Vitals: only when a browser is available; record mobile and desktop for login, supplier dashboard, finance dashboard and PO list.

#### 3C Deployment readiness

- Record on the host (read-only): PHP web SAPI and CLI versions and extensions (pdo_mysql, bcmath, mbstring, fileinfo, zip, xmlreader, dom, pcntl), OPcache settings, MySQL/MariaDB version (window functions, JSON functions, `FORCE INDEX`), `max_connections`, `innodb_lock_wait_timeout`, cron lines, `flock`, writable paths, session/cache drivers, `public/build/manifest.json` consistency, and `Cache-Control`/`Content-Encoding` headers.
- Deployment command set: `config:cache`, `route:cache`, `view:cache`, `icons:cache` (or `optimize`), then `queue:restart`; never `optimize:clear` alone. Fix README (D5).
- Monitoring: oldest job age per queue, failed jobs, import FAILED/rolled-back count, export duration, a notification push latency sample, and the slow query log on staging.

---

## 9. Implementation order and dependencies

```
Stage 1:  1A-0 ─▶ 1A-1 ─▶ 1A-2 (D-1) ─▶ 1A-3 (D-2) ─▶ 1A-4 ─▶ 1A-5 gate (D-3)
          1B discovery ─▶ 1B docs/cron (D-4)                        (independent of 1A)
          3B generator (subset) ─▶ 1C measurement ─▶ 1C change      (generator pulled forward)
Stage 2:  2E ─▶ 2F;  2A;  2B step 1 (ENV) ─▶ 2B (D-5);  2C (D-6);  2D (after generator)
Stage 3:  3B profiler repair + full generator ─▶ 3A ─▶ 3C ─▶ staging load test
```

The synthetic generator (3B) is a dependency of 1C and 2D, so its minimal subset is built at the start of Stage 1 and completed in Stage 3. Each stage ends with a report; the next stage starts only after the user accepts it.

---

## 10. Alternatives and trade-offs for expensive changes

| Change | Alternative | Why not (now) |
|---|---|---|
| Fewer lookups in import commit | Raise `commit_seconds` | Explicitly prohibited; hides cost and extends lock time |
| | Split confirm into per-batch transactions | Violates whole-file atomicity; needs business approval (D-3) |
| | Write audits outside the transaction | Audits must commit atomically with masters |
| Queue topology | Redis / Supervisor | Not available or not justified on cPanel; the handoff guarantees depend on same-connection database transactions |
| vs-best partition restriction | Persisted best-price table maintained on PO creation | New schema plus invalidation on rate/soft-delete changes; only if partition restriction is insufficient |
| Notification counts | Cache per user | Invalidation spans five write paths; rejected unless backlog evidence forces it |
| | Persist category/domain at write time and count in SQL | Legacy rows still need the PHP fallback; larger change; considered only if Steps 2–3 are insufficient |
| Chat pagination | Infinite scroll with IntersectionObserver | More frontend risk; an explicit "load older" control comes first |
| Bundle split | Separate Vite entries per role | Changes `@vite` calls in many layouts; dynamic import keeps one entry |

---

## 11. Risk matrix and regression coverage

| Risk | Likelihood | Impact | Mitigation | Required coverage |
|---|---|---|---|---|
| Import commit accepts a row the old `validate()` rejected | Low | High (financial master) | Characterization tests first; supplier/PO checks under lock | `LocalProcurementBackgroundImportTest` + new cases; `local-invoices:reconcile` |
| Audit snapshot drift | Low | High | Byte-equality test of `after_values` | existing audit regression |
| A lock-order change creates deadlocks | Low | High | Keep ascending-id lock order; concurrency probe | `LocalInvoiceConcurrencyTest`, `LocalGrReservationConcurrencyTest`, new import/invoice probe |
| Wrong worker topology on cPanel | Medium | Medium | Record current cron lines; staged change; rollback lines | staging export/import/push smoke |
| vs-best output change | Medium | Medium | Full-output equivalence test incl. ties and missing rates | `PriceComparisonPerformanceRegressionTest` + new |
| Finance KPI/forecast drift | Low | High | Exact-string equality test incl. legacy statuses and 00:00 WIB boundaries | `PaymentForecastAndReportingTest` + new |
| Notification count drift by role/scope | Medium | Medium | Randomized old-vs-new comparison per role/context | notification suites |
| Supplier scope memo staleness | Low | High (isolation) | Clear on write paths; test mid-request grant/revoke | isolation suites |
| Chat history becomes unreachable in the UI | Low | Medium | Older-page endpoint + tests; policy on every endpoint | chat tests |
| Alpine component not registered before start | Medium | Medium | Await gated imports before `Alpine.start()`; browser check | JS tests + browser harness |
| Icon markup change | Low | Low | HTML snapshot test | new snapshot test |
| Benchmarks pollute the shared test DB | Medium | Low | Serial runs, process check, prefix cleanup, optional isolated schema | — |

---

## 12. Acceptance criteria

Targets marked *provisional* are proposals to confirm with the user after the staging baseline. They are not measured results.

| Item | Criterion |
|---|---|
| 1A-1 | PO confirm issues ≤ 2 PO lookups per 500-row batch; GR lookups use an index range access at 500k history (EXPLAIN); master/audit counts and audit JSON identical; all import suites green |
| 1A overall | GR and PO 70k confirm complete in one delivery under the unchanged 60 s guard at 0 and 500k history on the test DB (met today: 20.5–25.5 s); *provisional:* ≤ 42 s (30% headroom) on staging-shaped InnoDB |
| 1A-2 | A budget overrun produces exactly one delivery, FAILED status and zero master/audit rows |
| 1A-3 | Invoice submission wait during a concurrent 70k GR confirm is measured and within the limit agreed in D-2 |
| 1B | The documented and deployed worker set covers `exports`, `imports` and `default`; no guide contains `--stop-when-empty`; staging push latency during a running export is measured; zero new failed jobs in smoke |
| 1C | Output-equivalence test green; vs-best SQL time at ×1 and ×5 synthetic cardinality reduced against the measured baseline (*provisional:* ≥ 40%) |
| 2A | Finance dashboard ≤ 15 queries; KPI and forecast arrays identical |
| 2B | Counts identical across roles/contexts; memory and time no longer grow with backlog beyond the projection cost (measured at 0/100/2,000 unread) |
| 2C | Initial drawer payload bounded to 50 messages; older pages retrievable; no policy regression |
| 2E | Supplier dashboard query count ≤ 10 with identical HTML |
| 3A | Initial-load JS gzip reduced on pages without the gated features (*provisional:* ≤ 35,000 B gzip on the supplier dashboard); all JS tests and build green; no console errors in the browser check |
| 3A icon | `x-ui.icon` HTML byte-identical; 81-icon render ≤ 10 ms locally |
| 3B | The profiler covers all listed paths without errors; the protocol produces P50/P95/memory/bytes per endpoint and the role matrix passes |
| 3C | The evidence checklist in `CPANEL-PERFORMANCE-RUNTIME.md` §11 is filled from the host |
| Global | Full `composer test` green (serial), `pint --test` on changed files, `npm.cmd run build`, `git diff --check`, `local-invoices:reconcile` clean |

A stage is not "complete" on unit tests alone where its criterion needs benchmark or environment evidence; such items are reported as "implemented, environment verification pending".

---

## 13. Deployment and rollback strategy

- One stage per release; each release contains only that stage's commits.
- No schema change is planned except an optional notifications index (2B Step 3). If added, it is additive and reversible, created on staging first, with `SHOW INDEX` before and after, in a low-traffic window.
- Import changes: pause import admission (`local_procurement_imports.paused=true`), drain `imports`, deploy, `queue:restart`, unpause. Rollback is the same sequence with the previous release.
- Cron changes: record the existing lines verbatim before editing, change one worker at a time; rollback restores the recorded lines.
- Frontend: deploy the full matching `public/build` set and keep the previous build files until the rollback window closes.
- After each deploy: the smoke checks from cPanel guide §9, plus one PO and one GR import on staging, one export, one realtime push, and a `queue:failed` review.

---

## 14. Open blockers requiring staging/cPanel access

| # | Blocker | Needed for |
|---|---|---|
| B1 | Production DB engine and version | 1C, S1-C2 (window functions are required now) |
| B2 | Actual cron lines, `flock`, PCNTL, process limits | 1B, import timeouts |
| B3 | A staging environment with production-shaped data and exclusive use | 1A acceptance, 1C, 3B load test |
| B4 | Read-only production statistics: unread notifications per user, invoices per month, quotation-history size, messages per conversation | 2A–2C prioritization |
| B5 | A browser environment for Lighthouse and interaction checks | 3A, 3B |
| B6 | Decisions D-1..D-6 | the respective items |

---

## 15. Explicitly out of scope

- Raising `commit_seconds`, removing the transaction guard, splitting the whole-file transaction, or partial commits.
- Redis, Memcached, Supervisor/systemd, Docker, Reverb, a CDN, Octane, new queue backends, or any new Composer/npm dependency.
- Changing financial calculations, rounding, exchange-rate snapshot rules, invoice/payment state machines, audit content, or authorization rules.
- FULLTEXT search or any change to search semantics.
- Load or destructive tests against production; mutating operational data; running `StressTestSeeder`/`ProductionDummySeeder`.
- The `whereDate('submitted_at')` timezone defect (separate correctness ticket), extraction of the large inline layout JS/CSS (deferred until browser contract coverage exists), and UI redesign work.

---

## Appendix A — Commands executed in Gate 1

All were read-only against the application database, except the guarded import benchmark, which writes and then removes its own fixtures in `adasi_portal_test`.

```text
git rev-parse HEAD; git status --short
php artisan migrate:status; php artisan db:show
php artisan tinker --execute '<row counts, SHOW INDEX, @@ variables, EXPLAIN ANALYZE of staging keyset queries, import summary timing>'
php artisan performance:profile-critical --json        (4 runs; first run cold)
php artisan tinker --execute '<supplier/finance dashboard query log and render split; x-ui.icon and svg() micro-benchmarks>'
php tests/Support/local-procurement-import-benchmark.php GR 20000 0 60 new csv
php tests/Support/local-procurement-import-benchmark.php GR 70000 0 60 new csv
php tests/Support/local-procurement-import-benchmark.php PO 70000 0 60 new csv
php tests/Support/local-procurement-import-benchmark.php GR 70000 500000 60 new csv
gzip -9 -c public/build/assets/app-*.{js,css} | wc -c
```

Raw benchmark outputs were written outside the repository (`%TEMP%\adasi-gate1\`).
