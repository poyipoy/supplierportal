# Implementation Plan — Phase 2 User Customization

**Project:** ADASI Supplier Portal
**Phase:** 2 — Advanced User Customization
**Status:** Planning / Gate 1 Required Before Implementation
**Target Stack:** Laravel, Blade, Alpine.js, Tailwind design tokens, Bootstrap compatibility, DataTables
**Repository:** `C:\laragon\www\adasi_portal_supplier`

---

## 1. Overview

Phase 2 memperluas fondasi **User Customization** dari Phase 1 menjadi personalisasi yang lebih kaya, tetapi tetap menjaga prinsip utama bahwa customization hanya mengubah **presentation dan preference**, bukan authorization atau business rules.

Phase 2 dirancang sebagai extension terhadap infrastructure Phase 1. Implementasi tidak boleh membuat sistem preference kedua jika `user_preferences`, resolver, frontend bootstrap, atau registry Phase 1 sudah dapat diperluas dengan aman.

Target utama Phase 2:

1. Dashboard Customization.
2. Regional Preferences.
3. Accent Color.
4. Notification Preferences.
5. Language Preference, hanya jika localization foundation repository cukup matang.

Prinsip utama:

```text
Customization != Authorization
```

User boleh mengatur tampilan dan pengalaman kerja, tetapi tidak boleh memperoleh data, menu, widget, route, notification channel, atau permission yang sebelumnya tidak dimiliki.

---

## 2. Dependency on Phase 1

Phase 2 bergantung pada hasil implementasi aktual Phase 1.

Sebelum coding, Gate 1 Phase 2 wajib memverifikasi source code aktual untuk memastikan keberadaan dan bentuk dari:

- `UserPreference` model;
- `user_preferences` migration;
- `UserPreferenceService`;
- customization controller/request;
- `/profile/customization`;
- theme preference;
- density preference;
- sidebar preference;
- page-size preference;
- Quick Access registry/resolver;
- frontend preference bootstrap;
- Phase 1 tests.

Source code menjadi source of truth. Jika implementasi Phase 1 berbeda dari rancangan awal, Phase 2 harus mengikuti implementasi yang benar-benar ada dan menghindari duplikasi.

---

## 3. Phase 2 Scope

### 3.1 Dashboard Customization

User dapat:

- menampilkan widget;
- menyembunyikan widget;
- mengubah urutan widget;
- mengembalikan layout dashboard ke default;
- memiliki layout berbeda sesuai role/context jika memang dashboard berbeda.

User tidak dapat:

- membuat widget arbitrary;
- mengirim Blade path;
- mengirim class/component name;
- mengirim raw HTML;
- mengirim route arbitrary;
- mengirim query/database expression;
- mengakses widget di luar role/context yang sah.

---

### 3.2 Regional Preferences

Preference display yang dapat dipertimbangkan:

- timezone;
- date format;
- time format;
- number format.

Preference hanya memengaruhi tampilan interaktif portal dan tidak boleh mengubah stored value atau format business/legal document tanpa requirement eksplisit.

---

### 3.3 Accent Color

User dapat memilih accent dari preset yang disetujui design system.

Tidak termasuk:

- arbitrary HEX color;
- color picker bebas;
- custom CSS;
- custom font;
- wallpaper/background image;
- arbitrary sidebar color.

Accent tidak boleh mengubah semantic status color seperti:

- error;
- warning;
- success.

---

### 3.4 Notification Preferences

User dapat mengatur notification yang memang configurable menurut existing notification architecture.

Phase 2 harus membedakan notification menjadi:

1. **configurable**;
2. **mandatory**;
3. **not applicable**.

Security-critical notification harus tetap mandatory kecuali current business rules menyatakan sebaliknya.

---

### 3.5 Language Preference

Target potensial:

- Bahasa Indonesia;
- English.

Namun Language Preference hanya masuk implementasi Phase 2 utama jika repository telah memiliki localization foundation yang cukup matang.

Jika mayoritas UI masih hardcoded, Language harus dipindahkan ke **Phase 2B** agar tidak mengubah Phase 2 menjadi refactor seluruh aplikasi.

---

## 4. Recommended Phase Split

### Phase 2A — Core Advanced Customization

Prioritas utama:

- Dashboard customization;
- Regional preferences;
- Accent color.

### Phase 2B — Communication and Localization

Conditional berdasarkan repository readiness:

- Notification preferences;
- Language preference.

Split ini harus divalidasi pada Gate 1. Jika notification architecture dan localization sudah matang dan blast radius tetap terkendali, keduanya dapat tetap dikerjakan dalam satu Phase 2.

---

## 5. Target Account Settings Structure

Struktur konseptual:

```text
ACCOUNT SETTINGS

/profile
└── Profile

/profile/security
└── Security

/profile/customization
└── Customization
    ├── Appearance
    ├── Navigation
    ├── Dashboard
    ├── Regional
    └── Notifications

/notifications
└── Notification Center
```

Jika Profile dan Security masih digabung saat Phase 2 dimulai, Phase 2 tidak boleh memaksakan split kecuali sudah menjadi task terpisah yang disetujui.

---

## 6. Dashboard Customization Architecture

### 6.1 Trusted Widget Registry

Dashboard widget wajib berasal dari trusted server registry.

Contoh konseptual:

```php
return [
    'supplier.import' => [
        'purchase_orders' => [
            'label' => 'Purchase Orders',
            'component' => '...',
            'default_visible' => true,
            'required' => false,
        ],
    ],
];
```

Contoh di atas hanya ilustrasi. Actual registry harus mengikuti current dashboard implementation.

Registry dapat memuat metadata seperti:

- stable key;
- label;
- component/view mapping;
- default visibility;
- required flag;
- role eligibility;
- supplier portal context;
- default order;
- optional capability/policy metadata.

Client hanya menyimpan dan mengirim **stable widget keys**.

---

### 6.2 Widget Trust Boundary

Flow yang diharapkan:

```text
User
  ↓
Role / Context
  ↓
Trusted Widget Registry
  ↓
Saved Widget Keys
  ↓
Server Normalization
  ↓
Safe Dashboard Rendering
```

Jangan pernah menggunakan client-provided value sebagai:

```text
Blade view path
Component path
PHP class
Route name
Raw HTML
Database query
```

---

### 6.3 Widget State

Contoh persisted structure:

```json
{
  "visible": [
    "purchase_orders",
    "shipments",
    "claims"
  ],
  "order": [
    "purchase_orders",
    "shipments",
    "claims"
  ]
}
```

Alternatif structure dapat digunakan jika lebih sesuai dengan repository.

Server harus melakukan normalization setiap render agar:

- deleted widget tidak merusak dashboard;
- revoked widget tidak muncul;
- role change tidak menghidupkan widget yang tidak lagi valid;
- context change tidak membocorkan widget lain.

---

### 6.4 Required Widgets

Registry sebaiknya mendukung:

```text
required
configurable
default_visible
```

Required widget tidak boleh disembunyikan oleh user.

Client tidak menentukan `required`; server registry yang menentukan.

---

## 7. Dashboard Reordering UX

Prioritas implementasi:

1. Move Up / Move Down button.
2. Optional native drag-and-drop jika tetap accessible.
3. Third-party drag library hanya jika benar-benar diperlukan dan sudah disetujui.

Keyboard user wajib dapat melakukan reorder tanpa drag.

Minimal accessibility contract:

- setiap widget memiliki accessible label;
- move controls dapat difokuskan;
- state order dapat dipahami screen reader;
- focus tidak hilang setelah reorder;
- status perubahan order disampaikan secara accessible bila perlu.

---

## 8. Preference Persistence Strategy

Phase 2 harus lebih dulu mengevaluasi apakah existing `user_preferences` dapat diperluas.

Pilihan yang disarankan jika struktur Phase 1 mendukung:

```text
user_preferences
├── theme
├── density
├── sidebar_state
├── page_size
├── quick_access
├── accent
├── timezone
├── date_format
├── time_format
├── number_format
├── dashboard_preferences JSON
└── revision
```

Jika notification settings berkembang menjadi matrix kategori × channel yang cukup besar, separate table mungkin lebih tepat.

Jangan memasukkan semuanya ke JSON jika field tersebut sering divalidasi/query secara individual dan memiliki contract yang stabil.

---

## 9. Dashboard Preference Storage

Recommended field:

```text
dashboard_preferences JSON
```

Isi hanya trusted stable keys dan layout state.

Jangan simpan:

- label;
- HTML;
- route;
- component path;
- class name;
- authorization flag.

Semua metadata harus tetap berasal dari server registry.

---

## 10. Regional Preferences Architecture

### 10.1 Timezone

Timezone harus berasal dari allowlist server-side.

Candidate awal yang perlu divalidasi terhadap kebutuhan bisnis:

```text
Asia/Jakarta
Asia/Makassar
Asia/Jayapura
```

Jangan secara otomatis menyediakan seluruh IANA timezone jika aplikasi hanya beroperasi dalam konteks bisnis Indonesia.

Stored database timestamps tetap dalam strategy aplikasi saat ini; timezone preference hanya digunakan untuk display conversion.

---

### 10.2 Date Format

Jangan menyimpan arbitrary PHP date format string.

Gunakan stable keys:

```text
human
dmy
iso
```

Contoh mapping server:

```text
human → 28 Sep 2026
dmy   → 28/09/2026
iso   → 2026-09-28
```

Exact format harus dikunci dalam config.

---

### 10.3 Time Format

Gunakan stable keys:

```text
24h
12h
```

Server memetakan ke trusted formatter.

---

### 10.4 Number Format

Possible presets:

```text
international → 1,250,000.00
indonesian    → 1.250.000,00
```

Actual key naming harus mengikuti repository convention.

Business/accounting outputs tidak boleh otomatis mengikuti personal display preference jika format tersebut memiliki requirement tetap.

---

## 11. Regional Formatting Scope

Preference hanya diterapkan pada interactive display kecuali source code/business rule membuktikan sebaliknya.

Default out-of-scope untuk personal formatting:

- Excel exports;
- PDF documents;
- invoices;
- tax documents;
- receipts;
- emails;
- integration payloads;
- persisted numeric/date values.

Gate 1 harus mengidentifikasi shared formatters yang aman untuk diperluas tanpa mengubah contract bisnis.

---

## 12. Accent Color Architecture

Accent harus menggunakan predefined design tokens.

Contoh conceptual mapping:

```php
'accent_presets' => [
    'default',
    'blue',
    'teal',
    'indigo',
];
```

Exact options ditentukan setelah audit palette existing.

Frontend dapat menggunakan:

```html
<html data-accent="default">
```

Design token layer kemudian menentukan semantic primary/interactive tokens.

Accent preference harus tetap compatible dengan:

- light theme;
- dark theme;
- focus rings;
- links;
- primary buttons;
- selected navigation state;
- hover/pressed state.

---

## 13. Notification Preferences Research

Sebelum implementasi, inspect:

- Laravel Notification classes;
- notification channels;
- mail notifications;
- database/in-app notifications;
- notification center;
- notification categories/types;
- security notifications;
- event/listener dispatch;
- notification URL resolver;
- role-specific notifications.

Jangan membuat category berdasarkan asumsi jika current notification domain sudah memiliki naming sendiri.

---

## 14. Notification Preference Architecture

Gate 1 harus menentukan apakah settings lebih cocok di:

```text
user_preferences.notification_preferences JSON
```

atau:

```text
notification_preferences
```

Separate table lebih tepat jika:

- banyak category;
- banyak channel;
- future channels mungkin bertambah;
- perlu query/reporting;
- mandatory/configurable state cukup kompleks.

Example normalized concept:

```text
user_id
category
channel
enabled
```

Jangan menetapkan schema final sebelum notification inventory selesai.

---

## 15. Mandatory Notifications

Mandatory notification tidak boleh memiliki toggle disable.

Candidate yang perlu diverifikasi dari source:

- new device login;
- password/security change;
- account security alert;
- critical workflow notification.

Gate 1 harus menghasilkan matrix:

| Category | In-App | Email | User Configurable | Mandatory |
|---|---:|---:|---:|---:|
| Example | Yes | Yes | Yes/No | Yes/No |

Actual rows harus berasal dari current implementation.

---

## 16. Language Preference Decision

### Include in Phase 2 jika:

- `lang/` resources sudah digunakan secara luas;
- Blade memakai `__()` / `@lang` secara konsisten;
- validation localization tersedia;
- locale middleware/resolver sudah ada atau mudah ditambahkan;
- JavaScript UI strings tidak overwhelmingly hardcoded.

### Defer to Phase 2B jika:

- sebagian besar UI masih hardcoded;
- email/notification text belum localized;
- JS strings tidak memiliki translation layer;
- perubahan akan menyentuh sebagian besar repository.

Jangan membuat selector bahasa yang hanya menerjemahkan halaman Customization tetapi memberi kesan seluruh portal sudah localized.

---

## 17. Customization Page Phase 2 Layout

Recommended conceptual layout:

```text
Customization

Appearance
├── Theme
├── Accent Color
└── Interface Density

Navigation
├── Sidebar
└── Quick Access

Dashboard
├── Visible Widgets
└── Widget Order

Regional
├── Language (conditional)
├── Timezone
├── Date Format
├── Time Format
└── Number Format

Notifications
├── Categories
└── Channels
```

Jika halaman menjadi terlalu panjang, section navigation dapat dipertimbangkan menggunakan existing component/style system.

Jangan menambahkan tab/navigation pattern baru jika current design system sudah memiliki pattern yang cocok.

---

## 18. Backend Components — Expected

Potential new files:

```text
app/
├── Services/
│   ├── DashboardCustomizationService.php
│   ├── DashboardWidgetRegistry.php      (atau config-backed resolver)
│   ├── RegionalPreferenceService.php    (jika diperlukan)
│   └── NotificationPreferenceService.php (conditional)
│
├── Http/
│   ├── Requests/
│   │   └── UpdatePhase2PreferenceRequest.php
│   └── Controllers/
│       └── existing customization controller extension preferred
│
config/
├── dashboard_widgets.php
├── regional_preferences.php
├── accent_presets.php
└── notification_preferences.php (conditional)
```

Jangan membuat service hanya untuk mengikuti nama plan jika existing Phase 1 service dapat diperluas dengan lebih bersih.

---

## 19. Existing Files — Expected Modifications

Potentially:

```text
app/Models/UserPreference.php
app/Models/User.php
app/Services/UserPreferenceService.php
app/Http/Controllers/UserPreferenceController.php
app/Http/Requests/UpdateUserPreferenceRequest.php

config/user_preferences.php

resources/views/profile/customization.blade.php
resources/views/layouts/app.blade.php
resources/js/preferences.js
resources/css/app.css

role dashboard Blade files
notification dispatch classes/services (conditional)
localization middleware/resources (conditional)
```

Exact list ditentukan setelah Gate 1 source inspection.

---

## 20. Dashboard Rendering Strategy

Jangan langsung memindahkan semua dashboard menjadi widget system baru.

Recommended incremental strategy:

1. identify existing dashboard blocks;
2. assign stable widget keys;
3. wrap only configurable blocks;
4. keep mandatory/non-configurable blocks unchanged;
5. normalize layout server-side;
6. render according to trusted resolved order.

Tujuan Phase 2 bukan general dashboard framework rewrite.

---

## 21. Avoiding Unnecessary Queries

Dashboard customization tidak boleh menyebabkan:

```text
1 widget = 1 preference query
```

Preferred flow:

```text
Request
  ↓
Resolve preferences once
  ↓
Resolve widget layout once
  ↓
Load required dashboard data
  ↓
Render visible widgets
```

Jika hiding widget memungkinkan query mahal tidak dijalankan, optimization dapat dilakukan hanya jika current architecture membuatnya aman dan jelas.

Jangan melakukan speculative performance refactor.

---

## 22. Security Requirements

Phase 2 dianggap security-sensitive karena user-controlled preference memengaruhi rendering dan notification delivery.

Wajib diverifikasi:

- preference ownership selalu berasal dari authenticated user;
- tidak ada `user_id` client parameter untuk ownership;
- widget keys melalui trusted registry;
- hidden widget tidak mengubah business logic;
- widget visibility tidak memberi data access;
- role change invalidate unavailable widgets;
- supplier context filtering tetap aktif;
- arbitrary component/view/route injection tidak mungkin;
- accent/date/time/number/locale menggunakan allowlist;
- mandatory notification tidak bisa dimatikan;
- unsupported notification channel ditolak;
- safe frontend serialization;
- no unsafe mass assignment;
- CSRF tetap aktif.

---

## 23. Accessibility Requirements

Dashboard customization:

- reorder harus keyboard-operable;
- drag bukan satu-satunya mechanism;
- show/hide controls harus memiliki label;
- focus state jelas;
- order changes tidak menghilangkan focus;
- required widget state dapat dipahami.

Accent/theme:

- contrast tetap memenuhi requirement;
- focus ring tetap visible;
- semantic status color tidak rusak.

Regional/notifications:

- form grouping jelas;
- label explicit;
- validation message readable;
- toggle state screen-reader friendly.

---

## 24. TDD Strategy

Semua slice menggunakan:

```text
RED
→ GREEN
→ REFACTOR
```

Jangan menjalankan full regression suite setelah setiap perubahan kecil.

Gunakan focused test saat slice berjalan, kemudian broader regression sebelum Gate 2.

---

## 25. TDD Matrix — Dashboard

| Scenario | Initial RED | Expected GREEN |
|---|---|---|
| No saved layout | current default dashboard preserved | registry defaults resolve without insert/read side effect as appropriate |
| Valid hide/show | no persistence | selected configurable widget state persists |
| Valid reorder | default order only | saved order respected |
| Unknown key | accepted/breaks | normalized/rejected safely |
| Other-role widget | potentially submitted | rejected or removed |
| Required widget hidden | user can attempt hide | server preserves required widget |
| Role change | stale widget remains | inaccessible widget removed at render |
| Supplier context switch | stale context widget visible | filtered by current context |
| Reset layout | no reset | default order/visibility restored |
| User isolation | cross-user mutation risk | User A never changes User B |

---

## 26. TDD Matrix — Regional

| Scenario | Expected |
|---|---|
| Valid timezone | persists and applies to interactive display |
| Invalid timezone | rejected |
| Valid date format key | persists |
| Arbitrary PHP format | rejected |
| Valid time format | persists |
| Invalid time format | rejected |
| Valid number preset | persists |
| Invalid number preset | rejected |
| Stored timestamps/numbers | unchanged |
| Business exports/docs | unchanged unless explicitly supported |

---

## 27. TDD Matrix — Accent

| Scenario | Expected |
|---|---|
| Valid preset | persists |
| Unknown preset | rejected |
| Arbitrary HEX | rejected |
| Accent + Light | valid token combination |
| Accent + Dark | valid token combination |
| Error/warning/success | semantic colors remain unchanged |
| Reset | default accent restored |

---

## 28. TDD Matrix — Notifications

Conditional on Phase 2 inclusion.

| Scenario | Expected |
|---|---|
| Configurable category disabled | optional channel suppressed |
| Mandatory category disabled | request rejected/ignored; remains enabled |
| Unsupported channel | rejected |
| Current user ownership | only own preferences modified |
| Email disabled for optional category | in-app may remain according to config |
| Mandatory security alert | still delivered through required channel |
| Role-inapplicable category | not offered / rejected |
| Reset | configured defaults restored |

---

## 29. TDD Matrix — Language

Conditional on localization readiness.

| Scenario | Expected |
|---|---|
| Supported locale | persists |
| Unsupported locale | rejected |
| Authenticated request | uses saved locale |
| User switch | locale does not leak |
| Guest | application default locale |
| Validation text | follows selected locale where supported |

---

## 30. Implementation Sequence

### P2.1 — Gate 1 Repository Validation

Inspect actual Phase 1 implementation and Phase 2 target surfaces.

Deliver:

- architecture findings;
- scope confirmation;
- Phase 2A/2B decision;
- exact file plan;
- test plan.

No implementation before approval.

---

### P2.2 — Preference Schema Extension

Add only approved new preference fields.

Possible fields:

- accent;
- timezone;
- date_format;
- time_format;
- number_format;
- dashboard_preferences.

Acceptance:

- existing Phase 1 data remains valid;
- defaults work for existing users;
- rollback path documented;
- no unnecessary backfill.

---

### P2.3 — Dashboard Widget Registry

Create trusted registry/resolver based on real dashboards.

Acceptance:

- only valid role/context widgets returned;
- stable keys used;
- required/default metadata resolved;
- unknown keys normalized safely.

---

### P2.4 — Dashboard Persistence

Implement show/hide, order, reset.

Acceptance:

- current user only;
- required widgets preserved;
- stale widgets safe;
- role/context changes safe.

---

### P2.5 — Dashboard Customization UI

Add widget visibility/order controls.

Acceptance:

- keyboard reorder works;
- no drag-only interaction;
- current state displayed correctly;
- reset available.

---

### P2.6 — Regional Preference Config

Centralize allowlists and display presets.

Acceptance:

- no arbitrary formatter values accepted;
- defaults preserve current output.

---

### P2.7 — Regional Formatting Integration

Apply preferences only to approved interactive display surfaces/shared formatters.

Acceptance:

- underlying DB values unchanged;
- business documents unaffected unless explicitly allowed;
- no broad string replacement.

---

### P2.8 — Accent Presets

Extend semantic token layer with approved accents.

Acceptance:

- light/dark compatible;
- focus/interactive states readable;
- semantic status colors unchanged.

---

### P2.9 — Notification Inventory

If included in Phase 2:

- classify categories;
- classify channels;
- identify mandatory alerts;
- design preference persistence.

This inventory must happen before notification preference coding.

---

### P2.10 — Notification Preferences

Conditional.

Acceptance:

- optional notifications respect user preference;
- mandatory alerts cannot be disabled;
- unsupported channels rejected;
- existing notification center remains functional.

---

### P2.11 — Language Preference

Conditional.

Only implement if Gate 1 declares localization foundation sufficient.

Otherwise create Phase 2B implementation plan and do not partially localize the portal.

---

### P2.12 — Integration and Regression

Run:

- Phase 1 preference regression;
- dashboard role/context tests;
- auth/profile/security tests;
- notification tests where applicable;
- frontend build;
- accessibility checks;
- design-system token checks.

---

## 31. Performance Verification

Before Gate 2, verify:

- preference query count;
- dashboard data query behavior;
- no per-widget preference queries;
- no N+1 introduced by registry/layout;
- notification preference lookup strategy is bounded;
- frontend payload remains small.

Do not claim performance improvement without measurements.

---

## 32. Backward Compatibility

Existing Phase 1 settings must remain intact:

- theme;
- density;
- sidebar state;
- page size;
- Quick Access.

Users without Phase 2 values receive defaults matching current application behavior.

Phase 2 migration must not reset Phase 1 preferences.

---

## 33. Out of Scope

Phase 2 does not include:

- arbitrary user-created widgets;
- arbitrary Blade/component path;
- dashboard query builder;
- workflow automation;
- permission editor;
- user-managed role access;
- arbitrary CSS;
- custom fonts;
- wallpaper;
- arbitrary color picker;
- external notification providers not already supported;
- full application redesign;
- full localization if current repository is not localization-ready.

---

## 34. ECC Execution Strategy

Recommended ECC references:

```md
[$orch-add-feature](C:\laragon\www\adasi_portal_supplier\\.agents\skills\orch-add-feature\SKILL.md)
[$laravel-patterns](C:\laragon\www\adasi_portal_supplier\\.agents\skills\laravel-patterns\SKILL.md)
[$laravel-tdd](C:\laragon\www\adasi_portal_supplier\\.agents\skills\laravel-tdd\SKILL.md)
[$laravel-security](C:\laragon\www\adasi_portal_supplier\\.agents\skills\laravel-security\SKILL.md)
[$accessibility](C:\laragon\www\adasi_portal_supplier\\.agents\skills\accessibility\SKILL.md)
[$design-system](C:\laragon\www\adasi_portal_supplier\\.agents\skills\design-system\SKILL.md)
```

Use ECC progressively:

```text
orch-add-feature
      │
      ├── backend architecture → laravel-patterns
      ├── tests → laravel-tdd
      ├── security-sensitive paths → laravel-security
      ├── UI/keyboard/contrast → accessibility
      └── accent/tokens/dashboard consistency → design-system
```

Do not reload the full ECC catalog or unrelated skills.

---

## 35. Gate 1 Deliverable Requirements

Before implementation, Gate 1 Phase 2 must produce:

1. ECC size classification.
2. Actual Phase 1 baseline.
3. Dashboard findings by role/context.
4. Formatting/localization findings.
5. Notification findings.
6. Design-token/accent findings.
7. Gap analysis.
8. Phase 2A/2B decision.
9. Architecture decisions.
10. Vertical task list.
11. Exact expected files.
12. TDD matrix.
13. Security risk matrix.
14. Accessibility risk matrix.
15. Migration/rollback notes.
16. Performance verification plan.
17. Final Gate 1 recommendation.

Then STOP and wait for approval.

---

## 36. Gate 2 Deliverable Requirements

After approved implementation and verification, Gate 2 must show:

### Implemented

Summary of completed Phase 2 capabilities.

### Phase 2 Changed Files

Only actual Phase 2 files/hunks.

### Pre-existing Dirty Overlap

Identify existing dirty files and exact Phase 2 additions.

### Tests Executed

Exact commands, with:

- PASS;
- FAIL;
- SKIPPED + reason.

### Code Review

Findings and remediation.

### Security Review

Findings and remediation.

### Accessibility Review

Findings and remediation.

### Design-System Review

Findings and remediation.

### Migration

Schema impact, test evidence, rollback behavior.

### Known Limitations

Only genuine remaining limitations.

### Proposed Commit

Conventional commit message(s).

No commit before explicit Gate 2 approval.

---

## 37. Definition of Done — Phase 2A

Phase 2A is complete when:

```text
✓ Dashboard widgets are resolved from a trusted registry
✓ User can show/hide configurable widgets
✓ Required widgets cannot be hidden
✓ User can reorder widgets using keyboard-accessible controls
✓ Dashboard layout persists per user
✓ Role/context changes safely normalize layouts
✓ Dashboard reset restores defaults

✓ Timezone preference uses a trusted allowlist
✓ Date format uses trusted presets
✓ Time format uses trusted presets
✓ Number format uses trusted presets
✓ Stored business values remain unchanged
✓ Business/legal documents are not unintentionally reformatted

✓ Accent uses approved predefined tokens only
✓ Arbitrary colors are rejected
✓ Accent works in Light/Dark modes
✓ Semantic status colors remain intact

✓ Existing Phase 1 preferences remain unchanged
✓ Authorization is not weakened
✓ Relevant tests pass
✓ No unresolved CRITICAL/HIGH review findings remain
```

---

## 38. Definition of Done — Phase 2B

If approved separately:

```text
✓ Notification inventory is complete
✓ Optional categories respect user preferences
✓ Mandatory alerts cannot be disabled
✓ Unsupported channels are rejected
✓ Existing notification center remains functional

✓ Language preference is only exposed if portal localization is sufficiently complete
✓ Unsupported locale is rejected
✓ Locale remains account scoped
✓ No cross-account locale leakage
✓ No misleading partial-translation experience is introduced
```

---

## 39. Recommended Development Principle

Phase 2 should extend the existing preference architecture rather than create a generic “settings framework”.

Preferred direction:

```text
Existing Phase 1 Preferences
          ↓
Trusted Registries + Allowlists
          ↓
Role/Context Normalization
          ↓
User-specific Presentation
```

Avoid:

```text
Client controls route/view/query
Client controls authorization
New preference subsystem per feature
Full dashboard rewrite
Full localization rewrite
```

The target is advanced personalization with controlled blast radius, strong authorization boundaries, and backward compatibility with Phase 1.

---

## 40. Final Recommendation

Start Phase 2 with a new Gate 1 research pass focused only on:

- actual Phase 1 implementation;
- dashboard composition;
- shared formatters;
- design token architecture;
- notification system;
- localization maturity.

Expected preferred implementation order:

```text
Gate 1
  ↓
Phase 2A
  ├── Dashboard Customization
  ├── Regional Preferences
  └── Accent Color
  ↓
Gate 2A

Phase 2B (only if justified)
  ├── Notification Preferences
  └── Language Preference
```

This split prevents Dashboard/Regional customization from being blocked by potentially large notification or localization refactors while keeping the final user-preference architecture cohesive.
