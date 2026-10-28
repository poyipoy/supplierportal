# Gate 2 Report: Portal Revision 2026-10-27

Integrated implementation report for the 14 approved revisions in
[GATE-1-PORTAL-REVISION-2026-10-27.md](../plans/GATE-1-PORTAL-REVISION-2026-10-27.md).
Status labels: **PASSED**, **FAILED**, **BLOCKED**, **NOT RUN**.

## 1. Baseline (Stage 0)

| Item | Result |
|---|---|
| Repository HEAD | `cb55aac42355460e57a36b06732a459a167b7126` = `origin/master` after `git fetch` (2026-10-28) |
| Main working tree | Clean; left untouched |
| Isolated branch / worktree | `gate2/portal-revision-20261027` at `C:\laragon\www\adasi_rev1027`, created from the HEAD above |
| `ShipmentService.php` sha256 | `a4361cbcf53ee6fb4a1c20e081eb87a8c449adda15d1c760ec0fa6d98912be77` (matches the plan's `a4361cbc…be77`) |
| PhpSpreadsheet | `1.30.7` (`composer.lock`); `composer install` from the lock succeeded |
| PHP / MySQL | PHP 8.2.30, MySQL 8.0.30 (Laragon) |
| Test database | `adasi_portal_test` (see note below). The `AppServiceProvider` guard requires a `_test`/`_testing` suffix; the worktree `.env` and `.env.testing` both point at the test DB, so no artisan command in the worktree can reach the app DB `adasi_portal` |
| JS baseline | `node --test tests/js/*.test.mjs`: **549 / 549 PASSED** |
| PHP baseline (full suite, clean worktree) | **3061 passed, 1 failed, 2 skipped** (2088 s). The one failure is `UserFacingCopyInventoryTest::final semantic ledger…`, the known stale copy ledger (pre-existing, unrelated) |

**Test database note.** The plan names `adasi_rev1027_test`, with the owner's fallback `adasi_portal_testing`.
`adasi_rev1027_test` does not exist. A first full run on `adasi_portal_testing` produced 106 failures, of which
103 were the suites' own hard-coded guard `assertSame('adasi_portal_test', DATABASE())` (concurrency suites,
`AdvancedExportBaselineTest`, preference migrations, and more). Those suites abort before running, which would hide
exactly the concurrency evidence Gate 2 requires. The run therefore uses the repository's dedicated test database
`adasi_portal_test`. It is a test-only schema (never production or staging). No other test process was running when
each suite started (checked with `tasklist`).

## 2. Implementation per revision ID

(Filled per stage below.)

## 3. Stage 1: Price grouping (PUR-06, GEN-01)

### Root cause [verified]
- The default `number_format` preference is `system` (`config/user_preferences.php`).
- `RegionalDisplayFormatter::number()` returns `system` text byte for byte (locked by
  `test_system_preserves_original_numeric_text_byte_for_byte`).
- Import-core price cells render `number(NumberFormat::maxDecimals($x), 'decimal')`; `maxDecimals` emits no grouping,
  so default users saw `648193716.98`. Explicit `indonesian`/`international` preferences already grouped.

### Fix
- New `NumberFormat::grouped($value, $decimals)`: the exact `maxDecimals` precision (trailing zeros trimmed, half-up),
  with `,` grouping. Plain decimal strings and integers use BCMath (no float round-trip); floats fall back to
  `maxDecimals`.
- New `RegionalDisplayFormatter::groupedDecimal($value, $decimals, $profile = 'international')`: `grouped()` in the
  surface's native profile, then the existing `number()` transcoding. Under `system` the surface keeps its native
  separators; explicit preferences transcode as before. `number()`, `money()` and `config/regional_display.php`
  are unchanged.

### Triage (A correct · B missing grouping · C wrong separator for the effective preference · D out of scope)

| Class | Surface (field) | Change |
|---|---|---|
| B | `purchasing/po/show`, `supplier/po/show`: unit price (4 dp), line amount, line IDR, grand total, grand IDR | `groupedDecimal()`; `Rp ` prefix and spacing kept |
| B | `quotations/_items_readonly_table` (Purchasing + Supplier quotation show): price/kg, requested amount, offer amount, IDR, reference price and delta, totals | new `$fmtPrice`; weights/dimensions keep `$fmt` |
| B | `purchasing/quotations/show` Generate PO modal: price/kg, amount, totals | `NumberFormat::grouped()` (fixed separators, because `RegionalProcurementDetailsTest` locks the modal HTML as identical across preferences) |
| B | `purchasing/po/consolidate-awards`: line amount | `groupedDecimal()`; view registered in the existing detail composer |
| B | `purchasing/comparison/_inter_supplier_content`, `_historical_content`: price/kg, price IDR, offer amount, weighted average, min/max, totals | `$formatPrice` = `groupedDecimal(…, 'indonesian')`, matching the module's native presentation (controller `formatRupiah` and the JS re-render both use Indonesian grouping under `system`), so the first render and AJAX re-renders agree |
| C | `purchasing/comparison/_scripts` `formatAnalyticMoney` | `displayNumber()` received `"Rp 1.234"`; its anchored regex never matched, so explicit `international` users saw Indonesian separators. Now transcodes the numeric segment only; prefix and spacing unchanged |
| C | Local-core Blade amounts hard-coded `number_format($x, d, ',', '.')`: `finance/{dashboard (fallbacks), drp/{_paid_table_content,ga,paid,show,supplier}, local-procurement/{form,index}, overpayments/index, vouchers/show}`, `local-invoices/detail` (payment amounts), `local-supplier/purchase-orders/index`, `purchasing/drp/{_paid_table_content,ga,paid,show,supplier}` | `$regionalFormatter->money($x, d)`: byte-identical under `system` and `indonesian` (Indonesian is `money()`'s native profile), follows `international`. Composer entries added for `finance.drp.paid`, `finance.local-procurement.form`, `purchasing.drp.paid` |
| A | Formatter-routed surfaces already grouping (dashboards, invoice detail/table, GA claims, `finance/drp/ga` claim amount, Vs Best DataTables cells, controller `formatRupiah`) | none |
| D | Exchange rates (`rate_to_idr`), weights, quantities, GR qty snapshots, percentages, counts, PDFs/print/receipts, Excel/CSV cells, `data-price`/`data-offer-amount` attributes, form inputs | none |
| D | `purchasing/po/create.blade.php` | **not rendered by any controller** (PO creation goes through award consolidation); left untouched |

Residual C (not changed, recorded): JS-only Local previews that call `toLocaleString('id-ID')` directly
(`local-invoices/form` `rupiah()`, `components/ui/multi-select`, `finance/drp/paid` Alpine `x-text` overpayment
strings, `finance/local-procurement/form` sidebar preview). They are grouped (Indonesian) but do not follow an
`international` preference. They are input-adjacent previews and outside the grouping defect; changing them is a
regional-preference extension, not a grouping fix.

### Tests
- `tests/Unit/RegionalDisplayFormatterTest::test_grouped_decimal_adds_missing_thousands_grouping_and_keeps_display_precision`
  (failing before, passing after): golden cases `648193716.98` → `648,193,716.98` / `648.193.716,98`; 0/2/4 decimals;
  trimmed precision equal to `maxDecimals`; negative, zero, `-0.001`, integer, float; 20-digit exact; invalid → `-`.
- `tests/Feature/RegionalProcurementDetailsTest::test_detail_price_and_amount_cells_carry_thousands_grouping`
  (4 surfaces × system/indonesian/international; `Rp ` prefix kept; no ungrouped amount remains).
- `tests/Feature/PriceGroupingLocalSurfacesTest` (Local PO amounts: system and indonesian unchanged, international
  follows; supplier isolation).
- `tests/js/regional-comparison.test.mjs` `formatAnalyticMoney` test (system/indonesian/international, prefix kept).
- **Sanctioned expectation change (1):** `RegionalProcurementDetailsTest:67` asserted the ungrouped system unit price
  `1234.5678`, the exact defect the user reported. It now asserts `1,234.5678` (same four decimals), paired with the
  new grouping test.

(Stage 1 verification results are recorded in section 8.)

## 8. Verification log

| Check | Result |
|---|---|
| Stage 0 full PHP baseline | PASSED with 1 pre-existing failure (copy ledger) |
| Stage 0 JS baseline | PASSED 549/549 |
