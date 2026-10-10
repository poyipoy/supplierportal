# Gate 2 — Local PO/GR large imports

## Outcome

Implemented Local PO, GR and combined XLSX imports up to 70,000 meaningful rows, asynchronous preview and confirmation, server-owned private staging, bounded preview/error pages, and whole-file atomic master/audit writes. Code remains unstaged. Local migration physical tables and ledger are verified. The approved worker inventory was retried after the approval service's stated quota reset: one existing imports worker (PID23800, started15:05local) was found and preserved; no duplicate worker was started. **Performance release gate remains open:** a later20kcombined run exceeded the60secondmastertransactionbudget and rolled back all domain/audit writes. Browser and staging/production release verification remain outstanding.

Branch: `master`. Starting and final observed HEAD: `33258819c6283de284eb9201a446caaadb7484a5`.

## Implementation

- Pinned `openspout/openspout:4.28.5`; supports the installed PHP 8.2.30. No other package versions were changed. Laravel Excel remains for exports/templates/other domains.
- One streaming XLSX read, 500-row staging/write batches, global PO/GR grouping across batch boundaries, original physical source rows, positional Infor mapping and warnings. UOM stays in blank-headed Q/index 16. Mixed UOM fails without presenting an aggregate quantity. Formula cells including cached formulas are rejected; styled/raw Excel dates respect 1900/1904 calendars.
- Database queue `imports`, scalar IDs/stage/run key, same-connection atomic state/queue handoff, attempt fencing, owned kind-bound confirmation tokens, revalidation of actor/supplier/master state, unique constraints and row locks. No financial-master upsert/ignore, overwrite or partial-file commits.
- The bulk path in `LocalProcurementMasterService` preserves per-record `po_created`/`gr_created` audit snapshots and immutable invoice snapshots. Bounded caching of identical casts avoids repeatedly serializing identical date/decimal values; regression tests compare audit snapshots to actual model arrays.
- Limits: 70,000 meaningful rows, 50 MiB upload, 512 MiB expanded ZIP, 1,000 ZIP entries, two active imports/user. Preview expires after 24 hours. `imports:cleanup` runs 02:30 business time, retaining terminal files/staging three days and retaining master/audit history.
- PO/GR modals and a combined modal support progress, warnings, owned recent-job selection, 100-record pages, cancellation before confirmation, and explicit Reload register after completion. EN/ID translations and JS text-node rendering preserve business text safely.

Migration: `database/migrations/2026_10_08_000001_create_local_procurement_import_staging.php`, creating `local_procurement_imports`, `local_procurement_import_rows`, `local_procurement_import_records`. `down()` refuses active imports or retained attachments. Read-only local verification confirmed `APP_ENV=local`, database `adasi_portal`, physical staging tables and migration ledger present. The final scoped migrate command returned `Nothing to migrate`; no production deployment or production-data mutation occurred.

New model/policy/job: `LocalProcurementImport`, `LocalProcurementImportPolicy`, `ProcessLocalProcurementImport`. New main services: `LocalProcurementStreamingReader`, `LocalProcurementImportService`; existing PO/GR/combined validators, master and audit services updated. New controller: `Finance/LocalProcurementImportController`; existing `LocalProcurementController` delegates to async services.

Existing preview/confirm/template URI names remain. Preview/confirm now acknowledge JSON HTTP 202, or HTTP 200 for a completed idempotent/synchronous-test result. Full session row payloads are removed. Under each `finance.local-procurement` and `purchasing.local-procurement` prefix, added:

| Method | Suffix | Action |
|---|---|---|
| GET | `/imports` | `imports.index` |
| GET | `/imports/{procurementImport}` | `imports.status` |
| GET | `/imports/{procurementImport}/records` | `imports.records` |
| GET | `/imports/{procurementImport}/errors` | `imports.errors` |
| POST | `/imports/{procurementImport}/cancel` | `imports.cancel` |

Hashids, owner-only policy checks, existing role groups and confirm throttling remain. No existing routes were removed.

## Measured capacity

Actual database-queue processing on **`adasi_portal_test`**, with **500,000 existing GRs**. Windows PHP 8.2.30/MySQL 8.0.30; local MySQL buffer pool 128 MiB and redo capacity 100 MiB. Synthetic fixtures; upload/network/queue waiting excluded. Benchmark fixtures were removed from the test database after each run.

| Import | Source rows | Preview seconds | Confirm seconds | Peak process working set MiB | PHP peak MiB | Created records/audits |
|---|---:|---:|---:|---:|---:|---|
| GR | 20,000 | 18.973 | 13.107 | Not sampled | 60 | 20,000 GR / 20,000 audits |
| GR | 70,000 | 47.540 | 30.957 | 172.50 | 62 | 70,000 GR / 70,000 audits |
| PO | 70,000 | 45.961 | 30.662 | 175.21 | 58 | 70,000 PO / 70,000 audits |
| Combined | 70,000 | 91.442 | 48.945 | 105.14 | 60 | 70,000 PO + 70,000 GR / 140,000 audits |
| PO, later matrix | 20,000 | 16.577 | 30.104 | 94.35 | 56 | 20,000 PO / 20,000 audits |
| Combined, later matrix **gate failed** | 20,000 | 19.111 | 63.017 including rollback | 79.45 | 60 | 0 PO/GR / 0 creation audits |
| PO, existing documents | 70,000 | 63.163 | 21.006 | 175.41 | 72 | 70,000 existing skipped / 0 creation audits |
| GR, existing documents | 70,000 | 57.507 | 8.524 | 172.66 | 62 | 70,000 existing skipped / 0 creation audits |
| Combined, existing PO/new GR, first delivery **rolled back** | 70,000 | 94.989 | 64.056 including rollback | 105.04 | 72 | 0 new records / 0 creation audits |

All successful runs reached `COMPLETED`, no failure, all counts matched. Dispatch acceptance0.069–0.155seconds; scalar database payloads897–926bytes. SQL counts grew by batch: GR70kpreview1,693/confirm1,416; PO70kpreview2,253/confirm1,695; accepted combined70kpreview4,614/confirm2,815. OS memory collectors use64bitcounters. The PowerShell wrapper's process ExitCode property returned null; completion is established by benchmark status/counts and empty error logs, not a claimed OS exit code.

The later20kcombined run spent57.980seconds in747SQLstatements during confirm, before exceeding the60secondguard. Those recorded runs process one queue delivery, so the rolled-back record remains IMPORTING for its scheduled retry; this is **not** a completed import. Zero master records and zero creation audits were verified after rollback. Do not raise the guard, introduce partial commits or claim all performance gates passed. SQL time dominates this failure; local disk/checkpoint/buffer-pool pressure is a hypothesis, not a proven root cause. Additional query-shape timing was added to the guarded test runner without modifying database configuration. The70kexistingPO/newGR first delivery likewise rolled back: local_purchase_orders reads consumed34.067seconds across803queries, with maximum single read1.889seconds. Hosting-shaped repeated capacity/lock/I/O profiling is required before production release.

The helper has subsequently been corrected to wait for terminal status across real queue retries (up to600seconds per measured stage), record delivery timings, and include retry backoff in total elapsed time. These changes do not alter runtime retry limits or the60secondtransactionguard. The recorded failed first-delivery runs have **not** been relabeled successful. This helper revision passed lint/Pint, but its terminal-retry execution remains unverified because MySQL subsequently became unavailable.

During final benchmark cleanup, read-only MySQL process inspection observed a separate `DROP TABLE adasi_portal_test...` waiting on a metadata lock, while the benchmark's synthetic GR cleanup DELETE was waiting for handler commit. This task's own DB suites/benchmarks were serial, but another concurrent process used the shared test database. No additional DB test was launched after this observation, and no external process was killed. This late observation does not prove the cause of earlier SQL timing spikes; it prevents treating the shared environment as a controlled single-run benchmark environment. Coordinate exclusive test-DB use before repeating capacity/retry tests.

**Final environment blocker:** MySQL127.0.0.1:3306 subsequently refused connections (`SQLSTATE HY000/2002`); no `mysqld` process was observed. The last benchmark's cleanup terminated with a connection-refused error while deleting its synthetic GR fixture rows. Therefore cleanup of that final probe is incomplete/unverified; do not claim every probe's fixtures were removed. The failed cleanup SQL identifies test-only supplier ID39 and its linked parent/new POs in `adasi_portal_test`. Confirm its synthetic CAP document numbers and physical test schema first after the separate DROP operation; remove only verified probe-owned records with the same FK-safe cleanup logic in the helper if they still exist. Never run this cleanup against `adasi_portal`.

No database restart, recovery, data-directory permission change, global database tuning, external test-process kill or application-data repair was attempted. The inspected Laragon error log was stale (last event01:39UTC) and does not establish the cause of the later outage. Restore the existing local MySQL service and exclusive use of the test database before terminal-retry/capacity verification. Worker presence was verified before this outage; it is not proof the worker can process while MySQL is offline.

Earlier combined probes exceeded the unchanged 60-second transaction budget and rolled back **all** master/audits. Those artifacts are retained with `before-*` names. A diagnostic-only 180-second-budget probe reached COMPLETED at65.950seconds; it is **not** an acceptance pass. The final accepted run uses the default60secondbudget after removing duplicate validation and reusing identical audit casts.

Before implementation: the repository's real GR sample has48meaningfulrows but16,475physicalrows. Whole-sheet parser:7.35seconds/214MiBPHP. A synthetic70k×21column filtered PhpSpreadsheet read still peaked at3,298.9MiBOS despite126MiBPHP. These characterize the reader bottleneck; the real sample is not a20kproduction fixture. Streaming benchmarks demonstrate local capacity only, not a production SLA.

Raw evidence: [benchmark directory](local-import-benchmarks-20261008/). Reproducible guarded runner: `tests/Support/local-procurement-import-benchmark.php`. Do not run it against application data.

## Verification executed

- `php artisan test --filter=TestingEnvironmentDatabaseSafetyTest --compact`:2passed/4assertions; MySQL test configuration inspected, DB safety enforced by benchmark runner.
- `php artisan test tests/Feature/LocalInvoice --compact --do-not-cache-result`:176passed/946assertions. Includes invoice reservation, snapshot, settlement, refund, imports and isolation. Ran serially.
- Final focused `LocalProcurementBackgroundImportTest`:14passed/82assertions, after final stream upload/auth/expiry/migration rollback guards. Covers2,001PO/GR/combined, mixed UOM, rollback after later write batch, duplicate confirmation, stale worker attempt, atomic handoff failure/file cleanup, owner/role access,100-row pagination, supplier deactivation, summary counters and cleanup.
- `LocalProcurementStreamingReaderTest`:6passed/21assertions. Includes70,001boundary rejection, slug headings, physical blanks, cached formulas,1904rawdates, rich/sharedstrings and real Infor fixtures.
- `TranslationParityTest` + `BusinessTimeGuardTest`:5passed; final combined run with background suite17passed/35,805assertions. No historical copy-audit regeneration was requested or performed by this task.
- `node tests/js/local-po-preview-localization.test.mjs`:5passed. EN/ID PO/GR progress, confirmation tokens, text-node escaping,100rowcap, recent jobs and reload action. Direct runner used because sandbox blocks Node test subprocess spawning.
- PHP lint:27explicit changed PHP files passed. Scoped Pint applied; final focused `--test` passed. No broad formatting of unrelated files.
- `npm.cmd run build`:passed via reviewed escalation after sandbox esbuild EPERM. Import module remains lazy-loaded. Existing unresolved logo runtime-path warning remains.
- `php artisan view:cache`:passed. `php artisan route:list --path=local-procurement --no-ansi`:52routes inspected.
- `php artisan local-invoices:reconcile --json`:passed with0issues. Local read-only ledger/schema checks verified staging and `owner_job_key`; no pending imports jobs at that observation.
- Composer locked audit executed: no advisory listed for OpenSpout; four advisories affect existing Laravel framework, league/commonmark(two) and league/flysystem. Existing dependencies were not upgraded. Initial installation completed but Composer returned nonzero from advisory/network checks; installed manifest/lock/autoload and subsequent test runs verify reader availability.
- Whole-tree `git diff --check` fails only at unrelated `context.md` trailing whitespace lines3,5,6. Import-scoped diff check passes; unrelated whitespace preserved.

## Remaining operational/release checks

- The initial persistent-worker inventory/start was not executed because automatic approval review could not complete at the usage limit. After the stated reset time elapsed, the same reviewed inventory/start succeeded and found an existing imports worker; no bypass or duplicate worker was used. Existing export worker was not stopped or replaced. Graceful code reload through existing worker supervision remains a deployment step; this task did not restart other users' workers.
- Read-only runtime evidence: four existing local import records each processed21,767source rows; latest GR preview finished with VALIDATION_FAILED, summary41invalidsource/documentgroups,9invalidsource rows and32invalidconsolidatedgroups. Errors concern qty/UOM/document consistency, not a row-limit rejection. No user business values or records were modified to force validation success. This is observed existing application activity, not an agent-performed browser smoke test.
- Local browser upload→preview→confirm, keyboard/screenreader/reflow, web-SAPI50MiB/proxy limits, scheduler/live cleanup, multi-operator row-lock contention, staging and production still require verification.
- Dedicated20kPO/combined and70kexistingPO/GR/combined were measured in the later matrix; combined first-delivery rollbacks are retained above. Production-shaped high-cardinality shared-string capacity and repeated stable throughput/lock-contention/terminal-retry gates remain unverified; smaller regression fixtures cover shared strings.
- Windows lacks PCNTL: job checkpoints/lease/fencing are implemented, but deployment must verify an external watchdog. One separate imports worker is required; see [deployment guide](../guides/LOCAL-PROCUREMENT-IMPORTS.md).
- No full-project test suite claim; LocalInvoice regression and focused checks are the executed evidence.

## Working tree preservation

No branch change, stage, commit, push, reset, stash or clean. Index is empty. Starting dirty export presentation files, three earlier ExportController/MissionFourExportTest edits, AGENTS/CLAUDE/context, root plan/PDF/skills-lock and sample artifacts remain. Concurrent account-settings changes (UserPreferenceController, customization/navigation translations, app.css, profile/account components, regional/customization JS and tests, account-settings plan) were observed and preserved. `resources/js/app.js` is shared: only the conditional import-module boot was added by this task. Historical copy-audit working-tree changes were observed and left untouched.

Implementation is locally reviewable and capacity-tested, with an existing local imports worker observed before the outage. **Do not release as production-ready:** later combined first-delivery transaction gates rolled back, terminal-retry performance and final fixture cleanup are blocked by the local MySQL outage/shared test-DB contention, and browser/staging/deployment checks remain outstanding. Retained successful70kruns do not erase those limitations.
