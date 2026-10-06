# Phase 4 — Dedicated Security Page & Delete Account Cleanup

## Status

**IMPLEMENTATION PLAN**

Phase 4 menyempurnakan area account/settings dengan memisahkan **Security** dari halaman **My Profile** menjadi halaman dedicated, sekaligus **menghapus fitur Delete Account sepenuhnya** beserta seluruh kode, route, request, view, test, dan referensi yang sudah tidak diperlukan.

Phase 4 tetap dibuat **flat dan cepat**:

**Gate 1 → Implementation → TDD → Review → Verification → Gate 2 → Selective Commit & Push → COMPLETE**

Tidak ada:

- Phase 4A
- Phase 4B
- Phase 4C
- subphase tambahan

Jika ditemukan fitur security lain yang belum matang atau terlalu besar, tandai sebagai **explicit deferred item / technical debt**, jangan memperluas Phase 4.

---

# 1. Final Account Navigation Objective

Target final User Dropdown tetap:

User Dropdown
├── My Profile
├── Security
├── Notifications
├── Customization
└── Logout

Target destination:

- **My Profile** → `route('profile.edit')`
- **Security** → dedicated Security page, expected candidate `route('profile.security')`
- **Notifications** → `route('profile.notifications')`
- **Customization** → `route('profile.customization')`
- **Logout** → existing POST `route('logout')`

Setelah Phase 4 selesai:

- Security **tidak lagi** mengarah ke `profile.edit#profile-security-title`.
- My Profile hanya berisi profile/account identity yang memang relevan.
- Security memiliki dedicated page.
- Delete Account **tidak tersedia lagi di UI maupun backend**.

---

# 2. Phase 4 Goals

Phase 4 memiliki dua tujuan utama.

## 2.1 Dedicated Security Page

Memindahkan existing user-facing security controls dari My Profile ke halaman Security tersendiri tanpa menduplikasi business logic.

Prinsip:

> UI boleh dipindahkan, tetapi security behavior yang sudah benar harus dipertahankan.

## 2.2 Delete Account Removal

Menghapus fitur **Delete Account** sepenuhnya dari repository application scope.

Removal harus mencakup semua artifact yang khusus mendukung fitur tersebut, selama artifact tersebut tidak dipakai untuk fungsi lain.

Target akhir:

> User tidak memiliki route, button, form, controller action, request, component, test, atau internal reference yang dapat menghapus account melalui fitur profile lama.

---

# 3. Core Engineering Principles

Gunakan prinsip:

**Evidence → Correctness → Verification → Simplicity → Maintainability**

serta:

**Understand first → Change minimally → Verify explicitly**

Additional constraints:

- Jangan membuat fitur security baru tanpa bukti existing.
- Jangan menyalin business logic jika bisa reuse.
- Jangan mengubah auth architecture tanpa kebutuhan.
- Jangan menghapus shared code yang masih dipakai fitur lain.
- Delete Account cleanup harus surgical dan evidence-based.
- Jangan menyerap BusinessTime, Regional debt, polling, atau unrelated dirty work.

---

# 4. Explicit Non-Goals

Phase 4 tidak menambahkan:

- Two-Factor Authentication
- MFA
- OTP setup
- Authenticator App
- SMS verification
- WhatsApp verification
- Passkeys
- WebAuthn
- Security Questions
- IP allowlist
- trusted-location engine
- geolocation security
- new CAPTCHA architecture
- login risk scoring
- device fingerprinting engine
- session-management framework baru
- login-history framework baru
- authentication redesign
- password-policy redesign
- account recovery redesign
- admin impersonation
- account deletion replacement workflow
- deactivate account workflow
- soft-delete user workflow
- data anonymization workflow

Delete Account **dihapus**, bukan diganti dengan feature baru.

---

# 5. Repository Baseline Expectation

Phase 4 harus dimulai dari branch:

`customization-update`

Expected committed HEAD sebelum Phase 4:

`b82b9b3daae944c5e2396d89a195f4e1b0b4d949`

Commit:

`feat: add notification preferences and account navigation`

Working tree kemungkinan masih intentionally dirty.

Gate 1 wajib mencatat:

- branch
- HEAD
- upstream
- tracked modified count
- untracked count
- staged count

Do not:

- reset
- clean
- stash
- restore unrelated work
- discard
- rebase
- merge
- switch branch

---

# 6. Gate 1 — Mandatory Discovery Scope

Gate 1 harus melakukan source audit terhadap:

- User Dropdown
- Profile page
- Security section
- password form
- password routes
- password controllers
- password request validation
- password confirmation logic
- login/session infrastructure
- known-device infrastructure
- auth security events
- security notifications
- Delete Account UI
- Delete Account routes
- Delete Account controller/action
- Delete Account request
- Delete Account tests
- Delete Account translations/labels
- Delete Account JavaScript/modal behavior jika ada
- database dependencies related only to account deletion
- internal links to current Security fragment

Gate 1 bersifat **PLAN ONLY**.

Tidak boleh implementasi saat Gate 1.

---

# 7. Current Profile Architecture Audit

Gate 1 harus menentukan exact contents halaman:

`profile.edit`

Audit setiap section secara terpisah.

Classify:

## A. My Profile

Content yang tetap berada di My Profile.

Contoh hanya jika actual source mendukung:

- user name
- email/account identity
- profile information

## B. Security

Content yang dipindah ke dedicated Security page.

Contoh hanya jika ditemukan:

- change password
- sign-in security
- existing known-device info
- session info yang sudah matang

## C. Remove

Content Delete Account.

## D. Keep Elsewhere

Backend security infrastructure yang bukan user-facing setting.

Contoh:

- lockout monitoring
- auth event logging
- admin alerts
- security telemetry

---

# 8. Dedicated Security Page

Preferred page candidate:

`resources/views/profile/security.blade.php`

Final route candidate:

`GET /profile/security`

Named route candidate:

`profile.security`

Exact route/controller harus mengikuti convention repository yang ditemukan Gate 1.

Page title:

**Security**

Suggested introduction:

**Manage your password and existing account security settings.**

Jangan membuat placeholder section kosong.

---

# 9. Security Page Content Boundary

Security page hanya boleh memuat functionality yang sudah existing dan user-facing.

Expected minimum jika source mengonfirmasi:

## Password

Existing password update form.

Possible additional sections hanya jika source confirms mature implementation:

## Sign-In Security

## Known Devices

## Sessions

## Login Activity

Jika functionality tidak ada atau backend-only:

**jangan dibuat UI baru**.

---

# 10. Password Feature Preservation

Password update behavior harus tetap menggunakan existing backend flow.

Preserve:

- current password validation
- password confirmation validation
- password rules
- password hashing
- CSRF
- authentication middleware
- rate limiting jika existing
- session/security behavior setelah password update
- existing error handling
- existing status feedback

Do not duplicate password update logic ke controller baru.

Preferred approach:

> Dedicated Security page renders/reuses existing password form and continues posting to the existing password update endpoint.

---

# 11. Password Form Refactor Strategy

Gate 1 harus menentukan apakah existing password markup:

- sudah partial/component
- inline di `profile/edit.blade.php`
- berasal dari Breeze/Starter Kit partial
- dimodifikasi khusus project

Preferred hierarchy:

1. **Reuse existing partial directly** jika sudah reusable.
2. Jika inline, lakukan **minimal extraction** ke partial bila diperlukan agar tidak copy-paste.
3. Jangan refactor form lebih luas dari kebutuhan Phase 4.

Potential candidate:

`resources/views/profile/partials/update-password-form.blade.php`

Gunakan actual path jika sudah ada.

---

# 12. My Profile Final Responsibility

Setelah Phase 4:

My Profile harus fokus pada profile/account identity saja.

Security content yang berhasil dipindahkan harus dihapus dari My Profile agar tidak duplikat.

Delete Account juga harus hilang.

Target conceptual state:

My Profile
→ Profile Information

Security
→ Password / existing security controls

Notifications
→ Notification Preferences

Customization
→ UI Preferences

---

# 13. Delete Account — Mandatory Full Removal

Feature Delete Account harus dihapus sepenuhnya.

Gate 1 wajib menemukan seluruh implementation chain.

Search minimal untuk:

- `delete account`
- `Delete Account`
- `profile.destroy`
- `DeleteUser`
- `DeleteAccount`
- `delete-user`
- `delete-account`
- password-confirmed delete form
- confirmation modal text
- profile delete partial
- user deletion tests
- account deletion translations

Jangan berasumsi nama file mengikuti Laravel Breeze default.

---

# 14. Delete Account UI Removal

Remove:

- Delete Account card/section
- Delete Account button
- confirmation modal
- password confirmation field yang khusus untuk deletion
- warning copy
- destructive action text
- delete-specific status/error rendering

Jika UI berada pada dedicated partial dan tidak dipakai fungsi lain:

delete partial tersebut.

Jika UI bercampur dengan shared Profile markup:

hapus hanya deletion-specific section.

---

# 15. Delete Account Route Removal

Jika terdapat route khusus account deletion, misalnya conceptual:

`DELETE /profile`

`profile.destroy`

route tersebut harus dihapus.

Gate 1 harus menemukan exact route.

Setelah removal:

- route tidak boleh muncul di `route:list`
- tidak ada internal link/form action menuju route tersebut
- tidak ada named route reference yang tertinggal

Jangan menghapus generic delete route milik domain lain.

---

# 16. Delete Account Controller Cleanup

Jika existing Profile controller memiliki action seperti:

`destroy()`

yang hanya digunakan untuk Delete Account:

hapus action tersebut.

Kemudian bersihkan imports yang menjadi unused, misalnya jika memang hanya dipakai destroy:

- `Illuminate\Support\Facades\Auth`
- `Illuminate\Http\RedirectResponse`
- request type tertentu
- event/helper khusus deletion

Tetapi:

> Jangan menghapus import/shared method jika masih digunakan method lain.

Gate 1 harus membuktikan usage terlebih dahulu.

---

# 17. Delete Account Request Cleanup

Jika terdapat request class khusus Delete Account, contoh conceptual:

`ProfileDeleteRequest`

`DeleteAccountRequest`

dan tidak digunakan fitur lain:

hapus file tersebut.

Jika deletion memakai generic Request atau password confirmation validator yang juga digunakan fungsi lain:

jangan hapus shared infrastructure.

Delete only code that becomes orphaned.

---

# 18. Delete Account Test Cleanup

Tests yang exclusively menguji account deletion harus dihapus atau disesuaikan.

Examples:

- user can delete account
- correct password required to delete account
- profile destroy route test

Do not delete an entire test file jika file tersebut juga mencakup Profile update behavior.

Instead:

- remove deletion-specific test methods
- preserve profile/security tests that remain relevant

Add regression proving account deletion endpoint no longer exists.

Expected:

- route unavailable / named route absent
- old DELETE request receives expected 404/405 according to final route table
- user remains present after obsolete endpoint attempt

---

# 19. Delete Account Frontend / JavaScript Cleanup

Search for delete-account-specific:

- JavaScript modal handling
- confirmation dialog
- Alpine state
- Bootstrap modal state
- form submit handler
- selector IDs/classes
- CSS selectors
- data attributes

If only used by Delete Account:

remove.

Do not remove generic modal or form JS used elsewhere.

---

# 20. Delete Account Translation / Copy Cleanup

Search translation/config/view content for:

- Delete Account
- Permanently delete your account
- account deletion warning
- deletion password prompt

Remove orphaned copy only if it is no longer referenced.

Do not perform broad localization cleanup outside Phase 4.

---

# 21. Delete Account Database Boundary

Preferred result:

**No migration required.**

Removing Delete Account does not imply removing users table, foreign keys, soft-delete columns, or historical records.

Do not remove database schema merely because UI deletion is removed.

Only propose schema changes if Gate 1 discovers a dedicated column/table that exists solely for this feature and is provably unused elsewhere.

Default decision:

> application-code removal only; no DDL.

---

# 22. Delete Account Business Data Safety

Do not manually delete existing user data.

Do not run:

- DELETE users
- TRUNCATE
- migration removing user records
- anonymization
- data migration

Phase 4 removes capability, not historical data.

---

# 23. Delete Account Security Outcome

Expected final behavior:

- no account-delete button
- no account-delete form
- no deletion endpoint
- no user-accessible account deletion action
- no hidden request path that still executes deletion
- no controller action callable through old route
- no orphaned deletion-specific validation
- no deletion-specific test expecting success

This must be explicitly verified.

---

# 24. User Dropdown Update

Modify Security destination.

Current:

`profile.edit#profile-security-title`

Target:

`profile.security`

Final order remains:

1. My Profile
2. Security
3. Notifications
4. Customization
5. Logout

Preserve:

- email header
- icons
- separators
- Bootstrap dropdown behavior
- keyboard behavior
- Logout POST
- CSRF

Do not modify navbar notification polling/activity behavior.

---

# 25. Security Fragment Cleanup

Current Phase 3 introduced/uses:

`profile-security-title`

Gate 1 harus search seluruh references.

After dedicated page:

- remove obsolete anchor-specific implementation if no longer needed
- update internal links
- update tests
- remove fragment-only `tabindex` or scroll-margin behavior if no longer useful

Do not retain dead fragment compatibility without evidence.

If repository-owned references depend on it:

document and choose minimal compatibility strategy.

---

# 26. Backward Compatibility Decision

Gate 1 must search exact references to:

- `#profile-security-title`
- `profile.edit` security links
- security heading IDs
- tests asserting fragment destination

If only Phase 3 navbar/test uses it:

replace cleanly.

If multiple production references exist:

update all repository-owned references.

Do not create a redirect purely for hypothetical external bookmarks unless product requires it.

---

# 27. Security Controller Decision

Gate 1 must choose one exact design.

## Option A — Reuse ProfileController

Use if page rendering naturally belongs there and controller remains cohesive.

## Option B — Dedicated UserSecurityController

Use if separation is clearer and aligns with Phase 3 account-page pattern.

Expected small responsibility:

`index()`

Do not move password update logic into this controller unless existing architecture already does so.

Recommendation must follow repository evidence.

---

# 28. Security Route Authorization

Security GET route should use same authenticated/account-access policy as profile area unless repository evidence says otherwise.

Verify actual roles:

- admin
- purchasing
- supplier
- qc
- accounting
- finance
- ga

Do not accidentally make Security supplier-only atau internal-only.

Security page should be current-user only.

No user ID route parameter.

---

# 29. Known Device Architecture Audit

Gate 1 must investigate existing known-device behavior.

Classify as:

- user-facing and reusable
- backend-only
- absent

Inspect:

- device storage
- known-device tokens
- login recognition
- new-device notifications
- revoke capability
- tests

Important:

`NewDeviceLoginNotification` existing does **not** automatically mean device management UI should be created.

If there is no mature user action:

defer.

---

# 30. Session Architecture Audit

Gate 1 must determine:

- configured session driver
- whether sessions are queryable by user
- user/session linkage
- existing logout-other-sessions behavior
- existing session UI
- relevant tests

If there is no user-facing existing capability:

do not build one.

No session-management feature from scratch.

---

# 31. Login / Security History Audit

Inspect whether user-facing login history already exists.

Do not confuse:

- auth audit logs
- security telemetry
- admin monitoring
- server logs

with:

user-facing account history.

If backend-only:

do not expose it.

TD-REG-01 remains out of scope unless unavoidable.

---

# 32. Security Notifications Boundary

Mandatory security notifications remain unchanged:

- password reset
- new device login
- repeated lockout alerts
- verification/security system notifications

Do not add toggles on Security page.

Do not modify Phase 3 notification registry.

Do not alter Phase 3 `NotificationSending` behavior.

---

# 33. Notifications Regression Boundary

Phase 3 must remain intact.

Must preserve:

- `/profile/notifications`
- notification preference registry
- `local_invoice_submission_received`
- mail suppression behavior
- notification preference persistence
- navbar bell/activity implementation

Phase 4 does not modify Notifications unless a navigation test requires a minimal reference update.

---

# 34. Customization Regression Boundary

Phase 1–2 Customization remains intact.

Do not modify:

- Theme
- Accent
- Density
- Sidebar
- Page Size
- Quick Access
- Dashboard preferences
- Regional preferences

Phase 4 account page separation must not alter UserPreference state.

---

# 35. BusinessTime & Regional Boundary

Explicitly exclude:

- TD-REG-01 remediation
- TD-REG-02
- TD-REG-03
- TD-REG-04
- BusinessTime
- `@bizdt`
- timezone experimental tests
- Regional formatting expansion

If an existing security view displays a timestamp with known debt:

report it as deferred.

Do not fix incidentally.

---

# 36. Dirty Working Tree Discipline

Repository has unrelated dirty work.

For every candidate file:

1. inspect `git diff -- <file>`
2. classify existing hunks
3. preserve unrelated changes
4. modify surgically

Mixed files must not be rewritten wholesale.

Do not run broad auto-formatting over dirty mixed files.

No staging during Gate 1 or implementation before Gate 2 approval.

---

# 37. Initial Candidate Files

Gate 1 must replace this list with exact paths.

Potentially relevant:

- `resources/views/profile/edit.blade.php`
- `resources/views/profile/security.blade.php`
- existing profile password partial
- existing delete-account partial
- `resources/views/partials/navbar.blade.php`
- `routes/web.php`
- `app/Http/Controllers/ProfileController.php`
- potential dedicated Security controller
- delete-account-specific request if present
- profile/security tests
- account navigation tests

Do not create unnecessary files.

---

# 38. Expected Production Scope Pattern

Ideal Phase 4 change should be small.

Potential new file:

- dedicated Security view
- possibly dedicated Security controller

Potential modified files:

- navbar
- profile page
- routes
- existing password partial only if needed
- Profile controller if deletion action exists

Potential removed files:

- delete-account partial
- delete-account request
- deletion-only artifacts

Exact scope must come from Gate 1 evidence.

---

# 39. TDD Strategy — Dedicated Security Route

Initial RED:

`profile.security` route does not exist.

Expected GREEN:

authenticated user can access dedicated Security page.

Verify:

- route name
- URI
- auth middleware
- applicable role middleware
- no user parameter
- correct page heading

---

# 40. TDD Strategy — Navigation

Verify final dropdown order:

My Profile
Security
Notifications
Customization
Logout

Security destination must equal:

`profile.security`

Must not contain:

`#profile-security-title`

Preserve Logout POST semantics and CSRF.

---

# 41. TDD Strategy — Profile/Security Separation

Verify:

My Profile:

- still contains profile identity form
- no longer contains moved password/security UI
- no longer contains Delete Account

Security:

- contains existing password/security UI
- does not duplicate Profile identity form
- does not contain Delete Account

Use stable DOM/form assertions.

---

# 42. TDD Strategy — Password Update Regression

Existing password flow must remain unchanged.

Test:

## Valid Current Password

Correct current password + valid new password + matching confirmation.

Expected:

password updated.

## Wrong Current Password

Expected:

validation error.

## Confirmation Mismatch

Expected:

validation error.

## Invalid Password Rule

Expected:

validation error.

## CSRF

Existing CSRF protection remains.

Do not weaken password requirements.

---

# 43. TDD Strategy — Delete Account Removal

Add explicit regression coverage.

Verify:

- Delete Account text/button absent from My Profile
- Delete Account absent from Security
- deletion form absent
- deletion route removed
- deletion named route unavailable
- old DELETE endpoint no longer performs deletion
- user record remains after attempting obsolete endpoint
- deletion controller action no longer exists if removed
- no deletion request class remains if removal is approved

Primary proof should be route/UI behavior plus source audit.

---

# 44. TDD Strategy — Role Access

Verify all roles with profile access can access Security:

- admin
- purchasing
- supplier
- qc
- accounting
- finance
- ga

Use actual middleware.

If role behavior differs in source:

document exact reason.

---

# 45. TDD Strategy — Notifications Regression

Re-run representative Phase 3 tests:

- account navigation
- notifications page
- notification preference persistence
- delivery suppression

Expected:

unchanged PASS.

---

# 46. TDD Strategy — Customization Regression

Re-run representative:

- customization access
- preference persistence
- account navigation

Expected:

unchanged PASS.

No UserPreference mutation should occur from Security page rendering.

---

# 47. TDD Strategy — Auth/Security Regression

Re-run relevant existing tests for:

- password reset
- password update
- login
- known-device behavior if existing
- lockout/security notifications if affected by moved UI

Do not alter backend auth semantics.

---

# 48. Accessibility Requirements

Dedicated Security page must provide:

- one clear `h1`
- logical heading hierarchy
- explicit labels
- help/error association
- visible focus
- keyboard navigation
- adequate touch targets
- accessible status messages
- responsive layout
- light/dark theme compatibility

Password inputs should use appropriate autocomplete:

Current password:

`autocomplete="current-password"`

New password:

`autocomplete="new-password"`

Confirmation:

`autocomplete="new-password"`

Only if consistent with actual form.

---

# 49. Delete Account Accessibility Cleanup

Ensure removal leaves no:

- empty heading
- empty card
- orphaned modal trigger
- invalid `aria-controls`
- dangling `aria-describedby`
- hidden modal markup
- inaccessible separator
- dead focus target

Profile page structure must remain semantically valid after deletion section removal.

---

# 50. Security Review Requirements

Review Phase 4 for:

- route authorization
- cross-user access
- current-user ownership
- password validation
- password disclosure
- session fixation regression
- CSRF
- unsafe redirect
- sensitive logging
- deletion endpoint accidentally left reachable
- hidden destructive path
- duplicate password processing
- mandatory notification weakening
- privacy leakage

No unresolved Critical/High finding before Gate 2.

---

# 51. Privacy Requirements

Do not expose:

- password hash
- reset token
- remember token
- session secrets
- raw authentication secrets
- private security logs
- another user's sessions/devices
- internal attack-monitoring data

Only current-user-safe existing information may be rendered.

---

# 52. Performance Requirements

Phase 4 should introduce negligible runtime overhead.

No:

- AJAX page boot
- polling
- background jobs
- external package
- redundant user query
- row-level N+1

Dedicated Security page should primarily use the authenticated user and existing forms.

---

# 53. Database Decision

Expected:

**NO MIGRATION**

Delete Account removal must not trigger schema cleanup by default.

Security page separation should not require schema changes.

Gate 1 must explicitly confirm database decision.

If any migration is proposed:

STOP and justify before implementation.

---

# 54. Route Verification Requirements

After implementation verify:

`profile.security` exists.

Verify old Delete Account route does not exist.

Verify:

- `profile.edit`
- `profile.security`
- `profile.notifications`
- `profile.customization`
- `logout`

all remain correct.

Use `php artisan route:list` with appropriate filters.

---

# 55. Static Cleanup Verification

Search repository after implementation for deletion-specific remnants.

Use exact searches based on discovered implementation.

Examples:

- `profile.destroy`
- `Delete Account`
- delete-account partial path
- removed request class name
- removed modal ID
- removed route name
- removed controller method reference

Expected:

zero application references, except historical docs/tests only if intentionally retained and explicitly justified.

Do not blindly remove words like `destroy` globally.

---

# 56. Code Quality Requirements

After removal:

- no unused imports
- no dead controller methods
- no orphan request classes
- no dead partials
- no obsolete test fixtures
- no route references to removed actions
- no empty profile sections
- no duplicate password form

Use scoped formatting only.

Do not reformat unrelated mixed files.

---

# 57. Gate 1 Required Questions

Gate 1 must answer all:

1. What exactly is rendered in current My Profile?
2. What exactly is current Security section?
3. Where is password form defined?
4. Which route processes password update?
5. Which controller/request handles password update?
6. Is there known-device UI?
7. Is there session-management UI?
8. Is there login-history UI?
9. Which security infrastructure is backend-only?
10. Where is Delete Account rendered?
11. What route performs Delete Account?
12. What controller/action performs deletion?
13. Is there a deletion-specific request?
14. What tests cover account deletion?
15. What JS/modal code exists only for deletion?
16. What translations/copy exist only for deletion?
17. Which files become dead after deletion removal?
18. Are there any other repository references to `#profile-security-title`?
19. What exact dedicated Security route should be used?
20. What controller architecture should render Security?
21. Can password UI be reused without duplication?
22. Are database changes required?
23. What dirty-file overlaps exist?
24. What exact production files must be added/modified/deleted?
25. What exact tests must be added/modified/deleted/reused?

---

# 58. Gate 1 Required Output

Return exactly:

# Gate 1 — Phase 4 Dedicated Security Page & Delete Account Cleanup

Status: PLAN ONLY; waiting for approval.

## 1. Repository Baseline

## 2. Current User Dropdown

## 3. Current My Profile Architecture

## 4. Current Security Section

## 5. Password Architecture

Include:

- route
- controller
- request
- view/partial
- validation

## 6. Known Device Architecture

Classify:

- user-facing
- backend-only
- absent

## 7. Session Architecture

Classify:

- user-facing
- backend-only
- absent

## 8. Login / Security History

Classify:

- user-facing
- backend-only
- absent

## 9. Delete Account Architecture

Include exact:

- UI
- route
- controller/action
- request
- JavaScript/modal
- tests
- other references

## 10. Delete Account Removal Plan

Exact artifacts to:

- delete
- modify
- preserve

## 11. Proposed Dedicated Security Page

## 12. Proposed Security Route

## 13. Controller Decision

## 14. Password Reuse Strategy

## 15. My Profile Cleanup

## 16. User Dropdown Change

## 17. Security Fragment / Backward Compatibility

## 18. Security Notifications Boundary

## 19. Database Decision

Expected no migration.

## 20. Exact Production Files

Separate:

- New
- Modified
- Deleted

No approximate paths.

## 21. Exact Test Files

Separate:

- New
- Modified
- Deleted
- Reused

## 22. Dirty-Tree Overlap

## 23. Security Findings

## 24. Accessibility Findings

## 25. Privacy Findings

## 26. Performance Findings

## 27. TDD Matrix

## 28. Static Cleanup Verification Plan

## 29. Explicit Deferred Items

## 30. Risk Classification

## 31. Phase 4 Definition of Done

## 32. Gate 1 Recommendation

Recommend one exact implementation scope.

Do NOT implement.

Do NOT stage.

Do NOT commit.

Do NOT push.

End exactly with:

STOP: waiting for `Approve Gate 1 — Phase 4`.

---

# 59. Required TDD Matrix

Gate 1 must provide:

| Slice | Initial RED | Expected GREEN | Regression Protection |
|---|---|---|---|

At minimum include:

1. dedicated Security route
2. Security dropdown destination
3. authenticated access
4. role access
5. password form moved
6. password update still works
7. My Profile no longer shows security form
8. Delete Account UI absent
9. Delete Account route removed
10. obsolete deletion endpoint cannot delete user
11. deletion-specific code cleanup
12. Notifications regression
13. Customization regression
14. accessibility
15. security fragment cleanup

---

# 60. Implementation Sequence

After Gate 1 approval, implementation should follow:

## Step 1 — Characterization

Lock current password/profile behavior with tests.

## Step 2 — Security Route/Page

Add dedicated Security GET route and page.

## Step 3 — Reuse/Move Password UI

Move existing security/password presentation without copying business logic.

## Step 4 — Navigation

Change Security dropdown destination.

## Step 5 — My Profile Cleanup

Remove moved Security markup.

## Step 6 — Delete Account Removal

Remove UI, route, action, request, tests, JS, copy, and orphan imports specific to deletion.

## Step 7 — Static Orphan Sweep

Search exact removed symbols/references.

## Step 8 — Focused TDD

Run profile/security/navigation/delete-removal tests.

## Step 9 — Regression

Run auth, Phase 3 Notifications, Customization, profile tests.

## Step 10 — Security & Accessibility Review

Resolve findings.

## Step 11 — Full Tracked Regression

Run all tracked PHP tests serially.

## Step 12 — Gate 2

Stop before staging/commit.

---

# 61. Gate 2 Verification Requirements

Gate 2 must verify:

- dedicated Security page exists
- dropdown points to Security page
- password flow unchanged
- My Profile cleaned
- Delete Account completely removed
- old delete route gone
- obsolete endpoint cannot delete user
- deletion-specific files/references removed
- no unrelated auth rewrite
- Notifications unaffected
- Customization unaffected
- no migration
- no dependency
- security review passes
- accessibility review passes
- focused tests pass
- tracked project regression passes

---

# 62. Focused Test Categories

Final focused suite should include actual discovered test files covering:

- profile navigation
- profile update
- password update
- Security page
- role access
- account navigation
- Delete Account removal
- Phase 3 Notifications
- representative Customization
- authentication/security regression

Do not invent test paths before inspecting repository.

---

# 63. Full Regression Requirement

After focused tests pass:

enumerate all tracked tests from Git.

Run full tracked PHP regression serially.

Report:

- test files
- tests
- assertions
- failures
- errors
- skipped
- duration
- exit code

Expected:

`exit code 0`

Known untracked BusinessTime/timezone tests remain separate.

Do not claim filesystem suite PASS if those still fail.

---

# 64. Build / Static Verification

Run as applicable:

- PHP lint on changed PHP
- scoped Pint
- scoped `git diff --check`
- `php artisan route:list`
- `php artisan view:cache`
- `npm.cmd run build`

Search for deleted symbols after implementation.

Do not fix unrelated whole-tree whitespace or build artifacts.

---

# 65. Dirty-Tree Protection at Gate 2

Gate 2 must report:

- branch
- HEAD
- tracked modified count
- untracked count
- staged count

Expected:

- 0 staged
- HEAD unchanged from Phase 3 commit until finalization
- unrelated dirty work preserved

Do not commit during Gate 2.

---

# 66. Phase 4 Definition of Done

Phase 4 is COMPLETE only when all are true:

## Navigation

- User Dropdown order remains:
  - My Profile
  - Security
  - Notifications
  - Customization
  - Logout
- Security points to dedicated Security page.
- No Profile anchor destination remains in dropdown.

## Security Page

- Dedicated Security page exists.
- Existing password functionality is available there.
- Existing password business logic is reused.
- No speculative security functionality was added.

## My Profile

- My Profile contains profile/account identity only.
- Security form is not duplicated.
- Delete Account is absent.

## Delete Account Removal

- Delete Account UI removed.
- Delete Account route removed.
- Delete Account controller action removed if exclusive.
- Delete Account request removed if exclusive.
- Delete Account modal/JS removed if exclusive.
- Delete Account test coverage expecting deletion removed.
- No obsolete endpoint can delete the user.
- No orphan internal reference remains.
- No database data is deleted.
- No replacement delete/deactivate feature added.

## Security

- Password validation unchanged.
- CSRF intact.
- route authorization correct.
- no cross-user access.
- no sensitive data exposure.
- mandatory security notifications unchanged.
- no unresolved Critical/High findings.

## Regression

- Notifications passes.
- Customization passes.
- Profile passes.
- Auth/password tests pass.
- tracked project regression passes.

## Scope

- no database migration.
- no external package.
- no BusinessTime work.
- no Regional debt remediation.
- no polling redesign.
- no auth architecture rewrite.

Final Gate 2 status:

**PHASE 4 COMPLETE**

No Phase 4A.

No Phase 4B.

No Phase 4C.

---

# 67. Final Commit & Push Workflow

Only after Gate 2 approval:

1. inspect exact diff
2. classify mixed files
3. selectively stage Phase 4 only
4. verify staged diff
5. run staged diff check
6. run focused verification
7. create one Phase 4 commit
8. normal push to `origin/customization-update`
9. verify local/remote parity
10. confirm unrelated dirty work remains local

Never use:

- `git add .`
- `git add -A`
- `git commit -a`
- force push

Preferred commit message candidate:

`feat: separate account security and remove account deletion`

Final status:

**PHASE 4 COMPLETE AND PUSHED**

---

# 68. Final Scope Summary

Phase 4 intentionally does only two product-level things:

1. **Security becomes a dedicated account page.**
2. **Delete Account is completely removed and its orphaned application code is cleaned up.**

Everything else remains stable unless required to preserve those two outcomes.

This keeps Phase 4:

- bounded
- testable
- secure
- maintainable
- fast to review
- safe against the existing dirty working tree
