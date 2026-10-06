# Phase 3 — Notifications

## Status

IMPLEMENTATION PLAN

Phase 3 membuat **Notifications sebagai menu/account area tersendiri**, terpisah dari menu Customization.

Target akhir User Dropdown:

User Dropdown
├── My Profile
├── Security
├── Notifications
├── Customization
└── Logout

Phase 3 menggunakan workflow cepat dan flat:

Gate 1
→ Implementation
→ Gate 2
→ COMPLETE

Tidak ada:

- Phase 3A
- Phase 3B
- Phase 3C
- subphase tambahan

Jika ditemukan edge case kecil atau bagian notification yang terlalu berisiko, tandai sebagai explicit technical debt / deferred dan jangan membuat subphase baru.

---

# 1. Final Navigation Objective

User Dropdown harus memiliki urutan final:

1. My Profile
2. Security
3. Notifications
4. Customization
5. Logout

`Notifications` dan `Customization` adalah dua menu yang berbeda.

Target responsibility:

My Profile
→ informasi dan pengaturan profil user

Security
→ password, session, dan security account

Notifications
→ notification preferences dan notification-related user controls

Customization
→ appearance, theme, accent, density, sidebar, page size, Quick Access, Dashboard Layout, dan Regional Preferences

Logout
→ logout account

Notification Preferences tidak boleh ditempatkan lagi sebagai card di halaman Customization.

---

# 2. Phase 3 Goal

Membuat area:

**Notifications**

yang dapat diakses langsung melalui User Dropdown.

Halaman ini memungkinkan user mengatur notification opsional yang benar-benar sudah tersedia di aplikasi tanpa memengaruhi notification wajib.

Core rule:

> Existing notification behavior must remain unchanged unless a registered optional notification channel is explicitly disabled by the user.

User yang belum pernah menyimpan Notification Preferences harus mendapatkan notification yang sama seperti sebelum Phase 3.

---

# 3. Proposed Route

Preferred route:

GET /profile/notifications

Named route:

profile.notifications

atau mengikuti naming convention repository yang ditemukan Gate 1.

Jika repository sudah memiliki route Notification yang sesuai:

reuse route existing.

Jangan membuat route duplikat.

Jika existing notification center menggunakan route lain, Gate 1 harus menentukan apakah:

A. route existing dapat dijadikan target menu Notifications

atau

B. diperlukan dedicated account Notifications page.

Exact route harus diputuskan berdasarkan source repository.

---

# 4. Proposed Page

Preferred view:

resources/views/profile/notifications.blade.php

Page title:

Notifications

Page ini menjadi account-level notification settings page.

Notification Preferences tidak lagi berada di:

resources/views/profile/customization.blade.php

Customization harus tetap fokus ke UI/personalization.

---

# 5. Notifications Page Scope

Phase 3 Notifications page sebaiknya berisi:

## Notification Preferences

User dapat memilih notification opsional berdasarkan channel yang benar-benar didukung.

Contoh konseptual:

Notifications

Invoice Notifications

In-App    [ON]
Email     [ON]

Purchase Order Updates

In-App    [ON]
Email     [OFF]

Actual categories dan labels wajib berasal dari repository notification inventory.

Jangan mengarang event yang tidak tersedia.

---

# 6. Optional Existing Notification History

Gate 1 harus mencari apakah repository sudah mempunyai:

- database notification history
- notification center
- unread notifications page
- navbar notification dropdown
- mark-as-read workflow
- notification detail links

Jika sudah ada page/history yang matang:

Notifications page dapat mengintegrasikan atau memberikan akses ke existing notification history secara minimal.

Tetapi Phase 3 tidak boleh membuat notification center baru dari nol.

Default Phase 3 scope tetap:

**Notification Preferences + dedicated Notifications menu/page.**

Notification history hanya ikut jika existing implementation dapat direuse tanpa memperbesar scope secara signifikan.

---

# 7. Explicit Boundary from Customization

Phase 3 harus menjaga separation of concerns.

Customization tetap berisi:

- Theme
- Accent
- Density
- Sidebar
- Page Size
- Quick Access
- Dashboard Layout
- Regional Preferences

Notifications page berisi:

- Notification Preferences
- existing notification user controls yang relevan jika sudah tersedia

Do not add Notification Preferences card into Customization.

Jika sebelumnya ada prototype/dirty implementation notification preferences pada Customization:

jangan otomatis mengadopsinya.

Gate 1 harus membedakan existing source dengan proposed Phase 3 work.

---

# 8. User Dropdown

Gate 1 harus menemukan exact Blade/component yang merender authenticated User Dropdown.

Update final order menjadi:

My Profile

Security

Notifications

Customization

Logout

Requirements:

- use named routes
- do not hard-code URLs
- preserve current icons/style
- preserve authorization
- preserve keyboard navigation
- preserve dropdown semantics
- preserve logout POST behavior
- do not alter unrelated navbar notification badge behavior

Notifications menu item harus menuju Notifications page, bukan Customization.

---

# 9. Navigation Separation

Notifications dan notification badge memiliki fungsi berbeda.

User Dropdown Notifications:

→ membuka notification settings/account page

Navbar bell/badge jika existing:

→ tetap menjadi notification activity / unread notification interface

Do not merge these two concepts unless repository architecture already treats them as one route.

Do not redesign notification badge polling.

---

# 10. Notification Infrastructure Discovery

Sebelum implementasi, Gate 1 wajib melakukan inventory source aktual.

Audit:

app/Notifications

notification-related Jobs

notification Events

listeners

mailables

notify() calls

Notification::send()

Notification::route()

via() methods

database notifications

mail notifications

notification badge endpoints

mark-as-read endpoints

notification routes

notification tests

provider/event registration

Gate 1 harus menemukan notification classes aktual.

Jangan membuat event berdasarkan asumsi.

---

# 11. Notification Classification

Setiap notification existing diklasifikasikan menjadi:

## A. Configurable

Notification opsional yang aman dikontrol user.

## B. Mandatory / Non-Configurable

Notification yang tidak boleh dimatikan karena berkaitan dengan:

- account security
- authentication
- required approval workflow
- compliance
- financial control
- required procurement workflow
- critical operational communication

## C. Existing but Out of Scope

Notification yang memiliki:

- unsupported channel
- ambiguous semantics
- experimental implementation
- dirty unrelated implementation
- excessive architectural coupling

Hanya kategori Configurable yang ditampilkan sebagai editable preference.

---

# 12. Safe-by-Default Rule

Notification yang tidak terdaftar dalam Phase 3 trusted registry:

→ preserve existing behavior.

Notification yang mandatory:

→ always allow.

Notification yang configurable:

→ evaluate preference.

Runtime principle:

unregistered notification
→ legacy behavior

mandatory notification
→ legacy behavior

registered optional notification
→ preference-aware behavior

Unknown notification tidak boleh otomatis diblokir.

---

# 13. Trusted Notification Registry

Create:

config/notification_preferences.php

Registry menjadi authority tunggal untuk notification preference.

Expected metadata:

stable key

notification class

label

description

category

eligible roles

eligible supplier contexts

supported channels

default state

configurable state

Conceptual structure:

'stable.event.key' => [
    'notification' => ExistingNotification::class,
    'label' => '...',
    'description' => '...',
    'category' => '...',
    'roles' => [...],
    'contexts' => [...],
    'channels' => [
        'database' => [
            'default' => true,
            'configurable' => true,
        ],
        'mail' => [
            'default' => true,
            'configurable' => true,
        ],
    ],
]

Actual keys/classes wajib berasal dari Gate 1 source research.

---

# 14. Stable Notification Contract

Create a bounded contract such as:

app/Contracts/UserConfigurableNotification.php

Conceptual method:

public function preferenceKey(): string;

Only explicitly configurable notifications implement this interface.

Mandatory notification classes do not implement it.

This creates an opt-in security boundary.

A newly created notification cannot accidentally become suppressible unless a developer explicitly adds it to:

- contract
- registry

---

# 15. Storage

Preferred storage remains:

user_preferences

Add nullable JSON column:

notification_preferences

only if Gate 1 proves no suitable storage already exists.

Expected:

notification_preferences JSON NULL

No:

- new notification preferences table
- pivot table
- notification preference model
- notification revision column

Reuse existing UserPreference revision lifecycle.

---

# 16. Override-Only Storage

Store only user overrides.

Do not persist complete registry snapshots.

Example:

Default:

database = true
mail = true

User disables email only:

{
    "some.notification.key": {
        "mail": false
    }
}

Advantages:

- new events automatically use defaults
- small JSON payload
- registry remains authoritative
- stale users do not require backfill

Do not persist:

- label
- category
- role
- class name
- description

---

# 17. UserPreference Model

If column is required:

add cast:

notification_preferences => array

Preserve:

theme

accent

density

sidebar_state

page_size

quick_access

dashboard_preferences

timezone

date_format

time_format

number_format

revision

sidebar_revision

Do not perform broad UserPreference refactor.

---

# 18. NotificationPreferenceService

Create:

app/Services/NotificationPreferenceService.php

Responsibilities:

registry()

eventsFor(User $user)

normalize(User $user, array $submitted)

effectivePreferences(User $user)

enabled(
    User $user,
    string $eventKey,
    string $channel
): bool

Do not send notification from this service.

Do not handle mail rendering.

Do not handle notification badge.

This service only resolves preference policy.

---

# 19. Existing Preference Cache

Reuse:

UserPreferenceService

Notification filtering must not query the database separately for every channel.

Target:

one resolved preference payload per request/notifiable lifecycle where possible.

Avoid:

mail channel query
+
database channel query
+
additional query per notification

No Redis.

No new caching subsystem.

---

# 20. Runtime Enforcement

Preferred architecture:

Notification generated
→ Laravel NotificationSending event
→ ApplyNotificationPreferences listener
→ determine whether notification is configurable
→ resolve stable key
→ evaluate current channel
→ NotificationPreferenceService
→ allow / suppress channel

Create:

app/Listeners/ApplyNotificationPreferences.php

if repository event architecture supports it cleanly.

Gate 1 must verify actual Laravel registration architecture first.

---

# 21. Listener Behavior

Expected logic:

notification does not implement configurable contract
→ allow

notification key not registered
→ allow

channel not registered
→ preserve legacy behavior

channel mandatory
→ allow

recipient not eligible for event
→ preserve safe existing behavior

channel explicitly disabled
→ suppress this channel only

otherwise
→ allow

Never suppress unknown notifications globally.

---

# 22. Channel Independence

If notification supports:

database
mail

and both are configurable, allow independent state.

Example:

database = ON
mail = OFF

Expected:

database stored
mail not sent

If only one channel is configurable:

only that channel gets a user control.

Do not expose channel toggles that the actual notification class does not support.

---

# 23. Notifications Controller

Preferred dedicated controller:

app/Http/Controllers/UserNotificationPreferenceController.php

or repository-consistent equivalent discovered Gate 1.

Responsibility should remain small:

index/show preferences

update preferences

possibly reset preferences

Alternative:

reuse UserPreferenceController only if repository architecture clearly favors one account preference controller and doing so remains clean.

Gate 1 must recommend one exact approach.

Because Notifications is now a separate menu/page, a dedicated controller is acceptable if it makes route responsibility clearer.

Do not duplicate persistence logic.

Use existing UserPreferenceService / NotificationPreferenceService.

---

# 24. Notifications Request Validation

Preferred request class:

app/Http/Requests/UpdateNotificationPreferenceRequest.php

or reuse UpdateUserPreferenceRequest if repository convention strongly prefers it.

Validation must ensure:

- registered event key
- registered channel
- boolean value
- role eligibility
- supplier context eligibility
- configurable channel only

Reject:

- unknown event
- unknown channel
- arbitrary class
- arbitrary email address
- arbitrary route
- unsupported communication channel

---

# 25. Notifications Page UI

Create dedicated Notifications page following existing profile/account UI style.

Suggested layout:

# Notifications

Manage how you receive optional notifications.

## Purchase Orders

PO Status Updated

In-App
[checkbox]

Email
[checkbox]

## Invoices

Invoice Status Updated

In-App
[checkbox]

Email
[checkbox]

Actual groups depend entirely on Gate 1 inventory.

Use:

- semantic headings
- fieldsets where appropriate
- visible labels
- existing UI components
- server-rendered current state

No JavaScript rule builder required.

---

# 26. Mandatory Notification Presentation

Preferred:

do not show mandatory notification controls unless useful context requires them.

If mandatory notification is shown:

- clearly label as required
- control disabled/non-editable
- explain that it is required for security/operation

Do not allow users to submit a disabled state for mandatory channels.

---

# 27. Save Behavior

Saving Notifications settings must modify only:

notification_preferences

Must preserve:

theme

accent

density

sidebar

page size

Quick Access

dashboard preferences

Regional preferences

other account/profile data

Use existing transactional preference persistence and revision logic where possible.

---

# 28. Notification Reset

Notifications page may provide:

Reset Notification Preferences

only if existing scoped reset architecture makes this safe and simple.

Expected effect:

notification_preferences = null / []

Registry defaults then apply.

This reset must NOT modify:

Appearance

Dashboard

Regional

Quick Access

Sidebar

Page Size

If scoped reset would meaningfully increase implementation complexity:

defer it.

It is not mandatory for Phase 3.

---

# 29. Global Customization Reset Boundary

Existing Customization reset behavior must be reviewed.

Because Notifications is now a separate feature/page:

Gate 1 must decide whether the existing global Customization reset should reset notification preferences.

Preferred separation:

Customization global reset
→ resets Customization preferences only

Notifications reset
→ resets Notification preferences only

Do not silently couple the two systems merely because both use user_preferences storage.

This behavior must be explicitly decided during Gate 1.

---

# 30. User Dropdown Implementation

Exact User Dropdown target:

My Profile
Security
Notifications
Customization
Logout

Requirements:

- preserve My Profile route
- preserve Security route
- add Notifications named route
- preserve Customization route
- preserve Logout action
- maintain current divider rules if applicable
- maintain current icons/style
- maintain aria/menu semantics

Do not add duplicate Notifications entry if another entry already exists.

Gate 1 must identify exact dropdown Blade/component file.

---

# 31. Notification Badge Boundary

Do not modify unrelated:

notification polling

badge timing

visibility handlers

notification dropdown refresh

unread badge animation

notification mark-as-read UX

unless exact Phase 3 integration requires an existing route link.

Notifications preference enforcement occurs when notification is sent/stored.

It does not require polling redesign.

---

# 32. Role Awareness

Notifications registry may restrict events by role.

Examples based only on actual source:

Admin

Purchasing

Finance

Accounting

QC

GA

Supplier

Do not create role variants without real notification events.

UI renders only eligible registry entries.

Server validates eligibility independently from UI.

---

# 33. Supplier Context Awareness

If actual notifications differ between:

Supplier Import

Supplier Local

use existing PortalContext.

Dual-scope supplier may configure event preferences only for eligible active context where appropriate.

If notification semantics are account-wide rather than context-specific:

do not artificially split them.

Gate 1 decides per actual class/event.

---

# 34. Migration

If needed:

database/migrations/..._add_notification_preferences_to_user_preferences_table.php

Migration characteristics:

- additive
- nullable JSON
- no data migration
- no table rewrite
- no extra index unless justified
- rollback drops only notification_preferences

Do not run production migration during Gate 1.

---

# 35. Exact Notification Class Changes

Gate 1 must identify exact existing Notification classes selected as configurable.

For each approved class:

- implement UserConfigurableNotification
- return stable preference key
- preserve recipient
- preserve message content
- preserve subject
- preserve action URL
- preserve queue behavior
- preserve channel list unless framework mechanics require otherwise

Do not rewrite Notification classes.

---

# 36. Notification Trigger Protection

Do not modify business workflows merely to support preferences.

Existing calls such as:

$user->notify(...)

Notification::send(...)

should ideally remain untouched.

Filtering should occur centrally.

This reduces regression risk across:

PO

Invoice

Claims

Finance

Supplier

other operational modules

---

# 37. Default Compatibility

Most important invariant:

notification_preferences = null

must produce exactly the same notification delivery behavior as before Phase 3.

Characterization tests must prove this.

No opt-in migration.

No default suppression.

No silent channel removal.

---

# 38. TDD — Dropdown

Test final user dropdown contains:

My Profile

Security

Notifications

Customization

Logout

Verify order.

Verify Notifications uses correct named route.

Verify Logout semantics remain unchanged.

---

# 39. TDD — Notifications Page

Verify authenticated user can open Notifications page.

Verify guest cannot access it.

Verify eligible notification settings render.

Verify ineligible registry entries do not render.

Verify mandatory notification cannot be disabled.

---

# 40. TDD — Default Compatibility

For representative configurable notification:

user has no notification preference override.

Expected:

all existing legacy channels still execute.

For mandatory notification:

behavior unchanged.

For unregistered notification:

behavior unchanged.

---

# 41. TDD — Channel Independence

For actual multi-channel notification:

database = true
mail = false

Expected:

database delivered
mail suppressed

If actual architecture supports inverse combination:

database = false
mail = true

verify independently.

Do not test unsupported combinations.

---

# 42. TDD — Validation

Unknown event key:

reject.

Unknown channel:

reject.

Cross-role event:

reject.

Cross-context event:

reject where applicable.

Mandatory channel false:

reject or normalize to enabled.

Do not persist arbitrary structures.

---

# 43. TDD — Persistence

Verify:

preference saves

page reload reflects state

registry defaults fill omitted values

new registry keys default correctly

stale stored keys ignored safely

other account settings remain untouched

---

# 44. TDD — Cross-System Protection

Prepare a user with customized:

Theme

Accent

Density

Sidebar

Page Size

Quick Access

Dashboard

Regional Preferences

Then save Notifications.

Assert every existing Customization preference remains unchanged.

Notifications is separate in UI but may share underlying UserPreference storage safely.

---

# 45. TDD — Performance

Verify preference resolution does not introduce channel-level query N+1.

For representative notification delivery:

database + mail

should not result in independent user_preferences queries for each channel if request-local reuse is possible.

Measure actual behavior.

Do not make unsupported performance claims.

---

# 46. Security Requirements

Verify:

authenticated user only

CSRF

ownership

trusted registry

bounded channels

mandatory notification protection

no arbitrary PHP class resolution

no arbitrary recipient override

no arbitrary route input

no stored XSS

role/context isolation

no cross-user preference mutation

no mass-assignment regression

Resolve Critical/High findings before Gate 2.

---

# 47. Accessibility Requirements

Notifications page must support:

keyboard navigation

visible focus

proper form labels

fieldset/group relationships

touch-friendly controls

screen-reader understandable channel names

disabled mandatory controls explained textually if shown

No custom JavaScript switch is required.

Native checkbox controls are preferred unless repository UI components already provide accessible toggles.

---

# 48. Performance Requirements

No:

external package

polling system

WebSocket

new caching infrastructure

notification scheduler

additional request merely to load preferences after page render

Preference page should be rendered server-side using existing user preference context.

---

# 49. Dirty Working Tree Protection

The branch may still contain unrelated local dirty work after Phase 2 Customization closure.

Before modifying each file:

inspect its diff.

Separate:

Phase 3 work

BusinessTime / TD-REG-04

notification polling

other domain work

unrelated local changes

Do not stage.

Do not commit.

Do not push during Gate 1 or implementation until Gate 2 is reviewed.

Do not reset/clean/stash unrelated work.

---

# 50. Initial Expected Production Files

Candidate files:

config/notification_preferences.php

app/Contracts/UserConfigurableNotification.php

app/Services/NotificationPreferenceService.php

app/Listeners/ApplyNotificationPreferences.php

app/Models/UserPreference.php

app/Http/Controllers/UserNotificationPreferenceController.php
or existing repository-consistent controller

app/Http/Requests/UpdateNotificationPreferenceRequest.php
or repository-consistent request

resources/views/profile/notifications.blade.php

User Dropdown Blade/component file

routes/web.php
only if dedicated route does not exist

existing event/listener registration location

additive migration if storage field is required

exact existing Notification classes selected as configurable

Gate 1 must replace this candidate list with exact files.

---

# 51. Explicit Phase 3 Exclusions

Do not implement:

Notification Center redesign

notification bell redesign

polling refactor

WebSocket

Pusher

broadcast notifications

mobile push

SMS

WhatsApp

Telegram

digest

daily summary

weekly summary

quiet hours

notification schedule

frequency controls

email template redesign

Language / Localization

BusinessTime

Phase 2 Regional technical debt

admin managing another user's notification preferences

If encountered:

document as deferred.

Do not create Phase 3A / 3B / 3C.

---

# 52. Gate 1 Required Questions

Gate 1 must answer:

1. Where is the current User Dropdown rendered?
2. What are its current menu items and route names?
3. Is a Notifications dropdown item already present?
4. Is there already a Notifications page or route?
5. What notification infrastructure exists?
6. Which Notification classes exist?
7. Which channels does each class use?
8. Which classes are safe to make configurable?
9. Which must remain mandatory?
10. Which are out of scope?
11. What stable preference keys will be used?
12. Which roles/contexts are eligible?
13. Is notification_preferences JSON required?
14. Which controller architecture fits existing repository patterns?
15. Where should NotificationSending listener be registered?
16. What exact files will change?
17. Which dirty files overlap?
18. What exact tests are required?
19. What query impact is expected?
20. Should Notifications have its own scoped reset?

---

# 53. Gate 1 Required Output

Return:

# Gate 1 — Phase 3 Notifications

Status: PLAN ONLY; waiting for approval.

## 1. Repository Baseline

## 2. Current User Dropdown

Show exact current structure.

## 3. Proposed User Dropdown

Expected:

My Profile
Security
Notifications
Customization
Logout

## 4. Existing Notifications Route / Page

## 5. Existing Notification Architecture

## 6. Notification Inventory

Use:

| Notification Class | Trigger | Channels | Audience | Configurable? | Reason |
|---|---|---|---|---|---|

## 7. Mandatory Notification Inventory

## 8. Configurable Notification Inventory

## 9. Proposed Notification Registry

Exact stable keys.

## 10. Role / Context Eligibility

## 11. Notifications Page Architecture

## 12. Runtime Enforcement Architecture

Confirm NotificationSending viability.

## 13. Storage Decision

## 14. Exact Production Files

No approximate list.

## 15. Exact Notification Classes to Modify

## 16. Exact Route Changes

## 17. Exact Dropdown File

## 18. Event / Listener Registration

## 19. Validation / Normalization

## 20. Reset Decision

## 21. Dirty-Tree Overlap

## 22. Security Findings

## 23. Accessibility Findings

## 24. Performance Findings

## 25. Expected Tests

New
Modified
Reused

## 26. TDD Matrix

## 27. Explicit Deferred Items

## 28. Risk Classification

## 29. Phase 3 Definition of Done

## 30. Gate 1 Recommendation

Recommend one exact implementation scope.

Do NOT implement.

Do NOT stage.

Do NOT commit.

Do NOT push.

At the end write exactly:

STOP: waiting for `Approve Gate 1 — Phase 3`.

---

# 54. Phase 3 Definition of Done

Phase 3 COMPLETE requires:

- User Dropdown final order is:
  - My Profile
  - Security
  - Notifications
  - Customization
  - Logout
- Notifications has its own page
- Notifications is separate from Customization
- only actual existing notification classes are represented
- only explicitly optional notifications are configurable
- mandatory notifications remain enabled
- users with no preference override retain legacy behavior
- supported channels can be controlled independently where safe
- unknown event keys are rejected
- unknown channels are rejected
- arbitrary notification classes cannot be submitted
- role/context isolation works
- Notification save preserves all Customization preferences
- no notification query N+1 introduced
- no polling redesign absorbed
- no BusinessTime work absorbed
- no external dependency added
- focused tests pass
- notification regression tests pass
- account/navigation tests pass
- tracked project regression passes
- no unresolved Critical/High security findings

Final state:

PHASE 3 COMPLETE

No Phase 3A.

No Phase 3B.

No Phase 3C.

---

# 55. Workflow

Current:

Gate 1 — inspect actual dropdown, routes, notification infrastructure, and exact configurable notifications.

After approval:

Implementation
→ TDD
→ Code Review
→ Security Review
→ Accessibility Review
→ Verification
→ Gate 2

After Gate 2:

PHASE 3 COMPLETE

Do not create another Phase 3 subdivision.