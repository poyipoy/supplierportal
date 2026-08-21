# UI Checkpoint — QC

Status: implementation complete; `MANUAL_VISUAL_QA_REQUIRED`.

## 1. Files changed

- `app/Http/Controllers/Qc/QcExportController.php`
- `app/Http/Controllers/Qc/QcInspectionController.php`
- `resources/views/qc/dashboard.blade.php`
- `resources/views/qc/inspections/{create,index,show}.blade.php`
- `resources/views/pdf/qc-inspection-pdf.blade.php`

## 2. Key patterns introduced

- QC dashboard, inspection queue/history, inspection form, evidence sections, and detail summary use the shared hierarchy and restrained semantic colors.
- NG evidence inputs, IDs, names, validation behavior, and inspection workflow were retained.

## 3. Icon migration status

- QC views, notification metadata, and server-generated table actions contain no Bootstrap Icon references.
- Blade icons render through `<x-ui.icon>`; server-rendered action controls use visible text.

## 4. Microcopy standardization

- QC page titles, actions, evidence guidance, export feedback, table labels, and inspection PDF conclusions are professional English.
- QC, NG, PO, MTC, and other domain codes remain unchanged.

## 5. Scoped CSS consolidation

- Reusable tokens/components handle the common surfaces and feedback.
- Local form/detail styles remain because they are coupled to inspection rows, evidence previews, print output, and status-dependent interaction.

## 6. Toast migration

- Async export feedback uses the shared `AdasiToast` progress/completion flow.
- Field validation remains inline and blocking decisions remain on `AdasiAlert`.

## 7. Tests/build checks

- `php artisan view:cache`: passed after QC changes.
- Notification delivery focused tests passed for QC and claim lifecycle recipients.
- Final verification: Vite build passed through `npm.cmd run build`; the full suite passed 230 tests / 2415 assertions. The exact PowerShell launcher result is recorded in the final result.

## 8. Known risks

- Camera/file input rendering, NG evidence previews, chart canvas sizing, and printable PDF pagination require rendered/manual inspection.
- Controller edits are limited to UI strings, notification icon metadata, and action HTML presentation.

## 9. Manual visual QA

- `MANUAL_VISUAL_QA_REQUIRED`: QC dashboard chart, waiting/history DataTables, multi-item inspection form, NG evidence requirements, inspection detail, PDF output, and 390/768/992/1280+ responsive behavior.
