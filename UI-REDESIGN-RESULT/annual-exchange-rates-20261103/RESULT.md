# Annual exchange rates — Historical Purchasing

Implemented on 3 November 2026. Existing unrelated working-tree changes were preserved. No commit or push was performed.

## Delivered behavior

- Admin dashboard and Exchange Rates show a currency-by-year matrix. The management page provides year filtering, year pagination, and a form for USD/JPY/CNY; IDR is fixed at 1.
- A complete annual revision is appended atomically. Stale forms are refused, previous rows cannot be updated/deleted through the model, and concurrent initial saves or corrections produce one complete winning revision.
- Only Historical Purchasing opts into annual conversion, including PR mode, drawer, weighted summaries, and charts. PO dates determine purchase years; quotation submission dates determine current-offer years, with the existing creation-date fallback for legacy quotations. Years use Asia/Jakarta.
- Original prices remain visible. Missing annual rates produce unavailable converted values without snapshot/effective-rate/previous-year fallback. Corrections become visible on the next request.
- Inter-Supplier, vs Best Price, document snapshots, and the default shared-service behavior retain their prior contracts.
- Conversion uses BCMath, with extra precision retained before percentages and extrema are calculated. Presentation remains at four decimal places where appropriate.

## Executed verification

- **102 PHP tests passed, 43,034 assertions.** Coverage includes annual management/authorization/validation, complete revisions, stale forms, rollback after a failure on the last currency, immutable history, two-process MySQL races, corrections, missing rates, business-year boundaries, weighted aggregation, decimal/cross-currency conversion, very small positive prices, existing pricing contracts, query growth, pagination, hash-only access, regional display, translation parity, and BusinessTime rules.
- **23 JavaScript tests passed** across Historical charts, comparison state, and Inter-Supplier charts.
- Scoped Pint passed; PHP syntax checks passed; frontend build passed; Blade cache compiled; route inspection and `git diff --check` passed. The code graph was updated with AST extraction.
- The build emitted two existing calendar `::part` selector optimization warnings; calendar CSS was not changed by this task.

PHP verification used the isolated MySQL database `adasi_annual_rates_test`. After initial schema migration, focused reruns used a temporary bootstrap that first verified the database name and annual migration ledger entry. Normal PHPUnit transaction isolation and the concurrency test's guarded cleanup remained enabled. The application database was not reset or seeded.

## Browser preview

A temporary loopback-only harness used synthetic Admin/Purchasing actors and the isolated test database; it did not exercise real login/MFA. The harness and its browser tabs were closed after verification.

- Desktop matrix visually inspected with 2024/2025/2026 values, including JPY 105.2 and fixed IDR 1.
- Historical table and chart showed USD 10/kg as IDR 172,000 versus 180,000: difference 8,000 and increase 4.65%.
- A correction submitted through the Admin form created a new revision. A subsequent browser request returned IDR 185,000 and 7.5581% for the corrected comparison.
- Keyboard Tab moved between currency inputs; chart arrow-key navigation announced the plotted values. New inputs had associated labels and visible focus.
- At 375 px, the form had no document-level horizontal overflow and all inputs fit. Dark-mode surface/text colors were checked in the DOM. The dark screenshot capture timed out, so complete dark visual sign-off is not claimed.
- No committed visual baseline was available; visual regression clearance and a full screen-reader audit remain unverified. A transient DataTables CDN load error cleared on reload.

## Local schema and remaining rollout

Read-only inspection found `2026_11_03_000001_create_annual_exchange_rates_table` already recorded in the local `adasi_portal` migration ledger, with the expected columns and unique index present. The annual-rate table contained zero rows at inspection. This session did not apply that migration to the application database or populate it with example values.

Admin must enter the required years' rates before foreign-currency Historical values can be converted. The migration performs no automatic backfill. MariaDB, staging/production deployment, and the complete application test suite were not executed.
