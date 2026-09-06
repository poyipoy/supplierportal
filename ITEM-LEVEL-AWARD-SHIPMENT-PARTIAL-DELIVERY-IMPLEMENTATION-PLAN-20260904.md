# Item-Level Award, Multi-PO Shipment & Partial Delivery
## Detailed Implementation Plan

**Project:** ADASI Portal Supplier  
**Repository:** `C:\laragon\www\adasi_portal_supplier`  
**Framework:** Laravel 12 / PHP 8.2 / MySQL 8  
**Plan Date:** 2026-09-04  
**Plan Type:** Functional domain evolution + backward-compatible implementation  
**Primary Areas:** PR Awarding, Quotations, Purchase Orders, Shipment Consolidation, Partial Delivery, Shipping Documents, Receiving, QC

---

# 1. Executive Summary

This plan evolves the procurement workflow so Purchasing can award different PR items to different suppliers, generate separate POs per supplier, and allow multiple POs from the same supplier to be consolidated into one shipment with one shared document set.

Target flow:

```text
Purchase Requisition
        ↓
Multiple Supplier Quotations
        ↓
Purchasing selects winner PER PR ITEM
        ↓
Awards grouped by Supplier
        ↓
One or more Purchase Orders
        ↓
Supplier may combine multiple same-supplier POs into one Shipment
        ↓
Shipment may contain partial quantities from each PO item
        ↓
One Invoice / Packing List / BL / Form E set per Shipment
        ↓
Receiving
        ↓
QC
        ↓
Remaining delivery quantity recalculated
```

Three concepts must remain separate:

1. **Item-level award** — one PR item is awarded entirely to one supplier; no quantity split between suppliers.
2. **PO topology** — one PO has one supplier; one PR may produce several POs.
3. **Delivery allocation** — a PO item may be delivered partially across several shipments.

---

# 2. Current Baseline to Preserve

The previous remediation result reported:

```text
355 tests passed
3,876 assertions
0 failures
```

Treat these completed changes as baseline unless this plan explicitly modifies an affected workflow:

- one quotation is protected from being attached to multiple POs;
- PO creation uses transactions and pessimistic locking;
- `all_unavailable` quotation state exists;
- safe account self-deletion is implemented;
- PO document status validation is enforced;
- MTC replacement lifecycle is hardened;
- notification ownership comparisons use defensive ID casting;
- one PO currently remains single-supplier.

Before coding, re-verify the repository. The current codebase is authoritative for actual implementation details.

---

# 3. Confirmed Business Rules

## 3.1 Award is per PR item

Example:

```text
PR-001
├── Item A
├── Item B
└── Item C

Quotation Supplier A
├── Item A → Available
├── Item B → Not Available
└── Item C → Available

Quotation Supplier B
├── Item A → Not Available
├── Item B → Available
└── Item C → Not Available
```

Purchasing may award:

```text
Item A → Supplier A
Item B → Supplier B
Item C → Supplier A
```

## 3.2 No quantity-level split award

Valid:

```text
PR Item A = 20 ton
→ Supplier A = 20 ton
```

Not supported:

```text
PR Item A = 20 ton
→ Supplier A = 12 ton
→ Supplier B = 8 ton
```

Hard invariant:

```text
ONE PR ITEM
→ MAXIMUM ONE WINNING SUPPLIER
```

## 3.3 One PR may produce multiple POs

```text
PR-001
├── Item A → Supplier A
├── Item B → Supplier B
└── Item C → Supplier A

↓ produces

PO-001 → Supplier A
├── Item A
└── Item C

PO-002 → Supplier B
└── Item B
```

## 3.4 One PO remains one supplier

Hard invariant:

```text
ONE PO
→ EXACTLY ONE SUPPLIER
```

Never create a multi-supplier PO.

## 3.5 Multi-PR same-supplier consolidation remains valid

If awarded items from different PRs belong to the same supplier, they may still be consolidated according to the existing Multi-PR PO behavior.

```text
PR-001 Item A → Supplier A
PR-002 Item D → Supplier A
PR-003 Item F → Supplier A

↓ may become

PO-001 → Supplier A
```

## 3.6 One quotation remains attached to maximum one PO

Existing invariant remains:

```text
ONE QUOTATION
→ MAXIMUM ONE PO
```

Item-level selection does not imply fragmenting one quotation into several POs.

Before a quotation is consumed into a PO, winning items from that quotation must be finalized.

## 3.7 `all_unavailable` remains authoritative

If all items in a supplier quotation are unavailable:

```text
Quotation → all_unavailable
```

It cannot supply a winning item.

Purchasing must still be able to request revision.

If one or more PR items have no available supplier, the PR remains actionable for revision/re-bid.

---

# 4. Confirmed Shipment and Delivery Rules

## 4.1 Multiple POs may be combined into one shipment

Example:

```text
PO-001 → Supplier A → 20 ton
PO-002 → Supplier A → 5 ton

↓ combined delivery

Shipment SHP-001
├── PO-001
└── PO-002
```

## 4.2 One shipment remains one supplier

Valid:

```text
PO-001 → Supplier A
PO-002 → Supplier A
→ Shipment-001
```

Invalid:

```text
PO-001 → Supplier A
PO-002 → Supplier B
→ Shipment-001 ❌
```

Hard invariant:

```text
ONE SHIPMENT
→ EXACTLY ONE SUPPLIER
```

## 4.3 Partial delivery is allowed

```text
PO-001 / Item A
Ordered = 20 ton

Shipment-001 = 8 ton
Shipment-002 = 7 ton
Shipment-003 = 5 ton
```

Result:

```text
Total = 20 ton
Remaining = 0
```

Award is not quantity-split, but delivery may be.

## 4.4 One shipment may contain partial quantities from several PO items

```text
Supplier A

PO-001 / Item A = 20 ton
PO-002 / Item C = 5 ton

Shipment SHP-001
├── PO-001 / Item A = 8 ton
└── PO-002 / Item C = 5 ton
```

Remaining:

```text
PO-001 / Item A = 12 ton
PO-002 / Item C = 0
```

## 4.5 Over-allocation is forbidden

For each PO item:

```text
allocated_quantity
=
SUM(active/finalized shipment item quantities)
```

Always:

```text
allocated_quantity <= ordered_quantity
```

This must remain true under concurrent shipment submissions.

---

# 5. Shipping Document Ownership

Invoice, Packing List, Bill of Lading, Form E, and equivalent shipping/import documents should conceptually belong to the shipment/delivery batch rather than exclusively to one PO.

Example:

```text
Shipment SHP-001
├── PO-001
├── PO-002
│
├── Invoice INV-001
├── Packing List PL-001
├── Bill of Lading BL-001
└── Form E
```

Existing PO-level documents must remain readable during migration.

Do not delete or destructively rewrite legacy document history during initial rollout.

---

# 6. Target Domain Separation

The system should explicitly distinguish:

```text
Commercial Requirement
→ PR / PR Item

Supplier Offer
→ Quotation / Quotation Item

Commercial Award
→ PR Item Award

Commercial Commitment
→ PO / PO Item

Physical Delivery
→ Shipment / Shipment Item

Document Package
→ Shipment Document

Physical Receipt
→ Receiving / Arrival

Quality Verification
→ QC
```

Do not overload quotation or PO statuses to represent all downstream logistics states.

---

# 7. Item-Level Award Model

Introduce an explicit award entity.

Recommended table:

```text
pr_item_awards
```

Conceptual fields:

```text
id
pr_item_id
quotation_id
quotation_item_id
supplier_id
awarded_by
awarded_at
purchase_order_id          nullable before PO assignment
purchase_order_item_id     nullable before PO assignment
created_at
updated_at
```

Exact names must follow the existing repository conventions.

Required constraints:

```text
UNIQUE(pr_item_id)
```

This enforces one winning supplier per PR item.

Additional validation:

- quotation item belongs to quotation;
- quotation belongs to supplier;
- quotation item corresponds to PR item;
- quotation item is available;
- quotation is award-eligible;
- quotation is not `all_unavailable`;
- PR item does not already have another winner.

Use foreign keys where practical.

---

# 8. Why Award Must Be Explicit

Do not use only:

```text
quotation.status = accepted
```

as winner truth.

After this redesign, a quotation may contain:

```text
winning items
+
non-winning items
```

Therefore quotation-level status cannot answer:

```text
Which supplier won PR Item #15?
```

Authoritative winner data must be item-level.

---

# 9. PO Item Traceability

Each new PO item must preserve traceability:

```text
PO Item
→ PR Item Award
→ Quotation Item
→ Quotation
→ PR Item
```

If current `purchase_order_items` already contains equivalent references, reuse them.

At minimum the system must be able to trace:

- PR;
- PR item;
- winning supplier;
- quotation;
- quotation item;
- awarded commercial price;
- PO.

---

# 10. Purchasing Award UI

Refactor quotation comparison from quotation-level acceptance into item-level winner selection.

For each PR item:

```text
Item A

Supplier A
Available
Price: ...
[ Select ]

Supplier B
Available
Price: ...
[ Select ]

Supplier C
Not Available
[ Disabled ]
```

Rules:

- only available items are selectable;
- `all_unavailable` offers are not selectable;
- no award quantity input exists;
- zero or one winner per PR item;
- show existing commercial comparison fields such as price, currency, lead time, and terms.

Before finalizing, show summary:

```text
PR-001

Item A → Supplier A
Item B → Supplier B
Item C → Supplier A
```

Then PO grouping preview:

```text
PO Group A → Supplier A
├── Item A
└── Item C

PO Group B → Supplier B
└── Item B
```

---

# 11. Unresolved PR Items

Purchasing may finalize only some items from a business perspective while other items remain unresolved.

Example:

```text
Item A → Supplier A
Item B → no available supplier
Item C → Supplier B
```

The system must not mark the PR completed.

Item B remains actionable for revision/re-bid.

Do not reject useful quotations simply because another supplier won a different item.

---

# 12. PO Generation From Awards

PO generation must group selected item awards by supplier.

Conceptual algorithm:

```text
selected item awards
↓
group by supplier_id
↓
create one PO per supplier group
```

Example:

```text
A → Supplier 10
B → Supplier 20
C → Supplier 10

↓ result

PO-001 → Supplier 10 → A + C
PO-002 → Supplier 20 → B
```

If current Multi-PR consolidation applies, same-supplier awarded items from multiple PRs may be grouped according to the existing feature.

---

# 13. Award + PO Transaction Strategy

Final award/PO creation must be concurrency-safe.

Recommended boundary:

```text
BEGIN TRANSACTION

lock relevant PR items
lock relevant quotation items
lock relevant quotations
reload existing awards

validate selections

create award rows
group awards by supplier

create PO(s)
create PO items
link awards to PO / PO items
link quotation(s) to the appropriate PO
update valid quotation / PR states

COMMIT
```

Use deterministic ordering:

```text
orderBy(id)
→ lockForUpdate()
```

Do not rely on mutable checks performed only before the transaction.

---

# 14. Atomicity of Multi-PO Finalization

When one Purchasing action creates several supplier POs from one PR, technical finalization should be atomic.

If:

```text
Supplier A PO succeeds
Supplier B PO fails
```

preferred behavior:

```text
rollback the finalization
```

Do not silently leave a technically half-created award unless the business explicitly supports independent finalization.

This is different from a legitimate business state where some PR items intentionally remain unresolved.

---

# 15. Quotation Status After Item-Level Award

Quotation status becomes a high-level workflow indicator, not the sole award truth.

Recommended semantics to verify against current status consumers:

```text
submitted
→ available for Purchasing review

all_unavailable
→ supplier cannot supply any item

accepted
→ at least one quotation item was awarded / quotation participates in a PO

rejected
→ no item won after relevant award decision is finalized

revision_requested
→ existing revision flow
```

Do not implement these exact transitions blindly.

First inventory all current consumers.

Critical rule:

```text
PR item award data is authoritative for winner determination.
```

---

# 16. Rejection Logic Must Become Item-Aware

The old pattern:

```text
one quotation accepted
→ reject all other quotations for same PR
```

is invalid after item-level split awarding.

Example:

```text
Supplier A wins Item A
Supplier B wins Item B
```

Both quotations are valid award contributors.

A quotation should only become fully rejected when none of its items won and the relevant award decision is finalized.

---

# 17. PR Completion Must Be Coverage-Based

For each PR item:

```text
Does a valid award exist?
```

Conceptual coverage:

```text
0 / 3 awarded
→ open

2 / 3 awarded
→ still actionable

3 / 3 awarded
→ fully awarded
```

Do not add a new persisted `partially_awarded` status unless current architecture requires it.

Prefer derived coverage where possible.

If current `completed` means procurement award completion, transition only when every required PR item is correctly awarded and satisfies the existing completion semantics.

---

# 18. Concurrent Award Protection

Because quantity-level split award is not allowed:

```text
pr_item_awards.pr_item_id
```

must be unique.

Two simultaneous requests selecting different suppliers for the same PR item must not both succeed.

Use:

```text
application lock/revalidation
+
database unique constraint
```

Return a controlled conflict message.

---

# 19. Shipment Model

Recommended table:

```text
shipments
```

Conceptual fields:

```text
id
shipment_number
supplier_id
shipment_date
estimated_arrival_date
actual_arrival_at
status
created_by
submitted_at
created_at
updated_at
```

Only add fields actually needed by current business screens.

Do not add generic ERP logistics fields without a verified requirement.

---

# 20. Shipment Item Model

Recommended table:

```text
shipment_items
```

Conceptual fields:

```text
id
shipment_id
purchase_order_item_id
shipped_quantity
created_at
updated_at
```

Foreign keys:

```text
shipment_items.shipment_id
→ shipments.id

shipment_items.purchase_order_item_id
→ purchase_order_items.id
```

A PO item may appear in multiple shipment records.

Within one shipment, duplicate allocation rows for the same PO item should be rejected or normalized safely.

---

# 21. Shipment-to-PO Relationship

A separate `shipment_purchase_orders` pivot is not required if POs can be reliably derived through:

```text
shipment
→ shipment_items
→ purchase_order_item
→ purchase_order
```

Avoid redundant relationships that may drift out of sync.

Add an explicit pivot only if current architecture or verified performance requirements justify it.

---

# 22. Delivery Quantity Calculations

Use the existing authoritative PO-item ordered quantity field.

Do not introduce a second quantity source.

Define:

```text
allocated_quantity
=
SUM(shipment item quantities
    for active/finalized shipments)
```

Then:

```text
remaining_to_allocate
=
ordered_quantity - allocated_quantity
```

Keep shipped/allocated and physically received quantities conceptually separate if current receiving/QC distinguishes them.

Do not assume:

```text
shipped = received = QC accepted
```

unless current business behavior explicitly does so.

---

# 23. Shipment Lifecycle and Reservation

Recommended conceptual behavior:

```text
DRAFT
→ editable
→ not a final reservation

SUBMITTED / CONFIRMED
→ quantities reserve PO remaining quantity

CANCELLED
→ reservation released
```

Exact status names must align with existing conventions.

Critical rule:

```text
active shipment reservations may never exceed ordered quantity.
```

---

# 24. Concurrent Shipment Protection

Race example:

```text
PO item remaining = 10

Request A = 8
Request B = 7
```

Without locking both could read 10.

Required finalization pattern:

```text
BEGIN

lock PO item
recalculate active allocated quantity
validate requested qty <= remaining
finalize shipment allocation

COMMIT
```

For multiple PO items, lock in deterministic primary-key order.

---

# 25. Shipment Validation

Server-side rules:

- shipment belongs to one supplier;
- all selected POs belong to that supplier;
- each selected PO is delivery-eligible;
- each shipment item belongs to an included supplier PO;
- shipped quantity > 0;
- shipped quantity <= current remaining quantity;
- fully allocated items cannot receive new active allocation;
- cancelled/closed PO semantics are respected;
- supplier ownership enforced;
- Hashids never replace authorization.

---

# 26. Shipment Documents

New shipping documents should be shipment-owned.

Supported types should reuse current business terminology, including:

```text
Invoice
Packing List
Bill of Lading
Form E
```

Inspect current:

- `PoDocument`;
- polymorphic attachments;
- private storage;
- attachment policies;
- document statuses.

Prefer existing attachment infrastructure.

Do not create duplicate file ownership mechanisms.

---

# 27. Legacy Document Compatibility

Preferred rollout is additive.

New deliveries:

```text
shipment_documents
→ shipment
```

Existing records:

```text
po_documents
→ remain readable
```

Do not immediately rename/delete/mass-migrate historical PO documents.

UI may temporarily show:

```text
Shipment Documents
Legacy PO Documents
```

Historical backfill should happen only after mapping is proven safe.

---

# 28. Existing Document Status Domain

Current PO document statuses include:

```text
pending
received
verified
issued
processing
done
```

Before reusing them for shipment documents, verify the exact business meaning and role transitions.

Reuse one centralized status definition only if semantics truly match.

---

# 29. Supplier Shipment Workflow

Recommended flow:

```text
Open Purchase Orders
↓
Create Shipment
↓
Select one or more same-supplier POs
↓
Select PO items
↓
Enter shipped quantity
↓
Upload shipment documents
↓
Review
↓
Submit
```

Display for each item:

```text
Ordered
Already Allocated/Shipped
Remaining
This Shipment
```

Example:

```text
PO-001 / Item A

Ordered:       20 ton
Allocated:      8 ton
Remaining:     12 ton
This Shipment:  5 ton
```

---

# 30. Purchasing Shipment View

Purchasing should be able to see:

- shipment number;
- supplier;
- shipment date;
- ETA;
- included POs;
- included PO items;
- quantity per item;
- Invoice;
- Packing List;
- BL;
- Form E;
- document status;
- receiving status;
- QC context.

Traceability should remain:

```text
Shipment
→ PO
→ PR
→ Quotation
→ PR Item
```

---

# 31. PO Delivery Progress

Do not overload commercial PO status unless necessary.

Prefer derived delivery progress such as:

```text
Not Shipped
Partially Shipped
Fully Allocated/Shipped
Partially Received
Received
```

Persist only if current architecture requires it.

During Phase 0, determine exactly what current PO status means before changing it.

---

# 32. Receiving Integration

Partial shipments invalidate the assumption:

```text
one PO → one arrival
```

New reality:

```text
one PO → many shipment arrivals
```

Receiving should operate on actual shipment context.

Recommended trace:

```text
Shipment
→ Shipment Item
→ PO Item
```

If current arrival records already exist, extend them rather than introducing a competing receiving subsystem.

---

# 33. QC Integration

Partial delivery requires independent QC context.

Example:

```text
PO-001 / Item A = 20 ton

Shipment 1 = 8 ton
→ QC #1

Shipment 2 = 12 ton
→ QC #2
```

During implementation:

1. inspect current `qc_inspections`;
2. determine current PO/arrival/item linkage;
3. add shipment/shipment-item linkage only where necessary;
4. preserve legacy QC history;
5. avoid destructive schema changes.

Do not assume shipped quantity equals QC-accepted quantity unless current QC model says so.

---

# 34. Authorization

## Supplier

May:

- view own POs;
- create shipments for own POs;
- edit own drafts;
- upload shipment documents;
- submit shipment.

Must never:

- access another supplier shipment;
- allocate another supplier PO item;
- modify Purchasing/QC-only statuses.

## Purchasing

May:

- review quotation items;
- finalize item awards;
- generate/coordinate POs;
- view shipments;
- manage applicable document/receiving actions.

## QC

May:

- view shipment items requiring QC;
- record inspections;
- access required PO/PR/material traceability.

## Admin

Preserve current oversight.

Use existing middleware/policy patterns and enforce ownership server-side.

---

# 35. Notifications, Exports, Dashboard

After the core workflow is stable, inspect:

- award notifications;
- PO issuance notifications;
- shipment submission/arrival;
- document status notifications;
- QC notifications;
- quotation exports;
- PO exports;
- delivery reports;
- dashboard counters.

Do not expand dashboard/reporting scope before core correctness is verified unless current screens would become incorrect.

---

# 36. Migration Strategy

Use additive migrations in small units.

Recommended order:

```text
Migration A
→ pr_item_awards

Migration B
→ PO item source traceability
  only if current schema lacks it

Migration C
→ shipments

Migration D
→ shipment_items

Migration E
→ shipment document ownership

Migration F
→ receiving/QC shipment linkage if required
```

Avoid one massive migration.

---

# 37. Historical Data Preflight

Before backfill, answer:

- Can every existing PO item be mapped to a PR item?
- Can every existing PO item be mapped to a quotation item?
- Are any PO items ambiguous?
- Do old PO documents represent actual deliveries or only administrative document tracking?
- Are partial arrivals already recorded anywhere?
- Does QC already imply multiple arrivals?

Produce a dry-run report.

Do not infer historical data.

---

# 38. Backfill Rules

## Award backfill

If mapping is unambiguous:

```text
PO Item
→ Quotation Item
→ PR Item
```

historical award records may be generated after review.

If ambiguous:

```text
DO NOT GUESS
```

Use compatibility behavior.

## Shipment backfill

Do not create fake historical shipment quantities from PO documents alone.

Legacy PO documents do not necessarily prove shipped quantity or delivery date.

Initial rollout may keep historical POs without reconstructed shipments.

---

# 39. Phase 0 — Repository Reconnaissance

Use:

```text
/boost
/systematic-debugging
/test-driven-development
/backend-security-coder
```

Inspect:

- `AGENTS.md`;
- routes;
- PR models/items;
- quotation models/items/controllers;
- PO models/items/controller;
- `po_quotations`;
- `po_documents`;
- attachments;
- arrival/receiving;
- QC;
- notifications;
- exports;
- policies;
- Blade views;
- migrations;
- tests.

Run:

```bash
git status
git diff
php artisan test
```

Produce an architecture map before coding:

```text
Current PR item table:
Current quotation item table:
Current PO item table:
Current PO↔quotation relation:
Current document model:
Current receiving model:
Current QC relation:
Current PO status semantics:
Current PR completion semantics:
Current attachment ownership:
Relevant existing tests:
```

---

# 40. Phase 1 — Item-Level Award Foundation

Tasks:

1. add item-level award persistence;
2. add unique PR-item winner constraint;
3. add model relationships;
4. validate availability/source consistency;
5. add transaction locking;
6. add focused tests.

Acceptance:

```text
one PR item cannot have two winners
unavailable item cannot win
all_unavailable cannot provide a winner
concurrent conflicting awards cannot both commit
```

---

# 41. Phase 2 — Purchasing Item Award UI

Tasks:

1. refactor comparison UI to item-level selection;
2. disable unavailable offers;
3. show selected winner per item;
4. show unresolved items;
5. add confirmation summary;
6. preserve Request Revision;
7. preserve conversations/history.

Acceptance:

```text
Item A → Supplier A
Item B → Supplier B
Item C → Supplier A
```

can be selected without quantity split.

---

# 42. Phase 3 — PO Generation

Tasks:

1. consume item awards;
2. group awards by supplier;
3. create separate POs;
4. preserve same-supplier Multi-PR consolidation;
5. create traceable PO items;
6. preserve quotation→PO uniqueness;
7. update quotation status semantics safely;
8. make finalization atomic.

Acceptance:

```text
1 PR
2 winning suppliers
→ 2 POs
```

No multi-supplier PO.

---

# 43. Phase 4 — PR Coverage and Completion

Tasks:

1. make completion item-coverage aware;
2. keep unresolved items actionable;
3. preserve all-unavailable revision/re-bid;
4. remove quotation-level blanket rejection assumptions;
5. expose award coverage.

Acceptance:

```text
2 / 3 awarded → not completed
3 / 3 awarded → eligible for completion
```

according to current PR lifecycle semantics.

---

# 44. Phase 5 — Shipment Foundation

Tasks:

1. create shipments;
2. create shipment items;
3. add supplier ownership;
4. derive PO-item remaining quantity;
5. enforce same-supplier multi-PO rule;
6. implement transaction-safe finalization;
7. add policies/routes/controllers;
8. add supplier shipment UI.

Acceptance:

```text
Shipment
├── PO-001 / Item A = partial
└── PO-002 / Item C = partial/full
```

works when both POs belong to one supplier.

---

# 45. Phase 6 — Partial Delivery

Tasks:

1. show ordered/allocated/remaining;
2. support many shipments per PO item;
3. block over-allocation;
4. add concurrency locks;
5. implement draft/edit/cancel semantics;
6. derive delivery progress.

Acceptance:

```text
Ordered = 20
Shipment 1 = 8
Shipment 2 = 7
Remaining = 5
```

A new shipment of `6` must fail.

---

# 46. Phase 7 — Shipment Documents

Tasks:

1. add shipment-level document ownership;
2. reuse attachment infrastructure;
3. support Invoice/Packing List/BL/Form E;
4. preserve status validation;
5. preserve legacy PO documents;
6. update policies and views.

Acceptance:

One shipment containing several same-supplier POs can use one shared document set.

---

# 47. Phase 8 — Receiving

Tasks:

1. inspect current arrival flow;
2. connect it to shipment context;
3. preserve PO traceability;
4. allow repeated arrivals for one PO;
5. add regression tests.

Acceptance:

```text
PO-001
→ Shipment 1 received
→ Shipment 2 received later
```

without first receipt falsely completing the whole PO.

---

# 48. Phase 9 — QC

Tasks:

1. add shipment context to QC where required;
2. preserve historical QC;
3. support independent QC per partial delivery;
4. preserve role and supplier isolation;
5. update QC tests/views.

Acceptance:

Two shipments for the same PO item may have two distinct QC events.

---

# 49. Phase 10 — Reporting and Compatibility

After core flow passes:

1. update notifications;
2. update exports;
3. update dashboard;
4. update filters/search;
5. verify legacy documents;
6. verify old QC records;
7. evaluate safe backfill;
8. retain compatibility code until migration confidence exists.

---

# 50. Testing Matrix

## Item award

- one item → one supplier;
- several PR items → different suppliers;
- unavailable item rejected;
- all-unavailable quotation cannot win;
- duplicate award rejected;
- concurrent duplicate award rejected;
- revision still works.

## PO generation

- 1 PR → 1 supplier → 1 PO;
- 1 PR → 2 suppliers → 2 POs;
- 1 PR → 3 suppliers → 3 POs;
- same supplier wins several items → grouped;
- same supplier across several PRs → consolidation preserved;
- different suppliers never share one PO;
- quotation cannot attach to several POs;
- rollback remains atomic;
- document numbering remains atomic.

## PR completion

- 0% awarded;
- partial coverage;
- full coverage;
- unresolved unavailable item;
- revision/re-bid path.

## Shipment

- single PO shipment;
- multiple same-supplier POs;
- different supplier blocked;
- partial quantity;
- multiple shipments for same PO item;
- exact remaining accepted;
- over-allocation rejected;
- duplicate shipment item rejected;
- cancelled shipment releases reservation;
- unauthorized supplier blocked.

## Concurrent shipment

Example:

```text
remaining = 10
Request A = 8
Request B = 7
```

Assert final active allocation never exceeds 10.

Do not claim concurrency verification if only serial tests were executed.

## Documents

- one invoice shared by multi-PO shipment;
- document visible through shipment history;
- legacy PO document remains accessible;
- ownership enforced;
- invalid status rejected;
- attachment replacement lifecycle safe.

## Receiving / QC

- same PO item arrives in two shipments;
- each delivery retains separate receiving/QC context;
- legacy QC remains readable;
- no cross-supplier access.

---

# 51. Performance and Transaction Rules

Avoid N+1 on:

- quotation comparison;
- award summaries;
- shipment creation;
- shipment history;
- delivery progress.

Prefer grouped aggregates for shipment quantities.

For multi-row writes:

```text
sort IDs
→ lock deterministically
```

Apply to:

- PR items;
- quotation items;
- quotations;
- PO items.

Do not perform slow file uploads/external calls while holding database locks.

---

# 52. File Handling

Follow existing private-storage conventions.

Safe lifecycle:

```text
validate
↓
store new file
↓
write DB metadata
↓
commit
↓
cleanup superseded file after successful commit
```

Do not delete a valid existing file before successful DB completion.

---

# 53. Error Handling

Return controlled business messages such as:

```text
This PR item has already been awarded.

The selected quotation item is no longer available.

One of the selected POs belongs to another supplier.

Shipment quantity exceeds the remaining PO quantity.

This PO item is already fully allocated.

The underlying record changed. Refresh and try again.
```

Do not expose raw SQL/filesystem errors.

---

# 54. Auditability

Preserve traceability of:

- who awarded a PR item;
- when it was awarded;
- winning quotation item;
- generated PO/PO item;
- who created/submitted shipment;
- shipment document history where current architecture supports it;
- receiving/QC history.

Do not overwrite historical commercial source data unnecessarily.

---

# 55. Rollout Strategy

Recommended sequence:

```text
Stage 1
Additive DB migrations

Stage 2
Item award backend + tests

Stage 3
Purchasing item-award UI + PO generation

Stage 4
Shipment backend + supplier UI

Stage 5
Shipment documents

Stage 6
Receiving + QC

Stage 7
Notifications / exports / dashboards

Stage 8
Legacy compatibility validation
```

Avoid a single uncontrolled big-bang change.

---

# 56. Out of Scope

Do not implement unless separately approved:

- quantity-level split award across suppliers;
- multi-supplier PO;
- multi-supplier shipment;
- automatic supplier selection;
- freight cost allocation engine;
- container optimization;
- ERP/accounting integration redesign;
- historical shipment reconstruction without reliable evidence.

---

# 57. Hard Invariants

Final implementation must enforce:

```text
1 PR item
→ max 1 winning supplier

1 PO
→ exactly 1 supplier

1 quotation
→ max 1 PO

1 shipment
→ exactly 1 supplier

1 shipment
→ may include many POs of that supplier

1 PO item
→ may appear in many shipments

SUM(active shipment quantities)
→ never exceeds PO item ordered quantity
```

---

# 58. Definition of Done — Award

- item-level winner is persisted;
- one PR item cannot have two winners;
- unavailable item cannot win;
- split-by-item works;
- quantity split at award level is impossible;
- concurrency safety exists;
- quotation status is no longer sole winner truth.

---

# 59. Definition of Done — PO

- one PR can create multiple supplier POs;
- every PO has one supplier;
- every PO item traces to award/quotation item;
- Multi-PR same-supplier consolidation still works;
- one quotation cannot belong to several POs;
- finalization is atomic;
- PR completion uses item coverage.

---

# 60. Definition of Done — Shipment

- one shipment may combine several same-supplier POs;
- different suppliers cannot be mixed;
- partial delivery works;
- one PO item may appear in several shipments;
- over-allocation is impossible;
- concurrent finalization cannot exceed remaining;
- supplier isolation is preserved.

---

# 61. Definition of Done — Documents

- new shipping documents are shipment-owned;
- one shipment document set may represent several POs;
- legacy PO documents remain accessible;
- authorization remains correct;
- file lifecycle remains safe.

---

# 62. Definition of Done — Receiving/QC

- repeated partial arrivals are distinguishable;
- receiving no longer assumes one arrival per PO;
- QC can identify the delivery/shipment inspected;
- separate partial deliveries may have separate QC;
- historical records remain readable.

---

# 63. Final Verification Gates

After implementation:

```text
/find-bugs
```

Review only the implementation diff and direct interactions.

Then:

```text
/laravel-security-audit
```

Focus on:

- authorization;
- supplier isolation;
- validation;
- transactions;
- locking;
- data integrity;
- attachment access;
- mass assignment;
- migration safety.

Full regression:

```bash
php artisan test
```

Do not declare production readiness until all final gates are complete.

---

# 64. Recommended Skills

Primary:

```text
/boost
/systematic-debugging
/test-driven-development
/backend-security-coder
```

Optional for ambiguous implementation issues:

```text
/bug-hunt-swarm
```

Final review:

```text
/find-bugs
/laravel-security-audit
```

---

# 65. Final Target Workflow

```text
PR-001
├── Item A
├── Item B
└── Item C
        ↓

Supplier Quotations
        ↓

Purchasing Item-Level Award
├── Item A → Supplier A
├── Item B → Supplier B
└── Item C → Supplier A
        ↓

Purchase Orders
├── PO-001 → Supplier A → Item A + Item C
└── PO-002 → Supplier B → Item B
        ↓

Supplier A may also have:
PO-003 → Supplier A
        ↓

Shipment SHP-001 → Supplier A
├── PO-001 / Item A → partial qty
├── PO-001 / Item C → partial/full qty
└── PO-003 / Item X → partial/full qty
        │
        ├── Invoice
        ├── Packing List
        ├── Bill of Lading
        └── Form E
        ↓

Receiving
        ↓
QC
        ↓

Remaining PO quantities recalculated
        ↓

Additional shipment(s) until fulfilled
```

This is the target domain behavior for the implementation.
