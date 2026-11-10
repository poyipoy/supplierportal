# ADASI PORTAL — IMPLEMENTATION PLAN
# Supplier Registration & Onboarding

**Feature:** Supplier Registration / Supplier Onboarding  
**Target Users:** Prospective Supplier, Admin ADASI, Finance ADASI, Purchasing ADASI  
**Repository:** `https://github.com/poyipoy/supplierportal`  
**Primary Principle:** `Evidence → Correctness → Security → Minimal Change → Verification → Maintainability`

---

## 0. Document Purpose

Dokumen ini merupakan implementation plan untuk membangun fitur **registrasi supplier** pada ADASI Supplier Portal.

Plan ini disusun berdasarkan requirement dan keputusan bisnis yang telah dikunci, serta mempertimbangkan struktur codebase existing yang telah diperiksa.

Dokumen ini menjadi **functional and engineering source of truth** untuk implementasi.

Implementer wajib:

- membaca source code repository terlebih dahulu;
- menggunakan abstraction existing ketika memang sesuai;
- tidak mengubah business rule existing tanpa alasan yang dapat dibuktikan;
- menjaga supplier isolation dan authorization boundary;
- tidak menganggap input public registration sebagai trusted input;
- tidak mengklaim verification/test berhasil bila tidak benar-benar dijalankan.

---

# 1. Executive Summary

Fitur ini memperkenalkan onboarding supplier berbasis **hybrid self-registration**.

Supplier dapat membuat registration sendiri melalui public registration page, tetapi registration tersebut **tidak membuat supplier langsung aktif**.

Alur utama:

```text
Public Supplier Registration
        ↓
PENDING
        ↓
    Review ADASI
        ├── REVISION
        │      ↓
        │   Resubmit
        │      ↓
        │    PENDING
        │
        ├── REJECTED
        │      ↓
        │   New Registration Attempt
        │
        └── APPROVE
               ↓
        Assign Portal Scope
               ↓
        APPROVED (audit/event)
               ↓
             ACTIVE
```

Approval cukup dilakukan oleh **salah satu** dari:

- Admin
- Finance
- Purchasing

Ketiga role mempunyai permission yang sama terhadap registration.

Saat approval dilakukan, **approval + scope assignment + activation harus atomic dalam satu transaction**.

Supplier tidak menentukan portal scope sendiri.

---

# 2. Locked Business Decisions

## 2.1 Registration Model

Model:

**Hybrid / Self-registration**

Supplier dapat mendaftar sendiri.

Namun public registration hanya membuat account supplier dalam status onboarding dan **tidak memberikan access ke portal**.

## 2.2 Initial State

Registration pertama selalu:

```text
PENDING
```

Tidak ada:

```text
EMAIL_VERIFICATION_REQUIRED
```

Email verification tidak menjadi bagian dari lifecycle registration.

Email tetap digunakan sebagai username/login identifier.

## 2.3 Lifecycle

Account lifecycle:

```text
PENDING
   │
   ├── REVISION
   │      │
   │      └── Resubmit → PENDING
   │
   ├── REJECTED
   │      │
   │      └── New Registration Attempt
   │
   └── Approval
          │
          ├── APPROVED (audit/event)
          ├── Scope Assigned
          └── ACTIVE
```

Account status yang persistent:

```text
PENDING
REVISION
ACTIVE
REJECTED
```

`APPROVED` adalah transition/audit state, bukan final persistent account state.

## 2.4 Approval Rule

Cukup **satu reviewer**.

Reviewer yang berwenang:

```text
Admin
Finance
Purchasing
```

Tidak diperlukan tiga approval.

Contoh:

```text
PENDING
   ↓
Finance approves
   ↓
scope assigned
   ↓
ACTIVE
```

## 2.5 Scope Assignment

Supplier tidak memilih scope sendiri.

Scope hanya ditentukan oleh reviewer saat approval.

Pilihan:

```text
Material Procurement  → technical scope: import
Local Supplier        → technical scope: local
Both                  → import + local
```

Technical scope existing:

```text
import
local
```

tidak boleh diubah.

Initial state:

```text
role = supplier
account_status = PENDING
is_active = false
supplier scopes = none
```

## 2.6 Account Activation

Approve melakukan secara atomic:

```text
registration → APPROVED audit event
scope assignment
account_status → ACTIVE
is_active → true
```

Tidak ada activation step tambahan.

Supplier dapat login normal setelah account ACTIVE.

## 2.7 Email

Email tetap:

- wajib;
- unique;
- digunakan sebagai username/login.

Tidak ada email verification.

Tidak ada email notification kepada supplier.

Registration/review notification menggunakan mekanisme **in-app notification existing project**.

## 2.8 Password

Supplier membuat password saat registration.

Password harus mengikuti:

```php
Rules\Password::defaults()
```

Tidak ada password activation step setelah approval.

## 2.9 Login Before Activation

Account dengan:

```text
PENDING
REVISION
REJECTED
```

tidak boleh login.

Response harus indistinguishable dari credential failure yang normal.

Jangan memberikan pesan:

- Account pending;
- Registration rejected;
- Waiting for approval;
- Revision required.

Public login response tetap menggunakan generic authentication failure.

Tujuannya mencegah account-status enumeration.

## 2.10 Reviewer Notification

Registration event mengirim in-app notification ke:

```text
Admin
Finance
Purchasing
```

Event yang harus menimbulkan notification:

- new registration submitted;
- revision requested;
- registration resubmitted;
- registration approved;
- registration rejected.

Tidak ada email notification.

Supplier tidak menerima notification dari registration system.

---

# 3. Company Identity Requirement

Supplier company identity harus memisahkan:

```text
Company Title
Company Name
```

Contoh:

```text
Company Title: PT
Company Name: Astra Daido Steel Indonesia
```

Render:

```text
PT Astra Daido Steel Indonesia
```

Company Title options:

```text
PT
CV
UD
PD
VPD
Firma
Koperasi
Yayasan
Company
Other
```

Jika:

```text
Other
```

maka tampilkan input custom company title.

### Implementation rule

`company_title` sebaiknya disimpan sebagai string, bukan database enum.

Reason:

- daftar legal/business designation dapat berkembang;
- menambah title tidak memerlukan migration;
- UI dapat dikontrol melalui configuration;
- tetap menjaga referential simplicity.

Recommended configuration:

```text
config/supplier_registration.php
```

dengan daftar company titles.

---

# 4. Registration Data Requirements

## 4.1 Account

Required:

```text
Full Name
Email
Password
Password Confirmation
```

## 4.2 Company

Required:

```text
Company Title
Company Name
Registered Address
Phone / Company Contact
Category / Business Category
NIB
NIK / NPWP
```

### Tax identity

Requirement bisnis menetapkan **satu field** untuk NIK/NPWP.

Gunakan satu canonical field:

```text
tax identity number
```

Namun karena codebase existing telah menggunakan column `suppliers.npwp`, lakukan codebase audit sebelum rename.

Preferred minimal-change strategy:

- pertahankan `suppliers.npwp` bila existing code bergantung padanya;
- ubah semantic/UI menjadi **NIK / NPWP**;
- gunakan dedicated normalized fingerprint untuk duplicate detection;
- hanya rename database column jika repository-wide impact assessment membuktikan aman.

Jangan membuat dua field NIK dan NPWP bila requirement tetap satu field.

## 4.3 PIC

Required:

```text
PIC Name
PIC Email
PIC Phone
```

Email PIC bukan mekanisme login supplier.

## 4.4 Bank Account

Required saat registration:

```text
Bank Name
Account Number
Account Holder Name
SKNR
```

Existing model:

```text
App\Models\SupplierBankAccount
```

sudah menyediakan lifecycle:

```text
PENDING
VERIFIED
REJECTED
INACTIVE
```

Registration harus membuat/update bank account dengan:

```text
status = PENDING
```

Registration approval **tidak otomatis** mengubah bank account menjadi VERIFIED.

Finance tetap menjadi authority untuk bank verification menggunakan existing flow.

---

# 5. Required Documents

Mandatory:

```text
NIB
NPWP / tax identity document
SKNR
```

Optional:

```text
SPPKP
SKD
```

Maximum:

```text
5 MB per file
```

File type mengikuti existing attachment/document validation rule.

Do not invent a new file-type policy when the repository already has one.

---

# 6. Existing Repository Structures to Reuse

Repository saat ini sudah memiliki beberapa komponen yang relevan.

## 6.1 User

Existing:

```text
app/Models/User.php
```

Existing fields include:

```text
name
email
password
role
is_active
```

Registration menambahkan lifecycle:

```text
account_status
```

## 6.2 Supplier

Existing:

```text
app/Models/Supplier.php
```

Already contains:

```text
company_name
address
phone
npwp
category
pic_name
pic_email
pic_phone
```

Registration sebaiknya mengisi data existing tersebut daripada membuat duplicate company profile model.

Potential schema additions:

```text
company_title
nib
```

dan fingerprint fields yang diperlukan untuk duplicate enforcement.

## 6.3 Supplier Bank Account

Existing:

```text
app/Models/SupplierBankAccount.php
```

Reuse.

Do not create a second bank-account domain model.

## 6.4 Supplier Master Document

Existing:

```text
app/Models/SupplierMasterDocument.php
```

Existing document types include:

```text
NIB
NPWP
SPPKP
SURAT_PERNYATAAN_REKENING
OTHER
```

First verify whether:

```text
SURAT_PERNYATAAN_REKENING
```

is semantically equivalent to the required SKNR document.

If yes:

- reuse it;
- expose it in registration UI as `SKNR`.

Only create a dedicated `SKNR` type if repository evidence proves the existing type is semantically insufficient.

## 6.5 Attachment Storage

Existing attachment infrastructure must remain authoritative.

New registration documents must use private storage and existing secure file-serving conventions.

Do not create a parallel public upload/storage system.

## 6.6 NotificationService

Existing:

```text
app/Services/NotificationService.php
```

supports:

- multiple recipients;
- event;
- event key;
- idempotent notification IDs;
- domain/category;
- `DB::afterCommit()` behavior.

Registration notifications should reuse this service.

Do not introduce a second notification delivery architecture.

---

# 7. Registration Attempt Architecture

Because rejected suppliers may register again, registration history must not be overwritten.

Conceptual model:

```text
User
 │
 └── Supplier
       │
       └── Registration Case / Access
              │
              ├── Attempt #1 → REJECTED
              │
              ├── Attempt #2 → REVISION
              │
              ├── Attempt #3 → PENDING
              │
              └── Attempt #4 → APPROVED → ACTIVE
```

Each registration attempt stores a complete submission snapshot.

---

# 8. Proposed Data Model

## 8.1 `users`

Add:

```text
account_status
```

Values:

```text
PENDING
REVISION
ACTIVE
REJECTED
```

Migration default for existing users:

```text
ACTIVE
```

because existing accounts already operate in the live system.

For new public supplier registration:

```text
PENDING
```

and:

```text
is_active = false
```

### Important compatibility invariant

Existing `is_active` remains part of the authentication/activation model.

However, all supplier operational eligibility must require:

```text
role = supplier
AND account_status = ACTIVE
AND is_active = true
```

Do not let an old query that checks only:

```text
role = supplier
AND is_active = true
```

accidentally activate the new onboarding states.

---

# 9. `supplier_registration_attempts`

Recommended structure:

```text
id
user_id
attempt_number

status

submission_snapshot
submission_checksum

submitted_at
reviewed_at

reviewed_by

revision_reason
rejection_reason

approved_at
rejected_at

created_at
updated_at
```

Status values:

```text
PENDING
REVISION
APPROVED
REJECTED
```

`APPROVED` is retained at attempt level as historical outcome.

### Snapshot contents

Snapshot must include the data submitted by the applicant, for example:

```text
company_title
company_name
address
phone
category
nib
tax_identity_number
pic_name
pic_email
pic_phone

bank_name
account_number
account_holder_name
```

Do NOT store:

```text
password
password confirmation
raw access token
```

inside the snapshot.

Sensitive snapshot data should use the application's existing encryption/casting conventions where practical.

Recommended:

```text
submission_snapshot
```

as an encrypted structured payload if supported safely by the existing database and model conventions.

---

# 10. Registration Access Credential

Because:

- supplier cannot login before ACTIVE;
- there is no email notification;
- there is no email verification;

supplier still needs a way to return to a `REVISION` registration.

Therefore the feature requires a **registration workflow access credential** that is separate from portal authentication.

## 10.1 Access model

Recommended structure:

```text
supplier_registration_access
----------------------------
id
user_id
registration_reference
token_hash
issued_at
expires_at
last_used_at
revoked_at
created_at
updated_at
```

One access credential belongs to one supplier account.

It should survive:

```text
PENDING
REVISION
REJECTED
new attempt
```

and be revoked once the supplier becomes ACTIVE, unless the application requires a read-only registration history page after activation.

## 10.2 Access credential requirements

The access token must be:

- cryptographically random;
- high entropy;
- stored hashed;
- never stored plaintext;
- rate limited;
- usable only for registration workflow;
- invalidated after ACTIVE when appropriate;
- never accepted by `Auth::login()`;
- never treated as a normal Laravel user session.

Registration workflow access must never grant:

```text
/dashboard
/invoices
/quotations
/purchase-orders
/profile
```

or any other authenticated portal resource.

## 10.3 Registration reference

The registration reference should be opaque and non-sequential.

Example:

```text
REG-8K4M2Q7P
```

Do not use raw database integer IDs.

The registration reference is an identifier, not an authorization secret.

Authorization requires the registration access secret.

---

# 11. Registration Access Workflow

## First registration

```text
Supplier
  ↓
/supplier/register
  ↓
Submit
  ↓
PENDING
  ↓
Generate registration reference + access credential
  ↓
Display both to supplier
```

The supplier must be instructed to retain these values.

Example:

```text
Registration Number:
REG-8K4M2Q7P

Registration Access Key:
••••••••••••••••
```

The access key should not be re-displayed later in plaintext.

Provide a clear copy action where appropriate.

## Status lookup

Supplier can visit:

```text
/supplier/registration/status
```

and submit:

```text
registration_reference
registration_access_key
```

After successful authentication to the registration workflow:

- create a dedicated short-lived registration session;
- do not call `Auth::login()`;
- regenerate the normal session ID;
- store only the minimum registration-access context;
- expire inactive registration sessions;
- restrict this session to registration routes.

---

# 12. Revision Workflow

When reviewer chooses:

```text
Request Revision
```

requirements:

- current attempt status must be `PENDING`;
- reviewer must be authorized;
- revision reason is mandatory;
- transaction updates account to `REVISION`;
- audit event is written;
- reviewer notification is sent after commit.

Supplier then accesses registration workflow:

```text
REVISION
   ↓
Edit all allowed data
   ↓
Replace/add/remove documents
   ↓
Resubmit
   ↓
New registration attempt
   ↓
PENDING
```

The old attempt remains historical and is not overwritten.

---

# 13. Rejected Workflow

When reviewer chooses:

```text
Reject
```

requirements:

- current attempt must still be actionable;
- rejection reason is mandatory;
- audit event is recorded;
- account becomes `REJECTED`;
- `is_active = false`;
- active supplier scopes must not remain;
- supplier remains unable to login.

Supplier may register again.

A new attempt is created against the same user identity.

Do not create a new user record solely because a previous registration was rejected.

---

# 14. Re-registration Rules

### Allowed

Same:

```text
email
NIB
NIK/NPWP
```

may be reused when the previous registration is `REJECTED`.

### Blocked

The following must block new registration against another active/in-progress supplier:

```text
same email
same NIB
same tax identity number
```

for supplier accounts/registrations that are:

```text
PENDING
REVISION
ACTIVE
```

### Public response

Do not disclose exactly which record caused the duplicate.

Use a generic response such as:

```text
The account or company information is already registered
or currently under review.
```

Do not expose:

- supplier company name;
- supplier ID;
- existing email;
- existing registration status;
- existing reviewer.

---

# 15. Duplicate Enforcement & Concurrency

Application-level duplicate checks are insufficient on their own.

Two public registration requests can arrive concurrently.

Therefore duplicate identity enforcement must have a database-level concurrency-safe strategy.

## Recommended strategy

Use normalized fingerprints for identity fields, for example:

```text
nib_fingerprint
tax_identity_fingerprint
```

stored as fixed-length hashes.

For currently active/in-progress supplier identities, fingerprints must be unique.

Recommended supplier master fields:

```text
nib
nib_fingerprint

npwp
tax_identity_fingerprint
```

where the existing `npwp` column is retained for compatibility if repository audit confirms this is the least disruptive approach.

When the supplier account reaches:

```text
REJECTED
```

the active uniqueness claim can be released while the historical snapshot remains intact.

### Important

Do not rely only on:

```php
Model::where(...)->exists()
```

for duplicate identity enforcement.

The database must participate in the race-condition defense.

Before migration, inspect existing data for duplicate NIB/NPWP identities. Resolve or safely classify collisions before adding unique constraints.

---

# 16. Approval Transaction

Approval is a security-critical transaction.

Pseudo-flow:

```text
BEGIN TRANSACTION

lock registration attempt

verify:
    status == PENDING

lock supplier/user record

verify:
    reviewer is active
    reviewer role ∈ {admin, finance, purchasing}

validate selected scope(s)

apply approved registration data to supplier master
ensure required bank data exists
ensure required documents exist

assign supplier scope(s)

record approval audit event
record scope assignment audit event
set:
    account_status = ACTIVE
    is_active = true

commit

send reviewer/internal notification after commit
```

### Supplier scope selection

Permitted:

```text
import
local
both
```

No other values.

Do not accept scope values directly from public registration.

---

# 17. Approval Race Condition

Scenario:

```text
Admin opens PENDING
Finance opens same PENDING

Admin → Approve
Finance → Approve
```

Required outcome:

```text
First valid transaction → succeeds

Second transaction:
    sees already processed state
    does not overwrite it
    does not duplicate activation
    does not duplicate scope assignment
    does not duplicate audit/notification
```

Use transaction + row locking.

Do not rely only on UI disabling buttons.

---

# 18. Reviewer Authorization Matrix

| Action | Admin | Finance | Purchasing |
|---|---:|---:|---:|
| View registration | YES | YES | YES |
| View documents | YES | YES | YES |
| View registration history | YES | YES | YES |
| Request revision | YES | YES | YES |
| Reject | YES | YES | YES |
| Approve | YES | YES | YES |
| Assign scope during approval | YES | YES | YES |

No role-based distinction is required inside this feature.

Authentication and active-account checks still apply.

---

# 19. Reviewer UI

Create a shared internal registration management area.

Recommended route family:

```text
/supplier-registrations
```

Suggested named routes:

```text
supplier-registrations.index
supplier-registrations.show
supplier-registrations.request-revision
supplier-registrations.reject
supplier-registrations.approve
```

Protect with:

```text
auth
role:admin,finance,purchasing
```

and server-side authorization.

## 19.1 List

Columns:

```text
Registration Reference
Company
NIB
NIK/NPWP
PIC
Submitted At
Status
Last Reviewer
Action
```

Filters:

```text
PENDING
REVISION
REJECTED
ACTIVE
ALL
```

Default view should prioritize actionable states:

```text
PENDING
REVISION
```

## 19.2 Detail

Sections:

```text
Registration Summary
Account
Company
PIC
Tax Identity
Bank Account
Documents
Submission Snapshot
Registration History
Reviewer Actions
```

Reviewer should be able to compare current master data with the selected registration snapshot when useful.

## 19.3 Reviewer Actions

For `PENDING`:

```text
Request Revision
Reject
Approve
```

Approve dialog/form must include:

```text
Portal Scope
[ ] Material Procurement
[ ] Local Supplier
```

At least one scope is required.

Action:

```text
Approve & Activate
```

The action must execute atomically.

---

# 20. Reviewer Notification Model

Use existing:

```text
App\Services\NotificationService
```

Suggested event families:

```text
supplier_registration_submitted
supplier_registration_revision_requested
supplier_registration_resubmitted
supplier_registration_approved
supplier_registration_rejected
```

Event key should uniquely identify the business event and recipient.

Example conceptual key:

```text
supplier-registration:{reference}:submitted
```

Notification must be sent using existing `afterCommit` behavior.

Do not send notification before the database transaction is committed.

---

# 21. Audit Trail

Registration requires an immutable-oriented business audit trail.

Minimum events:

```text
registration_created
registration_submitted
revision_requested
registration_resubmitted
registration_rejected
registration_approved
scope_assigned
account_activated
document_uploaded
document_replaced
document_removed
bank_account_updated
```

Each event should identify:

```text
registration_attempt_id
actor_id
actor_role
action
timestamp
reason/notes
```

Where useful:

```text
before_snapshot
after_snapshot
```

Do not store secrets such as:

```text
password
access token
```

inside audit records.

---

# 22. Document Lifecycle

## Upload

All registration documents:

```text
private storage
```

must use:

- generated storage filename;
- safe original filename metadata;
- MIME validation;
- extension validation;
- size validation;
- existing storage disk convention.

Never use user-supplied filename as storage path.

## Ownership

Document access must enforce:

```text
reviewer authorized for registration
OR
supplier currently authenticated to own registration workflow
```

Do not authorize solely by:

```text
attachment ID
filename
Hashid
session context
```

## Revision

User decision:

```text
Old documents may be removed/replaced.
```

Therefore:

- current master document is replaced;
- old binary may be deleted according to existing retention convention;
- audit records the replacement/removal event;
- store checksum/metadata of old file when practical for audit evidence;
- never expose deleted file as current.

Optional documents:

```text
SPPKP
SKD
```

may be removed during revision.

Mandatory documents may not be absent at final resubmission.

---

# 23. SKNR ↔ Bank Account Relationship

SKNR must refer to the same bank account submitted during registration.

Existing `SupplierMasterDocument` currently represents documents at supplier level.

During implementation:

1. verify whether current schema already supports document-to-bank-account linkage;
2. if not, add a nullable `supplier_bank_account_id` relationship for documents;
3. require the relationship for SKNR;
4. keep it nullable for unrelated supplier master documents.

This creates the invariant:

```text
SKNR
  ↓
Bank Account A
```

instead of:

```text
Supplier
 ├── Bank Account A
 ├── Bank Account B
 └── unrelated SKNR
```

Do not auto-verify the bank account merely because SKNR exists.

---

# 24. Existing User Management Interaction

Existing:

```text
app/Http/Controllers/Admin/UserController.php
```

already allows Admin to create/update suppliers and scopes.

Registration implementation must not accidentally create a bypass through this existing path.

Specific tasks:

1. audit supplier create path;
2. audit supplier update path;
3. prevent PENDING/REVISION/REJECTED accounts from being operationally eligible;
4. ensure account status is not silently reset to ACTIVE;
5. preserve existing internal-user management;
6. preserve supplier scope assignment behavior for authorized administrators.

Do not rewrite UserController unless necessary.

Prefer targeted changes.

---

# 25. Authentication Changes

Existing:

```text
app/Http/Requests/Auth/LoginRequest.php
```

currently uses `is_active` to determine whether the login attempt is valid.

Update authentication so the effective condition becomes:

```text
User exists
AND is_active = true
AND account_status = ACTIVE
AND password matches
```

For:

```text
PENDING
REVISION
REJECTED
```

the login path must still:

- perform constant-time password comparison using the existing dummy-hash approach where necessary;
- record generic login failure;
- return the same public authentication failure response;
- avoid revealing account status.

Do not add messages that disclose registration state.

---

# 26. Supplier Eligibility Changes

Existing helper methods such as:

```text
User::isImportEligible()
User::isLocalEligible()
scopeImportEligible()
scopeLocalEligible()
```

must be audited.

They must not classify PENDING/REVISION/REJECTED suppliers as eligible.

Expected effective rule:

```text
role = supplier
AND account_status = ACTIVE
AND is_active = true
AND required scope exists
```

This is a critical regression-prevention requirement.

Also audit:

- supplier route middleware;
- supplier dashboard access;
- quotation visibility;
- PO visibility;
- invoice access;
- shipment access;
- supplier switch portal;
- supplier-specific exports.

Do not assume checking `is_active` alone is sufficient.

---

# 27. Public Registration Controller/Service Design

Do not keep the existing generic registration controller as a large monolithic method.

Recommended separation:

```text
SupplierRegistrationController
    create()
    store()
    status/access entrypoint

SupplierRegistrationService
    createRegistration()
    submit()
    resubmit()
    requestRevision()
    reject()
    approve()
```

The service should own:

- transactions;
- duplicate enforcement;
- attempt creation;
- master synchronization;
- scope assignment;
- account activation;
- audit events.

Request classes should own validation.

Controllers should remain thin.

Do not introduce unnecessary abstraction if an existing project service pattern already provides an appropriate location.

---

# 28. Public Registration Validation

Validation must cover at least:

```text
name
email
password
password_confirmation

company_title
company_name
address
phone
category

nib
npwp/tax identity
pic_name
pic_email
pic_phone

bank_name
account_number
account_holder_name

required documents
optional documents
```

Do not accept:

```text
role
is_active
account_status
supplier_scopes
approved_by
reviewed_by
review_status
```

from the public request.

Even if supplied, these values must not influence server-side authorization.

---

# 29. Mass Assignment Protection

The registration implementation must explicitly construct trusted fields.

Do not:

```php
User::create($request->all());
```

Do not:

```php
Supplier::create($request->all());
```

Do not allow hidden inputs to define security-sensitive state.

Public request data and server-generated state must remain separated.

---

# 30. CSRF and HTTP Method

Mutating public/internal registration actions must use:

```text
POST
```

or appropriate HTTP verbs.

All browser forms must include:

```text
@csrf
```

Reviewer actions must not be implemented as GET mutations.

---

# 31. Rate Limiting & Abuse Protection

Public registration must use dedicated throttling.

At minimum:

```text
registration submit
registration access
registration status/retry endpoints
```

must be protected.

Recommended implementation default:

```text
registration attempts:
5 requests / 10 minutes / effective requester identity

access validation:
strictly lower threshold than ordinary browsing

repeated invalid access:
progressive throttling
```

The exact thresholds should follow existing project throttle conventions when available.

Rate limiting should consider:

```text
IP
normalized email
```

without creating a broad shared-NAT denial problem.

---

# 32. Turnstile / Anti-Bot

The project already contains:

```text
TurnstileVerifier
```

and existing Turnstile configuration.

Registration should reuse this capability:

```text
if configured:
    require valid Turnstile response according to registration abuse policy
else:
    continue without Turnstile
```

Do not create a second CAPTCHA integration.

Provider failure behavior should follow existing security conventions.

---

# 33. Enumeration Protection

Protect against:

## Email enumeration

Registration responses must not confirm:

```text
email exists
email belongs to supplier
email is pending
```

Use generic duplicate messaging.

## Company enumeration

Do not reveal:

```text
which company owns NIB
which supplier owns tax identity
```

## Login enumeration

Pending/rejected/revision accounts use the same generic credential failure response.

## Registration status enumeration

Status lookup requires:

```text
registration reference
+
access credential
```

and must be rate limited.

Do not permit lookup by:

```text
email alone
NIB alone
NPWP alone
company name alone
```

---

# 34. Session Security for Registration Access

The registration access flow is not Laravel authenticated user login.

On successful access:

```text
session.regenerate()
```

and store a dedicated registration-context marker such as:

```text
supplier_registration_access_user_id
supplier_registration_access_reference
supplier_registration_access_expires_at
```

Do not populate:

```text
auth user
```

and do not call:

```text
Auth::login()
```

Registration access middleware must explicitly reject requests to authenticated portal routes.

---

# 35. Authorization Model

There are three independent security boundaries:

```text
PUBLIC REGISTRATION ACCESS
        ≠
PORTAL AUTHENTICATION
        ≠
SUPPLIER PORTAL SCOPE
```

Public registration can only manipulate its own application.

Internal reviewer can access registration data according to reviewer authorization.

Portal scope remains determined by:

```text
supplier_scopes
```

not by:

```text
registration access
session context
UI state
```

---

# 36. Multi-tab / Context Interaction

Registration does not participate in Supplier Portal context switching.

Do not reuse:

```text
supplier_context
```

as registration authorization.

A registration applicant who has no ACTIVE account cannot enter the normal supplier portal.

---

# 37. Defensive Rendering

Registration and reviewer views must safely handle:

- missing optional documents;
- missing review notes on historical records;
- null reviewer;
- null reviewed_at;
- null optional values;
- legacy supplier rows with missing new fields;
- removed/superseded documents;
- incomplete legacy bank data.

Avoid unguarded:

```php
$array['key']
```

and unguarded relation access in Blade when the relation may not exist.

---

# 38. UI/UX

The registration UI must follow existing project design rules.

Do not introduce a separate visual system.

Use:

- existing auth layout;
- existing UI components;
- existing spacing;
- existing typography;
- existing form validation patterns;
- existing alert/toast system;
- existing responsive conventions.

## 38.1 Public Registration Flow

Recommended:

```text
Step 1 — Account
Step 2 — Company
Step 3 — PIC & Tax
Step 4 — Bank Account
Step 5 — Documents
Step 6 — Review & Submit
```

The wizard/stepper is a UX choice; implementation should follow the project's existing form patterns.

If a multi-step UI adds significant complexity without an existing project pattern, a single structured form is acceptable.

Functional flow is more important than a new JS architecture.

## 38.2 Submission Result

After successful registration:

```text
Registration Submitted
```

Display:

```text
Registration Number
Registration Access Key
Status: PENDING
```

Provide:

```text
Copy Registration Number
Copy Access Key
Print/Save instruction
```

The page must strongly tell the supplier to keep the access information.

Do not expose the access key again after its initial presentation.

## 38.3 Status Page

Show:

```text
Registration Number
Current Status
Submitted Date
Latest Action
```

For `REVISION`:

```text
Action Required
Reason
[Review & Update Registration]
```

For `REJECTED`:

```text
Registration Rejected
Reason
[Start New Registration]
```

For `ACTIVE`:

```text
Registration Approved
Account Active
[Go to Login]
```

Do not reveal reviewer internal identity if not required by business rules.

---

# 39. Existing Route Migration

The project currently has generic registration behavior.

The implementation must inspect:

```text
routes/auth.php
routes/web.php
```

and determine how the current `/register` route is wired.

New supplier registration should use a dedicated URL:

```text
/supplier/register
```

Recommended route naming:

```text
supplier.register
supplier.register.store
supplier.registration.access
supplier.registration.status
supplier.registration.resubmit
```

The existing generic `/register` behavior must not remain as an uncontrolled path that can create active users.

Potential options after repository audit:

1. Replace generic registration with supplier registration.
2. Redirect `/register` to `/supplier/register`.
3. Remove generic registration if no other role uses it.

Choose the smallest safe option based on actual route usage.

---

# 40. Database Migration Plan

Potential migrations:

## Migration A — user account lifecycle

Add:

```text
users.account_status
```

Default:

```text
ACTIVE
```

Existing rows are treated as ACTIVE.

## Migration B — supplier company identity

Add:

```text
suppliers.company_title
suppliers.nib
```

Potential compatibility fields:

```text
suppliers.nib_fingerprint
suppliers.tax_identity_fingerprint
```

Only add fields supported by the repository-wide duplicate strategy.

## Migration C — supplier registration attempts

Create:

```text
supplier_registration_attempts
```

with appropriate indexes:

```text
user_id
status
submitted_at
reviewed_by
```

## Migration D — registration access

Create:

```text
supplier_registration_access
```

with:

```text
user_id unique
registration_reference unique
token_hash
expires_at
revoked_at
```

Do not store raw access token.

## Migration E — document/bank relation

Only if required after repository audit:

```text
supplier_master_documents.supplier_bank_account_id
```

nullable, with appropriate foreign key behavior.

## Migration F — audit

Create:

```text
supplier_registration_audits
```

if no existing audit abstraction can safely capture the business lifecycle.

---

# 41. Indexing Strategy

Expected indexing:

```text
users.email UNIQUE
users.account_status INDEX

supplier_registration_attempts.user_id INDEX
supplier_registration_attempts.status INDEX
supplier_registration_attempts.submitted_at INDEX

supplier_registration_access.user_id UNIQUE
supplier_registration_access.registration_reference UNIQUE

suppliers.nib_fingerprint UNIQUE nullable
suppliers.tax_identity_fingerprint UNIQUE nullable
```

The exact nullable/unique strategy must be compatible with MySQL/MariaDB semantics used by the project.

Before adding unique indexes:

- inspect existing duplicates;
- produce a pre-migration duplicate report;
- do not silently delete or alter existing supplier master data.

---

# 42. Transaction Boundaries

Registration submission should use transaction boundaries around:

```text
user create/update
supplier create/update
bank account create/update
required document metadata
registration attempt
audit event
```

File binary writes should follow the application's established storage/transaction pattern.

Do not leave database records pointing to files that were never successfully stored.

Do not send notifications inside an uncommitted transaction.

Use existing `DB::afterCommit()` behavior for notification delivery.

---

# 43. Rollback / Failure Handling

If any required database operation fails:

```text
entire registration transaction rolls back
```

Do not leave:

```text
user created
but supplier missing
```

or:

```text
registration attempt created
but required documents missing
```

or:

```text
ACTIVE account
but no portal scope
```

For file operations:

- clean up orphaned files on transaction failure;
- do not silently swallow cleanup errors;
- log safely without sensitive content.

---

# 44. Security Threat Model

## T01 — Privilege escalation

Threat:

Applicant submits:

```text
role=admin
is_active=true
supplier_scopes[]=import
```

Required result:

```text
role=supplier
account_status=PENDING
is_active=false
no scope
```

## T02 — IDOR registration record

Threat:

Applicant changes registration ID/reference.

Required:

```text
access denied
```

unless valid registration access credential proves ownership.

## T03 — IDOR documents

Threat:

Supplier A guesses Supplier B's attachment ID.

Required:

```text
403/authorization failure
```

or equivalent secure denial.

## T04 — Duplicate identity race

Threat:

Two registrations submit same NIB simultaneously.

Required:

Only one non-rejected owner succeeds.

## T05 — Reviewer race

Threat:

Two reviewers approve simultaneously.

Required:

Only one transition succeeds.

## T06 — Session bypass

Threat:

PENDING supplier manipulates normal auth session.

Required:

No portal login.

## T07 — Scope manipulation

Threat:

Applicant sends:

```text
supplier_scopes[]=both
```

Required:

Ignored/rejected during public registration.

Scope only assigned by authorized reviewer.

## T08 — Status manipulation

Threat:

Applicant sends:

```text
account_status=ACTIVE
```

Required:

Ignored/rejected.

## T09 — Login enumeration

Threat:

Attacker probes pending/rejected emails.

Required:

same generic login failure response.

## T10 — Registration status enumeration

Threat:

Attacker probes registration references.

Required:

access credential required + rate limiting.

## T11 — Token leakage

Threat:

Registration access token appears in:

- logs;
- audit;
- exceptions;
- notification payloads;
- database plaintext.

Required:

never persist or log raw token.

## T12 — File upload attack

Threat:

Malicious file or extension spoofing.

Required:

existing attachment validation + private storage + safe generated path.

---

# 45. Validation Rules for Uploaded Files

Reuse existing project conventions first.

Required checks:

```text
max size <= 5 MB
extension allowed
MIME allowed
successful upload
```

Storage:

```text
private disk
generated filename
```

Original filename:

```text
metadata only
```

Do not trust:

```text
getClientOriginalName()
```

as a path.

Do not construct filesystem paths directly from user input.

---

# 46. Registration Attempt Snapshot Integrity

Each attempt must be distinguishable.

Recommended:

```text
submission_checksum
```

computed from canonical serialized registration data.

Purpose:

- detect accidental mutation;
- prove which data was submitted;
- aid audit;
- support comparison between attempts.

Checksum must not be used as an authorization credential.

---

# 47. Approval Data Synchronization

When reviewer approves:

```text
registration attempt snapshot
        ↓
current supplier master
```

must be synchronized.

The approval transaction must use the **reviewed submission**, not whatever data happens to be currently stored in the supplier master if those differ.

This is important because:

```text
supplier master may be modified by another internal process
```

after registration submission.

The attempt snapshot is the review authority for approval.

---

# 48. Master Data Integrity

After approval:

```text
users.account_status = ACTIVE
users.is_active = true
users.role = supplier
supplier profile = approved snapshot
bank data = approved bank data
required docs = current approved docs
supplier scopes = reviewer-selected scope(s)
```

Existing supplier master workflows must then continue using normal application rules.

Registration attempt history remains immutable for auditing.

---

# 49. Post-Activation Changes

After ACTIVE:

```text
Registration workflow
```

is no longer used for normal supplier master updates.

Continue using existing:

```text
SupplierChangeRequest
```

or equivalent existing change-control mechanism.

This preserves separation:

```text
Registration = onboarding
SupplierChangeRequest = post-activation master changes
```

---

# 50. Account Status vs `is_active`

This feature introduces two different concepts:

```text
account_status
```

represents registration/account lifecycle.

```text
is_active
```

represents whether login/access is enabled.

For supplier onboarding:

```text
PENDING
    is_active=false

REVISION
    is_active=false

REJECTED
    is_active=false

ACTIVE
    is_active=true
```

For existing non-supplier users:

```text
account_status=ACTIVE
```

should preserve current semantics.

Do not globally reinterpret `is_active` without auditing all role flows.

---

# 51. Existing Admin User CRUD Compatibility

Because Admin already has:

```text
UserController::create/store/update
```

the implementation must ensure it does not accidentally bypass registration.

Specific tasks:

1. audit supplier create path;
2. audit supplier update path;
3. prevent PENDING/REVISION/REJECTED accounts from being operationally eligible;
4. ensure account status is not silently reset to ACTIVE;
5. preserve existing internal-user management;
6. preserve supplier scope assignment behavior for authorized administrators.

Do not expose `account_status` as a generic arbitrary dropdown in the public flow.

If the existing Admin UI has an "Active Account" toggle, update its behavior carefully so it cannot violate the registration lifecycle.

---

# 52. Existing Supplier Scope Compatibility

Existing:

```text
supplier_scopes
```

must remain authoritative.

Registration:

```text
no scope
```

Approval:

```text
assign selected scope(s)
```

After activation:

```text
existing scope middleware applies
```

Do not create:

```text
registration_scope
```

as a second authorization source.

---

# 53. Recommended Code Structure

Likely components:

```text
app/Http/Controllers/Auth/SupplierRegistrationController.php

app/Http/Controllers/SupplierRegistrationController.php
# or project-equivalent internal controller location

app/Http/Requests/Auth/SupplierRegistrationRequest.php

app/Http/Requests/SupplierRegistration/
    AccessRegistrationRequest.php
    ResubmitRegistrationRequest.php
    RequestRevisionRequest.php
    RejectRegistrationRequest.php
    ApproveRegistrationRequest.php

app/Models/SupplierRegistrationAttempt.php
app/Models/SupplierRegistrationAccess.php
app/Models/SupplierRegistrationAudit.php

app/Services/SupplierRegistrationService.php
```

These paths are recommendations, not absolute requirements.

Before creating them, inspect project conventions and reuse existing namespaces/patterns.

---

# 54. Testing Strategy

Tests must prove actual invariants, not merely exercise controllers.

## 54.1 Public Registration

Test:

```text
valid registration succeeds
role forced to supplier
account_status forced to PENDING
is_active forced false
no supplier scope assigned
no auto-login
```

## 54.2 Input Tampering

Test:

```text
role=admin
is_active=true
account_status=ACTIVE
supplier_scopes[]=import
approved_by=<id>
```

and prove none can elevate privileges.

## 54.3 Duplicate Registration

Test:

```text
duplicate email
duplicate NIB
duplicate NIK/NPWP
```

against:

```text
PENDING
REVISION
ACTIVE
```

and verify rejection.

## 54.4 Re-register Rejected

Test:

```text
REJECTED attempt
   ↓
same user
   ↓
new attempt
```

with same:

```text
email
NIB
NIK/NPWP
```

and verify the new attempt is accepted.

## 54.5 Revision

Test:

```text
PENDING
   ↓
REVISION
   ↓
reason required
   ↓
resubmit
   ↓
new attempt PENDING
```

Verify old attempt remains historical.

## 54.6 Reject

Test:

```text
PENDING
   ↓
REJECTED
```

and verify:

```text
is_active=false
no operational scope
login denied
```

## 54.7 Approval

Test separately for:

```text
Admin
Finance
Purchasing
```

and verify each can approve.

## 54.8 Scope Assignment

Test:

```text
import
local
both
```

and verify correct `supplier_scopes` records.

## 54.9 Approval Atomicity

Test transaction failure scenarios and verify no partial activation.

Examples:

```text
scope assignment fails
supplier update fails
audit insert fails
```

Required:

```text
no ACTIVE state
no partial scope
no partial approval
```

## 54.10 Approval Race Condition

Two concurrent approval attempts against the same PENDING registration.

Expected:

```text
exactly one successful approval
```

## 54.11 Login Security

Test:

```text
PENDING + correct password → generic auth failure
REVISION + correct password → generic auth failure
REJECTED + correct password → generic auth failure
ACTIVE + correct password → normal login
```

Also verify no status-specific response leaks.

## 54.12 Supplier Isolation

Supplier A must not access:

```text
Supplier B registration
Supplier B documents
Supplier B bank data
```

even when identifiers are known.

## 54.13 Registration Access Credential

Test:

```text
valid reference + token → access granted
wrong token → denied
expired token → denied
revoked token → denied
missing token → denied
other supplier reference + token → denied
```

Also verify registration access cannot enter normal authenticated routes.

## 54.14 Document Security

Test:

```text
required documents enforced
optional documents accepted
>5 MB rejected
invalid MIME rejected
invalid extension rejected
private storage used
cross-supplier access denied
missing attachment handled safely
```

## 54.15 Notification

Test:

```text
registration submitted
revision requested
resubmitted
approved
rejected
```

and verify:

- notification recipients are Admin/Finance/Purchasing;
- supplier does not receive registration notification;
- notification is not created before transaction commit;
- duplicate delivery is prevented by event key/idempotency mechanism.

---

# 55. Regression Tests

At minimum run:

```text
php artisan test tests/Feature/SupplierDataIsolationTest.php
```

and all relevant:

```text
tests/Feature/Auth/
tests/Feature/Supplier/
tests/Feature/LocalInvoice/
```

plus any newly created supplier registration tests.

Audit all tests that construct `User` or supplier records directly, because new `account_status` behavior may affect fixtures/factories.

---

# 56. Existing Test Fixture Compatibility

After adding:

```text
users.account_status
```

audit:

- UserFactory;
- supplier factory;
- seeders;
- test helpers;
- custom user builders;
- existing login fixtures.

Existing active users should explicitly or implicitly resolve to:

```text
account_status=ACTIVE
```

to avoid widespread test ambiguity.

Do not hide fixture problems with broad assertions.

---

# 57. Verification Commands

Run appropriate commands based on repository tooling.

Minimum:

```text
php artisan test
composer validate --strict
composer audit
vendor/bin/pint --test
```

If frontend assets change:

```text
npm.cmd run build
```

or the actual package-manager command defined by `package.json`.

Do not claim success unless commands were actually executed.

---

# 58. No Browser E2E Requirement

Browser E2E is not required for this feature unless implementation evidence reveals a requirement that cannot be verified at application/test level.

Prefer:

- feature tests;
- policy tests;
- service tests;
- route tests;
- request validation tests;
- query verification;
- security tests.

UI structure should be validated through rendered response assertions where practical.

---

# 59. Performance Requirements

Registration itself is low-frequency, but reviewer lists and documents must remain efficient.

Check:

- no N+1 registration document queries;
- no N+1 reviewer queries;
- no N+1 audit history;
- no PHP-side filtering for registration list when DB filtering can be used;
- registration list pagination remains database-backed;
- document existence checks are eager-loaded/optimized;
- duplicate checks use indexed fingerprints;
- reviewer notification recipient lookup is efficient.

Do not add caching unless a measurable problem exists.

---

# 60. Logging Requirements

Security-sensitive logging should include enough information for investigation without exposing secrets.

Safe examples:

```text
supplier_registration_created
supplier_registration_approved
supplier_registration_rejected
supplier_registration_revision_requested
supplier_registration_access_failed
```

Include:

```text
registration_reference
user_id where safe
actor_id where applicable
event
```

Do NOT log:

```text
password
access token
document contents
full sensitive tax values
bank credentials unnecessarily
```

When sensitive values are needed operationally, prefer:

```text
masked value
hash/fingerprint
```

---

# 61. Failure Messages

Public-facing messages must be generic where the response could leak protected information.

Good:

```text
Unable to process the registration.
Please review the highlighted fields.
```

Duplicate:

```text
The account or company information is already registered
or currently under review.
```

Login:

```text
These credentials do not match our records.
```

Do not expose internal reviewer or workflow details to unauthenticated requests.

---

# 62. Accessibility Requirements

Follow the project's existing accessibility rules.

At minimum:

- labels associated with inputs;
- clear required indicators;
- keyboard-accessible steps/actions;
- errors tied to fields;
- no color-only status indication;
- documents identify file type/size expectations;
- reviewer status badges have text labels;
- buttons have descriptive accessible names;
- upload fields provide acceptable format/size guidance.

Do not create custom components when existing accessible UI components already exist.

---

# 63. Internationalization / Language

Use the existing project language conventions.

Do not introduce inconsistent terminology.

Required business terms:

```text
Material Procurement
Local Supplier
Pending
Revision
Approved
Rejected
Active
Registration
Company Title
NIK / NPWP
NIB
SKNR
SPPKP
SKD
```

Avoid changing existing technical scope values.

---

# 64. Non-Goals / Explicitly Out of Scope

Do NOT implement in this feature:

- email verification;
- supplier email notifications;
- supplier self-selection of scope;
- multi-reviewer approval;
- automatic bank verification;
- automatic email activation;
- new role creation;
- portal scope renaming;
- redesign of supplier portal authorization;
- replacement of SupplierChangeRequest for active suppliers;
- public supplier directory;
- browser E2E suite;
- unrelated user-management refactor;
- unrelated authentication redesign.

---

# 65. Implementation Sequence

## Phase 1 — Repository Audit

Inspect:

```text
User
Supplier
SupplierScope
SupplierBankAccount
SupplierMasterDocument
Attachment
NotificationService
NotificationDomain
notification categories
authentication middleware
LoginRequest
registration routes
admin user controller
existing supplier change request
existing route authorization
existing file validation/storage
existing factories/seeders/tests
```

Produce internal FACT/INFERENCE/ASSUMPTION classification.

Do not code before this audit.

## Phase 2 — Schema & Model Foundation

Implement:

1. `account_status`;
2. supplier company title;
3. NIB number;
4. duplicate fingerprints;
5. registration attempt table;
6. registration access table;
7. audit table if needed;
8. bank-document relationship if needed.

Run migration tests before proceeding.

## Phase 3 — Domain / Service Layer

Implement:

```text
createRegistration
submit
requestRevision
resubmit
reject
approve
```

with transaction boundaries and security checks.

Centralize state transitions.

Do not let controllers directly manipulate registration state in multiple locations.

## Phase 4 — Authentication Boundary

Update:

- login eligibility;
- supplier eligibility;
- dashboard access;
- route protection where necessary;
- admin user-management compatibility.

Verify PENDING/REVISION/REJECTED cannot enter operational supplier routes.

## Phase 5 — Public Registration

Implement:

```text
/supplier/register
```

with:

- validation;
- document upload;
- duplicate checks;
- anti-abuse;
- registration access credential;
- submission confirmation;
- no auto-login.

## Phase 6 — Registration Access / Revision

Implement:

```text
status lookup
registration access
revision edit
resubmission
new rejected attempt
```

with dedicated registration-only session middleware if required.

## Phase 7 — Reviewer Workflow

Implement:

```text
list
detail
revision
reject
approve + scope assignment
```

for:

```text
Admin
Finance
Purchasing
```

with race-safe transactions.

## Phase 8 — Notifications & Audit

Add:

- internal reviewer notifications;
- lifecycle audit events;
- document events;
- scope assignment event;
- activation event.

Ensure notification delivery occurs after commit.

## Phase 9 — UI Consistency

Implement public and reviewer UI using existing project design components.

Do not introduce a new design system.

## Phase 10 — Full Verification

Run:

```text
focused tests
registration tests
auth tests
supplier isolation tests
attachment tests
notification tests
full relevant regression
composer validate
composer audit
Pint
frontend build if applicable
```

Then perform final diff review.

---

# 66. Acceptance Criteria

## Registration

- [ ] Supplier can register without email verification.
- [ ] Email is required and unique.
- [ ] Password follows existing password policy.
- [ ] Company Title available with `Other`.
- [ ] NIB required.
- [ ] NIK/NPWP field required.
- [ ] PIC name/email/phone required.
- [ ] Bank name/account/holder required.
- [ ] NIB document required.
- [ ] NPWP/tax identity document required.
- [ ] SKNR required.
- [ ] SPPKP optional.
- [ ] SKD optional.
- [ ] 5 MB/file limit enforced.
- [ ] Existing file validation conventions reused.

## Initial State

- [ ] role forced to supplier.
- [ ] account_status = PENDING.
- [ ] is_active = false.
- [ ] no supplier scope assigned.
- [ ] supplier is not auto-logged in.

## Review

- [ ] Admin can review.
- [ ] Finance can review.
- [ ] Purchasing can review.
- [ ] Revision requires reason.
- [ ] Reject requires reason.
- [ ] Approve requires at least one scope.
- [ ] Only one reviewer is required.

## Approval

- [ ] Approval + scope assignment + activation are atomic.
- [ ] APPROVED is recorded as transition/audit.
- [ ] final account status = ACTIVE.
- [ ] is_active = true.
- [ ] exact selected scopes assigned.
- [ ] no scope outside approved values can be created.
- [ ] bank remains subject to existing verification lifecycle.

## Revision

- [ ] PENDING can become REVISION.
- [ ] revision reason persisted.
- [ ] supplier can access registration workflow.
- [ ] supplier can edit allowed registration data.
- [ ] supplier can replace/add/remove documents according to rules.
- [ ] resubmit creates a new attempt.
- [ ] previous attempt remains historical.

## Rejection

- [ ] reason required.
- [ ] account_status = REJECTED.
- [ ] is_active = false.
- [ ] no operational supplier access.
- [ ] same user can create a new registration attempt.
- [ ] old rejected attempt remains history.

## Duplicate

- [ ] duplicate email blocked.
- [ ] duplicate NIB blocked for active/in-progress supplier identities.
- [ ] duplicate NIK/NPWP blocked for active/in-progress supplier identities.
- [ ] rejected supplier can reuse its previous identity values.
- [ ] duplicate enforcement survives concurrent requests.

## Login

- [ ] PENDING login fails generically.
- [ ] REVISION login fails generically.
- [ ] REJECTED login fails generically.
- [ ] ACTIVE login works.
- [ ] no account-status enumeration.

## Security

- [ ] public request cannot set role.
- [ ] public request cannot set active status.
- [ ] public request cannot set account status.
- [ ] public request cannot set scopes.
- [ ] registration access cannot become portal auth.
- [ ] supplier cannot access another supplier's registration.
- [ ] supplier cannot access another supplier's documents.
- [ ] reviewer authorization is server-side.
- [ ] approval race condition is safe.
- [ ] registration access is rate limited.
- [ ] Turnstile is reused when configured.
- [ ] no secrets are stored/logged in plaintext.

## Compatibility

- [ ] existing supplier scope middleware remains authoritative.
- [ ] existing supplier portal behavior remains intact.
- [ ] existing SupplierChangeRequest workflow remains intact.
- [ ] existing Admin User CRUD remains functional.
- [ ] existing active suppliers remain active.
- [ ] existing non-supplier users remain unaffected.

---

# 67. Recommended Test File Structure

Suggested:

```text
tests/Feature/SupplierRegistration/
    SupplierRegistrationTest.php
    SupplierRegistrationReviewTest.php
    SupplierRegistrationAccessTest.php
    SupplierRegistrationSecurityTest.php
    SupplierRegistrationDocumentTest.php
    SupplierRegistrationConcurrencyTest.php
```

If the repository already has a better organization pattern, reuse it.

Avoid creating six files merely for aesthetics if existing feature-test conventions favor fewer cohesive test files.

---

# 68. Evidence-First Reporting Requirement

At completion, implementation report must clearly separate:

### FACT

Directly observed:

- actual files changed;
- actual migration state;
- actual tests;
- actual command output;
- actual route behavior.

### INFERENCE

Logical conclusions derived from those facts.

### ASSUMPTION

Anything not directly verifiable.

Do not say:

```text
production-ready
```

merely because tests pass.

---

# 69. Final Implementation Report

The implementer must return:

## A. Implementation Summary

What was implemented.

## B. Exact Files Changed

Full relative paths.

## C. Database Changes

Every migration and reason.

## D. Registration State Machine

Actual implemented states and transitions.

## E. Security Model

Actual authorization boundaries.

## F. Duplicate Enforcement

Exact database/application behavior.

## G. Document Handling

Actual storage and authorization behavior.

## H. Notifications

Exact recipients/events and delivery behavior.

## I. Audit Trail

Exact events/fields recorded.

## J. Test Results

For every executed command:

```text
command
result
assertions
duration if available
```

## K. Deviations from This Plan

List every deviation explicitly.

## L. Remaining Risks

Only evidence-supported risks.

---

# 70. Final Engineering Gate

Before declaring the feature complete:

1. Inspect the final diff.
2. Confirm no unrelated files were modified.
3. Verify all security boundaries.
4. Verify account status and `is_active` interaction.
5. Verify supplier eligibility.
6. Verify public registration cannot self-assign privileges.
7. Verify duplicate identity concurrency.
8. Verify approval race handling.
9. Verify document IDOR protection.
10. Verify registration access cannot become portal login.
11. Verify notification after commit.
12. Run relevant regression tests.
13. Run `composer validate --strict`.
14. Run `composer audit`.
15. Run `vendor/bin/pint --test`.
16. Run frontend build if assets changed.
17. Run `[verification-loop](slashCommand;verification-loop)`.

No browser E2E is required unless application-level verification cannot prove a critical invariant.

---

# 71. Engineering Principles to Preserve

Throughout implementation:

```text
Evidence
   ↓
Correctness
   ↓
Security
   ↓
Minimal Change
   ↓
Verification
   ↓
Maintainability
```

Operational rules:

- inspect before changing;
- do not assume schema;
- do not assume authorization;
- do not trust client-controlled privilege fields;
- keep public registration separate from portal authorization;
- preserve supplier isolation;
- use database-backed concurrency controls;
- keep state transitions centralized;
- use existing infrastructure where it is fit;
- do not introduce unnecessary dependencies;
- do not over-engineer future requirements;
- do not claim verification that was not performed.

---

# 72. Final Target Workflow

```text
                         PUBLIC
                           │
                           ▼
                 /supplier/register
                           │
               ┌───────────┼───────────┐
               │           │           │
            Account      Company      Documents
               │           │           │
               └───────────┼───────────┘
                           │
                           ▼
                         PENDING
                           │
                ┌──────────┼──────────┐
                │          │          │
                ▼          ▼          ▼
             REVISION   REJECTED   APPROVE
                │          │          │
                │          │          ├─ reviewer
                │          │          ├─ scope assignment
                │          │          ├─ APPROVED audit
                │          │          └─ ACTIVE
                │          │
                │          └─ New Registration Attempt
                │
                └─ Edit + Resubmit
                         │
                         ▼
                       PENDING
```

Reviewer:

```text
Admin
Finance
Purchasing
    │
    └──── one of them can approve
                  │
                  ▼
         Scope: import/local/both
                  │
                  ▼
                ACTIVE
```

Supplier authorization after activation:

```text
ACTIVE
+
role=supplier
+
supplier_scopes
        │
        ▼
Existing Supplier Portal Authorization
```

The registration workflow never becomes an alternative authorization system.

---

# 73. Definition of Done

The feature is considered implemented only when:

```text
Registration works
+
Revision works
+
Rejection works
+
Re-registration works
+
Approval works
+
Scope assignment works
+
Activation is atomic
+
Login boundary works
+
Duplicate protection works
+
Document security works
+
Reviewer notification works
+
Audit trail works
+
Existing supplier authorization remains intact
+
Regression tests pass
+
Security verification passes
```

The implementation is incomplete if any security-critical boundary is only implemented in UI and not enforced server-side.
