# PERF-06 — Queue, Export, Import, and PDF

## Mission status

`REPOSITORY_COMPLETE — CPANEL_VERIFICATION_REQUIRED`

The asynchronous Excel architecture remains in place. This mission hardened its launcher and terminal-state transitions, removed verified duplicate export preparation work, corrected the local development worker queue selection, and preserved synchronous import previews and small interactive PDFs. No operational jobs were run and no application database records were changed by this mission.

## Scope and environment

- Local evidence date: 2026-08-23.
- Runtime: PHP 8.2.30, Laravel 12.66.0, MySQL 8.0.30, Maatwebsite Excel 3.1.69.
- Deployment assumption: shared hosting/cPanel with the database queue.
- Production/cPanel Cron execution, worker logs, resource limits, PHP binary, and filesystem permissions: `NOT MEASURED — ENVIRONMENT REQUIRED`.
- Real production import and PDF durations: `NOT MEASURED — ENVIRONMENT REQUIRED`.

## Files inspected

- Queue lifecycle: `ProcessExportJob`, `FinalizeExportJob`, `MarkExportFailed`, `TrackExportChunkProgress`, `ExportDispatcher`, `ExportProgressService`, `ExportJob`, queue configuration, migrations, console schedule, `composer.json`, and the export download controller.
- Export work: all classes under `app/Exports`, the progress contract/concern, Maatwebsite's installed queued-writer implementation, and export controllers/tests.
- Import work: both import-preview controllers, `SpreadsheetImportReader`, `AbstractPreviewImport`, `PrItemsImport`, and `QuotationItemsImport`.
- PDF work: shared PDF routes, `Purchasing\PdfController`, PDF views/call sites, and supplier-isolation coverage.
- Operations: `.env.example`, `README.md`, `CLAUDE.md`, local `export_jobs`, `jobs`, and `failed_jobs` state through read-only queries.

## Evidence and classifications

| Finding | Classification | Evidence and decision |
|---|---|---|
| `composer dev` listened only to the default queue while all operational Excel launchers use `exports` | `VERIFIED BOTTLENECK` | The command and dispatcher queue names disagreed. The development listener now consumes `exports,default` with three tries and a 600-second timeout. |
| Duplicate `ProcessExportJob` deliveries could each reset progress and enqueue a complete Maatwebsite chain | `VERIFIED BOTTLENECK` | The old status guards allowed `processing` records through. A focused regression invokes the launcher twice and now observes exactly one `QueueExport`. |
| A worker termination between changing an export to `processing` and enqueueing the Excel chain could leave the export permanently stuck | `VERIFIED BOTTLENECK` | Export construction/counting now occurs while the record remains queued. The processing transition and database-queue root insert then commit or roll back in one same-connection transaction. |
| A late duplicate chain failure could downgrade a completed export and delete its downloadable file | `VERIFIED BOTTLENECK` | Terminal transitions were read-then-write without locking, and `fail()` ignored only an already-failed record. `complete()` and `fail()` now serialize terminal transitions with a row lock and treat both completed and failed as terminal. |
| A process termination between creating an export record and enqueueing its launcher could leave a permanently queued orphan | `VERIFIED BOTTLENECK` | Export-record creation and the initial `ProcessExportJob` database-queue insert now share one transaction. |
| `FromQuery` exports counted the same query once for progress and again when Maatwebsite planned chunks | `OPTIMIZATION OPPORTUNITY` | Installed Maatwebsite 3.1.69 uses `WithCustomQuerySize` when present. Four large exports now cache one scalar count; a regression verifies one `pr_items` count query, not two. |
| Detail `FromCollection` exports generated and hydrated the complete row collection once to count and again to queue | `OPTIMIZATION OPPORTUNITY` | PR, quotation, and PO detail progress now use ownership-aware scalar counts. Five detail variants verify row-count parity with the generated collection. |
| Supplier price-history export rebuilt the complete history for progress and queue preparation | `OPTIMIZATION OPPORTUNITY` | Rows are cached only for the launcher process and reused. The cache is explicitly omitted from serialized chain jobs because Maatwebsite already serializes each row chunk. A regression verifies no second query/build and no cached PR row in the serialized export. |
| Real cPanel worker availability and once-per-minute execution | `LIKELY BOTTLENECK — MEASUREMENT REQUIRED` | Repository configuration cannot prove that cPanel Cron is enabled, uses the intended PHP binary, or can write private export files. Manual checks below remain mandatory. |
| Queueing the bounded import previews | `NOT CURRENTLY JUSTIFIED` | Both previews require immediate validation feedback and enforce at most 1,000 non-empty rows. Existing tests confirm preview-only behavior and no business-table writes. Measure real uploads before designing queued imports. |
| Queueing current interactive PDFs | `NOT CURRENTLY JUSTIFIED` | The local operational maximum is six PO items and six QC items; no batch-PDF endpoint exists. An own-PO PDF feature test rendered a one-item PDF in about 0.51 seconds including test application boot. Production memory/P95 still require manual measurement. |
| Mandatory Redis, Supervisor, or a permanent worker daemon | `NOT CURRENTLY JUSTIFIED` | The bounded database-queue worker remains compatible with the shared-hosting target. |

## Local lifecycle evidence

Read-only inspection of existing local records found:

- 44 export records: 44 completed, zero failed, zero queued/processing;
- completion lead time (`created_at` to `completed_at`): minimum 0 s, average 13.2 s, median 3 s, nearest-rank P95 77 s, maximum 215 s;
- eight purchase-order list exports had an average recorded lead time of 51.6 s, the slowest export class represented by multiple records;
- one existing supplier price-history export completed in 1 s;
- the current `jobs` table was empty;
- `total_rows` was zero on these historical records, so they predate or do not demonstrate current row-progress behavior;
- 21 `failed_jobs` records existed on the `default` queue; all 21 were Pusher `BroadcastException` records dated 2026-06-09 through 2026-08-13, not Excel export failures.

These are historical local lead times, not pure workbook generation durations and not production cPanel percentiles. No new export was dispatched to manufacture a benchmark.

## Changes implemented

1. `ProcessExportJob` accepts only a queued record and performs export construction/counting before changing durable state, so a worker termination during preparation remains safely retryable.
2. `ExportProgressService::handoffToExportQueue()` locks the export row and commits the processing state plus forced Maatwebsite root-job insert in one transaction. Duplicate launchers serialize on the row lock; after commit, a retry sees processing while the root job is already durable.
3. `ExportDispatcher` commits export-record creation and its forced `ProcessExportJob` insert in one transaction, closing the initial orphan window as well.
4. Completed and failed states are terminal. Row locks prevent completion and a late failure callback from overwriting one another; failed partial-file cleanup occurs only after the failed transition wins.
5. Large query exports implement Maatwebsite `WithCustomQuerySize` through the existing progress concern and share the cached scalar with progress reporting.
6. Detail collection exports use narrow scalar counts instead of hydrating their full output twice.
7. Supplier price-history rows are built once in the launcher, while the temporary cache is excluded from all serialized queue jobs.
8. `composer dev` now listens to `exports,default` with retry/timeout values aligned to the export launcher's three tries and 600-second timeout.
9. During PDF inspection, a supplier-isolation gap was found and fixed: the shared PO PDF query now constrains suppliers to `purchase_orders.supplier_id = auth()->id()`. Own and foreign supplier PDF cases are covered.

Both handoffs validate that the active queue uses the `database` driver, `after_commit=false`, and the same Laravel database connection as `ExportJob`. The PHPUnit sync/fake path remains supported. A future external queue driver requires an outbox or equivalent durable idempotency design before migration.

The queue remains asynchronous: forcing `PendingDispatch` destruction performs only the database-queue insert in the request/launcher process; workbook chunk jobs still execute on the `exports` queue.

## Before versus after

| Concern | Before | After | Verification |
|---|---:|---:|---|
| Count executions for one large `FromQuery` launch | 2 | 1 | Focused query-log assertion |
| Excel chains from two deliveries of one launcher | Up to 2 | 1 | Focused duplicate-launcher test |
| Supplier-history builds for progress plus queue preparation | 2 | 1 | Query-log/cache regression |
| Full detail-output hydrations for progress plus queue preparation | 2 | 1 plus a scalar count | Row-count parity regression |
| Completed file after late duplicate failure | Could become failed/deleted | Remains completed/downloadable | Terminal-state regression |
| State/root-job handoff after a hard worker stop | Could leave `processing` without a chain | One database transaction commits both or neither | Same-connection transaction plus real database-queue regression |
| Record after initial queue enqueue rejection | Orphaned `queued` row | Transaction rolls back record and launcher insert | Dispatcher failure regression |

Wall-clock and peak-memory before/after for representative large exports are `NOT MEASURED — ENVIRONMENT REQUIRED`. The evidence above proves eliminated work and state correctness, not a fabricated production speedup.

## Import assessment

Import previews remain synchronous by design:

- uploaded files are copied to a controlled temporary path and deleted in `finally`;
- only the first worksheet is processed;
- the hard limit is 1,000 non-empty rows;
- preview validation is read-only and returns immediate row-level errors/warnings;
- existing import tests cover XLSX/XLS/CSV validation, row limits, cleanup, role guards, and no business writes.

Queueing imports would require a persisted upload, import-job lifecycle, progress/result endpoint, retention policy, and new UX. That expansion is deferred until real cPanel duration/memory evidence crosses an agreed threshold.

## PDF assessment

PO and QC PDFs are interactive single-document downloads. Local data has a maximum of six rendered items per PO or inspection, and the repository has no batch generator. They remain synchronous. The supplier ownership correction is a security fix, not a performance redesign.

Before reconsidering this decision, capture production P50/P95 response time and peak PHP memory for the largest real PO and QC inspection. Queue only if large or batch documents materially exceed shared-hosting limits.

## cPanel manual actions required

1. In cPanel Terminal, verify the application directory and CLI PHP binary/version. Do not copy a PHP path from documentation until it is confirmed on the target account.
2. Verify the deployed values include `QUEUE_CONNECTION=database` and `DB_QUEUE_RETRY_AFTER=660`. Leave `DB_QUEUE_CONNECTION` empty or set it to the same Laravel connection used by `export_jobs`; the atomic handoff intentionally rejects a separate queue database. The retry interval must remain greater than the 600-second per-job timeout.
3. Verify the `jobs` and `failed_jobs` migrations exist in the deployed schema and that `storage/app/private`, `storage/framework`, and the chosen worker log path are writable by the Cron user.
4. Configure one every-minute, short-lived worker using verified paths. Portable template:

   ```cron
   * * * * * cd /verified/path/to/application && /verified/path/to/php artisan queue:work database --queue=exports,default --stop-when-empty --max-time=50 --tries=3 --timeout=600 >> /verified/writable/path/queue-worker.log 2>&1
   ```

   If `flock` is confirmed available, the repository README's non-overlapping `flock` plus `--max-time=50` pattern may remain active without `--stop-when-empty`; it trades more CPU residency for lower within-the-minute queue latency. Confirm its currently documented target-specific paths rather than assuming them.
5. Configure the scheduler every minute so `exports:cleanup` runs on schedule. Use the same verified PHP binary and application directory.
6. Safely request one small export in staging, then confirm `queued -> processing -> completed`, a downloadable private file, row progress, and a completion notification. Confirm both `exports` and `default` jobs drain.
7. Review `php artisan queue:failed` and the worker log. The 21 local historical broadcast failures indicate Pusher endpoint/credential drift; do not blindly retry them until the deployed Pusher configuration is corrected.
8. Monitor cPanel CPU, memory, I/O, entry processes, MySQL connections, queue backlog age, export lead time, and failures for at least one representative business cycle.

No Redis, Memcached, Supervisor, systemd service, Reverb daemon, or permanent queue process is required by this mission.

## Tests and verification executed

- `php -l` on every changed PHP production/test file: passed.
- Scoped Laravel Pint on changed queue/export PHP files: passed; one formatting batch was applied.
- `php artisan test tests/Feature/AsyncExportQueueTest.php tests/Feature/DetailExportSecurityTest.php tests/Feature/MissionFourExportTest.php tests/Feature/MissionFiveImportTest.php`: **41 tests, 606 assertions, passed**.
- The final expanded `AsyncExportQueueTest` contained 19 tests and covered both real database-queue handoffs, mismatched-connection rejection, duplicate launch, retry safety (including zero-row enqueue failure), terminal state, single query count, collection cache serialization, detail row-count parity, end-to-end file generation, ownership, and cleanup.
- Supplier PO PDF isolation verification performed with the PDF fix: four relevant supplier-PO tests, six assertions, passed; own PDF returned PDF and foreign PDF returned 404.
- `composer validate --no-check-publish --no-interaction`: valid; existing unbounded dependency-constraint warnings remain for DomPDF, Reverb, Maatwebsite Excel, and Hashids.
- `git diff --check` on the scoped queue/export changes: passed.

No production stress test, browser automation, operational export dispatch, production database mutation, queue retry/delete, or cPanel change was performed.

## Files changed for PERF-06

- `composer.json`
- `app/Jobs/ProcessExportJob.php`
- `app/Services/ExportProgressService.php`
- `app/Support/ExportDispatcher.php`
- `app/Exports/Concerns/InteractsWithExportProgress.php`
- `app/Exports/RequisitionsExport.php`
- `app/Exports/PurchaseOrdersExport.php`
- `app/Exports/QuotationsExport.php`
- `app/Exports/InspectionsExport.php`
- `app/Exports/PurchaseRequisitionDetailExport.php`
- `app/Exports/QuotationDetailExport.php`
- `app/Exports/PurchaseOrderDetailExport.php`
- `app/Exports/SupplierPriceHistoryExport.php`
- `app/Http/Controllers/Purchasing/PdfController.php`
- `tests/Feature/AsyncExportQueueTest.php`
- `tests/Feature/SupplierDataIsolationTest.php`
- `docs/results/PERF-06-QUEUE-EXPORT-IMPORT-PDF.md`

No database migration or schema change was added.

## Risks and deferred work

- Actual cPanel worker execution remains an operational dependency and cannot be proven from this checkout.
- The atomic handoff is deliberately tied to the current same-connection database queue. Migrating exports to Redis/SQS or a separate queue database requires a transactional outbox/idempotency design rather than removing the configuration guard.
- Historical lifecycle time includes queue wait and cannot isolate generation time; add production-safe telemetry before setting export SLOs.
- Supplier price-history still uses `FromCollection`, so peak memory remains proportional to the selected history size even though duplicate construction was removed. Classify a conversion to chunked query/streaming as `LIKELY BOTTLENECK — MEASUREMENT REQUIRED` until representative peak memory is measured.
- Purchase-order list exports show the highest historical local lead time. Profile their Eloquent graph and mapping under representative cPanel data before further redesign; the request path is already asynchronous.
- Database cache/session/queue contention, Pusher broadcast failures, and Cron reliability should be reviewed during PERF-08 rather than introducing Redis speculatively.
- PDF and import queue thresholds remain environment-dependent. Do not queue tiny interactive work merely for architectural uniformity.
