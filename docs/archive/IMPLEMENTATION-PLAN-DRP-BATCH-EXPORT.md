# Implementation Plan — Export Data Batch DRP

**Repository:** `poyipoy/supplierportal`

**Feature:** Export Data Batch DRP Supplier ke Excel menggunakan template `DRP ADASI.xlsx`

**Primary domain:** Finance / Accounts Payable / Unified Payment Engine

**Status:** Implementation-ready plan; implementation agent wajib melakukan re-audit terhadap source code pada branch target sebelum mengubah kode.

---

## 1. Objective

Implement fitur export **Batch DRP Supplier** ke workbook Excel dengan layout yang mengikuti template bisnis `DRP ADASI.xlsx`, tanpa mengubah business logic DRP yang sudah ada.

Export harus menghasilkan data berdasarkan `PaymentBatch -> PaymentGroup -> PaymentItem -> LocalInvoice` sebagai source of truth. Export tidak boleh membangun ulang grouping supplier/rekening dari master vendor karena `PaymentGroup` sudah menyimpan snapshot data tujuan pembayaran saat batch dibuat.

Export harus terintegrasi dengan mekanisme asynchronous export yang sudah ada di project:

```text
Finance UI
  -> FinanceDrpController
  -> ExportDispatcher
  -> ProcessExportJob
  -> queue `exports`
  -> Excel/template generation
  -> FinalizeExportJob
  -> Export History / Download
```

Tidak menggunakan Browser QA. Verifikasi utama menggunakan targeted PHPUnit/feature tests dan validasi workbook hasil export.

---

## 2. Evidence From Current Codebase

Source yang telah diperiksa menunjukkan:

- `PaymentBatch` memiliki tipe `SUPPLIER` dan `GA`, status `DRAFT`, `FINALIZED`, `PARTIALLY_PAID`, `PAID`, `CANCELLED`, serta total `total_subtotal`, `total_bank_fee`, dan `total_net_amount`.
- `PaymentGroup` menyimpan snapshot `payee_name`, `bank_name`, `account_number`, `account_holder_name`, `subtotal_amount`, `bank_fee`, `net_payment_amount`, dan status group.
- `PaymentItem` adalah polymorphic dan saat ini menghubungkan `LocalInvoice` atau `GaClaim`; item memiliki status `ACTIVE` atau `REMOVED`.
- `LocalInvoice` memiliki `invoice_number`, `po_number`, `invoice_amount`, `tax_amount`, supplier relation, dan status payment readiness.
- DRP Supplier saat ini dibuat oleh `PaymentBatchService::createSupplierBatch()` dan grouping dilakukan berdasarkan supplier + active verified bank account.
- `PaymentGroup` menyimpan snapshot rekening/bank sehingga export historis harus membaca snapshot tersebut.
- `FinanceDrpController` belum memiliki route/action export DRP.
- `ExportDispatcher` memiliki allowlist `SUPPORTED_EXPORT_CLASSES`; export class baru wajib didaftarkan.
- Export operasional existing menggunakan asynchronous queue `exports`; template import adalah exception yang direct download.
- `ExportDownloadController` memiliki domain authorization tambahan yang saat ini mengenali `LocalInvoicesExport` secara khusus; DRP export perlu dipertimbangkan agar Finance dapat melihat/status/download export DRP tanpa membuka export domain lain.

---

## 3. Business Template Analysis

Template berisi sheet contoh: `01`, `02`, `03`, `04`, `05`, `09`.

Struktur sheet yang teramati:

```text
A:L

Row 1 : PT ASTRA DAIDO STEEL INDONESIA
Row 2 : REKAP PEMBAYARAN SUPPLIER
Row 3 : BULAN
Row 5 : MINGGU KE -N (DD/MM/YYYY) [opsional suffix -2/-3 untuk batch tanggal sama]
Row 7 : header tabel
Row 8+: data
      : continuation rows boleh ada pada kolom E (INVOICE)
        tanpa mengulang NO/KODE/supplier/bank/rekening
Row total : TOTAL PEMBAYARAN
Signature block : DIBUAT OLEH / DICEK OLEH / DIKETAHUI OLEH / DISETUJUI OLEH
```

Observed headers:

```text
NO | KODE | REFF | NAMA SUPPLIER | INVOICE | BANK | NO ACCOUNT | NAMA PENERIMA | NOMINAL | CEK | INPUT | APPROVE
```

Observed spreadsheet characteristics:

- Workbook menggunakan beberapa sheet.
- Logo/image object terdapat pada masing-masing sheet.
- Layout, merged cells, border, alignment, print layout, dan signature block harus dipertahankan.
- `NOMINAL` adalah numeric Excel value.
- Total pembayaran berada di kolom `I` dan secara bisnis setara dengan total nominal supplier pada sheet.
- `CEK`, `INPUT`, `APPROVE` kosong pada template contoh.
- `REFF` kosong pada template contoh.
- `KODE` mempunyai nilai supplier code pada data contoh, tetapi source code saat ini tidak menunjukkan master field `supplier_code` / `vendor_code` yang authoritative.
- Beberapa supplier memiliki invoice text yang melewati satu baris sehingga membutuhkan continuation rows.

---

## 4. Core Mapping

### 4.1 Mapping field

| Template | Source | Rule |
|---|---|---|
| `A: NO` | ordered active `PaymentGroup` | Sequential 1..N per sheet |
| `B: KODE` | No authoritative field currently found | Leave blank; do not derive from ID/submission number |
| `C: REFF` | No authoritative source currently found | Leave blank |
| `D: NAMA SUPPLIER` | `PaymentGroup.payee_name` | Use batch snapshot, not current supplier master |
| `E: INVOICE` | active `PaymentItem -> LocalInvoice.invoice_number` plus conservative display prefix | Include all active invoices belonging to the group |
| `F: BANK` | `PaymentGroup.bank_name` | Use batch snapshot |
| `G: NO ACCOUNT` | `PaymentGroup.account_number` | Use batch snapshot and preserve as text where necessary |
| `H: NAMA PENERIMA` | `PaymentGroup.account_holder_name` | Use batch snapshot |
| `I: NOMINAL` | `PaymentGroup.subtotal_amount` | Gross amount represented by template; do not substitute `net_payment_amount` |
| `J: CEK` | none | Blank |
| `K: INPUT` | none | Blank |
| `L: APPROVE` | none | Blank |

### 4.2 Why `subtotal_amount` is the nominal source

Current payment model is:

```text
subtotal_amount
    - bank_fee
    = net_payment_amount
```

The template has no bank-fee column. Therefore:

```text
Template NOMINAL = PaymentGroup.subtotal_amount
Template TOTAL   = sum(PaymentGroup.subtotal_amount)
```

This keeps the workbook consistent with the template semantics. `bank_fee` and `net_payment_amount` remain available in the application but are not forced into a column that does not exist.

### 4.3 Invoice description gap

The template's `INVOICE` column often contains a human description followed by invoice numbers, e.g. material/service description + `I - ...`.

The current `LocalInvoice` model does not expose a single authoritative field equivalent to that composite legacy description.

Conservative first implementation:

```text
INVOICE = "I - {invoice_number}"
```

When a payment group contains multiple invoices, concatenate them according to a deterministic order. Do not invent a description from unrelated PO/GR fields unless the business source is explicitly proven equivalent.

A future enhancement may introduce a dedicated display-description source, but that is outside this feature unless the current branch already contains one.

---

## 5. Batch and Sheet Semantics

### 5.1 Primary semantic rule

One `PaymentBatch` of type `SUPPLIER` is represented by one worksheet.

Therefore:

```text
PaymentBatch #1 -> Sheet 01
PaymentBatch #2 -> Sheet 02
PaymentBatch #3 -> Sheet 03
...
```

The example template contains several sheets because it represents multiple DRP batches, not because production must always contain exactly six sheets.

### 5.2 Export modes

Implement one export class capable of receiving one or more batch IDs.

Recommended UI behavior:

1. **Current batch export** from DRP batch detail page.
2. **Optional multi-batch export** from DRP Supplier list when selected batch IDs are provided.

The current-batch action is the minimum required feature. The underlying export class should nevertheless support multiple batch IDs because the template is multi-sheet and this avoids a second export architecture later.

### 5.3 Ordering

When multiple batches are exported:

```text
ORDER BY created_at ASC, id ASC
```

This makes sheet numbering deterministic.

The sheet display name should be `01`, `02`, `03`, etc. Excel limits sheet names to 31 characters, so numeric names are safe.

### 5.4 Batch eligibility

Only `PaymentBatch::TYPE_SUPPLIER` may be exported by this feature.

`GA` batch must be rejected server-side.

Cancelled batches should not be exported unless the business requirement explicitly changes. Default implementation:

```text
Allowed: DRAFT, FINALIZED, PARTIALLY_PAID, PAID
Rejected: CANCELLED
```

A batch with no active groups/items should fail cleanly rather than generating an apparently valid blank payment sheet.

---

## 6. Group and Item Inclusion Rules

### 6.1 Groups

Include:

```php
$group->status !== PaymentGroup::STATUS_CANCELLED
```

Do not regroup groups by current supplier or current bank account.

### 6.2 Items

Include only:

```php
$item->status === PaymentItem::STATUS_ACTIVE
&& $item->payable_type === LocalInvoice::class
```

Removed items must not appear in the export.

### 6.3 Invoice ordering

Within a payment group, order invoices deterministically, preferably:

```text
payment_items.id ASC
```

because that preserves their historical membership/order in the DRP container and does not depend on mutable invoice dates.

### 6.4 One group = one visible DRP row

The template's primary row is payment-recipient based, so one `PaymentGroup` produces one primary visible row.

When multiple invoices must overflow into continuation rows, only the `INVOICE` column is populated in continuation rows; the group-level columns remain blank.

Example:

```text
10 | S-XXX |   | Supplier ABC | I - INV001, INV002, INV003 | BCA | 123... | ABC | 15,000,000 |   |   |
   |       |   |              | INV004                    |     |         |     |           |   |   |
```

Do not repeat the nominal on continuation rows.

---

## 7. Template Strategy

### 7.1 Preserve the actual workbook template

Store the business template inside the application, for example:

```text
resources/templates/drp/DRP ADASI.xlsx
```

Use the uploaded `.xlsx` template as the source artifact after verifying that the committed repository copy is identical to the approved template.

Do not build the workbook from an empty spreadsheet if exact template fidelity is required.

### 7.2 Library strategy

The existing project already uses `maatwebsite/excel` for exports and `PhpSpreadsheet` underneath it.

For this particular feature, exact template preservation is the important requirement. The implementation may use PhpSpreadsheet workbook/template manipulation inside the already asynchronous export pipeline, while still using `ExportDispatcher` / `ProcessExportJob` as the application-level export transport.

Do not add a new Composer package unless the actual `composer.lock` proves the needed workbook functionality is unavailable.

### 7.3 Why not a normal `FromCollection + WithHeadings`

A standard export-generated workbook would recreate the workbook structure and would not reliably preserve:

- the existing logo/image objects;
- merged title cells;
- signature block layout;
- print settings;
- exact row/column formatting;
- template-specific spacing.

Therefore the template workbook should be loaded and populated rather than reconstructed from scratch.

---

## 8. Dynamic Row Management

The template uses variable-length data.

Implementation must support:

- a batch with one group;
- a batch with dozens/hundreds of groups;
- invoice continuation rows;
- total row moving down according to rendered data;
- signature block moving down with the total row;
- preservation of formatting when new rows are inserted.

### 8.1 Anchor model

Use these anchors from the approved template:

```text
Row 1 : Company title
Row 2 : Report title
Row 3 : Month
Row 5 : Period/week/date line
Row 7 : Table header
Row 8 : First data row
```

The renderer should locate the template's total row by its label `TOTAL PEMBAYARAN` rather than hardcoding one example row number.

Similarly locate signature labels/names by their existing positions or named/merged ranges if available.

### 8.2 Insertion policy

When output requires more rows than the selected template sheet currently contains:

1. Insert rows before the total/signature block.
2. Copy the formatting/merged-cell structure of the nearest data row into inserted data rows.
3. Keep the total/signature block below the generated data.
4. Reapply formulas/number formats where required.

When output needs fewer rows than the template example:

- clear surplus example data rows;
- keep the structural/signature block intact;
- do not leave example supplier data behind.

### 8.3 Formula strategy

The total `NOMINAL` cell should be formula-driven:

```excel
=SUM(I{first_data_row}:I{last_data_row})
```

Continuation rows with blank nominal values naturally do not affect the sum.

Do not hardcode the total if the cell is intended to remain a live Excel formula.

After writing the workbook, verify that the formula range is exactly the generated data range.

---

## 9. Header Rendering

Month:

```text
Carbon date -> uppercase Indonesian month
```

Example:

```text
09/2026 -> SEPTEMBER
```

Week line:

```text
week = ceil(day(created_at) / 7)
```

Examples:

```text
01/09/2026 -> MINGGU KE -1  (01/09/2026)
04/09/2026 -> MINGGU KE -1  (04/09/2026)
08/09/2026 -> MINGGU KE -2  (08/09/2026)
```

For multiple batches created on the same calendar date, preserve deterministic same-day ordering. If the approved business rule continues to use suffixes such as `-2`, `-3`, render them based on the ordinal batch of that date in the export set or, preferably, derive them from the ordered batches for that date rather than from an unrelated global counter.

Do not invent a different date source. Use the batch's `created_at` unless the business explicitly defines another DRP date field.

---

## 10. Sheet Naming and Collision Handling

Requested sheet names are numeric:

```text
01
02
03
...
99
```

For more than 99 batches, use zero-padded numeric names consistently, e.g. `100`.

No business data should be placed in the sheet name.

---

## 11. Security and Authorization

### 11.1 Request authorization

Route must be under the existing Finance middleware:

```text
auth + role:finance,admin
```

### 11.2 Object authorization

The server must verify every supplied batch ID:

- exists;
- is `SUPPLIER` type;
- is not `CANCELLED`;
- is accessible to Finance/Admin under the existing domain model.

Do not rely on a hidden form field, UI checkbox, or Hashid alone for authorization.

### 11.3 Hashids

If the export route uses `{batch}` route binding, preserve the existing `HasHashids` convention used by `PaymentBatch`.

For bulk IDs sent from the browser, decode/resolve server-side and validate ownership/role/domain before dispatch.

Do not put raw DB IDs into a public download path when the existing application contract expects hashids.

### 11.4 Formula injection

All user/business-controlled text written to cells must pass the project's existing spreadsheet sanitization policy where applicable.

At minimum inspect/use `SpreadsheetCellSanitizer` if its semantics cover this export. Cell values that start with formula-triggering characters must not become executable Excel formulas unless the cell is intentionally a formula controlled by the application.

Do not sanitize numeric/formula cells into strings in a way that breaks totals.

---

## 12. Async Export Integration

### 12.1 New export class

Recommended:

```text
app/Exports/PaymentBatchDrpExport.php
```

If separation is useful:

```text
app/Exports/PaymentBatchDrpExport.php
app/Exports/PaymentBatchDrpSheetRenderer.php
```

Keep workbook/template manipulation isolated from the controller.

### 12.2 ExportDispatcher allowlist

Add the new export class to:

```text
App\Support\ExportDispatcher::SUPPORTED_EXPORT_CLASSES
```

Without this, dispatch must fail and the export should not be reachable through the generic async engine.

### 12.3 Export arguments

Arguments must be JSON-serializable scalars/arrays only.

Preferred:

```php
[
    'batch_ids' => [123, 456],
]
```

or a positional array consistent with existing dispatcher conventions.

Do not pass Eloquent models into queued export arguments.

### 12.4 File name

Safe deterministic naming, for example:

```text
DRP_Supplier_20260923.xlsx
```

For a specific batch:

```text
DRP_{batch_number}.xlsx
```

Always rely on `ExportDispatcher::safeFileName()` for final sanitization.

---

## 13. Controller and Routes

Add a GET endpoint for export request. Recommended route shape for current batch:

```php
Route::get('/drp/{batch}/export', [FinanceDrpController::class, 'export'])
    ->name('drp.export');
```

Keep it after the specific/static routes and before any future conflicting wildcard if needed.

Controller responsibilities should remain thin:

1. validate/authorize batch;
2. construct scalar export arguments;
3. call `ExportDispatcher::dispatch()`;
4. return the standard async-export JSON/redirect contract already used elsewhere.

Do not query the entire payment graph in the controller for rendering.

For bulk export, use a validated array of batch IDs rather than accepting arbitrary user-supplied class names or export classes.

---

## 14. UI Changes

### Minimum UI

Add an **Export Excel** action to:

```text
resources/views/finance/drp/show.blade.php
```

It should export the currently displayed supplier batch.

The action should use the project's existing async-export UX rather than forcing a synchronous browser download.

### Optional bulk UI

On:

```text
resources/views/finance/drp/supplier.blade.php
```

add batch selection/export only if the product owner wants multi-batch export exposed immediately.

Do not implement a complex cross-pagination selection system just to satisfy the workbook's multi-sheet capability; the export engine itself should support multiple IDs even if the first UI exposes only one batch.

### No Browser QA

Do not introduce browser automation just for this feature.

---

## 15. Export History / Download Authorization

Inspect and update:

```text
app/Http/Controllers/ExportDownloadController.php
```

Requirements:

- Finance users can see their DRP export records.
- Finance users can see the resulting file status and download URL.
- DRP exports must not become downloadable by unrelated roles.
- Existing Local Invoice export restrictions must remain unchanged.
- Admin retains access according to existing policy.

Do not globally loosen:

```text
export_jobs.user_id
```

ownership enforcement.

---

## 16. Performance Considerations

The export may contain large payment batches, so avoid N+1 loading.

Recommended query graph:

```text
PaymentBatch
  with groups
       with active items
            with payable LocalInvoice
```

Also load only what is required for the workbook.

Do not call the following inside per-row loops without eager loading:

- supplier relation;
- invoice relation;
- bank account relation;
- voucher relation;
- payment relation.

The export must never perform current-bank-account lookup for each group because bank/account snapshots already exist in `payment_groups`.

If multi-batch exports are supported, use a bounded query strategy and avoid loading unrelated `GA` batches.

The existing async worker timeout is 600 seconds. Do not increase it merely because export implementation is inefficient. Fix query/workbook processing first.

---

## 17. Recommended Data Query

Conceptual query:

```php
PaymentBatch::query()
    ->where('batch_type', PaymentBatch::TYPE_SUPPLIER)
    ->whereIn('id', $batchIds)
    ->where('status', '!=', PaymentBatch::STATUS_CANCELLED)
    ->with([
        'groups' => fn ($q) => $q
            ->where('status', '!=', PaymentGroup::STATUS_CANCELLED)
            ->orderBy('id')
            ->with([
                'items' => fn ($q) => $q
                    ->where('status', PaymentItem::STATUS_ACTIVE)
                    ->where('payable_type', LocalInvoice::class)
                    ->orderBy('id')
                    ->with('payable'),
            ]),
    ])
    ->orderBy('created_at')
    ->orderBy('id')
    ->get();
```

This is a conceptual shape, not a license to copy it blindly. The implementation agent must verify relation names and whether the current branch provides constrained eager-load support exactly as expected.

---

## 18. Signature Block

The template contains a signature block with existing names.

For this feature:

- preserve the approved template signature text and layout if those names are formally part of the template;
- do not source signer names from arbitrary current user records;
- do not automatically insert the exporter's name into `DIBUAT OLEH` unless the business explicitly defines that behavior;
- do not copy signatures/images from an unrelated source file.

If the template's signer text is approved as static business content, keep it template-owned and unchanged.

---

## 19. Template Cleanup Rules

The uploaded workbook contains example data. All sample payment data must be cleared/replaced before production export.

The exporter must never accidentally leave:

- example supplier names;
- example invoices;
- example bank accounts;
- example nominal values;
- example date/week text;
- stale total formulas referencing deleted example rows.

Static formatting, logo, merged cells, and approved signature layout may remain.

---

## 20. Error Handling

Fail before queue dispatch for invalid request parameters where practical.

Examples:

```text
No batch selected
Unknown batch ID
GA batch requested through Supplier DRP exporter
Cancelled batch requested
Batch contains no active supplier payment items
Unsupported export class
Template file missing/unreadable
Workbook generation failure
```

The user should receive the standard async export error state rather than a raw stack trace.

Do not silently generate an empty workbook when the requested batch is invalid.

---

## 21. Regression Test Plan

### 21.1 Dispatcher tests

Extend existing async export coverage:

- DRP export class is supported by `ExportDispatcher`.
- Dispatch creates `export_jobs` record.
- Queue job targets queue `exports`.
- File name is sanitized.
- JSON arguments contain only scalar/array values.

### 21.2 Controller authorization tests

Add tests for:

- Finance can request export.
- Admin can request export.
- Supplier receives 403/route denial.
- Purchasing receives 403/route denial.
- GA receives 403/route denial.
- GA batch cannot be exported through Supplier DRP endpoint.
- Cancelled batch is rejected.
- Invalid batch ID is rejected.

### 21.3 Data integrity tests

Create a fixture containing:

```text
Batch A
  Group 1: Supplier A / BCA
    Invoice 1 ACTIVE
    Invoice 2 ACTIVE
  Group 2: Supplier B / Mandiri
    Invoice 3 ACTIVE
    Invoice 4 REMOVED
  Group 3: Supplier C / BCA
    CANCELLED

Batch B
  separate supplier groups
```

Assert:

- Batch A output has only active/non-cancelled groups/items.
- Removed Invoice 4 does not appear.
- Cancelled Group 3 does not appear.
- Batch B does not leak into Batch A sheet.
- Group order is deterministic.
- Invoice order is deterministic.

### 21.4 Snapshot correctness tests

After DRP creation, mutate the supplier's active bank account/master data.

Export the batch and assert that:

```text
bank_name
account_number
account_holder_name
payee_name
```

remain equal to `PaymentGroup` snapshots, not the newly changed master values.

### 21.5 Amount tests

For a group:

```text
subtotal = 10,000,000
bank_fee = 2,500
net = 9,997,500
```

Assert template column `I` contains:

```text
10,000,000
```

and the total formula is a SUM over visible nominal cells.

Do not map to `net_payment_amount`.

### 21.6 Continuation-row tests

Create a group with enough invoice display content to require continuation rows.

Assert:

- group-level columns appear only on the primary row;
- continuation invoice rows contain only invoice text;
- continuation rows have no duplicated nominal;
- total remains correct;
- signature block remains below total.

### 21.7 Template fidelity tests

Open/generated workbook and verify:

- expected sheet count;
- sheet names;
- title rows;
- header labels;
- static signature labels/names;
- logo/image/drawing survives where supported by the chosen template renderer;
- total formula exists and references the generated nominal rows;
- no `#REF!`, `#VALUE!`, `#NAME?`, or `#DIV/0!` in formula cells.

### 21.8 Multi-sheet tests

For 3 batch IDs:

```text
01 -> batch A
02 -> batch B
03 -> batch C
```

Assert deterministic order and independent totals.

### 21.9 Download authorization tests

Assert Finance can retrieve its completed DRP export and unrelated roles cannot access it.

---

## 22. Verification Protocol

Run only evidence-backed verification.

### Syntax / style

```bash
php -l app/Exports/PaymentBatchDrpExport.php
php -l app/Http/Controllers/Finance/FinanceDrpController.php
./vendor/bin/pint --test
```

Run PHPStan only if it is already part of the branch's normal verification contract and can be executed with the available environment.

### Targeted tests

Run the new/affected test files first, for example:

```bash
php artisan test tests/Feature/AsyncExportQueueTest.php
php artisan test tests/Feature/<DRP-export-test-file>.php
```

Then run the relevant Finance/payment test set:

```bash
php artisan test tests/Feature/FinanceVerificationV2Test.php
php artisan test tests/Feature/FinanceDrpPaidTest.php
php artisan test tests/Feature/UnifiedPaymentEngineTest.php
```

Finally, when the branch/project convention requires it:

```bash
composer test
```

### Workbook verification

The implementation must generate a real `.xlsx` artifact in a test context or controlled local runtime and inspect:

- sheet names;
- key cell values;
- formula cells;
- row placement;
- numeric cells;
- presence of the template structure.

Do not claim workbook fidelity based only on PHP tests that never open the workbook.

### Formula error scan

Search the generated workbook for:

```text
#REF!
#DIV/0!
#VALUE!
#NAME?
#N/A
```

---

## 23. Files Expected To Change

Expected minimal set, subject to codebase re-audit:

```text
app/Exports/PaymentBatchDrpExport.php
app/Http/Controllers/Finance/FinanceDrpController.php
app/Support/ExportDispatcher.php
app/Http/Controllers/ExportDownloadController.php
routes/finance.php
resources/views/finance/drp/show.blade.php

resources/templates/drp/DRP ADASI.xlsx

/tests/Feature/AsyncExportQueueTest.php
/tests/Feature/<new or existing DRP export test>.php
```

Additional helper/renderer files are acceptable only when they reduce coupling and remain directly tied to template export.

Do not refactor unrelated payment code.

Do not modify historical migrations unless the current codebase explicitly requires it and the evidence proves such an edit is safe. Prefer forward-only changes for schema additions.

---

## 24. Explicit Non-Goals

This feature does **not** include:

- new supplier-code master creation;
- changing DRP grouping rules;
- changing invoice amount/verification calculations;
- changing bank fee business rules;
- introducing a new payment engine;
- exporting GA claims through the Supplier DRP template;
- modifying settlement/payment lifecycle;
- changing voucher issuance rules;
- browser automation;
- synchronous long-running export requests;
- adding a new spreadsheet package without evidence of need.

---

## 25. Known Gaps / Decisions

### GAP-01 — Supplier `KODE`

Template contains supplier codes, but no authoritative `supplier_code`/`vendor_code` field was found in the current supplier/user/payment schema.

**Default implementation:** blank `KODE`.

Do not derive from integer IDs, submission numbers, hashids, or invoice numbers.

### GAP-02 — `REFF`

No authoritative `REFF` source was found in the current DRP/payment models.

**Default implementation:** blank `REFF`.

### GAP-03 — Description portion of `INVOICE`

Current `LocalInvoice` schema does not expose one authoritative composite description matching the legacy template text.

**Default implementation:** deterministic invoice-number presentation only, with no invented business description.

### GAP-04 — Signature ownership

Template includes static signer labels/names.

**Default implementation:** preserve approved template signature content; do not dynamically replace with exporter identity unless confirmed by business requirements.

---

## 26. Acceptance Criteria

Feature is considered complete only when all are true:

- Finance/Admin can initiate Supplier DRP export through the intended UI.
- Export request is asynchronous and uses the existing `exports` queue architecture.
- Export class is on the dispatcher allowlist.
- Only Supplier DRP batches are accepted by this exporter.
- Cancelled groups/items are excluded.
- Removed payment items are excluded.
- `PaymentGroup` snapshots are used for payee/bank/account information.
- `NOMINAL` equals `subtotal_amount`, not net payment.
- Multiple invoices in one payment group render deterministically, with continuation rows where necessary.
- Total formula matches the generated nominal data range.
- Example data from the template is fully replaced/cleared.
- Header month/week/date reflect the batch creation date.
- Template layout is preserved, including relevant drawings/signature structure.
- Export History/download authorization remains owner/domain scoped.
- Targeted tests pass.
- Generated workbook has no obvious formula errors.
- No unrelated business logic is changed.
- No unsupported claims about production readiness are made without runtime verification.

---

## 27. Implementation Sequence

### Phase 1 — Re-audit

Read the current branch source for:

```text
AGENTS.md
CLAUDE.md
context.md
FinanceDrpController
PaymentBatch
PaymentGroup
PaymentItem
LocalInvoice
ExportDispatcher
ProcessExportJob
ExportDownloadController
existing export tests
```

Verify exact relation names, current routes, current queue behavior, current branch HEAD, and whether a template storage convention already exists.

### Phase 2 — Template preparation

Add the approved `DRP ADASI.xlsx` to the repository at the agreed template path.

Verify that it contains no sensitive production transaction data beyond the approved business-template content.

### Phase 3 — Export renderer

Implement the template-based workbook renderer.

Keep business-data extraction separate from cell-writing logic where practical.

### Phase 4 — Queue integration

Register the export class with `ExportDispatcher` and make the controller dispatch it using scalar args.

### Phase 5 — Route + UI

Add Finance route and button using the existing async export UX.

### Phase 6 — Authorization / Download

Update `ExportDownloadController` as needed so Finance can see/download its DRP export while preserving existing export isolation.

### Phase 7 — Regression tests

Add targeted tests for authorization, data inclusion/exclusion, snapshot usage, amounts, continuation rows, multi-sheet behavior, and async lifecycle.

### Phase 8 — Verification

Run syntax/style, targeted tests, workbook inspection, formula scan, and relevant finance/payment regression tests.

### Phase 9 — Final diff review

Review `git diff` and verify:

- only intended files changed;
- no debug code;
- no hardcoded production transaction data;
- no accidental migration edits;
- no permission broadening;
- no unrelated UI refactor.

---

## 28. Final Engineering Rule

Follow the project engineering principle:

```text
Evidence -> Correctness -> Verification -> Simplicity -> Maintainability
```

The template is the visual/output authority. The current DRP payment domain is the data/business authority. When the template contains a field with no authoritative source in the current codebase, leave it blank rather than inventing a mapping.

Understand first -> change minimally -> verify explicitly.
