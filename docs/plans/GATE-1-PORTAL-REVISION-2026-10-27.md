# GATE 1 — Portal Revision 2026-10-27
**FINAL CANDIDATE — RECONCILED ARCHITECTURE PLAN (USER PRICE-GROUPING CLARIFICATION, 2026-10-28)**
Mode: planning and verification only. Status: GATE 1 FINAL CANDIDATE — GROUPING CLARIFIED, PENDING SINGLE GATE 2 APPROVAL.

## A. Correction pass, 2026-10-28: summary
This pass amends this document in place. It is not a new Gate 1 version. It adds no missions and does not widen product scope. The verified findings (§1), state-machine rules (§2), protected invariants and four workstreams are unchanged except where listed here.

1. **Authority.** The user's approved decisions (§B) now replace Gemini's restatement as the requirement authority. Gemini text remains evidence only.
2. **PUR-07.** The dynamic dimension-option contract has been restored (§3-G). Options come from eligible history, scoped to material, shape and supplier, and filter the existing range inputs. No duplicate filter parameter is added.
3. **GEN-03.**
   - Invalid entered values now return a controlled 422. They are never silently exported as empty cells.
   - The form's row model is documented (one row per PR item; no alternative-offer rows).
   - Item references are validated exactly as the existing save path does.
   - The Mode 2 scope and filter proof is defined (§3-F).
4. **PUR-06 / GEN-01 — final user clarification (2026-10-28).**
   - The actual defect is **missing thousands grouping** on certain price/monetary displays. For example, Indonesian `648193716,98` must display as `648.193.716,98`; international `648193716.98` as `648,193,716.98`.
   - The earlier `Rp70,000.00` was an illustrative price, **not** approval to force an `Rp` prefix, remove its spacing, or mandate two decimals.
   - The bounded cross-module inventory remains, but is now a **triage list**: change only surfaces proven to lack grouping or to have incorrect separators; preserve already-correct surfaces and Phase 2B precision/symbol contracts.
   - **D-1 is resolved by the user's clarification:** grouping-only remediation. The proposed Phase 2B currency/precision supersession is withdrawn. This is not another question awaiting Gate 2 approval.
5. **QC-01.** The blanket fail-closed 422 for unrecognised shapes has been withdrawn. A characterised, backward-compatible strategy replaces it (§3-B). Any data profiling uses a restored or sanitised dataset only.
6. **Performance oracle.** The "whole PO show page has constant query count" criterion has been replaced. The new criteria isolate the queries the revision adds and require a measured baseline (§D).
7. **Environment.** The Gate 2 entry conditions have been rewritten (§E). They include the HEAD recheck, no assumed DB access, DB creation only after approval, and the release prerequisites G-PDF, T-1 and staging rehearsal.
8. **Decisions.** The business-decision list has been re-examined against §B (§F). Readiness for all 14 IDs has been re-stated (§4).

## B. Approved Requirements Register (authority)
Source: the user's directly stated approved decisions (correction pass, 2026-10-28). The complete V1 document and interview transcript are not in the cloud session. If either is provided at Gate 2 kickoff, it must be compared row by row against this register, and any difference stops the affected ID until resolved.

| ID | Approved decision | Plan section | Earlier deviation now corrected |
|---|---|---|---|
| PUR-01 | A: all five PR dimensions appear separately in the import preview | §4 | — |
| PUR-02 | A: per-item Total KG, plus Grand Total KG and Grand Total Qty | §4 | — |
| PUR-03 | A: remove supplier contact information **only** from Price Comparison | §4 | — |
| PUR-04 | A: sticky header + sticky Material column | §4 | — |
| PUR-05 | A: AJAX material filtering without page reload | §4 | — |
| PUR-06 | A, clarified: restore missing thousands grouping on existing price/monetary display values according to regional preferences; preserve existing prefix, spacing and fractional precision | §3-H | New currency-symbol/fixed-decimal redesign withdrawn |
| PUR-07 | A: dynamic Flat→Thickness and Round→Diameter historical filters; dimension options come from available relevant data | §3-G | Option contract restored |
| PUR-08 | A: PO and Shipment status synchronisation based on aggregated progress | §3-A, §3-E | — |
| PUR-09 | A: PO completes only after all ordered material is received, accepted by QC, and required receiving processes are complete | §3-E, §F | Interpretation of "receiving processes" recorded (D-4) |
| SUP-01 | A: separate read-only Purchasing Request Overview for Suppliers | §3-D | — |
| QC-01 | A: actual dimensions required, initially empty, with an editable Copy from PO | §3-B | Fail-closed 422 withdrawn |
| GEN-01 | Apply the same grouping correction to relevant portal price/monetary display surfaces, **only where missing or incorrect** | §3-H | Broad visual reformatting withdrawn; bounded inventory retained for audit |
| GEN-02 | A: Download Template on every existing Excel import menu; the Supplier template column order is a confirmed issue | §3-F | — |
| GEN-03 | C: export both the currently entered unsaved form data and previously saved data | §3-F | Silent nulls withdrawn |
| Confirmation 1 (superseded interpretation) | Example `Rp70,000.00` illustrates a price with grouping; **not** a universal new prefix/decimal rule | §3-H | No global two-decimal or no-space `Rp` mandate |
| Confirmation 2 | The Supplier Excel template column order must be corrected | §3-F | — |
| Confirmation 3 | Export must contain the actual entered records, not an empty template | §3-F | — |
| **Confirmation 4 (latest, 2026-10-28)** | **Actual issue: missing thousands grouping.** E.g. Indonesian `648193716,98` → `648.193.716,98`; international `648193716.98` → `648,193,716.98`. Preserve current decimals, symbol, placement and spacing. | §3-H, §F | Supersedes the forced `Rp70,000.00` interpretation |

## 0. Source of truth established

| Item | Evidence (cloud checkout, read-only) |
|---|---|
| Branch / HEAD | `claude/sharp-edison-h93xqe` at `c74386f` (= `master`, merge of PR #11, 2026-10-23). Working tree clean. Only one worktree. |
| Gemini archive vs HEAD | The archive Gemini cites ends at migration `2026_10_19_172830`, matching HEAD. Its line numbers matched the current source in almost every spot-check. No drift was detected. |
| Gate 1 V1 (14-ID plan) | **Unavailable in the repository.** `docs/plans/GATE-1-MATERIAL-PROCUREMENT-REVISION-PLAN.md` is the older 35-decision plan (baseline `78e8bf4`). The 14-ID requirement text and V1 claims are known only as quoted by Gemini. Wherever this document says "V1 claim", it means the claim as reported by Gemini. |
| Approved requirement text | Not in the repository. **Superseded by §B** (user-stated approved decisions). Gemini §5 is evidence only, never authority. |
| Local dirty tree (V1 §I.1) | Not present in the cloud. The Gate 2 preflight must compare any local tree against `c74386f`. |
| Shipment Concurrency Hotfix | Committed (`b4f56cd`). Present at HEAD: `SUBMIT_ATTEMPTS = 3` (`ShipmentService.php:34`), sorted lock order. `ShipmentService.php` sha256 = `a4361cbc…be77`. Zero-diff guard. |
| Feature flags | `ProcurementFeatures`: `po_generation_enabled` (true), `ordered_quantity_write_enabled` (false), `allow_offer_surplus`, `shipment_mtc_enabled` (true). |
| PR status writers | draft→submitted (`PurchaseRequisitionController:258`), →bidding (`Supplier\QuotationController:811`, `PurchaseOrderGenerationService:356`), →completed (`…:334`). **Nothing writes `rejected`, and nothing reverts a PR to `draft`.** |
| PO `overdue` status | **Nothing writes `status='overdue'`.** The only reference is the preserve branch at `PurchaseOrder.php:810`; the value survives only in the DB enum. |

Evidence labels: **[S]** = verified from current source in this session. **[G]** = reported by Gemini, not independently re-verified. **[I]** = inference that needs Gate 2 runtime/browser proof. **[B]** = genuine business decision.

## 1. Independent Review Reconciliation Register

Columns: Gemini finding · V1 claim (as quoted) · actual current evidence · verdict · final disposition · regression and security implications.

| ID | Gemini (sev) | V1 claim | Current code evidence [S] | Verdict | Final disposition | Regression / security |
|---|---|---|---|---|---|---|
| RISK-01 | PUR-08 overdue (CRIT) | Overdue = "ETA passed AND qty not fully accepted" | `getIsOverdueAttribute` `PurchaseOrder.php:52-61` requires `active && !actual_arrival`. `poBadge/poLabel` let `$isOverdue` win (`StatusHelper.php:257-276`). The SQL copies in `PurchaseOrderExportFilters.php:59` (shared by the PO index and Supplier PO index) and `PurchaseOrdersExport.php:94-103` both use `whereNull('actual_arrival')`. `ShipmentService::confirmArrival` sets `actual_arrival` on **every** arrival (`:576-580`), then reconcile returns the PO to `active` after partial QC OK. | Confirmed (V1's query-based design is unsafe) | **Guarded predicate** (§3-A), not Gemini's bare removal. It differs for legacy-only POs (`active` + `actual_arrival` after a resolved NG claim, which is reachable via `PurchaseOrderController::confirmArrival` `:272-300` + claim resolve). | Badge masking avoided because `active` is still required. PHP/SQL parity is enforced by a test. No N+1. |
| RISK-02 | QC-01 spec + shape case (CRIT) | Copy from PR item | QC create/show read `$prItem->{field}` (`qc/inspections/create.blade.php:188-197`, `show.blade.php:128-186`). The PO views show the **offer** (`purchasing/po/show:355-360`, `supplier/po/show:250-251`). `relevantDimensionFields` is an exact-key lookup (`PrItem.php:145`). Production input is validated case-sensitively (`Rule::in(PrItem::SHAPES)`). Lowercase shapes enter only through direct writes, i.e. 7 test fixtures in 5 files. | Confirmed, with one refinement | Spec authority = `COALESCE(qi.available_*, pi.*)` (§3-B). Shape canonicalisation applies **only on the QC path**, not globally, because a global change would alter `specificationSnapshot()` and thus `spec_acceptance_hash` (`PrItemAwardService:321-331`). | A global normalisation would invalidate acceptance hashes. The plan avoids that. |
| RISK-03 | Vs Best eligibility (CRIT) | Add shape to PARTITION BY | `buildVsBestQuery` `PriceComparisonController.php:661-677`: no `status<>'cancelled'`, no award scoping, `qi.price_per_kg` instead of the frozen price, partition by `material_name` only, `users.name`. `PurchaseHistoryPriceService::eligibleLines` `:187-244` has the correct rules. | Confirmed | Port the `eligibleLines` eligibility rules (§3-C). | `PriceComparisonPerformanceRegressionTest` and `tests/Support/vs-best-benchmark.php` must stay green. |
| RISK-04 | Fulfillment N+1, NG shown as Waiting QC, claim routing (HIGH) | `fulfillmentSummary()` loops `itemFulfillmentStatus` | `itemFulfillmentStatus` always queries fresh (`:608,613-634`). `projectionForAward` adds an extra `DB::table('shipment_items')` query and computes `arrived_pending = reserved − in_transit`, which includes unresolved NG (`MaterialProgressService.php:72-139`). The Create Claim action picks the latest inspection regardless of status or claims (`MaterialClaimController::dataActionNeeded:38-69`, `PurchaseOrderController:63-71,126-131`, `po/show:504-506,739-745`), while `store` rejects any inspection that already has a claim (`:111,146-151`). | Confirmed | Separate read model with a shared pure tally (§3-E). Claim action = latest NG inspection **without any claim, including trashed**. | `ShipmentService` keeps its fresh-query path untouched. |
| RISK-05 | Export Form round-trip (HIGH) | FormData → template columns | Form names `items[i][available_length_input]` (`create.blade.php:1046`), hidden `is_available` (`:972`). Import reads column `available_length` and passes it through `nullableNumber` (pass-through) and then `DimensionRange::parse` (`QuotationItemsImport.php:101,158`), so ranges import correctly. `SpreadsheetCellSanitizer::text` defaults `fallback='-'` (`:7-28`). | Confirmed | §3-F. Plus a **new finding**: a sanitised note starting with `=+-@` gains a leading `'` on re-import, which is a documented, bounded exception. | Formula injection stays blocked. |
| RISK-06 | Supplier visibility (HIGH) | Use scope, optionally fix `isVisibleToSupplier` | Scope `PurchaseRequisition.php:98-105` ignores status. `isVisibleToSupplier` (`:107-117`) skips the own-quotation check when `invitedSuppliers` is loaded (`create:208-216`, `store:315-323`). `importableRequisition` uses the scope. `index()` `:57-63` has an unparenthesised `orWhereHas`. | Confirmed (facts). **Disposition differs** | SUP-01 uses a status-guarded read scope. **Do not broaden write authorisation** in `isVisibleToSupplier`, because that would let de-invited suppliers revise, a [B] decision. Overview action buttons use the exact `create()` predicate. The `index()` grouping is recorded as out of scope: it exposes only the supplier's own periods, not other suppliers' data. | No cross-supplier leak exists today. Drafts never appear on PR lists, because `period()` filters status. |
| RISK-07 | Company name in 7 places (HIGH) | Header + 2 SQL | 7 `users.name` sites confirmed: controller `:155,285,345-355,409,724,730,761-762`, service `:109,113`. The repo convention is `supplier?->company_name ?: name` (`QuotationDefinition:45`, `SupplierAudit:220`). | Confirmed | Use the convention plus eager-loading, with `COALESCE(NULLIF(TRIM(s.company_name),''), u.name)` in SQL. **No** lazy-query accessor (Gemini's accessor lazy-loads, a hidden N+1). | Bump the historical-suppliers cache key to `v2`. |
| RISK-08 | Material+shape select collision (HIGH) | Remove `unique('material_name')` | `historicalMaterialsForSupplier` `:954-972` calls `->unique('material_name')`. `historical()` `:370` uses `firstWhere('name')`. The request reads no `shape` input. The option value is the name only. | Confirmed | §3-PUR-07. | — |
| RISK-09 | Sticky conflicts (MED) | Replace class | Inline `!important` styles + `col-sticky-material` (`_inter_supplier_content:1-48`). The direct-child CSS (`app.css:3921-3953`) does not match DataTables' wrapper. Historical omits `sticky`. JS `renderTable`/`historicalResultShellHtml` rebuild the table without the sticky classes. | Confirmed | For Vs Best: a DataTables `dom` with the table in its own `ui-table-sticky` div, **not** a broadened CSS selector (which would scroll the search box and paging away). | Browser verification required [I]. |
| RISK-10 | PUR-02 `max(1)` (MED) | `max(1,qty)` everywhere | Footer uses `Math.max(0, trunc(qty))` (`_form_totals:40`). Requests require `required|integer|min:1`. The model accessor is `max(1,…)`. The MutationObserver `attributeFilter:['value']` misses `.val()`; it works by side effect except in `resetManualWeightOverride` (`_material_shape_script:486-489`). | Confirmed | §3-PUR-02. | — |
| RISK-11 | ajax() vs ServerTabs (MED) | — | All 3 tabs use `$request->ajax()` (`:319,440,489`). `replaceState(null/{} )` drops `_serverTabs` (`_scripts:1024,1215`). | Confirmed | Keep `ajax()`: AGENTS.md names comparison as the known legacy exception, and `PriceComparisonServerTabsTest` locks it. Fix the history state only. | Changing the fragment contract is out of scope. |
| RISK-12 | Grouping vs regional/test locks (MED) | `currency()` proposed | `RegionalDisplayFormatter` has no `currency()`. `'system'` returns text unchanged (`:101-104`). `data-price` regex is locked (`RegionalComparisonDisplayTest:76-87`). `QuotationDefinition` columns **all default true** (no `default:` passed). `AdvancedExportBaselineTest:97-171` locks headings and row. | Confirmed; scope clarified | **No mandated new `currency()` abstraction, no universal symbol/precision change.** Restore grouping using existing formatter paths where needed. New export columns still require `default:false`; `data-price` remains frozen. Preserve per-field decimal scale, existing prefix and Phase 2B contracts. | — |
| RISK-13 | Lazy module test (MED) | Two lazy modules | `app-lazy-modules.test.mjs` gates only 7 named modules (`:9-11`). | Confirmed | Import `pr-totals-core.js` statically in `app.js` and expose `window.AdasiPrTotals`. This is allowed because it is called synchronously by an inline script. | — |
| RISK-14 | `allQuotationItems` incl. unavailable (LOW) | — | `isFullyFulfilledAndInspected:738-740`. **Worse than reported:** `fulfillment_quantity = available_qty ?? prItem.quantity_value` (`QuotationItem.php:233-243`), so an unavailable item on a no-award PO with shipments has ordered_qty = PR qty and can never be accepted. That PO can never complete. `commercialQuotationItems()` also includes unavailable items when there are no awards (`:109`), so Gemini's "canonical method" premise is wrong. | Confirmed (re-scoped) | Filter `is_available !== false` in the completion check. | Reachable only for legacy no-award POs [I]. |
| RISK-15 | `PrItemsImport::HEADINGS` order (LOW) | — | `:20-32` order differs, but it is used only for heading presence and formula checks, not for display. | Confirmed, no impact | **No change** (deeper, not bigger). | — |
| RISK-16 | Copy inventory (LOW) | — | `TranslationParityTest`, `UserFacingCopyInventoryTest`. | Confirmed | All new copy goes in `lang/{en,id}`. Stage 0 determines whether the copy ledger must be extended for touched views (§5). | — |
| Gemini F4b | PUR-09 legacy `|| actual_arrival`, empty → true, award PO with 0 shipments | — | Code confirmed (`:731-744`). **None of these is reachable:** legacy arrival writes `waiting_qc` without reconcile, and every reconcile caller (QC store `:357`, claims `MaterialClaimController:164,229`, `ClaimController:126`, shipment arrival) runs after an inspection or shipment exists. | Code confirmed, impact **disproved** | **Characterise only. Do not modify** (no speculative patches). | Changing legacy completion could strand legacy POs. |
| Gemini Q3 | Open claim blocks other-material shipment | — | `ShipmentService:347` is a status allow-list that excludes `claim_needed`. It is not a claim query. | Partially (mechanism misdescribed) | Out of scope. Preserve the hotfix. | — |
| Gemini F10 | `_price_indicators` "IDR " literal | — | Prefix is `$code.' '` (currency code), not literal `IDR`. | Partially | Covered by PUR-06. | — |
| New | PUR-03 conflicts with Decision 10 test | — | `QuotationPresentationTest::test_comparison_header_shows_pic_and_the_q8_label` (`:182-193`) asserts the PIC is shown on inter-supplier. | New finding (missed by Gemini) | One sanctioned contract-test inversion (§4). | — |
| New | GEN-02 conflicts with template lock test | — | `MissionFiveImportTest:118-123` asserts the exact 13-key order. `:427` asserts import rows have **no** `material_name` (anti-spoof). | New finding | Sanction a heading-order assertion update. **Keep** `:427`: the preview Material column comes from a client-side lookup, not the payload. | — |

## 2. Truth tables

### 2.1 Overdue (PUR-08), every reachable PO state
Final predicate:
`status='overdue' OR (status='active' AND eta IS NOT NULL AND eta < BusinessTime::today() AND (actual_arrival IS NULL OR has_shipment_items))`

| Reachable state | Today | Gemini | Final |
|---|---|---|---|
| active, no arrival, ETA past | overdue | overdue | overdue |
| active after partial shipment-based arrival + QC OK, ETA past | **not overdue (bug)** | overdue | **overdue** |
| active after resolved NG claim (shipment-based), ETA past | not overdue | overdue | overdue (replacement pending) |
| legacy-only (no shipment items), active after resolved NG claim, ETA past | not overdue | overdue (behaviour change) | **not overdue (preserved)** |
| waiting_qc / claim_needed, ETA past | badge = status | badge = status | badge = status (requires `active`) |
| completed / cancelled | no | no | no |

`has_shipment_items` is exact. A legacy-arrived PO can never gain shipment items, because `createDraft` (`ShipmentService:155`) and submit (`:351`) both reject `hasLegacyOnlyArrivalState()`. Legacy arrival also requires zero shipment items (`isLegacyArrivalEligible`).

### 2.2 SUP-01 visibility (`scopeAccessibleBySupplier = visibleToSupplier AND (status IN (submitted,bidding) OR own non-trashed quotation)`)

| PR status | Invitation | Own quotation | List/detail | Action (same predicate as `create()`/`show()`) |
|---|---|---|---|---|
| draft | any | impossible (no quotation creation on draft; no revert to draft) | hidden, 404 | — |
| rejected | — | — (status never written) | hidden, 404 | — |
| submitted/bidding | none (open) | no | visible | Create → `quotations.create` |
| submitted/bidding | self invited | no | visible | Create |
| submitted/bidding | others only | no | hidden, 404 | — |
| submitted/bidding | self invited | draft / revision_requested, not award/PO-referenced | visible | Continue/Revise |
| submitted/bidding | **de-invited** (others only) | yes | visible (scope parity with `period()`/import) | **View only.** `create()` would 403 through `isVisibleToSupplier`'s loaded branch; the write rule is unchanged ([B] D-3) |
| submitted/bidding | any | submitted/accepted/rejected/all_unavailable | visible | View → `quotations.show` (`QuotationPolicy::view` = owner) |
| completed | any | yes, any status | visible | View |
| completed | any | no | hidden, 404 | — |

Soft-deleted own quotations do not grant visibility, because `whereHas` honours SoftDeletes. Data allowlist: PR number, period, logistics, notes, items (fields already shown in `supplier/quotations/create`), and the supplier's own quotation status and estimated delivery. Never loaded: other suppliers' quotations, the invitation list, awards, reference prices, the creator.

## 3. Critical findings: final designs (10-point evidence standard)

Each block lists: Location · Root cause · Design · Invariant · Characterization test (CT) · Contract test (KT) · Negative test (NT) · Oracle · Risk · Rollback.

### A. PO Overdue (PUR-08)
- **Location:** `PurchaseOrder.php:52-61`; `PurchaseOrderExportFilters.php:59`; `PurchaseOrdersExport.php:94-103`. Accessor consumers: `PurchaseOrderController:106-113`, `SupplierPurchaseOrderController:96-109`, `PurchaseOrderDetailExport:90`, `PurchaseOrderDefinition:61`, `PurchaseOrdersExport:151`, and views `po/show` ×2 and dashboards ×2.
- **Root cause:** `actual_arrival` means "latest arrival", not "fully received". The `!actual_arrival` guard predates partial shipments.
- **Design:**
  - Apply the §2.1 predicate in the accessor and in both SQL builders. The SQL form is `(actual_arrival IS NULL OR EXISTS(SELECT 1 FROM shipment_items si WHERE si.purchase_order_id = purchase_orders.id))`.
  - The accessor evaluates the cheap terms first. It reads `has_shipment_items` from a `withExists('shipmentItems as has_shipment_items')` attribute, or from a loaded `shipmentItems` relation. It falls back to a query **only** in the narrow branch (active, ETA past, arrived).
  - Add `withExists` to the list, dashboard and export queries that read `is_overdue`.
  - `po/show` step 4 shows "Latest partial arrival" when `actual_arrival` is set and the status is not `completed`.
  - `po/show:798-800` (`isPast()` without status) is aligned to `is_overdue`.
- **Invariant:** a `waiting_qc`/`claim_needed` badge is never masked. The PHP badge set equals the SQL `status=overdue` set.
- **CT:** the current outputs for all §2.1 rows, captured before the edit.
- **KT:** for N seeded POs covering every row, `PurchaseOrder::get()->filter->is_overdue->pluck('id')` equals the `PurchaseOrderExportFilters::apply(status=overdue)` ids, which equal the `PurchaseOrdersExport` query ids.
- **NT:** waiting_qc/claim_needed with ETA past → not overdue. Legacy-only resolved NG → not overdue.
- **Oracle:** the overdue predicate adds no per-row query. On the PO index DataTables JSON, the queries attributable to `is_overdue` (the `withExists` subselect inside the main query, plus any narrow-branch fallback) number zero for 1 and 25 rows. The page's own baseline query count is measured, not assumed (§D).
- **Risk:** low. No writes and no status transitions.
- **Rollback:** revert the commit. No data is involved.

### B. QC Specification Authority (QC-01)
- **Location:**
  - `QcInspectionController:165-204` (rules), `:445-460` (sanitize), transaction from `:215`.
  - `qc/inspections/create.blade.php:100-197,304-318`; `show.blade.php:124-186`.
  - `PrItem.php:83-150`; `QuotationItem.php:281-290,559-581`.
- **Root cause:** QC was built against the PR spec before offer-spec acceptance (Decision 04) existed.
- **Proof of the frozen PO spec:**
  - Once an award or PO references a quotation, it cannot be revised (`Quotation::isReferencedByAwardOrPurchaseOrder`, including soft-deleted POs; AGENTS §Item Award). The `available_*` fields are therefore immutable for every PO line.
  - When the spec differs, `pr_item_awards.spec_acceptance_snapshot.offered` records the same values under hash (`PrItemAwardService:321-331`).
  - The ordered spec per relevant dimension is: offered value when non-null; else the PR requested value (offer silent = requested spec). This is the same convention as `PurchaseHistoryPriceService` key dimensions, `COALESCE(qi.available_{f}, pi.{f})`.
  - Length is special: an exact `available_length` wins. If `available_length_min/max` is set instead, the spec is a **range**: it is displayed as `available_length_display`, has no scalar, and Copy leaves the field empty. Otherwise fall back to PR length.
- **Design:**
  1. Add `QuotationItem::orderedSpecification(): array<dim,{value:?string, display:string, source:'offer'|'requested'|'range'}>`. It is read-only, uses the QC canonical shape, and its PR requested values are shown as a secondary line only where they differ.
  2. Add `PrItem::canonicalShape(?string): ?string` (case-insensitive, trimmed). It is used **only** by the QC validation/sanitize/view path. `relevantDimensionFields()` stays untouched so the acceptance-hash and comparison semantics are unchanged.
  3. Server rules are built in the existing pre-transaction loop. Each relevant dimension is `required|numeric|gt:0|max:999999.9999` for both `ok` and `ng`. `actual_weight` stays `nullable|numeric|gt:0|max:999999.9999`. Lock order is untouched.
  4. View: inputs start empty (`old()` only), relevant fields are `required`, plus a per-item "Copy from PO" button (`<x-ui.button>` + `<x-ui.icon name="copy">`).
  5. Copy writes only relevant numeric dimensions, triggers `input`, never changes the OK/NG switch, never submits, and announces via `aria-live`.
  6. The tolerance check and `show` comparisons use the ordered spec.
  7. **Legacy shape compatibility (corrected; the blanket 422 is withdrawn).** Facts [S]: `pr_items.shape` is a nullable string with no CHECK. The PR form and the import accept `null` or an exact canonical value (`Rule::in(PrItem::SHAPES)`). Lowercase or unknown values can enter only through direct writes (tests, legacy data). For an unresolvable shape, today's QC view renders weight only, and `sanitizeActualMeasurements` nulls every dimension.

     | Characterised case | Required actual dims | Copy from PO | Notes |
     |---|---|---|---|
     | Canonical `Flat`/`Round`/`Hollow` | all relevant dims, `required\|numeric\|gt:0\|max:999999.9999`, ok **and** ng | relevant numeric spec values | never bypassable |
     | Case or whitespace variant (`'round'`, `' Flat '`) | as canonical (QC-path `canonicalShape`) | as canonical | storage, acceptance hashes and global lookups unchanged |
     | `null` shape | none can be derived. **Unchanged:** weight only, no 422 | hidden | non-blocking inline notice "shape not set on the PR; dimensions cannot be verified" (new `lang/{en,id}` key) |
     | Unknown non-empty value (e.g. `Square`) | same as `null`. **Unchanged**, no 422 | hidden | same notice. Mapping any such values is decision D-5 |
     | PO with an accepted alternative offer | relevant dims of the canonical shape | the **offered** values (§ proof above) | requested values shown secondarily |
     | Exact ordered length | required | `available_length` | — |
     | Range ordered length | required (inspector enters the measured value) | not copied; the range is displayed | the existing scalar 5% tolerance hint is skipped for the length field (no new range-check feature) |

     Required dimensions apply to every valid known shape. No existing legacy workflow (null or unknown shape) changes behaviour. Server-side, the shape is always taken from the DB `PrItem`, never from the request.
- **Invariant:** QC status is only ever chosen by the inspector. Copy never sets OK. The PO spec shown equals the PO page spec.
- **CT:** the current create/show output for a Flat exact match and an accepted different offer (12 vs 10 mm).
- **KT:** `data-val` equals `orderedSpecification()` for Flat/Round/Hollow, exact length, length range (`data-val=""`) and offer-silent fields.
- **NT:**
  - Missing, zero, negative or >999999.9999 relevant dims → 422 for both ok and ng.
  - Irrelevant dims are ignored and nulled.
  - A lowercase `'round'` fixture still requires `d_outer` and `length`.
  - Null and unknown shapes are accepted with weight only (characterisation: response identical to today apart from the notice).
  - Copy markup has no `name="items[*][status]"` side effect (JS test).
- **Oracle:** independent arithmetic on a fixture where PR ≠ offer, checked against `spec_acceptance_snapshot.offered`.
- **Risk:** medium. The 31 existing `qc.inspections.store` calls in 4 files must send dimensions. This is a direct consequence of the approved requirement: Gate 2 adds a `tests/Support` payload helper. Existing fixture rows are **not** modified (no lowercase shapes are rewritten).
- **Data profiling (Gate 2, read-only):** `SELECT BINARY shape, COUNT(*) FROM pr_items GROUP BY BINARY shape`, plus the same grouped over PO-linked items with QC inspections, run **only** on a restored or sanitised dataset that the user provides. Direct production access is not authorised. The result decides whether D-5 needs an answer; it does not change the default behaviour above.
- **Rollback:** revert. Inspections recorded with dimensions remain valid data.

### C. Historical Best Price (PUR-07 / PUR-05)
- **Location:** `PriceComparisonController::buildVsBestQuery:646-748`, `vsBestPriceData:489-545` (cache logic `:514-534`, key `:873`).
- **Root cause:** Vs Best predates the authoritative purchase-history rules.
- **Design:**
  - History subquery joins `pr_item_awards a` on (quotation_item, po).
  - Filters: `po.status <> 'cancelled'`, `whereNull po/q deleted_at`, and the award-scoping clause copied from `eligibleLines` (award line OR legacy PO without awards).
  - Price = `PurchaseHistoryPriceService::PRICE` (frozen `ordered_price_per_kg` when `ordered_amount` is set), multiplied by the existing benchmark factor.
  - `ROW_NUMBER() PARTITION BY material_name, COALESCE(shape,'')`, and the best_choice join on both.
  - Material and shape are compared within a partition. Dimensions stay **displayed, not partitioned**, to avoid silently changing what "best" means (Q4). Exact-dimension comparison remains on the indicator strip (`itemIndicators:1108-1116`).
  - Optional `material_name`/`shape` filters (PUR-05) are added to `$isUnfilteredDefault`, and the cache key moves to `v3`.
- **Invariant:** Vs Best "historical best" for (material, shape) ≤ the `PurchaseHistoryPriceService::summarize(material, shape)['total']['best']` set, computed under identical eligibility.
- **CT:** the current Vs Best rows for a fixture with a cancelled PO, an unawarded line and two shapes, captured to document the defect.
- **KT:** the best price per (material, shape) equals `PurchaseHistoryPriceService` best under the same currency and evaluation date.
- **NT:** a cancelled PO, an unawarded quotation line, and the other shape all never win "best".
- **Oracle:** hand-computed min over the fixture.
- **Risk:** query plan. `PriceComparisonPerformanceRegressionTest` must stay green, and `EXPLAIN` is checked on MySQL 8 / MariaDB 11.4.
- **Rollback:** revert. The cache key bump isolates old entries.

### D. Supplier Overview Authorization (SUP-01)
- **Location:** `PurchaseRequisition.php:98-117`; `Supplier\QuotationController:126-136,208-216,315-323,1137-1176`.
- **Root cause:** there is no status-aware read scope. The visibility semantics differ between the scope and the instance method.
- **Challenge to `OR hasOwnQuotation`:** it is safe because:
  - (a) a quotation is creatable only on submitted/bidding PRs and PRs never revert to draft, so draft visibility through it is unreachable;
  - (b) `rejected` is never written;
  - (c) on completed PRs it exposes only the supplier's own history, the same as `period()`;
  - (d) a de-invited supplier keeps read visibility, matching `period()` and the import endpoints.

  Write rights are not widened.
- **Design:**
  - `scopeAccessibleBySupplier` (§2.2), extracted from the `period()` filter, so `period()` can reuse it with identical SQL.
  - A new read-only `Supplier\PurchasingRequestController@index/show` under `['auth','role:supplier','supplier.scope:import']`, routes `supplier.requests.index|show` (`{pr_id}` is already in `HASHED_PARAM_KEYS`).
  - `show` resolves with `accessibleBySupplier(auth()->id())->findOrFail` (404).
  - Eager-loads only the supplier's own quotation (`quotations` constrained to `supplier_id`).
  - The action button comes from one shared predicate: `PurchaseRequisition::supplierQuotationAction(User)` mirroring `create()` (status, loaded-branch visibility, `canBeRevisedBySupplier`, not award/PO-referenced).
  - Page registry UUID, quick access, sidebar, and a regional composer entry.
- **Invariant:** list ⇔ detail ⇔ action parity. No other supplier's data reaches the response.
- **CT:** the current `period()` PR set for the matrix fixture. The new scope must return the same set.
- **KT:** every §2.2 row: list membership, detail 200/404, and the action route resolving to a 200 for that supplier.
- **NT:** raw integer id → 404. The competitor's price, reviewer notes and company name are absent from the HTML. Draft and other-invited PRs → 404.
- **Oracle:** the set equality `accessibleBySupplier` == `period()` results across all periods.
- **Risk:** low. The change is additive.
- **Rollback:** revert. Routes and menu disappear.

### E. Fulfillment Projection (PUR-08 / PUR-09)
- **Location:** `PurchaseOrder::itemFulfillmentStatus:606-723`; `MaterialProgressService:72-139,277-322`.
- **Root cause:** a per-item fresh-query design, and the `reserved` bucket conflates in-transit, waiting QC and unresolved NG.
- **Stale-read analysis:**
  - `ShipmentService:439` calls `itemFulfillmentStatus($qItemId, $lockedShipment->id)` inside the lock. `getItemDeliveryStatus` (`:52`) calls it for supplier UI. Both need fresh reads.
  - Gemini's "reuse loaded relations when `$excludeShipmentId === null`" would silently switch `getItemDeliveryStatus` to a possibly stale in-memory graph. **Rejected.**
- **Design:**
  1. Extract the loop body (`:627-684`) into a private static pure tally `tallyFulfillment(iterable $shipmentItems, int $orderedQty, int $poId): array`. It is behaviour-identical and additionally returns `in_transit_qty`, `waiting_qc_qty` and `unresolved_ng_qty`, so that `reserved = in_transit + waiting_qc + unresolved_ng`. All existing keys and values stay the same.
  2. `itemFulfillmentStatus` keeps **exactly** its query and calls the tally.
  3. Add a new read-only `PurchaseOrder::fulfillmentProjection(): array`. It loads `shipmentItems` (submitted/arrived) with `shipment` and `qcItems.inspection.materialClaims` **once**, groups them by quotation item, and tallies each `orderedLines()` line. It also returns a per-shipment history and a derived display phase. It is never used for writes or guards.
  4. `projectionForAward` reads the new keys from the projection and drops its extra query. `arrived_pending_qc_qty = waiting_qc_qty`, and an NG column is added.
  5. Add a "Shipments & receiving" section and list all inspections on the PO show views.
  6. PUR-09: filter `is_available !== false` in `isFullyFulfilledAndInspected`; nothing else changes (Register F4b).
- **Invariant:** `ShipmentService.php` has a zero diff (sha256 guard). The transactional path is unchanged. Per item, `accepted + in_transit + waiting_qc + unresolved_ng + replacement_eligible = physical_shipped`.
- **CT:** `itemFulfillmentStatus` outputs for scenario C-1 (below) recorded before the extraction.
- **KT:** `fulfillmentProjection()[i]` == `itemFulfillmentStatus(i)` for every item at every step of C-1. Key-by-key equality on the shared keys.
- **NT:** NG under an active claim never appears as waiting QC. A completed PO requires accepted ≥ ordered for every available item.
- **Oracle:** see §D. The fulfillment projection's own queries are constant in the number of items and shipments, and the revision adds no per-item query on the PO show pages, measured against a recorded baseline.
- **Concurrency:** `ShipmentSubmissionConcurrencyTest` and `OrderedQuantityShipmentConcurrencyTest` with MTC on and off.
- **Scenario C-1:** Item A 10 Flat, Item B 5 Round, ETA yesterday.
  1. S1 ships A6 and B5 and arrives → `waiting_qc`, not overdue.
  2. QC: A ok, B ng → `claim_needed`. B: unresolved_ng 5, waiting_qc 0.
  3. Claim resolved → `active`, **overdue**. B replacement_eligible 5.
  4. S2 ships A4 and B5, both ok → `completed`.
- **Risk:** medium (refactor of a hot method). It is mitigated by the CT-before-extract and the parity KT.
- **Rollback:** revert. There is no persisted state.

### F. Excel Export (GEN-02 / GEN-03)
- **Location:** `QuotationImportTemplateExport.php:17-53`; `QuotationItemsImport.php:21-45,83-166,285-313`; `supplier/quotations/create.blade.php:897-1105,1362-1478`; `SpreadsheetCellSanitizer.php`; `QuotationsExport.php:118-207`; `QuotationDefinition.php:42-67`.
- **Canonical headings (GEN-02):** `pr_item_id, material_name, requested_dimension, available_qty, available_thickness, available_width, available_d_outer, available_d_inner, available_length, offered_weight_per_unit, price_per_kg, notes, availability`.
  - This matches the form's editable order (Qty → dims in `FIXED_DIMENSION_ORDER` → KG/Unit → Price → Notes → Availability).
  - It is a constant on `QuotationImportTemplateExport`. The sample row follows it.
  - The import stays heading-based, so old files still import.
  - Preview table order is PR Item ID | Material | Offer Qty | dims | Offer KG/Unit | Price/kg | Notes | Availability. Material comes from a client-side lookup keyed by `pr_item_id` against the form rows. The import payload is unchanged (`MissionFiveImportTest:427` holds).
- **Form row model (investigated [S], corrected):**
  - There is exactly **one data row per PR item**. Each PR item renders two visual `<tr>`s (a requested row and an offer row) that share one input set `items[i][…]`, with hidden `items[i][pr_item_id]` and `items[i][is_available]` (`create.blade.php:945-1105`).
  - There are no alternative offers and no repeatable rows per PR item.
  - The save path enforces `items` `size:` = PR item count, `pr_item_id` `distinct` and `exists` within this PR (`Supplier\QuotationController:353-358`). The import also rejects duplicate `pr_item_id` values.
  - Irrelevant dimensions have **no input** (rendered as `—`, `create.blade.php:1021-1039`). Inputs on unavailable rows are disabled, so `FormData` omits them.
  - The export therefore keeps one row per PR item, in PR item order, keyed by `pr_item_id`, which the existing import schema already supports.
- **Mode 1 Export Form (corrected: no silent loss):**
  - `POST supplier.quotations.export-form` (`{pr_id}`), `throttle:15,1`, `no-store`. Authorisation goes through `importableRequisition()` (role, active supplier, PR status submitted/bidding, `visibleToSupplier` scope, editable own quotation). This is the same gate as `import-preview`.
  - **Validation before any output, in a dedicated FormRequest.** It reuses the save path's item rules **without** the commercial-term requirements:
    - `items` is required, an array, with `size:` = PR item count;
    - `pr_item_id` is `required|integer|distinct` and `exists` in this PR;
    - numeric fields use the save path's types plus `gt:0`;
    - a non-empty length must satisfy `DimensionRange::parse`;
    - a non-empty value in a dimension that is irrelevant for the item's DB shape is **rejected** ("not applicable for this shape"), because the UI has no input for it;
    - notes must be strings.

    A failure returns a **422** (`{message, errors}` keyed `items.{i}.{field}`). No file is produced, and the existing form state is left untouched: the JS shows the error summary with `AdasiToast` and leaves the DOM, values and localStorage draft as they were. A missing, duplicate, foreign or reordered `pr_item_id` is a 422; it is never re-bound to another PR item.
  - **Mapping (validated input only):**
    - material name and requested dimension come from the DB `PrItem`, never from the request;
    - unavailable → the offer cells are blank and the row reads `'Not Available'` (inputs are disabled, so nothing is entered; this is not data loss);
    - available:
      - qty is an integer, or blank only if it was blank;
      - relevant dims are numeric at 4 dp;
      - exact length is numeric; a range is text in `DimensionRange::display()` form;
      - weight is the entered value, or blank if blank. **It is not auto-filled** on export: the save path's auto-calculation remains a save-time behaviour, so the export reflects exactly what was entered;
      - price is numeric at 4 dp;
      - notes: blank stays blank; otherwise sanitised text;
      - availability reads `'Available'`.
  - `FromArray + WithHeadings + WithColumnFormatting` (`#,##0` qty, `#,##0.0000` dims/weight/price). There are no DB writes and no `ExportJob`; it is a direct download, like the template. The file carries the actual entered rows, not an empty template (Confirmation 3).
  - JS: `FormData` minus file inputs, posted with `fetch`. A 200 becomes a blob download; a 422 renders inline errors. The form is never submitted or reset.
- **Round-trip limitations (honest):**
  1. A note beginning with `= + - @` (after trim) gains a leading `'` from the mandated sanitiser. The import is not changed to strip it.
  2. Numerics are compared at 4 dp, which is the storage scale.
  3. A blank weight stays blank. The quotation import itself recalculates weight per its own existing rules on preview.
  4. Offer fields typed on an unavailable row are not exported: the disabled inputs are never sent, which matches the save behaviour.
  5. MTC files are never exported.
  6. Commercial header fields (currency, delivery date, payment terms, validity, general notes) are outside the import schema and are not exported.

  For every other value of a valid form, export → `import-preview` returns field-equal rows.
- **Mode 2 Export Saved Data (verified scope [S]):**
  - This is the existing `supplier.quotations` export: `Supplier\ExportController@quotations` → `QuotationsExport([$filters, auth()->id(), includeDrafts=true])`. The owner is forced through `forcedSupplierId` / `QuotationExportFilters::apply($q, $filters, $owner)`. It emits one row per saved `QuotationItem`.
  - Supplier filters are `pr_number`, `period_id`, `status` (including `draft`/`unresponded`) and `search` (`QuotationExportFilters.php:25,77`).
  - **Observed [S], to verify, not to widen:** the legacy no-options path filters `date_from`/`date_to` on raw UTC Carbon (`QuotationsExport.php:86-94`), while the catalog path uses `BusinessTime` (`QuotationExportFilters` `apply`). The supplier filter UI does not offer date fields, but `validated()` still accepts `date_from`/`date_to` from a crafted request. Gate 2 characterises both paths with a test and reports the result. It is out of the revision's scope unless that test shows the supplier UI path is affected.
  - `QuotationDefinition` gains requested shape and dimensions and offered thickness/width/d_outer/d_inner, all with **explicit** `default:false`. `offered_length` already exists and stays text, because it can hold a range.
  - Number masks go through `WithColumnFormatting` on `QuotationsExport`, catalog path, XLSX only. Column letters are computed from the selected columns after `applyOptions`. `map()` values are unchanged.
  - The legacy (no-options) path is untouched. `AdvancedExportBaselineTest` stays byte-identical.
  - **Mode 2 KT:** for suppliers S1 and S2 with draft, submitted and accepted quotations across two periods, the S1 export with each filter combination equals the hand-computed set of S1's `QuotationItem` ids (draft included). S2's rows never appear. A tampered `supplier_id` in the request is excluded by the `exclude` rule. The row count equals the item count, not a template.
- **Invariant:** the client is never the source of material or spec. Text always passes the sanitiser. The baseline export is unchanged.
- **CT:** the current template headings and baseline export output.
- **KT:** `headings() === CANONICAL_HEADINGS`. The relative order of form, preview and template is equal. A template download → fill → `import-preview` returns 200.
- **NT:**
  - Forged `material_name` is ignored (DB value written).
  - A foreign, duplicate or missing `pr_item_id` → 422, and no file is produced.
  - An invalid length (one that `DimensionRange::parse` rejects, such as `abc` or `0`), a non-numeric price, or a value in an irrelevant dimension → 422 naming `items.{i}.{field}`. The JS test asserts the form DOM is unchanged afterwards.
  - Uninvited PR → 403/404.
  - Zero `quotations` / `quotation_items` / `export_jobs` delta.
  - Formula payloads (`=1+1`, `+x`, `-x`, `@x`, `"  =x"`) are neutralised.
- **Oracle:** read the generated XLSX with `IOFactory`. Numeric cells are `TYPE_NUMERIC`, the range cell is a string, and feeding the binary to `import-preview` returns the expected row values.
- **Gate 2 condition [I]:** prove that `WithColumnFormatting` applies under the queued chunked chain (`Excel::queue` + `CloseSheet`). If not, Mode 2 formatting is dropped and reported; it is never forced through `map()` strings.
- **Rollback:** revert. Old templates keep importing.

### G. Historical dynamic dimension options (PUR-07 A, restored contract)
- **Current controls [S]:**
  - The historical form already has one key-dimension fieldset with `key_min`/`key_max` number inputs (`_historical_content.blade.php:76-95`).
  - Its label switches between thickness and `d_outer` keys. Round and Hollow both show "Outer diameter".
  - The server resolves the key field from `MaterialReferencePrice::DIMENSION_FIELD_BY_SHAPE` (Flat→`thickness`, Round→`d_outer`, Hollow→`d_outer`) and normalises the bounds (exact when min = max, range otherwise; `keyDimensionRange:981-1000`).
  - Materials come from `historicalMaterials` (`routes/web.php:561`, controller `:451`) via `historicalMaterialsForSupplier` (`:954-972`, currently `->unique('material_name')`).
  - No dimension options are offered today.
- **Decision:** reuse the existing range controls as the single filter parameter pair. Add **one** options control that writes into them, and no third query parameter. No duplicate range inputs are added.
- **Contract:**
  1. **Label by shape:** Flat → "Thickness (mm)"; Round → "Diameter (mm)" (a new `lang/{en,id}` key), mapped to the authoritative field `d_outer`; Hollow keeps today's "Outer diameter (mm)" (unchanged, not in scope); an unknown or null shape hides the fieldset and sends no key filter.
  2. **Option source:** a single grouped query on `PurchaseHistoryPriceService::eligibleLines(['supplier_id'?])` returning `material_name, shape, key_value` for each eligible line. `key_value` = `COALESCE(qi.available_{field}, pi.{field})` with the field chosen per shape in SQL (`CASE`), which is exactly the expression the history filter already uses (Service `:218-234`). It is `DISTINCT`, non-null, ordered ascending, capped at 100 values per material and shape, and normalised to 4 dp. It inherits the cancelled-PO, award-scope and soft-delete exclusions. It is not narrowed by the period range, so the options stay stable while the range changes.
  3. **Scope:** options are keyed by (material, shape) and the selected supplier. They are delivered inside the existing `historicalMaterials` JSON and the initial page payload, one query in total. There is no separate endpoint.
  4. **Control:** a `<select id="historicalKeyOption">` with **no `name`**, listing "All" plus the values. Choosing a value sets `key_min = key_max = value` and reloads; "All" clears both. Typing a custom range into `key_min`/`key_max` remains possible, and the select then shows "Custom range". The URL and request carry only `key_min`/`key_max`, as today.
  5. **No matching dimensions:** if the material and shape have zero non-null key values, the select is disabled with "No dimension data for this material", the range inputs stay usable, and the history is unfiltered. If a requested `key_min`/`key_max` matches no rows, the table and chart show the existing empty state with a `role=status` message. They never show stale data.
  6. **Safe reset:** on a material or shape change, clear `key_min`/`key_max` and the select. On a supplier change, keep the bounds only if the same (material, shape) is still offered **and** (for an exact value) the value is still in its options; otherwise clear them. The server ignores bounds when the key field is null.
  7. **Automatic update:** every change goes through `loadHistorycalPayloadFromFilters` (it rebuilds the chart, table and summary). It is guarded by the PUR-05 `AbortController` plus a sequence id, and the URL is updated with `replaceState` preserving `_serverTabs`.
  8. **Material identity:** the (material, shape) options and the hidden `shape` input are as in §4 PUR-07.
- **Fixtures (Gate 2):**
  - Supplier S1:
    - SCM440 Flat, awarded PO lines at thickness 10 and 12;
    - SCM440 Round, `d_outer` 50, plus one offer line with PR 50 / `available_d_outer` 52, so the option is 52;
    - a cancelled PO line, SCM440 Flat at thickness 99 (excluded);
    - an unawarded line on an awarded PO, Flat at thickness 77 (excluded);
    - S45C Hollow at `d_outer` 60;
    - a material with a null shape.
  - Supplier S2: SCM440 Flat at thickness 14.
- **Server KT:**
  - S1 options: Flat [10, 12]; Round [50, 52]; Hollow [60]; null shape → none.
  - All suppliers: Flat [10, 12, 14].
  - 99 and 77 never appear.
  - The options query count is 1.
- **Browser acceptance (observable, Chrome):**
  1. Select S1 → SCM440 (Flat): the legend shows "Thickness (mm)", the options are 10 and 12, and the table and chart show both.
  2. Choose 12: one request is made, the URL gains `key_min=12.0000&key_max=12.0000`, and the table rows and chart points are only thickness 12.
  3. Switch to SCM440 (Round): the legend shows "Diameter (mm)", the options are 50 and 52, the key inputs are empty, the URL loses `key_min`/`key_max`, and the table and chart update without a reload.
  4. Switch quickly Flat→Round→Flat: the final DOM and URL equal the last selection (no stale response).
  5. Pressing Back restores the previous state through `_serverTabs` popstate.
  6. Choose the null-shape material: the fieldset is hidden.
  7. Pick a supplier for whom SCM440 Round has no history: the bounds are cleared and the select shows the empty-data message.

### H. Missing thousands grouping in price displays (GEN-01, integrated with PUR-06)

**Authority — latest user clarification, 2026-10-28.** The problem is that **some** price/monetary amounts are rendered as a continuous run of digits without a thousands separator. For example, `648193716,98` should be grouped. The older example `Rp70,000.00` **does not** require a new currency-formatting policy. Preserve all existing regional, currency-prefix, prefix spacing, per-field decimal scale and rounding semantics, unless a call site has a separately demonstrated formatting defect.

**Correctness contract (presentation only):**

| Effective number-format preference / context | Ungrouped example | Expected grouped numeric segment | Invariant |
|---|---|---|---|
| Indonesian (`indonesian`) | `648193716,98` | **`648.193.716,98`** | `.` groups thousands and `,` remains the decimal separator; two source decimals remain two. |
| International (`international`) | `648193716.98` | **`648,193,716.98`** | `,` groups thousands and `.` remains the decimal separator; two source decimals remain two. |
| Existing price with 4 fractional digits | `648193716,9876` (id) or `648193716.9876` (en) | `648.193.716,9876` / `648,193,716.9876` | Preserve **four** decimals where the existing surface supports them. |
| Existing integer-only presentation | `648193716` | `648.193.716` or `648,193,716` per effective preference | Do **not** add `.00` or `,00`. |
| With existing prefix (`Rp `, `Rp`, `IDR `, other currency code) | E.g. `Rp 648193716,98` | `Rp 648.193.716,98` | **Keep the original prefix and its spacing**; group only the numeric segment. |
| Already grouped | `648.193.716,98` | unchanged | No double grouping, duplicate prefix or symbol shift. |

`system` is **not** automatically synonymous with `international`. Follow the portal's existing system/default regional-format contract for each call site. If the default context cannot be resolved reliably from a canonical numeric value and effective preference, record that specific location as a technical blocker; do not infer locale merely from a visually formatted string or re-interpret the global setting.

**Implementation contract (smallest safe correction):**

1. Audit the value source and rendering pipeline: canonical numeric string/value → existing precision/rounding policy → locale-aware grouping → existing currency label/prefix → escaped output. **Group only display values**, never mutate persisted or calculated amounts.
2. Reuse `RegionalDisplayFormatter::number()` / `money()` and the existing JS preference formatter where they can preserve call-site decimal and symbol behavior. Add a narrowly scoped helper only if an audited call site demonstrably cannot be fixed through existing functions. A new `currency()` method and universal JS twin are **not requirements**.
3. Do not parse or reformat already localized **display strings** blindly (`648.193.716,98`, `648,193,716.98`): get the underlying canonical numeric value, then group. Avoid float conversion for large or high-precision monetary values.
4. Preserve each call site's current fractional precision and rounding behavior, including unit-price precision up to the existing four decimals, numeric-only/integer displays, negative values, zero and null placeholders. No forced two decimals; no new trimming policy.
5. Preserve currency code/symbol, existing space or no-space placement, and original number unit. For IDR displays using `Rp `, do not convert them to `Rp` purely because of the prior illustrative example. For other currencies, do not invent symbols.
6. Honor explicit `indonesian` or `international` user preferences, with PHP and JS parity for the same canonical value and effective preference. Under `system`, preserve the existing fallback contract; where its output is a confirmed grouping defect, fix that specific rendering path without globally rewriting `system` semantics.
7. Keep untouched: forms/input values (including parsing), stored DB values, payment calculations, PO/document snapshots, PDF/printed receipts, comparison `data-price` canonical attributes, exchange-rate values, Excel numeric cell types and masks, CSV/raw exported numeric values, and quantities/weights/identifiers.
8. For dynamically rendered values (AJAX, DataTables, charts/tooltips, frontend re-renders), confirm grouping remains correct after refresh and filter changes. No double-grouping when the same cell is rendered repeatedly.
9. Apply grouping only to user-visible price and monetary values in the approved bounded portal inventory. A filename match does not prove a gap; inspect the actual rendered field before adding it to the implementation diff.

**Bounded source inventory [S] from the existing read-only audit, now a triage list rather than an implementation-to-do list:**

| Category / instruction | Existing candidate surfaces (retain original inventory; verify current HEAD and individual fields at Gate 2 Stage 0) |
|---|---|
| **Formatter-routed today (inspect first)**: many already honor locale and grouping, even if `Rp ` spacing or call-site decimals differ. **Do not modify for spacing or decimals.** Change only an actual missing/wrong grouping path. | `admin/dashboard`; `ga/dashboard`; `ga/claims/{index,show}`; `finance/{dashboard,invoices/show,drp/ga,local-procurement/show,ga-claims/{index,show}}`; `local-invoices/{detail,table}`; `local-supplier/purchase-orders/show`; `purchasing/{dashboard,pr/show,po/show,quotations/show,local-vendors/invoice-show}`; `purchasing/comparison/{_inter_supplier_content,_vs_best_content}`; `quotations/_items_readonly_table`; `supplier/{po/show,quotations/show}`; DataTables `total_idr` in `Purchasing\PurchaseOrderController:103` and `Supplier\SupplierPurchaseOrderController:93` |
| **Raw / hard-coded separators (inspect first)**: check whether the output is ungrouped, incorrectly grouped, or already correct for its effective regional preference. Reuse existing PHP/JS formatter with smallest per-call-site fix; no blanket conversion. | views `finance/{overpayments/index,drp/{_paid_table_content,paid,supplier,ga,show},vouchers/show,local-procurement/{index,form}}`, `purchasing/{po/create,drp/{_paid_table_content,supplier,ga,show}}`, `local-supplier/purchase-orders/index`; controllers `LocalSupplier\InvoiceController:67-69` (JSON `formatted_*`), `Finance\FinanceDrpPaidController:167-170` (flash amounts), `PriceComparisonController::formatRupiah:1151`; JS `comparison/_scripts` (`formatAnalyticMoney`, `formatRupiah` ×2), `finance/invoices/show` `formatRupiah`, `finance/local-procurement/form` `formatRupiah`, `local-invoices/form` `rupiah()`, `components/ui/multi-select:117` |
| **Outside the requirement** (documents, fixed forms, non-price numbers) | PO PDF (`PurchaseOrderPdf`, fixed PNR261178 form, AGENTS exception); `finance/vouchers/print` (terbilang document); `ga/claims/receipt`; `local-supplier/invoices/receipt`; `receipts/partials/verified-card` (signed receipt document); Excel exports (numeric cells; only masks per GEN-03); exchange-rate values (`admin/exchange-rates`, not prices); quantities, weights, percentages, counts, chart axes; `layouts/{app,auth}` (false positive: SRI hash) |

**Mandatory gap classification for every candidate display (Stage 0):**

| State | Meaning | Required change |
|---|---|---|
| **A — Correct** | Already grouped according to effective preference, and correct decimals/prefix | **No code change**, lock with regression test only if relevant |
| **B — Missing grouping** | E.g. numeric display `648193716,98` | Fix integer-part grouping using existing formatting path |
| **C — Incorrect regional separator** | Grouped under the wrong locale, or mixed separators | Correct at the rendering boundary while preserving precision/prefix |
| **D — Not applicable** | Count, dimensions, rates, identifiers, inputs, raw Excel/CSV, fixed documents | **Out of scope** |

The repository-level inventory is a search space, **not proof that each candidate is broken**. For every B/C item, record: exact `file:line`, role/page/field, raw value and type, before output, expected output, effective regional preference, existing prefix/spacing, existing fractional precision, minimal fix, and a test. Do not rewrite the A/D rows. Keep a one-to-one list of demonstrated gaps and changed call sites.

**D-1 — RESOLVED (by direct user clarification; not pending approval):**

- Withdraw the previous proposal to supersede Phase 2B formatting precision, symbol and spacing conventions.
- Phase 2B remains authoritative except for specific *ungrouped or incorrectly grouped* price displays. Do not invert a Phase 2B golden test merely to impose `Rp70,000.00` everywhere.
- Existing tests may be adjusted **only** when they assert the precise ungrouped output that the user has now confirmed is defective, and the test change must be paired with a failing-before / passing-after regression test of that real gap. No bulk snapshot updates and no lowering of assertions.
- This clarification resolves the earlier false D-1 choice between forcing new monetary precision and preserving Phase 2B. There is no pending format-policy question for the single Gate 2 approval.

**Acceptance specification (PHP, JS and rendered surfaces):**

- [ ] Indonesian numeric input `648193716.98` (canonical) rendered as `648.193.716,98` when the surface displays two decimals; international as `648,193,716.98`.
- [ ] 0/2/4 source decimal examples preserve the same displayed fractional scale as before (no forced `.00`, no precision loss, no new trimming rule).
- [ ] Already-grouped Indonesian/international displays do not change or gain double separators.
- [ ] Existing `Rp `, `Rp`, `IDR `, and other currency labels keep their old placement and spacing on each surface.
- [ ] Negative, zero, null, 20+ digit canonical decimal strings and large prices are handled without float-based precision loss.
- [ ] PHP and JS render the same grouped number for the same canonical value, regional preference, and original display-precision policy.
- [ ] AJAX, DataTables and chart rerenders do not restore ungrouped output or duplicate grouping.
- [ ] No change to DB values, arithmetic, `data-price`, input parsing, PDF snapshots, Excel cells, CSV raw values or unrelated numeric displays.
- [ ] All Phase 2B formatting tests remain valid except specific, justified expectation updates for demonstrated grouping defects.
- [ ] Stage 0 B/C gap inventory maps precisely to the touched source files and focused regression tests; A/D sites have zero diff.

## 4. Remaining design items and per-ID readiness

| ID | WS | Final design summary | Readiness |
|---|---|---|---|
| PUR-01 | A | Preview columns: Row, Material, HS Code, Shape, Qty, Thickness, Width, OD, ID, Length, KG/Unit, Total KG, Remark (`_import.blade.php:48-54,211-219`). Values are rendered with `.text()`. Irrelevant fields show `-`. `PrItemsImport::HEADINGS` is not changed. | Ready |
| PUR-02 | A | Server: each preview row gets `total_weight = Money::multiply(weight_needed, quantity, 4)`; the summary gets `total_quantity` and `total_weight_kg`. The `PrItem` accessor is unchanged. Client: `resources/js/pr-totals-core.js` (scaled-integer half-up 4 dp), imported statically and exposed as `window.AdasiPrTotals`. Blank or <1 qty shows `—` and contributes 0, matching today's footer `max(0)`. Never `max(1)` in the live form. A per-row Total KG cell is added. An explicit `adasi:pr-items-changed` event is dispatched from all `.val()` writers and add/remove/import-apply. | Ready with conditions: JS↔PHP parity test, colspans re-aligned |
| PUR-03 | B | Company name in all 7 places + `applyVsBestKeywordFilter`, using the convention + eager `supplier:id,user_id,company_name` + SQL `COALESCE`. Remove `_supplier_pic` from the comparison header **only**. Estimated delivery stays. Historical suppliers cache key → `v2`. | Ready with conditions: sanctioned inversion of `QuotationPresentationTest::test_comparison_header_shows_pic_and_the_q8_label` (PUR-03 supersedes Decision 10 on this surface only). Query count is not increased. |
| PUR-04 | B | Inter-supplier: `col-sticky-material` → `ui-sticky-col` plus a width class, and the conflicting inline `z-index`/`!important` background is removed. A `ResizeObserver` sets `--ui-table-sticky-head-row` from row 1's height. Historical: add `sticky`, and add `ui-table-sticky`/`ui-sticky-col` in both the Blade and the JS `renderTable`/`historicalResultShellHtml`. Vs Best: DataTables `dom` puts `t` in a `<div class="ui-data-table__scroll ui-table-sticky">`, with toolbar and paging outside; the first column gets `className 'text-start ui-sticky-col'`. | Ready with conditions: Chrome bounding-rect check (vertical, horizontal, after AJAX and after DataTables draw) on light and dark themes |
| PUR-05 | B | Shared query keys: `material_name`, `shape`, `currency`. One `syncComparisonState()` helper updates the evaluation-header hidden inputs, the three tab hrefs and history via `replaceState({...state, _serverTabs:'#purchasingComparisonContainer'})`. Per-fetch `AbortController` + sequence id for materials, historical payload and Vs Best reload. Inter-supplier options gain `data-material-name`/`data-shape` and preselect from the query. A Vs Best material select is added. An invalid incoming filter is ignored with a polite `role=status` note. The `ajax()` contract is kept (RISK-11). | Ready with conditions: JS race test plus a back/forward check in the browser |
| PUR-06 | B | §3-H **targeted missing-grouping remediation** for Price Comparison and associated monetary displays; reuse existing formatter/JS preference path where possible. Preserve current prefix, fractional precision, regional choice, `data-price` and numeric contracts. | Ready with conditions: Stage 0 confirmed gaps + grouping golden cases + PHP↔JS parity |
| GEN-01 | B | §3-H bounded cross-module inventory is **triage-only**: fix B/C missing or incorrect grouping in actual price/monetary displays; leave A correctly grouped, D out-of-scope, existing currency labels and decimal precision intact. GEN-03 numeric Excel types/masks remain governed by §3-F. | Ready with conditions: **D-1 resolved** by latest user clarification; targeted B/C evidence and tests only; no Phase 2B precision override |
| PUR-07 | B | Material options keyed by (name, shape) with labels "Name (Shape)" when ambiguous. A hidden `shape` input. `historical()` accepts `shape` and resolves by (name, shape), falling back to name. Dynamic dimension options and safe reset per §3-G. Labels: Flat = Thickness, Round = Diameter (new key, field `d_outer`), Hollow unchanged. Vs Best fixes per §3-C. | Ready with conditions: §3-G server KT plus browser acceptance 1–7 |
| PUR-08 | C | §3-A + §3-E + unclaimed-NG claim targeting (index subquery `whereNotExists` material_claims withTrashed, `dataActionNeeded`, `po/show`). | Ready with conditions: zero diff on `ShipmentService`; concurrency suites with MTC on and off |
| PUR-09 | C | Only the `is_available !== false` filter. The legacy branches are characterised, not changed. "Rejected (NG) never counts as accepted" is already guaranteed (`:657-680`) and is locked by a test. "Required receiving processes" = shipment arrival confirmed + QC inspected + no open NG/claim. These are already the preconditions of `completed` (`reconcileOperationalStatus:783-818`). | Ready with conditions: characterisation before edit; D-4 states the interpretation |
| QC-01 | C | §3-B, including the legacy-shape table (no blanket 422). | Ready with conditions: payload helper for the 31 calls; null/unknown shape characterisation tests; profiling only on a restored or sanitised dataset (D-5 only if unknown values exist) |
| SUP-01 | D | §3-D. | Ready with conditions. The out-of-scope items (`index()` grouping, de-invited revision rule D-3) are recorded, not changed. |
| GEN-02 | A | §3-F canonical headings + preview order. Template button audit: all 4 import screens already have one (PR, quotation, Local PO, Local GR). | Ready with conditions: sanctioned update of `MissionFiveImportTest:118-123` (order only) |
| GEN-03 | D | §3-F: one row per PR item; Mode 1 validated with a controlled 422 and no silent nulls; Mode 2 owner/filter-scoped saved records. | Ready with conditions: the queued-format proof; the round-trip limitations as listed; the Mode 2 scope KT |

Count (all 14 IDs): **Ready 1** (PUR-01) · **Ready with explicit technical conditions 13** (PUR-02, PUR-03, PUR-04, PUR-05, PUR-06, PUR-07, PUR-08, PUR-09, QC-01, SUP-01, GEN-01, GEN-02, GEN-03) · **Blocked by missing evidence 0** · **Blocked by genuine business decision 0**. **D-1 is resolved** by the user’s direct clarification: repair missing thousands grouping only, without changing Phase 2B decimal precision, currency symbol/code or spacing. Gate 2 still requires one explicit approval for implementation.

## D. Revised performance acceptance criteria
The earlier oracle "the whole PO show page runs the same number of queries for 1 and N items" is **withdrawn**. The full page architecture has not been proven to support that bound: per-section eager loads, documents, conversations and MaterialProgress all predate this revision. The replacement is:

1. **Baseline first (Stage 0).** On the clean worktree, record `DB::getQueryLog()` counts and query texts for `purchasing.purchase-orders.show`, `supplier.purchase-orders.show`, the PO index and supplier PO index JSON, and the three comparison tabs. Use representative shapes: (1 item, 1 shipment), (5 items, 3 shipments), (25 items, 5 shipments). These numbers are the measured baseline, not an assumption.
2. **Attribute the revision's queries.** Tag the new read paths (`fulfillmentProjection`, the `has_shipment_items` `withExists`, the PUR-07 options query, company-name eager loads) by wrapping them in a test listener. Their query counts must be **constant in the number of items and shipments**. Expected: projection ≤ 3, options = 1, the rest 0 extra (folded into existing queries).
3. **No added per-item N+1.** For each measured page, `count(after, N) − count(baseline, N)` must not grow with N. It may be ≤ 0, since the projection removes `projectionForAward`'s per-award query.
4. **Fresh transactional reads are preserved.** The `ShipmentService` zero diff stays. A test asserts `ShipmentService::submitShipment` still issues its `itemFulfillmentStatus` queries inside the lock (query log between the `lockForUpdate` and commit). The projection is never called from `ShipmentService`, `QcInspectionController::store`, or any guard (grep test).
5. **Plans, not guesses.** Gate 2 records `EXPLAIN` (MySQL 8.0; MariaDB 11.4 in the CI deep job) for the Vs Best rewrite, the overdue `EXISTS`, and the options query. `PriceComparisonPerformanceRegressionTest` stays green. Gate 1 states no measured numbers.

## E. Gate 2 entry conditions
1. **Recheck HEAD.**
   - GitHub verification on 2026-10-28 found `master` at `2597a509f1296f681feac0c3eb9bd805552e866a` (PR #13 merged), after the previous `ab5ad0c` checkpoint. This is a snapshot, not a guarantee of the HEAD at Gate 2 kickoff.
   - The locally edited grouping-clarified plan copy **has not been uploaded to GitHub**; the current GitHub plan in `master` still reflects the earlier currency-precision interpretation. Synchronize this corrected document to the implementation branch **before** using repository documentation as the Gate 2 source of truth.
   - Last reported `ShipmentService.php` sha256 = `a4361cbc…be77`; re-verify against freshly fetched HEAD before making edits.
   - Gate 2 begins with `git fetch`, source/requirement reconciliation, protected-file diff checks and re-verification of every §1 citation whose file changed.
2. **Dependency patch merged; test status must still be verified.** GitHub `master` commit `2597a509` includes PR #13 and `composer.lock` pins PhpSpreadsheet `1.30.7`. The patch is no longer an unmerged prerequisite. Revalidate dependency installation, security checks and CI on the fresh Gate 2 baseline; no green CI result was independently confirmed at the time of this document update.
3. **No assumed DB access.** Claude Code Web provides no project database. The isolated MySQL test DB (`adasi_rev1027_test`, satisfying the `_test` guard) (owner additional note: if `adasi_rev1027_test` doesnt exist, use local database testing instead 'adasi_portal_testing') is created or connected **only after** Gate 2 approval, and only after confirming it is not an application database. No DB command is run during Gate 1.
   - Disclosure: during the earlier dependency task in this ephemeral container, a throwaway MySQL 8.0 instance and `adasi_portal_test` were created to run tests. That run was stopped on request, and no application data was involved.
   - Container prerequisites seen then: `php-bcmath` and a Vite build (`npm ci && npm run build`) are required before feature tests.
4. **Protected code.** `ShipmentService.php` has a zero-diff guard. `config/regional_display.php`, `AGENTS.md`, migrations and fixtures stay unchanged except the sanctioned contract-test edits listed in §4 and §3-H.
5. **Release prerequisites (separate, not entry conditions):**
   - G-PDF (PO form-owner approval, `GATE-1-MATERIAL-PROCUREMENT-REVISION-PLAN.md:587,718`);
   - T-1 (staging/production DB version confirmation, `:411,617`);
   - the staging migration and rollback rehearsal (`:940,1102`).

   None is satisfied by Gate 2 work.
6. **Single approval.** The user approves Gate 2 once for all four workstreams and the justified contract-test list. **D-1 is already resolved** by the user's latest grouping clarification; no additional formatting-policy confirmation is required.

## F. Remaining genuine business decisions
- **D-1 (RESOLVED, not pending):** User clarified that some price displays lack thousands grouping, e.g. `648193716,98` should be `648.193.716,98`. Preserve Phase 2B's existing per-call-site decimal precision, currency symbol/code, and spacing. Respect explicit `indonesian`/`international` preferences and existing `system` fallback; do not impose a universal `Rp70,000.00` presentation. Only B/C demonstrably defective grouping surfaces may change (§3-H). No additional D-1 approval is required.
- **D-2: withdrawn.** GEN-01's portal-wide scope is approved (§B). It is bounded by the §3-H inventory.
- **D-3:** may a de-invited supplier who holds a quotation still revise it? Default: unchanged (`create()` denies; the overview shows View only). This does not block SUP-01.
- **D-4 (interpretation, non-blocking):** PUR-09 "required receiving processes" = arrival confirmation + QC + claim resolution. Customs documentation (Invoice/BL/Packing List/Form E) is **not** added as a completion precondition: that would be a new requirement and could strand POs. It is stated here so that a different intent can be raised at approval.
- **D-5 (conditional):** only if Gate 2 profiling on a restored or sanitised dataset finds non-empty, unknown `pr_items.shape` values. Should they be mapped to a canonical shape? Default: no mapping; QC behaviour is unchanged for them (§3-B.7).

## 5. Integrated Gate 2 execution plan
**Environment:**
- A dedicated `git worktree` off the HEAD rechecked per §E (not `c74386f`), on the branch the user designates.
- A dedicated MySQL test DB `adasi_rev1027_test`; the name satisfies the `AppServiceProvider` `_test` guard.
- Never run two suites at once. Never use `migrate`, `db:wipe` or seeding on the app DB.

**Protected (zero diff, checked by sha256 / `git diff --exit-code`):**
- `app/Services/ShipmentService.php`
- `config/regional_display.php`
- `AGENTS.md`
- all migrations and fixtures

**Workstream file ownership** (single serial agent; shared lang files are owned by the stage that touches them):

| WS | Owns |
|---|---|
| A | `PrItemsImport`, PR views (`create/edit/_item_row/_form_totals/_import/_material_shape_script`), `resources/js/pr-totals-core.js`, `app.js` (one static import), `QuotationImportTemplateExport`, quotation preview markup |
| B | `PriceComparisonController`, `PurchaseHistoryPriceService` (name select only), comparison views/`_scripts`, `app.css` (sticky width class only), **existing** `RegionalDisplayFormatter::number/money` and regional JS preference renderers as needed; **only confirmed B/C price-display call sites** from the §3-H inventory, with protected precision/prefix behavior. No mandatory new `currency()` or JS currency framework. |
| C | `PurchaseOrder` (accessor, tally extraction, projection, availability filter), `PrItem::canonicalShape`, `QuotationItem::orderedSpecification`, `MaterialProgressService`, `QcInspectionController`, QC views, PO show views, `MaterialClaimController`, `PurchaseOrderController` (index subqueries), `SupplierPurchaseOrderController`, `PurchaseOrderExportFilters`, `PurchaseOrdersExport`, dashboards |
| D | `PurchaseRequisition` scope + action predicate, `Supplier\PurchasingRequestController` + views, `routes/web.php`, `page_registry`, `quick_access`, sidebar, `AppServiceProvider` composers, `Supplier\QuotationController@exportForm`, `QuotationFormExport`, `QuotationDefinition`, `QuotationsExport` (formats) |

**Stages and dependency gates:**
- **Stage 0 — Baseline:**
  - Record results on the clean worktree for: `TestingEnvironmentDatabaseSafetyTest`, `ShipmentSubmissionConcurrencyTest`, `OrderedQuantityShipmentConcurrencyTest`, `PurchaseOrderOperationalStatusTest`, `ShipmentDocumentsAndQcIntegrationTest`, `PoItemMaterialProgressTest`, `QcInspectionAttachmentCompensationTest`, `NotificationDeliveryTest`, `PriceComparison*Test`, `PurchaseHistoryPriceTest`, `RegionalComparisonDisplayTest`, `AdvancedExportBaselineTest`, `MissionFiveImportTest`, `QuotationPresentationTest`, `SupplierDataIsolationTest`, `HashidUrlSecurityTest`, `PageRegistryTest`, `TranslationParityTest`, `UserFacingCopyInventoryTest`, and all of `tests/js/*.test.mjs`.
  - Record the ShipmentService sha256.
  - Determine whether the touched views require copy-ledger entries. If so, extend the ledger for the new keys only; never rewrite historical snapshots.
  - Record the §D query baselines.
  - Shape profiling only if a restored or sanitised dataset is provided (§3-B).
- **Stage 1 (WS-B presentation foundation):** first classify §3-H candidate displays A/B/C/D against the current HEAD and actual effective regional preferences; fix **confirmed** B/C grouping defects using existing PHP/JS formatter paths, not a universal currency redesign. Gate: before/after evidence per touched field, zero change to A/D sites and to symbol/precision, focused PHP/JS grouped-number golden tests, and Phase 2B/`RegionalComparisonDisplayTest` green except narrowly justified actual grouping assertions.
- **Stage 2 (WS-A):**
  1. PUR-01, PUR-02.
  2. GEN-02 (gate for Stage 5 Mode 1).
- **Stage 3 (WS-B):** run in this order, since each needs the previous:
  1. PUR-03
  2. PUR-07 + Vs Best eligibility
  3. PUR-05
  4. PUR-04 (last, because it touches the same scripts)
- **Stage 4 (WS-C):**
  1. CT capture.
  2. Tally extraction (parity gate).
  3. Projection + MaterialProgress.
  4. Overdue.
  5. Claim targeting.
  6. PUR-09 filter.
  7. QC-01 + payload helper.
  8. Concurrency suites (MTC on and off).
- **Stage 5 (WS-D):** SUP-01, then GEN-03 Modes 1 and 2.
- **Final gate:**
  - full `composer test` (serial)
  - `vendor/bin/pint --test <changed files>`
  - `php artisan view:cache`
  - `php artisan route:list`
  - `npm run build`
  - `node --test tests/js/*.test.mjs`
  - `git diff --check`
  - the protected-file zero-diff check
  - `local-invoices:reconcile` and `procurement:reconcile --json` (read-only)
  - browser checks for PUR-04/05 and the QC Copy button
- Each stage is one revertible commit, made only when the user authorises commits at Gate 2.

**Final acceptance criteria:**
1. Every §1 Critical/High row is closed by its KT, NT and oracle.
2. Zero diff on protected files.
3. The §D performance criteria hold: the revision's own queries are constant, nothing is added per item against the measured baseline, `EXPLAIN` is recorded, and fresh `ShipmentService` reads are preserved.
4. The round-trip oracle passes for supported records.
5. All locked suites are green. Sanctioned changes are narrowly scoped: only **proven missing-grouping** expectations (if any; §3-H), inverting `test_comparison_header_shows_pic_and_the_q8_label` for PUR-03, updating the template-order assertion in `MissionFiveImportTest:118-123` for GEN-02, and QC-01 store payloads. **No blanket D-1 regional currency/decimal/symbol assertion changes.** Each altered expectation must have an evidence-backed reason and an accompanying regression test.
6. CI green on MySQL 8.0; the `deep` job on MariaDB 11.4.

**Rollback:** no migrations, so every stage rolls back by `git revert` plus `view:cache`. The cache keys bumped (`historical_suppliers:v2`, `vs_best_summary_unfiltered:v3`) isolate cached state in both directions.

## 6. V1 → Gemini → Final delta

| Topic | V1 (as quoted) | Gemini Rev. 2 | Final |
|---|---|---|---|
| Overdue | Query-based qty rule | Drop `!actual_arrival` everywhere | Guarded predicate preserving legacy-only semantics; SQL/PHP parity; `withExists` |
| Fulfillment | Loop `itemFulfillmentStatus` | Implicit reuse of loaded relations | Pure tally extraction + explicit read-only projection; the transactional path is byte-identical in behaviour |
| PUR-09 | Mostly fine | Rewrite 3 legacy branches | Only the `is_available` filter (a reachable bug); the others are characterised, unreachable |
| PUR-07 options (correction) | — | Material + shape only | §3-G: eligible-history dimension options per (material, shape, supplier) filling the existing range inputs; no duplicate parameter |
| GEN-01 scope (correction) | — | Comparison only (earlier final) | §3-H bounded portal inventory retained as triage; only demonstrated grouping defects fixed, D-1 supersession withdrawn |
| QC legacy shapes (correction) | — | Fail-closed 422 (earlier final) | Characterised table: null/unknown unchanged; canonical and case variants required |
| GEN-03 Mode 1 (correction) | — | Invalid → null (earlier final) | Controlled 422, the save path's item rules, one row per PR item, honest limitation list |
| Performance oracle (correction) | — | Whole-page constant (earlier final) | Measured baseline + attributed queries + no per-item growth + `EXPLAIN` |
| QC spec | PR dims | `COALESCE(offer, PR)` + global case normalisation | `COALESCE(offer, PR)` with a frozen-quotation proof; QC-only canonical shape (protects acceptance hashes); null/unknown shapes characterised and unchanged (correction pass) |
| Vs Best | + shape | Port eligibility + shape + name | Same, plus frozen price, a perf test gate and a cache key bump |
| Supplier visibility | Optional fix | Mandatory broadening of `isVisibleToSupplier`, fix `index()` | Read scope + action parity; write rule unchanged (D-3); `index()` recorded out of scope |
| Company name | 3 places | 7 places + lazy accessor | 7 places + keyword filter, eager convention, no lazy accessor; Decision 10 test inversion sanctioned |
| Sticky | Class swap | Broaden CSS to DataTables wrapper | DataTables `dom` scroller; JS re-render keeps the classes |
| Currency / price grouping | `system` = international | New config, symbols, fixed decimals | **Latest user clarification:** missing thousands grouping only. Keep existing `system` contract, currency prefix/spacing and per-field decimal precision; reuse existing formatters and correct proven gaps |
| GEN-02 | Reorder | Add `material_name` to import rows | Reorder + client-side Material lookup (anti-spoof test kept); sanctioned order-assertion update |
| GEN-03 | FormData export | Field-name, range and notes fixes | Same, plus the explicit round-trip boundary for formula-leading notes, an explicit `default:false`, and a queued-format proof |
| PR totals | `max(1)` | Blank = `—` / 0 | Same, plus BCMath server totals and a static `window.AdasiPrTotals` |

## 7. Verification performed for this document
- **Inspected** (read-only): every file and line cited in §1 and §3.
- **Not executed:** tests, builds, database queries, browser checks.
- **Inferred [I]:** the reachability of the legacy no-award PO with unavailable items; the behaviour of `WithColumnFormatting` under the queued chain; sticky rendering.
- **Correction pass, inspected read-only:**
  - `PriceComparisonController` historical/`keyDimensionRange`/`historicalMaterialsForSupplier`;
  - `_historical_content` key fieldset;
  - the quotation form row model and the save-path rules (`Supplier\QuotationController:340-392`, `create.blade.php:940-1040`);
  - `Supplier\ExportController@quotations`, `QuotationsExport::query`, `QuotationExportFilters`;
  - `RegionalDisplayFormatter::money/number`;
  - the price-display grep inventory;
  - the Phase 2B plan and the regional lock tests;
  - PR shape validation rules;
  - the QC create view shape handling.

  No tests, builds or DB commands were run in this pass.
- **Latest clarification incorporated in this edited planning copy:** the user specified an actual ungrouped price `648193716,98`. This revision edits **the supplied plan document**, not application source; no new repository inspection, tests, browser runs, Git operations or database commands are claimed. The prior candidate inventory/evidence remains source-reported and must be rechecked at Gate 2 Stage 0.

GATE 1 FINAL CANDIDATE — GROUPING CLARIFIED, PENDING SINGLE GATE 2 APPROVAL
