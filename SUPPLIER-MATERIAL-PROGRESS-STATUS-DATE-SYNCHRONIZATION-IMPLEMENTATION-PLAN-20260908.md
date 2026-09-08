# Supplier Material Progress, Status & Delivery-Date Synchronization
## Complete Implementation Plan

**Project:** ADASI Portal Supplier  
**Repository:** `https://github.com/poyipoy/supplierportal`  
**Target branch:** `master`  
**Repository baseline reviewed:** `8c1f1ce3d991439dc64380e69992d42d478971c8`  
**Plan date:** 2026-09-08  
**Recommended repository destination:** `docs/plans/`  
**Document status:** Approved-business-rules implementation planning; no repository mutation performed by this document.

---

## 1. Executive Summary

This plan introduces a supplier-maintained **per-PO-item material progress workflow** so Purchasing can see what is happening to each awarded material after the Purchase Order is issued and before the material is fully dispatched.

The new material progress domain is intentionally separated from:

1. the Purchase Order operational status;
2. the Shipment lifecycle status;
3. Receiving / arrival state;
4. QC and claim state; and
5. authoritative quantity fulfillment.

The design must support partial delivery. A single awarded PO item can simultaneously have:

- part of the quantity already accepted;
- part in transit;
- part arrived and waiting for QC; and
- the remaining quantity still under Supplier control, for example `On Production`.

Example:

```text
PO Item: SKD11
Ordered Qty       : 8 pcs

Accepted          : 3 pcs
In Transit        : 2 pcs
Supplier-Controlled Remaining:
                   3 pcs — On Production
```

Therefore, no single `purchase_orders.status` or `shipment.status` value can accurately represent the material's complete operational progress.

The implementation must instead create a canonical progress projection that combines:

```text
PO operational state
+ Supplier material progress
+ Shipment lifecycle
+ QC / claim state
+ authoritative fulfillment Qty
```

without merging those domains into one database status.

This plan also formalizes the meaning and relationship of all delivery-related dates:

```text
Quotation
Supplier Estimated Ready / Dispatch Date
(original supplier commitment)
        ↓

PO
PO Target Arrival Date
(Purchasing target at ADSI)
        ↓

Supplier Material Progress
Current Estimated Ready Date
(latest supplier forecast)
        ↓

Shipment
Dispatch / Shipment Date
(actual dispatch when submitted)

Shipment ETA
(latest ETA for that physical consignment)
        ↓

Receiving
Actual Arrival Date
(actual physical arrival at ADSI)
```

The original quotation commitment must remain historical evidence and must not be overwritten by later supplier forecasts.

---

## 2. Relationship to the Shipment Qty + Actual Kg Revision

This plan is designed to coexist with the approved:

`SHIPMENT-QTY-ACTUAL-KG-IMPLEMENTATION-PLAN-20260908.md`

The repository baseline reviewed for this document still uses the old Shipment fulfillment field:

```text
shipment_items.shipped_quantity
```

with historical Kg semantics.

The Qty + Actual Kg plan changes Shipment fulfillment authority to:

```text
shipment_items.shipped_qty         INT
shipment_items.actual_weight_kg    DECIMAL(...)
```

and refactors fulfillment to integer Qty.

### Mandatory execution dependency

Material progress quantity logic MUST be based on the final Qty-based fulfillment contract.

Do not implement the material-progress quantity calculations against the old Kg-based:

```text
shipped_quantity
ordered_units
remaining_units
quantityToUnits()
```

contract.

If both plans are implemented in the same branch, the implementation order must be:

```text
1. Establish Qty-based Shipment fulfillment
2. Verify Qty-based fulfillment projections
3. Add Supplier Material Progress
4. Add synchronized progress/date presentation
```

The material-progress feature must consume the final authoritative Qty projection rather than duplicating quantity arithmetic.

---

## 3. Repository Evidence Reviewed

The plan is based on the current `master` source at the baseline SHA above.

Relevant existing architecture reviewed includes:

```text
AGENTS.md

app/Models/
├── PurchaseOrder.php
├── PrItemAward.php
├── Quotation.php
├── Shipment.php
└── ShipmentItem.php

app/Services/
├── PurchaseOrderGenerationService.php
├── ShipmentService.php
└── NotificationService.php

app/Http/Controllers/
├── Purchasing/
│   ├── PurchaseOrderController.php
│   └── PriceComparisonController.php
└── Supplier/
    ├── SupplierPurchaseOrderController.php
    ├── SupplierShipmentController.php
    └── QuotationController.php

app/Support/
├── StatusHelper.php
└── NotificationCategory.php

resources/views/
├── supplier/
│   ├── po/show.blade.php
│   ├── quotations/create.blade.php
│   ├── quotations/show.blade.php
│   └── shipments/...
└── purchasing/
    ├── po/show.blade.php
    ├── quotations/show.blade.php
    ├── comparison/inter-supplier.blade.php
    └── shipments/...
```

Important current facts:

- `PrItemAward` is the authoritative item-level award record.
- One PR item has at most one winning supplier.
- `PrItemAward.purchase_order_id` identifies the PO containing that awarded item.
- One PO belongs to one supplier.
- One PO may include multiple awarded items.
- One PO may be fulfilled through multiple Shipments.
- One Shipment may contain multiple POs only for the same supplier.
- Partial delivery is supported.
- Shipment `draft` does not consume fulfillment allocation.
- Shipment `submitted` and `arrived` participate in allocation/fulfillment.
- Cancellation releases Shipment allocation.
- Shipment and PO status labels are currently implemented in multiple places, including local Blade/controller `match()` blocks.
- `StatusHelper` already centralizes several PO status labels but Shipment presentation remains partially duplicated.
- `NotificationService` already provides idempotent database notifications and defers delivery until after commit when called inside a transaction.

These facts should be preserved.

---

## 4. Problem Statement

Purchasing currently has PO status information such as:

```text
Active
Waiting QC
Claim Needed
Completed
```

and Shipment status information such as:

```text
Draft
Submitted
Arrived
Cancelled
```

but neither status explains what the supplier is doing with material that has not yet been dispatched.

For example:

```text
PO = Active
Shipment = none
```

does not distinguish:

```text
Awaiting Supplier Confirmation
Material Preparation
On Production
Ready to Ship
```

The requirement is to allow Supplier to update the operational progress of each awarded PO item so Purchasing can monitor execution before Shipment.

The solution must also remain correct when a PO item is partially shipped.

---

## 5. Confirmed Business Decisions

The following decisions are confirmed and should be treated as implementation requirements.

### 5.1 Progress granularity

Supplier Material Progress is maintained:

```text
PER PO ITEM
```

not once for the entire PO.

### 5.2 Minimal manual stages

Use only the following stages for the first implementation:

```text
awaiting_confirmation
order_confirmed
material_preparation
on_production
ready_to_ship
```

User-facing labels:

```text
Awaiting Confirmation
Order Confirmed
Material Preparation
On Production
Ready to Ship
```

Do not add additional stages such as `Finishing`, `Packing`, `Post Production`, or percentage completion in this implementation.

### 5.3 Stage skipping

Forward stages are not a strict workflow gate.

Supplier may skip stages.

Valid example:

```text
Order Confirmed
→ Ready to Ship
```

This is required for cases such as ready stock or supplier-specific production flows.

### 5.4 Backward movement

Supplier may move progress backward.

Example:

```text
Ready to Ship
→ On Production
```

This can represent rework or another operational reversal.

Backward movement requires a progress note/reason.

History must preserve both states.

### 5.5 Shipment authority boundary

Manual Supplier Progress only describes quantity still under Supplier operational control.

For a Shipment in `draft`:

```text
Shipment does not consume allocation.
Supplier Progress remains editable.
```

When a Shipment is `submitted`:

```text
the submitted quantity moves under Shipment authority
and becomes system-derived In Transit.
```

Important for partial Shipment:

```text
Ordered Qty = 8
Submitted Qty = 3

3 pcs → Shipment authority / In Transit
5 pcs → Supplier Material Progress remains editable
```

Therefore, Shipment submission does NOT necessarily lock the entire PO item.

It only removes the submitted quantity from the manually tracked Supplier-controlled balance.

If the Supplier-controlled balance reaches zero, manual progress editing for that item is disabled.

### 5.6 Partial Shipment is a required use case

The design must support:

```text
one PO item
→ multiple Shipments
→ remaining material can continue through Supplier Progress
```

Example:

```text
Ordered Qty = 8

Shipment #1 = 3
Remaining   = 5

3 pcs → In Transit
5 pcs → On Production
```

### 5.7 Progress update fields

Supplier progress update includes:

```text
Progress Status          required
Progress Note            optional normally
Estimated Ready Date     optional
```

Progress Note becomes required for backward stage movement.

### 5.8 Notification

Every Supplier material-progress update must notify Purchasing.

Notification delivery must use the existing notification infrastructure.

### 5.9 History

Progress history is mandatory.

Supplier must not overwrite or delete historical progress records.

Purchasing must be able to inspect the full progress history for an item.

---

## 6. Domain Boundary

The final architecture must keep these domains separate:

```text
PO STATUS
Operational state of the Purchase Order

SUPPLIER MATERIAL PROGRESS
What the Supplier is currently doing with outstanding, Supplier-controlled Qty

SHIPMENT LIFECYCLE
State of a physical consignment

QC / CLAIM
Quality outcome and dispute/remediation state

FULFILLMENT
Authoritative Qty accounting
```

Explicitly preserve:

```text
PO STATUS
!= MATERIAL PROGRESS
!= SHIPMENT STATUS
!= QC STATUS
!= CLAIM STATUS
```

Do not add manual stages such as `on_production` or `ready_to_ship` to:

```text
purchase_orders.status
```

Do not add them to:

```text
shipments.status
```

---

## 7. Target End-to-End Lifecycle

Recommended business timeline:

```text
PR / Quotation
        ↓
Supplier submits quotation
        ↓
Supplier Estimated Ready / Dispatch Date
        ↓
Purchasing awards item
        ↓
PO created
        ↓
Awaiting Confirmation
        ↓
Order Confirmed
        ↓
Material Preparation
        ↓
On Production
        ↓
Ready to Ship
        ↓
Shipment Draft
        ↓
Shipment Submitted
        ↓
In Transit
        ↓
Shipment Arrived
        ↓
Waiting QC
        ↓
QC OK / QC NG
        ↓
Accepted / Claim
        ↓
Replacement if required
        ↓
PO Completed when accepted Qty reaches ordered Qty
```

Forward material stages may be skipped.

Backward material stages are allowed with a required reason.

---

## 8. Stage Definitions

### 8.1 `awaiting_confirmation`

Meaning:

Supplier has received the PO but has not yet provided a material-progress update.

Recommended implementation:

- derived default;
- no database history row is required merely because the PO was created;
- displayed when there is no Supplier progress history for the award.

### 8.2 `order_confirmed`

Meaning:

Supplier acknowledges the awarded PO item and confirms that it is being handled.

This does not mean production has started.

### 8.3 `material_preparation`

Meaning:

Supplier is preparing raw material, stock, tooling, internal allocation, or prerequisites before production.

### 8.4 `on_production`

Meaning:

The material is actively under manufacturing/processing.

This is an informational operational stage.

It must not change fulfillment quantities.

### 8.5 `ready_to_ship`

Meaning:

The remaining Supplier-controlled quantity is ready for dispatch.

This status is informational and must not itself create or submit a Shipment.

Shipment remains the authoritative physical-delivery domain.

---

## 9. Stage Ordering and Transition Rules

Use an explicit rank map in domain code:

```php
[
    'awaiting_confirmation' => 10,
    'order_confirmed'       => 20,
    'material_preparation'  => 30,
    'on_production'         => 40,
    'ready_to_ship'         => 50,
]
```

Rules:

```text
next rank > current rank
→ forward transition
→ allowed
→ skipping is allowed

next rank = current rank
→ status refresh/update
→ allowed

next rank < current rank
→ backward transition
→ allowed
→ note required
```

Do not encode a strict `A must go to B before C` state machine.

---

## 10. Quantity Authority

Supplier Material Progress must never become a second fulfillment ledger.

The authoritative ordered, allocated, accepted, NG, replacement, reserved, and remaining Qty must continue to come from the final Qty-based Purchase Order fulfillment logic.

After the Qty + Actual Kg revision, the material-progress service should consume the final equivalent of:

```text
ordered_qty
accepted_qty
reserved_qty
allocated_qty
remaining_qty
replacement_eligible_qty
```

The authoritative quantity representing the current manual Supplier Progress is:

```text
supplier_controlled_qty = authoritative remaining_qty
```

Do not calculate this independently from raw Shipment rows inside the progress controller.

Do not trust a Supplier-submitted quantity field for progress.

---

## 11. Progress Quantity Is Derived, Not Entered

Supplier does NOT manually enter:

```text
Progress Qty
```

The system derives it.

Example:

```text
Ordered Qty = 8
No Shipment

supplier_controlled_qty = 8
```

Supplier updates:

```text
On Production
```

Display:

```text
On Production — 8 pcs
```

Then Supplier submits a Shipment for 3 pcs:

```text
supplier_controlled_qty = 5
```

The latest manual stage remains:

```text
On Production
```

but display becomes:

```text
On Production — 5 pcs
```

No history row needs to be rewritten.

The original history entry can retain the Qty snapshot that existed at update time.

---

## 12. Quantity Snapshot for Audit History

Each Supplier progress history record should store:

```text
supplier_controlled_qty_snapshot
```

This value is:

- calculated server-side;
- an integer;
- the authoritative remaining Qty at the moment the update was committed.

Example:

```text
17 Sep
On Production
Supplier-controlled Qty snapshot: 8

20 Sep
Shipment 3 pcs submitted

Current Supplier-controlled Qty: 5
```

The history remains factually correct:

> On 17 Sep, the Supplier reported `On Production` when 8 pcs were still under Supplier control.

Current UI must derive the live Qty separately.

---

## 13. Partial Shipment Projection

Mandatory example:

```text
Ordered = 8
```

Initial:

```text
Supplier Progress:
8 pcs — On Production
```

After Shipment #1 submitted for 3:

```text
In Transit:
3 pcs

Supplier Progress:
5 pcs — On Production
```

After Shipment #1 arrives:

```text
Arrived / Waiting QC:
3 pcs

Supplier Progress:
5 pcs — On Production
```

After Shipment #1 QC OK:

```text
Accepted:
3 pcs

Supplier Progress:
5 pcs — On Production
```

After remaining 5 becomes Ready to Ship:

```text
Accepted:
3 pcs

Supplier Progress:
5 pcs — Ready to Ship
```

After Shipment #2 submitted:

```text
Accepted:
3 pcs

In Transit:
5 pcs

Supplier-Controlled:
0 pcs
```

Manual progress editing is disabled because:

```text
supplier_controlled_qty = 0
```

---

## 14. Cancellation Behavior

Shipment cancellation releases its allocation.

Therefore:

```text
supplier_controlled_qty
```

can increase after cancellation.

Example:

```text
Ordered = 8
Shipment Submitted = 3
Supplier-Controlled = 5
```

Shipment cancelled:

```text
Supplier-Controlled = 8
```

The progress system must not create a second quantity balance.

It simply uses the recalculated authoritative remaining Qty.

The current manual stage can remain the latest Supplier-reported stage unless the Supplier chooses to update it.

---

## 15. Claim and Replacement Behavior

Actual Kg and Supplier progress stages must not affect claim or replacement quantity arithmetic.

Claim and replacement continue to use the authoritative Qty fulfillment projection.

When a resolved claim makes replacement Qty available again, the Supplier-controlled Qty can increase.

The current stage continues to describe the outstanding Supplier-controlled balance until Supplier updates it.

Recommended UX hardening:

If current Supplier-controlled Qty becomes greater than the Qty snapshot stored in the latest Supplier update, the UI may display:

```text
Progress Update Recommended
```

because new replacement responsibility may have been added after the last progress report.

This warning is advisory and must not alter fulfillment.

It can be deferred if implementation scope needs to remain minimal.

---

## 16. `Ready to Ship` Is Not a Hard Shipment Gate

Do not require:

```text
manual status == ready_to_ship
```

before Shipment creation/submission.

Reason:

- material progress is operational reporting;
- Supplier may skip stages;
- a delayed manual update must not block a valid physical Shipment;
- Shipment service already owns authoritative delivery eligibility and over-allocation protection.

Recommended UI behavior:

If Supplier selects an item for Shipment while manual progress is not `Ready to Ship`, show a non-blocking informational warning.

Do not weaken Shipment validation.

---

# DELIVERY DATE SEMANTICS

## 17. Quotation Date — Original Supplier Commitment

Existing database field:

```text
quotations.estimated_delivery
```

Confirmed business meaning:

> Estimated date the material will be ready for dispatch / ready to ship from the Supplier.

It does NOT mean arrival at ADSI.

Recommended UI label:

```text
Supplier Estimated Ready / Dispatch Date
```

Alternative shorter label:

```text
Estimated Ready / Dispatch Date
```

This value represents the original quotation-stage Supplier commitment.

After the quotation becomes final/awarded, it must remain historical evidence.

Do not overwrite it with later progress updates.

---

## 18. PO Date — Purchasing Target

Existing database field:

```text
purchase_orders.estimated_arrival
```

Confirmed meaning:

> Purchasing's target date for the material to arrive at ADSI.

Recommended UI label:

```text
PO Target Arrival Date
```

Do not label this merely `Estimated Delivery` because that can be confused with the Supplier's ready-to-dispatch commitment.

---

## 19. Material Progress Date — Current Supplier Forecast

New progress field:

```text
estimated_ready_date
```

Meaning:

> Supplier's latest forecast for when the outstanding item quantity will be ready for dispatch.

Recommended UI label:

```text
Current Estimated Ready Date
```

This value:

- is optional;
- may change with each Supplier update;
- must be recorded in history;
- must NOT overwrite `quotations.estimated_delivery`.

Example:

```text
Quotation Original Commitment:
25 Sep

Progress Update:
Current Estimated Ready Date:
27 Sep
```

Purchasing can now see:

```text
Original commitment = 25 Sep
Current forecast     = 27 Sep
Variance             = +2 days
```

---

## 20. Shipment Dispatch Date

Existing database field:

```text
shipments.shipment_date
```

Target semantic:

> Actual dispatch date for the physical consignment when Shipment is submitted.

Current drafts may contain an editable date value.

Required semantic boundary:

```text
Draft
→ value is editable/planned
→ not authoritative evidence of dispatch

Submitted
→ dispatch date is confirmed
→ becomes authoritative
→ immutable with the submitted Shipment
```

UI should reflect lifecycle:

Draft:

```text
Planned Dispatch Date
```

Submitted/Arrived:

```text
Dispatch / Shipment Date
```

On submission, validate and persist the final confirmed dispatch date.

Do not create another automatic PO status from this date.

---

## 21. Shipment ETA

Existing database field:

```text
shipments.estimated_arrival_date
```

Meaning:

> Supplier's latest ETA for that physical Shipment to reach ADSI.

Recommended label:

```text
Shipment ETA
```

This is Shipment-specific.

It must not overwrite:

```text
purchase_orders.estimated_arrival
```

because one PO can have multiple Shipments with different ETAs.

---

## 22. Actual Arrival Date

Shipment-aware receiving uses:

```text
shipments.actual_arrival_date
```

Meaning:

> Actual physical arrival date of that consignment at ADSI.

This is authoritative for the physical Shipment.

Do not force every Shipment actual arrival date into one PO-level date as a simple one-to-one synchronization because:

```text
1 PO → many Shipments
```

Existing legacy PO arrival semantics must remain compatible.

---

## 23. Date Relationship Summary

```text
Quotation.estimated_delivery
= ORIGINAL supplier ready/dispatch commitment

PO.estimated_arrival
= PURCHASING target arrival at ADSI

ProgressUpdate.estimated_ready_date
= CURRENT supplier ready forecast

Shipment.shipment_date
= ACTUAL dispatch date once Shipment submitted

Shipment.estimated_arrival_date
= CURRENT ETA for that physical Shipment

Shipment.actual_arrival_date
= ACTUAL physical arrival for that Shipment
```

No automatic equality should be imposed between these fields.

They are timeline evidence, not duplicates.

---

## 24. Date Comparison / Risk Presentation

Recommended derived comparisons:

### Original Supplier commitment variance

```text
Current Estimated Ready Date
-
Quotation Original Ready Date
```

Example:

```text
Original: 25 Sep
Current : 27 Sep

Supplier forecast moved by +2 days
```

### Shipment dispatch performance

```text
Actual Dispatch Date
-
Original Supplier Ready / Dispatch Commitment
```

Example:

```text
Supplier commitment: 25 Sep
Actual dispatch     : 27 Sep

Dispatch: 2 days after original commitment
```

This should be informational unless future business rules define formal SLA logic.

### Arrival forecast against PO target

```text
Shipment ETA
vs
PO Target Arrival Date
```

Example:

```text
PO Target Arrival: 30 Sep
Shipment ETA      : 01 Oct

Forecast: 1 day after target
```

### Actual arrival performance

```text
Actual Arrival Date
vs
PO Target Arrival Date
```

Example:

```text
Target: 30 Sep
Actual: 02 Oct

Arrival: 2 days after target
```

These should be derived presentation values.

Do not persist duplicate `late_days` fields unless later reporting requirements justify them.

---

## 25. Price Comparison Integration

Current inter-supplier comparison already contains the PO target arrival input.

Required improvements:

1. Show each Supplier's quotation-level:

```text
Supplier Estimated Ready / Dispatch Date
```

as a visible comparison attribute.

2. Rename:

```text
Target Estimated Arrival Date
```

to:

```text
PO Target Arrival Date
```

3. Add helper text explaining:

```text
Supplier Ready / Dispatch Date
= when Supplier expects material ready to send

PO Target Arrival Date
= when Purchasing expects material at ADSI
```

4. Do not silently copy one date into the other.

5. If PO Target Arrival is earlier than the selected Supplier's original ready/dispatch date, show a warning.

Recommended warning:

```text
PO Target Arrival is earlier than the Supplier's estimated ready/dispatch date.
Review the target before generating the PO.
```

For the initial implementation, this should be a non-blocking warning unless Purchasing explicitly requests a hard validation rule.

---

## 26. Multi-Supplier Award Date Note

Current item-level award generation can create multiple POs from one action when different suppliers win different items.

The current PO generation service supports supplier-specific estimated-arrival options, but the current comparison UI uses a single target date input.

This plan does NOT silently redefine that business rule.

Initial implementation should preserve the current target-date write behavior unless Purchasing explicitly approves supplier-specific PO target dates.

Document this as a future refinement:

```text
Future:
estimated_arrivals[supplier_id]
```

Do not introduce this behavioral change without confirmation.

---

# DATA MODEL

## 27. Recommended New Table

Use one append-only history table as the source of truth for manual Supplier Progress:

```text
po_item_progress_updates
```

Do not create both a mutable snapshot table and a history table unless measured performance later requires it.

Current state can be resolved from the latest history record.

This avoids snapshot/history divergence.

---

## 28. Proposed Schema

Recommended migration:

```text
2026_09_08_000002_create_po_item_progress_updates_table.php
```

if the Qty + Actual Kg migration becomes `2026_09_08_000001...`.

The final sequence must follow the actual branch migration order.

Proposed structure:

```text
po_item_progress_updates
├── id
├── pr_item_award_id
├── status
├── supplier_controlled_qty_snapshot
├── estimated_ready_date
├── note
├── updated_by
├── created_at
└── updated_at
```

Recommended definitions:

```php
$table->id();

$table->foreignId('pr_item_award_id')
    ->constrained('pr_item_awards');

$table->string('status', 32);

$table->unsignedInteger('supplier_controlled_qty_snapshot');

$table->date('estimated_ready_date')->nullable();

$table->text('note')->nullable();

$table->foreignId('updated_by')
    ->nullable()
    ->constrained('users')
    ->nullOnDelete();

$table->timestamps();

$table->index(
    ['pr_item_award_id', 'created_at', 'id'],
    'po_item_progress_award_history_idx'
);
```

The exact FK delete behavior must preserve history integrity.

Do not introduce cascade deletion of progress history without explicitly validating how award deletion currently behaves.

For production auditability, restrictive/no-action award deletion is preferred after an award is attached to a PO.

---

## 29. Status Database Integrity

Use application constants as the canonical allowed values.

Example model:

```php
public const STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation';
public const STATUS_ORDER_CONFIRMED = 'order_confirmed';
public const STATUS_MATERIAL_PREPARATION = 'material_preparation';
public const STATUS_ON_PRODUCTION = 'on_production';
public const STATUS_READY_TO_SHIP = 'ready_to_ship';

public const STATUSES = [
    self::STATUS_AWAITING_CONFIRMATION,
    self::STATUS_ORDER_CONFIRMED,
    self::STATUS_MATERIAL_PREPARATION,
    self::STATUS_ON_PRODUCTION,
    self::STATUS_READY_TO_SHIP,
];
```

A DB CHECK can be added if compatible with the project's MySQL/MariaDB target and migration practices.

Do not use a mutable Supplier-provided arbitrary status string.

---

## 30. No Backfill Required for Current Award-Based POs

Existing award-based POs can safely behave as:

```text
no progress history
→ Awaiting Confirmation
```

until Supplier provides the first update.

Do not create synthetic historical Supplier updates.

PO creation itself already has an authoritative creation timestamp and notification.

---

## 31. Legacy PO Scope

Legacy POs without `PrItemAward` do not have the same authoritative per-item identity.

Version 1 should therefore scope editable Supplier Material Progress to:

```text
award-based PO items
```

Legacy POs remain unchanged.

Do not invent a new legacy item identity or backfill ambiguous history in this implementation.

If business later requires progress tracking for old legacy POs, handle it as a separate migration/design task.

---

# MODEL & SERVICE DESIGN

## 32. New Model

Recommended:

```text
app/Models/PoItemProgressUpdate.php
```

Responsibilities:

- constants for manual stage values;
- stage rank metadata;
- casts for `estimated_ready_date`;
- relation to `PrItemAward`;
- relation to updater `User`;
- label/tone helpers only if consistent with repository conventions.

Do not put fulfillment arithmetic in this model.

---

## 33. `PrItemAward` Relations

Add relations such as:

```php
public function progressUpdates(): HasMany
{
    return $this->hasMany(PoItemProgressUpdate::class);
}
```

and:

```php
public function latestProgressUpdate(): HasOne
{
    return $this->hasOne(PoItemProgressUpdate::class)->latestOfMany();
}
```

The exact `latestOfMany()` ordering should be deterministic.

If `created_at` ties are possible, use `id` as deterministic final ordering.

---

## 34. New Domain Service

Recommended:

```text
app/Services/MaterialProgressService.php
```

This service is the canonical domain boundary for Supplier material progress.

Do not place this logic directly in Blade or controllers.

Recommended responsibilities:

```php
currentForAward(...)
projectionForAward(...)
updateProgress(...)
historyForAward(...)
poSummary(...)
isBackwardTransition(...)
supplierControlledQty(...)
```

---

## 35. Current Progress Resolution

Pseudo-contract:

```php
public function currentForAward(PrItemAward $award): array
```

returns conceptually:

```php
[
    'status' => 'on_production',
    'label' => 'On Production',
    'estimated_ready_date' => ...,
    'note' => ...,
    'updated_at' => ...,
    'updated_by' => ...,

    'ordered_qty' => 8,
    'supplier_controlled_qty' => 5,

    'has_manual_update' => true,
]
```

If no update exists:

```php
[
    'status' => 'awaiting_confirmation',
    'has_manual_update' => false,
    ...
]
```

---

## 36. Canonical Item Projection

The service should provide a read-only projection per awarded PO item.

Conceptually:

```php
[
    'ordered_qty' => 8,

    'accepted_qty' => 3,
    'in_transit_qty' => 2,
    'arrived_pending_qc_qty' => 0,
    'ng_or_claim_qty' => 0,

    'supplier_controlled_qty' => 3,

    'manual_progress_status' => 'on_production',
    'manual_progress_label' => 'On Production',
    'current_estimated_ready_date' => ...,

    'original_supplier_ready_date' => ...,

    'last_progress_update_at' => ...,
]
```

Do not duplicate existing QC/claim/fulfillment algorithms merely to populate UI.

Reuse authoritative existing model/service projections.

---

## 37. Transactional Progress Update

Recommended flow:

```text
BEGIN TRANSACTION

1. Resolve authenticated Supplier
2. Lock PO
3. Verify PO owner
4. Lock PrItemAward
5. Verify award belongs to the PO
6. Verify award belongs to the authenticated Supplier
7. Verify PO is still eligible for Supplier progress reporting
8. Read authoritative Qty fulfillment
9. Resolve supplier_controlled_qty
10. Reject update if supplier_controlled_qty <= 0
11. Resolve current/previous stage
12. Detect backward movement
13. Require note if backward
14. Insert append-only progress history record
15. Commit
16. NotificationService sends after commit

END
```

Do not update/delete the previous progress record.

---

## 38. Locking and Concurrency

Progress updates can race with Shipment submission.

Required principle:

Supplier Progress must observe a coherent authoritative remaining Qty.

Recommended synchronization boundary:

```text
Progress update:
PO lock
→ award lock
→ authoritative fulfillment read
→ history insert
```

Shipment submission already serializes around Shipment and PO locks.

Do not introduce reverse locking against Shipment rows from the progress transaction unless strictly necessary.

Avoid:

```text
Progress:
PO → Shipment lock

Shipment submit:
Shipment → PO
```

because that creates an avoidable lock-order inversion.

The progress history Qty snapshot is informational audit evidence, while Shipment fulfillment remains authoritative.

Deterministic ordering reduces deadlock risk but does not make deadlocks impossible.

---

# AUTHORIZATION & SECURITY

## 39. Supplier Ownership Invariant

Supplier can update only an award satisfying all of:

```text
award.purchase_order_id == requested PO
award.supplier_id == auth()->id()
PO.supplier_id == auth()->id()
```

Do not trust route identifiers alone.

Do not treat Hashids as authorization.

---

## 40. Purchasing Access

Purchasing can:

```text
view current Supplier Material Progress
view full progress history
view date comparison information
```

Purchasing cannot impersonate Supplier progress updates through the Supplier route.

If an administrative override is ever needed, it must be a separate explicitly approved feature.

---

## 41. Hashid / Identifier Handling

Do not introduce new raw numeric IDs into user-visible URLs without reviewing the repository Hashid rules.

Preferred route shape:

```text
/supplier/purchase-orders/{po}/items/{award}/progress
```

If `PrItemAward` becomes routable:

- inspect all current usages first;
- use the existing `HasHashids` pattern if safe;
- register the new route parameter in `DecodeHashids` if required;
- add negative tests rejecting raw numeric identifiers where policy requires it.

Alternative:

Use the hashed PO route and securely resolve the item/award inside the request without exposing a raw award ID in the URL.

Whichever method is chosen:

```text
authorization must verify the complete PO → Award → Supplier chain.
```

---

# CONTROLLER / ROUTE PLAN

## 42. Supplier Controller

Recommended new controller:

```text
app/Http/Controllers/Supplier/PoItemProgressController.php
```

Responsibilities:

```text
update()
history()
```

Controller should remain thin.

Validation and domain writes should be delegated to `MaterialProgressService`.

---

## 43. Purchasing Controller

Recommended read-only controller:

```text
app/Http/Controllers/Purchasing/PoItemProgressController.php
```

Responsibilities:

```text
history()
```

Current progress for PO detail can be prepared in the existing Purchase Order controller through the service.

---

## 44. Suggested Routes

Supplier:

```text
POST
supplier.purchase-orders.item-progress.update

GET
supplier.purchase-orders.item-progress.history
```

Purchasing:

```text
GET
purchasing.purchase-orders.item-progress.history
```

Use route middleware already established for each role.

Do not create cross-role mutable routes.

---

# SUPPLIER UX

## 45. Supplier PO Detail

The Supplier PO detail is the primary update location.

Add a dedicated:

```text
Material Progress
```

section or integrate progress controls into the awarded-material table.

Each awarded item should show:

```text
Material
Ordered Qty
Accepted Qty
In Transit / Arrived Qty
Supplier-Controlled Qty
Current Progress
Original Supplier Ready / Dispatch Date
Current Estimated Ready Date
Last Updated
Action
```

Example:

```text
SKD11

Ordered:
8 pcs

Accepted:
3 pcs

In Transit:
0 pcs

Supplier-Controlled:
5 pcs

Current Progress:
On Production

Original Ready Commitment:
25 Sep 2026

Current Estimated Ready:
27 Sep 2026

Last Updated:
17 Sep 2026 14:32

[Update Progress] [History]
```

---

## 46. Supplier Update Form

Recommended fields:

```text
Progress Status *
Current Estimated Ready Date
Progress Note
```

Do not expose editable quantity.

Current Supplier-controlled Qty should be displayed read-only:

```text
This update applies to:
5 pcs currently under Supplier control
```

---

## 47. Backward Transition UX

When selected status is earlier than the current status:

```text
Ready to Ship
→ On Production
```

show:

```text
A reason is required because the material progress is moving backward.
```

Backend validation remains authoritative.

Do not rely only on JavaScript.

---

## 48. Zero Remaining Qty UX

If:

```text
supplier_controlled_qty = 0
```

do not show an active manual progress update button.

Display the system-managed state instead.

Example:

```text
All outstanding Qty is already allocated to Shipment / downstream processing.
```

---

## 49. Shipment Draft Interaction

Draft Shipment does not consume Qty.

Supplier PO detail may show:

```text
Draft Shipment Planned:
2 pcs
```

as informational context if useful.

However:

```text
supplier_controlled_qty
```

remains unchanged until submission.

Do not show draft Qty as `In Transit`.

---

# PURCHASING UX

## 50. Purchasing PO Detail

Add a dedicated:

```text
Supplier Material Progress
```

section.

Recommended location:

after Order Information / Materials and before downstream QC/claim sections.

Per item show:

```text
Material
Ordered Qty
Accepted Qty
In Transit
Waiting QC
Supplier-Controlled Qty
Supplier Progress
Original Supplier Ready / Dispatch Date
Current Estimated Ready Date
Last Supplier Update
History
```

---

## 51. Purchasing Progress History

History should display newest-first or timeline chronological order consistently.

Recommended event content:

```text
17 Sep 2026 14:32
On Production
Qty at update: 8 pcs
Estimated Ready: 27 Sep
Updated by: Supplier ABC User
Note: Heat treatment ongoing
```

Then:

```text
24 Sep 2026 09:10
Ready to Ship
Qty at update: 5 pcs
Estimated Ready: 24 Sep
Updated by: Supplier ABC User
Note: Packing completed
```

History must be read-only.

---

## 52. PO-Level Summary

Do not invent one mutable `material_progress_status` on the PO.

Instead derive a summary from item projections.

Example:

```text
3 awarded items

1 Ready to Ship
1 On Production
1 In Transit
```

Recommended PO-level text:

```text
Material Progress
1 Ready to Ship · 1 On Production · 1 In Transit
```

If all current items are homogeneous, a single summary label may be shown.

If mixed, show a count summary instead of forcing one misleading stage.

---

# SHIPMENT UX & SYNCHRONIZED PRESENTATION

## 53. Shipment Lifecycle Labels

Centralize Shipment lifecycle presentation.

Recommended lifecycle labels:

```text
draft      → Draft
submitted  → In Transit
arrived    → Arrived
cancelled  → Cancelled
```

The stored Shipment status values do not need to change.

Do not keep separate inconsistent `match()` labels in Supplier and Purchasing Shipment views.

---

## 54. Process Status vs Lifecycle Status

Shipment detail can display:

```text
Lifecycle Status:
Arrived

Process Status:
Waiting QC
```

or:

```text
Lifecycle Status:
Arrived

Process Status:
QC Passed
```

or:

```text
Lifecycle Status:
Arrived

Process Status:
QC NG — Claim Required
```

The second value is derived.

Do not add `waiting_qc`, `qc_ok`, or `claim` to `shipments.status`.

---

## 55. PO Status vs Material Progress

PO detail can display:

```text
PO Status:
Active

Material Progress:
On Production — 5 pcs
```

or:

```text
PO Status:
Active

Delivery Progress:
3 / 8 pcs accepted
5 pcs remaining — Ready to Ship
```

This makes `Active` understandable rather than replacing it.

---

## 56. Centralized Presentation

Existing `StatusHelper` should be extended only for presentation metadata.

Recommended additions:

```php
StatusHelper::materialProgressLabel(...)
StatusHelper::materialProgressTone(...)
StatusHelper::shipmentLifecycleLabel(...)
StatusHelper::shipmentLifecycleTone(...)
```

Cross-domain process calculation belongs in `MaterialProgressService`, not `StatusHelper`.

Avoid business queries inside presentation helpers.

---

# NOTIFICATIONS

## 57. Purchasing Notification

Every committed Supplier progress update must notify active Purchasing users.

Recommended event:

```text
po_item_progress.updated
```

Recommended idempotency key:

```text
po_item_progress.updated:{progress_update_id}
```

This is compatible with the existing NotificationService UUID/idempotency behavior.

---

## 58. Notification Content

Example:

```text
Title:
Supplier Material Progress Updated

Message:
Supplier ABC updated SKD11 on PO/09/2026/001:
On Production → Ready to Ship.
Current Supplier-controlled Qty: 5 pcs.
Estimated Ready: 24 Sep 2026.
```

URL:

```text
Purchasing PO detail
# Supplier Material Progress section
```

Use the existing notification category unless product requirements justify a new category.

Recommended initial category:

```text
NotificationCategory::OTHER
```

Avoid broad notification UI scope expansion.

---

# VALIDATION

## 59. Progress Request Validation

Recommended backend rules:

```text
status
required
allowed manual stages only

estimated_ready_date
nullable
date

note
nullable
string
max appropriate application limit
```

Domain validation adds:

```text
backward transition
→ note required

supplier_controlled_qty <= 0
→ reject update

PO completed/cancelled
→ reject update

award not owned by Supplier
→ reject

award not attached to requested PO
→ reject
```

Do not trust front-end disabled controls as authorization.

---

## 60. Estimated Ready Date Rules

Do not force:

```text
estimated_ready_date >= today
```

unless Purchasing explicitly requires it.

Historical or late updates can be operationally valid.

The date must be a valid date.

Variance from original commitment should be displayed, not silently rejected.

---

# MIGRATION STRATEGY

## 61. Forward Migration

The material-progress migration is additive.

It should:

1. create the history table;
2. create required indexes;
3. create FK constraints safely;
4. avoid modifying existing transactional rows;
5. avoid rewriting deployed Shipment or award migrations.

No data conversion is required.

---

## 62. Rollback

Development/test rollback can drop the new table.

Production rollback after real Supplier progress history exists is data-destructive.

Therefore deployment documentation must state:

```text
Do not drop po_item_progress_updates in production
after business history has been recorded
without explicit data-retention approval.
```

Application rollback can leave the table unused if needed.

---

# PERFORMANCE

## 63. Query Strategy

PO detail must not execute N+1 queries per item.

Use eager loading / latest-of-many:

```text
PO
→ awards
→ quotationItem
→ prItem
→ quotation
→ latestProgressUpdate
```

Only load full progress history when requested.

Do not eager load every historical row for every PO list page.

---

## 64. PO Index / Dashboard

Core implementation should prioritize PO detail correctness.

If Material Progress is later added to high-volume server-side PO lists:

- use aggregate/subquery/eager-loading appropriate to DataTables;
- preserve server-side search/filter behavior;
- profile the query;
- do not add one query per row.

A full dashboard analytics redesign is outside this scope.

---

# LEGACY & EXISTING BEHAVIOR PRESERVATION

## 65. Preserve Existing Invariants

Do not regress:

```text
supplier isolation

item-level award truth

one PO = one supplier

one Shipment = one supplier

same-supplier multi-PO Shipment

partial Shipment

duplicate logical Shipment-line rejection

Shipment allocation protection

Shipment cancellation release

shipment-aware receiving

shipment-aware QC

claim precedence

replacement semantics

private attachment authorization

Hashid protections

document sequence behavior
```

---

## 66. Preserve Commercial Weight Logic

The new progress feature does not change:

```text
price_per_kg
offered_weight_per_unit
offered_total_weight
PO commercial totals
exchange-rate snapshots
```

The Qty + Actual Kg plan handles Shipment quantity authority separately.

---

# EXPECTED FILE CHANGES

## 67. New Files

Expected new files include:

```text
database/migrations/
└── 2026_09_08_000002_create_po_item_progress_updates_table.php
   (exact sequence depends on Qty migration)

app/Models/
└── PoItemProgressUpdate.php

app/Services/
└── MaterialProgressService.php

app/Http/Controllers/Supplier/
└── PoItemProgressController.php

app/Http/Controllers/Purchasing/
└── PoItemProgressController.php

tests/Feature/
└── PoItemMaterialProgressTest.php
```

Optional dedicated test:

```text
tests/Feature/
└── PoItemProgressAuthorizationTest.php
```

---

## 68. Existing Files Likely Modified

Expected:

```text
app/Models/PrItemAward.php

app/Support/StatusHelper.php

app/Http/Controllers/Supplier/
└── SupplierPurchaseOrderController.php

app/Http/Controllers/Purchasing/
├── PurchaseOrderController.php
└── PriceComparisonController.php

resources/views/supplier/
├── po/show.blade.php
├── quotations/create.blade.php
├── quotations/show.blade.php
└── shipments/create.blade.php    (context/warning only if needed)

resources/views/purchasing/
├── po/show.blade.php
├── quotations/show.blade.php
├── comparison/inter-supplier.blade.php
└── shipments/show.blade.php

resources/views/supplier/shipments/
└── show.blade.php

routes/web.php

tests/Feature/
├── ShipmentAndPartialDeliveryTest.php
├── NotificationDeliveryTest.php
├── SupplierDataIsolationTest.php
└── HashidUrlSecurityTest.php
```

Exact scope must be confirmed from current source before editing.

---

# TEST STRATEGY

## 69. Core Progress Tests

Mandatory:

### P-01 Default state

Award-based PO item with no Supplier update:

```text
Awaiting Confirmation
```

### P-02 Supplier updates per item

PO has two awarded items.

Supplier updates only Item A.

Expected:

```text
Item A → On Production
Item B → Awaiting Confirmation
```

### P-03 Forward skip

```text
Order Confirmed
→ Ready to Ship
```

must be accepted.

### P-04 Backward with note

```text
Ready to Ship
→ On Production
```

with note must pass.

### P-05 Backward without note

Same transition without note must fail.

No history row inserted.

### P-06 Same-stage refresh

```text
On Production
→ On Production
```

with a new estimated ready date must be allowed.

History must contain both updates.

### P-07 History append-only

Multiple updates must create multiple rows.

Older history must remain unchanged.

---

## 70. Partial Shipment Tests

### P-08 Initial Qty

```text
Ordered = 8
No Shipment

Supplier-controlled = 8
```

### P-09 Partial submitted Shipment

```text
Ordered = 8
Submitted = 3

Supplier-controlled = 5
In Transit = 3
```

Latest manual status continues to apply to 5.

### P-10 Draft Shipment

```text
Ordered = 8
Draft Shipment = 3

Supplier-controlled remains 8
```

Draft must not be treated as In Transit.

### P-11 Arrival

```text
3 submitted → arrived

Supplier-controlled remains 5
Arrived/Waiting QC = 3
```

### P-12 QC OK

```text
3 accepted
5 outstanding

Supplier-controlled = 5
Accepted = 3
```

### P-13 Second Shipment

Remaining 5 submitted:

```text
Supplier-controlled = 0
```

Manual progress update must be disabled/rejected.

### P-14 Cancellation release

Shipment 3 cancelled:

```text
supplier-controlled Qty increases by 3
```

No duplicate fulfillment ledger is created.

---

## 71. Claim / Replacement Tests

### P-15 NG does not become accepted

Supplier progress update must not affect NG/accepted arithmetic.

### P-16 Replacement eligibility

When resolved claim returns Qty to remaining:

```text
supplier-controlled Qty follows authoritative fulfillment result
```

No manual quantity calculation.

---

## 72. Authorization Tests

### P-17 Cross-supplier PO

Supplier A cannot update Supplier B PO progress.

### P-18 Foreign award

Supplier cannot pair:

```text
own PO
+
another PO's award
```

### P-19 Purchasing read-only

Purchasing can view history but cannot use Supplier update endpoint.

### P-20 Raw identifier security

New routes must comply with current Hashid policy.

Raw numeric route identifiers must be rejected where the existing policy requires it.

---

## 73. Notification Tests

### P-21 Every Supplier update notifies Purchasing

One committed progress update:

```text
→ Purchasing notification created
```

### P-22 Notification after commit

Failed transaction:

```text
→ no progress notification
```

### P-23 Idempotent event key

Retrying notification delivery for the same progress-update event must not create duplicate logical notifications.

---

## 74. Date Semantics Tests

### D-01 Quotation label / data preserved

`quotation.estimated_delivery` remains unchanged after later progress updates.

### D-02 Progress forecast can change

Original:

```text
25 Sep
```

Current:

```text
27 Sep
```

Both values must remain independently available.

### D-03 PO target remains independent

Updating Supplier estimated ready date must not change:

```text
purchase_orders.estimated_arrival
```

### D-04 Shipment ETA remains independent

Shipment ETA update/draft metadata must not overwrite PO target arrival.

### D-05 Actual dispatch

Submitted Shipment persists the confirmed dispatch date and becomes immutable under existing submitted-Shipment rules.

### D-06 Partial Shipment dates

Two Shipments for one PO can have:

```text
different dispatch dates
different ETAs
different actual arrival dates
```

without rewriting the PO target date.

---

# STATUS SYNCHRONIZATION TESTS

## 75. Presentation Consistency

### S-01 Submitted Shipment

Supplier and Purchasing must both see the lifecycle as:

```text
In Transit
```

using one canonical label definition.

### S-02 Arrived waiting QC

Shipment:

```text
Lifecycle: Arrived
Process: Waiting QC
```

PO:

```text
Operational Status: Waiting QC
```

where existing PO reconciliation dictates that state.

### S-03 Partial accepted PO

Example:

```text
3 / 8 accepted
5 remaining On Production
```

PO must not display a misleading `Completed`.

Shipment that already arrived remains `Arrived`.

### S-04 Mixed states

PO with multiple items:

```text
Item A Ready to Ship
Item B On Production
Item C In Transit
```

PO-level progress summary must represent mixed state rather than forcing one item status onto the PO.

---

# EDGE CASES

## 76. Multiple Quotations in One PO

A same-supplier consolidated PO may contain awards from multiple quotations.

Each awarded item must resolve its original Supplier Ready / Dispatch commitment through its own authoritative quotation.

Do not assume:

```text
one PO = one quotation
```

---

## 77. Multiple POs in One Shipment

A Shipment can contain several same-supplier POs.

Shipment-level process presentation must show each related PO/item accurately.

Do not aggregate one PO's material stage onto another PO.

---

## 78. Multiple Shipments for One PO Item

One item can be:

```text
Shipment A → accepted
Shipment B → in transit
Remaining → on production
```

The progress projection must support all simultaneously.

---

## 79. No Current Manual Progress

No history:

```text
Awaiting Confirmation
```

Do not insert fake Supplier acknowledgements.

---

## 80. Completed PO

If PO is truly completed:

```text
accepted_qty >= ordered_qty
```

manual Supplier progress updates are not allowed.

Progress history remains visible.

---

## 81. Cancelled PO

Manual progress update is not allowed.

History remains visible.

---

# UI ACCEPTANCE EXAMPLES

## 82. Example A — Before Shipment

```text
PO-001
SKD11

PO Status
Active

Ordered Qty
8 pcs

Supplier Progress
On Production — 8 pcs

Supplier Original Ready / Dispatch
25 Sep 2026

Current Estimated Ready
27 Sep 2026

PO Target Arrival
30 Sep 2026
```

---

## 83. Example B — Partial Shipment

```text
PO-001
SKD11

Ordered
8 pcs

Accepted
3 pcs

In Transit
2 pcs

Supplier-Controlled
3 pcs

Supplier Progress
On Production — 3 pcs

Current Estimated Ready
27 Sep 2026
```

---

## 84. Example C — Ready to Ship

```text
Accepted
3 pcs

Supplier-Controlled
5 pcs

Supplier Progress
Ready to Ship — 5 pcs

Current Estimated Ready
24 Sep 2026
```

---

## 85. Example D — Shipment Submitted

```text
Supplier-Controlled
0 pcs

Shipment
SHP/09/2026/002

Lifecycle
In Transit

Dispatch
24 Sep

ETA
28 Sep

PO Target Arrival
30 Sep

Forecast
On Track
```

---

## 86. Example E — Arrival / QC

```text
Shipment Lifecycle
Arrived

Process
Waiting QC

Actual Arrival
29 Sep

PO Target
30 Sep

Arrival
On / Before Target
```

After QC OK:

```text
Accepted
8 / 8 pcs

PO
Completed
```

---

# OUT OF SCOPE

## 87. Explicit Non-Goals

Do not include in this implementation unless separately approved:

```text
production percentage
custom Supplier-defined statuses
Finishing / Packing stages
Gantt chart
supplier performance scoring
formal SLA penalties
automatic supplier rating
predictive ETA
machine-learning delay prediction
progress attachments/photos
progress approval by Purchasing
legacy PO progress backfill
new mobile application
automatic hard blocking based on Ready to Ship
automatic PO target per supplier in multi-supplier award
```

---

# IMPLEMENTATION PHASES

## 88. Phase 0 — Reconcile Current Branch

Before coding:

1. read global rules;
2. read workspace rules;
3. read `AGENTS.md`;
4. verify branch and HEAD;
5. inspect current Shipment Qty refactor state;
6. determine whether the Qty + Actual Kg plan has already been implemented;
7. search all relevant PO/Shipment/status/date references.

Do not assume the repository is still at this planning baseline.

---

## 89. Phase 1 — Qty Foundation

If not already implemented:

Complete and verify the Shipment Qty + Actual Kg revision first.

Required outcome:

```text
authoritative integer ordered/remaining Qty exists
```

Material Progress must consume it.

---

## 90. Phase 2 — Schema and Model

Implement:

```text
po_item_progress_updates
PoItemProgressUpdate
PrItemAward progress relations
```

Verify migration and rollback in development.

---

## 91. Phase 3 — Domain Service

Implement:

```text
MaterialProgressService
```

with:

- authoritative remaining Qty;
- stage ranking;
- backward-note rule;
- append-only history;
- ownership checks;
- transaction boundary;
- current projection.

Unit/feature-test domain behavior before UI integration.

---

## 92. Phase 4 — Supplier UI

Add:

```text
per-item progress display
update action
estimated ready date
note
history
```

to Supplier PO detail.

Ensure partial Shipment Qty changes are reflected live.

---

## 93. Phase 5 — Purchasing UI

Add:

```text
current per-item material progress
Qty distribution
original supplier commitment
current forecast
progress history
```

to Purchasing PO detail.

---

## 94. Phase 6 — Date Terminology

Update user-facing labels consistently:

```text
Estimated Material Delivery Time
→ Supplier Estimated Ready / Dispatch Date

Target Estimated Arrival Date
→ PO Target Arrival Date

Estimated Arrival Date
→ Shipment ETA

Draft shipment date
→ Planned Dispatch Date

Submitted shipment date
→ Dispatch / Shipment Date
```

Do not rename existing DB columns solely for display terminology.

---

## 95. Phase 7 — Price Comparison Context

Expose Supplier quotation ready/dispatch date in comparison.

Add target-date helper/warning.

Preserve current PO creation semantics unless separately approved.

---

## 96. Phase 8 — Status Presentation Synchronization

Centralize:

```text
Shipment lifecycle labels
Supplier material-progress labels
PO status labels where currently duplicated
```

Remove relevant duplicate `match()` blocks from modified paths.

Use derived process presentation instead of creating new database enums.

---

## 97. Phase 9 — Notifications

Notify active Purchasing users for every committed Supplier progress update.

Verify:

```text
after-commit behavior
idempotency
correct PO URL
no cross-supplier data leak
```

---

## 98. Phase 10 — Regression Verification

Run targeted tests first.

Then run complete regression suite.

Do not declare completion if:

```text
targeted tests fail
full suite has unexplained regressions
migration is not verified
authorization cases are not verified
Qty authority is ambiguous
```

---

# VERIFICATION COMMANDS

## 99. Recommended Verification

Exact commands depend on current project scripts/rules.

At minimum:

```bash
php artisan test --filter=PoItemMaterialProgressTest
php artisan test --filter=ShipmentAndPartialDeliveryTest
php artisan test --filter=NotificationDeliveryTest
php artisan test --filter=SupplierDataIsolationTest
php artisan test --filter=HashidUrlSecurityTest
```

Then:

```bash
php artisan test
```

Also execute formatting/static/security checks required by current global/workspace rules.

Report exact results.

---

# DEFINITION OF DONE

## 100. Functional Definition of Done

Implementation is complete only when all of the following are true:

- [ ] Material Progress is per award-based PO item.
- [ ] Minimal five-stage list is implemented.
- [ ] Supplier can skip forward stages.
- [ ] Supplier can move backward with required note.
- [ ] History is append-only and viewable.
- [ ] Estimated Ready Date is optional and historical.
- [ ] Progress Qty is server-derived.
- [ ] Partial Shipment correctly reduces Supplier-controlled Qty.
- [ ] Draft Shipment does not reduce Supplier-controlled Qty.
- [ ] Submitted Shipment moves its Qty to system `In Transit`.
- [ ] Remaining Qty stays manually progress-trackable.
- [ ] Zero remaining Qty disables manual update.
- [ ] Cancellation releases Qty back to authoritative remaining.
- [ ] Claim/replacement continues to use authoritative fulfillment Qty.
- [ ] Every progress update notifies Purchasing.
- [ ] Cross-supplier update is impossible.
- [ ] PO and Shipment DB statuses are not polluted with manual material stages.
- [ ] Quotation original ready/dispatch commitment is preserved.
- [ ] Current Supplier estimated ready date is separate.
- [ ] PO Target Arrival remains independent.
- [ ] Shipment Dispatch and ETA remain per physical consignment.
- [ ] Actual Arrival remains physical receiving evidence.
- [ ] Supplier and Purchasing use consistent Shipment lifecycle labels.
- [ ] Mixed item states are represented accurately.
- [ ] No old Kg arithmetic is introduced into material progress.
- [ ] Required targeted tests pass.
- [ ] Full regression suite passes or any environment limitation is explicitly reported.

---

# FINAL ARCHITECTURAL RULES

## 101. Source of Truth Matrix

```text
Question:
"What did the Supplier originally promise?"
→ quotations.estimated_delivery

Question:
"When does Purchasing want it at ADSI?"
→ purchase_orders.estimated_arrival

Question:
"What is the Supplier doing with the outstanding material now?"
→ latest po_item_progress_updates status

Question:
"When does Supplier currently expect remaining material to be ready?"
→ latest po_item_progress_updates.estimated_ready_date

Question:
"How many pieces are still under Supplier control?"
→ authoritative PO fulfillment remaining_qty

Question:
"What has physically been dispatched?"
→ submitted/arrived Shipment Items

Question:
"When was it dispatched?"
→ shipments.shipment_date after submission

Question:
"When is this physical Shipment expected at ADSI?"
→ shipments.estimated_arrival_date

Question:
"When did this Shipment actually arrive?"
→ shipments.actual_arrival_date

Question:
"How many pieces are accepted?"
→ authoritative QC/fulfillment projection

Question:
"Is the PO complete?"
→ accepted_qty >= ordered_qty
```

---

## 102. Final Domain Principle

The system should communicate one coherent procurement timeline without collapsing independent domain states.

The final conceptual model is:

```text
Supplier Original Commitment
        ↓
Purchasing Target
        ↓
Supplier Current Material Progress
        ↓
Supplier Current Ready Forecast
        ↓
Actual Shipment Dispatch
        ↓
Shipment ETA
        ↓
Actual Arrival
        ↓
QC / Claim
        ↓
Accepted Fulfillment
```

And for quantity:

```text
Ordered Qty
=
Accepted Qty
+ Active/Downstream Shipment Responsibility
+ Supplier-Controlled Remaining Qty
subject to existing claim/replacement semantics
```

Supplier Material Progress describes only the Supplier-controlled outstanding balance.

It never replaces or overrides authoritative fulfillment.

---

## 103. Final Recommendation

Implement this feature as an **item-level operational progress layer**, not as additional PO or Shipment enum values.

Use:

```text
PrItemAward
```

as the authoritative award-based PO-item identity.

Use an append-only progress history as the audit source of truth.

Use the final Qty-based Purchase Order fulfillment projection for all quantity calculations.

Keep original commitments, current forecasts, dispatch evidence, ETA, and actual arrival as separate timeline facts.

This structure provides Purchasing with meaningful operational visibility while preserving the integrity of the existing procurement, Shipment, QC, claim, authorization, and fulfillment domains.
