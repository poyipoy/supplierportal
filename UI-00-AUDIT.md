# UI-00 Frontend Audit and Baseline

## Package status

- Status: `PASS`
- Completed at: `2026-08-19 23:43:02 +07:00`
- Active branch: `feature/full-redesign-ui-ux`
- Start commit: `32f1d25aeffb0d74e6b5f3da269c2f0fd37d864a`
- Worktree before the first redesign edit: clean
- Visual baseline: `VISUAL_QA_BLOCKED` because the in-app browser runtime reported that no browser was available
- Database mutation: none

The audit is sufficient to map a safe implementation path. No hard-stop condition was found. Browser-derived DOM, request-count, interaction-timing, and screenshot baselines remain unavailable and must not be inferred from static checks.

## Sources and constraints loaded

- `FULL-UI-REDESIGN-BRIEF-LONG-HORIZON.md` was read completely.
- `AGENTS.md` was read completely.
- No earlier `UI-REDESIGN-PROGRESS.md` or `UI-*-RESULT.md` existed at recovery time.
- The `design`, `ui-styling`, `ui-ux-pro-max`, and routed `design-system` guides were loaded and applied.
- The ui-ux-pro-max guide's referenced `.agents/skills/ui-ux-pro-max/scripts/search.py` helper is absent in this checkout. This is recorded as `GUIDE_HELPER_UNAVAILABLE`; the guide itself is available and its embedded accessibility, density, responsive, form, navigation, chart, and motion rules remain applicable.
- Material Design 3 MCP became available after the permission-mode change.
- Coolors MCP is available.

Project-specific safety rules override general design guidance. The redesign remains presentation-only: no routes, controllers, models, migrations, schema/data, Hashid behavior, translation logic, authorization, supplier isolation, or business workflow may change.

## Repository and page inventory

| Item | Current state |
|---|---:|
| Blade views | 108 |
| Laravel routes | 169 |
| Admin views | 14 |
| Auth views | 9 |
| Existing Blade components | 16 |
| Purchasing views | 25 |
| QC views | 4 |
| Supplier views | 15 |
| Profile views | 8 |
| Layouts | 4 |
| Shared partials | 4 |

Primary layout sizes:

| File | Lines | Bytes |
|---|---:|---:|
| `resources/views/layouts/app.blade.php` | 2169 | 70942 |
| `resources/views/layouts/auth.blade.php` | 313 | 9952 |
| `resources/views/layouts/guest.blade.php` | 41 | 1858 |
| `resources/views/partials/sidebar.blade.php` | 164 | 10276 |
| `resources/views/partials/navbar.blade.php` | 364 | 19526 |
| `resources/views/partials/chat-drawer.blade.php` | 741 | 42117 |

The authenticated shell is a high-risk shared file. It combines tokens, global styling, compatibility CSS, dependency loading, and JavaScript behavior, so it must be migrated in small verified batches.

## Frontend toolchain

| Area | Current state | Audit decision |
|---|---|---|
| Templates | Laravel Blade | Retain; migrate toward `x-ui.*` components |
| Build | Vite 7.3.6 through `npm run build` | Retain |
| Tailwind | Config declares `^3.1.0`; installed version is 3.4.19 | Keep Tailwind 3 during this mission |
| Tailwind 4 package | `@tailwindcss/vite` 4.x is installed but not wired into Vite | Do not switch major versions during redesign; record dependency mismatch |
| PostCSS | Tailwind + Autoprefixer | Retain |
| Alpine | Installed and started by `resources/js/app.js` | Activate in app/auth layouts through Vite |
| Bootstrap | 5.3.3 CSS/JS via CDN | Compatibility dependency; remove only at zero required callsites |
| Bootstrap Icons | 1.11.3 via CDN | Keep as the single compatibility icon library for this run |
| jQuery | 3.7.1 via CDN | Retain for DataTables and remaining proven callsites |
| DataTables | 1.13.6 with Bootstrap 5 adapter | Retain + restyle |
| SweetAlert2 | 11.7.32 | Retain + restyle |
| Chart.js | CDN references in five views, some unpinned | Retain; no chart-engine migration |

Important asset-delivery gap: only `resources/views/layouts/guest.blade.php` currently loads `@vite`. The authenticated app and auth layouts therefore do not load the configured Tailwind CSS or Alpine entry despite both being installed. UI-01 must add the Vite entry to those layouts while disabling Tailwind preflight and prefixing utility classes so legacy Bootstrap pages remain stable.

## Existing token foundation

`resources/views/layouts/app.blade.php` already contains the design source seeds and semantic roles. They must be extended, not regenerated:

| Role | Main | On main | Container | On container |
|---|---|---|---|---|
| Primary | `#1F5FA6` | `#FFFFFF` | `#B9E3FF` | `#001E59` |
| Secondary | `#535E78` | `#FFFFFF` | `#D7E2FF` | `#0E1C31` |
| Error | `#B12B21` | `#FFFFFF` | `#FFB79B` | `#590000` |
| Success | `#198754` | `#FFFFFF` | `#E1F3EA` | `#14532D` |
| Warning | `#B7791F` | `#111827` | `#FBF0DD` | `#5F3D08` |

Existing neutral roles include surface, three surface-container levels, background, outline, outline-variant, on-surface, and on-surface-variant. Existing foundations also include three elevations, 4/8/12/16/full shapes, and state opacities for hover/focus/pressed/disabled. There are 417 `--md-*` references across 15 source files.

The design-system guide's three layers will be applied conservatively:

1. Existing seed/tonal values remain primitive reference tokens.
2. Existing `--md-*` purpose roles remain the semantic source of truth.
3. New `--ui-*` component tokens reference the semantic layer instead of duplicating raw colors.

Tailwind must map to CSS variables, not duplicate hexadecimal values in `tailwind.config.js`.

## MCP evidence

### Material Design 3 MCP

Actual pages retrieved:

- `/styles/color/roles`
- `/foundations/interaction/states/state-layers`
- `/styles/elevation/applying-elevation`
- `/styles/shape/corner-radius-scale`
- `/components/buttons/guidelines`
- `/components/text-fields/accessibility`
- `/components/navigation-drawer/guidelines`
- `/components/dialogs/accessibility`

Applied guidance:

- Pair container roles only with their intended `on-*` roles.
- Use surface/container tonal differences before adding heavy shadows.
- Use state-layer opacities of 8% hover, 10% focus, 10% pressed, and 16% dragged.
- Keep high elevation for interaction or truly floating surfaces.
- Use modest shape tokens for information-dense enterprise components.
- Keep one high-emphasis filled action per page or section.
- Input boundaries need at least 3:1 non-text contrast where the outline is required to identify the field.
- Dialogs require initial focus, keyboard cycling, Escape dismissal where safe, labels, and focus return.
- Preserve the existing 992px shell contract: a standard drawer at larger widths and modal behavior below the boundary.

### Coolors MCP

Actual operations performed:

- Generated HCT tonal scales from the five existing seeds without replacing the semantic seed values.
- Checked all intended semantic foreground/background pairs with WCAG 2.x and APCA.
- Darkened the current outline experimentally and validated a separate strong-outline candidate.
- Audited the five semantic/chart colors for protanopia, deuteranopia, and tritanopia distinguishability.

WCAG 2.x results for actual semantic pairs:

| Pair | Ratio | WCAG AA normal text |
|---|---:|---|
| on-primary / primary | 6.47:1 | PASS |
| on-primary-container / primary-container | 11.65:1 | PASS |
| on-secondary / secondary | 6.48:1 | PASS |
| on-secondary-container / secondary-container | 13.19:1 | PASS |
| on-error / error | 6.49:1 | PASS |
| on-error-container / error-container | 8.77:1 | PASS |
| on-success / success | 4.53:1 | PASS |
| on-success-container / success-container | 7.90:1 | PASS |
| on-warning / warning | 4.87:1 | PASS |
| on-warning-container / warning-container | 8.63:1 | PASS |
| on-surface / surface-container | 13.35:1 | PASS |
| on-surface-variant / surface-container | 4.61:1 | PASS |

APCA is retained as an advisory signal because it remains a draft algorithm. `on-success`, `on-warning`, and `on-surface-variant` meet the mission's required WCAG 2.x AA threshold but do not meet APCA's body-text threshold in every pairing. They should be used for short labels/content according to their intended roles, not long low-emphasis prose.

The existing `#CBD5E1` outline is only 1.48:1 on white and must not be the sole boundary for an outlined form control. Coolors produced and validated `#748EAF` as a new strong-outline candidate: 3.37:1 on white, 3.22:1 on surface-container-low, and 3.08:1 on surface-container. Decorative dividers may continue using outline-variant because they are not the sole indicator of an interactive boundary.

The five tested semantic colors remained distinguishable at the configured Delta E threshold under protanopia, deuteranopia, and tritanopia simulations. UI status and chart meaning must still include text, labels, icons, shapes, or legends instead of relying on color alone.

## Legacy dependency inventory

Counts use source-only `rg` scans over Blade, CSS, and JavaScript. They are an engineering inventory, not proof of runtime execution.

| Dependency/pattern | Matches | Files | Decision |
|---|---:|---:|---|
| `data-bs-*` | 67 | 27 | Reduce only on migrated pages |
| jQuery call syntax | 512 | 25 | Retain proven compatibility callsites |
| DataTables references | 32 | 16 | Retain + restyle |
| SweetAlert/AdasiAlert | 54 | 18 | Retain + restyle |
| Bootstrap Icons | 432 | 74 | Keep as single icon family |
| Chart.js | 12 | 5 | Retain |
| Inline `style=` | 273 | 70 | Reduce in migrated scope |
| `<style>` blocks | 16 | 16 | Consolidate where safe |
| Raw hex literals | 320 | 13 | Classify token definitions, email/PDF exceptions, then remove migrated UI literals |
| Raw rgb/rgba functions | 77 | 11 | Classify token/state/shadow use before cleanup |

DataTables has 14 actual initializations and every one is configured for server-side processing. Selectors and endpoint/data contracts must remain stable:

- Admin: auth audit, users, material master, HS-code rules.
- Purchasing: periods, PO, PR, vs-best comparison, claim action/history.
- QC: waiting and history.
- Supplier: claims, quotation periods, PO, and price history.

High-risk jQuery areas are the PR multi-row form/import/supplier picker, PO create/show, QC inspection create, material/HS-code admin script, and DataTables event hooks. These are not candidates for blind global replacement.

Chart.js views:

- `resources/views/purchasing/dashboard.blade.php`
- `resources/views/purchasing/comparison/historical.blade.php`
- `resources/views/purchasing/comparison/inter-supplier.blade.php`
- `resources/views/qc/dashboard.blade.php`
- `resources/views/supplier/price-history/historical.blade.php`

## Existing components and compatibility strategy

The repository has 16 Breeze/project components, including button variants, modal, input label/error/text input, breadcrumb, empty state, status badge, navigation links, and dropdowns. They are used unevenly and do not yet form the requested `x-ui.*` API.

UI-02 will add a bounded `resources/views/components/ui/` library and keep thin compatibility components where existing callsites would otherwise require risky mass edits. Components must remain presentation-only and forward attributes, names, ARIA/data hooks, and existing selectors.

Bootstrap Icons remain the only icon library during this run. Adding Material Symbols, Font Awesome, Phosphor, or Heroicons would increase fragmentation and contradict the project-specific icon rule.

## Baseline verification

| Command/check | Result | Evidence |
|---|---|---|
| `npm.cmd run build` | PASS | Vite 7.3.6; CSS 51.56 kB (8.82 kB gzip); JS 94.78 kB (34.73 kB gzip); manifest 331 bytes |
| `php artisan view:cache` | PASS | Blade views compiled and cached |
| `php artisan test` | BASELINE WITH 1 KNOWN FAILURE | 204 passed, 1 failed, 2101 assertions, 59.61s |
| HTTP `/login` | PASS | 200; 13242 bytes |
| HTTP `/assets/css/adasi-alert.css` | PASS | 200; 9120 bytes |
| HTTP `/build/manifest.json` | PASS | 200; 331 bytes |

The sole test failure is pre-existing and already documented in `MISSION-MD3-DESIGN-SYSTEM-RESULT.md`:

`Tests\Feature\CustomAdasiAlertTest::test_export_confirmation_retains_the_single_download_guard`

It expects `window.exportConfirmationOpen` in `resources/views/layouts/app.blade.php`. UI packages must not create additional failures, and this unrelated baseline failure is not silently repaired in UI-00.

`phpunit.xml` explicitly targets the isolated `adasi_portal_test` MySQL database with array cache/session and sync queue. No migration or database mutation was executed.

## Visual and performance baseline limitation

The in-app browser setup completed, but the runtime returned `No browser is available` and the browser list was empty. Therefore:

- no screenshots were captured;
- no breakpoint was visually verified;
- no keyboard/focus behavior was browser-verified;
- no DOM-node, initial request-count, long-task, or post-response render metrics were captured;
- no before/after performance claim is permitted.

Static responsive auditing, build sizes, HTTP smoke checks, and functional tests can continue. Browser availability will be retried once during UI-08.

## Exact work-package file map

### UI-01 — Foundation

- `tailwind.config.js`
- `resources/css/app.css`
- `resources/js/app.js` only if a foundation interaction requires it
- `resources/views/layouts/app.blade.php`
- `resources/views/layouts/auth.blade.php`
- `resources/views/layouts/guest.blade.php` only for shared foundation alignment

### UI-02 — Components and shell

- new `resources/views/components/ui/**/*.blade.php`
- compatibility updates under `resources/views/components/*.blade.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/partials/sidebar.blade.php`
- `resources/views/partials/navbar.blade.php`
- `resources/views/partials/chat-drawer.blade.php`
- `resources/views/partials/alerts.blade.php`

### UI-03 — Purchasing PR pilot

- all ten files under `resources/views/purchasing/pr/`
- shared presentation components used by those views
- no PR route/controller/model/request/query change

### UI-04 — Remaining Purchasing

- `resources/views/purchasing/dashboard.blade.php`
- purchasing periods, quotations, comparison, PO, claims, conversations, and related presentation partials discovered in the live tree
- shared components only when the pattern is reusable

### UI-05 — Supplier

- all live views under `resources/views/supplier/`
- shared components required by those views
- no supplier data-scope or authorization change

### UI-06 — QC, Admin, Auth

- all live views under `resources/views/qc/`
- all live views under `resources/views/admin/`
- auth layouts/views and presentation-only profile views where required for consistent shells

### UI-07 — Compatibility cleanup

- migrated Blade/CSS/JS files only
- shared layout dependency tags only if final inventory proves zero required callsites
- DataTables, SweetAlert, and their required jQuery remain allowed compatibility dependencies

### UI-08 — QA and fixes

- source files implicated by verified regression findings
- `UI-08-QA-RESULT.md`
- screenshots only if a browser runtime becomes available

### UI-09 — Final audit

- `UI-REDESIGN-PROGRESS.md`
- `UI-REDESIGN-OVERNIGHT-RESULT.md`
- no runtime source change unless needed to repair a verified regression

## Primary risks and conservative decisions

1. Tailwind preflight can break legacy Bootstrap pages. Use a `tw-` prefix and disable preflight during the hybrid phase.
2. The Tailwind 4 Vite plugin is installed beside Tailwind 3. Keep the working Tailwind 3 PostCSS path; dependency rationalization is a later package.
3. The authenticated layout is unusually large. Move styling incrementally and verify after each coherent batch.
4. DataTables and SweetAlert are intentionally retained. Their continued presence is not a package failure.
5. Bootstrap global removal is unlikely to be safe while 27 `data-bs-*` files remain. Do not force a zero-dependency claim.
6. Existing inline Blade queries and domain behavior are outside this presentation mission. Do not expand or refactor them incidentally.
7. Browser-only gates remain blocked. Every affected result must use `CODE COMPLETE / VISUAL QA BLOCKED`, never `DONE` or screenshot-verified language.

## UI-00 auto-gate

`PASS`: the implementation path, compatibility constraints, baseline failures, module scope, MCP provenance, guide provenance, and package file map are documented. UI-01 can proceed safely.
