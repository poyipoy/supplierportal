# Excel Operational Export Presentation — Result

Implemented on branch `master`, starting HEAD `33258819c6283de284eb9201a446caaadb7484a5`. Changes remain local and unstaged.

## Changes

- PR, PO, quotation, shipment, QC and supplier price history now share `StylesOperationalWorkbook` through `WithStyles`.
- Final header: solid `#9C4A0F`, white bold base 11 pt text, centred horizontally/vertically, **24-pixel vertical height (18 pt)**, single-line text (`wrapText=false`, `shrinkToFit=true`).
- Removed the incorrectly interpreted uniform 24-pixel horizontal override. Normal horizontal widths now come directly from legacy `columnWidths()` and the advanced catalog, in the range 10-24 native Excel width units. Selected/reordered advanced columns retain their per-key width and wrapping metadata.
- Added internal `ExportColumn.wrapText` metadata, default false; long supplier/material/specification/note/status/reference columns wrap and align to the top. No explicit data row height is imposed, so row height remains available to Excel's automatic layout.
- No auto-size, dataset scan for width calculation, retained Worksheet/callback state, new package, migration, route or database application change.
- Headings, mapped values/types, worksheet title, sanitisation, filters, authorisation and queue contracts preserved. CSV presentation returns no styles and retains existing width/CSV behavior.
- Local invoice, detail exports, import templates, DRP and transfer implementations were not modified. The shared catalog's new helpers have no effect on exporters that do not opt into the styling concern.

## Widths

**Final output: only the header's vertical height is fixed at 24 pixels.** Horizontal widths below are Excel character-based width units, not pixels. The approved plan distinguishes header height from column width, and data row height remains automatic.

The following widths are applied identically by legacy and Advanced Export, including reordered column subsets:

| Export | Widths |
|---|---|
| PR | 18, 16, 22, 24, 10, 14, 14, 14, 24, 18, 20 |
| PO | 18, 22, 22, 24, 10, 18, 18, 14, 24, 18 |
| Quotation | 18, 16, 22, 10, 22, 14, 12, 24, 12, 24, 18, 18, 16, 18, 24, 18, 20, 18, 16, 16, 18, 16, 18, 18 |
| Shipment | 18, 22, 24, 10, 10, 16, 14, 14, 14, 18, 24 |
| QC | 18, 22, 22, 24, 24, 10, 14, 20 |
| Price history monthly | 18, 14, 18, 18, 10, 12 |
| Price history yearly | 10, 18, 18, 18, 10, 12 |

## Verification

The approved header-height correction supersedes the earlier width interpretations. The RED check reproduced the former 56-pixel header height; final XLSX presentation verification passes with a 24-pixel header, normal horizontal widths, nowrap/shrink-to-fit and automatic data rows.

| Check | Result |
|---|---|
| `php artisan test tests/Unit/OperationalWorkbookPresentationTest.php --compact --do-not-cache-result` | Final header-height correction: 62 passed, 7,142 assertions. Covers EN/ID, legacy/advanced, empty/populated XLSX write/read, 24 px / 18 pt header, normal horizontal widths, nowrap/shrink-to-fit, automatic data rows, long text/large numbers, reordered subsets, byte-identical CSV/BOM, queue serialization and invoice exclusion. |
| `php artisan test tests/Feature/AdvancedExportBaselineTest.php tests/Feature/AdvancedPurchaseOrderExportTest.php tests/Feature/AdvancedExportPhaseSixTest.php --compact --do-not-cache-result` | 52 passed, 409 assertions. Real mapped rows/headings, owner/filter isolation, native queue CSV, formula protection, price history cache/serialization and unchanged invoice baselines passed. |
| TestingEnvironmentDatabaseSafetyTest | 2 passed, 4 assertions; live SELECT DATABASE confirmed `adasi_portal_test` before database-backed tests. Those tests ran serially. |
| `php artisan test tests/Feature/AdvancedPurchaseOrderExportTest.php --filter=test_advanced_xlsx_queue_preserves_normal_widths_and_compact_header --compact --do-not-cache-result` | 1 passed, 20 assertions. Advanced supplier XLSX POST goes through dispatcher, native queued sheet closure and finalizer (sync test queue), then the file is read back to verify 24 px header height, normal reordered widths, single-line header and automatic wrapped data rows. |
| PHP lint | All 15 changed/new PHP code/test files passed. |
| Scoped Pint | All 15 changed/new PHP code/test files passed. |
| `git diff --check` | Passed. |
| Representative workbook generation/read-back | Both EN/ID files generated through the real Maatwebsite Sheet open/close presentation lifecycle and read back successfully. Eight sheets per workbook; synthetic data only. |

The presentation tests deliberately validate the XLSX serialization itself rather than inspecting only returned style arrays. Golden/regression suites validate business mapping separately. No full suite or large XLSX benchmark was run for this presentation-only change. No Blade/CSS/JS changed, so no frontend build was required.

## Review examples

- [English workbook — 24-pixel header height](EXCEL-PRESENTATION-SAMPLES/operational-exports-header24px-en.xlsx)
- [Indonesian workbook — 24-pixel header height](EXCEL-PRESENTATION-SAMPLES/operational-exports-header24px-id.xlsx)

Older wide and narrow-column examples are retained only as historical review files. Use the new `header24px` files for the final accepted layout.

Each workbook includes PR, PO, quotation, shipment, QC, monthly/yearly price history, and a reordered PO subset. Rows are explicitly synthetic, not production data.

## Limits and preserved working tree

### Earlier worker diagnosis

The user's screenshot was confirmed against completed PO export 67: its columns were 70-168 pixels wide despite the source already containing the 24-pixel conversion. The local `artisan queue:work --queue=exports,default` process had started before that change and retained previously loaded exporter code.

- Sent a graceful `php artisan queue:restart` signal, waited for worker 28024 to exit, and started replacement worker 25712 with the same existing arguments and project working directory. The replacement was confirmed alive across the continuation turn.
- Existing completed export records/files were preserved. A separate private corrected snapshot retained every cell value and verified 24 pixels in all columns.
- A fresh PO workbook was also generated through the actual `PurchaseOrdersExport` and `Excel::raw` pipeline, using the completed record's original owner filters and re-authorised stored options. It contains 2,045 data rows; XLSX read-back confirmed A-J all 24 pixels and header colour `9C4A0F`. This verification reads application data and writes a separate private workbook, without adding an export record, changing domain data or sending a notification.
- No new UI-dispatched export has been observed after the reload yet. Previously downloaded/history files keep their original widths; a new export request uses the fresh worker. The fresh private review file is `storage/app/private/exports/4/67/summary_po_supplier_20261008_034356_fresh24px.xlsx`.

### Final header-height application verification

- Gracefully reloaded local worker 25712 after the header-height change, waited for exit, and started worker 13592 with the same `artisan queue:work --queue=exports,default` arguments. Confirmed the replacement alive and avoided a duplicate worker.
- Generated a separate private PO workbook through the actual `PurchaseOrdersExport` / `Excel::raw` pipeline, using completed record 69's original owner filters and re-authorised stored advanced options. XLSX read-back confirmed 2,045 data rows, header height 24 px, header colour `9C4A0F`, nowrap/shrink-to-fit, and normal horizontal widths A-J of `18,22,22,24,10,18,18,14,24,18`.
- Final real-data review file: `storage/app/private/exports/4/69/summary_po_supplier_20261008_040912_header24px.xlsx`. Application domain data and existing export records/files were preserved; this verification wrote only a separate private file. No new UI-triggered production export or Excel desktop interaction is claimed.
- Final scoped PHP lint/Pint and `git diff --check -- app/Exports tests/Feature/AdvancedPurchaseOrderExportTest.php tests/Unit/OperationalWorkbookPresentationTest.php UI-REDESIGN-RESULT/EXCEL-EXPORT-PRESENTATION-RESULT-20261008.md` passed. The whole-tree whitespace check now reports trailing whitespace in unrelated concurrent `context.md` changes (lines 3, 5, 6); those user documentation changes were preserved. AGENTS.md/CLAUDE.md/context.md changes and the untracked PDF remain outside this export task.

### Live Advanced Export output confirmation

After the user reported that Advanced Export was still unchanged, inspected actual application export records and their stored XLSX files without regeneration. Record 74 completed at `2026-10-08 04:44:31 UTC`. Its stored options explicitly include `audience=supplier`, `format=xlsx` and the ten default PO catalog keys, confirming it is Advanced Export rather than a separate legacy sample.

The original downloaded file `storage/app/private/exports/4/74/summary_po_supplier_20261008_044420.xlsx` has 2,045 data rows, header height **24 px**, fill `9C4A0F`, wrap disabled, shrink-to-fit enabled and normal horizontal widths `18,22,22,24,10,18,18,14,24,18`. Record 71 independently has the same presentation. The newest adjacent CSV records (72/73) cannot contain Excel styles or row heights; user format clarification was requested, without assuming CSV was the observed problem. No exporter behavior was changed during this diagnosis because the live Advanced XLSX output already satisfies the final header-height specification.

Excel desktop visual inspection, automatic wrapped-row sizing in Excel desktop, and production database-worker XLSX rendering have not been manually verified. Worksheet properties and file round-trips passed; no desktop-validation claim is made. Reload the existing `exports` workers during normal deployment so they load the new exporter code.

The initial dirty Purchasing ExportController, Supplier ExportController and MissionFourExportTest diffs remain unchanged. The supplied implementation plan remains untracked. An unrelated untracked `skills-lock.json` appeared during this task and was preserved. No staging, commit or push performed in this presentation task.
