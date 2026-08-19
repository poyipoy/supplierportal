# UI-07 Compatibility Cleanup Result

Date: `2026-08-20`

## Status

`PASS` for bounded compatibility cleanup, compile, and targeted regression coverage.

This package intentionally does not claim that Bootstrap or jQuery can be removed globally. Their active dependencies are recorded below.

## Cleanup completed

- Removed the duplicate DataTables stylesheet and two duplicate DataTables scripts from the Supplier price-history overview. The shared application layout remains the single runtime source for DataTables assets.
- Removed the now-redundant page-level DataTables CSS from that view; table header behavior already comes from the shared `ui-data-table` component styling.
- Moved the reusable QC specification/dimension grid rules from an inline Blade style block into `resources/css/app.css`.
- Replaced static inline presentation declarations with tokenized Tailwind utilities across Purchasing, Supplier, QC, Admin, and Profile views.
- Converted fixed table-column widths to compiled utility classes while preserving the exact dimensions.
- Kept the sole remaining inline style because its width is server-calculated runtime data for the PO document progress bar.
- Replaced inline supplier-field visibility with the semantic `hidden` property and kept the existing required-field behavior.
- Corrected the Admin user form helper from "Minimum 8 characters" to "Minimum 12 characters" so it matches the existing HTML constraint and backend password policy.

## Before/after inventory

Measured across 76 runtime Blade files under Purchasing, Supplier, QC, Admin, Auth, Profile, and the shared auth layout against checkpoint `be04e47`:

| Pattern | Before | After | Decision |
|---|---:|---:|---|
| Page-level DataTables CDN references | 3 | 0 | Duplicate CSS/JS removed; global layout remains authoritative |
| `<style>` blocks | 12 | 10 | Two redundant/scoped blocks removed |
| inline `style=` | 117 | 1 | Static presentation moved to utilities; dynamic progress width retained |
| `data-bs-*` | 80 | 80 | Retained because modal/tab/collapse/dropdown/tooltip callsites are active |
| jQuery call syntax | 523 | 523 | Retained for DataTables and complex legacy workflows not safely replaced here |
| `.DataTable(` | 16 | 16 | Required engine and callsites preserved |
| `new Chart(` | 7 | 7 | Required Chart.js callsites preserved |
| Font Awesome callsites | 0 | 0 | Bootstrap Icons remains the only icon family |
| hexadecimal-looking matches | 4 | 4 | All four are the escaped HTML entity `&#039;`, not hardcoded colors |

The ten retained style blocks support complex PR/quotation sticky tables, search overlays, import modals, historical chart layout, and responsive data-entry behavior. Removing them without browser coverage would be speculative and is outside this bounded cleanup.

## Compatibility decisions

- **DataTables:** retained and now loaded once by the application layout.
- **jQuery:** retained. It still powers DataTables plus active PR, quotation, PO, QC, HS Code, claim, and import workflows.
- **SweetAlert/AdasiAlert:** retained for confirmations, flash handling, and async workflows.
- **Bootstrap:** retained globally because modal, offcanvas, tab, collapse, dropdown, switch, and tooltip callsites remain non-zero.
- **Chart.js:** retained on the five chart-bearing views that load it on demand.
- **Alpine.js:** remains active for the application shell, auth password visibility, and new accessible UI primitives; no complex legacy workflow was force-rewritten.

No obsolete Bootstrap 4 `data-toggle`, `data-target`, or `data-dismiss` attributes were found in the audited runtime scope. No dead alternate icon-family callsite was found.

## Verification

| Check | Result |
|---|---|
| `git diff --check` | PASS |
| `php artisan view:clear` + `php artisan view:cache` | PASS |
| `npm.cmd run build` | PASS - CSS 35.22 kB / 7.54 kB gzip; JS 95.72 kB / 34.99 kB gzip |
| PR/import/quotation/PO/supplier isolation/Admin-HS targeted batch | PASS - 41 passed, 8 existing assertion-less tests reported risky, 464 assertions |
| Guarded-path audit | PASS - no app, route, database, config, translation, or dependency-file change |
| Database mutation | NONE |

## Design guidance applied

The loaded `design`, `ui-styling`, `ui-ux-pro-max`, and `design-system` guides informed the cleanup by keeping token ownership centralized, retaining meaningful responsive table widths, avoiding a risky framework purge, and favoring consistent utilities over isolated inline declarations. No new MCP call or result is claimed for UI-07.

## Next package

Proceed to `UI-08` visual QA and regression fixes. Retry the browser runtime once; if it remains unavailable, record `VISUAL_QA_BLOCKED`, perform the required static responsive/accessibility audit, and repair only evidence-backed issues.
