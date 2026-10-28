# Handoff — ADASI Supplier Portal Architecture Remediation

**Written for:** Codex, continuing this branch in a fresh session with no prior context of the work below.

**Date:** 2026-10-23
**Branch:** `fix/architecture-remediation` (local only, never pushed)
**Base:** `origin/master` @ `257acb0` (merge of PR #8)
**Current HEAD:** `3932224`
**Status:** full test suite green on a clean checkout; one dependency advisory intentionally left open pending an owner decision; MariaDB execution configured but not run (no local MariaDB/Docker available).

Do not restart the investigation or redo work described as DONE below. Read this file fully before touching any file.

---

## 1. How this branch came to exist

Two prior sessions on this repo produced this branch:

1. **Architecture assessment** (read-only, Plan Mode) against `poyipoy/supplierportal` HEAD `1594754`. Found three confirmed issues across both business cores (Import Procurement, Local Supplier/Finance) plus three concrete CI failures the user had separately observed on `master` @ `257acb0`.
2. **Implementation session** that created this branch from `257acb0`, fixed all three findings plus the three CI failures, and verified everything on a clean `git worktree` checkout (not the dirty main working tree).
3. **This stabilization session** closed the two remaining gaps the implementation session had left as "owner decision needed before proceeding": the tests that depended on real, gitignored, proprietary Infor export files, and part of the `npm audit` advisories.

Everything below is **already committed** to this branch. Nothing here is a plan to execute — it is a record of what was done, with the evidence, so you can verify it independently and decide what to do next.

## 2. Non-negotiable constraints (carried over, still apply)

- No Infor LN integration of any kind.
- No new third-party runtime services.
- Pusher stays the notification provider (not touched).
- Both business cores preserved: Import Procurement and Local Supplier/Finance.
- No weakening of authorization, supplier isolation, transaction integrity, audit trails, or existing security controls.
- No production or development database was touched. Only the isolated test DB `adasi_portal_test` was used, always after running `TestingEnvironmentDatabaseSafetyTest` first and checking no other `php.exe` test process was running.
- Nothing has been pushed, merged, deployed, or had production data modified.
- Do not normalize CRLF/LF repo-wide; only touched where it affected repeatability (see §6).
- Do not delete or weaken an assertion to force a green result. Every fix below either made a failing test pass for the right reason, or added a new regression test that fails on the pre-fix code and passes after.

## 3. Commit-by-commit summary

Nine commits, `257acb0..HEAD`, in this order:

| Commit | What |
|---|---|
| `f2d508d` | CI bootstrap order fix (env before `composer install`) + Symfony `http-foundation`/`yaml` patch bump |
| `d1d3cbc` | **Finding 1**: freeze quotations referenced by an award/PO on every review path |
| `ea2e70b` | **Finding 2**: business-rule refusals in Finance/GA render as feedback, not HTTP 500 |
| `355bdbf` | **Finding 3a**: race-test the authoritative `lockAndApprove` path, remove dead `InvoiceWorkflowService` + its 7 policy abilities |
| `2fa09ce` | **Finding 3b**: `DROP CHECK` → `DROP CONSTRAINT` in 5 migrations (MariaDB compat) |
| `40ebfd9` | **Finding 3c**: layered CI — PR job gets core workflow suites added; new `deep` job runs full suite on MySQL 8.0 **and** MariaDB 11.4 |
| `3c93078` | Regenerated the stale `UI-REDESIGN-RESULT/PHASE-7-COPY-AUDIT.jsonl` ledger (was already stale on `master`, unrelated to this remediation, but blocked a green CI) |
| `33acd5c` | **Prior session**: replaced the 5 tests that read real Infor exports with a synthetic fixture generator; kept the real files usable via an opt-in test group |
| `3932224` | **Prior session**: `vite` 7.3.6→7.3.7, forced `postcss-selector-parser` ^7.1.6 via npm `overrides`; left the `braces` advisory open (documented why) |

Full file manifest (`git diff --name-status origin/master..HEAD`):

```
M  .github/workflows/tests.yml
M  AGENTS.md
M  CLAUDE.md
M  UI-REDESIGN-RESULT/PHASE-7-COPY-AUDIT.jsonl
M  app/Console/Commands/ReconcileProcurement.php
A  app/Exceptions/BusinessRuleArgumentException.php
A  app/Exceptions/BusinessRuleException.php
A  app/Exceptions/BusinessRuleViolation.php
M  app/Http/Controllers/ConversationMessageController.php
M  app/Http/Controllers/Finance/FinanceInvoiceController.php
M  app/Http/Controllers/Purchasing/QuotationListController.php
M  app/Http/Controllers/Supplier/QuotationController.php
M  app/Models/Quotation.php
M  app/Policies/LocalInvoicePolicy.php
M  app/Services/Ga/GaClaimService.php
M  app/Services/Ga/GaVerificationService.php
M  app/Services/LocalInvoice/InvoicePhysicalReceiptService.php
M  app/Services/LocalInvoice/InvoiceVerificationService.php
D  app/Services/LocalInvoice/InvoiceWorkflowService.php
M  app/Services/Payment/LocalInvoicePaymentService.php
M  app/Services/Payment/LocalInvoiceVoucherService.php
M  app/Services/Payment/PaymentBatchService.php
M  app/Services/Payment/PaymentExecutionService.php
M  app/Services/Payment/PaymentVoucherService.php
M  app/Support/ConversationPresenter.php
M  bootstrap/app.php
M  composer.lock
M  context.md
M  database/migrations/2026_09_04_000003_harden_shipment_integrity_constraints.php
M  database/migrations/2026_09_08_000001_change_shipment_items_to_qty_and_actual_weight.php
M  database/migrations/2026_10_14_000002_add_logistics_terms_to_purchase_requisitions.php
M  database/migrations/2026_10_14_000003_add_logistics_terms_to_purchase_orders.php
M  database/migrations/2026_10_14_000004_add_ordered_and_acceptance_fields_to_pr_item_awards.php
M  package-lock.json
M  package.json
M  resources/views/purchasing/quotations/show.blade.php
A  tests/Feature/Finance/BusinessRuleRefusalHttpTest.php
A  tests/Feature/LocalInvoice/InforRealExportSampleTest.php
M  tests/Feature/LocalInvoice/LocalGrImportTest.php
M  tests/Feature/LocalInvoice/LocalInvoiceConcurrencyTest.php
M  tests/Feature/LocalInvoice/LocalPoImportTest.php
A  tests/Feature/Procurement/QuotationReviewInvariantTest.php
A  tests/Support/InforExportFixtures.php
M  tests/Support/local-invoice-concurrency-worker.php
M  tests/Unit/LocalProcurementStreamingReaderTest.php
```

45 files, +2604/−1624. The diff was reviewed line-by-line: the 9 service files under `app/Services/Payment/` and `app/Services/Ga/` (plus `InvoiceVerificationService`, `InvoicePhysicalReceiptService`) contain **only** `throw new RuntimeException/InvalidArgumentException` → `throw new BusinessRuleException/BusinessRuleArgumentException` swaps and their matching `use` imports — zero other lines changed in those files. Nothing touches Pusher, `routes/`, `config/`, `lang/`, middleware, `Money`, or `BusinessTime`.

## 4. What each finding fix actually does (read the code before changing it)

### Finding 1 — quotation review invariant (`d1d3cbc`)

`app/Models/Quotation.php` gained `isReferencedByAwardOrPurchaseOrder()`, the single source of truth for "this quotation is locked because an item award or a Purchase Order (including soft-deleted) points at it." `canApproveBy()` and `canRequestRevision()` now call it. Every writer (`QuotationListController` review actions, `ConversationMessageController` quick actions, `Supplier\QuotationController::create`, `ConversationPresenter`, the Blade view) was updated to defer to this one rule instead of re-deriving it. The Purchasing review page now locks `PurchaseRequisition → Quotation` in the same order every other award writer uses (`QuotationListController::lockQuotationForReview()`).

`procurement:reconcile` gained `unassigned_award_on_ineligible_quotation_count` to surface any pre-existing stranded awards in real data (read-only metric, no backfill).

Regression test: `tests/Feature/Procurement/QuotationReviewInvariantTest.php` — 9 tests, verified to fail on pre-fix code (8/9 failed) and pass after.

### Finding 2 — business-rule refusals as feedback (`ea2e70b`)

New marker interface `App\Exceptions\BusinessRuleViolation`, implemented by two thin subclasses that **preserve the existing exception hierarchy** so no existing `catch` or test assertion needed to change:

- `BusinessRuleException extends RuntimeException implements BusinessRuleViolation`
- `BusinessRuleArgumentException extends InvalidArgumentException implements BusinessRuleViolation`

`bootstrap/app.php` registers one renderer: web requests get `back()->withInput()->with('error', $message)`, JSON requests get `422 {success:false, message}`. `dontReport()` keeps these out of the error log. `FinanceInvoiceController`'s two existing broad `catch (\InvalidArgumentException|\RuntimeException $e)` blocks were narrowed to `\InvalidArgumentException|BusinessRuleViolation` so a `QueryException` (which extends `RuntimeException`) no longer gets its SQL flashed to the user.

80 throw sites across 9 services were swapped (see §3 diff note). Regression test: `tests/Feature/Finance/BusinessRuleRefusalHttpTest.php` — 9 tests, verified to fail (9/9, as HTTP 500) on pre-fix code and pass after.

### Finding 3 — CI and verification gaps (`355bdbf`, `2fa09ce`, `40ebfd9`)

- **Legacy workflow removed**: `InvoiceWorkflowService` (dead code, no runtime caller, no route — `context.md` §8 already said to remove it) and the 7 `LocalInvoicePolicy` abilities only it used. The one race test that exercised it now races the real path, `InvoiceVerificationService::lockAndApprove`, against `requestRevision`/`reject` from two separate DB connections, and was mutation-tested (removing the row lock makes it fail).
- **Migration portability**: 7 occurrences of `DROP CHECK name` → `DROP CONSTRAINT name` across 5 migration files. MariaDB's `ALTER TABLE` only documents `DROP CONSTRAINT`; MySQL has accepted it since 8.0.19. Verified: `ShipmentMigrationRollbackTest` + `ProcurementMigrationRollbackTest` (up/down/up-again) pass on MySQL 8.0.30.
- **CI workflow** (`.github/workflows/tests.yml`): `php` job's env-prep step moved before `composer install` (was the actual root cause of the Composer failure the user reported — `package:discover` boots the app, which defaulted to `production` and tripped the `HASHIDS_SALT` guard with no `.env` present). `node` job now installs the Composer autoloader (`--no-scripts`) because two JS tests shell out to `php -r "require 'vendor/autoload.php'; ..."`. New `deep` job (triggers: push to `master`, nightly cron, `workflow_dispatch`) runs the full suite — `--testsuite=Parallel` then `--testsuite=Serial` — against a matrix of `mysql:8.0` and `mariadb:11.4` service containers, printing `SELECT VERSION()` so the actual engine is logged. PR/push job also gained a "Core workflow suites" step covering both cores' main invariants (quotation/award/PO/shipment/QC/claims; invoice submit/verify/DRP/voucher/settlement/GA), not just the isolation/auth subset that existed before.

### Prior session's additions (`33acd5c`, `3932224`)

See §5 and §6.

## 5. Infor export test fixtures (`33acd5c`)

**Problem found**: 4 tests read two real Infor LN export files from the repo root (`whinh3512m600_0520_…xlsx`, `tdpur4100m000_0520_…xlsx`). `.gitignore:35` (`/*.xlsx`) deliberately keeps them out of git, so they fail on any fresh checkout — including CI. A 5th test (`test_row_limit_counts_meaningful_rows_and_rejects_without_returning_a_ready_file`) was a **false pass**: it expected a `ValidationException` and got one — but for the wrong reason (missing file), not the row-limit it claimed to test.

**Fix**: `tests/Support/InforExportFixtures.php` builds both workbooks in-memory from OpenSpout at test time — same column layout (position-based, blank headers including the GR UoM column), same native date cells, same integer qty/amount types, same extra `enums` sheet Infor appends. Verified parity: read through the production `LocalProcurementStreamingReader`, the synthetic files and the real files produce **identical** values across 18 structural characteristics (row counts, receipt/PO counts, max lines per receipt, UoM, qty type, date format, warnings, etc.).

All 5 tests now run on the synthetic fixtures. The row-limit test additionally asserts the exact `local_procurement.import.row_limit` message, so it can no longer pass via "file not found."

**Real files stay usable**, opt-in, via `tests/Feature/LocalInvoice/InforRealExportSampleTest.php` (`#[Group('infor-real-samples')]`), activated with `INFOR_SAMPLE_DIR=/path/to/samples php artisan test --group=infor-real-samples`. It also re-checks that the real files still match the synthetic fixture's structural profile, so a future Infor export-format change surfaces as a fixture gap, not a silent pass. Verified locally against the real files on the original machine: 2/2 passed.

Verification run (clean worktree, no `.xlsx` files present): 33 passed, 2 skipped (the opt-in group, correctly skipped without `INFOR_SAMPLE_DIR`).

## 6. npm audit (`3932224`)

Before: 8 advisories (2 moderate, 6 high) — `vite` (5, direct), `postcss-selector-parser` (via `tailwindcss@3`/`postcss-nested`, no direct fix available without a Tailwind major bump), `braces`/`micromatch`/`chokidar`/`fast-glob`/`tailwindcss` (all chained to one `braces` advisory).

Fixed:
- `vite`: `^7.0.7` → `^7.3.7` (within-major patch, direct `npm install vite@^7.3.7`).
- `postcss-selector-parser`: no non-major fix exists through `tailwindcss@3`'s own dependency tree (it requests `^6.1.2`; the fix is only in `7.1.6+`). Forced via `package.json` `"overrides": { "postcss-selector-parser": "^7.1.6" }`. The one breaking change between 6.x and 7.0.0 ("make insertions during iteration safe") does not affect Tailwind 3's usage — verified by running `npm run build` before and after and diffing SHA-256 of every emitted file in `public/build/` (16 files): **byte-identical** both times.

Left open, deliberately: `braces` ≤3.0.3 (GHSA-vfj7-8cjw-p6xm, high), reached through `micromatch`/`chokidar`/`fast-glob`, all owned by `tailwindcss@3.4.19`. **No patched `braces` release exists** (registry's latest is still 3.0.3), so no override can close it. `npm audit fix --force` would pull `tailwindcss@4.3.3`. That is explicitly out of scope per the task's own instruction ("do not automatically migrate from Tailwind 3 to Tailwind 4"), and the blast radius is real: `tw-` prefixed class tokens appear ~23,333 times across 272 files, plus `tailwind.config.js`'s `prefix`/`corePlugins.preflight: false`/`safelist`/`forms` plugin setup would all need re-validation under Tailwind 4's new config model.

Current state: `npm audit --package-lock-only` → 5 high (down from 8/2-moderate+6-high), all tracing to the single `braces` advisory. `npm audit --omit=dev` → clean (everything affected is a dev/build-time dependency). `composer audit --locked` → clean.

**Open decision for the repo owner**: accept the `braces` advisory as documented risk (dev-only, build-time, no shipped runtime exposure), or schedule a Tailwind 4 migration as its own project. Until decided, `security.yml`'s `npm audit` step stays red. Do not suppress or weaken that gate to make it green.

## 7. Verification already performed (what you can trust without rerunning)

All of the below were run in a **clean `git worktree`** checkout of the relevant commit — never the dirty main working tree — with `.env`/`.env.testing` freshly copied from `.env.testing.example`, against the isolated `adasi_portal_test` MySQL 8.0.30 database, after confirming no other PHP test process was running.

| Check | Result |
|---|---|
| `composer install` (no `.env` present beforehand, as CI does it) | exit 0 after the bootstrap-order fix (was exit 1 before) |
| `npm ci` / `npm run build` | exit 0; build output byte-identical before/after both dependency changes |
| Pint on every changed PHP file (36–40 files depending on commit) | passed |
| `node --test tests/js/*.test.mjs` | 541/541 passed, every run |
| `composer audit --locked` | clean |
| `npm audit --package-lock-only` | 5 high (down from 8; see §6) |
| `npm audit --omit=dev` | clean |
| `php scripts/check-tracked-artifacts.php` | passed |
| `php artisan test --parallel --processes=4 --testsuite=Parallel` (final, on `3932224`, no `.xlsx` samples present) | 2924 tests, 0 failures/errors, 2 skipped (opt-in group) |
| `php artisan test --testsuite=Serial` (final, on `3932224`) | 137/137 passed |
| `tests/Feature/Procurement/QuotationReviewInvariantTest.php` | 8/9 failed pre-fix → 9/9 pass post-fix |
| `tests/Feature/Finance/BusinessRuleRefusalHttpTest.php` | 9/9 failed (as HTTP 500) pre-fix → 9/9 pass post-fix |
| `LocalInvoiceConcurrencyTest` race test | passes; fails when `lockForUpdate()` is removed from `lockAndApprove` (mutation-checked) |
| `ShipmentMigrationRollbackTest` + `ProcurementMigrationRollbackTest` | pass after the `DROP CONSTRAINT` change |
| 525 Import-core regression tests, 948 Core-2 (Finance/LocalInvoice/GA) regression tests | all pass |
| **MariaDB 11.4** | **not executed anywhere, ever, in this work.** No Docker/WSL/MariaDB binary on the development machine. The `deep` CI job is configured and will run it on first push — this is the one thing in this whole branch that is config-only, unverified by actual execution. |

## 8. What is NOT done — do not claim otherwise

1. **MariaDB has never actually run.** Everything said about MariaDB compatibility (the `DROP CONSTRAINT` fix, the `deep` job matrix) is based on reading MariaDB's own `ALTER TABLE` documentation and MySQL's changelog, not on an executed test. The first real evidence will be the `deep` job's `mariadb-11.4` leg after this branch is pushed.
2. **Tailwind 4 / `braces` advisory**: open, by design, pending an owner decision (§6).
3. **Production `DB_CONNECTION`** (`mysql` vs `mariadb` driver) is unknown — not discoverable from this repo.
4. **Three business decisions flagged by the original assessment, still unresolved:**
   - Should `accept` on an awarded quotation really be refused (current behavior, matching the chat path), or should accept-while-awarded be allowed?
   - Is an explicit "release this award" operation needed for cases where Purchasing wants to re-open negotiation after awarding?
   - Should quotation expiry also be checked on the award/consolidation path (currently only checked on the direct-PO path)?
5. **Nothing has been pushed.** `git log origin/master..HEAD` shows all 9 commits are local-only.
6. An untracked `.rnd` file sits at the repo root (OpenSSL RNG seed, 1024 bytes, dated 2026-10-20 — predates this work, not gitignored, not committed, not created by this remediation). Leave it alone unless the user asks about it specifically.
7. A detached scratch worktree at `C:\Users\BAHRIALGI\AppData\Local\Temp\adasi-final-check` may or may not still be registered (a removal was attempted; a Windows file lock made one attempt fail with "Permission denied," a retry may have succeeded). Run `git worktree list` — if it still shows up and the directory is gone, run `git worktree prune`. It is **outside** the repo and holds no commits; it is safe to discard regardless.

## 9. If you need to re-verify from scratch

```bash
# From the real repo (not a worktree), confirm the branch:
git log --oneline origin/master..HEAD   # should show the 9 commits above

# Build a clean worktree to test from (never test from a dirty working tree):
git worktree add --detach /tmp/adasi-verify HEAD
cd /tmp/adasi-verify
cp .env.testing.example .env
cp .env.testing.example .env.testing
composer install --no-interaction --prefer-dist --no-progress
php artisan key:generate --no-interaction
php artisan key:generate --env=testing --no-interaction
npm ci --no-audit --no-fund
npm run build

# Safety check before any DB-backed test:
php artisan test --filter=TestingEnvironmentDatabaseSafetyTest

# Full suite:
php artisan test --parallel --processes=4 --testsuite=Parallel
php artisan test --testsuite=Serial

# Audits:
composer audit --locked --no-interaction
npm audit --package-lock-only

# Cleanup when done:
cd /c/laragon/www/adasi_portal_supplier
git worktree remove --force /tmp/adasi-verify
```

To exercise the optional real-Infor-sample path, set `INFOR_SAMPLE_DIR` to a folder containing `whinh3512m600_0520_20260924-134847_116644.xlsx` and `tdpur4100m000_0520_20260924-133401_101112.xlsx` (never commit these) and run `php artisan test --group=infor-real-samples`.

## 10. Recommended next step

This branch is ready for `git push` and a PR against `master`, which will give real GitHub Actions results — in particular the first actual MariaDB execution. Do not push without the user's explicit go-ahead; pushing was out of scope for every session that touched this branch so far. If asked to proceed, push `fix/architecture-remediation`, open the PR, and report back the `deep` job's MariaDB leg result once it completes — that closes the one remaining unverified claim in this whole effort.
