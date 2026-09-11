# LOCAL SUPPLIER INVOICE PORTAL
## Detailed Implementation & Integration Plan

**Project:** ADASI Supplier Portal  
**Repository:** `poyipoy/supplierportal`  
**Reference Codebase:** `remediation/item-level-award-shipment-20260905`  
**Module:** Local Supplier Invoice Portal  
**Application:** Existing Laravel-based ADASI Supplier Portal  
**Document Type:** Engineering Implementation Plan  
**Status:** Ready for Technical Review / Execution Planning

---

# 1. Executive Summary

ADASI Supplier Portal saat ini berfokus pada proses procurement untuk **Import Supplier**, dengan domain utama:

`Purchase Requisition → Quotation → Item Award → Purchase Order → Shipment → Arrival → QC → Claim`

Requirement baru adalah menambahkan proses untuk **Local Supplier** dalam aplikasi yang sama.

Local Supplier bukan variasi dari workflow Import Supplier. Local Supplier merupakan bounded context baru yang berfokus pada proses:

`Invoice Submission → Physical Document Verification → Review → Revision / Approval → Payment Scheduling → Completion`

Kedua domain harus menggunakan:

- satu aplikasi;
- satu authentication system;
- satu user management;
- satu deployment;
- satu UI/design system;
- satu notification infrastructure;
- komponen reusable yang sama jika relevan.

Namun harus terisolasi pada:

- navigation;
- route;
- authorization;
- business logic;
- controller;
- service;
- transaction data;
- query;
- dashboard;
- workflow;
- test suite.

Target arsitektur:

```text
ADASI SUPPLIER PORTAL
│
├── IMPORT SUPPLIER DOMAIN
│   ├── PR
│   ├── Quotation
│   ├── Price Comparison
│   ├── Purchase Order
│   ├── Shipment
│   ├── QC
│   ├── Claim
│   └── Existing Reports
│
└── LOCAL SUPPLIER DOMAIN
    │
    ├── Local Supplier
    │   ├── Dashboard
    │   ├── Submit Invoice
    │   ├── Track Invoice
    │   ├── Invoice Detail
    │   ├── Revision / Resubmit
    │   └── Invoice Receipt
    │
    └── Accounting / Finance
        ├── Dashboard
        ├── Invoice Register
        ├── Physical Document Verification
        ├── Invoice Review
        ├── Payment Schedule
        └── Reports
```

Import Supplier behavior yang existing harus dipertahankan.

Implementasi Local Supplier tidak boleh menggunakan existing Import PO, Quotation, Shipment, atau procurement transaction sebagai dependency hanya karena terdapat konsep bernama serupa.

---

# 2. Confirmed Business Requirements

Requirement berikut dianggap telah dikonfirmasi dan menjadi authority untuk implementation planning.

## 2.1 Local Supplier Scope

Local Supplier fase pertama hanya menangani invoice.

Tidak termasuk:

- Purchase Requisition;
- Quotation;
- Supplier Award;
- Import Purchase Order workflow;
- Shipment;
- QC;
- Claim.

Flow awal:

```text
Local Supplier
      ↓
Submit Invoice
      ↓
Physical Document
      ↓
Accounting / Finance Verification
      ↓
Invoice Review
      ↓
Approval / Revision / Rejection
      ↓
Payment Schedule
      ↓
Completed
```

---

# 3. Supplier Classification

Supplier dapat memiliki scope:

```text
IMPORT
LOCAL
IMPORT + LOCAL
```

Satu supplier dapat menjadi Import dan Local sekaligus.

Karena itu, supplier classification tidak boleh direpresentasikan menggunakan:

```text
role = local_supplier
role = import_supplier
role = both_supplier
```

Role tetap menggambarkan actor.

Untuk supplier:

```text
users.role = supplier
```

Sedangkan capability/business scope disimpan secara terpisah.

Recommended model:

```text
supplier_scopes

id
supplier_id
scope
created_at
updated_at
```

Allowed values:

```text
import
local
```

Unique constraint:

```text
UNIQUE(supplier_id, scope)
```

Contoh:

```text
supplier_id | scope
------------|--------
15          | import
15          | local
```

Artinya supplier tersebut memiliki akses ke kedua portal.

Field `category` pada existing `suppliers` tidak boleh digunakan sebagai pengganti scope karena semantic-nya berbeda.

---

# 4. Internal User Roles

Codebase existing saat ini mengenal:

```text
admin
purchasing
supplier
qc
```

Local Supplier membutuhkan actor internal baru:

```text
accounting
finance
```

Pada fase pertama Accounting dan Finance dapat memiliki akses operasional Local Invoice yang sama apabila business belum memisahkan responsibility secara lebih granular.

Namun implementation harus tetap menggunakan Policy/Gate untuk action penting sehingga nanti responsibility dapat dipisahkan tanpa redesign domain.

Contoh authorization capability:

```text
local-invoice.view
local-invoice.review
local-invoice.request-revision
local-invoice.reject
local-invoice.approve
local-invoice.verify-physical
local-invoice.schedule-payment
local-invoice.complete-payment
```

Tidak diperlukan RBAC framework baru apabila existing Laravel Policy/Gate cukup.

Jangan memperkenalkan permission package eksternal hanya untuk fitur ini.

---

# 5. High-Level Authorization Matrix

| Action | Local Supplier | Import Supplier Only | Accounting | Finance | Admin |
|---|---:|---:|---:|---:|---:|
| Local Dashboard | Yes | No | No | No | Optional |
| Submit Invoice | Yes | No | No | No | No |
| View Own Invoice | Yes | No | Yes | Yes | Yes |
| View Other Supplier Invoice | No | No | Yes | Yes | Yes |
| Resubmit Revision | Own only | No | No | No | No |
| Download Own Documents | Own only | No | Yes | Yes | Yes |
| Verify Physical Document | No | No | Yes | Yes | Optional |
| Request Revision | No | No | Yes | Yes | Optional |
| Reject Invoice | No | No | Yes | Yes | Optional |
| Approve Invoice | No | No | Yes | Yes | Optional |
| Schedule Payment | No | No | Yes | Yes | Optional |
| Complete Payment | No | No | Yes | Yes | Optional |
| Local Reports | No | No | Yes | Yes | Yes |

Supplier ownership remains hard authorization invariant.

Hashid atau hidden URL bukan authorization mechanism.

---

# 6. Supplier Portal Context Isolation

Existing supplier routes saat ini berada pada namespace:

```text
/supplier/*
```

dan hanya dilindungi `role:supplier`.

Itu tidak lagi cukup setelah Local Supplier diperkenalkan.

## 6.1 New Scope Middleware

Tambahkan middleware:

```text
SupplierScopeMiddleware
```

Alias contoh:

```text
supplier.scope
```

Usage:

```php
middleware('supplier.scope:import')
```

atau:

```php
middleware('supplier.scope:local')
```

## 6.2 Existing Import Routes

Existing routes:

```text
/supplier/*
```

harus mendapatkan additional protection:

```text
role:supplier
supplier.scope:import
```

Tujuannya agar supplier yang hanya ber-scope `local` tidak dapat mengakses:

- quotation;
- PO Import;
- shipment;
- claim;
- price history;
- Import dashboard.

## 6.3 New Local Routes

New supplier Local routes:

```text
/local-supplier/*
```

dilindungi:

```text
auth
role:supplier
supplier.scope:local
```

---

# 7. Supplier Context Selection

Behavior setelah login:

### Import-only

```text
Login
 ↓
Import Supplier Dashboard
```

### Local-only

```text
Login
 ↓
Local Supplier Dashboard
```

### Both

```text
Login
 ↓
Supplier Context Resolution
```

Untuk supplier `BOTH`, UI menyediakan context switcher:

```text
[ Import Portal ] [ Local Portal ]
```

Recommended behavior:

- context terakhir disimpan dalam session;
- setelah login, context terakhir digunakan jika masih valid;
- jika belum pernah memiliki context, tampilkan context selection;
- switcher hanya tampil untuk supplier `BOTH`;
- Local-only dan Import-only tidak melihat switcher.

Contoh session:

```text
supplier_context = import
```

atau:

```text
supplier_context = local
```

Context hanya memengaruhi navigation dan landing page.

Authorization tetap dilakukan pada route/middleware/policy.

Jangan menggunakan session context sebagai satu-satunya security control.

---

# 8. Dashboard Route Resolution

Existing generic route:

```text
/dashboard
```

saat ini melakukan redirect berdasarkan `users.role`.

Logic tersebut harus diperluas.

Conceptually:

```text
admin       → admin.dashboard
purchasing  → purchasing.dashboard
qc          → qc.dashboard
accounting  → accounting.dashboard
finance     → accounting.dashboard

supplier:
    import only → supplier.dashboard
    local only  → local-supplier.dashboard
    both        → resolve supplier context
```

Existing behavior untuk Import Supplier harus tetap backward compatible.

---

# 9. Local Supplier Navigation

Local supplier sidebar mengikuti existing ADASI Supplier Portal sidebar, bukan mockup SharePoint.

Recommended Local navigation:

```text
OVERVIEW
Dashboard

INVOICE
Submit Invoice
Track Invoice

INFORMATION
ADASI Information
```

Optional:

```text
Export History
```

hanya jika Local invoice export diintegrasikan ke export infrastructure existing.

Tidak menampilkan:

```text
Quotation Period
Purchase Order
Shipment
Negotiation
Material Claim
Price History
```

kepada Local-only supplier.

Supplier `BOTH` melihat menu sesuai context aktif, bukan seluruh Import + Local menu dicampur dalam satu sidebar.

---

# 10. Accounting / Finance Navigation

Recommended:

```text
OVERVIEW
Dashboard

INVOICE CONTROL
Invoice Register
Physical Document Verification
Payment Schedule

REPORTING
Reports
Export History
```

Invoice Review dilakukan melalui Invoice Detail dari Invoice Register, sehingga tidak wajib menjadi menu terpisah.

Ini mengurangi duplicate navigation.

---

# 11. UI / UX Integration Strategy

HTML mockup user digunakan sebagai:

```text
Functional Reference
+
Information Architecture Reference
+
Workflow Reference
```

Bukan sebagai visual implementation langsung.

Tidak dibawa:

- SharePoint header;
- SharePoint sidebar;
- SharePoint blue theme;
- standalone mockup CSS;
- custom typography;
- standalone component system.

Gunakan design foundation existing:

- `resources/views/layouts/app.blade.php`;
- existing ADASI sidebar;
- `x-ui.card`;
- `x-ui.status-chip`;
- existing buttons;
- existing icon components;
- existing table pattern;
- Bootstrap compatibility;
- Tailwind UI foundation existing;
- responsive sidebar existing;
- ADASI loader;
- existing form spacing and visual hierarchy.

Goal:

> Local Supplier harus terlihat sebagai bagian native dari ADASI Supplier Portal, bukan aplikasi kedua yang ditempelkan ke dalam portal.

---

# 12. Local Supplier Screens

## 12.1 Local Dashboard

Isi:

- greeting supplier;
- Submit New Invoice CTA;
- Track Invoice CTA;
- recent invoice submissions;
- summary widget.

Recommended KPI:

```text
Waiting Physical
Under Review
Need Revision
Approved / Scheduled
```

Recent table:

```text
Submission ID
Invoice No
PO Number
Submitted Date
Amount
Status
```

Supplier hanya melihat datanya sendiri.

---

# 13. Submit Invoice

Form mengikuti mockup.

## Vendor Information

Recommended auto-populated dan readonly:

```text
Vendor Name
Vendor PIC
Email
```

Source:

```text
authenticated user + supplier profile
```

Supplier tidak seharusnya mengubah Vendor Name hanya pada transaction form.

## Invoice Information

Fields:

```text
Invoice Number *
Invoice Date *
PO Number *
Payment Term
Invoice Amount *
PPN *
```

`PO Number`:

```text
manual free-text input
```

Tidak dihubungkan ke existing `purchase_orders` Import.

`Payment Term`:

```text
readonly
```

Source:

```text
supplier master
```

## Documents

Required:

```text
Invoice
Faktur Pajak
```

Optional:

```text
Supporting Document
```

Allowed phase-1 file types sesuai mockup:

```text
PDF
JPG
JPEG
PNG
```

Maximum:

```text
10 MB per file
```

Server-side MIME validation wajib.

Client-side `accept` tidak dianggap security control.

---

# 14. Payment Term Master

Tambahkan Local payment term ke Supplier master.

Recommended field:

```text
suppliers.payment_term_days
```

Type:

```text
unsigned small integer
nullable
```

Rules:

- nullable untuk Import-only supplier;
- required jika supplier mendapatkan Local scope;
- nilai > 0;
- practical maximum dapat dibatasi, misalnya 365.

Display:

```text
Net 30
Net 45
Net 60
```

Invoice menyimpan snapshot:

```text
payment_term_days_snapshot
```

Tujuannya supaya perubahan payment term master tidak mengubah historical invoice.

Example:

```text
Supplier:
payment_term_days = 45

Invoice A:
payment_term_days_snapshot = 30

Invoice B:
payment_term_days_snapshot = 45
```

Invoice A tetap Net 30.

---

# 15. PO Behavior

Confirmed business rule:

```text
1 PO → many invoices
```

Contoh:

```text
PO-LOCAL-001
 ├── INV-001
 ├── INV-002
 └── INV-003
```

Karena PO diinput manual, relation bersifat referential text.

Tidak ada:

```text
foreign key → purchase_orders.id
```

Invoice amount dan PPN juga bebas.

Sistem fase pertama tidak melakukan:

```text
Invoice Amount <= PO Amount
```

karena tidak memiliki authoritative Local PO master.

Ini harus dipertahankan secara eksplisit agar developer tidak membuat fake validation terhadap Import PO.

---

# 16. Invoice Number Uniqueness

Recommended invariant:

```text
supplier_id + invoice_number
```

harus unik.

Dengan demikian dua supplier boleh memiliki:

```text
INV-001
```

tetapi supplier yang sama tidak boleh membuat dua submission terpisah menggunakan invoice number identik.

Revision tidak membuat invoice baru.

---

# 17. Submission Lifecycle

Recommended persistent statuses:

```text
WAITING_PHYSICAL_DOCUMENT
UNDER_REVIEW
NEED_REVISION
REJECTED
APPROVED
PAYMENT_SCHEDULED
COMPLETED
```

`SUBMITTED` dan `RESUBMITTED` lebih baik direkam sebagai domain events/status history daripada persistent state jika tidak ada proses operasional yang berhenti pada state tersebut.

Initial transition:

```text
Submit Invoice
      ↓
WAITING_PHYSICAL_DOCUMENT
```

Physical receipt:

```text
WAITING_PHYSICAL_DOCUMENT
      ↓
UNDER_REVIEW
```

Review:

```text
UNDER_REVIEW
    │
    ├──→ NEED_REVISION
    │
    ├──→ REJECTED
    │
    └──→ APPROVED
```

Payment:

```text
APPROVED
    ↓
PAYMENT_SCHEDULED
    ↓
COMPLETED
```

---

# 18. Illegal Status Transitions

State transition harus divalidasi server-side.

Contoh illegal transition:

```text
WAITING_PHYSICAL → APPROVED
NEED_REVISION → APPROVED
REJECTED → APPROVED
COMPLETED → UNDER_REVIEW
COMPLETED → NEED_REVISION
```

UI hiding button bukan business rule.

Service layer harus menolak transition ilegal meskipun endpoint dipanggil langsung.

Recommended:

```text
LocalInvoiceWorkflowService
```

sebagai authority untuk transition.

---

# 19. Revision Workflow

Confirmed:

```text
Revision menggunakan submission yang sama.
```

Contoh:

```text
Submission:
SUB-2026-00125

Revision 1
 ↓
Need Revision

Revision 2
 ↓
Approved
```

`local_invoices.id` dan `submission_number` tidak berubah.

Setiap revision menyimpan historical snapshot.

Recommended:

```text
local_invoice_revisions
```

Tidak overwrite historical revision.

---

# 20. Recommended Revision Integrity Rule

Recommended implementation default:

> Setiap supplier melakukan resubmit setelah `NEED_REVISION`, physical verification sebelumnya dianggap tidak lagi authoritative.

Reason:

Jika invoice/faktur pajak atau data tagihan berubah, dokumen fisik sebelumnya mungkin tidak lagi sama dengan submission digital terbaru.

Dengan demikian:

```text
NEED_REVISION
      ↓
RESUBMIT
      ↓
WAITING_PHYSICAL_DOCUMENT
      ↓
physical verification baru
      ↓
UNDER_REVIEW
```

Receipt submission number tetap sama.

Physical verification history lama tetap disimpan.

Rule ini merupakan recommended integrity behavior dan dapat diubah kemudian jika Accounting menetapkan bahwa revision digital tidak membutuhkan physical re-verification.

---

# 21. Due Date Calculation

Confirmed formula:

```text
Due Date =
Approval Date
+
Payment Term Snapshot
```

Example:

```text
Approved:
10 September 2026

Payment Term:
30 days

Due Date:
10 October 2026
```

Due date baru authoritative setelah invoice approved.

Sebelum approval:

```text
Due Date: —
```

Jika UI ingin menampilkan estimation sebelum approval, label wajib mengatakan `Estimated`, tetapi fase pertama tidak membutuhkannya.

Recommended:

```text
due_date
```

disimpan saat approval, bukan dihitung ulang setiap page load.

---

# 22. Manual Payment Flow

Confirmed payment status bersifat manual.

Actions:

```text
APPROVED
 ↓
Schedule Payment
 ↓
PAYMENT_SCHEDULED

PAYMENT_SCHEDULED
 ↓
Mark Completed
 ↓
COMPLETED
```

Setiap action menyimpan:

```text
actor
timestamp
from status
to status
notes
```

Tidak diperbolehkan direct DB status update dari controller.

Semua melalui workflow/service.

---

# 23. Recommended Database Schema

## 23.1 supplier_scopes

```text
id
supplier_id
scope
created_at
updated_at
```

Constraints:

```text
FK supplier_id
UNIQUE(supplier_id, scope)
INDEX(scope)
```

---

## 23.2 suppliers

Add:

```text
payment_term_days
```

Existing supplier fields tetap dipertahankan.

---

## 23.3 local_invoices

Recommended:

```text
id
submission_number
supplier_id
invoice_number
invoice_date
po_number
currency
invoice_amount
tax_amount
payment_term_days_snapshot
status
submitted_at
physical_verified_at
approved_at
due_date
payment_scheduled_at
completed_at
created_at
updated_at
```

Recommended types:

```text
currency               CHAR(3)
invoice_amount         DECIMAL(20,2)
tax_amount             DECIMAL(20,2)
invoice_date           DATE
due_date               DATE
```

Currency initial default:

```text
IDR
```

tetapi tetap disimpan secara eksplisit.

Indexes:

```text
UNIQUE(submission_number)
UNIQUE(supplier_id, invoice_number)

INDEX(supplier_id, status)
INDEX(status, submitted_at)
INDEX(due_date)
INDEX(po_number)
```

---

# 24. local_invoice_revisions

```text
id
local_invoice_id
revision_number
invoice_number
invoice_date
po_number
invoice_amount
tax_amount
reason
requested_by
requested_at
resubmitted_at
created_at
updated_at
```

Revision menyimpan transaction snapshot yang relevan.

Unique:

```text
UNIQUE(local_invoice_id, revision_number)
```

---

# 25. local_invoice_documents

Recommended isolated table, bukan memaksa existing generic attachment schema.

```text
id
local_invoice_id
local_invoice_revision_id
document_type
file_path
original_filename
mime_type
file_size
uploaded_by
created_at
updated_at
```

Document type:

```text
invoice
tax_invoice
supporting
```

Documents harus immutable per revision.

Upload revision baru membuat record baru.

Tidak overwrite file lama.

---

# 26. local_invoice_status_histories

```text
id
local_invoice_id
from_status
to_status
actor_id
event
notes
created_at
```

Example event:

```text
submitted
physical_verified
review_started
revision_requested
resubmitted
rejected
approved
payment_scheduled
completed
```

History merupakan audit trail.

Tidak boleh diedit melalui UI.

---

# 27. local_invoice_receipts

```text
id
local_invoice_id
receipt_number
issued_at
created_at
updated_at
```

Constraints:

```text
UNIQUE(local_invoice_id)
UNIQUE(receipt_number)
```

Satu invoice submission memiliki satu receipt identity.

Revision tidak menghasilkan submission/receipt identity baru.

---

# 28. local_invoice_physical_verifications

```text
id
local_invoice_id
revision_number
status
verified_by
verified_at
notes
created_at
```

Status contoh:

```text
received
matched
mismatch
invalidated
```

Minimal phase-1 dapat menggunakan:

```text
matched
invalidated
```

Setiap verification disimpan sebagai history.

Jangan overwrite verification sebelumnya.

---

# 29. Number Generation

System-generated:

```text
Submission ID
Receipt Number
```

Example format dari mockup:

```text
SUB-2026-00125
TT-2026-00125
```

Generation harus concurrency-safe.

Jangan gunakan:

```php
LocalInvoice::max('id') + 1
```

tanpa concurrency protection.

Recommended options:

1. generate setelah DB insert menggunakan immutable primary key; atau
2. dedicated sequence with row-level locking.

Unique database constraint tetap wajib.

---

# 30. Recommended Model Layer

New models:

```text
App\Models\LocalInvoice
App\Models\LocalInvoiceRevision
App\Models\LocalInvoiceDocument
App\Models\LocalInvoiceReceipt
App\Models\LocalInvoiceStatusHistory
App\Models\LocalInvoicePhysicalVerification
App\Models\SupplierScope
```

Update:

```text
App\Models\Supplier
App\Models\User
```

Relationships:

```text
Supplier
 ├── scopes
 └── localInvoices

LocalInvoice
 ├── supplier
 ├── revisions
 ├── documents
 ├── receipt
 ├── physicalVerifications
 └── statusHistories
```

---

# 31. Route Architecture

Recommended separate route files:

```text
routes/
├── web.php
├── supplier-import.php
├── supplier-local.php
└── accounting.php
```

Existing routes may be moved only if low-risk.

Jika memindahkan existing Import routes berisiko menyebabkan regression, jangan lakukan refactor hanya demi structural purity.

Minimal-risk implementation:

```php
require __DIR__.'/supplier-local.php';
require __DIR__.'/accounting.php';
```

Existing Import routes dapat tetap di `web.php`, tetapi ditambahkan scope middleware.

---

# 32. Local Supplier Routes

Conceptual routes:

```text
GET  /local-supplier/dashboard
GET  /local-supplier/invoices
GET  /local-supplier/invoices/create
POST /local-supplier/invoices
GET  /local-supplier/invoices/{invoice}

GET  /local-supplier/invoices/{invoice}/receipt

GET  /local-supplier/invoices/{invoice}/revision
POST /local-supplier/invoices/{invoice}/resubmit
```

Names:

```text
local-supplier.dashboard
local-supplier.invoices.index
local-supplier.invoices.create
local-supplier.invoices.store
local-supplier.invoices.show
local-supplier.invoices.receipt
local-supplier.invoices.revision
local-supplier.invoices.resubmit
```

Middleware:

```text
auth
role:supplier
supplier.scope:local
```

---

# 33. Accounting / Finance Routes

Conceptual:

```text
GET /accounting/dashboard

GET /accounting/invoices
GET /accounting/invoices/{invoice}

POST /accounting/invoices/{invoice}/physical-verification
POST /accounting/invoices/{invoice}/start-review
POST /accounting/invoices/{invoice}/request-revision
POST /accounting/invoices/{invoice}/reject
POST /accounting/invoices/{invoice}/approve

GET  /accounting/payment-schedule
POST /accounting/invoices/{invoice}/schedule-payment
POST /accounting/invoices/{invoice}/complete-payment

GET /accounting/reports
```

Middleware:

```text
auth
role:accounting,finance
```

Admin access dapat diberikan melalui Policy jika memang diperlukan.

---

# 34. Controller Structure

Recommended:

```text
app/Http/Controllers/
│
├── LocalSupplier/
│   ├── DashboardController.php
│   ├── InvoiceController.php
│   ├── InvoiceRevisionController.php
│   ├── InvoiceDocumentController.php
│   └── InvoiceReceiptController.php
│
└── Accounting/
    ├── DashboardController.php
    ├── InvoiceController.php
    ├── InvoiceReviewController.php
    ├── PhysicalVerificationController.php
    ├── PaymentScheduleController.php
    └── ReportController.php
```

Controller responsibilities harus tipis:

```text
authorize
validate
invoke service
prepare response
```

Business transition tidak ditempatkan langsung di controller.

---

# 35. Service Layer

Recommended:

```text
app/Services/LocalInvoice/
│
├── InvoiceSubmissionService.php
├── InvoiceWorkflowService.php
├── InvoiceRevisionService.php
├── InvoiceDocumentService.php
├── InvoiceReceiptService.php
├── PhysicalVerificationService.php
└── PaymentScheduleService.php
```

## InvoiceSubmissionService

Responsibilities:

- validate supplier Local scope;
- create invoice;
- snapshot payment term;
- generate submission number;
- store initial documents;
- create receipt;
- create status history;
- send notification;
- transactional consistency.

## InvoiceWorkflowService

Authority untuk:

```text
verify physical
start review
request revision
reject
approve
schedule payment
complete
```

## InvoiceRevisionService

Responsibilities:

- validate `NEED_REVISION`;
- create new revision;
- preserve old revision;
- update current invoice values;
- save new documents;
- invalidate physical verification;
- transition status;
- create audit history.

## InvoiceDocumentService

Responsibilities:

- MIME/size validation;
- private storage;
- safe filename handling;
- DB/file compensation behavior;
- ownership-safe retrieval.

---

# 36. Form Requests

Recommended dedicated requests:

```text
StoreLocalInvoiceRequest
ResubmitLocalInvoiceRequest
VerifyPhysicalDocumentRequest
RequestLocalInvoiceRevisionRequest
RejectLocalInvoiceRequest
ApproveLocalInvoiceRequest
ScheduleLocalInvoicePaymentRequest
CompleteLocalInvoicePaymentRequest
```

Jangan menaruh seluruh validation inline di controller.

---

# 37. Validation Rules

## Submit Invoice

```text
invoice_number:
required
string
max length
unique per supplier

invoice_date:
required
date

po_number:
required
string
max length

invoice_amount:
required
numeric
>= 0

tax_amount:
required
numeric
>= 0

invoice:
required
allowed MIME
max 10MB

tax_invoice:
required
allowed MIME
max 10MB

supporting:
optional
allowed MIME
max 10MB
```

No server-side relationship dengan Import PO.

---

# 38. Server-Authoritative Supplier Information

Form menampilkan Vendor Name, PIC, dan Email.

Data authoritative harus berasal dari:

```text
authenticated user
supplier profile
```

Jangan mempercayai hidden form fields seperti:

```text
supplier_id
vendor_name
vendor_email
```

untuk menentukan ownership.

Server selalu menentukan:

```php
supplier_id = auth()->id() / authenticated supplier relation
```

sesuai convention codebase.

---

# 39. Authorization Policies

Recommended:

```text
LocalInvoicePolicy
LocalInvoiceDocumentPolicy
LocalInvoiceReceiptPolicy
```

Hard invariant:

```text
supplier user
can only view/update/resubmit
invoice owned by that supplier
```

Accounting/Finance dapat melihat Local invoice sesuai role.

Import Purchasing/QC tidak otomatis mendapat akses Local Invoice.

Access antar domain harus explicit, bukan implicit.

---

# 40. Private Document Storage

Codebase existing sudah memiliki private filesystem pattern.

Local invoice documents harus menggunakan:

```text
Storage::disk('private')
```

Bukan:

```text
public/storage
```

Tidak boleh expose direct filesystem URL.

Download/view selalu melalui authenticated controller + policy.

Recommended path:

```text
local-invoices/
  {invoice-id}/
    revisions/
      1/
        invoice/
        tax-invoice/
        supporting/
```

Storage path harus menggunakan generated/random filename.

Original filename hanya metadata.

---

# 41. File Transaction Integrity

Upload workflow harus menangani failure dengan benar.

Required behavior:

### Physical file write fails

```text
NO document DB record
```

### Database transaction fails setelah file baru ditulis

```text
delete newly created file
rollback DB
```

Jangan menghapus historical file existing dalam compensation.

Revision lama tidak boleh hilang.

---

# 42. Receipt

Receipt diterbitkan setelah successful invoice submission.

Display:

```text
Receipt Number
Submission ID
Invoice Number
PO Number
Vendor
Invoice Amount
Submission Timestamp
```

Supplier dapat:

```text
View
Print
```

Initial implementation menggunakan printable Blade view.

Tidak perlu introducing PDF generator jika belum diperlukan.

Print CSS mengikuti existing design system.

Receipt identity immutable.

---

# 43. Physical Document Verification

Accounting/Finance memiliki search field:

```text
Receipt Number
Submission Number
Invoice Number
```

Primary operational match:

```text
Receipt Number
```

Queue:

```text
Waiting Physical Verification
```

Table:

```text
Receipt No
Invoice No
Vendor
Digital Submission Date
Status
Action
```

Action:

```text
Mark as Matched / Received
```

Setelah verified:

```text
WAITING_PHYSICAL_DOCUMENT
       ↓
UNDER_REVIEW
```

Actor dan timestamp dicatat.

---

# 44. Invoice Review

Invoice Detail internal menampilkan:

```text
Submission Information
Supplier Information
Invoice Information
Documents
Physical Verification Status
Revision History
Status Timeline
Payment Information
```

Actions hanya tampil sesuai current state.

`UNDER_REVIEW`:

```text
Request Revision
Reject
Approve
```

Setiap destructive/high-impact action menggunakan confirmation UI existing.

---

# 45. Request Revision

Accounting/Finance wajib memberikan:

```text
revision reason
```

Reason tidak boleh kosong.

Transition:

```text
UNDER_REVIEW
      ↓
NEED_REVISION
```

Supplier menerima:

- in-app notification;
- email notification.

Invoice detail supplier menampilkan reason secara jelas.

---

# 46. Resubmit

Supplier dapat melakukan resubmit hanya jika:

```text
invoice.status = NEED_REVISION
AND
invoice.supplier_id = authenticated supplier
```

Supplier melihat current data dan reason.

Submission ID tetap.

Revision number bertambah.

Example:

```text
SUB-2026-00125

Revision #1
Revision #2
```

Previous file/data tidak dihapus.

---

# 47. Reject

Transition:

```text
UNDER_REVIEW
      ↓
REJECTED
```

Reason wajib.

Rejected dianggap terminal pada phase-1.

Jika bisnis ingin reopen di masa depan, buat explicit reopen workflow; jangan memungkinkan status edit bebas.

---

# 48. Approve

Requirements:

```text
status = UNDER_REVIEW
physical verification valid
required documents available
```

Approval transaction:

```text
approved_at = now()

due_date =
approved_at date
+
payment_term_days_snapshot

status = APPROVED
```

History dan notification dibuat dalam transaction-safe workflow.

---

# 49. Payment Schedule

Payment Schedule menampilkan invoice:

```text
APPROVED
PAYMENT_SCHEDULED
```

dan completed jika historical filter dipilih.

Columns:

```text
Invoice Number
Supplier
Amount
Approval Date
Payment Term
Due Date
Remaining Days
Status
```

Calculated categories:

```text
Scheduled
Due < 7 Days
Overdue
Completed
```

Overdue:

```text
due_date < today
AND status != COMPLETED
```

Remaining days computed from due date.

Tidak perlu menyimpan redundant `remaining_days` di database.

---

# 50. Accounting Dashboard

Mockup Accounting Dashboard diterjemahkan ke ADASI design system.

Recommended KPIs:

```text
Total Invoice
Waiting Physical
Under Review
Need Revision
Approved
Overdue
```

Potential visualizations:

```text
Status Distribution
Invoice Received Trend
Top Overdue Suppliers
Recent Invoices
```

Semua query Local-only.

Tidak menggabungkan Import PO/shipment statistics.

---

# 51. Local Supplier Dashboard

Recommended KPI:

```text
Waiting Physical
Under Review
Need Revision
Approved / Scheduled
```

Quick actions:

```text
Submit New Invoice
Track Invoice
```

Recent Submissions:

```text
latest 5
```

Tidak perlu dashboard visual yang terlalu kompleks pada phase-1.

---

# 52. Notifications

Integrasikan dengan notification infrastructure existing.

Required notification events:

### To Accounting / Finance

```text
New Invoice Submitted
Invoice Resubmitted
Physical document pending / relevant operational alerts
```

### To Supplier

```text
Physical Document Verified
Revision Required
Invoice Rejected
Invoice Approved
Payment Scheduled
Payment Completed
```

At minimum, Revision Required harus memiliki email seperti mockup.

Recommended email events:

```text
Revision Required
Invoice Approved
Payment Completed
```

Event lain dapat in-app first.

---

# 53. Notification URL Resolution

Existing notification URL resolution harus diperluas agar notification Local mengarah ke:

Supplier:

```text
local-supplier.invoices.show
```

Accounting/Finance:

```text
accounting.invoices.show
```

Ownership dan role tetap dicek pada destination controller.

Notification URL bukan authorization bypass.

---

# 54. User Management Changes

Admin User Management perlu mendukung:

```text
Accounting
Finance
```

Untuk role `supplier`, tambahkan Local/Import access configuration.

Recommended form:

```text
Supplier Access

☐ Import Supplier
☐ Local Supplier
```

At least one required.

Jika Local dipilih:

```text
Payment Term (Days) *
```

Jika Import-only:

```text
Payment Term optional
```

Admin edit existing supplier tidak boleh otomatis mengubahnya menjadi Local.

Migration/backfill existing supplier:

```text
all existing suppliers = import
```

Ini critical untuk backward compatibility.

---

# 55. Existing Supplier Migration Strategy

Pada deployment:

Existing supplier records harus mendapatkan:

```text
supplier_scopes.scope = import
```

Backfill harus:

- idempotent;
- tidak menghasilkan duplicate;
- transaction-safe;
- tidak mengubah procurement data.

Dengan demikian existing supplier setelah rollout tetap mendapatkan portal yang sama seperti sebelumnya.

---

# 56. BOTH Supplier Migration Behavior

Admin dapat kemudian menambahkan:

```text
local
```

ke supplier existing.

Setelah itu:

```text
import
local
```

keduanya tersedia.

Tidak melakukan duplicate supplier user/account.

Satu login tetap digunakan.

---

# 57. Sidebar Changes

`resources/views/partials/sidebar.blade.php` saat ini menentukan navigation berdasarkan `role`.

Refactor harus minimal.

Recommended extraction:

```text
partials/sidebar/
  purchasing.blade.php
  supplier-import.blade.php
  supplier-local.blade.php
  accounting.blade.php
  qc.blade.php
  admin.blade.php
```

Namun extraction ini dilakukan hanya jika tidak memperbesar regression risk.

Minimum implementation juga acceptable:

```text
if supplier
   resolve supplier context
```

dan render block sesuai context.

Requirement utama adalah correctness, bukan refactor aesthetic.

---

# 58. Design Components to Reuse

Local pages harus memprioritaskan existing components:

```text
x-ui.card
x-ui.status-chip
x-ui.sidebar-item
x-ui.icon
x-ui.icon-button
existing empty-state components
existing data actions
existing alert/confirmation patterns
```

Status mapping contoh:

```text
WAITING_PHYSICAL     → warning
UNDER_REVIEW         → warning/info
NEED_REVISION        → danger
REJECTED             → neutral/danger
APPROVED             → success
PAYMENT_SCHEDULED    → info
COMPLETED            → success
```

Gunakan semantic token existing.

Jangan hardcode SharePoint colors dari mockup.

---

# 59. Search / Filter Requirements

Supplier Track Invoice:

Filters:

```text
Invoice Number
Submission ID
PO Number
Status
Date range
```

Accounting Invoice Register:

```text
Supplier
Invoice Number
Submission ID
PO Number
Status
Submitted Date
Due Date
```

Payment Schedule:

```text
Supplier
Status
Due range
Overdue only
```

Server-side DataTables dapat digunakan mengikuti existing application pattern.

---

# 60. Reports

Mockup menunjukkan Reports tetapi detail format belum didefinisikan.

Phase-1 recommended minimum:

```text
Invoice Register Export
Payment Schedule Export
```

Filters mengikuti screen.

Possible formats mengikuti export infrastructure existing.

Tidak membuat complex financial report tanpa requirement tambahan.

---

# 61. Transaction Boundaries

Operations berikut wajib menggunakan DB transaction:

```text
initial invoice submission
revision resubmit
physical verification
request revision
reject
approve
schedule payment
complete payment
```

File write membutuhkan compensation seperti dijelaskan sebelumnya.

Approval harus atomically menyimpan:

```text
status
approved_at
due_date
history
```

Jangan memiliki kondisi di mana:

```text
status = APPROVED
approved_at = null
```

atau:

```text
status = APPROVED
due_date = null
```

---

# 62. Concurrency Protection

Transition penting menggunakan row lock:

```text
SELECT ... FOR UPDATE
```

via Laravel:

```php
lockForUpdate()
```

contohnya pada:

```text
verify physical
request revision
approve
schedule payment
complete
resubmit
```

Tujuannya mencegah:

```text
Accounting A → Approve
Accounting B → Request Revision
```

terjadi bersamaan.

State dibaca kembali setelah lock.

---

# 63. Idempotency / Double Submit Protection

Submission button perlu:

- disable after submit di UI;
- DB uniqueness;
- transaction protection.

Workflow actions perlu menolak repeated transition.

Example:

```text
APPROVED → approve again
```

harus gagal cleanly.

`COMPLETED → complete again`

juga harus gagal.

---

# 64. Security Invariants

Must hold:

1. Local supplier tidak dapat mengakses Import domain tanpa Import scope.
2. Import-only supplier tidak dapat mengakses Local domain.
3. Supplier BOTH dapat mengakses keduanya.
4. Local supplier A tidak dapat membaca invoice supplier B.
5. Local supplier A tidak dapat download document supplier B.
6. Local supplier tidak dapat spoof `supplier_id`.
7. Supplier tidak dapat approve invoice sendiri.
8. Supplier tidak dapat verify physical document sendiri.
9. Supplier tidak dapat mengubah status langsung.
10. Accounting/Finance tidak dapat menggunakan Local routes untuk mengubah Import procurement transaction.
11. Document menggunakan private storage.
12. Revision lama tidak dapat dihapus melalui normal workflow.
13. Status transition harus server-authoritative.
14. Due date harus server-calculated.
15. Payment term snapshot tidak dapat dikirim/diubah oleh supplier.

---

# 65. Testing Strategy

Test Local dibuat sebagai dedicated test group/domain.

Recommended test classes:

```text
LocalSupplierAccessTest
SupplierScopeMiddlewareTest
LocalInvoiceSubmissionTest
LocalInvoiceIsolationTest
LocalInvoiceDocumentSecurityTest
LocalInvoicePhysicalVerificationTest
LocalInvoiceWorkflowTest
LocalInvoiceRevisionTest
LocalInvoicePaymentScheduleTest
LocalInvoiceNotificationTest
LocalInvoiceConcurrencyTest
LocalInvoiceDashboardTest
LocalInvoiceAdminConfigurationTest
LocalInvoiceMigrationTest
```

---

# 66. Mandatory Supplier Scope Tests

Cases:

```text
Import-only → Import allowed
Import-only → Local denied

Local-only → Local allowed
Local-only → Import denied

Both → Import allowed
Both → Local allowed
```

Also test direct URL access.

Sidebar hiding alone tidak cukup.

---

# 67. Mandatory Isolation Tests

Create:

```text
Supplier A
Supplier B

Invoice A
Invoice B
```

Verify Supplier A cannot:

```text
GET Invoice B
download Invoice B document
download Invoice B receipt
resubmit Invoice B
access Invoice B revision
```

Expected:

```text
403 or 404
```

sesuai existing authorization convention.

---

# 68. Mandatory Workflow Tests

Verify valid:

```text
submit
→ waiting physical
→ under review
→ approve
→ scheduled
→ completed
```

Revision:

```text
under review
→ need revision
→ resubmit
→ physical verification
→ under review
→ approve
```

Reject:

```text
under review
→ rejected
```

Verify illegal transitions ditolak.

---

# 69. Mandatory Payment Tests

Verify:

```text
due_date = approved date + snapshot term
```

Verify changing supplier master payment term setelah invoice approved tidak mengubah existing invoice.

Verify:

```text
Overdue
Due < 7 days
Scheduled
Completed
```

classification.

---

# 70. Mandatory Document Tests

Test:

```text
unsupported MIME rejected
oversize rejected
required documents enforced
private storage used
cross-supplier denied
missing physical file returns safe 404
DB failure compensates newly stored file
old revision document preserved
```

---

# 71. Existing Import Regression

Ini merupakan release gate.

Semua existing Import tests harus tetap pass.

Regression focus:

```text
supplier dashboard
quotation
purchase order
shipment
claim
notification
attachment
admin supplier management
authentication
authorization
QC
purchasing
```

Paling penting:

penambahan `supplier.scope:import` tidak boleh membuat existing production suppliers kehilangan akses setelah migration/backfill.

---

# 72. Migration Order

Recommended deployment migration order:

```text
1. Create supplier_scopes
2. Add payment_term_days
3. Backfill all existing supplier → import
4. Create local_invoices
5. Create local_invoice_revisions
6. Create local_invoice_documents
7. Create local_invoice_receipts
8. Create local_invoice_status_histories
9. Create local_invoice_physical_verifications
10. Deploy Local models/services/routes/UI
```

Backfill harus terjadi sebelum Import scope middleware mulai enforce access.

Ini mencegah temporary lockout.

---

# 73. Implementation Phases

## Phase 1 — Domain Foundation

Implement:

```text
Supplier scopes
Accounting / Finance roles
payment term
middleware
policies
dashboard resolver
migration/backfill
```

Exit criteria:

```text
Import-only / Local-only / Both authorization works
Existing Import regression green
```

---

## Phase 2 — Local Invoice Core

Implement:

```text
LocalInvoice
submission
documents
receipt
tracking
supplier dashboard
```

Exit criteria:

```text
Local supplier can safely submit and track own invoice
```

---

## Phase 3 — Physical Verification

Implement:

```text
Accounting dashboard
invoice register
physical verification queue
receipt lookup
verification history
```

Exit criteria:

```text
invoice cannot enter review before valid physical verification
```

---

## Phase 4 — Review & Revision

Implement:

```text
review
request revision
resubmit
reject
approve
revision history
status history
notification/email
```

Exit criteria:

```text
full revision lifecycle works without destroying history
```

---

## Phase 5 — Payment

Implement:

```text
due date
payment schedule
schedule payment
complete payment
overdue calculations
dashboard KPIs
```

Exit criteria:

```text
approved invoice reliably reaches completion with audit history
```

---

## Phase 6 — Reports & UX Hardening

Implement:

```text
reports/export
filtering
empty states
responsive checks
accessibility
loading states
print receipt
notification polish
```

---

## Phase 7 — Regression & Release Validation

Run:

```text
Local targeted tests
Full existing test suite
authorization negative-path tests
migration tests
file storage tests
concurrency tests
manual UX walkthrough
```

No deployment until regression passes.

---

# 74. Suggested File Structure

```text
app/
├── Http/
│   ├── Controllers/
│   │   ├── LocalSupplier/
│   │   └── Accounting/
│   ├── Middleware/
│   │   └── SupplierScopeMiddleware.php
│   └── Requests/
│       └── LocalInvoice/
│
├── Models/
│   ├── LocalInvoice.php
│   ├── LocalInvoiceRevision.php
│   ├── LocalInvoiceDocument.php
│   ├── LocalInvoiceReceipt.php
│   ├── LocalInvoiceStatusHistory.php
│   ├── LocalInvoicePhysicalVerification.php
│   └── SupplierScope.php
│
├── Policies/
│   ├── LocalInvoicePolicy.php
│   ├── LocalInvoiceDocumentPolicy.php
│   └── LocalInvoiceReceiptPolicy.php
│
├── Services/
│   └── LocalInvoice/
│       ├── InvoiceSubmissionService.php
│       ├── InvoiceWorkflowService.php
│       ├── InvoiceRevisionService.php
│       ├── InvoiceDocumentService.php
│       ├── InvoiceReceiptService.php
│       ├── PhysicalVerificationService.php
│       └── PaymentScheduleService.php
│
└── Notifications/
    └── LocalInvoice/
```

Views:

```text
resources/views/
├── local-supplier/
│   ├── dashboard.blade.php
│   └── invoices/
│       ├── index.blade.php
│       ├── create.blade.php
│       ├── show.blade.php
│       ├── revision.blade.php
│       └── receipt.blade.php
│
└── accounting/
    ├── dashboard.blade.php
    ├── invoices/
    │   ├── index.blade.php
    │   └── show.blade.php
    ├── physical-verification/
    │   └── index.blade.php
    ├── payment-schedule/
    │   └── index.blade.php
    └── reports/
        └── index.blade.php
```

---

# 75. Existing Files Expected to Change

Likely existing files:

```text
routes/web.php
app/Models/User.php
app/Models/Supplier.php
app/Http/Controllers/Admin/UserController.php
resources/views/admin/users/create.blade.php
resources/views/admin/users/edit.blade.php
resources/views/partials/sidebar.blade.php
resources/views/layouts/app.blade.php
app/Policies/AttachmentPolicy.php or policy registration infrastructure
bootstrap/app.php or middleware registration
notification URL resolver
database factories / test helpers
```

Actual implementation harus mengikuti current repository evidence.

Jangan membuat unnecessary refactor hanya karena file berada di area yang sama.

---

# 76. Explicit Non-Goals

Phase pertama tidak mencakup:

```text
Local PR
Local quotation
Local PO master
PO amount reconciliation
SAP integration
ERP integration
automatic payment confirmation
bank integration
automatic tax validation
OCR
automatic invoice extraction
multi-level approval engine
supplier scoring
shipment
QC
claim
```

PO Number tetap manual.

Invoice amount tetap manual.

PPN tetap manual.

---

# 77. Design Guardrails

Do not:

- copy SharePoint mockup CSS;
- duplicate whole existing layout;
- create second authentication system;
- create second supplier account;
- share Local transaction table with Import procurement;
- add `local/import` column ke semua existing transaction tables;
- use `category` as supplier scope;
- use Import PurchaseOrder model for Local PO number;
- expose Local invoice document publicly;
- use client-submitted supplier ID as authority;
- permit arbitrary status updates;
- overwrite revision history;
- calculate old due date from current supplier term;
- modify existing Import business behavior unnecessarily.

---

# 78. Audit Requirements

Every significant transition stores:

```text
who
what
when
from state
to state
reason / notes
```

Minimum audited events:

```text
invoice submitted
physical verified
revision requested
invoice resubmitted
invoice rejected
invoice approved
payment scheduled
payment completed
```

Document upload actor juga disimpan.

---

# 79. Observability

Recommended structured logging untuk operational failures:

```text
local_invoice_id
submission_number
supplier_id
actor_id
action
previous_status
target_status
```

Jangan log:

- uploaded document contents;
- sensitive file paths unnecessarily;
- raw session token;
- password;
- authentication secret.

---

# 80. Performance Considerations

Add indexes untuk high-frequency filters:

```text
supplier_id + status
status + submitted_at
due_date
po_number
submission_number
invoice_number
receipt_number
```

Dashboard aggregates harus query-efficient.

Avoid N+1:

```text
supplier
receipt
latest revision
documents
```

gunakan eager loading ketika diperlukan.

DataTables internal sebaiknya server-side jika volume besar.

---

# 81. Acceptance Criteria — Supplier Local

A Local Supplier dapat:

1. login menggunakan authentication existing;
2. hanya melihat Local navigation;
3. submit invoice;
4. memasukkan PO manual;
5. memasukkan invoice amount dan PPN manual;
6. upload invoice;
7. upload faktur pajak;
8. optional upload supporting document;
9. memperoleh submission ID;
10. memperoleh printable receipt;
11. track invoice;
12. melihat physical verification status;
13. melihat revision reason;
14. melakukan resubmit pada submission yang sama;
15. melihat current status;
16. menerima relevant notification;
17. tidak dapat melihat Local invoice supplier lain;
18. tidak dapat mengakses Import domain jika tidak memiliki Import scope.

---

# 82. Acceptance Criteria — BOTH Supplier

Supplier dengan:

```text
import + local
```

dapat:

- masuk Import portal;
- masuk Local portal;
- berpindah context;
- menggunakan account yang sama;
- menerima data sesuai masing-masing context;
- tidak mengalami data leakage antar domain;
- existing Import functionality tetap utuh.

---

# 83. Acceptance Criteria — Accounting / Finance

Accounting/Finance dapat:

1. melihat dashboard Local invoice;
2. membuka Invoice Register;
3. search/filter invoice;
4. mencari physical document menggunakan receipt;
5. mark document received/matched;
6. review invoice setelah physical verification;
7. request revision dengan reason;
8. reject dengan reason;
9. approve;
10. menghasilkan due date otomatis;
11. memasukkan invoice ke Payment Schedule;
12. mark payment completed;
13. melihat complete audit history;
14. melihat/download Local documents secara authorized.

---

# 84. Release Gates

Implementation tidak dianggap selesai hanya karena UI bekerja.

Required release gates:

```text
Database migration successful
Existing supplier backfill successful
Local scope authorization green
Supplier isolation tests green
Local workflow tests green
Document security tests green
Concurrency tests green
Notification tests green
Full Import regression suite green
No unintended route exposure
No public Local document path
Manual UI walkthrough completed
```

---

# 85. Primary Regression Principle

Existing Import Supplier domain dianggap protected behavior.

Local development tidak boleh mengubah semantics:

```text
PR
Quotation
Award
PO
Shipment
Arrival
QC
Claim
```

Jika sebuah Local implementation membutuhkan perubahan besar terhadap model Import, itu harus dianggap architectural smell dan diperiksa ulang.

Preferred direction:

```text
extend application
not mutate procurement domain
```

---

# 86. Recommended Implementation Sequence

Final execution order:

```text
01  Supplier scope schema
02  Existing supplier import backfill
03  Scope middleware
04  Dashboard/context resolver
05  Admin user/supplier configuration
06  Accounting/Finance roles
07  LocalInvoice schema/models
08  Local authorization policies
09  Submission service
10  Private document handling
11  Supplier Local dashboard
12  Submit Invoice UI
13  Track Invoice UI
14  Invoice Detail UI
15  Receipt
16  Accounting Dashboard
17  Invoice Register
18  Physical Verification
19  Review workflow
20  Revision workflow
21  Approval + due-date calculation
22  Payment Schedule
23  Completion workflow
24  Notification/email
25  Reports/export
26  Security negative-path tests
27  Import regression
28  Final UI consistency review
```

Urutan tersebut menjaga perubahan infrastructure dan authorization selesai sebelum business workflow dibuat di atasnya.

---

# 87. Final Target Architecture

```text
                     ADASI SUPPLIER PORTAL
                              │
                  ┌───────────┴───────────┐
                  │                       │
             Shared Platform          Shared UI
                  │                       │
          Authentication              Layout
          User Management             Components
          Notifications               Design Tokens
          Private Storage             Alerts
          Audit Foundation            Tables
                  │
        ┌─────────┴──────────┐
        │                    │
 IMPORT SUPPLIER       LOCAL SUPPLIER
        │                    │
 Procurement           Invoice / AP
        │                    │
 PR                     Submission
 Quotation              Physical Docs
 Award                  Review
 PO                     Revision
 Shipment               Approval
 QC                     Payment
 Claim                  Completion
        │                    │
        └─────────┬──────────┘
                  │
            Same Application
```

Core architectural rule:

> Share platform capabilities where sharing is safe. Isolate business domains wherever their data, workflow, authorization, or lifecycle differs.

---

# 88. Definition of Done

Local Supplier implementation dinyatakan complete apabila:

- seluruh confirmed business requirement terimplementasi;
- Local dan Import berada dalam aplikasi yang sama;
- supplier Local/Import/Both resolution benar;
- Local transaction tidak menggunakan Import procurement table sebagai source of truth;
- Local supplier isolation terbukti melalui negative-path tests;
- physical verification enforced sebelum review/approval;
- revision mempertahankan submission identity dan audit history;
- required document bersifat private dan authorized;
- due date dihitung dari approval date + payment term snapshot;
- payment lifecycle manual dan audited;
- UI konsisten dengan ADASI Supplier Portal existing;
- mockup digunakan sebagai workflow/reference, bukan copied visual implementation;
- existing Import workflow tetap berfungsi;
- seluruh targeted dan regression tests pass;
- tidak ada unrelated refactor atau behavioral regression.

---

## Final Engineering Principle

Implementasi harus diperlakukan sebagai:

```text
NEW BOUNDED CONTEXT
WITHIN EXISTING APPLICATION
```

bukan:

```text
COPY OF IMPORT SUPPLIER
```

dan bukan:

```text
SECOND APPLICATION
```

Hasil akhirnya harus memungkinkan ADASI memiliki satu Supplier Portal dengan dua business context yang terpisah secara jelas:

```text
Import Procurement
Local Invoice / AP
```

tanpa mengorbankan isolation, maintainability, security, auditability, maupun existing Import Supplier behavior.