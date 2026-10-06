# Phase 7 hardcoded-copy audit

Audit status: complete against the final Phase 7 source snapshot on branch language-update, HEAD bc073d7153fcd907908e6677d93d253a3c08420a.

Result: unresolved fixed user-facing copy is **0**. Remaining scanner candidates have a source-located disposition in [PHASE-7-COPY-AUDIT.jsonl](PHASE-7-COPY-AUDIT.jsonl); these are not a list of untranslated strings.

## Scanner snapshot

The scanner reads Blade views, JS, application HTTP/services/notifications/support/models/data/imports/exports/jobs and relevant configuration. It preserves original file, line, byte offset, source kind and sink context.

| Measure | Count |
|---|---:|
| Candidate occurrences | 19,535 |
| Mechanically classified with a recorded reason | 17,116 |
| Candidates requiring semantic review | 2,419 |
| Translation references found | 7,733 |
| Files with review-required candidates | 288 |

The 2,419 review-required occurrences break down by source context as follows:

| Context | Occurrences | Audit decision |
|---|---:|---|
| Message/display context | 926 | Trace the active caller: import/file contracts, translated validation boundary, internal diagnostics, provenance metadata, or dynamically generated markup. |
| Script text sink | 932 | Distinguish translated references, DOM/data/selector values, escaped dynamic data, and generated template markup. |
| Display expression literal | 297 | Preserve enum, field, format, route, state, legal and styling identifiers; render human labels through translation helpers. |
| Visible text | 212 | Preserve official company data, statutory/technical terms, currency/units and separators; the default Laravel starter views are inactive. |
| Interpolated message/display context | 48 | Preserve field paths and protocol metadata; user-facing errors are translated at their controller/service boundary. |
| ARIA label | 2 | Keep the language self-names “English” and “Bahasa Indonesia”; the registration group name now resolves through a translation key. |
| Placeholder | 2 | Keep local email/telephone input examples as format examples, not prose. |

By source root, the 2,419 review-required candidates are 1,535 under resources, 801 under app, and 83 under public. These are scanner occurrences and do not represent 2,419 unique phrases.

## Candidate decisions

The JSONL ledger contains one row per candidate occurrence with path, line, offset, source, text, context, scanner classification/reason, module, final decision and decision rationale. The decision buckets for the semantically reviewed occurrences are:

| Decision | Occurrences |
|---|---:|
| Machine/domain identifier | 1,382 |
| Dynamic JavaScript template | 305 |
| Statutory or technical term | 243 |
| Internal or domain message | 100 |
| Generated markup/code | 125 |
| Official/user-supplied display data | 44 |
| Official company/contact data | 14 |
| Internal diagnostic | 51 |
| Source/audit metadata | 35 |
| Translated domain exception | 22 |
| Fixed import protocol | 13 |
| Fixed workbook contract | 15 |
| Other named dispositions (language names, input examples, historical classifier, legacy constants, compatibility value, validation field paths, translation references, inactive scaffolds, operator CLI diagnostic) | 70 |
| **Total manually dispositioned** | **2,419** |

The inventory test compares the complete current candidate set to this ledger and fails if a new candidate appears, a prior candidate disappears without ledger regeneration, or a decision/module/rationale is blank. Regenerate it after an intentional source change with:

    php tests/Support/user-facing-copy-audit.php

The raw scanner can also be reviewed with:

    php tests/Support/user-facing-copy-inventory.php

## Active user-facing copy fixes confirmed

- Alpine x-text expressions are now scanned alongside scripts, x-data, Blade output, PHP interpolation, HTML display attributes and JS t/choice references. A fixture proves a conditional branch remains reviewable while a translation key is recorded.
- The public supplier-registration summary now translates its General, PKP and Non-PKP labels. NIB/NPWP and tax-invoice digit counters use complete locale-specific messages with :count and :max; they no longer append a grammatical fragment.
- The registration language-selector group has a bilingual accessible name. The button names retain the self-names of the languages.
- The Admin HS-code inactive-reference empty state uses admin.copy.none in both languages.
- Conversation sender fallback, read-receipt accessible title, chat time format and supplier price-history month labels follow the effective account/page language. Async export fallback counts use the account language.
- The audited local-finance, DRP, PO, registration and QC status cells render through StatusHelper labels; stored statuses remain unchanged.
- Currency option display labels use translated terms while currency codes and the legacy ExchangeRate::CURRENCY_LABELS constant remain stable.

## Explicit remaining candidate classes

Import workbook headings and accepted values such as Available / Not Available, Order Line and Received Quantity remain fixed because parsers and supplier/ERP templates rely on those exact protocol tokens. Legal business identifiers and statutory Indonesian terms—including PKP/Non-PKP, NPWP, NIB, NSFP, PPN, PPh and DPP—remain recognizable legal codes; surrounding explanatory copy is translated where the interface provides it. Company legal name/address, configured support contacts, user-entered values, file names, currency symbols, units, route/event/status/field identifiers and CSS/DOM tokens retain their data contract or protocol meaning.

Export worker exceptions are internal diagnostics: ExportProgressService stores the exception message, while ExportDownloadController::serialize() does not expose error_message; the user receives the translated export status/notification. Local PO/GR validation exceptions pass through LocalPoReferenceService::messageForDisplay() before becoming HTTP validation copy. Notification-category words in NotificationCategory::key() classify historical notification text; the rendered category options come from translated keys.

resources/views/welcome.blade.php is not routed: / redirects to login. The legacy resources/views/dashboard.blade.php is likewise not a role dashboard route. PortalContext::LABEL_* constants have no callers; active labels use translation methods. The employee-import error is emitted only by its operator-only Artisan path.

No human-readable fixed English or Indonesian sentence remains unresolved in an active application-rendering path. Translation parity, rendered EN/ID pages, accessible names, server/JS output and the source-located candidate ledger provide separate checks for this conclusion.
