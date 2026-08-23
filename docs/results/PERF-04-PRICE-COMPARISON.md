# PERF-04 — Price Comparison

## Mission Status

`COMPLETE — VERIFIED N+1 AND HIGH-CARDINALITY HYDRATION BOTTLENECKS REMEDIATED`

The mission preserved the existing financial rules: quotation prices use document snapshot rates, PO-backed history prefers the PO snapshot and falls back to the quotation snapshot, quotation amounts remain authoritative through `QuotationItem::calculateAmount()`, and URL parameters remain Hashids.

## Scope and Evidence

Files inspected or changed:

- `app/Http/Controllers/Purchasing/PriceComparisonController.php`
- `resources/views/purchasing/comparison/inter-supplier.blade.php`
- `resources/views/purchasing/comparison/historical.blade.php`
- `resources/views/purchasing/comparison/vs-best.blade.php`
- `app/Models/QuotationItem.php`
- `app/Models/PrItem.php`
- comparison, Hashid, and supplier price-history tests

Representative local environment: PHP 8.2.30, Laravel 12.66.0, MySQL 8.0.30, 2,067 requisitions, 4,343 PR items, 2,132 quotations, 4,351 quotation items, 2,052 purchase orders, and 2,056 PO/quotation links. Results are local measurements, not production cPanel percentiles.

## Findings and Changes

### Eligible requisitions

Classification: `VERIFIED BOTTLENECK`.

The selector eager-loaded eligible quotation collections only to count them and lazily loaded items once per eligible PR. It now:

- selects only selector fields;
- eager-loads the bounded item projection in one query;
- uses a status-constrained `withCount()` for quotation counts;
- preserves the same option labels, material preview, status universe, and hashes.

Measured representative result for 37 eligible PRs:

| Metric | Before | After |
|---|---:|---:|
| Queries | 40 | 3 |
| SQL time | 22.09 ms | 9.49 ms |
| Response time | 57.73 ms | 38.26 ms |

Query-growth regression coverage confirms the item query remains bounded as eligible PR count increases.

### Monthly history

Classification: `VERIFIED BOTTLENECK`.

The monthly path previously hydrated the complete Eloquent graph and rendered every detail record. For a 2,000-row supplier/material history this caused high memory and response cost. The implementation now separates:

- a lightweight, complete chronological series used by the chart and full-series summary; and
- a 50-row server-paginated Eloquent detail projection used by the supporting table.

The exact series count supplies pagination metadata, so no redundant paginator `COUNT` query is issued. Page overflow clamps to the final page. The full chart and summary are not silently capped.

| High-cardinality metric | Before | After |
|---|---:|---:|
| Full Blade response | 1,195.65 ms | 239.19 ms |
| Full Blade bytes | 5,216,555 | 310,149 |
| Full Blade peak memory | 88 MB | 38 MB |
| Full Blade queries | 11 | 12 |
| Full Blade SQL | 99.45 ms | 103.05 ms |
| AJAX response | 640.35 ms | 171.08 ms warm |
| AJAX bytes | 1,424,238 | 76,440 |
| AJAX peak memory | 70 MB | 38 MB |

The one additional full-page query is a deliberate bounded detail projection; SQL time stayed essentially flat while hydration, serialization, output size, and peak memory fell materially. Page 1 and page 2 produced identical chart SHA-256 values and identical full summaries. The measured 2,000 rows report 40 detail pages. A dimension-filtered 225-row result reports 5 pages.

Stable initial page JavaScript now embeds only chart configuration instead of serializing the already-rendered summary and table a second time. JSON pagination avoids reloading the full supplier selector and full material selector; the requested material is still checked against the selected supplier's PO-backed history.

### Yearly history and material selector

- Yearly aggregation: `NOT CURRENTLY JUSTIFIED`. It remains a compact SQL aggregate, unpaginated, and measured at four queries.
- Material selector: `OPTIMIZATION OPPORTUNITY`. It measured approximately 25–27 ms on the local stress-shaped data. `EXPLAIN ANALYZE` and a direct-join prototype were effectively equal, so no speculative rewrite or index was introduced.

### vs best price

Classification: `VERIFIED BOTTLENECK`, partially remediated.

The original broad-range DataTable executed the complex derived query three times and performed 29 PR plus 29 quotation model lookups to build one 25-row page. Trusted query-result IDs are now encoded directly with Hashids, and the existing summary count is reused for DataTables total/filtered counts when no keyword is present.

| Metric | Before | After |
|---|---:|---:|
| Queries | 61 | 2 |
| Cumulative SQL | 411.26 ms | approximately 278–370 ms |
| Response | 432.78 ms | approximately 293–381 ms |
| Result rows | 4,141 | 4,141 |

The range reflects repeated local runs and cache/host variance. The remaining two complex SQL executions are now the dominant cost. Search requests intentionally retain a third execution so `recordsTotal` remains the unfiltered total while `recordsFiltered` and the summary reflect the keyword.

`EXPLAIN ANALYZE` showed that all-time PO-backed history is materialized twice within each complex query. An exact nested-window prototype produced byte-identical 4,141-row output and reduced a full query from 202.20 ms to 146.55 ms locally. It was not implemented because the target cPanel MySQL/MariaDB version is unverified and the rewrite depends on window-function support. This remains `LIKELY BOTTLENECK — MEASUREMENT REQUIRED` on the target host, not a portable repository change.

The query now excludes current quotations whose requisition is soft-deleted, preventing dead Hashid links exposed by direct encoding. Best-price material identity remains the established `material_name` rule; changing it to include dimensions would be a separate business decision.

## Verification Actually Executed

- `PriceComparisonPerformanceRegressionTest`: 7 tests, 119 assertions, passed.
- Regression coverage includes snapshot-rate stability, authoritative amount calculation, query growth, DataTables totals/search and legacy numeric price fields, canonical Hashid URLs, soft-deleted current requisitions, soft-deleted historical-link suppression, full chart/summary parity across pages, overflow clamping, and yearly behavior.
- `HashidUrlSecurityTest`: 5 tests, 119 assertions passed during the historical implementation check.
- PHP syntax checks: passed.
- `php artisan view:cache`: passed.
- `git diff --check`: passed at the implementation checkpoint.
- Browser automation: not run by instruction.
- Rendered chart, pagination interaction, and responsive visual QA: `MANUAL_VISUAL_QA_REQUIRED`.

## Risks and Deferred Work

- The 2,000-point chart intentionally remains complete; browser render cost requires manual Lighthouse/runtime verification.
- Target cPanel database version and `EXPLAIN`/`EXPLAIN ANALYZE` behavior must be verified before considering the exact window-function rewrite.
- A quotation linked to multiple active POs can still yield multiple best-history projections under the existing data model. No business rule was invented to select one PO.
- Production latency, concurrency, and memory are `NOT MEASURED — ENVIRONMENT REQUIRED`.

## Files Modified or Added

- `app/Http/Controllers/Purchasing/PriceComparisonController.php`
- `resources/views/purchasing/comparison/historical.blade.php`
- `tests/Feature/PriceComparisonPerformanceRegressionTest.php`
