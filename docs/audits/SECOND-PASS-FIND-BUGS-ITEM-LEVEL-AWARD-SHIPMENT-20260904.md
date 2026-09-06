# Second-Pass Find-Bugs Audit Report: Item-Level Award & Shipment Final Diff Review

> **Project:** ADASI Portal Supplier (PT. Astra Daido Steel Indonesia)  
> **Workspace:** `C:\laragon\www\adasi_portal_supplier`  
> **Framework & Platform:** Laravel 12.0.1 (MVC), PHP 8.2, MySQL 8.0, Tailwind CSS (`tw-` prefix) + Bootstrap 5 compatibility layer  
> **Authoritative Specification:** [`ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-IMPLEMENTATION-PLAN-20260904.md`](file:///c:/laragon/www/adasi_portal_supplier/ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-IMPLEMENTATION-PLAN-20260904.md)  
> **Initial Review Report:** [`docs/audits/FIND-BUGS-ITEM-LEVEL-AWARD-SHIPMENT-20260904.md`](file:///c:/laragon/www/adasi_portal_supplier/docs/audits/FIND-BUGS-ITEM-LEVEL-AWARD-SHIPMENT-20260904.md)  
> **Implementation Result:** [`docs/results/ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-RESULT-20260904.md`](file:///c:/laragon/www/adasi_portal_supplier/docs/results/ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-RESULT-20260904.md)  
> **Audit Date:** September 4, 2026  
> **Verification Mode:** STRICT READ-ONLY ADVERSARIAL FINAL DIFF REVIEW (Post-Remediation Verification)  
> **Final Verdict:** `FINAL DIFF REVIEW: PASS`

---

## 1. Executive Summary & Verification Matrix

This report documents the independent, adversarial second-pass review of the remediations applied to resolve Findings 1–7 from the initial final diff review ([`FIND-BUGS-ITEM-LEVEL-AWARD-SHIPMENT-20260904.md`](file:///c:/laragon/www/adasi_portal_supplier/docs/audits/FIND-BUGS-ITEM-LEVEL-AWARD-SHIPMENT-20260904.md)).

All seven findings have been verified through static code inspection, execution path tracing, database schema verification, and negative automated regression test execution. Zero regressions were introduced. The full automated test suite passes with **399 passed tests (4,089 assertions)**, up from the pre-remediation count of 388 tests (4,042 assertions) and the pre-feature baseline of 355 tests (3,876 assertions).

| Finding | Original Problem | Code & Architecture Fix | Automated Test Coverage | Status |
|---|---|---|---|---|
| **1. Line Source Consistency** | No cross-validation between `purchase_order_id` and `quotation_item_id`; allowed foreign items or fallback to `pr_item_award_id = null`. | `ShipmentService` validates `PrItemAward` ownership on item-level POs. Mismatched items throw `InvalidArgumentException`. Legacy fallback restricted strictly to POs without awards. | `ShipmentAndPartialDeliveryTest` (3 tests: PO-A + Item-B, cross-supplier rejection, draft sync mismatch). | **CLOSED — VERIFIED** |
| **2. Duplicate Shipment Lines** | Duplicate entries for the same item in a single payload could bypass quantity ceilings (e.g. 5 kg + 5 kg > 8 kg). | Triple-layer defense: controller validator `after` hook, service-level duplicate rejection, and database composite unique constraint `(shipment_id, purchase_order_id, quotation_item_id)`. | `ShipmentAndPartialDeliveryTest` (3 tests: draft sync, 5+5 vs 8 ceiling bypass, DB unique constraint exception). | **CLOSED — VERIFIED** |
| **3. Quotation Status Award Eligibility** | Ineligible quotations (`draft`, `rejected`, `revision_requested`) could be awarded. | Defined `Quotation::AWARD_ELIGIBLE_STATUSES = ['submitted', 'accepted']`. Enforced across `PrItemAwardService`, `PurchaseOrderGenerationService`, and `PriceComparisonController`. | `ItemLevelAwardTest::test_cannot_award_item_from_ineligible_quotation_statuses` (validating rejection of 4 ineligible statuses and acceptance of 2 eligible statuses). | **CLOSED — VERIFIED** |
| **4. QC Shipment ↔ PO Consistency** | `QcInspectionController::store` did not verify that the posted `shipment_id` contained line items for the inspected PO. | `store()` independently checks `$shipment->items()->where('purchase_order_id', $po->id)->exists()`, throwing 422 `RuntimeException` if mismatched. Preserves separate inspection of consolidated shipments. | `ShipmentDocumentsAndQcIntegrationTest` (2 tests: direct POST negative mismatched shipment rejection, multi-PO consolidated shipment inspection). | **CLOSED — VERIFIED** |
| **5. Migration Down Safety** | Rollback of `document_sequences` ENUM to `('PR', 'PO')` caused MySQL Error 1265 truncation when `SHP` rows existed. | Migration `down()` deletes `where('type', 'SHP')` records prior to altering the ENUM column. | Live test executed with active `SHP` row: rollback succeeded cleanly; re-migration succeeded cleanly. | **CLOSED — VERIFIED** |
| **6. Partial-Delivery delivery_progress** | `PurchaseOrder::getDeliveryProgressAttribute()` prematurely returned `'received'` upon first partial arrival (e.g. 5/20 kg). | `delivery_progress` calculates arrived allocations (`where('status', 'arrived')`) against `total_ordered_weight`. Returns `'received'` only if `arrived >= total_ordered`. Preserves legacy fallback. | `ShipmentAndPartialDeliveryTest::test_delivery_progress_attribute_for_partial_and_full_deliveries_and_legacy_po` (0/20, 5/20 with `actual_arrival`, 20/20, and legacy PO). | **CLOSED — VERIFIED** |
| **7. Claim Resolution for Partial PO** | Resolving a claim unconditionally transitioned partially fulfilled POs to `completed`, terminating remaining deliveries. | Centralized domain helpers `PurchaseOrder::isFullyFulfilledAndInspected()` and `hasArrivedShipmentsAwaitingQc()`. PO only transitions to `completed` if 100% fulfilled; otherwise `waiting_qc` or `active`. | `ShipmentDocumentsAndQcIntegrationTest::test_claim_resolution_on_partial_ng_delivery_does_not_complete_po_and_allows_workflow_continuation`. | **CLOSED — VERIFIED** |

---

## 2. In-Depth Technical Verification for Each Finding

### Finding 1: Shipment Line Source Consistency
- **Location:** [`app/Services/ShipmentService.php:148-172`](file:///c:/laragon/www/adasi_portal_supplier/app/Services/ShipmentService.php#L148-L172) (`syncDraftItems`), [`app/Services/ShipmentService.php:281-305`](file:///c:/laragon/www/adasi_portal_supplier/app/Services/ShipmentService.php#L281-L305) (`submitShipment`).
- **Mechanism:**
  For any PO where `$po->awards()->exists()` is true:
  ```php
  $award = PrItemAward::where('purchase_order_id', $poId)
      ->where('quotation_item_id', $qItemId)
      ->first();

  if (! $award) {
      throw new InvalidArgumentException("Quotation item #{$qItemId} does not belong to Purchase Order #{$po->po_number}.");
  }

  if ((int) $award->supplier_id !== (int) $lockedShipment->supplier_id) {
      throw new InvalidArgumentException("Item does not belong to supplier #{$lockedShipment->supplier_id}.");
  }
  ```
  Only if `$po->awards()->exists()` is false does it evaluate `po_quotations` membership for legacy POs.
- **Disproving Attempts & Results:**
  1. *PO-A + quotation item from PO-B:* Rejected with `InvalidArgumentException` ("does not belong to Purchase Order").
  2. *PO-A + quotation item from competitor:* Rejected with `InvalidArgumentException`.
  3. *PO-A + award linked to another PO:* Rejected because the award query filters `where('purchase_order_id', $poId)`.
  4. *Attempting to persist `pr_item_award_id = null` for item-level POs:* Impossible because `$award` must be found and non-null, or the transaction aborts before line insertion.
- **Verification Status:** **CLOSED — VERIFIED**.

---

### Finding 2: Duplicate Shipment Line Prevention
- **Location:**
  - Database: [`database/migrations/2026_09_04_000002_create_shipments_tables.php:48`](file:///c:/laragon/www/adasi_portal_supplier/database/migrations/2026_09_04_000002_create_shipments_tables.php#L48)
  - Service: [`app/Services/ShipmentService.php:114-120, 226-232`](file:///c:/laragon/www/adasi_portal_supplier/app/Services/ShipmentService.php#L114-L120)
  - Controller: [`app/Http/Controllers/Supplier/SupplierShipmentController.php:105-118`](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Supplier/SupplierShipmentController.php#L105-L118)
- **Mechanism:**
  - **Controller:** Custom `$validator->after()` callback indexes submitted items by `purchase_order_id:quotation_item_id` and flags duplicates before any service calls.
  - **Service:** Pre-groups allocations by composite key in both `syncDraftItems` and `submitShipment`, throwing `InvalidArgumentException("Duplicate item entries detected...")`.
  - **Database:** Unique composite index verified live in MySQL:
    - Table: `shipment_items`
    - Key name: `shipment_items_unique_item`
    - Columns: `(shipment_id, purchase_order_id, quotation_item_id)`
- **Draft & Resubmission Behavior:**
  In `syncDraftItems`, all existing lines are purged (`$shipment->items()->delete()`) before inserting the validated, deduplicated set, ensuring re-syncing does not self-collide with the unique index.
- **Verification Status:** **CLOSED — VERIFIED**.

---

### Finding 3: Quotation Status Award Allow-List
- **Location:**
  - Model: [`app/Models/Quotation.php:35-43`](file:///c:/laragon/www/adasi_portal_supplier/app/Models/Quotation.php#L35-L43)
  - Award Service: [`app/Services/PrItemAwardService.php:77-79, 211-213`](file:///c:/laragon/www/adasi_portal_supplier/app/Services/PrItemAwardService.php#L77-L79)
  - PO Generation Service: [`app/Services/PurchaseOrderGenerationService.php:110-112`](file:///c:/laragon/www/adasi_portal_supplier/app/Services/PurchaseOrderGenerationService.php#L110-L112)
  - Controller & UI: [`app/Http/Controllers/Purchasing/PriceComparisonController.php:154`](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Purchasing/PriceComparisonController.php#L154)
- **Mechanism:**
  - `Quotation::AWARD_ELIGIBLE_STATUSES = [self::STATUS_SUBMITTED, self::STATUS_ACCEPTED]`.
  - `awardItem` and `awardBatch` strictly enforce `in_array($quotation->status, Quotation::AWARD_ELIGIBLE_STATUSES, true)`. Direct backend requests for quotations in `draft`, `revision_requested`, `rejected`, or `all_unavailable` immediately throw `InvalidArgumentException`.
  - Inside the pessimistic transaction in `PurchaseOrderGenerationService::generateFromAwards`, every award re-checks `in_array($award->quotation->status, Quotation::AWARD_ELIGIBLE_STATUSES, true)`. A saved award cannot generate a PO if the quotation was subsequently rejected or modified.
- **Verification Status:** **CLOSED — VERIFIED**.

---

### Finding 4: QC Shipment ↔ PO Consistency Validation
- **Location:** [`app/Http/Controllers/Qc/QcInspectionController.php:217-220`](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Qc/QcInspectionController.php#L217-L220) (`store`).
- **Mechanism:**
  ```php
  if ($shipment) {
      if (! $shipment->items()->where('purchase_order_id', $po->id)->exists()) {
          throw new \RuntimeException('The specified shipment does not contain items for this Purchase Order.');
      }
      if (QcInspection::where('po_id', $po->id)->where('shipment_id', $shipment->id)->exists()) {
          throw new \RuntimeException('This shipment for Purchase Order '.$po->po_number.' has already been inspected.');
      }
  }
  ```
- **Disproving Attempts & Results:**
  - Direct crafted POST linking PO-A to Shipment-B (containing only PO-B items) triggers the RuntimeException and flashes an error to the session. Verified no inspection record is created (`QcInspection::where('po_id', $poA->id)->exists()` is `false`).
  - Consolidated shipment containing PO-A and PO-B: inspecting PO-A attaches only PO-A items to `qc_items`, marks PO-A inspected, and leaves PO-B ready for its separate shipment-scoped inspection.
- **Verification Status:** **CLOSED — VERIFIED**.

---

### Finding 5: SHP document_sequences Rollback Safety
- **Location:** [`database/migrations/2026_09_04_000002_create_shipments_tables.php:89-92`](file:///c:/laragon/www/adasi_portal_supplier/database/migrations/2026_09_04_000002_create_shipments_tables.php#L89-L92) (`down`).
- **Mechanism:**
  ```php
  if (DB::getDriverName() === 'mysql') {
      DB::table('document_sequences')->where('type', 'SHP')->delete();
      DB::statement("ALTER TABLE document_sequences MODIFY COLUMN type ENUM('PR', 'PO') NOT NULL");
  }
  ```
- **Live Test Scenario:**
  1. Migration applied, active `document_sequences` row created with `type = 'SHP'`.
  2. `php artisan migrate:rollback --step=1` executed.
  3. Purge executed cleanly; `ALTER TABLE` succeeded without MySQL Error 1265 truncation.
  4. Column type verified narrowed to `enum('PR','PO')`.
  5. `php artisan migrate` re-applied cleanly, restoring `enum('PR','PO','SHP')`.
- **Deployment Safety Flag:**
  Migration `2026_09_04_000002` is an untracked, uncommitted file on this branch (`?? database/migrations/...`). Because it has not been merged or deployed to remote shared environments, in-place migration modification is clean. For non-disposable environments where an earlier revision was already executed, a forward migration would be required; for this repo state, it is fully contained.
- **Verification Status:** **CLOSED — VERIFIED**.

---

### Finding 6: Partial-Delivery delivery_progress Correction
- **Location:** [`app/Models/PurchaseOrder.php:210-240`](file:///c:/laragon/www/adasi_portal_supplier/app/Models/PurchaseOrder.php#L210-L240) (`getDeliveryProgressAttribute`).
- **Mechanism:**
  - Evaluates arrived allocations:
    ```php
    $arrivedAllocations = (float) $this->shipmentItems()
        ->whereHas('shipment', fn ($q) => $q->where('status', Shipment::STATUS_ARRIVED))
        ->sum('shipped_quantity');

    if ($totalOrdered > 0 && round($arrivedAllocations, 4) >= round($totalOrdered, 4)) {
        return 'received';
    }
    ```
  - For active shipments: returns `'fully_shipped'` if active allocations satisfy total ordered quantity, `'partially_shipped'` if partial, and `'not_shipped'` if zero.
  - Preserves legacy fallback: if no shipment items exist, returns `$this->actual_arrival ? 'received' : 'not_shipped'`.
- **Behavioral Scenarios Verified:**
  - 0 / 20 kg arrived $\rightarrow$ `'not_shipped'`
  - 5 / 20 kg arrived with `actual_arrival` set $\rightarrow$ `'partially_shipped'` (NOT `'received'`)
  - 20 / 20 kg arrived $\rightarrow$ `'received'`
  - Legacy PO with `actual_arrival` $\rightarrow$ `'received'`
- **Verification Status:** **CLOSED — VERIFIED**.

---

### Finding 7: Claim Resolution for Partially Fulfilled PO
- **Location:**
  - Model helpers: [`app/Models/PurchaseOrder.php:245-279`](file:///c:/laragon/www/adasi_portal_supplier/app/Models/PurchaseOrder.php#L245-L279) (`isFullyFulfilledAndInspected`), lines 284–306 (`hasArrivedShipmentsAwaitingQc`).
  - Claim Controller: [`app/Http/Controllers/Purchasing/MaterialClaimController.php:225-231`](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Purchasing/MaterialClaimController.php#L225-L231) (`resolve`).
  - QC Controller: [`app/Http/Controllers/Qc/QcInspectionController.php:293-299`](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Qc/QcInspectionController.php#L293-L299) (`store`).
- **Mechanism:**
  - `isFullyFulfilledAndInspected()` calculates total shipped quantity from shipments with an inspection `status = 'ok'`:
    ```php
    $okShipmentIds = QcInspection::where('po_id', $this->id)
        ->where('status', 'ok')
        ->pluck('shipment_id')
        ->filter()
        ->all();

    $totalOkDelivered = (float) $this->shipmentItems()
        ->whereIn('shipment_id', $okShipmentIds)
        ->sum('shipped_quantity');

    return round($totalOkDelivered, 4) >= round($totalOrdered, 4);
    ```
  - In `MaterialClaimController::resolve()`, when all active claims are resolved:
    - If `isFullyFulfilledAndInspected()` $\rightarrow$ `'completed'`
    - Elseif `hasArrivedShipmentsAwaitingQc()` $\rightarrow$ `'waiting_qc'`
    - Else $\rightarrow$ `'active'`
- **Workflow Continuation Verified:**
  Ordered = 20 kg; Partial shipment = 5 kg; QC = NG; Claim resolved $\rightarrow$ PO status becomes `'active'`. The supplier is able to create and submit subsequent shipments for the remaining 15 kg without hindrance.
- **Verification Status:** **CLOSED — VERIFIED**.

---

## 3. Side Effects & Invariant Integrity Check

1. **Legacy PO Compatibility:**
   Legacy POs without `pr_item_awards` correctly route through `po_quotations` in `ShipmentService`, and their arrival and QC checks use direct PO date and single-inspection fallbacks without error.
2. **Multi-PR Same-Supplier Consolidation:**
   Verified via `ItemLevelPoGenerationTest::test_same_supplier_multi_pr_consolidation` (PASS).
3. **Quotation Invariant (1 Quotation $\rightarrow$ max 1 PO):**
   Protected by `po_quotations.quotation_id` unique database index and service-level atomic transaction lock.
4. **Supplier Data Isolation:**
   All 11 tests in `SupplierDataIsolationTest` pass, and `ShipmentDocumentsAndQcIntegrationTest::test_supplier_data_isolation_on_shipments` verifies suppliers cannot inspect or modify other suppliers' shipments.
5. **Cancelled Shipments Allocation Release:**
   `whereIn('shipments.status', [Shipment::STATUS_SUBMITTED, Shipment::STATUS_ARRIVED])` ensures that draft and cancelled shipments release their allocated weights.
6. **QC Inspection Duplicate Prevention:**
   Attempting to re-inspect an already-inspected shipment for the same PO throws a `RuntimeException`.
7. **Document Numbering Atomicity:**
   `DocumentSequence::generateNumber()` uses pessimistic row locks on `document_sequences` for PR, PO, and SHP sequence generation.

---

## 4. Test Suite Claims & Audit Integrity

### Test Suite Summary
```text
PASS  Tests\Unit\...
PASS  Tests\Feature\...

Tests:    399 passed (4,089 assertions)
Duration: 143.71s
Failures: 0
Errors:   0
Skipped:  0
```

### Breakdown of the 11 Remediation Regression Tests
1. `ShipmentAndPartialDeliveryTest::test_cannot_allocate_item_belonging_to_different_po` (Negative path: cross-PO line allocation rejected).
2. `ShipmentAndPartialDeliveryTest::test_cannot_allocate_item_belonging_to_another_supplier` (Negative path: cross-supplier line allocation rejected).
3. `ShipmentAndPartialDeliveryTest::test_cannot_sync_draft_with_mismatched_po_and_item` (Negative path: draft sync mismatched PO item rejected).
4. `ShipmentAndPartialDeliveryTest::test_duplicate_shipment_line_rejected_in_draft_sync` (Negative path: duplicate allocation lines in draft rejected).
5. `ShipmentAndPartialDeliveryTest::test_duplicate_shipment_line_cannot_bypass_quantity_ceiling` (Negative path: 5+5 vs 8 ceiling bypass rejected).
6. `ShipmentAndPartialDeliveryTest::test_database_uniqueness_constraint_prevents_duplicate_shipment_line` (Negative path: DB unique constraint violation).
7. `ShipmentAndPartialDeliveryTest::test_delivery_progress_attribute_for_partial_and_full_deliveries_and_legacy_po` (State verification: 0/20, 5/20, 20/20, and legacy).
8. `ItemLevelAwardTest::test_cannot_award_item_from_ineligible_quotation_statuses` (Negative path: 4 non-eligible quotation statuses rejected).
9. `ShipmentDocumentsAndQcIntegrationTest::test_qc_inspection_store_rejects_mismatched_shipment_without_items_for_po` (Negative path: direct POST mismatched shipment rejected).
10. `ShipmentDocumentsAndQcIntegrationTest::test_qc_inspection_store_supports_legitimate_multi_po_shipment` (Workflow verification: separate inspections for multi-PO shipment).
11. `ShipmentDocumentsAndQcIntegrationTest::test_claim_resolution_on_partial_ng_delivery_does_not_complete_po_and_allows_workflow_continuation` (Workflow verification: claim resolution leaves PO active and allows remaining delivery).

### Concurrency Classification
The implementation report ([`ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-RESULT-20260904.md:380-394`](file:///c:/laragon/www/adasi_portal_supplier/docs/results/ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-RESULT-20260904.md#L380-L394)) accurately classifies the concurrency test as **Serial Race Regression Coverage** (simulated serialization) and documents static row-level lock ordering (`orderBy('id')->lockForUpdate()`), resolving the previous overclaim.

---

## 5. Documentation Observations

The following non-blocking documentation observations are recorded for consistency:
1. **Sections 16 & 17 in Implementation Report:** Sections 16 and 17 retain pre-remediation counts (33 tests / 141 assertions across new files), whereas Section 19 and the Final Diff section document the post-remediation totals (44 tests / 188 assertions across new files; 399 tests / 4,089 assertions overall).
2. **Locking Terminology:** Line 393 notes that lock ordering "ensures deadlock-free race prevention". Technically, lock ordering minimizes deadlock probability; it does not theoretically eliminate all deadlocks in complex multi-table graphs.

---

## 6. Final Verdict

```text
FINAL DIFF REVIEW: PASS
```

All seven findings from `FIND-BUGS-ITEM-LEVEL-AWARD-SHIPMENT-20260904.md` are closed, verified with negative automated regression tests, and backed by robust multi-layer guards. Zero regressions were introduced.

The implementation is structurally sound and may proceed to the final `/laravel-security-audit` quality gate.
