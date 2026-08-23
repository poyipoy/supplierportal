# PERF-03 — PR & PO DataTables

## Status

`COMPLETE — VERIFIED LOCAL IMPROVEMENT`

## Findings

### Purchase Requisition

- `OPTIMIZATION OPPORTUNITY`: the list hydrated every `PrItem` only to call `count()`.
- `VERIFIED BOTTLENECK`: Yajra's total-record query evaluated the total-KG and submitted-supplier correlated projections for all 2,067 PRs, taking about 29.7 ms in the captured run even though neither projection affects the unfiltered row count.

### Purchase Order

- `VERIFIED BOTTLENECK`: a 25-row page hydrated roughly 304 Eloquent models plus pivots: 25 POs, 3 suppliers, 29 quotations, 29 PRs, 4 periods, 5 rates, 48 quotation items, 48 PR items, 100 unused documents, 10 inspections, and 3 claims.
- `OPTIMIZATION OPPORTUNITY`: the list calculated every IDR total through nested PHP loops and loaded full claim/QC collections only to choose one action link.
- The four document rows per PO were not used anywhere in the list output.

## Changes

- PR `items` eager loading became `items_count`; period and creator eager loads now select only required columns.
- PR and PO base projections select only fields used by their DataTable rows.
- Yajra count queries now ignore display-only correlated selects, while retaining every filter predicate.
- DataTable JSON is allow-listed to rendered columns, preventing internal model attributes and eager relations from being serialized.
- Added a shared `PurchaseOrder::withResolvedTotalIdr()` projection. It preserves positive stored `amount`, the legacy zero-amount recalculation using quantity-aware PR weight and four-decimal rounding, quotation exchange-rate snapshots, and the existing rate fallback.
- PO eager loading is limited to supplier plus the narrow quotation → PR → period graph needed for display and existing search filters.
- Purchasing claim and NG-inspection actions use soft-delete-aware scalar latest-ID projections rather than full collections.
- Supplier pending/latest claim projections are additionally constrained by the authenticated supplier ID.
- Projected action IDs are encoded with the same Hashids configuration; numeric IDs remain absent from URLs.

## Warm Before vs After

| Metric | PR before | PR after | PO before | PO after |
|---|---:|---:|---:|---:|
| Queries | 5 | 4 | 12 | 6 |
| Cumulative SQL | 37.03 ms | 3.09 ms | 6.46 ms | 4.46 ms |
| Response time | 122.73 ms | 31.17 ms | 86.59 ms | 30.28 ms |
| Peak memory | 44 MB | 40 MB | 44 MB | 42 MB |
| JSON bytes | 262,636 | 104,476 | 221,735 | 102,024 |

These are warm local CLI controller/DataTable observations, not production P95. The PR SQL reduction primarily comes from excluding scalar display projections from the total-record count. The PO projection reduces both queries and hydration; a representative 25-row page retains only approximately 90 required models plus pivots instead of the prior graph.

## Financial Equivalence Evidence

The SQL total was compared read-only with the former PHP `resolved_amount × quotation snapshot rate` calculation for all 2,052 local POs:

- mismatches above `0.00001`: 0;
- maximum floating-point delta: approximately `1.86e-9`.

Feature coverage separately verifies that a positive stored amount remains authoritative and a zero stored amount uses the quantity-aware compatibility recalculation.

## Query-Plan Evidence

The representative PO projection uses existing indexes on PO creation date, pivot PO ID, quotation-item quotation ID, and primary-key joins. Claim/QC scalar projections use existing PO foreign-key indexes and small per-PO ordering. No new index was justified in this mission; the complete inventory and plan audit is PERF-05.

## Verification

- `php artisan test tests/Feature/ProcurementRevisionTest.php tests/Feature/PrItemRemarkTest.php` — 7 tests, 65 assertions, passed.
- `php artisan test tests/Feature/PurchaseOrderReferenceRemarkTest.php` — 6 tests, 75 assertions, passed.
- `php artisan test tests/Feature/HashidUrlSecurityTest.php tests/Feature/SupplierDataIsolationTest.php` — 14 tests, 128 assertions, passed.
- Relevant PHP syntax checks — passed.
- `php artisan performance:profile-critical` — passed after both projections.
- `git diff --check` — passed.
- Browser/DataTables visual interaction: `MANUAL_VISUAL_QA_REQUIRED`; browser automation was not run.

## Migrations

None.
