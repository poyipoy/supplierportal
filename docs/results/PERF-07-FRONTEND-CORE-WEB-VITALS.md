# PERF-07 — Frontend and Core Web Vitals

## Mission status

`REPOSITORY_IMPLEMENTATION_COMPLETE — MANUAL_CWV_VALIDATION_REQUIRED`

The safe source-level dependency work is complete. Browser automation was not run by instruction, so Lighthouse/Core Web Vitals and rendered interaction behavior remain `NOT MEASURED — ENVIRONMENT REQUIRED`.

## Scope and evidence

- Inspected the authenticated application layout, Vite entries, package manifests, public images, and all Blade call sites for DataTables, jQuery, SweetAlert, Chart.js, Bootstrap, and Axios.
- The application has 52 Blade entry views extending `layouts.app`; 13 initialize DataTables and 39 do not.
- Axios had one import/bootstrap assignment and no application request call site.
- jQuery remains load-bearing in 27 source/view files, including DataTables and legacy AJAX flows.
- SweetAlert/AdasiAlert contracts remain present in 18 files and are retained for blocking confirmations/prompts.
- Chart.js was already page-scoped to five chart pages and was not made global.
- The eager authentication background is a 2,087 × 1,080 JPEG of 396,086 bytes. Its browser decode/LCP effect was not measured, so no speculative visual asset rewrite was made.

## Findings and classifications

| Finding | Classification | Evidence / decision |
|---|---|---|
| Axios was bundled without a runtime call site | `VERIFIED BOTTLENECK` | Removing it reduced the production JS entry by about one half without changing request code. |
| DataTables CSS and two JS files loaded on every authenticated page | `VERIFIED BOTTLENECK` | Only 13 of 52 application entry views initialize a DataTable. |
| Bootstrap, jQuery, and SweetAlert are globally loaded | `NOT CURRENTLY JUSTIFIED` for removal | Repository-wide usage remains load-bearing for compatibility, dropdown/modal/offcanvas behavior, confirmations, and legacy AJAX. |
| Stable inline layout CSS/JS remains large | `OPTIMIZATION OPPORTUNITY` | Extraction could improve browser caching, but the blocks include Blade/config/auth-dependent behavior and were not moved without browser regression evidence. |
| Authentication hero-image and font impact on LCP | `LIKELY BOTTLENECK — MEASUREMENT REQUIRED` | File size is known; decoded/rendered timing is not. |
| LCP, INP, CLS, render-blocking waterfall, and mobile behavior | `NOT MEASURED — ENVIRONMENT REQUIRED` | Requires manual Lighthouse and rendered QA; no browser automation was performed. |

## Changes implemented

1. Removed the unused Axios import and npm dependency. Native `fetch` and existing jQuery AJAX remain the authoritative request paths.
2. Wrapped DataTables CSS/JS in a Blade `uses-datatables` page contract.
3. Enabled that contract for every current DataTables initializer, including the material-master page whose initializer lives in a partial.
4. Added regression coverage that renders a non-DataTables dashboard and a DataTables PR list, scans all Blade initializers for the opt-in contract, and prevents Axios from returning unnoticed.

Bootstrap, jQuery, SweetAlert, Alpine, prefixed Tailwind, Vite, existing CDN versions, and DataTables initialization markup were not otherwise changed.

## Production bundle before and after

| Asset | Before raw | After raw | Change | Before gzip | After gzip | Change |
|---|---:|---:|---:|---:|---:|---:|
| Vite JavaScript | 100,648 B | 51,762 B | −48,886 B (−48.6%) | 36,719 B | 18,541 B | −18,178 B (−49.5%) |
| Vite CSS | 51,602 B | 51,611 B | +9 B (+0.02%) | 10,112 B | 10,130 B | +18 B (+0.2%) |

For each of the 39 non-DataTables entry views, the initial document no longer requests the DataTables Bootstrap stylesheet or the two DataTables scripts. CDN transfer bytes were not fabricated because encoding, cache state, and edge response behavior vary by environment.

## Verification actually executed

- `php artisan test tests/Feature/FrontendAssetLoadingTest.php` — 3 tests, 25 assertions, passed.
- `npm.cmd run build` — passed with Vite 7.3.6; three modules transformed.
- `npm.cmd ls --depth=0` — passed; Axios and its now-orphaned dependency subtree are absent.
- Complete Blade initializer scan — 13 initializer entry views, all opted in.
- Production bundle raw/gzip sizes measured from `public/build/manifest.json` and generated assets.
- `php artisan view:cache` and broader regression suites are final PERF-09 gates.

## Manual validation required

Run on staging/production after deployment:

1. Lighthouse mobile and desktop for login, Purchasing Dashboard, Supplier Dashboard, PR list, PO list, and all three comparison pages.
2. Record LCP, INP, CLS, transferred bytes, request count, and the critical request waterfall with a cold cache and a repeat-view warm cache.
3. Confirm non-table pages make no DataTables requests; confirm all 13 table pages retain sorting, search, filters, pagination, processing state, and responsive horizontal scrolling.
4. Smoke-test Bootstrap dropdowns/modals/offcanvas, SweetAlert blocking confirmations, Alpine shell/toasts, jQuery AJAX, and the 2,000-point comparison chart.

Until those checks exist, the frontend mission is repository-verified but not Core-Web-Vitals-verified.
