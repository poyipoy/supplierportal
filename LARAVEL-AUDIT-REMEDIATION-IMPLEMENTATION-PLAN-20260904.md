# Laravel Audit Remediation Implementation Plan
## Item-Level Award, Multi-PO Shipment Consolidation & Partial Delivery

**Project:** ADASI Portal Supplier  
**Workspace:** `C:\laragon\www\adasi_portal_supplier`  
**Framework:** Laravel 12 / PHP 8.2 / MySQL 8.0  
**Plan Date:** 2026-09-04  
**Plan Type:** Release-blocker remediation + Laravel integrity hardening  
**Primary Source:** `FINAL-LARAVEL-REVIEW-ITEM-LEVEL-AWARD-SHIPMENT-20260904.md`  
**Current Laravel Audit Verdict:** `FAIL — ACTION REQUIRED`

---

# 1. Executive Summary

The current implementation successfully introduced item-level awards, supplier-grouped PO generation, shipment consolidation, partial delivery, shipment documents, receiving, and shipment-aware QC. The previous second-pass `/find-bugs` review also closed seven earlier findings.

The final Laravel integrity review identified eight distinct release blockers that remain in the current source:

1. active legacy PO creation can bypass item-level awards;
2. shipment-aware QC can still fall back to legacy/null-shipment handling or certify incomplete/non-arrived shipment contents;
3. QC-NG quantities remain allocated and can permanently block replacement fulfillment;
4. award saving and PO generation do not share one complete atomic finalization boundary, and mutable quotation state is not fully protected by locks;
5. an exact `0.0001` shipment over-allocation is accepted;
6. claim and PO state transitions are not transactionally coherent;
7. shipment-document attachment authorization, persistence, and version handling remain unsafe;
8. shipment edit/update/destroy routes are registered through the resource route while the controller does not implement the required actions.

The implementation objective is to close all eight blockers without regressing the previously verified business rules, supplier isolation, legacy read compatibility, Multi-PR consolidation, quotation uniqueness, shipment allocation safeguards, or existing test coverage.

This plan also includes the audit's non-blocking hardening and release-control observations as a separate post-blocker phase.

---

# 2. Authoritative Business and Data Invariants

## 2.1 Item-Level Award

```text
1 PR Item
→ maximum 1 winning supplier
```

Award selection is not quantity-split.

```text
PR Item = 20 ton
→ Supplier A = 20 ton
```

Not supported:

```text
12 ton → Supplier A
8 ton  → Supplier B
```

Item-level award records remain the authoritative source for newly created procurement decisions.

## 2.2 Purchase Order

```text
1 PO
→ exactly 1 supplier
```

A PR may generate multiple POs when different PR items are awarded to different suppliers.

Same-supplier Multi-PR consolidation remains valid.

Existing invariant:

```text
1 Quotation
→ maximum 1 PO
```

must remain enforced.

## 2.3 Shipment

```text
1 Shipment
→ exactly 1 supplier
```

A shipment may contain multiple POs only when all POs belong to the same supplier.

For new item-level-award POs:

```text
Shipment
→ PurchaseOrder
→ QuotationItem
→ PrItemAward
→ PR Item
```

must remain internally consistent.

## 2.4 Partial Delivery

Delivery may be partial:

```text
PO Item ordered = 20
Shipment 1 = 8
Shipment 2 = 7
Shipment 3 = 5
```

The system must maintain:

```text
effective allocatable fulfillment
<= ordered quantity
```

while still allowing replacement delivery after rejected/NG quantities.

## 2.5 QC and Claims

QC is shipment-aware for shipment-based POs.

Physical delivery history, accepted fulfillment, NG/rejected quantity, and replacement eligibility are distinct concepts.

A resolved claim must not prematurely complete a partially fulfilled PO.

## 2.6 Legacy Compatibility

Legacy records without item awards, shipments, or shipment-scoped QC remain readable.

Legacy compatibility is a read/backward-compatibility concern.

It must not remain an active write path that bypasses the new item-level award workflow.

---

# 3. Current Verified Baseline

Before remediation, preserve the following verified state:

- prior second-pass `/find-bugs`: `PASS`;
- previous full regression result: `399 tests passed`, `4,089 assertions`, `0 failures`, `0 errors`, `0 skipped`;
- supplier shipment isolation verified;
- Hashid handling verified;
- `pr_item_awards.pr_item_id` uniqueness verified;
- `po_quotations.quotation_id` uniqueness verified;
- `shipment_items` composite uniqueness verified;
- draft/cancelled shipment allocations excluded;
- submitted/arrived allocations included;
- soft-deleted shipments excluded;
- legacy PO read compatibility preserved;
- shipment notification URL ownership preserved.

The final Laravel audit itself reran a focused subset of 66 tests / 351 assertions successfully, but did not rerun the full suite.

---

# 4. Remediation Strategy and Dependency Order

Recommended implementation order:

```text
Phase 0  — Recovery / Baseline
Phase 1  — Disable Active Legacy PO Write Bypass
Phase 2  — Strict Shipment-Aware QC
Phase 3  — Per-Line Accepted / NG / Replacement Fulfillment
Phase 4  — Atomic Award + PO Finalization
Phase 5  — Centralized PO / Claim State Reconciliation
Phase 6  — Shipment Document Lifecycle
Phase 7  — Exact 4-Decimal Quantity Enforcement
Phase 8  — Draft Shipment Edit / Update + Route Cleanup
Phase 9  — Hardening & Release-Control Cleanup
Phase 10 — Integrated Regression & Final Quality Gates
```

Dependency rationale:

- Phase 1 establishes the only valid write path for new POs.
- Phase 2 establishes exact shipment-scoped QC data.
- Phase 3 depends on trustworthy QC item linkage.
- Phase 5 depends on the authoritative fulfillment semantics from Phase 3.
- Phase 4 must be regression-tested against Phase 1.
- Phase 6 and Phase 7 are orthogonal but must be completed before final regression.
- Phase 8 closes the route/controller contract gap and must reuse the hardened shipment service.

---

# 5. Phase 0 — Recovery, Repository State, and Baseline

## Objectives

Establish the exact current worktree and avoid overwriting previously completed fixes.

## Required Actions

Run:

```bash
git status
git diff
git diff --stat
php artisan migrate:status
```

Inspect:

- `AGENTS.md`;
- current routes;
- controller/service/model implementations listed in the Laravel audit;
- current migrations;
- current local schema;
- current tests;
- current untracked files.

Record:

```text
Current modified files:
Current untracked files:
Current applied migrations:
Current shipment_items schema:
Current document_sequences enum:
Current full-suite baseline evidence:
```

The audit reported 79 modified/untracked entries and noted that many feature files remain untracked. Before eventual release, ensure the deployment artifact includes every intended file.

## Deliverable

Produce:

| Blocker | Current Code State | Files | Existing Tests | Planned Change | Status |
|---|---|---|---|---|---|

Status:

```text
NOT STARTED
IN PROGRESS
IMPLEMENTED
VERIFIED
BLOCKED
DEFERRED
```

---

# 6. Phase 1 — Disable Active Legacy PO Creation Bypass

## Audit Finding

Current active legacy flow:

```text
Purchasing quotation page
→ Create PO from Quotation
→ PurchaseOrderController::store()
→ PO created without PrItemAward
→ quotation accepted
→ competing quotations rejected
→ PR completed
```

This violates the current item-level award model.

## Relevant Locations

Audit references:

- `routes/web.php:159-160`
- `app/Http/Controllers/Purchasing/PurchaseOrderController.php:260-385`
- `app/Http/Controllers/Purchasing/QuotationListController.php:123-129`
- `resources/views/purchasing/quotations/show.blade.php:338-342`

## Target Behavior

New PO creation must require finalized item-level awards.

Valid:

```text
PR Item Award(s)
→ PurchaseOrderGenerationService
→ supplier-grouped PO(s)
```

Invalid new write:

```text
Quotation IDs
→ direct PO without awards
```

must be rejected or routed through award-aware logic.

## Implementation Tasks

1. Inventory every active PO creation route.
2. Identify all call sites of `PurchaseOrderController::store`.
3. Identify UI controls still exposing quotation-level PO creation.
4. Remove/redirect the active legacy UI action.
5. Add a server-side guard so direct POST cannot bypass awards.
6. Route valid generation through `PurchaseOrderGenerationService`.
7. Preserve same-supplier Multi-PR consolidation.
8. Preserve historical legacy PO read/display behavior.
9. Preserve `po_quotations.quotation_id` uniqueness.
10. Preserve item-aware PR completion/rejection semantics.

## Required Tests

- direct legacy POST without awards → rejected;
- legacy UI action no longer creates direct PO;
- valid award-based generation succeeds;
- same-supplier Multi-PR consolidation still passes;
- new PO has award traceability;
- historical legacy PO remains readable.

## Definition of Done

- no active new PO write can bypass item awards;
- crafted requests cannot bypass the guard;
- legacy records remain readable;
- PR completion remains item-coverage-based.

---

# 7. Phase 2 — Strict Shipment-Aware QC

## Audit Finding

Shipment-aware QC can currently be bypassed by:

- omitting `shipment_id`;
- selecting a `draft` or `submitted` shipment;
- submitting only a subset of shipment lines;
- duplicating lines;
- submitting items absent from the selected shipment;
- allowing `shipment_item_id = null`.

## Relevant Location

- `app/Http/Controllers/Qc/QcInspectionController.php:152-305`

## Target Rule

If a PO has shipment items:

```text
shipment_id
→ REQUIRED
```

If a PO has no shipment items:

```text
shipment_id = null
→ allowed only for genuine legacy QC
```

Shipment must be:

```text
status = arrived
```

and must contain items for the inspected PO.

## Exact Line Coverage

Expected lines must be derived server-side:

```text
ShipmentItems
where shipment_id = selected shipment
and purchase_order_id = inspected PO
```

Require:

- every expected line exactly once;
- no omissions;
- no duplicates;
- no foreign line;
- every shipment-aware `QcItem` has non-null `shipment_item_id`.

## Transaction/Locking

Inside one transaction:

1. lock PO;
2. lock/reload shipment;
3. require shipment `arrived`;
4. derive expected shipment items;
5. block duplicate inspection;
6. validate exact payload;
7. persist inspection/QC items;
8. recalculate PO status through centralized state logic.

Avoid file I/O while holding broad DB locks where possible.

## Multi-PO Shipment

A consolidated shipment containing PO-A and PO-B must allow separate shipment-scoped inspections for each PO without cross-linking lines.

## Required Tests

- shipment-aware PO + missing `shipment_id` rejected;
- draft shipment QC rejected;
- submitted/non-arrived shipment QC rejected;
- wrong shipment rejected;
- omitted expected line rejected;
- duplicate QC line rejected;
- same-PO item absent from shipment rejected;
- cross-PO/cross-supplier line rejected;
- shipment-aware QC cannot persist null `shipment_item_id`;
- valid Multi-PO shipment QC remains supported.

## Definition of Done

Shipment-aware QC cannot be processed as legacy QC and exact physical line membership is guaranteed server-side.

---

# 8. Phase 3 — Per-Line Accepted, NG, and Replacement Fulfillment

## Audit Finding

Current allocation can deadlock replacement delivery:

```text
Ordered = 20
Shipment 1 = 5 → NG
Shipment 2 = 15 → OK

physical allocation = 20
accepted fulfillment = 15
remaining allocation = 0
```

Replacement 5 cannot be submitted.

The current overall inspection status can also cross-contaminate mixed OK/NG lines.

## Domain Separation

Calculate separately:

```text
ordered quantity
physical shipped quantity
physical arrived quantity
QC-accepted quantity
QC-NG quantity
replacement-eligible quantity
currently reserved quantity
remaining accepted fulfillment
```

Prefer authoritative derived calculations unless persistence is required by current architecture.

## Per-Line Source of Truth

Use:

```text
ShipmentItem
↔ QcItem
```

not only overall `QcInspection.status`.

For:

```text
Shipment
├── Item A → OK
└── Item B → NG
```

expected:

```text
Item A → counts toward accepted fulfillment
Item B → does not count; remains replacement-required
```

## Replacement Semantics

Do not delete original NG shipment/QC history.

After claim resolution, rejected quantity must become replacement-eligible while the physical arrival remains historical.

## Centralized Fulfillment Calculation

Create/reuse one domain calculator/helper for:

- ordered quantity;
- accepted quantity;
- reserved quantity;
- NG/rejected quantity;
- replacement eligibility;
- remaining shipment quantity;
- fully fulfilled and inspected.

Reuse it from:

- `ShipmentService`;
- `PurchaseOrder`;
- QC flow;
- claim resolution.

Do not duplicate formulas.

## Conceptual Remaining Formula

```text
remaining commercial fulfillment
=
ordered
-
accepted
-
valid outstanding reservation
```

Rejected/NG quantity whose claim disposition permits replacement must not permanently consume the commercial fulfillment ceiling.

## Required Tests

### Partial NG + replacement

```text
Ordered = 20
Shipment 1 = 5 → NG
claim resolved
Shipment 2 = 15 → OK
Replacement Shipment 3 = 5 → allowed
QC Shipment 3 = OK
→ PO completed
```

### Mixed shipment

```text
Shipment
├── Item A = 10 → OK
└── Item B = 5  → NG
```

Assert independent accepted/replacement quantities.

### No double counting

One `ShipmentItem/QcItem` must not count twice toward accepted fulfillment.

## Definition of Done

- fulfillment is per line;
- NG quantity does not count as accepted;
- mixed OK/NG works;
- replacement is possible after resolved NG;
- physical audit history remains;
- completion only occurs when accepted fulfillment reaches ordered quantity.

---

# 9. Phase 4 — Atomic Award + PO Finalization

## Audit Finding

Current `generate_pos` may commit awards before PO generation starts.

If PO generation fails, awards can remain committed.

Quotation eligibility is also not fully protected by locking.

## Relevant Locations

- `app/Http/Controllers/Purchasing/PriceComparisonController.php:1261-1310`
- `app/Services/PrItemAwardService.php:146-245`
- `app/Services/PurchaseOrderGenerationService.php:80-191`
- `app/Http/Controllers/Purchasing/QuotationListController.php:150-266`

## Target Atomic Operation

For `action=generate_pos`:

```text
save awards
+ generate supplier-grouped POs
+ attach quotations
+ link awards
+ update PR/quotation states
= one business transaction
```

## Transaction Boundary

Preferred:

```text
BEGIN

lock PR(s)
lock PR items
lock quotations
lock quotation items
reload/revalidate awards

save awards
generate POs
attach quotations
link awards
recalculate PR/quotation state

COMMIT
```

Use deterministic ID ordering.

## Quotation Eligibility

Only:

```text
submitted
accepted
```

may finalize.

Re-read status while quotation rows are locked.

Do not rely on stale eager-loaded relation state.

## Quotation Mutation Paths

Review rejection/revision state mutation so those paths use compatible locked/revalidated transitions.

Do not redesign unrelated quotation workflow.

## Failure Behavior

For `generate_pos` failure:

```text
new awards from that finalization
→ rollback
```

Separate `action=save` behavior may remain intentionally persistent if already approved.

## Required Tests

- successful combined finalization;
- forced PO generation failure → awards rolled back;
- stale/ineligible quotation state → finalization rejected;
- quotation eligibility revalidated under transaction.

Do not call serial tests real concurrency.

## Definition of Done

Award + PO generation is atomic for finalization and mutable eligibility is protected by the same locking boundary.

---

# 10. Phase 5 — Centralized PO / Claim State Reconciliation

## Audit Finding

Current PO status has multiple independent writers:

```text
shipment arrival
QC
claim response
claim resolution
```

The audit found:

- resolved claim can become `responded` again;
- arrival can overwrite `claim_needed`;
- QC OK can overwrite claim state;
- claim and PO updates are not atomic.

## State Precedence

At minimum:

```text
if active claim exists
→ claim_needed

else if arrived shipment awaits QC
→ waiting_qc

else if accepted fulfillment is complete
→ completed

else
→ active
```

Preserve other verified existing states where required.

## Centralized Reconciliation

Create/reuse one authoritative PO operational status method/service.

All relevant flows call it rather than directly competing to set status.

## Claim Transition Rules

Define legal source-status transitions.

At minimum:

```text
resolved
→ cannot become responded
```

Lock claim + PO in one transaction for response/resolution.

Multiple active claims must be evaluated consistently.

## Arrival Rule

Arrival records physical arrival but must not override `claim_needed` when an active claim exists.

## QC Rule

QC OK cannot move the PO out of `claim_needed` if another active claim exists.

## Required Tests

- response after resolved claim rejected;
- arrival while active claim → PO remains `claim_needed`;
- QC OK while another active claim → PO remains `claim_needed`;
- multiple active claims;
- last claim resolved + QC pending → `waiting_qc`;
- last claim resolved + incomplete fulfillment → `active`;
- last claim resolved + full accepted fulfillment → `completed`.

## Definition of Done

All operational status changes follow one state-reconciliation model and claim/PO writes are transactionally coherent.

---

# 11. Phase 6 — Shipment Document Attachment Lifecycle

## Audit Finding

The final Laravel review identified:

1. owner supplier cannot open own shipment-document attachment;
2. failed physical `put()` may still be followed by DB persistence;
3. DB failure after physical write may orphan a file;
4. re-upload after `verified`/`done` does not force re-review;
5. Supplier and Purchasing may resolve different attachment versions.

## Relevant Locations

- `app/Policies/AttachmentPolicy.php:21-68`
- `app/Http/Controllers/AttachmentController.php:11-39`
- `app/Services/ShipmentService.php:481-514`
- `config/filesystems.php:41-45`
- `resources/views/purchasing/shipments/show.blade.php:171-196`
- `resources/views/supplier/shipments/show.blade.php:150-179`

## Authorization Rule

Supplier may access an attachment only when:

```text
Attachment
→ ShipmentDocument
→ Shipment.supplier_id
=
authenticated supplier
```

Other suppliers remain denied.

## Storage Integrity

Check the result of:

```php
Storage::disk('private')->put(...)
```

If it fails:

```text
no Attachment DB row
```

## Persistence Compensation

If file write succeeds but DB persistence fails:

```text
delete only the newly written file
```

Do not remove historical versions.

## Version History

Historical versions remain preserved.

Define one deterministic current version, for example latest by `created_at` + `id`, and reuse the same relation/accessor for Supplier and Purchasing views.

## Verification Reset

A replacement upload after `verified` or `done` must return the document to the appropriate review-required state. Reuse the existing post-upload state semantics rather than inventing a new status.

## File I/O Discipline

Avoid long DB locks during physical file I/O.

Recommended:

```text
authorize + validate
→ write new file
→ short DB transaction
→ persist attachment + reset document status
→ commit
→ on DB failure delete only new file
```

## Required Tests

- owner supplier can download;
- other supplier denied;
- failed storage write creates no DB row;
- DB failure compensates only new file;
- re-upload verified document resets review state;
- re-upload done document resets review state;
- history remains;
- deterministic latest attachment used consistently by both roles.

## Definition of Done

Document ownership, persistence, versioning, and review status are coherent and auditable.

---

# 12. Phase 7 — Exact Four-Decimal Quantity Enforcement

## Audit Finding

Current comparison:

```php
$shippedQty > $remainingQty + 0.0001
```

allows:

```text
remaining = 1.0000
requested = 1.0001
```

## Actual Scale

Audit reports live schema:

```text
DECIMAL(12,4) NOT NULL
```

The implementation must therefore enforce a maximum of four decimal places.

## Validation

Accept valid 4dp quantities and reject:

```text
0
negative
more than 4 decimal places
```

## Exact Comparison

Do not use binary float epsilon.

Use exact fixed-scale normalization, e.g.:

```text
1.0000 → 10000
1.0001 → 10001
```

or equivalent decimal-string logic.

Avoid introducing a dependency only for this calculation unless the existing stack already uses one.

## Required Tests

```text
remaining 1.0000 / requested 1.0000 → accepted
remaining 1.0000 / requested 1.0001 → rejected
requested 1.00001 → rejected
zero → rejected
negative → rejected
exact 4dp accumulation → accepted
sum exceeding by 0.0001 → rejected
```

## Definition of Done

No quantity exceeds the four-decimal ceiling by one unit of precision and malformed precision never reaches persistence.

---

# 13. Phase 8 — Draft Shipment Edit / Update and Route Cleanup

## Audit Finding

`Route::resource('shipments', SupplierShipmentController::class)` registers `edit`, `update`, and `destroy`, but the controller does not implement those actions.

The approved workflow says draft shipments are editable.

## Target Routes

Support valid draft flows:

```text
index
create
store
show
edit
update
```

Do not expose physical destroy.

Use explicit cancellation for shipment cancellation.

## `edit`

- supplier owner only;
- status must be `draft`;
- cross-supplier denied.

## `update`

- supplier owner only;
- status still `draft`;
- reuse `ShipmentService` validation;
- reuse source consistency and duplicate-line checks;
- validate dates/notes;
- submitted/arrived/cancelled shipments cannot be updated.

## Route Cleanup

Restrict the resource to implemented actions via `only(...)` or equivalent.

Remove unsupported `destroy`.

## Required Tests

- owner can edit own draft;
- owner can update own draft;
- other supplier denied;
- submitted shipment edit/update blocked;
- arrived blocked;
- cancelled blocked;
- destroy route absent;
- update reuses source/duplicate/quantity validation.

## Definition of Done

Every registered route maps to a valid controller action and draft editing matches the approved lifecycle.

---

# 14. Phase 9 — Hardening and Release-Control Cleanup

These items are not the eight primary blockers, but they were explicitly identified by the final Laravel review.

## 14.1 Mass Assignment

Review `$fillable`/`$guarded` in:

- `PrItemAward`;
- `Shipment`;
- `ShipmentItem`;
- `ShipmentDocument`.

Pay special attention to:

```text
supplier_id
created_by
awarded_by
purchase_order_id
pr_item_award_id
status
actual_arrival_date
submitted_at
```

Keep authoritative writes service-mediated. Do not pass raw request arrays directly to models.

## 14.2 Database Constraints

Evaluate:

```text
CHECK (shipped_quantity > 0)
UNIQUE(shipment_id, doc_type)
stronger award-reference protection
```

First inspect existing data and legacy-null requirements.

Do not add constraints that break genuine legacy records.

## 14.3 HTTP Validation Boundaries

Add focused Laravel validation/Form Requests where useful for:

- nested shipment item arrays;
- dates;
- notes;
- quantity format and scale;
- award nested structure;
- arrival date;
- document type/status;
- action values.

Service-level domain validation remains authoritative.

Avoid exposing raw DB exception details.

## 14.4 Schema/Documentation Discrepancy

Current report discrepancy:

```text
previous documentation: DECIMAL(14,4) UNSIGNED
live/current:           DECIMAL(12,4) NOT NULL
```

Determine whether the current range is sufficient before changing schema.

Do not change schema merely to match documentation.

Update final documentation to reflect the actual final schema.

## 14.5 Git Packaging

Before release:

```bash
git status
git diff --stat
git ls-files --others --exclude-standard
```

Ensure intended:

- migrations;
- services;
- models;
- controllers;
- policies;
- routes;
- tests;

are included.

Exclude scratch/debug/secrets.

---

# 15. Migration Strategy

The audit reports both feature migrations are already applied locally:

```text
2026_09_04_000001_create_pr_item_awards_table → Ran
2026_09_04_000002_create_shipments_tables     → Ran
```

Both were untracked at audit time.

Deployment to shared environments was not verified.

Rules:

1. verify deployment state before editing applied migration history;
2. if an earlier migration version may exist in a shared environment, use a new forward migration;
3. only edit in-place when the environment is definitively local/disposable and not shared.

Potential forward migration needs may include:

- positive quantity CHECK;
- unique `(shipment_id, doc_type)`;
- schema hardening required by the chosen final implementation.

Do not introduce new persistence merely when authoritative state can be derived safely from existing `ShipmentItem` and `QcItem` relations.

---

# 16. Transaction and Locking Plan

Audit classification:

| Path | Current | Target |
|---|---|---|
| Shipment submission/allocation | SOUND | Preserve |
| Shipment cancellation | SOUND | Preserve |
| Award + PO generation | NEEDS HARDENING | Atomic finalization |
| Arrival confirmation | NEEDS HARDENING | Lock/reconcile related POs |
| QC store | INCORRECT | Shipment + exact line validation under lock |
| Claim response/resolution | INCORRECT | Locked atomic transitions |

Use deterministic lock ordering.

Do not mechanically lock every related table in every transaction.

Lock only mutable rows required for the specific business operation.

Avoid physical file operations while holding unrelated procurement locks.

Terminology:

```text
deterministic lock ordering reduces deadlock risk
```

Do not claim it makes deadlocks impossible.

---

# 17. Integrated Test Matrix

## PO Creation

- direct legacy write rejected;
- valid award generation succeeds;
- Multi-PR consolidation preserved;
- quotation uniqueness preserved;
- combined failure rollback.

## QC

- shipment ID mandatory for shipment-aware PO;
- null shipment only for genuine legacy PO;
- draft/submitted shipment rejected;
- exact shipment line coverage;
- duplicate/omitted/foreign line rejection;
- valid Multi-PO QC.

## NG / Replacement

- partial NG then replacement;
- mixed OK/NG same shipment;
- accepted quantity per item;
- final completion only after accepted fulfillment.

## Award Finalization

- success atomic;
- forced PO failure rolls awards back;
- stale quotation status rejected.

## Claim / PO State

- resolved claim cannot respond again;
- active claim survives arrival;
- active claim survives other QC OK;
- multiple claims;
- correct final reconciliation.

## Documents

- owner download;
- cross-supplier denial;
- failed storage;
- DB failure compensation;
- re-upload review reset;
- deterministic latest version;
- history preserved.

## Quantity

- exact 4dp boundary;
- >4 decimals rejected;
- zero/negative rejected;
- exact accumulation.

## Draft Editing

- owner draft edit/update;
- non-owner denied;
- non-draft blocked;
- destroy route absent.

## Legacy Regression

- old PO display;
- old PO documents;
- legacy arrival;
- legacy QC;
- legacy claims;
- exports;
- notification URLs.

---

# 18. Verification Protocol

For every phase:

1. inspect current source;
2. reproduce/prove the problem;
3. add/update regression test;
4. implement the smallest safe correction;
5. run `php -l` on changed PHP;
6. run targeted tests;
7. run adjacent subsystem tests;
8. inspect `git diff`;
9. mark `VERIFIED` only after execution evidence.

After all phases:

```bash
php artisan test
```

Record:

```text
tests
assertions
failures
errors
skipped
duration
```

Do not reuse the historical `399 / 4,089` as post-remediation evidence.

---

# 19. Data-Integrity Verification Checklist

After implementation verify:

```text
No new PO without item awards
No shipment-aware QC with null shipment
No shipment-aware QcItem with null shipment_item_id
No incomplete QC certifying a shipment
No accepted quantity double-counting
No NG quantity permanently blocking replacement
No 0.0001 over-allocation
No resolved claim returning to responded
No active claim overwritten by arrival/QC
No orphan attachment row from failed storage
No orphan new file after DB persistence failure
No route pointing to missing controller action
```

---

# 20. Backward Compatibility Requirements

Do not break:

```text
existing legacy POs
legacy po_documents
legacy QC with shipment_id = null
legacy claims
existing exports
notification URLs
Hashid decoding
supplier isolation
same-supplier Multi-PR consolidation
```

Legacy compatibility must remain bounded to historical/compatible behavior.

New writes must use the new domain workflow.

---

# 21. Non-Goals

Do not add unrelated scope:

- quantity-level supplier split award;
- multi-supplier PO;
- multi-supplier shipment;
- ERP integration redesign;
- carrier API;
- barcode/QR receiving;
- freight/container optimization;
- broad refactors unrelated to confirmed blockers;
- speculative historical reconstruction.

---

# 22. Phase Completion Checklist

## Phase 1
- [ ] Legacy new PO write bypass closed
- [ ] Award-aware generation authoritative
- [ ] Legacy read preserved
- [ ] Tests pass

## Phase 2
- [ ] Shipment ID mandatory where required
- [ ] Arrived status enforced
- [ ] Exact line coverage
- [ ] Non-null shipment_item_id
- [ ] Tests pass

## Phase 3
- [ ] Per-line accepted fulfillment
- [ ] Mixed OK/NG correct
- [ ] Replacement allowed
- [ ] History preserved
- [ ] Tests pass

## Phase 4
- [ ] One finalization transaction
- [ ] Quotations locked/revalidated
- [ ] Failure rollback complete
- [ ] Tests pass

## Phase 5
- [ ] Central PO status reconciliation
- [ ] Active claim precedence
- [ ] Legal claim transitions
- [ ] Atomic claim+PO writes
- [ ] Tests pass

## Phase 6
- [ ] Owner supplier access
- [ ] Failed storage safe
- [ ] DB failure compensation
- [ ] Review reset
- [ ] Deterministic current version
- [ ] History preserved
- [ ] Tests pass

## Phase 7
- [ ] Max 4 decimals
- [ ] Exact comparison
- [ ] 0.0001 overage rejected
- [ ] Zero/negative rejected
- [ ] Tests pass

## Phase 8
- [ ] Edit method
- [ ] Update method
- [ ] Owner/draft-only guard
- [ ] Destroy route removed
- [ ] Tests pass

## Phase 9
- [ ] Validation hardening reviewed
- [ ] Mass assignment reviewed
- [ ] DB constraints evaluated
- [ ] Schema documentation corrected
- [ ] Git packaging verified

---

# 23. Final Definition of Done

Remediation is complete only when:

1. all eight confirmed Laravel blockers are fixed;
2. each has targeted regression coverage;
3. no new PO bypasses item-level awards;
4. shipment-aware QC is strictly shipment/line scoped;
5. NG/replacement fulfillment works per line;
6. award + PO generation is atomic;
7. PO/claim transitions follow one coherent precedence model;
8. shipment document authorization/storage/versioning is safe;
9. quantity comparison is exact at four decimals;
10. draft shipment edit/update routes are functional and owner-scoped;
11. legacy read compatibility remains intact;
12. targeted suites pass;
13. full `php artisan test` passes after remediation;
14. worktree/deployment packaging includes all required files;
15. no release blocker from the final Laravel review remains.

---

# 24. Post-Implementation Quality Gates

## Gate 1 — Focused Read-Only `/find-bugs`

Review only:

- the eight Laravel blocker remediations;
- direct side effects;
- new regression tests;
- transaction/locking changes;
- state reconciliation;
- attachment lifecycle;
- quantity precision;
- route changes.

## Gate 2 — Final `/laravel-security-audit`

If Gate 1 passes, rerun final Laravel review against:

- original audit;
- remediation diff;
- latest schema/migrations;
- latest full-suite result.

## Gate 3 — Release-Control Verification

Before deployment confirm:

```text
git state understood
all required files tracked/packaged
migration deployment state known
full regression green
manual core business-flow smoke test completed
```

Only after these gates pass should release readiness be evaluated.

---

# 25. Expected Final Implementation Report

```text
# Laravel Audit Remediation Result

## 1. Executive Summary
## 2. Baseline / Recovery State
## 3. Blocker 1 — Legacy PO Write Bypass
## 4. Blocker 2 — Shipment-Aware QC
## 5. Blocker 3 — NG / Replacement Fulfillment
## 6. Blocker 4 — Award + PO Atomicity
## 7. Blocker 5 — PO / Claim State Reconciliation
## 8. Blocker 6 — Shipment Document Lifecycle
## 9. Blocker 7 — Exact Quantity Precision
## 10. Blocker 8 — Draft Shipment Editing
## 11. Hardening Changes
## 12. Migration / Schema Notes
## 13. Tests Added / Updated
## 14. Targeted Test Results
## 15. Full Regression Result
## 16. Legacy Compatibility
## 17. Deviations From Plan
## 18. Remaining Unverified Items
## 19. Definition of Done Assessment
## 20. Final Status
```

Each blocker must be classified:

```text
PASS
FAIL
NOT VERIFIED
DEFERRED
```

Do not claim `PASS` without execution/direct-inspection evidence.

---

# 26. Final Target State

```text
PR
↓
Item-Level Award
↓
Atomic PO Generation
↓
PO per Supplier
↓
Shipment(s)
↓
Partial Delivery
↓
Shipment-Level Documents
↓
Arrival
↓
Exact Shipment-Scoped QC
↓
Per-Line Accepted / NG Fulfillment
↓
Claim / Replacement if required
↓
Centralized PO State Reconciliation
↓
Completion only when accepted fulfillment is complete
```

Critical guarantees:

```text
No new legacy PO bypass
No shipment-aware legacy QC bypass
No NG allocation dead-end
No non-atomic award finalization
No 0.0001 over-allocation
No conflicting claim/PO state writers
No unsafe shipment-document lifecycle
No broken shipment resource routes
```

This is the required implementation state before the feature can proceed through final release quality gates.
