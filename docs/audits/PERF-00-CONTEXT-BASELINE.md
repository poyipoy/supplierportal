# PERF-00 — Context and Safety Baseline

## Scope

This audit establishes the repository, runtime, data, and correctness boundaries for the Supplier Portal performance program. It records facts observed before optimization and does not authorize production database mutation or infrastructure changes.

## Context Files Read

The following files were read completely before implementation:

- `AGENTS.md`
- `CLAUDE.md`
- `claudes-cognitive-framework-for-laravel-development.md`
- `C:\Users\BAHRIALGI\Downloads\SUPPLIER-PORTAL-COMPREHENSIVE-PERFORMANCE-TUNING-MASTER-PLAN.md`
- `composer.json`
- `package.json`
- `.env.example`

No nested or directory-specific `AGENTS.md` exists in the repository. No mandatory context file was missing.

## Repository Baseline

- Branch: `master`
- Baseline commit: `70b77455bcbc46b8bdc6ca324c9341bfce3f0286`
- Pre-existing worktree changes:
  - modified: `AGENTS.md`
  - untracked: `.claude/`
  - untracked: `CLAUDE.md`
  - untracked: `claudes-cognitive-framework-for-laravel-development.md`
- Policy: preserve all pre-existing changes; do not commit, push, reset, or overwrite them.

## Observed Local Runtime

| Item | Observed value |
|---|---|
| PHP | 8.2.30 CLI |
| Laravel | 12.66.0 |
| Database | MySQL 8.0.30, database `adasi_portal` |
| Application environment | `local` |
| Cache | file |
| Session | file |
| Queue | database |
| Broadcasting | pusher |
| Config cache | not cached |
| Route cache | not cached |
| View cache | cached |

These local driver values are not evidence of the cPanel production configuration. The production example configuration uses database cache/session/queue.

## Representative Local Dataset

The existing local database already contains a useful read-only baseline, so `StressTestSeeder` was not executed.

| Table | Rows |
|---|---:|
| users | 12 |
| purchase_requisitions | 2,067 |
| pr_items | 4,343 |
| quotations | 2,132 |
| quotation_items | 4,351 |
| purchase_orders | 2,052 |
| po_quotations | 2,056 |
| notifications | 438 |
| conversations | 17 |
| messages | 59 |
| material_claims | 11 |
| qc_inspections | 26 |

## Correctness Invariants Verified in Source

- Canonical PR table: `purchase_requisitions`.
- PR and PO numbering use `document_sequences` inside database transactions with `lockForUpdate()`.
- Supported currencies are `USD`, `JPY`, `IDR`, and `CNY` through `ExchangeRate::CURRENCIES`.
- Quotation amounts are calculated through `QuotationItem::calculateAmount()` using `PrItem::total_weight`.
- Supplier-owned quotations, POs, and claims use `users.id` in `supplier_id`; supplier-facing queries remain user-scoped.
- Consolidated PO relationships use the `po_quotations` pivot; there is no active `purchase_orders.quotation_id` contract.
- Soft-delete behavior on legal/procurement documents must remain intact.

## Documentation and Configuration Drift

1. `AGENTS.md` describes 48 migrations; the local schema currently reports 50 completed migrations.
2. `.env.example` documents `BROADCAST_CONNECTION=log` with Reverb placeholders, while current project guidance and the observed local runtime identify Pusher as the active realtime intent. This remains configuration-documentation drift and must not be “fixed” by enabling persistent Reverb on cPanel.
3. The master plan treats the duplicate `quotations.submitted_at` index as a candidate. The current local schema confirms both `quot_submitted_at_index` and `quotations_submitted_at_index` exist on the same ordered column; removal still requires query-plan and migration-safety review.
4. `StressTestSeeder` reads `$item->total_weight` from query-builder rows. `total_weight` is a model accessor, not a persisted `pr_items` column, so rerunning the current seeder is likely to fail or calculate incorrectly. It was not executed.
5. Local cache/session drivers are file-backed, whereas `.env.example` specifies database-backed production drivers. Measurements must label which environment they represent.

## Verification Commands Established

- Targeted tests: `php artisan test --filter=<TestName>`
- Supplier isolation: `php artisan test --filter=SupplierDataIsolationTest`
- Hashid security: `php artisan test --filter=HashidUrlSecurityTest`
- Full suite: `composer test`
- PHP formatting: `vendor/bin/pint`
- Frontend build: `npm.cmd run build`
- Cached-view check: `php artisan view:cache`
- Route/config cache checks: `php artisan route:cache`, `php artisan config:cache`
- Working-tree validation: `git diff --check`

The test suite uses the dedicated MySQL database `adasi_portal_test` with `RefreshDatabase` where applicable. No pending migration exists in the local development database.

## PERF-00 Status

`COMPLETE — CONTEXT AND SAFETY BASELINE ESTABLISHED`
