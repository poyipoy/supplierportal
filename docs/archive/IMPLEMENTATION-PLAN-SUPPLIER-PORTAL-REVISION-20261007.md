# Implementation Plan — Supplier Portal Revision

**Project:** Supplier Portal  
**Repository:** `poyipoy/supplierportal`  
**Repository baseline reviewed:** `bcb29db8c78e14ad5cbd13ce6af2875ccb653ce3`  
**Document date:** 2026-10-07  
**Document type:** Final implementation planning based on revision interview  
**Status:** Ready for Gate 1 repository audit before implementation

---

## 1. Objective

Dokumen ini menjadi implementation plan final untuk revisi Supplier Portal yang mencakup empat area utama:

1. **Goods Receipt (GR)**
   - QTY mendukung maksimal 3 angka desimal.
   - UOM ditambahkan dan bersumber dari file import Infor untuk GR import.
   - Manual GR tetap dipertahankan dengan dropdown UOM predefined.
   - QTY dan UOM disimpan serta ditampilkan secara terstruktur.
   - UOM ikut disnapshot saat GR dipakai pada invoice.

2. **DRP Supplier**
   - Tambah `Verification Date`.
   - Tambah filter `Due Date` dan `Verification Date`.
   - Tambah sorting ASC/DESC pada kedua kolom.
   - Default sorting menggunakan `Due Date ASC`.

3. **GA Claim & DRP GA**
   - Penyederhanaan claim type menjadi kategori canonical.
   - Entertainment wajib supporting document pada submission/resubmission baru.
   - Workflow DRP GA disederhanakan agar mengikuti pola DRP Supplier.
   - Workflow `DRP GA Draft` dari sisi GA dihapus.
   - Legacy GA DRAFT lama di-hard-delete secara terkontrol.

4. **Refund Overpayment**
   - `refund_reference` dihapus total dari UI, validation, service, history, notification, test, dan database.

Dokumen ini harus digunakan sebagai source of truth implementation. Jika ditemukan ketidaksesuaian antara dokumen ini dan kondisi repository aktual saat Gate 1, agent harus melaporkan gap tersebut sebelum coding dan tidak boleh mengarang struktur baru tanpa evidence.

---

# 2. Locked Business Decisions

Semua keputusan di bawah sudah dikonfirmasi selama interview dan tidak perlu ditanyakan ulang kecuali repository menunjukkan conflict teknis yang material.

## 2.1 GR Quantity

- Database quantity existing tetap mempertahankan precision yang tersedia saat ini.
- Application validation membatasi maksimal **3 angka di belakang koma**.
- Valid: `1`, `1.5`, `10.125`.
- Invalid: `0`, nilai negatif, `10.1256`.
- Quantity tetap positive.
- Display tidak memaksa trailing zeros:
  - `5.000` → `5`
  - `2.500` → `2.5`
  - `10.125` → `10.125`

## 2.2 GR UOM

Canonical UOM yang diizinkan:

- `box`
- `doz`
- `kg`
- `lbr`
- `ltr`
- `mtr`
- `pcs`
- `rim`
- `rol`
- `set`
- `unt`

Semua UOM dinormalisasi menjadi lowercase.

Unknown UOM ditolak. Blank UOM ditolak untuk GR baru.

## 2.3 Source UOM

### Import GR
UOM berasal dari **file import GR Infor**, satu sumber dengan data `Received Quantity`.

Agent wajib menemukan exact source column/header dari workbook/parser aktual.

**Dilarang mengarang nama header seperti `UOM`, `Unit`, atau nama lain tanpa evidence dari file/parser Infor.**

### Manual GR
Manual GR tetap ada.

Manual GR harus memiliki field UOM berbentuk dropdown predefined menggunakan canonical UOM list di atas.

## 2.4 GR Display

QTY dan UOM ditampilkan sebagai **dua kolom terpisah**.

| QTY | UOM |
|---:|---|
| 10.125 | kg |
| 5 | pcs |
| 2.5 | mtr |

## 2.5 GR Invoice Snapshot

Saat GR dipakai pada invoice:
- QTY existing tetap disnapshot.
- UOM wajib ikut disnapshot.
- Historical invoice tidak boleh berubah maknanya bila master GR berubah setelahnya.

## 2.6 DRP Supplier Verification Date

`Verification Date` berarti tanggal ketika invoice masuk status `READY_TO_PAY`.

Authoritative source: `local_invoices.ready_to_pay_at`.

Bukan:
- `LocalInvoiceVerification.verified_at`
- `physical_verified_at`
- timestamp verification lainnya.

## 2.7 DRP Supplier Filter

Candidate invoice pada **Create New Supplier DRP Batch** memiliki:

- Due Date From
- Due Date To
- Verification Date From
- Verification Date To

Jika kedua date range digunakan bersamaan, semantics = **AND**.

## 2.8 DRP Supplier Sorting

Sortable columns:
- Due Date
- Verification Date

Direction:
- ASC
- DESC

Default:
- **Due Date ASC**

Scope:
- hanya candidate invoice pada Create New Supplier DRP Batch.
- tidak diterapkan ke batch history/list.

## 2.9 GA Claim Type

Canonical claim type:
- `Entertainment`
- `Business Travel`
- `Reimburse/Claim`

Migration mapping:

| Legacy | Canonical |
|---|---|
| `Entertain Sales` | `Entertainment` |
| `UPD Sales` | `Business Travel` |
| `UPD GA` | `Business Travel` |
| `Reimburse/Claim` | `Reimburse/Claim` |

Perubahan ini bukan label-only. Database existing ikut dimigrasikan.

## 2.10 Entertainment Supporting Document

Untuk claim type `Entertainment`:
- minimal 1 supporting document wajib saat **submit**;
- minimal 1 supporting document wajib saat **resubmit**;
- draft, jika workflow draft memang tersedia di domain yang relevan, boleh incomplete;
- historical claim lama yang sebelumnya valid tidak dibatalkan secara retroaktif hanya karena tidak memiliki supporting document.

Rule harus enforced di backend/domain, bukan hanya UI.

## 2.11 GA Workflow Baru

Flow target:

`GA Claim`
→ `Finance Verification`
→ `READY_TO_PAY`
→ muncul sebagai candidate di `Create New GA DRP Batch`
→ Finance memilih satu atau lebih claim
→ Finance membuat GA DRP Batch

Flow lama berikut dihapus:

`READY_TO_PAY`
→ GA membuat `DRP GA Draft`
→ Finance menerima draft

## 2.12 Legacy GA DRAFT

Legacy PaymentBatch dengan:
- `batch_type = GA`
- `status = DRAFT`

dipilih untuk **hard delete**, bukan cancel.

Hard delete hanya boleh dilakukan setelah FK/dependency audit.

Tidak boleh menghapus:
- FINALIZED
- PARTIALLY_PAID
- PAID
- CANCELLED
- Supplier DRP

## 2.13 Refund Overpayment Reference

`refund_reference` dihapus total.

Scope removal:
- database column
- request validation
- service validation
- service persistence
- history message
- notification payload
- UI form
- UI display
- translations
- tests
- fixtures

Jangan menghapus:
- `transfer_reference`
- payment transfer reference
- reference field domain lain yang bukan refund overpayment.

---

# 3. Current Repository Baseline

## 3.1 GR

Relevant sources:
- `app/Models/LocalGoodsReceipt.php`
- `app/Services/LocalInvoice/LocalGrImportService.php`
- `app/Services/LocalInvoice/LocalProcurementMasterService.php`
- `resources/views/finance/local-procurement/show.blade.php`
- Local Procurement controller/request terkait
- invoice–GR pivot/model/migration terkait

Current behavior:
- `LocalGoodsReceipt.qty` dicast sebagai decimal.
- importer memvalidasi quantity positive.
- import path melakukan grouping/aggregation.
- manual GR masih tersedia.
- existing UOM field belum terlihat pada baseline yang sudah diperiksa.

## 3.2 DRP Supplier

Relevant sources:
- `app/Http/Controllers/Finance/FinanceDrpController.php`
- `app/Services/Payment/PaymentBatchService.php`
- `resources/views/finance/drp/supplier.blade.php`
- `app/Models/LocalInvoice.php`

Current behavior:
- `FinanceDrpController::indexSupplier()` memuat eligible invoice.
- `createSupplierBatch()` sudah tersedia.
- `ready_to_pay_at` sudah ada dan digunakan pada invoice workflow.

## 3.3 GA

Relevant sources:
- `app/Models/GaClaim.php`
- `app/Services/Ga/GaClaimService.php`
- `app/Services/Ga/GaVerificationService.php`
- `app/Http/Controllers/Ga/GaController.php`
- `app/Http/Controllers/Finance/FinanceGaClaimController.php`
- `app/Http/Controllers/Finance/FinanceDrpController.php`
- `app/Services/Payment/PaymentBatchService.php`
- `resources/views/finance/drp/ga.blade.php`
- GA create/revision/detail views
- `lang/en/ga.php`
- `lang/id/ga.php`

Current behavior:
- Finance approval mengubah GA Claim menjadi `READY_TO_PAY`.
- `ready_to_pay_at` sudah diisi.
- GA saat ini memiliki `drpDraft()` dan `createDrpDraft()`.
- `PaymentBatchService::createGaBatch()` sudah ada.
- `FinanceDrpController::indexGa()` saat baseline hanya menampilkan GA batches dan belum bertindak seperti supplier candidate page.

## 3.4 Overpayment

Relevant sources:
- `app/Http/Controllers/Finance/LocalInvoiceSettlementController.php`
- `app/Services/Payment/SupplierOverpaymentService.php`
- `resources/views/finance/overpayments/index.blade.php`
- `resources/views/local-invoices/detail.blade.php`
- `SupplierOverpaymentRefund` model/migration
- translation files
- overpayment tests

Current behavior:
- `refund_reference` diwajibkan controller.
- service kembali memvalidasi reference.
- service menyimpan reference.
- history dan notification memakai reference.
- UI menampilkan dan meminta reference.
- database column existing nullable tetapi dipakai application layer.

---

# 4. Implementation Strategy

Recommended execution order:

1. Gate 1 repository audit
2. Schema/migration design
3. GR UOM + quantity
4. DRP Supplier filters/sorting
5. GA claim type migration
6. Entertainment supporting-document rule
7. GA DRP workflow simplification
8. Legacy GA DRAFT cleanup
9. Refund reference removal
10. Targeted tests
11. Cross-domain regression
12. Gate 2 verification
13. User approval
14. Baru staging/commit/push jika diminta

---

# 5. Workstream A — GR Quantity and UOM

## 5.1 Goals

Add explicit unit-of-measure support to Local GR without changing business meaning of existing PO/invoice flows.

## 5.2 Schema

Add UOM field to `local_goods_receipts`.

Recommended logical definition:

`uom VARCHAR(...)`

Do not immediately enforce DB `NOT NULL` if legacy data exists without UOM.

Preferred rollout:
1. add nullable column;
2. new writes require UOM at application level;
3. preserve legacy rows as null if no authoritative source exists;
4. do not backfill guessed `pcs`.

Do not silently assign `pcs` to old records.

## 5.3 Model

Update `LocalGoodsReceipt`:
- add `uom` to `$fillable`;
- retain existing qty cast;
- centralize UOM list and normalization.

Recommended centralized API pattern:
- `LocalGoodsReceipt::UOMS`
- `LocalGoodsReceipt::normalizeUom()`
- `LocalGoodsReceipt::isSupportedUom()`

Exact implementation can differ if project conventions provide a better domain object/service.

Avoid duplicating UOM arrays in controllers, Blade views, services, and tests.

## 5.4 Quantity Validation

Create/reuse validation helper that accepts:
- positive number;
- max 3 decimal places.

Do not cast through float for authoritative validation if it can create binary precision issues.

Prefer string/decimal-safe handling consistent with project conventions.

## 5.5 Import GR from Infor

Relevant source:

`app/Services/LocalInvoice/LocalGrImportService.php`

Tasks:
1. inspect actual parser mapping;
2. identify real workbook column for UOM;
3. include UOM in normalized import row;
4. validate required UOM;
5. normalize lowercase;
6. reject unknown UOM;
7. persist UOM;
8. include UOM in preview;
9. preserve preview → token/session → confirm architecture;
10. validate again during confirm.

### Import errors

Reject:
- missing UOM;
- unsupported UOM;
- quantity >3 decimals;
- non-positive quantity.

Translations must be available in English and Indonesian where the import UI uses localized copy.

## 5.6 Multi-row GR Aggregation Safety

Current importer appears to aggregate rows for the same GR.

Before summing quantity:
- ensure all rows being aggregated represent a compatible unit;
- if same GR contains different UOM values, do not sum blindly.

Invalid example:

`10 kg + 5 pcs`

### Required Gate 1 decision

Agent must determine from sample workbook/domain whether:

A. one GR is guaranteed to have one UOM; or  
B. one GR can contain item lines with mixed UOM.

If B is possible, existing single-row GR data model may be insufficient.

In that case:
- stop implementation of aggregation;
- document the data-model conflict;
- propose minimal compatible model change;
- do not invent an unsupported workaround.

## 5.7 Manual GR

Manual GR stays.

Add UOM dropdown to Add/Edit GR.

Dropdown options:
- box
- doz
- kg
- lbr
- ltr
- mtr
- pcs
- rim
- rol
- set
- unt

Validation:
- required;
- canonical whitelist;
- lowercase normalization.

## 5.8 Invoice–GR Snapshot

Find pivot/model for invoice–GR relation.

Current repository already stores quantity snapshot.

Add corresponding UOM snapshot field, suggested logical name:

`gr_uom_snapshot`

Use project naming pattern if existing convention suggests another name.

When reserving/consuming GR into invoice:
- snapshot current GR UOM;
- never dynamically overwrite historical snapshot later.

## 5.9 UI Display

Update relevant tables/details:
- Local Procurement GR list/detail
- invoice candidate GR selection
- Finance invoice detail if GR shown
- Purchasing mirror/read-only views if GR shown
- exports if GR quantity appears
- print/download surfaces if applicable

Render QTY and UOM separately.

## 5.10 Acceptance Criteria — GR

- [ ] QTY `1` accepted.
- [ ] QTY `1.5` accepted.
- [ ] QTY `10.125` accepted.
- [ ] QTY `10.1256` rejected.
- [ ] QTY `0` rejected.
- [ ] Negative QTY rejected.
- [ ] Import blank UOM rejected.
- [ ] Import unknown UOM rejected.
- [ ] `KG`, `Kg`, `kg` persist as `kg`.
- [ ] Manual GR only allows canonical UOM.
- [ ] UOM persists on GR.
- [ ] QTY and UOM display as separate columns.
- [ ] Invoice–GR relation snapshots UOM.
- [ ] Historical invoice uses snapshot, not mutable master UOM.
- [ ] Import never sums different UOM values into one quantity.

---

# 6. Workstream B — DRP Supplier Verification Date, Filter, Sorting

## 6.1 Scope

Only:

**Create New Supplier DRP Batch → candidate invoice list**

Not:
- batch history
- paid history
- purchasing history page
- unrelated reports

## 6.2 Verification Date

Display value:

`LocalInvoice.ready_to_pay_at`

Do not use finance verification record timestamp or physical verification timestamp.

## 6.3 Filter Inputs

Add four inputs:
- `due_date_from`
- `due_date_to`
- `verification_date_from`
- `verification_date_to`

Each pair is optional.

### Due Date predicate

If both set:
- `due_date BETWEEN from AND to`

If only from:
- `due_date >= from`

If only to:
- `due_date <= to`

### Verification Date predicate

`ready_to_pay_at` is a timestamp.

Date-range input must respect business timezone boundaries.

Use existing `BusinessTime` conventions instead of raw UTC assumptions.

## 6.4 Combined Filter Semantics

If Due Date and Verification Date filters are both populated:

**AND**

## 6.5 Sorting

Allowed sort keys:
- `due_date`
- `verification_date`

Map `verification_date` internally to `ready_to_pay_at`.

Allowed direction:
- `asc`
- `desc`

Reject/fallback on arbitrary values.

Do not pass raw query-string column names into `orderBy()` without whitelist mapping.

## 6.6 Default

If no sort supplied:

`due_date ASC`

Define stable tie-breaker to prevent pagination jitter, following current repository conventions.

## 6.7 Header Behavior

Clicking Due Date:
- current ASC → DESC
- current DESC → ASC
- unrelated sort → ASC

Same for Verification Date.

Preserve:
- date filters
- supplier filter
- pagination-compatible query state
- any current search/filter inputs

## 6.8 Candidate Eligibility

Do not change eligibility semantics beyond what is needed.

Eligible invoices must remain:
- `READY_TO_PAY`
- not reserved in active DRP
- existing payment/business guards intact

## 6.9 Acceptance Criteria — DRP Supplier

- [ ] Verification Date column is present.
- [ ] Value comes from `ready_to_pay_at`.
- [ ] Due Date From works.
- [ ] Due Date To works.
- [ ] Verification Date From works.
- [ ] Verification Date To works.
- [ ] Both filter sets combine using AND.
- [ ] Default sort is Due Date ASC.
- [ ] Due Date header toggles ASC/DESC.
- [ ] Verification Date header toggles ASC/DESC.
- [ ] Invalid sort key cannot produce arbitrary SQL ordering.
- [ ] Existing supplier filter continues to work.
- [ ] Filter/sort only changes candidate invoice surface.

---

# 7. Workstream C — GA Claim Type Canonicalization

## 7.1 Canonical Values

New canonical DB values:
- `Entertainment`
- `Business Travel`
- `Reimburse/Claim`

## 7.2 Database Migration

Current schema uses enum.

Safe migration sequence:

### Step 1 — Expand allowed values
Temporarily allow legacy values and canonical values.

### Step 2 — Data migration
Update:
- Entertain Sales → Entertainment
- UPD Sales → Business Travel
- UPD GA → Business Travel

### Step 3 — Shrink enum
Remove legacy enum options after confirming no legacy value remains.

### Step 4 — Assertions
Migration/test should assert there are no remaining:
- Entertain Sales
- UPD Sales
- UPD GA

## 7.3 Model Constants

Refactor `GaClaim`.

Replace legacy constants with canonical constants, for example:
- `TYPE_ENTERTAINMENT`
- `TYPE_BUSINESS_TRAVEL`
- `TYPE_REIMBURSE_CLAIM`

Update `CLAIM_TYPES`.

Remove runtime dependence on legacy Sales/GA-specific constants.

## 7.4 Labels

English:
- Entertainment
- Business Travel
- Reimbursement / Claim, while keeping DB canonical `Reimburse/Claim` if desired by existing data convention

Indonesian:
- use existing translation conventions

Do not reintroduce role prefix Sales or GA.

## 7.5 Global Surfaces

Audit all usages:
- create form
- revision form
- detail
- list
- Finance verification
- DRP GA
- filters
- exports
- dashboards
- reports
- tests
- translations
- notification text if claim type appears

## 7.6 Acceptance Criteria — Claim Types

- [ ] No new claim can use legacy values.
- [ ] Existing legacy rows are migrated.
- [ ] Entertainment renders consistently.
- [ ] Business Travel renders consistently.
- [ ] UPD Sales and UPD GA are merged.
- [ ] Reimburse/Claim remains separate.
- [ ] Active UI no longer displays Sales/GA prefix claim types.

---

# 8. Workstream D — Entertainment Supporting Document

## 8.1 Rule

For canonical `Entertainment`:

At least one valid supporting document must exist during:
- initial submit;
- resubmit/revision submit.

## 8.2 Existing Architecture

Reuse:
- `GaClaimDocument`
- existing private storage
- `document_type = supporting`
- existing policy/controller/download path

Do not introduce:
- polymorphic Attachment migration
- new document table
- public storage path

## 8.3 Enforcement Layer

Primary enforcement belongs in domain/service level.

Likely source:
- `app/Services/Ga/GaClaimService.php`

UI validation may supplement backend validation but cannot replace it.

## 8.4 Historical Claims

Historical migrated Entertainment records:
- do not automatically become invalid;
- do not change status;
- do not cancel;
- do not block read-only historical access solely due to missing document.

If a historical claim later enters explicit resubmission flow after deployment:
- new rule applies.

## 8.5 Acceptance Criteria — Supporting Document

- [ ] New Entertainment without supporting document fails.
- [ ] New Entertainment with supporting document succeeds.
- [ ] Business Travel is not accidentally blocked.
- [ ] Reimburse/Claim is not accidentally blocked.
- [ ] Entertainment resubmit enforces rule.
- [ ] Historical existing Entertainment is not retroactively invalidated.
- [ ] Documents remain private and policy-protected.

---

# 9. Workstream E — Simplify GA DRP Workflow

## 9.1 Old Flow to Remove

Current GA-side flow includes:
- `GaController::drpDraft()`
- `GaController::createDrpDraft()`
- GET/POST DRP draft routes
- GA UI button/menu for Prepare DRP GA Draft

These should be removed from active workflow.

## 9.2 New Flow

After Finance verifies claim:

`GaVerificationService::financeVerify(... approve: true)`

sets:
- status = READY_TO_PAY
- finance_verified_by
- finance_verified_at
- ready_to_pay_at

No batch is automatically created.

Instead, claim becomes eligible candidate in Finance DRP GA page.

## 9.3 Finance DRP GA Candidate Page

`FinanceDrpController::indexGa()` should be expanded to mirror supplier concepts.

Page should contain:
- existing GA batch list/history as appropriate;
- candidate READY_TO_PAY claims;
- multi-select;
- employee/payee information;
- claim number;
- claim type;
- amount;
- relevant bank data;
- verification/ready date if useful and consistent with current UI;
- action `Create New GA DRP Batch`.

Exact columns should reuse existing design system and supplier DRP patterns.

## 9.4 Candidate Query

Eligible GA Claim:
- status = READY_TO_PAY;
- not already attached through active PaymentItem;
- relevant PaymentGroup is not active/unpaid on an active batch;
- employee/payment prerequisites remain valid.

Reuse the same reservation semantics already used by `PaymentBatchService`.

## 9.5 Create GA DRP Batch

Add Finance-side action.

Input:
- claim_ids[]
- optional notes if same pattern remains useful

Controller validation:
- required array
- min 1
- IDs must exist

Service:
- reuse `PaymentBatchService::createGaBatch()`

## 9.6 Authorization

After revision:

Allowed creator:
- Finance
- Admin if current project policy intentionally grants it

GA role:
- must no longer create PaymentBatch.

Update `createGaBatch()` authorization accordingly.

## 9.7 Existing GA Payment Rules

Must remain unchanged:
- GA batch type remains `GA`;
- Supplier and GA cannot mix;
- GA bank fee remains zero;
- existing grouping by employee/bank details remains unless audit finds a separate requirement;
- finalize/payment execution semantics stay intact.

## 9.8 Remove GA Draft UX

Remove:
- sidebar/menu entry
- dashboard quick action
- page
- routes
- controller methods
- text/help describing GA as preparer of DRP Draft

## 9.9 Acceptance Criteria — GA DRP

- [ ] Finance-approved GA claim reaches READY_TO_PAY.
- [ ] READY_TO_PAY claim appears on Finance Create New GA DRP Batch.
- [ ] Finance can select multiple claims.
- [ ] Finance can create one GA batch containing selected claims.
- [ ] Batched claim disappears from candidate pool.
- [ ] Cancelled/released reservation follows existing PaymentBatch rules.
- [ ] GA user cannot create a DRP batch.
- [ ] GA draft route is removed/disabled.
- [ ] No active GA Draft menu remains.
- [ ] Supplier DRP behavior is unaffected.
- [ ] GA bank fee stays zero.

---

# 10. Workstream F — Hard Delete Legacy GA DRAFT

## 10.1 Target

Delete only:

`payment_batches.batch_type = GA`
AND
`payment_batches.status = DRAFT`

## 10.2 Non-target

Never delete:
- GA FINALIZED
- GA PARTIALLY_PAID
- GA PAID
- GA CANCELLED
- any SUPPLIER batch

## 10.3 Pre-delete Audit

Before migration/command deletion, inspect dependencies:
- payment_items
- payment_groups
- payment_batches
- vouchers
- export artifacts/records
- execution/payment records
- settlement data
- notification references
- audit tables
- history tables
- any FK not listed here

## 10.4 Safe Delete Conditions

If a DRAFT GA batch has only expected draft-level groups/items and no downstream execution artifacts, delete child-to-parent according to FK strategy or use defined cascade only if schema explicitly guarantees correct behavior.

## 10.5 Unexpected Artifact Handling

If a GA DRAFT contains downstream record that should only exist post-finalization:

Do not force-delete.

Migration/cleanup must:
- stop;
- report batch ID/number;
- require manual investigation.

## 10.6 Re-eligibility

After delete:
- old PaymentItems must no longer reserve claims;
- READY_TO_PAY claim should reappear in new Finance candidate page.

## 10.7 Migration vs Command

Gate 1 must decide whether destructive cleanup belongs in:
- one-time migration; or
- explicit artisan command run during deployment.

Prefer preflight-capable command if migration-time destructive deletion would make safe inspection difficult.

Do not hide destructive cleanup inside unrelated schema migration.

## 10.8 Acceptance Criteria — Cleanup

- [ ] GA DRAFT target count can be previewed before deletion.
- [ ] Only target GA DRAFT is deleted.
- [ ] Supplier DRAFT remains untouched.
- [ ] Finalized/paid/cancelled GA batches remain.
- [ ] Orphan groups/items do not remain.
- [ ] Eligible claim returns to candidate pool.
- [ ] Unexpected downstream artifacts block deletion.

---

# 11. Workstream G — Remove Refund Overpayment Reference

## 11.1 Database

Drop:

`supplier_overpayment_refunds.refund_reference`

User explicitly approved permanent removal including historical values.

## 11.2 Controller

Update:

`LocalInvoiceSettlementController::refund()`

Remove:
- request validation for `refund_reference`.

Retain:
- refund_amount
- refund_date
- notes
- proof

## 11.3 Service

Update:

`SupplierOverpaymentService::settle()`

Remove:
- blank reference validation;
- persistence of reference;
- history placeholder;
- notification payload reference.

Retain:
- positive amount validation;
- exact full-refund rule;
- proof private storage;
- cleanup-on-failure;
- duplicate/already-settled guard;
- refund date;
- notes;
- audit record.

## 11.4 History Copy

Refactor translation so final sentence remains grammatically correct without reference.

Do not leave:
- `:reference`
- empty parentheses
- duplicated punctuation

## 11.5 Notification Payload

Remove refund reference from:
- payload construction;
- template copy;
- registry/translation expectations;
- tests.

## 11.6 UI

Remove reference from:
- Finance overpayment settlement form;
- settled refund table/card;
- local invoice detail refund section;
- any print/export surface;
- any read-only purchasing view if present.

## 11.7 Acceptance Criteria — Refund

- [ ] Refund form no longer contains reference input.
- [ ] Refund can settle without reference.
- [ ] Full refund amount still mandatory.
- [ ] Refund date still mandatory.
- [ ] Proof still mandatory.
- [ ] Proof remains private.
- [ ] Database no longer contains refund_reference.
- [ ] History has no reference.
- [ ] Notification has no reference.
- [ ] Invoice payment transfer reference is unaffected.

---

# 12. Database Migration Plan

Recommended new migrations should be additive and forward-only against historical migrations.

Do not edit already-applied old migration files just to change production schema.

## 12.1 GR Migration

Add:
- `local_goods_receipts.uom`
- invoice–GR UOM snapshot field

Consider index only if actual query pattern requires it.

## 12.2 GA Claim Type Migration

Perform enum expansion → data migration → enum contraction safely.

Database-specific implementation must match actual DB engine/version.

## 12.3 Refund Migration

Drop:
- `supplier_overpayment_refunds.refund_reference`

Document that rollback cannot recover old reference values.

## 12.4 GA Draft Cleanup

Keep cleanup clearly isolated.

If command-based:
- dry-run/preflight mode preferred;
- explicit output counts;
- production operator can inspect before destructive run.

If migration-based:
- transaction where supported;
- hard guard for unexpected downstream artifacts.

---

# 13. Validation Contract

## 13.1 GR QTY

Server authority:
- required where GR create/update/import requires it;
- positive;
- max 3 decimals.

Client-side may use `step="0.001"`, but that is not sufficient as domain validation.

## 13.2 GR UOM

Manual:
- required whitelist.

Import:
- required;
- normalized;
- whitelist.

## 13.3 DRP Filters

Validate:
- date format
- sort key
- sort direction

Invalid sort:
- fallback safely to default rather than direct SQL usage.

## 13.4 Entertainment Document

Enforce in service.

## 13.5 GA Batch Creation

Finance request:
- claim_ids required array
- min 1
- each exists

Service revalidates:
- READY_TO_PAY
- not actively reserved
- employee/payment prerequisites

Controller validation alone is insufficient.

---

# 14. Localization Requirements

Repository is bilingual.

Every new user-facing copy must be added consistently to:
- English
- Indonesian

Includes:
- UOM validation errors if shown
- Verification Date label
- Due Date filter labels if changed
- Entertainment supporting-document validation
- Create New GA DRP Batch
- removal/replacement of GA Draft wording
- refund history copy without reference

Do not hardcode visible text directly in Blade if current localization architecture expects translation keys.

---

# 15. UI/UX Requirements

## 15.1 GR
- UOM manual input is dropdown, not free text.
- QTY and UOM are separate columns.
- imported UOM shown normalized.
- validation error near relevant field.

## 15.2 DRP Supplier
Recommended compact filter layout:
- Due Date From
- Due Date To
- Verification Date From
- Verification Date To
- Apply
- Reset

Sorting header should visibly indicate active sort direction if existing component support exists.

## 15.3 DRP GA
Mirror visual language of Supplier DRP:
- candidate selector
- batch creation action
- empty-state when no eligible claims

## 15.4 Overpayment
After removing reference:
- rebalance form spacing;
- do not leave an empty grid column.

---

# 16. Authorization and Security

## 16.1 GA DRP
Only Finance/Admin should create new GA batch after workflow revision.

Remove GA-side ability to create PaymentBatch.

## 16.2 Documents
Entertainment supporting documents remain:
- private;
- accessed through authorized controller/policy;
- not linked with raw storage path.

## 16.3 Import
Preserve:
- preview
- authoritative server parse
- token/session
- confirm
- server-side revalidation
- transaction
- audit

Do not trust client-modified preview rows.

## 16.4 Refund Proof
Retain:
- MIME validation
- size validation
- private storage
- cleanup on transaction/storage failure

---

# 17. Testing Plan

## 17.1 GR Unit/Feature Tests

Required cases:
1. manual qty integer accepted
2. manual qty 1 decimal accepted
3. manual qty 2 decimals accepted
4. manual qty 3 decimals accepted
5. manual qty 4 decimals rejected
6. zero rejected
7. negative rejected
8. manual UOM valid accepted
9. manual UOM invalid rejected
10. import uppercase UOM normalized
11. import mixed-case UOM normalized
12. import blank UOM rejected
13. import unsupported UOM rejected
14. import 4-decimal QTY rejected
15. UOM stored
16. invoice snapshot stores UOM
17. snapshot remains unchanged after GR master mutation
18. mixed UOM aggregation guard

## 17.2 DRP Supplier Tests

Required cases:
1. candidate renders Verification Date
2. value is from ready_to_pay_at
3. Due Date From
4. Due Date To
5. Due Date range
6. Verification Date From
7. Verification Date To
8. Verification range
9. due + verification = AND
10. default Due Date ASC
11. Due Date DESC
12. Verification ASC
13. Verification DESC
14. invalid sort ignored/fallback
15. filters preserve supplier filter
16. active-batched invoice excluded

## 17.3 GA Claim Tests

Required cases:
1. legacy mapping migration
2. no legacy claim values remain
3. new Entertainment accepted
4. new Business Travel accepted
5. Reimburse/Claim accepted
6. Entertainment without supporting rejected
7. Entertainment with supporting accepted
8. Business Travel without new supporting requirement still follows existing workflow
9. historical Entertainment remains readable/valid
10. Entertainment resubmit rule
11. label consistency in EN/ID

## 17.4 GA DRP Tests

Required cases:
1. finance approve → READY_TO_PAY
2. READY_TO_PAY appears in Finance candidate list
3. non-ready claim excluded
4. already batched claim excluded
5. finance can create GA batch
6. multiple claims in one batch
7. GA role forbidden from batch creation
8. bank fee stays zero
9. supplier batch unaffected
10. removed GA Draft route unavailable
11. old GA Draft menu unavailable

## 17.5 Legacy GA DRAFT Cleanup Tests

Required:
1. GA DRAFT deleted
2. Supplier DRAFT preserved
3. GA FINALIZED preserved
4. GA PARTIALLY_PAID preserved
5. GA PAID preserved
6. GA CANCELLED preserved
7. groups/items cleaned
8. claim becomes eligible again
9. unexpected downstream artifact blocks cleanup

## 17.6 Overpayment Tests

Required:
1. refund without reference succeeds
2. full amount still enforced
3. partial refund rejected
4. proof still required
5. refund date required
6. already settled guard works
7. storage cleanup works on failure
8. history created without reference
9. notification created without reference
10. UI no reference field
11. DB column absent
12. payment transfer reference remains functional

---

# 18. Regression Test Areas

Run relevant regression for:
- Local Supplier invoice workflow
- Local Procurement
- GR reservation
- invoice creation
- Finance verification
- PaymentBatch unified engine
- GA claim workflow
- GA payment execution
- DRP export
- DRP paid flow
- overpayment settlement
- notification translation
- bilingual rendering
- timezone-related financial tests

Use serial execution where repository history indicates shared mutable DB contention.

---

# 19. Gate 1 — Mandatory Repository Audit

Before implementation, produce a Gate 1 report.

Gate 1 must answer:

## 19.1 Git State
- current branch
- HEAD
- upstream
- staged files
- modified files
- untracked files
- unrelated dirty work that must be preserved

## 19.2 GR Import
- exact parser class
- exact header mapping
- actual UOM column name
- sample workbook evidence
- aggregation key
- whether mixed UOM can occur within same GR
- import preview/confirm path

## 19.3 GR Schema
- current local_goods_receipts schema
- invoice–GR pivot schema
- current quantity snapshot field
- foreign keys
- legacy null risks

## 19.4 DRP Supplier
- exact candidate query
- current default order
- pagination behavior
- current filters
- timezone handling for date filters

## 19.5 GA Claims
- all claim-type references repo-wide
- enum schema
- document/revision semantics
- all GA Draft routes/views/actions
- candidate-reservation logic

## 19.6 GA DRAFT Cleanup
- exact FK tree
- cascade behavior
- downstream relations
- count or safe query shape for legacy GA DRAFT
- recommended cleanup vehicle: migration vs command

## 19.7 Refund Reference
- every code usage of `refund_reference`
- history translations
- notifications
- UI
- test fixtures
- exports/reports if any

Gate 1 must make **no production data mutation**.

---

# 20. Gate 2 — Verification Report

After implementation, produce Gate 2 containing:
- files changed
- migrations added
- tests added/updated
- commands executed
- exact pass/fail results
- unresolved risks
- unrelated dirty files preserved
- `git diff --check`
- targeted diff review
- no staged files unless explicitly requested
- no commit
- no push

Gate 2 should explicitly map each Locked Business Decision to code/test evidence.

---

# 21. Git Safety Rules

Until explicit user approval:

Do not:
- `git add .`
- `git add -A`
- stage unrelated hunks
- commit
- push
- force push
- reset
- clean
- stash
- revert unrelated files
- switch branch if doing so risks dirty work

If staging is later requested:
- stage only relevant paths/hunks;
- review `git diff --cached`;
- run `git diff --cached --check`.

Preserve unrelated BusinessTime, notification, customization, language, or experimental changes.

---

# 22. Out of Scope

Unless Gate 1 reveals a hard dependency, do not expand this revision into:
- redesign of all payment architecture;
- changing Supplier DRP grouping;
- changing GA bank fee;
- changing invoice settlement rules;
- changing PO monetary logic;
- replacing LocalGoodsReceipt with line-item ERP model;
- broad ERP integration rewrite;
- new notification preference system;
- new package/dependency;
- unrelated localization refactor;
- unrelated UI redesign.

---

# 23. Key Risk Register

## Risk 1 — Mixed UOM in one imported GR
**Severity:** High  
Current importer may aggregate lines. Gate 1 must inspect sample file and implement same-UOM guard.

## Risk 2 — Enum migration failure
**Severity:** High  
Mitigation: expand → migrate → shrink.

## Risk 3 — Hard-delete legacy GA DRAFT
**Severity:** High  
Mitigation: preflight, narrow predicate, dependency audit, blocker detection.

## Risk 4 — Timezone bug on Verification Date
**Severity:** Medium/High  
Mitigation: use business timezone boundaries.

## Risk 5 — Over-broad removal of “reference”
**Severity:** High  
Mitigation: remove exact `refund_reference` domain only.

## Risk 6 — Retroactive Entertainment validation
**Severity:** Medium  
Mitigation: apply rule on new submit/resubmit only.

## Risk 7 — GA permission leak
**Severity:** High  
Mitigation: update service authorization and route exposure, test forbidden role.

---

# 24. Definition of Done

## GR
- [ ] QTY max 3 decimals enforced.
- [ ] UOM imported from real Infor source.
- [ ] UOM canonicalization implemented.
- [ ] Manual UOM dropdown implemented.
- [ ] Invalid/missing UOM rejected.
- [ ] UOM persisted.
- [ ] UOM snapshot implemented.
- [ ] QTY/UOM separate display implemented.

## DRP Supplier
- [ ] Verification Date uses ready_to_pay_at.
- [ ] Due Date range filter implemented.
- [ ] Verification Date range filter implemented.
- [ ] AND semantics implemented.
- [ ] sort ASC/DESC implemented.
- [ ] default Due Date ASC implemented.
- [ ] only candidate surface changed.

## GA
- [ ] canonical claim types migrated.
- [ ] Entertainment document rule implemented.
- [ ] historical behavior protected.
- [ ] GA Draft workflow removed.
- [ ] Finance GA candidate flow implemented.
- [ ] Finance multi-select GA batch works.
- [ ] GA creator permission removed.
- [ ] old GA DRAFT cleanup completed safely.

## Refund
- [ ] refund_reference removed from DB.
- [ ] removed from controller/service.
- [ ] removed from UI.
- [ ] removed from history/notification.
- [ ] tests updated.
- [ ] transfer_reference unaffected.

## Quality
- [ ] targeted tests pass.
- [ ] relevant regression tests pass.
- [ ] `git diff --check` passes.
- [ ] no unrelated work modified.
- [ ] Gate 2 report produced.
- [ ] no commit/push before approval.

---

# 25. Recommended Implementation File Inventory

This is an initial file inventory. Gate 1 must confirm exact paths.

## GR
Potential:
- `app/Models/LocalGoodsReceipt.php`
- `app/Services/LocalInvoice/LocalGrImportService.php`
- `app/Services/LocalInvoice/LocalProcurementMasterService.php`
- Local Procurement controller/request
- `resources/views/finance/local-procurement/show.blade.php`
- GR import preview/modal views
- invoice GR selection views
- invoice–GR pivot model
- new migrations
- GR/import tests
- translations

## DRP Supplier
Potential:
- `app/Http/Controllers/Finance/FinanceDrpController.php`
- `resources/views/finance/drp/supplier.blade.php`
- `app/Models/LocalInvoice.php` only if query scope needs safe extension
- DRP Supplier tests
- translations

## GA
Potential:
- `app/Models/GaClaim.php`
- `app/Services/Ga/GaClaimService.php`
- `app/Services/Ga/GaVerificationService.php`
- `app/Http/Controllers/Ga/GaController.php`
- `app/Http/Controllers/Finance/FinanceDrpController.php`
- `app/Services/Payment/PaymentBatchService.php`
- GA routes
- `resources/views/finance/drp/ga.blade.php`
- GA create/revision/detail views
- GA dashboard/navigation
- `lang/en/ga.php`
- `lang/id/ga.php`
- new migrations/cleanup
- GA tests
- PaymentBatch tests

## Refund
Potential:
- `app/Http/Controllers/Finance/LocalInvoiceSettlementController.php`
- `app/Services/Payment/SupplierOverpaymentService.php`
- SupplierOverpaymentRefund model if fillable/casts mention field
- `resources/views/finance/overpayments/index.blade.php`
- `resources/views/local-invoices/detail.blade.php`
- translation files
- notification templates/registry
- new migration
- overpayment tests

---

# 26. Recommended Execution Checklist

## Gate 1
- [ ] read AGENTS.md / repo instructions
- [ ] inspect current git state
- [ ] inspect actual Infor GR parser/sample
- [ ] inspect GR schemas
- [ ] inspect DRP candidate query
- [ ] inspect GA enum/document workflow
- [ ] inspect GA DRAFT FK graph
- [ ] inspect all refund_reference usages
- [ ] publish Gate 1 findings
- [ ] stop for approval if repository conflicts materially with this plan

## Implementation
- [ ] migrations
- [ ] GR domain changes
- [ ] GR import changes
- [ ] manual GR UI
- [ ] invoice snapshot
- [ ] DRP Supplier filter/sort
- [ ] GA claim-type migration
- [ ] Entertainment document rule
- [ ] Finance GA candidate flow
- [ ] remove GA Draft route/UI
- [ ] legacy GA DRAFT cleanup
- [ ] refund_reference removal
- [ ] translations
- [ ] targeted tests

## Verification
- [ ] focused tests
- [ ] regression tests
- [ ] migration test
- [ ] rollback behavior review
- [ ] `git diff --check`
- [ ] review final diff
- [ ] Gate 2 report
- [ ] no stage/commit/push

---

# 27. Final Workflow Summary

## GR Import

`Infor GR file`
→ parse `Received Quantity`
→ parse actual Infor unit column
→ normalize UOM
→ validate QTY max 3 decimals
→ validate UOM whitelist
→ preview
→ confirm/revalidate
→ store GR QTY + UOM

## GR Manual

Authorized user
→ enter QTY
→ choose UOM dropdown
→ validate
→ store

## Invoice

select GR
→ reserve
→ snapshot QTY
→ snapshot UOM
→ historical invoice retains original meaning

## DRP Supplier

`READY_TO_PAY LocalInvoice`
→ candidate query
→ filter Due Date
→ filter Verification Date (`ready_to_pay_at`)
→ default Due Date ASC
→ selectable
→ Create Supplier DRP Batch

## GA

`GA Claim`
→ submit
→ if Entertainment: supporting document required
→ basic verification
→ Finance verification
→ READY_TO_PAY
→ Finance Create New GA DRP Batch candidate
→ select one/multiple claims
→ `PaymentBatchService::createGaBatch()`
→ finalize/payment existing workflow

No GA-side DRP Draft.

## Refund Overpayment

`Overpayment OPEN`
→ Finance enters full refund amount
→ refund date
→ proof
→ optional notes
→ settle
→ status SETTLED

No refund reference field.

---

# 28. Final Implementation Principle

Use:

**Evidence → Correctness → Verification → Simplicity → Maintainability**

And:

**Understand first → Change minimally → Verify explicitly**

Do not solve this revision by broad architectural rewrite when the existing domain architecture can be extended safely.
