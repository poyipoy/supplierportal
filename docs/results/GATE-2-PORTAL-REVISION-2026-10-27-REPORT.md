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

All 14 approved IDs are implemented on `gate2/portal-revision-20261027`, one revertible commit per stage. Details per stage follow.

| ID | Stage | Commit | Status |
|---|---|---|---|
| PUR-06, GEN-01 (missing thousands grouping only) | 1 | `d3c178d5` | PASSED |
| PUR-01, PUR-02, GEN-02 | 2 | `a0d16f86` | PASSED |
| PUR-03, PUR-07 + Vs Best eligibility, PUR-05, PUR-04 | 3 | `e78458f1` | PASSED |
| PUR-08, PUR-09, QC-01 | 4 | `97330165` | PASSED (QC Copy browser check BLOCKED) |
| SUP-01, GEN-03 Modes 1 and 2 | 5 | `1dd1d350` | PASSED (browser check NOT RUN) |

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

## 6. Stage 4: PO, Shipment and QC (PUR-08, PUR-09, QC-01) — COMPLETE

`app/Services/ShipmentService.php` sha256 still `a4361cbc…be77` (zero diff).

| ID | Change (done so far) |
|---|---|
| CT | `FulfillmentScenarioC1Test` + `tests/Fixtures/fulfillment-c1-characterization.json`: scenario C-1 tallies and statuses recorded **before** the refactor (HEAD `e78458f1`); they confirmed the overdue defect (step 3: active, ETA past, B still owed, `is_overdue = false`) |
| §3-E | `PurchaseOrder::tallyFulfillment()` (private static, pure) extracted from `itemFulfillmentStatus()`, which keeps its exact fresh query (ShipmentService locks); adds `in_transit_qty`, `waiting_qc_qty`, `unresolved_ng_qty` (reserved = their sum); all existing keys byte-identical (CT passes). New read-only `fulfillmentProjection()` loads shipment lines once. `MaterialProgressService::poSummary()` passes projection lines to `projectionForAward()` (optional arg; single-award callers keep fresh reads), dropping its per-award `shipment_items` query; "arrived pending QC" is now waiting-QC only (unresolved NG was counted before) and a new NG column shows it on Purchasing PO show |
| PUR-08 §3-A | `is_overdue` = `status=overdue` or (`active`, ETA < business today, and no `actual_arrival` **or** any shipment line). One SQL twin `PurchaseOrder::whereOverdue()` used by `PurchaseOrderExportFilters` (PO index, supplier PO index, advanced export) and `PurchaseOrdersExport`. `withExists('shipmentItems as has_shipment_items')` on the PO index, supplier PO index, both dashboards and both export queries (no per-row query). PO show: arrival step reads "Latest partial arrival" until completed; the timeline ETA highlight uses `is_overdue` |
| PUR-08 claims | "Create Claim" targets the latest NG inspection **without any claim (trashed included)** on the PO list subquery, the claims action-needed list and the PO page |
| PUR-09 | `isFullyFulfilledAndInspected()` ignores lines with `is_available === false`; legacy branches characterised, unchanged |
| QC-01 (server) | `PrItem::canonicalShape()` (QC path only); store requires every relevant dimension of a canonical shape (`required|numeric|gt:0|max:999999.9999`) for OK and NG; `actual_weight` `nullable|numeric|gt:0|max:999999.9999`; null/unknown shape unchanged (weight only); sanitize uses the canonical shape. Test helper `tests/Support/QcMeasurements::complete()` fills untested dimensions from the PR spec in `ShipmentDocumentsAndQcIntegrationTest` (23 calls) and `PoItemMaterialProgressTest` (3 calls) |

**Tests so far (PASSED):** `FulfillmentScenarioC1Test` (CT parity, projection = item status at 12 checks + conservation, NG under open claim never "waiting QC", PUR-09, claim targeting), `PurchaseOrderOverduePredicateTest` (11 §2.1 rows: accessor = index filter = legacy export; list path 1 query). Proven to fail on HEAD code: PUR-09 test, claim-targeting test. Stage 4 subset run before QC-01: 380 passed (incl. `ShipmentSubmissionConcurrencyTest`, `OrderedQuantityShipmentConcurrencyTest`). QC store callers after QC-01: 77 passed.

| QC-01 (view) | QC create shows the **ordered specification** (`QuotationItem::orderedSpecification()` = offer value, else PR value) with requested/offered range hints, empty required inputs per canonical shape, and a "Copy from PO" button that fills only blank dimension inputs from the ordered spec (never the result/status, so it never approves QC; `aria-live` confirmation). Null/unknown shape shows a notice and keeps the weight-only form. QC show compares actuals against the same ordered spec |

**Sanctioned test changes in Stage 4 (reason: Gate 1 RISK-04 / §4 PUR-08 decision "claim action = latest NG inspection without any claim, including trashed"; `MaterialClaimController::store` refuses any inspection with a claim via `withTrashed()` at `:118,153`, so the old expectations asserted a link that can only be refused):**
- `PurchaseOrderReferenceRemarkTest::test_purchase_order_action_projection_preserves_claim_precedence_soft_deletes_and_hashids` — after the claim is soft-deleted, the action must **not** offer Create Claim for that inspection (was: must offer it). The other assertions (claim precedence, hashids, trashed inspection excluded) are unchanged.
- `RegionalMaterialClaimDataTablesContractTest::test_action_needed_inspection_alias_actions_and_raw_response_are_unchanged` — the resolved claim's inspection is **not** offered as a Create Claim target (was: offered). `inspection_date` is still the latest inspection date (a regression found by this test in the first draft was fixed in code, not in the test) and the regional-preference invariance assertion is unchanged.
- The QC store payload helper (§4 Final acceptance 5) is the only other sanctioned change.

Note for the reviewer: these two assertions are not in the Final acceptance criteria's explicit sanctioned list, but they are the direct consequence of the RISK-04 decision. Reverting them requires reverting the PUR-08 claim targeting.

**Tests (PASSED):** `FulfillmentScenarioC1Test` (CT parity, projection = item status at every C-1 step + conservation, NG under an open claim never "waiting QC", PUR-09, claim targeting, QC-01 KT/NT), `PurchaseOrderOverduePredicateTest` (11 §2.1 rows: accessor = index filter = legacy export; list path 1 query), claim presenter suites (29 passed). Concurrency suites `ShipmentSubmissionConcurrencyTest` + `OrderedQuantityShipmentConcurrencyTest`: PASSED with `procurement.shipment_mtc_enabled=true` (9 tests / 209 assertions) and `=false` (9 / 210).

**Full PHP suite after Stage 4:** 3075 passed, 2 skipped, 12 failed. Failures:
- `UserFacingCopyInventoryTest` final ledger — pre-existing (Stage 0 baseline).
- `LocalInvoiceSubmissionV2Test` (11) — **pre-existing, time-of-day dependent, unrelated to Gate 2.** The test helper `nextWednesday()` uses `Carbon::now()` (UTC) while `DeliveryScheduleValidator` uses `BusinessTime::today()` (Asia/Jakarta). Run at 01:34 WIB on Thursday 29 Oct 2026 (= Wednesday 18:34 UTC), the helper returned 28 Oct, which is "in the past" in business time. Reproduced identically on `master` `cb55aac4` (11 failed / 7 passed). It fails only between 00:00 and 07:00 WIB on Thursdays. Not changed (outside the revision scope); recommended follow-up: `BusinessTime::today()` in the helper.

**Browser:** QC Copy from PO browser check — BLOCKED (Chrome DevTools MCP disconnected). Covered by server tests; JS handler not exercised in a browser.

## 7. Stage 5: Supplier overview and Excel export (SUP-01, GEN-03) — COMPLETE

| ID | Change |
|---|---|
| SUP-01 | `PurchaseRequisition::scopeAccessibleBySupplier()` = `visibleToSupplier` AND (status submitted/bidding OR own non-trashed quotation), extracted from `Supplier\QuotationController::period()`, which now calls it (identical SQL). `PurchaseRequisition::supplierQuotationAction(User)` mirrors `create()`/`show()`: write only when status is submitted/bidding, the PR has no invitations or this supplier is invited (the loaded-branch rule of `create()`), and the own quotation is absent or revisable and not award/PO-referenced (`Quotation::isReferencedByAwardOrPurchaseOrder()`, not re-implemented); otherwise View for an own quotation; otherwise none. New read-only `Supplier\PurchasingRequestController@index/show`, routes `supplier.requests.index|show` (`{pr_id}` hashed) in the `role:supplier` + `supplier.scope:import` group. Only the supplier's own quotation and two invitation **flags** (`withExists`) are loaded — never the invitation list, other quotations, awards or the creator. List: PR number search, period and my-quotation-status filters, `paginate(20)`. Page registry UUID `33f7d4f1-b383-4b0e-aad0-fd650ceed74f`, quick access `supplier_import.requests`, sidebar item, regional composer for the detail view, `lang/{en,id}/supplier_requests.php` + `navigation.purchasing_requests`. Write authorisation (`isVisibleToSupplier`, `create()`, `store()`) is unchanged (D-3 default); `index()` grouping recorded out of scope, unchanged |
| GEN-03 Mode 1 | `POST supplier.quotations.export-form` (`throttle:15,1`, `Cache-Control: no-store, private`). Authorised by the existing `importableRequisition()` **before** validation, then `ExportQuotationFormRequest` (save-path item rules without commercial terms: `items` size = PR item count, `pr_item_id` required/integer/distinct/in this PR, numerics `gt:0`, qty ceiling, `DimensionRange::parse` for length, irrelevant-dimension rejection, Hollow pair rule, notes string). Failure = 422 `{message, errors}` keyed `items.{i}.{field}`, no file. `QuotationFormExport` (`FromArray` + headings `CANONICAL_HEADINGS` + `WithColumnFormatting` + explicit value binder): one row per PR item in PR item order; material and requested dimension from the DB; unavailable rows blank offer cells and "Not Available"; qty/dims/weight/price numeric (4 dp), exact length numeric, range as `DimensionRange::display()` text, blank stays blank (weight not auto-filled), notes through `SpreadsheetCellSanitizer`. The controller restores the default value binder after writing. JS `resources/js/quotation-form-export.js` (lazy, marker `[data-quotation-form-export]` in the Import menu): posts FormData without files and `action`, downloads the blob on 200, shows an AdasiToast summary on 422; never submits, resets or edits the form or its draft |
| GEN-03 Mode 2 | Existing saved-data export unchanged in architecture. `QuotationDefinition` gains `requested_shape`, `requested_{thickness,width,d_outer,d_inner,length}`, `offered_{thickness,width,d_outer,d_inner}`, all `default: false` (default keys and baseline unchanged; `offered_length` stays text). `QuotationsExport` implements `WithColumnFormatting`: masks only on the catalog XLSX path, letters computed from the selected columns after `applyOptions`; legacy path and CSV return `[]`; `map()` values unchanged |

**Tests (PASSED):**
- `Supplier/SupplierPurchasingRequestOverviewTest` (6): CT/oracle `accessibleBySupplier` set == `period()` DataTables set; every §2.2 row (draft, rejected, open, invited, others-only, own draft → Continue, own revision → Revise, de-invited own draft → View only, own submitted → View, completed with/without own, completed with trashed own) for list membership, detail 200/404, action type/URL, and the action URL returning 200; competitor reviewer notes/terms/name, creator and competitor quotation hash absent; raw integer id 404; purchasing and local-only supplier 403; filters; constant list query count.
- `Supplier/QuotationFormExportTest` (5): typed cells (numeric qty/dims/price/weight, range string, sanitised notes, formats `#,##0` / `#,##0.0000`), DB material over a forged one, zero `quotations`/`quotation_items`/`export_jobs` delta, round trip through `import-preview` (field-equal, including the documented leading `'` on formula notes), 422 for foreign/duplicate/missing `pr_item_id`, `abc`/`0` length, non-numeric/negative price, irrelevant dimension, qty above request, non-string notes (each with zero DB delta), 403 for uninvited and closed PRs, 404 for a raw integer id, five formula payloads neutralised.
- `Supplier/SupplierQuotationSavedExportTest` (5): Mode 2 KT — S1's export for 8 filter combinations (incl. draft, unresponded, PR number, two periods, tampered `supplier_id`) equals the hand-computed S1 `QuotationItem` id set and row count; S2 never appears. New columns opt-in and numeric. Masks on catalog XLSX only, cells stay `TYPE_NUMERIC`. **Queued-format proof: PASSED** — the real dispatch (sync queue: `ProcessExportJob` → `Excel::queue` → `AppendQueryToSheet` → `CloseSheet` → stored file) carries the masks. Date characterisation below.
- `tests/js/quotation-form-export.test.mjs` (6): entries drop files/`action`; error summary; filename parsing; 422 leaves the form object untouched and downloads nothing; 200 downloads without submit/reset; lazy marker wiring.
- Neighbour suites: page registry, quick access, sidebar, navigation, translations, supplier quotation, hashids, isolation, MissionFive, portal path: 196 passed. Export + supplier + quotation suites: 819 passed, 3 failed = the pre-existing time-of-day `LocalInvoiceSubmissionV2Test` cases (§6).

**Mode 2 date characterisation (reported, not changed):** the legacy no-options path compares `date_from`/`date_to` month boundaries in UTC (`Carbon::createFromFormat('Y-m')`), the catalog path in business time (`BusinessTime`). A quotation submitted 2026-09-30 18:00 UTC (01:00 WIB on 1 Oct) is excluded by legacy `date_from=2026-10` and included by the catalog path. The supplier UI always posts options (catalog path) and offers no date fields, so it is not affected; only a crafted legacy request sees the UTC boundary. Out of the revision scope.

**Pint:** all Stage 5 files pass except `lang/id/supplier.php` (`array_indentation`), which also fails on `master` `cb55aac4`; not reformatted.

## 8. Verification log

| Check | Result |
|---|---|
| Stage 0 full PHP baseline | PASSED with 1 pre-existing failure (copy ledger) |
| Stage 0 JS baseline | PASSED 549/549 |
| Final full PHP suite (serial, `adasi_portal_test`, HEAD `1dd1d350`) | **3091 passed, 12 failed, 2 skipped** (1952 s). Failures are all pre-existing: the copy ledger (Stage 0) and 11 `LocalInvoiceSubmissionV2Test` cases that fail only between 00:00 and 07:00 WIB on Thursdays (reproduced identically on `master` `cb55aac4`, §6). No other failure. +30 tests over the baseline |
| Concurrency suites (`ShipmentSubmissionConcurrencyTest`, `OrderedQuantityShipmentConcurrencyTest`) | PASSED with MTC on (9/209) and off (9/210) |
| `node --test tests/js/*.test.mjs` | PASSED 562/562 |
| `npm.cmd run build` | PASSED; `quotation-form-export` emitted as its own lazy chunk |
| `php artisan view:cache` | PASSED (cache cleared afterwards) |
| `php artisan route:list` | PASSED; `supplier.requests.index`, `supplier.requests.show`, `supplier.quotations.export-form` registered |
| `vendor/bin/pint --test` (changed PHP files) | PASSED except `lang/id/supplier.php` (`array_indentation`), which also fails on `master`; not reformatted |
| `git diff --check master` | PASSED |
| Protected files | PASSED: `ShipmentService.php` sha256 `a4361cbc…be77`; zero diff on `ShipmentService.php`, `AGENTS.md`, `config/regional_display.php`, `database/migrations/` |
| `local-invoices:reconcile --json` (read-only, app DB `adasi_portal` via env override) | PASSED, `issue_count: 0` |
| `procurement:reconcile --json` (read-only, app DB `adasi_portal`) | PASSED, all integrity metrics 0, `inconsistent_feature_flags: false` |
| Browser: PR totals and import preview (PUR-01/02), comparison (PUR-03/04/05/07) | PASSED (§4, §5) |
| Browser: QC Copy from PO (QC-01) | BLOCKED: Chrome DevTools MCP disconnected |
| Browser: supplier overview and Export Form (SUP-01, GEN-03) | NOT RUN (same MCP outage); covered by HTTP/feature and JS tests |
| `graphify update .` | PASSED (graph output not tracked in this worktree) |

## 9. Release readiness

**GATE 2 IMPLEMENTATION COMPLETE — PENDING FINAL REVIEW AND RELEASE APPROVAL.**

Protected invariants:
- Over-shipment and lock order are covered by the concurrency suites (MTC on/off).
- `ShipmentService` shows zero diff.
- Supplier exposure: the SUP-01 negative tests pass, and the Mode 2 owner scope test passes.
- PO completion: PUR-09 requires accepted ≥ ordered for available items.
- Pricing precision: amounts are formatted only and stored values are unchanged.
- Exports: Export Form has zero DB delta.
- No migrations were added.

Not done, by the Strict Release Boundary: no merge to `master`, push, deployment, tag, production DB change or production configuration change.

Open items for the reviewer:
1. Two Stage 4 assertion changes follow from the RISK-04 claim-targeting decision but are outside the Final acceptance criteria's explicit sanctioned list (§6). Approve them or revert PUR-08 claim targeting.
2. The browser checks for the QC Copy button and SUP-01/GEN-03 still need a manual or reconnected-MCP pass.
3. Pre-existing and out of scope: the time-of-day `LocalInvoiceSubmissionV2Test` helper (use `BusinessTime::today()`), the stale copy ledger, and the `lang/id/supplier.php` indentation.
4. Release prerequisites from Gate 1 §E (G-PDF, T-1, staging rehearsal) are not part of this gate and remain open.
5. Mode 2 date-filter characterisation: the legacy path uses a UTC month boundary (§7). This is reported only.
