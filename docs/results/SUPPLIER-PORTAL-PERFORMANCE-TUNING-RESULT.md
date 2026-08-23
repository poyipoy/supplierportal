# Supplier Portal Performance Tuning Result

## 1. Executive Summary

The repository-level performance program completed missions PERF-00 through PERF-09 in dependency order. The work removed verified global-request database work, eliminated critical N+1 patterns, bounded high-cardinality detail rendering, reduced PR/PO list hydration, preserved historical exchange-rate and quotation-amount rules, made database-queue export handoffs durable, removed four proven redundant indexes through a reversible pending migration, reduced the production Vite JavaScript bundle by 48.6%, and added cPanel-compatible static-cache/runtime guidance.

The strongest measured local improvements are:

- PR DataTable: 5 to 4 queries; 122.73 ms baseline observation to 29.80 ms final warm median; response size down 60.3%.
- PO DataTable: 12 to 6 queries; 86.59 ms to 29.50 ms; response size down 54.0%.
- Inter-supplier comparison: 43 to 3 queries; 128.38 ms to 38.68 ms.
- vs-best comparison: 61 to 2 queries; 445.99 ms to 311.12 ms, with the remaining complex SQL now clearly isolated.
- A 2,000-row monthly history Blade response: 1,195.65 ms to 239.19 ms, 88 MB to 38 MB peak memory, and 5,216,555 B to 310,149 B while preserving the complete chart and summary.
- Vite JavaScript: 100,648 B to 51,762 B raw and 36,719 B to 18,541 B gzip.

Final functional verification passed: 262 tests and 2,791 assertions. A read-only local Stage-A concurrency replay reached 25 parallel profiler processes without a process failure or application-path error. This is not an HTTP or production capacity test.

The final verdict is `PERFORMANCE_TARGET_PARTIALLY_MET`. Repository targets are materially improved and regression-verified, but production Core Web Vitals, cPanel resource limits, OPcache, compression, HTTP/2, Cron/worker operation, and sustainable 50–200-user concurrency remain unmeasured.

## 2. Environment & Assumptions

| Item | Observed / assumed value |
|---|---|
| Repository branch / baseline | `master` / `70b77455bcbc46b8bdc6ca324c9341bfce3f0286` |
| Local application | Laravel 12.66.0; PHP 8.2.30 CLI; environment `local` |
| Local database | MySQL 8.0.30; database `adasi_portal` |
| Local drivers | file cache, file session, database queue, Pusher broadcasting |
| Production assumption | shared hosting/cPanel, PHP 8.2+, MySQL/MariaDB, database cache/session/queue, Pusher plus polling fallback |
| Target usage | approximately 50–200 active concurrent users; sustainable simultaneous dynamic requests depend on cPanel/CloudLinux quotas |
| Representative local data | 2,067 PRs; 4,343 PR items; 2,132 quotations; 4,351 quotation items; 2,052 POs; 2,056 PO links |
| Low-volume local data | 438 notifications; 17 conversations; 59 messages; 11 claims; 26 QC inspections |

Measurements use a read-only CLI application-path profiler. It renders controllers/views and DataTables JSON in process and records Laravel query-log count/time, wall time, peak PHP memory, and response bytes. It excludes HTTP server/middleware/session transport, network/CDN behavior, browser rendering, and cPanel quotas. Final P95 values come from 20 sequential warm local runs; baseline timings are single warm observations and are not baseline P95 values.

The existing local dataset was used. `StressTestSeeder` and `ProductionDummySeeder` were not run because the former is unsafe/non-repeatable and the latter deletes operational data. No production database, operational local database migration, destructive load test, browser automation, commit, push, or pull request was performed.

## 3. Context Files Read

The following mandatory context files were read completely before implementation:

- `AGENTS.md`
- `CLAUDE.md`
- `claudes-cognitive-framework-for-laravel-development.md`
- `C:\Users\BAHRIALGI\Downloads\SUPPLIER-PORTAL-COMPREHENSIVE-PERFORMANCE-TUNING-MASTER-PLAN.md`

No mandatory file was missing. No nested or directory-specific `AGENTS.md` exists for the changed files. Current routes, controllers, models, services, migrations, schema/indexes, policies, configuration, Blade, JavaScript, exports/imports, jobs, and tests were inspected rather than treating documentation as current implementation truth.

## 4. Documentation Drift Found

1. `AGENTS.md` describes 48 migrations; the repository now has 51 migration files: 50 applied locally plus the new pending performance migration.
2. The original `.env.example` documented `BROADCAST_CONNECTION=log` and Reverb placeholders, while the active application intent is Pusher with 30-second polling fallback. The example now documents Pusher.
3. Existing README Cron examples contain account-specific paths. The canonical cPanel guide uses placeholders that must be discovered on the target account.
4. `bootstrap/app.php` reads `TRUSTED_PROXIES` directly through `env()`. This is a config-cache compatibility risk if a proxy topology later relies on it; the direct-SSL baseline leaves it empty.
5. Local cache/session drivers are file-backed, while the documented production baseline is database-backed. Local request measurements therefore do not prove database session/cache cost on cPanel.
6. `StressTestSeeder` assumes an accessor-only `total_weight` exists on query-builder rows and uses fixed/manual identifiers without safe cleanup or idempotency. It was not used.
7. The potential duplicate `quotations.submitted_at` index identified by the master plan was confirmed, together with three additional redundant index shapes.
8. Target cPanel MySQL/MariaDB version and window-function support are unknown. A locally faster exact vs-best window-query prototype was therefore not made a deployment requirement.

## 5. Baseline Metrics

Warm PERF-01 local baseline:

| Path | Queries | SQL ms | Response ms | Peak MB | Bytes |
|---|---:|---:|---:|---:|---:|
| Purchasing Dashboard | 9 | 5.63 | 184.90 | 40 | 460,828 |
| Supplier Dashboard | 12 | 6.11 | 121.39 | 42 | 454,733 |
| PR DataTable | 5 | 37.03 | 122.73 | 44 | 262,636 |
| PO DataTable | 12 | 6.46 | 86.59 | 44 | 221,735 |
| Inter-supplier comparison | 43 | 20.26 | 128.38 | 46 | 429,804 |
| Historical comparison | 14 | 36.27 | 156.15 | 46 | 513,860 |
| vs-best DataTable | 61 | 425.85 | 445.99 | 46 | 110,487 |
| Purchasing quotations | 10 | 6.72 | 156.34 | 46 | 509,157 |
| QC history | 5 | 2.02 | 21.58 | 46 | 40,581 |
| Purchasing claims | 5 | 1.62 | 13.40 | 46 | 47,704 |
| Notification counts | 2 | 1.29 | 5.45 | 46 | 192 |
| Notification summary | 2 | 0.93 | 13.03 | 46 | 50,607 |
| Chat unread count | 1 | 0.56 | 2.15 | 46 | 11 |

Baseline frontend production assets were 100,648 B raw / 36,719 B gzip JavaScript and 51,602 B raw / 10,112 B gzip CSS. Local Vite and stable public assets had no explicit `Cache-Control` policy.

Core Web Vitals, production HTTP P50/P95, cPanel resource graphs, OPcache, queue Cron behavior, database-backed production session/cache cost, and production export/PDF memory remain `NOT MEASURED — ENVIRONMENT REQUIRED`.

## 6. Database Findings

| Finding | Classification | Result |
|---|---|---|
| Duplicate `quotations.submitted_at` index | `OPTIMIZATION OPPORTUNITY` | Verified equivalent key retained; redundant key scheduled for removal. |
| Duplicate PR/PO number indexes | `OPTIMIZATION OPPORTUNITY` | Retained unique indexes preserve lookup performance and numbering invariants. |
| Redundant `po_quotations(po_id)` | `OPTIMIZATION OPPORTUNITY` | Retained `(po_id, quotation_id)` unique left-most prefix provides the access path. |
| Missing simple index for vs-best | `NOT CURRENTLY JUSTIFIED` | `EXPLAIN ANALYZE` showed derived-history materialization dominates; no missing selective simple key was proven. |
| Leading-wildcard filters | `OPTIMIZATION OPPORTUNITY` | Normal B-tree additions would not make `%keyword%` selective; no speculative index was added. |

Read-only local plans showed MySQL selecting the retained `quot_submitted_at_index` for submitted-date range/order, unique PR/PO indexes for exact document lookups, and equivalent `ref` access through the retained pivot composite key.

The pending reversible migration removes only:

- `quotations_submitted_at_index`
- `pr_number_index`
- `purchase_orders_po_number_index`
- `po_quotations_po_id_index`

It verifies retained coverage before each drop and restores the canonical pre-migration index definitions in `down()`. Unique constraints, foreign keys, atomic document numbering, business data, and transaction/locking rules are unchanged. Production DDL algorithm, lock time, reclaimed bytes, and write-latency benefit require staging/production measurement.

## 7. Backend Findings

- `VERIFIED BOTTLENECK`: authenticated full-page rendering loaded notification details and hydrated chat state before polling repeated the work. The initial response now performs neither; notification details load on dropdown demand, badge counts still poll immediately, and realtime/polling contracts remain.
- `VERIFIED BOTTLENECK`: chat drawer formatting executed unread-count work per conversation. One relationship aggregate now supplies projected unread counts; global unread count is one membership-constrained message aggregate.
- `VERIFIED BOTTLENECK`: inter-supplier eligible-PR rendering lazily loaded items. The selector now uses one constrained eager-load plus `withCount()`: 40 selector queries after the global-cost phase became 3.
- `OPTIMIZATION OPPORTUNITY`: PR list hydrated items only to count them. It now uses `withCount()` and ignores display projections during Yajra's total count.
- `VERIFIED BOTTLENECK`: PO list hydrated a large nested graph and calculated totals in PHP. Narrow projections, scalar claim/QC IDs, and a shared SQL total projection reduced 12 queries to 6 and approximately 304 hydrated models/pivots to about 90 on a representative page.
- Financial equivalence was checked for all 2,052 local POs: zero mismatches above `0.00001`; maximum floating-point delta approximately `1.86e-9`. Stored positive amounts, legacy zero-amount recalculation, quantity floor, four-decimal rounding, snapshot rates, soft deletes, and fallback semantics remain.
- `VERIFIED BOTTLENECK`: monthly history rendered/hydrated every detail row and duplicated payload in JavaScript. A complete lightweight chart/summary series remains, while detail rows are server-paginated at 50 per page.
- `VERIFIED BOTTLENECK`: vs-best repeated complex SQL and did model lookups per row for Hashids. It now executes two broad no-search queries and encodes trusted query-result IDs directly. Legacy numeric JSON fields and canonical Hashid link behavior remain covered.
- A supplier PO PDF ownership gap found during performance inspection was fixed. Supplier access is now constrained by `purchase_orders.supplier_id = auth()->id()`; own PDF returns PDF and foreign PDF returns 404.

No supplier isolation, role middleware, authorization, document numbering, currency, exchange-rate snapshot, quotation amount, PO consolidation, soft-delete, or transaction invariant was weakened for performance.

## 8. Frontend Findings

| Finding | Classification | Result |
|---|---|---|
| Axios bundled with no application call site | `VERIFIED BOTTLENECK` | Removed runtime import and npm dependency. |
| DataTables loaded on all authenticated pages | `VERIFIED BOTTLENECK` | CSS/two JS files now load only on the 13 entry views that initialize DataTables; 39 entry views skip them. |
| Bootstrap, jQuery, SweetAlert global loading | `NOT CURRENTLY JUSTIFIED` for removal | Repository usage remains load-bearing for compatibility, components, confirmations, and legacy AJAX. |
| Chart.js loading | `NOT CURRENTLY JUSTIFIED` for change | Already page-scoped to chart pages. |
| Large stable inline layout CSS/JS | `OPTIMIZATION OPPORTUNITY` | Extraction deferred because blocks include Blade/config/auth behavior and browser regression evidence is unavailable. |
| 396,086 B login hero image | `LIKELY BOTTLENECK — MEASUREMENT REQUIRED` | Dimensions/bytes inspected; browser decode and LCP effect not measured. |

Final Vite output:

| Asset | Before raw | After raw | Before gzip | After gzip |
|---|---:|---:|---:|---:|
| JavaScript | 100,648 B | 51,762 B | 36,719 B | 18,541 B |
| CSS | 51,602 B | 51,611 B | 10,112 B | 10,130 B |

The generated production build completed. Browser automation was prohibited; desktop/mobile rendering, DataTables interaction, 2,000-point chart behavior, and Lighthouse LCP/INP/CLS remain `MANUAL_VISUAL_QA_REQUIRED`.

## 9. Queue/Export/Import Findings

- `VERIFIED BOTTLENECK`: the development queue listener ignored the `exports` queue. `composer dev` now consumes `exports,default` with three tries and a 600-second timeout.
- `VERIFIED BOTTLENECK`: hard worker termination could leave an export `processing` without a durable Excel chain. Export construction/counting now occurs while the record is still queued; the state transition and database-queue root insertion then commit/rollback in one same-connection transaction.
- Initial `ExportJob` creation and `ProcessExportJob` insertion are also one transaction, closing the queued-orphan window.
- Atomic handoff enforces the database queue, `after_commit=false`, and the same Laravel database connection as `export_jobs`. A future external/separate queue requires an outbox/idempotency design.
- Duplicate launcher delivery is serialized; terminal completed/failed transitions are row-locked; a late failure cannot downgrade or delete a completed file.
- Four large `FromQuery` exports reuse one cached scalar count through `WithCustomQuerySize`; detail exports use narrow scalar counts; supplier-history rows are built once in the launcher and excluded from serialized chain-job state.
- Existing local history: 44/44 exports completed; lead-time min/average/median/nearest-rank P95/max was 0/13.2/3/77/215 seconds. These are historical queue-to-completion lead times, not production generation P95.
- The current `jobs` table was empty during read-only inspection. Twenty-one historical `failed_jobs` were Pusher broadcast failures, not export failures.
- Imports remain synchronous because previews are read-only, bounded to 1,000 non-empty rows, and require immediate validation feedback. Queueing is `NOT CURRENTLY JUSTIFIED` without production duration/memory evidence.
- Current single-document PO/QC PDFs remain synchronous; local maximum item counts were six and an own one-item PO PDF test took about 0.51 seconds including test boot. Batch/large production evidence is required before queueing.

No operational export was dispatched merely to manufacture a benchmark.

## 10. cPanel Runtime Findings

- Added one-year immutable caching for content-hashed `/build/assets/*` and one-day revalidating caching for stable `/assets/*`; dynamic HTML/JSON/download/private paths are not made public-cacheable.
- Added guarded gzip configuration for text assets when `mod_deflate` exists. Brotli remains a provider/LiteSpeed setting to avoid an order-dependent dual filter chain.
- Local header verification changed Vite CSS from no `Cache-Control` to `public, max-age=31536000, immutable`, and a stable asset to `public, max-age=86400, must-revalidate`. `/up` remained `no-cache, private`; `/login` remained `no-store, private`.
- Local Apache 2.4.54 does not load deflate, Brotli, or HTTP/2, so production compression/transport remains unverified.
- Config, route, and view caches built successfully together; five notification routes remained available; caches were cleared after verification.
- Scheduler inventory shows daily auth-log pruning and export cleanup.
- The canonical guide documents an every-minute, short-lived, non-overlapping cPanel worker for `exports,default`, verified paths, `--stop-when-empty`, `--max-time=50`, `--tries=3`, and `--timeout=600`. `DB_QUEUE_RETRY_AFTER=660` must remain greater than the timeout.
- Redis, Memcached, Supervisor/systemd, Reverb, containers, microservices, a dedicated broker, and a mandatory CDN are `NOT CURRENTLY JUSTIFIED`.

Production OPcache, PHP limits, HTTP/2, compression, public headers, scheduler/worker execution, filesystem permissions, CloudLinux resource usage, MySQL connections, and queue backlog are `NOT MEASURED — ENVIRONMENT REQUIRED`.

## 11. Changes Implemented

1. Added a local/testing-only repeatable read-only critical-path profiler.
2. Removed synchronous global notification detail/chat hydration and base64 logo generation; added lazy notification summary and aggregate chat counts.
3. Reprojected PR and purchasing/supplier PO DataTables with bounded fields/counts and preserved output/filter/action contracts.
4. Added SQL-resolved PO IDR totals with full legacy financial compatibility.
5. Eliminated inter-supplier N+1 and bounded monthly-history detail rendering without truncating chart/summary data.
6. Reduced vs-best query repetitions and per-row Hashid model lookups; retained legacy JSON fields and suppressed dead soft-deleted PR links.
7. Added a reversible migration for four verified redundant indexes.
8. Hardened async export counting, atomic handoffs, duplicate delivery, retry, terminal state, and completion-file safety.
9. Corrected supplier PO PDF isolation.
10. Removed unused Axios and made DataTables assets opt-in per page.
11. Added static browser-cache/gzip rules, corrected Pusher example configuration, and documented cPanel runtime/worker deployment.
12. Added focused query-growth, financial, ownership, migration, frontend-asset, notification/chat, and queue regressions.

## 12. Files Modified

Runtime/configuration source changed by this program:

- `.env.example`, `composer.json`, `package.json`, `package-lock.json`, `public/.htaccess`
- `app/Console/Commands/ProfileCriticalPaths.php`
- `app/Providers/AppServiceProvider.php`
- `app/Http/Controllers/ConversationMessageController.php`
- `app/Http/Controllers/NotificationController.php`
- `app/Http/Controllers/Purchasing/PdfController.php`
- `app/Http/Controllers/Purchasing/PriceComparisonController.php`
- `app/Http/Controllers/Purchasing/PurchaseOrderController.php`
- `app/Http/Controllers/Purchasing/PurchaseRequisitionController.php`
- `app/Http/Controllers/Supplier/SupplierPurchaseOrderController.php`
- `app/Models/PurchaseOrder.php`
- `app/Services/ExportProgressService.php`, `app/Services/NotificationSummaryService.php`
- `app/Support/ExportDispatcher.php`, `app/Jobs/ProcessExportJob.php`
- `app/Exports/Concerns/InteractsWithExportProgress.php`
- `app/Exports/InspectionsExport.php`, `PurchaseOrdersExport.php`, `QuotationsExport.php`, `RequisitionsExport.php`
- `app/Exports/PurchaseOrderDetailExport.php`, `PurchaseRequisitionDetailExport.php`, `QuotationDetailExport.php`, `SupplierPriceHistoryExport.php`
- `routes/web.php`
- `resources/js/app.js`, `resources/js/bootstrap.js`
- `resources/views/layouts/app.blade.php`
- `resources/views/partials/navbar.blade.php`, `resources/views/partials/notification-panel.blade.php`
- `resources/views/purchasing/comparison/historical.blade.php`, `vs-best.blade.php`
- DataTables opt-in entry views under admin auth-audit/material-HS/users; Purchasing claims/periods/PO/PR; QC inspections; Supplier claims/PO/price-history/quotation-period

Tests added/changed:

- `tests/Feature/AsyncExportQueueTest.php`
- `tests/Feature/ConversationUnreadCountTest.php`
- `tests/Feature/FrontendAssetLoadingTest.php`
- `tests/Feature/NotificationControllerTest.php`
- `tests/Feature/PriceComparisonPerformanceRegressionTest.php`
- `tests/Feature/PurchaseOrderReferenceRemarkTest.php`
- `tests/Feature/RedundantIndexMigrationTest.php`
- `tests/Feature/SupplierDataIsolationTest.php`

Documentation added:

- `docs/audits/PERF-00-CONTEXT-BASELINE.md`
- `docs/audits/PERF-01-MEASUREMENT-BASELINE.md`
- `docs/results/PERF-02-GLOBAL-REQUEST-COST.md` through `PERF-08-CPANEL-RUNTIME-STATIC-DELIVERY.md`
- `docs/guides/CPANEL-PERFORMANCE-RUNTIME.md`
- this canonical final report

Pre-existing user-owned changes to `AGENTS.md`, `.claude/`, `CLAUDE.md`, and `claudes-cognitive-framework-for-laravel-development.md` were preserved and are not performance-program modifications. Generated `public/build` artifacts are ignored build output; deploy the complete matching manifest and asset directory produced by the successful build.

## 13. Database Migrations Added/Changed

Added only:

- `database/migrations/2026_08_23_000003_remove_verified_redundant_indexes.php`

Status: `Pending` on the operational local database. It was applied/reversed/reapplied only inside the PHPUnit test database. No existing migration was modified and no operational/production schema was mutated.

Before production deployment, capture `SHOW INDEX`, back up normally, test on a current staging copy, check long transactions and DDL behavior, use a low-traffic window, apply `php artisan migrate --force`, and verify both retained and removed index names. Rollback recreates indexes and may be more blocking than removal.

## 14. Tests & Verification Actually Executed

- Final full suite: `php artisan test` — 262 tests, 2,791 assertions, passed in 114.97 seconds.
- The first integration run exposed a removed legacy `best_price_idr` JSON field; the field was restored and targeted plus full suites were rerun successfully.
- Final queue/export/import suite — 41 tests, 606 assertions, passed.
- Final price-comparison performance suite — 7 tests, 119 assertions, passed.
- Supplier isolation/Hashid, PR/PO projection, notification/chat, frontend asset, migration, PDF, import/export, financial and action-link regressions are included in the full pass.
- PO financial comparison across all 2,052 local POs — zero material mismatches.
- `npm.cmd run build` — passed; Vite 7.3.6, three modules transformed.
- Config, route, and view cache build — passed together; notification route listing passed; caches cleared afterward.
- Laravel Pint check on all changed/untracked PHP — passed.
- PHP lint on all changed/untracked PHP/Blade — passed.
- `node -c` for both JS source entries — passed.
- `composer validate --no-check-publish --no-interaction` — valid; existing unbounded dependency warnings remain.
- `git diff --check` — passed.
- `php artisan migrate:status` — passed; performance migration confirmed pending.
- `php artisan schedule:list` — passed; two scheduled commands present.
- 20 sequential warm profiler runs — 260 path samples, zero errors.
- Local Stage-A concurrency 1/5/10/25 — 41 profiler processes, zero process failures and zero application-path errors.

Not executed: browser automation, Lighthouse, production/cPanel changes, production database queries/migrations, unsafe seeders, operational queue workers, destructive load tests, commit, push, or PR creation.

## 15. Before vs After Metrics

The baseline column is one warm observation. Final response median/P95 comes from 20 warm sequential runs; timing deltas are directional local evidence, not production percentile comparisons.

| Path | Query count before → after | Baseline response ms | Final median / P95 ms | Bytes before → after |
|---|---:|---:|---:|---:|
| Purchasing Dashboard | 9 → 6 | 184.90 | 74.20 / 79.99 | 460,828 → 184,781 |
| Supplier Dashboard | 12 → 9 | 121.39 | 49.64 / 52.01 | 454,733 → 166,626 |
| PR DataTable | 5 → 4 | 122.73 | 29.80 / 32.00 | 262,636 → 104,477 |
| PO DataTable | 12 → 6 | 86.59 | 29.50 / 31.83 | 221,735 → 102,025 |
| Inter-supplier | 43 → 3 | 128.38 | 38.68 / 46.52 | 429,804 → 154,760 |
| Historical comparison | 14 → 11 | 156.15 | 69.94 / 74.26 | 513,860 → 224,852 |
| vs-best | 61 → 2 | 445.99 | 311.12 / 319.45 | 110,487 → 93,279 |
| Purchasing quotations | 10 → 7 | 156.34 | 66.54 / 78.86 | 509,157 → 234,113 |
| QC history | 5 → 5 | 21.58 | 21.48 / 23.17 | 40,581 → 40,581 |
| Purchasing claims | 5 → 5 | 13.40 | 13.72 / 14.56 | 47,704 → 47,704 |

Final warm profiler peak memory was at most 44 MB across the 20-run path set; the final PO path was 42 MB versus 44 MB baseline. Notification/chat asynchronous endpoints retained their compact query counts; the material gain is removal of their three queries and large detail payload from every initial authenticated response.

High-cardinality monthly history:

| Metric | Before | After |
|---|---:|---:|
| Blade response | 1,195.65 ms | 239.19 ms |
| Blade bytes | 5,216,555 | 310,149 |
| Peak memory | 88 MB | 38 MB |
| AJAX response | 640.35 ms | 171.08 ms warm |
| AJAX bytes | 1,424,238 | 76,440 |

Stage-A local read-only concurrency replay:

| Parallel profiler processes | Failures / path errors | PR P95 ms | PO P95 ms | Inter-supplier P95 ms | vs-best P95 ms |
|---:|---:|---:|---:|---:|---:|
| 1 | 0 / 0 | 29.68 | 31.23 | 38.05 | 308.12 |
| 5 | 0 / 0 | 43.10 | 43.57 | 53.90 | 424.56 |
| 10 | 0 / 0 | 90.32 | 55.04 | 47.71 | 452.87 |
| 25 | 0 / 0 | 105.99 | 126.63 | 210.76 | 1,531.63 |

This concurrency replay runs the full CLI profile sequence per process against local MySQL. It is useful contention evidence but does not include HTTP, sessions, cPanel entry processes, remote network, or think time and does not prove 50–200-user production capacity.

LCP, INP, and CLS: `NOT MEASURED — ENVIRONMENT REQUIRED`.

## 16. Remaining Bottlenecks

1. `VERIFIED BOTTLENECK`: vs-best's two remaining derived-history/current SQL executions dominate its response. Final warm SQL median was 303.19 ms of a 311.12 ms response; local concurrency-25 P95 reached 1.53 seconds.
2. `LIKELY BOTTLENECK — MEASUREMENT REQUIRED`: the complete 2,000-point history chart is intentionally retained; browser rendering cost is unknown.
3. `LIKELY BOTTLENECK — MEASUREMENT REQUIRED`: supplier price-history export remains `FromCollection`; memory remains proportional to selected history size even though duplicate construction was removed.
4. `LIKELY BOTTLENECK — MEASUREMENT REQUIRED`: production database cache/session/queue tables may contend under shared-hosting load.
5. `OPTIMIZATION OPPORTUNITY`: large stable inline layout CSS/JS could be extracted after browser contract coverage exists.
6. `LIKELY BOTTLENECK — MEASUREMENT REQUIRED`: login hero image/font/network behavior may affect LCP.
7. QC, claims, notifications, and chat local datasets are too small for a high-volume scalability conclusion.

## 17. Deferred / Environment-Dependent Work

- Lighthouse mobile/desktop and field/CrUX LCP, INP, CLS.
- Stage-B larger disposable dataset and Stage-C HTTP user-flow load test from a separate client.
- Production cPanel CPU, entry processes, memory, I/O, MySQL connections, disk quota, and throttling evidence.
- Production OPcache, PHP-FPM/LiteSpeed limits, gzip/Brotli, HTTP/2/3, TLS, and public cache-header confirmation.
- End-to-end scheduler and short-lived worker Cron evidence, queue backlog age, export lead-time/memory telemetry, retry/failure review.
- Production/staging `EXPLAIN ANALYZE` for vs-best and confirmation of database version/window support before considering the exact window-function rewrite.
- Production index sizes, online-DDL/lock behavior, reclaimed bytes, and write-latency benefit.
- Production PDF/import duration and memory thresholds.
- Manual visual/interaction QA for DataTables, comparisons/charts, notification dropdown, chat drawer, Bootstrap components, Alpine shell/toasts, and responsive behavior.

## 18. cPanel Manual Actions Required

1. Discover and record the real application path, public document root, CLI PHP binary/version, web SAPI, MySQL/MariaDB version, Cron availability, `flock`/lock mechanism, and writable log/storage paths.
2. Deploy the source plus the complete matching `public/build/manifest.json` and `public/build/assets/*`; do not mix manifests and assets.
3. Back up normally, inspect production indexes/table sizes, test the migration on a current staging copy, then apply during a low-traffic window and verify retained/removed keys.
4. Configure `QUEUE_CONNECTION=database`; keep `DB_QUEUE_CONNECTION` unset or identical to the `export_jobs` connection; keep `DB_QUEUE_RETRY_AFTER=660` with worker timeout 600.
5. Configure an overlap-safe every-minute short-lived worker for `exports,default` using verified paths, `--stop-when-empty --max-time=50 --tries=3 --timeout=600`.
6. Configure the scheduler every minute and verify both scheduled commands execute. Temporarily capture Cron logs until two invocations and a small export lifecycle are confirmed.
7. Run `php artisan migrate --force`, `config:cache`, `route:cache`, and `view:cache` using the verified PHP binary; smoke-test all roles and rollback caches if deployment behavior differs.
8. Confirm OPcache is enabled for web requests and record memory, interned strings, file limit, validation/revalidation, and reset/deploy behavior.
9. Verify public hashed/stable asset `Cache-Control`, `Content-Encoding`, HTTPS/HTTP2, and dynamic/private no-store/no-cache behavior from outside the host.
10. Request one small staging export and verify `queued → processing → completed`, row progress, private downloadable file, completion notification, queue drain, retry behavior, and cleanup.
11. Run manual Lighthouse and critical-path interaction QA, then a controlled staging HTTP load progression. Stop on 5xx, connection exhaustion, latency explosion, CPU throttling, or uncontrolled queue backlog.
12. Capture CloudLinux/cPanel resource graphs during representative usage before deciding on Redis, a VPS, or other infrastructure.

The exact commands and rollback checks are in `docs/guides/CPANEL-PERFORMANCE-RUNTIME.md`.

## 19. Risk Assessment

| Risk | Level | Mitigation / status |
|---|---|---|
| Business/financial output drift | Low | Full suite passed; all local PO totals compared; snapshot/amount rules covered. |
| Supplier isolation/authorization regression | Low | Supplier/Hashid tests pass; PDF ownership gap fixed and covered. |
| Export duplicate/stuck state | Low for current DB queue | Atomic same-connection handoffs, row locks, terminal guards, and 19 focused queue tests. |
| Separate/external queue introduced without outbox | High if guard bypassed | Runtime rejects incompatible configuration; redesign required before migration. |
| Index DDL lock/downtime | Medium/environment-dependent | Pending migration, staging copy, backup, low-traffic window, live lock monitoring. |
| vs-best latency under shared-hosting contention | Medium | Known residual; capture production plan/resources before portable window rewrite. |
| Frontend behavior after conditional assets | Low-to-medium | Static/render regression and build pass; manual visual/interaction QA still required. |
| Cache/compression directives differ on cPanel/LiteSpeed | Medium/environment-dependent | Module guards, dynamic cache scoping, public header verification and rollback guide. |
| Claims overstating production capacity | Controlled | Final verdict remains partial; all environment-dependent metrics are labeled. |

Adversarial review specifically challenged query count, memory transfer, business output, stale UI state, supplier isolation, authorization, locking, queue hard-kill windows, duplicate callbacks, soft-deleted links, migration rollback, frontend contracts, and speculative infrastructure. Material findings were corrected and retested before this report.

## 20. Infrastructure Verdict

Continue with a single-node cPanel deployment for the current evidence set. The repository no longer justifies mandatory Redis, Memcached, Supervisor, systemd, Reverb, a separate broker, microservices, containers, or a load balancer. Database queue plus an overlap-safe short-lived Cron worker, Pusher plus polling fallback, Laravel deployment caches, OPcache, and correct static delivery are the appropriate next production baseline.

An infrastructure limit has not been proven. Conversely, sustainable 50–200-user production capacity has not been proven because entry-process, CPU, memory, MySQL-connection, HTTP, and queue evidence are unavailable. Consider a VPS/stronger platform only if measured cPanel throttling, unacceptable production P95 after these changes, recurring export limits/backlog, insufficient entry processes, or a requirement for continuously running workers demonstrates concrete pressure.

## 21. Final Performance Verdict

`PERFORMANCE_TARGET_PARTIALLY_MET`

Repository-level query, hydration, memory, payload, bundle, index, queue-safety, cache compatibility, and regression goals are materially met and supported by local evidence. The complete target cannot be marked met until cPanel runtime/resource checks, production/staging HTTP stress evidence, end-to-end queue Cron verification, and manual Core Web Vitals/visual QA are recorded.
