# Gate 2 — Phase 2A User Customization

Status: implementation committed in two dependency-ordered commits on customization-update.
This report attributes only Phase 2A work. Existing Phase 1 and unrelated working-tree changes are preserved.

## 1. Implemented

- Trusted, per-user dashboard layout persistence for Admin, Purchasing, Finance, Accounting, QC, GA, Supplier Import, and Supplier Local.
- Twenty-nine registered panels: fourteen required and fifteen configurable. Optional panels can be hidden and reordered with native keyboard-operable Move Up/Move Down buttons. Required workflow panels and original navigation remain available.
- Two trusted accent presets: ADASI Blue (brand/default) and Slate. Appearance preview stays in DOM/memory until Save; browser history restores saved values and radio selections.
- Independent sidebar_revision. Dashboard/accent saves preserve a device override; sidebar-state changes and reset reconcile it. Missing-row resolution and the first appearance-only save have the same sidebar token.
- Safe normalized persistence, authenticated ownership, explicit reset, transaction/first-save concurrency protection, role/context validation, and safe frontend serialization.
- Semantic Light/Dark/Bootstrap accent mapping, preserved status and business-series colors, and chart-chrome refresh for theme/accent changes and initial saved-theme rendering.
- Phase 1 theme, density, page size, Quick Access, original sidebar/mobile behavior, and combined Profile & Security remain functional.

Regional, Notification, and Language preferences remain deferred.

## 2. Phase 2A Files

### Created source/test files

1. app/Services/Dashboard/DashboardWidgetService.php
2. config/dashboard_widgets.php
3. database/migrations/2026_09_28_000002_extend_user_preferences_for_dashboard_customization.php
4. resources/views/components/ui/dashboard-layout.blade.php
5. tests/Feature/UserDashboardCustomizationTest.php
6. tests/Feature/UserDashboardRenderingTest.php
7. tests/Feature/UserDashboardUiTest.php
8. tests/js/dashboard-customization.test.mjs

### Intentionally extended pre-existing files

1. app/Models/UserPreference.php
2. app/Services/UserPreferenceService.php
3. app/Http/Requests/UpdateUserPreferenceRequest.php
4. app/Http/Controllers/UserPreferenceController.php
5. config/user_preferences.php
6. resources/views/profile/customization.blade.php
7. resources/views/layouts/app.blade.php
8. resources/js/preferences.js
9. resources/js/chart-theme.js
10. resources/css/app.css
11. resources/views/admin/dashboard.blade.php
12. resources/views/purchasing/dashboard.blade.php
13. resources/views/finance/dashboard.blade.php
14. resources/views/accounting/dashboard.blade.php
15. resources/views/qc/dashboard.blade.php
16. resources/views/ga/dashboard.blade.php
17. resources/views/supplier/dashboard.blade.php
18. resources/views/local-supplier/dashboard.blade.php
19. tests/Feature/UserCustomizationTest.php
20. tests/Feature/UserPreferenceMigrationTest.php
21. tests/Feature/UserPreferenceConcurrencyTest.php
22. tests/Feature/_user-preference-concurrency-worker.php
23. tests/js/preferences.test.mjs

This report is the additional delivery artifact. No dashboard controller, route file, User model, app.js, Tailwind configuration, notification class, regional formatter, package manifest, or source Phase 2 implementation plan was edited for Phase 2A.

## 3. Existing Dirty Overlap

The eight dashboard Blade files were initially clean. Their Phase 2A changes wrap existing blocks in trusted slots, retain responsive widths, and preserve required headers/actions, routes, callbacks, selectors, datasets, and scripts. No controller query refactor was introduced.

| Previously dirty file | Pre-existing work retained | Phase 2A hunk |
|---|---|---|
| app/Models/UserPreference.php | Phase 1 model, relationship, allowed fields/casts | Accent/layout fillable and casts; sidebar cast; owner/revisions remain excluded from mass assignment |
| app/Services/UserPreferenceService.php | Defaults, request-local cache, owner lock, Quick Access merge, reset | Trusted accent/layout normalization, independent sidebar revision, stable first-save token, new frontend values |
| app/Http/Requests/UpdateUserPreferenceRequest.php | Theme/density/sidebar/page size/Quick Access and stale supplier marker validation | Allowlisted accent; bounded hidden/order lists; duplicates, forged metadata, wrong role/context and required hiding rejected |
| app/Http/Controllers/UserPreferenceController.php | Existing edit/save/reset endpoints and redirects | Trusted dashboard/accent view data and two additional safe save fields |
| config/user_preferences.php | Phase 1 defaults/allowlists | Three Phase 2A defaults and the brand/slate registry |
| resources/views/profile/customization.blade.php | Theme, density, sidebar, rows, Quick Access, error/reset semantics | Accent controls, dashboard controls/old input, accessible status, reset copy; two changed help lines use contrast-safe foreground |
| resources/views/layouts/app.blade.php | Phase 1 early theme/sidebar bootstrap, DataTables defaults, existing unrelated notification/network hunks | Early accent state/allowlist, ephemeral preview and restoration |
| resources/js/preferences.js | Phase 1 preview and history entry points | Accent preview binding, keyboard reorder/focus, saved radio synchronization |
| resources/js/chart-theme.js | Phase 1 chart refresh, formatter/callback/data handling | Accent event, brand-owned business-series tokens, initial module-ready chrome refresh |
| resources/css/app.css | Existing tokens, dark mode, density and unrelated style hunks | Slate semantic/Bootstrap mappings, link/focus integration, fixed info/business chart colors; legacy Dark hover excludes Slate |
| tests/Feature/UserCustomizationTest.php | Existing Phase 1 acceptance cases | Three added default expectations |
| tests/Feature/UserPreferenceMigrationTest.php | Phase 1 table/constraint test and restoration | Extension down/up/re-up and Phase 1 data preservation assertions |
| tests/Feature/UserPreferenceConcurrencyTest.php | Real two-process test and test-DB guard | Accent/layout/sidebar revision arguments/assertions |
| tests/Feature/_user-preference-concurrency-worker.php | Bootstrap, dedicated DB check, timed start and failure handling | Optional registered accent and trusted admin layout payload |
| tests/js/preferences.test.mjs | Existing Phase 1 tests | Accent, history, independent revision, contrast/cascade, chart initialization and data preservation cases |

Phase 1 files above were already untracked; being untracked now does not mean Phase 2A created their entire content. Dashboard/controller BusinessTime changes were preserved and controllers were not edited. The existing composer.json diff is also outside this task; no dependency or manifest edit was performed.

## 4. Database / Migration

The additive migration extends user_preferences with:

| Column | SQL contract |
|---|---|
| accent | VARCHAR(20), default brand from preference config |
| dashboard_preferences | JSON, default JSON_OBJECT() |
| sidebar_revision | Unsigned BIGINT, default 1 |

Existing unique user_id, users FK/cascade, Phase 1 columns and rows remain intact. No users-table fields or unrelated backfill.

Effective and connected test database were verified as adasi_portal_test before database tests. A read-only SELECT DATABASE() check also runs in the browser-fixture test. All migration/RefreshDatabase/concurrency work used the dedicated test DB; application/development DB was not migrated or mutated.

Migration tests exercised up/down/re-up with an existing preference row and verified Phase 1 values survive. Rollback drops only these three columns and necessarily discards stored Phase 2A choices. It preserves preference rows and Phase 1 data. Production/staging DDL and deployment are outside this execution.

The browser sidebar token is now sidebar-v2:<sidebar_revision>, scoped by account ID. Existing Phase 1 tokens reconcile once on upgrade; subsequent dashboard/accent saves preserve the device override. It excludes preference-row ID, preventing first-save invalidation.

## 5. Tests Executed

Commands below were actually executed. Focused commands were repeated only for RED/GREEN, integration, or an identified remediation.

    php artisan test --filter=TestingEnvironmentDatabaseSafetyTest
    php artisan test --filter=UserDashboardCustomizationTest
    php artisan test --filter='UserCustomizationTest|QuickAccessTest|UserPreferenceMigrationTest|UserPreferenceConcurrencyTest'
    php artisan test --filter=UserDashboardRenderingTest
    php artisan test --filter=UserDashboardUiTest --do-not-cache-result
    php artisan test --filter='UserDashboardCustomizationTest|UserDashboardRenderingTest|UserDashboardUiTest' --compact --do-not-cache-result
    php artisan test --filter=corrupt_saved_accent_is_pruned_by_legacy_save --do-not-cache-result
    php artisan test --filter='FrontendAssetLoadingTest|UserDashboardUiTest' --compact --do-not-cache-result
    node --test tests/js/preferences.test.mjs tests/js/dashboard-customization.test.mjs
    node --test tests/js/preferences.test.mjs
    node --test tests/js/preferences.test.mjs tests/js/dashboard-customization.test.mjs tests/js/calendar.test.mjs tests/js/unsaved-changes.test.mjs
    php vendor/bin/pint tests/Feature/UserDashboardCustomizationTest.php
    php artisan route:list --no-ansi
    php artisan view:cache
    npm.cmd run build
    git diff --check
    composer test

Shell-free tool execution passed the pipe-containing filter as one argument; it was not a shell pipeline.

Composer initially could not start because the tool process lacked APPDATA/COMPOSER_HOME. It was rerun successfully with a temporary process-scoped Composer home:

    $env:COMPOSER_HOME = 'C:\Users\BAHRIA~1\AppData\Local\Temp/phase2a-composer-home'
    & 'C:\ProgramData\ComposerSetup\bin\composer.bat' test

No dependency installation occurred.

An ephemeral PHP test outside the repository generated real Laravel responses for browser verification:

    php vendor/bin/phpunit --configuration phpunit.xml C:\Users\BAHRIA~1\AppData\Local\Temp/phase2a-browser/Phase2BrowserFixtureTest.php --do-not-cache-result

Its first setup attempt omitted mandatory Phase 1 fields and failed; the fixture was corrected to use centralized defaults. Final execution passed one test/seven assertions. The fixture seeded only the test DB and its transaction rolled back. The temporary HTTP server served these responses/assets and rejected non-GET writes; no browser action could mutate the application database.

Scoped syntax and Pint commands are expanded in the appendix.

## 6. Results

| Check | Final result |
|---|---|
| Test database safety | PASS — 2 tests / 4 assertions; effective/connected adasi_portal_test verified |
| Initial backend Phase 2A acceptance | PASS — 12 tests / 91 assertions before the additional stale-accent regression |
| Phase 1 preference/Quick Access/migration/concurrency combined run | PASS — 21 tests / 141 assertions |
| Initial integrated Phase 2A acceptance/render/UI run | PASS — 17 tests / 161 assertions |
| Stale stored accent remediation | PASS — 1 test / 2 assertions; preceding RED reproduced the defect |
| Final asset-loading/UI focused check | PASS — 6 tests / 46 assertions |
| Final full composer regression | PASS — 1,091 tests / 10,778 assertions, 579.55 seconds, exit 0 |
| Final frontend regression | PASS — 22 tests, including preferences, reorder, calendar and unsaved changes |
| PHP syntax / scoped Pint | PASS — 15 files |
| Route listing | PASS — 352 routes; same authenticated customization GET/PATCH/DELETE endpoints |
| Blade cache | PASS |
| Final Vite build | PASS — 15 modules, no new dependencies |
| Phase 2A whitespace scan/scoped diff check | PASS — no new whitespace failures |
| Whole-tree git diff --check | FAIL — two known pre-existing findings; see section 13 |
| Staging/production migration and deployment | SKIPPED — explicitly outside authorized scope |
| Live browser authentication/save/reset against application DB | SKIPPED — application DB mutation prohibited; backend tests cover persistence/CSRF/reset |

RED failures were expected and confirmed missing/new behavior before implementation: backend fields/registry, dashboard rendering/order, UI controls, accent preview, keyboard binding, contrast/cascade, history radio restoration, stale stored accent, and initial saved-theme chart chrome. No unresolved implementation test failure remains.

The full suite emits existing PHPUnit doc-comment deprecation notices and a non-fatal result-cache permission warning. Build reports that the logo URL resolves at runtime; the referenced public logo file was verified present. Coverage percentage was not measured or claimed.

## 7. Performance Evidence

- The rendering test instruments SQL and asserts exactly one user_preferences lookup on each of the eight dashboard requests, plus a separate Admin saved-order check. These assertions passed in the full regression.
- Registry evaluation is finite: eight audience definitions; the largest current widget list has five keys. Stored key inspection is capped by registry size.
- No per-widget preference query, global preference eager loading, Redis/cache subsystem, or dashboard-controller refactor was added.
- Hidden optional widgets are omitted from the returned DOM. Existing controller data work remains; this feature does not claim optional-query savings. QC history aggregation and Finance forecast behavior retain their previous contracts.

## 8. Code Review

Root reviewed backend/registry/validation/concurrency contracts; the delegated ECC implementer independently reviewed root-authored frontend/component surfaces and rechecked remediation. Scope was Phase 2A and directly affected Phase 1 contracts.

| Finding | Remediation/evidence |
|---|---|
| MEDIUM: legacy Dark Bootstrap hover overrode Slate, 3.49:1 | Excluded Slate from that legacy rule; Slate hover foreground/background computes 11.15:1; Node cascade/contrast regression and browser computed properties verified |
| MEDIUM: preventScroll could leave reordered control offscreen | Normal focus() restores viewport visibility; native Enter browser test retained visible focus |
| LOW: BFCache restored appearance but retained unsaved radio selections | Restore saved theme/density/accent radios; actual BFCache back navigation verified saved values |
| LOW reliability: corrupt stored accent could be re-persisted by legacy save | Allowlist fallback before persistence; RED/GREEN regression verified |
| MEDIUM: new Light help text was 4.34:1 | Existing on-surface token selected for the two changed header lines; browser measured 16.30:1 |
| MEDIUM: Purchasing chart initialized before deferred theme module | Module-ready/DOMContentLoaded refresh through existing chrome routine; Node RED/GREEN and browser saved-Dark verification passed |

No unresolved CRITICAL/HIGH finding remains in the reviewed scope.

## 9. Security Review

PASS in scope: authenticated owner derivation; A/B isolation; explicit fillable; internal revisions; real CSRF rejection; safe JSON; trusted accent/widget metadata; rejection of arbitrary route/view/URL/CSS/label fields; strict role lists; supplier context separation/stale marker; stale layout filtering; safe reset.

Saved layout is presentation only. Original destination middleware/policies and supplier ownership queries remain authoritative. Required panels cannot be hidden through the form or forged stored preferences. Reset modifies preference fields only; profile, role, password, activation, 2FA and sessions remain intact.

No unresolved CRITICAL/HIGH security finding remains in Phase 2A. This was not a repository-wide security audit.

## 10. Accessibility Review

PASS on changed surfaces through source, focused tests, and isolated Chromium browser verification:

- Native labeled radio/checkbox/button semantics and fieldsets/legends.
- Keyboard Space changes visibility; no hide control for required panels.
- Keyboard Enter reorders stable submitted keys, announces position, and keeps focus visible.
- Move controls measured 44 CSS pixels high.
- 390px viewport: no horizontal overflow on the tested customization surface.
- Mobile sidebar opens, focuses inside, releases inert; Escape closes, restores inert, and returns focus to the trigger.
- Real BFCache restoration resets saved appearance and corresponding radio state.
- Blocked LocalStorage leaves early preferences and Alpine/sidebar functional; no console error.
- Two account fixtures keep distinct sidebar caches; returning to the first preserves its device override.
- Hidden panels are absent from server output, removing their focusable descendants.

Browser checks used real Laravel-rendered test fixtures and built assets. All eight role/context dashboards were covered by server-rendering tests; interactive browser spot checks covered Customization and Purchasing. Manual assistive-technology, other browser engines, and all-role live sessions were not exercised.

## 11. Design-System Review

PASS: existing semantic tokens are authoritative; brand default retains current ADASI colors. Slate derives from the existing secondary palette. Error/warning/success/info and business-series colors remain unchanged. Light/Dark action/link/focus contrast and Bootstrap hover mapping were checked; saved-Dark Purchasing axis/grid colors match tokens after initialization.

Actual browser saved-Dark chart result: ticks #B4C0D2, grid rgba(76, 91, 112, .58), business series border #1F5FA6 with data unchanged. Theme/accent updates preserve dataset references and callbacks in tests.

Reorder integration uses a flat responsive grid with trusted slot widths. Admin/Import nested-column packing can differ slightly from the original grouping; existing cards, data, scripts, routes and workflow actions remain intact. No design system, token catalog, competitor research, permanent preview page, or dashboard redesign was generated. Browser screenshots were inspected in tool output.

## 12. Known Limitations

- Regional, Notification, and Language preferences remain deferred.
- Development/staging/production migrations were not applied. Deployment needs the Phase 1 preference migration followed by this additive extension.
- Optional hidden panels retain existing controller query work; no performance improvement is claimed for those queries.
- Browser verification used isolated test fixtures. Live login/save/reset, all-role interactive sessions, assistive technology and cross-engine QA remain release checks.
- Legacy Phase 1 sidebar tokens reconcile once when the new independent token format is first used.

## 13. Diff Health

Whole-tree git diff --check reports only:

- .env.example:148 — known pre-existing blank line at EOF.
- resources/views/purchasing/po/show.blade.php:661 — known pre-existing trailing space.

Neither file/hunk was edited. The Phase 2A scoped check and an explicit trailing-whitespace scan of all 31 source/test files found no new issue. Existing CRLF notices in layout/navbar are not new whitespace failures. No unrelated reset, clean, stash, revert, checkout, discard, commit, push, merge or PR was performed.

## 14. Proposed Commit

    feat: add dashboard and accent customization

Do not commit yet. Wait for:

**Approve Gate 2 — Phase 2A**

## Appendix — Exact scoped PHP checks

    php -l app/Models/UserPreference.php
    php -l app/Services/UserPreferenceService.php
    php -l app/Services/Dashboard/DashboardWidgetService.php
    php -l app/Http/Requests/UpdateUserPreferenceRequest.php
    php -l app/Http/Controllers/UserPreferenceController.php
    php -l config/user_preferences.php
    php -l config/dashboard_widgets.php
    php -l database/migrations/2026_09_28_000002_extend_user_preferences_for_dashboard_customization.php
    php -l tests/Feature/UserDashboardCustomizationTest.php
    php -l tests/Feature/UserDashboardRenderingTest.php
    php -l tests/Feature/UserDashboardUiTest.php
    php -l tests/Feature/UserCustomizationTest.php
    php -l tests/Feature/UserPreferenceMigrationTest.php
    php -l tests/Feature/UserPreferenceConcurrencyTest.php
    php -l tests/Feature/_user-preference-concurrency-worker.php

    php vendor/bin/pint --test app/Models/UserPreference.php app/Services/UserPreferenceService.php app/Services/Dashboard/DashboardWidgetService.php app/Http/Requests/UpdateUserPreferenceRequest.php app/Http/Controllers/UserPreferenceController.php config/user_preferences.php config/dashboard_widgets.php database/migrations/2026_09_28_000002_extend_user_preferences_for_dashboard_customization.php tests/Feature/UserDashboardCustomizationTest.php tests/Feature/UserDashboardRenderingTest.php tests/Feature/UserDashboardUiTest.php tests/Feature/UserCustomizationTest.php tests/Feature/UserPreferenceMigrationTest.php tests/Feature/UserPreferenceConcurrencyTest.php tests/Feature/_user-preference-concurrency-worker.php

Scoped whitespace command:

    git diff --check -- resources/views/profile/customization.blade.php app/Models/UserPreference.php app/Http/Requests/UpdateUserPreferenceRequest.php config/user_preferences.php app/Services/UserPreferenceService.php resources/views/layouts/app.blade.php resources/views/accounting/dashboard.blade.php resources/css/app.css resources/js/chart-theme.js app/Http/Controllers/UserPreferenceController.php resources/js/preferences.js resources/views/purchasing/dashboard.blade.php resources/views/admin/dashboard.blade.php resources/views/finance/dashboard.blade.php resources/views/ga/dashboard.blade.php resources/views/qc/dashboard.blade.php resources/views/local-supplier/dashboard.blade.php resources/views/supplier/dashboard.blade.php tests/js/preferences.test.mjs app/Services/Dashboard/DashboardWidgetService.php config/dashboard_widgets.php database/migrations/2026_09_28_000002_extend_user_preferences_for_dashboard_customization.php tests/Feature/UserDashboardCustomizationTest.php tests/Feature/UserDashboardRenderingTest.php tests/Feature/UserDashboardUiTest.php tests/Feature/UserCustomizationTest.php tests/Feature/UserPreferenceMigrationTest.php tests/Feature/UserPreferenceConcurrencyTest.php tests/Feature/_user-preference-concurrency-worker.php resources/views/components/ui/dashboard-layout.blade.php tests/js/dashboard-customization.test.mjs


## 15. Commit Dependency Check — Resolved

The dependency was resolved with separate local commits on customization-update:

- Phase 1 preference foundation: ce2e114 — feat: add per-user portal customization.
- Phase 2A extension: 7f0e0b7 — feat: add dashboard and accent customization.

The Phase 1 commit creates user_preferences and provides the UserPreference model, request-local preference service, Quick Access registry, authenticated customization endpoints, shared bootstrap, and tests. The Phase 2A commit extends that contract with accent, dashboard layout, sidebar_revision, the trusted widget registry, eight dashboard integrations, and focused tests.

Each commit was staged and reviewed separately. Phase 1 used an isolated snapshot so Phase 2A fields and unrelated dirty hunks were excluded. Phase 2A used a 31-path staged snapshot; the mixed app layout staged only accent-bootstrap hunks and excluded unrelated notification polling and portal-scope metadata. Both cached diffs passed git diff --cached --check before commit. The index is empty after both commits.

Remaining working-tree modifications are pre-existing/unrelated supplier, export, payment, security, timezone, and documentation work. No Phase 2A source remains unstaged. Planning documents and the generated Gate 2 report remain untracked. No changes from those paths were included in either commit.
