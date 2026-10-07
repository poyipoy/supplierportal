# Phase 2 User Customization — Gate 1 Plan

**Status:** Gate 1 proposal for review; no Phase 2 implementation is authorized yet.
**Repository/branch:** ADASI Portal Supplier, customization-update.
**Work performed:** planning only. No app source, migrations, tests, database state, or commits were changed.
**Recommendation:** Phase 2A covers dashboard layout customization and trusted accent presets. Phase 2B regional presentation preferences are deferred pending a bounded interactive-surface inventory. Notification opt-outs and language selection are deferred for the reasons below.

## 1. ECC execution strategy and scope

Use orch-add-feature as the primary workflow. Continue through implementation, focused TDD, integration, code review, security review, accessibility/design-system verification, and stop at Gate 2. Do not invoke orch-pipeline separately if orch-add-feature delegates to it.

Use laravel-patterns only for preference schema/model/service/request/routes and resolution. Use laravel-tdd for persistence, authorization, concurrency, registry, and browser-preference tests. Use laravel-security for ownership, safe registry resolution, role/context boundaries, validation, serialization, and CSRF. Use accessibility/design-system guidance only for changed customization controls, dashboard surfaces, accent tokens, and chart chrome. No broad skill discovery, unrelated audit, redesign, new dependencies, commit, push, merge, or database mutation is in scope.

This plan covers user customization only. Dashboard settings never suppress access to original navigation or change routes, roles, policies, supplier scope, or workflows. Do not split Profile & Security or implement later roadmap phases.

## 2. Phase 1 baseline observed

Phase 1 is present as uncommitted work on the existing branch. The implementation observed includes the user_preferences table/model and User relationship; centralized defaults; UserPreferenceService; trusted Quick Access config/resolver; authenticated customization routes/controller/FormRequest; customization view; shared frontend preference bootstrap; theme/density/sidebar behavior; shared DataTables defaults; and associated tests.

The present preference contract covers theme, density, sidebar state, page size, Quick Access, and revision. Missing preference rows resolve defaults without inserting a row. Quick Access uses trusted keys filtered by role and supplier context. This plan makes no claim that Phase 1 has passed Gate 2, been committed, or been deployed.

Compatibility finding: sidebar LocalStorage reconciliation uses the overall preference revision. Dashboard/accent saves that increment this revision could reset a device-specific sidebar override. Phase 2A must add an independent sidebar revision/token or equivalent isolated contract, and prove unrelated preference changes do not invalidate the device override. Preserve existing Phase 1 behavior and dirty hunks.

Reconcile the exact Phase 1 file inventory against the live tree before implementation. No Phase 1 file is modified by this plan.

## 3. Dashboard inventory

All eight role/context dashboards are separate Blade pages. No persisted dashboard layout or shared trusted widget registry exists. Controllers calculate dashboard data before Blade renders it, so hiding a block in the browser alone will not avoid its query work.

| Audience and implementation | Current workflow-critical content | Candidates and constraints |
|---|---|---|
| Admin: admin routes in routes/web.php; AdminController; resources/views/admin/dashboard.blade.php | Original navigation and exchange-rate administration | Recent notifications, summary, and shortcut panels may be configurable if shortcuts remain reachable from unchanged navigation. Keep exchange-rate controls visible unless confirmed optional. |
| Purchasing: purchasing.dashboard in routes/web.php; PurchasingController; resources/views/purchasing/dashboard.blade.php | Operational exception queue, arrival follow-up, create actions | Exception and arrival worklists stay required. KPI, chart/rate reference, and latest PR panels are candidates. Preserve PurchasingNavigation listUrl/return-filter behavior. |
| Finance: finance.dashboard in routes/finance.php; FinanceDashboardController, InvoiceQuery, PaymentForecastService; resources/views/finance/dashboard.blade.php | Invoice status/actions and payment forecast | Keep status visibility and forecast required. Recent batches/invoices are candidates only if navigation keeps their workflows discoverable. Audit query shape before conditionally skipping optional data. |
| Accounting: accounting.dashboard in routes/accounting.php; Accounting InvoiceController; resources/views/accounting/dashboard.blade.php | Core invoice status and recent invoice access | Keep status and invoice access required. Lifecycle strip may be optional if confirmed informational. Preserve the explicit role list. |
| QC: qc.dashboard in routes/web.php; Qc DashboardController; resources/views/qc/dashboard.blade.php | Waiting-inspection alert and inspection queue | Keep both required. Metrics/charts may be configurable. Controller currently hydrates all inspected rows and derives some aggregates in PHP; measure before considering a bounded query change. |
| GA: ga.dashboard in routes/ga.php; GA dashboard controller; resources/views/ga/dashboard.blade.php | Claim state, create-claim and DRP draft actions | Keep status and workflow actions reachable. Recent-claim display is a candidate only if claim navigation remains obvious. |
| Supplier Import: supplier.dashboard in routes/web.php behind supplier.scope:import; SupplierController; resources/views/supplier/dashboard.blade.php | Own outstanding quotations and pending claim responses | Keep worklists required. KPIs and announcements/support may be configurable. Retain supplier ownership filtering and active Import context. |
| Supplier Local: local dashboard in routes/supplier-local.php behind supplier.scope:local; Local Invoice controller/InvoiceQuery; resources/views/local-supplier/dashboard.blade.php | Own invoice status, list, and submission | Keep these required. Company/tax summary may be configurable if profile action remains reachable. Retain supplier_id ownership filtering. |

Use stable keys prefixed by audience/context, such as admin.*, purchasing.*, finance.*, accounting.*, qc.*, ga.*, supplier.import.*, and supplier.local.*. The key is not authorization. Eligibility comes from authenticated route/middleware, role, and active PortalContext. Supplier Import and Local layouts are separate.

## 4. Regional formatting inventory

The source scan found about 248 number_format calls in 58 Blade views, 178 direct date/time format calls in 71 views, and 27 BusinessTime::format callsites in 11 views. Other paths use NumberFormat::maxDecimals and JavaScript locale formatting. The app stores database timestamps in UTC and uses configured business time, default Asia/Jakarta, for business date calculations.

These callsites span interactive UI and durable financial/legal documents, PDFs, receipts, exports, emails, and business records. Preferences must not change calculations, stored values, sort keys, deadlines, accounting/legal artifacts, email content, integrations, exports, or date-only values. Broad search-and-replace or a global locale override is unsafe.

Defer regional settings to Phase 2B. Before that Gate, inventory each candidate and classify it as interactive presentation, date-only business value, financial/legal output, or integration/export. Only explicit interactive presentation callsites may migrate. Proposed allowlisted settings should preserve current behavior by default: supported business timezones only; date system/human/DMY/ISO; time system/24-hour/12-hour; number system/international/Indonesian. Reject arbitrary IANA zones and user-supplied patterns. Existing domain formatters continue to govern precision and calculations.

## 5. Design system and accent

The existing semantic token system remains authoritative: semantic values in resources/css/app.css, the Bootstrap bridge, Tailwind semantic mappings, Light/Dark values, focus/link/button/calendar tokens, and token-aware chart colors. Current primary brand seed is #1F5FA6.

Accent is a trusted preset key, never arbitrary CSS or a raw user color. Default retains the current brand blue. Any additional restrained presets must be derived from existing brand/secondary scales and checked for foreground, focus, link, Bootstrap control, chart-chrome, Light, and Dark contrast. Accent may change primary/action chrome only; error, warning, success, and other status semantics remain fixed. Preserve chart data, business-series meanings, formatters, and callbacks.

Expose only the preset key and server-owned tokens in the safe frontend bootstrap. Theme/accent preview stays in memory/DOM until Save; do not persist unsaved preview in LocalStorage.

## 6. Notifications and localization

Current notification-center filters are all, chat, quotation, document, invoice, and other. They are not opt-out policy. NotificationDomain separates global/import/local and uses active context for dual-scope suppliers. System notifications use database and broadcast. Local invoice flows also send operational email for submission/resubmission, revision, payment, and physical-delivery reminders. Password reset and security notifications have distinct delivery behavior; auth-security mail is environment-gated.

There is no complete trusted event-by-channel registry or opt-out policy. A new-device notification can fall through to generic other/import classification, which may misclassify a local-only supplier. Characterize and fix that classification in a security-scoped change before adding notification controls. Do not use broad center categories as preference keys.

Defer notification opt-outs until a separate Gate approves an event/category × channel matrix with mandatory flags, recipient/domain rules, and defaults. Password reset, account/security, repeated lockout, critical invoice/payment, and other mandatory operational events must not become suppressible by default. Decide JSON versus normalized storage only after that policy matrix is stable. No notification schema is authorized here.

Language selection is not ready: app locale/fallback is English, only four Laravel vendor language files exist, there is no application translation catalog/resolver/middleware, and most UI copy is hardcoded. Import is contractually English-first; Local Supplier is Indonesian-first. A toggle now would create incomplete mixed-language UI. Defer until translation catalogs, fallback, role/domain copy policy, notification translation, and coverage are designed.

## 7. Gap analysis

| Requirement | Gap | Decision |
|---|---|---|
| Per-user dashboard layout | No stable widget registry or persisted layout | Add trusted registry and per-audience layout in Phase 2A |
| Required workflow content | Dashboards mix queues/actions and summaries | Required entries stay visible; only explicitly configurable entries can hide/reorder |
| Role/context resolution | Routes use distinct role lists and supplier context | Resolve from authenticated route/middleware and PortalContext, never client audience |
| Optional widget cost | Some controllers compute all content before rendering | One request-scoped resolution; skip optional queries only when cleanly separable and measured |
| Accent | Semantic token system exists, preference does not | Add trusted preset key mapped to semantic tokens |
| Sidebar compatibility | Overall revision versions device-local state | Separate sidebar revision/token |
| Regional settings | Hundreds of mixed UI/durable-output callsites | Defer and migrate only audited interactive surfaces |
| Notification preferences | No safe channel/mandatory event policy | Defer registry, UI, and persistence |
| Language | No complete translation foundation | Defer language selector |

## 8. Phase 2A/2B recommendation

**Phase 2A — recommended for this Gate 1 approval:** dashboard layout customization for the eight current audiences/contexts; trusted accent presets; sidebar revision compatibility; safe preview, persistence, tests, and focused reviews. Required worklists/actions and original navigation remain available.

**Phase 2B — deferred and requiring a separate implementation approval:** regional presentation preferences with an audited interactive-only formatter migration. Notification opt-outs may be considered only after the trusted channel/event policy and new-device classification issue are resolved.

Language selection is deferred beyond these phases until localization readiness criteria are met. The split follows actual blast radius: dashboard layout has a finite registry across eight surfaces; regional formatting crosses hundreds of mixed-purpose callsites; notification settings have no safe opt-out contract. Gate 1 approval should authorize Phase 2A only.

## 9. Architecture and data contract

Extend user_preferences and UserPreferenceService; do not add customization fields to users or create a generic settings/cache subsystem.

| Phase 2A field | Meaning/default |
|---|---|
| accent | Stable preset key; default brand, preserving current appearance |
| dashboard_preferences | JSON mapping audience/context to hidden stable keys and order keys; default empty, registry supplies defaults |
| sidebar_revision | Independent version for device-local sidebar reconciliation; default 1 |

Continue using the existing overall revision for saved preference versioning. Use account ID plus sidebar_revision for LocalStorage reconciliation. Increment sidebar_revision only when sidebar_state changes or reset changes it. Dashboard/accent saves must not invalidate a valid device override. Migration must be additive, default existing rows safely, and preserve every Phase 1 field.

Conceptual dashboard shape: audience → {hidden: [stable keys], order: [stable keys]}. A trusted server registry defines label, audience/context, required/configurable state, and default order. Read/write normalization discards unknown, duplicate, revoked, and ineligible keys; required entries are forced visible; new keys append in default order. The browser never submits labels, routes, views, URLs, CSS, role, or active context.

Resolve audience from a fixed server route mapping, authenticated middleware result, and current PortalContext. No valid supplier context means no operational supplier widget choices. Admin does not implicitly bypass middleware. Destination routes and policies remain authoritative.

Use existing service patterns for defaults, request-local reuse, save/reset, and safe frontend output. Save/reset operate on the current authenticated owner in a transaction, preserve unique constraint and first-save concurrency handling, and increment revision after state changes. Reset writes explicit defaults, clears all layouts and Quick Access, and changes only preference/revision columns. It must not touch profile, role, password, account status, 2FA, or sessions; repeated reset is safe.

Render only trusted server-owned Blade sections/components. No request-derived dynamic view/include path. Resolve preferences/registry once per request; no query per widget or global eager loading.

## 10. Implementation slices and TDD order

**A — defaults/schema/resolution.** RED: missing-row defaults, migration shape, safe frontend serialization, and sidebar token fail. GREEN: config, additive migration, casts/fillable, request-local resolution. REFACTOR: centralize allowlists and verify one preference query.

**B — trusted dashboard registry.** RED: forged keys, cross-role/context layouts, duplicate keys, required hiding, and request-supplied route/view fail. GREEN: server-owned registry, audience resolver, normalization. REFACTOR: bounded deterministic resolution.

**C — save/reset security and concurrency.** RED: guest access, ownership, invalid values, forbidden fields, concurrent first save, reset, and revision behavior fail. GREEN: authenticated route, dedicated FormRequest, owner-derived transactional save/reset. REFACTOR: confirm CSRF and middleware unchanged.

**D — UI/live preview.** RED: accessible controls, old input, validation, persistence, and preview non-persistence fail. GREEN: controls for configurable keys and accents using existing components; keyboard move-up/down and visibility checkboxes. REFACTOR: preview is ephemeral and Save alone persists.

**E — dashboard integration.** Integrate Admin, Purchasing, Finance, Accounting, QC, GA, Supplier Import, Supplier Local one at a time. Characterize each first; mark only approved surfaces; keep queues/actions intact; run its focused tests. Preserve shared invoice contracts, PurchasingNavigation return filters, supplier ownership/context.

**F — accent/tokens/charts.** RED: allowlist, contrast, Bootstrap state, preview, safe serialization, and chart-chrome refresh fail. GREEN: map preset to semantic values before visible render. REFACTOR: preserve data, series, and status semantics.

**G — sidebar compatibility.** RED: unrelated save resets a matching device override; changed sidebar revision fails to resync; reset fails to sync browser. GREEN: use account-scoped sidebar revision and update current-browser cache on save/reset. REFACTOR: LocalStorage failures remain non-fatal and mobile focus/Escape/overlay/inert/tooltip behavior remains intact.

**H — integration and reviews.** Run combined relevant suites and final approved regression/build checks. Review only Phase 2 files and directly affected contracts. Resolve Critical/High issues before Gate 2.

Phase 2B regional formatting and notification controls are not implementation slices approved here.

## 11. Expected Phase 2A files

Confirm exact current source and dirty hunks before editing. Expected new files:

- config/dashboard_widgets.php — stable keys, audience/context, labels, required/configurable status, default order.
- app/Services/Dashboard/DashboardWidgetService.php — only if no existing resolver can own bounded registry normalization.
- additive migration extending user_preferences for accent, dashboard_preferences, sidebar_revision.
- focused dashboard customization feature tests; extend existing preference/concurrency suites rather than duplicate them.
- focused JS tests only if new client behavior is introduced.

Expected intentional modifications:

- UserPreference and User relationship only if required; UserPreferenceService, UpdateUserPreferenceRequest, UserPreferenceController, config/user_preferences.php.
- Existing authenticated customization routes, layout/bootstrap, profile customization view, preferences JS, semantic CSS/Tailwind bridge, chart theme only where needed.
- The eight dashboard views in the inventory; controllers only if hidden optional data can be cleanly and measurably skipped.
- Focused PHP/JS tests for changed contracts.

Do not modify notification classes, localization catalogs, PDF/receipt/export/email formatting, Phase 2B regional callsites, package manifests, or unrelated dashboards.

## 12. TDD and acceptance matrix

| Area | Minimum evidence |
|---|---|
| Defaults/schema | Existing user without a row gets defaults without insert; JSON cast works; FK/unique/cascade remain; Phase 1 values survive additive migration. |
| Auth/ownership | Guest denied; authenticated user reads/saves/resets; A cannot affect B; payload cannot alter role/password/profile/status/2FA/session/owner/revision. |
| Accent | Only registered keys accepted; arbitrary color/CSS rejected; safe serialization; reset returns brand; accent save preserves sidebar override. |
| Registry | Known eligible keys accepted; unknown/duplicate/arbitrary route/view/URL/icon/label rejected or normalized; required keys cannot hide; new key defaults safely. |
| Role/context | Each route uses its actual role list; no implicit admin bypass; Supplier Import/Local separation; no valid context gives no operational choices; stale role/scope/context keys are filtered. |
| Save/reset/concurrency | Transactional owner update; unique constraint handles concurrent first save; revision advances on changes; explicit reset clears layouts/Quick Access, preserves auth/profile, and is safe twice. |
| Rendering | Required queues/actions remain for all audiences; optional saved order/visibility works; original navigation remains; finance/accounting shared views and supplier ownership remain correct. |
| Sidebar/preview | Matching account/sidebar revision preserves device override; changed token resyncs; account keys isolated; blocked LocalStorage non-fatal; unsaved preview never persists and history returns to saved state. |
| Performance | At most one preference lookup per relevant request; bounded resolver; no per-widget lookup/N+1; query work skipped only for separable hidden optional sections. |
| Migration | Verify actual test DB before RefreshDatabase/migration tests; up/down/re-up only on dedicated test DB; rollback drops only Phase 2A columns and preserves Phase 1 data. |

Use existing PHPUnit/MySQL and Node test infrastructure; no new dependencies. Run DB suites serially unless isolated test databases are verified.

## 13. Security review

- Derive owner only from authenticated session; never accept user ID, role, supplier scope, audience, revision, route, view, URL, label, icon, HTML, or CSS from client.
- Preserve web auth, CSRF, role middleware, Supplier PortalContext middleware, and destination policies.
- Validate accent and dashboard keys against server-owned registries; labels/views are server-owned and escaped; frontend JSON uses safe serialization.
- Visibility is presentation only; original navigation and route protections remain.
- Re-evaluate role, account eligibility, supplier scope, and context each request; prune stale keys.
- Keep owner and revision assignment internal; use transaction, unique constraint, and existing concurrency approach.
- Reset only preference fields; never authentication/profile/security fields.
- Do not expose notification opt-outs before mandatory channel policy is established.

## 14. Accessibility and design-system verification

Use explicit labels, fieldsets/legends, text state, visible focus, and errors associated with controls. Make visibility/order keyboard operable; use named move-up/down buttons rather than drag-only controls. Announce save/reset/validation results accessibly. Ensure hiding a panel removes its descendants from keyboard/accessibility navigation and that the user can restore hidden optional panels.

Check Light/Dark accent contrast for text, focus, links, Bootstrap controls, and chart chrome at narrow viewport and 200% zoom. Do not use color alone. Preserve destructive/password/dialog/mobile target sizes. Keep required workflow panels clear in DOM and reading order; preserve existing mobile sidebar focus behavior.

## 15. Migration, rollback, and database safety

Phase 2A migration is additive to user_preferences: accent key, JSON dashboard layout, and sidebar_revision default. No users-table fields or row backfill. Preserve Phase 1 data and user FK/unique/cascade. Rollback drops only columns introduced by the Phase 2A migration; this necessarily discards values stored in those columns, which must be recorded in deployment rollback planning.

Confirm MySQL JSON/column behavior from repository migrations and the dedicated test connection. No migration, rollback, seed/reset, or application/development database mutation was run or authorized during Gate 1. Production deployment is out of scope.

## 16. Performance plan

Establish focused query baselines per dashboard. Target at most one preference read per relevant request. Registry normalization is bounded by configured keys and cached only as immutable application config; no Redis or global user cache.

Avoid loading data for hidden optional blocks only when that query is separable and no required workflow shares it. Measure QC's all-inspected-row hydration and Finance forecast collection before changing either. Use query-count/list assertions if a stable test mechanism exists; report measurements, not assumptions. Render layout server-side without waiting for JavaScript. Keep early accent bootstrap small and safe.

## 17. Verification plan before Gate 2

Run focused tests per slice, then combined suites and the broader approved regression. Adjust names to suites actually present and record exact commands/results:

    php artisan test --filter=TestingEnvironmentDatabaseSafetyTest
    php artisan test --filter=UserDashboardCustomizationTest
    php artisan test --filter=UserPreferenceTest
    php artisan test --filter=UserPreferenceMigrationTest
    php artisan test --filter=UserPreferenceConcurrencyTest
    php artisan test tests/Feature/ProfileTest.php
    php artisan test --filter=SidebarShellTest
    php artisan test --filter=FrontendAssetLoadingTest
    php artisan test --filter=HashidUrlSecurityTest
    php artisan test --filter=SupplierDataIsolationTest
    php artisan test --filter=SupplierPortalContextV2Test
    php artisan test tests/Feature/LocalInvoice/LocalInvoiceScopeIsolationTest.php
    node --test tests/js/preferences.test.mjs
    node --test tests/js/dashboard-customization.test.mjs
    php artisan route:list --no-ansi
    php artisan view:cache
    npm.cmd run build
    git diff --check
    composer test

Run composer test only at final regression if runtime permits. Run database tests only after proving the actual connection is the dedicated test DB. No tests, migration commands, or database checks were executed for this planning-only Gate 1.

Manual Gate 2 browser checks: all eight role/context dashboards; desktop/narrow layouts; accent preview/save/reset; keyboard visibility/order; focus after hide/reorder; role/context changes; disabled LocalStorage; two accounts in one browser; and browser back/history after unsaved preview. Report manual and automated evidence separately.

## 18. Existing dirty-file overlap and preservation

Relevant pre-existing dirty files observed include AdminController, FinanceDashboardController, PurchasingController, SupplierController, InvoiceQuery, NewDeviceLoginNotification, config/app.php, resources/css/app.css, tailwind.config.js, resources/js/app.js, routes/web.php, and untracked app/Support/BusinessTime.php. Phase 1 preference files/tests/migration and the user's Phase 2 source plan are also uncommitted.

Dashboard/controller files contain existing business-time changes. Before any implementation edit, inspect the target source and exact diff hunk; preserve unrelated content. Do not reset, clean, stash, revert, switch branch, or attribute the full repository diff to Phase 2. Do not edit IMPLEMENTATION-PLAN-PHASE-2-CUSTOMIZATION.md. This Gate 1 report is the only file this planning task adds.

Known unrelated diff-check findings in .env.example and resources/views/purchasing/po/show.blade.php remain untouched unless Phase 2 directly modifies the same lines. Gate 2 must distinguish pre-existing findings from new Phase 2 issues.

## 19. Gate 1 recommendation and stop condition

Approve for implementation: Phase 2A dashboard layout customization and trusted accent presets, including the independent sidebar revision compatibility fix, scoped as above.

Defer Phase 2B regional preferences until the interactive-only callsite list and supported surfaces are approved. Defer notification preferences until the event/channel matrix, mandatory policy, and new-device classification issue are resolved. Defer language selection until localization coverage and fallback behavior are ready.

No application source, migration, test, database state, or commit was changed by this plan. Stop here and wait for the exact approval:

**Approve Gate 1 — Phase 2**
