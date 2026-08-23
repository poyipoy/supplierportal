# PERF-05 — Index & Query Plan Audit

## Mission Status

`COMPLETE — FOUR VERIFIED REDUNDANT INDEXES SCHEDULED FOR SAFE REMOVAL`

The audit found four secondary indexes whose read capability is already supplied by a retained index. A reversible migration removes only those four. It was exercised against the PHPUnit database and was deliberately left pending on the operational local database.

## Scope and Evidence

Files inspected:

- `database/migrations/2026_05_08_043308_mission6_po_and_pr_updates.php`
- `database/migrations/2026_05_21_000001_add_performance_indexes.php`
- `database/migrations/2026_05_22_000001_restructure_po_consolidation.php`
- `database/migrations/2026_05_28_000003_add_remaining_performance_indexes.php`
- `app/Http/Controllers/Purchasing/PriceComparisonController.php`
- current list/comparison query shapes and the PERF-01 profiler results
- live local development `INFORMATION_SCHEMA.STATISTICS`, table statistics, `SHOW INDEX`-equivalent output, and read-only `EXPLAIN`

Environment measured: MySQL 8.0.30, local development data, 2026-08-23. These figures are representative of this checkout, not production cPanel measurements.

## Table Size and Cardinality

`INFORMATION_SCHEMA.TABLES.TABLE_ROWS` is an InnoDB estimate; the exact counts below came from `COUNT(*)`.

| Table | Exact rows | Estimated rows | Data bytes | Index bytes | Audited key distinct values |
|---|---:|---:|---:|---:|---:|
| `quotations` | 2,132 | 2,115 | 262,144 | 589,824 | 71 non-null `submitted_at` values |
| `purchase_requisitions` | 2,067 | 2,043 | 294,912 | 442,368 | 2,064 non-null `pr_number` values |
| `purchase_orders` | 2,052 | 2,038 | 212,992 | 458,752 | 2,052 `po_number` values |
| `po_quotations` | 2,056 | 2,038 | 147,456 | 229,376 | 2,052 `po_id` values |

Production table size and the exact bytes reclaimable by this migration are `NOT MEASURED — ENVIRONMENT REQUIRED`.

## Verified Redundancies

Classification for all four rows: `OPTIMIZATION OPPORTUNITY`. The redundancy itself is verified; an end-user latency bottleneck attributable to these indexes was not claimed.

| Drop | Retain | Evidence |
|---|---|---|
| `quotations_submitted_at_index (submitted_at)` | `quot_submitted_at_index (submitted_at)` | Same ordered column, index type, key length, and non-unique semantics. |
| `pr_number_index (pr_number)` | `purchase_requirements_pr_number_unique (pr_number)` | The unique index provides the same lookup/order access and enforces the required document-number invariant. The old table name in the retained index name survived the table rename and is harmless. |
| `purchase_orders_po_number_index (po_number)` | `purchase_orders_po_number_unique (po_number)` | The unique index provides the same lookup/order access and preserves atomic document-number uniqueness. |
| `po_quotations_po_id_index (po_id)` | `po_quotations_po_id_quotation_id_unique (po_id, quotation_id)` | `po_id` is the left-most prefix of the retained composite unique index. |

An exact-column-shape scan across the current schema found only the first three duplicate shapes. The pivot case was evaluated separately as a left-most-prefix redundancy rather than inferred from name alone.

## Query Plan Evidence

Read-only `EXPLAIN` results on the local dataset:

| Query shape | Chosen/forced key | Access | Estimated rows | Finding |
|---|---|---|---:|---|
| `quotations WHERE submitted_at >= ? ORDER BY submitted_at DESC LIMIT 25` | `quot_submitted_at_index` | `range`, backward index scan, covering | 2,056 | MySQL already chose the retained index. Forcing either submitted-at index produced the same access shape and row estimate. |
| `purchase_requisitions WHERE pr_number = ? LIMIT 1` | `purchase_requirements_pr_number_unique` | `const`, covering | 1 | Retained unique index is preferred. |
| `purchase_orders WHERE po_number = ? LIMIT 1` | `purchase_orders_po_number_unique` | `const`, covering | 1 | Retained unique index is preferred. |
| `po_quotations WHERE po_id = ?` | current single-column index | `ref` | 1 | Forcing the retained composite unique index also produced `ref`, key length 8, one estimated row, and a covering read. |

The price-comparison evidence does not justify adding an index in this mission:

- `VERIFIED BOTTLENECK`: the PERF-01 vs-best baseline spent 425.85 ms in SQL across 61 queries. Subsequent query-count work reduced repeated execution, while its remaining cost comes from multi-stage historical/current derived-query work. No missing simple index was proven.
- `VERIFIED BOTTLENECK`: a high-cardinality monthly historical request loaded approximately 2,000 rows. PERF-04 bounded the detail projection while retaining the complete chart series; this was a result-shape/hydration issue, not evidence for another index.
- `OPTIMIZATION OPPORTUNITY`: leading-wildcard `%keyword%` filters cannot make normal B-tree prefix lookups selective. Adding single-column indexes for those filters would add write/storage cost without a demonstrated read benefit.

Verdict for new indexes: `NOT CURRENTLY JUSTIFIED`. No new performance index was added.

## Change Implemented

`database/migrations/2026_08_23_000003_remove_verified_redundant_indexes.php`:

- drops the four explicitly named redundant indexes;
- verifies that the retained index exists and covers the removed index before each drop;
- becomes a safe no-op for any redundant index already removed manually;
- throws on unexpected schema drift instead of removing an unprotected access path;
- recreates the original four non-unique indexes in `down()` using explicit names and columns.

This does not change unique constraints, foreign keys, data, query semantics, supplier isolation, or document-number locking.

## Lock, Downtime, and Deployment Safety

The local tables are small, but cPanel may run MySQL or MariaDB versions with different online-DDL behavior. Dropping a secondary index is often in-place/online on modern MySQL, but that capability must not be assumed. Recreating indexes during rollback can be more expensive and can block writes.

Production procedure:

1. capture `SHOW INDEX` and table sizes on production;
2. test the migration on a current staging copy;
3. schedule a low-traffic deployment window and take the normal database backup;
4. check for long-running transactions before `php artisan migrate --force`;
5. monitor cPanel/MySQL process activity and application error rate;
6. verify the four retained indexes and confirm the four redundant names are absent;
7. run representative PR, PO, comparison, and document-number smoke tests.

MySQL DDL is not transactionally atomic across all four tables. The migration is intentionally re-runnable: if a later statement fails, already-absent redundant indexes are skipped on the next attempt. Do not run rollback during peak traffic without separately assessing index-build time.

`down()` restores the repository's canonical pre-migration index set. If `up()` finds that an operator had already removed one of the redundant indexes manually, a later rollback will recreate that canonical index even though this migration did not remove it in that deployment. This asymmetry is intentional and documented; capture production `SHOW INDEX` before rollback if preserving manual drift is required.

## Write Overhead and Before/After Metrics

Each insert/update affecting the indexed key currently maintains an unnecessary second B-tree for the audited key or prefix. Removing those structures reduces index storage and write amplification by construction. Exact write-latency improvement, disk bytes reclaimed, buffer-pool benefit, and production query latency are `NOT MEASURED — ENVIRONMENT REQUIRED`; no destructive write benchmark was run against operational data.

## Verification Actually Executed

- PHP syntax checks for the migration and focused test: passed.
- Laravel Pint check for the migration and focused test: passed.
- `php artisan test --filter=RedundantIndexMigrationTest`: passed, 1 test and 38 assertions.
- The test verified the clean state, `down()` recreation, and a second `up()` cleanup while retaining all covering/unique indexes.
- `php artisan migrate:status`: new migration is `Pending` on the operational local database.
- Read-only local `INFORMATION_SCHEMA`, `COUNT(*)`, and `EXPLAIN` queries: completed.
- Operational database migration: not run.

## Risks and Deferred Items

- Production table size, DDL algorithm, lock duration, and cPanel resource headroom require production/staging confirmation.
- Optimizer statistics should be refreshed/observed after deployment if production plans differ from local plans.
- vs-best SQL remains a known expensive query shape; a structural rewrite should be accepted only with financial-equivalence tests and representative `EXPLAIN ANALYZE` evidence.
- Full-text/external search is `NOT CURRENTLY JUSTIFIED` for the current shared-hosting scope.

## Files Added

- `database/migrations/2026_08_23_000003_remove_verified_redundant_indexes.php`
- `tests/Feature/RedundantIndexMigrationTest.php`
- `docs/results/PERF-05-INDEX-QUERY-PLAN-AUDIT.md`
