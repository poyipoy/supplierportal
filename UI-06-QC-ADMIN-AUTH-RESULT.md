# UI-06 QC, Admin, and Auth Result

Date: `2026-08-20`

## Status

`PASS` for code, compile, and functional regression coverage.

`VISUAL_QA_BLOCKED`: no browser runtime was available. No screenshot, responsive, keyboard, focus, or visual PASS is claimed.

## Scope completed

The active QC, Admin, and authentication surfaces were migrated to the UI-01/UI-02 token and component foundation:

- QC dashboard, waiting/history tables, inspection entry, item status feedback, NG evidence, and inspection detail;
- Admin dashboard, users, exchange-rate history/input, announcements, authentication audit, material/HS Code administration, data-quality presentation, and requisition detail;
- login, forgot/reset/confirm password, secure-action continuation, email verification, MFA challenge, rate-limit response, registration template, MFA setup, and recovery-code views;
- the shared authentication layout was rebuilt as a neutral, restrained surface without the previous photo background, decorative gradients, glass effects, or continuous background motion.

## Security and workflow preservation

- Login, Turnstile, remember-me, password-reset, email-verification, password-confirmation, pending secure-action replay, MFA challenge/setup/recovery, logout, and rate-limit endpoints and field names are unchanged.
- The password-confirmation continuation form still replays the stored method and scalar inputs once, with a no-JavaScript submit fallback.
- The 429 response still exposes the retry countdown, safe return target, and scoped branded response while preserving its HTTP behavior.
- QC inspection item IDs, actual-measurement names, status switches, NG attachment rules, form routes, and JavaScript hooks are unchanged.
- Admin DataTables/AJAX IDs, HS Code modal and tab hooks, filters, routes, and CRUD form methods are unchanged.
- Exchange-rate creation remains append-only; no existing rate is overwritten.
- No route, controller, model, migration, database schema/data, authorization, translation, Hashid, or business-workflow change was made.

## Verification

| Check | Result |
|---|---|
| `git diff --check` | PASS |
| `php artisan view:clear` + `php artisan view:cache` | PASS |
| `npm.cmd run build` | PASS - CSS 33.69 kB / 7.20 kB gzip; JS 95.72 kB / 34.99 kB gzip |
| Auth feature suite | PASS - 74 passed, 13 existing assertion-less tests reported risky, 488 assertions |
| Admin material/HS Code feature and unit batch | PASS - 10 passed, 1 existing assertion-less test reported risky, 160 assertions |
| Supplier isolation + Hashid batch | PASS - 8 passed, 6 existing assertion-less tests reported risky, 128 assertions |
| Full `php artisan test` | 179 passed, 25 risky, 1 known pre-existing failure, 2182 assertions |

The sole full-suite failure remains `CustomAdasiAlertTest::test_export_confirmation_retains_the_single_download_guard`, which expects `window.exportConfirmationOpen` in `resources/views/layouts/app.blade.php`. UI-06 did not modify that layout or export guard.

## Static regression audit

Measured across the QC, Admin, authentication, shared auth layout, and two MFA profile views against checkpoint `d5bdfc1`:

| Pattern | Before | After | Result |
|---|---:|---:|---|
| `<x-ui.*` usage | 0 | 135 | Shared component patterns adopted |
| legacy card-class occurrences | 87 | 6 | Reduced; remaining instances are QC item-card/JavaScript compatibility |
| `<style>` blocks | 4 | 1 | Reduced to one scoped QC measurement grid |
| inline `style=` | 42 | 10 | Reduced; remaining uses are dynamic visibility, fixed chart/evidence dimensions, and table width compatibility |
| `data-bs-*` | 23 | 23 | Preserved for live modal/tab behavior |
| `.DataTable(` | 6 | 6 | Engine and callsites preserved |
| `new Chart(` | 2 | 2 | Engine and callsites preserved |
| auth photo-background references | 2 | 0 | Removed |
| decorative `linear-gradient` references | 11 | 0 | Removed |

- No literal hexadecimal colors remain in the migrated UI-06 Blade scope.
- Bootstrap Icons remains the only icon family.
- Guarded-path diff (`app`, `routes`, `database`, configuration, dependencies) is empty.
- No database command or mutation was run.

## Design guidance applied

The loaded `design`, `ui-styling`, `ui-ux-pro-max`, and `design-system` guidance was applied through role-specific hierarchy, task-focused actions, restrained surfaces/elevation, explicit labels, visible status feedback, accessible auth structure, responsive wrappers, and consistent component states. The ui-ux-pro-max guide content was applied directly; its absent local helper remains recorded as `GUIDE_HELPER_UNAVAILABLE`.

Material Design 3 and Coolors evidence remains recorded in UI-00/UI-01. No new MCP call or result is claimed for UI-06.

## Remaining compatibility dependencies

- Bootstrap remains required for active Admin/QC modal, tab, switch, and complex item-card behavior.
- jQuery remains required by DataTables and existing QC/Admin scripts.
- DataTables, SweetAlert/`AdasiAlert`, and Chart.js remain intentionally retained.
- One scoped QC style block and several compatibility-bound inline dimensions/visibility declarations remain for UI-07 audit.

## Next package

Proceed to `UI-07` compatibility cleanup. Remove only dependencies or legacy declarations proven unused; preserve live DataTables, SweetAlert, Chart.js, Bootstrap, and contract-bound JavaScript.
