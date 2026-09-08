# Detailed Engineering Remediation Plan: Shipment Qty + Actual Kg Defect Resolution

**Prepared Date:** 2026-09-08  
**Repository:** ADASI Portal Supplier (`poyipoy/supplierportal`)  
**Target Specification:** `SHIPMENT-QTY-ACTUAL-KG-IMPLEMENTATION-PLAN-20260908.md`  
**Status:** READY FOR EXECUTION  

---

## 1. Executive Summary & Objective

During the independent FIND BUGS engineering review of the Shipment Qty + Actual Kg revision, the implementation was confirmed to be functionally strong in its core domain (integer quantity enforcement, authoritative `available_qty` resolution, decoupling of physical actual weight from fulfillment, and partial delivery accounting).

However, two operational defects and one code-hygiene issue were uncovered that block production readiness:
1. **F-01 (HIGH - RUNTIME CRASH):** `PurchaseOrder::shipments()` is commented out by an unclosed docblock delimiter `/**` at line 257 in `app/Models/PurchaseOrder.php`. This triggers a fatal `BadMethodCallException` whenever a Quality Control (QC) officer accesses the waiting inspections list (`qc.inspections.data-waiting`) or initiates an inspection without an explicit `?shipment_id=` query parameter.
2. **F-02 (MEDIUM - MIGRATION DRIFT):** A column positioning mismatch exists between the production DDL script `ADASI-PRODUCTION-SHIPMENT-QTY-ACTUAL-KG-20260908.sql` (`AFTER quotation_item_id`) and the canonical Laravel migration `2026_09_08_000001_change_shipment_items_to_qty_and_actual_weight.php` (`AFTER pr_item_award_id`).
3. **F-03 (LOW - DEAD CODE):** Obsolete ten-thousandth arithmetic scaling helpers (`quantityToUnits()` and `quantityUnitsToDecimal()`) remain in `PurchaseOrder.php` despite discrete integer quantities being natively used across all active paths.
4. **TEST GAP:** Automated tests in `ShipmentDocumentsAndQcIntegrationTest.php` always supplied `?shipment_id=` when hitting the create screen and never exercised the DataTables waiting endpoint, creating a false-negative blind spot that allowed F-01 to escape.

This remediation plan outlines the exact, surgical code modifications, security validations, database alignment, and automated regression tests required to transition the project to a **VERIFIED PASS** state.

---

## 2. Systematic Root Cause & Vulnerability Scanner Analysis

### 2.1 Root Cause Analysis of F-01 (Runtime Crash)
- **Causal Chain:** During refactoring of `PurchaseOrder.php`, a docblock was added above `public function shipments(): Collection`. Line 257 contained `* Unique shipments associated with this PO.` without the terminating `*/`.
- **PHP Lexer Consequence:** The PHP parser consumed lines 256 through 265 as a single docblock comment. The declaration `public function shipments(): Collection` was omitted from the compiled class symbol table.
- **Trigger Points:**
  - `QcInspectionController::dataWaiting()` (line 67): Resolves `$po->shipments()` to determine if an arrived shipment awaits inspection and generates the direct "Start Inspection" URL.
  - `QcInspectionController::create()` (line 130): Resolves `$po->shipments()` when `$request->query('shipment_id')` is null, auto-selecting the single arrived shipment or redirecting if multiple exist.

### 2.2 Security & Vulnerability Analysis
- **Authorization & Supplier Isolation:**
  - `PurchaseOrder::shipments()` maps `$this->shipmentItems->map(fn ($item) => $item->shipment)`.
  - In `QcInspectionController`, inspections are restricted to `role:qc,purchasing`.
  - In `SupplierShipmentController`, all queries enforce `where('supplier_id', auth()->id())` and reject foreign PO items via database and service cross-validation.
  - Restoring `shipments()` does not create any IDOR or cross-supplier data leakage because shipments are already scoped to the PO and supplier.
- **Database Concurrency & DDL Safety (F-02):**
  - MySQL/MariaDB DDL statements (`ALTER TABLE`) trigger **implicit commits**. If an `ALTER TABLE` statement fails or executes out-of-order, it cannot be rolled back via an outer SQL transaction.
  - Parity between raw SQL scripts and Laravel migrations is critical to prevent schema drift, index mismatch, or unexpected column offset bugs.
  - Aligning `shipped_qty` placement to `AFTER pr_item_award_id` in `ADASI-PRODUCTION-SHIPMENT-QTY-ACTUAL-KG-20260908.sql` guarantees identical physical table structure across environments.

---

## 3. Surgical Code Modifications

### 3.1 Domain Model: `app/Models/PurchaseOrder.php`

#### Change 1: Restore `PurchaseOrder::shipments()` (Fix F-01)
**File:** `app/Models/PurchaseOrder.php`  
**Lines:** 256–262

```diff
     /**
      * Unique shipments associated with this PO.
+     */
     public function shipments(): Collection
     {
         return $this->shipmentItems->map(fn ($item) => $item->shipment)->filter()->unique('id')->values();
     }
```

#### Change 2: Formally Deprecate Obsolete Scaling Helpers (Fix F-03)
**File:** `app/Models/PurchaseOrder.php`  
**Lines:** 319–337

```diff
     /**
-     * Obsolete helper retained for backward compatibility / reference.
-     * With integer-based quantities, 1 piece is 1 unit.
+     * @deprecated Obsolete ten-thousandth scaling helper retained for backward compatibility.
+     * Discrete integer quantities are native; 1 piece equals 1 unit. Do not use in new code.
      */
     public static function quantityToUnits(int|float $quantity): int
     {
         return (int) round($quantity);
     }

     /**
-     * Obsolete helper retained for backward compatibility / reference.
-     * With integer-based quantities, 1 piece is 1 unit.
+     * @deprecated Obsolete ten-thousandth scaling helper retained for backward compatibility.
+     * Discrete integer quantities are native; 1 piece equals 1 unit. Do not use in new code.
      */
     public static function quantityUnitsToDecimal(int $units): int
     {
         return $units;
     }
```

---

### 3.2 Controller Optimization: `app/Http/Controllers/Qc/QcInspectionController.php`

To prevent N+1 queries when mapping `$po->shipments()` across arrived POs in the DataTables endpoint, add eager-loading of `shipmentItems.shipment`.

#### Change 1: Eager Load in `dataWaiting()`
**File:** `app/Http/Controllers/Qc/QcInspectionController.php`  
**Lines:** 51–56

```diff
         $query = $this->waitingPurchaseOrdersQuery()->with([
             'supplier',
             'quotations' => fn ($query) => $query->withCount('items'),
+            'shipmentItems.shipment',
         ])
             ->orderBy('actual_arrival', 'asc');
```

#### Change 2: Eager Load in `create()`
**File:** `app/Http/Controllers/Qc/QcInspectionController.php`  
**Line:** 108

```diff
-        $po = PurchaseOrder::with(['supplier', 'quotations.items.prItem'])->findOrFail($po_id);
+        $po = PurchaseOrder::with(['supplier', 'quotations.items.prItem', 'shipmentItems.shipment'])->findOrFail($po_id);
```

---

### 3.3 Production SQL Alignment: `ADASI-PRODUCTION-SHIPMENT-QTY-ACTUAL-KG-20260908.sql`

#### Change: Align Column Positioning (Fix F-02)
**File:** `ADASI-PRODUCTION-SHIPMENT-QTY-ACTUAL-KG-20260908.sql`  
**Lines:** 57–60

```diff
 -- Step 3: Add New Columns (shipped_qty, actual_weight_kg)
 -- ----------------------------------------------------------------------------
 ALTER TABLE `shipment_items`
-    ADD COLUMN `shipped_qty` INT UNSIGNED NOT NULL AFTER `quotation_item_id`,
+    ADD COLUMN `shipped_qty` INT UNSIGNED NOT NULL AFTER `pr_item_award_id`,
     ADD COLUMN `actual_weight_kg` DECIMAL(12,4) NOT NULL AFTER `shipped_qty`;
```

---

## 4. Automated Regression Feature Tests

**Target File:** `tests/Feature/ShipmentDocumentsAndQcIntegrationTest.php`  
**Location:** Append before closing class brace (line 1390).

```php
    public function test_qc_waiting_inspections_data_endpoint_resolves_shipments_and_renders_action_url(): void
    {
        [, $prItem, , $qItem, $po] = $this->createAwardedPo(20.0);
        $shipment = $this->createShipment($po, $qItem, 20.0, true);

        $response = $this->actingAs($this->qcUser)
            ->getJson(route('qc.inspections.data-waiting'));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $matchingRow = collect($data)->first(fn ($row) => str_contains($row['po_number_display'] ?? '', $po->po_number));
        $this->assertNotNull($matchingRow, 'Expected arrived PO was not found in QC waiting data.');
        $this->assertStringContainsString('Start Inspection', $matchingRow['action']);
        $this->assertStringContainsString($shipment->hash, $matchingRow['action']);
    }

    public function test_qc_inspection_create_without_shipment_id_auto_resolves_single_arrived_shipment(): void
    {
        [, $prItem, , $qItem, $po] = $this->createAwardedPo(20.0);
        $shipment = $this->createShipment($po, $qItem, 20.0, true);

        $response = $this->actingAs($this->qcUser)
            ->get(route('qc.inspections.create', ['id' => $po->hash]));

        $response->assertOk();
        $response->assertViewIs('qc.inspections.create');
        $response->assertViewHas('shipment', fn ($resolved) => $resolved && (int) $resolved->id === (int) $shipment->id);
    }

    public function test_qc_inspection_create_without_shipment_id_redirects_when_multiple_arrived_shipments_await_inspection(): void
    {
        [$po, $prItems, $qItems] = $this->createTwoItemAwardedPo();
        $shipmentService = app(ShipmentService::class);

        // First arrived shipment
        $shipment1 = $shipmentService->createDraft($this->supplierUserA);
        $shipment1 = $shipmentService->submitShipment($shipment1, [
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItems[0]->id,
                'shipped_qty' => 5,
                'actual_weight_kg' => 5.0,
            ]],
        ]);
        $shipmentService->confirmArrival($shipment1, $this->purchasingUser);

        // Second arrived shipment
        $shipment2 = $shipmentService->createDraft($this->supplierUserA);
        $shipment2 = $shipmentService->submitShipment($shipment2, [
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItems[1]->id,
                'shipped_qty' => 6,
                'actual_weight_kg' => 6.0,
            ]],
        ]);
        $shipmentService->confirmArrival($shipment2, $this->purchasingUser);

        $response = $this->actingAs($this->qcUser)
            ->get(route('qc.inspections.create', ['id' => $po->hash]));

        $response->assertRedirect(route('qc.inspections.index'));
        $response->assertSessionHas('error', 'This PO has multiple arrived shipments. Please start inspection from the shipment list.');
    }
```

---

## 5. Production Deployment & Database Safety Protocol

When deploying to production:
1. **Preflight Verification (Mandatory):**
   ```sql
   SELECT COUNT(*) AS shipment_items_row_count FROM shipment_items;
   ```
   Must return exactly `0`.
2. **Execution Options:**
   - **Option A (Artisan Migration - Recommended):**
     ```bash
     php artisan migrate --force
     ```
   - **Option B (Raw SQL Script):**
     Execute `ADASI-PRODUCTION-SHIPMENT-QTY-ACTUAL-KG-20260908.sql` directly in MySQL client.
3. **Post-Migration Verification:**
   ```sql
   DESCRIBE shipment_items;
   ```
   Confirm columns:
   - `shipped_qty`: `int(10) unsigned`, `NOT NULL`, positioned after `pr_item_award_id`.
   - `actual_weight_kg`: `decimal(12,4)`, `NOT NULL`, positioned after `shipped_qty`.
   Confirm check constraints:
   - `shipment_items_shipped_qty_positive`: `(shipped_qty > 0)`.
   - `shipment_items_actual_weight_kg_positive`: `(actual_weight_kg > 0.0000)`.

---

## 6. Execution & Verification Checklist

| Step | Action | Verification Command / Indicator | Success Criteria |
|---|---|---|---|
| **1** | Update `app/Models/PurchaseOrder.php` | `php -l app/Models/PurchaseOrder.php` | `No syntax errors detected` |
| **2** | Update `app/Http/Controllers/Qc/QcInspectionController.php` | `php -l app/Http/Controllers/Qc/QcInspectionController.php` | `No syntax errors detected` |
| **3** | Update `ADASI-PRODUCTION-SHIPMENT-QTY-ACTUAL-KG-20260908.sql` | `git diff ADASI-PRODUCTION-SHIPMENT-QTY-ACTUAL-KG-20260908.sql` | `AFTER pr_item_award_id` verified |
| **4** | Add 3 new regression tests in `ShipmentDocumentsAndQcIntegrationTest.php` | `php artisan test tests/Feature/ShipmentDocumentsAndQcIntegrationTest.php` | 31 passed (28 existing + 3 new), 0 failures |
| **5** | Run core delivery & fulfillment test suite | `php artisan test tests/Feature/ShipmentAndPartialDeliveryTest.php` | 41 passed, 0 failures |
| **6** | Run migration rollback test suite | `php artisan test tests/Feature/ShipmentMigrationRollbackTest.php` | 3 passed, 0 failures |
| **7** | Run UI and Export test suite | `php artisan test tests/Feature/ShipmentUiAndExportTest.php` | 9 passed, 0 failures |
| **8** | Run full test suite | `php artisan test` | 100% green across entire suite |
