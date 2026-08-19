# UI-03 Purchasing PR Pilot Result

Status: `PASS` (architecture gate) / `VISUAL_QA_BLOCKED`

Completed: `2026-08-20 00:26:16 +07:00`

## Scope completed

- Migrated the Purchasing PR index, create, edit, and detail views to the UI-01/UI-02 design foundation.
- Applied reusable page headers, breadcrumbs, cards, form controls, buttons, status chips, alerts, empty states, and table presentation shells.
- Preserved the existing server-side DataTables 1.13 engine and controller-provided action markup.
- Preserved the dynamic multi-row material table, sticky material/action columns, material-master search, shape-dependent dimensions, preview calculations, manual override flags, and row add/remove behavior.
- Restyled the supplier picker and Excel import surfaces while retaining Bootstrap modal/dropdown behavior and their jQuery hooks.
- Reorganized the PR detail page into semantic overview, material, quotation, action, chat, and timeline regions without changing access or workflow decisions.

## Contract audit

The following contracts remain in place:

- routes and canonical Hashid parameters for index/create/edit/show/submit/delete/export/comparison/quotation/chat;
- CSRF and `PUT`/`DELETE` method fields;
- `return_url`, `action`, and `supplier_selection_present` hidden values;
- `period_id`, `supplier_id`/`supplier_ids[]`, `notes`, and every nested `items[index][field]` name;
- item IDs, material-master IDs, HS-code/manual-source flags, all dimension source fields, quantity, weight/manual-source flags, and remarks;
- draft versus submitted action values and submit confirmations;
- draft-only import availability and rejected/final import restrictions;
- supplier picker all-suppliers fallback;
- DataTables filter/search/export propagation and delegated delete/quick-submit actions;
- quotation comparison threshold, creator-only edit behavior, and chat-start forms.

The current PR module has no polymorphic attachment upload field to migrate. The Excel control is a read-only preview/import workflow, not a PR attachment; its endpoint, limits, and apply modes remain unchanged.

## Verification

| Check | Result |
|---|---|
| `npm.cmd run build` | PASS — CSS `28.59 kB` (`6.32 kB` gzip), JS `95.72 kB` (`34.99 kB` gzip) |
| `php artisan view:clear; php artisan view:cache` | PASS |
| PR pilot relevant tests | PASS — 51 tests, 540 assertions |
| Full `php artisan test` | Baseline-equivalent — 204 passed, 1 known pre-existing failure, 2173 assertions |
| Known failure | `CustomAdasiAlertTest::test_export_confirmation_retains_the_single_download_guard`; unchanged from UI-00/UI-01/UI-02 baseline |
| `git diff --check` | PASS |
| Guarded backend/schema paths | PASS — none changed |
| Browser screenshots/responsive visual QA | BLOCKED — browser runtime unavailable; no screenshot or visual PASS claimed |

Relevant automated coverage included:

- `PurchaseRequisitionMaterialAutomationTest`
- `MissionFiveImportTest`
- `PrItemRemarkTest`
- `HashidUrlSecurityTest`
- `MaterialCalculationTest`
- `MissionFourExportTest`
- `NotificationDeliveryTest`

## Static regression audit

Compared with checkpoint `6c60943`:

- PR `data-bs-*` occurrences: `10 → 10`
- PR `.DataTable(...)` initializations: `1 → 1`
- PR inline `style=` occurrences: `38 → 19`
- new hardcoded hex colors: none
- new icon family: none
- changed controllers, models, routes, migrations, or database files: none

Compatibility intentionally retained:

- Yajra/server-side DataTables data contract and jQuery DataTables initialization;
- Bootstrap modal/dropdown behavior for supplier and import dialogs;
- jQuery-driven material calculation/search/import scripts;
- controller-rendered Bootstrap action/status markup;
- AdasiAlert/SweetAlert confirmation engine.

These are proven compatibility dependencies, not blockers for UI-04. Their removal would require a separate parity audit and, for server-side table replacement, possible backend approval.

## Design sources used

- The project brief controlled the pilot gate and feature-parity checklist.
- The loaded `design`, `ui-styling`, and `ui-ux-pro-max` guides informed hierarchy, responsive grids, accessible field relationships, focus treatment, dense-table handling, and primary-action discipline.
- Material Design 3 MCP guidance from UI-01/UI-02 informed surface levels, state layers, shapes, status treatment, and dialog/navigation semantics.
- The validated Coolors palette from UI-01 remains the only palette source.

## Gate decision

`UI-03 PASS`: the hybrid Tailwind presentation plus measured legacy-engine compatibility pattern is safe to reuse in UI-04. Visual QA remains deferred to UI-08 because the browser runtime is unavailable.
