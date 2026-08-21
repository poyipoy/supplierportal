# UI Checkpoint — Purchasing

Status: implementation complete; `MANUAL_VISUAL_QA_REQUIRED`.

## 1. Files changed

- Controllers: `ConversationController.php`, `ExportController.php`, `MaterialClaimController.php`, `PeriodController.php`, `PoDocumentController.php`, `PriceComparisonController.php`, `PurchaseOrderController.php`, `PurchaseRequisitionController.php`, `PurchasingController.php`, `QuotationListController.php`, and `ReportController.php` under `app/Http/Controllers/Purchasing/`.
- Claims: `resources/views/purchasing/claims/{create,index,show}.blade.php`.
- Price comparison: `resources/views/purchasing/comparison/{historical,inter-supplier,vs-best}.blade.php`.
- Requisitions: `resources/views/purchasing/pr/{create,edit,index,show,_import,_import_controls,_item_row,_supplier_picker_modal}.blade.php`.
- Purchase orders: `resources/views/purchasing/po/{create,index,show}.blade.php`.
- Quotations and supporting screens: `resources/views/purchasing/quotations/{index,show}.blade.php`, `dashboard.blade.php`, `periods/index.blade.php`, `conversations/index.blade.php`, and `reports/index.blade.php`.

## 2. Key patterns introduced

- Content-first page headers, compact metrics, restrained operational alerts, clearer primary/secondary actions, and dense comparison/data-table treatment.
- Purchasing dashboard action checks no longer use decorative icon circles, hover lift, or arbitrary shadows.
- Existing DataTables IDs/classes, Bootstrap modal markup, forms, routes, and AJAX-bound DOM structure were retained.

## 3. Icon migration status

- Purchasing views and controller-rendered action markup contain no Bootstrap Icon references.
- View icons render only through `<x-ui.icon>`; dynamic table actions use explicit text where server-side Blade rendering is unavailable.

## 4. Microcopy standardization

- Purchasing navigation, filters, date-range labels, exports, comparison labels, empty states, action feedback, and PDF-facing copy were standardized to professional English.
- PR, PO, HS Code, QC, MTC, ADASI, and business codes remain unchanged.

## 5. Scoped CSS consolidation

- Duplicate historical-price underline styles were replaced by the shared utility pattern.
- Local CSS remains only where it is tightly coupled to PR material-entry tables, import controls, comparison charts, or JS-bound PO/document layouts.

## 6. Toast migration

- PR import and PO document success feedback now call `AdasiToast` directly.
- Confirmation, destructive decisions, prompts, and blocking errors remain on `AdasiAlert`.

## 7. Tests/build checks

- `php artisan view:cache`: passed after the module changes.
- `PrItemRemarkTest`: 4 passed, 43 assertions.
- Final verification: Vite build passed through `npm.cmd run build`; the full suite passed 230 tests / 2415 assertions. The exact PowerShell `npm run build` launcher limitation is recorded in the final result.

## 8. Known risks

- Historical comparison and PO detail screens deliberately retain JS-bound Bootstrap structure; changing those hierarchies would risk selectors, charts, document updates, and DataTables behavior.
- Controller changes are presentation-only strings/icons; query, workflow, authorization, and validation semantics were not changed.

## 9. Manual visual QA

- `MANUAL_VISUAL_QA_REQUIRED`: dashboard density, PR create/edit table horizontal behavior, supplier picker modal, import previews, comparison charts/tables, quotation review, PO timeline/documents, claim screens, and 390/768/992/1280+ viewport behavior.
