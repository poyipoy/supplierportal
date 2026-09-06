# Find Bugs Result: Item-Level Award & Shipment Final Diff Review

> **Project:** ADASI Portal Supplier  
> **Workspace:** `C:\laragon\www\adasi_portal_supplier`  
> **Target Specification:** [`ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-IMPLEMENTATION-PLAN-20260904.md`](file:///c:/laragon/www/adasi_portal_supplier/ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-IMPLEMENTATION-PLAN-20260904.md)  
> **Implementation Report:** [`docs/results/ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-RESULT-20260904.md`](file:///c:/laragon/www/adasi_portal_supplier/docs/results/ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-RESULT-20260904.md)  
> **Audit Date:** September 4, 2026  
> **Audit Mode:** STRICT READ-ONLY ADVERSARIAL FINAL DIFF REVIEW (No files modified)  
> **Final Verdict:** `FINAL DIFF REVIEW: FAIL — ACTION REQUIRED`

---

## 1. Executive Summary & Verification Matrix

This audit is a strict read-only final diff review of the changes introduced for the **Item-Level Award, Multi-PO Shipment Consolidation, and Partial Delivery Workflow**.

The implementation establishes core models, services, migrations, and UI flows. However, this adversarial review identified **four (4) confirmed data-integrity / authorization gaps**, **two (2) potential regressions / safety hazards**, **one (1) business-rule ambiguity**, and **three (3) reporting overclaims** that must be resolved prior to production readiness.

| Target Area | Evaluation | Primary Classification | Key Finding |
|---|---|---|---|
| **A. Item-Level Award Integrity** | Non-Compliant | `CONFIRMED DATA-INTEGRITY ISSUE` | Quotations in `draft`, `rejected`, or `revision_requested` can be awarded. |
| **B. PO Generation** | Compliant | `SAFE / VERIFIED` | Grouping, atomicity, and `po_quotations` uniqueness verified. |
| **C. Quotation Status / Rejection** | Compliant | `SAFE / VERIFIED` | Unresolved PR items keep PR actionable; non-winning quotations rejected only upon full PR completion. |
| **D. PR Completion** | Compliant | `SAFE / VERIFIED` | Coverage-based completion derived accurately via `awards.purchase_order_id`. |
| **E. Shipment Supplier Isolation** | Compliant | `SAFE / VERIFIED` | Server-side authorization and policy guards enforce `supplier_id` strictly. |
| **F. Shipment Line Consistency** | Non-Compliant | `CONFIRMED DATA-INTEGRITY ISSUE` | No cross-validation between `purchase_order_id` and `quotation_item_id` in shipment items. |
| **G. Partial Delivery Quantity** | Non-Compliant | `CONFIRMED DATA-INTEGRITY ISSUE` | Duplicate line items within the same shipment request bypass quantity limits. |
| **H. True Concurrency Claim** | Overclaimed | `REPORTING OVERCLAIM` | Test is single-threaded serial execution, not real concurrency verification. |
| **I. Arrival Semantics** | Incomplete | `POTENTIAL REGRESSION — NEEDS VERIFICATION` | `PurchaseOrder::delivery_progress` prematurely returns `'received'` on partial arrival. |
| **J. PO Status During Partial Delivery** | Ambiguous | `BUSINESS-RULE AMBIGUITY` | NG inspection triggers `claim_needed`, which blocks future shipments; resolving claim unconditionally sets PO to `completed`. |
| **K. Shipment Document Status** | Compliant | `SAFE / VERIFIED` | All 6 statuses including `issued` are supported in model, DB, controller, and Blade. |
| **L. File Lifecycle** | Overclaimed | `REPORTING OVERCLAIM` | Old attachment files are not deleted/cleaned up; version history is retained. |
| **M. Migration Safety** | Unsafe Rollback | `POTENTIAL REGRESSION — NEEDS VERIFICATION` | Rollback of `document_sequences` enum fails in MySQL if `SHP` rows exist. |
| **N. Receiving / QC** | Incomplete Guard | `CONFIRMED DATA-INTEGRITY ISSUE` | `QcInspectionController::store` lacks validation that the shipment contains items for the PO. |
| **O. Legacy Compatibility** | Compliant | `SAFE / VERIFIED` | Legacy POs, direct arrival confirmation, and direct QC inspections remain operational. |
| **P. Report Accuracy** | Partially Inaccurate | `REPORTING OVERCLAIM` | Deviations regarding `purchase_order_items` and concurrency claims were inaccurately stated. |

---

## 2. Detailed Findings

---

### Finding 1: Lack of Cross-Validation Between `purchase_order_id` and `quotation_item_id` in Shipment Creation
- **Classification:** `CONFIRMED DATA-INTEGRITY ISSUE`
- **Severity:** Critical / High
- **Exact File / Location:**
  - [`app/Services/ShipmentService.php:125-143`](file:///c:/laragon/www/adasi_portal_supplier/app/Services/ShipmentService.php#L125-L143) (`syncDraftItems`)
  - [`app/Services/ShipmentService.php:218-270`](file:///c:/laragon/www/adasi_portal_supplier/app/Services/ShipmentService.php#L218-L270) (`submitShipment`)
  - [`app/Http/Controllers/Supplier/SupplierShipmentController.php:94-103`](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Supplier/SupplierShipmentController.php#L94-L103) (`store`)
- **Execution Path:**
  `SupplierShipmentController::store` $\rightarrow$ `ShipmentService::createDraft` $\rightarrow$ `ShipmentService::syncDraftItems` / `ShipmentService::submitShipment`.
- **Evidence:**
  In `submitShipment`, the service verifies that every `purchase_order_id` belongs to the shipment's supplier (`$po->supplier_id === $lockedShipment->supplier_id`) and locks each `quotation_item_id`. However, **it never cross-validates whether `quotation_item_id` actually belongs to `purchase_order_id`**:
  ```php
  // Lines 257-268
  $award = PrItemAward::where('purchase_order_id', $itemAlloc['purchase_order_id'])
      ->where('quotation_item_id', $itemAlloc['quotation_item_id'])
      ->first();

  ShipmentItem::create([
      'shipment_id' => $lockedShipment->id,
      'purchase_order_id' => $itemAlloc['purchase_order_id'],
      'quotation_item_id' => $itemAlloc['quotation_item_id'],
      'pr_item_award_id' => $award?->id, // Evaluates to null when mismatched!
      'shipped_quantity' => round((float) $itemAlloc['shipped_quantity'], 4),
  ]);
  ```
  If a supplier posts `purchase_order_id = PO-A` with a `quotation_item_id` belonging to PO-B (or even a quotation item belonging to a competitor):
  1. The PO belongs to the supplier, so PO validation passes.
  2. The quotation item is loaded without verifying its ownership or association to the PO.
  3. The active allocation query for `(PO-A, foreign_quotation_item_id)` returns `0.0`.
  4. `$award` evaluates to `null`.
  5. Because `pr_item_award_id` is nullable in `shipment_items`, the record is persisted with mismatched references.
- **Affected Invariant:** Primary Target F (Shipment Line Consistency: *"A ShipmentItem must not be able to represent PO A + Quotation Item belonging to PO B + Award belonging to PO C"*).
- **Current Test Coverage:** None. `tests/Feature/ShipmentAndPartialDeliveryTest.php` only supplies valid matching pairs.
- **Why Existing Tests Do Not Catch It:** Tests only supply pre-matched valid fixture data.
- **Smallest Safe Remediation:**
  In `ShipmentService::submitShipment` and `syncDraftItems`, assert that every item belongs to the specified PO via `pr_item_awards` or `po_quotations`:
  ```php
  $isValidPoItem = PrItemAward::where('purchase_order_id', $poId)
      ->where('quotation_item_id', $qItemId)
      ->exists()
      || DB::table('po_quotations')
          ->join('quotation_items', 'quotation_items.quotation_id', '=', 'po_quotations.quotation_id')
          ->where('po_quotations.po_id', $poId)
          ->where('quotation_items.id', $qItemId)
          ->exists();

  if (! $isValidPoItem) {
      throw new InvalidArgumentException("Quotation item #{$qItemId} does not belong to Purchase Order #{$poId}.");
  }
  ```

---

### Finding 2: Duplicate Line Allocation in a Single Shipment Bypasses Quantity Ceiling
- **Classification:** `CONFIRMED DATA-INTEGRITY ISSUE`
- **Severity:** High
- **Exact File / Location:**
  - [`app/Services/ShipmentService.php:219-253`](file:///c:/laragon/www/adasi_portal_supplier/app/Services/ShipmentService.php#L219-L253) (`submitShipment`)
  - [`app/Http/Controllers/Supplier/SupplierShipmentController.php:98-102`](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Supplier/SupplierShipmentController.php#L98-L102) (`store`)
- **Execution Path:**
  `SupplierShipmentController::store` $\rightarrow$ `ShipmentService::submitShipment`.
- **Evidence:**
  In `submitShipment`:
  ```php
  foreach ($itemsData as $itemAlloc) {
      $poId = (int) $itemAlloc['purchase_order_id'];
      $qItemId = (int) $itemAlloc['quotation_item_id'];
      $shippedQty = round((float) $itemAlloc['shipped_quantity'], 4);
      ...
      $existingAllocations = (float) DB::table('shipment_items')
          ->join('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')
          ->where('shipment_items.purchase_order_id', $poId)
          ->where('shipment_items.quotation_item_id', $qItemId)
          ->where('shipment_items.shipment_id', '!=', $lockedShipment->id)
          ->whereIn('shipments.status', [Shipment::STATUS_SUBMITTED, Shipment::STATUS_ARRIVED])
          ->whereNull('shipments.deleted_at')
          ->lockForUpdate()
          ->sum('shipment_items.shipped_quantity');

      $remainingQty = round($orderedQty - $existingAllocations, 4);

      if ($shippedQty > $remainingQty + 0.0001) { throw ... }
  }
  ```
  If `$itemsData` contains two duplicate entries for the same `(purchase_order_id, quotation_item_id)` (for example, two lines of 5 kg against an ordered balance of 8 kg):
  - In loop iteration 1: `existingAllocations` = 0 kg; `remaining` = 8 kg; 5 kg $\le$ 8 kg $\rightarrow$ Validated.
  - In loop iteration 2: `existingAllocations` from *other* shipments is still 0 kg (iteration 1 has not been inserted into `shipment_items`); 5 kg $\le$ 8 kg $\rightarrow$ Validated.
  - Lines 256–270 then insert *both* rows into `shipment_items`, creating a total shipment allocation of 10 kg against an 8 kg limit.
  - Furthermore, `migration 2026_09_04_000002` does not define a unique constraint on `(shipment_id, purchase_order_id, quotation_item_id)`.
- **Affected Invariant:** Hard Invariant 7 (`SUM(active shipment quantities) <= ordered PO item quantity`).
- **Current Test Coverage:** `test_over_allocation_strictly_rejected` only tests a single item whose quantity exceeds remaining balance.
- **Why Existing Tests Do Not Catch It:** No test submitted duplicate lines for the same PO item in a single shipment payload.
- **Smallest Safe Remediation:**
  In `ShipmentService::submitShipment` and `syncDraftItems`, enforce uniqueness or aggregate allocations by item before validation:
  ```php
  $duplicates = collect($itemsData)
      ->groupBy(fn ($i) => $i['purchase_order_id'] . ':' . $i['quotation_item_id'])
      ->filter(fn ($group) => $group->count() > 1);

  if ($duplicates->isNotEmpty()) {
      throw new InvalidArgumentException("Duplicate line items detected for the same Purchase Order item in this shipment.");
  }
  ```

---

### Finding 3: Quotations in `draft`, `rejected`, or `revision_requested` Can Be Awarded
- **Classification:** `CONFIRMED DATA-INTEGRITY ISSUE`
- **Severity:** Medium
- **Exact File / Location:**
  - [`app/Services/PrItemAwardService.php:73-75`](file:///c:/laragon/www/adasi_portal_supplier/app/Services/PrItemAwardService.php#L73-L75) (`awardItem`)
  - [`app/Services/PrItemAwardService.php:203-205`](file:///c:/laragon/www/adasi_portal_supplier/app/Services/PrItemAwardService.php#L203-L205) (`awardBatch`)
  - [`app/Http/Controllers/Purchasing/PriceComparisonController.php:154`](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Purchasing/PriceComparisonController.php#L154) (`interSupplier`)
- **Execution Path:**
  `PriceComparisonController::saveItemAwards` $\rightarrow$ `PrItemAwardService::awardBatch`.
- **Evidence:**
  1. In `PriceComparisonController::interSupplier`:
     ```php
     $isSelectable = $isAvailable && $quotation->status !== Quotation::STATUS_ALL_UNAVAILABLE && (! $award || $award->purchase_order_id === null);
     ```
     Because the controller loads `whereIn('status', ['submitted', 'accepted', 'rejected'])`, a `rejected` quotation item has `$isSelectable = true`.
  2. In `PrItemAwardService::awardItem` and `awardBatch`, the only check is:
     ```php
     if ($quotation->status === Quotation::STATUS_ALL_UNAVAILABLE) {
         throw new InvalidArgumentException("...");
     }
     ```
     It does not verify `in_array($quotation->status, [Quotation::STATUS_SUBMITTED, Quotation::STATUS_ACCEPTED], true)`.
  3. If an item from a `draft` or `revision_requested` quotation is awarded, the supplier can still revise prices and dimensions via `Supplier\QuotationController::update` (guarded by `$quotation->canBeRevisedBySupplier()`), mutating an already-awarded offer before PO generation.
- **Affected Invariant:** Target A (Item-Level Award Integrity: *"award whose quotation later becomes inconsistent"*).
- **Current Test Coverage:** `ItemLevelAwardTest` tests unavailable items and `all_unavailable` status, but not `draft` or `rejected` quotations.
- **Why Existing Tests Do Not Catch It:** Tests only created submitted quotations.
- **Smallest Safe Remediation:**
  In `PrItemAwardService::awardItem` and `awardBatch`:
  ```php
  if (! in_array($quotation->status, [Quotation::STATUS_SUBMITTED, Quotation::STATUS_ACCEPTED], true)) {
      throw new InvalidArgumentException("Quotation #{$quotation->id} cannot be awarded because its status is {$quotation->status}.");
  }
  ```
  And in `PriceComparisonController::interSupplier`:
  ```php
  $isSelectable = $isAvailable
      && in_array($quotation->status, [Quotation::STATUS_SUBMITTED, Quotation::STATUS_ACCEPTED], true)
      && (! $award || $award->purchase_order_id === null);
  ```

---

### Finding 4: Missing PO Item Cross-Validation in `QcInspectionController::store`
- **Classification:** `CONFIRMED DATA-INTEGRITY ISSUE`
- **Severity:** Medium
- **Exact File / Location:**
  - [`app/Http/Controllers/Qc/QcInspectionController.php:213-221`](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Qc/QcInspectionController.php#L213-L221) (`store`)
- **Execution Path:**
  `POST /qc/inspections/{po_id}` with arbitrary `shipment_id`.
- **Evidence:**
  In `QcInspectionController::create`, the form check guards against mismatched shipments:
  ```php
  $hasPoItems = $shipment->items()->where('purchase_order_id', $po->id)->exists();
  if (! $hasPoItems) {
      return redirect()->route('qc.inspections.index')->with('error', 'The specified shipment does not contain items for this PO.');
  }
  ```
  However, in `QcInspectionController::store`:
  ```php
  if ($shipment) {
      if (QcInspection::where('po_id', $po->id)->where('shipment_id', $shipment->id)->exists()) {
          throw new \RuntimeException('This shipment has already been inspected.');
      }
  }
  ```
  `store()` completely omits verifying whether `$shipment->items()->where('purchase_order_id', $po->id)->exists()`. An inspector or direct POST request can link PO-A to Shipment-B (belonging to another PO or even another supplier).
- **Affected Invariant:** Target N (Receiving / QC: *"QC for shipment belonging to another PO"*).
- **Current Test Coverage:** Tests only pass the intended shipment ID.
- **Why Existing Tests Do Not Catch It:** No negative test posts an unrelated `shipment_id` during QC store.
- **Smallest Safe Remediation:**
  In `QcInspectionController::store`:
  ```php
  if ($shipment) {
      if (! $shipment->items()->where('purchase_order_id', $po->id)->exists()) {
          throw new \RuntimeException('The specified shipment does not contain items for this Purchase Order.');
      }
      if (QcInspection::where('po_id', $po->id)->where('shipment_id', $shipment->id)->exists()) {
          throw new \RuntimeException('This shipment has already been inspected.');
      }
  }
  ```

---

### Finding 5: MySQL Down Migration Failure for `document_sequences` ENUM
- **Classification:** `POTENTIAL REGRESSION — NEEDS VERIFICATION`
- **Severity:** Medium
- **Exact File / Location:**
  - [`database/migrations/2026_09_04_000002_create_shipments_tables.php:88-90`](file:///c:/laragon/www/adasi_portal_supplier/database/migrations/2026_09_04_000002_create_shipments_tables.php#L88-L90)
- **Execution Path:**
  `php artisan migrate:rollback` after shipment sequence numbers (`SHP/...`) have been generated.
- **Evidence:**
  In the down migration:
  ```php
  if (DB::getDriverName() === 'mysql') {
      DB::statement("ALTER TABLE document_sequences MODIFY COLUMN type ENUM('PR', 'PO') NOT NULL");
  }
  ```
  When shipments are generated, `Shipment::generateShipmentNumber()` inserts records into `document_sequences` where `type = 'SHP'`. In MySQL with strict mode enabled (`STRICT_TRANS_TABLES`), executing `ALTER TABLE ... MODIFY COLUMN type ENUM('PR', 'PO')` fails with:
  `SQLSTATE[22001]: String data, right truncated: 1265 Data truncated for column 'type' at row X`.
  The migration rollback was only verified prior to generating `SHP` sequence records.
- **Affected Invariant:** Target M (Migration Safety: *"Verify DOWN migration behavior after actual SHP sequence rows exist"*).
- **Current Test Coverage:** Rollback test was run against an empty database.
- **Why Existing Tests Do Not Catch It:** No `SHP` sequence rows existed prior to rollback testing.
- **Smallest Safe Remediation:**
  In `2026_09_04_000002_create_shipments_tables.php::down()`:
  ```php
  if (DB::getDriverName() === 'mysql') {
      DB::table('document_sequences')->where('type', 'SHP')->delete();
      DB::statement("ALTER TABLE document_sequences MODIFY COLUMN type ENUM('PR', 'PO') NOT NULL");
  }
  ```

---

### Finding 6: Premature `'received'` Progress Attribute on PurchaseOrder
- **Classification:** `POTENTIAL REGRESSION — NEEDS VERIFICATION`
- **Severity:** Low / Medium
- **Exact File / Location:**
  - [`app/Models/PurchaseOrder.php:201-204`](file:///c:/laragon/www/adasi_portal_supplier/app/Models/PurchaseOrder.php#L201-L204) (`getDeliveryProgressAttribute`)
- **Execution Path:**
  `PurchaseOrder::getDeliveryProgressAttribute()` when `$this->actual_arrival` is set after the first partial delivery.
- **Evidence:**
  In `PurchaseOrder.php`:
  ```php
  public function getDeliveryProgressAttribute(): string
  {
      if ($this->actual_arrival) {
          return 'received';
      }

      $activeAllocations = (float) $this->shipmentItems()
          ->whereHas('shipment', fn ($q) => $q->whereIn('status', [Shipment::STATUS_SUBMITTED, Shipment::STATUS_ARRIVED]))
          ->sum('shipped_quantity');
      ...
  ```
  When the first partial shipment arrives, `ShipmentService::confirmArrival` sets `purchase_orders.actual_arrival = $arrivalDate`.
  Because `if ($this->actual_arrival) { return 'received'; }` checks arrival before comparing quantities, a PO that has received 5 kg out of 20 kg immediately returns `'received'`. The partial delivery progress calculations (`not_shipped`, `partially_shipped`, `fully_shipped`) on lines 206–224 are never reached once any partial consignment arrives.
- **Affected Invariant:** Target I (Arrival Semantics: *"A first partial shipment must not falsely mark the complete PO as received when quantity remains outstanding"*).
- **Current Test Coverage:** Tests assert PO status (`active`/`waiting_qc`), not the computed attribute `delivery_progress`.
- **Why Existing Tests Do Not Catch It:** No test asserted `$po->delivery_progress` following partial arrival.
- **Smallest Safe Remediation:**
  Check whether arrived allocations meet total ordered quantity before returning `'received'`:
  ```php
  public function getDeliveryProgressAttribute(): string
  {
      $totalOrdered = $this->awards()->exists()
          ? (float) $this->awards->sum(fn ($a) => (float) ($a->quotationItem?->offered_total_weight ?? $a->prItem?->total_weight ?? 0))
          : (float) $this->allQuotationItems()->sum(fn ($qi) => (float) ($qi->offered_total_weight ?? $qi->prItem?->total_weight ?? 0));

      $arrivedAllocations = (float) $this->shipmentItems()
          ->whereHas('shipment', fn ($q) => $q->where('status', Shipment::STATUS_ARRIVED))
          ->sum('shipped_quantity');

      if ($totalOrdered > 0 && $arrivedAllocations >= $totalOrdered) {
          return 'received';
      }
      ...
  ```

---

### Finding 7: Unconditional `completed` Transition in Legacy `MaterialClaimController` on Claim Resolution
- **Classification:** `BUSINESS-RULE AMBIGUITY`
- **Severity:** Medium
- **Exact File / Location:**
  - [`app/Http/Controllers/Purchasing/MaterialClaimController.php:220-225`](file:///c:/laragon/www/adasi_portal_supplier/app/Http/Controllers/Purchasing/MaterialClaimController.php#L220-L225)
- **Execution Path:**
  Purchasing resolves a claim resulting from an NG partial shipment.
- **Evidence:**
  In `MaterialClaimController::resolve()`:
  ```php
  if (! MaterialClaim::where('po_id', $claim->po_id)->whereIn('status', ['pending', 'responded', 'escalated'])->exists()) {
      $claim->purchaseOrder->update(['status' => 'completed']);
  }
  ```
  If a partial shipment (e.g. 5 tons of a 20-ton order) is inspected as NG, the PO transitions to `claim_needed`. While in `claim_needed`, `SupplierShipmentController::create` and `ShipmentService::submitShipment` reject further shipments.
  When Purchasing marks the claim as `resolved`, `MaterialClaimController` unconditionally transitions the PO to `completed`, prematurely closing the PO even though 15 tons were never delivered.
- **Affected Invariant:** Target J (PO Status During Partial Delivery: *"Specifically check interactions with claim_needed and whether an NG partial shipment unintentionally blocks remaining deliveries or replacement delivery"*).
- **Current Test Coverage:** Existing tests verify NG transitions PO to `claim_needed`, but do not test claim resolution in a partial delivery scenario.
- **Why Existing Tests Do Not Catch It:** No test executed claim resolution for a partial delivery PO.
- **Smallest Safe Remediation:**
  In `MaterialClaimController::resolve()`:
  ```php
  $po = $claim->purchaseOrder;
  $isFullyFulfilled = (float) $po->shipmentItems()
      ->whereHas('shipment', fn ($q) => $q->where('status', Shipment::STATUS_ARRIVED))
      ->sum('shipped_quantity') >= (float) ($po->total_ordered_weight ?? 0);

  $po->update(['status' => $isFullyFulfilled ? 'completed' : 'active']);
  ```

---

### Finding 8: Overclaim of "Real Concurrency Verification" in Implementation Report
- **Classification:** `REPORTING OVERCLAIM`
- **Severity:** Low / Informational (Documentation)
- **Exact File / Location:**
  - [`docs/results/ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-RESULT-20260904.md:380-394`](file:///c:/laragon/www/adasi_portal_supplier/docs/results/ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-RESULT-20260904.md#L380-L394) (Section 18)
  - [`tests/Feature/ShipmentAndPartialDeliveryTest.php:258-285`](file:///c:/laragon/www/adasi_portal_supplier/tests/Feature/ShipmentAndPartialDeliveryTest.php#L258-L285) (`test_concurrency_race_condition_protection`)
- **Evidence:**
  `test_concurrency_race_condition_protection` executes:
  ```php
  $this->shipmentService->submitShipment($shpA, [...]);
  ...
  $this->shipmentService->submitShipment($shpB, [...]);
  ```
  Request A runs to completion in transaction 1. Request B then executes in transaction 2. There are no overlapping transactions, separate database connections, or concurrent workers producing true lock contention.
  The row-level locking implementation (`orderBy('id')->lockForUpdate()`) is statically sound, but the test is **SERIAL RACE REGRESSION COVERAGE**, not "Real Concurrency Verification".
- **Affected Invariant:** Target H (True Concurrency Claim).
- **Smallest Safe Remediation:**
  Accurately classify Section 18 of the report as `Serial Race Regression Coverage` and note that true multi-process stress/concurrency testing requires external parallel test runners.

---

### Finding 9: Inaccurate Claim of "Deviations From Plan: NONE"
- **Classification:** `REPORTING OVERCLAIM`
- **Severity:** Low / Informational (Documentation)
- **Exact File / Location:**
  - [`docs/results/ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-RESULT-20260904.md:417-420`](file:///c:/laragon/www/adasi_portal_supplier/docs/results/ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-RESULT-20260904.md#L417-L420) (Section 20)
- **Evidence:**
  The specification assumed the existence or creation of a `purchase_order_items` table (`ShipmentItem -> PurchaseOrderItem -> QuotationItem -> PrItem`). Phase 0 confirmed that the repository had no such table. The implementation adapted this by linking `ShipmentItem` directly to `QuotationItem` and `PrItemAward`.
  While this is an architecture-compatible adaptation, stating "Deviations From Plan: NONE" is inaccurate; it should be documented as an approved architecture-compatible deviation.
- **Affected Invariant:** Target P (Report Accuracy).
- **Smallest Safe Remediation:**
  Document the intentional schema adaptation regarding `purchase_order_items` in Section 20.

---

### Finding 10: Inaccurate Claim of Superseded File Cleanup in Report
- **Classification:** `REPORTING OVERCLAIM`
- **Severity:** Low / Informational (Documentation)
- **Exact File / Location:**
  - [`docs/results/ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-RESULT-20260904.md:234`](file:///c:/laragon/www/adasi_portal_supplier/docs/results/ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-RESULT-20260904.md#L234) (Section 10)
  - [`app/Services/ShipmentService.php:400-434`](file:///c:/laragon/www/adasi_portal_supplier/app/Services/ShipmentService.php#L400-L434) (`uploadDocument`)
- **Evidence:**
  Section 10 states: *"Superseded files are safely cleaned up."*
  In `ShipmentService::uploadDocument`, each upload creates a new `Attachment` row. Old physical files and previous attachment records are not deleted; historical versions are preserved and `getLatestAttachmentAttribute()` resolves the most recent.
- **Affected Invariant:** Target L & P.
- **Smallest Safe Remediation:**
  Clarify that document version history is preserved rather than physically deleted.

---

## 3. Analysis of Safe & Verified Components

- **Item-Level Winner Uniqueness (Invariant 1):**
  Guaranteed at database level by `UNIQUE(pr_item_id)` in `pr_item_awards` table and pessimistic ordering `orderBy('id')->lockForUpdate()` in `PrItemAwardService::awardBatch`.
- **Single-Supplier PO Grouping (Invariant 2):**
  `PurchaseOrderGenerationService::generateFromAwards` groups awards by `supplier_id` (`$groupedBySupplier = $lockedAwards->groupBy('supplier_id')`), guaranteeing that each PO contains only one supplier's items.
- **Quotation Invariant (Invariant 4):**
  `po_quotations.quotation_id` unique index and pre-checks guarantee 1 Quotation $\rightarrow$ max 1 PO.
- **Multi-PO Shipment Consolidation (Invariant 5):**
  `ShipmentService::submitShipment` verifies that all consolidated POs belong to the shipment supplier (`$po->supplier_id === $lockedShipment->supplier_id`).
- **PR Completion Semantics (Phase 4):**
  `PurchaseOrderGenerationService` derives PR completion strictly from full item coverage with valid PO assignments (`$totalItemsCount > 0 && $awardedAndPoCount === $totalItemsCount`). Unresolved items keep the PR in `bidding` without rejecting competing quotations prematurely.
- **Supplier Data Isolation:**
  `SupplierShipmentController` and `ShipmentPolicy` enforce `(int) $shipment->supplier_id === (int) auth()->id()` across all supplier actions, returning `403 Forbidden` on mismatch.
- **Multi-Consignment QC (Phase 9):**
  Independent QC inspection events are supported per shipment consignment (`where('po_id', $po->id)->where('shipment_id', $shipment->id)->exists()`).
- **Document Numbering:**
  Atomic sequential generation via `document_sequences` using `lockForUpdate` verified for PR, PO, and SHP types.
- **Legacy Compatibility:**
  Direct PO arrival (`PurchaseOrderController::confirmArrival`) and direct single-PO QC inspections (`shipment_id = null`) remain functional.

---

## 4. Final Verdict

```text
FINAL DIFF REVIEW: FAIL — ACTION REQUIRED
```

### Confirmed Issues Required to Be Fixed Before Next Quality Gate:
1. **Shipment Line Cross-Validation:** In `ShipmentService::submitShipment` and `syncDraftItems`, validate that every submitted `quotation_item_id` belongs to the designated `purchase_order_id`.
2. **Duplicate Line Prevention:** In `ShipmentService::submitShipment` and `SupplierShipmentController::store`, prevent duplicate line items for the same `(purchase_order_id, quotation_item_id)` from bypassing the remaining quantity limit within a single payload.
3. **Quotation Status Guard in Awarding:** In `PrItemAwardService` and `PriceComparisonController`, restrict item awarding strictly to quotations in `submitted` or `accepted` status.
4. **QC Store Shipment Validation:** In `QcInspectionController::store`, validate that the posted `shipment_id` actually contains line items belonging to the inspected PO.
5. **Migration Down Safety:** In `2026_09_04_000002_create_shipments_tables.php::down()`, delete any `type = 'SHP'` rows from `document_sequences` before altering the ENUM column back to `ENUM('PR', 'PO')`.
6. **Partial Delivery Progress Attribute:** In `PurchaseOrder::getDeliveryProgressAttribute()`, evaluate whether arrived allocations satisfy the total ordered quantity before returning `'received'`.
7. **Report Classification Adjustments:** Update the implementation report to classify Section 18 as `Serial Race Regression Coverage` and record the `purchase_order_items` schema adjustment as an architecture-compatible deviation.
