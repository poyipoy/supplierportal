# ADASI LOCAL SUPPLIER PORTAL V2
## Final Detailed Implementation & Revision Plan

**Organization:** PT Astra Daido Steel Indonesia  
**Application:** ADASI Supplier Portal  
**Repository:** `https://github.com/poyipoy/supplierportal`  
**Target Branch:** `local-supplier-update`  
**Baseline HEAD:** `6595e01088f0fbf92885389fb93fd3b4fb6527f3`  
**Plan Type:** Revision / Expansion of Existing Local Supplier Invoice Implementation  
**Primary Business Authority:** Latest Supplier Invoice Flowchart  
**Supporting Authority:** Final interview decisions, Role Access, clickable HTML mockup, current source code

---

# 1. PURPOSE

This revision evolves the existing Local Supplier Invoice module into a complete Local Accounts Payable workflow covering:

1. Vendor Master
2. Supplier Invoice Submission
3. Internal PO / GR Validation
4. Physical Document Receipt
5. Invoice & Tax Verification
6. Revision Handling
7. Ready-to-Pay Processing
8. DRP Supplier
9. GA Claims / Invoice GA
10. DRP GA
11. Voucher & Payment Execution
12. Paid Confirmation
13. Payment Forecasting
14. Master Invoice / Reporting
15. Email Notifications
16. Role and Domain Isolation

The implementation must build on the existing Local Supplier bounded context.

This is an incremental revision.

Do not rewrite the Local Supplier module from zero.

---

# 2. SOURCE-OF-TRUTH PRIORITY

If requirements conflict during implementation, resolve them in this order:

```text
1. Latest Supplier Invoice Flowchart
2. Latest confirmed interview decisions
3. Final Role Access
4. Latest clickable HTML mockup
5. Existing implementation / previous plan
```

The current source code remains authoritative for determining how the existing system actually behaves before modification.

---

# 3. CORE ARCHITECTURAL PRINCIPLE

The application continues to contain two business contexts:

```text
ADASI SUPPLIER PORTAL

├── IMPORT PROCUREMENT
│
│   PR
│   → Quotation
│   → Award
│   → PO
│   → Shipment
│   → Arrival
│   → QC
│   → Claim
│
└── LOCAL PAYABLES
    │
    ├── Vendor Master
    ├── Supplier Invoice
    ├── GA Invoice / Claim
    ├── Verification
    ├── DRP
    ├── Payment
    └── Forecast / Reporting
```

The two contexts may share:

- authentication;
- users;
- application layout;
- notification infrastructure;
- storage infrastructure;
- common UI components;
- Supplier identity.

They must remain isolated in:

- routes;
- authorization;
- policies;
- business services;
- transaction tables;
- workflow state;
- reporting queries;
- dashboards;
- data visibility.

Do not reuse Import Procurement models merely because names such as PO, supplier, or document are similar.

---

# 4. CURRENT IMPLEMENTATION TO PRESERVE

The existing branch already contains useful foundations that should be retained wherever compatible:

- Supplier `import/local` business scopes;
- Supplier context switching;
- Local Supplier routes;
- Local Invoice model;
- immutable revision structure;
- invoice receipt numbering;
- private document storage;
- document policies;
- supplier ownership enforcement;
- status history;
- physical verification infrastructure;
- notification-domain isolation;
- Import supplier eligibility protection;
- Local invoice query/report infrastructure;
- concurrency-safe submission numbering;
- authorization tests;
- scope-isolation tests.

Do not remove these protections during V2 implementation.

---

# 5. PRIMARY ACTORS

Final business roles:

```text
supplier
finance
purchasing
ga
admin
qc
```

`accounting` must be retired.

Existing Accounting users must be migrated to:

```text
finance
```

before removing `accounting` from the role enum / validation logic.

`qc` remains part of Import Supplier and is unaffected.

---

# 6. ROLE ACCESS

## 6.1 Supplier

Supplier access:

```text
Home
Submit Invoice
Tanda Terima
Track Invoice
Vendor Profile
Email Notifications
```

Supplier may only access its own Local transactions.

Supplier cannot:

- verify invoices;
- change invoice workflow status;
- access DRP;
- see other vendors;
- see Finance forecast;
- see internal payment dates;
- see payment planning;
- approve its own Vendor Master changes.

---

# 7. Finance

Finance access:

```text
Dashboard

Invoice Register
Verifikasi Invoice
Invoice Detail

DRP Supplier
DRP GA
DRP Paid

Master Invoice
Master Vendor

Payment Forecast
Reports / Export
```

Finance has full Local AP operational access.

Finance may:

- receive physical documents;
- verify Supplier invoices;
- verify documents;
- verify tax;
- request revisions;
- make invoice Ready to Pay;
- create Supplier DRP;
- review/finalize GA DRP;
- execute payment workflow;
- mark payment groups Paid;
- review/approve Vendor Master changes;
- maintain Vendor Master where authorized;
- view all Local AP reporting.

---

# 8. Purchasing

Purchasing responsibilities:

```text
Master Vendor
Vendor Change Approval

Invoice Verification — READ ONLY
```

Purchasing may see:

- Supplier identity;
- Invoice information;
- PO;
- GR;
- uploaded documents;
- verification result;
- tax verification;
- invoice status;
- due date;
- payment state.

Purchasing must not be able to:

- mark verification OK / Not OK;
- request invoice revision;
- modify tax verification;
- make Ready to Pay;
- create DRP;
- remove DRP items;
- mark Paid.

Read-only must be enforced server-side.

Hiding UI buttons is not sufficient authorization.

---

# 9. GA

GA access:

```text
Invoice GA
Tanda Terima GA
Detail Invoice GA / Basic Verification
DRP GA
DRP Paid / Payment Status
```

GA:

- submits claims on behalf of multiple employees;
- selects the employee from Employee Master;
- produces Tanda Terima GA;
- performs / records basic-information verification;
- prepares DRP GA Draft;
- may remove GA items while DRP remains Draft.

GA cannot:

- finalize payment;
- execute payment;
- mark a payment group Paid.

Finance remains final payment authority.

---

# 10. ADMIN

Admin remains a platform-management actor.

Do not automatically grant Admin financial write actions merely because Admin has system-level access.

Existing Admin behavior should be preserved unless a Local policy explicitly permits the operation.

---

# 11. SUPPLIER BUSINESS SCOPE

Existing:

```text
supplier_scopes

import
local
```

must remain.

Supplier can be:

```text
IMPORT only
LOCAL only
BOTH
```

The new V2 implementation must not weaken scope isolation.

Local-only Supplier must never become eligible for new Import Procurement transactions.

Import-only Supplier must never access Local Supplier invoice functionality.

---

# 12. VENDOR MASTER V2

Vendor Master becomes an authoritative source for Local AP.

Vendor Master must contain sufficient information for:

- Supplier identity;
- tax;
- invoice validation;
- payment;
- bank destination;
- document rules;
- payment term;
- notification.

---

# 13. VENDOR MASTER DATA

Retain existing Supplier profile fields where possible.

Required business information includes:

```text
Company Name
Address
Phone
NPWP
PKP Status
Vendor Category
Payment Term
PIC Name
PIC Email
PIC Phone
```

Bank information must not be stored as a simple overwrite-only field.

---

# 14. VENDOR CATEGORY

Vendor category is authoritative for document requirements.

Confirmed example categories:

```text
Barang
Jasa Konsultan
Jasa Konstruksi
Jasa Teknik & Manajemen
Jasa Sewa
Lainnya
```

Initial document rule:

```text
Category = Barang
→ Surat Jalan required

Other categories
→ Surat Jalan not mandatory
```

Centralize this rule.

Do not duplicate category checks across controllers and Blade templates.

---

# 15. VENDOR MASTER DOCUMENTS

Support Vendor Master documents such as:

```text
NIB
NPWP
SPPKP — when applicable
Surat Pernyataan Rekening
```

Documents must use private storage.

Access must remain policy-controlled.

---

# 16. SUPPLIER BANK ACCOUNT

Create dedicated versioned bank-account records.

Recommended domain:

```text
supplier_bank_accounts
```

Conceptual fields:

```text
id
supplier_id

bank_name
account_number
account_holder_name

status

verified_by
verified_at

activated_at
deactivated_at

created_at
updated_at
```

Possible statuses:

```text
PENDING
VERIFIED
REJECTED
INACTIVE
```

Never overwrite historical bank-account records when the Supplier changes its account.

---

# 17. VENDOR PROFILE CHANGE REQUEST

Supplier does not directly update approved master values.

Flow:

```text
Supplier edits profile
        ↓
Change Request
        ↓
PENDING
        ↓
Purchasing OR Finance
        ├── APPROVE
        └── REJECT
```

All Supplier-originated changes require approval.

This applies to:

- company data;
- contact data;
- tax information;
- category;
- bank data;
- supporting master documents.

---

# 18. CHANGE REQUEST DATA

Recommended domain:

```text
supplier_change_requests
```

Store:

```text
supplier_id
change_type

current_data_snapshot
proposed_data

requested_by
requested_at

status

reviewed_by
reviewed_at
review_notes
```

Recommended statuses:

```text
PENDING
APPROVED
REJECTED
CANCELLED
```

Approval should be transactional:

```text
lock request
→ validate still pending
→ apply change
→ update master
→ record reviewer
→ mark approved
```

---

# 19. FINANCE MASTER VENDOR ACCESS

Finance has full operational access to Master Vendor.

Supplier-originated modifications must still follow the approval workflow.

Internal Finance maintenance must reuse the same audited service layer rather than directly issuing uncontrolled database updates.

All changes to sensitive payment information must remain traceable.

---

# 20. EXISTING VENDOR MIGRATION

Existing Local vendors should be considered for migration.

Migration must not silently overwrite existing Supplier users.

Migration process should support:

```text
Source Data
→ Validation
→ Matching
→ Conflict Detection
→ Preview
→ Import
→ Review
```

Likely match keys:

```text
email
NPWP
company name
existing Supplier identity
```

Do not automatically resolve ambiguous matches.

Conflicts require manual review.

---

# 21. INTERNAL PO / GR BOUNDARY

Local invoice PO/GR must not be directly coupled to Import Procurement PO tables unless codebase evidence proves they are the same authoritative business source.

Introduce an explicit Local reference boundary.

Conceptually:

```text
LocalPurchaseOrderProvider
LocalGoodsReceiptProvider
```

These services abstract the actual internal source.

Possible source implementation may later be:

- internal database;
- internal API;
- synchronized table;
- another ADASI system.

The business layer must not depend directly on the transport mechanism.

---

# 22. PO SOURCE TYPES

Supplier submission supports two PO modes:

```text
INTERNAL
MANUAL
```

Store the source explicitly.

Example:

```text
po_source = INTERNAL | MANUAL
```

Do not infer it from whether a string happens to match an internal PO.

---

# 23. INTERNAL PO FLOW

For `INTERNAL`:

Supplier selects an eligible PO belonging to that Supplier.

Display:

```text
PO Number
PO Description
PO Value
Previously Invoiced Value
Remaining PO Value
GR
```

The server must independently verify:

- Supplier owns / is eligible for PO;
- PO exists;
- PO is active / invoiceable;
- GR is available;
- remaining value is current.

Never trust values posted by the browser.

---

# 24. GR RULE

Per authoritative flowchart:

```text
GR must already exist
before Supplier Invoice can be submitted.
```

For internal PO:

```text
No GR
→ block submit
```

The UI may show:

```text
GR not yet available
```

but the server-side request must still reject the submission.

---

# 25. MANUAL PO FLOW

Manual PO remains supported as confirmed during interview.

Manual PO:

- Supplier enters PO reference;
- submission is flagged as manual;
- Finance manually verifies the PO;
- automated PO remaining-value validation is unavailable until the reference can be matched.

Derived implementation rule required to preserve the GR gate:

> Manual PO submissions must also contain or resolve a GR reference before submission can proceed.

Because the source material does not define the technical source for GR on manual POs, implement this path through an explicit manual GR reference subject to Finance verification rather than pretending the GR came from the internal provider.

Do not silently map a manual PO to Import Procurement PO records.

---

# 26. PO VALUE VALIDATION

Authoritative flowchart rule:

```text
if DPP > Remaining PO
```

do not hard-reject the invoice.

Instead:

```text
show warning
→ Supplier explicitly confirms
→ allow submission
→ mark PO discrepancy
→ AP / Finance must review
```

Store an authoritative flag such as:

```text
has_po_discrepancy
```

plus snapshot values:

```text
po_value_snapshot
previously_invoiced_snapshot
remaining_po_snapshot
invoice_dpp_snapshot
```

The discrepancy cannot rely only on a browser warning.

---

# 27. SUPPLIER INVOICE SUBMISSION V2

Supplier submission consists of:

```text
Vendor Data
PO / GR Reference
Invoice Data
Tax Data
Documents
Physical Delivery Schedule
```

Vendor data comes from the approved Vendor Master.

Supplier must not alter approved Vendor Master values from the invoice form.

---

# 28. INVOICE DATA

Required core fields:

```text
Invoice Number
Invoice Date
PO Source
PO Number / Internal PO Reference
GR Reference
DPP
PPN Scheme
PPN Amount
Physical Document Delivery Date
```

System fields include:

```text
Submission Number
Revision Number
Receipt Number
Status
Submitted At
```

---

# 29. PPN INPUT

Supplier selects the applicable PPN scheme.

Initial supported values from approved mockup:

```text
11%
1.1%
0%
```

System calculates the proposed PPN amount from DPP.

The value remains subject to Finance verification.

Supplier cannot determine withholding taxes.

---

# 30. SUPPLIER DOCUMENT RULES

Confirmed document requirements:

### Invoice

```text
Required for every Supplier invoice
```

### Faktur Pajak

```text
Required when Supplier is PKP
```

### Surat Jalan

```text
Required when Vendor Category = Barang
```

### Supporting Document

```text
Optional
```

PO and GR are references / internal evidence, not normal Supplier-upload document types.

All validation must exist server-side.

---

# 31. DOCUMENT STORAGE

Continue private storage.

Requirements:

- no public URL;
- ownership/policy authorization;
- MIME and file-size validation;
- secure generated paths;
- preserve original filename separately;
- database/file-write compensation;
- immutable historical revision documents.

Never delete a historical document because a later revision uploads a replacement.

---

# 32. SUBMISSION IDENTITY

Every new Supplier invoice receives a unique:

```text
Submission Number
Receipt Number
```

Existing concurrency-safe sequence logic should be preserved.

Example business format may remain:

```text
SUB-YYYY-NNNNN
TT-YYYY-NNNNN
```

Receipt identity remains tied to the logical submission.

---

# 33. TANDA TERIMA & QR

After successful submission:

```text
System
→ Generate Tanda Terima
→ Generate QR
```

Receipt includes appropriate data such as:

- Receipt Number;
- Submission Number;
- Invoice Number;
- PO;
- Vendor;
- amount;
- submission timestamp.

QR should use an authenticated/signed/opaque verification reference.

Do not embed:

- bank account data;
- internal payment planning;
- private document URLs;
- sensitive tax details

inside QR payloads.

---

# 34. PHYSICAL DOCUMENT SCHEDULE

Supplier selects a physical-document delivery date.

Rule:

```text
Only Wednesday
```

Enforce:

- UI calendar restriction;
- server-side validation.

Never rely only on JavaScript.

---

# 35. PHYSICAL DOCUMENT RECEIPT

Finance / cashier records arrival of the physical invoice.

This event is financially significant.

Authoritative timestamp:

```text
cashier_received_at
```

or an equivalent clearly named field.

The event must record:

```text
received_at
received_by
revision_number
receipt_number
```

---

# 36. PAYMENT TERM — FINAL RULE

Payment term remains part of Vendor Master.

However:

> Payment term starts when the physical invoice is received by the cashier.

Therefore:

```text
Due Date
=
Cashier Received Date
+
Payment Term Days
```

Example:

```text
Cashier Received: 10 Sep 2026
Payment Term: Net 30

Due Date: 10 Oct 2026
```

---

# 37. PAYMENT TERM SNAPSHOT

Do not derive historical due dates from the current mutable Vendor Master.

At cashier receipt:

```text
payment_term_days_snapshot
=
current approved Vendor payment term
```

Then:

```text
due_date
=
cashier_received_date
+ payment_term_days_snapshot
```

After receipt:

changing the Vendor payment term must NOT modify the invoice due date.

---

# 38. SUBMISSION-TIME PAYMENT TERM

The existing implementation snapshots the payment term at invoice submission.

V2 must change this behavior.

The financially authoritative snapshot occurs when cashier receipt is recorded.

The system may still require Vendor Master to have a valid payment term before submission, but it must not finalize due date at submission.

---

# 39. MISSED PHYSICAL DELIVERY

If physical documents do not arrive on scheduled Wednesday:

```text
WAITING_PHYSICAL_DOCUMENT
→ late delivery state
```

System / Finance may:

- send reminder;
- allow reschedule to next Wednesday.

Track:

```text
missed_delivery_count
rescheduled_at
rescheduled_by
scheduled_delivery_date
```

---

# 40. EXPIRY RULE

After two missed Wednesday schedules:

```text
Invoice → EXPIRED
```

The expired submission:

- becomes terminal;
- cannot be resubmitted as the same submission;
- retains full history;
- remains searchable.

Supplier must create:

```text
new Submission
new Receipt
```

to try again.

---

# 41. FINANCE VERIFICATION

After cashier receives the physical invoice:

```text
WAITING_PHYSICAL_DOCUMENT
→ UNDER_VERIFICATION
```

Finance verification contains:

```text
Section A — Basic / Document Verification
Section B — Tax Verification
```

Purchasing may view both sections read-only.

---

# 42. SECTION A — BASIC / DOCUMENT VERIFICATION

Verify:

```text
Invoice
Faktur Pajak
PO
Surat Jalan
GR
```

Each applicable item:

```text
OK
NOT OK
```

If `NOT OK`:

```text
reason is mandatory
```

Conditional rules remain applicable:

- no Faktur Pajak requirement for Non-PKP;
- no Surat Jalan requirement when vendor category does not require it.

---

# 43. PO / GR VERIFICATION

Internal PO / GR:

```text
auto-match from internal source
```

but Finance may still mark:

```text
NOT OK
```

if physical/business evidence conflicts.

Manual PO / GR:

```text
Finance verification required
```

before invoice can proceed to Ready to Pay.

---

# 44. SECTION B — TAX VERIFICATION

Tax verification includes:

```text
PPN
PPh 23
PPh 4(2)
PPh 21
Verification Notes
```

Section B cannot be finalized until Section A is complete and valid.

---

# 45. PPN VERIFICATION

Finance determines whether Supplier PPN is:

```text
SESUAI
TIDAK SESUAI
```

If incorrect:

Finance may record corrected PPN, including:

```text
0
```

where applicable.

Store both:

```text
submitted_ppn
verified_ppn
```

for auditability.

---

# 46. PPH 23

Flowchart establishes:

```text
PPh 23 applicable? yes/no
Tax Base
Rate based on Vendor NPWP status
```

Implementation should:

- derive / suggest the applicable rate from approved Vendor Master tax data;
- allow Finance to determine/confirm the actual nominal withholding;
- persist the final Finance-approved amount.

Do not recalculate historical tax verification after Vendor Master tax data changes.

---

# 47. OTHER TAX

Finance may manually enter:

```text
PPh 4(2)
PPh 21
```

when applicable.

Store:

```text
applicable flag
amount
verified_by
verified_at
notes
```

---

# 48. VERIFICATION LOCK

Final verification must be explicitly locked/confirmed.

Only after:

```text
Section A = complete and acceptable
AND
Section B = complete
```

may status transition to:

```text
READY_TO_PAY
```

The final transaction should persist:

- verification result;
- tax result;
- final payable data;
- Finance actor;
- timestamp;
- status history.

---

# 49. NEED REVISION

If verification cannot pass:

```text
UNDER_VERIFICATION
→ NEED_REVISION
```

Revision reason is mandatory.

The system must:

- record reason;
- record Finance actor;
- record timestamp;
- notify Supplier by email;
- expose revision reason in Track Invoice.

---

# 50. SUPPLIER RESUBMISSION

Supplier can resubmit only when:

```text
status = NEED_REVISION
```

Resubmission:

```text
same logical submission
same invoice identity
new revision
new revision documents
historical revision retained
```

After resubmission:

```text
→ WAITING_PHYSICAL_DOCUMENT
```

Current-verification state is invalidated.

The corrected physical documents must pass the physical-receipt/verification flow again.

---

# 51. REVISION HISTORY

Revision snapshots must preserve relevant Supplier-entered data.

At minimum:

```text
revision_number
invoice_number
invoice_date
PO source/reference
GR reference
DPP
PPN scheme
submitted PPN
documents
revision reason
requested_by
requested_at
resubmitted_at
```

No old revision may be modified in place.

---

# 52. SUPPLIER INVOICE STATUS MODEL V2

Primary new lifecycle:

```text
WAITING_PHYSICAL_DOCUMENT
        │
        ├── missed twice → EXPIRED
        │
        ▼
UNDER_VERIFICATION
        │
        ├── NEED_REVISION
        │       │
        │       └── resubmit
        │              ↓
        │     WAITING_PHYSICAL_DOCUMENT
        │
        ▼
READY_TO_PAY
        │
        ▼
DRP membership
        │
        ▼
PAID
```

DRP membership is not an invoice status.

---

# 53. LEGACY STATUS MIGRATION

Current statuses such as:

```text
UNDER_REVIEW
APPROVED
PAYMENT_SCHEDULED
COMPLETED
```

must be migrated forward.

Target conceptual mapping:

```text
UNDER_REVIEW
→ UNDER_VERIFICATION

APPROVED
→ READY_TO_PAY

PAYMENT_SCHEDULED
→ READY_TO_PAY

COMPLETED
→ PAID
```

`REJECTED` is not part of the primary V2 flowchart.

If historical rejected records exist:

- preserve them;
- keep them readable;
- do not automatically expose new Reject actions unless explicitly required.

---

# 54. INTERNAL PAYMENT INFORMATION VISIBILITY

Supplier must NOT see:

```text
Due Date
Estimated Payment Date
DRP Number
DRP membership
Payment schedule
Forecast
Internal payment notes
Voucher approval process
```

Supplier sees business-friendly status only.

Finance and Purchasing may see due date/payment information as authorized.

---

# 55. SUPPLIER TRACK INVOICE

Supplier Track Invoice should show:

```text
Submission
Invoice Number
Submitted Date
Amount
Status
Revision reason when applicable
Receipt
```

Do not display estimated payment date.

---

# 56. EMAIL NOTIFICATIONS

Required Supplier email events include at minimum:

```text
NEED_REVISION
PAID
```

Physical-document reminders should also support email.

All notification payloads must respect Local/Import domain isolation.

---

# 57. GA DOMAIN

GA must be implemented as a separate payable source.

Do not store GA claims inside `local_invoices`.

Create an explicit GA domain.

Conceptually:

```text
ga_claims
ga_claim_documents
ga_claim_receipts
ga_claim_status_histories
```

GA and Supplier invoices converge only at the DRP/payment layer.

---

# 58. EMPLOYEE MASTER

GA submits on behalf of employees.

Create a new Employee Master inside the portal.

Minimum information supported by the confirmed UI/process:

```text
Employee Name
Department
Bank
Bank Account Number
Bank Account Holder
Active Status
```

The exact HR identifier / additional accounting fields are not defined by the supplied requirements and should therefore remain extensible.

Do not invent mandatory cost-center fields without business confirmation.

---

# 59. GA CLAIM TYPES

The following types are final:

```text
Entertain Sales
UPD Sales
UPD GA
Reimburse/Claim
```

Use a controlled enum/configuration rather than free text.

---

# 60. GA SUBMISSION

GA selects:

```text
Employee
Claim Type
Date
Amount
Supporting Documents
```

Employee bank details are loaded from Employee Master.

GA does not manually overwrite the employee bank account during submission.

---

# 61. GA DOCUMENTS

GA supporting documents are mandatory.

The exact per-claim-type document taxonomy has not been defined.

Therefore:

- require supporting document attachment;
- design the document model to support typed documents later;
- do not hardcode an invented Entertain/UPD/Reimburse-specific document checklist until provided by the business.

---

# 62. TANDA TERIMA GA

After GA submission:

```text
System
→ Generate TT-GA
→ Generate QR
```

Receipt includes:

```text
Receipt Number
Employee
Bank reference as appropriate
Claim Type
Date
Amount
```

Private/internal information must remain protected.

---

# 63. GA BASIC VERIFICATION

GA Tanda Terima verification checks basic information.

Conceptual result:

```text
SUBMITTED
→ BASIC_VERIFIED
```

This is not the Finance payment approval.

Finance still verifies GA supporting documents/payment eligibility before:

```text
READY_TO_PAY
```

---

# 64. GA REVISION

If Finance finds the GA submission invalid:

```text
→ NEED_REVISION
```

Reason is mandatory.

GA corrects/resubmits while preserving historical versions.

---

# 65. GA PAYMENT STATUS

Simplified GA workflow:

```text
GA SUBMIT
→ TT GA
→ BASIC VERIFICATION
→ FINANCE VERIFICATION
→ NEED_REVISION
      or
→ READY_TO_PAY
→ DRP GA
→ PAID
```

GA has no PO/GR flow.

GA does not receive Supplier bank-transfer deductions.

---

# 66. UNIFIED DRP ENGINE

Supplier and GA must use one reusable payment-batch engine.

Conceptual:

```text
payment_batches
```

with:

```text
type = SUPPLIER | GA
```

Do not create two duplicated implementations.

Differences belong in policies/rules.

---

# 67. DRP STATUS

Recommended DRP lifecycle:

```text
DRAFT
→ FINALIZED
→ PARTIALLY_PAID
→ PAID
```

Optional exceptional status:

```text
CANCELLED
```

if required operationally.

No membership changes are allowed after Finalized.

---

# 68. DRP SUPPLIER — CREATION

Finance / cashier:

```text
Filter Ready-to-Pay invoices
→ select invoices
→ generate DRP Draft
```

Only:

```text
READY_TO_PAY
```

invoices are eligible.

An invoice already reserved in an active DRP must disappear from the candidate pool.

---

# 69. DUPLICATE PAYMENT PROTECTION

System must enforce:

> one payable cannot belong to two active DRPs simultaneously.

This must not rely only on UI filtering.

Use:

- transaction locking;
- server-side revalidation;
- DB-backed active reservation / uniqueness strategy.

---

# 70. SUPPLIER PAYMENT GROUPING

Within a Supplier DRP:

```text
same Supplier
+
same destination bank/account
```

are grouped into one payment group.

A group may contain multiple invoices.

---

# 71. BANK ACCOUNT SNAPSHOT

When payment group is created/finalized:

snapshot the approved destination:

```text
Vendor
Bank
Account Number
Account Holder
```

Later changes to Vendor Master must not mutate an existing DRP.

---

# 72. SUPPLIER BANK FEE

Default business rule:

```text
Destination Bank = BCA
→ Fee = Rp 0

Destination Bank != BCA
→ Fee = Rp 2.500
```

Fee is deducted from transfer amount.

Store the fee as a snapshot.

Do not calculate historical DRP fee dynamically from current settings.

---

# 73. BANK FEE OVERRIDE

Finance may adjust:

```text
fee amount
or
fee = 0
```

only while:

```text
DRP = DRAFT
```

A reason is mandatory.

Store:

```text
old fee
new fee
reason
actor
timestamp
```

---

# 74. REMOVE ITEM FROM DRP

Removal is allowed only while:

```text
DRP = DRAFT
```

Removing an invoice:

```text
does NOT reject invoice
does NOT cancel invoice
does NOT delete invoice
```

The invoice returns to:

```text
READY_TO_PAY pool
```

and can be selected in another DRP.

Store removal audit history.

---

# 75. FINALIZE DRP

Once finalized:

```text
membership locked
grouping locked
bank snapshot locked
fee locked
```

No add/remove is allowed.

Any correction requiring membership changes should require a controlled return/cancel flow rather than silent mutation.

---

# 76. DRP GA

GA creates/prepares:

```text
DRP GA DRAFT
```

GA can:

- select eligible Ready-to-Pay claims;
- remove claims while Draft;
- review grouping.

GA cannot execute payment.

Finance:

```text
review
→ finalize
→ payment
```

---

# 77. GA PAYMENT GROUPING

GA grouping uses:

```text
Employee
+
Destination Bank / Account
```

Multiple eligible claims for the same employee/account may be grouped.

---

# 78. GA BANK FEE

GA rule:

```text
Bank fee = Rp 0
```

for all destination banks.

The Rp2.500 Supplier rule must never leak into GA.

---

# 79. DRP NUMBER

Use concurrency-safe numbering.

Example business format:

```text
DRP-YYYY-NNNN
DRP-GA-YYYY-NNNN
```

Exact formatting may follow existing naming conventions.

Never use:

```text
COUNT(*) + 1
MAX(id) + 1
```

without locking.

---

# 80. PAYMENT VOUCHER

Generate Voucher Bayar per payment group.

Voucher includes:

```text
Payee
Total
Terbilang
Invoices / Claims
Bank
Account Number
NPWP where applicable
Bank Fee
Net Transfer Amount
```

Voucher Number and Voucher Date may remain manually entered according to the supplied workflow.

---

# 81. DRP PAID

Finance/cashier searches a DRP.

Payment confirmation is performed per payment group.

Required:

```text
Bank Transfer Reference
Transfer Date
```

Optional:

```text
Difference / Payment Note
```

---

# 82. PARTIAL DRP PAYMENT

Example:

```text
DRP-001

Group A → PAID
Group B → PAID
Group C → UNPAID
```

Then:

```text
DRP status = PARTIALLY_PAID
```

Only when all active groups are Paid:

```text
DRP status = PAID
```

---

# 83. PAYABLE STATUS UPDATE

When one payment group is marked Paid:

all active invoices / claims belonging to that group become:

```text
PAID
```

in the same transaction.

Never allow:

```text
payment group = PAID
invoice = READY_TO_PAY
```

after successful commit.

---

# 84. PAYMENT TRANSACTION INTEGRITY

Mark Paid operation:

```text
begin transaction

lock DRP
lock payment group
lock payable items

verify not already paid
verify DRP is finalized / payable
verify items still belong to group

store transfer reference
store transfer date
store payment notes

group → PAID
items → PAID

recalculate DRP status

commit
```

Duplicate execution must fail safely.

---

# 85. MASTER INVOICE

`Master Invoice` is NOT a new master-data table.

It is:

```text
consolidated invoice repository / reporting screen
```

built from authoritative invoice records.

Support views such as:

```text
All Received Invoices
Unpaid
Paid
```

plus:

```text
period filter
vendor filter
export
```

Do not create duplicated `master_invoices` records.

---

# 86. FINANCE DASHBOARD

Dashboard should provide operational KPIs such as:

```text
Waiting Physical Document
Waiting Verification
Need Revision
Ready to Pay
Paid
Expired
Over SLA / Overdue
```

Relevant metrics must be query-driven from authoritative status.

---

# 87. PAYMENT FORECAST

Finance dashboard must support:

```text
Weekly Forecast
Monthly Forecast
```

For Supplier invoices:

primary forecast source:

```text
due_date
```

where:

```text
due_date
=
cashier_received_at
+
payment_term_days_snapshot
```

Once an item enters a finalized DRP, planned DRP/payment data may take precedence for internal forecasting.

Supplier never sees this forecast.

---

# 88. GA FORECAST

No independent GA payment-term rule is defined in the supplied requirements.

Therefore GA must not be given an invented due-date formula.

GA can participate in forecast once an internal DRP/payment date is available.

---

# 89. NOTIFICATION EVENTS

Supplier notification events:

```text
Need Revision
Physical Delivery Reminder
Paid
```

Other status notifications may be added if required but should not leak internal Finance workflow.

GA notifications:

```text
Need Revision
Ready to Pay / DRP status as operationally useful
Paid
```

Notification domain isolation must continue to distinguish:

```text
global
import
local
```

---

# 90. ROUTE STRUCTURE

Recommended bounded routes:

```text
/local-supplier/*
/finance/*
/ga/*
/purchasing/local-vendors/*
```

Purchasing verification read-only route may reuse Finance query services but not Finance mutation endpoints.

Do not expose mutation URLs and merely hide buttons.

---

# 91. ACCOUNTING ROUTE MIGRATION

Current `/accounting/*` module should transition to Finance.

Recommended:

```text
/accounting/*
→ retire / compatibility redirect if necessary

/finance/*
→ authoritative Local Finance routes
```

Because the application has not yet completed rollout, unnecessary long-term compatibility should be avoided.

---

# 92. SERVICE LAYER

Recommended domain services:

```text
VendorMasterService
VendorChangeRequestService

LocalPoReferenceService
LocalGrReferenceService

InvoiceSubmissionService
InvoicePhysicalReceiptService
InvoiceVerificationService
InvoiceRevisionService
InvoiceExpiryService

GaClaimService
GaVerificationService

PaymentBatchService
PaymentGroupingService
PaymentVoucherService
PaymentExecutionService

PaymentForecastService
```

Avoid placing complex state transitions directly inside controllers.

---

# 93. REQUEST VALIDATION

Use dedicated FormRequests for:

```text
Supplier invoice
Supplier resubmit
Vendor profile change
Physical receipt
Verification Section A
Verification Section B
GA claim
GA resubmit
DRP create
DRP edit
DRP finalize
DRP Paid
```

Every server-authoritative field must be ignored/rejected if submitted by unauthorized actors.

---

# 94. SERVER-AUTHORITATIVE FIELDS

Supplier must never control:

```text
supplier_id
status
due_date
cashier_received_at
payment_term snapshot
verification result
verified tax
Ready-to-Pay timestamp
DRP
Paid timestamp
bank fee
bank account snapshot
```

GA must never control:

```text
payment status
DRP finalization
bank transfer reference
Paid status
```

---

# 95. PROPOSED DATABASE CHANGES

Do not edit the original committed Local migrations.

Add forward migrations.

Major V2 schema areas:

```text
supplier_bank_accounts
supplier_change_requests
supplier_master_documents

local invoice V2 fields
local invoice verification
local invoice tax verification

employee master

ga claims
ga documents
ga receipts
ga histories

payment batches
payment groups
payment items
payment audit / voucher data
```

---

# 96. LOCAL INVOICE V2 FIELDS

Conceptual additions:

```text
po_source
internal_po_reference
manual_po_number

internal_gr_reference
manual_gr_reference

po_value_snapshot
po_invoiced_snapshot
po_remaining_snapshot
has_po_discrepancy

ppn_scheme
submitted_ppn_amount

scheduled_physical_delivery_date
missed_delivery_count
cashier_received_at
cashier_received_by

payment_term_days_snapshot
due_date

ready_to_pay_at
paid_at
expired_at
```

Use actual naming consistent with Laravel conventions.

---

# 97. VERIFICATION TABLE

Prefer a dedicated verification aggregate rather than adding dozens of mutable flags directly to `local_invoices`.

Conceptual:

```text
local_invoice_verifications
```

including:

```text
invoice_id
revision_number
verified_by

section_a_status
section_b_status

locked_at
notes
```

Document checks may use child rows.

---

# 98. DOCUMENT CHECKS

Conceptual:

```text
local_invoice_verification_items
```

Example types:

```text
invoice
tax_invoice
po
delivery_note
gr
```

Store:

```text
status
reason
verified_by
verified_at
```

This provides complete auditability.

---

# 99. TAX VERIFICATION

Conceptual:

```text
local_invoice_tax_verifications
```

Store final locked values:

```text
submitted_ppn
verified_ppn

pph23_applicable
pph23_tax_base
pph23_rate_snapshot
pph23_amount

pph4_2_applicable
pph4_2_amount

pph21_applicable
pph21_amount

final_payable_amount

verified_by
verified_at
locked_at
notes
```

Do not derive historical values from mutable Vendor Master after verification.

---

# 100. PAYMENT TABLES

Recommended architecture:

```text
payment_batches
payment_groups
payment_items
```

`payment_batches`:

```text
id
batch_number
type
status
created_by
finalized_by
finalized_at
created_at
updated_at
```

`payment_groups`:

```text
payment_batch_id
payee identity
bank snapshot
subtotal
bank_fee
net_transfer
fee_adjustment_reason
status
transfer_reference
transfer_date
paid_by
paid_at
```

`payment_items`:

```text
payment_group_id
payable type / reference
amount snapshot
added_by
removed_by
removed_at
removal_reason
```

Implementation should preserve referential integrity appropriate to Laravel/MySQL capabilities.

---

# 101. ACTIVE DRP RESERVATION

Implement an explicit mechanism preventing the same payable from being present in two active DRPs.

Required behavior:

```text
Finance A adds Invoice X
Finance B simultaneously adds Invoice X

Only one succeeds.
```

This is a financial invariant and must be concurrency-tested.

---

# 102. STATUS HISTORY

Continue status-history recording.

Important events include:

```text
submitted
physical_schedule_missed
physical_rescheduled
expired
physical_received
verification_started
revision_requested
resubmitted
ready_to_pay
added_to_drp
removed_from_drp
drp_finalized
paid
```

DRP membership events may live in payment audit history where more appropriate.

---

# 103. TRANSACTION BOUNDARIES

Mandatory DB transactions for:

```text
Vendor change approval
Invoice submission
Invoice resubmission
Cashier receipt
Verification lock
Ready to Pay
GA submission
GA verification
DRP creation
DRP item removal
DRP finalization
Fee adjustment
Mark Paid
```

Revalidate business state inside the transaction after locking.

---

# 104. LOCK ORDER

Define and keep a consistent locking strategy.

Example:

```text
payment batch
→ payment group
→ payable
```

Avoid different endpoints locking these in reverse order.

Likewise:

```text
invoice
→ current revision
→ verification
```

should remain consistent.

---

# 105. PRIVATE DOCUMENT SECURITY

All Supplier / GA / Vendor Master documents must:

- remain on private storage;
- use policy-controlled download endpoints;
- reject cross-Supplier access;
- reject unauthorized GA access;
- reject cross-domain Import access;
- retain immutable historical revisions.

QR must never become a bypass around document authorization.

---

# 106. SUPPLIER ISOLATION

Hard invariant:

```text
Supplier A
cannot access
Supplier B invoice
Supplier B receipt
Supplier B document
Supplier B revision
Supplier B status
Supplier B profile change
```

Hashids are not authorization.

---

# 107. ROLE ISOLATION

Must test:

```text
Supplier → Finance endpoint = denied
Supplier → GA endpoint = denied
GA → Supplier invoice mutation = denied
Purchasing → verification mutation = denied
Purchasing → DRP mutation = denied
Finance → Import Supplier mutation remains governed by existing Import policies
```

---

# 108. IMPORT REGRESSION PROTECTION

Do not modify Import Procurement business logic unless strictly required for shared infrastructure.

Regression areas:

```text
PR
Quotation
Award
PO
Shipment
Arrival
QC
Claim
Conversations
Attachments
Notifications
Exports
Supplier authorization
```

Existing supplier `import/local` eligibility must remain intact.

---

# 109. NAVIGATION

## Supplier

```text
Home
Submit Invoice
Tanda Terima / Receipt access
Track Invoice
Vendor Profile
```

Email Notification is a delivery channel, not necessarily a sidebar module.

## Finance

```text
Dashboard

Invoice Register
Verifikasi Invoice

DRP Supplier
DRP GA
DRP Paid

Master Invoice
Master Vendor
```

## Purchasing

```text
Local Vendor Master
Vendor Change Requests
Invoice Verification — Read Only
```

## GA

```text
Invoice GA
Tanda Terima GA
Detail / Basic Verification
DRP GA
DRP Paid / History
```

---

# 110. TEST PLAN — VENDOR MASTER

Required cases:

- Supplier profile change creates pending request.
- Approved master unchanged before approval.
- Purchasing can approve.
- Finance can approve.
- unauthorized actor cannot approve.
- rejected request does not change master.
- bank change preserves old account.
- historical payment keeps old bank snapshot.
- concurrent double approval is rejected.
- Supplier A cannot alter Supplier B master.

---

# 111. TEST PLAN — PO / GR

Test:

- internal PO belongs to Supplier;
- foreign Supplier PO rejected;
- missing PO rejected;
- internal PO without GR cannot submit;
- PO with GR can submit;
- DPP within remaining PO normal;
- DPP above remaining PO requires confirmation and discrepancy flag;
- browser-tampered remaining value ignored;
- manual PO accepted through manual pathway;
- manual PO marked for Finance verification;
- manual GR path enforced;
- duplicate invoice protected.

---

# 112. TEST PLAN — DOCUMENTS

Test:

```text
Invoice missing → fail

PKP + no Faktur Pajak → fail
Non-PKP + no Faktur Pajak → allowed

Barang + no Surat Jalan → fail
Non-Barang + no Surat Jalan → allowed

Supporting missing → allowed
```

Also test private access and historical revisions.

---

# 113. TEST PLAN — PHYSICAL DOCUMENT

Test:

- only Wednesday date accepted;
- non-Wednesday forged request rejected;
- first missed schedule recorded;
- reschedule works;
- second missed schedule → EXPIRED;
- expired invoice cannot resubmit same submission;
- new submission may be created;
- cashier receipt recorded once;
- duplicate receipt action rejected.

---

# 114. TEST PLAN — PAYMENT TERM

Critical:

```text
Supplier term = 30
submit date = Sep 1
cashier received = Sep 10

due date must be Oct 10
NOT Oct 1
```

Also:

- change Vendor term before cashier receipt → receipt uses current approved term;
- change Vendor term after receipt → historical due date unchanged;
- Supplier cannot post its own due date;
- Vendor cannot view due date;
- Finance/Purchasing can view due date.

---

# 115. TEST PLAN — VERIFICATION

Test:

- Section A incomplete cannot finalize;
- Not OK requires reason;
- manual PO requires Finance verification;
- PPN correction persisted;
- PPh values persisted;
- Section B cannot lock before Section A;
- Purchasing view-only cannot mutate;
- Ready to Pay only after complete locked verification;
- concurrent Revision vs Ready to Pay results in one valid winner.

---

# 116. TEST PLAN — REVISION

Test:

- Finance request revision with reason;
- email event created;
- Supplier can resubmit only its own invoice;
- revision number increments;
- old documents remain;
- previous physical verification invalidated;
- resubmission returns to physical-document flow;
- direct Ready-to-Pay from old verification fails.

---

# 117. TEST PLAN — GA

Test:

- GA selects active Employee Master record;
- bank comes from Employee Master;
- fixed claim type validation;
- required GA document enforced;
- TT GA generated;
- basic verification recorded;
- Finance verification required;
- Need Revision/resubmit works;
- GA cannot mark Ready to Pay directly;
- GA cannot mark Paid.

---

# 118. TEST PLAN — DRP SUPPLIER

Test:

- only Ready-to-Pay eligible;
- duplicate active DRP reservation rejected;
- grouping same Vendor/account;
- BCA fee = 0;
- non-BCA fee = 2500;
- override requires reason;
- remove allowed Draft;
- remove restores candidate pool;
- remove denied after Finalized;
- bank snapshot immutable.

---

# 119. TEST PLAN — DRP GA

Test:

- only Ready-to-Pay GA claims;
- GA can create Draft;
- GA can remove Draft item;
- GA cannot finalize payment;
- Finance can finalize;
- all banks fee = 0;
- Supplier fee rule never applied to GA.

---

# 120. TEST PLAN — DRP PAID

Test:

- transfer reference mandatory;
- transfer date mandatory;
- one payment group can become Paid independently;
- invoice/claim status changes atomically;
- DRP becomes PARTIALLY_PAID if groups remain;
- all groups Paid → DRP Paid;
- duplicate Mark Paid rejected;
- simultaneous payment execution does not double-pay.

---

# 121. TEST PLAN — REPORT / FORECAST

Verify:

- Master Invoice All;
- Unpaid;
- Paid;
- Supplier filter;
- period filter;
- authorized export;
- weekly forecast;
- monthly forecast;
- paid excluded from outstanding forecast;
- Supplier cannot access internal forecast.

---

# 122. MIGRATION STRATEGY

Do not edit existing committed migrations:

```text
2026_09_08_000001_add_supplier_business_scopes.php
2026_09_08_000002_create_local_invoice_domain.php
```

Create forward migrations.

Suggested order:

```text
01 migrate accounting users → finance and add GA role

02 Vendor Master V2
   bank accounts
   master documents
   change requests

03 Local Invoice V2 fields / statuses

04 Verification + tax structures

05 Employee Master + GA domain

06 Payment Batch / DRP domain

07 Data/status backfill and indexes
```

Exact migration numbering should follow repository timestamp conventions at execution time.

---

# 123. DATA MIGRATION

Before changing status enum:

map existing records safely.

Preserve:

```text
submission_number
receipt_number
revision history
document history
status history
approved/payment timestamps
```

Do not discard legacy data merely because new terminology changed.

---

# 124. IMPLEMENTATION PHASE 0 — BASELINE

Before modification:

- verify branch and HEAD;
- run targeted existing Local tests;
- run existing Import regression;
- record baseline results;
- inspect migrations;
- inspect routes;
- inspect role usage globally;
- inspect notification-domain implementation.

No implementation should begin from an unverified baseline.

---

# 125. PHASE 1 — ROLE & VENDOR MASTER

Implement:

- Accounting → Finance migration;
- GA role;
- Vendor Master V2;
- bank history;
- Vendor Master documents;
- Supplier change request;
- Purchasing/Finance approval;
- navigation/policies/tests.

Exit criteria:

Vendor cannot directly mutate approved master.

---

# 126. PHASE 2 — PO / GR + SUBMISSION V2

Implement:

- internal/manual PO source;
- internal PO provider boundary;
- GR requirement;
- discrepancy flag;
- Supplier document matrix;
- PPN scheme;
- physical delivery schedule;
- revised submission snapshots.

Exit:

Supplier submission matches the authoritative flowchart through Tanda Terima.

---

# 127. PHASE 3 — PHYSICAL RECEIPT & TERMIN

Implement:

- Wednesday schedule;
- reminders/rescheduling;
- 2-miss expiry;
- cashier receipt;
- payment-term snapshot;
- due-date calculation;
- Finance/Purchasing visibility;
- Supplier hiding.

Exit:

Due date is generated only from cashier receipt.

---

# 128. PHASE 4 — VERIFICATION & REVISION

Implement:

- Section A;
- Section B;
- tax snapshots;
- Need Revision;
- resubmit;
- Ready to Pay;
- read-only Purchasing verification.

Exit:

Only fully verified invoice reaches Ready to Pay.

---

# 129. PHASE 5 — GA

Implement:

- Employee Master;
- GA claim domain;
- GA documents;
- TT GA;
- basic verification;
- Finance verification;
- revision;
- Ready to Pay.

---

# 130. PHASE 6 — DRP ENGINE

Implement reusable:

```text
Payment Batch
Payment Group
Payment Items
Voucher
Reservations
Audit
```

Then configure:

```text
SUPPLIER rules
GA rules
```

Do not duplicate the engine.

---

# 131. PHASE 7 — PAYMENT EXECUTION

Implement:

- Draft editing;
- Finalize;
- Voucher;
- DRP Paid;
- per-group payment;
- partial DRP state;
- paid notifications.

---

# 132. PHASE 8 — DASHBOARD / REPORTING

Implement:

- Finance dashboard;
- payment forecast;
- Master Invoice;
- Master Vendor reporting;
- filters;
- exports;
- overdue indicators.

---

# 133. PHASE 9 — UI ALIGNMENT

Apply existing ADASI application design system.

Use the supplied HTML primarily for:

- information architecture;
- fields;
- user interaction;
- workflow references.

Do not reproduce SharePoint styling literally unless that is already part of the ADASI design system.

Preserve current application consistency.

---

# 134. PHASE 10 — FULL VERIFICATION

Minimum verification:

```text
targeted Local Supplier tests
Vendor Master tests
PO/GR tests
Finance verification tests
GA tests
DRP tests
payment tests
authorization tests
concurrency tests
migration tests

full application test suite

php artisan route:list
php artisan view:cache
npm run build
PHP syntax checks
Pint
git diff --check
```

Full Import regression remains mandatory.

---

# 135. MANUAL TRIAL

Before production:

walk through at minimum:

### Supplier

```text
edit Vendor profile
submit invoice
print receipt
miss/reschedule physical date
physical receipt
Need Revision
resubmit
Ready to Pay
Paid
```

### Purchasing

```text
approve Vendor change
open verification read-only
confirm mutation impossible
```

### Finance

```text
receive document
verify invoice
verify taxes
request revision
Ready to Pay
DRP Supplier
remove Draft item
finalize
mark payment group Paid
forecast/report
```

### GA

```text
submit employee claim
TT GA
basic verification
Finance verification
DRP Draft
Finance finalize
Paid
```

---

# 136. EXPLICIT NON-GOALS

Do not:

- rewrite Import Supplier;
- merge Import and Local PO models;
- use Hashids as authorization;
- make DRP membership an invoice status;
- expose due date to Supplier;
- allow Supplier to directly update approved Vendor Master;
- allow GA to execute payment;
- duplicate Supplier/GA DRP engines;
- create a separate `master_invoices` transactional table;
- overwrite historical bank information;
- overwrite old revisions/documents;
- calculate old due dates from current Vendor Master;
- modify previously applied migrations directly.

---

# 137. DERIVED IMPLEMENTATION DECISIONS

The following are technical derivations required because source material does not explicitly define every implementation detail.

### Manual PO / GR

Flowchart requires GR before submission while interview allows manual PO.

Therefore V2 should support a manually supplied GR reference subject to Finance verification for the manual-PO pathway.

This is an implementation bridge, not evidence of an ERP-generated GR.

### GA Document Taxonomy

GA documents are required, but exact per-type document names are not defined.

Use generic required supporting documents initially and make the schema extensible.

### GA Due Date

No GA payment-term rule is supplied.

Do not invent one.

### Legacy Rejected Invoices

Keep existing historical `REJECTED` records readable, but do not treat Reject as a primary V2 workflow action unless separately confirmed.

---

# 138. CRITICAL BUSINESS INVARIANTS

Release must guarantee:

1. Supplier cannot access another Supplier's invoice.
2. Local-only Supplier cannot participate in new Import procurement.
3. Internal invoice PO must belong to Supplier.
4. Internal PO must have GR before submit.
5. PO discrepancy is flagged and auditable.
6. Physical delivery date must be Wednesday.
7. Two missed physical schedules expire the submission.
8. Expired submission requires new Submission/Receipt.
9. Payment term starts at cashier receipt.
10. Due date cannot change after term snapshot.
11. Supplier cannot see due date/payment plan.
12. Purchasing verification is strictly read-only.
13. Ready to Pay requires completed verification.
14. Need Revision requires reason.
15. Revision does not erase old revision/document history.
16. Only Ready-to-Pay items enter DRP.
17. One payable cannot exist in two active DRPs.
18. DRP membership changes only while Draft.
19. Supplier non-BCA default fee = Rp2.500.
20. BCA Supplier fee = Rp0.
21. GA bank fee = Rp0.
22. Bank/payment destination is snapshotted.
23. Finance executes/finalizes payment.
24. Mark Paid is transaction-safe and idempotent.
25. Partial DRP payment is supported.
26. Existing Import Procurement remains regression-free.

---

# 139. DEFINITION OF DONE

The Local Supplier Portal V2 is complete only when:

- Vendor Master workflow works with approval;
- bank history is immutable;
- Supplier can use internal/manual PO path;
- GR gate works;
- PO discrepancy follows flowchart;
- invoice document requirements are conditional and correct;
- Tanda Terima + QR work;
- Wednesday physical schedule works;
- 2-miss expiration works;
- cashier receipt starts payment term;
- due date is correctly snapshotted;
- Finance Section A/B verification works;
- Purchasing is read-only;
- revision/resubmission is safe;
- Ready-to-Pay transition is authoritative;
- GA workflow works;
- DRP Supplier works;
- DRP GA works;
- Draft remove works;
- Voucher generation works;
- per-payment-group Paid works;
- partial DRP is supported;
- Supplier Paid notification works;
- Finance forecast works;
- Master Invoice reporting works;
- role/data isolation tests pass;
- concurrency tests pass;
- migration tests pass;
- full Import regression passes;
- manual role-based trial passes.

---

# 140. FINAL TARGET FLOW

## Supplier

```text
Vendor Master
      ↓
Supplier Submit Invoice
      ↓
Select Internal PO + GR
or
Manual PO + Manual Verification Reference
      ↓
PO / GR Validation
      ↓
PO Discrepancy Flag if required
      ↓
Generate Tanda Terima + QR
      ↓
Physical Document Schedule — Wednesday
      ↓
Cashier Receives Invoice
      ↓
START PAYMENT TERM
      ↓
Finance Verification
 ├── Section A
 └── Section B Tax
      ↓
Need Revision?
 ├── YES → Supplier Resubmit
 │            ↓
 │      Physical Flow Again
 │
 └── NO → READY TO PAY
                ↓
          DRP SUPPLIER
                ↓
             VOUCHER
                ↓
         FINANCE TRANSFER
                ↓
            DRP PAID
                ↓
              PAID
                ↓
       Email Notification
```

## GA

```text
Employee Master
      ↓
GA Submit Claim
      ↓
Required Supporting Documents
      ↓
Tanda Terima GA + QR
      ↓
GA Basic Verification
      ↓
Finance Verification
      ↓
Need Revision?
 ├── YES → GA Resubmit
 │
 └── NO → READY TO PAY
                ↓
         GA Creates DRP Draft
                ↓
         Finance Finalizes
                ↓
          Finance Transfer
                ↓
            DRP PAID
                ↓
              PAID
```

---

# 141. IMPLEMENTATION PRINCIPLE

The implementation should optimize for:

```text
Evidence
→ Correctness
→ Transaction Integrity
→ Authorization
→ Auditability
→ Regression Safety
→ Simplicity
```

Prefer minimal, explicit changes over broad architectural rewrites.

Every financial or authorization transition must be server-authoritative, transaction-safe, auditable, and regression-tested.