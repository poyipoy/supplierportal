# Phase 7 — Language Preference & Full Internationalization

## Status

**IMPLEMENTATION PLAN**

Target repository:

`C:\laragon\www\adasi_portal_supplier`

Target branch:

`customization-update`

Execution environment:

**Codex**

Recommended skill stack:

- `[$orch-add-feature](C:\laragon\www\adasi_portal_supplier\.agents\skills\orch-add-feature\SKILL.md)`
- `[$search-first](C:\laragon\www\adasi_portal_supplier\.agents\skills\search-first\SKILL.md)`
- `[$laravel-patterns](C:\laragon\www\adasi_portal_supplier\.agents\skills\laravel-patterns\SKILL.md)`
- `[$laravel-tdd](C:\laragon\www\adasi_portal_supplier\.agents\skills\laravel-tdd\SKILL.md)`
- `[$laravel-verification](C:\laragon\www\adasi_portal_supplier\.agents\skills\laravel-verification\SKILL.md)`
- `[$laravel-security](C:\laragon\www\adasi_portal_supplier\.agents\skills\laravel-security\SKILL.md)`
- `[$accessibility](C:\laragon\www\adasi_portal_supplier\.agents\skills\accessibility\SKILL.md)`

Execution principles:

**Evidence → Correctness → Verification → Simplicity → Maintainability**

**Understand first → Change minimally → Verify explicitly**

---

# 1. Phase 7 Goal

Implement a complete, account-level **Language Preference / Internationalization** capability across the Supplier Portal.

Approved language set:

- **English**
- **Bahasa Indonesia**

Approved default:

- **English**

Approved availability:

- **All roles / all supported user audiences**

Approved coverage:

- **Entire user-facing portal**
- UI copy
- navigation
- auth
- profile
- security
- notifications
- customization
- dashboards
- purchasing
- finance
- accounting
- QC
- GA
- supplier import
- supplier local
- validation messages
- flash messages
- empty states
- modal text
- confirmation copy
- button labels
- breadcrumbs
- tables / DataTables
- pagination
- operational/domain terminology
- in-app notification text
- Password Assistance copy/template
- accessibility labels
- generic error messages
- JavaScript-originated UI copy
- server-rendered UI copy

Migration strategy:

**Migrate all user-facing hardcoded copy in one Phase 7 implementation.**

No partial module rollout.

No Phase 7A / 7B / 7C.

---

# 2. Product Decisions — Locked

## 2.1 Supported Languages

Exactly:

- `en` → English
- `id` → Bahasa Indonesia

Do not add browser locale auto-detection, regional locale variants, or additional languages in Phase 7.

## 2.2 Default Language

Default:

`en`

A user with no preference, stale preference, invalid preference, or a legacy account must see English.

## 2.3 Audience

Language preference is available to all authenticated roles supported by the portal.

Expected audiences include:

- Admin
- Purchasing
- Finance
- Accounting
- QC
- GA
- Supplier Import
- Supplier Local
- dual-scope supplier accounts where applicable

Gate 1 must verify actual repository role identifiers.

## 2.4 Coverage

Phase 7 internationalizes the entire user-facing application surface.

A hardcoded user-facing sentence/string discovered during Gate 1 is in-scope unless it is developer-only, log-only, audit-only internal metadata, a machine identifier, an external protocol value, third-party package source, or a database enum/status key not directly shown to users.

## 2.5 Delivery Strategy

The migration is performed **all at once**.

No temporary mixed-language production state is acceptable as the intended final Phase 7 result.

English and Indonesian coverage must both be complete for all in-scope strings.

---

# 3. Non-Goals

Phase 7 is not:

- a visual redesign,
- a translation-management platform,
- a CMS,
- machine translation,
- browser locale auto-detection,
- a Regional/date/number formatting rewrite,
- a BusinessTime project,
- a currency conversion project,
- a database-content translation project,
- an email-notification reintroduction project,
- an auth redesign,
- a notification-center redesign.

Language and Regional preferences remain separate concerns.

---

# 4. Locale vs Regional Preferences

Language preference controls text language, UI labels, validation messages, and translated domain terminology.

Regional preferences continue controlling timezone, date format, time format, and number format.

Example:

Language = Indonesian

Date Format = ISO

must remain valid.

Changing language must not silently rewrite Regional preferences.

---

# 5. Preference Model

Preferred account-level key:

`locale`

Approved values:

- `en`
- `id`

Default:

`en`

Gate 1 must inspect whether a locale field already exists, whether `UserPreference` already supports it, and whether locale middleware or `App::setLocale()` is already present.

Preferred direction:

reuse the existing `user_preferences` architecture.

Do not create a second preference system.

---

# 6. Migration Policy

Preferred result:

**NO MIGRATION**

if the existing schema can safely persist locale.

If a migration is genuinely required:

- stop at Gate 1,
- explain why,
- propose the smallest schema change,
- do not implement until explicit approval.

---

# 7. Locale Resolution Priority

Recommended order:

1. authenticated user's stored language preference,
2. application default,
3. fallback locale.

Approved default and fallback:

`en`

Do not use `Accept-Language` as a higher-priority source.

Do not use localStorage as the canonical account value.

---

# 8. Runtime Locale Application

Gate 1 must identify the safest Laravel-native point to apply effective locale.

Preferred concept:

authenticated web request
→ resolve current user language
→ `App::setLocale($locale)`

Potential implementations may use middleware, an existing request hook, or an existing preference pipeline, but Gate 1 must choose from repository evidence.

Avoid controller-by-controller locale setting.

---

# 9. Language Preference UI

Add language selection under:

`Profile → Customization`

Recommended section:

**Language**

Options:

- English
- Bahasa Indonesia

Preferred control:

native radio group or select consistent with the current Customization UI.

Requirements:

- visible labels,
- accessible description,
- current value clearly indicated,
- keyboard operable,
- server-side validation,
- no client-side-only persistence.

---

# 10. Save / Reset / Preservation

Preferred save path:

reuse the existing Customization update endpoint.

Changing language should take effect immediately after save/redirect.

Customization reset must restore language to `en` while preserving unrelated scoped state according to existing reset behavior.

Saving language must preserve:

- theme,
- accent,
- density,
- sidebar,
- rows/page,
- Quick Access,
- dashboard preferences,
- Regional preferences,
- Notification preferences.

Saving any other preference must preserve language.

---

# 11. Concurrency

Reuse existing:

- row locking,
- revision,
- transaction retry,
- cache invalidation.

Add representative concurrent save coverage, e.g. Language + Accent or Language + Notification/Regional.

Do not create another locking mechanism.

---

# 12. Translation Architecture

Use Laravel's native localization system unless repository evidence establishes another standard.

Preferred structure:

`lang/en/...`
`lang/id/...`

or the repository's existing equivalent.

Organize by domain/module instead of one giant flat file.

Recommended domains, subject to Gate 1 refinement:

- `common.php`
- `navigation.php`
- `auth.php`
- `profile.php`
- `security.php`
- `customization.php`
- `notifications.php`
- `dashboard.php`
- `purchasing.php`
- `supplier.php`
- `finance.php`
- `accounting.php`
- `qc.php`
- `ga.php`
- `local_invoice.php`
- `claims.php`
- `shipment.php`
- `documents.php`
- `validation.php`

---

# 13. Translation Key Policy

Keys must be stable and semantic.

Good examples:

- `common.actions.save`
- `auth.password_assistance.title`
- `local_invoice.status.ready_to_pay`
- `notifications.events.local_invoice_paid.label`

Avoid:

- full English sentences as keys,
- route names as translation keys,
- database IDs,
- CSS classes,
- UI-position-based keys.

---

# 14. Translation Quality Policy

English must preserve current validated business meaning.

Bahasa Indonesia must be professional, natural, and consistent—not literal machine-translation style.

Do not translate stable technical/product terms unnecessarily, such as:

- PO
- GR
- QC
- MFA / 2FA where established
- ADASI
- supplier codes
- invoice numbers

Gate 1 must create a terminology glossary.

---

# 15. Domain Terminology Glossary

Before implementation, Gate 1 must inventory recurring business terms and lock English/Indonesian equivalents.

At minimum include:

- Purchase Requisition
- Purchase Order
- Goods Receipt
- Quotation
- Material Progress
- Shipment
- Quality Control
- Claim
- Invoice
- Revision
- Rejected
- Approved
- Paid
- Ready to Pay
- Physical Delivery
- Supplier Registration
- Import Supplier
- Local Supplier
- Payment
- Refund
- Overpayment
- Settlement
- Dashboard
- Notification
- Security
- Active Sessions

Use one consistent glossary across all modules.

---

# 16. Hardcoded String Inventory

Gate 1 must perform a repository-wide inventory of user-facing copy.

Search:

- Blade literal copy,
- controller flash messages,
- request validation messages,
- service-generated user messages,
- notification titles/bodies,
- JS alert/toast strings,
- modal copy,
- table headings,
- empty states,
- DataTables labels,
- accessibility labels,
- `aria-label`,
- buttons,
- menu names,
- breadcrumb names.

Exclude logs, internal identifiers, package/vendor source, test descriptions, SQL, API field names, and other machine-only values.

---

# 17. Inventory Classification

Classify each discovered string:

| Source | Module | User-facing? | Dynamic? | Translation Domain | Action |
|---|---|---:|---:|---|---|

Actions may include:

- translate now,
- keep technical term,
- machine/internal — no translation,
- external package override,
- interpolation/pluralization required,
- glossary decision required.

---

# 18. Blade Translation

Replace hardcoded user-facing Blade copy with translation helpers.

Preferred:

`__('domain.key')`

or equivalent repository-standard usage.

Avoid locale conditionals in Blade.

Do not duplicate markup solely for each language.

---

# 19. Controller / Service Messages

Translate:

- success flash messages,
- validation-related business feedback,
- user-visible exceptions,
- redirect status messages,
- informational banners.

Prefer translating at display/generation time rather than persisting already-translated copy when stable event/code metadata exists.

---

# 20. Validation Localization

Phase 7 includes validation messages.

Gate 1 must inspect:

- Laravel default validation locale files,
- custom FormRequest messages,
- attribute labels,
- custom rules.

Target:

complete English and Indonesian validation output.

Translate user-facing attribute names where practical.

---

# 21. Auth Localization

Internationalize all current user-facing auth flows:

- Login
- Password Assistance
- 2FA confirmation/setup/recovery
- password confirmation
- rate-limit feedback
- authentication validation
- security-related generic messages.

Do not reintroduce removed automated reset-email or email-verification flows.

---

# 22. Password Assistance

Translate:

- page title,
- help copy,
- support label,
- subject label,
- template label,
- copy buttons,
- Open Email App,
- clipboard feedback.

The email template itself must be localized.

Locale `en` → English subject/body.

Locale `id` → Indonesian subject/body.

Support email destination remains config-controlled and language-independent.

No backend email send.

---

# 23. Profile / Security / Customization

Internationalize all visible copy in:

- My Profile,
- Account Information,
- Security,
- Change Password,
- 2FA,
- Active Sessions,
- Logout Other Devices,
- Theme,
- Accent Color,
- Density,
- Sidebar,
- Page Size,
- Quick Access,
- Dashboard layout customization,
- Regional preferences,
- Language selector,
- save/reset actions,
- helper copy.

Do not change underlying behavior.

---

# 24. Notification Preferences UI

Internationalize:

- page title,
- intro,
- categories,
- event labels,
- event descriptions,
- reset/save feedback.

If safe, move registry labels/descriptions from baked English text to translation keys.

No notification-delivery behavior change.

---

# 25. Runtime Notification Localization

Phase 7 includes notification title/body content.

Gate 1 must inspect whether notification payload stores rendered text, event key + data, or both.

Preferred final behavior:

each recipient receives notification content in that recipient's stored language at creation time.

For multi-recipient sends:

- English recipient → English content
- Indonesian recipient → Indonesian content

Do not use sender/request locale globally for all recipients.

Historical notification rows do not need retroactive translation unless current architecture already supports safe dynamic re-rendering.

---

# 26. Queue / Worker Locale Safety

Queued/background user-facing copy must resolve locale per recipient/job.

Long-lived workers must not leak one user's locale into another user's job.

If `App::setLocale()` is used in queued work, set/reset it safely per recipient/job.

Gate 1 must audit worker semantics.

---

# 27. Dashboard Localization

Internationalize all dashboard:

- panel titles,
- KPI labels,
- empty states,
- shortcuts,
- buttons,
- helper copy,
- role-specific widgets.

Dashboard registry labels should preferably become translation keys.

Do not modify widget composition/order logic.

---

# 28. Navigation Localization

Internationalize:

- sidebar,
- user dropdown,
- page navigation,
- breadcrumbs,
- module links,
- mobile navigation.

Role-based visibility must remain unchanged.

Only labels change.

---

# 29. Purchasing / Supplier / Finance / Accounting / QC / GA

Translate all user-facing copy in the operational modules.

This includes:

- Purchase Requisitions
- Quotations
- Negotiation / Conversations
- Purchase Orders
- Import Documents
- Material Progress
- Shipments
- QC Inspection
- Claims
- Supplier Registration
- Local Invoice
- Revision flows
- Payment
- Physical delivery
- Overpayment / Refund / Settlement
- Finance verification
- Accounting workflows
- GA-facing surfaces

Do not translate machine status codes or database keys.

---

# 30. DataTables

Phase 7 includes DataTables UI language.

Inventory and localize:

- Search
- Show entries
- Showing X to Y
- No matching records
- Empty table
- Loading
- Processing
- Previous
- Next
- sorting accessibility labels if applicable

Prefer one centralized locale dictionary driven by effective locale.

Avoid per-table duplication where possible.

No external locale CDN/package.

---

# 31. Pagination

Server-side pagination labels must follow locale.

Inspect:

- Tailwind pagination
- Bootstrap pagination
- custom pagination views

Preserve accessibility labels.

---

# 32. JavaScript UI Copy

Inventory all JS-generated user-facing strings:

- toasts,
- confirmations,
- copy feedback,
- dashboard controls,
- DataTables,
- live-region messages,
- auth/password-assistance messages,
- generic interaction text.

Preferred architecture:

one centralized trusted JS translation payload/bootstrap.

Do not add locale conditionals to every JS module.

---

# 33. JS Translation Payload

Gate 1 must choose a bounded strategy.

Preferred concept:

one server-provided current-locale translation payload containing only JS-needed keys.

Requirements:

- trusted keys,
- no network fetch per module,
- no sensitive data,
- no raw HTML,
- no entire translation tree if unnecessary.

---

# 34. Accessibility Copy

Translate user-facing:

- `aria-label`,
- helper copy referenced by `aria-describedby`,
- screen-reader-only labels,
- live-region announcements,
- button accessible names,
- pagination labels.

Do not translate machine roles/ARIA attribute names themselves.

---

# 35. Pluralization / Interpolation

Use Laravel pluralization helpers where numeric grammar changes.

Use safe placeholders for dynamic values.

Examples:

- `:count notifications`
- `Invoice :invoice has been paid`
- `Welcome, :name`

Do not concatenate fragments that become grammatically broken in Indonesian.

---

# 36. HTML in Translation Strings

Translations should contain plain text by default.

Keep markup in Blade/components.

Avoid unescaped translation output such as:

`{!! __('...') !!}`

unless explicitly reviewed and unavoidable.

---

# 37. Database-Stored Business Data

Do not translate user-entered/database business content such as:

- supplier names,
- company names,
- descriptions,
- comments,
- invoice text,
- PO numbers,
- attachment names.

Translate only surrounding application UI.

---

# 38. Enum / Status Display Mapping

Machine status keys remain stable.

User-facing status labels are translated at presentation layer.

Example:

DB:

`READY_TO_PAY`

English:

`Ready to Pay`

Indonesian:

`Siap Dibayar`

Do not change stored status values for localization.

---

# 39. Export / Download Boundary

Gate 1 must inventory generated CSV/XLSX/PDF outputs.

Classify each as:

- human-facing localized export,
- fixed machine/integration format,
- internal audit format.

Localize human-facing headers where safe.

Do not translate fixed integration contracts.

---

# 40. Machine Identifier Protection

User locale must never mutate:

- audit event keys,
- security log action names,
- queue names,
- notification preference keys,
- domain event IDs,
- database status codes,
- analytics identifiers,
- external API field names.

Translate presentation only.

---

# 41. Browser `<html lang>`

Effective locale must set:

- English → `<html lang="en">`
- Indonesian → `<html lang="id">`

All user-facing layout shells must reflect the effective locale.

---

# 42. Fallback / Missing Translation Policy

English is fallback.

Missing Indonesian keys must not crash the page.

However, missing in-scope Indonesian translations are a Gate 2 completeness defect.

Add parity checks so the application translation key sets are complete in both languages.

---

# 43. Translation Parity Test

Add an automated test/script that recursively compares English and Indonesian application translation keys.

Expected:

English application key set = Indonesian application key set.

Document only legitimate framework/vendor exceptions.

---

# 44. Hardcoded Copy Gate

Gate 2 must include a practical static scan for remaining in-scope user-facing hardcoded copy.

The scan must support documented allowlists/exclusions.

Do not use a naive "any English word is a failure" rule.

---

# 45. Security Requirements

Language preference must not allow:

- arbitrary locale paths,
- directory traversal,
- translation file injection,
- arbitrary file inclusion,
- XSS through translation strings,
- cross-user locale writes,
- mass assignment,
- arbitrary BCP-47 values.

Allow only:

- `en`
- `id`

---

# 46. CSP

No CSP weakening.

Do not add:

- `unsafe-inline`,
- eval,
- external translation service,
- dynamic remote translation fetch.

If JS receives translation payload, use the existing safe bootstrap pattern.

---

# 47. Performance Targets

- no query per translated string,
- no network request per translation file,
- no translation API calls,
- no N+1 locale resolution,
- locale resolved once per request/user lifecycle,
- bounded JS translation payload,
- normal Laravel translator/cache behavior.

---

# 48. Testing Strategy

Phase 7 uses:

1. characterization,
2. locale preference tests,
3. runtime locale tests,
4. translation parity tests,
5. module rendering tests,
6. validation tests,
7. JS localization tests,
8. recipient-locale notification tests,
9. DataTables tests,
10. browser language matrix,
11. static hardcoded-copy sweep,
12. full tracked regression.

---

# 49. Characterization Scope

Before migration, lock representative current English output for:

- login,
- password assistance,
- profile,
- security,
- customization,
- notifications,
- admin dashboard,
- purchasing,
- supplier import,
- supplier local invoice,
- finance/accounting,
- QC,
- GA.

Characterization protects behavior while strings migrate.

---

# 50. Locale Preference Tests

Prove:

- default English,
- save English,
- save Indonesian,
- invalid locale rejection,
- stale locale fallback,
- persistence,
- reset to English,
- cross-user isolation,
- cross-preference preservation,
- concurrency.

---

# 51. Locale Runtime Tests

Verify:

- English authenticated user → `en`,
- Indonesian authenticated user → `id`,
- guest → English default unless Gate 1 finds an existing guest locale system,
- invalid stored locale → English,
- `<html lang>` matches effective locale.

---

# 52. Translation File Tests

Verify:

- both locale trees load,
- every expected key exists,
- key parity,
- interpolation,
- pluralization,
- no unexpected raw HTML in protected domains.

---

# 53. Representative Blade Rendering Tests

Test stable headings, labels, buttons, helper text, and key business terms in both languages.

Do not snapshot entire pages unless existing project conventions already do so.

---

# 54. Validation Tests

Verify representative validation in both languages:

- required,
- email,
- min/max,
- confirmed,
- current password,
- custom business validation.

---

# 55. Notification Locale Tests

For two recipients with different language preferences:

same event
→ English recipient gets English title/body
→ Indonesian recipient gets Indonesian title/body.

Also verify:

- preference keys unchanged,
- delivery suppression unchanged,
- DB/broadcast behavior unchanged,
- no cross-recipient locale leakage.

---

# 56. Historical Notification Policy

Recommended:

notifications are rendered in recipient locale at creation time.

Historical rows remain as stored.

Changing language does not retroactively rewrite old notifications.

If current architecture already dynamically renders event data, Gate 1 may recommend preserving that approach, but must prove compatibility.

---

# 57. DataTables Tests

Verify centralized language config under both locales.

At minimum cover:

- Search
- Show entries
- Previous
- Next
- No matching records
- Empty table

Avoid mixed-language control chrome.

---

# 58. JS Tests

Test centralized JS translation helper/payload:

- known key lookup,
- interpolation,
- current locale,
- fallback,
- representative translated interaction messages,
- no raw HTML injection.

---

# 59. Browser Matrix

Verify both languages across representative surfaces:

## English

- Login
- Customization
- Notifications
- Security
- Admin dashboard
- one Purchasing page
- one Supplier Import page
- one Supplier Local page
- one Finance/Accounting page
- one QC page
- one GA page

## Bahasa Indonesia

Repeat the same surfaces.

---

# 60. Language Switching Browser Flow

Verify:

English
→ Customization
→ Bahasa Indonesia
→ Save
→ redirected UI is Indonesian

Then:

Indonesian
→ Customization
→ English
→ Save
→ redirected UI is English

No logout/relogin.

---

# 61. Cross-Page Persistence

After selecting Indonesian:

- navigate across modules,
- refresh,
- open another authenticated page.

Locale must remain Indonesian.

Repeat for English.

---

# 62. Accessibility

Verify both languages for:

- headings,
- labels,
- screen-reader-only copy,
- ARIA labels,
- live-region announcements,
- button names,
- table labels,
- pagination labels,
- 320 CSS px,
- 400% equivalent reflow,
- no clipped longer Indonesian copy.

---

# 63. Layout Stress Test

Specifically inspect Indonesian text in:

- sidebar,
- buttons,
- tabs,
- DataTables controls,
- dropdowns,
- modal actions,
- notification event cards,
- dashboard shortcuts,
- mobile navigation,
- helper text.

No layout breakage.

---

# 64. Dirty Tree Discipline

Never:

- reset,
- clean,
- stash,
- restore unrelated files,
- discard,
- switch branch,
- broad-format repo,
- stage during implementation,
- commit,
- push before Gate 2 approval.

Mixed files require surgical edits.

---

# 65. Git Finalization Policy

Flow:

Gate 1
→ Implementation
→ TDD
→ Security Review
→ Accessibility Review
→ Verification
→ Gate 2
→ explicit approval
→ selective staging
→ one commit
→ normal push
→ parity verification
→ COMPLETE

Never use:

- `git add .`
- `git add -A`
- `git commit -a`
- force push.

---

# 66. Gate 1 Mandatory Audit — Repository Baseline

Verify:

- repository root,
- branch,
- HEAD,
- upstream,
- ahead/behind,
- staged,
- tracked modified,
- untracked,
- Phase 6 committed/pushed.

If Phase 6 is not finalized:

STOP.

---

# 67. Gate 1 Mandatory Audit — Existing Localization

Search for:

- `App::setLocale`
- `app()->setLocale`
- `__()`
- `@lang`
- `trans()`
- `Lang::`
- `lang/`
- `resources/lang/`
- locale middleware
- locale fields
- `config('app.locale')`
- fallback locale
- validation locale files
- JS localization payloads.

Report what already exists.

---

# 68. Gate 1 Mandatory Audit — Preference Storage

Inspect:

- UserPreference schema,
- model,
- service,
- controller,
- request,
- reset,
- frontend payload,
- concurrency harness.

Determine the exact locale persistence design.

---

# 69. Gate 1 Mandatory Audit — Full String Inventory

Perform repository-wide user-facing copy inventory grouped by:

- Auth
- Profile
- Security
- Customization
- Notifications
- Dashboard
- Navigation
- Purchasing
- Supplier Import
- Supplier Local
- Finance
- Accounting
- QC
- GA
- Claims
- Shipments
- Documents
- Local Invoice
- DataTables
- Pagination
- JS
- Validation
- Accessibility
- Exports

Return counts by group where practical.

---

# 70. Gate 1 Mandatory Audit — Registry Labels

Inspect configs/registries containing user-facing labels, including:

- notification preference registry,
- dashboard widget registry,
- customization choices,
- navigation definitions,
- status maps,
- other domain configs.

Recommend which should store translation keys instead of English display text.

---

# 71. Gate 1 Mandatory Audit — Notification Content

Trace in-app notification title/body generation.

Determine:

- where text is built,
- whether rendered text is stored,
- whether event key/data is retained,
- sync vs queue,
- recipient locale availability,
- DB/broadcast consistency.

Lock recipient-locale strategy.

---

# 72. Gate 1 Mandatory Audit — JS Copy

Find all user-visible strings in JS.

Classify:

- static page-generated,
- toast,
- confirmation,
- aria-live,
- DataTables,
- dashboard,
- customization,
- notification,
- auth/password assistance.

Recommend centralized translation bootstrap.

---

# 73. Gate 1 Mandatory Audit — DataTables

Find shared DataTables initialization.

Determine:

- centralized defaults,
- per-table overrides,
- current language config,
- whether package locale assets exist.

Prefer trusted local strings with no external dependency.

---

# 74. Gate 1 Mandatory Audit — Validation

Inspect:

- default Laravel validation files,
- custom FormRequest messages,
- custom attribute labels,
- custom rules.

Recommend exact English/Indonesian structure.

---

# 75. Gate 1 Mandatory Audit — Exports

Inventory user-facing exports/reports.

Classify each as:

- human-facing localized,
- external integration fixed,
- internal audit fixed.

Do not break import/export contracts.

---

# 76. Gate 1 Mandatory Audit — Dirty Overlap

For every likely Phase 7 file:

inspect current diff.

Classify:

- clean,
- mixed,
- unrelated dirty,
- BusinessTime,
- Regional,
- notification polling,
- other experiments.

Identify high-risk mixed files.

---

# 77. Gate 1 Required Architecture Decisions

Gate 1 must decide:

- storage key,
- default/fallback,
- middleware/application point,
- guest locale behavior,
- JS locale bootstrap,
- `<html lang>`,
- queue/worker recipient locale behavior,
- fallback behavior,
- historical notification behavior.

---

# 78. Gate 1 Translation Domain Map

Provide:

| Domain | Purpose | Estimated Strings | Notes |
|---|---|---:|---|

Use actual repository findings.

---

# 79. Gate 1 Terminology Glossary

Provide:

| Concept | English | Bahasa Indonesia | Keep Technical? |
|---|---|---|---:|

This glossary becomes part of the implementation contract.

---

# 80. Gate 1 Hardcoded Copy Inventory

Provide source-backed counts:

| Module | Blade | PHP | JS | Registry | Total |
|---|---:|---:|---:|---:|---:|

Do not guess counts.

---

# 81. Gate 1 Exact Production Files

Return:

## New

## Modified

## Deleted

Mark:

**MIXED — selective edit required**

where relevant.

A large file count is acceptable because the locked scope is full-portal migration.

---

# 82. Gate 1 Exact Test Files

Return:

## New

## Modified

## Reused

Include parity/static-scan tests.

---

# 83. Gate 1 TDD Matrix

At minimum include:

1. default English
2. save English
3. save Indonesian
4. invalid locale reject
5. stale fallback
6. reset
7. persistence
8. cross-user isolation
9. cross-preference preservation
10. concurrency
11. request locale
12. `<html lang>`
13. translation key parity
14. glossary consistency
15. validation
16. auth
17. customization
18. notification preferences
19. notification recipient locale
20. dashboard
21. navigation
22. DataTables
23. JS copy
24. accessibility
25. 320px/reflow
26. export classification
27. static hardcoded-copy sweep
28. full tracked regression.

---

# 84. Implementation Task Breakdown

After Gate 1 approval, execute these tasks in order.

## Task 1 — Characterization

Lock representative English behavior and create baseline string inventory.

## Task 2 — Locale Preference Infrastructure

Implement persistence, validation, default/fallback, runtime locale application, `<html lang>`, reset, preservation, concurrency.

## Task 3 — Translation Foundation

Create English and Indonesian locale domain files, glossary structure, parity test.

## Task 4 — Customization Language Selector

Add English/Bahasa Indonesia selector and verify immediate post-save effect.

## Task 5 — Shared Layout & Navigation

Migrate app/auth layouts, navbar, sidebar, user dropdown, breadcrumbs, pagination, shared components.

## Task 6 — Auth / Profile / Security

Migrate login, Password Assistance, 2FA, password confirmation, profile, security.

## Task 7 — Customization / Notification Preferences

Migrate customization copy and notification preference registry labels/descriptions.

## Task 8 — Runtime Notification Content

Make in-app notification title/body recipient-locale-aware without changing event/preference keys or delivery semantics.

## Task 9 — Dashboards

Migrate all role-specific dashboards.

## Task 10 — Purchasing / Supplier Import

Migrate PR, quotation, conversation, PO, documents, material progress, shipment, claims.

## Task 11 — Supplier Local / Invoice

Migrate submission, revision, physical delivery, payment, refund, overpayment, settlement, statuses.

## Task 12 — Finance / Accounting

Migrate all user-facing copy and table labels.

## Task 13 — QC / GA

Migrate all user-facing copy.

## Task 14 — Validation

Migrate default validation, custom messages, attribute labels, custom rule copy.

## Task 15 — DataTables / JS UI

Implement centralized JS translation payload and migrate DataTables/toasts/confirmations/live-region text.

## Task 16 — Exports

Localize human-facing exports where safe; preserve fixed integration formats.

## Task 17 — Static String Sweep

Remove remaining in-scope hardcoded user-facing strings and document legitimate exclusions.

## Task 18 — Security / Accessibility Review

Review locale injection, XSS, worker leakage, ARIA translation, and layout overflow.

## Task 19 — Focused Regression

Run Phase 7-specific tests.

## Task 20 — Browser Matrix

Verify English and Indonesian across all representative roles/modules.

## Task 21 — Full Tracked Regression

Run complete tracked test suite.

## Task 22 — Gate 2

Produce full implementation evidence and stop before staging.

---

# 85. Static Hardcoded String Sweep

Gate 2 must scan:

- `resources/views`
- `app/Http`
- `app/Services`
- `app/Notifications`
- relevant `config`
- `resources/js`

Classify remaining literals as:

- machine identifier,
- enum key,
- route name,
- developer log,
- internal/test,
- user-entered content,
- technical acronym,
- approved fixed integration format.

Everything else should be translated.

---

# 86. Translation Completeness

A Phase 7 page is complete only if switching locale changes all in-scope copy on that page without English remnants in Indonesian mode except approved technical terms.

No intentional half-translated production page.

---

# 87. Browser Verification — Roles

Verify at least one representative account for every supported role in both languages.

At minimum:

- Admin
- Purchasing
- Finance
- Accounting
- QC
- GA
- Supplier Import
- Supplier Local

Verify dual-scope supplier if available.

---

# 88. Browser Verification — Notifications

Verify:

- preferences page translated,
- notification center translated,
- newly generated notification respects recipient language,
- English and Indonesian recipients receive independent content.

---

# 89. Browser Verification — DataTables

Verify both locales on at least:

- one internal table,
- one supplier table,
- one invoice/finance table.

No mixed-language controls.

---

# 90. Responsive Verification

Test both languages at:

- desktop,
- 320 CSS px,
- 400% equivalent reflow.

Pay special attention to longer Indonesian copy.

---

# 91. Security Review

Review:

- locale allowlist,
- path traversal,
- translation-key injection,
- raw HTML translations,
- placeholder escaping,
- queue locale contamination,
- cross-user locale writes,
- CSRF,
- mass assignment.

No unresolved Critical/High.

---

# 92. Accessibility Review

Verify both languages for:

- accessible names,
- heading hierarchy,
- focus,
- aria-live,
- form labels,
- table labels,
- pagination labels,
- reflow,
- no clipped text.

---

# 93. Performance Verification

Measure:

- preference query count before/after,
- locale resolution overhead,
- DataTables initialization,
- notification creation per recipient locale,
- JS translation payload size.

Targets:

- no query per translation key,
- no network translation fetch,
- no N+1 locale reads.

---

# 94. Gate 1 Required Output

Return exactly:

# Gate 1 — Phase 7 Language Preference & Full Internationalization

Status: PLAN ONLY; waiting for approval.

## 1. Repository Baseline

## 2. Phase 6 Prerequisite Verification

## 3. Existing Localization Architecture

## 4. Existing Preference Architecture

## 5. Locale Storage Decision

## 6. Runtime Locale Resolution

## 7. Guest Locale Strategy

## 8. HTML Lang Strategy

## 9. JS Localization Strategy

## 10. Translation Domain Map

## 11. Hardcoded String Inventory

## 12. Terminology Glossary

## 13. Shared Layout / Navigation Scope

## 14. Auth Scope

## 15. Profile / Security Scope

## 16. Customization Scope

## 17. Notification Preferences Scope

## 18. Runtime Notification Localization

## 19. Dashboard Scope

## 20. Purchasing Scope

## 21. Supplier Import Scope

## 22. Supplier Local / Invoice Scope

## 23. Finance Scope

## 24. Accounting Scope

## 25. QC Scope

## 26. GA Scope

## 27. Validation Localization

## 28. DataTables / Pagination

## 29. JS User-Facing Copy

## 30. Export / Report Classification

## 31. Accessibility Copy

## 32. Pluralization / Interpolation

## 33. Historical Notification Policy

## 34. Queue / Worker Locale Safety

## 35. Security Findings

## 36. Accessibility Findings

## 37. Performance Findings

## 38. Database Decision

Expected:

NO MIGRATION

If migration is required:

BLOCKER.

## 39. Dependency Decision

Expected:

NO NEW PACKAGE

## 40. Exact Production Files

Separate:

- New
- Modified
- Deleted

## 41. Exact Test Files

Separate:

- New
- Modified
- Reused

## 42. Dirty-Tree Overlap

## 43. TDD Matrix

## 44. Static Hardcoded-Copy Sweep Plan

## 45. Browser Verification Plan

## 46. Full Regression Plan

## 47. Implementation Task Plan

List Tasks 1–22 with repository-backed refinements.

## 48. Explicit Deferred Items

## 49. Risk Classification

## 50. Phase 7 Definition of Done

## 51. Gate 1 Recommendation

Do NOT implement.

Do NOT modify files.

Do NOT create files.

Do NOT delete files.

Do NOT stage.

Do NOT commit.

Do NOT push.

End exactly with:

STOP: waiting for `Approve Gate 1 — Phase 7`.

---

# 95. Gate 2 Required Output

After implementation, return:

# Gate 2 — Phase 7 Language Preference & Full Internationalization

## 1. Status

Expected:

PHASE 7 COMPLETE

## 2. Repository State

Branch:
HEAD:
Upstream:
Tracked modified:
Untracked:
Staged:

## 3. Locale Architecture

## 4. Final Supported Locales

- en
- id

## 5. Default / Fallback

## 6. Preference Persistence

## 7. Language Selector

## 8. HTML Lang

## 9. Translation Domain Map

## 10. Translation Key Parity

## 11. Terminology Glossary

## 12. Hardcoded String Sweep

Report counts and classified remaining literals.

## 13. Auth Localization

## 14. Profile Localization

## 15. Security Localization

## 16. Customization Localization

## 17. Notification Preferences Localization

## 18. Runtime Notification Localization

## 19. Dashboard Localization

## 20. Purchasing Localization

## 21. Supplier Import Localization

## 22. Supplier Local / Invoice Localization

## 23. Finance Localization

## 24. Accounting Localization

## 25. QC Localization

## 26. GA Localization

## 27. Validation Localization

## 28. DataTables / Pagination

## 29. JS Localization

## 30. Export / Report Localization

## 31. Historical Notification Behavior

## 32. Queue / Worker Locale Safety

## 33. Cross-Preference Preservation

## 34. Concurrency

## 35. Security Review

## 36. Accessibility Review

## 37. Performance Measurements

## 38. Exact Production Files

New:
Modified:
Deleted:

## 39. Exact Test Files

New:
Modified:
Reused:

## 40. Dirty-Tree Protection

## 41. Tests Executed

## 42. Focused Results

Tests:
Assertions:
Failures:
Errors:
Skipped:
Duration:
Exit code:

## 43. JS Results

## 44. Translation Parity Results

## 45. Static Hardcoded-Copy Sweep Results

## 46. Tracked Project Regression

Tracked files:
Tests:
Assertions:
Failures:
Errors:
Skipped:
Duration:
Exit code:

## 47. Static / Build Verification

## 48. Browser Verification — English

## 49. Browser Verification — Bahasa Indonesia

## 50. Role Coverage

## 51. Responsive / Reflow Verification

## 52. Database Decision

Expected:

NO MIGRATION

## 53. Dependency Decision

Expected:

NO NEW PACKAGE

## 54. Explicit Deferred Items

## 55. Scope Protection

Confirm no:

- BusinessTime remediation,
- Regional behavior rewrite,
- notification delivery redesign,
- auth/security redesign,
- machine translation service,
- new package,
- unintended schema migration.

## 56. Phase 7 Closure Decision

Expected:

PHASE 7 COMPLETE

Do NOT stage.
Do NOT commit.
Do NOT push.

Wait for explicit Gate 2 approval.

---

# 96. Phase 7 Definition of Done

Phase 7 is complete only when:

## Preference

- English and Bahasa Indonesia available.
- English default.
- All roles can select language.
- Invalid locale rejected.
- Stale locale safely falls back to English.
- Reset returns to English.
- Preference persists account-wide.

## Runtime

- locale applied consistently on every request.
- `<html lang>` correct.
- queue/background user-facing copy resolves per recipient safely.
- no cross-user locale leakage.

## Translation Coverage

- all in-scope UI copy translated.
- all major modules translated.
- validation translated.
- DataTables translated.
- pagination translated.
- JS UI copy translated.
- accessibility copy translated.
- Password Assistance translated.
- in-app notifications translated.
- dashboard and domain terminology translated.

## Consistency

- glossary enforced.
- English/Indonesian key parity passes.
- no intentional half-translated production page.
- technical identifiers remain stable.

## Security

- trusted locale allowlist only.
- no path traversal.
- no arbitrary translation file access.
- no HTML/XSS through translation strings.
- no cross-user locale write.
- CSRF preserved.

## Accessibility

- both languages accessible.
- Indonesian longer copy does not break layout.
- 320 CSS px / 400% equivalent reflow passes.

## Performance

- no translation network fetch.
- no per-string DB query.
- no N+1 locale resolution.
- JS payload bounded.

## Regression

- Phase 4 Security intact.
- Phase 5 Notifications/Password Assistance architecture intact.
- Phase 6 Accent intact.
- Dashboard logic intact.
- Regional behavior intact.
- BusinessTime untouched.
- tracked regression passes.

## Scope

- no machine translation.
- no third-party localization service.
- no browser locale auto-detection.
- no new package unless explicitly approved.
- no migration unless explicitly approved.

Final implementation status:

**PHASE 7 COMPLETE**

No Phase 7A.
No Phase 7B.
No Phase 7C.

---

# 97. Finalization After Gate 2

Only after explicit Gate 2 approval:

1. inspect exact diff,
2. classify mixed files,
3. selectively stage Phase 7 only,
4. audit cached diff,
5. run cached diff checks,
6. run focused final verification,
7. create one Phase 7 commit,
8. normal push,
9. verify local/remote parity,
10. preserve unrelated dirty work.

Suggested commit candidate:

`feat: add bilingual portal localization`

Final status after successful push:

**PHASE 7 COMPLETE AND PUSHED**
