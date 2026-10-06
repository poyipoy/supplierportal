# Gate 1 — Phase 1 User Customization

Date: 2026-09-28. Status: PLAN ONLY; waiting for Approve Gate 1.

## 1. ECC size classification

**Large.** A new user-owned persistence contract, shared authenticated rendering, early browser state, role/context navigation, design tokens, Bootstrap, charts and DataTables cross multiple modules. Research found 16 hardcoded pageLength: 25 occurrences in 15 Blade files, covering 18 table initializations. No new dependencies are proposed.

Execution uses orch-add-feature through orch-pipeline, supported by laravel-patterns, laravel-tdd, laravel-security, accessibility and design-system. ECC code-explorer agents investigated backend and frontend; ECC planner reviewed architecture and verification. After approval: tdd-guide → integration → code-reviewer + security-reviewer → accessibility/design-system verification → Gate 2.

The actual branch is customization-update, not master. The existing diff contains 143 modified tracked files plus untracked work. Keep this checkout and all prior changes; inspect overlapping hunks before editing. No checkout, commit, push, reset, clean or application database mutation is part of Gate 1. The requested master base is a release/base reference, not authorization to discard this working tree.

## 2. Repository findings

All three mandatory root documents and context.md were read. No additional AGENTS.md was found within the relevant application directories. Source inspection takes precedence over old documentation.

| Area | Observed implementation / evidence |
|---|---|
| Profile | Combined Profile & Security page with profile/password/2FA/sessions/account deletion: resources/views/profile/edit.blade.php:3–67; ProfileController.php:17–89. |
| Routes | Shared auth group in routes/web.php:118, profile GET/PATCH/DELETE at 130–133. Runtime route:list confirms existing profile/2FA/session routes; customization is absent. |
| Account menu | Single Profile & Security entry: partials/navbar.blade.php:98–100. |
| Existing preferences | No UserPreference, user_preferences, quick_access or customization implementation found in app/routes/config/database. |
| User | Explicit fillable and casts; existing hasOne patterns: app/Models/User.php:36–81,113–117. |
| Sidebar | Early head bootstrap reads unscoped sidebarCollapsed at layouts/app.blade.php:37–50; Alpine desktop state/storage at resources/js/app.js:301–369. |
| Mobile/focus | Mobile first-link focus, Tab containment, Escape and trigger restoration: app.js:395–440; inert/aria-hidden at sidebar.blade.php:1–8; collapsed tooltips at app.js:372–392. |
| Design tokens | Semantic --md-* colors/RGB, --ui-* aliases, Bootstrap adapter: resources/css/app.css:87–297; prefixed Tailwind mappings: tailwind.config.js:34–80. No authenticated theme/system listener. |
| Density | Existing shared table component defaults to compact (components/ui/data-table.blade.php:7); tables/toolbars/sidebar already provide CSS seams. Global comfortable must preserve the current baseline. |
| Tables | CDN DataTables 1.13.6 loads only on uses-datatables pages; opt-in assets at layouts/app.blade.php:127–131. No resource stateSave or lengthMenu configuration found. Some lists use ordinary server pagination. |
| Charts | resources/js/chart-theme.js reads tokens but has fixed light grid color at line 30 and no refresh on theme change. Existing chart options capture colors during creation. |
| Supplier context | Actual scope values import/local; PortalContext::current uses route then session; dual-scope without valid session resolves null (PortalContext.php:60–98). Sidebar:172–177 intentionally shows a neutral portal-choice message. |
| Purchasing links | Existing PurchasingNavigation::listUrl preserves remembered list filters; sidebar:213–241. Reuse it for shortcuts. |
| Security | Exact role middleware, no implicit admin bypass. EnforceSupplierDomain retains document/chat restrictions and denies exports to local-only suppliers. Finance/accounting exports are allowed by current code. |
| Tests | PHPUnit 11, real MySQL adasi_portal_test, RefreshDatabase and serial execution. UserFactory automatically adds import scope; local-only fixtures must remove it. |

Downloads plan proposes splitting Profile/Security and contains illustrative route names. The explicit pasted request says preserve the existing combined page: add Customization only. Some older CLAUDE/context facts differ from updated AGENTS/source; none justify changing business contracts in this task.

## 3. Gap analysis

| Requirement | Classification | Action |
|---|---|---|
| Authentication, CSRF, session security, role authorization | Reusable as-is | Use existing web/auth stack; no exemptions or new auth system. |
| Profile, account menu, sidebar/mobile | Requires extension | Add customization entry and Quick Access; preserve existing behavior. |
| Per-user persistence, defaults, validation, reset | New implementation | Dedicated preference table/config/model/controller/request/resolver. |
| Light/dark/system | Requires extension | Extend semantic token values, Bootstrap adapter and chart helper. |
| Density | Requires extension / architecture adjustment | Root density variables with baseline-preserving precedence over default compact table classes; avoid shrinking security controls. |
| Sidebar default | Requires extension | Account-scoped cache reconciled with server preference version. |
| Rows per page | Requires extension | Shared DataTables defaults; remove 16 hardcoded overrides. Ordinary pagination remains unchanged. |
| Quick Access | New implementation | Trusted stable-key registry and context-aware resolver, existing sidebar-item component. |
| Profile/Security split in Downloads plan | Adjust to explicit request | Keep Profile & Security intact. |

## 4. Architecture decision

### Persistence and resolution

Create user_preferences with id, unique FK user_id → users.id/cascade delete, theme, density, sidebar_state, unsigned page_size, nullable JSON quick_access and timestamps. Add a small unsigned revision counter used solely for browser cache invalidation. User hasOne preference; UserPreference belongsTo user; fillable only editable preference fields, casts page_size/revision to integer and quick_access to array. Owner and revision are assigned internally.

config/user_preferences.php centralizes defaults and allowed values: system, comfortable, expanded, 25, []. Users without a row read defaults and do not create rows during page views. A focused UserPreferenceService resolves once with request-local reuse; no Redis or singleton user-state cache. A narrowly scoped layouts.app composer in the existing AppServiceProvider supplies safe layout data to included partials. The controller uses the same resolver. Target overhead: at most one preference lookup per authenticated render; measure after implementation.

Save/reset serialize under a transaction locking the existing authenticated users row, then create/update the hasOne preference. This handles simultaneous first saves and context-preserving merges; unique user_id remains the database backstop. Entire-form concurrent saves use last serialized write wins. No user ID from client selects ownership.

**Reset uses explicit defaults and increments revision**, instead of deleting the row. Both are allowed by the request. This prevents an old device cache from surviving save/reset cycles that return to an absent-row marker; it also avoids relying on timestamp precision for invalidation. Reset touches preferences only, empties all context favorites, and uses existing ADASI confirmation/feedback.

### Frontend exposure and theme

Server safely serializes only resolved preference values, cache version and current account cache identifier. A small head bootstrap runs before application styles/visible UI, setting data-theme, data-bs-theme, data-density and sidebar initial state. Expose a readonly shared AdasiPreferences API early enough for inline page scripts. resources/js/preferences.js adds OS matchMedia listening, theme/density preview and navigation restoration; preview never writes DB or persistent browser cache. Explicit themes override OS. Returning through browser history must restore saved values too.

Keep light defaults and ADASI brand primitives. Supply dark semantic roles including RGB companions/workspace, repair Bootstrap bridge values and foreground pairs, and reuse component aliases. Theme existing charts through chart-theme.js with a shared refresh hook for axes/grid/title/legend/tooltips; preserve data, formatter callbacks and semantic series meaning. No PDF/email/receipt restyling.

Density changes shared table padding, toolbar/filter spacing and sidebar sizing. Comfortable preserves current spacing; compact reduces selected data-heavy areas. Keep mobile targets, password/2FA/destructive controls and dialog actions usable. Do not globally reduce every input token.

### Sidebar and DataTables

Use account-scoped localStorage containing server version and the current desktop device override. Matching version preserves the local toggle; new server revision replaces it with the account default. New/no-record accounts start expanded and never inherit another account's legacy global key. Save/reset resynchronizes the current browser immediately; other devices reconcile on their next authenticated page load. Handle unavailable storage without errors. Keep Alpine mobile state, focus handling, overlay and Bootstrap tooltips.

Set jQuery DataTables defaults immediately after CDN loading and before page scripts; include configured pageLength and lengthMenu [10,25,50,100]. Remove local pageLength:25 so shared defaults apply. Do not depend on deferred Vite timing. Existing server-side Ajax, filters, sorting, search, callbacks and selectors stay intact. Manual table length changes remain usable; saved setting supplies initial length on subsequent pages.

### Quick Access trust and supplier context

config/quick_access.php maps globally unique stable keys to trusted labels/icons/routes/active patterns/audience. Only keys are stored. QuickAccessService supplies selectable/renderable entries by actual role, eligibility, current PortalContext and route existence. No arbitrary client route/URL/label/icon is rendered. Destination middleware/policy stays authoritative; customization cannot grant access. QuickAccessTest also verifies remembered Purchasing list URLs.

Use actual sidebar destinations: local supplier dashboard/invoice create/list/PO/vendor profile/information; import supplier dashboard/quotation/PO/shipment/chat/claim/history/export/announcements; role-specific Purchasing, Finance, accounting, QC, GA and Admin normal menus. Use actual route names (e.g. supplier.purchase-orders.index and local-supplier.vendor-profile.show), never example aliases from the Downloads plan. Purchasing shortcuts reuse PurchasingNavigation::listUrl.

A single flat array has **six keys maximum account-wide**. Supplier editing shows only active-context choices. Save preserves still-authorized saved keys for the other context; validates submitted keys against the active context; enforces the cap on the merged set; prunes unknown/revoked entries. Explain total capacity in UI. A server-checked context marker rejects stale submissions if another tab changed context; it never sets authorization. Dual-scope without selected context has no supplier operational choices/rendered shortcuts, but appearance remains editable. Rendering filters every request, including role/scope changes. Reset clears all contexts. Original menus stay available.

## 5. Vertical task list

Every slice follows RED → GREEN → REFACTOR after Gate 1:

A. Defaults, schema, relationship and missing-row resolution.
B. Trusted registry/resolver and role/supplier context contracts.
C. Authenticated GET/PATCH/DELETE, FormRequest, save/reset and owner isolation.
D. Dedicated customization page with native labeled groups, validation, save/reset controls.
E. Layout resolution, early safe frontend payload, theme bootstrap and OS handling.
F. Semantic dark theme, Bootstrap compatibility, foreground pairing and charts.
G. Root density with current-baseline precedence and accessible target sizes.
H. Account-scoped sidebar cache/revision integration without mobile rewrite.
I. DataTables defaults and all affected initializer cleanup.
J. Quick Access rendering and navbar Customization entry.
K. Integration/regression, code/security reviewers, accessibility/design-system review; resolve CRITICAL/HIGH; deliver Gate 2 before commit.

## 6. Expected files

New:
- config/user_preferences.php and config/quick_access.php.
- database/migrations/<timestamp>_create_user_preferences_table.php.
- app/Models/UserPreference.php.
- app/Services/UserPreferenceService.php and QuickAccessService.php.
- app/Http/Controllers/UserPreferenceController.php.
- app/Http/Requests/UpdateUserPreferenceRequest.php.
- resources/views/profile/customization.blade.php.
- resources/js/preferences.js; a pure core helper only if necessary for behavioral tests.
- tests/Feature/UserCustomizationTest.php; tests/Feature/QuickAccessTest.php; tests/Feature/UserPreferenceMigrationTest.php; tests/Feature/UserPreferenceConcurrencyTest.php; tests/js/preferences.test.mjs.

Modified:
- app/Models/User.php, app/Providers/AppServiceProvider.php, routes/web.php.
- resources/views/layouts/app.blade.php, partials/navbar.blade.php, partials/sidebar.blade.php.
- resources/js/app.js and chart-theme.js; resources/css/app.css.
- resources/views/components/ui/data-table.blade.php only for density inheritance when needed.
- tests/Feature/SidebarShellTest.php and FrontendAssetLoadingTest.php for intentional new contracts.
- The 15 table scripts listed below.
- tailwind.config.js / shared components / chart consumers / public/assets/css/adasi-alert.css only for verified token/foreground/theme-hook gaps. Preserve unrelated dirty hunks; no general cleanup.

DataTable scripts under resources/views:
admin/auth-audit-logs/index.blade.php; admin/material-hs-code/_script.blade.php (two); admin/users/index.blade.php; purchasing/claims/index.blade.php; purchasing/comparison/_scripts.blade.php; purchasing/periods/index.blade.php; purchasing/po/index.blade.php; purchasing/pr/index.blade.php; purchasing/shipments/index.blade.php; qc/inspections/index.blade.php; supplier/claims/index.blade.php; supplier/po/index.blade.php; supplier/price-history/index.blade.php; supplier/quotations/period.blade.php; supplier/shipments/index.blade.php.

## 7. TDD matrix

| Slice | Test to write → initial RED | Implementation → expected GREEN |
|---|---|---|
| A | Missing row defaults/no read insert; relationship/casts; FK cascade; unique user; migration up/down: table/resolver absent | Schema/model/config/resolver; correct defaults without seed/backfill, cascade and reversible isolated migration. |
| B | Unknown key, arbitrary route/URL, other-role shortcut, local/import mismatch, six-plus, duplicate keys, scope revocation, no context: no registry validation | Registry/resolver and server context checks; rejected input, safe output, max six merged, other-context preservation. |
| C | Guest GET/PATCH/DELETE; every supported role GET; valid save; forged owner/unrelated role/password input; invalid theme/density/sidebar/page size; later request/login; repeated/concurrent save/reset: routes absent | Auth routes/FormRequest/current-user save; persistent single owned row, no unrelated user changes, serialized revision updates. |
| C/D | Reset defaults only, other-user untouched, all-context favorites cleared, second reset harmless: reset absent | Explicit-default reset, confirmation and revision bump; profile/password/2FA/session state preserved. |
| D/J | Current values/errors; proper input labels/groups; navbar route; empty/one/six shortcuts; active state; original menus preserved: page absent | Existing Blade components/native controls/sidebar-item; trusted escaped links and retained ordinary navigation. |
| E/F | OS dark/light changes; explicit override; early initialization before Vite; unsaved preview and back-navigation restoration; chart live recolor preserving callbacks: handler absent | Early bootstrap/module/token bridge/chart hook; correct visible theme and saved preference separation. |
| G | Root comfortable preserves baseline; compact affects selected dense controls only: no density contract | Shared spacing variables/inheritance; usable focus/target sizes and security forms. |
| H | Account switch, server revision, reset, blocked storage, desktop toggle/breakpoints, mobile open/close/focus/tooltips: old global cache | Scoped versioned cache + small shell integration; no cross-account leak or mobile regression. |
| I | Default page size and 10/25/50/100 request lengths; available length menu; search/order/filter/redraw callbacks: local hardcoded 25 | Shared defaults before initializers; correct pagination and unchanged endpoint contracts. |
| K | Existing Profile/Auth/context/navigation/asset tests and browser matrix | Regression evidence and reviewer remediation; no unverified claims. |

Explicit backend acceptance scenarios: (1) guest access denied; (2) authenticated access; (3) no-row defaults; (4) valid persistence; (5) current-user ownership; (6) invalid theme; (7) invalid density; (8) invalid sidebar; (9) invalid page size; (10) more than six favorites; (11) unknown key; (12) other-role shortcut; (13) Local restrictions; (14) Import restrictions; (15) reset defaults; (16) deletion cascade; (17) later request/login persistence; (18) arbitrary route strings; (19) unrelated User attributes unchanged.

The backend matrix covers all 19 requested scenarios, plus duplicate keys, unresolved/changed context, concurrent first save and stale-cache reset. Use existing PHPUnit infrastructure and real MySQL test database; no new testing framework. Unit/JS coverage targets follow skills where a coverage driver is available; never report a measured percentage without running coverage.

## 8. Security risks and controls

- Ownership/IDOR: all read/write/reset targets derived from request->user, no user identifier parameter.
- Mass assignment: allowlisted editable fields only; owner, revision and User security fields never accepted.
- Route injection/XSS: registry keys only; route resolution from trusted metadata; safe JSON serialization and escaped Blade.
- Authorization bypass: registry mirrors normal authorized navigation, with route/middleware tests; no generic admin bypass or weakened policies.
- Supplier leakage: actual eligibility + current context; revoked keys filtered; context marker checked server-side; other-context saved keys never rendered here.
- CSRF: existing web protection and token on update/reset; test actual enforcement with middleware enabled since Laravel normally bypasses it during tests.
- Stale keys: unknown/revoked entries ignored at render and pruned on save; role changes cannot resurrect unauthorized shortcuts.
- User-specific rendering: retain existing private/no-store headers and current CSP. Existing CSP permits early inline bootstrap; no weakening necessary.

## 9. UI / technical risks and verification

Theme flash: execute head bootstrap before styles; check first render and slow assets. Sidebar hydration: one shared initial state for CSS and Alpine, no deferred-Vite dependency. Bootstrap: set data-bs-theme alongside semantic tokens and inspect local component variables. Dark coverage: workspace/RGB adapters, hardcoded white foreground assumptions, statuses, controls, hover/focus/disabled/empty states and SweetAlert. Charts: update token-dependent chrome after OS/preview changes, retaining business series.

Compact mode: default shared table already compact, so preserve existing baseline and define explicit root precedence. Mobile: retain inert/overlay/Tab/Escape/focus restoration and 44px mobile link targets. Accessibility: native fieldsets/radio/select/checkboxes, readable errors, keyboard confirmation, visible focus, contrast checks in both themes and usable targets. Performance: one preference lookup, request-local reuse, bounded registry/six favorites, no global eager loading or new cache infrastructure; measure query cost rather than claim negligible overhead without evidence.

Planned verification after approval (not executed yet):

~~~powershell
php artisan test --filter=TestingEnvironmentDatabaseSafetyTest
php artisan test --filter=UserCustomizationTest
php artisan test --filter=QuickAccessTest
php artisan test --filter=UserPreferenceMigrationTest
php artisan test --filter=UserPreferenceConcurrencyTest
php artisan test tests/Feature/ProfileTest.php
php artisan test tests/Feature/Auth --compact
php artisan test tests/Feature/Supplier/SupplierPortalContextV2Test.php
php artisan test --filter=SidebarShellTest
php artisan test --filter=FrontendAssetLoadingTest
php artisan test --filter=HashidUrlSecurityTest
php artisan test --filter=SupplierDataIsolationTest
php artisan test tests/Feature/LocalInvoice/LocalInvoiceScopeIsolationTest.php
node --test tests/js/preferences.test.mjs
node --test tests/js/calendar.test.mjs
node --test tests/js/unsaved-changes.test.mjs
composer test
php -l <each changed PHP file>
php vendor/bin/pint --test <changed PHP files>
php artisan view:cache
php artisan route:list --no-ansi
npm.cmd run build
git diff --check
~~~

Check test configuration/cached config/DB_URL before any RefreshDatabase test. Run database suites serially. Test new migration up/down/re-up only against the verified test connection with isolated migration handling; do not issue broad application rollback/fresh. Deploy the new table before exposing application code that reads preferences; exercise restored-staging up/down/up and back up before production DDL. Roll back application/assets before dropping this table, which loses customization data. Production deployment is outside this task. Application schema/ledger and production rollout remain separately identified; test success never proves production migration status. No new lint/static-analysis package is proposed; no configured PHPStan/ESLint script was found in inspected manifests.

Browser matrix: all three themes including OS changes; both densities; sidebar expanded/collapsed/mobile; Quick Access empty/one/six and active/collapsed; each page size; supplier local/import/scope switch and role samples; chart refresh; dialogs/dropdowns/keyboard/focus/contrast; preview exit/reset and account switching. Report browser, automated, migration/staging and production evidence separately. Do not count unexecuted checks as passed.

Executed for Gate 1: read/search/source and initial diff inspection; git branch/status; php artisan route:list --path=profile --no-ansi passed (13 routes). git diff --check reported **pre-existing** whitespace at .env.example:148 and resources/views/purchasing/po/show.blade.php:661. These were not changed. No feature tests/build/browser checks or database migrations were run.

## 10. Gate 1 recommendation

Approve the six requested Phase 1 capabilities with the architecture above. Deliberate adjustments: preserve combined Profile & Security; account-scope sidebar storage; internal revision counter + explicit-default reset; retain inactive-context favorites under a six-total cap; preserve remembered Purchasing URLs; initialize DataTables defaults before inline scripts. No Phase 2 features, unrelated cleanup, new dependencies or auth/business changes.

**STOP: wait for Approve Gate 1.** This requirement is explicit in the pasted request and in .agents/skills/orch-add-feature/SKILL.md, which says: “Stop at Gate 1 (plan approval) and Gate 2 (pre-commit).” No implementation starts before approval; no final Git commit occurs before Approve Gate 2.
