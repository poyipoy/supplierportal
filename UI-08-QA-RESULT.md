# UI-08 Visual QA and Regression Fix Result

Date: `2026-08-20`

## Status

`PASS` for the browser-independent UI-08 gate: static responsive/accessibility audit, evidence-backed repairs, Blade compilation, production asset build, targeted functional regression tests, and unauthenticated HTTP smoke checks.

`VISUAL_QA_BLOCKED` remains explicit. No screenshot, rendered breakpoint, keyboard-navigation, focus-order, chart-rendering, or visual PASS is claimed.

## Browser and visual QA evidence

- The in-app browser setup was retried against `http://adasi_portal_supplier.test`.
- Browser acquisition returned `No browser is available`.
- The documented runtime diagnostic `agent.browsers.list()` returned an empty list (`[]`).
- Screenshot matrix executed: **no**.
- Screenshot paths: **none**.
- Rendered viewports verified: **none**.

This is a platform/runtime limitation rather than evidence that the UI passes visual QA.

## Static responsive audit

| Target | Code-level evidence | Result boundary |
|---|---|---|
| 390 px | The shell uses its mobile sidebar, inert hidden navigation, scrim, single-column utility defaults, and horizontally scrolling data-table wrapper. Compact topbar identity is hidden below the existing 768 px boundary. | Static only |
| 768 px | Bootstrap `md` grids can expand while the application shell remains in mobile navigation mode; tables continue to use the shared overflow wrapper. | Static only |
| 992 px | CSS uses `max-width: 992px`, Alpine treats desktop as `window.innerWidth > 992`, and the Tailwind `shell` breakpoint begins at `993px`. The exact shell boundary is aligned. | Static only |
| 1280 px+ | Desktop sidebar/collapse behavior and the `lg`/`xl` multi-column layouts are present; wide PR and quotation tables retain explicit horizontal scrolling. | Static only |

The shared `x-ui.data-table` component wraps its slot in `tw-overflow-x-auto`. Specialized raw tables use existing responsive scroll containers. These checks validate code structure, not rendered geometry.

## Issues found and repaired

### BLOCKER

- `VISUAL_QA_BLOCKED`: no browser instance exists, so the required screenshot matrix cannot be executed in this environment. This cannot be repaired inside the repository.

### MAJOR

- Added accessible names and close-button labels to six Bootstrap modal surfaces that were missing a complete dialog relationship.
- Added text alternatives and `role="img"` to all seven Chart.js canvas callsites.
- Added keyboard focus trapping and focus return to the custom drawer and mobile navigation drawer; the existing custom dialog focus trap remains intact.
- Replaced remaining active compatibility-layout color literals with the existing MD3 semantic roles. RGB aliases were added only for existing surface/background/scrim tokens that need alpha composition.

### MINOR

- Completed ARIA relationships for the QC, claim, and material/HS tab sets.
- Associated QC evidence upload fields, historical dimension filters, exchange-rate fields, Admin user fields, QC actual-value fields, and dynamic PR row controls with labels or accessible names.
- Added progressbar bounds/current-value semantics and synchronized `aria-valuenow` after PO document status updates.
- Replaced `javascript:` import/cancel links with semantic buttons or a real route.

### POLISH

- No repository-only polish item was promoted without rendered evidence. Spacing, wrapping, truncation, density, and perceived hierarchy remain part of the blocked browser review.

## Static audit evidence

| Audit | Result |
|---|---|
| Active modal, close button, canvas, image, tab, and tabpanel scan | PASS across 98 active/shared Blade files |
| Literal label target scan | PASS |
| Non-hidden input/select/textarea explicit ID or accessible-name scan | PASS |
| `href="javascript:..."` in active UI scope | 0 |
| Active un-tokenized hex/RGB color lines outside token declarations | 0 |
| Mobile/custom drawer focus trap and focus-return implementation | Present |
| Data-table horizontal overflow pattern | Present in shared component and specialized table wrappers |
| `git diff --check` | PASS |

These are bounded static checks and are not a WCAG conformance claim.

## Build, test, and smoke evidence

| Check | Result |
|---|---|
| `php artisan view:clear` + `php artisan view:cache` | PASS |
| `npm.cmd run build` | PASS — CSS 35.31 kB / 7.55 kB gzip; JS 96.11 kB / 35.11 kB gzip |
| Admin HS, import, supplier isolation, detail export, and PO-reference targeted tests | PASS — 28 passed, 7 existing assertion-less tests reported risky, 343 assertions |
| `GET http://adasi_portal_supplier.test/login` | HTTP 200; built CSS and JS tags present |
| Built CSS asset request | HTTP 200; 35,305 bytes |
| Built JS asset request | HTTP 200; 96,114 bytes |

## Design-guide and MCP provenance

- The loaded `design`, `ui-styling`, `ui-ux-pro-max`, and `design-system` guides informed the static accessibility, focus, responsive overflow, token-ownership, and issue-severity decisions in this package.
- The `ui-ux-pro-max` referenced search helper remains unavailable; its loaded guide rules were applied directly (`GUIDE_HELPER_UNAVAILABLE`).
- No new Material Design 3 or Coolors MCP result is claimed for UI-08. Repairs reuse the MD3 terminology, state/focus guidance, semantic roles, and contrast-checked palette established with actual MCP calls in UI-00/UI-01.

## Guardrails

- Routes/controllers/models/migrations/database schema/data: not changed.
- Hashid, translation, authorization, and business workflow logic: not changed.
- Database mutation: none.
- DataTables, SweetAlert, Chart.js, Bootstrap compatibility, and required jQuery callsites: retained.

## Remaining UI-08 limitation

Run the authenticated screenshot and interaction matrix on a machine where an in-app browser is available. At minimum, cover Admin, Purchasing, Supplier, QC, and Auth at 390, 768, 992, and 1280 px, including real data, empty/error/loading states, modal/drawer focus, validation, table controls, charts, uploads, and role-specific action visibility.
