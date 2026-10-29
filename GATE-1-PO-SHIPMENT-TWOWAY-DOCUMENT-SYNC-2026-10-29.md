# GATE 1 — Two-Way PO ↔ Shipment Document Synchronization

**ADASI Supplier Portal** · 29 Oktober 2026 · **PLANNING ONLY / NO IMPLEMENTATION**

**Decision locked — B:** When Purchasing starts a document-status change from the PO detail page, Purchasing must explicitly select the particular linked Shipment document(s) to update. Only selected, eligible Shipment documents are modified. No implicit broadcast to every Shipment.

**Repository inspection baseline:** `poyipoy/supplierportal` `master` at `08b1a9cdbcf1249889283e2b17e9a55cd3f786b4` (snapshot, recheck HEAD before Gate 2). Analysis based on read-only GitHub source inspection; no code edits, database operations, test runs, or deployment.

## A. Problem / verified current behavior

1. `PoDocument` stores a status per PO and document type; `ShipmentDocument` stores a status per Shipment and document type (`invoice`, `packing_list`, `bl`, `form_e`). Rank mapping is `pending=0`, `issued/processing=1`, `received=2`, `verified/done=3`.
2. Supplier upload calls `ShipmentService::uploadDocument()`; status becomes `received`, then `PoDocument::syncFromShipmentDocument()` advances linked PO rows if and only if target rank is higher. Purchasing updating Shipment status also invokes that one-way sync.
3. Direct PO update at `PoDocumentController::update()` writes the PO row alone; it does not update Shipment rows.
4. `PurchaseOrder::customsDocumentationSummary()` derives a PO effective status from the highest-ranked Shipment doc or PO doc and includes links/attachments; `reconcileCustomsDocumentationStatus()` persists forward-only healing. Purchasing PO show GET calls reconciliation; supplier PO show GET remains read-only.
5. Existing PO completion widget counts 4 document types as complete when statuses are `received`, `verified`, or `done`. This is a document-availability metric and does not mean every partial Shipment is verified; it is separate from operational PO `completed` and QC acceptance.
6. `PoDocumentController::update()` currently accepts all six statuses, including downgrades, while Shipment-to-PO synchronization only advances ranks. Existing tests lock in current forward-only behavior and must be considered when changing contracts.

### Source map

- `app/Models/PoDocument.php` — status mapping and forward-only sync: https://github.com/poyipoy/supplierportal/blob/master/app/Models/PoDocument.php
- `app/Models/PurchaseOrder.php` — customs summary and explicit reconciliation: https://github.com/poyipoy/supplierportal/blob/master/app/Models/PurchaseOrder.php
- `app/Http/Controllers/Purchasing/PoDocumentController.php` — PO update and notifications: https://github.com/poyipoy/supplierportal/blob/master/app/Http/Controllers/Purchasing/PoDocumentController.php
- `app/Http/Controllers/Purchasing/ShipmentController.php` — purchasing document review: https://github.com/poyipoy/supplierportal/blob/master/app/Http/Controllers/Purchasing/ShipmentController.php
- `app/Http/Controllers/Supplier/SupplierShipmentController.php` — supplier upload: https://github.com/poyipoy/supplierportal/blob/master/app/Http/Controllers/Supplier/SupplierShipmentController.php
- `app/Services/ShipmentService.php` — existing locked upload/ship logic (protected): https://github.com/poyipoy/supplierportal/blob/master/app/Services/ShipmentService.php
- `app/Policies/ShipmentPolicy.php` — `reviewDocument` purchasing/admin; supplier owner uploads: https://github.com/poyipoy/supplierportal/blob/master/app/Policies/ShipmentPolicy.php
- `resources/views/purchasing/po/show.blade.php` — existing 4 doc cards and update action: https://github.com/poyipoy/supplierportal/blob/master/resources/views/purchasing/po/show.blade.php
- `tests/Feature/PoCustomsDocumentationShipmentSyncTest.php` — existing behavior baseline: https://github.com/poyipoy/supplierportal/blob/master/tests/Feature/PoCustomsDocumentationShipmentSyncTest.php

## B. Desired user experience — B selected

Within a PO document card (e.g. Packing List), clicking **Update Status** opens a dialog showing each linked eligible Shipment with: Shipment number, document status, upload presence, latest document reference, preview/download link (permission-scoped), and whether it is eligible for the target transition. The user selects one or more rows explicitly (default none), chooses the target status, optionally enters a reason/note, and receives a confirmation summary.

- Button: **Update selected Shipment document(s)**; never **Update all** by default.
- Rows with no usable attachment cannot be verified/done through this action; show a specific reason and keep them unselected/disabled for that target status.
- Display **X/Y documents received** and **X/Y verified** per doc type, plus individual Shipment statuses. **Partial** is a derived *UI label*, not a new `shipment_documents.status` value.
- If a single Shipment includes items from multiple POs, the UI warns that a change to this shared document is visible on all linked POs. No duplicate file upload or copying records.
- Status update feedback identifies updated, skipped, stale and rejected rows. User cannot assume a batch was entirely successful if only some rows changed.
- No linked Shipment: preserve PO-only manual status for genuinely legacy PO; for shipment-aware POs, offer a waiting/no-eligible-Shipment state rather than pretending an attachment exists.

## C. Architecture and state ownership

**Source of truth:** `shipment_documents` is authoritative for each Shipment's document status; attachments remain attached to the Shipment document. `po_documents` retains legacy/manual compatibility and existing notification identity; a **read-only projection** computes PO aggregate, and a controlled write-side reconciliation preserves compatibility until a deprecation decision is separately approved. Never represent a PO's aggregate as proof that each individual Shipment has the same status.

**Proposal for aggregate semantics (technical default; to be confirmed against product expectations):** For eligible, linked, noncancelled Shipments, compute by document type:
- `total_shipments`, `files_present`, `received_count` (received/verified/done and required attachment evidence), `verified_count` (verified/done and evidence), `pending_count`, `processing_count`, `complete_for_all`.
- `partial`: at least one but not all eligible Shipment documents meet the chosen criterion.
- `all received` requires all relevant Shipment documents to meet received criterion; `all verified` requires all to meet verified/done criterion. These are distinct from today's 4/4 metric.
- `pending`/`issued`/`processing` should not be silently converted into completed. Do not rely exclusively on numeric `STATUS_RANKS` to decide eligibility or finality; `issued` and `processing`, and `verified` and `done`, are peers rather than strictly ordered states.
- Existing PO-level manual status can be displayed separately as **PO legacy/manual status** while linked-shipments aggregate is displayed distinctly. Do not silently downgrade persisted PO status during read or destroy a user's prior manual setting.

**Status and attachment validation:** Preserve existing supplier-upload flow. For PO-initiated verification (`verified`/`done`), require a linked, accessible, acceptable Shipment attachment and validated inspection/scan outcome where the existing attachment inspection pipeline supports that requirement; characterize inspection states before hard-coding a check. No synthetic file evidence. For correction/downgrade, use a reason and authorized explicit action (not generic negative-rank propagation). Do not infer success from a raw attachment row alone without verifying its security state.

**Shipment eligibility baseline:** audit `draft`, `submitted`, `arrived`, `cancelled`, soft-deleted Shipment associations and document lifetime. Proposed: use submitted/arrived for PO completion; exclude cancelled and draft from completion counts but retain their history visibly. An attachment uploaded to a draft may be shown as preparatory evidence, not as finalized completion. Do not change existing upload permissions without an independently approved compatibility decision.

**Consolidated Shipment:** One Shipment document can apply to multiple POs. The same ShipmentDocument row is updated once, then all affected PO summaries are recomputed. PO selection never clones the document or limits a legitimate shared update to the initiating PO only.

**Partial Shipment:** if PO-001 has three active Shipments with Packing List `[verified,received,pending]`, show `1/3 verified`, `2/3 received`, `Partial`; selecting only Shipment-002 advances its row; Shipment-003 stays pending. If a new valid partial Shipment is added later, denominator can increase and aggregate completion legitimately decrease without resetting individual statuses.

## D. Write contract and transaction safety

### PO → Shipment (new)
1. Controller accepts PO document identity, **explicit ShipmentDocument ID list**, desired status, and optional reason; no implicit scope expansion.
2. Validate: logged-in Purchasing/Admin; PO exists and is visible; each submitted Shipment is linked to that PO via `shipment_items.purchase_order_id`; each selected document type matches the PO doc type; Shipment is eligible and not cancelled; target transition, attachment and current `updated_at`/version token pass server validation. Reject tampered IDs; validate all before mutation.
3. Start a DB transaction with consistent lock ordering: lock Shipment rows in sorted ID order, then ShipmentDocument rows in sorted ID order; load affected PO document rows consistently; coordinate with existing `ShipmentService::uploadDocument()` lock order (Shipment → ShipmentDocument → PO doc). Never lock PO doc first then Shipment. Characterize DB isolation, deadlocks and any `firstOrCreate` race in Gate 1 evidence.
4. Apply only selected rows with controlled status transitions and optional notes; never create another ShipmentDocument for an unrelated Shipment.
5. Recompute/refresh aggregate for **all PO IDs** linked through selected shared Shipments; emit deduplicated notifications on transitions, ideally after transaction commit; do not notify a false completion.
6. Return structured per-row result and refreshed aggregate. For atomic batches, prevalidate and **all-or-nothing rollback** on any invalid/stale row; the UI may reselect and retry. No partial committed batch silently returned as success.

### Shipment → PO (existing, to be unified)
- Supplier upload continues to set Shipment status `received` and attach file via the existing protected service.
- Purchasing updates a Shipment document through the existing authorized path.
- Refactor only safe sync entry points/helpers to project consistent aggregate; retain the call from protected `ShipmentService.php` without changing its file or lock semantics.
- Prevent infinite feedback loops: side effects originate from a user action and call one write synchronization coordinator; recomputation is not a new user action and must not invoke the inverse handler.

### Notification rules
- Event only after a *real* status change, not on read or no-op.
- PO manual status and per-Shipment status change are distinguished in notification text.
- All POs affected by a consolidated Shipment are handled once each; avoid spamming on unchanged display summaries.
- No notification merely because a pure-read aggregate is queried.

## E. UI, API, and compatibility surface

- `resources/views/purchasing/po/show.blade.php`: retain 4 cards; replace direct PO status mutation for linked Shipments with selection modal; show attachment availability and per-Shipment progress.
- `resources/views/purchasing/shipments/show.blade.php`: retain review form but share backend transition guard; keep references consistent.
- `resources/views/supplier/po/show.blade.php`: read-only aggregate and per-Shipment links; **GET performs zero writes**.
- `PoDocumentController` & `ShipmentController`: thin controllers delegating to a shared service, not mirrored controller calls.
- `PurchaseOrder::customsDocumentationSummary()` remains pure read; move complex projections into a dedicated `DocumentStatusProjection` or equivalent if justified by query profiling.
- `PoDocument::syncFromShipmentDocument()` remains compatible with existing protected call site; avoid changing `ShipmentService.php`.
- Routes: prefer reusing or minimally evolving the existing PO document update route with backwards-compatible behavior for genuinely legacy POs. Do not silently accept old unscoped POST payload for new shipment-aware POs.
- Data/schema: first evaluate reuse of existing tables and document notes. A migration for audit history or optimistic versioning is **not assumed**; propose only if evidence shows existing columns/event logs cannot satisfy audit and concurrency needs. Migration requires separate rollout/rehearsal.

## F. Test/acceptance matrix (mandatory at Gate 2)

| Scenario | Expected oracle |
|---|---|
| One PO, one Shipment, upload file | Only that Shipment receives file; PO display reflects it |
| One PO, 3 Shipments | Select SHP-002 only: other document status rows unchanged; aggregate recomputed |
| Selected Shipment without file | `verified`/`done` rejected, zero data writes |
| Forged ShipmentDocument ID / wrong PO or doc_type | authorization/validation rejects with zero writes |
| Consolidated Shipment linked to PO-A and PO-B | One doc mutation, both PO projections accurate, no duplicate docs |
| Existing PO manually `verified`, Shipment `received` | No fabricated Shipment `verified`; old manual provenance preserved |
| Equal-rank status transitions | No accidental `verified↔done`, `issued↔processing` overwrite based on rank alone |
| Cancelled/draft/deleted Shipment | Exclusion rules respected; no false completeness; history accessible as permitted |
| New Shipment after PO previously fully complete | Denominator changes and derived summary can return to Partial without corrupting individual status |
| Repeated identical request | Idempotent; no duplicate writes/notifications |
| Concurrent supplier upload vs PO action | Same lock order, no lost update, deterministic outcome or retriable conflict |
| Concurrent two PO actions | Conflict detected with version / updated_at proof; no silent overwrite |
| PO legacy with zero Shipment | Current manual behavior preserved |
| Supplier/read-only GET | Zero writes, no cross-supplier leak |
| One of 4 document types outstanding | Aggregate does not falsely say all verified or 4/4 fully reviewed |
| QC/material fulfillment | Unchanged; PO operational `completed` remains independent |
| Protected shipment code | `app/Services/ShipmentService.php` byte-identical and existing concurrency suites pass |

Also include adversarial file states (failed/pending file inspection), notification delivery/parity, SQL query baselines/N+1 guards, dark/light and responsive modal tests, accessibility/keyboard, and rollback tests. Capture existing test baselines (`PoCustomsDocumentationShipmentSyncTest`, `PoDocumentStatusValidationTest`, `ShipmentDocumentsAndQcIntegrationTest`, shipment concurrency with MTC on/off) before making changes.

## G. Specific Gate 1 open contract to characterize (not silently assume)

1. Existing `received` contributes to the **current** 4/4 completion widget, while the proposed **all verified** should use `verified/done`: preserve two separate indicators or get product sign-off before replacing existing meaning.
2. Legacy `PoDocument` status is not necessarily aligned with Shipment status; decide whether to keep separate legacy/manual display or redesign PO-level status into a pure derived projection. Recommend preserve legacy explicitly and make new aggregate independent.
3. Define exact file validity/inspection states required to verify, based on actual file inspection pipeline; no unsupported assumption that an uploaded attachment is immediately safe.
4. Confirm operational scope of draft/cancelled/soft-deleted/late-created Shipments and transition guard for downgrades with reason.
5. Determine whether all-or-nothing multi-selection is feasible within existing controllers and UI without large rewrite. Default yes.

## H. Stage plan — bounded, no new mission proliferation

**Gate 1 (now):** Inspect all call paths, characterize the five contracts above, document locked decision B, design UI/API/transactions, register test oracles and risks. No edits or tests unless explicitly approved; no DB access needed. If tests cannot be inferred from code, list them as Gate 2 required, not passing.

**Gate 2 (after explicit approval):** One implementation branch with three internal stages: (1) shared status/coordinator, guards, projection and tests; (2) PO selection UI and compatible controller/API, shipment review reuse; (3) full regression, performance, browser, concurrence, accessibility and release-report. Avoid modifying protected shipment service and unrelated modules.

**Release:** G-PDF, T-1, staging rehearse/rollback, and any migration-ledger prerequisites remain separate release gates; no production deployment is authorized.

**Gate 1 status:** DECISION B LOCKED — TECHNICAL PLAN READY FOR REVIEW, NOT AUTHORIZATION TO IMPLEMENT.
