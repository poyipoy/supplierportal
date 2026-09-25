# ADASI Supplier Portal — Implementation Plan
## Separate ERP Import for Local Master PO & GR (Infor tdpur / whinh)

**Date:** 24 September 2026  
**Repository:** `https://github.com/poyipoy/supplierportal`  
**Domain:** Core 2 — Local Supplier & Disbursement  
**Scope:** Master PO & GR ERP import only  

> This plan covers the revision of the existing **Master PO & GR import** flow. It does **not** include Supplier Registration or other previously discussed features.

---

## 1. Objective

Replace the current combined **PO + GR spreadsheet import** with two independent ERP-oriented import flows:

1. **Import PO** — based on the Infor ERP `tdpur` export.
2. **Import GR** — based on the Infor ERP `whinh` export.

The portal must accept the ERP exports using their native column positions rather than requiring users to manually transform the files.

### Target result

```text
Master PO & GR
      │
      ├── Import PO
      │     ├── Download Template PO (tdpur)
      │     ├── Upload XLSX
      │     ├── Parse fixed ERP columns
      │     ├── Validate supplier / PO / date / amount
      │     ├── Preview
      │     └── Atomic Confirm
      │
      └── Import GR
            ├── Download Template GR (whinh)
            ├── Upload XLSX
            ├── Parse fixed ERP columns
            ├── Match GR -> existing PO
            ├── Aggregate ERP line rows into GR master records
            ├── Validate quantity / date / duplicate / PO existence
            ├── Preview
            └── Atomic Confirm
```

---

## 2. Source Documents Analyzed

### 2.1 PO source — Infor `tdpur`

Source file:

`tdpur4100m000_0520_20260924-133401_101112.xlsx`

Observed workbook structure:

- Worksheets: `data`, `enums`
- `data` sheet uses columns through `P`
- 4 populated data rows were present in the supplied sample
- Header row contains the ERP labels and the requested fields are located at the specified positions

Requested mapping:

| ERP Column | Source Header | Portal Meaning | Target |
|---|---|---|---|
| E | `Order` | Nomor PO | `local_purchase_orders.po_number` |
| G | blank in the supplied header row | Nama Supplier | resolve to `users.id` / local supplier |
| J | `Order Date` | Tanggal PO | `local_purchase_orders.po_date` |
| K | `Order Amount` | Amount PO | `local_purchase_orders.total_amount` |

Observed sample PO values include numbers such as `PNR261178`, `PNR261179`, `PNR261181`, and `PNR261182`.

The supplier names are ordinary text values such as `PT SURYA UTAMA TEKNOLOGI` and `PT PRIMA NANO COATING`.

The ERP date cells are Excel serial date/time values and must be normalized to the application's `Y-m-d` database representation.

---

### 2.2 GR source — Infor `whinh`

Source file:

`whinh3512m600_0520_20260924-134847_116644(1).xlsx`

Observed workbook structure:

- Worksheets: `data`, `enums`
- `data` sheet uses columns through `U`
- The supplied sample contains 48 populated ERP data rows
- Only four columns are authoritative for the requested import mapping

Requested mapping:

| ERP Column | Source Header | Portal Meaning | Target |
|---|---|---|---|
| J | `Receipt` | GR Number | `local_goods_receipts.gr_number` |
| T | `Actual Receipt Date` | Tanggal GR | `local_goods_receipts.gr_date` |
| P | `Received Quantity` | GR Qty | `local_goods_receipts.qty` |
| L | `Order Line` in the supplied export | **User-defined as Nomor PO** | match `local_purchase_orders.po_number` |

The import must follow the user's explicit mapping of **L → Nomor PO**.

However, the supplied `whinh` sample contains an important data-quality signal: column L is headed `Order Line` and its sample values are `WRO005077`, `WRO004892`, etc., while the supplied `tdpur` sample contains PO numbers such as `PNR261178`. There is no overlap between those sample PO identifiers.

Therefore the implementation must **not invent a transformation** between `WRO...` and `PNR...`. It must perform exact matching against the existing Local PO master and report unmatched rows clearly. The ERP export definition must be treated as authoritative at runtime.

---

## 3. Important Finding: GR Is Line-Level in the ERP Export

The supplied `whinh` sample has 48 populated rows but only 30 unique GR numbers.

Several GR numbers occur multiple times:

- `REC060436` appears 4 times
- `REC058496` appears 6 times
- `REC058497` appears 3 times
- `REC057944` appears 5 times
- and other repeated receipt numbers

The repeated rows are associated with individual ERP item/line records. Consequently, inserting every ERP row directly into `local_goods_receipts` would violate the application's unique GR-number rule and would create duplicate business GR records.

### Required normalization rule

The import layer must consolidate ERP rows before database persistence:

```text
ERP rows
   │
   ├── group by normalized GR Number + PO Number
   │
   ├── validate all rows in the group
   │
   ├── SUM Received Quantity (P)
   │
   ├── normalize GR date to calendar date
   │
   └── create exactly one LocalGoodsReceipt
```

For a GR represented by several ERP line rows:

```text
GR Qty = SUM(P for all rows belonging to the same GR + PO)
```

The importer must not create one Local GR per ERP item line.

### Conflict rules

A grouped GR is invalid when:

- the same GR Number is associated with multiple PO numbers;
- rows belonging to one GR have conflicting calendar dates;
- a required field is missing;
- the aggregated quantity is not positive;
- the target PO does not exist.

Different ERP timestamps on the same calendar date are allowed because the application stores `gr_date` as a date rather than a datetime.

---

## 4. Current Repository Architecture Relevant to This Change

The current repository already has a dedicated Local Core 2 master:

- `LocalPurchaseOrder`
- `LocalGoodsReceipt`
- `LocalProcurementMasterService`
- `LocalPoGrImportService`
- `LocalPoGrImport`
- `LocalPoGrImportTemplateExport`
- `Finance\LocalProcurementController`
- `finance.local-procurement.*`
- `purchasing.local-procurement.*`

The Local PO/GR master is shared between Finance and Purchasing. It must remain a single authoritative dataset; do not create a second import-specific master.

The project also already follows these conventions:

- Core 2 uses services for domain transitions.
- Supplier visibility is ownership/scoped.
- Private uploads must use the private storage strategy.
- Hashids are used for external model URLs; Hashids are not authorization.
- Import flows use preview → validation → confirmation rather than immediate blind persistence.
- Existing Local PO/GR records are treated as authoritative and should not be silently overwritten by import.

---

## 5. Existing Import Flow That Must Be Reworked

The current implementation accepts a combined PO/GR workbook with application-specific headings such as:

```text
po_number
supplier_name
po_date
po_amount
po_remarks
gr_number
gr_date
gr_amount
gr_remarks
```

This is no longer the required ERP format.

The following combined concept must be replaced:

- one combined PO + GR file;
- combined template;
- combined importer;
- combined validation model;
- combined preview semantics;
- combined import confirmation.

The new design must instead expose independent PO and GR workflows.

The existing `LocalPoGrImportService` may be refactored into separate services if that produces the smallest maintainable change. Do not retain a large combined service merely to preserve obsolete behavior.

---

# 6. Functional Design

## 6.1 Master PO & GR page

Current page:

`resources/views/finance/local-procurement/index.blade.php`

The page currently exposes one combined import action. Replace it with two explicit actions:

```text
[ Import PO ]   [ Import GR ]
```

Each action must open its own modal or dedicated import interface using the existing project UI components and modal patterns.

### Import PO modal

Display:

- description of the Infor `tdpur` format;
- allowed file type `.xlsx`;
- maximum upload size consistent with the existing ERP import safety policy;
- **Download Template PO** button;
- file selection;
- Validate & Preview button;
- preview summary;
- validation error list;
- Confirm Import button.

### Import GR modal

Display:

- description of the Infor `whinh` format;
- allowed file type `.xlsx`;
- **Download Template GR** button;
- file selection;
- Validate & Preview button;
- preview summary;
- validation error list;
- Confirm Import button.

Do not reuse text implying that the workbook combines PO and GR.

---

# 7. ERP Template Design

## 7.1 PO template

Create a dedicated PO template derived from the supplied `tdpur` export structure.

The template must preserve the ERP column positions so the user can upload either the generated template or a real Infor export without rearranging fields.

### Required positional structure

At minimum, the `data` sheet must preserve columns through `P` with the ERP header row layout.

Authoritative fields:

- E — Order
- G — Supplier name
- J — Order Date
- K — Order Amount

Other source columns may remain present to preserve positional fidelity but are **ignored by the importer** unless explicitly required later.

The template must not invent additional business mappings merely because other columns exist in the ERP file.

Recommended template behavior:

- row 1: ERP headers;
- one clearly marked example row or blank data rows;
- no production/sample supplier records in the actual downloadable template unless intentionally anonymized;
- workbook/sheet structure remains compatible with direct ERP export.

### Template endpoint

Replace the single combined template endpoint with a dedicated PO template endpoint, while checking whether the existing route must remain as a backwards-compatible alias.

Suggested naming:

```text
GET /finance/local-procurement/import/po/template
GET /purchasing/local-procurement/import/po/template
```

Actual route naming must follow the repository's existing dual-mounted Local Procurement controller structure.

---

## 7.2 GR template

Create a dedicated GR template derived from the supplied `whinh` export structure.

The template must preserve columns through `U` and the original positional layout.

Authoritative fields:

- J — Receipt / GR Number
- L — Order Line / user-defined PO Number
- P — Received Quantity
- T — Actual Receipt Date

Other columns remain informational/ignored unless later requirements explicitly assign a meaning.

No GR Amount column is part of the new ERP import mapping.

Suggested endpoints:

```text
GET /finance/local-procurement/import/gr/template
GET /purchasing/local-procurement/import/gr/template
```

Again, use the existing controller mounting convention rather than creating two unrelated controllers.

---

# 8. PO Import Pipeline

## 8.1 Request validation

Create a dedicated request, for example:

`UploadLocalPoImportRequest`

Responsibilities:

- require `.xlsx`;
- validate upload size;
- reject unsupported formats;
- prevent empty uploads;
- apply existing upload safety conventions.

The controller must not contain ERP parsing logic.

---

## 8.2 ERP parser

Create a dedicated PO import reader/parser, for example:

`app/Imports/LocalPoImport.php`

The parser must use the **fixed ERP column positions**, not application-specific heading names as the only source of truth.

Reason:

- the ERP export is positional;
- some required source columns may have blank/ambiguous heading cells;
- the user's requirements explicitly identify columns E/G/J/K.

Recommended parser contract:

```php
[
    '_row' => 2,
    'po_number' => 'PNR261178',
    'supplier_name' => 'PT SURYA UTAMA TEKNOLOGI',
    'po_date' => '2026-09-16',
    'po_amount' => '633000.00',
]
```

The parser should still inspect the header row and report a file-format warning/error if the expected ERP labels at the authoritative positions are materially different.

Do not fail solely because unused ERP columns change.

---

## 8.3 PO normalization

Rules:

- preserve PO number as string;
- trim leading/trailing whitespace;
- normalize only for comparison, not for stored business identity;
- preserve leading zeroes if present;
- supplier name: trim and normalize case/whitespace for lookup;
- date: support Excel serial/date values and normalize to `Y-m-d`;
- amount: numeric, positive, maximum 2 decimal places;
- formulas in authoritative fields are rejected;
- empty ERP data rows are ignored.

---

## 8.4 Supplier matching

The ERP supplier name from column G must resolve to exactly one active Local Supplier using the existing Local eligibility/source-of-truth helpers.

Matching should be:

```text
normalized ERP supplier name
        ↓
active Local Supplier master
        ↓
exactly 1 match required
```

Failure cases:

- no match → blocking validation error;
- multiple matches → blocking ambiguity error;
- inactive/non-local supplier → blocking error.

Do not create a supplier automatically during PO import.

---

## 8.5 Existing PO handling

Preserve the current import safety policy:

### Existing PO with equivalent header

If the PO already exists and its authoritative values are consistent with ERP data:

- do not overwrite it;
- classify as `existing` / skipped;
- include it in the preview summary.

### Existing PO with conflicting header

If the same PO number already exists but supplier/date/amount differs:

- block the import;
- show the conflict in preview;
- do not update the existing PO automatically.

### New PO

Create:

```text
LocalPurchaseOrder
    po_number
    supplier_id
    po_date
    total_amount
    currency = IDR
    status = OPEN
    source = IMPORT
```

Do not infer additional values from unrelated ERP fields.

---

# 9. GR Import Pipeline

## 9.1 Request validation

Create a dedicated request, for example:

`UploadLocalGrImportRequest`

Responsibilities are equivalent to PO import but scoped to the GR workbook.

---

## 9.2 ERP parser

Create a dedicated GR import reader, for example:

`app/Imports/LocalGrImport.php`

Authoritative positional mapping:

```text
J → gr_number
L → po_number
P → qty
T → gr_date
```

The parser must not read or require a GR amount.

---

## 9.3 GR normalization and grouping

The raw ERP file may have several item lines belonging to one receipt.

The importer must group raw rows by:

```text
normalized(gr_number) + normalized(po_number)
```

For each group:

```text
GR Number     = J
PO Number     = L
GR Date       = normalized T
GR Qty        = SUM(P)
```

The importer must not create one `LocalGoodsReceipt` per item line.

### Example

```text
REC060436 | WRO004914 | P=1
REC060436 | WRO004914 | P=1
REC060436 | WRO004914 | P=1
REC060436 | WRO004914 | P=1
```

becomes:

```text
GR Number = REC060436
PO        = WRO004914
Qty       = 4
```

The actual PO matching result depends on the value existing in `local_purchase_orders.po_number`.

---

## 9.4 GR → PO automatic matching

For every consolidated GR:

```text
GR.column L
      ↓
normalize PO reference
      ↓
exact lookup local_purchase_orders.po_number
      ↓
existing PO required
```

No manual PO selector is required in the GR import flow.

No PO is created automatically from a GR file.

No fuzzy matching is allowed.

### Unmatched PO

If the GR references a PO that does not exist:

```text
ERROR
GR RECxxxx references PO WROxxxxx, but no matching Local PO exists.
```

The GR must not be imported.

This is especially important because the supplied ERP samples currently show different identifier patterns between the PO and GR files.

---

# 10. GR Amount Removal and Downstream Impact

This requirement requires special handling because the current repository still has `received_amount` as part of the Local GR domain and invoice settlement.

Current code evidence shows `received_amount` is used in:

- `LocalGoodsReceipt`;
- Local Procurement Master calculations;
- Local Supplier Purchase Order views;
- Local Invoice PO/GR selection;
- `LocalGrReservationService`;
- `LocalInvoiceGoodsReceipt.gr_amount_snapshot`;
- invoice reconciliation/integrity checks;
- sample seeders/tests.

The ERP `whinh` file contains no GR monetary amount, and the new import requirement explicitly says GR Amount is no longer required.

### Required architectural treatment

Do **not** fabricate a GR amount from:

- PO total amount;
- quantity;
- item count;
- another ERP column;
- invoice amount.

There is no source evidence supporting such a derivation.

### Recommended target state

Treat `qty` as the ERP GR operational measure and remove the GR monetary dependency from the new Local GR master flow.

However, this must be implemented as a controlled domain migration because the current invoice settlement logic requires GR amount equality with invoice DPP.

The implementation should therefore include the following dependency work:

1. Keep historical `received_amount` data during migration for backward compatibility.
2. Make the field nullable before introducing amount-less ERP GR records, if the existing schema requires a value.
3. Add `qty` as the authoritative GR quantity for newly imported records.
4. Add `qty_snapshot` to `local_invoice_goods_receipts` if invoice/settlement history needs an immutable GR quantity snapshot.
5. Remove the requirement that a newly imported GR must have a monetary amount.
6. Refactor invoice GR selection/reservation so it continues to enforce:
   - one PO per invoice;
   - one or more whole GRs;
   - GR ownership and PO ownership;
   - row locking and state transitions;
   - no partial GR consumption;
   - one GR cannot be simultaneously consumed by competing invoices.
7. Remove the direct dependency on `SUM(GR amount) == invoice DPP` for new amount-less GRs.
8. Preserve existing historical invoice records and snapshots.
9. Update reconciliation logic so an amount-less ERP GR is not falsely reported as an integrity error merely because it has no amount.
10. Establish a separate financial guard before accepting invoices against amount-less GRs. At minimum, the implementation must prevent invoice value from exceeding the authoritative PO financial ceiling where the current domain already has enough information to enforce that safely.

### Important constraint

Do not delete `received_amount` in the same change without first proving, through repository search and tests, that no historical or operational record still depends on it.

A safe migration sequence is preferred:

```text
Phase 1
existing received_amount remains
        ↓
Phase 2
amount becomes nullable / legacy-compatible
        ↓
Phase 3
ERP GR import stores qty, no amount
        ↓
Phase 4
invoice/reconciliation logic no longer requires GR amount
        ↓
Phase 5
only then consider physical removal of received_amount
```

If the implementation cannot safely complete Phase 3–4 within this feature, the executor must report the dependency as a blocker rather than fabricate values.

---

# 11. Data Model Changes

## 11.1 `local_goods_receipts`

Existing relevant fields include:

- `gr_number`
- `local_purchase_order_id`
- `gr_date`
- `received_amount`
- `qty`
- `description`
- `notes`
- status/audit fields

Required changes:

- retain `qty` as `decimal(12,4)` or equivalent existing project precision;
- ensure import persistence writes `qty`;
- preserve `description`/`notes` according to existing project semantics;
- make `received_amount` nullable if amount-less imported GR records are supported;
- do not auto-populate `received_amount`.

Whether `received_amount` is ultimately dropped must be deferred until downstream dependencies are removed and verified.

---

## 11.2 `local_invoice_goods_receipts`

Current records contain `gr_amount_snapshot`.

Recommended migration:

```text
ADD gr_qty_snapshot DECIMAL(12,4) NULL
```

Historical `gr_amount_snapshot` should remain readable for historical invoices.

For newly created reservations:

- snapshot GR number;
- snapshot GR quantity;
- snapshot amount only when an amount actually exists for legacy/historical records.

Do not populate monetary snapshot values with `0` merely because the ERP did not provide a value.

---

# 12. Service Layer

## 12.1 PO service

Create/refactor a focused service, for example:

`app/Services/LocalInvoice/LocalPoImportService.php`

Responsibilities:

- parse normalized rows;
- validate supplier resolution;
- validate duplicates/conflicts;
- generate preview summary;
- persist new POs in a transaction;
- record import audit events;
- return deterministic import counts.

Suggested result:

```php
[
    'rows' => 120,
    'new_po' => 85,
    'existing_po' => 35,
    'errors' => 0,
]
```

---

## 12.2 GR service

Create/refactor:

`app/Services/LocalInvoice/LocalGrImportService.php`

Responsibilities:

- parse ERP row data;
- normalize and group GR line rows;
- resolve PO ownership;
- validate duplicate/conflicting GRs;
- validate quantity/date;
- persist consolidated GRs atomically;
- record audit events;
- return deterministic counts.

Suggested result:

```php
[
    'source_rows' => 4800,
    'consolidated_gr' => 920,
    'new_gr' => 880,
    'existing_gr' => 40,
    'unmatched_po' => 0,
    'errors' => 0,
]
```

---

# 13. Shared Import Infrastructure

Shared infrastructure can remain where it genuinely reduces duplication:

- preview token generation;
- audit recording;
- upload staging;
- formula detection;
- date normalization utilities;
- common error response formatting.

Do not force PO and GR into one DTO or row structure merely to share code.

The data contracts are materially different and should remain explicit.

---

# 14. Preview Behavior

## PO preview

Show:

| Row | PO Number | Supplier | Date | Amount | Action |
|---|---|---|---|---:|---|
| 2 | PNR261178 | Supplier A | 16 Sep 2026 | Rp 633,000 | NEW |

Summary cards:

- Total ERP Rows
- New PO
- Existing PO
- Conflicts
- Errors

## GR preview

Show **consolidated GR records**, not raw ERP line rows as the primary business preview:

| Source Rows | GR Number | PO Number | GR Date | Qty | Action |
|---:|---|---|---|---:|---|
| 4 | REC060436 | WRO004914 | 08 Sep 2026 | 4 | NEW |

Summary cards:

- ERP Source Rows
- Consolidated GR
- New GR
- Existing GR
- Unmatched PO
- Conflicts
- Errors

For traceability, optionally provide a collapsed/detail view of the contributing ERP rows for a consolidated GR.

---

# 15. Atomicity and Transaction Rules

Both imports must use a two-stage flow:

```text
UPLOAD
  ↓
PARSE
  ↓
VALIDATE
  ↓
PREVIEW
  ↓
CONFIRM
  ↓
DB TRANSACTION
```

### No partial confirm

If one blocking validation exists:

```text
0 new records persisted
```

The system must not partially import valid rows while silently skipping invalid ones.

### Concurrent imports

Use database constraints and `lockForUpdate()` where necessary.

PO:

- lock existing PO during conflict verification/import;
- depend on unique `po_number` constraint as final race-condition protection.

GR:

- lock the target PO before adding new GRs;
- lock existing GR rows when checking duplicates if applicable;
- depend on unique GR-number constraint as final race-condition protection.

---

# 16. Audit Trail

Use the existing Local Finance audit mechanism.

Separate event keys are recommended:

```text
po_import_previewed
gr_import_previewed
po_import_confirmed
gr_import_confirmed
po_import_created
gr_import_created
gr_import_matched_to_po
```

Correct typo-free final event names in implementation; the list above intentionally describes the conceptual events and should not be copied blindly.

Audit metadata should include:

- actor ID;
- original filename;
- import type (`PO` / `GR`);
- timestamp;
- source row count;
- consolidated record count for GR;
- created count;
- skipped/existing count;
- error count;
- import token/batch identifier where appropriate.

Do not store the entire uploaded workbook in the audit record.

---

# 17. UI and Route Changes

## Controllers

Continue using:

`app/Http/Controllers/Finance/LocalProcurementController.php`

because the existing controller is deliberately mounted for both Finance and Purchasing.

Add dedicated methods or action methods for:

```text
poTemplate()
poPreview()
poConfirm()
grTemplate()
grPreview()
grConfirm()
```

Keep the controller thin.

---

## Routes

Current combined routes include:

```text
{role}.local-procurement.import.template
{role}.local-procurement.import.preview
{role}.local-procurement.import.confirm
```

Replace/extend them with separate route names following the same role-prefixed convention:

```text
{role}.local-procurement.import.po.template
{role}.local-procurement.import.po.preview
{role}.local-procurement.import.po.confirm

{role}.local-procurement.import.gr.template
{role}.local-procurement.import.gr.preview
{role}.local-procurement.import.gr.confirm
```

Before removing the existing combined route names, search all repository callers. If there are no external consumers and compatibility is not needed, remove the obsolete route. Otherwise retain a controlled compatibility wrapper that does not accept the obsolete mixed workbook silently.

---

# 18. Existing File/Code Changes — Expected Matrix

### Replace / refactor

- `app/Services/LocalInvoice/LocalPoGrImportService.php`
- `app/Imports/LocalPoGrImport.php`
- `app/Exports/LocalPoGrImportTemplateExport.php`
- `resources/views/finance/local-procurement/_import_modal.blade.php`
- `app/Http/Controllers/Finance/LocalProcurementController.php`
- `routes/finance.php`
- `routes/web.php` if the dual-mounted Purchasing route block is defined there

### Likely new files

- `app/Imports/LocalPoImport.php`
- `app/Imports/LocalGrImport.php`
- `app/Exports/LocalPoImportTemplateExport.php`
- `app/Exports/LocalGrImportTemplateExport.php`
- `app/Services/LocalInvoice/LocalPoImportService.php`
- `app/Services/LocalInvoice/LocalGrImportService.php`
- dedicated request classes if current upload validation pattern warrants them
- new migration(s) for GR amount compatibility / invoice GR quantity snapshot if required by the finalized domain cutover

### Existing models/services requiring audit

- `app/Models/LocalGoodsReceipt.php`
- `app/Models/LocalPurchaseOrder.php`
- `app/Models/LocalInvoiceGoodsReceipt.php`
- `app/Services/LocalInvoice/LocalProcurementMasterService.php`
- `app/Services/LocalInvoice/LocalGrReservationService.php`
- `app/Services/LocalInvoice/LocalPoReferenceService.php`
- `app/Console/Commands/ReconcileLocalSupplierInvoices.php`

### Existing views requiring audit

- `resources/views/finance/local-procurement/index.blade.php`
- `resources/views/finance/local-procurement/show.blade.php`
- `resources/views/local-supplier/purchase-orders/index.blade.php`
- `resources/views/local-supplier/purchase-orders/show.blade.php`
- `resources/views/local-invoices/form.blade.php`

Do not assume every listed file must be modified. Change only files proven necessary after dependency inspection.

---

# 19. Database Constraints and Idempotency

## PO

Business key:

```text
po_number
```

Required behavior:

- duplicate within same workbook → validate consistently;
- duplicate against DB with identical header → existing/skipped;
- duplicate against DB with conflicting header → blocking conflict;
- never create two Local PO records for one PO number.

## GR

Business key:

```text
g r_number
```

Final implementation should use the repository's actual canonical normalization and unique constraint.

Required behavior:

- repeated ERP line rows → consolidated into one GR;
- same GR + same PO → aggregate quantity;
- same GR + different PO → blocking conflict;
- existing GR with identical business data → existing/skipped where safe;
- existing GR with conflicting PO/date/qty → blocking conflict;
- never duplicate the same business GR.

---

# 20. Security Requirements

1. Uploads remain private and temporary.
2. Do not expose raw storage paths.
3. Use existing authorization and role boundaries.
4. Only Finance/Admin/Purchasing operators can execute the master import.
5. Supplier users cannot trigger import endpoints.
6. All model URLs continue using existing Hashids conventions where models are externally referenced.
7. Never trust client-provided supplier IDs to change ERP supplier ownership during import.
8. Supplier resolution must use server-side Local Supplier eligibility.
9. Reject formula-based authoritative values.
10. Reject malformed spreadsheets before DB persistence.
11. Limit workbook size and row count according to safe configured thresholds.
12. Do not execute macros or external workbook links.
13. Temporary import files must be deleted after processing.
14. Import audit records must not contain credentials or arbitrary workbook binary data.

---

# 21. Performance Requirements

The new GR workflow can receive significantly more raw ERP line rows than the 48-row supplied sample.

Therefore:

- avoid one database query per ERP row;
- preload/resolve PO numbers in batches;
- use normalized maps for existing PO and GR lookups;
- aggregate GR rows in memory before persistence where practical;
- use transactions in bounded chunks only if the configured maximum dataset makes one transaction impractical and the import semantics explicitly allow it;
- do not introduce N+1 queries in the preview API;
- keep the preview response bounded if ERP datasets become large.

The first implementation should remain compatible with the existing preview architecture, then optimize only where actual dataset sizes justify it.

---

# 22. Test Plan

## 22.1 PO import tests

Create a dedicated test suite such as:

`tests/Feature/LocalInvoice/LocalPoImportTest.php`

Cover:

1. Valid `tdpur` workbook parses E/G/J/K correctly.
2. Supplier name resolves to exactly one local supplier.
3. Unknown supplier is rejected.
4. Ambiguous supplier is rejected.
5. Invalid PO date is rejected.
6. Invalid/negative/non-numeric amount is rejected.
7. Formula in E/G/J/K is rejected.
8. New PO is created with `source=IMPORT`.
9. Existing identical PO is not duplicated.
10. Existing conflicting PO blocks import.
11. Duplicate PO rows are handled deterministically.
12. Import confirmation is atomic.
13. Concurrent imports cannot create duplicate PO numbers.

---

## 22.2 GR import tests

Create:

`tests/Feature/LocalInvoice/LocalGrImportTest.php`

Cover:

1. Valid `whinh` workbook parses J/L/P/T correctly.
2. GR automatically matches an existing Local PO using L.
3. Missing PO produces a blocking error.
4. Formula in J/L/P/T is rejected.
5. Invalid date is rejected.
6. Missing GR Number/PO/Qty/Date is rejected.
7. Zero/negative aggregated quantity is rejected.
8. Repeated ERP rows for the same GR + PO are consolidated.
9. Aggregated quantity equals the sum of P values.
10. Same GR Number linked to different PO numbers is rejected.
11. Existing GR is not duplicated.
12. Existing conflicting GR blocks import.
13. New GR is created with `source=IMPORT`.
14. GR import does not require a GR amount.
15. Import is atomic.
16. Concurrent GR imports cannot create duplicates.

---

## 22.3 Fixture tests using the supplied ERP shape

Add fixtures/tests that reproduce the structural characteristics of the supplied files:

### PO fixture

- columns through P;
- authoritative data at E/G/J/K;
- Excel serial date values;
- supplier names in G even though the source heading cell is blank.

### GR fixture

- columns through U;
- authoritative data at J/L/P/T;
- multiple raw rows for one GR Number;
- `Receipt` values repeated across line items;
- quantity aggregation;
- no amount column used by importer.

Include a fixture where L contains an unmatched `WRO...` value to ensure the system reports the mismatch rather than performing fuzzy mapping.

---

# 23. Regression Tests

Because the Local PO/GR master feeds invoicing and payment, run at minimum:

```text
php artisan test tests/Feature/LocalInvoice/
php artisan test tests/Feature/SupplierDataIsolationTest.php
php artisan test tests/Feature/HashidUrlSecurityTest.php
php artisan local-invoices:reconcile --json
```

Also run the specific tests covering:

- whole-GR reservation;
- invoice submission;
- invoice resubmission;
- finance verification;
- cashier receipt/expiry;
- payment/voucher settlement.

If `received_amount` is modified or removed, all tests touching `LocalGrReservationService`, reconciliation, voucher eligibility, and invoice settlement must be rerun.

---

# 24. Verification Commands

Follow the repository's smallest-first verification pattern.

### Focused

```bash
php artisan test tests/Feature/LocalInvoice/LocalPoImportTest.php
php artisan test tests/Feature/LocalInvoice/LocalGrImportTest.php
```

### Existing Local Core regression

```bash
php artisan test tests/Feature/LocalInvoice/
php artisan test tests/Feature/SupplierDataIsolationTest.php
php artisan test tests/Feature/HashidUrlSecurityTest.php
php artisan local-invoices:reconcile --json
```

### Project checks

```bash
composer validate --strict
composer audit
vendor/bin/pint --test
npm run build
```

Run `npm run build` because the Master PO & GR import UI is Blade/JavaScript-backed.

If the repository's current verified baseline contains known unrelated failures, report them separately and do not count them as regressions introduced by this change.

---

# 25. Acceptance Criteria

The feature is complete only when all of the following are true:

### Import separation

- [ ] Master PO & GR exposes two independent options: Import PO and Import GR.
- [ ] PO and GR have separate templates.
- [ ] The old combined application-specific spreadsheet format is no longer required.

### PO

- [ ] `tdpur` layout is accepted directly.
- [ ] E maps to PO number.
- [ ] G maps to supplier name.
- [ ] J maps to PO date.
- [ ] K maps to PO amount.
- [ ] Supplier matching is exact and server-side.
- [ ] Existing conflicting POs are not overwritten.

### GR

- [ ] `whinh` layout is accepted directly.
- [ ] J maps to GR Number.
- [ ] T maps to GR date.
- [ ] P maps to GR Qty.
- [ ] L is used to match an existing PO.
- [ ] No GR Amount is read from the ERP file.
- [ ] Repeated line rows for one GR are consolidated.
- [ ] Aggregate Qty is correct.
- [ ] Unmatched PO blocks import.
- [ ] Conflicting GR → PO mappings block import.

### Data integrity

- [ ] Imports are previewed before persistence.
- [ ] Confirmation is atomic.
- [ ] Duplicate PO/GR records are prevented.
- [ ] Import audit events are recorded.
- [ ] Existing supplier/PO/GR isolation rules remain intact.

### GR Amount dependency

- [ ] No fabricated GR amount is introduced.
- [ ] Historical amount data remains safe.
- [ ] Amount-less imported GRs do not break reconciliation.
- [ ] Invoice/GR reservation logic has an explicitly verified strategy for amount-less GRs.
- [ ] The implementation does not silently weaken financial controls.

---

# 26. Implementation Sequence

Recommended execution order:

```text
1. Read CLAUDE.md, context.md, AGENTS.md and affected tests.
   ↓
2. Inspect current combined PO/GR importer and all callers.
   ↓
3. Inspect current Local GR amount dependencies.
   ↓
4. Implement PO ERP parser + validator.
   ↓
5. Implement PO template + independent UI flow.
   ↓
6. Add PO tests.
   ↓
7. Implement GR ERP parser using fixed J/L/P/T positions.
   ↓
8. Implement GR line-row consolidation.
   ↓
9. Implement automatic PO matching.
   ↓
10. Implement GR template + independent UI flow.
   ↓
11. Add GR tests.
   ↓
12. Resolve the GR Amount dependency without fabricated values.
   ↓
13. Update reconciliation / invoice integration tests.
   ↓
14. Remove or compatibility-wrap obsolete combined import paths.
   ↓
15. Run focused regression.
   ↓
16. Run full required verification.
```

---

# 27. Engineering Guardrails

The executor must follow these rules:

- Current repository code is authoritative for actual route names, schema, helper methods, and existing implementation patterns.
- The two supplied Infor workbooks are authoritative for source column positions and terminology.
- The user's explicit mappings override ambiguous ERP header wording for the requested fields, but no hidden mapping between identifier formats may be invented.
- Distinguish fact, inference, and assumption during implementation.
- Do not create a parallel PO/GR master.
- Do not add fuzzy matching.
- Do not auto-create a supplier from ERP text.
- Do not auto-create a PO from a GR workbook.
- Do not derive a GR amount from quantity or PO amount.
- Do not weaken supplier isolation.
- Do not overwrite an existing PO because an ERP import happens to contain a different value.
- Do not claim the new import is production-ready until focused and regression verification has actually passed.
- Do not add browser E2E as a completion prerequisite.
- Do not perform unrelated cleanup or refactoring.

---

# 28. Final Deliverable

The implementation should result in:

```text
Master PO & GR
├── Import PO
│   ├── ERP tdpur template
│   ├── ERP positional parser
│   ├── Supplier resolution
│   ├── PO validation
│   ├── Preview
│   └── Atomic import
│
└── Import GR
    ├── ERP whinh template
    ├── ERP positional parser
    ├── GR line consolidation
    ├── PO automatic matching
    ├── Qty persistence
    ├── No GR Amount dependency for ERP source
    ├── Preview
    └── Atomic import
```

The implementation must preserve the existing Local Procurement domain as the single source of truth while making the ERP import contract match the real Infor export structure supplied for this revision.
