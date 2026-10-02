# Gate 2 — Phase 2B.1 Regional Preferences

Status: Gate 2 approved; final full regression passed. The approved Phase 2B.1 paths are staged for local commit on customization-update.

## 1. Implemented

Per-user Timezone, Date Format, Time Format and Number Format settings; configuration-backed Regional controls; missing-row defaults without insertion; safe omission of regional values by legacy clients; full reset; overall revision increments without changing sidebar_revision on regional-only saves. Eight approved dashboards use explicit presentation formatting. Phase 1/2A behavior remains intact.

## 2. Phase 2B.1 Files

### Created

- app/Services/RegionalDisplayFormatter.php
- config/regional_display.php
- database/migrations/2026_09_29_000001_extend_user_preferences_for_regional_preferences.php
- tests/Unit/RegionalDisplayFormatterTest.php
- tests/Feature/UserRegionalPreferencesTest.php
- tests/Feature/RegionalDashboardRenderingTest.php
- tests/Feature/RegionalScopeProtectionTest.php
- tests/Feature/RegionalSettingsUiTest.php
- tests/js/regional-preferences.test.mjs
- tests/Fixtures/regional-display-cases.json
- tests/Fixtures/regional-date-cases.json

### Intentionally modified

- resources/views/accounting/dashboard.blade.php
- resources/views/purchasing/dashboard.blade.php
- resources/views/profile/customization.blade.php
- resources/views/admin/dashboard.blade.php
- app/Http/Controllers/UserPreferenceController.php
- app/Providers/AppServiceProvider.php
- app/Services/UserPreferenceService.php
- app/Http/Requests/UpdateUserPreferenceRequest.php
- app/Models/UserPreference.php
- config/user_preferences.php
- resources/views/qc/dashboard.blade.php
- resources/views/ga/dashboard.blade.php
- resources/views/finance/dashboard.blade.php
- resources/views/supplier/dashboard.blade.php
- resources/views/local-supplier/dashboard.blade.php
- tests/Feature/UserCustomizationTest.php
- tests/Feature/UserPreferenceMigrationTest.php
- resources/views/layouts/app.blade.php
- tests/Feature/UserDashboardCustomizationTest.php

### Verification artifacts

- UI-REDESIGN-RESULT/PHASE-2B1-REGIONAL/GATE2-REPORT.md
- UI-REDESIGN-RESULT/PHASE-2B1-REGIONAL/browser-evidence.json

The existing Gate 1 planning document and other pre-existing untracked files are not new implementation work.

## 3. Existing Dirty Overlap

Only two production files overlapped previously dirty source:

| File | Pre-existing work | Phase 2B.1 addition |
|---|---|---|
| app/Providers/AppServiceProvider.php | BusinessTime bizdt directive, Blade import and existing import order | Regional formatter import/scoped binding and exact eight-dashboard composer |
| resources/views/layouts/app.blade.php | portal-scope meta, existing indentation/blank-line edits, notification polling timeout/dedup behavior | Early pure regional display helpers and AdasiPreferences API members |

Removing only regional additions from the final contents reproduced both captured starting files byte-for-byte. No unrelated hunk was rewritten. Other Phase 2B.1 modified files were clean at the captured baseline. The initial index was empty; after Gate 2 approval, only the approved 30 implementation/test files, two overlap hunks and two repository-policy delivery artifacts were staged.

## 4. Preference / Migration Contract

| Column | Type | Allowlist | Default |
|---|---|---|---|
| timezone | VARCHAR(64) | system, Asia/Jakarta | system |
| date_format | VARCHAR(16) | system, human, dmy, iso | system |
| time_format | VARCHAR(8) | system, 24h, 12h | system |
| number_format | VARCHAR(20) | system, international, indonesian | system |

Additive migration: database/migrations/2026_09_29_000001_extend_user_preferences_for_regional_preferences.php. Existing rows receive database defaults without a manual backfill. No users fields, indexes, dependencies or regional_revision were added. Down removes only the four regional columns; Phase 1/2A columns and preference rows remain. Re-up restores System defaults. Up/down/re-up and preservation assertions passed on the dedicated test database only. The application/development database was not migrated.

## 5. Formatter Contract

- Calendar dates use their source calendar components. Jakarta never shifts DATE values or adds hours.
- Timestamp/time methods require DateTimeInterface; immutable clones convert real instants to Jakarta. Explicit Jakarta adds WIB; System retains the source timezone and original legacy profile. Date-only timestamp profiles do not gain a time from a 12h/24h setting.
- Numbers consume trusted already-formatted numeric strings plus trusted source-profile keys. Only grouping/decimal separators change. No float conversion, extra rounding, fractional scale normalization or currency/unit ownership is introduced.
- System returns numeric text byte-for-byte, including placeholders, sign, negative zero and whitespace. Unknown stored preferences fall back to defaults.
- Existing number_format, NumberFormat, Money and callsite toFixed/toLocaleString behavior remain authoritative for precision. Currency prefixes, percentages, units, M/jt abbreviations and thresholds stay with callers.
- PHP and early JS helpers share numeric/calendar golden fixtures. JS date-only formatting uses ISO components rather than Date parsing. Helpers execute before affected inline initializers and require no browser regional cache.

## 6. Migrated Surfaces

| Template | Presentation changed |
|---|---|
| profile/customization.blade.php | Four native selects, static examples, old input and associated validation errors |
| admin/dashboard.blade.php | Counts, FX text and valid_from calendar date; missing-rate '-' fallback retained |
| purchasing/dashboard.blade.php | Metrics, FX, valid_from update date, ETA calendar date and selected numeric chart callbacks |
| finance/dashboard.blade.php | Visible metrics, amount text, forecast range labels, batch created_at timestamp and selected chart callbacks |
| accounting/dashboard.blade.php | Metrics and lifecycle counts only |
| qc/dashboard.blade.php | Metrics, inspected_at instant and selected chart numeric callbacks |
| ga/dashboard.blade.php | Claim metrics, claim_date calendar label and existing amount text |
| supplier/dashboard.blade.php | Metrics and PR/PO created_at event-instant display |
| local-supplier/dashboard.blade.php | Invoice-state metric counts only |

All paths above are under resources/views/. Phase 2A widget visibility/order/required-panel contracts and original actions remain intact.

## 7. Deferred Surfaces

Phase 2B.2 lists/details, operational DataTables presenters, shared invoice tables/details, export-progress UI and contractual invoice/tax displays were not migrated. PDF/receipt/print/export/import, email/notification/integration, language/localization and business-calculation code were not changed. BusinessTime, NumberFormat, Money, config/app.php, config/database.php and forecast services remain untouched by this task. Captured protected-contract hashes remained identical.

## 8. Tests Executed

Completed final verification commands (PowerShell quoting shown for filter arguments; execution used explicit argument arrays where applicable):

```powershell
php artisan test --filter=TestingEnvironmentDatabaseSafetyTest --compact --do-not-cache-result
php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml --filter 'UserRegionalPreferencesTest|RegionalDisplayFormatterTest|RegionalSettingsUiTest|RegionalDashboardRenderingTest|RegionalScopeProtectionTest|UserPreferenceMigrationTest' --do-not-cache-result
php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml tests/Feature/RegionalScopeProtectionTest.php --do-not-cache-result
php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml --filter 'UserRegionalPreferencesTest|RegionalDisplayFormatterTest|RegionalSettingsUiTest|RegionalDashboardRenderingTest|RegionalScopeProtectionTest|UserPreferenceMigrationTest|UserCustomizationTest|QuickAccessTest|UserDashboardCustomizationTest|UserDashboardRenderingTest|UserDashboardUiTest|UserPreferenceConcurrencyTest' --do-not-cache-result
node --test tests/js/regional-preferences.test.mjs tests/js/preferences.test.mjs tests/js/calendar.test.mjs tests/js/unsaved-changes.test.mjs
php artisan route:list --no-ansi
php artisan view:cache
npm.cmd run build
php vendor/bin/pint --test tests/Feature/RegionalSettingsUiTest.php tests/Feature/RegionalScopeProtectionTest.php
php -l tests/Feature/RegionalDashboardRenderingTest.php
php vendor/bin/pint --test tests/Feature/RegionalDashboardRenderingTest.php
composer test
git diff --check
```

Scoped git diff --check also ran against all 19 intentionally modified files. All 11 new source/test/fixture files were checked for trailing whitespace. Backend php -l ran for its 13 files plus AppServiceProvider (14 syntax checks); scoped Pint ran against those 13 feature files, excluding AppServiceProvider to preserve unrelated dirty formatting.

Temporary fixture verification executed PHPUnit against RegionalDashboardBrowserTest.php and RegionalSettingsBrowserTest.php copies in a system temporary directory, with phpunit.xml and REGIONAL_BROWSER_FIXTURE_DIR. They captured genuine test HTTP responses and were served through a GET-only fixture server. No application routes or database were modified for browser verification.

RED evidence: initial formatter/persistence 17 expected failures; UI 3 expected failures; original dashboard 11 expected failures. Intermediate fixture/expectation failures were corrected (simultaneous separator mapping, fresh model attribute baselines and complete base preference fixture). Incomplete/timeout wrapper attempts are not counted as passes; subsequent logged direct PHPUnit runs supersede them.

## 9. Results

| Verification | Result |
|---|---|
| Database safety | PASS — 2 tests, 4 assertions; backend probe independently verified configured and SELECT DATABASE() = adasi_portal_test |
| Integrated regional acceptance | PASS — 50 tests, 427 assertions; 2 pre-existing PHPUnit metadata deprecations |
| Final affected preference suites after compatibility/focus fixes | PASS — 88 tests, 725 assertions, 178.409 seconds; 2 pre-existing metadata deprecations |
| Scope protection after final DECIMAL assertions | PASS — 3 tests, 16 assertions |
| Temporary real-render browser fixtures | PASS — 26 tests, 147 assertions |
| Integrated JavaScript suite | PASS — 46 tests |
| Route list | PASS — 352 routes |
| View cache | PASS — all Blade templates compiled |
| Frontend build | PASS — no dependencies added; existing logo asset warning remains runtime-resolved |
| Scoped Pint/PHP lint | PASS after one Regional UI test import/separation formatting correction |
| Phase 2B.1 diff | PASS — no new whitespace failures |
| Whole-tree diff | FAIL from the two known unrelated pre-existing whitespace findings only |
| Final composer test | PASS — 1,140 tests, 11,191 assertions, 718.75 seconds, exit code 0. Two PHPUnit doc-comment metadata deprecation warnings and the existing .phpunit.result.cache write-permission warning did not fail tests. |
| Development/staging/production migration | SKIPPED — outside authorized task; only dedicated test DB was mutated |
| Real-user live save/login transport browser test | SKIPPED — fixture-based browser QA plus real Laravel feature requests were used to protect development DB |

No coverage percentage was measured or claimed.

## 10. Performance Evidence

Each of the eight dashboard rendering tests measured exactly one user_preferences lookup. Customization GET also measured one. Resolving defaults and running 30 repetitions each of date/number formatting retained a total of one preference query; formatter invocations added zero queries. The formatter operates on a scoped in-memory resolved array. No Redis/global cache or per-row Eloquent preference lookup was added.

## 11. Code Review

Independent targeted review covered regional backend, migration, bootstrap, form and eight dashboard hunks. One HIGH regression was found: nullable Admin FX valid_from was passed to a nonnullable formatter. It was fixed with the original null guard/'-' fallback and System/regional missing-rate tests. No unresolved CRITICAL/HIGH findings remain. Full regression also identified the Phase 2A payload-key test contract that required its approved additive regional keys; the expected whitelist was corrected without relaxing sensitive-field boundaries.

## 12. Security Review

Authenticated owner remains the only ownership source. String allowlists reject arbitrary timezone/format expressions, arrays and forged separators. Safe request fields exclude owner/revisions/security attributes; existing transactions/user locks/unique constraints and CSRF/auth routes remain intact. Stored forged values normalize safely. Frontend payload contains only trusted regional keys/registry presentation metadata and existing safe preference fields, serialized with @js. Output remains escaped Blade/text rendering. No global timezone/locale mutation or personal-formatted value flows into business input. Targeted reviewer found no unresolved CRITICAL/HIGH security issue.

## 13. Accessibility Review

Regional controls have fieldset/legend, explicit labels, native keyboard behavior, help/examples and per-field error IDs/aria-invalid. Browser verification observed 44px controls, ArrowDown selection, Tab from Timezone to Date Format and visible focus outlines using the existing ui-input-focus token. Static examples distinguish event instants from calendar-only values, require no color and never move focus or persist preview state.

A browser-only accessibility finding was remediated: the generic primary ring had 2.56:1 contrast on dark surfaces. Regional selects now reuse the existing preference-option focus outline; its dark contrast is 8.11:1. No CSS/token or unrelated component change was needed. Light help contrast 4.76:1; dark help 8.99:1; dark error 9.75:1; dark native select 11.20:1. Light error contrast is recorded in browser-evidence.json. Regional content fits 375px and 320px viewports and 200% zoom. A 3px whole-shell overflow was observed at zoom200/768px; the Regional section itself fits and remains usable. Full screen-reader/manual cross-browser testing was not performed. Existing semantic tokens, components and mobile sidebar behavior were preserved; no design-system redesign.

## 14. Scope Protection Evidence

Actual PO PDF Blade semantic output, PurchaseOrdersExport row arrays and voucher print HTML were identical after changing regional presets. Money rounding, NumberFormat precision, stored DECIMAL, stored timestamps, date-only values, query ordering and deadline result were unchanged. Finance rendering/runtime checks retained raw ISO start/end, raw amount 1250000.5, selectedMonth/period keys and chart datasets; visible text alone changed. Supplier role/context and required-panel regression coverage remains in the integrated/full suites. Protected helpers/config/forecast/shared invoice views/package files retained captured hashes.

## 15. Known Limitations

Rollout intentionally covers nine templates only; other interactive formatting awaits Phase 2B.2 approval. Timezone choices are only System/Jakarta. Browser QA used actual rendered test fixtures, not a real authenticated production/dev transport. Native select rendering was verified in Chrome only. Migration has not been applied to application/staging/production; deployment remains separate. Composer was rerun after both final changes and passed. Existing PHPUnit deprecations, result-cache write permission warning and the build-time logo warning are unrelated.

## 16. Diff Health

Known pre-existing findings remain:

- .env.example:148 — blank line at EOF.
- resources/views/purchasing/po/show.blade.php:661 — trailing whitespace.

No new Phase 2B.1 whitespace issue. Existing AppServiceProvider/layout dirty hunks are byte-preserved after subtracting only regional additions. The staged diff contains 32 approved paths and passes git diff --cached --check. All unrelated dirty work remains unstaged. No commit, push, merge, reset, clean, stash, branch switch or dependency change has occurred.

## 17. Proposed Commit

feat: add regional display preferences

STOP: waiting for Approve Gate 2 — Phase 2B.1. No commit is authorized before that approval.
