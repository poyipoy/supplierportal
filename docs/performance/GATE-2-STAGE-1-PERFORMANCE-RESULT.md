# Gate 2 — Stage 1 Performance Result

Date: 21 October 2026. Specification: [GATE-1-SUPPLIER-PORTAL-PERFORMANCE-IMPLEMENTATION-PLAN.md](GATE-1-SUPPLIER-PORTAL-PERFORMANCE-IMPLEMENTATION-PLAN.md). Scope: Stage 1 only (1A imports, 1B queue workers, 1C best price comparison). No Stage 2/3 work was done.

Labels: **MEASURED** (executed here), **CODE** (read from source), **PROD** (provided by the owner from cPanel on 21 October 2026), **ENV-UNKNOWN** (still unverified).

Revision: the first version of this report ended "partially verified". Afterwards the owner provided the production database version and Cron lines, chose the recommended lock-order fix for blocker B4, waived staging (none exists), and approved a full-suite run. This version includes those results.

---

## 1. Repository state and modified files

- Work was done in an isolated git worktree, `c:\laragon\www\adasi_perf_stage1`, on branch `perf/gate2-stage1`, created from `master` at `732304736bb283f65d12eb61bb05febcd2a9cf06`. The worktree has its own copy of `vendor`; a junction made Composer autoload the main tree's classes, so it was replaced. Nothing is committed.
- A second detached worktree, `c:\laragon\www\adasi_perf_baseline`, at the same commit, was used only to re-run failing tests on the unchanged code.
- The main working tree (`c:\laragon\www\adasi_portal_supplier`) and its uncommitted user changes (registration/PKP, invoice form, navbar, lang, `config/portal_urls.php`) were not touched.
- Gitignored local fixtures from the main tree root (`*.xlsx` Infor samples) were copied into both worktrees so the existing reader tests can run there. They stay ignored.

Application code:

| File | Change |
|---|---|
| `app/Services/LocalInvoice/LocalProcurementImportService.php` | PO confirm skips the redundant registered-PO lookup; PO confirm locks the file's supplier `users` rows (shared) before any PO lock; the commit budget throws `ImportBudgetExceeded` and marks the import FAILED; preview writes each consolidated record once; `recordBatches()` reads by a bounded primary-key range |
| `app/Services/LocalInvoice/LocalPoImportService.php` | `validate(array $rows, bool $checkDatabase = true)`; the default is unchanged for every existing caller |
| `app/Jobs/ProcessLocalProcurementImport.php` | On `ImportBudgetExceeded`, fail the delivery once (`$this->fail()`) instead of releasing it |
| `app/Exceptions/ImportBudgetExceeded.php` | New exception (extends `RuntimeException`) |
| `lang/en/local_procurement.php`, `lang/id/local_procurement.php` | New key `large_import.budget_exceeded` |
| `composer.json` | `composer dev` adds a `queue:listen --queue=imports` process |

Tests and test tooling:

| File | Purpose |
|---|---|
| `tests/Feature/LocalInvoice/LocalProcurementCommitRevalidationTest.php` | Characterization of every confirm-time rejection; PO lookup ceiling; supplier-before-PO lock order; primary-key record reads |
| `tests/Feature/LocalInvoice/LocalProcurementCommitBudgetTest.php` | Budget overrun: rollback, FAILED, no retry on the database queue, transient failures keep retrying, a completed import is not overwritten |
| `tests/Support/local-procurement-import-benchmark.php` | Adds a PO-history argument and EXPLAIN capture for GR lookups and staged-record reads |
| `tests/Support/local-import-lock-contention-probe.php` | New two-process lock-contention probe (import confirm vs the invoice-submission lock order) |
| `tests/Support/vs-best-benchmark.php` | New guarded vs-best fixture and benchmark (seed, measure, cleanup) |

Documentation: `docs/guides/CPANEL-PERFORMANCE-RUNTIME.md` (§5 rewritten), `README.md`, `docs/guides/PRODUCTION-CRON-COMMANDS-20261012.md`, `docs/guides/AUTH-SECURITY-DEPLOYMENT.md`, `docs/guides/LOCAL-PROCUREMENT-IMPORTS.md`, `docs/guides/NATIVE-FILE-SECURITY.md`, `AGENTS.md`, `CLAUDE.md`.

No migration, schema change, index, configuration value, business limit (`max_rows` 70,000, `commit_seconds` 60) or package was changed.

---

## 2. Problems reproduced

| Problem | Result |
|---|---|
| PO confirm runs 4 `local_purchase_orders` lookups per 500-row batch | Reproduced: 560 lookups for 70k rows (MEASURED) |
| A budget overrun is retried like any failure | Confirmed in code: `RuntimeException` → `$tries=3`, `$backoff=30` (CODE) |
| Preview writes each record twice and re-reads it | Confirmed: insert + `upsert` + reread per 500 records (CODE; MEASURED in query shapes) |
| Import confirm blocks invoice submission | Reproduced by the probe: a 25.8 s wait (GR) and a deadlock (PO) (MEASURED, §5.2). The deadlock is fixed; the wait is inherent to the whole-file transaction |
| GR lookups need `FORCE INDEX` | **Not reproduced.** At 500,000 existing GR, EXPLAIN shows `range` on `local_goods_receipts_gr_number_unique`, rows=500, 6–7 ms (MEASURED). No hint added |
| **New:** the staged-record keyset read flips between two plans | Found during benchmarking. The same query used `range` on PRIMARY (2 ms per batch) in some runs and `ref` on `local_import_document_unique` plus a filesort of all import records (26–97 ms per batch) in others, both before and after the other changes. Up to 7.1 s inside the locked commit at 70k (MEASURED via EXPLAIN) |
| vs-best window-function history dominates | **Not reproduced.** The window/materialize step is ~23 ms of ~166 ms at 4.3k items and ~110 ms of ~800 ms at 22k (MEASURED, §5.4) |
| Combined PO+GR failures from 8 October | Not applicable: the feature was removed and was not reintroduced |

---

## 3. Root causes confirmed

1. **Redundant PO lookup in confirm.** Confirm re-ran `LocalPoImportService::validate()`, including `checkDatabaseConflicts()`, and then `createImportBatch()` made the same cancelled/identity/PR decisions again under lock. The lock sweep and the post-insert reload are still required (lock ordering and audit IDs).
2. **Optimizer plan instability in `recordBatches()`.** `WHERE import_id=? AND attempt=? AND kind=? AND id>? ORDER BY id LIMIT 500` can be served by PRIMARY (range) or by `local_import_document_unique` (ref + filesort). The choice depends on InnoDB statistics, and the second plan sorts every record of the import for each batch.
3. **Budget overrun handled as a transient failure.** The queue could not tell a deterministic overrun from a deadlock.
4. **Preview write amplification.** Validation ran after all records were inserted, so every record was written twice and read back.
5. **Lock-order inversion between PO import and invoice submission.** PO confirm locked registered POs first (the sweep) and only then took `LOCK IN SHARE MODE` on the supplier's `users` row in each batch. `InvoiceSubmissionService::submit()` locks the supplier's `users` row `FOR UPDATE` and then the PO. When a PO file contained a registered PO of a supplier who was submitting an invoice at the same time, InnoDB detected a deadlock (MEASURED). The GR path has no inversion, but it holds the parent PO lock for the whole commit.
6. **Realtime pushes on `default`.** Every `SystemNotification` queues `BroadcastNotificationCreated` (`ShouldBroadcast`) on `default` (CODE, vendor source). Production serves `default` after `exports` in one worker (PROD).

---

## 4. Changes implemented

### 4.1 Import confirm (1A-1)

- Confirm calls `validate($rows, checkDatabase: false)`. Supplier names are still resolved again at commit time (the existing behavior, characterized in a test), field validation is unchanged, and the registered-PO decisions run once, under lock, in `createImportBatch()`.
- `recordBatches()` uses `FORCE INDEX (PRIMARY)` with `id > last AND id <= MAX(id)` for this import, attempt and kind. It returns the same rows in the same order; the upper bound stops the last batch from scanning later imports' rows.
- PO lookups per 500-row batch went from 4 to 3 (lock sweep, locked batch map, post-insert reload). The plan's target of ≤ 2 was not met: the remaining sweep enforces the global PO lock order.
- No GR `FORCE INDEX`: the plan evidence did not support it.

### 4.2 Budget overrun (1A-2, D-1 option A)

- `commitDeadline()` throws `ImportBudgetExceeded`. The commit transaction rolls back. The service then marks the import `FAILED` with `large_import.budget_exceeded`, guarded by `owner_job_key` and `status = IMPORTING`, and rethrows.
- The job catches it and calls `$this->fail()`. The delivery is recorded once in `failed_jobs` and is not released for a retry. `failed()` does not overwrite the specific message because the status is no longer active.
- Deadlocks, lost connections and every other exception keep the existing path: Laravel's transaction retry for deadlocks, then queue retries.
- The preview deadline (`timeout_seconds`) is unchanged.

### 4.3 Preview (1A-4)

- Each batch of consolidated records is validated in `consolidate()` and inserted once (`insertValidatedRecords()`). The insert-then-upsert-then-reread pass is removed. Batches contain the same records in the same order as before (500 per batch, insertion order).

### 4.4 Lock order (1A-3, owner decision: recommended option b)

- PO confirm now reads the distinct `supplier_id` values of its staged records and takes `LOCK IN SHARE MODE` on those `users` rows, in ascending id order, before the PO lock sweep. These are the same shared locks `createImportBatch()` already took per batch, now taken earlier. PO imports and invoice submission therefore lock in the same order (`users`, then PO).
- Nothing else changed: the transaction is still whole-file, the per-batch eligibility check under lock is still there, and GR imports are unchanged (they had no inversion).
- Effect: a concurrent invoice submission by a supplier in the file now **waits** until the import commits instead of being chosen as a deadlock victim (§5.2). The wait itself is inherent to the whole-file transaction and is documented in `LOCAL-PROCUREMENT-IMPORTS.md` (run large imports outside invoice-submission hours).

### 4.5 Queue workers (1B) — documentation only

See §8.

### 4.6 vs-best (1C) — no code change

See §5.4.

---

## 5. Before/after measurements

Environment: Windows, PHP 8.2.30 CLI, MySQL 8.0.30 (buffer pool 128 MiB, redo 100 MiB, binlog on, flush=1), isolated `adasi_portal_test`, a real database-queue worker (`queue:work --once`), commit budget 60 s. One run per cell. The test database was empty before every run and empty again after the last one. Production runs MariaDB 11.4.13 (PROD); query plans there may differ from these local MySQL plans.

### 5.1 Import matrix

Before = unchanged code; after = code with §4.1–4.3 (the §4.4 lock change was measured separately in §5.2). Both used the same fixtures and commands. Preview and confirm are in seconds; Q = SQL statements.

| Case | Preview s before → after | Confirm s before → after | Preview Q | Confirm Q | PO lookups (confirm) | Created master / audit |
|---|---|---|---|---|---|---|
| PO XLSX 20k | 10.7 → 7.8 | 7.7 → 5.7 | 675 → 596 | 502 → 464 | 160 → 120 | 20,000 / 20,000 |
| PO XLSX 70k | 31.1 → 30.8 | 25.4 → 30.1 | 2,266 → 1,986 | 1,702 → 1,564 | 560 → 420 | 70,000 / 70,000 |
| PO CSV 70k, 200k existing PO | 28.5 → 29.1 | **39.4 → 24.3** | 2,265 → 1,985 | 1,702 → 1,564 | 560 → 420 | 70,000 / 70,000 |
| PO XLS 65,535 | 87.1 → 45.8 | 23.6 → 17.0 | 2,133 → 1,878 | 1,604 → 1,474 | 528 → 396 | 65,535 / 65,535 |
| GR XLSX 20k | 26.0 → 7.8 | 10.3 → 6.4 | 504 → 437 | 344 → 346 | — | 20,000 / 20,000 |
| GR XLSX 70k | 38.3 → 33.9 | 28.4 → 18.4 | 1,706 → 1,429 | 1,144 → 1,146 | — | 70,000 / 70,000 |
| GR CSV 70k, 500k existing GR | 41.4 → 19.6 | 20.9 → 18.0 | 1,691 → 1,429 | 1,144 → 1,146 | — | 70,000 / 70,000 |
| GR XLS 65,535 | 130.8 → 127.2 | 19.2 → 19.4 | 1,606 → 1,350 | 1,078 → 1,081 | — | 65,535 / 65,535 |

All 16 runs ended `COMPLETED` after exactly one confirm delivery, with no failures. Master and creation-audit counts equal the row count. Peak PHP memory was unchanged: 56–62 MiB for XLSX/CSV and 120/188 MiB for XLS (PhpSpreadsheet reader windows).

Staged-record reads inside the confirm transaction took 142–4,716 ms before (bimodal) and 117–513 ms after (stable). Three repeated PO 20k runs after the fix all used `range` on PRIMARY at 2 ms per batch; before the fix, two of three used `ref` + filesort.

Interpretation:

- Statement counts are deterministic and dropped as designed: confirm −140 per 70k PO file, preview −255 to −284 per file. The §4.4 change adds one `DISTINCT` read and one shared-lock read per 500 suppliers.
- Timings are single runs on a local disk and vary by several seconds. The largest remaining confirm cost is the INSERT of masters and audits (single statements up to 0.5–0.8 s in some runs). That cost is the floor of the whole-file transaction and was not changed. PO XLSX 70k confirm was slower in the final run (30.1 s): its insert time was 13.5 s against 7.1 s in the baseline run, while lookups and record reads fell.
- The worst baseline case (PO 70k with 200k registered POs, 66% of the budget) used 41% of it in this run. The worst confirm after the change was 30.1 s, 50% of the 60 s budget, on local hardware.

### 5.2 Lock contention probe (1A-3)

`tests/Support/local-import-lock-contention-probe.php`, 70,000 rows. A child process replays the lock order of `InvoiceSubmissionService::submit()` against the overlapping PO (`innodb_lock_wait_timeout` = 50 s).

| Scenario | Code | Import | Invoice side |
|---|---|---|---|
| GR import on parent PO P; the supplier of P submits an invoice for P | §4.1–4.3 | COMPLETED, commit 29.3 s, one transaction | Waited **25.8 s** for the PO lock, then acquired it |
| PO import containing registered PO P; the supplier of P submits an invoice for P | §4.1–4.3 | COMPLETED, commit 19.4 s, one transaction | **Deadlock 1213** after 0.55 s; the invoice side was rolled back |
| Same PO scenario | **with §4.4** | COMPLETED, commit 19.6 s, one transaction | Waited **17.1 s** for the supplier `users` lock, then acquired both locks; **no deadlock** |

The remaining wait is bounded by the commit duration. It stays below the 50 s lock-wait timeout as long as confirm stays well inside its own 60 s budget.

### 5.3 Preview equivalence

A snapshot script ran a 1,211-row PO file (registered, conflicting, PR-fill, invalid-supplier, invalid-date, zero-amount and header-conflict rows) and a 1,210-row GR file (missing PO, closed PO, mixed UOM, excess precision, existing and conflicting GR) on the old and the new code. Result: 0 differences in `kind`, `document_key`, `source_row`, `source_count`, `values` and `errors` across 1,206 PO and 605 GR records. Status, failure and summary were identical. The only differing values were fixture IDs (`supplier_id`, `po_id`), which mapped one-to-one between the runs.

On MariaDB the `values`/`errors` columns are stored as JSON text without key normalization. The comparison holds there as well: both the old final `upsert` and the new single insert write `json_encode()` of the same merged PHP array.

### 5.4 vs-best (1C)

`tests/Support/vs-best-benchmark.php`, a synthetic fixture with a fixed seed: 20 suppliers; USD (two rates), JPY, CNY with no rate, IDR identity; 2% soft-deleted PRs and quotations, 1% soft-deleted POs; 5% unavailable items; zero and half-step prices for ties; statuses accepted/submitted/rejected/draft. 20 warm runs per scenario, with the summary cache flushed before each run.

| Items (history) | Scenario | p50 ms | p95 ms | Queries | SQL ms | Rows |
|---|---|---|---|---|---|---|
| 4,300 (1,925) | default | 363.5 | 488.1 | 2 | 273.5 | 3,365 |
| 4,300 | date 2026 | 190.7 | 313.1 | 2 | 178.1 | 1,672 |
| 4,300 | keyword | 330.4 | 379.8 | 3 | 347.3 | 763 |
| 22,000 (9,830) | default | 1,165.5 | 1,302.2 | 2 | 1,287.3 | 17,111 |
| 22,000 | date 2026 | 773.4 | 887.2 | 2 | 870.9 | 8,675 |
| 22,000 | keyword | 1,440.4 | 2,154.6 | 3 | 1,235.3 | 810 |

EXPLAIN ANALYZE at 22k (default): the summary query took 798 ms, of which the ranked-history window and materialization took ~110 ms (8,000 rows). The page query took 590 ms with the same ~110 ms. The rest is the left-join chain over every candidate row (17,111) and the final aggregate or sort.

Decision: **the history-restriction rewrite was not implemented.** Materials in the candidate set cover essentially all history materials (2,078 ranked winners of ~2,175 materials, also with the 2026 date filter). Restricting the partition would not reduce the materialized rows, and the step is at most ~14% of the query. The rule "implement only with a meaningful, plan-explained benefit" is not met. No index or schema change was made.

Deployment prerequisite: `ROW_NUMBER() OVER (...)` requires MySQL ≥ 8.0 or MariaDB ≥ 10.2. **Production runs MariaDB 11.4.13-cll-lve (PROD), so the prerequisite is met.**

---

## 6. Regression tests and exact outcomes

| Run | Outcome |
|---|---|
| `LocalProcurementCommitRevalidationTest` on the **unchanged** code | 8 passed, 1 failed (PO lookup ceiling: 12 > 9, the expected pre-change failure) |
| `LocalProcurementCommitRevalidationTest` + `LocalProcurementCommitBudgetTest` after §4.1–4.2 | 13 passed (89 assertions) |
| After the `recordBatches()` change: both new files + `LocalProcurementBackgroundImportTest` + `LocalImportRevalidationTest` | 72 passed (582 assertions) |
| `tests/Feature/LocalInvoice` (all, including the isolation and concurrency tests) + `tests/Unit/LocalProcurementStreamingReaderTest.php` + `tests/Unit/TranslationParityTest.php` + `tests/Feature/RouteContractTest.php` | 555 passed (44,625 assertions) |
| After §4.4: both new files + `LocalProcurementBackgroundImportTest` | 48 passed (479 assertions), including the new lock-order test |
| **Full suite** (`config:clear` + `php artisan test`, i.e. `composer test`) on the final code | **2,981 passed, 3 failed** (240,606 assertions), 2,494 s |
| The 3 failing tests re-run on the unchanged code (`adasi_perf_baseline` worktree at `7323047`) | **The same 3 fail identically** — not regressions (see below) |
| `php -l` / `vendor/bin/pint --test` on all changed PHP files; `git diff --check` | passed |

The three failures that already exist on `master`:

| Test | Failure on both trees |
|---|---|
| `UserFacingCopyInventoryTest::test_final_semantic_ledger_covers_every_current_copy_candidate_with_a_reason` | Copy-audit ledger out of date (a known red baseline) |
| `AuthRateLimitingTest::test_mfa_code_challenge_is_limited_by_pending_user` | "Session missing error: code" |
| `LocalizationPerformanceTest::test_js_payload_is_bounded_to_the_declared_ui_domains_and_plain_data` | JS dictionary is 16,462 bytes, limit 16,384 — the same size on both trees; this change set adds no JS translation key |

Not run: browser tests and staging (no staging environment exists; the owner waived it).

---

## 7. Business and security invariants verified

| Invariant | Evidence |
|---|---|
| Whole-file atomicity; no partial master/audit commits | Budget test: zero PO/GR and zero creation/confirm audits after an overrun that happened after writes; the existing rollback-after-a-later-batch test passes |
| No duplicate PO/GR | Existing replay and idempotency tests pass; unique constraints untouched |
| Every confirm-time rejection preserved | 8 characterization cases pass before and after (renamed supplier, ambiguous name, lost scope, pending account, inactive account, conflicting PO, cancelled PO, different PR) |
| Supplier eligibility revalidated under lock | Unchanged in `createImportBatch()`; supplier-name re-resolution at commit preserved |
| Lock order | PO confirm: supplier `users` (shared, ascending id) → POs (exclusive, ascending id) → per-batch maps, the same order as invoice submission; GR confirm unchanged. Lock-order test and probe pass |
| Exact audit snapshots | The existing `after_values` equality test passes; the audit writer is untouched |
| Owner/attempt fencing | The FAILED update is guarded by `owner_job_key` + `IMPORTING`; a completed import is not overwritten (test) |
| Slot release | FAILED is not in `ACTIVE`; a new import starts with `active_per_user=1` (test) |
| Transient failures retry | A non-budget exception propagates and the status stays `IMPORTING` (test) |
| Role and ownership controls | Import routes and policies, the owner/role tests in `LocalImportRevalidationTest`, `LocalInvoiceScopeIsolationTest` and `SupplierDataIsolationTest` (full suite) pass |
| Financial precision, exchange-rate rules, invoice/payment logic | Not touched |

---

## 8. Queue/Cron configuration recommendations

Canonical text: [CPANEL-PERFORMANCE-RUNTIME.md](../guides/CPANEL-PERFORMANCE-RUNTIME.md) §5. Production-specific lines: [PRODUCTION-CRON-COMMANDS-20261012.md](../guides/PRODUCTION-CRON-COMMANDS-20261012.md).

Production today (PROD, cPanel screenshot of 21 October): scheduler; `queue:work database --queue=exports,default --sleep=1 --max-time=50 --tries=3 --timeout=600` with `async-export-worker.lock`; `queue:work database --queue=imports --sleep=1 --max-time=50 --tries=3 --timeout=300 --memory=384` with `import-worker.lock`. None of them uses `--stop-when-empty`.

Recommended change (not applied; production Cron was not modified):

1. Edit the export entry from `--queue=exports,default` to `--queue=exports`, keeping its lock and log.
2. Add a `default` worker:

```sh
cd /home/adaw2196/supplierportal && /usr/bin/flock -n /home/adaw2196/supplierportal/storage/framework/default-worker.lock /usr/local/bin/ea-php83 artisan queue:work database --queue=default --sleep=1 --max-time=50 --tries=3 --timeout=60 >> /home/adaw2196/supplierportal/storage/logs/default-worker.log 2>&1
```

3. Before step 2, check the account's process limit and PCNTL (`/usr/local/bin/ea-php83 -m | grep pcntl`). If a fourth Cron process is not allowed, use the fallback instead: change the export entry to `--queue=default,exports` and add nothing.

Other points:

- `--stop-when-empty` was removed from every guide. Without it, each worker stays resident about 50 s per minute and polls `jobs` once per second.
- `DB_QUEUE_RETRY_AFTER=660` stays above every job timeout.
- Run `queue:restart` after each deploy, and pause import admission while switching import code.
- `composer dev` now also runs `queue:listen --queue=imports`.

---

## 9. Remaining items

| # | Item | Status |
|---|---|---|
| B1 | Production DB engine/version | **Resolved**: MariaDB 11.4.13-cll-lve supports window functions |
| B2 | Cron lines / `flock` / PCNTL / process limits | Cron lines and `flock` known (PROD). PCNTL and the account's process limit are not verified; they decide between three workers and the fallback (§8 step 3) |
| B3 | Staging with production-shaped data | **Waived by the owner** (no staging). Confirm headroom is known only from local hardware (worst case 30.1 s of 60 s) |
| B4 | Lock contention with invoice submission | **Deadlock fixed** (§4.4, §5.2). The wait during a large import remains by design; operating guidance documented |
| B5 | Production push latency while an export runs | Measurable only after the `default` worker is added in production |

---

## 10. Rollback instructions

- Code: discard the worktree branch, or revert the three changed application files, remove the new exception class, and remove the two lang keys. There are no migrations, data changes or configuration changes to undo. Imports staged by the new preview are read unchanged by the old commit code, because the staging content is identical.
- Documentation and `composer.json`: revert the listed files.
- Deploying this change set (once approved): pause import admission, drain `imports`, deploy, run `queue:restart`, unpause (see `LOCAL-PROCUREMENT-IMPORTS.md`).
- Cron (if changed): record the existing lines first (§8 lists today's lines); to roll back, restore them and run `queue:restart`.

---

## 11. Risks and remaining decisions

| Item | Status |
|---|---|
| PO lookup target ≤ 2 per batch not met (now 3) | The remaining lookup is the global PO lock sweep; removing it would change lock order. Recommend accepting |
| Failure text when several PO conflicts appear in one batch at confirm: previously up to ten messages, now only the first conflict (same translation keys) | Low impact; disclosed |
| Large imports make invoice submissions of the affected suppliers/POs wait up to the commit duration | By design (whole-file atomicity); guidance: run large imports outside invoice-submission hours |
| Local measurements on MySQL 8.0, production on MariaDB 11.4 | Plans can differ; the `FORCE INDEX` hints and SQL used are valid on both. No production benchmark was run |
| vs-best remains ~1.2 s p50 at 22k items locally; the cost is the full candidate join computed for both summary and page | Candidate for Stage 2 (single-pass summary and page); needs an equivalence proof |
| Benchmark results are single local runs | Disclosed; statement counts are deterministic |

---

## 12. Final Stage 1 acceptance status

| Item | Status |
|---|---|
| 1A-0 measurement (20k/70k, history scenarios, XLSX/CSV/XLS, plans) | Done (local) |
| 1A-1 redundant lookups | Implemented and verified (4 → 3 per batch; ≤ 2 not met, see §11) |
| 1A-1 plan-stable record reads (found during the work) | Implemented and verified |
| 1A-2 fail-fast budget overrun | Implemented and verified (tests, database-queue worker) |
| 1A-3 lock contention | Deadlock fixed (owner-approved option b); verified by probe and test |
| 1A-4 preview write amplification | Implemented; equivalence verified |
| 1A-5 70k gate | All 70k cases complete within 60 s locally (worst 30.1 s); staging waived by the owner |
| 1B queue topology | Documented against the real production lines; the Cron change is the owner's deployment step (§8) |
| 1C vs-best | Measured; rewrite rejected on evidence; production DB satisfies the window-function prerequisite |
| Full regression suite | 2,981 passed; the 3 failures are identical on unchanged `master` |

Implementation and repository-side verification are complete. The remaining items are deployment steps outside the repository: checking PCNTL and the process limit, then applying the Cron change in §8.

**STATUS: GATE 2 STAGE 1 IMPLEMENTATION COMPLETE — PENDING USER REVIEW**
