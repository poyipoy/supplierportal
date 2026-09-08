# Shipment Qty-Based Fulfillment & Actual Kg Revision
## Detailed Implementation Plan

**Project:** ADASI Portal Supplier  
**Repository:** `https://github.com/poyipoy/supplierportal`  
**Target Branch:** `master` as analysis baseline  
**Baseline SHA Reviewed:** `8c1f1ce3d991439dc64380e69992d42d478971c8`  
**Date:** 2026-09-08  
**Document Type:** Implementation Planning  
**Status:** Ready for Engineering Execution

---

## 1. Executive Summary

The Shipment module currently uses **weight in kilograms (Kg)** as the authoritative fulfillment basis for shipment allocation, partial delivery, remaining balance, arrival, QC fulfillment, replacement, and PO completion.

The requested revision changes the Shipment domain so that:

1. **Qty becomes the authoritative fulfillment unit.**
2. Supplier inputs **Qty** instead of shipment Kg.
3. Supplier also inputs a new field: **Actual Kg**.
4. **Actual Kg is informational / physical audit data only** and must not affect allocation, remaining quantity, shipment completion, arrival completion, QC accepted quantity, NG quantity, replacement eligibility, claim resolution, or PO completion.
5. For award-based POs, authoritative ordered Qty comes from `quotation_items.available_qty`, with fallback to `pr_items.quantity` for legacy compatibility.
6. Qty must be a **positive integer**.
7. Production currently has **no Shipment transactions**, so this revision can safely use a clean schema transition instead of attempting to convert historical Kg values into Qty.

This is therefore a **Shipment fulfillment domain refactor**, not merely a UI label change.

The key architectural principle becomes:

```text
AWARD / PO commercial weight
        !=
SHIPMENT fulfillment quantity
        !=
ACTUAL physical weight
```

The target fulfillment rule is:

```text
SUM(active shipment Qty) <= authoritative ordered Qty
```

instead of:

```text
SUM(active shipped Kg) <= ordered Kg
```

---

# 2. Confirmed Business Decisions

The following decisions are confirmed and must be treated as authoritative requirements.

## 2.1 Ordered Qty Source

For award-based POs:

```text
ordered_qty = quotation_items.available_qty
```

Example:

```text
PR requested Qty       = 10 pcs
Supplier available Qty = 8 pcs
Awarded supplier       = that supplier

Authoritative ordered Qty for Shipment = 8 pcs
```

Legacy fallback:

```text
ordered_qty = pr_items.quantity
```

when `quotation_items.available_qty` is unavailable.

## 2.2 Actual Kg Input Ownership

`Actual Kg` is entered by the **Supplier** during Shipment creation/editing.

It represents the actual physical/logistics weight of the shipment line.

## 2.3 Actual Kg Semantics

`Actual Kg` is:

```text
physical / audit information only
```

It must not affect fulfillment.

Example:

```text
Ordered Qty = 8 pcs
Shipment Qty = 8 pcs
Actual Kg = 100 Kg

Fulfillment = 8 / 8 pcs
```

Likewise:

```text
Ordered Qty = 8 pcs
Shipment Qty = 8 pcs
Actual Kg = 50 Kg

Fulfillment = 8 / 8 pcs
```

The difference between system/theoretical Kg and actual physical Kg must be visible for operational awareness, but must not alter commercial fulfillment.

## 2.4 Qty Precision

Shipment Qty must be:

```text
positive integer only
```

Valid:

```text
1
2
8
100
```

Invalid:

```text
0
-1
1.5
0.5
```

## 2.5 Existing Production Shipment Data

Confirmed:

```text
Production shipment_items transaction rows = none
```

Therefore no historical Kg-to-Qty data conversion is required.

This materially simplifies migration safety.

---

# 3. Current Architecture Analysis

The current implementation is Kg-centric through the Shipment lifecycle.

## 3.1 Current Shipment Schema

Current schema:

```text
shipment_items
├── id
├── shipment_id
├── purchase_order_id
├── quotation_item_id
├── pr_item_award_id
├── shipped_quantity DECIMAL(12,4)
├── notes
├── created_at
└── updated_at
```

`shipped_quantity` is technically named "quantity" but semantically used as **Kg**.

The existing hardening migration also enforces:

```text
CHECK (shipped_quantity > 0)
```

## 3.2 Current Fulfillment Basis

`PurchaseOrder::itemFulfillmentStatus()` currently obtains ordered fulfillment from:

```php
$quotationItem->offered_total_weight
    ?? $quotationItem->prItem?->total_weight
```

It then compares that against:

```text
shipment_items.shipped_quantity
```

This means the following values are currently weight-based:

```text
ordered
physical shipped
physical arrived
accepted
NG
replacement eligible
reserved
allocated
remaining
```

This current behavior must be changed comprehensively.

## 3.3 Current Delivery Progress

`PurchaseOrder::getDeliveryProgressAttribute()` currently compares:

```text
total ordered weight
vs
SUM(shipped_quantity)
```

for active/arrived shipments.

Therefore:

```text
not_shipped
partially_shipped
fully_shipped
received
```

are currently determined by Kg.

## 3.4 Current Supplier Shipment UI

Current Supplier Shipment creation uses:

```text
Ordered (Kg)
Already Shipped (Kg)
Remaining (Kg)
This Shipment (Kg)
```

Input is currently:

```html
step="0.0001"
min="0.0001"
```

The Shipment summary also sums shipment input as Kg.

## 3.5 Current Purchasing Views

Purchasing Shipment index/detail currently calculate:

```php
$shipment->items->sum('shipped_quantity')
```

and render this as:

```text
Total Weight (Kg)
Shipped Weight (Kg)
```

## 3.6 Current QC Coupling

QC views currently expose Shipment delivery context using:

```php
$shipmentItem->shipped_quantity
```

and display it as Kg.

QC also has a separate existing field:

```text
qc_items.actual_weight
```

which means QC measured **weight per unit**.

This is semantically different from the new Shipment-level `actual_weight_kg`.

The implementation must keep those concepts separate.

---

# 4. Target Domain Model

The Shipment line must explicitly separate quantity and actual physical weight.

## 4.1 Target `shipment_items` Structure

Recommended target:

```text
shipment_items
├── id
├── shipment_id
├── purchase_order_id
├── quotation_item_id
├── pr_item_award_id
├── shipped_qty INT UNSIGNED
├── actual_weight_kg DECIMAL(12,4)
├── notes
├── created_at
└── updated_at
```

The old field:

```text
shipped_quantity
```

should be removed.

Reason:

- its old semantic meaning is Kg,
- reusing the same column name for Qty would create ambiguity,
- existing audit/history documentation already associates it with Kg,
- explicit naming reduces future maintenance risk.

---

# 5. Core Domain Invariants

The following invariants must be enforced.

## 5.1 Shipment Allocation Invariant

```text
SUM(active shipment shipped_qty) <= ordered_qty
```

Active allocation statuses:

```text
submitted
arrived
```

Non-consuming statuses:

```text
draft
cancelled
```

## 5.2 Quantity Authority

For award-based PO line:

```text
ordered_qty = quotation_items.available_qty
```

Fallback:

```text
ordered_qty = pr_items.quantity
```

## 5.3 Actual Kg Non-Authority

`actual_weight_kg` must never participate in:

```text
allocation
remaining
shipment completion
arrival completion
QC accepted calculation
NG calculation
replacement eligibility
claim reconciliation
PO completion
```

## 5.4 Completion Invariant

A line is fulfilled only when:

```text
accepted_qty >= ordered_qty
```

A PO is complete only when every authoritative PO line satisfies this rule.

## 5.5 Replacement Invariant

For resolved NG:

```text
resolved NG Qty
→ replacement eligible Qty
→ reservation released
→ remaining Qty increases accordingly
```

Replacement is Qty-based.

---

# 6. Ordered Qty Resolution

A single canonical ordered Qty rule should be introduced.

## 6.1 Recommended `QuotationItem` Accessor

Add an accessor such as:

```php
public function getFulfillmentQuantityAttribute(): int
{
    return max(
        0,
        (int) (
            $this->available_qty
            ?? $this->prItem?->quantity_value
            ?? 0
        )
    );
}
```

Purpose:

- centralize award-based Qty authority,
- avoid duplicate logic,
- preserve legacy fallback,
- keep Supplier, Shipment Service, PO fulfillment, QC and tests consistent.

## 6.2 Legacy Compatibility

Legacy rows may not have:

```text
quotation_items.available_qty
```

Therefore fallback must remain:

```text
pr_items.quantity_value
```

Do not derive ordered Qty from Kg.

---

# 7. Database Migration Plan

A **new forward migration** must be created.

Do not edit previously deployed Shipment migrations.

Recommended file:

```text
database/migrations/
2026_09_08_000001_change_shipment_items_to_qty_and_actual_weight.php
```

## 7.1 Migration Preflight

Before destructive schema changes, verify:

```text
shipment_items exists
shipment_items row count = 0
shipped_quantity exists
shipped_qty does not exist
actual_weight_kg does not exist
```

If `shipment_items` contains data:

```text
ABORT
```

Do not attempt:

```text
old Kg -> Qty
```

because that conversion is semantically unsafe.

## 7.2 Remove Old Constraint

Drop:

```text
shipment_items_shipped_quantity_positive
```

before dropping the old column.

## 7.3 Remove Old Column

Drop:

```text
shipment_items.shipped_quantity
```

## 7.4 Add New Columns

Add:

```text
shipped_qty INT UNSIGNED NOT NULL
actual_weight_kg DECIMAL(12,4) NOT NULL
```

Recommended actual weight precision:

```text
DECIMAL(12,4)
```

This is consistent with existing shipment precision requirements and supports physical weight values with up to four decimal places.

## 7.5 Add Integrity Constraints

Add:

```text
CHECK (shipped_qty > 0)
CHECK (actual_weight_kg > 0)
```

Recommended names:

```text
shipment_items_shipped_qty_positive
shipment_items_actual_weight_kg_positive
```

Existing unique line constraint remains unchanged:

```text
UNIQUE (
    shipment_id,
    purchase_order_id,
    quotation_item_id
)
```

---

# 8. Migration Rollback Strategy

Rollback is safe only while `shipment_items` remains empty.

Rollback should:

1. preflight row count,
2. drop new checks,
3. drop `shipped_qty`,
4. drop `actual_weight_kg`,
5. restore `shipped_quantity DECIMAL(12,4) NOT NULL`,
6. restore `CHECK (shipped_quantity > 0)`.

If rows exist:

```text
ABORT ROLLBACK
```

because:

```text
Qty -> Kg
```

is not a valid reversible conversion.

---

# 9. `ShipmentItem` Model Changes

File:

```text
app/Models/ShipmentItem.php
```

Current fillable:

```php
'shipped_quantity'
```

Target:

```php
'shipped_qty',
'actual_weight_kg',
```

Recommended casts:

```php
protected function casts(): array
{
    return [
        'shipped_qty' => 'integer',
        'actual_weight_kg' => 'decimal:4',
    ];
}
```

Remove the old decimal cast for `shipped_quantity`.

---

# 10. `PurchaseOrder` Fulfillment Refactor

File:

```text
app/Models/PurchaseOrder.php
```

This is the highest-risk domain change.

## 10.1 Preserve Commercial Weight

Existing:

```text
total_ordered_weight
```

must remain available.

Commercial weight is still required for:

```text
quotation
price per Kg
PO amount
comparison
reporting
commercial references
```

Do not remove weight calculation from PO/Quotation domain.

## 10.2 Add Total Ordered Qty

Add an accessor such as:

```php
getTotalOrderedQuantityAttribute()
```

For award-based POs:

```text
sum(quotationItem.fulfillment_quantity)
```

For legacy POs:

```text
sum(PR quantity fallback)
```

## 10.3 Refactor Delivery Progress

Current:

```text
SUM(shipped_quantity Kg)
vs
total ordered weight
```

Target:

```text
SUM(shipped_qty)
vs
total ordered Qty
```

Expected semantics:

```text
0 Qty
→ not_shipped

0 < Qty < ordered
→ partially_shipped

active Qty >= ordered
→ fully_shipped

arrived Qty >= ordered
→ received
```

---

# 11. Refactor `itemFulfillmentStatus()`

The current lifecycle structure should be preserved because it already centralizes:

```text
physical shipped
arrived
accepted
NG
replacement eligible
reserved
remaining
```

Only the basis must change from Kg to Qty.

Recommended contract:

```php
[
    'ordered_qty' => 8,
    'physical_shipped_qty' => 5,
    'physical_arrived_qty' => 5,
    'accepted_qty' => 3,
    'ng_qty' => 2,
    'replacement_eligible_qty' => 2,
    'reserved_qty' => 0,
    'allocated_qty' => 3,
    'remaining_qty' => 5,

    'is_fully_allocated' => false,
    'is_fully_accepted' => false,
]
```

## 11.1 Remove Old Ten-Thousandth Semantics

Current:

```text
ordered_units
physical_shipped_units
accepted_units
remaining_units
```

represent ten-thousandths of Kg.

These should not remain authoritative after migration.

Recommended new naming:

```text
ordered_qty
physical_shipped_qty
physical_arrived_qty
accepted_qty
ng_qty
replacement_eligible_qty
reserved_qty
allocated_qty
remaining_qty
```

## 11.2 Decimal Helper Cleanup

Current helpers:

```php
PurchaseOrder::quantityToUnits()
PurchaseOrder::quantityUnitsToDecimal()
```

exist to provide exact 4-decimal arithmetic.

Qty is integer after this revision.

Therefore:

```text
integer arithmetic only
```

should be used for Shipment fulfillment.

If no other valid callers remain after refactor, remove these helpers.

Do not retain obsolete Kg-specific complexity unless required by another domain.

---

# 12. `ShipmentService` Refactor

File:

```text
app/Services/ShipmentService.php
```

All Shipment item payloads change from:

```php
[
    'shipped_quantity' => ...
]
```

to:

```php
[
    'shipped_qty' => ...,
    'actual_weight_kg' => ...,
]
```

## 12.1 Draft Creation

`createDraft()` must accept:

```text
shipped_qty
actual_weight_kg
```

for each selected line.

## 12.2 Draft Synchronization

`syncDraftItems()` and internal draft sync logic must validate:

```text
shipped_qty > 0
shipped_qty integer
actual_weight_kg > 0
actual_weight_kg max 4 decimals
```

## 12.3 Submission Validation

Current logic:

```text
shipped Kg <= remaining Kg
```

Target:

```text
shipped Qty <= remaining Qty
```

Example error:

```text
Shipped quantity (6 pcs) exceeds remaining ordered quantity
(5 pcs) for item 'SKD11'.
```

## 12.4 Persisted Values

Persist:

```php
ShipmentItem::create([
    'shipment_id' => ...,
    'purchase_order_id' => ...,
    'quotation_item_id' => ...,
    'pr_item_award_id' => ...,
    'shipped_qty' => $shippedQty,
    'actual_weight_kg' => $actualWeightKg,
    'notes' => ...,
]);
```

---

# 13. Supplier Shipment Controller

File:

```text
app/Http/Controllers/Supplier/SupplierShipmentController.php
```

## 13.1 Create/Edit Data

Current:

```text
ordered
allocated
remaining
current_quantity
```

Target:

```text
ordered_qty
allocated_qty
remaining_qty
current_qty
current_actual_weight_kg
```

## 13.2 Request Validation

Recommended:

```php
'items.*.shipped_qty' => [
    'required',
    'integer',
    'min:1',
],

'items.*.actual_weight_kg' => [
    'required',
    'numeric',
    'gt:0',
    'decimal:0,4',
],
```

Do not allow decimal Qty.

## 13.3 Remove-Unselected-Row Logic

Current row selection filtering depends on:

```text
shipped_quantity
```

Update it to:

```text
shipped_qty
```

Actual Kg alone must not cause a row to be treated as selected.

A selected line must have a valid Qty.

---

# 14. Supplier Shipment Create/Edit UI

File:

```text
resources/views/supplier/shipments/create.blade.php
```

## 14.1 Table Layout

Current:

```text
Ordered (Kg)
Already Shipped (Kg)
Remaining (Kg)
This Shipment (Kg)
```

Target:

```text
Ordered Qty
Already Shipped Qty
Remaining Qty
This Shipment Qty
Actual Kg
```

Recommended columns:

```text
Select
PO Number
Material Name
Ordered Qty
Already Shipped
Remaining
This Shipment Qty
Actual Kg
```

## 14.2 Qty Input

Use:

```html
type="number"
step="1"
min="1"
```

Maximum:

```text
remaining_qty
```

Do not use:

```html
step="0.0001"
```

## 14.3 Actual Kg Input

Use:

```html
type="number"
step="0.0001"
min="0.0001"
```

Actual Kg is required for every selected Shipment line.

## 14.4 "All" Button

Current:

```text
fill full remaining Kg
```

Target:

```text
fill full remaining Qty
```

Actual Kg must not be auto-derived from Qty unless explicitly requested in a future requirement.

The Supplier must provide the real Actual Kg.

---

# 15. Shipment Summary UI

Current summary:

```text
Total Shipped: xxx Kg
```

Target example:

```text
3 item(s) allocated
Total Qty: 14 pcs
Actual Weight: 152.7500 Kg
```

Calculation:

```text
Total Qty
= SUM(selected shipped_qty)

Actual Weight
= SUM(selected actual_weight_kg)
```

---

# 16. Supplier Shipment Index

File:

```text
resources/views/supplier/shipments/index.blade.php
```

Current:

```text
Total Weight (Kg)
```

Target:

```text
Total Qty
Actual Kg
```

Recommended calculations:

```php
$totalQty = $shipment->items->sum('shipped_qty');
$totalActualKg = $shipment->items->sum('actual_weight_kg');
```

---

# 17. Supplier Shipment Detail

File:

```text
resources/views/supplier/shipments/show.blade.php
```

Current:

```text
Shipped Quantity (Kg)
```

Target:

```text
Shipped Qty
Actual Kg
```

Example:

```text
SKD11
5 pcs
54.2000 Kg
```

Do not use wording such as:

```text
Quantity (Kg)
```

because Qty and weight are different physical dimensions.

---

# 18. Purchasing Shipment Index

File:

```text
resources/views/purchasing/shipments/index.blade.php
```

Target columns:

```text
Shipment Number
Supplier
Consolidated POs
Items
Total Qty
Actual Kg
Shipment Date
Est. Arrival
Actual Arrival
Status
Action
```

Current `Total Weight (Kg)` must no longer be derived from shipment Qty.

---

# 19. Purchasing Shipment Detail

File:

```text
resources/views/purchasing/shipments/show.blade.php
```

Target line table:

```text
PO Number
PR Reference
Material Name
Shape & Specs
Shipped Qty
Actual Kg
Item Notes
```

Supplier-entered Actual Kg remains read-only to Purchasing.

---

# 20. QC Create Screen

File:

```text
resources/views/qc/inspections/create.blade.php
```

Current shipment mapping:

```php
$item->shipped_delivery_qty = $si->shipped_quantity;
```

Target:

```php
$item->shipped_delivery_qty = $si->shipped_qty;
$item->shipment_actual_weight_kg = $si->actual_weight_kg;
```

## 20.1 QC Shipment Context

Current:

```text
Consignment Shipped
5.00 Kg
```

Target:

```text
Consignment Qty
5 pcs

Shipment Actual Weight
54.2000 Kg
```

These values are context only.

QC does not alter fulfillment Qty.

---

# 21. QC Show Screen

File:

```text
resources/views/qc/inspections/show.blade.php
```

Current:

```text
Consignment: 5.00 Kg
```

Target:

```text
Consignment: 5 pcs
Actual Shipment Weight: 54.2000 Kg
```

Existing QC-specific:

```text
Weight/Unit (Kg)
```

remains unchanged.

---

# 22. Separation Between Shipment Actual Kg and QC Actual Weight

This is a critical semantic boundary.

## Shipment

```text
shipment_items.actual_weight_kg
```

Meaning:

```text
actual physical total weight of that shipment line
```

Entered by:

```text
Supplier
```

## QC

```text
qc_items.actual_weight
```

Meaning:

```text
actual measured weight per unit during QC
```

Entered by:

```text
QC
```

These fields must not:

```text
overwrite each other
auto-sync each other
share fulfillment semantics
```

---

# 23. Arrival Semantics

`ShipmentService::confirmArrival()` should not calculate or update Actual Kg.

Actual Kg must already exist from Supplier submission.

Arrival remains:

```text
submitted
→ arrived
```

Purchasing only records:

```text
actual_arrival_date
```

and triggers shipment-aware QC.

---

# 24. Cancelled Shipment Semantics

Keep current lifecycle behavior:

```text
draft
→ does not consume Qty

submitted
→ consumes Qty

arrived
→ consumes Qty

cancelled
→ releases Qty
```

Example:

```text
Ordered Qty = 8

Shipment A submitted = 3
Remaining = 5

Shipment A cancelled
Remaining = 8
```

`actual_weight_kg` remains historical information on the cancelled shipment line but has no allocation effect.

---

# 25. QC / NG / Claim / Replacement Semantics

All fulfillment arithmetic must become Qty-based.

Example:

```text
Ordered Qty = 8

Shipment A = 3
QC = NG
```

Before claim resolution:

```text
reserved Qty = 3
remaining Qty = 5
```

After resolved replacement claim:

```text
replacement eligible Qty = 3
reservation released
remaining Qty = 8
```

Replacement delivery:

```text
Shipment B = 5 OK
Shipment C = 3 OK
```

Final:

```text
accepted Qty = 8
remaining Qty = 0
PO = completed
```

Actual Kg must not change this result.

---

# 26. PO Completion

Current completion logic ultimately depends on `itemFulfillmentStatus()`.

Target completion condition:

```text
accepted_qty >= ordered_qty
```

for every authoritative PO line.

Do not use:

```text
accepted Kg
ordered Kg
actual Kg
```

for fulfillment completion.

---

# 27. Concurrency and Locking

Existing locking architecture must be preserved.

Current high-level ordering:

```text
Shipment
→ Purchase Order(s)
→ Quotation Item(s)
→ fulfillment validation
→ dependent writes
```

Only comparison semantics change.

Old:

```text
SUM(active shipped Kg) <= ordered Kg
```

New:

```text
SUM(active shipped Qty) <= ordered Qty
```

Example race condition that must remain prevented:

```text
Ordered Qty = 8

Shipment A attempts 5
Shipment B attempts 5
```

Final state must never become:

```text
10 / 8
```

One transaction must fail after serialized validation.

---

# 28. Data Integrity Constraints

Database and application should enforce:

```text
shipped_qty > 0
actual_weight_kg > 0
```

Database uniqueness remains:

```text
UNIQUE (
    shipment_id,
    purchase_order_id,
    quotation_item_id
)
```

Qty must be integer at both:

```text
HTTP boundary
service layer
database type
```

---

# 29. Existing Behavior That Must Be Preserved

The refactor must preserve all previously remediated behavior.

Do not regress:

```text
supplier isolation
same-supplier Shipment enforcement
cross-PO commercial consistency
item-level award lineage
duplicate logical Shipment line prevention
draft Shipment edit restrictions
cancel allocation release
shipment document lifecycle
private document storage
Hashid route handling
shipment-aware arrival
shipment-aware QC
cross-shipment QC isolation
claim state precedence
replacement flow
PO status reconciliation
locking order
legacy PO arrival compatibility
```

---

# 30. Legacy Compatibility

Legacy PO behavior must remain valid.

If a legacy PO has no authoritative `available_qty`, use:

```text
pr_items.quantity_value
```

Do not derive Qty from:

```text
total_weight
weight_needed
offered_total_weight
```

Legacy arrival/QC fallback logic should remain unchanged unless a direct failing test demonstrates a required adjustment.

---

# 31. Expected Files to Change

## Database

```text
database/migrations/
└── 2026_09_08_000001_change_shipment_items_to_qty_and_actual_weight.php
```

## Models

```text
app/Models/
├── PurchaseOrder.php
├── QuotationItem.php
└── ShipmentItem.php
```

## Services

```text
app/Services/
└── ShipmentService.php
```

## Controllers

```text
app/Http/Controllers/Supplier/
└── SupplierShipmentController.php
```

## Supplier Views

```text
resources/views/supplier/shipments/
├── create.blade.php
├── index.blade.php
└── show.blade.php
```

## Purchasing Views

```text
resources/views/purchasing/shipments/
├── index.blade.php
└── show.blade.php
```

## QC Views

```text
resources/views/qc/inspections/
├── create.blade.php
└── show.blade.php
```

## Tests

Expected primary impact:

```text
tests/Feature/
├── ShipmentAndPartialDeliveryTest.php
├── ShipmentDocumentsAndQcIntegrationTest.php
├── ShipmentMigrationRollbackTest.php
├── NotificationDeliveryTest.php
└── QuotationAvailabilityTest.php
```

Additional tests may require changes if they reference:

```text
shipped_quantity
ordered_units
accepted_units
remaining_units
```

---

# 32. Explicitly Out of Scope

Do not modify unless evidence proves necessary:

```text
PR creation
Quotation amount architecture
price-per-Kg commercial calculation
award selection rules
PO generation architecture
document numbering
Shipment document types
private attachment storage
Hashid mechanism
supplier authorization model
Purchasing authorization model
claim ownership model
QC dimensional measurement rules
```

---

# 33. Test Refactor Strategy

Existing Shipment tests are currently Kg-based.

They must be migrated to Qty semantics.

Old pattern:

```php
'shipped_quantity' => 5.0
```

Target:

```php
'shipped_qty' => 5,
'actual_weight_kg' => 51.2500,
```

Old assertions such as:

```text
accepted_units = 200000
```

must become explicit Qty assertions.

Recommended:

```text
accepted_qty = 20
remaining_qty = 0
```

---

# 34. Mandatory Regression Test Matrix

## 34.1 Ordered Qty

### Test A

```text
PR Qty = 10
available_qty = 8
award supplier
```

Expected:

```text
ordered_qty = 8
```

## 34.2 Positive Integer Qty

Valid:

```text
1
5
8
```

Invalid:

```text
0
-1
1.5
```

Expected:

```text
validation rejection
```

## 34.3 Partial Shipment

```text
Ordered = 8
Shipment A = 3
Shipment B = 5
```

Expected:

```text
after A: remaining = 5
after B: remaining = 0
```

## 34.4 Over-Allocation

```text
Ordered = 8
Already allocated = 5
New shipment = 4
```

Expected:

```text
rejected
```

## 34.5 Draft Does Not Allocate

```text
Draft shipment Qty = 5
```

Expected:

```text
remaining unchanged
```

## 34.6 Submitted Allocates

```text
Submitted shipment Qty = 5
```

Expected:

```text
remaining reduced by 5
```

## 34.7 Cancel Releases Qty

```text
Submitted = 5
Cancel shipment
```

Expected:

```text
remaining restored
```

## 34.8 Actual Kg Does Not Affect Remaining

Case 1:

```text
Qty = 5
Actual Kg = 1
```

Case 2:

```text
Qty = 5
Actual Kg = 10000
```

Expected:

```text
same fulfillment result
```

## 34.9 Actual Kg Does Not Affect Completion

```text
Ordered Qty = 8
Shipped/accepted Qty = 8
Actual Kg differs from theoretical Kg
```

Expected:

```text
PO completion determined by Qty
```

## 34.10 NG + Replacement

```text
Ordered = 8
3 Qty NG
claim resolved
5 Qty OK
3 replacement Qty OK
```

Expected:

```text
accepted = 8
remaining = 0
completed
```

## 34.11 Mixed OK / NG Lines

One Shipment contains multiple lines.

Expected:

```text
fulfillment counted per ShipmentItem Qty
no cross-line contamination
```

## 34.12 Supplier Isolation

Supplier A attempts to submit Shipment for Supplier B PO.

Expected:

```text
403 / service rejection
```

## 34.13 Duplicate Logical Shipment Line

Same:

```text
shipment_id
purchase_order_id
quotation_item_id
```

submitted twice.

Expected:

```text
rejected
```

## 34.14 Submitted Shipment Edit

Attempt editing submitted shipment.

Expected:

```text
rejected
```

## 34.15 Concurrency / Serial Race Regression

```text
Ordered = 8
Shipment A = 5
Shipment B = 5
```

Expected:

```text
total active Qty <= 8
```

## 34.16 Shipment-Aware QC

QC for Shipment A must only operate on Shipment A lines.

Expected:

```text
no Shipment B line accepted/NG by mistake
```

---

# 35. Migration Tests

Migration-specific tests must verify:

## Forward

```text
old shipped_quantity removed
shipped_qty exists
actual_weight_kg exists
shipped_qty integer
actual_weight_kg decimal
positive checks exist
existing unique constraint remains
```

## Preflight

When `shipment_items` contains rows:

```text
migration aborts
```

## Rollback

When table is empty:

```text
new columns removed
old shipped_quantity restored
old positive check restored
```

When rows exist:

```text
rollback aborts
```

---

# 36. UI Acceptance Criteria

Supplier Create/Edit Shipment must display:

```text
Ordered Qty
Already Shipped Qty
Remaining Qty
This Shipment Qty
Actual Kg
```

Qty input:

```text
integer only
```

Actual Kg:

```text
positive decimal
```

Supplier detail:

```text
Shipped Qty
Actual Kg
```

Purchasing detail:

```text
Shipped Qty
Actual Kg
```

QC context:

```text
Consignment Qty
Shipment Actual Weight
```

---

# 37. Example End-to-End Scenario

```text
PR
Qty = 10 pcs
Weight/Unit = 10.2 Kg
```

Supplier quotation:

```text
Available Qty = 8 pcs
Offered Weight/Unit = 10.4 Kg
```

Award:

```text
Supplier wins the line
```

Authoritative Shipment requirement:

```text
Ordered Qty = 8 pcs
```

Commercial/system weight:

```text
8 × 10.4 = 83.2 Kg
```

Shipment 1:

```text
Qty = 3
Actual Kg = 32.0
```

Result:

```text
remaining = 5
```

Shipment 2:

```text
Qty = 5
Actual Kg = 52.4
```

Result:

```text
remaining = 0
```

Actual total:

```text
32.0 + 52.4 = 84.4 Kg
```

Difference:

```text
System/offer Kg = 83.2
Actual Kg = 84.4
```

Fulfillment:

```text
8 / 8 pcs
```

The difference in Kg does not alter completion.

---

# 38. Production Deployment Strategy

Production uses raw SQL / phpMyAdmin.

Therefore implementation should produce both:

```text
Laravel migration
+
production SQL reconciliation artifact
```

Recommended production SQL name:

```text
ADASI-PRODUCTION-SHIPMENT-QTY-ACTUAL-KG-20260908.sql
```

## 38.1 Production SQL Preflight

Before DDL:

```text
correct production database selected
shipment_items exists
shipment_items row count = 0
shipped_quantity exists
shipped_qty absent
actual_weight_kg absent
old positive constraint exists
```

If any assumption differs:

```text
ABORT
```

## 38.2 Production SQL Upgrade

Apply:

```text
drop old CHECK
drop shipped_quantity
add shipped_qty
add actual_weight_kg
add Qty positive CHECK
add Actual Kg positive CHECK
record migration ledger row
```

## 38.3 Production SQL Post-Verification

Verify:

```text
shipment_items.shipped_qty exists
shipped_qty integer
actual_weight_kg exists
actual_weight_kg DECIMAL(12,4)
old shipped_quantity absent
Qty CHECK exists
Actual Kg CHECK exists
unique shipment line constraint still exists
migration ledger row exists
```

---

# 39. Deployment Order

Because the application and database schema change together, deploy in one maintenance window.

Recommended sequence:

```text
1. Full production DB backup

2. Put application into maintenance / stop writes

3. Verify shipment_items row count = 0

4. Apply production SQL schema upgrade

5. Deploy application code

6. Clear Laravel config/cache

7. Run smoke tests

8. Re-enable application
```

Do not run the new database schema while old application code is still serving Shipment requests.

Old code expects:

```text
shipped_quantity
```

and would fail after the column is removed.

---

# 40. Smoke Test After Deployment

Minimum production smoke test:

```text
Supplier login
→ Shipment create page opens

→ active awarded PO appears

→ Ordered Qty matches available_qty

→ Supplier selects item

→ Qty accepts integer

→ Actual Kg accepts decimal

→ Save Draft

→ Edit Draft

→ Submit Shipment

→ Purchasing sees Qty + Actual Kg

→ Purchasing confirms arrival

→ QC sees shipment-specific Qty + Actual Kg context

→ QC completes inspection

→ PO status reconciles correctly
```

Do not create irreversible or business-impacting production test transactions unless approved.

A controlled test PO should be used where possible.

---

# 41. Engineering Verification Commands

Recommended targeted tests:

```bash
php artisan test --filter=ShipmentAndPartialDeliveryTest
php artisan test --filter=ShipmentDocumentsAndQcIntegrationTest
php artisan test --filter=ShipmentMigrationRollbackTest
php artisan test --filter=NotificationDeliveryTest
php artisan test --filter=QuotationAvailabilityTest
```

Then:

```bash
php artisan test
```

Do not claim completion until the full suite passes.

---

# 42. Risk Analysis

## Risk 1 — Partial Refactor

If UI becomes Qty but `itemFulfillmentStatus()` remains Kg-based:

```text
Critical domain defect
```

Mitigation:

```text
refactor canonical fulfillment first
```

## Risk 2 — Actual Kg Accidentally Used as Fulfillment

This would create inconsistent completion.

Mitigation:

```text
explicit regression tests proving different Actual Kg values do not alter remaining/completion
```

## Risk 3 — Legacy Rows Without `available_qty`

Mitigation:

```text
canonical fallback to pr_items.quantity_value
```

## Risk 4 — Old Field References Left Behind

Potential runtime error after dropping:

```text
shipped_quantity
```

Mitigation:

```text
global code search for:
shipped_quantity
ordered_units
accepted_units
remaining_units
physical_shipped_units
```

Every runtime reference must be classified and updated.

Historical documentation references do not need runtime changes unless intentionally maintained.

## Risk 5 — Existing Shipment Data Appears Before Deployment

If `shipment_items` is no longer empty:

```text
do not execute destructive migration
```

Reassess migration strategy first.

## Risk 6 — Concurrency Regression

Changing arithmetic must not weaken locking.

Mitigation:

```text
preserve existing lock order
preserve service-level over-allocation check
retain DB constraints
run serial race regression tests
```

---

# 43. Recommended Execution Order

Engineering should implement in this order.

## Phase 1 — Domain Contract

1. add canonical `fulfillment_quantity` to `QuotationItem`
2. refactor `PurchaseOrder::itemFulfillmentStatus()`
3. refactor delivery progress
4. refactor completion logic

## Phase 2 — Schema

5. create forward migration
6. add migration tests
7. update `ShipmentItem`

## Phase 3 — Shipment Service

8. change payload names
9. change validation
10. change allocation logic
11. persist Qty + Actual Kg

## Phase 4 — HTTP Boundary

12. update Supplier Shipment Controller
13. update unselected row filtering

## Phase 5 — Supplier UI

14. create/edit page
15. index
16. show
17. JS summary

## Phase 6 — Purchasing UI

18. index
19. show

## Phase 7 — QC Context

20. QC create
21. QC show

## Phase 8 — Tests

22. update existing Shipment tests
23. add Qty authority tests
24. add Actual Kg non-authority tests
25. add migration tests
26. run targeted suite
27. run full suite

## Phase 9 — Production Artifact

28. create raw SQL deployment file
29. test SQL on production clone
30. verify migration ledger compatibility

---

# 44. Definition of Done

The revision is complete only when all conditions below are satisfied.

```text
Shipment allocation is Qty-based
Supplier inputs integer Qty
Supplier inputs Actual Kg
Awarded available_qty is authoritative ordered Qty
Legacy fallback uses PR quantity
Partial delivery uses Qty
Remaining uses Qty
Arrival progress uses Qty
QC accepted/NG uses Qty
Replacement uses Qty
PO completion uses Qty
Actual Kg does not affect fulfillment
Actual Kg is visible to Supplier/Purchasing/QC
Supplier isolation remains intact
Commercial chain validation remains intact
Duplicate shipment line prevention remains intact
Claim behavior remains intact
Concurrency protection remains intact
Migration passes on empty shipment_items
Migration fails closed on non-empty shipment_items
Targeted tests pass
Full regression suite passes
Production SQL is rehearsed on a clone
```

---

# 45. Final Architectural Rule

The final system should maintain this separation:

```text
PR / QUOTATION / PO
Commercial quantity + theoretical / offered weight
        │
        ▼
AWARDED QTY
quotation_items.available_qty
        │
        ▼
SHIPMENT
shipped_qty
        │
        ├── authoritative for fulfillment
        │
        └── actual_weight_kg
              physical/audit information only
        │
        ▼
ARRIVAL
Qty-based progress
        │
        ▼
QC
Qty-based accepted / NG
        │
        ▼
CLAIM / REPLACEMENT
Qty-based replacement eligibility
        │
        ▼
PO COMPLETION
accepted_qty >= ordered_qty
```

The following must never become equivalent:

```text
Qty
System/Theoretical Kg
Actual Shipment Kg
QC Weight/Unit
```

Each value serves a different business purpose and must remain semantically isolated.

---

# 46. Final Implementation Principle

The Shipment module must transition from:

```text
weight-driven fulfillment
```

to:

```text
quantity-driven fulfillment
+
actual physical weight tracking
```

without altering commercial price-per-Kg logic.

The highest-risk code path is:

```text
PurchaseOrder::itemFulfillmentStatus()
```

because it is the central projection used by:

```text
Shipment allocation
arrival
QC
NG
claim
replacement
PO completion
```

Therefore the implementation should treat the fulfillment refactor as the core change and all controller/view changes as consumers of that new domain contract.

---

**End of Plan**
