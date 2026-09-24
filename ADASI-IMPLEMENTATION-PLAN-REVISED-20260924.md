# Implementation Plan — Local Supplier Portal Revisions

Tanggal: 2026-09-24

## Objective

Implement only the three newly requested revisions against the current `poyipoy/supplierportal` codebase:

1. Extend Goods Receipt (GR) with additional information: `qty` and `description`.
2. Auto-fill invoice number and tax invoice number from uploaded filenames, with server-side enforcement.
3. Add Supplier `Purchase Orders`, PO PDF upload/download, filename-based PO matching, and secure ZIP auto-extraction.

Current repository source is authoritative for existing schema, route names, authorization, storage, UI components, and service boundaries.

## Existing Codebase Context

The current Local Supplier flow already has authoritative Local PO/GR models and services:

- `app/Models/LocalPurchaseOrder.php`
- `app/Models/LocalGoodsReceipt.php`
- `app/Services/LocalInvoice/LocalProcurementMasterService.php`
- `app/Services/LocalInvoice/LocalPoGrImportService.php`
- `app/Http/Controllers/Finance/LocalProcurementController.php`
- `app/Http/Requests/LocalInvoice/SaveLocalGoodsReceiptRequest.php`
- `app/Models/LocalInvoice.php`
- `app/Services/LocalInvoice/InvoiceSubmissionService.php`
- `app/Http/Requests/LocalInvoice/StoreLocalInvoiceRequest.php`
- `app/Http/Requests/LocalInvoice/ResubmitLocalInvoiceRequest.php`

The repository documents `local_purchase_orders` and `local_goods_receipts` as the authoritative Local Supplier PO/GR master, shared by Finance and Purchasing. Existing invoice settlement reserves complete GR records and uses the monetary `received_amount` value as the settlement basis. These invariants must remain unchanged.

The existing generic private-file mechanism is the polymorphic `attachments` table with `AttachmentPolicy` and `AttachmentController`. The implementation should reuse that mechanism for PO PDFs unless repository evidence proves a different model is required.

---

# R-01 — Extend Goods Receipt (GR) Information

## 1. Requirement

Add two informational fields to the existing Local Goods Receipt master:

- `description` — description of the received goods/material.
- `qty` — quantity received.

These fields are additive. The existing `received_amount` remains the authoritative monetary value for the current whole-GR invoice settlement flow.

`qty` must not silently replace or alter amount calculations, GR settlement, PO cap validation, or invoice matching.

## 2. Database

Add a migration for `local_goods_receipts`:

- `description` — nullable text or varchar following existing project conventions.
- `qty` — decimal/numeric using the quantity precision already established by the repository. Inspect existing material/quantity columns first; do not invent a different precision convention.

The migration must be reversible and must not edit an existing historical migration.

## 3. Model

Modify `app/Models/LocalGoodsReceipt.php`:

- add `description` and `qty` to `$fillable`;
- add the appropriate cast for `qty` consistent with the repository's established quantity precision;
- preserve all existing relationships and status behavior.

## 4. Request Validation

Modify `app/Http/Requests/LocalInvoice/SaveLocalGoodsReceiptRequest.php`:

- `description`: nullable string with a sensible project-consistent max length;
- `qty`: required numeric positive value, with precision/scale matching the selected database convention;
- preserve validation for `gr_number`, `gr_date`, and `received_amount`.

Apply identical business validation on create and update paths.

## 5. Service Layer

Modify `LocalProcurementMasterService`:

- persist `description` and `qty` in `createGoodsReceipt()`;
- persist them in `updateGoodsReceipt()`;
- include them in existing audit snapshots where appropriate;
- do not modify `assertCap()` or invoice settlement semantics to use `qty`.

## 6. UI

Update the existing Finance/Purchasing Local PO detail GR form/modal in:

`resources/views/finance/local-procurement/show.blade.php`

Add inputs for:

- Quantity
- Description/Keterangan

Also show these values in the GR table/detail so users can see them after creation.

Follow existing UI components and styling. Do not introduce a separate visual design system.

## 7. Supplier View

The Supplier `Purchase Orders` page introduced in R-03 must expose GR quantity and description alongside GR number/date/amount.

## 8. Tests

Add focused coverage for:

- creating a GR with `qty` + `description`;
- updating an AVAILABLE GR with those fields;
- validation failures for invalid qty;
- persistence and correct casting/formatting;
- preserving existing PO cap validation;
- preserving existing whole-GR invoice settlement behavior.

---

# R-02 — Auto-Fill Invoice Number and Tax Invoice Number From Uploaded Filename

## 1. Requirement

When the supplier uploads invoice documents in the existing **Ajukan Invoice** flow:

- the invoice number field should be filled automatically from the uploaded invoice filename;
- the tax invoice number field should be filled automatically from the uploaded tax-invoice filename.

Example logical identifier supplied by the user:

`INV/2026/09/01`

Important: `/` cannot be a literal filename path separator on normal filesystems. Therefore the implementation must inspect the actual filename convention used by the application and derive the logical identifier from the real uploaded filename instead of arbitrarily replacing `/` with another separator.

## 2. Filename Parsing Authority

Create a small reusable parser/service, or place the parser in an existing service boundary if the repository already has a suitable convention.

The parser must:

- use the original client filename only as input to parsing, never as an authorization value;
- strip only the actual file extension;
- normalize according to the established business format;
- return a deterministic identifier or a validation failure;
- never silently guess when the filename is ambiguous.

The implementation must first inspect existing invoice/tax-invoice filename conventions in the current codebase, tests, fixtures, or operational documentation.

## 3. Multiple Files

The existing UI allows multiple invoice and tax-invoice files.

Define deterministic behavior:

- use one primary file as the identifier source according to the repository's existing ordering/convention; or
- if more than one uploaded file is allowed to define the number and they yield conflicting identifiers, reject the submission with a validation error.

Do not silently select an arbitrary conflicting identifier.

## 4. UI Behavior

Update the shared invoice form:

`resources/views/local-invoices/form.blade.php`

When the relevant file input changes:

- inspect the selected filename(s);
- derive the candidate identifier;
- populate the corresponding input automatically;
- clearly indicate that the value was derived from the uploaded filename;
- keep the field editable only if the business rule permits it. If the filename-derived value is authoritative, the server must still recompute it and ignore tampered browser values.

The behavior must work for both new submissions and revision/resubmission flows.

## 5. Server-Side Enforcement

Modify:

- `StoreLocalInvoiceRequest`
- `ResubmitLocalInvoiceRequest`
- `InvoiceSubmissionService`

Server-side validation must derive the expected identifiers from the uploaded files and compare/use those values as the authoritative source.

A request containing a manipulated `invoice_number` or `tax_invoice_number` field must not allow a mismatch to be persisted.

Preserve existing:

- invoice number uniqueness rules;
- tax invoice number formatting;
- PKP/non-PKP requirements;
- revision behavior where invoice number is immutable for an existing invoice.

## 6. Error Handling

Reject with a clear validation error when:

- the filename cannot produce a valid identifier;
- required identifier file is missing;
- multiple candidate files conflict;
- derived tax invoice number fails the existing tax-number format rules;
- derived invoice number violates existing uniqueness rules.

Do not fall back to silently accepting manually entered mismatching values.

## 7. Tests

Add focused coverage for:

- invoice filename auto-fill;
- tax invoice filename auto-fill;
- extension removal;
- valid and invalid filename patterns;
- multiple-file conflict handling;
- tampered browser values rejected/ignored;
- uniqueness validation based on the derived identifier;
- revision/resubmission behavior;
- existing PKP and non-PKP flows remaining intact.

---

# R-03 — Supplier Purchase Orders, PO PDF Upload, Filename Matching, and ZIP Extraction

## 1. Business Flow

### Internal users

Purchasing or Finance opens the Local PO/GR master and chooses **Upload PO**.

A small modal appears with:

- Supplier selector;
- PO file upload.

Supported input:

- PDF;
- ZIP containing PDF PO documents.

After upload, the system derives the PO number from each filename and matches each file to an existing `LocalPurchaseOrder`.

The system must never create a new PO merely because a file contains an unfamiliar filename.

### Supplier

Supplier gets a new menu:

**Purchase Orders**

The page contains only PO records belonging to that supplier and relevant GR records belonging to those POs.

For each PO, supplier can:

- view PO information;
- view associated GR information;
- download the uploaded PO PDF when available.

Supplier must never see another supplier's PO or GR through URL manipulation or Hashids.

---

## 2. PO Document Storage

First inspect the current `attachments` implementation:

- `app/Models/Attachment.php`
- `app/Policies/AttachmentPolicy.php`
- `app/Http/Controllers/AttachmentController.php`
- existing attachment upload patterns.

Preferred implementation:

- associate PO PDF as an `Attachment` of `LocalPurchaseOrder`;
- store on the existing private disk;
- use `AttachmentController` for retrieval;
- extend `AttachmentPolicy` for `LocalPurchaseOrder` ownership rules.

Do not expose `storage` paths directly.

Do not create a second document table unless the existing attachment architecture is technically incapable of representing the requirement.

If more metadata is needed, prefer adding a minimal field/model that complements the existing attachment structure rather than duplicating storage functionality.

## 3. Data Model

Modify `LocalPurchaseOrder` as required for document access, preferably by reusing its existing `attachments()` relationship.

If the implementation needs a dedicated PO-document concept, document the reason and keep it strictly scoped to the PO document lifecycle.

Do not alter the authoritative PO identity, supplier ownership, amount, status, or GR relations.

## 4. Internal Upload UI

Update the shared Local PO/GR management interface used by Finance and Purchasing.

Add an **Upload PO** action that opens a mini modal.

Modal fields:

- Supplier — required, active Local Supplier only;
- File — required, PDF or ZIP.

The modal should submit to a dedicated upload endpoint and display useful validation/errors.

Use the same UI components/patterns already present in the repository.

## 5. Upload Authorization

Only Finance and Purchasing, plus Admin where existing authorization patterns permit administrative access, may upload PO documents.

The selected supplier must be validated server-side.

The upload endpoint must not trust a supplier ID merely because the UI supplied it.

The PO file must ultimately match an existing Local PO belonging to the selected supplier.

## 6. Filename-Based PO Matching

Implement a deterministic PO filename parser.

Rules:

1. Remove only the actual extension.
2. Normalize according to the repository's real PO-number convention.
3. Resolve against `LocalPurchaseOrder.po_number`.
4. Require the matched PO to belong to the selected supplier.
5. If there is no exact match, reject the file.
6. If more than one PO could match after normalization, reject the file as ambiguous.
7. Never auto-create a PO.
8. Do not use Hashids as the matching mechanism.

The parser should be reusable and unit tested separately from the upload controller.

## 7. PDF Upload Behavior

For a single PDF:

- validate file type and size;
- derive PO number from filename;
- resolve the existing Local PO;
- store the file privately;
- attach it to the resolved PO;
- record uploader/audit metadata;
- if an existing PO PDF exists, apply a deterministic replacement/versioning rule consistent with existing project behavior.

The implementation must not leave a database attachment pointing to a file that failed to write.

## 8. ZIP Upload and Auto-Extraction

A ZIP may contain multiple PO PDFs.

Process ZIP files through a private staging area.

Security requirements:

- reject path traversal (`../`, absolute paths, drive-letter paths, unusual separator traversal);
- extract only to an isolated temporary directory;
- reject nested ZIP/archive files;
- enforce a maximum number of entries;
- enforce a maximum aggregate uncompressed size;
- validate extracted file types before persistence;
- ignore/reject directories and unsupported file types according to a deterministic policy;
- prevent symlink-based extraction escapes where supported by the extraction library;
- perform a complete preflight of all entries before committing any attachment;
- if any file is invalid or unmatched, fail the entire batch unless the codebase already has an explicit partial-import contract;
- clean up temporary files in success and failure paths.

Do not recursively extract archives.

Do not trust the ZIP's MIME type alone; validate actual extracted files using the project's secure file-validation approach.

## 9. ZIP Batch Transaction Semantics

The system needs two coordinated atomicity boundaries:

### Database

The database changes for the batch should be committed together.

### Filesystem

Because filesystem storage is not part of the database transaction, implement compensation:

- write/stage files first;
- track every path written;
- if database persistence fails, delete only files written by this operation;
- if post-write validation fails, clean the staged files;
- do not delete a previous valid PO attachment until the replacement has been safely persisted according to the chosen replacement strategy.

## 10. Duplicate / Replacement Behavior

Define and implement a deterministic rule for uploading a PO PDF when one already exists.

Preferred behavior:

- the newest approved upload becomes the current downloadable PO document;
- previous files should not be destroyed unless the existing attachment architecture explicitly requires replacement;
- if history is retained, the current attachment must be deterministic and the previous attachment must not accidentally remain the supplier-visible default.

The chosen rule must be reflected in tests.

## 11. Audit Trail

Record an audit event for successful PO document upload/replacement containing at least:

- actor;
- supplier;
- PO;
- original filename;
- document type/category;
- upload timestamp;
- whether source was PDF or ZIP;
- ZIP batch identifier when applicable.

Do not store raw file contents or secrets in audit metadata.

Reuse the existing project audit conventions where available.

## 12. Supplier Purchase Orders Query

Add a supplier-facing query/controller/service for Purchase Orders.

The query must enforce:

`LocalPurchaseOrder.supplier_id = authenticated_supplier_user_id`

and load only the associated GR records for those POs.

Do not query all POs and filter only in Blade.

Use pagination for the PO list when the repository's standard list patterns require it.

## 13. Supplier Purchase Orders UI

Add a supplier sidebar/menu item:

**Purchase Orders**

Suggested page structure:

- PO Number
- PO Date
- PO Amount
- PO Status
- PO Description
- GR summary
- GR Number
- GR Date
- GR Qty
- GR Description
- GR Amount
- PO document availability
- Download action

The design must reuse current supplier portal UI components and layout conventions.

No custom visual redesign is required.

## 14. Secure Download

Supplier PO PDF download must:

- require authentication;
- require supplier role/scope eligibility consistent with the existing portal;
- authorize the `LocalPurchaseOrder` ownership before returning the attachment;
- use the existing `AttachmentController`/policy mechanism where possible;
- return 404/403 according to existing project conventions;
- never accept a path supplied by the client.

Add an explicit regression test where Supplier B attempts to download Supplier A's PO PDF.

## 15. Routes

Add only the required route changes after inspecting current route conventions.

Expected responsibilities:

- supplier Purchase Orders index/detail/download access;
- Finance/Purchasing PO upload endpoint;
- optional secure PO document endpoint only if the existing attachment route cannot satisfy the requirement.

Use existing Hashids conventions for public route parameters, but never use Hashids as authorization.

## 16. Sidebar

Update `resources/views/partials/sidebar.blade.php` with the Supplier `Purchase Orders` navigation item.

Finance/Purchasing should receive the `Upload PO` action inside their existing Local PO/GR context rather than as a duplicate unrelated master menu.

## 17. Tests

Add focused test coverage for:

### GR

- create/update qty + description;
- validation and persistence;
- unchanged monetary settlement behavior.

### Invoice filename parsing

- invoice number derived from filename;
- tax invoice number derived from filename;
- UI auto-fill logic where practical;
- server-side enforcement;
- conflicting multiple files;
- tampered manual values;
- uniqueness and revision behavior.

### PO upload

- Finance can upload a matching PDF;
- Purchasing can upload a matching PDF;
- unauthorized roles are denied;
- supplier selection is validated;
- filename match resolves the correct existing PO;
- unmatched filename is rejected;
- ambiguous filename is rejected;
- a different supplier's PO cannot be attached through manipulated input;
- duplicate/replacement behavior is deterministic.

### ZIP

- valid ZIP with multiple matching PDFs succeeds;
- unmatched member causes the defined atomic failure behavior;
- nested ZIP rejected;
- path traversal rejected;
- excessive entry count rejected;
- excessive uncompressed size rejected;
- unsupported extracted file rejected;
- temporary files are cleaned up;
- filesystem failure compensates newly written files.

### Supplier isolation

- supplier sees only own Local POs;
- supplier sees only own GR records;
- Supplier B cannot download Supplier A PO attachment;
- Hashid substitution/IDOR attempts fail.

---

# Cross-Feature Regression Requirements

The implementation must preserve all existing behavior outside these three revisions, especially:

- Local PO/GR remains one authoritative master dataset shared by Finance and Purchasing;
- imports remain add-only as currently defined;
- Local Invoice whole-GR reservation/settlement remains unchanged;
- `received_amount` remains the monetary GR basis;
- supplier isolation remains enforced server-side;
- current attachment storage remains private;
- existing authentication/authorization behavior remains intact;
- existing invoice tax validation and status workflow remain intact;
- existing import `PurchaseOrder` behavior remains untouched unless a direct code dependency is proven.

Do not modify unrelated modules.

---

# Verification Plan

## Focused Tests

Run the new/affected tests for:

- Local GR information;
- invoice filename parsing/auto-fill;
- Local PO document upload;
- ZIP extraction/security;
- supplier PO isolation/download;
- affected Local Invoice flows.

## Existing Regression Suites

At minimum, inspect and run the relevant existing suites, including:

```text
php artisan test tests/Feature/LocalInvoiceSubmissionV2Test.php
php artisan test tests/Feature/LocalInvoice/LocalSupplierWholeGrSettlementTest.php
php artisan test tests/Feature/LocalInvoice/LocalGrReservationConcurrencyTest.php
php artisan test tests/Feature/LocalInvoice/
php artisan test tests/Feature/Auth/
```

Run the full test suite when practical.

## Static / Build Verification

```text
composer validate --strict
composer audit
vendor/bin/pint --test
npm run build
```

Before completion, inspect:

- route registration;
- policies and authorization boundaries;
- storage paths;
- ZIP extraction implementation;
- migration rollback behavior;
- supplier query scoping;
- no direct public storage exposure;
- no accidental modification of unrelated PO/GR or invoice invariants.

Do not claim completion based only on code inspection. Report the actual command results.

---

# Explicit Non-Goals

Do not:

- create a second Local PO/GR master;
- change the whole-GR settlement rule;
- make `qty` replace `received_amount` for invoice settlement;
- auto-create a PO from a filename;
- expose PO documents through public storage paths;
- authorize supplier access using Hashids alone;
- recursively extract nested archives;
- introduce browser automation/E2E as a mandatory completion gate;
- redesign the existing portal UI beyond the requested menus/fields/actions.
