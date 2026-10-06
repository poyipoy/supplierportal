# Gate 1 — Phase 2B Regional Preferences

**Status:** PLAN ONLY; waiting for approval.

Repository: C:/laragon/www/adasi_portal_supplier. Inspected branch: customization-update. Inspected HEAD: 7f0e0b7. Phase 1 is committed in ce2e114 and Phase 2A in 7f0e0b7. The index was empty and the working tree contained 181 modified/untracked paths before this document was added.

Scope: timezone, date format, time format and number format preferences for explicitly supported interactive displays. Recommendation: approve **Phase 2B.1 — persistence, formatter, Regional settings and eight dashboard surfaces**. Lists/details are Phase 2B.2 and require a subsequent bounded plan/approval.

This task inspected repository source only. No application source, migration, test, database state, Git index or commit was changed. This plan is the sole new document.

## 1. ECC Size Classification

**Tier: Large.** Existing formatting spans 84 interactive Blade templates, shared helpers, dynamically rendered Finance values, and controllers that combine display fields with machine data. The risk is confusing presentation with date calculations, financial precision, wire contracts or durable output.

- Eligible candidates: selected dashboard summaries, operational event timestamps, date-only text labels and presentation numbers.
- Excluded: document/PDF/receipt/print output, export/import contracts, emails/notifications, business calculations, input/filter values, audit/wire fields and unapproved invoice/tax displays.
- First delivery: 9 named interactive templates (8 dashboards plus Customization), one shared shell bootstrap, 3 new production files and narrowly changed preference/provider files; approximately 19 production files plus focused tests.
- Shared opportunities: existing request-local UserPreferenceService; one presentation formatter; existing metric-card accepting formatted value strings; existing early preference bootstrap.
- Workflow: orch-add-feature → scoped research → surface classification → architecture/TDD plan → Gate 1. Laravel patterns/security, TDD planning and changed-form accessibility apply. No catalog discovery or unrelated ECC workflow is needed.

## 2. Current Preference Baseline

Observed and committed:

- user_preferences has a unique user_id, users FK/cascade, theme/density/sidebar/page-size/Quick Access, accent, dashboard_preferences, general revision and sidebar_revision.
- UserPreferenceService resolves missing rows without inserting, caches resolved preferences on the request, locks the authenticated owner's User row for transactional save/reset, and exposes a small frontend payload.
- UserPreference has explicit fillable fields; ownership and both revisions are assigned internally.
- Existing GET/PATCH/DELETE profile/customization routes remain in the auth/web group. UpdateUserPreferenceRequest and controller safe()->only limit writes.
- Shared early bootstrap applies theme/density/accent and account-scoped sidebar state. sidebar_revision is independent of ordinary appearance/dashboard saves.
- Quick Access, eight trusted dashboard layouts and reset are already implemented. Regional settings will extend this contract; no alternative settings endpoint or users-table columns are needed.

Source references: app/Services/UserPreferenceService.php:18,55,109,134; app/Models/UserPreference.php:10; config/user_preferences.php:4; routes/web.php:119,135.

**Material baseline difference:** BusinessTime, its business_timezone setting, explicit MySQL session timezone settings and related timezone tests exist only in the dirty working tree. They are not present in HEAD. They must not be silently included in a regional commit or treated as deployed prerequisites.

## 3. Formatting Inventory

Counts were recomputed using Git searches against HEAD and equivalent working-tree searches. A callsite means one matched API invocation, not one line or one runtime execution. Multiple calls on one line count separately. Counts describe syntax, not automatic permission to migrate.

| Blade formatting family | HEAD calls / files | Working-tree calls / files |
|---|---:|---:|
| number_format | 248 / 58 | 248 / 58 |
| direct ->format / ?->format | 209 / 75 | 178 / 71 |
| BusinessTime::format | 0 / 0 | 27 / 11 |
| NumberFormat::maxDecimals | 96 / 20 | 96 / 20 |
| @bizdt / bizdt invocations | 0 / 0 | 2 / 2 |
| PHP date / gmdate | 7 / 6 | 7 / 6 |
| inline Intl / toLocale formatting | 37 / 18 | 37 / 18 |

All 209 HEAD direct-format calls have literal patterns: 141 use date-only syntax, 63 combine date/time and 5 show time only. Syntax alone does not distinguish a timestamp displayed as a date from a true DATE column. Common patterns are d M Y (100), d M Y, H:i (32), Y-m-d (27), d M Y H:i (17), d F Y (9) and H:i (5).

Frontend asset/module search found 3 Intl.DateTimeFormat calls, 9 toLocaleString calls, no Intl.NumberFormat, and no toLocaleDateString/toLocaleTimeString. Together with the 37 inline Blade calls, there are 49 known frontend formatting invocations. The separate 28 new Date calls are calendar/date-only processing, not timestamp display candidates. The dashboard subset has 8 toLocaleString calls and 2 additional Finance toFixed abbreviation operations.

Seven Blade diffForHumans lines were found separately; relative elapsed-time text is outside this first migration. ISO serializers, hidden inputs and machine attributes are not included as eligible display operations.

The earlier 248/178/27 figures describe the working tree, not the committed baseline. In particular, HEAD contains zero BusinessTime formatter calls.

## 4. Surface Classification

The following table uses output paths and inspected representative contracts. B overlaps other categories and must not be added to the table total. Controller/shared-code rows are conservative location buckets, not claims that every match is an arithmetic operation.

| Surface | Count/scope in HEAD | Eligible? | Reason |
|---|---:|---|---|
| A — Interactive Blade presentation | 557 syntax matches / 84 templates | Only the named first batch | Includes display amounts, input values and reused invoice tables; eligibility needs field-level selection |
| A — Frontend assets/modules | 12 formatter invocations / 5 files | Dashboard callbacks only initially | Global calendar, metric, export-progress and chart defaults serve wider contracts |
| B — Date-only business values | 107 direct-format candidates / 45 views; 103 in interactive views | Format visible text only; never timezone-shift | Candidate expressions reference 20 known DATE-cast field names; first-batch fields are verified below |
| C — PDF/receipt/print/legal output | 40 syntax matches / 7 templates | No | Durable presentation remains fixed |
| D — Export/import | 32 syntax matches / 14 files | No | Business/machine format remains fixed |
| E — Email/notification | 2 matching calls / 2 notification classes | No | Notification policy/localization is a separate scope |
| F — Business/shared helpers | 77 matches / 25 files | No user preference in business logic | Includes period keys, import parsing, audit timestamps and reusable descriptions |
| Mixed controller output | 96 matches / 33 controllers | Deferred | DataTables HTML, CSV/wire values, filters and document routes need separate value tracing |

The broad discovery contains 816 matches in HEAD and 817 in the working tree. The working-tree A Blade bucket is 555 rather than 557 because existing unrelated time formatting was refactored. These totals are search-family counts, not a migration target.

Representative sources:

- Interactive: eight dashboard templates; resources/views/local-invoices/detail.blade.php; purchasing/quotations/show.blade.php; the reused local-invoices/table.blade.php.
- Date-only: ExchangeRate.valid_from, PurchaseOrder.estimated_arrival, GaClaim.claim_date, LocalInvoice.invoice_date/due_date/scheduled_payment_date.
- Durable: pdf/po-pdf.blade.php, finance/vouchers/print.blade.php and local-supplier/invoices/receipt.blade.php.
- Export: app/Exports/LocalInvoicesExport.php and PaymentBatchDrpSheetRenderer.php.
- Notification: InvoicePaidNotification and NewDeviceLoginNotification.
- Business/mixed: PaymentForecastService period keys, LocalInvoice due comparisons, document/import parsing and PriceComparisonController output.

## 5. BusinessTime Findings

The working-tree BusinessTime represents **both business calculations and display**:

- tz/now/today/parseDate establish the global business calendar.
- toStorage converts business instants to the configured storage timezone.
- toBusiness and format convert timestamp displays; format defaults to d M Y H:i with a zone label.
- format is explicitly documented for DATETIME/TIMESTAMP, not DATE.
- Its label map includes WIB, WITA and WIT, but its configured default is Asia/Jakarta. The map is not evidence that three user zones are operationally required.

Committed app.timezone is UTC. The dirty config/app.php adds business_timezone=Asia/Jakarta; dirty config/database.php adds +00:00 to MySQL/MariaDB connections. HEAD has neither additional setting. Actual database session timezone and deployed configuration were not queried during this planning task. Before implementation accepts display conversion, verify that selected typed timestamp values represent known event instants and inspect the dedicated test connection's timezone read-only. Ambiguous naive timestamp inputs or a contradicted UTC-storage contract must be treated as a separate prerequisite issue; do not repair connection settings or historical values inside Regional Preferences. Documentation declares UTC storage; source config confirms Laravel UTC, while the explicit DB connection enforcement is still uncommitted.

**Decision:** keep BusinessTime and business/query timezone configuration independent. The new formatter must not inject user timezone into BusinessTime::tz/now/today/parseDate/toStorage or change global Carbon/PHP/Laravel timezone/locale.

The first-batch dashboard formatting is the same in HEAD and the working tree. Its timestamp objects use current per-field rendering. System preserves those legacy profiles; an explicit Jakarta choice converts a cloned instant. No dependency on the uncommitted BusinessTime class is required.

Verified examples:

| Field | Source evidence | Regional behavior |
|---|---|---|
| ExchangeRate.valid_from | date column and date cast; admin/dashboard:28 | Reformat calendar text only |
| PurchaseOrder.estimated_arrival | date column and date cast; purchasing/dashboard:255 | Never shift date |
| Purchasing lastRateUpdated | Derived from valid_from at dashboard:188 | Date-only, despite the variable name |
| GaClaim.claim_date | date column/cast; ga/dashboard:107 | Date-only |
| QcInspection.inspected_at | timestamp column/datetime cast; qc/dashboard:76 | Convert a cloned instant |
| PaymentBatch.created_at | model timestamps; finance/dashboard:304 | Convert an instant |
| PR/PO created_at | timestamp fields; supplier/dashboard:66,131 | Still instants even though the current label shows only a date |
| Finance row.start/end | toDateString output from PaymentForecastService:157,159,255,257 | Pure calendar labels; no timezone conversion |

For 2026-09-28T23:35:00Z, a Jakarta timestamp display may correctly become 29 September. A business date 2026-09-28 must retain that calendar date.

## 6. NumberFormat Findings

NumberFormat::maxDecimals is a **precision/presentation helper**, not locale or currency selection. It validates numeric/finite values, performs the existing number_format rounding with dot decimal/no grouping, trims trailing zeros and normalizes negative zero. Null/invalid values yield the existing '-' placeholder. It is used for quantities/dimensions and some durable outputs.

Money owns exact decimal arithmetic and financial scale/rounding. It must not receive display preferences or be replaced by float arithmetic.

**Decision:** leave NumberFormat and Money unchanged. Compose the regional formatter with their existing output where needed:

1. Existing domain helper or number_format produces the current numeric text with its current precision.
2. The regional display formatter changes grouping/decimal separators on that trusted numeric text.
3. Currency code/prefix, percent sign, unit and abbreviation remain caller-owned.
4. No new rounding, float conversion, recalculation, decimal precision change or input parsing is introduced. With number_format=system, return the original numeric text byte-for-byte, including grouping absence, trailing zeros, negative-zero text, placeholders and whitespace. Existing domain helpers may already normalize those values; the regional layer does not normalize them again.

For example, the original two-decimal text 1,250,000.50 can become 1.250.000,50. A max-decimal output 1250000.5 stays at one displayed fractional digit under either preset; the regional layer must not add .50 or round again.

First-batch currency findings: 9 of the 14 direct dashboard number_format calls display currency/rates and 5 display counts. Admin FX uses 2 decimals; Purchasing FX, Finance summaries/batches and GA amount use 0. Dynamic Finance uses its existing toLocaleString result; compact axes retain toFixed precision, thresholds and M/jt suffixes.

## 7. Regional Preference Contract

| Field | Allowed values for Phase 2B.1 | Default |
|---|---|---|
| timezone | system, Asia/Jakarta | system |
| date_format | system, human, dmy, iso | system |
| time_format | system, 24h, 12h | system |
| number_format | system, international, indonesian | system |

Centralize registries, patterns, separators, labels and examples in config/regional_display.php. Keep preference defaults in the existing config/user_preferences.php.

Proposed fixed mappings:

- human: d M Y → 28 Sep 2026.
- dmy: d/m/Y → 28/09/2026.
- iso: Y-m-d → 2026-09-28.
- 24h: H:i → 14:35.
- 12h: g:i A → 2:35 PM.
- international: comma groups and dot decimal.
- indonesian: dot groups and comma decimal.

**System means preserve the existing supported surface's formatting.** It does not mean browser/OS locale. Trusted legacy profiles retain each target's current date pattern, time presence/precision, timestamp zone, punctuation, placeholder and numeric style. Current dashboard timestamp profiles preserve existing UTC rendering unless explicitly changed; already business-formatted future surfaces must retain their business strategy.

A format that currently shows only a date does not gain a time just because 12h is selected. Explicit timezone conversion of a timestamp uses a registered zone label; true date-only values do not receive a timezone suffix. Partial month/week labels, ISO machine attributes and form values remain fixed.

Only WIB is evidenced as the configured business default. Makassar/Jayapura labels exist in pending helper code but no repository evidence establishes the need for those user options. Defer them. If business requirements confirm either before approval, amend the registry and add the corresponding instant/date-only tests; do not expose all IANA zones.

## 8. Architecture Decision

| Option | Benefit | Risk | Decision |
|---|---|---|---|
| A — Extend BusinessTime and NumberFormat with user preferences | Fewer class names | Mixes calculation/global-time behavior and durable precision helpers with personal display; BusinessTime is not in HEAD | Reject global preference behavior |
| B — One focused presentation formatter | Explicit scope, testable date/instant distinction, existing helpers unchanged | A small PHP/JS parity contract is required for dynamic Finance/chart text | Choose |

Create **app/Services/RegionalDisplayFormatter.php**, not a generic formatter framework and not a separate resolver chain.

Conceptual methods:

- date: receives a semantic calendar date and trusted legacy profile; never converts timezone.
- timestamp: receives a typed event instant and trusted profile; uses an immutable copy, selected zone/date/time formats and existing display granularity.
- time: formats the time portion of an instant using the selected style and profile precision.
- number: receives already-formatted numeric text plus a trusted source-style key; transcodes separators/grouping without arithmetic.
- Internal string composition may handle canonical maxDecimals output; no modification to NumberFormat is necessary.

UserPreferenceService resolves/normalizes the four keys once. A request-scoped formatter is created from that safe state. An exact dashboard view composer makes it available before the child Blade content renders; the later layout composer reuses the same preference query/cache. Do not depend on variables first added when layouts.app renders.

Existing metric-card already accepts a display value string. Pass explicitly formatted dashboard values to it; keep the shared component's default behavior unchanged.

For dynamic UI, add small early bootstrap helpers for numeric-text presentation and pure ISO date-only labels. Use server-owned registries and the same golden cases as PHP, comparing transcoding of the same already-formatted source text and legacy profile. Preserve each callsite's existing precision: PHP Finance values currently use zero decimals, dynamic toLocaleString may retain fractions, and compact axes use their existing toFixed scale. Do not force those distinct presentations to a new shared precision. Helpers must be available before inline chart/dashboard initializers; test callback execution before deferred Vite modules explicitly. Do not globally replace chart-theme.js callbacks or defer correctness until an unrelated module loads.

No global locale/timezone override, middleware, Redis, permissions change or dependency is required.

## 9. Persistence / Migration

Proposed additive columns on user_preferences:

| Column | Type | SQL default |
|---|---|---|
| timezone | VARCHAR(64) | system |
| date_format | VARCHAR(16) | system |
| time_format | VARCHAR(8) | system |
| number_format | VARCHAR(20) | system |

The future migration follows 2026_09_28_000002. Fixed defaults are a migration snapshot; runtime defaults/registries remain centralized. No index is needed because these fields are not query predicates. Existing rows obtain defaults without manual backfill; users without rows still read defaults without insertion.

Extend explicit fillable and FormRequest allowlists. Owner/revision/sidebar_revision remain excluded. New fields are optional for legacy payloads; omitting them preserves stored values. Invalid stored keys fall back to defaults on read and are pruned safely on subsequent save.

Regional saves use the existing owner-derived transaction and increment only overall revision. They do not change sidebar_revision. Existing full Reset to Default restores regional and Phase 1/2A defaults and retains its current overall/sidebar revision behavior. No regional-specific revision or permanent browser cache is needed.

## 10. UI Plan

Extend the existing Customization form with a Regional fieldset and four native selects. Do not rebuild the page.

- Timezone labels explain System's preserved portal behavior and Jakarta/WIB UTC+07:00.
- Date/time options include the fixed examples above.
- Number labels explain both separators, not just a locale name.
- Associated help states which dashboard displays are supported and which invoice tables, document outputs, inputs and filters retain their established formats.
- Examples use a fixed event instant and a separate calendar date; they must not imply that a date-only value is timezone-shifted.
- Static examples in labels/help are sufficient initially. If a small changing sample is added, it is confined to this fieldset, accessible and ephemeral. Do not reformat the whole current page or write unsaved values to LocalStorage.
- Preserve old input, per-field errors, existing Save/Reset actions and other customization sections.

No Language or Notification section is added. No new CSS/design system is required.

## 11. Migration Surface

**Approved candidate list for Phase 2B.1:**

| Actual template | Formatting permitted in this batch |
|---|---|
| resources/views/profile/customization.blade.php | Four selectors, examples and errors |
| resources/views/admin/dashboard.blade.php | Count summaries, current FX display, valid_from calendar label |
| resources/views/purchasing/dashboard.blade.php | Metrics, FX reference, valid_from/ETA date text, 3 chart numeric callbacks |
| resources/views/finance/dashboard.blade.php | Status metrics, forecast/batch amount text, created_at instant, ISO forecast range labels, 2 numeric callbacks and existing compact-axis strings |
| resources/views/accounting/dashboard.blade.php | Metric/lifecycle counts; shared invoice table unchanged |
| resources/views/qc/dashboard.blade.php | Inspection metrics and inspected_at instant; 3 chart numeric callbacks |
| resources/views/ga/dashboard.blade.php | Claim metrics, claim_date calendar text, existing amount precision |
| resources/views/supplier/dashboard.blade.php | Supplier metrics and PR/PO created_at display instants |
| resources/views/local-supplier/dashboard.blade.php | Invoice-state metric counts; company identifiers/shared invoice table unchanged |

This finite set contains 30 existing formatting API calls and 33 metric-card values, before repeated rows. Finance also has raw ISO start/end text that is not counted as a formatting API. Its start/end values stay ISO in JSON, keys and requests; only visible text is formatted. Existing server-formatted money strings/fallbacks retain digits/precision and Rp outside the formatter.

Keep Finance week/month grouping labels, selectedMonth and ?month= filters unchanged. Keep chart numeric arrays and callbacks' rounding/abbreviation calculations unchanged.

**Shared mechanisms:** the formatter/config, dashboard-only composer, preference persistence and existing early app layout bootstrap. No controllers' business queries need changes.

**Deferred interactive targets for Phase 2B.2:** local-invoices/table and detail, operational DataTables presenters, PO/quotation/shipment/claim lists/details and export-progress UI. Shared invoice tables are reused across roles and include tax/refund/due-date fields; they require a separate explicit contract review. Date-picker inputs and machine values are not candidates for either mechanical migration.

## 12. Phase 2B vs 2B.1/2B.2 Decision

Split delivery.

**Phase 2B.1:** all four settings/persistence/validation/reset; one display formatter; the nine named interactive templates; bounded PHP/JS integration; focused scope/performance/security/accessibility verification.

**Phase 2B.2:** separately plan high-frequency lists/details and shared invoice/presenter surfaces after the first release. Do not automatically continue into it when 2B.1 completes.

Reason: 84 interactive templates and 96 mixed controller matches exceed a responsible direct-edit budget. The first batch needs at most 10 interactive/shared-shell templates, below the 30–40 threshold. It neither promises global reformatting nor requires dozens of mechanical changes.

## 13. Vertical Task List

1. **Schema/defaults:** characterize existing preferences; RED defaults/no-insert/additive migration preservation; add four fields and fallback normalization.
2. **Persistence/validation:** RED allowlists, legacy omission, ownership, protected fields and revisions; extend existing FormRequest/controller/service/reset.
3. **Temporal formatter:** RED typed timestamp vs calendar date, immutable conversion, system legacy profiles and valid/invalid keys; implement explicit methods.
4. **Numeric formatter:** RED system parity and preserved canonical precision; transcode known numeric strings and compose with existing helpers without new rounding.
5. **Regional form:** RED saved/old values, examples, accessible names/errors and reset; extend the existing sectioned form.
6. **Server dashboards:** integrate Admin → Purchasing → Finance → Accounting → QC → GA → Supplier Import → Supplier Local with field-level characterization. Preserve widget order/visibility and original data/navigation.
7. **Client display:** RED early-loading/parity/date-only cases; format only dashboard callbacks and Finance visible range labels, using unchanged raw data.
8. **Integration/review:** combined relevant tests; scope protection, query-count evidence, targeted code/security/form accessibility review; remediate before Gate 2.
9. **Gate 2:** report exact changes/tests/limitations and stop before commit. Phase 2B.2 stays deferred.

Every implementation slice uses RED → GREEN → REFACTOR. No test or migration is created during this Gate 1 task.

## 14. Expected Files

### New

Production candidates:

- config/regional_display.php.
- app/Services/RegionalDisplayFormatter.php.
- One additive migration extending user_preferences with the four regional fields; exact timestamp chosen at implementation.

Proposed tests, not yet created:

- tests/Unit/RegionalDisplayFormatterTest.php.
- tests/Feature/UserRegionalPreferencesTest.php.
- tests/Feature/RegionalDashboardRenderingTest.php.
- tests/Feature/RegionalScopeProtectionTest.php.
- tests/js/regional-preferences.test.mjs.

### Modified

- config/user_preferences.php.
- app/Models/UserPreference.php.
- app/Services/UserPreferenceService.php.
- app/Http/Requests/UpdateUserPreferenceRequest.php.
- app/Http/Controllers/UserPreferenceController.php.
- app/Providers/AppServiceProvider.php: only scoped binding/dashboard composer hunks.
- resources/views/layouts/app.blade.php: only safe regional bootstrap/helper hunks.
- The nine templates in section 11.
- Existing preference-default assertions in UserCustomizationTest/UserDashboardCustomizationTest where affected.
- UserPreferenceMigrationTest restoration, so direct drop/recreate tests restore regional columns too.

### Conditional

- resources/js/preferences.js only if accessible field-local dynamic examples prove worthwhile.
- Existing concurrency test/worker only if testing preserved regional values cannot be covered without extending the real-process contract.
- Existing metric-card component only if an opt-in prop is simpler than passing a formatted value; no default global change.
- A small JS module only if the existing early bootstrap cannot stay clear and compact; it must still be synchronously ready for affected initializers. No dependency.

### Explicitly excluded

BusinessTime, NumberFormat, Money, app/database timezone config, scheduler/business services, Finance forecast calculation/JSON contract, routes/auth/policies, input parsers/calendar helpers, global chart-theme defaults, shared invoice/tax table/detail, PDF/receipt/print, exports/imports, email/notification classes, translations, package manifests and unrelated dirty hunks.

Dirty overlap: AppServiceProvider and layouts.app contain pre-existing business-time/notification work. Dashboard controllers, config/app.php/config/database.php and BusinessTime work remain outside this implementation. Inspect exact hunks before future edits. The formatter and regional migration must work atop the committed preference baseline without staging that unrelated foundation.

## 15. TDD Matrix

| Slice | Initial RED | Expected GREEN |
|---|---|---|
| Defaults/schema | Existing/missing row lacks four defaults; read inserts a row; extension loses Phase 1/2A data | System defaults; no insert; unique/FK/cascade/revisions and existing values preserved |
| Validation | Valid keys rejected; unsupported timezone/arbitrary patterns/separators/locale/code or nested arrays accepted | Exact registered keys accepted; malformed/unknown request values rejected |
| Owner/protected fields | A can target B or overwrite owner/revisions/role/security | Current authenticated owner only; unrelated User data unchanged |
| Legacy payload/save | Omitting regional fields clears saved values; regional save changes sidebar token | Existing values preserved; only overall revision advances |
| Reset | Four fields not restored or profile/2FA/session state changes | Full preference defaults, existing reset revisions, identity/security unchanged; repeated reset safe |
| Timestamp | UTC object mutates; Jakarta conversion wrong at midnight; system adds unexpected hours/labels | Immutable instant conversion; 07:35Z → 14:35 WIB; boundary 23:35Z → next Jakarta calendar day; source-preserved system profile |
| Date-only | 2026-09-28 shifts under Jakarta; JS parses Y-m-d as an instant | Same calendar date in every zone; presentation only human/dmy/iso |
| Format keys | User PHP/Carbon expressions or JS code become formatter behavior | Trusted config mappings; null/placeholders and current punctuation preserved |
| Numbers | 1250000.5/zero/negative/integer/fractions change digits or rounding | Fixed 2-decimal example matches presets; max-decimal result remains trimmed; no extra float/rounding |
| Currency/quantity | Rp/currency code/unit/scale/abbreviations change | Same code/prefix/precision/units and thresholds; separators only |
| Settings UI | Missing labels/examples/error association/old input; preview persists | Native labeled selects/fieldset, clear examples/errors, no unsaved storage |
| Dashboards/roles | Regional formatting changes required panels/layout/access or supplier records | Existing role/context/dashboard contracts and own-data isolation remain; chosen displays format correctly |
| PHP/JS parity | Client helper unavailable at initialization; numeric/date-only output differs | Helpers work before deferred Vite; shared golden source text/profile cases preserve existing per-callsite scale and legacy fallback; chart datasets/order/raw values unchanged |
| Scope protection | Personal keys alter PDF/receipt/export/invoice/tax output, stored data, filters/order or deadlines | Representative outputs and raw/query/business results identical across preference choices |
| Performance | Each row/helper query reloads preferences | At most one preference lookup per relevant request; repeated formatting does zero additional queries |
| Migration rollback | Drop/re-up loses unrelated columns or breaks test restoration | Only four columns removed; Phase 1/2A data retained; re-up restores defaults |

Makassar/Jayapura conversions are conditional tests only if those zones become approved. In the proposed registry they are rejection cases. Add no new framework; use existing PHPUnit/MySQL/Node infrastructure.

## 16. Security Risks

- Reject arbitrary format strings, separators, locale and timezone identifiers; never evaluate a format expression or render user-owned HTML.
- Use existing owner-derived save/reset and explicit safe()->only fields; no user ID in route or request controls ownership.
- Normalize stale stored values to central defaults before use; validate legacy display profile keys against trusted config.
- Serialize only required primitive keys and server-owned numeric/date-only registries with safe JSON. No complete User object, format expression or sensitive attribute.
- Preserve CSRF/auth/role/PortalContext and destination policies; personal display never grants authorization.
- Do not change global timezone/locale or inject preferences into scheduler/jobs/export/mail.
- Keep formatter pure/in-memory and result escaped. Do not parse a personal-formatted value back into a calculation or an input.
- Retain CSP/security-header configuration and current sidebar account isolation.

Plan a targeted Laravel security review of these boundaries at Gate 2; no repository-wide audit.

## 17. Business-Logic Regression Risks

Highest risks and controls:

1. **DATE treated as instant:** method/API distinction, field/migration evidence, pure Y-m-d JS handling and boundary tests.
2. **Timezone change mutates Carbon/model attributes:** immutable copy and stored-value assertions.
3. **System silently switches UTC/WIB or precision:** per-target legacy profiles and before/after rendered-string characterization.
4. **Financial display re-rounds canonical values:** transcode existing numeric text; retain NumberFormat/Money and callback rounding.
5. **Localized text drives DataTables sorting/query/filter:** preserve raw data, machine attributes, ISO dates, month keys, selectedMonth and orderBy.
6. **Server/AJAX transcoding introduces a new precision difference:** compare PHP/JS output for the same source text/profile, preserve each existing callsite's zero/max/fixed-decimal behavior, format DOM only and preserve API field values/period calculations.
7. **Pending BusinessTime work accidentally included:** preserve dirty files and commit boundary; no personal preferences in those methods.
8. **Currency or grouping text mistaken for a numeric input:** display functions never feed input parsing, validation or writes.

Scope tests should compare representative PDF PO, invoice receipt/voucher display, Excel export row data, stored decimals/timestamps, ordering and a deadline/forecast result between users with different regional choices. Compare semantic fields/strings, not unstable binary PDF metadata.

## 18. Accessibility Risks

Regional UI only:

- Native select labels and a Regional fieldset/legend.
- Clear timezone names/offsets and number separator descriptions; examples associated by aria-describedby.
- Per-field aria-invalid/error association and preserved old input.
- Keyboard operation, visible focus, usable existing target sizes and reflow at zoom/narrow viewport.
- Examples distinguish event instants from calendar dates and do not rely on color.
- Optional changing samples use a polite status region without moving focus or announcing the whole form repeatedly.
- Existing semantic tokens govern Light/Dark; do not create a site-wide contrast/redesign task.

Browser/screen-reader verification is planned for implementation, not claimed in this plan.

## 19. Performance Plan

One safe resolved preference state per relevant request. Existing request-attribute cache remains authoritative. The exact dashboard composer and app layout share it; formatter instances and registries are reused in memory.

Repeated row/date/number operations must not query Eloquent, auth preference relations, config from the database or global caches. The number transcoder inspects only a bounded numeric text string; timezone choices and profiles are finite.

Measure query count on an authenticated dashboard and Customization request, then assert repeated formatter calls add zero preference queries. No Redis, global eager loading, per-row preference query or speculative query optimization.

The early client helpers operate only on visible text, use already-resolved keys and a small trusted registry, and are loaded before affected inline callbacks. Do not convert server-rendered Blade values wholesale to JS.

## 20. Migration / Rollback Plan

Future additive migration follows both committed customization migrations. No application/development DB mutation is part of this Gate. Before any future RefreshDatabase/migration test, verify both configured and connected dedicated test database; run MySQL suites serially.

Test up/down/re-up with an existing row containing non-default Phase 1/2A values and revisions. No manual data backfill, user rows or schema rewrite. Up defaults do not invalidate the sidebar cache or advance revisions.

Down removes only timezone/date_format/time_format/number_format. It discards saved regional selections; preference rows, ownership constraints, other settings and revisions remain. Re-up gives system defaults. Document that loss before deployment rollback.

Database UTC enforcement and the pending BusinessTime foundation are separate changes. This feature will not alter connection timezone, migrate historical event values or claim deployment status. Staging/production migration remains a separately authorized release action.

## 21. Verification Plan

**This Gate 1 executed read-only source/Git searches and document checks only. No tests, runtime browser checks, builds or database commands were executed.**

Future focused commands (new suite names are proposals until created):

    php artisan test --filter=TestingEnvironmentDatabaseSafetyTest
    php artisan test --filter=UserRegionalPreferencesTest
    php artisan test --filter=RegionalDisplayFormatterTest
    php artisan test --filter=RegionalDashboardRenderingTest
    php artisan test --filter=RegionalScopeProtectionTest
    php artisan test --filter=UserPreferenceMigrationTest
    php artisan test --filter='UserCustomizationTest|QuickAccessTest|UserDashboardCustomizationTest|UserDashboardRenderingTest|UserDashboardUiTest|UserPreferenceConcurrencyTest'
    php artisan test tests/Feature/ProfileTest.php
    php artisan test tests/Feature/Auth --compact
    php artisan test --filter='SidebarShellTest|FrontendAssetLoadingTest|SupplierPortalContextV2Test|SupplierDataIsolationTest|HashidUrlSecurityTest'
    php artisan test tests/Feature/LocalInvoice/LocalInvoiceScopeIsolationTest.php
    node --test tests/js/regional-preferences.test.mjs tests/js/preferences.test.mjs tests/js/calendar.test.mjs tests/js/unsaved-changes.test.mjs

Run appropriate php -l and scoped Pint on actual changed files, route:list, view:cache, npm.cmd run build and scoped/whole-tree git diff --check at integration. Run composer test only at final regression if required/runtime permits, not after small edits.

Manual future checks: native Regional selects/errors/examples; all eight dashboard outputs; System before/after parity; Jakarta midnight timestamp vs invariant calendar date; Finance initial/AJAX formatting under each original precision profile (not forced rounding parity); callbacks executed before deferred Vite; charts/compact labels; two accounts; reset; history after unsaved changes; keyboard/zoom. Keep automated, browser and deployment evidence separate. Do not claim coverage percentage unless measured.

Known whole-tree whitespace findings in .env.example and purchasing/po/show.blade.php remain untouched unless the feature directly changes those exact hunks; this plan does not target either.

## 22. Gate 1 Recommendation

Approve **Phase 2B.1 only**:

- All four regional preference fields/defaults/allowlists, preserving existing customization and revisions.
- System plus explicitly registered Asia/Jakarta; human/dmy/iso, 24h/12h and international/indonesian presets.
- One focused display formatter with strict timestamp/calendar-date distinction and unchanged precision helpers.
- The nine named interactive templates and safe early helpers for dashboard callbacks/Finance date-only text.
- The TDD, migration/scope protection, performance, security and Regional-form accessibility plan above.
- Existing outputs under System characterized and preserved; no global timezone/locale mutation or implicit inclusion of dirty BusinessTime work.

Phase 2B.2 lists/details are deferred. Notification, Language, dashboard/accent changes, document/export/API contracts and business calculations remain outside this approval.

**STOP: waiting for `Approve Gate 1 — Phase 2B`.**
