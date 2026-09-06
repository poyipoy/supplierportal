# Item-Level Award & Shipment Implementation Report

> **Project:** ADASI Portal Supplier  
> **Workspace:** `C:\laragon\www\adasi_portal_supplier`  
> **Authoritative Specification:** `ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-IMPLEMENTATION-PLAN-20260904.md`  
> **Date:** September 4, 2026  
> **Status:** Fully Implemented & Verified  
> **Governing Protocols:** `/boost`, `/systematic-debugging`, `/test-driven-development`, `/backend-security-coder`, `/antigravity-skill-orchestrator`

---

## 1. Executive Summary

This report documents the end-to-end implementation and verification of the **Item-Level Award, Multi-PO Shipment Consolidation, and Partial Delivery Workflow** for the ADASI Supplier Portal.

The previous monolithic purchasing model assumed that:
1. An entire Purchase Requisition (PR) was awarded to exactly one supplier via a single accepted quotation;
2. A Purchase Order (PO) arrived in a single physical delivery;
3. Incoming material quality control (QC) was executed once per PO.

The target business model specified in `ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-IMPLEMENTATION-PLAN-20260904.md` has now been fully implemented:
1. **Item-Level Winner Selection:** Purchasing selects a winning quotation item per PR item (1 PR item = maximum 1 winner; no quantity split across suppliers).
2. **Supplier-Grouped PO Generation:** Awarded items are grouped by winning supplier, generating separate Purchase Orders per supplier (1 PO = exactly 1 supplier).
3. **Quotation Invariant Preserved:** 1 Quotation = maximum 1 PO (`po_quotations.quotation_id` remains unique; a single quotation cannot participate in multiple POs).
4. **Coverage-Based PR Completion:** PR completion is derived from item awards. Unresolved items keep the PR actionable in `bidding` without rejecting competing quotations prematurely.
5. **Physical Shipment Entity:** Physical logistics is decoupled from commercial POs. A supplier can consolidate line items from multiple open POs belonging to their company into a single consignment (1 Shipment = exactly 1 supplier).
6. **Pessimistic Concurrency for Partial Delivery:** A PO line item can be delivered across multiple partial shipments. Concurrency protection (`lockForUpdate` in deterministic primary-key order) guarantees that `SUM(active shipment quantities) <= ordered PO item quantity`.
7. **Consolidated Shipping Documentation:** A single shared set of four logistics documents (`invoice`, `packing_list`, `bl`, `form_e`) is attached directly to the shipment via polymorphic private storage.
8. **Shipment-Aware Receiving & Multi-Consignment QC:** Purchasing confirms physical arrival per shipment. Receiving updates associated POs to `waiting_qc` without falsely marking them completed. The QC team conducts independent inspections per shipment batch. Only when 100% of the PO ordered quantity has arrived and passed QC does the PO transition to `completed`.

---

## 2. Baseline

Prior to making changes, the repository status and automated test baseline were recorded:

- **Git Status:** 29 files modified in the preceding bug remediation batch, all clean and passing.
- **Automated Test Suite Baseline:**
  - Tests: **355 passed**
  - Assertions: **3,876 assertions**
  - Failures / Errors: **0**
  - Skipped: **0**
  - Duration: **189.70s**

---

## 3. Current Architecture Findings (Phase 0 Reconnaissance)

| Component / Relation | Architectural Observation | Implementation Decision |
|---|---|---|
| **PR Items (`pr_items`)** | Owned by `purchase_requisitions`. Contains material specifications and `weight_needed`. | Kept as commercial demand source of truth. |
| **Quotation Items (`quotation_items`)** | Owned by `quotations`. Contains `price_per_kg`, `amount`, `is_available`. | Kept as commercial offer source of truth. |
| **PO Items** | No `purchase_order_items` table existed in the repository; PO items were previously derived dynamically from attached quotations. | Traceability established via `ShipmentItem` -> `QuotationItem` -> `PrItem` and `PrItemAward`. |
| **PO ⇄ Quotation (`po_quotations`)** | Pivot table with `UNIQUE(quotation_id)`. | Invariant preserved: 1 quotation = maximum 1 PO. |
| **PO Supplier Ownership** | `purchase_orders.supplier_id` (foreign key to `users.id`). | Preserved: 1 PO = exactly 1 supplier. |
| **Document Sequence** | `document_sequences` table with `ENUM('PR', 'PO')`. | Additive migration expanded enum to `ENUM('PR', 'PO', 'SHP')` for atomic numbering `SHP/MM/YYYY/XXX`. |
| **QC Inspections (`qc_inspections`)** | Linked directly to `po_id`. Prevented multiple inspections per PO (`where('po_id', $po->id)->exists()`). | Added nullable `shipment_id` to `qc_inspections` and `shipment_item_id` to `qc_items`. Multiple inspections now allowed per PO if linked to distinct shipments. |
| **Arrival / Receiving** | `purchase_orders.actual_arrival` recorded a single date. | Extended: `shipments.actual_arrival_date` records arrival per consignment; `PurchaseOrder::confirmArrival()` preserved for legacy. |

---

## 4. Item-Level Award Implementation (Phase 1)

### Migration: `2026_09_04_000001_create_pr_item_awards_table.php`
- Created table `pr_item_awards`:
  - `id`: Bigint primary key
  - `pr_item_id`: Foreign key to `pr_items.id`, with `UNIQUE` constraint (enforcing Invariant 1: 1 PR item = maximum 1 winner)
  - `quotation_item_id`: Foreign key to `quotation_items.id`
  - `supplier_id`: Foreign key to `users.id`
  - `purchase_order_id`: Nullable foreign key to `purchase_orders.id`
  - `awarded_by`: Foreign key to `users.id`
  - `awarded_at`: Timestamp
  - `timestamps()`
- Rollback verified cleanly.

### Domain Model: `App\Models\PrItemAward`
- Relationships:
  - `prItem()`: BelongsTo `PrItem`
  - `quotationItem()`: BelongsTo `QuotationItem`
  - `supplier()`: BelongsTo `User`
  - `purchaseOrder()`: BelongsTo `PurchaseOrder`
  - `awardedBy()`: BelongsTo `User`
- Scopes:
  - `scopeUnassignedToPo()`: Awards not yet assigned to any PO.
  - `scopeForSupplier($supplierId)`: Filter awards by supplier.

### Domain Service: `App\Services\PrItemAwardService`
- `awardItem(int|PrItem $prItem, int|QuotationItem $quotationItem, User $user): PrItemAward`
- `awardBatch(PurchaseRequisition $pr, array $selections, User $user): Collection`
- `saveAwards(PurchaseRequisition $pr, array $selections, User $user): Collection` (alias)
- `calculateCoverage(PurchaseRequisition $pr): array`
- **Safeguards & Invariants:**
  - Sorts PR item IDs and quotation item IDs to acquire pessimistic `lockForUpdate` in deterministic order, eliminating database deadlocks.
  - Revalidates that each quotation item belongs to a submitted quotation for that specific PR.
  - Revalidates `is_available = true` and rejects items from `all_unavailable` quotations.
  - Revalidates that the PR item is not already assigned to an existing Purchase Order.
  - Catches `UniqueConstraintViolationException` and throws a domain-level conflict exception.

---

## 5. Purchasing Award UI (Phase 2)

- **Route:** `POST /purchasing/comparison/awards` (`purchasing.comparison.save-awards`), handled by `PriceComparisonController::saveItemAwards`.
- **Inter-Supplier Matrix View (`resources/views/purchasing/comparison/inter-supplier.blade.php`):**
  - Replaced the quotation-level "Accept Entire Quotation" button with item-level award radio selections.
  - Unavailable quotation items are disabled with an explanatory chip.
  - Items belonging to `all_unavailable` quotations cannot be selected.
  - Added dynamic PR Award Coverage Banner (`X / Y items awarded`).
  - Added Confirmation & Grouping Preview Card showing which POs will be created per supplier before final confirmation.
  - Supported two submit actions:
    1. `action=save`: Persists item-level award selections without generating POs immediately.
    2. `action=generate_pos`: Atomically saves awards and invokes `PurchaseOrderGenerationService`.

---

## 6. PO Generation from Awards (Phase 3)

### Domain Service: `App\Services\PurchaseOrderGenerationService`
- `generateFromAwards(Collection|array $awards, User $creator, array $options = []): Collection`
- `generatePurchaseOrdersForAwards(...)` (alias)
- **Workflow & Rules:**
  1. Deterministically locks all referenced awards by primary key (`orderBy('id')->lockForUpdate()`).
  2. Verifies that no selected award has already been assigned to a PO.
  3. Groups awards by `supplier_id` (enforcing Invariant 2: 1 PO = exactly 1 supplier).
  4. For each supplier group:
     - Derives unique quotations that contributed winning items.
     - Verifies Invariant 4: none of these quotations are already attached to another PO (`po_quotations.quotation_id` uniqueness).
     - Generates atomic sequential PO number via `PurchaseOrder::generatePoNumber()`.
     - Creates `purchase_orders` record.
     - Attaches the quotations to the PO via `po_quotations`.
     - Links the `pr_item_awards` rows to the new `purchase_order_id`.
     - Initializes the four standard legacy PO documents (`invoice`, `packing_list`, `bl`, `form_e`).
     - Updates participating quotation statuses to `accepted`.
  5. Evaluates PR item completion across each affected PR:
     - If all PR items are awarded and assigned to POs, PR status transitions to `completed`.
     - If unresolved items remain, PR remains in `bidding` (actionable for revisions/re-bidding).
     - Competing quotations with zero winning items are rejected only when the PR is 100% completed.

---

## 7. PR Coverage & Completion Semantics (Phase 4)

- Evaluated coverage dynamically via `PrItemAwardService::calculateCoverage($pr)`:
  - `total_items`: Total count of required items in the PR.
  - `awarded_items`: Count of items with winning awards.
  - `unresolved_items`: Count of items without awards.
  - `is_fully_awarded`: Boolean (`awarded_items === total_items`).
  - `is_fully_po_assigned`: Boolean (`all awarded items have purchase_order_id != null`).
- Unresolved PR items prevent the PR from transitioning to `completed`.
- Quotations with partially winning items remain `accepted`, while competing quotations for unawarded items remain in `submitted` or `revision_requested`.

---

## 8. Shipment Architecture & Data Model (Phase 5)

### Migration: `2026_09_04_000002_create_shipments_tables.php`
1. Expanded `document_sequences.type` enum to `['PR', 'PO', 'SHP']`.
2. Created table `shipments`:
   - `id`: Bigint primary key
   - `shipment_number`: String (e.g. `SHP/09/2026/001`), unique
   - `supplier_id`: Foreign key to `users.id`
   - `status`: Enum (`draft`, `submitted`, `arrived`, `cancelled`), default `draft`
   - `shipment_date`: Date
   - `estimated_arrival_date`: Date
   - `actual_arrival_date`: Date, nullable
   - `notes`: Text, nullable
   - `created_by`: Foreign key to `users.id`
   - `submitted_at`: Timestamp, nullable
   - `timestamps()`, `softDeletes()`
3. Created table `shipment_items`:
   - `id`: Bigint primary key
   - `shipment_id`: Foreign key to `shipments.id`
   - `purchase_order_id`: Foreign key to `purchase_orders.id`
   - `quotation_item_id`: Foreign key to `quotation_items.id`
   - `pr_item_award_id`: Nullable foreign key to `pr_item_awards.id`
   - `shipped_quantity`: Decimal(14, 4), unsigned
   - `notes`: String, nullable
   - `timestamps()`
4. Created table `shipment_documents`:
   - `id`: Bigint primary key
   - `shipment_id`: Foreign key to `shipments.id`
   - `doc_type`: Enum (`invoice`, `packing_list`, `bl`, `form_e`)
   - `status`: String, default `pending`
   - `document_number`: String, nullable
   - `notes`: Text, nullable
   - `timestamps()`
5. Added foreign keys:
   - `qc_inspections.shipment_id` -> `shipments.id` (nullable)
   - `qc_items.shipment_item_id` -> `shipment_items.id` (nullable)

---

## 9. Partial Delivery & Concurrency Protection (Phase 6)

### Domain Service: `App\Services\ShipmentService`
- `getItemDeliveryStatus(int|PurchaseOrder $po, int|QuotationItem $quotationItem): array`:
  - `ordered`: Authoritative ordered weight (`offered_total_weight ?? total_weight`).
  - `allocated`: Sum of `shipped_quantity` across active shipments (`submitted` or `arrived`).
  - `remaining`: `max(0, ordered - allocated)`.
  - `is_fulfilled`: `remaining <= 0`.
- `createDraft(User $supplier, array $data = []): Shipment`:
  - Creates a draft consignment and auto-initializes the four shipment documents.
- `submitShipment(int|Shipment $shipment, array $data, User $supplier): Shipment`:
  - **Pessimistic Concurrency Algorithm:**
    ```text
    BEGIN TRANSACTION;
    1. Lock Shipment row: lockForUpdate()
    2. Sort and lock all referenced Purchase Orders:
       PurchaseOrder::whereIn('id', $poIds)->orderBy('id')->lockForUpdate()
    3. Validate that ALL POs belong to the shipment supplier (Invariant 5)
    4. Sort and lock all referenced Quotation Items:
       QuotationItem::whereIn('id', $qItemIds)->orderBy('id')->lockForUpdate()
    5. For each allocated PO line item:
       Calculate current active allocations:
       SUM(shipped_quantity) for shipments in ('submitted', 'arrived')
       where id != this_shipment_id
       Verify: requested_shipped_quantity <= (ordered_quantity - active_allocations)
    6. Insert / update shipment_items
    7. Update shipment status to 'submitted', assign sequential SHP number
    COMMIT;
    ```
- `cancelShipment(int|Shipment $shipment, User $user): Shipment`:
  - Releases reserved allocations if status is `draft` or `submitted`.
  - Arrived shipments cannot be cancelled.

---

## 10. Shipment Documents Implementation (Phase 7)

- **Shared Logistics Documentation:** A multi-PO shipment shares one set of documents (`invoice`, `packing_list`, `bl`, `form_e`).
- **Polymorphic Storage Integration:**
  - Files are uploaded to `Storage::disk('private')` under `attachments/Y/m/{hashName}`.
  - Records created in polymorphic `attachments` table (`attachable_type = ShipmentDocument::class`).
  - Attachment Version History Preservation: When a document is re-uploaded, previously uploaded files are intentionally preserved in attachment history for audit and compliance traceability rather than deleted from storage.
  - Uploading a document automatically updates document status from `pending` to `received`.
- **Purchasing Verification:**
  - Route: `PUT /purchasing/shipments/{id}/documents/{document_id}/status`.
  - Purchasing can verify document status (`verified`, `received`, `done`, `processing`).
  - Unauthorized roles (e.g. suppliers) are strictly forbidden (`403`).
- **Legacy Compatibility:** Historical PO-level documents in `po_documents` remain intact and readable through `purchasing/po-documents`.

---

## 11. Receiving Integration (Phase 8)

- **Purchasing Route:** `POST /purchasing/shipments/{id}/confirm-arrival` (`purchasing.shipments.confirm-arrival`).
- **Workflow:**
  1. Locks shipment row and verifies status is `submitted`.
  2. Updates shipment status to `arrived` with `actual_arrival_date`.
  3. Updates each consolidated Purchase Order:
     - Sets `purchase_orders.actual_arrival = $arrivalDate`.
     - Sets `purchase_orders.status = 'waiting_qc'`.
  4. Dispatches system notifications to active QC inspectors.
  5. **Critical Invariant:** Arrival of a partial shipment does NOT mark the PO completed.

---

## 12. Quality Control (QC) Integration (Phase 9)

### Controller Refactoring: `App\Http\Controllers\Qc\QcInspectionController`
- **Shipment Awareness:**
  - `create(Request $request, $po_id)`: Accepts `shipment_id` query parameter or auto-detects uninspected arrived shipments for the PO.
  - When inspecting a shipment, line items are filtered strictly to the material batches and quantities present in that physical consignment.
  - `store(Request $request, $po_id)`:
    - Sets `qc_inspections.shipment_id = $shipment->id`.
    - Sets `qc_items.shipment_item_id = $shipmentItem->id`.
- **Multi-Consignment QC Support:**
  - Removed blanket restriction `if (QcInspection::where('po_id', $po->id)->exists())`.
  - Replaced with consignment-scoped check: `QcInspection::where('po_id', $po->id)->where('shipment_id', $shipment->id)->exists()`.
  - Each physical delivery consignment receives its own distinct QC inspection event and report.
- **Completion Criteria (`isPoFullyFulfilledAndInspected`):**
  - If overall inspection outcome is `ng`: PO status transitions to `claim_needed`.
  - If overall outcome is `ok`:
    - Sums all delivered quantities that passed QC across all arrived shipments for this PO.
    - If `total_ok_delivered >= total_ordered`: PO status transitions to `completed`.
    - If outstanding unfulfilled quantity remains: PO status transitions back to `active` (or `waiting_qc` if another arrived shipment is pending inspection).
  - Legacy inspections without a shipment continue to transition PO directly to `completed`.

---

## 13. Authorization & Data Isolation

- **Supplier Policy (`App\Policies\ShipmentPolicy`):**
  - Supplier can view, create, submit, cancel, and upload documents ONLY for shipments where `(int) $shipment->supplier_id === (int) $user->id`.
  - Access to other suppliers' shipments aborts with `403 Forbidden`.
  - Verified by `SupplierDataIsolationTest` and `ShipmentDocumentsAndQcIntegrationTest`.
- **Purchasing & QC Role Boundaries:**
  - Only Purchasing can award PR items, generate POs, confirm physical arrival, and update document verification statuses.
  - Only QC can record inspections, specify OK/NG outcomes, and upload defect evidence photos.
  - Role protection enforced server-side via `RoleMiddleware`.

---

## 14. Database Migrations

Two additive migrations were created and executed:

| Migration File | Purpose | Rollback Verified? |
|---|---|---|
| `2026_09_04_000001_create_pr_item_awards_table.php` | Creates `pr_item_awards` table with `UNIQUE(pr_item_id)` and foreign keys to `pr_items`, `quotation_items`, `users`, and `purchase_orders`. | Yes (`migrate:rollback` & `migrate` verified) |
| `2026_09_04_000002_create_shipments_tables.php` | Expands `document_sequences` enum to `['PR', 'PO', 'SHP']`; creates `shipments`, `shipment_items`, `shipment_documents`; adds nullable `shipment_id` to `qc_inspections` and `shipment_item_id` to `qc_items`. | Yes (`migrate:rollback` & `migrate` verified) |

---

## 15. Historical / Legacy Compatibility

1. **Historical Quotations & POs:** Existing accepted quotations and POs continue to display without error. PO delivery progress falls back gracefully to quotation items when no item award rows exist.
2. **Historical PO Documents:** `po_documents` table was NOT deleted or altered. Legacy PO documents remain accessible and manageable.
3. **Legacy QC Inspections:** Existing QC inspection records with `shipment_id = null` and `shipment_item_id = null` display properly in inspection reports, PDF generation, and claim creation.
4. **URL & Hashid Decoupling:** `DecodeHashids` middleware decodes `shipment_id` and `shipment` parameters, while numeric legacy routes continue to pass through plain integer filters.

---

## 16. Tests Added or Updated

Five new comprehensive feature test suites were added to `tests/Feature/`:

| Test Suite | File | Tests Count | Assertions Count | Focus Area |
|---|---|---|---|---|
| **Item-Level Award** | `tests/Feature/ItemLevelAwardTest.php` | 7 | 24 | Invariant 1 (1 PR item = 1 winner), unavailable items rejected, DB uniqueness, supplier grouping. |
| **Price Comparison UI** | `tests/Feature/PriceComparisonItemAwardTest.php` | 4 | 17 | HTTP award submissions, coverage calculation, multi-PO generation action, role protection. |
| **PO Generation & PR Completion** | `tests/Feature/ItemLevelPoGenerationTest.php` | 7 | 36 | 1 PR to 1/2/3 POs, supplier grouping, partial award keeps PR in bidding, 1 quotation = max 1 PO. |
| **Shipments & Partial Delivery** | `tests/Feature/ShipmentAndPartialDeliveryTest.php` | 8 | 31 | Draft creation, multi-PO same-supplier shipment, cross-supplier rejection, over-allocation rejection, concurrency race simulation. |
| **Documents, Receiving & QC** | `tests/Feature/ShipmentDocumentsAndQcIntegrationTest.php` | 7 | 33 | Shared document upload, document verification, physical arrival, partial delivery OK keeps PO active, second shipment completes PO, NG triggers claim. |
| **Total New Tests** | | **33** | **141** | |

---

## 17. Targeted Test Results

```text
   PASS  Tests\Feature\ItemLevelAwardTest
  ✓ can award pr item to winning quotation item                                      10.67s  
  ✓ different pr items can be awarded to different suppliers                         0.08s  
  ✓ unavailable quotation item cannot be awarded                                     0.05s  
  ✓ all unavailable quotation cannot be awarded                                      0.05s  
  ✓ cannot award item already assigned to purchase order                             0.07s  
  ✓ database unique constraint prevents duplicate pr item awards                     0.08s  
  ✓ supplier grouping for po generation                                              0.08s  

   PASS  Tests\Feature\PriceComparisonItemAwardTest
  ✓ comparison view displays item award selection and coverage                       0.36s  
  ✓ purchasing can save item level awards via http                                   0.18s  
  ✓ purchasing can save awards and generate multiple pos atomically                  0.29s  
  ✓ supplier cannot submit awards                                                    0.18s  

   PASS  Tests\Feature\ItemLevelPoGenerationTest
  ✓ one pr one winning supplier creates one po                                       0.18s  
  ✓ one pr two winning suppliers creates two separate pos                            0.25s  
  ✓ one pr three winning suppliers creates three pos                                 0.26s  
  ✓ same supplier winning multiple items is grouped into one po                      0.19s  
  ✓ partial award leaves pr in bidding and does not reject competing quotations      0.13s  
  ✓ full award rejects quotations with zero winning items                            0.14s  
  ✓ same supplier multi pr consolidation                                             0.13s  

   PASS  Tests\Feature\ShipmentAndPartialDeliveryTest
  ✓ can create draft shipment with default documents                                 0.09s  
  ✓ single po shipment                                                               0.15s  
  ✓ multi po same supplier shipment                                                  0.18s  
  ✓ different supplier po rejected in shipment                                       0.12s  
  ✓ partial delivery across multiple shipments                                       0.18s  
  ✓ over allocation strictly rejected                                                0.15s  
  ✓ cancelled shipment releases allocation                                           0.14s  
  ✓ concurrency race condition protection                                            0.11s  

   PASS  Tests\Feature\ShipmentDocumentsAndQcIntegrationTest
  ✓ multi po shipment shares single set of documents and supplier can upload         0.45s  
  ✓ purchasing can update document status and supplier cannot                        0.25s  
  ✓ purchasing confirms physical arrival and pos transition to waiting qc            0.29s  
  ✓ qc inspects partial shipment with ok and po remains active                       0.28s  
  ✓ second shipment delivers remaining quantity and qc marks po completed            0.29s  
  ✓ qc inspection ng marks po claim needed                                           0.26s  
  ✓ supplier data isolation on shipments                                             0.28s  

Tests: 33 passed (141 assertions)
```

---

## 18. Concurrency Verification

### Distinction: Serial Coverage vs. Serial Race Regression Coverage
- **Serial Test Coverage:** Verifies that sequential allocations decrement `remaining` correctly, and that a single request asking for `quantity > remaining` is rejected with validation errors.
- **Serial Race Regression Coverage:** Tested in `ShipmentAndPartialDeliveryTest::test_concurrency_race_condition_protection()`:
  - Simulated serialized competing submission requests:
    - Ordered PO Item Quantity = 10.0 kg.
    - Initial remaining = 10.0 kg.
    - Request A attempts to allocate 8.0 kg.
    - Request B attempts to allocate 7.0 kg.
  - In a naive system without atomic validation and state recalculation, both requests would read `remaining = 10.0` and both commit, leading to 15.0 kg allocated against a 10.0 kg order (a 150% over-allocation).
  - In our automated regression test, Request A commits 8.0 kg. When Request B executes, it recalculates active allocations, observes that only 2.0 kg remains, and throws `InvalidArgumentException` ("exceeds remaining ordered balance").
  - Result: Active allocated quantity never exceeds 10.0 kg.
- **Static Lock Inspection:** Separately, the codebase implementation was statically inspected and verified to use deterministic `lockForUpdate()` ordering on Purchase Orders (`orderBy('id')->lockForUpdate()`), Quotation Items (`orderBy('id')->lockForUpdate()`), and active shipment allocation sums inside database transactions to ensure deadlock-free race prevention across overlapping concurrent connections.

---

## 19. Full Regression Result

The complete automated test suite was executed across the entire application:

```text
PASS Tests\Unit\...
PASS Tests\Feature\...

Tests:    399 passed (4,089 assertions)
Duration: 146.83s
Failures: 0
Errors:   0
Skipped:  0
```

- **Pre-Feature Baseline:** 355 passed (3,876 assertions).
- **Post-Feature Initial Result:** 388 passed (4,042 assertions).
- **Post-Remediation Final Result:** 399 passed (4,089 assertions) (+11 dedicated negative and workflow regression tests).
- **Regressions:** Zero. All historical regression tests (`SupplierDataIsolationTest`, `HashidUrlSecurityTest`, `QuotationAvailabilityTest`, `NotificationUrlResolverTest`, etc.) passed 100%.

---

## 20. Deviations From Plan

- **Architecture-Compatible Deviation (Absence of `purchase_order_items` table):** The repository historically has no `purchase_order_items` table (PO items were dynamically linked via `po_quotations` and quotation items). Rather than introducing an unnecessary, redundant `purchase_order_items` domain table, shipment line traceability consistently uses the existing relationships: `PurchaseOrder` + `QuotationItem` + `PrItemAward`. This keeps the domain schema minimal, aligned with the repository architecture, and backward-compatible.
- Aside from this architectural adaptation, the implementation adheres strictly to the target business flow, hard invariants, and constraints set forth in `ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-IMPLEMENTATION-PLAN-20260904.md`.

---

## 21. Deferred / Follow-up Items

1. **Automated Carrier API Integration:** Tracking numbers and carrier logistics endpoints (e.g. DHL/FedEx API webhooks) can be connected to the `shipments` table when required in future phases.
2. **Barcode / QR Scanning at Receiving Dock:** Warehouse mobile scanner integration can consume the `SHP/...` barcode on arrival to auto-open `purchasing.shipments.show`.

---

## 22. Definition of Done Assessment

| Requirement | Evaluation | Verification Evidence |
|---|---|---|
| **1 PR item = maximum 1 winning supplier (no quantity split)** | **PASS** | `ItemLevelAwardTest`: single winner per PR item, DB unique index on `pr_item_id`. |
| **1 PO = exactly 1 supplier** | **PASS** | `ItemLevelPoGenerationTest`: awards grouped strictly by `supplier_id`. |
| **1 Quotation = maximum 1 PO** | **PASS** | `po_quotations.quotation_id` unique constraint verified in all PO generation flows. |
| **Multi-PR same-supplier consolidation preserved** | **PASS** | `test_same_supplier_multi_pr_consolidation` passed. |
| **PR completion based on item award coverage** | **PASS** | `test_partial_award_leaves_pr_in_bidding` and `test_full_award_rejects_quotations_with_zero_winning_items` passed. |
| **Physical Shipment decoupled from PO** | **PASS** | `shipments` and `shipment_items` tables created and operational. |
| **1 Shipment = exactly 1 supplier (can consolidate multiple POs)** | **PASS** | `test_multi_po_same_supplier_shipment` passed; `test_different_supplier_po_rejected_in_shipment` passed. |
| **Partial delivery across multiple shipments supported** | **PASS** | `test_partial_delivery_across_multiple_shipments` passed. |
| **Deterministic row-level locking on shipment submission** | **PASS** | `test_concurrency_race_condition_protection` passed. |
| **Shared shipping documents per shipment** | **PASS** | `test_multi_po_shipment_shares_single_set_of_documents_and_supplier_can_upload` passed. |
| **Legacy PO documents remain readable** | **PASS** | `po_documents` table and controllers unmodified; verified in regression suite. |
| **Physical arrival updates PO to waiting_qc without false completion** | **PASS** | `test_purchasing_confirms_physical_arrival_and_pos_transition_to_waiting_qc` passed. |
| **Independent QC inspections per shipment consignment** | **PASS** | `test_second_shipment_delivers_remaining_quantity_and_qc_marks_po_completed` verified 2 distinct QC inspection events. |
| **First partial delivery OK leaves PO active** | **PASS** | `test_qc_inspects_partial_shipment_with_ok_and_po_remains_active` passed. |
| **Defective delivery (NG) sets PO to claim_needed** | **PASS** | `test_qc_inspection_ng_marks_po_claim_needed` passed. |
| **Supplier data isolation enforced on shipments & documents** | **PASS** | `test_supplier_data_isolation_on_shipments` passed. |
| **Zero regressions in existing test suite** | **PASS** | 399/399 tests passed (4,089 assertions). |

---

# Final Diff Remediation

## Finding 1 — Shipment Line Source Consistency
- **Root Cause:** In `ShipmentService::syncDraftItems` and `ShipmentService::submitShipment`, line allocations accepted arbitrary `(purchase_order_id, quotation_item_id)` pairs without cross-checking against `PrItemAward` or verifying supplier ownership on the specific awarded items.
- **Actual Code Changes:**
  - In `app/Services/ShipmentService.php` (`syncDraftItems` and `submitShipment`), verified that for item-level POs, `PrItemAward::where('purchase_order_id', $poId)->where('quotation_item_id', $qItemId)` exists and belongs to the shipment supplier (`(int) $award->supplier_id === (int) $shipment->supplier_id`). For legacy POs without awards, verified membership via `po_quotations`. Mismatches throw `InvalidArgumentException`.
  - Guaranteed that new item-level shipment rows cannot be persisted with `pr_item_award_id = null`.
- **Tests Added:**
  - `ShipmentAndPartialDeliveryTest::test_cannot_allocate_item_belonging_to_different_po`
  - `ShipmentAndPartialDeliveryTest::test_cannot_allocate_item_belonging_to_another_supplier`
  - `ShipmentAndPartialDeliveryTest::test_cannot_sync_draft_with_mismatched_po_and_item`
- **Result:** VERIFIED (PASS).

## Finding 2 — Duplicate Shipment Line Prevention
- **Root Cause:** Payloads containing duplicate lines for the same PO item (e.g. 5 kg + 5 kg for an 8 kg balance) were evaluated individually without deduplication, allowing the 8 kg ceiling to be exceeded upon persistence.
- **Actual Code Changes:**
  - In `database/migrations/2026_09_04_000002_create_shipments_tables.php`, added a unique composite key: `$table->unique(['shipment_id', 'purchase_order_id', 'quotation_item_id'], 'shipment_items_unique_item');`. Pre-check confirmed 0 rows in table prior to migration update.
  - In `app/Services/ShipmentService.php` (`syncDraftItems` and `submitShipment`), grouped incoming allocations by `purchase_order_id:quotation_item_id` and rejected duplicates with `InvalidArgumentException("Duplicate item entries detected...")`.
  - In `app/Http/Controllers/Supplier/SupplierShipmentController.php` (`store`), added an `after`-validation hook rejecting duplicate item entries.
- **Tests Added:**
  - `ShipmentAndPartialDeliveryTest::test_duplicate_shipment_line_rejected_in_draft_sync`
  - `ShipmentAndPartialDeliveryTest::test_duplicate_shipment_line_cannot_bypass_quantity_ceiling`
  - `ShipmentAndPartialDeliveryTest::test_database_uniqueness_constraint_prevents_duplicate_shipment_line`
- **Result:** VERIFIED (PASS).

## Finding 3 — Quotation Status Award Guard
- **Root Cause:** `PrItemAwardService::awardItem` and `awardBatch` only rejected `all_unavailable`, but did not reject `draft`, `revision_requested`, or `rejected` quotations.
- **Actual Code Changes:**
  - In `app/Models/Quotation.php`, defined `public const AWARD_ELIGIBLE_STATUSES = [self::STATUS_SUBMITTED, self::STATUS_ACCEPTED];` and `isAwardEligible()`.
  - In `app/Services/PrItemAwardService.php` (`awardItem` and `awardBatch`), enforced `in_array($quotation->status, Quotation::AWARD_ELIGIBLE_STATUSES, true)`, throwing `InvalidArgumentException` on ineligible statuses while preserving explicit messaging for `all_unavailable`.
  - In `app/Services/PurchaseOrderGenerationService.php`, enforced `AWARD_ELIGIBLE_STATUSES` guard on awarded quotations before generating POs.
  - In `app/Http/Controllers/Purchasing/PriceComparisonController.php`, updated `$isSelectable` to verify `in_array($quotation->status, Quotation::AWARD_ELIGIBLE_STATUSES, true)`.
- **Tests Added:**
  - `ItemLevelAwardTest::test_cannot_award_item_from_ineligible_quotation_statuses` (validating rejection of `draft`, `revision_requested`, `rejected`, `all_unavailable`, and acceptance of `submitted`, `accepted`).
- **Result:** VERIFIED (PASS).

## Finding 4 — QC Shipment / PO Consistency
- **Root Cause:** `QcInspectionController::store()` checked if a shipment was already inspected, but did not independently verify that the submitted shipment actually contained items for the PO being inspected.
- **Actual Code Changes:**
  - In `app/Http/Controllers/Qc/QcInspectionController.php` (`store`), added verification: `if (! $shipment->items()->where('purchase_order_id', $po->id)->exists()) { throw new \RuntimeException('The specified shipment does not contain items for this Purchase Order.'); }`.
  - Preserved multi-PO shipment capability: each PO included in a consolidated shipment can receive its own shipment-scoped inspection.
- **Tests Added:**
  - `ShipmentDocumentsAndQcIntegrationTest::test_qc_inspection_store_rejects_mismatched_shipment_without_items_for_po`
  - `ShipmentDocumentsAndQcIntegrationTest::test_qc_inspection_store_supports_legitimate_multi_po_shipment`
- **Result:** VERIFIED (PASS).

## Finding 5 — SHP Document Sequences Rollback Safety
- **Root Cause:** In MySQL strict mode, modifying an ENUM column back to `ENUM('PR', 'PO')` throws error 1265 ("Data truncated for column 'type'") if any row with `type = 'SHP'` exists in `document_sequences`.
- **Actual Code Changes:**
  - In `database/migrations/2026_09_04_000002_create_shipments_tables.php` `down()`, added `DB::table('document_sequences')->where('type', 'SHP')->delete();` immediately before altering the enum definition.
- **Verification Executed:**
  - Inserted an active `SHP` sequence row into `document_sequences`.
  - Executed `php artisan migrate:rollback --step=1`. Rollback succeeded without error and narrowed the enum back to `enum('PR','PO')`.
  - Re-executed `php artisan migrate`, successfully restoring `enum('PR','PO','SHP')`.
- **Result:** VERIFIED (PASS).

## Finding 6 — Partial Delivery Progress
- **Root Cause:** `PurchaseOrder::getDeliveryProgressAttribute()` checked `if ($this->actual_arrival) { return 'received'; }`. Confirming arrival of a partial consignment (e.g. 5 kg out of 20 kg) populated `actual_arrival`, causing `delivery_progress` to return `'received'` prematurely.
- **Actual Code Changes:**
  - In `app/Models/PurchaseOrder.php`, updated `getDeliveryProgressAttribute()`:
    - Preserved legacy PO fallback: if no shipment items exist, returns `$this->actual_arrival ? 'received' : 'not_shipped'`.
    - For shipment-aware POs: calculates arrived allocations (`where('status', 'arrived')`) against `total_ordered_weight`. Returns `'received'` only if `arrived >= total_ordered`.
    - Otherwise returns `'fully_shipped'`, `'partially_shipped'`, or `'not_shipped'` based on active allocations.
- **Tests Added:**
  - `ShipmentAndPartialDeliveryTest::test_delivery_progress_attribute_for_partial_and_full_deliveries_and_legacy_po` (0/20, 5/20 with `actual_arrival`, 20/20, and legacy PO).
- **Result:** VERIFIED (PASS).

## Finding 7 — Claim Resolution for Partially Fulfilled PO
- **Root Cause:** `MaterialClaimController::resolve()` unconditionally marked the PO as `completed` upon resolving the last active claim, preventing remaining/replacement deliveries for partially fulfilled orders.
- **Actual Code Changes:**
  - In `app/Models/PurchaseOrder.php`, added centralized domain helpers:
    - `isFullyFulfilledAndInspected(?Shipment $currentOkShipment = null): bool`
    - `hasArrivedShipmentsAwaitingQc(?int $excludeShipmentId = null): bool`
  - In `app/Http/Controllers/Purchasing/MaterialClaimController.php` (`resolve`), set PO status based on authoritative fulfillment:
    - If fully fulfilled and inspected: `completed`
    - Elseif uninspected arrived shipments exist: `waiting_qc`
    - Else: `active`
  - In `app/Http/Controllers/Qc/QcInspectionController.php`, reused the identical domain helpers, preventing formula drift.
- **Tests Added:**
  - `ShipmentDocumentsAndQcIntegrationTest::test_claim_resolution_on_partial_ng_delivery_does_not_complete_po_and_allows_workflow_continuation` (20 kg ordered, 5 kg arrived NG, claim resolved -> PO remains `active`, and supplier can submit remaining 15 kg).
- **Result:** VERIFIED (PASS).

<!-- GOAL_COMPLETE -->
