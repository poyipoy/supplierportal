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

## 4. Stage 2: PR and Excel (PUR-01, PUR-02, GEN-02)

| ID | Change |
|---|---|
| PUR-01 | PR import preview (`purchasing/pr/_import`) shows Row, Material, HS Code, Shape, Qty, Thickness, Width, Outer D., Inner D., Length, KG/Unit, Total KG, Remark. Values rendered with `.text()`; a dimension the shape does not use is `null` from the server and shows `-`. `PrItemsImport::HEADINGS` unchanged |
| PUR-02 | Server: `PrItemsImport::preview()` adds per-row `total_weight = Money::multiply(weight_needed, quantity, 4)` and `summary.total_quantity` / `summary.total_weight_kg` (BCMath). Client: new `resources/js/pr-totals-core.js` (scaled BigInt, same 12 dp working scale and half-up as `Money::multiply`), imported statically in `app.js` and exposed as `window.AdasiPrTotals`; per-row Total KG cell in `_item_row`; footer colspans re-aligned; blank or below-1 quantity shows `—` and contributes 0 (never `max(1)`); `.val()` writers, add/remove and import-apply dispatch `adasi:pr-items-changed`. The `PrItem::total_weight` accessor is unchanged |
| GEN-02 | `QuotationImportTemplateExport::CANONICAL_HEADINGS` = form order (`pr_item_id, material_name, requested_dimension, available_qty, available_thickness, available_width, available_d_outer, available_d_inner, available_length, offered_weight_per_unit, price_per_kg, notes, availability`); the sample row follows it. The import stays heading-based, so old-order files still import. Supplier import preview order: PR Item ID, Material (looked up from this form's PR rows by `pr_item_id`, never from the file), Offer Qty, dimensions, Offer KG/Unit, Price/kg, Notes, Availability. Template-download audit: all four Excel imports (PR, quotation, Local PO, Local GR) already offer Download Template; no change |

**Tests:** `MissionFiveImportTest::test_pr_preview_reports_row_total_weight_and_grand_totals` (independent bcmul oracle,
no DB writes), `test_pr_form_and_import_preview_show_dimensions_and_total_kg_columns` (rendered headers; footer colspan
sum equals header count on both tables), `test_quotation_template_round_trips_through_import_preview_in_canonical_order`
(download → fill in its own column order incl. a length range → `import-preview` 200 with field-equal values, no DB
writes), `test_quotation_form_preview_and_template_share_the_canonical_order`; `MoneyTest` and
`tests/js/pr-totals-core.test.mjs` share `tests/Fixtures/pr-totals-cases.json` (PHP/JS parity, half-up edges,
20-digit exactness).
**Sanctioned expectation change (2):** `MissionFiveImportTest:118-123` asserted the old 13-key template order; it now
asserts the canonical order (same key set), plus that the sample row's `availability` is `Available`. Kept:
`MissionFiveImportTest:427` (import rows carry no `material_name`).

**Browser (Chrome DevTools, isolated server on `adasi_rev1027_browser_test`):** PR create form: `3 × 12.5` → row
`37.5`, totals `3` / `37.5`; programmatic `.val('1234.56785')` + notify → `3,703.7036`; blank quantity → `—`, totals
`0`; `addRow()` keeps totals correct; import preview rendered with a server-shaped payload shows the five dimension
columns, `-` for unused ones, KG/Unit, Total KG and the grand total footer; HTML in a remark is rendered as text. No
console errors. A first run found `window.AdasiPrTotals` undefined because the asset build was stale; rebuilt and
re-verified.

## 5. Stage 3: Price Comparison (PUR-03, PUR-07 + Vs Best, PUR-05, PUR-04)

| ID | Change |
|---|---|
| PUR-03 | Company name (login name only when the company name is blank) on all comparison surfaces: inter-supplier header and chart labels, indicator strip, historical supplier select, chart title and table (`PurchaseHistoryPriceService::lines()`), Vs Best current/best cells and keyword search (`COALESCE(NULLIF(TRIM(company_name),''), name)`). PIC removed from the comparison header only. Historical suppliers cache key `v1` → `v2` (eager `supplier:id,user_id,company_name`) |
| Vs Best (§3-C) | The "best" ranking now runs on `PurchaseHistoryPriceService::eligibleLines()`: no cancelled/soft-deleted PO or quotation, award line or legacy PO without awards, frozen `PRICE`, KG > 0, partitioned by (material, shape). The winning PO and frozen price are carried from the ranked line, so the best PO/date/price come from one line and a quotation linked to several POs no longer duplicates rows. Summary cache key `v2` → `v3` |
| PUR-07 (§3-G) | One grouped query returns each (material, shape) with eligible history and its distinct key-dimension values (`COALESCE(qi.available_*, pi.*)` per shape, 4 dp, ≤ 100). Labels "Name (Shape)" when a name has several shapes; hidden `shape` input; `historical()` resolves (name, shape) with name fallback; a null-shape material is its own choice (`shape_is_null`, opt-in filter). Legend: Flat → Thickness, Round → **Diameter** (new key), Hollow → Outer diameter; no shape → fieldset hidden. A name-less "Recorded values" select writes `key_min = key_max`; typing a range shows "Custom range"; material change clears the bounds; supplier change keeps them only when the (material, shape) and exact value are still offered |
| PUR-05 | `syncComparisonState()` keeps the URL, the history entry (with `_serverTabs`, so Back/Forward still swaps tabs), the three tab links and the currency form's hidden inputs in step for `material_name`, `shape`, `currency`. Historical material list and payload use a latest-request-wins guard (AbortController + sequence id). Inter-supplier options carry `data-material-name`/`data-shape` and preselect from the URL. Vs Best gains a material select (options = distinct current (material, shape), one query), filtering through AJAX; DataTables discards stale draws itself. An unknown requested material is ignored with a `role=status` note |
| PUR-04 | Inter-supplier: `col-sticky-material` (inline `z-index: 5` beat the header's `z-index: 3`, so the column painted over the header) → shared `ui-sticky-col` + width class; a `ResizeObserver` sets `--ui-table-sticky-head-row` from the first header row. Historical: `sticky` data table, first column `ui-sticky-col` in Blade and in the JS re-render. Vs Best: DataTables `dom` puts only the table in a `ui-table-sticky` scroller (search/paging outside; region role/label set on init); first column `ui-sticky-col`; one `app.css` rule restores `position: sticky` on DataTables sortable headers, which DataTables sets to `relative` |
| PUR-06 residue | Vs Best original-currency prices and non-IDR amounts (`formatNumber` / `$formatRupiah`) were ungrouped under `system`; now `groupedDecimal(…, 'indonesian')`, like the module's other amounts |

**Tests:** `PriceComparisonCompanyNameTest` (3), `PriceComparisonVsBestEligibilityTest` (2; oracle = `PurchaseHistoryPriceService::summarize` best; **fails on the pre-change controller** with Flat best 3.0 instead of the frozen 6.5), `PriceComparisonHistoricalDimensionOptionsTest` (2; the §3-G fixture: S1 Flat [10, 12], Round [50, 52], Hollow [60], null shape []; all suppliers Flat [10, 12, 14]; 99 and 77 never; one history query), `tests/js/comparison-state.test.mjs` (latest-request-wins; `_serverTabs` preserved; tab hrefs and currency form follow the shared keys).
**Sanctioned expectation changes:** (3) `QuotationPresentationTest::test_comparison_header_shows_pic_and_the_q8_label` inverted to `…_shows_company_name_without_pic…` (PUR-03 supersedes Decision 10 on this surface only); it also asserts the PIC still shows on the quotation detail. (4) `PriceComparisonServerTabsTest` cache key `v2` → `v3` (§3-C mandated bump; same assertions).

**Browser (Chrome DevTools, isolated server):** §3-G acceptance 1–7 all observed: (1) S1 + SCM440 (Flat): legend Thickness, options 10/12, table both lines with company names; (2) choose 12: one request, URL `key_min=12.0000&key_max=12.0000`, table and chart only thickness 12; (3) SCM440 (Round): legend Diameter, options 50/52, bounds and URL keys cleared, no reload; (4) Flat→Round→Flat quickly: 3 requests, older aborted, final DOM/URL = Flat; (5) tab to Vs Best (link carried `material_name=SCM440&shape=Flat`, Vs Best preselected it) and Back restored the historical state; (6) null-shape material: fieldset hidden; (7) switching to a supplier without that material clears bounds and material. A defect found in step 7 (URL kept the old supplier/bounds when nothing reloads) was fixed and re-verified. PUR-04 measured with bounding rects and `elementFromPoint`, light and dark: inter-supplier header row 1 at top 0 and row 2 at exactly row 1's height (94 px), Material column at left 0, header above the frozen body column; Vs Best the same, also after a DataTables redraw (sort); historical sticky after an AJAX re-render (yearly). No console errors.

**Performance (§D):** comparison probe before → after (1 / 5 / 25 items): inter-supplier 19/23/43 → 19/23/43 (company names add 0 queries); historical page 7/5/5 → 8/5/5 (+1 only on the cold `v2` supplier-cache build); Vs Best page 1 → 2 (material options, constant); Vs Best data 4 → 4 for 1 and 25 rows; historical materials 2 → 2 (options folded into the same query). `EXPLAIN` (MySQL 8.0.30, empty test schema, plan shape only): the history subquery reaches `po_quotations`, `pr_item_awards` and the PKs by index. A production-size plan and MariaDB 11.4 are NOT RUN (CI deep job / staging).

## 8. Verification log

| Check | Result |
|---|---|
| Stage 0 full PHP baseline | PASSED with 1 pre-existing failure (copy ledger) |
| Stage 0 JS baseline | PASSED 549/549 |
