# Gate 2 - Phase 7 Language Preference & Full Internationalization

## 1. Status

**NOT COMPLETE - final interactive browser, responsive-reflow, and downloadable-export checks are unverified because no browser provider is available. The wider Pint dry run also reports style recommendations across 84 CLEAN PHP paths and additional MIXED paths.**

The Phase 7 implementation and required PHP/JavaScript acceptance suites pass. Sections 50-58 record the final test and static evidence. Earlier browser snapshots do not verify the latest copy edits.

## 2. Repository State

- Repository: C:\laragon\www\adasi_portal_supplier.
- Branch: language-update; HEAD: bc073d7153fcd907908e6677d93d253a3c08420a.
- The Phase 7 manifest contains 537 current dirty paths: 456 production and 81 test files. Classifications: 415 CLEAN, 121 MIXED, and one separately documented technical file.
- Current Git status has 631 dirty paths: 514 tracked and 117 untracked. The other 94 dirty paths are outside the Phase 7 manifest and remain untouched.
- Staged files: 0. Cached diff: empty. No commit or push was performed.
- The preserved intake baseline records 157 pre-existing tracked dirty files; no reset, stash, cleanup, or database migration outside the test database was performed.

## 3. TDD Progression

Locale migration, preference persistence, Laravel request locale, translated domains, recipient-local notifications, export locale restoration, and cross-preference preservation are implemented.

Latest separate regression runs: 1,359 tracked PHP tests passed with 15,021 assertions and two PHPUnit deprecations; 104 Phase 7 PHP tests passed with 165,271 assertions. The JavaScript suite passed 116 tests. The test database safety check passed against adasi_portal_test before each database suite.

The separate Regional/BusinessTime test run is recorded in section 57. The required final browser matrix remains unavailable in this environment.

## 4. Approved Migration

Implemented one migration: add locale to user_preferences as VARCHAR(2), NOT NULL, DEFAULT 'en', with no index. Existing rows receive en through the default. Rollback drops only locale. The timestamp selected was 2026_10_04_000001 after confirming the October migration sequence. Automated migration tests ran against the isolated adasi_portal_test database. No application, staging, or production migration was run.

## 5. Locale Architecture

Laravel native language files are under lang/en and lang/id. ApplyUserLocale resolves the authenticated account preference, otherwise an explicit guest session locale, with English established first. The bounded locale.switch route is used for guest session selection and authenticated preference saves. No external translation service or remote dictionary fetch is used.

## 6. Final Supported Locales

Exactly en and id are accepted and persisted. The UI labels are English and Bahasa Indonesia.

## 7. Default/Fallback

Application default and fallback are en. Unknown/stale stored values normalize to en. Browser Accept-Language does not affect locale selection.

## 8. Preference Persistence

Locale is a dedicated preference column, included in model fillable/config defaults and options, request allow-list validation, service normalization, effective snapshots, persistence, reset and cache invalidation. Unrelated saves preserve locale. The previous quick-access/dashboard/notification JSON contracts were left out of locale storage.

## 9. Runtime Locale Middleware

ApplyUserLocale runs in the web request stack after session start and before route binding/controller validation. It resets each request to en, then applies the authenticated user’s normalized effective preference. It uses the existing preference snapshot path. Controllers do not set locale individually.

## 10. Guest Locale Strategy

A new guest session starts in English. The public supplier registration page offers a bounded English/Bahasa Indonesia selector that stores only the locale in the session; no Accept-Language detection is used. The explicit guest choice follows the guest session to login and informational Password Assistance. Authenticated requests use the saved account locale.

## 11. HTML Lang

Authenticated layouts emit lang="en" or lang="id" from effective locale. Guest/auth layouts emit lang="en". Layout scope and browser spot-checks are listed in the file manifest and browser evidence below.

## 12. Language Selector

Profile → Customization has a Language select with English and Bahasa Indonesia, localized label/help, standard form save, allow-list validation, and no autosave. Selection persists with the full preference form.

## 13. Reset/Cross-Preference Preservation

Customization reset restores locale en while retaining the existing scoped reset contract. Notification preferences and security/auth state are not reset. The service merges partial updates under its existing transaction/row-lock path so theme, accent, density, sidebar, page size, quick access, dashboard, regional settings, and notification preferences survive locale saves; locale survives unrelated saves.

## 14. Concurrency

Existing UserPreferenceService transaction retries, lockForUpdate, revision and cache invalidation are reused. Locale/accent concurrency and representative notification/regional preservation are covered by the locale-preference and concurrency tests listed in the exact test manifest. No new concurrency mechanism was introduced.

## 15. Translation Domain Map

Thirty paired Laravel domains live at lang/en/<domain>.php and lang/id/<domain>.php. They cover common/navigation, auth/profile/security/customization, preferences/notifications, dashboards, purchasing, supplier import/local, invoices, finance/accounting, QC/GA/claims, validation, exports, tables and pagination. The exact domain/key inventory is the paired lang tree; parity is enforced mechanically.

## 16. Translation Key Parity

Thirty paired Laravel domains contain 6,537 flattened keys per locale. Automated parity reports no missing English/Indonesian keys and no placeholder-name mismatches. TranslationContentTest verifies plain translation values. These checks establish key and output safety, not translation quality by themselves.

## 17. Terminology Glossary

| Concept | English | Bahasa Indonesia | Keep technical? |
|---|---|---|---|
| Purchase Requisition | Purchase Requisition (PR) | Permintaan Pembelian (PR) | PR |
| Purchase Order | Purchase Order (PO) | Pesanan Pembelian (PO) | PO |
| Goods Receipt | Goods Receipt (GR) | Penerimaan Barang (GR) | GR |
| Quotation / Negotiation | Quotation / Negotiation | Penawaran / Negosiasi | No |
| Material Progress / Shipment | Material Progress / Shipment | Progres Material / Pengiriman | No |
| Quality Control / Claim | Quality Control / Claim | Quality Control / Klaim | QC |
| Invoice / Revision | Invoice / Revision | Invoice / Revisi | Invoice retained in local workflow |
| Approved / Rejected / Paid | Approved / Rejected / Paid | Disetujui / Ditolak / Dibayar | No |
| Ready to Pay / Physical Delivery | Ready to Pay / Physical Delivery | Siap Dibayar / Pengiriman Fisik | No |
| Supplier Registration | Supplier Registration | Pendaftaran Pemasok | No |
| Import Supplier / Local Supplier | Import Supplier / Local Supplier | Pemasok Impor / Pemasok Lokal | No |
| Payment / Refund / Overpayment / Settlement | Payment / Refund / Overpayment / Settlement | Pembayaran / Pengembalian Dana / Lebih Bayar / Pelunasan | No |
| Dashboard / Notification / Security | Dashboard / Notification / Security | Dasbor / Notifikasi / Keamanan | No |
| Active Sessions / Two-Factor Authentication | Active Sessions / Two-Factor Authentication | Sesi Aktif / Autentikasi Dua Faktor | 2FA and MFA retained |
| Quick Access / Customization | Quick Access / Customization | Akses Cepat / Kustomisasi | No |
| ADASI | ADASI | ADASI | Yes |

## 18. Hardcoded String Sweep

The final source-located ledger contains 19,535 candidate occurrences: 17,116 mechanically classified with reasons and 2,419 semantically reviewed. It records 7,733 translation references across 288 files with review-required candidates. The inventory test requires exact parity between current scanner output and the ledger and rejects missing decisions or rationales.

The semantic audit decision is unresolved fixed user-facing copy = 0 in active application-rendering paths. Candidate totals are occurrences, not unique phrases or unresolved strings. The reproducible generator and JSONL ledger are linked from PHASE-7-COPY-AUDIT.md.

## 19. Shared Layout / Navigation Localization

Shared app/auth/guest layouts, navbar, sidebar, breadcrumbs, dropdowns, tabs, mobile navigation and Quick Access use semantic keys. Role visibility and authorization expressions remain unchanged. HTML lang follows the layout locale policy in section 11.

## 20. Auth Localization

Login, password confirmation, 2FA setup/confirmation, recovery codes, throttling and validation copy use English/Indonesian Laravel domains. No reset-email/token workflow or email-verification flow was added.

## 21. Password Assistance Localization

Password Assistance is an informational page with localized title, description, support contact, subject/body template, copy actions, Open Email App, and clipboard feedback. Authenticated users can view it in their saved locale; guests use English unless they explicitly selected Indonesian in the guest session. The support destination remains configuration-controlled. No reset token, reset email, generated email, password reset, or email-verification flow was added.

## 22. Profile/Security Localization

My Profile, Account Information, Security, Change Password, 2FA, Browser/Active Sessions and Log Out Other Devices display translated labels and statuses. Security behavior, authentication state and session revocation logic were not changed.

## 23. Customization Localization

Customization’s language field follows the existing design system and full-form save semantics. Browser evidence switched id → en → id, checked selected option, HTML lang and JS payload, and confirmed regional settings stayed the same.

## 24. Notification Preferences Localization

Preference labels, descriptions and categories resolve through translation keys; event/preference keys and persistence semantics remain stable. Locale and notification preference saves preserve one another.

## 25. Runtime Notification Localization

Notification delivery resolves recipient locale for each eligible recipient using batched preference reads and applies stored text overrides per recipient. A browser check showed the same synthetic event as English for an EN account and Indonesian for an ID account. Delivery eligibility, deduplication, channel and event keys remain unchanged.

## 26. Historical Notification Policy

New database notification text is rendered in the recipient’s effective locale when created. Existing notification rows are never rewritten when a user changes language. No historical data migration was added.

## 27. Queue/Worker Locale Safety

Locale is captured for exports and restored in finally blocks around job/export execution. Recipient notification rendering uses recipient-local translation without relying on one sender’s request locale. Reviewed export queue entry points include ProcessExportJob, workbook generation and the locale-aware export concern. Long-lived worker leakage is covered by export/worker tests.

## 28. Dashboard Localization

Eight role audiences were browser checked: Admin, Purchasing, Finance, Accounting, QC, GA, Import Supplier, and Local Supplier. Each retained its existing widgets and labels were translated. Desktop and 320 CSS px checks reported no horizontal document overflow.

## 29. Purchasing Localization

Requisitions, quotations/review, comparison, negotiation, PO, documents, progress, shipment, QC feedback, claims, registration review and DRP copy use the purchasing/common domains. Status values, comparison calculations, award composition, routes and permission checks remain stable.

## 30. Supplier Import Localization

Supplier dashboard, quotations, conversations, PO, documents, progress, shipment and claims use supplier/import domains. Supplier ownership filters, workflow and stored values were not changed.

## 31. Supplier Local/Invoice Localization

Local Supplier profile, PO/GR, invoice lifecycle, revision, physical receipt/delivery, payment, refund, overpayment and settlement surfaces use local-invoice/common domains. Shared invoice views were checked for Supplier, Accounting and Finance audiences. Amounts, GR snapshots, payment data and history remain fixed.

## 32. Finance Localization

Finance dashboard, invoice, physical receipt, review, DRP, voucher, payment/refund and forecast labels/messages are localized. English single/plural invoice counters use complete trans_choice forms; JS counters use the existing bounded choice helper.

## 33. Accounting Localization

Legacy accounting invoice listing and shared invoice views use the account locale while accounting routes and role access remain intact. Browser checks covered the localized populated invoice/history view.

## 34. QC Localization

QC dashboard, inspection, material labels, result/status presentation and accessible copy use translated display values. QC machine states and inspection behavior remain unchanged.

## 35. GA Localization

Employee, claim, receipt and DRP labels use GA/common domains. Claim type codes and payment-engine state remain stable.

## 36. Validation Localization

Laravel validation language files and FormRequest messages/attribute labels include English and Indonesian. The locale middleware runs before request validation. No controller-specific locale branching was added.

## 37. Status/Enum Presentation

Known status display maps, including local invoice and GR snapshot labels, are locale-aware. Raw enum/database values such as READY_TO_PAY, PAID, REJECTED and APPROVED remain unchanged. Tone and authorization maps were preserved.

## 38. DataTables

Central setup reads one local dictionary for search, length, information, empty/loading/processing, pagination and accessibility labels. No external language JSON/CDN request is used. Populated browser check saw locale-appropriate search and previous labels; the token values remained intact.

## 39. Pagination

Server pagination views use localized labels and ARIA text. Accent styling and Phase 6 pagination behavior were retained.

## 40. JS Localization

AdasiI18n exposes a bounded synchronous JSON payload and plain-text translation/plural-choice helpers. It includes only keys used by JavaScript and makes no translation network request. The final measured payload is 253 keys: English 14,761 raw / 4,161 gzip bytes; Indonesian 14,991 raw / 4,329 gzip bytes. Both remain within the configured 16 KiB raw / 5 KiB gzip targets.

## 41. Accessibility Copy

ARIA labels, descriptions, sr-only copy, live announcements, action names, pagination and table copy are localized as values; ARIA attribute names and roles stay fixed. Longer Indonesian copy was checked at 320 CSS px on representative pages.

## 42. Pluralization/Interpolation

Named placeholders are parity checked. Local invoice summaries, Finance invoice counters, awarded-item messages, employee/item counts and pending-selection counts use whole singular/plural messages. User/business values remain escaped Blade output or plain JS text interpolation; no grammatical fragments are concatenated for these converted messages.

## 43. Export/Report Localization

Human-facing XLSX and PDF headings, captions, and status presentation use the captured locale. No application-generated CSV export path was found; CSV is used for import protocols. Bank/ERP cells, machine headers, formulas, identifiers, and audit/history snapshots remain fixed. Automated workbook/export and locale-restoration tests pass. Earlier browser QA downloaded representative PO XLSX and PDF files in both locales; a final post-edit browser download/open check is still outstanding.

## 44. Security Review

Effective values are allow-listed to en/id; translation paths are not user-controlled. Locale is mass-assigned only through the preference service/request allow-list and authenticated user boundary. Translation files are static PHP, output remains escaped/plain text, and JS interpolation does not interpret HTML. CSRF and authorization middleware remain in place. Preview tests exercised quoted/HTML payloads as data. Per-recipient and worker locale are scoped/restored.

## 45. Accessibility Review

Browser checks covered navigation, forms, dashboard cards, data tables, notification panel, modal keyboard behavior and selected pages at 320 CSS px. Indonesian copy motivated stacked toolbars, wrapping controls and narrower invoice layout. One existing Bootstrap aria-hidden focus warning was observed while closing the GR modal; focus returned and Escape closed it. Screen-reader and full 400% reflow certification were not performed.

## 46. Performance Measurements

Preference query test measured one user_preferences SELECT for middleware/layout/100 translation-payload calls. Translation lookup is file-backed with no query per key. Notification preference reads are batched, up to 500 recipients per chunk; no per-recipient locale query loop was added. The bounded JS payload measurements are in section 40. No extra polling or translation network request was added.

## 47. Exact Production Files

The companion exact file manifest enumerates 537 Phase 7 paths: 456 production and 81 test files, with 415 CLEAN, 121 MIXED, and one separately documented technical file; no deletions. Each row records module, reason, classification, and overlap. Reconciliation against the current Git status found no missing or stale manifest path.

## 48. Exact Test Files

The companion manifest enumerates all 81 Phase 7 test files. Coverage includes migration up/down and existing-row defaults; locale validation, persistence, reset and isolation; middleware and HTML lang; translation parity and plain content; validation; recipient-locale notifications and history; export locale restoration; DataTables; JS; concurrency; copy-ledger parity; and representative rendering.

## 49. Dirty Tree Protection

The preserved baseline is under %TEMP%/adasi-phase7-baseline-20261004-085909 and includes user-diff-before.patch. The initial tracked dirty set was 157 files. Current dirty state is 631 paths; 537 manifest paths belong to Phase 7 and 94 other paths remain outside it. BusinessTime, Regional, polling, registration, supplier/finance, and other mixed edits were preserved. Staged count remains zero. No reset, stash, cleanup, commit, or push occurred.

## 50. Tests Executed

Each database-backed suite was run serially after TestingEnvironmentDatabaseSafetyTest verified configuration and SELECT DATABASE() both equal adasi_portal_test.

- Full tracked PHP suite: 1,359 tests, 15,021 assertions, zero failures/errors, two PHPUnit deprecations.
- Phase 7 PHP suite: 104 tests, 165,271 assertions, zero failures/errors.
- JavaScript suite: 116 passed, zero failures.
- TestingEnvironmentDatabaseSafetyTest: 2 tests, 4 assertions, passed.
- Separate technical-debt suite: 19 tests, 3 failures and 1 error; see section 57.
- No application, staging, or production database migration was run.

## 51. Focused Results

The Phase 7 suite passed 104 tests and 165,271 assertions. Focused copy-inventory coverage passed 28 tests / 75,880 assertions; translation content passed 15 / 52,457; parity passed 3 / 35,110; import rendering passed 8 / 80. These are separate runs and their assertion totals must not be added as a unique total.

## 52. JS Results

All tests/js/*.test.mjs passed: 116 tests, zero failures, zero skipped. Coverage includes translation helpers, zero/one/many messages, chat accessible copy, async export counts, registration/local invoice input counters, import preview escaping, and interaction behavior.

## 53. Translation Parity Results

Final paired translation tree: 30 domains and 6,537 flattened keys per locale. TranslationParityTest passed with no missing keys or placeholder mismatches; TranslationContentTest passed its plain-content checks.

## 54. Static Hardcoded-Copy Sweep Results

The final scanner/ledger snapshot reports 19,535 candidate occurrences, 17,116 mechanically classified, 2,419 semantically reviewed, 7,733 translation references, and 288 files with review-required candidates. The review ledger gives every candidate a source location, module, decision, and rationale. Its reviewed conclusion is zero unresolved fixed user-facing copy in active paths. These scanner numbers are not counts of untranslated strings.

## 55. Tracked PHP Regression

The final frozen-source tracked PHP regression passed 1,359 tests and 15,021 assertions, with no failures or errors and two PHPUnit deprecations. The separate 104-test Phase 7 suite also passed. Browser-only gates remain outstanding.

## 56. Tracked JS Regression

The complete JavaScript suite, including tracked and Phase 7 additions, passed 116 tests with no failures or skips after the final source edits.

## 57. Untracked Technical Debt Boundary

The separate untracked Regional/BusinessTime timezone suite ran 19 tests and produced 3 failures plus 1 error; it is outside the 81-file Phase 7 test manifest.

- PresentationLayerTimezoneTest references app/Notifications/NewDeviceLoginNotification.php, which is absent from the repository.
- Three LocalInvoiceViewTimezoneTest assertions expect WIB for timezone=system. The active regional registry gives the system timezone no zone_label, so the formatter omits WIB; failures assert on 09:00 WIB / 14 Oct 2026 09:00 WIB.
- The tracked RegionalDisplayFormatterTest passed in the full tracked regression. The Phase 7 month-name localization addition shares that formatter, but did not change timezone semantics. The separate failures concern the regional system zone-label behavior and a missing notification test class; no unrelated fixes were made.

## 58. Static/Build Verification

- PHP syntax: 509 manifest PHP files linted; zero syntax failures.
- Laravel view:cache completed successfully after the last Blade edits.
- JavaScript: 116 passed. Vite build succeeded: CSS 128.45 kB / gzip 22.47 kB; JS 116.86 kB / gzip 36.49 kB. The existing logo asset warning remains and resolves at runtime.
- Locale route inspection shows locale.switch at GET|POST locale/{locale?}. git diff --check passed.
- Focused Pint dry run on 14 closure-related PHP files passed 13; the only finding is the MIXED RegionalDisplayFormatter.php (unary spacing and import ordering).
- The wider 509-file Pint dry run is not clean: it reports formatter recommendations across 84 of 391 CLEAN PHP paths and additional MIXED paths, mostly line-ending normalization. No mass formatting was applied because it would expand the diff into unrelated existing lines. This is a style-check limitation, not a PHP syntax or test failure.

## 59. Browser Verification English

Earlier English browser QA checked guest Login and Password Assistance at 320 CSS px, Admin/Finance dashboard and Customization, populated invoices, DataTables, notifications, and clipboard focus feedback. No email or financial action was submitted.

The final closure turn could not reopen the browser because the CUA inventory returned no apps or browsers and no browser provider was available. The last registration/chat/HS-code copy edits and final browser sign-off therefore remain unverified interactively.

## 60. Browser Verification Bahasa Indonesia

Earlier Indonesian browser QA checked dashboards, Customization, Security, notifications, and populated Finance/Supplier/Accounting invoice pages. Account language persistence and stable numeric/history values were observed.

The browser provider was unavailable for the final closure turn, so this earlier evidence does not validate the latest source snapshot.

## 61. Role Coverage

Earlier browser sessions covered dashboards for Admin, Purchasing, Finance, Accounting, QC, GA, Import Supplier, and Local Supplier, including supplier dual-scope switching. Dashboard composition remained stable. A final post-edit role-by-role browser pass is still required.

## 62. Language Switching/Persistence

Earlier authenticated QA saved id -> en -> id and checked HTML lang, selected option, bounded JS payload, and preservation of regional settings. Fresh guest sessions default to English; the explicit selector on /supplier/register stores locale in the session and applies it to later guest pages. Account preferences take precedence after authentication. Final interactive recheck is blocked by the absent browser provider.

## 63. Notification Recipient Locale Browser Verification

Earlier browser QA showed the same representative notification in English and Indonesian for recipients with different account locales; stored historical text stayed unchanged after changing locale. Automated recipient-locale tests pass. No mail or broadcast was sent. Final browser recheck is blocked by the absent provider.

## 64. DataTables Browser Verification

Earlier browser QA checked a populated internal user table with localized Search and Previous labels and no external language JSON request. DataTablesLocalizationTest passes. The centralized dictionary and token formatting remain intact. Final post-edit browser recheck is outstanding.

## 65. Responsive/Reflow Verification

Earlier browser QA checked representative shared toolbar, Admin users, QC dashboard, notification panel/preferences, Finance, PO/GR, and populated local invoice detail at 320 CSS px. Corrected pages had no horizontal document overflow; operator tables retain their internal scroll container.

A dedicated 400% equivalent reflow and the final post-edit responsive/keyboard sweep were not verified in this session. Formal screen-reader certification is separate operational work.

## 66. Export Verification

Export automated tests cover localized human captions, worker locale restoration, formulas, and fixed bank/ERP integration cells. Earlier browser QA downloaded representative PO XLSX and PDF exports in both locales. The latest browser download/open pass could not run because no browser provider is available. No generated CSV exporter was found; CSV is an import protocol only.

## 67. Database Decision

One approved schema change only: user_preferences.locale VARCHAR(2) NOT NULL DEFAULT 'en', no index; down removes only locale. Migration tests use adasi_portal_test. No ordinary application DB migration was run.

## 68. Dependency Decision

NO NEW PACKAGE. Laravel native localization is used.

## 69. Explicit Deferred Items

The required final interactive browser pass remains blocked by the empty CUA app/browser inventory. It must verify the latest registration summary/counters, Admin HS-code empty state, chat time/read-receipt copy, and supplier price-history dates; the role EN/ID matrix; DataTables; notification recipients; keyboard/focus; 320 CSS px and 400% equivalent reflow; and representative XLSX/PDF downloads in both locales.

The broader Pint dry run reports style recommendations on 84 CLEAN PHP paths and additional MIXED paths. Review these within the Phase 7 diff before a final style sign-off; do not mass-format mixed files or overwrite unrelated line endings. The separate timezone failures remain recorded in section 57.

Staging/production migration and deployment, production schema verification, and formal screen-reader certification remain separate from local Gate 2.

## 70. Scope Protection

No browser Accept-Language detection, machine translation, historical notification rewrite, auth delivery/reset workflow, additional schema change, or package was introduced. Guest locale selection is an explicit en/id session switch on public supplier registration; authenticated persistence uses the account preference service. Existing supplier authorization, finance snapshots, payment workflows, machine keys, and dashboard composition remain unchanged.

## 71. Phase 7 Closure Decision

Gate 2 remains NOT COMPLETE because the implementation plan requires final interactive browser evidence for responsive/reflow, role/localization behavior, and downloaded exports. This session could not provide it: CUA listed no apps or browsers, rejected the in-app tab, and reported no browser provider. The isolated QA server was stopped, and its configuration plus SELECT DATABASE() were verified as adasi_portal_test before launch.

Automated runtime gates passed: 1,359 tracked PHP tests, 104 Phase 7 PHP tests, 116 JavaScript tests, parity/content/copy-ledger checks, 509 PHP syntax checks, Laravel view cache, Vite build, route inspection, and diff check. The broader Pint dry run is not clean: it reports recommendations across 84 CLEAN and additional MIXED PHP paths. Focused Pint passed 13 of 14 closure-related files; the remaining file is MIXED. These findings are recorded and were not mass-formatted.

The separate Regional/BusinessTime failures remain listed in section 57. No staging or production migration/deployment was performed. No files were staged, committed, or pushed. The current evidence is in this report, PHASE-7-COPY-AUDIT.md, and PHASE-7-EXACT-FILE-MANIFEST.md. Resume browser and scoped style review when available; do not mark COMPLETE until the required checks pass against the frozen source.

