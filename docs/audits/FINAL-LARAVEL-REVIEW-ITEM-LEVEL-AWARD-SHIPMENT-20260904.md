# Final Laravel Integrity and Application-Safety Review

**Project:** ADASI Portal Supplier  
**Review date:** 2026-09-04  
**Scope:** Item-Level Award, Multi-PO Shipment Consolidation, Partial Delivery, and direct Laravel application interactions  
**Review mode:** Read-only  
**Final result:** **FAIL — ACTION REQUIRED**

## Authoritative Inputs

- `ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-IMPLEMENTATION-PLAN-20260904.md`
- `docs/results/ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-RESULT-20260904.md`
- `docs/audits/FIND-BUGS-ITEM-LEVEL-AWARD-SHIPMENT-20260904.md`
- `docs/audits/SECOND-PASS-FIND-BUGS-ITEM-LEVEL-AWARD-SHIPMENT-20260904.md`
- Current repository source and local database state

The seven findings closed by the second `/find-bugs` pass were not reopened. The confirmed issues below are distinct Laravel-specific paths evidenced by the current source.

## Executive Summary

The implemented authorization groups, supplier ownership checks, shipment commercial-chain validation, Hashid handling, private storage, and core shipment locking provide useful protection. The focused current regression run also passed.

Release should nevertheless be blocked because current source still permits:

1. creation of new POs through the legacy quotation-level path without item awards;
2. shipment-aware QC to be processed as legacy QC or against incomplete/non-arrived shipment contents;
3. QC-NG quantities to consume allocation permanently while never satisfying accepted fulfillment;
4. award finalization against mutable quotation state without a complete lock/transaction boundary;
5. shipment over-allocation by exactly `0.0001`;
6. invalid or non-atomic claim and PO state transitions;
7. unsafe shipment-document access, persistence, and version verification behavior; and
8. registered shipment edit/update routes whose controller actions do not exist.

## Confirmed Issues

### 1. Active legacy PO creation bypasses item-level awards

**Classification:** CONFIRMED ISSUE  
**Severity:** High

**Locations:**

- `routes/web.php:159-160`
- `app/Http/Controllers/Purchasing/PurchaseOrderController.php:260-385`
- `app/Http/Controllers/Purchasing/QuotationListController.php:123-129`
- `resources/views/purchasing/quotations/show.blade.php:338-342`

**Execution path:**

```text
Purchasing quotation page
→ Create PO from Quotation
→ PurchaseOrderController::store()
→ PO created without PrItemAward records
→ selected quotation accepted
→ competing quotations rejected
→ PR marked completed unconditionally
```

**Evidence:**

The active controller creates the PO directly from quotation IDs. It does not create or require item awards. It then rejects all other submitted/accepted quotations for the affected PRs and updates every affected PR to `completed`. Because the resulting PO has no awards, `ShipmentService` subsequently treats it as a legacy PO through the `po_quotations` fallback.

**Affected invariant:**

- Item-level awards are the authoritative winner source.
- PR completion is based on item coverage.
- Useful competing quotations must remain actionable while PR items are unresolved.
- New item-level PO shipment lines require award traceability.

**Current test coverage:**

`PurchaseOrderCreationConcurrencyAndInvariantTest` positively exercises this legacy write path. There is no test proving that new procurement cannot bypass item awards.

**Smallest safe remediation:**

Preserve legacy records as readable, but route every new PO write through award-aware generation. Replace or server-guard the quotation-level creation endpoint while retaining an award-aware path for same-supplier Multi-PR consolidation.

### 2. Shipment-aware QC can be bypassed through crafted requests

**Classification:** CONFIRMED ISSUE  
**Severity:** High

**Location:** `app/Http/Controllers/Qc/QcInspectionController.php:152-305`

**Execution paths:**

1. A QC user omits `shipment_id` for a shipment-aware PO. The request is processed as a legacy inspection, and an overall OK result immediately sets the PO to `completed`.
2. A QC user supplies a shipment in `draft` or `submitted` status while the PO is `waiting_qc` because of another arrival. No `arrived` status check rejects it.
3. A QC user submits only a subset of shipment items, duplicates one PR item, or submits a same-PO PR item that is absent from the shipment. The controller may persist `shipment_item_id = null` and still certify the shipment.

**Evidence:**

- `shipment_id` is not required when a PO already has shipment items.
- The shipment check only proves that the shipment contains any item for the PO.
- The payload is validated against all quotation PR items on the PO, not the exact shipment lines.
- There is no distinct or exact-coverage validation.
- A missing shipment-line match is stored with a nullable `shipment_item_id`.
- Shipment lifecycle status is not checked before QC.

**Affected invariant:**

- Physical arrival must precede QC.
- QC is shipment-scoped.
- QC items must belong to the inspected shipment and PO.
- PO completion must use valid inspected fulfillment.
- `shipment_id = null` is only a legacy compatibility mode.

**Current test coverage:**

Tests cover a shipment containing no items for the selected PO and a valid Multi-PO shipment. They do not cover omitted `shipment_id`, non-arrived shipments, omitted lines, duplicated lines, or same-PO items outside the selected shipment.

**Smallest safe remediation:**

Inside the locked PO transaction:

- require `shipment_id` whenever the PO has shipment items;
- lock and reload the selected shipment;
- require shipment status `arrived`;
- derive the expected PO shipment lines server-side;
- require each line exactly once; and
- require every shipment-aware QC item to have a matching non-null `shipment_item_id`.

Allow `shipment_id = null` only when the PO has no shipment items.

### 3. QC-NG quantities can permanently block replacement fulfillment

**Classification:** CONFIRMED ISSUE  
**Severity:** High

**Locations:**

- `app/Services/ShipmentService.php:36-56`
- `app/Services/ShipmentService.php:312-330`
- `app/Models/PurchaseOrder.php:245-278`

**Execution path:**

```text
20 ordered
→ Shipment 1: 5 arrived and QC NG
→ claim resolved
→ Shipment 2: remaining 15 submitted and QC OK
→ active allocation = 20
→ QC-accepted fulfillment = 15
→ PO remains active
→ remaining allocatable quantity = 0
→ replacement 5 cannot be submitted
```

**Evidence:**

Active allocation counts every submitted or arrived shipment quantity, including NG quantities. Accepted fulfillment only sums shipment quantities whose overall inspection status is `ok`. An NG quantity therefore consumes allocation but never fulfills the PO.

The logic also operates at overall inspection level. If one shipment contains multiple items and one QC item is NG, the overall inspection becomes NG and otherwise-OK shipment items are excluded from fulfillment.

**Affected invariant:**

- Resolved partial-NG claims must leave remaining/replacement delivery possible.
- Completion must use authoritative QC-accepted item quantities.
- OK and NG outcomes must not cross-contaminate other lines in the same shipment.

**Current test coverage:**

`ShipmentDocumentsAndQcIntegrationTest::test_claim_resolution_on_partial_ng_delivery_does_not_complete_po_and_allows_workflow_continuation` stops after submitting the remaining 15. It does not QC-accept that delivery and attempt the final NG replacement.

**Smallest safe remediation:**

Calculate accepted fulfillment per `ShipmentItem`/`QcItem`, not from overall shipment inspection status. When the claim for an NG line is resolved, release that line's allocation for replacement while retaining its physical-arrival history. Centralize this calculation for shipment availability, QC, and claim resolution.

### 4. Award finalization lacks a complete lock and transaction boundary

**Classification:** CONFIRMED ISSUE  
**Severity:** High

**Locations:**

- `app/Http/Controllers/Purchasing/PriceComparisonController.php:1261-1310`
- `app/Services/PrItemAwardService.php:146-245`
- `app/Services/PurchaseOrderGenerationService.php:80-191`
- `app/Http/Controllers/Purchasing/QuotationListController.php:150-266`

**Execution paths:**

1. `awardBatch()` commits before `generateFromAwards()` starts. If PO generation fails, the HTTP action reports failure but the new awards remain committed.
2. Award and PO services validate quotation status through eagerly loaded relations without locking the quotation rows. A concurrent reject or revision request can update the quotation after eligibility was read but before the award/PO write commits.

**Evidence:**

The award and PO services lock PR items, quotation items, or award rows, but not the mutable quotation rows whose statuses are authoritative for eligibility. Quotation status mutation paths do not follow a matching transactional lock protocol.

**Affected invariant:**

- Only `submitted` and `accepted` quotations may be awarded.
- The combined `generate_pos` action must not leave a technically partial result after failure.
- Mutable validation must occur under the locks that protect the validated state.

**Current test coverage:**

Tests cover eligible/ineligible statuses and successful generation. They do not cover failure rollback for the combined HTTP action or real concurrent quotation mutation.

**Smallest safe remediation:**

- Wrap award saving and PO generation for `generate_pos` in one outer transaction.
- Lock PR, quotation, quotation-item, and award rows in deterministic order.
- Make quotation status mutation paths use a compatible locked/revalidated transition.
- Translate unique conflicts into controlled validation responses.

### 5. Exact four-decimal over-allocation is accepted

**Classification:** CONFIRMED ISSUE  
**Severity:** Medium

**Location:** `app/Services/ShipmentService.php:270-330`

**Execution path:**

The service rejects a quantity only when:

```php
$shippedQty > $remainingQty + 0.0001
```

For an ordered/remaining quantity of `1.0000`, a submitted quantity of `1.0001` is therefore accepted and stored.

**Evidence:**

The current PHP comparison was executed directly and returned `false` for the rejection expression. MySQL stores the value at four-decimal precision.

**Affected invariant:**

```text
SUM(active shipment quantities) <= ordered PO item quantity
```

**Current test coverage:**

Over-allocation is tested with larger differences. The exact `0.0001` boundary and inputs with more than four decimal places are not tested.

**Smallest safe remediation:**

Validate a maximum four-decimal scale and compare exact normalized integer ten-thousandths or equivalent decimal strings without an epsilon.

### 6. Claim and PO state transitions are not transactionally coherent

**Classification:** CONFIRMED ISSUE  
**Severity:** Medium

**Locations:**

- `app/Http/Controllers/Supplier/ClaimController.php:72-88`
- `app/Http/Controllers/Purchasing/MaterialClaimController.php:207-233`
- `app/Services/ShipmentService.php:422-453`
- `app/Http/Controllers/Qc/QcInspectionController.php:290-305`

**Execution paths:**

- An owner supplier can POST another response to a resolved claim, changing it back to `responded`.
- Purchasing resolves the claim and updates its PO through separate, unlocked writes.
- Shipment arrival unconditionally sets the PO to `waiting_qc`, even if an active claim requires `claim_needed` precedence.
- QC OK state calculation does not check for active claims before writing `active`, `waiting_qc`, or `completed`.

**Affected invariant:**

- Resolved claims must remain resolved.
- Active claims must prevent the PO from leaving `claim_needed` accidentally.
- Claim resolution and PO status recalculation must commit atomically.
- Multiple claims must use consistent active-claim precedence.

**Current test coverage:**

Partial claim resolution is covered. Illegal re-response, concurrent resolution, arrival while a claim is active, and multiple-claim races are not covered.

**Smallest safe remediation:**

Lock the claim and PO in one transaction, enforce explicit allowed source statuses, and reuse one authoritative PO-state recalculation helper in arrival, QC, and claim resolution, with active claims taking precedence.

### 7. Shipment document attachment lifecycle is unsafe

**Classification:** CONFIRMED ISSUE  
**Severity:** High

**Locations:**

- `app/Policies/AttachmentPolicy.php:21-68`
- `app/Http/Controllers/AttachmentController.php:11-39`
- `app/Services/ShipmentService.php:481-514`
- `config/filesystems.php:41-45`
- `resources/views/purchasing/shipments/show.blade.php:171-196`
- `resources/views/supplier/shipments/show.blade.php:150-179`

**Execution paths and evidence:**

1. `AttachmentPolicy` contains no supplier case for `ShipmentDocument`; the owner supplier is denied when opening its own uploaded shipment document.
2. The private disk has `throw => false`, but the result of `put()` is ignored. A failed physical write can still be followed by an attachment database row.
3. A successful physical write followed by a database failure leaves an orphan file.
4. Re-uploading content while the document is `verified` or `done` does not reset its status because only `pending` becomes `received`.
5. The supplier view selects `latest_attachment`, while Purchasing uses an unordered collection `first()`. The reviewed status and reviewed file version can diverge.

**Affected invariant:**

- A supplier must be able to access its own shipment documents without accessing another supplier's data.
- Purchasing-only verification must apply to the current document version.
- Failed uploads must not create missing-file database records or orphan physical files.
- Historical versions must remain preserved.

**Current test coverage:**

Upload and cross-supplier denial are tested. Owner download, failed storage, failed database persistence, re-upload after verification, and current-version selection are not.

**Smallest safe remediation:**

- Add owner-aware `ShipmentDocument` handling to `AttachmentPolicy`.
- Check the storage result.
- Use a short database transaction with compensating deletion of only the newly written file when persistence fails.
- Reset replacement uploads to a review-required status.
- Use a deterministic latest attachment relationship for both roles while preserving history.

### 8. Draft shipment edit/update routes have no controller implementation

**Classification:** CONFIRMED ISSUE  
**Severity:** Medium

**Locations:**

- `routes/web.php:242`
- `app/Http/Controllers/Supplier/SupplierShipmentController.php:15-244`
- `ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-IMPLEMENTATION-PLAN-20260904.md:863-876`
- `ITEM-LEVEL-AWARD-SHIPMENT-PARTIAL-DELIVERY-IMPLEMENTATION-PLAN-20260904.md:1479-1488`

**Execution path:**

`Route::resource('shipments', SupplierShipmentController::class)` registers `edit`, `update`, and `destroy`. The controller implements none of those methods. The route table confirmed all three routes remain registered.

**Affected invariant:**

Draft shipments are editable, and registered HTTP routes must resolve to valid server handlers.

**Current test coverage:**

Draft creation, submission, and cancellation are covered. Draft edit/update and route-controller contract behavior are not.

**Smallest safe remediation:**

Implement owner-scoped, draft-only edit/update through existing service validation and locking. Remove the unsupported resource `destroy` route in favor of explicit cancellation.

## Transaction and Locking Classification

| Path | Classification | Evidence |
|---|---|---|
| Shipment submission and allocation | **SOUND** | Shipment, PO, and quotation-item locks are transactional and deterministically ordered. Parent-row locking serializes competing allocation for the same PO/item. This minimizes deadlock risk; it does not eliminate deadlocks. |
| Shipment cancellation | **SOUND** | Shipment status is locked and changed transactionally; cancelled allocation is excluded by status. |
| Award and PO generation | **NEEDS HARDENING** | Deterministic locks exist, but mutable quotation/PR state and the combined HTTP finalization boundary are incomplete. |
| Arrival confirmation | **NEEDS HARDENING** | Shipment is locked, but associated POs are not explicitly locked and sorted before status updates. |
| QC store | **INCORRECT** | PO locking exists, but shipment status and exact line membership are not locked/revalidated. File I/O also occurs while the PO lock is held. |
| Claim response and resolution | **INCORRECT** | Critical claim/PO transitions do not use one locked transaction or enforce legal source statuses. |

## Safe / Verified Areas

### Authorization and session boundary

**Classification:** SAFE / VERIFIED

- Award and PO-generation routes are within `auth`, `role:purchasing`, and purchasing-navigation middleware.
- QC store routes require `auth` and `role:qc`.
- Supplier shipment routes require `auth` and `role:supplier`.
- State-changing web routes retain Laravel CSRF/session middleware.
- There is no purchaser-specific ownership scope in the confirmed domain; Purchasing is globally authorized for procurement records.

### Supplier confidentiality isolation

**Classification:** SAFE / VERIFIED, subject to the owner-download availability defect in Finding 7.

- Supplier shipment index and create queries filter by `supplier_id = auth()->id()`.
- Show, submit, cancel, and upload paths compare the shipment supplier to the authenticated supplier.
- Shipment document IDs are resolved through the already-owned shipment.
- Direct attachment IDs remain protected by `AttachmentPolicy`.
- No cross-supplier shipment, PO, quotation item, award, or document disclosure was found.

### Shipment commercial chain

**Classification:** SAFE / VERIFIED for current service-mediated writes.

`ShipmentService` validates:

```text
Shipment supplier
→ PurchaseOrder.supplier_id
→ QuotationItem
→ matching PrItemAward for item-level POs
```

Legacy POs fall back to `po_quotations` membership. Cross-PO, cross-supplier, draft-sync mismatch, and duplicate-line negative tests are present.

### Database uniqueness

**Classification:** SAFE / VERIFIED

- `pr_item_awards.pr_item_id` is unique.
- `po_quotations.quotation_id` remains unique.
- The live local `shipment_items_unique_item` index exactly covers:

```text
shipment_id
purchase_order_id
quotation_item_id
```

### Shipment allocation status handling

**Classification:** SAFE / VERIFIED, except for the exact precision and NG-release defects.

- Draft and cancelled shipments are excluded.
- Submitted and arrived shipments are included.
- Soft-deleted shipments are excluded explicitly.
- Cancellation releases reservations.

### Hashids and route binding

**Classification:** SAFE / VERIFIED

- `DecodeHashids` recognizes `id`, `shipment`, and `shipment_id` route parameter names.
- Plain numeric or non-canonical values on hashed routes fail with 404.
- Attachment and shipment-document IDs remain intentionally plain where configured.
- Decoding does not replace ownership or role authorization.

### Notifications inside transactions

**Classification:** SAFE / VERIFIED

`NotificationService` defers delivery through `DB::afterCommit()` when called inside a transaction, so PO/shipment database locks are not held during notification delivery.

## Hardening Recommendations

### Mass assignment and model invariants

**Classification:** HARDENING RECOMMENDATION

`PrItemAward`, `Shipment`, `ShipmentItem`, and `ShipmentDocument` expose linkage, owner, audit, and status fields through `$fillable`. Current HTTP endpoints write explicit arrays and no direct `$request->all()` model create/update exploit was found. However, the models and foreign keys do not independently prove cross-table chain consistency.

Keep these writes service-only or more narrowly guard immutable fields. Any future endpoint must not pass request arrays directly into these models.

### Additional database constraints

**Classification:** HARDENING RECOMMENDATION

Consider:

- `CHECK (shipped_quantity > 0)` where supported;
- unique `(shipment_id, doc_type)` for the four shared document rows; and
- stronger protection against deleting an award referenced by a new shipment line.

These must preserve nullable award references for genuine legacy shipment data.

### HTTP validation boundaries

**Classification:** HARDENING RECOMMENDATION

- Supplier `submit()` passes `$request->all()` into the service without HTTP validation. Core IDs and quantities are rechecked by the service, but malformed arrays, dates, and notes are not handled through normal Laravel validation responses.
- Purchasing arrival confirmation accepts `actual_arrival_date` without validation.
- Award HTTP validation checks only the top-level awards array; nested key/value shape is deferred to casting and service exceptions.
- Raw caught exception messages can expose database details during unique conflicts.

Use focused Form Requests or equivalent controller validation without duplicating the authoritative service checks.

## Migration Safety and Repository State

### Current migration status

**Classification:** INFORMATIONAL

Current local database state:

```text
2026_09_04_000001_create_pr_item_awards_table ........ Ran, batch 28
2026_09_04_000002_create_shipments_tables ............ Ran, batch 29
```

Both migration files remain untracked in Git. Therefore:

- the previous claim that migration `000002` remains untracked is confirmed;
- the migrations are already applied to the current local database; and
- deployment to any shared environment is **NOT VERIFIED**.

Git state alone cannot prove that an untracked migration was never copied or executed elsewhere. Do not edit applied migration history for a shared environment without first confirming deployment state; use a forward migration if an earlier form was deployed.

### Rollback

**Classification:** SAFE / INSPECTED

Migration `000002` deletes `document_sequences` rows with type `SHP` before narrowing the MySQL enum back to `PR, PO`. The source rollback order is correct. Rollback was not executed during this read-only review; the second-pass report records the prior live rollback/re-migration test.

### Foreign keys and soft deletes

**Classification:** SAFE / VERIFIED with hardening caveats

- Shipment parent rows use soft deletes.
- Active-allocation queries exclude soft-deleted shipments.
- Shipment items and documents cascade when a shipment is physically deleted.
- QC shipment links and QC item shipment-line links use nullable `nullOnDelete()` compatibility.
- Award linkage on shipment items is nullable for legacy support.

### MySQL strict mode

**Classification:** SAFE / VERIFIED

The local MySQL session includes `STRICT_TRANS_TABLES`, and Laravel MySQL configuration has strict mode enabled.

### Schema/report discrepancy

**Classification:** INFORMATIONAL

The implementation result reports `shipped_quantity` as `DECIMAL(14,4) UNSIGNED`. Current migration source and live local MySQL schema are:

```text
DECIMAL(12,4) NOT NULL
```

The four-decimal scale is unchanged, and `DECIMAL(12,4)` still supports a large operational range. The signed schema nevertheless lacks the database-level non-negative protection claimed by the report.

### Git packaging state

**Classification:** INFORMATIONAL / RELEASE CONTROL

The worktree contains 79 pre-existing modified or untracked entries. Most new feature services, models, migrations, controllers, tests, and reports are untracked. A deployment assembled only from committed Git content would omit this implementation.

No files were modified during the review itself.

## Legacy Compatibility

### Preserved behavior

**Classification:** SAFE / VERIFIED

- Existing POs without awards remain readable through quotation/pivot fallback.
- Existing POs without shipment items retain `purchase_orders.actual_arrival` delivery-progress fallback.
- Nullable legacy QC inspections and QC items remain structurally readable.
- Legacy PO documents remain separate and readable.
- Shipment notification URL resolution checks supplier ownership.
- Export paths continue using existing PO/quotation relationships.

### Unsafe compatibility ambiguity

**Classification:** CONFIRMED ISSUE

Legacy QC mode is currently selected merely by omitting `shipment_id`, rather than by proving that the PO has no shipment items. This is included in Finding 2.

Legacy read compatibility also does not justify the active legacy PO write path described in Finding 1.

## Test Quality

### Verification performed in this review

The following focused suites were executed:

- `ItemLevelAwardTest`
- `PriceComparisonItemAwardTest`
- `ItemLevelPoGenerationTest`
- `ShipmentAndPartialDeliveryTest`
- `ShipmentDocumentsAndQcIntegrationTest`
- `SupplierDataIsolationTest`
- `HashidUrlSecurityTest`
- `PurchaseOrderCreationConcurrencyAndInvariantTest`

Result:

```text
66 tests passed
351 assertions
0 failures
```

The historical full-suite evidence remains:

```text
399 tests passed
4,089 assertions
0 failures
0 errors
0 skipped
```

The full suite was not rerun during this scoped review.

### Strong existing negative coverage

**Classification:** SAFE / VERIFIED

Coverage exists for:

- supplier/non-purchasing award denial;
- unavailable and ineligible quotation awards;
- cross-PO and cross-supplier shipment lines;
- draft-sync mismatch;
- duplicate shipment lines and database uniqueness;
- larger over-allocation attempts;
- cancelled allocation release;
- mismatched QC shipment/PO;
- legitimate Multi-PO shipment QC;
- partial claim resolution;
- supplier shipment ownership; and
- Hashid rejection/canonicalization.

### Meaningful missing coverage

**Classification:** NOT VERIFIED

Add negative regression coverage for:

1. new PO creation without finalized item awards;
2. shipment-aware QC with omitted `shipment_id`;
3. QC against draft/submitted shipments;
4. omitted, duplicated, and non-shipment QC lines;
5. mixed OK/NG items in one shipment;
6. replacement submission after resolved NG plus delivery of the non-NG remainder;
7. exact `0.0001` over-allocation and more-than-four-decimal input;
8. combined award/generation failure rollback;
9. quotation status changes concurrent with award/PO generation;
10. claim response after resolution and arrival while another claim is active;
11. supplier download of its own shipment document;
12. document storage/database failure compensation;
13. re-upload after document verification; and
14. draft shipment edit/update authorization and route handling.

The current concurrency test is serial race regression coverage. It is not real concurrent execution evidence.

## Final Release Blockers

The following confirmed issues should be remediated before release:

1. active PO creation without item awards;
2. shipment-aware QC legacy/null and line-scope bypass;
3. NG allocation and per-item fulfillment deadlock;
4. incomplete award/quotation locking and combined finalization atomicity;
5. exact four-decimal quantity over-allocation;
6. invalid and non-atomic claim/PO state transitions;
7. shipment-document authorization, persistence, and version integrity defects; and
8. missing draft shipment edit/update implementation.

# FINAL LARAVEL REVIEW: FAIL — ACTION REQUIRED
