# UI Checkpoint — Admin

Status: implementation complete; `MANUAL_VISUAL_QA_REQUIRED`.

## 1. Files changed

- Controllers: `HsCodeRuleController.php`, `MaterialMasterController.php`, and `UserController.php` under `app/Http/Controllers/Admin/`.
- Views: announcements create/edit/index, admin dashboard, exchange-rate index, material/HS Code index and script, requisition detail, and users create/edit/index under `resources/views/admin/`.

## 2. Key patterns introduced

- Admin dashboard uses compact metrics, activity hierarchy, exchange-rate management, and restrained quick links.
- User/material/HS Code screens retain DataTables, modal targets, payload attributes, and action selectors while using explicit text actions.

## 3. Icon migration status

- Admin views and server-rendered DataTable action HTML contain no Bootstrap Icon references.
- Blade icons use `<x-ui.icon>`; runtime actions use accessible text labels.

## 4. Microcopy standardization

- Admin dashboard, user forms, material category, announcements, exchange rates, HS Code administration, and table actions use professional English.
- ADASI, HS Code, PR, and other domain identifiers remain unchanged.

## 5. Scoped CSS consolidation

- Shared tokens/components cover cards, data tables, fields, buttons, chips, and empty states.
- The material/HS Code script retains functional selectors and dynamic payload structure.

## 6. Toast migration

- Flash/transient feedback is delivered through the shared `AdasiToast` bridge.
- Deactivate/delete confirmations remain blocking `AdasiAlert` decisions.

## 7. Tests/build checks

- `php artisan view:cache`: passed after admin changes.
- `RenderedComponentTest`: 7 passed, 58 assertions.
- Final verification: Vite build passed through `npm.cmd run build`; the full suite passed 230 tests / 2415 assertions. The exact PowerShell launcher result is recorded in the final result.

## 8. Known risks

- DataTables responsive behavior and edit/toggle/delete controls require authenticated manual testing.
- Controller edits are presentation-only action markup; authorization, validation, and data semantics remain unchanged.

## 9. Manual visual QA

- `MANUAL_VISUAL_QA_REQUIRED`: dashboard, exchange-rate modal/table, user DataTable and forms, announcements, material/HS Code tables/modals, read-only requisition detail, and 390/768/992/1280+ layouts.
