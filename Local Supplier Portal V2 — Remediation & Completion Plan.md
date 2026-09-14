# Local Supplier Portal V2 — Remediation & Completion Plan

## 1. Objective

Melakukan remediation terhadap implementasi `local-supplier-update` agar seluruh flow Local Supplier / Finance / Purchasing / GA benar-benar sesuai implementation plan final, aman secara finansial, tidak memiliki bypass workflow, dan dapat digunakan end-to-end melalui aplikasi.

Repository:

`https://github.com/poyipoy/supplierportal`

Target branch:

`local-supplier-update`

Reviewed implementation HEAD:

`7d068473ea7ce0bc8783dc0d898a7f5160040746`

Plan ini bersifat **incremental remediation**, bukan rewrite.

Seluruh existing Import Procurement domain harus tetap dipertahankan tanpa behavioral regression.

---

# 2. Core Rules

Implementasi revisi harus mengikuti prinsip berikut:

1. Current source code menjadi authority untuk memahami existing implementation.
2. Implementation plan final Local Payables menjadi authority untuk target behavior.
3. Jangan membuat parallel workflow baru jika existing V2 workflow dapat diperbaiki.
4. Jangan mempertahankan legacy financial mutation hanya demi backward compatibility jika dapat melewati workflow V2.
5. Semua financial state transition harus server-authoritative.
6. UI tidak boleh menjadi satu-satunya authorization atau validation boundary.
7. Semua perubahan financial state harus transactional.
8. Gunakan `lockForUpdate()` untuk resource yang memerlukan serialization.
9. Money tidak boleh dihitung menggunakan floating-point untuk keputusan bisnis kritis.
10. Private document tidak boleh diekspos melalui public/direct storage URL.
11. Semua perubahan schema menggunakan forward migration baru.
12. Jangan mengedit migration yang sudah dapat pernah dijalankan di environment lain.
13. Jangan commit, push, merge, atau deploy.
14. Jangan mengubah file Import Procurement yang tidak diperlukan.
15. Jangan melakukan unrelated refactor.

---

# 3. Target Architecture

Final Local Payment bounded context:

```text
ADASI Supplier Portal
│
├── Import Procurement
│   └── Existing flow — MUST remain unchanged
│
└── Local Payables
    │
    ├── Supplier
    │   ├── Home
    │   ├── Submit Invoice
    │   ├── Tanda Terima
    │   └── Track Invoice
    │
    ├── Purchasing
    │   ├── Vendor Master coordination
    │   └── Invoice Verification — READ ONLY
    │
    ├── Finance
    │   ├── Dashboard
    │   ├── Invoice Register
    │   ├── Invoice Verification
    │   ├── DRP Supplier
    │   ├── DRP GA
    │   ├── DRP Paid
    │   ├── Master Invoice
    │   └── Master Vendor
    │
    └── GA
        ├── Invoice GA
        ├── Tanda Terima GA
        ├── Detail / Basic Verification
        ├── DRP GA Draft
        └── DRP Paid
```

---

# 4. Final Supplier Invoice State Machine

Canonical state machine:

```text
WAITING_PHYSICAL_DOCUMENT
        │
        ├── physical received
        ▼
UNDER_VERIFICATION
        │
        ├── verification failed
        ▼
NEED_REVISION
        │
        └── supplier resubmit
                │
                ▼
WAITING_PHYSICAL_DOCUMENT

UNDER_VERIFICATION
        │
        ├── Section A passed
        ├── Section B passed
        └── verification locked
                │
                ▼
READY_TO_PAY
        │
        └── included in DRP
                │
                ▼
PAID
```

Additional terminal states:

```text
REJECTED
EXPIRED
```

Legacy states:

```text
UNDER_REVIEW
APPROVED
PAYMENT_SCHEDULED
COMPLETED
```

tidak boleh lagi menjadi active runtime workflow states setelah migration/remediation selesai.

---

# 5. Phase 0 — Release Blocker Remediation

Phase ini wajib selesai sebelum pekerjaan feature completion berikutnya.

## P0-01 — Disable Legacy Accounting Mutation Workflow

### Problem

Legacy `/accounting/*` masih dapat menjalankan:

```text
verifyPhysical
startReview
approve
schedulePayment
completePayment
```

dan berpotensi melewati Section A, Section B, Ready-to-Pay, dan DRP.

### Required Change

Hapus atau nonaktifkan seluruh mutation legacy Local Invoice.

Legacy Accounting routes yang hanya digunakan untuk compatibility/navigation boleh redirect ke Finance jika masih dibutuhkan.

Mutation berikut harus tidak dapat digunakan:

```text
POST /accounting/.../physical-verification
POST /accounting/.../start-review
POST /accounting/.../approve
POST /accounting/.../schedule-payment
POST /accounting/.../complete-payment
```

Legacy workflow service tidak boleh lagi menjadi active business path.

### Accounting Role

Existing database users:

```text
accounting → finance
```

Setelah migration:

- user baru tidak boleh dibuat dengan role `accounting`;
- edit user tidak boleh memilih `accounting`;
- middleware baru tidak perlu mengizinkan accounting;
- helper Local Operator harus menjadikan Finance sebagai canonical operator.

Compatibility terhadap historical audit data boleh dipertahankan tanpa mempertahankan active role.

### Acceptance Criteria

- Finance tidak dapat mengubah Local Invoice melalui legacy Accounting route.
- Tidak ada route yang dapat menghasilkan `APPROVED`, `PAYMENT_SCHEDULED`, atau `COMPLETED`.
- Semua Finance mutation menggunakan V2 workflow.

---

## P0-02 — Fix Legacy Status Migration

### Problem

Existing enum lama belum mengenal:

```text
UNDER_VERIFICATION
READY_TO_PAY
PAID
```

tetapi migration mencoba meng-update row ke state tersebut terlebih dahulu.

### Required Migration Strategy

Buat migration corrective baru.

Urutan:

```text
1. Expand status enum:
   old states + new states.

2. Convert legacy rows:
   UNDER_REVIEW      → UNDER_VERIFICATION
   APPROVED          → READY_TO_PAY
   PAYMENT_SCHEDULED → READY_TO_PAY
   COMPLETED         → PAID

3. Validate no unexpected old runtime states remain.

4. Jika aman, migration terpisah dapat tighten enum
   menjadi canonical V2 states.
```

Jangan bergantung pada SQL mode permissive.

### Migration Test

Buat integration migration test:

```text
create schema/state legacy
insert:
- UNDER_REVIEW
- APPROVED
- PAYMENT_SCHEDULED
- COMPLETED

run corrective migration

assert:
- UNDER_VERIFICATION
- READY_TO_PAY
- READY_TO_PAY
- PAID
```

Fresh migration test saja tidak cukup.

---

## P0-03 — Fix PARTIALLY_PAID Double DRP Reservation

### Business Invariant

Satu payable hanya boleh berada dalam **satu active DRP**.

Active DRP status:

```text
DRAFT
FINALIZED
PARTIALLY_PAID
```

`PAID` dan `CANCELLED` tidak lagi mereservasi payable.

### Required Change

Centralize definition:

```php
PaymentBatch::ACTIVE_STATUSES
```

atau equivalent domain helper.

Gunakan helper yang sama pada:

- Supplier DRP candidate query;
- GA DRP candidate query;
- `createSupplierBatch()`;
- `createGaBatch()`;
- duplicate reservation guard;
- any reporting query yang menentukan active membership.

### Required Test

Scenario:

```text
Invoice A
Invoice B

DRP-001:
 Group A = Invoice A
 Group B = Invoice B

Finalize DRP-001
Pay Group A

DRP-001 → PARTIALLY_PAID

Attempt:
Add Invoice B to DRP-002

Expected:
REJECTED
```

Test harus memastikan Invoice B tetap reserved sampai group-nya Paid atau item secara legal dilepas.

---

## P0-04 — Replace Mock Internal PO Provider

### Problem

Production code saat ini menggunakan in-memory mock registry.

### Target Design

Pisahkan contract dan implementation.

```text
LocalPoReferenceProvider
│
├── Database / ERP implementation
└── FakeLocalPoReferenceProvider — tests only
```

Contoh contract:

```text
findEligiblePoForSupplier()
getPoDetails()
getGoodsReceipt()
getPreviouslyInvoicedAmount()
getRemainingAmount()
```

### Important

Jangan langsung menggunakan Import Procurement `purchase_orders` sebagai Local ERP PO jika belum terbukti bahwa source bisnisnya sama.

Jika ERP source sebenarnya belum tersedia, buat explicit integration boundary/staging repository.

Jangan silently fallback ke manual PO.

### Acceptance Criteria

- Internal PO pilihan berasal dari authoritative persisted source.
- PO wajib milik Supplier tersebut.
- GR berasal dari source authoritative.
- Test fake tidak menjadi production provider.

---

## P0-05 — Remove Synthetic Manual GR

Hapus behavior seperti:

```text
GR-{po_number}
```

atau bentuk implicit GR generation lainnya.

Manual mode harus memerlukan:

```text
manual_po_number
manual_gr_reference
```

secara eksplisit.

Jika manual GR tidak diberikan:

```text
submission rejected
```

Tidak boleh ada system-generated fictional reference.

---

## P0-06 — Complete Supplier Submit Invoice HTTP Boundary

Service V2 dan actual form/request harus mempunyai contract yang sama.

### Supplier Fields

```text
invoice_number
invoice_date

po_source:
- INTERNAL
- MANUAL

INTERNAL:
- internal_po_reference
- internal_gr_reference resolved server-side

MANUAL:
- manual_po_number
- manual_gr_reference

invoice_amount
ppn_scheme
scheduled_physical_delivery_date

documents:
- invoice
- tax_invoice conditional
- delivery_note conditional
- supporting optional
```

### PPN Scheme

Allowed:

```text
11%
1.1%
0%
```

PPN harus dihitung server-side.

Client calculation hanya UX assistance.

### Wednesday Schedule

`scheduled_physical_delivery_date`:

- required sesuai agreed business flow;
- harus Wednesday;
- tidak boleh tanggal invalid/past kecuali business rule eksplisit mengizinkan.

### Conditional Document Rules

PKP:

```text
Faktur Pajak required
```

Non-PKP:

```text
Faktur Pajak optional / not applicable
```

Vendor category `Barang`:

```text
Surat Jalan required
```

Non-Barang:

```text
Surat Jalan optional
```

FormRequest dan DocumentService harus menggunakan rule bisnis yang sama.

---

## P0-07 — Restore Private File Authorization

Tidak boleh menggunakan:

```php
Storage::disk('private')->url(...)
```

untuk dokumen bisnis private.

Buat authorized download endpoint untuk:

```text
Local Invoice Document
Supplier Master Document
GA Claim Document
```

Gunakan policy.

### Authorization

Supplier:

```text
hanya dokumen miliknya
```

Finance:

```text
Local Payables documents
```

Purchasing:

```text
dokumen yang memang dibutuhkan untuk read-only audit/vendor master
```

GA:

```text
GA claim documents
```

Cross-domain access harus ditolak.

### Required Tests

- Supplier A → Supplier B invoice document = 403.
- Supplier → Vendor legal document vendor lain = 403.
- GA → Supplier invoice private document = 403.
- Purchasing only allowed explicitly authorized document categories.
- Unauthenticated = redirect/403.

---

# 6. Phase 1 — Supplier Invoice Business Correctness

## P1-01 — Correct Resubmission

Resubmission harus memperlakukan revisi sebagai business revision sebenarnya.

### Re-run PO Validation

Saat resubmit:

```text
resolve current PO
resolve GR
recalculate:
- PO value
- previous invoiced
- remaining
- discrepancy
```

Jangan copy stale snapshot dari revision sebelumnya.

### Recalculate Tax

Recalculate:

```text
ppn_scheme
submitted_ppn_amount
```

berdasarkan data revisi.

### Reset Receipt State

Saat kembali ke:

```text
WAITING_PHYSICAL_DOCUMENT
```

reset:

```text
physical_verified_at
cashier_received_at
cashier_received_by
review_started_at

due_date
payment_term_days_snapshot
ready_to_pay_at
approved_at

verification lock state for new revision
```

Historical data lama tetap berada pada revision/status history.

### Preserve

Jangan hapus historical:

```text
old revisions
old documents
old verification
old receipt events
```

---

## P1-02 — Cashier Receipt Must Fail on Invalid Vendor Master

Jangan gunakan silent fallback:

```text
payment_term ?: 30
```

Jika Vendor Master belum mempunyai valid:

```text
payment_term_days
```

receipt harus gagal dengan actionable validation error.

Payment term authoritative:

```text
1..365
```

Saat cashier receipt:

```text
snapshot term
+
received_at
→ due_date
```

Setelah snapshot dibuat, perubahan Vendor Master tidak boleh mengubah invoice historis.

---

## P1-03 — Wire Physical Delivery Schedule

Implementasikan operational workflow untuk:

```text
Scheduled Wednesday
→ Belum Datang
→ Sudah Datang
→ Lewat Jadwal
```

### First Miss

```text
missed_delivery_count = 1
status remains WAITING_PHYSICAL_DOCUMENT
supplier may reschedule to next valid Wednesday
```

### Second Consecutive Miss

```text
missed_delivery_count = 2
status = EXPIRED
expired_at set
```

Supplier harus submit invoice baru jika expired sesuai agreed business requirement.

### Required Operational Paths

Finance:

```text
mark missed delivery
record physical receipt
```

Supplier:

```text
reschedule after first miss
```

Actions harus mempunyai role enforcement dan history.

---

# 7. Phase 2 — Verification Hardening

## P2-01 — Section A Server-Authoritative Rules

Checks:

```text
Invoice
Faktur Pajak
PO
Surat Jalan
GR
```

### Conditional N/A

Server harus enforce:

```text
Vendor PKP:
tax_invoice_check != NOT_APPLICABLE

Vendor Non-PKP:
NOT_APPLICABLE allowed

Vendor category Barang:
delivery_note_check != NOT_APPLICABLE

Non-Barang:
NOT_APPLICABLE allowed
```

### NOT_OK

Jika `NOT_OK`:

```text
notes required
```

Section A passes hanya jika setiap applicable item = `OK`.

---

## P2-02 — Section B Tax Verification

### PPN

Rules:

```text
SESUAI:
verified_ppn = submitted_ppn

TIDAK_SESUAI:
corrected verified_ppn mandatory
verification note mandatory
```

Tidak boleh fallback diam-diam ke submitted PPN saat `TIDAK_SESUAI`.

### PPh23

Vendor Master harus menyediakan tax classification yang cukup untuk menentukan recommended/default rate.

Finance boleh melakukan correction hanya jika memang bisnis mengizinkan.

Jika rate di-override:

```text
override reason required
```

### PPh 4(2) / PPh21

Manual jika sesuai requirement:

```text
applicable flag
amount
notes / rationale
```

### Net Payable

Canonical:

```text
DPP
+ verified PPN
- PPh23
- PPh4(2)
- PPh21
= Net Payable
```

Gunakan Decimal handling, bukan float untuk internal calculation.

---

## P2-03 — READY_TO_PAY Gate

Invoice hanya boleh menjadi `READY_TO_PAY` jika:

```text
physical document received
AND
Section A exists
AND
Section A passed
AND
Section B exists
AND
Section B passed
AND
verification locked
```

Lock harus transactional.

Setelah locked:

```text
Section A/B immutable
```

kecuali explicit authorized reopen workflow dibuat di masa depan.

---

# 8. Phase 3 — Vendor Master Completion

## P3-01 — Supplier Vendor Profile

Implement Supplier-accessible Vendor Profile.

Fields:

```text
company_name
NPWP
PKP
address
vendor category
payment term — read only unless internal workflow allows
PIC name
PIC email
PIC phone

bank:
bank name
account number
account holder
```

Sensitive/legal authoritative fields tidak boleh direct overwrite tanpa approval.

---

## P3-02 — Supplier Change Request

Supplier update harus menghasilkan:

```text
PENDING change request
```

bukan langsung mengubah master.

Snapshot:

```text
current_data
proposed_data
requested_by
requested_at
```

### Bank Change

Bank lama tetap authoritative sampai bank baru approved.

Payment/DRP yang sudah terbentuk tetap menggunakan bank snapshot lama.

---

## P3-03 — Vendor Master Approval

Latest role design:

Finance:

```text
full Master Vendor access
```

Purchasing:

```text
coordination / validation access
```

Implement sesuai agreed organization ownership tanpa menghilangkan audit.

Approval:

```text
PENDING
→ APPROVED / REJECTED
```

Rejected:

```text
reason mandatory
```

---

## P3-04 — Vendor Legal Documents

Implement upload/download private documents:

```text
NIB
NPWP
SPPKP
Surat Pernyataan Rekening
OTHER
```

Dokumen harus:

```text
private storage
authorized download
version/history retained where required
```

---

## P3-05 — Fix Vendor Master UI Data Binding

Correct field references.

Contoh:

```text
account_holder_name
bukan account_holder
```

Status active berasal dari bank account status/versioning, bukan field yang tidak ada.

Change request:

```text
change_type
review_notes
```

Master document:

```text
original_filename
```

Tambahkan view integration tests agar field typo tidak lolos kembali.

---

# 9. Phase 4 — GA Domain Completion

## P4-01 — Finalize GA Document Requirement

Latest detailed UI reference menyebut GA claim tidak wajib upload dokumen, sementara meeting note sebelumnya menyebut supporting documents.

Gunakan latest agreed rule:

```text
supporting document OPTIONAL
```

tetapi schema/document infrastructure tetap dipertahankan.

Jangan require supporting file di `submitClaim()`.

---

## P4-02 — GA Submit Flow

Fields:

```text
employee
bank auto from Employee Master
claim type
date
amount
description optional
supporting optional
```

Supported types:

```text
Entertain Sales
UPD Sales
UPD GA
Reimburse/Claim
```

Create:

```text
GA Claim
TT-GA Receipt
Status History
```

---

## P4-03 — Employee Master

Implement Employee Master maintenance atau explicit external source integration.

Minimal data:

```text
employee code if available
name
department
bank
account number
account holder
active
```

GA claim tidak boleh menggunakan arbitrary employee ID.

---

## P4-04 — GA Basic Verification

Only GA/Admin if Admin business bypass is explicitly retained.

Flow:

```text
SUBMITTED
→ BASIC_VERIFIED
```

Finance tidak boleh langsung approve dari `SUBMITTED`.

---

## P4-05 — Finance GA Verification

Add Finance:

```text
GA Invoice Register
GA Claim Detail
Finance Verify
Request Revision
Ready to Pay
```

Finance verify only:

```text
BASIC_VERIFIED
→ READY_TO_PAY
```

atau:

```text
BASIC_VERIFIED
→ NEED_REVISION
```

---

## P4-06 — GA Revision / Resubmit

Implement:

```text
NEED_REVISION
→ GA edit/resubmit
→ revision_number + 1
→ BASIC verification again
```

Maintain full history.

No dead-end `NEED_REVISION`.

---

# 10. Phase 5 — DRP Hardening

## P5-01 — Canonical DRP Membership

Supplier candidate:

```text
READY_TO_PAY only
```

GA candidate:

```text
READY_TO_PAY only
```

No legacy `APPROVED` compatibility in new DRP after status migration is complete.

---

## P5-02 — Database-Level Duplicate Protection

Application query alone is not sufficient against race conditions.

Create a robust reservation invariant.

Preferred design:

- explicit active payment reservation;
- or DB-supported uniqueness strategy;
- plus transaction/locks.

Goal:

```text
Two concurrent transactions
attempt same payable
→ exactly one succeeds
```

Required concurrency test.

---

## P5-03 — DRP Supplier Grouping

Group by:

```text
supplier
+
verified bank snapshot
```

Store snapshot:

```text
payee name
bank name
account number
account holder
```

Bank changes after DRP creation must not alter existing group.

---

## P5-04 — Bank Fee

Supplier:

```text
BCA     → 0
Non-BCA → Rp2.500 per transfer group
```

GA:

```text
all banks → 0
```

Fee override:

```text
Finance only
Draft only
reason mandatory
audit actor + timestamp
```

Do not store actor only as concatenated text.

Prefer explicit columns/audit record.

---

## P5-05 — Remove DRP Item

Allowed only before payment/finalization according to approved business rule.

Current agreed safe default:

```text
DRAFT only
```

Remove:

```text
reason required
removed_by
removed_at
```

Recalculate:

```text
group subtotal
group fee
group net
batch totals
```

If group has no active item:

```text
exclude/deactivate empty group
```

Do not leave misleading zero-value active payment row.

---

## P5-06 — GA Draft Ownership

GA prepares DRP GA draft.

Finance does:

```text
review
finalize
voucher
payment
```

Finance should not silently bypass GA preparation unless explicit Admin emergency path exists.

GA must be able to:

```text
open own draft
remove item before Finance finalization
```

---

## P5-07 — Finalize DRP

Before finalize, revalidate:

```text
batch DRAFT
has active items
each payable still READY_TO_PAY
no duplicate active reservation
valid bank snapshots
all group totals consistent
```

Then atomically:

```text
DRAFT → FINALIZED
```

After finalization:

```text
membership locked
fee locked
```

---

## P5-08 — Payment Execution

Payment is performed **per payment group**, not per invoice.

Required:

```text
transfer_reference
transfer_date
difference/payment note optional
```

Before paying:

```text
batch = FINALIZED or PARTIALLY_PAID
group = UNPAID
every active payable = READY_TO_PAY
```

Then transactionally:

```text
group → PAID

all active payables:
READY_TO_PAY → PAID

create histories

if all non-empty groups PAID:
batch → PAID
else:
batch → PARTIALLY_PAID
```

History `from_status` must use actual database state, not hardcoded value.

Payment must be idempotency-safe.

Second payment attempt:

```text
rejected
```

---

# 11. Phase 6 — Forecast & Reporting

## P6-01 — Prevent Forecast Double Counting

Canonical forecast precedence:

```text
If payable belongs to active DRP:
    forecast using DRP planned/payment date

Else if payable READY_TO_PAY:
    forecast using due_date
```

Do not count:

```text
same invoice as READY_TO_PAY
+
same invoice again inside DRP
```

Do not include:

```text
UNDER_VERIFICATION
```

as committed payable forecast unless business explicitly requests pipeline forecast.

---

## P6-02 — Weekly Forecast

Aggregate by week:

```text
count
supplier net payable
GA payable
total
```

Use payable/net values rather than raw DPP only.

---

## P6-03 — Monthly Forecast

Use the same semantic source as weekly forecast.

Weekly and monthly must not use different business logic.

---

## P6-04 — Master Invoice

Keep as reporting/query layer.

Do not create duplicate `master_invoices` source-of-truth table.

Required:

```text
All
Unpaid
Paid
Period filter
Vendor filter
Status filter
Export Excel
```

---

# 12. Phase 7 — Receipt & Notification

## P7-01 — Supplier Tanda Terima QR

Receipt contains:

```text
receipt number
submission number
invoice number
PO
vendor
amount
submitted_at
QR
```

QR payload must use:

```text
opaque/signed identifier
```

Do not encode sensitive raw database fields or unrestricted file URL.

QR verification endpoint must enforce safe disclosure.

---

## P7-02 — GA Tanda Terima QR

Receipt contains:

```text
receipt number
claim number
employee
bank
account
claim type
date
amount
QR
```

Fix bank display to read authoritative Employee/payment snapshot.

---

## P7-03 — Supplier Email Events

Implement mail events for:

```text
submission received
Need Revision
resubmission processed if required
Ready to Pay if business wants vendor notification
Paid
physical delivery reminder
```

Critical final requirement:

```text
Paid → email Supplier
```

Do not expose internal planned payment date in Supplier email.

---

# 13. Phase 8 — Supplier Information Visibility

Supplier must not see:

```text
internal estimated payment schedule
internal DRP date
internal cash-flow forecast
Finance verification-only information
```

Remove Supplier form:

```text
Estimated Due Date
```

especially calculation:

```text
invoice_date + payment term
```

because business due date begins from physical receipt.

Supplier may see:

```text
status
receipt
revision reason
Paid confirmation
```

---

# 14. Phase 9 — Authorization Matrix

Final backend authorization must match:

| Capability | Supplier | Purchasing | Finance | GA | Admin |
|---|---:|---:|---:|---:|---:|
| Submit Local Invoice | Own | No | No | No | Emergency only if explicitly required |
| View Supplier Invoice | Own | Read | Full | No | Yes |
| Verify Invoice | No | Read only | Yes | No | Avoid normal business mutation |
| Request Supplier Revision | No | No | Yes | No | Avoid normal business mutation |
| Ready to Pay | No | No | Yes | No | Avoid normal business mutation |
| Vendor Master | Own profile/request | Coordination | Full | No | System administration |
| Create Supplier DRP | No | No | Yes | No | Avoid normal business mutation |
| Submit GA Claim | No | No | No | Yes | Optional emergency |
| GA Basic Verification | No | No | No | Yes | Optional |
| Finance GA Verification | No | No | Yes | No | Optional emergency |
| GA DRP Draft | No | No | Review only | Yes | Optional |
| Finalize DRP | No | No | Yes | No | Optional emergency |
| Mark Paid | No | No | Yes | No | Prefer Finance only |

Admin must not automatically become business approver simply because it is Admin.

Separate system administration permission from financial authorization.

---

# 15. Phase 10 — Money Precision

Remove financial decision logic based on PHP float.

Review:

```text
invoice DPP
PPN
withholding
subtotal
bank fee
net payable
DRP totals
forecast totals
```

Use consistent decimal-safe representation.

Possible approaches:

```text
integer rupiah/minor units
```

or controlled decimal string/library arithmetic.

Database remains:

```text
DECIMAL(20,2)
```

Do not use floating point comparisons for discrepancy/payment invariants.

---

# 16. Required Concurrency Tests

Add targeted transactional tests for:

### DRP

```text
same invoice → two DRPs concurrently
same GA claim → two DRPs concurrently
remove item vs finalize
remove item vs second DRP
finalize vs second DRP creation
pay same group twice
two simultaneous mark-paid
pay while payable state changes
```

### Verification

```text
request revision vs Ready-to-Pay lock
Section A update vs lock
Section B update vs lock
physical receipt duplicate request
```

### Vendor Master

```text
two bank approvals concurrently
change request approve twice
approve vs reject race
```

Exactly one valid terminal outcome must occur.

---

# 17. Required HTTP Integration Tests

Service tests alone are insufficient.

Add browser/feature request coverage.

## Supplier

```text
GET submit form
POST Internal PO invoice
POST Manual PO invoice
conditional tax invoice
conditional Surat Jalan
Wednesday validation
receipt
track invoice
resubmit
cross-supplier denial
```

## Finance

```text
register
detail
physical receipt
Section A
Section B
request revision
Ready-to-Pay
DRP Supplier
DRP GA
finalize
remove
voucher
Paid
Master Invoice
Master Vendor
```

## Purchasing

```text
invoice detail READ ONLY
cannot POST Finance actions
Vendor Master coordination
private document authorization
```

## GA

```text
submit
receipt
basic verification
Need Revision
resubmit
DRP draft
remove Draft item
Paid status
```

---

# 18. Import Procurement Regression Protection

Existing Import flow must remain unchanged:

```text
PR
→ Quotation
→ Item Award
→ PO
→ Shipment
→ Arrival
→ QC
→ Claim / Replacement
→ Completion
```

Regression suite must specifically preserve:

```text
supplier isolation
item-level award
one supplier per PO
shipment allocation
partial shipment
shipment-aware arrival
shipment-aware QC
NG/replacement fulfillment
claim state
private procurement documents
```

Do not modify Import route/policy/model unless remediation absolutely requires it.

---

# 19. Test Execution Requirement

Minimum execution:

```bash
php artisan optimize:clear
php artisan migrate:fresh --env=testing

php artisan test
```

In addition, targeted suites for:

```text
LocalInvoice*
FinanceVerification*
CashierReceipt*
VendorMaster*
GaClaim*
UnifiedPayment*
DRP*
Authorization*
Document*
Migration*
```

If project mempunyai static analysis/lint/build:

```text
composer lint / pint
npm build
```

jalankan sesuai project configuration.

---

# 20. Verification Integrity

Final implementation report harus membedakan:

```text
PASS
FAIL
NOT RUN
NOT APPLICABLE
```

Jangan menulis:

```text
production ready
fully verified
all tests passed
```

kecuali memang ada execution evidence.

Untuk setiap failed test:

```text
test
error
root cause
whether introduced by remediation
status
```

---

# 21. Completion Criteria

Remediation dianggap selesai hanya jika seluruh kondisi berikut terpenuhi:

### Workflow

```text
No legacy Accounting mutation bypass
No APPROVED/PAYMENT_SCHEDULED/COMPLETED active workflow
Supplier submit V2 works through HTTP
Cashier receipt works
Section A/B enforced server-side
Ready-to-Pay cannot be bypassed
```

### DRP

```text
One payable → one active DRP
PARTIALLY_PAID remains reserved
GA fee always zero
Supplier fee correct
Removal recalculates safely
Payment group transaction safe
No duplicate Paid transition
```

### Vendor Master

```text
Supplier can request profile/bank changes
Finance/Purchasing approval works
Bank versioning preserved
Legal documents private
```

### GA

```text
Submit works
Basic verification works
Finance verification works
Revision works
DRP draft works
Paid works
```

### Security

```text
supplier isolation maintained
private documents authorized
Purchasing verification backend read-only
GA/Supplier domain isolation
QR does not create bypass
```

### UI

```text
no stale/missing model bindings
no Supplier estimated payment date
all V2 fields available
all operational actions wired
```

### Verification

```text
targeted tests pass
full suite pass
Import Procurement regressions pass
migration from populated legacy state passes
```

---

# 22. Recommended Implementation Order

Implement exactly in this order:

```text
1. Correct migration/status transition.
2. Retire Accounting workflow mutations.
3. Fix DRP active reservation / PARTIALLY_PAID.
4. Fix private document authorization.
5. Build real Internal PO provider boundary.
6. Remove synthetic GR fallback.
7. Complete Supplier V2 request + form.
8. Correct resubmission.
9. Harden cashier receipt.
10. Wire Wednesday expiry/reschedule.
11. Harden Section A.
12. Harden Section B and money precision.
13. Complete Vendor Master Supplier workflow.
14. Complete Finance/Purchasing Vendor Master workflow.
15. Complete GA workflow.
16. Harden DRP GA/Supplier.
17. Fix payment execution concurrency.
18. Fix forecast.
19. QR receipt.
20. Notifications.
21. UI/model-binding cleanup.
22. HTTP + concurrency tests.
23. Full regression suite.
```

Do not start with cosmetic UI cleanup before P0 financial blockers have been closed.

---

# 23. Final Deliverable

Implementation agent must return:

```text
1. Final HEAD SHA reviewed
2. Files changed
3. Migrations added
4. P0-01 ... P7 status
5. Legacy workflow closure evidence
6. Authorization changes
7. State-machine changes
8. DRP invariant verification
9. Migration upgrade verification
10. Targeted test results
11. Full test suite result
12. Import regression result
13. Known remaining limitations
```

No commit, push, merge, or deploy unless separately instructed.