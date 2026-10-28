# GATE 1 — Portal Revision 2026-10-27
**FINAL CANDIDATE — RECONCILED ARCHITECTURE PLAN**
Mode: planning and verification only. Status: pending user approval for Gate 2.

## 0. Source of truth established

| Item | Evidence (cloud checkout, read-only) |
|---|---|
| Branch / HEAD | `claude/sharp-edison-h93xqe` at `c74386f` (= `master`, merge of PR #11, 2026-10-23). Working tree clean. Only one worktree. |
| Gemini archive vs HEAD | The archive Gemini cites ends at migration `2026_10_19_172830`, matching HEAD. Its line numbers matched the current source in almost every spot-check. No drift was detected. |
| Gate 1 V1 (14-ID plan) | **Unavailable in the repository.** `docs/plans/GATE-1-MATERIAL-PROCUREMENT-REVISION-PLAN.md` is the older 35-decision plan (baseline `78e8bf4`). The 14-ID requirement text and V1 claims are known only as quoted by Gemini. Wherever this document says "V1 claim", it means the claim as reported by Gemini. |
| Approved requirement text | Not in the repository. The working contract is the requirement statements as restated in Gemini §5. Any wording dispute must be settled against the user's original prompt at Gate 2 kickoff. |
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
| RISK-12 | Currency vs test locks (MED) | `currency()` | `RegionalDisplayFormatter` has no `currency()`. `'system'` returns text unchanged (`:101-104`). `data-price` regex is locked (`RegionalComparisonDisplayTest:76-87`). `QuotationDefinition` columns **all default true** (no `default:` passed). `AdvancedExportBaselineTest:97-171` locks headings and row. | Confirmed + correction | New columns must pass `default:false` explicitly. `data-price` stays frozen. Fixed 2 dp for USD unit prices would lose precision (see §3-PUR-06). Gemini's invented symbols (`US$`, `CN¥`, `JP¥`) are rejected as new requirements. | — |
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
- **Oracle:** query count on the PO index DataTables JSON for 1 vs 25 rows is identical (`DB::getQueryLog`).
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
  7. A non-canonical shape that `canonicalShape` cannot resolve fails closed: 422 "shape not recognised".
- **Invariant:** QC status is only ever chosen by the inspector. Copy never sets OK. The PO spec shown equals the PO page spec.
- **CT:** the current create/show output for a Flat exact match and an accepted different offer (12 vs 10 mm).
- **KT:** `data-val` equals `orderedSpecification()` for Flat/Round/Hollow, exact length, length range (`data-val=""`) and offer-silent fields.
- **NT:**
  - Missing, zero, negative or >999999.9999 relevant dims → 422 for both ok and ng.
  - Irrelevant dims are ignored and nulled.
  - A lowercase `'round'` fixture still requires `d_outer` and `length`.
  - Copy markup has no `name="items[*][status]"` side effect (JS test).
- **Oracle:** independent arithmetic on a fixture where PR ≠ offer, checked against `spec_acceptance_snapshot.offered`.
- **Risk:** medium. The 31 existing `qc.inspections.store` calls in 4 files must send dimensions. This is a direct consequence of the approved requirement: Gate 2 adds a `tests/Support` payload helper. Existing fixture rows are **not** modified (no lowercase shapes are rewritten).
- **Preflight (read-only):** `SELECT BINARY shape, COUNT(*) FROM pr_items GROUP BY BINARY shape` on a restored copy of production data, to size the fail-closed path.
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
- **Oracle:** query count on `purchasing.purchase-orders.show` for 1 item/1 shipment equals 5 items/3 shipments (constant).
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
- **Mode 1 Export Form:**
  - `POST supplier.quotations.export-form` (`{pr_id}`), `throttle:15,1`, `no-store`. Authorisation goes through `importableRequisition()`.
  - Iterate the DB `pr->items` in canonical order. Material and requested dimension always come from the DB.
  - Unavailable → offer numerics null, `'Not Available'`.
  - Available:
    - qty is an integer or null;
    - relevant scalar dims are 4 dp numerics and irrelevant ones are null;
    - length reads `available_length_input` (fallback `available_length`) through `DimensionRange::parse`: exact → numeric, range → text `display()`, invalid → null;
    - weight is the client value if > 0, else the server auto-calculated value via the same `resolveOfferedWeight` logic;
    - price is 4 dp;
    - notes: empty → null (sanitizer `fallback=''`), otherwise sanitised text;
    - `'Available'`.
  - `FromArray + WithHeadings + WithColumnFormatting` (`#,##0` qty, `#,##0.0000` dims/weight/price). There are no DB writes and no `ExportJob`: it is a direct download, like the import template.
  - JS: `FormData` minus file inputs, fetch to blob download. The DOM and localStorage draft are untouched.
- **Round-trip definition:** "supported records" are all rows whose notes do not begin with `= + - @` after trim. For those, re-import yields field-equal rows (numerics compared at 4 dp, ranges equal as min/max, empty notes → null, availability preserved). For notes beginning with a trigger character, the mandated sanitiser adds a leading `'`. This is a documented, bounded alteration: security policy (`app/Exports/CLAUDE.md`) takes precedence, and the import is **not** changed to strip it.
- **Mode 2:**
  - `QuotationDefinition` gains requested shape and dimensions and offered thickness/width/d_outer/d_inner, all with **explicit** `default:false`. `offered_length` already exists and stays text, because it can hold a range.
  - Number masks go through `WithColumnFormatting` on `QuotationsExport`, catalog path, XLSX only. Column letters are computed from the selected columns after `applyOptions`. `map()` values are unchanged.
  - The legacy (no-options) path is untouched. `AdvancedExportBaselineTest` stays byte-identical.
- **Invariant:** the client is never the source of material or spec. Text always passes the sanitiser. The baseline export is unchanged.
- **CT:** the current template headings and baseline export output.
- **KT:** `headings() === CANONICAL_HEADINGS`. The relative order of form, preview and template is equal. A template download → fill → `import-preview` returns 200.
- **NT:**
  - Forged `material_name` is ignored.
  - Foreign `pr_item_id` is ignored.
  - Uninvited PR → 403/404.
  - Zero `quotations` / `quotation_items` / `export_jobs` delta.
  - Formula payloads (`=1+1`, `+x`, `-x`, `@x`, `"  =x"`) are neutralised.
- **Oracle:** read the generated XLSX with `IOFactory`. Numeric cells are `TYPE_NUMERIC`, the range cell is a string, and feeding the binary to `import-preview` returns the expected row values.
- **Gate 2 condition [I]:** prove that `WithColumnFormatting` applies under the queued chunked chain (`Excel::queue` + `CloseSheet`). If not, Mode 2 formatting is dropped and reported; it is never forced through `map()` strings.
- **Rollback:** revert. Old templates keep importing.

## 4. Remaining design items and per-ID readiness

| ID | WS | Final design summary | Readiness |
|---|---|---|---|
| PUR-01 | A | Preview columns: Row, Material, HS Code, Shape, Qty, Thickness, Width, OD, ID, Length, KG/Unit, Total KG, Remark (`_import.blade.php:48-54,211-219`). Values are rendered with `.text()`. Irrelevant fields show `-`. `PrItemsImport::HEADINGS` is not changed. | Ready |
| PUR-02 | A | Server: each preview row gets `total_weight = Money::multiply(weight_needed, quantity, 4)`; the summary gets `total_quantity` and `total_weight_kg`. The `PrItem` accessor is unchanged. Client: `resources/js/pr-totals-core.js` (scaled-integer half-up 4 dp), imported statically and exposed as `window.AdasiPrTotals`. Blank or <1 qty shows `—` and contributes 0, matching today's footer `max(0)`. Never `max(1)` in the live form. A per-row Total KG cell is added. An explicit `adasi:pr-items-changed` event is dispatched from all `.val()` writers and add/remove/import-apply. | Ready with conditions: JS↔PHP parity test, colspans re-aligned |
| PUR-03 | B | Company name in all 7 places + `applyVsBestKeywordFilter`, using the convention + eager `supplier:id,user_id,company_name` + SQL `COALESCE`. Remove `_supplier_pic` from the comparison header **only**. Estimated delivery stays. Historical suppliers cache key → `v2`. | Ready with conditions: sanctioned inversion of `QuotationPresentationTest::test_comparison_header_shows_pic_and_the_q8_label` (PUR-03 supersedes Decision 10 on this surface only). Query count is not increased. |
| PUR-04 | B | Inter-supplier: `col-sticky-material` → `ui-sticky-col` plus a width class, and the conflicting inline `z-index`/`!important` background is removed. A `ResizeObserver` sets `--ui-table-sticky-head-row` from row 1's height. Historical: add `sticky`, and add `ui-table-sticky`/`ui-sticky-col` in both the Blade and the JS `renderTable`/`historicalResultShellHtml`. Vs Best: DataTables `dom` puts `t` in a `<div class="ui-data-table__scroll ui-table-sticky">`, with toolbar and paging outside; the first column gets `className 'text-start ui-sticky-col'`. | Ready with conditions: Chrome bounding-rect check (vertical, horizontal, after AJAX and after DataTables draw) on light and dark themes |
| PUR-05 | B | Shared query keys: `material_name`, `shape`, `currency`. One `syncComparisonState()` helper updates the evaluation-header hidden inputs, the three tab hrefs and history via `replaceState({...state, _serverTabs:'#purchasingComparisonContainer'})`. Per-fetch `AbortController` + sequence id for materials, historical payload and Vs Best reload. Inter-supplier options gain `data-material-name`/`data-shape` and preselect from the query. A Vs Best material select is added. An invalid incoming filter is ignored with a polite `role=status` note. The `ajax()` contract is kept (RISK-11). | Ready with conditions: JS race test plus a back/forward check in the browser |
| PUR-06 | B | `RegionalDisplayFormatter::currency(amount, code, scale)`, built with BCMath `Money::normalize`, international grouping, then `number()`. Output: `Rp70,000.00` under `system`/`international`, `Rp70.000,00` under an explicit `indonesian` choice. Non-IDR keeps the existing ISO-code prefix (`USD 3.4512`); no new symbols. Scale is 2 for amounts and 2–4 for unit prices (precision is never dropped). JS twin on `AdasiPreferences`. `data-price` is frozen. Applies to comparison surfaces, charts and summaries. | Ready with conditions. **[B] D-1** (below) has a safe default. |
| GEN-01 | B | The same formatter, applied to the comparison module and to the Excel masks for quotation exports (GEN-03). Other modules are **not** retrofitted. | Blocked by genuine business decision **D-2** (portal-wide scope) for anything beyond those surfaces. The listed surfaces are ready. |
| PUR-07 | B | Material options keyed by (name, shape) with labels "Name (Shape)" when ambiguous. A hidden `shape` input. `historical()` accepts `shape` and resolves by (name, shape), falling back to name. Changing material, shape or supplier resets `key_min`/`key_max`. Labels: Flat = Thickness, Round = Diameter (new key), Hollow = Outer Diameter. Key field per `MaterialReferencePrice::DIMENSION_FIELD_BY_SHAPE`. Vs Best fixes per §3-C. | Ready with conditions: new translation key pair |
| PUR-08 | C | §3-A + §3-E + unclaimed-NG claim targeting (index subquery `whereNotExists` material_claims withTrashed, `dataActionNeeded`, `po/show`). | Ready with conditions: zero diff on `ShipmentService`; concurrency suites with MTC on and off |
| PUR-09 | C | Only the `is_available !== false` filter. The legacy branches are characterised, not changed. "Rejected (NG) never counts as accepted" is already guaranteed (`:657-680`) and is locked by a test. | Ready with conditions: characterisation before edit |
| QC-01 | C | §3-B. | Ready with conditions: payload helper for 31 calls; read-only production shape probe |
| SUP-01 | D | §3-D. | Ready with conditions. The out-of-scope items (`index()` grouping, de-invited revision rule D-3) are recorded, not changed. |
| GEN-02 | A | §3-F canonical headings + preview order. Template button audit: all 4 import screens already have one (PR, quotation, Local PO, Local GR). | Ready with conditions: sanctioned update of `MissionFiveImportTest:118-123` (order only) |
| GEN-03 | D | §3-F Mode 1 + Mode 2. | Ready with conditions: the queued-format proof; the documented apostrophe exception |

Count: Ready 1 · Ready with conditions 12 · Blocked by business decision 1 (GEN-01, beyond the defined surfaces only) · Blocked by missing evidence 0.

### Genuine business decisions (safest behaviour proposed, not approved)
- **D-1:** must `Rp70,000.00` override a user's explicit `indonesian` number preference? Default: no, the preference is respected (consistent with AGENTS.md per-user regional format).
- **D-2:** GEN-01 scope beyond Price Comparison and quotation exports. Default: limited to those surfaces.
- **D-3:** may a de-invited supplier who holds a quotation still revise it? Default: unchanged (`create()` denies; the overview shows View only).

## 5. Integrated Gate 2 execution plan
**Environment:**
- A dedicated `git worktree` off `c74386f` on the branch the user designates.
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
| B | `PriceComparisonController`, `PurchaseHistoryPriceService` (name select only), comparison views/`_scripts`, `app.css` (sticky width class only), `RegionalDisplayFormatter::currency`, `preferences` JS twin |
| C | `PurchaseOrder` (accessor, tally extraction, projection, availability filter), `PrItem::canonicalShape`, `QuotationItem::orderedSpecification`, `MaterialProgressService`, `QcInspectionController`, QC views, PO show views, `MaterialClaimController`, `PurchaseOrderController` (index subqueries), `SupplierPurchaseOrderController`, `PurchaseOrderExportFilters`, `PurchaseOrdersExport`, dashboards |
| D | `PurchaseRequisition` scope + action predicate, `Supplier\PurchasingRequestController` + views, `routes/web.php`, `page_registry`, `quick_access`, sidebar, `AppServiceProvider` composers, `Supplier\QuotationController@exportForm`, `QuotationFormExport`, `QuotationDefinition`, `QuotationsExport` (formats) |

**Stages and dependency gates:**
- **Stage 0 — Baseline:**
  - Record results on the clean worktree for: `TestingEnvironmentDatabaseSafetyTest`, `ShipmentSubmissionConcurrencyTest`, `OrderedQuantityShipmentConcurrencyTest`, `PurchaseOrderOperationalStatusTest`, `ShipmentDocumentsAndQcIntegrationTest`, `PoItemMaterialProgressTest`, `QcInspectionAttachmentCompensationTest`, `NotificationDeliveryTest`, `PriceComparison*Test`, `PurchaseHistoryPriceTest`, `RegionalComparisonDisplayTest`, `AdvancedExportBaselineTest`, `MissionFiveImportTest`, `QuotationPresentationTest`, `SupplierDataIsolationTest`, `HashidUrlSecurityTest`, `PageRegistryTest`, `TranslationParityTest`, `UserFacingCopyInventoryTest`, and all of `tests/js/*.test.mjs`.
  - Record the ShipmentService sha256.
  - Determine whether the touched views require copy-ledger entries. If so, extend the ledger for the new keys only; never rewrite historical snapshots.
  - Run the read-only shape probe.
- **Stage 1 (WS-B foundation):** PUR-06 formatter + JS twin. Gate: unit/JS tests and `RegionalComparisonDisplayTest` green.
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
3. Query-count oracles are constant (PO index, PO show, comparison).
4. The round-trip oracle passes for supported records.
5. All locked suites are green, apart from the three sanctioned contract-test edits, which are listed in the PR body: inverting `test_comparison_header_shows_pic_and_the_q8_label`, the template-order assertion in `MissionFiveImportTest:118-123`, and the QC-01 store payloads.
6. CI green on MySQL 8.0; the `deep` job on MariaDB 11.4.

**Rollback:** no migrations, so every stage rolls back by `git revert` plus `view:cache`. The cache keys bumped (`historical_suppliers:v2`, `vs_best_summary_unfiltered:v3`) isolate cached state in both directions.

## 6. V1 → Gemini → Final delta

| Topic | V1 (as quoted) | Gemini Rev. 2 | Final |
|---|---|---|---|
| Overdue | Query-based qty rule | Drop `!actual_arrival` everywhere | Guarded predicate preserving legacy-only semantics; SQL/PHP parity; `withExists` |
| Fulfillment | Loop `itemFulfillmentStatus` | Implicit reuse of loaded relations | Pure tally extraction + explicit read-only projection; the transactional path is byte-identical in behaviour |
| PUR-09 | Mostly fine | Rewrite 3 legacy branches | Only the `is_available` filter (a reachable bug); the others are characterised, unreachable |
| QC spec | PR dims | `COALESCE(offer, PR)` + global case normalisation | `COALESCE(offer, PR)` with a frozen-quotation proof; QC-only canonical shape (protects acceptance hashes); fail closed on unknown shape |
| Vs Best | + shape | Port eligibility + shape + name | Same, plus frozen price, a perf test gate and a cache key bump |
| Supplier visibility | Optional fix | Mandatory broadening of `isVisibleToSupplier`, fix `index()` | Read scope + action parity; write rule unchanged (D-3); `index()` recorded out of scope |
| Company name | 3 places | 7 places + lazy accessor | 7 places + keyword filter, eager convention, no lazy accessor; Decision 10 test inversion sanctioned |
| Sticky | Class swap | Broaden CSS to DataTables wrapper | DataTables `dom` scroller; JS re-render keeps the classes |
| Currency | `system` = international | New config, new symbols, fixed decimals | Preference-respecting IDR format; ISO prefix for others; unit-price precision kept; no new config file |
| GEN-02 | Reorder | Add `material_name` to import rows | Reorder + client-side Material lookup (anti-spoof test kept); sanctioned order-assertion update |
| GEN-03 | FormData export | Field-name, range and notes fixes | Same, plus the explicit round-trip boundary for formula-leading notes, an explicit `default:false`, and a queued-format proof |
| PR totals | `max(1)` | Blank = `—` / 0 | Same, plus BCMath server totals and a static `window.AdasiPrTotals` |

## 7. Verification performed for this document
- **Inspected** (read-only): every file and line cited in §1 and §3.
- **Not executed:** tests, builds, database queries, browser checks.
- **Inferred [I]:** the reachability of the legacy no-award PO with unavailable items; the behaviour of `WithColumnFormatting` under the queued chain; sticky rendering.

GATE 1 FINAL ARCHITECTURE RECONCILIATION COMPLETE — PENDING USER APPROVAL FOR GATE 2.
