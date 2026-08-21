# Procurement Revision Result

Date: 2026-08-20
Repository: C:\laragon\www\adasi_portal_supplier

## 1. Overall status

PASS for repository-verifiable implementation and regression checks. Manual authenticated browser visual QA remains MANUAL_VISUAL_QA_REQUIRED; no screenshot or rendered responsive evidence is claimed.

All seven revision packages were completed:

- REV-01: unresolved HS code can be submitted when the remaining material data is valid.
- REV-02: PR dimensions use separate shape-aware columns.
- REV-03: quotation amount is calculated server-side and legacy zero rows have an explicit repair path.
- REV-04: PR Total KG is available in the list, detail, and exports.
- REV-05: new procurement periods are annual and history is PO-backed.
- REV-06: bidding rows show a distinct submitted-supplier count.
- REV-07: regression cleanup and final verification passed.

## 2. Branch and starting commit

- Starting commit: 140b3b8 (UI updates)
- Current branch at recovery: master. The instruction requested a non-master branch, but recovery found master active; no branch switch or remote operation was performed.
- Local checkpoints: bded026 (Implement procurement revision packages) and d69e4bf (Update import UI regression assertions)
- Worktree is clean after the checkpoints.

## 3. Files changed

The complete manifest is the diff from 140b3b8 through d69e4bf. Runtime changes are grouped below:

- Amount/lifecycle: app/Models/QuotationItem.php, app/Http/Controllers/Supplier/QuotationController.php, app/Console/Commands/RepairQuotationAmounts.php, quotation/PO controllers, views, exports, and conversation totals.
- PR material workflow: app/Services/Materials/PrItemProcessor.php, PR item partials/scripts/styles, PR create/edit/list/detail views.
- Annual periods/history: app/Models/Period.php, app/Http/Controllers/Purchasing/PeriodController.php, PurchaseRequisitionController.php, ReportController.php, supplier quotation/history controllers, PriceComparisonController.php, SupplierPriceHistoryBuilder.php, history/period/PO/quotation/dashboard/PDF views.
- Reporting: requisition, quotation, PO, and supplier price-history exports.
- Tests: ProcurementRevisionTest.php, QuotationAvailabilityTest.php, SupplierPriceHistoryBuilderTest.php, import/export/Hashid/PR workflow regression assertions.

## 4. Migration

Created and applied locally:

database/migrations/2026_08_20_000001_make_period_month_nullable_for_annual_periods.php

It changes only periods.month to nullable, preserving existing monthly rows and all period_id relationships. Rollback refuses to make the column required while annual rows exist; no existing data is deleted or rewritten.

## 5. HS Code submission behavior

PrItemProcessor no longer rejects a valid PR submission merely because no HS rule resolved a code. Auto, manual, ambiguous, unmapped, and insufficient-data states remain distinct. A valid unresolved item persists hs_code = null without inventing a value. Existing manual-format and insufficient-data validation remains enforced.

## 6. Separate dimension UI

PR create/edit now render direct named inputs for thickness, width, d_outer, d_inner, and length. The shape script enables only relevant cells, clears/disables irrelevant values, and the server sanitizer applies the same shape contract. The old grouped slot/source-input contract was removed; the import regression test was updated to assert the new direct dimension-input contract.

## 7. Supplier amount root cause and fix

The persisted quotation_items.amount was a snapshot calculated inline from a potentially stale/cached PrItem relation, with no shared rounding contract, no fresh source re-read during the save loop, and no reconciliation/display strategy for legacy rows already saved as zero. That allowed a valid price and current PR weight to continue displaying a zero snapshot.

Fixes:

- QuotationItem::calculateAmount() is the single server formula: price_per_kg times prItem.total_weight, rounded to four decimals.
- QuotationController::store() re-queries each validated PR item by both item id and owning PR before calculating; request amount fields are never trusted.
- Submitted quotations still snapshot the selected exchange rate for IDR conversion.
- resolved_amount derives an eligible legacy zero for display without changing a positive historical snapshot.
- quotations:repair-zero-amounts is dry-run by default. It only considers price_per_kg greater than zero, amount less than or equal to zero, and positive PR total KG; --execute is required to persist repairs. No production repair was run automatically.

Coverage includes normal quantity, manual KG, draft, submitted, revision, IDR conversion, legacy-zero display, and dry-run/execute repair behavior.

## 8. Total KG

PR list AJAX data calculates SUM(weight_needed times quantity) in a subquery (with the model's minimum quantity semantics), avoiding an N+1 aggregate. The list displays four decimals plus kg; the detail page has a total requested-weight summary; both requisition exports repeat a PR Total KG column for each item row.

## 9. Annual Period strategy

New period creation no longer sends a month and derives a default name (Period YYYY) when needed. Annual rows are unique per year at the application validation boundary and display the year as their identity. The migration makes month nullable. The edit screen retains an optional legacy-month selector so historical monthly records remain editable without changing their relationships.

Important month-based logic replaced or intentionally retained:

Replaced with annual/year display and annual-first ordering:

- Period display identity and PeriodController create/update/index behavior.
- Purchasing PR index/create/edit/show selectors and labels.
- Purchasing report period selector.
- Supplier quotation period index/period page/create labels.
- Purchasing quotation list/detail, PO create/detail/list, supplier/purchasing dashboards, admin requisition detail, and PO/QC PDF labels.
- Inter-supplier comparison labels and period fallback labels.
- Requisition, quotation, and PO export period labels.

Intentionally retained:

- Existing monthly ProductionDummySeeder data and legacy monthly rows.
- The legacy-month edit option and month_display (Annual versus legacy month).
- Calendar-month dashboard analytics (supplier current-month submissions, purchasing PR-per-month chart, admin current-month PO count).
- PR/PO document numbering based on the current calendar month, not procurement-period identity.
- Historical chart monthly/yearly views as analytic granularity; their source event/date is now the PO.
- Month-based date-range filters for quotation/report analytics where they describe a reporting window rather than period identity.

## 10. PO-backed historical data

History now requires this live path:

po_quotations.quotation_id -> quotations.id
po_quotations.po_id -> purchase_orders.id
quotation_items.quotation_id -> po_quotations.quotation_id
quotation_items.pr_item_id -> pr_items.id -> purchase_requisitions/period

Queries scope the supplier directly from purchase_orders.supplier_id, exclude deleted POs and deleted quotations, and use the PO exchange-rate snapshot first with the quotation rate as a legacy fallback. Monthly rows, yearly aggregates, overview cards, supplier material options, and vs best history all use purchase_orders.created_at. A quotation without a PO link is excluded; a test explicitly detaches the pivot and confirms the history disappears.

## 11. Bidding supplier count

The PR list uses a correlated count of COUNT(DISTINCT quotations.supplier_id) where submitted_at is non-null and the quotation is not deleted. The compact numeric chip is rendered only for bidding status. PR detail uses the same distinct submitted-supplier semantics. Drafts, duplicate submissions from one supplier, and non-bidding statuses are covered by tests.

## 12. Tests and build

Final full suite:

223 passed (2309 assertions)

Important targeted evidence:

- ProcurementRevisionTest: 3 passed, 18 assertions
- QuotationAvailabilityTest: 10 passed, 80 assertions
- SupplierPriceHistoryBuilderTest: 3 passed, 28 assertions
- MissionFiveImportTest: 14 passed, 144 assertions
- MissionFourExportTest: 4 passed, 65 assertions
- PurchaseOrderReferenceRemarkTest: 3 passed, 51 assertions
- PurchaseRequisitionMaterialAutomationTest: 13 passed, 105 assertions
- HashidUrlSecurityTest: 5 passed, 119 assertions
- AsyncExportQueueTest: 7 passed, 237 assertions

Also passed:

- php artisan view:clear
- php artisan view:cache
- PHP syntax checks on changed PHP files
- git diff --check
- npm.cmd run build (Vite; CSS 38.13 kB, JS 96.11 kB before gzip)

PowerShell's npm.ps1 execution policy blocked the bare npm command; the equivalent npm.cmd run build completed successfully.

## 13. Design guides and MCP usage

Read and applied:

- design
- ui-styling
- ui-ux-pro-max

The ui-ux-pro-max referenced local search helper was unavailable, so its embedded guidance was used directly and the limitation is recorded rather than fabricated.

MCP calls actually used:

- Material Design 3: page index plus spacing, color/contrast, and state-layer guidance.
- Coolors: tonal palette for #1F5FA6, WCAG/APCA contrast checks, and palette CVD accessibility audit.

Evidence from Coolors included 5.97:1 for #1F5FA6 on #F4F6F8 and 6.47:1 for white on the primary blue. The palette audit flagged that the red/blue pair can become similar under achromatopsia; status semantics therefore continue to use text/icons and this is not presented as a blanket WCAG-compliance claim.

## 14. Manual visual QA and deferred risks

MANUAL_VISUAL_QA_REQUIRED: no browser runtime was available in this execution, so authenticated desktop/mobile rendering, keyboard/focus behavior, horizontal scroll feel, and screenshot comparison remain outstanding. Static Blade compilation, CSS build, route-level feature tests, and accessibility-oriented markup assertions passed.

Remaining compatibility dependencies are intentional: DataTables, jQuery required by current DataTables callsites, SweetAlert/AdasiAlert, Chart.js, Bootstrap 5/Bootstrap Icons, Tailwind, and Alpine.js. Routes, authorization, Hashids, supplier isolation, business workflow, and database data contracts were preserved; the only schema change is the explicitly permitted nullable legacy period month.

Recommended next steps:

1. Run the authenticated visual matrix at 390, 768, 992, and 1280+ widths, including the supplier quotation horizontal table scroll and compact Additional Information fields.
2. Review the dry-run output of php artisan quotations:repair-zero-amounts against production records, then approve --execute only for eligible rows.
3. Keep monthly history analytics and legacy period rows under observation during deployment.
