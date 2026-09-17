# Implementation Plan — Local Supplier Update Revision

## 1. Document Control

- **Repository:** `https://github.com/poyipoy/supplierportal`
- **Branch:** `local-supplier-update`
- **Baseline Commit:** `be7323be747b9a00bf9d0a934e6dc82a731b5d78`
- **Baseline Tree:** `ff55bea52d6565015249624a2c22cdc3f99ac69c`
- **Technology Baseline:** PHP 8.2, Laravel 12
- **Document Status:** Planned / Ready for Engineering Execution
- **Prepared:** 2026-09-15
- **Primary Sources:** Current repository source, `context.md`, user interview decisions, and the attached Voucher Bayar reference image.

---

## 2. Executive Summary

This plan defines the implementation required to extend the Local Supplier invoice and Finance payment flow with authoritative PO/GR master data, whole-GR invoice allocation, PO/GR import, supplier filtering in DRP, formal per-invoice payment vouchers, corrective transfer handling, and supplier overpayment/refund tracking.

The implementation must preserve the existing unified payment architecture (`payment_batches -> payment_groups -> payment_items`) as the DRP/container layer. Local Supplier invoice settlement behavior will be added in a focused, additive manner so that existing Expense Claim and other payable flows are not unintentionally altered.

The most important final business rules are:

1. One invoice references exactly one PO.
2. One invoice may reference one or more GRs from that PO.
3. GRs are indivisible. A GR must be consumed in full; there is no GR `remaining_amount` and no partial GR allocation.
4. The sum of selected GR amounts must exactly equal the invoice DPP.
5. One GR cannot be consumed by more than one active/final invoice.
6. One invoice has one Voucher Bayar and one Payment Settlement.
7. Planned installment/partial payment is not supported.
8. A genuine accidental short bank transfer may be corrected using one or more corrective transfer events under the same Payment Settlement.
9. Overpayment finalizes the invoice as paid and creates a separate Supplier Overpayment Receivable that Finance must recover in one full refund.
10. PO/GR records are never hard-deleted. Incorrect master entries are cancelled subject to dependency rules.

---

## 3. Objectives

The implementation must achieve the following objectives:

- Establish authoritative Local Supplier PO and GR master data maintained by Purchasing and Finance.
- Allow one PO to contain multiple GRs created progressively as receiving occurs.
- Replace the supplier's invoice PO/GR reference behavior with a controlled one-PO/multi-GR selection model.
- Prevent duplicate GR usage under concurrent invoice submissions.
- Support `.xlsx` import for new PO and GR master data with atomic validation.
- Add a single Supplier filter to Ready-to-Pay invoices when Finance builds DRP.
- Generate a formal, A4, ADASI-branded Voucher Bayar for each Local Supplier invoice.
- Preserve the existing voucher numbering format/sequence while changing voucher ownership for Local Supplier invoices from payment-group-oriented behavior to per-invoice behavior.
- Enforce one Payment Settlement per invoice while allowing correction transfer events for accidental short transfers.
- Track overpayment and full supplier refund independently from the invoice payable balance.
- Preserve existing Expense Claim/payment behavior and other closed Local Supplier flows.
- Update `context.md` after implementation so future engineering work reflects the final domain model.

---

## 4. Source of Truth and Decision Precedence

Where current code, `context.md`, earlier meeting assumptions, and later interview clarification differ, use this precedence:

1. Final interview decisions in this plan.
2. Actual current repository behavior/schema where it does not conflict with final requirements.
3. Existing `context.md` for historical flow/context.
4. Earlier assumptions only when still compatible with items 1–3.

Important superseded assumptions:

- GR does **not** support partial allocation or `remaining_amount`.
- One invoice does **not** support multiple independent Payment Settlements.
- A short transfer does **not** create a second DRP/payment. It remains the same Payment Settlement and is corrected with transfer-event history.

---

## 5. Current-State Findings

### 5.1 Existing Payment Architecture

The current codebase already has a unified payment structure based on:

```text
payment_batches
    -> payment_groups
        -> payment_items
```

This architecture should remain the DRP/container layer. It is already shared across payable types and therefore must not be replaced with a Local-Supplier-only payment architecture.

Existing services include:

- `app/Services/Payment/PaymentVoucherService.php`
- `app/Services/Payment/PaymentExecutionService.php`

Current behavior is group-oriented. Voucher generation and payment execution therefore require a Local Supplier invoice-specific extension/refactor without breaking other payable types.

### 5.2 Existing Local PO/GR Domain

Verified relevant files include:

- `app/Models/LocalPurchaseOrder.php`
- `app/Models/LocalGoodsReceipt.php`
- `app/Models/LocalInvoice.php`
- `app/Services/LocalInvoice/LocalPoReferenceService.php`
- `app/Http/Controllers/LocalSupplier/InvoiceController.php`

Existing database migrations already contain Local PO/GR structures.

Important source/schema alignment issues must be resolved before new behavior is layered on top:

- PO database schema uses `po_date`, while the model currently references/casts `order_date`.
- GR database schema uses `gr_date` and `received_amount`, while the model currently references naming such as `received_date` and appears to expose fields that do not align cleanly with the migration.
- The database already has `received_amount`; this should be treated as the canonical GR monetary value and exposed in the business/UI as **GR Amount** unless a controlled rename is proven safe.
- Existing Local PO/GR code must not be duplicated into a parallel master-data subsystem.

### 5.3 Existing Reference Service

`app/Services/LocalInvoice/LocalPoReferenceService.php` currently follows remaining-PO/reference semantics that do not match the final whole-GR model.

It must be refactored so eligibility is based on:

- authoritative PO master state;
- authoritative GR state;
- supplier ownership;
- whole-GR availability;
- current invoice reservation ownership.

### 5.4 Existing Excel and PDF Capability

`composer.json` already provides Excel-related libraries and DomPDF capability. The implementation should reuse the existing stack rather than add a new import or PDF dependency without a demonstrated need.

---

## 6. Scope

### In Scope

- PO master maintenance.
- GR master maintenance.
- Manual PO/GR entry by Finance and Purchasing.
- `.xlsx` PO/GR import.
- Import preview, validation, atomic confirmation, and row-level errors.
- Supplier invoice PO dropdown.
- Supplier invoice GR multi-select/checklist dropdown.
- Whole-GR reservation and consumption.
- Invoice revision/cancellation/rejection/expiry release behavior.
- Finance verification integration.
- Supplier filter on Ready-to-Pay DRP candidate list.
- Per-invoice Voucher Bayar.
- Formal ADASI voucher print layout.
- Payment Settlement per invoice.
- Primary/correction transfer history.
- Accidental short-payment correction.
- Overpayment receivable.
- Full supplier refund settlement.
- Authorization, audit trail, concurrency controls, regression tests.
- Historical reconciliation/cutover strategy.
- `context.md` update.

### Explicit Non-Goals

- Intentional installment payment.
- Partial GR allocation.
- GR remaining balance.
- Multi-PO invoice.
- Multi-currency Local Supplier PO/GR flow.
- Fuzzy Supplier Name matching during import.
- Hard delete for PO or GR.
- Bulk update of existing PO/GR through import.
- Rebuilding unrelated Expense Claim or non-Local-Supplier payment flows.
- Fabricating historic GR amounts from invoice DPP when authoritative data does not exist.

---

## 7. Locked Business Invariants

### 7.1 PO

```text
1 PO -> exactly 1 Supplier
1 PO -> 0..N GR
Currency -> IDR for the new Local Supplier master flow
SUM(non-cancelled GR.received_amount) <= PO.total_amount
```

PO status:

- `OPEN`
- `CLOSED`
- `CANCELLED`

Rules:

- `CLOSED` is a manual business state, not automatic when GR total reaches PO total.
- `CLOSED` blocks new invoice reservations.
- Existing submitted invoices that already own RESERVED GRs continue normally after PO closure.
- `CANCELLED` is used for erroneous or cancelled records when dependency rules allow.
- No hard delete.

### 7.2 GR

GR state:

- `AVAILABLE`
- `RESERVED`
- `INVOICED`
- `CANCELLED`

GR is indivisible:

```text
GR Amount = Rp30,000,000
```

Valid:

```text
Invoice A consumes GR = Rp30,000,000
```

Invalid:

```text
Invoice A consumes Rp20,000,000
Remaining GR = Rp10,000,000
```

There is no `remaining_amount`, `allocated_amount`, or `PARTIALLY_INVOICED` state.

### 7.3 Invoice Reference

```text
1 Invoice -> exactly 1 PO
1 Invoice -> 1..N GR from that PO
SUM(selected full GR amounts) == Invoice DPP
```

All selected GRs must:

- belong to the selected PO;
- belong to the logged-in supplier through the PO;
- not be cancelled;
- be `AVAILABLE`, or already `RESERVED` by the same invoice during revision;
- not be owned by another invoice.

### 7.4 Payment

```text
1 Invoice -> 1 Voucher
1 Invoice -> maximum 1 Payment Settlement
```

A Payment Settlement may contain:

```text
1 PRIMARY transfer
0..N CORRECTION transfers
```

Correction transfers exist only for genuine payment mistakes, not planned installments.

### 7.5 Overpayment

```text
cumulative actual paid > expected net payable
```

Results in:

- invoice/payment settlement finalized;
- invoice status `PAID`;
- separate Supplier Overpayment Receivable for the excess.

The invoice must never be represented as a negative payable balance.

---

## 8. Target End-to-End Workflow

```text
Purchasing / Finance
    |
    +-- Maintain Master PO
    |      |
    |      +-- Add GR progressively as receiving occurs
    |      +-- or import PO/GR via XLSX
    |
Local Supplier
    |
    +-- Create Invoice Draft
    |      |
    |      +-- Select one OPEN PO
    |      +-- Select one or more AVAILABLE GRs via checklist
    |      +-- System shows selected GR total
    |
    +-- Submit Invoice
           |
           +-- transaction + row lock
           +-- validate ownership/PO/GR/state
           +-- validate SUM(GR Amount) == DPP
           +-- GR AVAILABLE -> RESERVED
           |
           +-- Finance Verification
                  |
                  +-- NEED_REVISION -> keep reservation
                  +-- reject/cancel/expiry -> release reservation
                  +-- READY_TO_PAY -> RESERVED -> INVOICED
                         |
                         +-- DRP candidate list
                         |      +-- optional single Supplier filter
                         |
                         +-- DRP Batch / Payment Group
                                |
                                +-- Voucher per Invoice
                                |      +-- final before transfer
                                |
                                +-- Payment Settlement per Invoice
                                       |
                                       +-- PRIMARY transfer
                                       |
                                       +-- if short by mistake:
                                       |      CORRECTION transfer(s)
                                       |
                                       +-- cumulative == expected -> PAID
                                       |
                                       +-- cumulative > expected -> PAID
                                              + Supplier Overpayment Receivable
                                                     |
                                                     +-- one full refund
                                                     +-- proof required
                                                     +-- SETTLED
```

---

## 9. State Models

### 9.1 PO State

```text
OPEN ------> CLOSED
 |
 +---------> CANCELLED
```

Recommended transitions:

- Create -> `OPEN`.
- `OPEN -> CLOSED`: Purchasing or Finance, subject to normal validation.
- `OPEN -> CANCELLED`: only when no reserved/invoiced dependency exists.
- Reopening should not be introduced unless an existing business rule already supports it.

### 9.2 GR State

```text
AVAILABLE -> RESERVED -> INVOICED
    |
    +------> CANCELLED

RESERVED -> AVAILABLE
```

`RESERVED -> AVAILABLE` occurs on:

- invoice cancellation;
- permanent rejection;
- invoice expiry;
- revision when the supplier removes the GR and the replacement set is committed successfully.

### 9.3 Invoice Reservation State Behavior

Draft:

- no reservation;
- supplier may change PO/GR freely.

Submitted:

- selected GRs become `RESERVED`.

Need Revision:

- existing selected GRs remain reserved.

Resubmit:

- unchanged GRs remain reserved;
- removed GRs are released;
- new GRs are reserved;
- all changes occur in one DB transaction.

Expired / cancelled / permanently rejected:

- release all active reservations.

Ready to Pay:

- reserved GRs become `INVOICED`.

### 9.4 Voucher State

Recommended minimum:

```text
DRAFT -> FINAL
```

Voucher can become FINAL before bank transfer.

Once FINAL:

- fields that determine voucher amount are locked;
- supplier/account snapshot is locked;
- voucher number/date are locked;
- actual payment fields remain separate and may still be entered until Payment Settlement finalization.

### 9.5 Payment Settlement State

Recommended:

- `OPEN`
- `CORRECTION_REQUIRED`
- `FINALIZED`

Behavior:

```text
cumulative < expected
    -> OPEN/CORRECTION_REQUIRED
    -> invoice not PAID

cumulative == expected
    -> FINALIZED
    -> invoice PAID

cumulative > expected
    -> FINALIZED
    -> invoice PAID
    -> create overpayment receivable
```

### 9.6 Overpayment Refund State

- `OPEN`
- `SETTLED`

Optional `VOIDED` should only be introduced if an existing finance correction pattern requires it. Do not add it speculatively.

---

## 10. Target Data Model

### 10.1 Existing `local_purchase_orders`

Reuse the existing table.

Canonical business mapping:

- existing `po_number` -> PO Number
- existing `po_date` -> PO Date
- existing `total_amount` -> PO Amount
- existing `currency` -> retained for backward compatibility but forced/treated as `IDR` for the new Local Supplier flow
- existing description field -> Remarks/Description
- supplier relation -> existing Local Supplier/vendor relation

Add where missing:

- status support required by final state model;
- source metadata (`MANUAL` / `IMPORT`) if not already represented;
- `created_by` / `updated_by` if current audit infrastructure does not already provide actor attribution.

Do not add a second master PO table.

### 10.2 Existing `local_goods_receipts`

Reuse the existing table.

Canonical mapping:

- `local_purchase_order_id`
- `gr_number`
- `gr_date`
- `received_amount` -> **GR Amount**
- `notes` -> Remarks

Add where missing:

- `status`: `AVAILABLE|RESERVED|INVOICED|CANCELLED`
- `current_invoice_id` nullable, used as authoritative current reservation/final owner
- `source`: `MANUAL|IMPORT`
- actor audit fields where appropriate

No `remaining_amount`.

### 10.3 Invoice-GR Association / History

Create a dedicated association/history table. Proposed name:

`local_invoice_goods_receipts`

Recommended fields:

```text
id
local_invoice_id
local_purchase_order_id
local_goods_receipt_id
state                 // RESERVED, CONSUMED, RELEASED
gr_number_snapshot
gr_amount_snapshot
reserved_at
consumed_at
released_at
created_at
updated_at
```

Purpose:

- historical evidence of which GRs an invoice referenced;
- revision history when a GR is released and replaced;
- snapshot values for audit;
- separation of current GR owner from historical associations.

Do not apply a uniqueness rule that prevents a RELEASED GR from later being legitimately used by another invoice.

Recommended active-owner enforcement remains on `local_goods_receipts.current_invoice_id` plus row locking/state checks.

### 10.4 Invoice Voucher

Proposed table:

`local_invoice_vouchers`

Recommended fields:

```text
id
local_invoice_id UNIQUE
payment_batch_id
payment_group_id nullable
voucher_number UNIQUE
voucher_date
payment_method        // BANK or KAS
status                // DRAFT, FINAL

supplier_name_snapshot
bank_name_snapshot
bank_account_snapshot
bank_account_holder_snapshot
npwp_snapshot
invoice_number_snapshot
po_number_snapshot
gr_references_snapshot

dpp_snapshot
ppn_snapshot
pph_snapshot
net_payable_snapshot
amount
terbilang_snapshot
remarks_snapshot nullable

finalized_by nullable
finalized_at nullable
created_at
updated_at
```

The exact current voucher-number generator should be extracted/reused from `PaymentVoucherService`; do not define a new numbering scheme.

### 10.5 Local Invoice Payment Settlement

Proposed table:

`local_invoice_payments`

Recommended fields:

```text
id
local_invoice_id UNIQUE
local_invoice_voucher_id UNIQUE
expected_amount DECIMAL(20,2)
actual_paid_total DECIMAL(20,2)
status
finalized_by nullable
finalized_at nullable
created_by
updated_by
created_at
updated_at
```

`actual_paid_total` may be stored as a controlled aggregate or calculated from valid transfer events. If stored, service-level invariants must guarantee it always equals the transfer-event sum.

### 10.6 Payment Transfer Events

Proposed table:

`local_invoice_payment_transfers`

Recommended fields:

```text
id
local_invoice_payment_id
sequence_no
transfer_type          // PRIMARY, CORRECTION
amount DECIMAL(20,2)
transfer_reference
transfer_date
correction_reason nullable
notes nullable
entered_by
created_at
updated_at
```

Rules:

- exactly one PRIMARY transfer maximum;
- CORRECTION requires an existing short-payment condition and mandatory reason;
- payment may have multiple CORRECTION events only if operationally required to fully correct an accidental transfer error;
- transfer reference is not globally unique because one physical bank transfer can cover several invoices.

### 10.7 Supplier Overpayment / Refund

Proposed table:

`supplier_overpayment_refunds`

Recommended fields:

```text
id
local_invoice_payment_id UNIQUE
local_invoice_id
supplier_id
overpayment_amount DECIMAL(20,2)
status               // OPEN, SETTLED
refund_amount nullable
refund_reference nullable
refund_date nullable
proof_path nullable
notes nullable
created_by
settled_by nullable
settled_at nullable
created_at
updated_at
```

Refund rules:

- one refund only;
- refund amount must exactly equal `overpayment_amount`;
- refund reference/date/proof required;
- no partial refund.

---

## 11. Database Constraints and Indexing

Recommended constraints/indexes:

### PO

- unique PO number according to existing business assumption.
- index supplier + status.
- index PO date if the master list will filter by date.

### GR

- index `local_purchase_order_id`.
- index `status`.
- index `current_invoice_id`.
- duplicate rule must reject the same `PO + GR Number`.

The current schema appears to treat GR number as globally unique. Before weakening this to a composite uniqueness rule:

1. reconcile existing data;
2. verify integrations and reports do not depend on global uniqueness;
3. if safe, migrate to composite unique `(local_purchase_order_id, gr_number)`;
4. otherwise keep stronger global uniqueness and still enforce the PO+GR import duplicate rule.

### Invoice-GR History

- index invoice ID.
- index GR ID.
- index state.

### Voucher

- unique invoice ID.
- unique voucher number.
- index batch/group references.

### Payment Settlement

- unique invoice ID.
- unique voucher ID.

### Transfer Events

- index payment ID + sequence.
- index transfer reference, not unique.

### Overpayment

- unique payment ID.
- index supplier + status.

### Delete Semantics

Business records must not be hard-deleted.

Where existing foreign keys use cascade delete, evaluate hardening them to restrictive/no-action semantics for PO/GR/financial records. Do not change unrelated cascade behavior blindly; migration must be validated against existing data and rollback requirements.

---

## 12. Phase 0 — Source/Schema Alignment

This phase is mandatory before adding new domain behavior.

### 12.1 Align PO Model

Reconcile `LocalPurchaseOrder` model with actual migration/schema:

- use `po_date` as canonical database attribute;
- retain compatibility accessors only if existing views/services still use `order_date`;
- standardize casts for `po_date` and `total_amount`;
- confirm supplier relation is authoritative.

### 12.2 Align GR Model

Reconcile `LocalGoodsReceipt` with actual schema:

- use `gr_date` as canonical date;
- use `received_amount` as canonical GR monetary value;
- expose business-friendly accessor/label `GR Amount` in UI rather than creating duplicate monetary columns;
- remove or correct unsupported assumptions such as direct `supplier_id` if supplier ownership is actually inherited through PO.

### 12.3 Regression Protection

Before refactoring reference logic, add characterization tests around current Local Supplier invoice reference behavior so the migration can distinguish intentional behavior changes from accidental regressions.

---

## 13. Master PO / GR Module

### 13.1 Access

Roles:

- Purchasing
- Finance

Both roles can:

- view PO master;
- create PO;
- edit PO subject to constraints;
- close PO;
- cancel eligible PO;
- add GR;
- edit eligible GR;
- cancel eligible GR;
- import PO/GR.

No delete action or DELETE route should be provided.

### 13.2 PO List

Recommended columns:

- PO Number
- Supplier
- PO Date
- PO Amount
- Total Active GR
- Total Active GR Amount
- Status
- Source
- Last Updated
- Actions

Recommended filters:

- PO Number
- Supplier
- Status
- PO Date range

### 13.3 PO Create/Edit

Fields:

- PO Number
- Supplier
- PO Date
- PO Amount
- Remarks/Description

New-flow currency is fixed to IDR and should not be user-editable.

Validation:

- PO Number required/unique.
- Supplier required.
- PO Amount > 0.
- PO date required.

### 13.4 PO Edit Rules

Amount increase:

- allowed.

Amount decrease:

```text
new PO amount >= SUM(non-cancelled GR.received_amount)
```

Supplier or PO-number mutation after dependent GR/invoice usage should be prohibited unless current business processes explicitly support controlled correction. Prefer immutability once referenced.

### 13.5 PO Close

- status `OPEN -> CLOSED`.
- no new invoice/GR reservation for closed PO.
- existing invoice reservations continue.

### 13.6 PO Cancel

Allowed only when there is no active or consumed dependency that would invalidate history.

At minimum reject when:

- any GR is RESERVED;
- any GR is INVOICED;
- an active/final invoice association exists.

### 13.7 GR Management

GR fields:

- GR Number
- GR Date
- GR Amount (`received_amount`)
- Remarks

On creation:

- status `AVAILABLE`.

Validation:

```text
existing non-cancelled GR total + new GR amount <= PO total amount
```

### 13.8 GR Edit

GR amount/date/reference may only be edited when status is `AVAILABLE` and resulting PO cap remains valid.

`RESERVED` and `INVOICED` financial/reference fields are immutable.

### 13.9 GR Cancel

Only `AVAILABLE` GR can be cancelled.

Reject cancellation when GR is:

- RESERVED;
- INVOICED.

---

## 14. XLSX Import

### 14.1 File Format

Supported file:

- `.xlsx`

Use the existing Laravel Excel / PhpSpreadsheet stack.

### 14.2 Single-Sheet Structure

Recommended columns:

| Column | Required | Notes |
|---|---|---|
| PO Number | Yes | PO identifier |
| Supplier Name | Yes | exact supplier mapping |
| PO Date | Yes | authoritative PO date |
| PO Amount | Yes | pre-tax PO amount |
| PO Remarks | No | optional |
| GR Number | Conditional | optional for PO-only row |
| GR Date | Conditional | required if GR Number supplied |
| GR Amount | Conditional | required if GR Number supplied |
| GR Remarks | No | optional |

### 14.3 Supported Row Types

PO-only:

```text
PO fields present
GR fields blank
```

PO + GR:

```text
PO fields present
GR Number/Date/Amount present
```

### 14.4 Existing PO Behavior

Allowed:

```text
existing PO + new GR
```

Rejected:

```text
existing PO + same GR
```

Import is create/add-only. It must not update existing PO/GR values.

### 14.5 Supplier Resolution

Supplier Name matching:

1. trim whitespace;
2. normalized case-insensitive exact match;
3. no fuzzy matching;
4. zero match -> error;
5. more than one match -> error, even though current business assumption states names are unique.

### 14.6 Repeated PO Rows

When a PO repeats for several GR rows, all PO-header values must be identical after normalization.

Conflict example:

```text
Row 2 PO001 Amount 100,000,000
Row 3 PO001 Amount 120,000,000
```

Result: reject workbook.

### 14.7 Atomic Validation

The full workbook must be validated before persistence.

Example:

```text
200 rows uploaded
row 183 invalid
```

Result:

```text
0 rows persisted
```

No partial import.

### 14.8 Validation Rules

Reject for:

- invalid extension;
- missing required PO fields;
- unknown supplier;
- invalid date;
- amount <= 0;
- incomplete GR field set;
- duplicate PO+GR in file;
- duplicate existing PO+GR;
- repeated PO header conflict;
- existing PO incoming header conflict;
- cumulative GR amount exceeding PO amount;
- cancelled/closed PO receiving incompatible imported GR if business status blocks additions.

### 14.9 Preview Flow

```text
Upload -> Validate -> Preview -> Confirm Import
```

Preview summary:

- New PO count
- Existing PO count referenced
- New GR count
- Invalid row count

If any error exists:

- Confirm Import disabled.
- Display exact row number, field, and reason.

### 14.10 Import Transaction

Confirmation must execute in one DB transaction.

Re-run critical duplicate/cap checks inside the transaction to protect against data changing between preview and confirm.

---

## 15. Supplier Submit Invoice — PO and GR Selection

### 15.1 PO Dropdown

Replace free/reference behavior with a searchable dropdown.

Only return PO where:

- PO belongs to current supplier;
- PO status = `OPEN`;
- PO has at least one eligible AVAILABLE GR, or owns RESERVED GRs when editing the same invoice.

### 15.2 GR Dropdown

After PO selection, show a multi-select dropdown with checkbox UI.

Recommended row presentation:

```text
[ ] GR-001 | 10/09/2026 | Rp30,000,000
[ ] GR-002 | 11/09/2026 | Rp20,000,000
```

Show only GR from the selected PO.

### 15.3 Selected GR Summary

Display:

```text
Selected GR Total    Rp50,000,000
Invoice DPP          Rp50,000,000
Status               Match
```

For mismatch:

```text
Selected GR Total    Rp45,000,000
Invoice DPP          Rp50,000,000
Difference           Rp5,000,000
```

Frontend feedback improves usability, but backend validation remains authoritative.

### 15.4 Changing PO

When PO changes:

- clear current GR UI selection;
- reload eligible GRs;
- do not release existing persisted reservations until submit/resubmit transaction commits.

---

## 16. GR Reservation Service

Create a focused service, proposed:

`app/Services/LocalInvoice/LocalGrReservationService.php` `[NEW]`

Responsibilities:

- validate selected GR set;
- lock PO and GR rows;
- reserve GRs on submit;
- reconcile reservations on resubmit;
- release on cancel/reject/expiry;
- consume on Finance Ready-to-Pay transition;
- maintain association/history records.

Do not overload `LocalPoReferenceService` with lifecycle mutation.

### 16.1 Submit Algorithm

Within DB transaction:

1. Lock selected PO.
2. Validate supplier ownership and `OPEN` state.
3. Normalize/deduplicate selected GR IDs.
4. Lock all selected GR rows with `lockForUpdate`.
5. Validate all selected GRs belong to PO.
6. Validate state/owner.
7. Calculate authoritative server-side GR sum using `received_amount`.
8. Validate sum exactly equals invoice DPP.
9. Persist invoice state.
10. Mark GRs `RESERVED` and set `current_invoice_id`.
11. Create reservation history rows.
12. Commit.

### 16.2 Concurrency Requirement

If two requests try to reserve the same GR concurrently:

```text
exactly one request succeeds
second request receives business validation error
```

This must have an automated concurrency/regression test suitable for the project test environment.

---

## 17. Revision, Cancellation, Rejection, and Expiry

### 17.1 Need Revision

Keep current GR reservation.

### 17.2 Resubmit with Same GR

No release/re-reserve churn required; verify ownership remains valid.

### 17.3 Resubmit with Changed GR Set

One transaction:

1. lock current reserved GRs;
2. lock newly requested GRs;
3. validate new complete set;
4. validate new GR sum equals current invoice DPP;
5. release removed GRs;
6. retain unchanged GRs;
7. reserve new GRs;
8. write association/history changes;
9. persist invoice;
10. commit.

Never release the old set before the replacement set has been successfully validated and locked.

### 17.4 Cancel / Permanent Reject

Release active GR reservations atomically with invoice status transition.

### 17.5 Expiry

The system already has invoice expiry behavior.

Integrate GR release into the existing expiry transition path rather than introducing a second independent expiry scheduler.

Expiry transaction must:

- lock invoice;
- lock its RESERVED GRs;
- transition invoice to existing expired state;
- set those GRs back to AVAILABLE;
- clear `current_invoice_id`;
- mark reservation-history rows RELEASED.

---

## 18. Finance Verification Integration

Finance verification retains existing DPP/PPN/PPh/net-payable concepts.

PO/GR values are pre-tax references. Therefore:

```text
SUM(selected GR amounts) == invoice DPP
```

Payment expected amount remains:

```text
verified net payable
= verified DPP
+ verified PPN
- PPh / applicable verified deductions
```

When Finance completes verification and invoice becomes `READY_TO_PAY`:

- lock invoice and RESERVED GRs;
- confirm ownership/state remains valid;
- transition GRs `RESERVED -> INVOICED`;
- retain `current_invoice_id` as final owner/reference;
- mark association rows `CONSUMED`.

Do not consume GR merely when draft/submitted invoice exists.

---

## 19. DRP Supplier Filter

Target: Ready-to-Pay invoice candidate list used when Finance builds DRP.

Add:

```text
Supplier [ searchable single-select dropdown ]
```

Behavior:

- optional;
- server-side query filter;
- one supplier at a time;
- preserves existing search/sort/pagination parameters;
- does not expose non-Ready-to-Pay invoices;
- no changes required to DRP batch semantics.

Do not implement only client-side filtering over the current page.

---

## 20. DRP and Payment-Group Compatibility

Keep:

```text
PaymentBatch -> PaymentGroup -> PaymentItems
```

A DRP can still contain multiple Local Supplier invoices.

Local Supplier invoices in the same DRP can settle independently.

Example:

```text
DRP-001
  Invoice A -> PAID
  Invoice B -> PAID
  Invoice C -> awaiting correction
```

DRP/group completion must not mark Invoice C paid merely because other items finished.

For Local Supplier invoice items, group-level completion should be derived from the underlying invoice settlement state.

Expense Claims and other item types must preserve their current execution behavior unless an explicit compatibility adjustment is necessary.

---

## 21. Voucher Bayar — Functional Design

### 21.1 Ownership

Final rule:

```text
1 Local Supplier Invoice -> 1 Voucher Bayar
```

Do not generate one Local Supplier voucher per PaymentGroup.

### 21.2 Numbering

Preserve the exact existing voucher numbering format/sequence.

Implementation approach:

- extract/reuse current numbering logic from `PaymentVoucherService`;
- make numbering generation reusable for invoice-owned voucher records;
- validate sequence collision behavior under concurrent voucher generation;
- do not invent a new prefix/sequence.

### 21.3 Finalization Timing

Voucher may be FINAL before the bank transfer occurs.

Voucher amount is the approved obligation / verified net payable.

Actual payment errors do not rewrite the final voucher.

### 21.4 Bank / Kas

Render as checkboxes matching the reference design:

```text
[ ] Bank    [ ] Kas
```

Business rule:

- exactly one selected at finalization;
- both selected -> invalid;
- both empty -> invalid.

Although visually checkboxes, behavior is mutually exclusive.

### 21.5 Voucher Field Mapping

Header:

- official ADASI logo
- `PT Astra Daido Steel Indonesia`
- title `VOUCHER BAYAR`
- Voucher Number
- Ref/Batch = DRP batch number
- Tgl. = Voucher Date
- Bank/Kas checkbox

Payee:

- Supplier name
- Amount
- Terbilang

Description:

- Invoice Number
- PO Number
- selected GR references
- optional invoice remarks where appropriate

Financial breakdown:

- DPP
- PPN
- PPh
- Net Payable / Total Voucher

Destination details where available:

- Bank
- Account Number
- Account Holder
- NPWP

Approval/signature area:

- Diterima
- Disetujui
- Diperiksa
- Dibuat

All signature boxes remain blank for manual signature.

Administrative footer:

- Jurnal checkbox
- Cek checkbox
- Posting checkbox
- Filing checkbox

### 21.6 Voucher Snapshots

Final voucher must snapshot displayed financial/master values.

Reason:

- later supplier bank/master edits must not rewrite historical voucher output;
- later PO/GR display changes must not alter the final document;
- finance audit must reproduce the approved document exactly.

---

## 22. Voucher Print Design

### 22.1 Acceptance Standard

The voucher must look like a formal Finance document, not a standard application card printed from the browser.

### 22.2 Layout

Minimum:

- A4 portrait;
- controlled margins;
- print-safe monochrome styling;
- official logo with correct aspect ratio;
- clear document hierarchy;
- tabular numeric alignment;
- no navigation/sidebar/button chrome;
- stable signature block;
- no awkward page breaks.

### 22.3 Print CSS

Use dedicated print stylesheet/template.

At minimum:

```css
@page {
    size: A4;
}
```

Hide non-document UI during print.

Use page-break controls so:

- signature boxes stay together;
- destination bank block is not split;
- totals stay with the line-item table.

### 22.4 PDF Option

Browser A4 printing is the minimum required behavior.

If the existing Finance flow already expects downloadable PDF, reuse the project's existing DomPDF capability. Do not introduce a second PDF library.

---

## 23. Payment Settlement Design

### 23.1 Core Definition

A Payment Settlement is the settlement of one invoice obligation.

```text
1 Invoice -> 1 Payment Settlement
```

This is distinct from physical bank-transfer events.

### 23.2 Expected Amount

On settlement creation:

```text
expected_amount = finalized verified net payable
```

Once voucher is FINAL, the amount-driving invoice values are locked.

### 23.3 Primary Transfer

Normal case:

```text
Expected 100,000,000
PRIMARY  100,000,000
```

Result:

- cumulative = expected;
- settlement FINALIZED;
- invoice PAID.

### 23.4 Accidental Short Transfer

Example:

```text
Expected 100,000,000
PRIMARY   99,000,000
```

This is not a planned installment.

Result:

- settlement `CORRECTION_REQUIRED`;
- invoice not PAID;
- no second Payment Settlement;
- Finance may add CORRECTION transfer event(s).

Then:

```text
PRIMARY      99,000,000
CORRECTION    1,000,000
Cumulative  100,000,000
```

Result:

- FINALIZED;
- invoice PAID.

### 23.5 Prevent Planned Partial Payment

The system cannot perfectly infer human intent from an amount, so the design must make the exception path explicit.

Recommended controls:

- normal PRIMARY payment UI expects the full voucher amount;
- entering a lower actual bank amount requires explicit “actual transfer was short / correction required” handling;
- mandatory reason for short transfer;
- no payment schedule/due-date UI;
- no “remaining payment” DRP item;
- no second settlement row;
- CORRECTION event available only while the same settlement is underpaid.

### 23.6 Wrong Application Input vs Wrong Bank Transfer

Wrong application input:

- bank actually transferred correct value;
- Finance typed wrong amount/reference in application;
- editable before settlement finalization;
- do not create fake correction history.

Wrong actual bank transfer:

- bank actually transferred the wrong value;
- preserve original PRIMARY event;
- add CORRECTION event;
- never rewrite history to make the first transfer appear correct.

### 23.7 Overpayment

Example:

```text
Expected    100,000,000
PRIMARY     100,500,000
Cumulative  100,500,000
```

Result atomically:

- settlement FINALIZED;
- invoice PAID;
- overpayment receivable = 500,000.

Correction overshoot is handled identically:

```text
PRIMARY       99,000,000
CORRECTION     2,000,000
Cumulative   101,000,000
Overpayment    1,000,000
```

---

## 24. Payment Finalization Transaction

Finalization must execute under a DB transaction and appropriate row locks.

Recommended sequence:

1. Lock invoice.
2. Lock voucher.
3. Lock Payment Settlement.
4. Lock/load valid transfer events.
5. Recalculate cumulative paid from transfer events.
6. Compare against expected amount.
7. If below expected: reject finalization / remain correction-required.
8. If equal: mark settlement FINALIZED and invoice PAID.
9. If above: mark settlement FINALIZED and invoice PAID, create overpayment receivable in the same transaction.
10. Update payment item/group completion state as appropriate.
11. Commit.

Never permit:

```text
invoice PAID
but overpayment record creation failed
```

---

## 25. Supplier Overpayment / Refund Module

### 25.1 Access

Finance only.

### 25.2 Register

Recommended list columns:

- Invoice
- Supplier
- Payment Reference(s)
- Expected Amount
- Actual Paid
- Overpayment
- Refund Status
- Refund Date
- Action

Recommended filters:

- Supplier
- Status
- Date
- Invoice/Reference search

### 25.3 Refund Settlement

User requirement: no partial refund.

Validation:

```text
refund_amount == overpayment_amount
```

Required:

- refund date;
- refund reference;
- transfer proof;
- full amount.

Result:

```text
OPEN -> SETTLED
```

Second refund attempt must be rejected.

### 25.4 Proof Storage

Use the project's private document/storage pattern.

Requirements:

- authorization enforced on retrieval;
- failed physical write must not leave a valid financial record;
- DB failure after a new file is written should compensate/remove only the newly written file as appropriate to current storage conventions.

---

## 26. Invoice Detail Enhancements

Show PO/GR references clearly.

After payment:

```text
Expected Amount
Actual Paid Total
Payment Status
Transfer History
```

Transfer history example:

```text
#1 PRIMARY     Rp99,000,000   TRX001   15/09/2026
#2 CORRECTION   Rp1,000,000   TRX002   16/09/2026
```

For overpayment:

```text
Expected       Rp100,000,000
Actual         Rp100,500,000
Overpayment       Rp500,000
Refund Status   OPEN
```

After refund:

```text
Refund Amount   Rp500,000
Reference       RF001
Status          SETTLED
```

---

## 27. Authorization Matrix

| Capability | Purchasing | Finance | Local Supplier |
|---|---:|---:|---:|
| View PO Master | Yes | Yes | No direct master access |
| Create/Edit PO | Yes | Yes | No |
| Close/Cancel eligible PO | Yes | Yes | No |
| Add/Edit eligible GR | Yes | Yes | No |
| Cancel eligible GR | Yes | Yes | No |
| Import PO/GR | Yes | Yes | No |
| View eligible own PO/GR while invoicing | No | No | Yes |
| Submit Invoice | No | No | Yes |
| Finance Verification | No | Yes | No |
| Create DRP | No | Yes | No |
| Finalize Voucher | No | Yes | No |
| Record/Finalize Payment | No | Yes | No |
| Manage Overpayment Refund | No | Yes | No |

All authorization must be server-side.

Hiding a menu/button is not authorization.

Supplier isolation must be revalidated from authenticated supplier ownership, not request IDs alone.

---

## 28. Audit Trail

Capture at minimum:

### PO

- create
- edit
- close
- cancel

### GR

- create
- edit
- cancel
- reserve
- release
- consume

### Import

- actor
- original filename
- preview/confirmation timestamp
- row count
- created PO count
- created GR count
- failed import summary where audit policy requires

### Invoice

- submitted PO/GR set
- revision changes to GR selection
- cancellation/rejection/expiry release

### Voucher

- draft creation
- voucher number assignment
- finalization
- actor/date

### Payment

- primary transfer entry/change before final
- correction transfer with reason
- finalization
- expected vs actual totals

### Overpayment

- receivable creation
- refund settlement
- refund proof metadata/reference

For financial changes, retain before/after values where the existing audit infrastructure supports them.

---

## 29. Transaction Boundaries

The following operations must be transactional:

1. Import confirmation.
2. Invoice submit + GR reservation.
3. Invoice resubmit + release/retain/new reservation reconciliation.
4. Invoice cancel/permanent reject + GR release.
5. Invoice expiry + GR release.
6. Finance transition to Ready-to-Pay + GR consumption.
7. Voucher number assignment/finalization where sequence concurrency matters.
8. Payment finalization + invoice PAID + optional overpayment creation.
9. Refund settlement + financial record/proof metadata persistence.

---

## 30. Idempotency and Concurrency

### 30.1 GR Reservation

Use DB row locking, not just pre-query validation.

Two concurrent submissions for the same GR must not both succeed.

### 30.2 Import Confirmation

Confirmation should carry a validated-preview token/hash or equivalent state where feasible, but must still revalidate critical database constraints inside the transaction.

### 30.3 Voucher Generation

Repeated request must not create duplicate voucher for the same invoice.

Use unique invoice ownership plus transaction-safe numbering.

### 30.4 Payment Settlement

Unique `local_invoice_id` prevents a second settlement.

Repeated finalization call on already FINALIZED settlement must be idempotent/rejected safely without creating duplicate overpayment records.

### 30.5 Refund

Unique payment/overpayment ownership prevents duplicate refund settlement.

---

## 31. Historical Data and Reconciliation

Do not fabricate historical GR allocation.

Specifically, do not infer:

```text
historic GR amount = historic invoice DPP
```

unless supported by authoritative source data.

### 31.1 Reconciliation Report

Before strict cutover, report:

- PO with missing/invalid amounts;
- GR with missing/invalid `received_amount`;
- orphan GR/PO references;
- duplicate GR references;
- Local Invoice legacy reference that cannot map deterministically;
- PO/GR supplier mismatch;
- model/schema attribute mismatch effects;
- current invoice references with no authoritative GR master row.

### 31.2 Grandfathering

Legacy invoices that cannot be deterministically reconstructed should remain readable and follow their existing historical representation.

Strict new multi-GR rules apply to new/cutover transactions only.

Do not rewrite history simply to satisfy the new schema.

---

## 32. Migration Strategy

### Stage A — Backward-Compatible Schema

Add new status/owner/history/voucher/payment/refund structures without immediately breaking legacy reads.

### Stage B — Source/Model Alignment

Correct PO/GR model mapping and add characterization tests.

### Stage C — Master Data

Release PO/GR master UI and import.

Allow Purchasing/Finance to populate and reconcile authoritative data.

### Stage D — Strict Invoice Reference Cutover

Enable:

- PO dropdown;
- multi-GR checklist;
- exact DPP/GR validation;
- reservation lifecycle.

### Stage E — Finance Enhancements

Enable:

- DRP Supplier filter;
- per-invoice voucher;
- settlement/correction flow;
- overpayment/refund.

### Stage F — Final Reconciliation and Cleanup

Remove obsolete code paths only after proving they are unused and all supported legacy data remains readable.

---

## 33. Detailed Implementation Phases

### Phase 0 — Baseline and Safety Net

Deliverables:

- baseline commit recorded;
- current tests run and results recorded;
- source/schema mismatch characterization tests;
- no functional change yet.

### Phase 1 — PO/GR Model Alignment

Deliverables:

- `LocalPurchaseOrder` mapping corrected;
- `LocalGoodsReceipt` mapping corrected;
- canonical amount/date usage established;
- backward-compatible accessors only where necessary.

### Phase 2 — Master PO/GR State and Audit

Deliverables:

- statuses;
- source metadata;
- current invoice ownership for GR;
- actor audit support;
- no-delete behavior.

### Phase 3 — Master PO/GR UI

Deliverables:

- list/detail/create/edit;
- add/edit/cancel GR;
- close/cancel PO;
- role authorization.

### Phase 4 — XLSX Import

Deliverables:

- template;
- upload;
- preview;
- exact supplier mapping;
- atomic confirm;
- row-level error UI;
- audit.

### Phase 5 — Invoice/GR Association and Reservation

Deliverables:

- history table/model;
- reservation service;
- concurrency protection;
- release/consume operations.

### Phase 6 — Submit Invoice UI/API

Deliverables:

- PO dropdown;
- GR checklist dropdown;
- selected total;
- DPP match validation;
- supplier isolation;
- one-PO rule.

### Phase 7 — Revision/Expiry/Verification Integration

Deliverables:

- NEED_REVISION reservation retention;
- resubmit reconciliation;
- expiry release;
- cancel/reject release;
- Ready-to-Pay consumption.

### Phase 8 — DRP Supplier Filter

Deliverables:

- single-select filter;
- server-side query;
- pagination/query preservation.

### Phase 9 — Per-Invoice Voucher

Deliverables:

- voucher model/table;
- reuse numbering generator;
- snapshot data;
- Bank/Kas validation;
- finalization lock rules.

### Phase 10 — Formal Voucher Layout

Deliverables:

- official logo;
- A4 print template;
- financial breakdown;
- signature boxes;
- administrative checkboxes;
- print QA.

### Phase 11 — Invoice Payment Settlement

Deliverables:

- one settlement per invoice;
- PRIMARY transfer;
- exact-payment finalization;
- group/item compatibility.

### Phase 12 — Correction Transfer

Deliverables:

- short-payment exception state;
- CORRECTION events;
- mandatory reason;
- cumulative calculation;
- intentional partial payment blocked.

### Phase 13 — Overpayment and Refund

Deliverables:

- atomic overpayment creation;
- Finance register;
- one full refund;
- proof/reference/date requirements;
- invoice detail integration.

### Phase 14 — Regression and Cutover

Deliverables:

- full targeted test suite;
- existing payment/claim regressions;
- reconciliation report;
- staged enablement;
- `context.md` update.

---

## 34. Existing File Impact Map

Verified existing files likely to change:

- `app/Models/LocalPurchaseOrder.php`
- `app/Models/LocalGoodsReceipt.php`
- `app/Models/LocalInvoice.php`
- `app/Services/LocalInvoice/LocalPoReferenceService.php`
- `app/Services/Payment/PaymentVoucherService.php`
- `app/Services/Payment/PaymentExecutionService.php`
- `app/Http/Controllers/LocalSupplier/InvoiceController.php`
- Finance controllers under `app/Http/Controllers/Finance/`
- relevant Local Supplier invoice views under `resources/views/`
- Finance DRP/payment views under `resources/views/`
- route definitions for Local Supplier / Finance / Purchasing areas
- existing Local Supplier/payment tests
- `context.md`

Existing migrations relevant to compatibility:

- `database/migrations/2026_09_08_000002_create_local_invoice_domain.php`
- `database/migrations/2026_09_11_000002_create_vendor_master_v2_tables.php`
- `database/migrations/2026_09_11_000003_add_v2_fields_to_local_invoices_table.php`
- `database/migrations/2026_09_11_000006_create_unified_payment_batches_tables.php`
- `database/migrations/2026_09_14_000001_harden_local_invoice_and_payment_invariants.php`

Do not edit old production migrations unless the project explicitly follows that convention and migration history proves it is safe. Prefer new forward migrations.

---

## 35. Proposed New Modules / Files

Names below are proposed and should follow existing project namespace conventions during execution.

### Models

- `[NEW] LocalInvoiceGoodsReceipt`
- `[NEW] LocalInvoiceVoucher`
- `[NEW] LocalInvoicePayment`
- `[NEW] LocalInvoicePaymentTransfer`
- `[NEW] SupplierOverpaymentRefund`

### Services

- `[NEW] app/Services/LocalInvoice/LocalGrReservationService.php`
- `[NEW] Master PO/GR service(s)` if existing controller/service conventions require them
- `[NEW] PO/GR Import service`
- `[NEW] LocalInvoiceVoucherService` or focused extension around current `PaymentVoucherService`
- `[NEW] LocalInvoicePaymentService`
- `[NEW] SupplierOverpaymentService`

### Requests

Create dedicated Form Requests for:

- PO create/edit;
- GR create/edit;
- import upload/confirm;
- invoice PO/GR submission;
- voucher finalization;
- payment transfer entry;
- correction transfer;
- refund settlement.

### Views

- Master PO list/detail/form
- import upload/preview
- invoice PO/GR selector enhancement
- formal voucher print template
- payment settlement detail/history
- overpayment register/detail/refund form

Do not place substantial business invariants directly in Blade templates or controllers.

---

## 36. Validation Matrix

### PO

- valid supplier required;
- amount > 0;
- new amount cannot be lower than active GR total;
- no hard delete;
- cancel blocked by active/consumed dependencies.

### GR

- belongs to PO;
- amount > 0;
- cumulative active GR <= PO amount;
- editable only when AVAILABLE;
- cancel only when AVAILABLE.

### Invoice

- exactly one PO;
- at least one GR;
- no duplicate GR in payload;
- all GRs from selected PO;
- all owned by supplier;
- whole GR only;
- total GR amount = DPP;
- state/owner validation under lock.

### Voucher

- one voucher per invoice;
- invoice eligible for voucher;
- exactly one Bank/Kas selected;
- voucher amount from verified net payable;
- final snapshots complete.

### Payment

- one settlement per invoice;
- settlement linked to final voucher;
- one PRIMARY transfer max;
- correction only for underpaid open settlement;
- correction reason required;
- finalized settlement immutable.

### Refund

- overpayment exists and OPEN;
- Finance role;
- exact full refund amount;
- proof/reference/date required;
- second settlement rejected.

---

## 37. Automated Test Plan

### 37.1 Master PO Tests

- Finance can create PO.
- Purchasing can create PO.
- unauthorized role denied.
- duplicate PO rejected.
- PO amount must be positive.
- PO amount can increase.
- PO amount cannot decrease below non-cancelled GR total.
- PO can close.
- closed PO unavailable for new invoice reservation.
- existing reserved invoice can continue after PO close.
- eligible unused PO can cancel.
- PO with RESERVED/INVOICED dependency cannot cancel.
- no delete endpoint/action.

### 37.2 GR Tests

- add multiple GR to one PO.
- GR amount > 0.
- cumulative GR == PO amount allowed.
- cumulative GR > PO amount rejected.
- AVAILABLE GR editable.
- RESERVED GR amount immutable.
- INVOICED GR amount immutable.
- AVAILABLE GR can cancel.
- RESERVED/INVOICED GR cannot cancel.

### 37.3 Import Tests

- valid PO-only row imports.
- valid PO+GR row imports.
- repeated PO with different new GR imports.
- existing PO + new GR imports.
- existing PO + existing GR rejects.
- duplicate same PO+GR inside workbook rejects.
- conflicting repeated PO header rejects.
- unknown supplier rejects.
- case-insensitive exact Supplier Name resolves.
- incomplete GR columns reject.
- invalid dates/amounts reject.
- cumulative GR cap violation rejects.
- one invalid row rolls back entire import.
- import cannot update existing PO/GR values.

### 37.4 Invoice Selection Tests

- supplier sees only own eligible OPEN PO.
- supplier cannot access another supplier PO by crafted ID.
- closed/cancelled PO rejected server-side.
- only eligible GRs returned.
- multiple GR selection allowed.
- different-PO GR rejected.
- duplicate GR IDs rejected.
- selected GR total below DPP rejected.
- selected GR total above DPP rejected.
- selected GR total exactly DPP succeeds.
- no partial GR amount input exists.

### 37.5 Reservation Tests

- draft does not reserve.
- submit reserves selected GRs.
- NEED_REVISION preserves reservation.
- same-GR resubmit remains valid.
- changed-GR resubmit atomically releases/reserves.
- permanent rejection releases.
- cancellation releases.
- existing invoice expiry releases.
- Ready-to-Pay consumes reservation to INVOICED.

### 37.6 Concurrency Tests

Simulate two submissions targeting the same AVAILABLE GR.

Expected:

- one commits;
- one fails with deterministic business error;
- GR has exactly one owner;
- no duplicate active association.

### 37.7 DRP Filter Tests

- no Supplier filter preserves existing candidates.
- selected supplier returns only its READY_TO_PAY invoices.
- other statuses excluded.
- query preserved across pagination.
- user cannot inject inaccessible supplier data.

### 37.8 Voucher Tests

- one invoice creates one voucher.
- duplicate voucher rejected/idempotent.
- existing numbering format preserved.
- Ref/Batch equals DRP batch reference.
- voucher date stored correctly.
- exactly one Bank/Kas required.
- amount equals verified net payable.
- final voucher snapshot unaffected by later supplier master edit.
- voucher can finalize before transfer.
- amount-driving invoice fields blocked after voucher finalization.
- print route authorization enforced.

### 37.9 Payment Settlement Tests

- one settlement per invoice.
- second settlement creation rejected.
- one PRIMARY transfer max.
- exact PRIMARY payment finalizes and marks invoice PAID.
- short PRIMARY does not mark invoice PAID.
- short PRIMARY changes settlement to correction-required.
- correction can close exact shortfall.
- multiple corrections allowed only while underpaid, if implementation retains N corrections.
- correction requires reason.
- planned installment workflow does not exist.
- same bank transfer reference can appear against multiple invoice settlements.
- data-entry fields editable before finalization.
- finalized settlement immutable.

### 37.10 Overpayment Tests

- PRIMARY over expected creates overpayment atomically.
- correction overshoot creates correct overpayment difference.
- invoice remains PAID.
- no negative payable balance created.
- duplicate overpayment record prevented.

### 37.11 Refund Tests

- Finance can view overpayment register.
- non-Finance denied.
- partial refund rejected.
- refund below amount rejected.
- refund above amount rejected.
- exact full refund accepted.
- proof required.
- refund reference required.
- refund date required.
- status becomes SETTLED.
- second refund attempt rejected.

### 37.12 Regression Tests

Must include current flows for:

- PaymentBatch creation;
- PaymentGroup creation/grouping;
- non-Local-Supplier payable items;
- Expense Claims;
- current Finance verification rules;
- supplier isolation;
- invoice revision behavior;
- existing attachments/private documents;
- current audit history.

---

## 38. Manual QA Scenarios

### Scenario A — Normal Invoice

1. Purchasing creates PO Rp100m.
2. Adds GR1 Rp40m, GR2 Rp60m.
3. Supplier selects PO and both GRs.
4. DPP = Rp100m.
5. Submit succeeds.
6. Finance verifies.
7. DRP generated.
8. Voucher finalized.
9. Transfer exact net payable.
10. Payment finalized.
11. Invoice PAID.

### Scenario B — One GR Reused

1. Invoice A reserves GR1.
2. Supplier attempts Invoice B with same GR1.
3. GR unavailable / server rejects crafted request.

### Scenario C — Revision

1. Invoice reserves GR1 + GR2.
2. Finance requests revision.
3. Supplier replaces GR2 with GR3.
4. Transaction validates complete set.
5. GR2 becomes AVAILABLE.
6. GR1 remains RESERVED.
7. GR3 becomes RESERVED.

### Scenario D — Expiry

1. Submitted invoice owns GR1.
2. Existing expiry process runs.
3. Invoice expires.
4. GR1 becomes AVAILABLE.

### Scenario E — Accidental Short Transfer

1. Voucher = Rp100m.
2. Actual PRIMARY transfer = Rp99m.
3. Settlement shows CORRECTION_REQUIRED.
4. Invoice remains unpaid.
5. Finance records corrective transfer Rp1m.
6. Total reaches Rp100m.
7. Settlement finalizes.
8. Invoice PAID.

### Scenario F — Overpayment

1. Voucher = Rp100m.
2. Actual payment = Rp100.5m.
3. Invoice PAID.
4. Overpayment register shows Rp0.5m OPEN.
5. Supplier refunds Rp0.5m once.
6. Finance uploads proof.
7. Refund becomes SETTLED.

---

## 39. Security Requirements

- Supplier ownership checks must be server-side on every PO/GR lookup and invoice submission.
- Do not rely on Hashids or dropdown visibility as authorization.
- Import is limited to Finance/Purchasing roles.
- Uploaded XLSX must use project file validation limits and safe parsing.
- Refund proof stored privately.
- Voucher/payment/refund endpoints Finance-only.
- Protect state-changing routes from IDOR.
- Do not accept client-calculated GR totals; always recalculate from DB.
- Do not accept client-calculated expected payment; derive from final Finance-verified invoice values.
- Use transactions and row locking for financial/state transitions.

---

## 40. Performance Considerations

Add indexes for common filters/joins rather than loading all rows and filtering in PHP.

Particularly:

- PO supplier/status;
- GR PO/status/current owner;
- invoice status/supplier;
- Payment Item payable reference;
- settlement invoice ID;
- transfer settlement ID;
- overpayment supplier/status.

Supplier PO/GR dropdown endpoints should return only eligible rows and support searching/pagination if master volume warrants it.

Avoid N+1 queries in:

- PO master list GR totals;
- invoice detail GR references;
- DRP candidate list;
- voucher rendering;
- overpayment register.

---

## 41. Observability and Error Handling

Business errors should be explicit and actionable.

Examples:

```text
GR GR-001 is already reserved by another invoice.
```

```text
Selected GR total is Rp45,000,000 while invoice DPP is Rp50,000,000.
```

```text
PO PO-001 cannot be cancelled because it contains an invoiced GR.
```

```text
Payment cannot be finalized. Cumulative actual payment is Rp99,000,000 while expected payment is Rp100,000,000.
```

Import errors must include row number and field.

Unexpected technical errors must use existing application logging/error reporting without exposing stack traces to end users.

---

## 42. Risks and Mitigations

### Risk: Model/schema mismatch causes silent wrong-field behavior

Mitigation:

- mandatory Phase 0 alignment;
- characterization tests;
- explicit canonical attribute mapping.

### Risk: Existing payment flows regress

Mitigation:

- preserve PaymentBatch/PaymentGroup architecture;
- add Local Supplier settlement behavior additively;
- comprehensive Expense Claim/non-local regression tests.

### Risk: Same GR used twice under concurrent submit

Mitigation:

- DB transaction + row locks + current owner/state validation.

### Risk: Historic data cannot map to new GR model

Mitigation:

- reconciliation report;
- no fabricated backfill;
- grandfather legacy records.

### Risk: Voucher numbering collision when moving from group to invoice

Mitigation:

- reuse centralized existing generator;
- transactional number assignment;
- unique constraint;
- concurrency tests.

### Risk: Correction transfer is abused as planned partial payment

Mitigation:

- no installment UX;
- normal expected payment is full voucher value;
- mandatory correction reason;
- correction only available after recorded underpayment exception;
- audit trail.

### Risk: PO/GR imported while another user edits master data

Mitigation:

- revalidate on import confirmation inside transaction;
- use unique/foreign-key constraints.

---

## 43. Recommended Implementation Order

1. Freeze/record baseline and test results.
2. Align Local PO/GR models with actual schema.
3. Add new master statuses/ownership/audit schema.
4. Implement Master PO/GR domain services and authorization.
5. Implement Master PO/GR UI.
6. Implement XLSX import preview/confirm.
7. Add invoice-GR association/history.
8. Implement GR reservation service and locks.
9. Refactor `LocalPoReferenceService` to authoritative PO/GR availability.
10. Implement Supplier PO dropdown + GR checklist UI/API.
11. Integrate revision/cancel/reject/expiry lifecycle.
12. Integrate Finance Ready-to-Pay GR consumption.
13. Add DRP Supplier filter.
14. Add invoice voucher model/snapshots.
15. Extract/reuse voucher number generator.
16. Implement formal Voucher Bayar template.
17. Implement invoice Payment Settlement.
18. Add PRIMARY transfer behavior.
19. Add correction-required/corrective transfer behavior.
20. Add overpayment receivable.
21. Add Finance overpayment/refund register and full-refund workflow.
22. Run full targeted + regression tests.
23. Produce reconciliation report and complete data preparation.
24. Stage production cutover.
25. Update `context.md`.

---

## 44. Engineering Checkpoints

### Checkpoint 1 — Schema Integrity

Must pass before UI work:

- model/schema field alignment verified;
- migrations apply/rollback cleanly;
- foreign keys/indexes valid;
- legacy data preserved.

### Checkpoint 2 — Master Data Integrity

Must pass before strict invoice cutover:

- PO/GR CRUD-without-delete works;
- amount cap rules work;
- import atomicity proven;
- authoritative PO/GR data populated.

### Checkpoint 3 — Invoice Reservation Integrity

Must pass before supplier rollout:

- exact DPP/GR match;
- supplier isolation;
- concurrency test;
- revision/expiry release.

### Checkpoint 4 — Payment Integrity

Must pass before Finance rollout:

- per-invoice voucher;
- one settlement per invoice;
- exact/short/over payment paths;
- existing payment types regression-clean.

### Checkpoint 5 — Financial Document QA

Must pass before release:

- voucher A4 print review;
- official logo;
- number/date/batch mapping;
- financial arithmetic;
- signature/check boxes;
- snapshot stability.

---

## 45. Definition of Done

Implementation is complete only when all of the following are true:

### PO / GR

- [ ] Finance and Purchasing can maintain PO/GR.
- [ ] No PO/GR hard-delete path exists.
- [ ] PO/GR cancellation follows dependency rules.
- [ ] One PO supports many GR.
- [ ] Active GR total cannot exceed PO amount.
- [ ] GR is indivisible and has no remaining amount.
- [ ] GR amount/date state is protected after reservation/consumption.

### Import

- [ ] `.xlsx` import works.
- [ ] PO-only row works.
- [ ] existing PO + new GR works.
- [ ] duplicate PO+GR rejects.
- [ ] Supplier Name exact mapping works.
- [ ] whole workbook is atomic.
- [ ] preview and row errors are clear.

### Invoice

- [ ] Supplier sees only its eligible PO.
- [ ] PO is dropdown.
- [ ] GR is multi-select checklist dropdown.
- [ ] invoice references exactly one PO.
- [ ] invoice references one or more full GRs.
- [ ] selected GR total must equal DPP.
- [ ] duplicate/concurrent GR usage is prevented.
- [ ] revision lifecycle is correct.
- [ ] existing invoice expiry releases GR.
- [ ] Ready-to-Pay consumes GR.

### DRP

- [ ] Ready-to-Pay has single Supplier filter.
- [ ] filtering is server-side and pagination-safe.
- [ ] existing DRP batching/grouping remains intact.

### Voucher

- [ ] voucher is per invoice.
- [ ] existing numbering format retained.
- [ ] voucher can finalize before transfer.
- [ ] Ref/Batch = DRP batch.
- [ ] date = voucher date.
- [ ] Bank/Kas checkbox behavior is mutually exclusive.
- [ ] official ADASI logo present.
- [ ] formal A4 layout passes print review.
- [ ] blank signature boxes match requirement.
- [ ] final voucher uses snapshots.

### Payment

- [ ] one invoice has one Payment Settlement maximum.
- [ ] planned installments are not supported.
- [ ] one normal PRIMARY transfer completes exact payment.
- [ ] genuine short transfer remains correction-required.
- [ ] CORRECTION event stays inside same settlement.
- [ ] cumulative exact payment finalizes invoice.
- [ ] overpayment finalizes invoice and creates separate receivable.
- [ ] finalized settlement is immutable.

### Refund

- [ ] Finance-only overpayment register exists.
- [ ] refund cannot be partial.
- [ ] exact full refund is required.
- [ ] reference/date/proof are mandatory.
- [ ] refund settles once only.

### Regression / Documentation

- [ ] Existing Expense Claim/payment behavior passes regression tests.
- [ ] Supplier authorization/isolation remains intact.
- [ ] legacy Local Supplier records remain readable.
- [ ] no fabricated historical allocation is introduced.
- [ ] reconciliation/cutover completed.
- [ ] `context.md` updated to final implemented behavior.

---

## 46. Requirement Traceability Matrix

| Requirement | Implementation Area |
|---|---|
| Submit Invoice adds PO dropdown and GR checklist | Supplier Invoice UI/API, PO reference service, GR reservation service |
| One PO can contain multiple GR | Master PO/GR model, PO detail, import |
| Master PO maintained by Finance + Purchasing | Master module + authorization |
| Import Master PO/GR | XLSX import service + preview/atomic confirm |
| Supplier filter on Ready-to-Pay DRP | Finance DRP query/UI |
| Generate formal Voucher Bayar | Per-invoice voucher model/service + A4 print template |
| Payment difference / correction | Payment Settlement + PRIMARY/CORRECTION transfer events |
| One invoice must be paid as one payment | Unique invoice Payment Settlement; no installment model |
| Accidental short transfer can be corrected | Correction-required state + corrective transfer history |
| GR must be paid/used whole | No remaining allocation; exact sum of full GR values = DPP |
| Overpayment must be recovered from supplier | Separate overpayment/refund module |
| Refund cannot be partial | Exact full-refund validation |
| Existing voucher number retained | Reuse/extract existing number generator |
| Voucher uses ADASI logo/formal design | Dedicated print template |

---

## 47. `context.md` Update Checklist

After implementation, update `context.md` to document:

- authoritative PO Master;
- Finance/Purchasing ownership of Master PO/GR;
- progressive GR creation;
- XLSX import behavior;
- one PO + multiple whole GR invoice reference;
- GR states/reservation lifecycle;
- no GR remaining amount;
- expiry release behavior;
- Ready-to-Pay consumption;
- DRP Supplier filter;
- per-invoice voucher;
- voucher snapshot/finalization behavior;
- one Payment Settlement per invoice;
- PRIMARY vs CORRECTION transfer events;
- no planned installment;
- overpayment receivable;
- full supplier refund flow;
- new models/services/routes;
- updated authorization matrix;
- updated tests and invariants.

Remove or revise old wording that implies:

- PO remaining-value allocation is the primary invoice reference mechanism;
- only one GR reference per invoice;
- partial GR use;
- voucher ownership is necessarily one-per-payment-group for Local Supplier invoices;
- group payment alone is sufficient to mark Local Supplier invoice paid.

---

## 48. Implementation Constraints — Do Not

- Do not create a parallel PO/GR master table if the existing domain can be safely extended.
- Do not introduce GR `remaining_amount`.
- Do not support split/partial GR allocation.
- Do not allow one invoice to reference multiple POs.
- Do not add planned installment payment.
- Do not create a second Payment Settlement for correction.
- Do not overwrite a real short bank-transfer event to make history appear correct.
- Do not represent overpayment as a negative invoice balance.
- Do not allow partial supplier refund.
- Do not break Expense Claim or other payment item types.
- Do not rely on UI hiding for authorization.
- Do not rely on client totals for GR/payment validation.
- Do not hard-delete PO/GR/financial records.
- Do not fuzzy-match supplier names during import.
- Do not partially import a workbook after a validation failure.
- Do not fabricate historic GR data.
- Do not change the existing voucher numbering scheme without explicit new business approval.
- Do not add dependencies when the current stack already provides the required Excel/PDF capability.

---

## 49. Final Handoff Checklist for Execution Agent

Before coding:

- [ ] Checkout `local-supplier-update`.
- [ ] Confirm HEAD still matches baseline or document drift.
- [ ] Read current `context.md`.
- [ ] Run baseline tests.
- [ ] Inspect actual current migrations/models before editing.

During coding:

- [ ] Follow phased order in this plan.
- [ ] Keep migrations forward-only and backward-compatible where practical.
- [ ] Preserve unrelated behavior.
- [ ] Add tests with each domain change.
- [ ] Use transactions/locks for reservations and financial finalization.
- [ ] Keep controllers thin; place invariants in services/domain layer.

Before completion:

- [ ] Run targeted Master PO/GR/import tests.
- [ ] Run invoice reservation/concurrency tests.
- [ ] Run voucher/payment/refund tests.
- [ ] Run existing payment/Expense Claim regressions.
- [ ] Perform A4 voucher print QA.
- [ ] Run reconciliation report.
- [ ] Confirm legacy records remain readable.
- [ ] Update `context.md`.
- [ ] Provide changed-file list, migration notes, test evidence, and unresolved risks.

---

## 50. Final Architecture Summary

The final domain should be understood as:

```text
PO Master
  1
  |
  N
GR Master (whole/indivisible)
  |
  | reserve/consume
  N
Invoice-GR History
  |
  N
Local Invoice
  |
  +-- exactly one PO
  +-- one or more whole GR
  +-- DPP = SUM(GR Amount)
  |
  +-- DRP Payment Item
         |
         +-- one Invoice Voucher
         |
         +-- one Payment Settlement
                |
                +-- one PRIMARY transfer
                +-- optional CORRECTION transfer(s) for genuine error
                |
                +-- cumulative == expected -> PAID
                +-- cumulative > expected -> PAID + Overpayment
                                                   |
                                                   +-- one full Supplier Refund
```

This architecture keeps PO/GR reference integrity, payment auditability, and the existing DRP container model separate. That separation is the central design decision that prevents the new Local Supplier requirements from destabilizing the broader payment subsystem.
