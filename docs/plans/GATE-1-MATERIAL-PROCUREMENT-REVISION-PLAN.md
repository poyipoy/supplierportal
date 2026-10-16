# GATE 1 — ADASI Supplier Portal Material Procurement Revision

**Document type:** Planning only (Gate 1). **Revision:** 2 (final): approved business decisions Q1–Q9 integrated (14 October 2026). **Final consistency checks applied:**
1. durable `delete_refused` audit outside rolled-back transactions (§13, I-16, M06A);
2. Mission 04 rollback safety — two-step M04-R/M04-W delivery, rollback gate, forward-fix, kill switch (§15 M04, §16, §20, I-17, R-21);
3. dynamic evaluation date from `BusinessTime::today()` (Asia/Jakarta), with literal dates marked as examples (§12.1, §14, AC-39);
4. T-6 kept unresolved with fail-closed MTC behavior for `arrived` (§13, M05, AC-34, R-19, T-6).

**Deployment-sequencing fixes:**

5. the Q1 logistics guard and PO logistics snapshot ship in **R1** together with PR logistics, so there is no window in which a PO can be created with conflicting or silently omitted logistics (§15 M02/M04, §16, §20, I-18, AC-42);
6. POs finalized before Reference Price activation have an explicit "not captured" state; they are never backfilled and never retroactively priced (§12.2, M06B, I-19, AC-41);
7. the `po_generation_enabled` kill switch moves to **M01/R1**, enforced at the single PO choke point. It stays operational after any M04-W rollback, and all three entry points are tested (§15 M01/M04, §16, §20, I-20, AC-40).

**Final safety corrections:**

8. after an M04-W rollback, an unassigned award that already carries a selected `ordered_qty`/spec acceptance is refused (fail-closed) — never ordered at the offer quantity, never partially honored. Re-pointing clears stale selections (§15 M04, I-21, AC-43, R-26);
9. M04-W is runtime-gated (`ordered_quantity_write_enabled`). Its rollback is a tested configuration switch. A code revert of R2b is forbidden once R4/R5 are deployed, except as a rehearsed coordinated rollback (§15 M04/M06B/M07, §16, §20, I-22, AC-44, R-27).

The 35 decisions and Q1–Q9 are unchanged.
**Repository:** `poyipoy/supplierportal` · **Base:** `master` · **Working branch:** `feature/material-procurement-revision-gate2` (the `gate2` suffix is historical and does **not** authorize implementation).
**Evidence baseline:** commit `78e8bf4` (HEAD = `master` = `origin/master` = `origin/feature/material-procurement-revision-gate2`).
**Confidence vocabulary:**
- *Verified-by-reading*: source read in the planning sessions.
- *Inferred*: consistent with read code, but not executed.
- *Unverified*: not inspected.

No statement in this document was verified by executing tests or by querying or altering any database.

---

## 1. Executive Summary

The ADASI Import-procurement core already has a mature award → PO → shipment pipeline: row locks with deterministic lock ordering, document exchange-rate snapshots, and private polymorphic attachments. The 35 locked decisions extend it. **All nine follow-up business questions (Q1–Q9) are now approved** and are integrated as locked requirements throughout this plan (decision log: §22).

Four structural gaps must close before UI work is safe:

1. **No persisted ordered quantity.** Every consumer derives "ordered qty" from the Supplier's offered `available_qty` (`QuotationItem::fulfillment_quantity`, `app/Models/QuotationItem.php:233`). Decision 01/02/03/07 + **Q2** require a Purchasing-selected `pr_item_awards.ordered_qty`. The default is `min(requested, offered)` and the value is editable within `1..offered`. It must be consumed through **one resolver** by fulfillment, totals SQL, PDF, exports and progress.
2. **No specification-acceptance record.** Differences are detected (`QuotationItem::getAvailabilityComparisonAttribute`, `:455`) but never accepted or audited. Acceptance and **Q1** logistics uniformity are enforced inside `PurchaseOrderGenerationService::generateFromAwards()` (`:63`), the single choke point for all three live PO paths.
3. **Exchange rates are conflated, and missing rates are fabricated.**
   - Analytics use document snapshot rates with `COALESCE(...,1)` (`PriceComparisonController.php:625,627,1256`).
   - The PO list total uses `COALESCE(er.rate_to_idr, 1)` (`PurchaseOrder.php:187`).
   - Five export paths use `rate ?? 0`.

   **Q4** separates the two purposes. *Analytical* comparisons use the Exchange Rate Benchmark at the automatic business-today evaluation date (**Q9**). *Transactional* valuations use immutable document snapshots. A missing foreign rate shows `—` / `Exchange Rate Unavailable` and never becomes 1 or 0. An `AGENTS.md` amendment is planned for Mission 07; it is **not** made in Gate 1.
4. **New capabilities:**
   - Master Reference Price (Master Harga), with no PR-dimension fallback (**Q6A**);
   - Port/Incoterms on PR → PO detail, exports and **PDF** (**Q7**);
   - Shipment-level MTC, append-only after submit (**Q6B**);
   - Purchasing access to the Material/HS Code masters, with **guarded hard delete** (**Q3**).

Coverage of the 35 decisions (unchanged by Q1–Q9; they refine *how*, not *whether*): **0 EXISTING · 11 PARTIAL · 17 MISSING · 7 CONFLICT · 0 UNVERIFIED** (§7).

Delivery: **Missions 01, 02, 03, 04, 05, 06A, 06B, 07, 08**, executed sequentially against one test database.
- All migrations are additive and nullable. There is no mandatory backfill.
- Legacy POs keep their original totals through an explicit fallback chain.

Remaining items are **technical verifications and one external approval** (§22.2). The external approval is the PO form owner's sign-off for the PDF layout change.

---

## 2. Project and Git Baseline

| Item | Observed (verified-by-reading, re-checked for Rev. 2) |
|---|---|
| Remote | `origin https://github.com/poyipoy/supplierportal.git` |
| Current branch | `feature/material-procurement-revision-gate2` |
| Base | `master`; merge-base = `78e8bf4` = HEAD; remote branch exists and equals HEAD |
| Uncommitted user changes (preserved; not touched by planning) | `app/Services/Auth/KnownDeviceService.php` (**new since Rev. 1**), `lang/{en,id}/finance.php`, `resources/views/finance/vouchers/print.blade.php`, `resources/views/purchasing/comparison/{historical,inter-supplier,vs-best}.blade.php` (adds `tw-min-w-0 tw-max-w-full` to `#purchasingComparisonContent`), `tests/Feature/PriceComparisonServerTabsTest.php`, `tests/Feature/RegionalScopeProtectionTest.php` |
| Untracked (preserved) | `GATE-1-PORTAL-SUPPLIER-MATERIAL-PROCUREMENT-REVISION-PLAN.md`, `Sarapan Algi.docx`, `Screenshot_Infor-LN.jpeg`, `docs/results/USER-ACCOUNTS-FINANCE-GA-LOCAL-20261012.sql` |
| Stack | PHP ^8.2, Laravel ^12, MySQL (Laragon local **8.0.30**), Blade + Bootstrap 5 compat + `tw-` Tailwind, jQuery/Alpine, yajra DataTables, Laravel Excel, dompdf |
| Test DB | `phpunit.xml` + `.env.testing` → MySQL `adasi_portal_test`; the `AppServiceProvider` guard refuses non-`_test` DBs in testing; no cached config |

Every mission must start by re-checking `git status`. The Price Comparison edits overlap Mission 07 and must be committed or explicitly acknowledged by the user first (§16). `KnownDeviceService.php` is outside the scope of every mission.

---

## 3. Reference Documents and Scope

| Ref | Source | Notes |
|---|---|---|
| A | `GATE-1-PORTAL-SUPPLIER-MATERIAL-PROCUREMENT-REVISION-PLAN.md` | Interview plan (Indonesian). §3 file claims re-verified at HEAD. |
| B | `Sarapan Algi.docx` (text read-only from `word/document.xml`) | Original stakeholder list. |
| C | `Screenshot_Infor-LN.jpeg` | Layout reference (label-left / input-right) **only** for Port of Discharge and Incoterms. |
| D | User approval of Q1–Q9 (Rev. 2 instruction) | Locked; recorded in §22.1. |
| — | `AGENTS.md`, `claudes-cognitive-framework-for-laravel-development.md`, `CLAUDE.md` | Project rules / method. `context.md` not required (Local core). |

**Documented discrepancies (not silently resolved):**
- Ref B Supplier #1 asks for a "notif … (di line)". This is implemented as per-line indicators; no new notification type is added.
- Ref B "thickness, diameter" is narrowed by Decision 18 to Outer Diameter.
- Ref A proposed a quotation-level logistics snapshot. It is dropped because PRs are immutable after submit (§5.1).
- `AGENTS.md` "Price Comparison uses snapshot rates" vs Decision 34 + Q4 → planned amendment (Appendix A).

**Out of scope:**
- the Local Supplier core;
- the Supplier-side price history (`SupplierPriceHistoryBuilder`);
- a new PO approval state;
- migration or deletion of historical quotation MTC files;
- a manual evaluation-date picker (Q9);
- human-readable Excel header aliases (Q5);
- automatic PO splitting (Q1);
- any other Infor LN fields.

---

## 4. ECC Skills Used and Availability

| Skill | Status | Use | Limitation |
|---|---|---|---|
| `/ecc:plan` (`~/.claude/plugins/cache/ecc/ecc/2.2.1/commands/plan.md`) | Installed, read | Restatement, pattern grounding, phased plan, wait-for-confirm | Not invoked as a workflow (PRD mode writes `.claude/plans/*`) |
| `/ecc:blueprint` | Installed, read | Cold-start mission briefs, dependency graph, adversarial review, STOP gates | Not invoked (writes `plans/`, memory, sub-agents) |
| `/ecc:database-migrations` | Installed, read | Additive, nullable, schema/data separation, guarded `down()` | PostgreSQL `CONCURRENTLY` advice not applied (MySQL) |
| `/ecc:mysql-patterns` | Installed, read | Lock ordering, `DECIMAL`, `EXPLAIN` before indexing, no `SKIP LOCKED` on integrity paths | — |
| `/ecc:laravel-patterns`, `/ecc:laravel-security`, `/ecc:laravel-tdd` | Installed, outline read | Applied through repo conventions (`AGENTS.md` wins) | Generic API/Sanctum content not applicable |
| `design-system`, `accessibility`, `make-interfaces-feel-better`, `search-first`, `/boost` | Installed | Not used (UI rules come from `AGENTS.md` + Phase 2 contract) | — |

---

## 5. Repository Architecture Findings

All references were verified by reading at `78e8bf4`. Rev. 2 additions are marked ➕.

### 5.1 Purchase Requisition
- **Model:** `app/Models/PurchaseRequisition.php`. Fillable `period_id, created_by, pr_number, notes, status`; soft deletes.
- **Three submit paths** in `Purchasing/PurchaseRequisitionController.php`:
  - `store()` (`:270`);
  - `update()` (`:403`);
  - `submitDraft()` (`:233`), a list quick-action that posts **no form fields**.

  All three pass through `PurchaseRequisitionItemSynchronizer` (`reprocessForSubmission()` `:88` / `sync(..., true)`).
- **FormRequest:** `SavePurchaseRequisitionRequest.php` requires an active `material_master_id` on every item.
- **PRs are immutable after submit.** PR edit, update and destroy, and `PrItemController` store/update/destroy, all require `draft|rejected`. No code sets a PR to `rejected`.
- **Excel import:** `PrItemsImport.php` is preview-only. Shape-irrelevant dimensions are nulled with a **warning** (`:132-145`); row errors return HTTP 200 with partial `rows`. The client gate is already all-or-nothing (`purchasing/pr/_import.blade.php:224-225`).
- **Template:** `PrImportTemplateExport.php:26-39` → `material_name, shape, quantity, thickness, d_inner, d_outer, width, length, remark`. Parsing is heading-based (`WithHeadingRow`).
- **Totals:** a "Total requested weight" metric card on PR show (`show.blade.php:110`); no footer Qty/KG row.

### 5.2 Supplier Quotation
- **`Supplier/QuotationController::store()` (`:303`):**
  - quantity ceiling `:447` (validator) and `:711` (in-transaction);
  - hollow rule;
  - server-authoritative amounts `:722-731`;
  - items deleted and recreated on every save (`:664`);
  - save refused once any award/PO exists (`:565-569`);
  - rate snapshot via `latestRate()` at submit (`:586`).
- **Per-item MTC upload:** `items.*.mtc_file` (`:370`), `storeMtcAttachment` (`:1027`, profile `'mtc'`), keep/clone/replace (`:749-784`).
- **`QuotationItemsImport.php`:** ceiling `:181`; irrelevant dimensions → warning `:252-262`.
- **Template `QuotationImportTemplateExport.php`:**
  - `pr_item_id, material_name, requested_dimension, price_per_kg, available_qty, available_thickness, available_d_inner, available_d_outer, available_width, available_length, notes, availability, offered_weight_per_unit`;
  - the importer additionally accepts existing aliases `price_kg`, `offer_weight_kg`, `offer_weight_per_kg`.
- **Views:**
  - `supplier/quotations/create.blade.php` (2,773 lines; `data-async-submit` `:683`): Procurement Context `:689` → Material & Commercial Offer `:716` → Commercial Terms & Logistics `:1173`; sticky CSS `:65-151`; footer shows amount only `:1158`.
  - `supplier/quotations/show.blade.php`: table `:37` → Quotation Parameters `:188` → Status and Follow-up `:238`.
- **No quotation autosave exists** (verified by grep).
- ➕ **Every label naming `quotations.estimated_delivery`** (Q8 target set):

  | Key | Where it renders |
  |---|---|
  | `supplier.copy.supplier_estimated_ready_dispatch_date` | `supplier/quotations/create:1184`, `show:207` |
  | `purchasing.copy.supplier_estimated_ready_dispatch_date` | `purchasing/quotations/show:72` |
  | `purchasing.copy.supplier_original_estimated_ready_dispatch_date` | title on `_inter_supplier_content:230` and `purchasing/po/show:457` (value `original_supplier_ready_date` = `$award->quotation?->estimated_delivery`, `MaterialProgressService.php:132`) |
  | `purchasing.copy.ready_dispatch` ("Ready / Dispatch:") | `_inter_supplier_content:231` |
  | `purchasing.copy.supplier_ready_dispatch_date` + `when_supplier_expects_material_ready_to_send` | `_inter_supplier_content:389` |
  | `purchasing.copy.po_target_arrival_date_is_earlier_than_the_selected_supplier_s_estimated_ready_dispatch_date_review` | `_inter_supplier_content:394` |
  | `exports.closure.estimated_delivery` ("Estimated Delivery") | heading in `QuotationDetailExport.php:107` |

  **Not** in scope: "Current Estimated Ready" / "Estimated Ready" (`po_item_progress_updates.estimated_ready_date`, a different field).

### 5.3 Award and Purchase Order
- **`pr_item_awards`:** `pr_item_id` UNIQUE, nullable `purchase_order_id`. No ordered quantity and no acceptance fields. Updated in place while unassigned (`PrItemAwardService::awardItem` `:22`, `awardBatch` `:142`). The existing guard refuses re-award once `purchase_order_id` is set.
- **`PurchaseOrderGenerationService::generateFromAwards()` (`:63`)** is the only PO creator:
  - groups by supplier;
  - single currency per group;
  - enforces quotation → one PO (`:181-189`; UNIQUE `po_quotations.quotation_id`);
  - takes the rate snapshot (`:175-179`);
  - creates the PO as **`active` immediately** (`:201`) — the PO-finalization event.
- **Live PO entry points:**
  1. `PriceComparisonController::saveItemAwards()` `:1383`;
  2. `QuotationListController::generatePo()` `:141` (auto-awards every available line);
  3. `AwardConsolidationController::store()` `:55` (cross-PR, single supplier).

  `PurchaseOrderController::create/store` are retired (`:187`, `:198`).
- **Ordered-quantity / amount consumers** (all derive from the offer today):

  | Consumer | Location |
  |---|---|
  | `itemFulfillmentStatus()` | `PurchaseOrder.php:519` (`:522`) |
  | `total_ordered_weight` / `total_ordered_quantity` | `PurchaseOrder.php:401`, `:412` |
  | `isFullyFulfilledAndInspected` | `:639` |
  | `delivery_progress` | `:419` |
  | `scopeWithResolvedTotalIdr` | `:148` (`COALESCE(er.rate_to_idr,1)` `:187`) |
  | `ShipmentService` | `:44`, `:423` |
  | `MaterialProgressService` | `:31,83,193` |
  | `PurchaseOrderPdf` | `:46,65-70,168` |
  | `PurchaseOrdersExport` | `:129-141` |
  | `PurchaseOrderDetailExport` | `:64-135,196` |
  | `Advanced/Definitions/PurchaseOrderDefinition` | `:54-69` |
  | Views | `purchasing/po/{show,consolidate-awards}`, `supplier/po/show`, `_inter_supplier_content`, `purchasing/quotations/show` |

### 5.4 Shipment and MTC
- **Tables:** `shipments` (`draft|submitted|arrived|cancelled`); `shipment_items` (CHECK qty>0, weight>0); `shipment_documents` (exactly four `doc_type` values, UNIQUE `(shipment_id, doc_type)`). MTC cannot be a fifth single-row type.
- **`Shipment::attachments()` (`Shipment.php:85`)** is unused, so it is free for MTC.
- **`ShipmentService::submitShipment()` (`:273`)** is the single Draft→Submitted transition for `store/update` with `action=submit` (`SupplierShipmentController:264`), `submit()` (`:348`) and the list quick-submit. It locks the shipment row.
- **`ShipmentService::uploadDocument()` (`:593`)** is the upload pattern: `FileInspectionService::storeUpload()`, transaction, cleanup and inspection `ERROR` on failure.
- **`confirmArrival()` (`:523`)** locks the shipment row.
- **`AttachmentPolicy`** has no `Shipment::class` arm for Suppliers.
- **The `'mtc'` profile** (pdf/jpg/png, 5 MB) exists in `config/native_file_security.php:19`. A valid upload yields inspection status `VALIDATED`.

### 5.5 Price Comparison, History, Exchange Rates
- **`PriceComparisonController.php` (1,512 lines):**
  - `interSupplier` `:42` uses snapshot IDR;
  - `historical` `:307` requires a supplier, filters `material_name`, and filters exactly on all five **PR** dimensions (`:362-367`, `:1052`);
  - yearly view uses unweighted `AVG` with `COALESCE(...,1)` (`:1256`);
  - `vsBest` `:622` uses `COALESCE(...,1)` (`:625,627`).
- **`ExchangeRate::latestRate()` (`:101`)** = latest effective as of business today, `id` tie-break, 60-minute cache. There is no as-of resolver.
- **`updateKurs` stores `valid_from => now()`** — a UTC instant written into a `date` column — in both `Purchasing\PurchasingController:160` and `Admin\AdminController:50`. This violates the BusinessTime invariant.
- ➕ **Fabricated-zero snapshot fallbacks** in transactional outputs, all `rate_to_idr ?? 0`:
  - `PurchaseOrdersExport.php:139`;
  - `Advanced/Definitions/PurchaseOrderDefinition.php:69`;
  - `Advanced/Definitions/QuotationDefinition.php:54-55`;
  - `QuotationDetailExport.php:41`;
  - `QuotationsExport.php:123`.

  `resources/views/purchasing/po/create.blade.php:89,231` also uses `?? 0`. That view belongs to the retired `create` path (*inferred* unreachable; to be confirmed in M04).

### 5.6 Master Material and HS Code
- **Routes:** admin-only (`routes/web.php:434-443`): data, store, update, status. **No delete route for any role.**
- **FormRequests:** `SaveMaterialMasterRequest:14` and `SaveHsCodeRuleRequest:18` hard-code `isAdmin()`. No policies exist.
- ➕ **References, exhaustive for FKs** (grep of all migrations):

  | Child | FK | On delete |
  |---|---|---|
  | `material_aliases.material_master_id` | → `material_masters` | `restrictOnDelete` |
  | `pr_items.material_master_id` | → `material_masters` | `restrictOnDelete` |
  | `pr_items.hs_code_rule_id` | → `hs_code_rules` | `restrictOnDelete` |

  The planned `material_reference_prices.material_master_id` will also restrict.
- ➕ **Logical dependencies:**
  - HS rules resolve by `material_category`/`shape`, not by material ID. Deleting a rule changes **future** resolution only.
  - The `MaterialHsCodeMasterSeeder` (and the dummy seeder) can re-create seeded rows if re-run.
  - No other application code stores these IDs (grep across `app/`).
- ➕ **Audit:** there is no master-data audit table; only `created_by/updated_by`. The established repo pattern is **domain-specific append-only audit tables** (`auth_audit_logs`, `local_finance_audit_logs`, `supplier_registration_audits`).

### 5.7 UI / Design System
- **Shared component:** `x-ui.data-table` (scroll wrapper `tw-overflow-x-auto`).
- **Sticky headers:** page-local sticky CSS in quotation create and the PR form uses wrappers with `overflow-y:hidden` and no bounded height, so `thead` sticky cannot work during page scroll (*inferred from CSS*).
- **Requirements:** `$regionalFormatter`; EN/ID translation parity; Vite build.

### 5.8 ➕ PO PDF (`app/Support/PurchaseOrderPdf.php` + `resources/views/pdf/po-pdf.blade.php`)
- **Form identity:** a fixed English "PNR261178" form. Header geometry is absolute (mm):
  - supplier block `top:31.8 left:0 width:112`;
  - **`.dates` key/value table `top:40.5 right:0 width:55.5`** with three rows (Purchase Order Date, Planned Receipt Date, Currency) at `line-height 4.1` → ends ≈ 52.8 mm;
  - items table top is computed as `max(79.5, 44 + supplierLines×4.1 + 8) − 10` → ≥ 69.5 mm.
- **Space for two more rows:** two rows (Port of Discharge, Incoterms) would end ≈ 61.0 mm. That is still above the items area, and there is no horizontal overlap with the 112 mm supplier column (*inferred from geometry*; visual verification required).
- **Unchanged dependencies:** totals use `$items->sum('resolved_amount')` (`:168`).

---

## 6. Complete 35-Decision Traceability Matrix

**Status definitions.**
- **EXISTING:** implemented as approved.
- **PARTIAL:** foundation exists.
- **MISSING:** absent.
- **CONFLICT:** current code (and usually a test) enforces behavior the decision reverses.

Q1–Q9 refine implementation only; **no status changed**. Impact columns: B/U/D/S = Backend/UI/DB/Security (H/M/L/–).

| ID | Objective | Status | Evidence (verified) | Required change | Approved Q | B | U | D | S | Compat risk | Acceptance (observable) | Mission |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 01 | Offer may exceed PR; order 1..offered | **CONFLICT** | Ceiling `QuotationController:447,711`, `QuotationItemsImport:181`; tests `QuotationAvailabilityTest:646`, `MissionFiveImportTest:511` | Remove ceiling (keep ≥1); `ordered_qty` on award; server bound check | Q2 | H | M | M | M | M | Offer 15 vs PR 10 saved; 16 → 422; 12 accepted | 01, 03, 04 |
| 02 | PR qty preserved; PO qty separate; Δ; audit | PARTIAL | PR immutable post-submit; no PO qty; awards mutable w/o history | Persist `ordered_qty`; `pr_item_award_events`; show Req/Offered/Ordered/Δ | Q2 | M | M | M | L | L | PR qty unchanged; Δ shown; event per change | 01, 04 |
| 03 | Remainder never auto-ordered; no reuse; shipment ≤ PO qty | PARTIAL | UNIQUE `po_quotations.quotation_id`; shipment basis = offered (`PurchaseOrder:522`) | Fulfillment basis = resolved ordered qty | Q2 | H | L | – | L | M | 12 of 15: 7+5 OK, +1 rejected | 01, 04 |
| 04 | Diff flags, explicit acceptance, audit, invalid geometry rejected | PARTIAL | Detection `QuotationItem:455`; hollow rule; no acceptance | Acceptance + hash on award, enforced in `generateFromAwards` | — | H | M | M | M | L | Unaccepted "Different" blocks PO on all paths | 01, 04 |
| 05 | Excel all-or-nothing; row/col/value errors; reject shape-incompatible | **CONFLICT** | Server partial rows/200; irrelevant dims → warnings (`PrItemsImport:132-145`, `QuotationItemsImport:252-262`); `MissionFiveImportTest:168` pins drop | Irrelevant dimension → error; any error → `rows:[]`, 422 | Q5 (headers unchanged) | M | L | – | L | M | Flat+OD → 0 rows, error with value | 02, 03 |
| 06 | Footer totals Qty/KG/Price, backend authoritative | PARTIAL | Amount-only footers; PR none | Backend totals; JS preview only | — | M | M | – | – | L | Footer = Σ server lines | 02, 03 |
| 07 | KG per stage | PARTIAL | PO uses offer KG (`PurchaseOrder:401`) | Frozen `ordered_total_weight_kg` + resolver | Q2 | M | M | M | – | M | PO KG = ordered × offered kg/unit | 01, 04 |
| 08 | Template order Thickness, Width, OD, ID, Length | **CONFLICT** | `PrImportTemplateExport:26-39`; `MissionFiveImportTest:109` | Reorder **PR and Quotation** templates; keep technical keys | Q5 | L | – | – | – | L | Header order exact; old-order files parse | 02, 03 |
| 09 | Sticky header + Material column | PARTIAL | Page-local sticky; unbounded wrappers | Shared `.ui-table-sticky` + bounded scroll | — | – | M | – | – | L | Header & Material stay visible | 03 |
| 10 | Supplier PIC visible to Purchasing | PARTIAL | PIC fields exist; not rendered (`purchasing/quotations/show:245-253`) | Render PIC; eager-load | — | L | L | – | L | L | PIC or "–"; no N+1 | 03 |
| 11 | Port/Incoterms on PR → Quotation → PO | MISSING | None (grep) | PR + PO columns; quotation reads PR; PO snapshot; consolidation guard; PDF/export/detail | Q1, Q7 | M | M | M | L | L | Values visible to Supplier; on PO detail, export, PDF; mixed consolidation rejected | 01, 02, 04 |
| 12 | Optional draft, required on submit | MISSING | — | Shared submission guard (3 paths) | — | M | L | – | – | L | All submit paths reject missing | 02 |
| 13 | Fixed options, no master | MISSING | — | `config/procurement.php` + CHECK | — | L | L | L | L | L | Out-of-list rejected app+DB | 01, 02 |
| 14 | Purchasing detail mirrors Supplier form, read-only | PARTIAL | Chips exist (`purchasing/quotations/show:114-239`) | Shared read-only paired-row partial | — | L | H | – | L | L | Same structure, no inputs | 03 |
| 15 | History graph from PO history | PARTIAL | PO-joined; supplier required | Eligible-line service; supplier optional | Q9 | M | M | – | – | M | Non-PO quotes never in history | 07 |
| 16 | Weighted avg Σ(price×KG)/Σ(KG) | **CONFLICT** | `AVG(price)` `:1256` | Currency-grouped exact weighting (§12.3) | Q4, Q9 | H | M | – | – | M | 100kg@50k + 300kg@70k = 65k; multi-currency exact | 07 |
| 17 | Exact and range filters | MISSING | Exact only `:1052` | Exact/range, inclusive | — | M | M | – | L | L | Bounds inclusive | 07 |
| 18 | Flat→Thickness; Round/Hollow→OD | **CONFLICT** | All 5 dims filtered `:362-367` | Shape-driven key dimension | — | L | M | – | – | M | ID not a filter | 07 |
| 19 | Best Current, Historical Best, Historical Avg | PARTIAL | vs-best MIN only | Indicator strip via services | Q4, Q9 | M | M | – | – | L | Three values, one currency | 07 |
| 20 | Comparison currency IDR/USD/JPY/CNY | MISSING | IDR only | `?currency=` normalization | Q4, Q9 | M | M | – | L | L | All indicators switch | 07 |
| 21 | MTC on Shipment, multi-file, legacy kept | **CONFLICT** | Quotation MTC upload (`QuotationController:370,749-784,1027`) | Shipment MTC + removal of new quotation MTC **in the same mission (M05)** | Q6B | H | M | – | H | M | No quotation MTC input; legacy downloadable; shipment ≥1 file | 05 |
| 22 | MTC optional draft, ≥1 VALIDATED to submit | MISSING | — | Guard in `submitShipment` under lock; append-only after submit | Q6B | H | L | – | M | M | All submit paths blocked w/o MTC; post-submit delete refused | 05 |
| 23 | Label "Supplier Estimate Delivery" | MISSING | §5.2 label map | New EN/ID keys at all mapped usages | Q8 | – | L | – | – | L | EN `Supplier Estimate Delivery` / ID `Estimasi Pengiriman Supplier` | 03 |
| 24 | Section order PC → CT&L → Status → Detail | MISSING | Create/show order differ | Reorder create & show | — | – | M | – | – | L | DOM order | 03 |
| 25 | Master Harga Admin+Purchasing, evaluation-only | MISSING | — | `material_reference_prices` + service + CRUD | — | H | M | H | M | L | Transactions untouched | 06B |
| 26 | General across suppliers | MISSING | — | No `supplier_id` | — | – | – | M | – | – | Same ref for all | 06B |
| 27 | Effective periods, versions, no conflicts | MISSING | — | Append-only versions | — | H | M | H | L | L | Prior versions kept | 06B |
| 28 | Purchasing full access to Material & HS Code only | MISSING | Admin-only; no delete anywhere | Policies; purchasing routes; **guarded hard delete** + audit | Q3 | M | M | L | H | L | Purchasing manages + deletes unreferenced; 403 elsewhere | 06A |
| 29 | Reference on Comparison + Detail, Δ nominal/% | MISSING | — | Resolver output; never mutates offer | Q4, Q6A, Q9 | M | M | – | – | L | Δ only when matched | 06B, 07 |
| 30 | Match by offered spec | MISSING | — | Offered key dimension only | Q6A | M | – | – | – | L | Missing offered dim → Not Available | 06B |
| 31 | Evaluation-date reference + snapshot at PO finalization | MISSING | PO `active` at generation | Snapshot inside `generateFromAwards` | Q9 | M | L | M | L | L | Snapshot immutable | 06B |
| 32 | Missing reference → Not Available + reason; no PO block | MISSING | — | Reason codes; null deltas | Q6A | M | L | – | – | L | Reason shown; PO allowed | 06B, 07 |
| 33 | Reject overlapping ranges; concurrency-safe | MISSING | Detector without lock | `material_masters` row lock | — | H | L | M | – | – | Concurrent overlap: one wins | 06B |
| 34 | Evaluation-date FX for analytics; snapshots preserved | **CONFLICT** | Snapshot-based comparison; `AGENTS.md` mandate | Analytical layer; snapshot layer untouched; `AGENTS.md` amendment in M07 | Q4, Q9 | H | M | – | – | H | Snapshot totals unchanged after new benchmark | 01, 07 |
| 35 | Reuse benchmark; as-of resolver; Unavailable; never 1 | PARTIAL | Infra exists; no resolver; `COALESCE(…,1)`, `?? 0`; UTC `valid_from` | `ExchangeRateResolver`; fix fallbacks (analytics M07, transactional M04); business-date `valid_from` | Q4, Q9 | M | L | L | L | M | No fabricated rates anywhere | 01, 04, 07 |

---

## 7. Existing / Partial / Missing / Conflict Summary

| Status | Count | Decision IDs |
|---|---|---|
| EXISTING | 0 | — |
| PARTIAL | 11 | 02, 03, 04, 06, 07, 09, 10, 14, 15, 19, 35 |
| MISSING | 17 | 11, 12, 13, 17, 20, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33 |
| CONFLICT | 7 | 01, 05, 08, 16, 18, 21, 34 |
| UNVERIFIED | 0 | Partially inspected sub-areas: R-16 |

---

## 8. Critical Invariants

| # | Invariant | Enforcement |
|---|---|---|
| I-1 | `1 ≤ ordered_qty ≤ offered available_qty` for every PO-assigned award | Award + PO services; DB CHECK `ordered_qty >= 1` |
| I-2 | Σ active `shipped_qty` per PO line ≤ resolved ordered qty | `submitShipment` under PO locks (existing) |
| I-3 | A quotation joins at most one PO | UNIQUE `po_quotations.quotation_id` (existing) |
| I-4 | No `different`-spec line without a current acceptance hash | `generateFromAwards` |
| I-5 | PR qty/dimensions never change after submit | Existing status guards |
| I-6 | Document snapshots (`exchange_rate_id`, stored amounts) never rewritten | No migration/backfill touches them |
| I-7 | No two active reference prices with the same material+shape+currency have intersecting dimension **and** date intervals | Service under `material_masters` row lock |
| I-8 | No non-IDR value is ever converted with an implicit 1 or 0; IDR→IDR = 1 | `ExchangeRateResolver` (analytics); transactional helpers (M04) |
| I-9 | No shipment reaches `submitted` with zero VALIDATED MTC | `submitShipment` under shipment lock |
| I-10 | Supplier sees only own data; Purchasing gains no unrelated Admin routes | Policies + route groups |
| I-11 ➕ | All awards in one PO share identical PR `port_of_discharge` **and** `incoterm` (NULL counts as a distinct value) (Q1) | `generateFromAwards` per supplier group |
| I-12 ➕ | MTC attachments of a non-draft shipment are never deleted via the application (Q6B) | `ShipmentService::removeMtc` + `ShipmentPolicy` |
| I-13 ➕ | A master record is hard-deleted only when unreferenced; the FK RESTRICT backstop is never weakened (Q3) | Service under row lock + DB FK |
| I-14 ➕ | One request uses one evaluation date for every analytical value (Q9) | Resolved once per request, passed down |
| I-15 ➕ | Every consumer obtains ordered qty/KG/amount through one resolver with one precedence chain (§11.2) | `PurchaseOrder::orderedLines()` + SQL mirror; consistency test |
| I-16 ➕ | A `delete_refused` audit row is never written inside a transaction that can be rolled back. It is persisted after the deletion transaction ends, for both app-check and FK-backstop refusals | `MasterDataDeletionService` two-phase design (§13) |
| I-17 ➕ | Once a PO has frozen ordered values, no deployed code version may compute its quantity, KG or amount from the offer | Read path (M04-R) is never reverted while frozen POs exist; rollback gate in §20 |
| I-18 ➕ | From the first release in which a PR can be submitted with logistics (R1), every PO generation validates logistics uniformity (Q1) and persists the PO logistics snapshot. No deployed version can create a PO from PRs that carry logistics without that guard and snapshot | Guard + snapshot in `generateFromAwards`, delivered by M02 in R1; R1 rollback gate (§20) |
| I-19 ➕ | Reference-price snapshots exist only for POs finalized while the feature is active (R4 onward). Earlier POs are "not captured": never backfilled, never retroactively priced. Every award of a PO finalized after activation has exactly one snapshot row (`matched` or `not_available`) | M06B snapshot insert inside `generateFromAwards`; no backfill command; reconcile check |
| I-20 ➕ | `procurement.po_generation_enabled = false` stops PO creation on all three entry points in every deployed version from R1 onward, including after an M04-W rollback | Check at the start of `generateFromAwards` (single choke point), delivered by M01 in R1 |
| I-21 ➕ | No PO line is ever created from an award carrying a Purchasing selection (`ordered_qty` or spec acceptance) unless the running code freezes `ordered_*` from that selection in the same transaction. Code without an active write path refuses such awards; it never orders `fulfillment_quantity` instead. A selection never survives re-pointing to another offer | M04-R guard (b) in `generateFromAwards`; `selection_cleared` in `PrItemAwardService`; AC-43 |
| I-22 ➕ | M04-W is never code-reverted beneath deployed dependents (M06B in R4, M07 in R5). Its rollback after R4/R5 is configuration-only (`ordered_quantity_write_enabled=false`), under which M06B snapshotting and M07 analytics remain correct | Runtime flag; shared generation/read paths; rollback table §20; AC-44 |

---

## 9. Proposed Architecture and Data Flow

```
PR draft ──submit (store|update|submitDraft → shared guard)──► PR submitted: port+incoterm REQUIRED, frozen
        │ (quotations read logistics through PR — no copy)
        ▼
Supplier Quotation: offer qty ≥1 (no ceiling), offered dims, kg/unit, price/kg; rate snapshot at submit (unchanged)
        ▼
Purchasing evaluation (EvaluationContext: date = BusinessTime::today() resolved ONCE per request, Asia/Jakarta)
   • ExchangeRateResolver.rateAsOf(currency, date)          — analytics only, never future-dated
   • ReferencePriceResolver(material, shape, OFFERED key dim, date, target currency) — no PR fallback (Q6A)
   • PurchaseHistoryPriceService (eligible PO lines, exact KG weighting across currencies)
        ▼
Award (pr_item_awards): quotation_item_id + ordered_qty (default min(req, offered) suggested by UI, Q2)
                         + spec acceptance (+SHA-256 hash) ─ events → pr_item_award_events
        ▼
PurchaseOrderGenerationService::generateFromAwards  ← single choke point (3 entry paths)
   locks PR→PR items→quotations→quotation items→awards (existing order)
   • I-1, I-4, I-11 (Q1: reject, never split)
   • freeze ordered_* on awards; snapshot port/incoterm on PO
   • write award_reference_price_snapshots (evaluation date = generation business date)  ← "PO finalization" (PO is created `active`)
        ▼
PO active ── all consumers via PurchaseOrder::orderedLines() (precedence: frozen → award+qty → legacy award → legacy PO)
        ▼
Shipment draft ── MTC upload/remove ── submitShipment (I-2, I-9) ──► submitted (MTC append-only, Q6B) ──► arrived ──► QC
```

**Two exchange-rate layers (Q4):**

| Layer | Purpose | Source | Missing foreign rate |
|---|---|---|---|
| Transactional | Quotation/PO values in IDR: PO list total, PO/quotation exports, detail pages | Document snapshot `exchange_rate_id` (immutable) | `—` / `Exchange Rate Unavailable`; original amount + currency shown; never 1, never 0 |
| Analytical | Price Comparison indicators, history, reference deltas | `exchange_rates` benchmark, latest `valid_from ≤ evaluation date` | Value excluded from the computation, with the exclusion count reported |

---

## 10. Database Schema and Migration Design

All migrations are additive and nullable. `down()` throws if data would be lost (repo pattern). Q1–Q9 add **one** table (000007, for Q3 auditability). Everything else is configuration or presentation.

| # | Migration (proposed) | Change | Constraints / indexes | `down()` | Mission |
|---|---|---|---|---|---|
| 000001 | `add_logistics_terms_to_purchase_requisitions` | `port_of_discharge VARCHAR(30) NULL`, `incoterm VARCHAR(10) NULL` | CHECK `IN ('TANJUNG_PRIOK','TANJUNG_PERAK')` / `IN ('CFR','CIF','FOB')`, NULL allowed | guard if non-null rows | 01 |
| 000002 | `add_logistics_terms_to_purchase_orders` | same two columns (snapshot) | same CHECKs | guard | 01 |
| 000003 | `add_ordered_and_acceptance_fields_to_pr_item_awards` | `ordered_qty INT UNSIGNED NULL`; frozen at PO: `ordered_weight_per_unit DECIMAL(12,4)`, `ordered_total_weight_kg DECIMAL(14,4)`, `ordered_price_per_kg DECIMAL(15,4)`, `ordered_amount DECIMAL(15,4)` (all NULL); `spec_accepted_by` FK users NULL nullOnDelete, `spec_accepted_at`, `spec_acceptance_snapshot JSON`, `spec_acceptance_hash CHAR(64)` | CHECK `ordered_qty IS NULL OR ordered_qty >= 1` | guard | 01 |
| 000004 | `create_pr_item_award_events` | append-only: `pr_item_award_id` (NULL, nullOnDelete), `pr_id`, `pr_item_id`, `quotation_item_id`, `purchase_order_id`, `event VARCHAR(40)`, `payload JSON`, `actor_id`, `created_at` | idx `(pr_item_award_id, created_at, id)`, `(pr_id, created_at)` | guard | 01 |
| 000005 | `create_material_reference_prices` | `material_master_id` FK **restrict**, `shape`, `dimension_field`, `dimension_min/max DECIMAL(10,4)`, `currency`, `price_per_kg DECIMAL(15,4)`, `valid_from DATE`, `valid_until DATE NULL`, `status ('active','void')`, `supersedes_id` self-FK NULL, `version_no`, `notes`, `voided_by/at`, `void_reason`, `created_by`, `updated_by`, timestamps | CHECKs: shape list; Flat↔thickness / Round,Hollow↔d_outer; `min>0 AND max>=min`; `price>0`; `until IS NULL OR until>=from`; currency list; status list. Idx `(material_master_id, shape, currency, status, valid_from)` | guard | 01 |
| 000006 | `create_award_reference_price_snapshots` | insert-only: `pr_item_award_id` UNIQUE, `purchase_order_id`, `material_reference_price_id` NULL restrict, `status ('matched','not_available')`, `reason_code`, `evaluation_date`, `dimension_field/value`, `reference_currency/price`, `po_currency`, `reference_rate_id`, `po_rate_id`, `converted_reference_price_per_kg`, `offer_price_per_kg`, `diff_amount_per_kg`, `diff_percent` | — | guard | 01 |
| 000007 ➕ | `create_master_data_audit_logs` | append-only: `id`, `entity_type VARCHAR(40)` (`material_master`/`hs_code_rule`), `entity_id BIGINT`, `action VARCHAR(30)` (`created`/`updated`/`status_changed`/`deleted`/`delete_refused`), `before JSON NULL`, `after JSON NULL`, `actor_id` FK users NULL nullOnDelete, `actor_role VARCHAR(20)`, `created_at` | idx `(entity_type, entity_id, created_at)` | guard | **06A** (only consumer; keeps 06A independent of M01) |
| (evidence-gated) | indexes | `exchange_rates(currency, valid_from, id)`, `pr_items(material_master_id, shape, thickness)`, `pr_items(material_master_id, shape, d_outer)` | only after `EXPLAIN` | `dropIndex` | 07 |

**No schema for:**
- Q1 (uses the 000002 snapshot);
- Q2 (000003);
- Q4 (code-only fallbacks);
- Q5 (template code);
- Q6A (`reason_code` value);
- Q6B (`attachments` reuse + state rules);
- Q7 (000002 + PDF code);
- Q8 (lang);
- Q9 (code).

**CHECK support:** CHECK constraints require MySQL ≥ 8.0.16. Local is 8.0.30. Staging/production is unverified (T-1). The existing migrations `2026_09_04_000003` and `2026_09_08_000001` already rely on CHECKs.

**Backfill:** none mandatory (§11.2 fallback chain). The optional read-only reconciliation command lives in M08.

---

## 11. Financial and Quantity Calculation Contracts

### 11.1 Quantity lifecycle (Q2)

| Stage | Meaning | Where | Rule |
|---|---|---|---|
| Requested | PR quantity | `pr_items.quantity` (`quantity_value` ≥1) | Immutable after submit |
| Offered | Supplier quantity | `quotation_items.available_qty` | Integer ≥1, **no upper bound**; never modified by Purchasing |
| **Default (suggested)** | UI pre-fill only | Rendered by the server into each award line's Order Qty input | `min(requested, offered)` (e.g. 10 vs 15 → 10). **Not stored, not applied silently by the server.** |
| **Submitted (selected)** | Purchasing's choice in the request | `order_qty[pr_item_id]` on all three PO paths and award save | Server-validated integer, `1 ≤ q ≤ offered`; violations → 422 mapped to the line. A missing value for an award without a persisted `ordered_qty` → 422 "Order quantity required". |
| **Persisted (authoritative)** | Final ordered qty | `pr_item_awards.ordered_qty` | Written by the award save / PO generation inside the existing lock order. Immutable once `purchase_order_id` is set (existing guard). Changes are logged in `pr_item_award_events`. |
| Frozen derivatives | Values at PO finalization | `ordered_weight_per_unit`, `ordered_total_weight_kg`, `ordered_price_per_kg`, `ordered_amount` | Written once in `generateFromAwards` |
| **Legacy fallback** | Pre-release POs only | — | Applies only when `purchase_order_id IS NOT NULL AND ordered_qty IS NULL` (award) or the PO has no awards (pre-award PO). It reproduces today's values exactly (§11.2). **Never used for new PO generation.** |

### 11.2 Single resolver and precedence (consistency fix 6.5)

`PurchaseOrder::orderedLines(): Collection<OrderedLine>` is the **only** API consumers may use. `OrderedLine` is a readonly value object: `quotationItem, award?, qty, kg, pricePerKg, amount, source`.

| Precedence | Condition | qty | kg | amount | `source` |
|---|---|---|---|---|---|
| 1 | Award with frozen values | `ordered_qty` | `ordered_total_weight_kg` | `ordered_amount` | `frozen` |
| 2 | Award with `ordered_qty`, not frozen (pre-generation display only) | `ordered_qty` | `round(qty × offered_wpu ?? prItem.weight_needed, 4)` | `round(kg × price_per_kg, 4)` | `selected` |
| 3 | Legacy award (`ordered_qty` NULL, PO assigned) | `fulfillment_quantity` | `offered_total_weight ?? prItem.total_weight` | `resolved_amount` | `legacy_award` |
| 4 | Legacy PO without awards | each quotation item `fulfillment_quantity` | as row 3 | `resolved_amount` | `legacy_po` |

**Consumers to migrate in M04:**
- `itemFulfillmentStatus` (ordered qty for the `(po, quotation_item)` line);
- `total_ordered_*`, `isFullyFulfilledAndInspected`, `delivery_progress`;
- `MaterialProgressService`;
- `PurchaseOrderPdf`;
- the PO exports and Advanced definition;
- the views.

**SQL mirror:** `scopeWithResolvedTotalIdr` uses `COALESCE(a.ordered_amount, <selected expr>, <existing legacy expr>)` with the same precedence.

**Consistency test:** for fixtures of every `source`, Σ PHP `orderedLines()->amount` equals the SQL scope value, and the PDF, the exports and `itemFulfillmentStatus.ordered_qty` agree. A legacy PO's totals must be identical before and after M04 (characterization captured first).

### 11.3 Formulas and rounding
- **Offered KG:** `available_qty × offered_weight_per_unit` (existing).
- **Offer amount:** stored server-side (existing).
- **Ordered KG and amount:** as above, using BCMath via `App\Support\Money`.
- **Storage:** 4 dp.
- **Analytics conversions:** scale 8, half-up to 4 dp at the end.
- **Display:** `RegionalDisplayFormatter`.
- **Δ vs PR** = `ordered_qty − requested_qty` (display only).

### 11.4 Transactional IDR display (Q4C)
- **IDR documents:** rate 1 (identity).
- **Foreign documents:** use the snapshot rate. If it is missing, the IDR value is `null` → rendered `—` with the "Exchange Rate Unavailable" tooltip; original amount + currency are always shown.
- **SQL aggregate safety:** `SUM()` ignores NULL, so the PO total uses `CASE WHEN SUM(CASE WHEN currency<>'IDR' AND rate IS NULL THEN 1 ELSE 0 END) > 0 THEN NULL ELSE SUM(amount × COALESCE(rate_for_idr_identity)) END`. A missing rate yields an **unavailable** total, never a partial one.

---

## 12. Master Harga and Exchange Rate Design

### 12.1 Evaluation context and `ExchangeRateResolver` (Mission 01; Q9)

```
EvaluationContext::current(): date = BusinessTime::today() (Asia/Jakarta) — computed ONCE per request
ExchangeRateResolver (request-scoped singleton, in-request memo only)
  rateAsOf(currency, date): ?RateResult{rate, exchangeRateId, validFrom}
    IDR → ('1', null, null)
    else latest row: currency = ?, valid_from <= date, rate_to_idr > 0
         ORDER BY valid_from DESC, id DESC   (same tie-break as latestRate)
         none → null (Unavailable); future-dated rows never selected
  convert(amount, from, to, date): ?string = amount × rate(from) / rate(to)  (BCMath; null if any rate null)
```

- **No manual date picker.** The UI shows "Evaluation date: `{evaluation_date}`" plus, per currency, "rate effective `{valid_from}`". Both are **dynamic values**: `{evaluation_date}` = `EvaluationContext::current()->date` = `BusinessTime::today()` (Asia/Jakarta) for that request, and `{valid_from}` = the resolved rate row. No date is hard-coded in views, translations, JavaScript or tests. Tests freeze the clock and assert that the rendered date equals `BusinessTime::today()` under that frozen clock, not a literal string. Any concrete date in this document is illustrative only.
- **Document snapshots and `latestRate()` are untouched** (they keep creating new snapshots).
- **`updateKurs` fix (both controllers):** `valid_from => BusinessTime::today()->toDateString()`.

### 12.2 Master Reference Price (Mission 06B; Q6A)

**Writes** (`MaterialReferencePriceService`, one transaction each):
1. `MaterialMaster::lockForUpdate()` — the serialization point.
2. Overlap check against `active` rows with the same material+shape+currency:
   - dimension overlap `a.min ≤ b.max ∧ b.min ≤ a.max`;
   - date overlap with an open end = `9999-12-31`.
3. Insert or `ValidationException`.

**Operations:**
- **Create.**
- **Supersede:** close the current row at `X−1` and insert the next version.
- **Void:** with a reason.
- **Notes edit.**
- There is no price edit and no delete.

**Read** — `ReferencePriceResolver::resolve(line, EvaluationContext, targetCurrency)`:
- Key dimension = **offered** `available_thickness` (Flat) or `available_d_outer` (Round/Hollow) **only**.
- Reason codes, applied in this order:
  - `material_unmapped` (legacy item without `material_master_id`);
  - **`offered_dimension_missing`** → "Reference Price Not Available — Offered Dimension Missing" (**no PR fallback**, Q6A);
  - `no_price_for_material`;
  - `dimension_not_covered`;
  - `period_not_effective`;
  - `exchange_rate_unavailable` (conversion to the target currency impossible);
  - `ambiguous_configuration` (defensive; logged).
- On a match:
  - reference (original currency) + converted value;
  - `diff = offer − ref`;
  - `diff% = diff/ref×100`.
- Otherwise every Δ is `null`. Offer data stays visible.
- **The resolver never blocks PO generation and never writes transactional data.**

**PO finalization snapshot (fix 6.6):**
- **Timing:** inside the `generateFromAwards` transaction, immediately after the PO (created `active`, the repository's finalization event) and its award links are written.
- **Content:** one `award_reference_price_snapshots` row per award, `matched` or `not_available` + reason.
- **Evaluation date:** the generation business date.
- **No new approval workflow.**

**POs finalized before Reference Price activation (I-19):**
- **Activation point:** the R4 release that deploys M06B's snapshot insert. POs finalized in R1–R3 (and all earlier legacy POs) have **no** `award_reference_price_snapshots` rows. That absence is the defined state **"Reference snapshot not captured"**. No placeholder rows are written and no schema value is added, so the absence itself encodes the state.
- **PO pages** (Purchasing PO show, Supplier-hidden) display "Reference snapshot not captured — PO finalized before Reference Price was available". They **do not** call `ReferencePriceResolver` for a finalized PO, so a reference price created later is never shown as if it applied to that PO.
- **Never backfilled:** no migration, seeder or Artisan command creates snapshot rows for an existing PO. Creating, superseding or voiding a reference price never touches existing POs or snapshots.
- **Not affected by this rule:** evaluation views (Quotation detail, Price Comparison) keep resolving live reference prices at the evaluation date for **open quotations**. History analytics (§12.3) use PO purchase prices, not reference snapshots.
- **From activation onward:** every award linked by `generateFromAwards` gets exactly one snapshot row in the same transaction, `not_available` included. The snapshot is immutable (AC-28).
- **Reconcile check (M06B):** `po_missing_reference_snapshot_after_activation` = POs created at or after the earliest snapshot's `created_at` that have any award without a snapshot row. The report lists the PO numbers. It must be 0, except POs explicitly recorded as known gaps of an approved M06B rollback window (§20), which stay "not captured" and are never backfilled.

### 12.3 Historical weighted average across currencies (fix 6.3)

**Eligible lines:**
- an award with PO (legacy: PO quotation items);
- PO not soft-deleted and `status <> 'cancelled'`;
- quotation not soft-deleted;
- available;
- `price_per_kg > 0`;
- ordered KG > 0;
- material + shape + key-dimension filter matches (spec = `COALESCE(offered key dim, PR key dim)` for legacy rows).

UNIQUE `po_quotations.quotation_id` prevents pivot double-counting.

**Contract:** `WA = Σ_i (convert(price_i, cur_i → sel, date) × kg_i) / Σ_i kg_i` over eligible lines whose rate is available.

**Implementation:**
- SQL returns, per `(period, currency)`: `S_c = Σ price×kg`, `K_c = Σ kg`, `N_c = count`, `MIN_c = MIN(price)`.
- Because the rate `r_c` is constant per currency at the single evaluation date, `Σ_i convert(price_i)×kg_i = Σ_c convert(S_c)` exactly. Therefore `WA = Σ_{c∈available} convert(S_c) / Σ_{c∈available} K_c`.
- This is **not** an average of currency averages.
- `Historical Best = min_{c∈available} convert(MIN_c)`.
- Currencies without a rate are excluded, and their `N_c` and `K_c` are reported ("n lines / x kg excluded: Exchange Rate Unavailable").
- `Σ K = 0` → "No eligible purchase history".
- BCMath throughout; rounding only on the final value.
- Document snapshot IDR values remain unchanged and separately labelled.

---

## 13. Security and Authorization Design

| Area | Design |
|---|---|
| **Material/HS Code access (D28, Q3)** | New `MaterialMasterPolicy` and `HsCodeRulePolicy` (`viewAny, create, update, toggleStatus, delete` → `admin`, `purchasing`). FormRequests authorize via the policy (not `isAdmin()`). Purchasing route group `purchasing/master-data` (`purchasing.master-data.*`) maps to the same controllers. Admin routes are unchanged, and admin delete routes are added. Views take a route prefix; the hard-coded `admin.*` URLs in `admin/material-hs-code/_script.blade.php:5-10,26,55,272` are parameterised. Purchasing has **no** access to `admin.users.*`, `admin.exchange-rates.*`, `admin.announcements.*`, `admin.auth-audit-logs.*` or `admin.dashboard`. |
| **Guarded hard delete (Q3)** | `MasterDataDeletionService::delete()` runs in **two phases** so that a refusal is always durably audited (I-16).<br>**Phase 1 — deletion transaction** (`DB::transaction`): (1) `lockForUpdate` on the target row; (2) reference checks — material: `pr_items.material_master_id`, `material_aliases`, `material_reference_prices`; HS rule: `pr_items.hs_code_rule_id`; (3) if referenced, the transaction **performs no write** and **returns** a `DeletionOutcome::refused(counts)` value (no exception, nothing to roll back); (4) otherwise snapshot `before`, delete, and insert the `deleted` audit row **inside the same transaction**, so the delete and its audit commit or roll back together.<br>**Phase 2 — refusal audit, after Phase 1 has finished:** for `refused` outcomes, *and* for a Phase 1 transaction that rolled back because the DELETE hit the FK backstop (SQLSTATE 23000/1451 from a concurrent child insert that committed first, caught **outside** the closure), the service writes the `delete_refused` audit row in a **separate, auto-committed insert** after the deletion transaction has completed. That row carries the counts or `fk_restrict`, actor and role. The refusal audit is therefore never inside a transaction that is rolled back. The caller then returns **422** "Cannot delete: used by N PR items / N aliases / N reference prices. Deactivate it instead."<br>If the Phase 2 insert itself fails, the 422 is still returned to the user, and the failure is reported through `report()`/the log channel. A refusal never silently succeeds and never becomes a delete.<br>A child insert that starts after the parent lock blocks, then fails its FK check (the PR save surfaces "material no longer available").<br>Create, update and status changes are written to `master_data_audit_logs` in the same transaction as the change.<br>Operational note: re-running `MaterialHsCodeMasterSeeder` can re-create deleted seeded rows. |
| **Reference prices** | `MaterialReferencePricePolicy` (admin, purchasing). Supplier routes and views never expose reference data. |
| **Award/PO** | Purchasing-only routes; `order_qty` and `accept_spec` validated server-side; frozen fields never mass-assigned from the request. |
| **Shipment MTC (Q6B)** | Routes `POST supplier/shipments/{shipment}/mtc`, `DELETE supplier/shipments/{shipment}/mtc/{attachment}` (`role:supplier`, `supplier.scope:import`, throttle). `ShipmentPolicy::uploadMtc` = owner ∧ status ∈ {draft, submitted}. `ShipmentPolicy::deleteMtc` = owner ∧ status = draft. Service re-checks run under `Shipment::lockForUpdate()`. The attachment must belong to the route's shipment (anti-IDOR). Profile `'mtc'`, private disk, SHA-256, `file_inspection_id`, `uploaded_by`. `AttachmentPolicy` gets a Supplier arm `Shipment::class => owner`. Downloads go through `attachments.show` + `FileAccessGuard`. **Fail-closed allow-lists:** upload = {draft, submitted}, delete = {draft}. `arrived`, `cancelled` and any other status are denied by default. The `arrived` policy remains **unresolved (T-6)**, and no Supplier or Purchasing permission is invented for it; read access is unchanged. |
| **Quotation MTC legacy** | Read/download paths remain. Write inputs are removed in **M05**. |
| **Logistics** | `Rule::in(config('procurement.*'))` + DB CHECK. |
| **Comparison currency** | `Rule::in(ExchangeRate::CURRENCIES)`; default `IDR`. |
| **Supplier isolation** | `SupplierDataIsolationTest`, `HashidUrlSecurityTest` and `AttachmentAuthorizationBoundaryTest` run in every mission. |
| **Excel** | Existing formula guard and limits. The error `value` is sanitized (`SpreadsheetCellSanitizer::text`) and truncated to 100 characters. |

---

## 14. UI/UX Implementation Specifications

All UI follows `AGENTS.md` §Panduan UI + the Phase 2 global contract: tokens, `x-ui.*`, `tw-`, dark-mode tonal surfaces, EN+ID strings, `$regionalFormatter`, `x-ui.date-picker`.

| Item | Spec |
|---|---|
| PR logistics (D11-13) | Infor-style label-left/select-right pair in the PR header. "Port of Discharge" (Tanjung Priok / Tanjung Perak); "Incoterms" (CFR — Cost and Freight / CIF — Cost, Insurance and Freight / FOB — Free On Board). Required marker; draft allowed. Read-only on PR show, Supplier quotation Procurement Context, Purchasing quotation detail, comparison header, consolidation list (as columns, with a non-authoritative pre-warning for mixed values, Q1), PO show, Supplier PO show, PO exports, **PO PDF** (Q7). |
| PO PDF (Q7) | Two rows appended to the `.dates` block after Currency: "Port of Discharge" and "Incoterms" (English labels, `trans(..., [], 'en')` like existing rows; values = PO snapshot; `-` if NULL). No other layout element moves. Verified visually (§17 AC-31) and **released only after the PO form owner's approval (gate G-PDF)**. |
| Sticky tables (D9) | Shared `.ui-table-sticky`: bounded scroll `max-height: calc(100vh - var(--topbar-height) - var(--ui-table-sticky-offset, 12rem)); overflow:auto`, sticky `thead th`, `.ui-sticky-col` with opaque token backgrounds, plus an opt-in `sticky` prop on `x-ui.data-table`. Applied to PR form/show, Supplier quotation create/show, Purchasing quotation show, inter-supplier matrix, PO show. Keeps `role="region" tabindex="0" aria-label`. |
| Footer totals (D6) | PR: Total Qty, Total KG. Quotation create: Total Offered Qty, KG, Amount (live JS preview; server recomputes). Show/detail: Requested vs Offered Qty and KG, Offer Amount, Est. IDR (snapshot; `—` if unavailable). |
| Purchasing detail (D14) | Shared read-only partial `resources/views/quotations/_items_readonly_table.blade.php` (paired Requested/Offer rows, `table-bordered`). Per-dimension columns (shape-relevant), Qty, KG/unit, Total KG, Price/kg, Amount, Notes, Status chips (Match / Surplus +n / Shortage −n / Different Spec / Est. Weight), legacy MTC column only if attachments exist. Purchasing-only reference columns (M06B): Reference, Δ, Δ%, or "Reference Price Not Available" + reason. |
| Contact person (D10) | PIC name, phone (`tel:`), email (`mailto:`) on the quotation detail Supplier card, comparison header and PO show; "–" fallback. |
| Order Qty & acceptance (D1-4, Q2) | Per selected award line: number input `min=1 max=offered`, **value = `min(requested, offered)`**, hint "PR: `{requested}` · Offered: `{offered}` · Δ vs PR: `{ordered − requested}`" (all values dynamic; *example:* "PR: 10 · Offered: 15 · Δ vs PR: 0"). `different` specs need a required checkbox "Accept specification difference" with the requested vs offered diff. Same controls on the inter-supplier matrix, the direct Generate-PO panel and consolidation. Server errors are anchored to the line. |
| Section order (D24) | Create: Procurement Context → Commercial Terms & Logistics → Status & Follow-up (revision alert/reviewer notes) → Detail Quotation. Show: same order. All `name`/`id`, `data-async-submit`, import modal and JS selectors preserved. |
| Labels (D23, Q8) | New keys `supplier.copy.supplier_estimate_delivery` and `purchasing.copy.supplier_estimate_delivery`: EN **`Supplier Estimate Delivery`**, ID **`Estimasi Pengiriman Supplier`**. Replace every usage in the §5.2 map. Sentence copy reworded (EN "PO Target Arrival Date is earlier than the selected Supplier Estimate Delivery. Review the target before generating the PO." / ID "Tanggal target kedatangan PO lebih awal dari Estimasi Pengiriman Supplier terpilih. Tinjau target sebelum membuat PO."). Export heading `exports.closure.estimated_delivery` → same EN/ID labels. Progress "Estimated Ready" keys untouched. Obsolete keys are removed only when unreferenced (parity + copy-inventory tests). |
| Shipment MTC (Q6B) | "Mill Test Certificates (MTC)" section: `<x-ui.file-upload multiple>`; list (name, size, uploader, uploaded at, inspection state, open). **Remove** button only while Draft. In Submitted: "Add MTC" only, with the note "Submitted certificates cannot be removed". Hint "At least one MTC is required before submitting". Form converted to `data-async-submit`. Purchasing shipment and PO pages list MTC per shipment. |
| Comparison (D15-20, Q9) | Currency selector (`?currency=`, default IDR). Header "Evaluation date: `{evaluation_date}` (Asia/Jakarta)", where `{evaluation_date}` is rendered from the request's `EvaluationContext`, i.e. `BusinessTime::today()` in `Asia/Jakarta`, resolved once per request and formatted with `$regionalFormatter` (**never a literal**; *example rendering only:* "Evaluation date: 14 Oct 2026 (Asia/Jakarta)"). Per-currency rate effective dates come from each selected rate's `valid_from`. Indicator strip per PR item: Best Current Offer, Historical Best, Historical Avg (weighted, n POs, Σ KG), Reference — converted value + original. "Exchange Rate Unavailable" chip + exclusion note. Historical tab: supplier optional, material by master, shape-driven key dimension (Exact/Range). Chart = weighted average "converted at rates as of {date}". |
| Master Harga (D25-27) | Purchasing → Master Data → Reference Prices: server-side DataTable, create modal (exact toggle min=max), New version / Void with reason, history drawer. |
| Master delete (Q3) | "Delete" in the overflow menu of the Material and HS Code tables, with confirm dialog (SweetAlert per AGENTS.md). On refusal, an inline message with reference counts and a "Deactivate instead" action. |

---

## 15. Detailed Mission Execution Plan

> Common rules for every mission: fresh session with this document; read `AGENTS.md`.
> - Check `git status`. Preserve unrelated changes, including `KnownDeviceService.php`.
> - Ensure no other `artisan test`/`phpunit` process runs against `adasi_portal_test`.
> - Tests first; then `php -l` and `php vendor/bin/pint --test <files>`; targeted tests `--compact`.
> - Run `php artisan view:cache` / `npm.cmd run build` / `node --test tests/js/<file>.test.mjs` when relevant, plus `php artisan route:list --no-ansi` and `git diff --check`.
> - Never migrate the application DB. No commit or push without explicit instruction.
> - **STOP** at the gate with a report separating executed vs not executed.

### Mission 01 — Domain Contracts and Schema
- **Decisions:** 01–03, 07, 11, 13, 34, 35 (contracts); schema for 04, 25–27, 31, 33. Q2, Q4, Q9 contracts.
- **Prerequisites (fix 6.1):**
  - explicit Gate 2 approval;
  - Q1–Q9 **resolved** (§22.1) — no open business prerequisite;
  - local MySQL 8.0.30 suffices for tests; T-1 (staging/production version) must be confirmed before any staging run.
- **Reuse:** `ExchangeRate` tie-break, `Money`/BCMath, the CHECK migration pattern (`2026_09_08_000001`), the guarded `down()` pattern (`2026_10_07_000002`).
- **Files:**
  - migrations 000001–000006;
  - `PrItemAward` (fillable / casts / acceptance helpers);
  - `PurchaseOrder::orderedLines()` + `App\Support\Procurement\OrderedLine` (resolver per §11.2, **not yet wired** into consumers);
  - new models `PrItemAwardEvent`, `MaterialReferencePrice`, `AwardReferencePriceSnapshot`;
  - `PurchaseRequisition` and `PurchaseOrder` fillable;
  - `config/procurement.php` (logistics options; flags `allow_offer_surplus` default `false`, `ordered_quantity_write_enabled` default `false` and `po_generation_enabled` default `true`), `App\Support\Procurement\LogisticsTerms`, `App\Support\Procurement\ProcurementFeatures` (single reader of the effective flags, §15 M04 flag-coupling rule);
  - `App\Support\Pricing\EvaluationContext`, `App\Services\Pricing\ExchangeRateResolver` (+ binding in `AppServiceProvider`);
  - logistics lang keys (EN/ID);
  - both `updateKurs` (`valid_from` business date);
  - **PO generation kill switch (I-20):**
    - check `config('procurement.po_generation_enabled')` (env `PROCUREMENT_PO_GENERATION_ENABLED`, default `true`) as the **first statement** of `PurchaseOrderGenerationService::generateFromAwards()`, before any lock or write;
    - when it is `false`, throw a dedicated `PoGenerationDisabledException` (extends `InvalidArgumentException`, so all three callers' existing `catch` blocks map it to a user-facing message without new controller logic);
    - no controller change is needed for atomicity: `QuotationListController::generatePo` (`:156`) and `PriceComparisonController::saveItemAwards` (`:1413`) call the service inside their own `DB::transaction(...)` closure, so the exception rolls back the whole outer transaction, including awards created by `awardBatch()` in the same closure, before their existing `catch (InvalidArgumentException)` renders the message. `AwardConsolidationController::store` (`:67`) maps it to a validation error the same way;
    - award saving without PO generation (`saveItemAwards` action `save`) stays allowed;
    - PO generation buttons render disabled with a notice while the switch is off (UI is non-authoritative);
    - operational note: toggling requires an env change + `php artisan config:clear`/`config:cache` — no code deploy;
  - **read-only Artisan command `procurement:reconcile --json`** (skeleton, extended by M02, M04-R, M06B, M08). Initial metric `po_generation_enabled`.
- **Tests:**
  - **kill switch on all three entry points (AC-40):**
    - with the switch `false`, `saveItemAwards` (`generate_pos`), `QuotationListController::generatePo` and `AwardConsolidationController::store` each create **no** PO, **no** `po_quotations` row and **no** `po_documents` rows, and leave **no** new award (the outer transaction rolls back) and no quotation status change;
    - each returns the maintenance message;
    - `saveItemAwards` action `save` still saves awards;
    - shipment submission, PO PDF and exports still work;
    - a static test asserts that no other `PurchaseOrder::create` call site exists in `app/` (guards the single-choke-point assumption);
  - `Unit/Pricing/ExchangeRateResolverTest`: future excluded; equal-date tie-break; IDR identity; missing → null; ≤0 ignored; BCMath cross-currency; one date per context;
  - `Feature/Procurement/OrderedLineContractTest`: all four precedence sources; legacy surplus rows;
  - `Feature/Procurement/ProcurementSchemaTest`: CHECKs;
  - migration rollback test (pattern `ShipmentMigrationRollbackTest`);
  - extend `ExchangeRateEffectiveDateTest` (business-date `valid_from` across UTC midnight).
- **Acceptance:** migrations up/down on the test DB; zero behavior change in `ItemLevelAwardTest`, `PriceComparisonItemAwardTest`, `Purchasing/SplitAwardPoGenerationTest`, `ShipmentAndPartialDeliveryTest`, `PurchaseOrderPdfTest`.
- **Deploy:** inert for business data. The kill switch defaults to `true`, so behavior is unchanged until an operator disables it. Ships in R1 so the switch exists before any later bundle.
- **Rollback:** `down()` guards (staging only). M01 code is not reverted after R2a, because the kill switch and resolver contracts are prerequisites of later bundles (§20).
- **STOP.**

### Mission 02 — PR, Excel Import and Logistics
- **Decisions:** 05 (PR), 06 (PR), 08 (PR template), 11, 12, 13. **Q5.**
- **Prerequisite:** M01.
- **Files:**
  - `SavePurchaseRequisitionRequest` (logistics, `required` on submit);
  - shared submission guard used by `store`, `update`, `submitDraft` (field-keyed `ValidationException`);
  - PR views (create/edit/show + footer totals);
  - `PrItemsImport` (irrelevant non-empty dimension → error with value);
  - `AbstractPreviewImport::preview()` (any error → `rows:[]`, 422);
  - `PrImportTemplateExport`;
  - lang;
  - **PO logistics guard + snapshot, moved here from M04-W (I-18):**
    - in `PurchaseOrderGenerationService::generateFromAwards()`, after the existing PR locks and per supplier group, compare `(port_of_discharge, incoterm)` across all participating PRs (NULL is a distinct value). On mismatch, reject with the Q1 message listing each PR number and its values (*example only:* "REQ/10/2026/001 (Tanjung Priok, CIF) ≠ REQ/10/2026/004 (Tanjung Perak, FOB)"). Never pick one; never split;
    - write the uniform values to `purchase_orders.port_of_discharge` / `incoterm` (migration 000002, M01) in the same transaction as `PurchaseOrder::create`;
    - this behavior is identical to approved Q1; only its delivery moves earlier;
  - **read-only display** of the PO logistics snapshot on Purchasing PO show and Supplier PO show ("–" when NULL), so persisted values are visible from R1. Exports and the **PDF** output (Q7) remain in M04-W; the PDF is gated by G-PDF;
  - `procurement:reconcile --json` metrics:
    - `submitted_pr_with_logistics_count`;
    - `po_missing_logistics_snapshot_count` = POs created after R1 activation whose PRs all carry logistics but whose PO snapshot is NULL (must be 0);
    - `po_mixed_logistics_count` = POs whose linked PRs disagree on logistics (must be 0 for POs created after R1).
- **Q5 template:**
  - headings: `material_name, shape, quantity, thickness, width, d_outer, d_inner, length, remark`;
  - example row reordered identically (`SCM440, Round, 10, null, null, 25, null, 6000, …`);
  - technical keys kept; no new aliases;
  - old-order files keep parsing (heading-based).
- **Note:** the `AbstractPreviewImport` change also affects `QuotationItemsImport`. Quotation import tests are updated in this mission for the response contract only. The Local imports use their own pipeline (*inferred*; confirm no subclassing).
- **Tests:**
  - update `MissionFiveImportTest` (`:106` headings, `:168` → errors, `:221` → `rows:[]` + 422);
  - new: draft w/o logistics saved; three submit paths rejected; invalid code; Flat+OD error (row, column, value); 95+5 → 0 rows; old-order PR file parses; footer totals;
  - **logistics window (AC-30, AC-42):**
    - on each of the three PO entry points, mixed-logistics awards are rejected with no PO created;
    - uniform logistics → PO created with the snapshot equal to the PR values;
    - two legacy NULL-logistics PRs → PO with a NULL snapshot (allowed, identical values);
    - NULL + valued → rejected;
    - the PO show pages display the snapshot.
- **Acceptance:** AC-05, AC-06, AC-07, AC-08 (PR side + PO snapshot), AC-29 (PR template), AC-30, AC-42.
- **Deploy:** R1, together with M01. PR logistics capture, the Q1 guard and the PO snapshot become live **in the same release** (I-18).
- **Rollback:** reverting R1 would remove the guard and snapshot while PRs keep their logistics. Allowed **only** while `submitted_pr_with_logistics_count = 0`; otherwise forward-fix only. Use the M01 kill switch (`po_generation_enabled=false`) as the first incident response.
- **STOP.**

### Mission 03 — Supplier Quotation and Purchasing Quotation UI
- **Decisions:** 01 (Supplier side), 05 (quotation import), 06, 08 (Quotation template), 09, 10, 14, 23, 24. **Q2 (display), Q5, Q8.**
- **Prerequisites:** M01, M02.
- **Scope change (fix 6.2):** M03 **does not** touch quotation MTC upload. The MTC input/column stays exactly as today until M05.
- **Files:**
  - `Supplier/QuotationController` (remove ceiling `:447`, `:711`; keep ≥1);
  - `QuotationItemsImport` (remove ceiling; irrelevant dimension → error);
  - `QuotationImportTemplateExport`: Q5 order `pr_item_id, material_name, requested_dimension, price_per_kg, available_qty, available_thickness, available_width, available_d_outer, available_d_inner, available_length, notes, availability, offered_weight_per_unit` (row array reordered identically; existing aliases retained, none added);
  - views: Supplier create/show, Purchasing quotation show, new shared read-only partial, comparison header (PIC, labels);
  - `resources/css/app.css` + `x-ui.data-table` `sticky` prop;
  - `QuotationListController::show` (eager-load, totals);
  - lang (Q8 keys + sentence copy + export heading).
- **Feature flag:** ceiling removal behind `ProcurementFeatures::surplusOffersAllowed()` (= `allow_offer_surplus AND ordered_quantity_write_enabled`), so surplus offers are only possible while the M04-W write path is active (R-2, I-21).
- **Tests:**
  - update `QuotationAvailabilityTest:646` (surplus accepted when the flag is on; rejected when off) and `:235` copy, `MissionFiveImportTest:106,511`;
  - new: Q5 quotation template order + old-order file parse;
  - Q8 exact strings in EN/ID on every mapped view + export heading; progress "Estimated Ready" unchanged;
  - section order; PIC; no N+1; read-only detail has no form; `TranslationParityTest`, `UserFacingCopyInventoryTest`.
- **Acceptance:** AC-01, AC-09, AC-10 (quotation), AC-27, AC-29 (quotation template), AC-36.
- **Deploy:** with M04-W (bundle R2b), after M04-R (R2a) is live.
- **STOP.**

### Mission 04 — Award, Ordered Quantity, PO, Exports and PDF
- **Decisions:** 01–04, 07, 11 (PO snapshot, outputs). **Q1, Q2, Q4C, Q7.**
- **Prerequisites:** M01–M03. Gate **G-PDF** (form-owner approval) must be satisfied before the PDF part is **released**; it may be implemented and visually verified beforehand.
- **Delivery in two separately deployable steps (rollback safety, I-17):**
  - **M04-R — read path:** behavior-identical for existing data. It contains:
    - every consumer wired to `orderedLines()` (reads frozen values when present, the legacy chain otherwise);
    - the Q4C transactional display fixes;
    - the **fail-closed legacy guard** in `generateFromAwards` (I-21). While the write path is absent (code not deployed) **or** disabled (`procurement.ordered_quantity_write_enabled=false`), the legacy path refuses an award in **either** of these cases:
      - **(a) unselected surplus/shortage:** the offered `available_qty` ≠ the PR requested quantity **and** no `ordered_qty` is persisted. Message: "Order quantity selection is required; PO generation for this line is temporarily unavailable".
      - **(b) selected but unfinalized:** the award is unassigned (`purchase_order_id IS NULL`) and carries **any** M04-W selection data (`ordered_qty IS NOT NULL` **or** `spec_accepted_at IS NOT NULL`), whatever the offered quantity. Message: "This line has a Purchasing-selected order quantity/specification acceptance that cannot be finalized while ordered-quantity PO generation is disabled".

      The legacy path cannot freeze `ordered_*`, re-validate the acceptance hash, or price a non-offer quantity. It must therefore **never** silently drop a selection by ordering `fulfillment_quantity`, and it never partially honors one. Refusal is all-or-nothing for the request: the transaction rolls back, no PO is created, and the award row is left unchanged.
    - **Stale-selection protection in `PrItemAwardService` (M04-R):** when an existing unassigned award is re-pointed to a different `quotation_item_id`, the same transaction nulls `ordered_qty` and all `spec_accepted_*` fields and records a `selection_cleared` event. A selection made for one offer can never later be applied to another offer.
    - Reconcile metric `unassigned_selected_award_count`, listing PR numbers and award IDs blocked by guard (b).

    M04-R is deployed first and verified with the characterization tests (legacy totals unchanged).
  - **M04-W — write path**, deployed after M04-R is live and **gated at runtime** by `procurement.ordered_quantity_write_enabled` (default `false`; enabled in the R2b release). When the flag is `false`, every code path behaves exactly as M04-R, including guard (a)/(b). M04-W can therefore be rolled back by configuration, without removing code that later missions build on (§20). It adds:
    - `order_qty` / `accept_spec` input, persistence, freezing of `ordered_*`, events;
    - the PDF/export logistics output (Q7; PDF behind G-PDF);
    - turning `procurement.allow_offer_surplus` on (set back to `false` whenever `ordered_quantity_write_enabled` is set to `false`).
  - **Flag-coupling rule:** the *effective* surplus permission is `allow_offer_surplus AND ordered_quantity_write_enabled`. It is read through one helper, `ProcurementFeatures::surplusOffersAllowed()` (support class from M01), never from the raw flag. An inconsistent env therefore fails closed (surplus refused) rather than crashing the application, and `procurement:reconcile --json` reports `inconsistent_feature_flags=true` so it is noticed.
  - **Already live from R1, and not part of either M04 step:**
    - the Q1 logistics guard and PO logistics snapshot (M02, I-18);
    - the `po_generation_enabled` kill switch (M01, I-20).

    Neither M04-R nor M04-W may remove or bypass them. Rolling back M04-W or M04-R leaves both in place, because they live in code delivered by R1.
- **Files:**
  - `PrItemAwardService` (`order_qty`, acceptance, events; re-point resets both);
  - `PurchaseOrderGenerationService`: I-1, I-4; freeze `ordered_*`; events. Preserve unchanged the existing **I-11 Q1** guard + PO logistics snapshot (from M02) and the kill-switch check (from M01) at the start of the method;
  - `PriceComparisonController::saveItemAwards`, `QuotationListController::generatePo`, `AwardConsolidationController` (+ `SaveItemAwardsRequest`): `order_qty`, `accept_spec`; consolidation shows port/incoterm columns;
  - wire `orderedLines()` into every consumer in §5.3 **(M04-R)**;
  - extend `procurement:reconcile --json` (created in M01) with `frozen_po_count` / `divergent_frozen_po_count` **(M04-R)**; M06B and M08 extend it further;
  - remove the flag default (allow surplus);
  - **Q4C transactional fixes:** `scopeWithResolvedTotalIdr` (NULL-safe aggregate §11.4), `PurchaseOrdersExport:139`, `PurchaseOrderDefinition:69`, `QuotationDefinition:54-55`, `QuotationDetailExport:41`, `QuotationsExport:123` → IDR identity or snapshot or `null` (blank / "Exchange Rate Unavailable" cell); confirm `purchasing/po/create` is unreachable (leave it or remove it from scope);
  - `PurchaseOrderPdf` + `pdf/po-pdf.blade.php` (two `.dates` rows; ordered qty/amount);
  - PO show and Supplier PO show (logistics, ordered vs PR Δ);
  - PO exports (logistics columns; Advanced Export catalog additive columns).
- **Tests:**
  - AC-02, 03, 04, 08 (PO), 10, 30 (Q1), 31 (Q7 PDF), 32 (Q2), 33 (Q4C);
  - the three PO paths × {missing acceptance, stale hash, qty 0, qty > offered, qty missing → 422, valid};
  - concurrency: two shipment submits on the remaining qty;
  - legacy PO totals identical (characterization before the change);
  - §11.2 consistency test;
  - extend `PurchaseOrderCreationConcurrencyAndInvariantTest`, `ItemLevelPoGenerationTest`, `QuotationDirectPoGenerationTest`, `PriceComparisonItemAwardTest`, `Purchasing/SplitAwardPoGenerationTest`, `ShipmentAndPartialDeliveryTest`, `PurchaseOrderPdfTest`, `AdvancedPurchaseOrderExportTest`, `DetailExportSecurityTest`.
- **PDF verification:** render PDFs for:
  - a 1-line PO;
  - a 3-supplier-line-address PO (long supplier block);
  - a multi-page PO;
  - a long-notes overflow PO;
  - a NULL-logistics legacy PO.

  Check for no overlap with the supplier block / items / page number, and identical totals (manual visual + text-extraction assertions).
- **Additional tests (rollback safety):**
  - with M04-R deployed and M04-W absent, a PO created by M04-W (fixture with frozen values and `ordered_qty < available_qty`) still shows the frozen qty/KG/amount in fulfillment, totals SQL, PDF, exports and `MaterialProgressService` (I-17);
  - the fail-closed legacy guard refuses surplus and shortage awards without `ordered_qty` on all three PO paths;
  - **selected-but-unassigned award across an M04-W rollback (AC-43, I-21)**, run with `ordered_quantity_write_enabled` toggled in-process and repeated in the M08 staging rehearsal on the R2a release tag:
    1. *(example fixture values)* PR qty 10, offer 15; with the flag `true`, save the award with `ordered_qty=12` and spec acceptance, without generating a PO.
    2. Set the flag `false`.
    3. Attempt PO generation on each of the three entry points. Each is refused with guard (b)'s message: no PO, no `po_quotations`/`po_documents`, the award row byte-identical (`ordered_qty=12`, acceptance intact), no quotation status change.
    4. The same award with offer == request (10/10) and `ordered_qty=10` is **also** refused, proving guard (b) does not depend on surplus.
    5. Reconcile lists the award in `unassigned_selected_award_count`.
    6. Re-pointing the award to another offer under flag `false` nulls `ordered_qty` + acceptance and writes a `selection_cleared` event.
    7. Set the flag `true` again. PO generation for the untouched award succeeds with qty 12 and frozen KG/amount equal to 12 × offered kg/unit × price (no value lost or recomputed from the offer);
  - **effective surplus flag:** `allow_offer_surplus=true` with `ordered_quantity_write_enabled=false` → Supplier surplus offers refused (fail-closed), reconcile reports `inconsistent_feature_flags=true`, application boots normally;
  - re-run the M01 kill-switch suite (AC-40) **in three deployment states** — after M04-R, after M04-W, and with M04-W reverted onto M04-R. In each state, `po_generation_enabled=false` blocks all three entry points with no PO, award or quotation-status side effects, and shipment submission, PDF and exports keep working;
  - re-run the M02 logistics suite (AC-30/AC-42) in the same three states;
  - `procurement:reconcile --json` reports `frozen_po_count` and `divergent_frozen_po_count` correctly.
- **Rollback strategy (replaces "code revert"):**
  - **Reconcile metrics:**
    - `frozen_po_count` = POs with any award where `ordered_amount IS NOT NULL`;
    - `divergent_frozen_po_count` = the subset where `ordered_qty ≠ fulfillment_quantity` or `ordered_amount ≠ resolved_amount` on any line.
  - **Safe rollback conditions:**

    | What is rolled back | Allowed when | Effect |
    |---|---|---|
    | M04-W by **configuration** (`ordered_quantity_write_enabled=false` + `allow_offer_surplus=false`) | Any time, including after R4/R5 are live (I-22). This is the **preferred and only tested** M04-W rollback mode. | Reads stay on frozen values (I-17). Guard (a)/(b) blocks unselected surplus/shortage and selected-but-unassigned awards (I-21). M06B snapshot insertion and M07 analytics keep working because they live in the shared generation/read path (AC-44). The kill switch (M01) and the logistics guard + snapshot (M02) remain active (I-18, I-20). |
    | M04-W by **code revert** (R2b) | **Only** while neither R4 (M06B) nor R5 (M07) has been deployed, **and** after setting both flags `false` and running AC-43 on the target tag | Same effects as the configuration rollback for data. Code-reverting M04-W underneath deployed M06B/M07 is **forbidden** (I-22): their generation hook and ordered-KG readers are built on M04-W code. |
    | M04-W code revert after R4 or R5 is live | **Forbidden** unless executed as a **coordinated rollback**: revert R5, then R4, then R2b, in that order, each step rehearsed on a restored staging copy with AC-40–AC-44 green and reconcile clean. Otherwise **forward-fix only**. | Prevents a deployed M06B/M07 from running against a code base missing the M04-W functions it calls. |
    | M04-R (read path) | **Only** when `procurement:reconcile --json` reports `frozen_po_count = 0` on production (i.e. M04-W never generated a PO) | Old consumers would compute from the offer, which is identical for legacy data. The kill switch and logistics guard remain (R1 code). |
    | M04-R when `frozen_po_count > 0` | **Forbidden** | A revert would make finalized POs revert to offer-based quantities and amounts (wrong shipment limits, PDFs and totals). |

  - **Incident procedure:**
    1. Set `po_generation_enabled=false` (env change + `config:clear`/`config:cache`, no code revert). The switch is enforced by R1 code, so it works regardless of which M04 step is currently deployed. New PO creation stops on all three entry points while reads stay correct.
    2. Run `procurement:reconcile --json` and record `frozen_po_count`, `divergent_frozen_po_count` and the affected PO numbers.
    3. If the incident is in the write path, set `ordered_quantity_write_enabled=false` and `allow_offer_surplus=false` (configuration rollback), then re-enable `po_generation_enabled`. Guard (a)/(b) keeps any remaining generation safe.
    4. Code revert only within the conditions in the table above (R2a: `frozen_po_count = 0`; R2b: R4/R5 not deployed). Otherwise **forward-fix only** — a hotfix on the current code, a regression test reproducing the incident, the targeted suites, deploy, then re-enable the flags.
  - **Safeguards for finalized POs:**
    - frozen `ordered_*` values are never recalculated, nulled or bulk-updated by any migration, command or rollback;
    - `down()` of migration 000003 refuses while frozen data exists (existing guard);
    - correcting a wrong finalized PO requires a separately approved, audited procedure (event row in `pr_item_award_events` with actor and reason) — never ad-hoc SQL;
    - shipments, QC and claims linked to a finalized PO keep using its frozen ordered quantity.
- **STOP.**

### Mission 05 — Shipment-Level MTC and Quotation MTC Retirement (atomic switch)
- **Decisions:** 21, 22. **Q6B.**
- **Prerequisites:** M01. Recommended after M04 (shared `ShipmentService`, different methods).
- **Release-sequence contract (fix 6.2):** removal of the **new** quotation MTC input and introduction of Shipment MTC ship **in the same mission and release (R3)**, so Suppliers always have exactly one place to upload MTC. Historical quotation MTC stays readable and downloadable. Draft/revision quotation saves keep re-parenting existing attachments (`QuotationController:772-778`) so no legacy file is deleted by a save.
- **Files:**
  - `ShipmentService`: `uploadMtc()` (lock shipment; status ∈ {draft, submitted}; owner; inspect `'mtc'`; transactional + cleanup); `removeMtc()` (lock; status = draft; owner; attachment belongs to shipment; delete row, then file after commit); `submitShipment` guard (≥1 VALIDATED, counted after the lock); `mtc_files[]` accepted on store/update before an in-request submit;
  - `SupplierShipmentController` + routes;
  - `ShipmentPolicy::uploadMtc/deleteMtc`; `AttachmentPolicy` Shipment arm; `Shipment::mtcAttachments()`;
  - `Supplier/QuotationController` (drop `items.*.mtc_file` / `copy_from_attachment_id` writes; keep re-parenting);
  - Supplier quotation create view (remove the MTC upload column);
  - shipment views (`data-async-submit`); Purchasing shipment/PO views (MTC list);
  - lang.
  - The `AGENTS.md` upload-table amendment (Appendix A-2) is applied in this mission **with explicit approval at mission time**.
- **State matrix (Q6B):**

  | Status | Upload | Delete | Notes |
  |---|---|---|---|
  | draft | ✓ | ✓ | MTC optional |
  | submitted | ✓ (append) | ✗ | Deletion refused (422) |
  | arrived | ✗ **fail-closed** (policy unresolved, T-6) | ✗ | Refused with "MTC changes are not available for this shipment status" (422). Existing MTC stays viewable/downloadable under current permissions. |
  | cancelled | ✗ | ✗ | Refused (422); existing MTC stays viewable |
  | any other / future status | ✗ | ✗ | Default deny |

- **Fail-closed rule (T-6 stays unresolved):**
  - `ShipmentPolicy::uploadMtc` and `deleteMtc` are **allow-lists**: upload = {draft, submitted}, delete = {draft}. Every other status is denied by default, and the service re-checks the same allow-list under the shipment lock.
  - No additional Supplier permission is introduced for `arrived` or later states: no upload, no delete, no replace, no request/approval flow. Purchasing gains no MTC upload/delete capability either.
  - Read access (list/download through `AttachmentPolicy` + `FileAccessGuard`) is unchanged for all states.
  - Any future change to the `arrived` policy requires a separate business decision (T-6) and its own change. Until then, this matrix is the implemented behavior, and it is covered by tests that assert refusal in `arrived` and `cancelled`.

- **Concurrency:**
  - remove-last vs submit: both lock the shipment row; submit counts after the lock, so it is never submitted with zero;
  - upload vs `confirmArrival`: both lock; upload re-checks status, so a post-arrival upload is refused;
  - upload vs cancel: same.
- **Tests:**
  - AC-11, 12, 13, 14, 34 (Q6B);
  - partial shipments each need their own MTC;
  - the four customs documents and `customsDocumentationSummary` unchanged;
  - cross-supplier list/download/delete denied;
  - legacy quotation MTC downloadable;
  - quotation save keeps legacy attachments;
  - rework `QuotationMtcReplacementLifecycleTest`, `SupplierMtcBatchCopyTest`, `MissionFiveImportTest::test_revision_requested_preview_preserves…mtc_attachment`;
  - extend `ShipmentDocumentUploadStateTest`, `ShipmentDocumentsAndQcIntegrationTest`, `PoCustomsDocumentationShipmentSyncTest`, `AttachmentAuthorizationBoundaryTest`, `HashidUrlSecurityTest`.
- **Deploy:** release R3 with a Supplier notice ("MTC now attached per shipment; required before submit; drafts in progress need MTC").
- **Rollback:** code revert restores quotation MTC upload. Shipment MTC rows stay (readable by Purchasing).
- **STOP.**

### Mission 06A — Purchasing Access to Material & HS Code (incl. guarded delete)
- **Decision:** 28. **Q3.**
- **Prerequisite:** none (independent; scheduled sequentially).
- **Files:**
  - migration 000007 + `MasterDataAuditLog` model;
  - policies;
  - FormRequests;
  - `MasterDataDeletionService`;
  - `Admin/MaterialMasterController`, `HsCodeRuleController`, `MaterialHsCodeController` (`Gate::authorize`, `destroy`, audit on create/update/status/delete);
  - routes (purchasing group + admin delete);
  - `admin/material-hs-code/*` (route prefix, delete action);
  - sidebar (Purchasing → Master Data);
  - `config/quick_access.php` (optional);
  - lang.
  - The `AGENTS.md` amendment for policies/roles (Appendix A-3) needs approval at mission time.
- **Tests:**
  - authz matrix (admin ✓, purchasing ✓ on both modules incl. delete; purchasing ✗ on unrelated admin routes; supplier/qc/finance ✗);
  - unreferenced delete ✓ + audit `deleted`;
  - referenced by PR item / alias / reference price → 422 + `delete_refused` audit + row intact;
  - **durability of `delete_refused` (I-16):** after a refused delete, the audit row exists and is visible to a **separate DB connection** (not only the test's connection). After a forced FK-backstop refusal (a child row committed by a subprocess worker between the reference check and the DELETE), the deletion transaction is rolled back **and** the `delete_refused` row (reason `fk_restrict`) still persists. A successful delete whose audit insert is forced to fail leaves the master row intact (atomic `deleted` audit);
  - concurrent "insert PR item referencing material" vs delete → exactly one outcome, no orphan (subprocess worker);
  - HS rule referenced by PR item → refused;
  - extend `AdminMaterialHsCodeTest`.
- **Acceptance:** AC-25, AC-26, AC-35.
- **STOP.**

### Mission 06B — Master Reference Price and PO Finalization Snapshot
- **Decisions:** 25–27, 29 (detail), 30–33. **Q6A, Q9.**
- **Prerequisites:** M01, M04, M06A.
- **Files:**
  - `MaterialReferencePriceService`, `ReferencePriceResolver`, `MaterialReferencePricePolicy`;
  - `Purchasing/MaterialReferencePriceController` + FormRequests (save, supersede, void);
  - views;
  - `PurchaseOrderGenerationService` (snapshot insert per §12.2, inserted after the existing kill-switch check and logistics guard). The insert lives in the **shared** generation path, so it runs whether `ordered_quantity_write_enabled` is `true` or `false` (I-22). When the flag is `false`, guard (a)/(b) runs first, so only lines the legacy path may legitimately order reach the insert;
  - `QuotationListController::show` + shared partial (reference columns);
  - Purchasing PO show: a "Reference price at finalization" panel rendering snapshot rows, or "Reference snapshot not captured — PO finalized before Reference Price was available" when the PO has no snapshot rows (§12.2 pre-activation rule). It never calls the live resolver for a finalized PO;
  - `procurement:reconcile --json` metric `po_missing_reference_snapshot_after_activation`;
  - `DecodeHashids::HASHED_PARAM_KEYS` if a raw parameter is used;
  - lang.
  - **Explicitly not delivered:** any backfill migration, seeder or command for historical POs.
  - Appendix A-4 (Hashids/policies lists) needs approval at mission time.
- **Tests:**
  - AC-15–18, AC-28, AC-37 (Q6A), AC-41 (pre-activation POs);
  - overlap matrix (`[100,150]`∩`[150,200]` overlap; `[100,149.9999]` vs `[150,200]` none; open-ended dates);
  - concurrency worker (two overlapping inserts → one `ValidationException`);
  - supersede/void history;
  - every reason code;
  - Supplier denied;
  - PO generation succeeds with `not_available`;
  - **pre-activation POs (I-19):**
    - a PO created before deploying M06B (fixture: PO + awards, no snapshot rows) shows "not captured";
    - creating, superseding and voiding reference prices that would match its lines afterwards creates **no** snapshot rows and changes nothing on that PO page;
    - the snapshot row count for pre-existing POs is unchanged after the migrations and all M06B code paths run;
  - **post-activation completeness:** every PO generated after activation has exactly one snapshot row per award (incl. `not_available`); reconcile `po_missing_reference_snapshot_after_activation = 0`;
  - **M04-W configuration rollback while R4 is live (AC-44, I-22):** with `ordered_quantity_write_enabled=false`, a legacy-eligible PO (offer == request, no selection) is generated with exactly one snapshot row per award; guard (a)/(b) still refuses ineligible lines before any snapshot is written; existing snapshots and frozen POs are unchanged; switching the flag back on resumes normal generation + snapshots.
- **Deploy:** R4. The activation point is the R4 deploy; POs finalized earlier remain "not captured" permanently. Requires R2b deployed (code); compatible with R2b in either flag state.
- **Rollback:** code revert, only together with R5 if R5 is live (revert R5 first), or forward-fix. Snapshot rows already written stay immutable. While reverted, new POs get no snapshot ("not captured"). After re-deploy, the reconcile check counts from the earliest existing snapshot, so record the revert window's PO numbers as known gaps. They are **not** backfilled. Never code-revert R2b beneath a deployed R4 (I-22).
- **STOP.**

### Mission 07 — Exchange Rate Benchmark, Price History and Comparison
- **Decisions:** 15–20, 29 (comparison), 34, 35 (analytics). **Q4A/B, Q9.**
- **Prerequisites:** M01, M04, M06B; the user's uncommitted comparison-view/test edits committed or acknowledged.
- **Files:**
  - `PurchaseHistoryPriceService` (§12.3 contract);
  - `PriceComparisonController` (`interSupplier` indicators; `historical` rework; `buildVsBestQuery` with per-currency evaluation rates bound as `CASE currency WHEN ? THEN ?` and NULL for unavailable — no `COALESCE(…,1)`; same `EvaluationContext` throughout);
  - comparison views + tabs component + chart JS;
  - evidence-gated indexes;
  - **`AGENTS.md` amendment Appendix A-1** — approved in principle by Q4, applied in this mission only after explicit confirmation at mission start; not applied in Gate 1.
- **Tests:**
  - AC-19–24, AC-38 (multi-currency weighted average);
  - AC-39 (single evaluation date even when the request spans 00:00 WIB: inject a frozen clock, change time mid-request, all values use the first date);
  - draft/rejected/unawarded excluded; cancelled/soft-deleted POs excluded; no pivot duplication; Hollow ignores ID; range inclusive; future-dated rate ignored; unavailable currency excluded with counts; snapshot IDR values unchanged;
  - update `PriceComparisonItemAwardTest`, `PriceComparisonServerTabsTest` (preserving the user's pending assertions), `PriceComparisonPerformanceRegressionTest`, `RegionalComparisonDisplayTest`;
  - **M04-W configuration rollback while R5 is live (AC-44, I-22):** with `ordered_quantity_write_enabled=false`, weighted averages, historical best and series are identical before and after the flag change (history reads frozen and legacy lines only; no write-path code is called); the inter-supplier award form hides the Order Qty input, and generation is guarded by (a)/(b).
- **Performance:** `EXPLAIN` on ≥50k quotation items before adding indexes.
- **Deploy:** R5. Requires R2b and R4 deployed (code); compatible with R2b in either flag state.
- **Rollback:** code revert (analytics return to snapshot behavior; `AGENTS.md` A-1 reverted). R5 must be reverted **first** in any coordinated rollback of R4 or R2b (I-22).
- **STOP.**

### Mission 08 — Integration, Regression and Release Readiness
- **Scope:**
  - end-to-end AC-01–AC-44 on a restored staging copy;
  - full serial suite;
  - `php artisan local-invoices:reconcile --json` (sanity);
  - complete the read-only `procurement:reconcile --json` (created in M01 with `po_generation_enabled`; the logistics metrics come from M02, the frozen metrics from M04-R and the reference-snapshot metric from M06B, so every rollback gate is measurable from its own release onward). Full report: `po_missing_logistics_snapshot_count` (0); `po_mixed_logistics_count` (0); `po_missing_reference_snapshot_after_activation` (0); legacy-fallback counts by `source`; `frozen_po_count`; `divergent_frozen_po_count` with PO numbers; PO totals whose old vs new expressions differ (must be 0 for legacy); shipments over ordered qty (0); reference overlaps (0); foreign POs without a snapshot (reported, never altered);
  - T-1 check; migrations dry-run and rollback on staging;
  - **rollback rehearsal on staging (I-21, I-22):**
    1. configuration rollback of M04-W with R4 + R5 live (AC-44);
    2. AC-43 executed on the R2a release tag against a database produced by R2b (selected-but-unassigned awards present);
    3. one coordinated R5 → R4 → R2b code rollback and re-deploy, with AC-40–AC-44 green and reconcile clean (`unassigned_selected_award_count` reported, `inconsistent_feature_flags=false`);
  - `route:list`, `view:cache`, `npm.cmd run build`;
  - browser/mobile/dark/keyboard QA;
  - PDF visual check incl. G-PDF evidence;
  - release notes (Supplier: surplus offers allowed, MTC per shipment, label; Purchasing: order qty, spec acceptance, consolidation rule, reference prices, master delete);
  - consolidated `AGENTS.md` diff (only the approved Appendix A items).
- **STOP:** a production deploy needs separate approval + backup.

---

## 16. Dependencies, Ordering and Release Bundles

```
M01 ─► M02 ─► M03 ─► M04 ─► M05 ─► M06A ─► M06B ─► M07 ─► M08
                      (M06A has no technical dependency and may move earlier; kept sequential for one test DB)
```

**Hard dependencies:**
- M03←M02 (shared preview contract).
- M04←M01, M02, M03.
- M05←M01 (recommended after M04).
- M06B←M04, M06A.
- M07←M04, M06B.
- No cycles.

**Release bundles:**

| Bundle | Missions | Constraint |
|---|---|---|
| R1 | M01 + M02 | M01 and M02 ship **together, never separately**: PR logistics capture, the Q1 guard, the PO logistics snapshot + PO-show display (I-18), the kill switch (I-20) and reconcile skeleton all go live in this release. AC-30, AC-40 and AC-42 must be green before release. |
| R2a | M04-R (read path + fail-closed legacy guard + Q4C displays) | Only after R1; characterization tests green; behavior-identical for existing POs; kill switch and logistics guard (R1) untouched |
| R2b | M03 + M04-W (write path) | Only after R2a is live; `ordered_quantity_write_enabled` then `allow_offer_surplus` turned on in this release; logistics in exports/PDF (PDF only after G-PDF) |
| R3 | M05 | Atomic MTC switch + Supplier notice |
| R4 | M06A + M06B | The R4 deploy is the Reference Price activation point. POs finalized in R1–R3 stay "not captured" (I-19); no backfill. |
| R5 | M07 | After the user's comparison edits are resolved; `AGENTS.md` A-1 confirmed |

**Release dependency and rollback-mode table (I-22):**

| Bundle | Depends on (code) | Depended on by | Rollback while dependents are **not** deployed | Rollback once dependents **are** deployed |
|---|---|---|---|---|
| R1 | — | R2a, R2b, R3, R4, R5 | Code revert only if `submitted_pr_with_logistics_count = 0` | Forbidden; forward-fix (kill switch first) |
| R2a | R1 | R2b, R4 (M06B hook in generation path), R5 (M07 ordered-KG reads) | Code revert only if `frozen_po_count = 0` | Forbidden; forward-fix |
| R2b | R1, R2a | R4 (M06B snapshot insert sits in the generation method M04-W modifies), R5 (M07 reads frozen ordered KG and uses the M04 resolver) | Configuration rollback (preferred) **or** code revert after AC-43 on the target tag | **Configuration rollback only** (`ordered_quantity_write_enabled=false`, `allow_offer_surplus=false`). A code revert only as a coordinated R5 → R4 → R2b rollback rehearsed on staging with AC-40–AC-44 green; otherwise forward-fix |
| R3 | R1 | — | Code revert (quotation MTC upload returns) | n/a |
| R4 | R1, R2a, R2b | R5 (reference overlay) | Code revert; snapshots immutable; gaps recorded | Revert only together with R5 (R5 first), or forward-fix |
| R5 | R1, R2a, R2b, R4 | — | Code revert (analytics return to snapshot behavior; `AGENTS.md` A-1 reverted) | n/a |

**Sequencing:** strictly sequential execution (shared `adasi_portal_test`; shared `lang/*`, `routes/web.php`, sidebar).

---

## 17. Acceptance Criteria and Testing Matrix

| AC | Given / When | Then | Mission |
|---|---|---|---|
| AC-01 | PR 10; Supplier offers 15 | Saved; "Surplus +5"; PR stays 10 | 03 |
| AC-02 | Purchasing orders 12 of 15 | PO qty 12, KG = 12 × offered kg/unit, amount = KG × price; remainder 3 never ordered | 04 |
| AC-03 | Shipments 7, 5, then 1 | 7 + 5 OK; +1 rejected; concurrent pair cannot exceed 12 | 04 |
| AC-04 | Offer thickness 105 vs PR 100 | PO blocked until acceptance; event + snapshot; stale hash blocks | 04 |
| AC-05 | Excel Flat + OD value | 422, `rows:[]`, error row/column/value | 02, 03 |
| AC-06 | 95 valid + 5 invalid | 0 rows; ≥5 errors | 02, 03 |
| AC-07 | Draft without logistics; submit via 3 paths | Draft saved; every submit rejected | 02 |
| AC-08 | PR Perak + FOB | Supplier sees; PO snapshot; on PO detail/export/PDF | 02, 04 |
| AC-09 | Long/wide tables | Header and Material sticky; focus visible; dark mode legible | 03 |
| AC-10 | Tampered client totals | Server totals shown and persisted | 03, 04 |
| AC-11 | New quotation after R3 | No MTC control; submit allowed | 05 |
| AC-12 | Draft shipment without MTC | Save OK; all 3 submit paths blocked | 05 |
| AC-13 | Shipment with 2 MTC | Submitted; owner + Purchasing download; other Supplier 403 | 05 |
| AC-14 | Legacy quotation MTC | Listed and downloadable on both sides; quotation save keeps it | 05 |
| AC-15 | Overlapping reference range and date | Rejected, including concurrent insert | 06B |
| AC-16 | (*example fixture months*) v1 ends Sep, v2 starts Oct | Both kept; Sep → v1, Oct → v2 | 06B |
| AC-17 | PR 100, offered 155 | Band containing 155 | 06B |
| AC-18 | No reference | "Reference Price Not Available" + reason; no Δ; PO allowed | 06B |
| AC-19 | PO 100 kg @50k + 300 kg @70k (IDR) | WA = 65k | 07 |
| AC-20 | Hollow filter | OD only; ID displayed, not filtered | 07 |
| AC-21 | USD + JPY offers, IDR selected | Converted at evaluation-date rates; originals shown | 07 |
| AC-22 | Rate `valid_from` tomorrow | Not used | 01, 07 |
| AC-23 | JPY benchmark missing | "Exchange Rate Unavailable"; excluded with count; IDR identity unaffected | 07 |
| AC-24 | New benchmark inserted | Document snapshot IDR totals unchanged | 07 |
| AC-25 | Purchasing on Material/HS Code; on `admin.users` | Allowed; 403 | 06A |
| AC-26 | Delete referenced master | 422 with counts; row intact; audit `delete_refused` | 06A |
| AC-27 | Supplier quotation pages | Section order; behaviors preserved | 03 |
| AC-28 | Master changed after PO | PO reference snapshot unchanged | 06B |
| AC-29 ➕ | Download PR and Quotation templates (Q5) | Technical keys unchanged; dimensions ordered Thickness → Width → OD → ID → Length; old-order files parse identically | 02, 03 |
| AC-30 ➕ | Consolidate awards from PRs (Priok, CIF) + (Perak, FOB) (Q1) | Rejected on every path; message lists both PRs and values; no PO, no split; identical logistics → one PO with that snapshot | 02 (re-verified in 04) |
| AC-31 ➕ | PO PDF with logistics (Q7) | Rows "Port of Discharge"/"Incoterms" in the header block; no overlap/clipping; pagination and totals identical to the baseline; NULL legacy → "-" | 04 |
| AC-32 ➕ | Award form for PR 10 / offer 15 (Q2) | Order Qty pre-filled 10; editable 1–15; 0 / 16 / missing → 422; PR qty and offer unchanged; persisted value drives KG, amount, shipment limit, PDF, exports | 04 |
| AC-33 ➕ | USD PO without snapshot rate (Q4C) | PO list IDR = "—"/"Exchange Rate Unavailable"; original USD shown; exports blank IDR cell (not 0); mixed-line PO total unavailable (not partial); IDR PO total = amount | 04 |
| AC-34 ➕ | MTC state rules (Q6B) | Draft: upload + delete; Submitted: upload OK, delete 422; Arrived/Cancelled/any other status: upload **and** delete refused (fail-closed, 422) while download still works; delete-last vs submit race never yields a submitted shipment without MTC | 05 |
| AC-35 ➕ | Delete unreferenced material/HS rule (Q3) | Deleted by Admin or Purchasing; audit `deleted` with before-snapshot; concurrent referencing insert cannot leave an orphan | 06A |
| AC-36 ➕ | Labels (Q8) | EN "Supplier Estimate Delivery", ID "Estimasi Pengiriman Supplier" on all mapped views and the export heading; "Estimated Ready" progress labels unchanged; parity green | 03 |
| AC-37 ➕ | Offered thickness blank, PR thickness present (Q6A) | "Reference Price Not Available — Offered Dimension Missing"; PR dimension not used; offer data visible; PO allowed | 06B |
| AC-38 ➕ | History (*example fixture values*): IDR 100 kg @50,000 + USD 300 kg @4.00 with benchmark USD = 16,000 IDR effective on or before the frozen evaluation date | WA(IDR) = (5,000,000 + 19,200,000) / 400 = 60,500; equals per-line computation; JPY line without rate excluded and reported | 07 |
| AC-39 ➕ | Comparison request across 00:00 WIB (Q9) | All indicators, reference deltas and chart use the single resolved date. The UI shows that date (asserted equal to `BusinessTime::today()` under a frozen clock, run for at least two different frozen dates, never a literal) and the rate effective dates | 07 |
| AC-40 ➕ | `po_generation_enabled=false` (I-20) | `saveItemAwards` (`generate_pos`), `QuotationListController::generatePo` and `AwardConsolidationController::store` each refuse with the maintenance message. No PO, `po_quotations`, `po_documents`, new award or quotation-status change persists. `saveItemAwards` (`save`), shipment submit, PDF and exports work. Verified after R1, after R2a, after R2b and with M04-W reverted | 01 (re-run 04) |
| AC-41 ➕ | PO finalized before R4; reference prices created afterwards (I-19) | PO page shows "Reference snapshot not captured"; no snapshot rows are created for it, by any migration, command or reference-price write; POs generated after R4 have one snapshot row per award | 06B |
| AC-42 ➕ | Deployment window R1 → R2b (I-18) | Every PO generated in R1 or R2a from PRs with logistics has a non-NULL logistics snapshot equal to the PR values and is visible on PO show. Mixed logistics are rejected on all three paths. Reconcile `po_missing_logistics_snapshot_count = 0` and `po_mixed_logistics_count = 0` | 02 (re-run 04) |
| AC-43 ➕ | Award selected under M04-W (*example fixture:* PR 10, offer 15, `ordered_qty=12`, spec accepted), unassigned; then `ordered_quantity_write_enabled=false` (I-21) | All three PO entry points refuse with guard (b): no PO/pivot/documents; award row unchanged; quotation status unchanged; also refused when offer == request. Reconcile lists it in `unassigned_selected_award_count`. Re-pointing under the flag clears the selection with a `selection_cleared` event. Flag back on → PO with qty 12 and frozen KG/amount from the selection. Repeated on the R2a tag in the M08 staging rehearsal | 04 (re-run 08) |
| AC-44 ➕ | Configuration rollback of M04-W while R4 and R5 are live (I-22) | M06B: legacy-eligible POs still get one snapshot row per award; ineligible lines refused before snapshotting. M07: analytics values unchanged by the flag change. Frozen POs and existing snapshots untouched. Kill switch and logistics guard active. Coordinated rollback order R5 → R4 → R2b rehearsed once on staging with AC-40–AC-44 green | 06B, 07 (rehearsal 08) |

**Negative, authorization, concurrency, legacy and rollback cases:**
- Every mission includes 403 tests for the other roles and Supplier cross-ownership tests.
- Concurrency uses subprocess workers (`tests/Support/*-worker.php` pattern) for AC-03, AC-15, AC-34 and AC-35.
- Legacy characterization snapshots are taken before M04 and M07.
- Every new migration has a rollback test.

**Test environment:**
- `adasi_portal_test` (MySQL); run `TestingEnvironmentDatabaseSafetyTest` first.
- Never run in parallel.
- Only `UserFactory` exists, so fixtures use `Model::create([...])`.
- `phpunit-results.log` / `test-results.log` are not baselines.
- **No test was executed in Gate 1.**

---

## 18. Backward Compatibility and Data Preservation

- **Routes:** names and URLs unchanged; additions only (`purchasing.master-data.*`, admin delete, `supplier.shipments.mtc.*`, reference prices).
- **Never rewritten:**
  - `quotation_items.amount`;
  - document `exchange_rate_id`;
  - PO currency/rate;
  - shipment rows, QC, claims;
  - historical quotation MTC files (preserved and downloadable; quotation saves keep them attached).
- **Legacy reads:**
  - ordered values via the §11.2 chain (legacy PO totals identical);
  - logistics NULL → "–" / "-" in the PDF;
  - legacy consolidation of two NULL-logistics PRs remains allowed (identical values);
  - legacy materials without a master → `material_unmapped`.
- **Excel compatibility:** previous-order spreadsheets still import (heading-based); headings unchanged (Q5).
- **Intentionally removed behaviors** (tests updated in the same mission):
  - quantity ceiling (M03/M04);
  - silent dimension drop (M02/M03);
  - quotation MTC upload (M05);
  - old template order (M02/M03);
  - `COALESCE(rate,1)` analytics (M07);
  - `rate,1` / `?? 0` transactional fallbacks (M04).

---

## 19. Performance Considerations

- **History:**
  - SQL aggregation per `(period, currency)` over award lines;
  - PHP conversion of ≤4 currency sums per period;
  - no per-row conversion;
  - no unbounded `get()`.
- **vs-best:** server-side DataTables with bound rate parameters.
- **Resolver memo:** ≤4 rate queries per request.
- **Reference resolution per quotation:** one candidate query (materials × shapes × currencies effective on the date), matched in memory.
- **Delete reference checks:** indexed FK columns (`pr_items.material_master_id`, `material_aliases.material_master_id`, `pr_items.hs_code_rule_id` exist as FKs; InnoDB auto-indexes FKs).
- **PDF:** two extra wrapped rows; negligible.
- **Indexes:** only after `EXPLAIN`.

---

## 20. Deployment and Rollback Strategy

1. Back up production → restore to staging → confirm T-1 → apply migrations 000001–000006 (inert) and 000007 with R4.
2. Deploy R1, R2a, R2b, R3, R4, R5 in order, each behind its STOP gate. Run `procurement:reconcile --json` before R2a, after R2a, after R2b and after R5.
3. **Rollback per bundle = code revert, subject to the conditions below.** Schema remains (additive, inert).

   | Bundle | Rollback specifics |
   |---|---|
   | R1 | Revert M01+M02 together, never one alone. Allowed **only** while `submitted_pr_with_logistics_count = 0`. Otherwise forbidden (it would remove the Q1 guard and PO snapshot while PRs carry logistics, I-18); forward-fix only. First response is always `po_generation_enabled=false`. |
   | R2a | Allowed **only** if `frozen_po_count = 0`. Otherwise forbidden (I-17); forward-fix only (Mission 04 rollback strategy). The kill switch and logistics guard (R1) stay active. |
   | R2b | **Configuration rollback any time** (`ordered_quantity_write_enabled=false`, `allow_offer_surplus=false`). R2a stays, so finalized POs keep their frozen values (I-17), and guard (a)/(b) blocks unselected surplus/shortage and selected-but-unassigned awards (I-21). **Code revert** only while R4/R5 are not deployed (after AC-43 on the target tag); once R4/R5 are live, only via a coordinated R5 → R4 → R2b rollback rehearsed on staging, otherwise forward-fix (I-22). Prefer `po_generation_enabled=false` as the first incident response. Record the affected PO numbers and the `unassigned_selected_award_count` list. |
   | R3 | Quotation MTC upload returns; shipment MTC remains readable. |
   | R4 | Audit and reference rows remain. Existing snapshots stay immutable. POs finalized while M06B is reverted are recorded as known "not captured" gaps (never backfilled, I-19). If R5 is live, revert R5 first, or forward-fix. |
   | R5 | Analytics return to the previous (snapshot) behavior. The `AGENTS.md` amendment is reverted with it. R5 is always reverted first in a coordinated rollback (I-22). |

   `down()` runs only on staging and refuses when data exists.
4. **Post-deploy:** `php artisan optimize:clear`, restart the workers, smoke-test PR → quotation → award → PO (PDF) → shipment (MTC) → QC.

---

## 21. Risk Register

| ID | Risk | L | I | Mitigation | Mission |
|---|---|---|---|---|---|
| R-1 | A consumer bypasses `orderedLines()` → inconsistent qty/totals | M | H | Single resolver (I-15); grep gate for `fulfillment_quantity`/`resolved_amount` in PO contexts; consistency test; reconcile command | 04 |
| R-2 | Surplus offers reach POs before ordered qty exists | M | H | `allow_offer_surplus` flag default false until M04-W; R2a before R2b; fail-closed legacy guard | 03/04 |
| R-3 | `AGENTS.md` snapshot rule vs Q4 analytics | H | M | Q4 approved; Appendix A-1 applied in M07 after confirmation; snapshot layer untouched | 07 |
| R-4 | Mixed-logistics consolidation (Q1) | M | M | Reject in the service (I-11) + UI pre-warning; legacy NULL treated as a value | 04 |
| R-5 | Stale spec acceptance | L | H | Hash check at generation; recreated items get new IDs; existing award lock blocks Supplier edits | 04 |
| R-6 | Concurrent overlapping reference prices | M | M | `material_masters` lock + worker test | 06B |
| R-7 | Legacy quotation MTC lost on save after M05 | M | M | Keep re-parenting; dedicated test | 05 |
| R-8 | MTC requirement blocks in-flight drafts | M | M | Supplier notice; clear per-path error; append-only after submit | 05 |
| R-9 | `updateKurs` stores the UTC date | H | M | Business-date fix in M01 + test | 01 |
| R-10 | Fabricated rates (`1`, `0`) in transactional totals/exports (Q4C) | H | M | NULL-safe aggregate; `—` display; AC-33; reconcile reports foreign POs without a snapshot | 04 |
| R-11 | CHECK unsupported on staging/production MySQL | L | M | T-1; app validation remains primary | 01 |
| R-12 | 2,773-line view regresses on reorder | M | M | Rendered-order tests; IDs kept; browser QA | 03 |
| R-13 | Hard delete races / data loss (Q3) | L | H | Row lock + reference checks + FK RESTRICT backstop + audit + concurrency test | 06A |
| R-14 | Legacy items without `material_master_id` | M | L | `material_unmapped` reason; `material_name` fallback in history (labelled) | 06B/07 |
| R-15 | Shared test DB used concurrently by other sessions | M | M | Process check before every run | all |
| R-16 | Partially inspected areas: full `PurchaseOrderDetailExport`, `supplier/po/show`, `purchasing/po/show`, `SupplierShipmentController::store/update`, `MaterialProgressService`, Local import base classes, `purchasing/po/create` reachability | M | M | Each mission re-reads its files first; T-5 | all |
| R-17 ➕ | PDF layout change breaks pagination or needs form-owner approval (Q7) | M | M | Geometry headroom ≈ 8 mm (inferred); multi-scenario visual test; gate G-PDF before release | 04 |
| R-18 ➕ | Seeder re-run re-creates deleted masters (Q3) | L | L | Document in release notes; seeders not run in production routinely | 06A |
| R-19 ➕ | `arrived`-state MTC handling unspecified (Q6B) | M | L | Fail-closed allow-lists (upload {draft, submitted}, delete {draft}); `arrived`/`cancelled`/other statuses denied; no new Supplier/Purchasing permission; T-6 kept open | 05 |
| R-20 ➕ | History normalized at today's rate is misread as the historical IDR paid | M | M | UI labels "converted at rates as of {evaluation date}"; document-snapshot IDR shown separately in tables | 07 |
| R-21 ➕ | A code revert of M04 makes finalized POs fall back to offer-based quantities/amounts (wrong shipment limits, PDFs, totals) | M | H | Two-step delivery M04-R / M04-W; M04-R never reverted while `frozen_po_count > 0` (I-17); M04-W rolled back by configuration (I-22); fail-closed legacy guard (a)/(b) (I-21); `po_generation_enabled` kill switch; forward-fix procedure; no recalculation of frozen values | 04 |
| R-22 ➕ | A refusal audit lost through transaction rollback (Q3) | L | M | Two-phase delete; `delete_refused` written after the deletion transaction (I-16); durability test from a separate connection | 06A |
| R-23 ➕ | Deployment window in which PRs carry logistics but PO generation neither validates nor snapshots them | M | H | Q1 guard + snapshot delivered by M02 in R1 with PR logistics (I-18); R1 ships atomically; R1 rollback gate; AC-42; reconcile metrics | 02 |
| R-24 ➕ | POs finalized before Reference Price activation are retroactively "priced" or backfilled | L | M | Absence = "not captured" (I-19); PO pages never call the live resolver; no backfill tooling; AC-41 | 06B |
| R-25 ➕ | Kill switch lost after an M04 rollback, or bypassed by one entry point | L | H | Enforced at the start of `generateFromAwards` from R1 (I-20); static test for a single `PurchaseOrder::create` call site; AC-40 run in four deployment states | 01 |
| R-26 ➕ | After an M04-W rollback, the legacy path silently ignores a persisted `ordered_qty`/acceptance (orders the full offer) or applies a stale selection to a different offer | M | H | Guard (b) refuses every unassigned award with selection data (I-21); re-point clears the selection; `unassigned_selected_award_count` reconcile metric; AC-43 incl. the R2a-tag rehearsal | 04 |
| R-27 ➕ | Code-reverting R2b while M06B (R4) / M07 (R5) depend on its generation and read paths leaves a broken or inconsistent deployment | M | H | Runtime flag `ordered_quantity_write_enabled` makes the configuration rollback the default (I-22); code revert forbidden once R4/R5 are live unless coordinated R5 → R4 → R2b and rehearsed; dependency/rollback-mode table (§16); AC-44 | 04, 06B, 07 |

**Adversarial review (Rev. 2):**
- **Coverage:** all 35 decisions plus Q1–Q9 are mapped; no approved decision is listed as open.
- **Q-specific behaviors:**
  - Q1 rejects and never splits.
  - The Q2 default is a UI suggestion, with server validation and a legacy-only fallback.
  - Q3 delete is guarded with lock + FK + audit.
  - Q4 keeps the two layers separate and never fabricates a rate.
  - Q5 applies to both templates and keeps technical keys.
  - Q6A has no PR fallback.
  - Q6B allows append-only after submit, with `arrived` unspecified.
  - Q7 covers detail, export and PDF behind G-PDF.
  - Q8 uses the exact strings.
  - Q9 resolves one evaluation date per request.
- **Structural:** no duplicate structures; the MTC switch is atomic; the cross-currency weighted average is exact; legacy totals are preserved; no circular dependencies; every AC is observable; every mission is reversible.

---

## 22. Decision Log and Remaining Verification Items

### 22.1 Approved business decisions (RESOLVED — APPROVED, 14 Oct 2026)

| Q | Decision (locked) | Integrated in |
|---|---|---|
| Q1 | **Reject** consolidation when Port of Discharge or Incoterms differ across participating PRs; clear message listing PRs and values; no silent pick; no auto-split; enforced in `generateFromAwards` | §8 I-11/I-18, §9, §14, M02 (delivered in R1), M04, AC-30, AC-42, R-4, R-23 |
| Q2 | Default Order Qty = `min(requested, offered)` as a UI suggestion; editable `1..offered`; persisted independently; server-validated on all PO paths; drives KG, amount, shipment, PDF, exports, progress | §11.1–11.2, §14, M04, AC-32 |
| Q3 | **Guarded hard delete** for Admin and Purchasing on Material/HS Code only when unreferenced (FK + logical checks); actionable refusal recommending deactivation; lock + FK backstop; audited; no unrelated Admin access | §5.6, §10 (000007), §13, M06A, AC-26/35, R-13/18 |
| Q4 | Separate analytical (benchmark at evaluation date) from transactional (immutable snapshots); missing foreign snapshot → `—`/Exchange Rate Unavailable, never 1 or 0; `AGENTS.md` amendment planned (not in Gate 1) | §9, §11.4, §12, M04, M07, App. A-1, AC-23/24/33, R-3/R-10/R-20 |
| Q5 | Keep technical heading keys; dimension order Thickness → Width → Outer Diameter → Inner Diameter → Length in **both** PR and Quotation templates; no new aliases; old files still parse | M02, M03, AC-29 |
| Q6A | Missing offered dimension → "Reference Price Not Available — Offered Dimension Missing"; no PR fallback; no Δ; PO not blocked | §12.2, M06B, AC-37 |
| Q6B | MTC: draft upload/delete; submitted **append-only**; ≥1 VALIDATED required to submit; `arrived` unspecified → T-6 | §13, M05 state matrix, AC-34, R-19 |
| Q7 | Port/Incoterms on PO detail, PO exports and **PO PDF**; preserve layout and calculations; visual verification; form-owner approval gate G-PDF if the controlled form changes | §5.8, §14, M02 (PO detail display), M04 (exports, PDF), AC-31, R-17 |
| Q8 | EN "Supplier Estimate Delivery" / ID **"Estimasi Pengiriman Supplier"**; presentation only | §5.2 map, §14, M03, AC-36 |
| Q9 | Evaluation date = automatic business today (Asia/Jakarta) via `BusinessTime`; no picker; one date per request; never future-dated rates; display the evaluation date and rate effective dates | §12.1, M01, M07, AC-39 |

### 22.2 Remaining technical verification / approval items (not business questions)

| ID | Item | Owner / when |
|---|---|---|
| T-1 | Confirm the staging/production MySQL version ≥ 8.0.16 (local 8.0.30 verified by install directory) | Ops, before the M01 staging run |
| T-2 | **G-PDF:** PO form owner approves adding two header rows to the controlled PNR261178 form | Business, before the R2b PDF release |
| T-3 | User commits or acknowledges the uncommitted Price Comparison view/test edits | User, before M07 |
| T-4 | Explicit confirmation to apply each `AGENTS.md` amendment (Appendix A) at its mission | User, at M05/M06A/M06B/M07 |
| T-5 | Re-read the partially inspected files listed in R-16; confirm `purchasing/po/create` is unreachable | Implementer, M04/M05 |
| T-6 | **Unresolved policy:** MTC upload for `arrived` shipments (and any later state). Implemented behavior until decided: fail-closed (upload/delete refused, read unchanged); no permission invented | Business clarification; any change needs a separate approved decision |
| T-7 | `EXPLAIN` evidence before adding indexes | Implementer, M07 |
| T-8 | Confirm the Local imports do not extend `AbstractPreviewImport` | Implementer, M02 |

---

## 23. Gate 1 Completion Checklist

- [x] Git preflight: branch, base, remote, status verified; unrelated changes preserved (incl. new `KnownDeviceService.php`)
- [x] References A–D read
- [x] ECC skills located/read; limitations disclosed
- [x] Repository audit of PR, quotation, award/PO, PDF, shipment/MTC, comparison/FX, masters, UI
- [x] 35-decision traceability retained; Q1–Q9 integrated and cross-referenced
- [x] Q1–Q9 recorded as RESOLVED — APPROVED (Q6A/Q6B separate)
- [x] Consistency fixes: M01 prerequisites (6.1), atomic MTC switch (6.2), exact cross-currency weighting (6.3), local changes (6.4), legacy ordered chain (6.5), snapshot timing (6.6), `AGENTS.md` timing (6.7)
- [x] Adversarial review re-run
- [x] Final consistency checks:
  - durable refusal audit (I-16);
  - M04 rollback gate and forward-fix (I-17, R-21);
  - dynamic evaluation date with examples labelled;
  - T-6 fail-closed and still unresolved
- [x] Deployment-sequencing fixes:
  - logistics guard + snapshot in R1 (I-18);
  - pre-activation reference snapshots "not captured" with no backfill (I-19);
  - kill switch in M01/R1, tested on all three entry points across deployment states (I-20)
- [x] Final safety corrections:
  - selected-but-unassigned awards fail closed after an M04-W rollback, and stale selections are cleared on re-point (I-21, AC-43);
  - R2b rollback restricted by downstream releases, with configuration rollback and a coordinated rollback rehearsal (I-22, AC-44)
- [ ] T-1 … T-8 completed at their missions
- [ ] Gate 2 authorized by the user

## 24. Gate 2 Readiness Recommendation

**Ready for Gate 2 once the user approves this plan.** Mission 01 has no open business prerequisite. Release constraints:
- R1 (M01 + M02) ships atomically: PR logistics, Q1 guard, PO logistics snapshot and the kill switch go live together (I-18, I-20);
- R2a (M04-R read path) ships before R2b (M03 + M04-W); M04-R is never reverted once frozen POs exist (I-17);
- the R4 deploy is the Reference Price activation point; earlier POs stay "not captured" with no backfill (I-19);
- M04-W is rolled back by configuration (`ordered_quantity_write_enabled=false`). It is never code-reverted beneath a live R4/R5, except as a rehearsed coordinated R5 → R4 → R2b rollback (I-22);
- the PDF change is released only after G-PDF;
- M05 performs the MTC switch atomically;
- M07 starts after T-3 and T-4.

No implementation has started.

---

## Appendix A — Proposed `AGENTS.md` Amendments (NOT applied in Gate 1)

Each item is applied only in its mission, after explicit user confirmation at that time.

**A-1 (M07; approved in principle by Q4).** Replace the snapshot-only rules in §"Konversi Kurs → 2" (the paragraph starting "Modul Perbandingan Harga dan Riwayat Harga **join ke kurs snapshot**…") and the note in §"Modul Perbandingan Harga" with:

> *Nilai dokumen* (total quotation/PO, daftar PO, ekspor, PDF) memakai **kurs snapshot** dokumen (`exchange_rate_id`) dan tidak pernah dihitung ulang. Mata uang asing tanpa snapshot ditampilkan "—"/"Exchange Rate Unavailable": tidak pernah dianggap 1 atau 0. IDR→IDR = 1.
>
> *Analitik Perbandingan Harga* (Best Current Offer, Historical Best/Average, Master Reference Price, grafik) memakai **Exchange Rate Benchmark** (`exchange_rates`). Kurs yang dipakai adalah yang berlaku pada **tanggal evaluasi = `BusinessTime::today()`** (Asia/Jakarta, satu tanggal per request) melalui `ExchangeRateResolver::rateAsOf()`. Kurs bertanggal masa depan tidak dipakai. Nilai tanpa kurs dikeluarkan dari perhitungan dan dilaporkan.
>
> `latestRate()` tetap hanya untuk membuat snapshot baru. Jangan mencampur kedua lapisan.

**A-2 (M05).**
- Upload table: replace the row "Penawaran (Supplier) — file MTC" with "Shipment — MTC: `pdf,jpg,jpeg,png`, 5 MB, profile `mtc`, polymorphic pada `Shipment`; draft: unggah/hapus; submitted: hanya tambah; wajib ≥1 VALIDATED sebelum submit. MTC quotation historis tetap dapat diunduh (read-only)".
- Note `Shipment` attachments = MTC only.

**A-3 (M06A).**
- Role table: Purchasing "kelola Master Material & HS Code (termasuk hapus terjaga bila tidak direferensikan)".
- Policies count 16 → +`MaterialMasterPolicy`, `HsCodeRulePolicy`.
- Mention `master_data_audit_logs`.

**A-4 (M06B).**
- Hashids list +`MaterialReferencePrice`.
- Policies +`MaterialReferencePricePolicy`.
- Short "Master Harga" subsection (versions, overlap lock, snapshot at PO generation).

**STATUS: PENDING USER APPROVAL FOR GATE 2**
