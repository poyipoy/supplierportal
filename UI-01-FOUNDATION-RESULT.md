# UI-01 Design Foundation Result

## Status

- Package: `UI-01 Design Foundation`
- Status: `CODE COMPLETE / VISUAL QA BLOCKED`
- Automatic gate: `PASS`
- Completed at: `2026-08-19 23:53:50 +07:00`
- Starting checkpoint: `b69f620`
- Browser runtime: unavailable
- Database mutation: none

UI-01 establishes a token-driven Tailwind/Alpine foundation without migrating a business module and without removing any approved compatibility engine.

## Implemented foundation

### Shared token source

The existing `--md-*` seeds and semantic roles were moved from the authenticated layout into `resources/css/app.css`, the Vite CSS entry shared by app, auth, and guest layouts.

The token architecture now has three explicit layers:

1. Primitive reference scales: the existing ADASI seeds plus the actual Coolors HCT tonal-scale output.
2. Semantic roles: primary, secondary, error, success, warning, surface, outline, and intended `on-*` pairings.
3. Component aliases: button, input, card, table, and dialog aliases that reference semantic roles.

The exact existing semantic colors remain unchanged. Generated tonal scales do not replace the project seeds.

Additional foundations include:

- validated `--md-outline-strong` for interactive boundaries;
- MD3 dragged-state opacity and scrim opacity;
- 4px-based spacing tokens;
- compact enterprise type roles and line heights;
- 36/44/48px control-height tokens;
- motion duration/easing tokens;
- documented z-index levels;
- focus-ring, tabular-number, tonal-surface, and reduced-motion primitives.

### Tailwind hybrid bridge

`tailwind.config.js` now:

- uses the `tw-` prefix;
- disables preflight;
- configures the forms plugin with `strategy: 'class'` so it does not globally restyle Bootstrap controls;
- scans Blade and JavaScript source;
- maps colors, typography, spacing, shape, elevation, motion, and layering to CSS variables instead of duplicate raw colors;
- defines a `shell` breakpoint at 993px, matching the existing `max-width: 992px` sidebar contract;
- retains Tailwind 3 and the working PostCSS path.

The installed Tailwind 4 Vite plugin remains unused. Switching compiler generations during the redesign would add unnecessary migration risk.

### Vite and Alpine activation

The shared Vite CSS/JavaScript entries now load in:

- `resources/views/layouts/app.blade.php`
- `resources/views/layouts/auth.blade.php`
- `resources/views/layouts/guest.blade.php`

This activates the already-installed Alpine entry in the authenticated and auth shells. No existing jQuery/Bootstrap interaction was replaced in this package.

### Guest compatibility

Because Tailwind is now deliberately prefixed, the inactive Breeze-style registration path and the anonymous components it calls were converted to `tw-*` classes. This prevents the hybrid-safety prefix from silently stripping their existing utility styling if registration is enabled later.

The live repository currently returns 404 for `/register`; no route was added or changed.

## Guide usage

Actual guides loaded and applied:

| Guide | Applied decisions |
|---|---|
| `design` | Routed token work to design-system and implementation work to ui-styling |
| `design-system` | Three-layer tokens, semantic Tailwind mapping, component aliases, explicit states |
| `ui-styling` | Mobile-first utilities, CSS layers, accessible focus and form states, prefixed hybrid configuration |
| `ui-ux-pro-max` | 4/8px rhythm, compact data density, 44px default control, reduced motion, one-primary-action hierarchy |

`GUIDE_HELPER_UNAVAILABLE` remains recorded for the absent ui-ux-pro-max local search script. No helper output is claimed.

## MCP usage

Material Design 3 MCP was used for actual color-role, state-layer, elevation, shape, button, text-field, navigation-drawer, and dialog guidance.

Coolors MCP was used for actual tonal generation, WCAG/APCA contrast checks, strong-outline adjustment, and color-vision-deficiency palette auditing. The resulting provenance and measured ratios are recorded in `UI-00-AUDIT.md`.

## Files changed

### Foundation

- `tailwind.config.js`
- `resources/css/app.css`

### Layout activation

- `resources/views/layouts/app.blade.php`
- `resources/views/layouts/auth.blade.php`
- `resources/views/layouts/guest.blade.php`

### Prefixed guest compatibility

- `resources/views/auth/register.blade.php`
- `resources/views/components/auth-session-status.blade.php`
- `resources/views/components/input-error.blade.php`
- `resources/views/components/input-label.blade.php`
- `resources/views/components/primary-button.blade.php`
- `resources/views/components/text-input.blade.php`

### Mission evidence

- `UI-REDESIGN-PROGRESS.md`
- `UI-01-FOUNDATION-RESULT.md`

## Verification

| Check | Result |
|---|---|
| `git diff --check` | PASS |
| `npm.cmd run build` | PASS — Vite 7.3.6, 57 modules |
| Production CSS | 14.97 kB / 3.60 kB gzip |
| Production JS | 94.78 kB / 34.73 kB gzip |
| `php artisan view:clear` | PASS |
| `php artisan view:cache` | PASS |
| Targeted layout/security tests | 10 PASS, 1 known pre-existing failure |
| Full `php artisan test` | 204 PASS, 1 known pre-existing failure, 2101 assertions, 53.87s |
| HTTP `/login` | PASS — 200, 13662 bytes, Vite CSS and JS tags present |
| HTTP built CSS | PASS — 200, 14967 bytes |
| HTTP built JS | PASS — 200, 94775 bytes |
| HTTP manifest | PASS — 200, 331 bytes |
| HTTP `/register` | 404 — route does not exist; no route change made |

The sole failing test remains:

`Tests\Feature\CustomAdasiAlertTest::test_export_confirmation_retains_the_single_download_guard`

It still expects pre-existing `window.exportConfirmationOpen` markup that was already absent at mission baseline. UI-01 caused no new test failure.

The CSS bundle is 70.97% smaller than the UI-00 baseline because the prefixed JIT build emits only used utilities. This is a measured asset-size change, not a browser performance claim.

## Static regression audit

Legacy dependency counts remain unchanged from UI-00:

| Dependency | Current |
|---|---:|
| `data-bs-*` | 67 matches / 27 files |
| jQuery syntax | 512 matches / 25 files |
| DataTables | 32 matches / 16 files |
| SweetAlert/AdasiAlert | 54 matches / 18 files |
| Bootstrap Icons | 432 matches / 74 files |

This is expected: UI-01 establishes the bridge and does not claim dependency retirement.

Guarded-path diff from the UI-00 checkpoint:

- routes: no change
- controllers: no change
- models: no change
- migrations/schema: no change
- database data: no mutation
- Hashid logic: no change
- translation logic: no change
- authorization/workflow: no change

## Visual QA

- Runtime available: no
- Screenshots captured: none
- Responsive breakpoints visually checked: none
- Keyboard/focus behavior browser-checked: no
- Status: `VISUAL_QA_BLOCKED`

Static compilation proves that the requested prefixed utilities and token variables exist in the production CSS. It does not prove visual correctness.

## Remaining compatibility dependencies

- Bootstrap CSS/JS remains required by measured live callsites.
- jQuery remains required by DataTables and legacy scripts.
- DataTables remains the approved data engine.
- SweetAlert remains the approved alert/confirmation engine.
- Bootstrap Icons remains the single icon library.
- Auth layout still has its legacy presentation layer; it is scheduled for UI-06.

## Gate decision

`PASS`: build, Blade compilation, full tests, HTTP asset delivery, static dependency inventory, and backend guardrails show no new regression. UI-02 can build reusable components and migrate the shell on this foundation.
