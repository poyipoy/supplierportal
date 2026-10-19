# app/Exports (+ app/Jobs export chain)

Operational exports never render inline. Before adding or changing a list export, read `docs/guides/ADVANCED-EXPORT-DEVELOPMENT.md`.

## Pipeline
`ExportDispatcher::dispatch()` (`app/Support`) → `ExportJob` row → `ProcessExportJob` on queue `exports` → either `GenerateWorkbookJob` (classes implementing `App\Contracts\GeneratesWorkbook`) or a chunked `Excel::queue` chain → `FinalizeExportJob` → private disk → polling (`exports.status`) → authorized `exports.download`.
- Handoff is atomic: the `ExportJob` row and the queued job share one DB transaction. `assertAtomicQueueConfiguration()` requires the `database` queue driver on the same connection with `after_commit=false` (`sync` is accepted in tests). Don't switch to dispatch-after-commit or Redis.
- A new export class must be added to `SUPPORTED_EXPORT_CLASSES` in `ExportDispatcher`. List exports also go in `LIST_EXPORT_CLASSES`, which enforces the `config/exports.php` limits (`max_rows`, `max_concurrent_per_user`).
- Constructor arguments must be scalar or JSON-serializable IDs and filters, never Eloquent models or closures. Don't add constructor args to existing exports; options flow through `App\Contracts\AcceptsExportOptions::applyOptions()`.
- Jobs carry the requester's locale and set it in the worker, so export text follows the user's language.

## Advanced Export (column picker, presets, XLSX/CSV)
- Implement `Advanced\ExportDefinition` (catalog via `Advanced\Concerns\UsesColumnCatalog`), put it in `Advanced/Definitions/`, and register its key in `App\Support\Export\ExportDefinitions`.
- The export key and audience are set by the controller, never by the request or role. Admin gets no automatic access.
- Advanced requests are POST on the same URI as the legacy GET export, and the GET must keep working.

## Safety
- Every text cell goes through `App\Support\SpreadsheetCellSanitizer` (formula injection), for both XLSX and CSV.
- Timestamps go through `BusinessTime`; pure date columns stay dates. Price/rate snapshots are never recalculated.
- Import templates (`*ImportTemplateExport`) are direct downloads and sit outside this pipeline.

Run locally: `php artisan queue:work database --queue=exports,default --tries=3 --timeout=600`.
Tests: `AsyncExportQueueTest`, `DetailExportSecurityTest`, `AdvancedExport*Test`, `ExportPresetTest`, `node --test tests/js/advanced-export-sdk.test.mjs`.
