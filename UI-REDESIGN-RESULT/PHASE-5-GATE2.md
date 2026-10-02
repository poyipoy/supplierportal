# Gate 2 — Phase 5 In-App Notifications & Manual Password Assistance

## 1. Status

**PHASE 5 COMPLETE.** Implementation and verification complete; staging, commit, and push are not authorized.

## 2. Repository State

- Branch: `customization-update`.
- HEAD: `02e682934275e4b45f46cfe89db7bdf5e26f50ca`.
- Upstream: `origin/customization-update`; ahead/behind: `0/0`.
- Tracked modified/deleted: 213. Untracked porcelain entries: 28, including this report. Staged: 0.

## 3. TDD Progression

Retained prior evidence: database safety 2/4 PASS; original characterization 6/41 PASS; notification RED 61/331 with 9 expected failures; Assistance RED 3/9 with 3 expected failures. Additional registry, auth removal, reminder identity, and capability-cache failure RED preceded their corrections. Existing characterization methods were individually converted from obsolete mail expectations to in-app behavior. No new RED assertions were discarded.

Final focused: 414 tests / 3,472 assertions, 0 failures/errors/skipped, exit 0, 01:25.893. Final tracked: 1,282 tests / 13,772 assertions, 0 failures/errors/skipped, exit 0, 07:58.520.

## 4. Notification Architecture

Trusted registry resolves exact `SystemNotification` class plus `event()`. Occurrence `eventKey()`, UUIDv5, constructor identity, and payload identity remain unchanged. Database/broadcast are one logical in-app preference. Default ON; unknown/malformed event, class mismatch, unsupported channel, or preference failure preserves legacy delivery. Capability-cache failure falls back to the authoritative supplier-scope check, without relaxing authorization.

## 5. Final Registry

40 entries, each default true, with no channel metadata:

- Purchase requisitions: `pr_submitted`.
- Quotations: `quotation_submitted`, `quotation_revised`, `quotation_accepted`, `quotation_rejected`, `quotation_revision_requested`.
- Conversations: `quotation_negotiation_message`, `conversation_message_created`.
- Purchase orders: `po_issued`, `po_item_progress_updated`.
- Documents: `document_status_updated`, `document_all_completed`.
- Shipments and QC: `shipment_submitted`, `po_material_arrived`, `qc_inspection_ok`, `qc_inspection_ng`.
- Material claims: `claim_created`, `claim_responded`, `claim_resolved`.
- Local invoices: `local_invoice_submitted`, `local_invoice_resubmitted`, `local_invoice_cancelled`, `local_invoice_physical_received`, `local_invoice_approved`, `local_invoice_revision_requested`, `local_invoice_rejected`, `local_invoice_partial_payment`, `local_invoice_paid`, `local_invoice_overpaid`, `local_invoice_refund_settled`, `local_invoice_physical_delivery_reminder`.
- Supplier registration: `supplier_registration_submitted`, `supplier_registration_resubmitted`, `supplier_registration_revision_requested`, `supplier_registration_rejected`, `supplier_registration_approved`.
- Exports: `export_completed`, `export_failed`.
- Security: `new_device_login`, `repeated_lockouts_detected`.

Inactive history/workflow-only events were not added.

## 6. Role / Supplier-Scope Eligibility

Active/ACTIVE accounts only. Supplier import/local scopes are durable account capabilities; dual-scope accounts receive the union regardless active portal. Document options include prospective Purchasing and actual eligible legacy PO creators. Export options include current producers and actual historical job owners. These checks do not grant new operational access.

Browser QA accounts without owner exceptions rendered: Admin 10; Purchasing 18; Finance 10; Accounting 5; QC 4; GA 1; import supplier 11; local supplier 13; dual-scope supplier 23 controls. Each returned 200, one h1, and no legacy preference section. Category ordering is additionally asserted for all account roles on the final controller implementation.

## 7. Notification Preferences UI

In-app event checkboxes only. Native hidden-0/checkbox-1 PATCH form, Save Changes, separate notification-only DELETE reset. No Email, Required, Optional, Always On, database, or broadcast controls. Empty categories omitted; approved eleven-category order enforced.

## 8. Preference Persistence / Compatibility

Existing JSON column stores only registered false overrides, e.g. `{"quotation_rejected":false}`. True removes a key; empty state is SQL NULL. Nested Phase 3 mail data is ignored without GET writes and normalized on next notification save/reset. Other preference fields remain intact.

## 9. Runtime Suppression

OFF prevents both database creation and broadcast enqueue for the registered event. Same-class event independence, unsupported-channel/class behavior, real lookup failures, and delivery-time queued-notification changes are tested. Existing already-created rows or already-enqueued broadcast transport are not retroactively cancelled.

## 10. Notification Reset

`DELETE /profile/notifications`, `profile.notifications.reset`, current-user-only, auth/CSRF. Existing user-row lock and transaction retry pattern, JSON NULL, revision increment once, sidebar revision and all customization fields preserved. Guest and missing-CSRF rejection tested; concurrent reset/customization save tested with real MySQL subprocesses.

## 11. Local Invoice Conversion

| Flow | Mail | In-app | Duplicate protection |
|---|---|---|---|
| Submitted/resubmitted | Removed | Existing staff retained; supplier confirmation added | Same history identity, recipient-specific UUID |
| Revision requested | Removed | Existing supplier event retained | No second notification |
| Paid | Removed | Existing supplier event retained | No second notification |
| Physical reminder | Removed | New approved reminder event | Invoice/revision/date/reschedule identity and existing sent flag |

Real scheduler tests prove OFF consumes the reminder flag without changing obligation/status/date or replaying when reenabled. Financial transitions and supplier payload privacy regressions pass.

## 12. Security Notification Conversion

New-device and repeated-lockout events retain existing detection/audit and use explicit GLOBAL. New-device destination is `profile.security#active-sessions`. Old mail-enable config cannot restore email. Real-pipeline OFF tests preserve known-device rows, new-device audit, lockout threshold/audit, and suppress only user notifications.

## 13. NewDeviceLoginNotification Deletion Exception

`app/Notifications/NewDeviceLoginNotification.php` deleted completely. Its pre-existing BusinessTime formatting hunk intentionally disappeared under explicit user approval. It was not preserved or moved elsewhere. All other BusinessTime/Regional work remains protected.

## 14. Outbound Email Audit

No active approved application emitter remains for `toMail`, `Mail::`, mail notification routing, reset-link sending, reset notification hooks, or verification notification calls. Remaining source concepts are classified: `config/mail.php` framework transport support; `config/support.php` contact configuration; Assistance mailto/copy/template; existing refund mailto; vendor-detail/icon `mail` icon identifiers. Tests retain mail transport/fake assertions and stale-mail compatibility fixtures; historical docs remain historical. Framework mail capability is not removed or claimed absent.

## 15. Password Assistance

Guest/no-store GET `/forgot-password`, retained `password.request`, new `PasswordAssistanceController::show()`. Config `support.supplier_email` reads `SUPPLIER_SUPPORT_EMAIL`, fallback `supplier.support@example.com`; `.env` untouched. Fixed approved subject/body and three copy actions. Destination validated as a single email; query parameters cannot change address/subject/body. Invalid config has guidance and no active mailto. No account lookup, token generation, or server email.

## 16. Forgot / Reset Flow Removal

Removed POST `/forgot-password`, GET `/reset-password/{token}`, POST `/reset-password`, old controllers, reset view, branded reset notification/templates, and User override/import. Literal URI tests prove no token/mail/password mutation. Password-reset schema/config retained.

## 17. Email Verification Removal

Removed notice, signed verify, resend routes/controllers/view and Profile warning/resend form. No business `verified` authorization dependency found; unverified QC access and forbidden Admin access tested. `email_verified_at` cast/schema and harmless identity-change metadata hygiene retained.

## 18. Internal Staff Reset Capability

Existing Admin user edit/password assignment remains unchanged. Password policy, hashing, locking, session invalidation, and password_changed audit retained. Identity verification/credential handoff remains an internal operational process; no new admin reset feature.

## 19. Logged-In Password Regression

PUT `/password`, current-password rule, confirmation/policy, session rotation and other-session revocation retained. PasswordUpdate/PasswordPolicy/SessionSecurity regressions passed.

## 20. Phase 4 Security Regression

Dedicated Security, 2FA, Active Sessions, opaque session revocation tokens, logout other devices, current-session protection, and Delete Account absence retained. Automated regression passed. Browser confirmed Security form, current-password field, 2FA/sessions headings, no Delete Account or raw session_id field. Password submission was tested automatically, not manually through browser.

## 21. Notification Center Regression

Center/controller/summary/polling implementation untouched. Automated summary/read/ownership/category regressions passed. Browser bell lazy load worked; new-device click marked read, retained total=1, changed unread to 0, and navigated to Security#active-sessions. Temporary QA account loss after a separate RefreshDatabase test was identified as harness state, then restored and the interaction repeated successfully.

## 22. Security Review

Reviewed strict key/class/recipient/root-field rejection, current-user writes, role/scope/owner eligibility, cache isolation, fail-open business boundary, enumeration, safe encoded mailto, CSRF/XSS, verification authorization, and Phase 4 security. Capability-cache failure boundary was fixed and regression tested. No unresolved Critical/High implementation finding. Final independent read-only source review also found no concrete Critical/High security, privacy, authorization, or accessibility blocker. Runtime/test/browser evidence was verified by the primary agent.

## 23. Privacy Review

No new password/hash/token/session ID/internal user ID/MFA/recovery/device token or audit detail in Assistance template/mailto. No sensitive autofill. Supplier payload regressions retain planned-payment-date privacy. Existing unrelated shell payloads were not redesigned.

## 24. Accessibility Review

Source/automated: one Assistance h1 with page-specific brand semantics; native labeled event controls, associated help/errors, separate CSRF reset form, polite atomic copy status, focus preservation, selectable template, no CSP relaxation.

Browser: all nine audience pages, keyboard Space toggle, successful copy for all three actions, Clipboard rejection fallback, manual failure guidance, focus retention, 320px reflow without overflow. Notifications label targets >=44px; Assistance buttons 40px. Measured minimum text contrast: Notifications light 4.76:1/dark 8.99:1; Assistance light 4.55:1/dark 9.74:1. Screen-reader certification and physical 400% zoom were not performed; 320 CSS px equivalent reflow was checked.

## 25. Performance Measurements

- One preference SELECT for page including layout.
- Ten real sender/database/broadcast iterations: one preference read, one supplier-scope read.
- Repeated document/export eligibility: one indexed PO EXISTS and one export EXISTS, shared across each event pair.
- Unregistered direct notification: zero preference/scope reads.
- Worker scopes reread raw current storage, with no cross-recipient/job leakage.

Legacy notification idempotency queries remain per occurrence; they are not per-row preference reads. No new polling/jobs.

## 26. Exact Production Files

New:
```
app/Http/Controllers/Auth/PasswordAssistanceController.php
config/support.php
resources/js/password-assistance.js
```

Modified (`*` mixed/protected baseline):
```
.env.example *
config/notification_preferences.php
config/auth_security.php
routes/web.php *
routes/auth.php
app/Notifications/SystemNotification.php
app/Services/NotificationService.php
app/Support/NotificationDomain.php
app/Services/NotificationPreferenceService.php
app/Services/UserPreferenceService.php
app/Listeners/ApplyNotificationPreferences.php
app/Http/Controllers/UserNotificationPreferenceController.php
app/Http/Requests/UpdateNotificationPreferenceRequest.php
app/Services/LocalInvoice/InvoiceNotificationService.php
app/Services/Auth/CompleteLoginService.php
app/Listeners/LogAuthenticationEvent.php *
app/Services/NotificationUrlResolver.php *
app/Models/User.php
app/Providers/AuthSecurityServiceProvider.php *
app/Support/RateLimitResponse.php
app/Http/Middleware/DecodeHashids.php *
resources/views/profile/notifications.blade.php
resources/views/profile/partials/update-profile-information-form.blade.php
resources/views/auth/forgot-password.blade.php
resources/views/layouts/auth.blade.php
resources/js/app.js
```

Deleted:
```
app/Contracts/UserConfigurableNotification.php
app/Notifications/LocalInvoice/InvoiceSubmissionReceivedNotification.php
app/Notifications/LocalInvoice/RevisionRequiredNotification.php
app/Notifications/LocalInvoice/InvoicePaidNotification.php
app/Notifications/LocalInvoice/PhysicalDeliveryReminderNotification.php
app/Notifications/NewDeviceLoginNotification.php
app/Notifications/RepeatedLockoutAlertNotification.php
app/Notifications/AdasiResetPasswordNotification.php
app/Http/Controllers/Auth/PasswordResetLinkController.php
app/Http/Controllers/Auth/NewPasswordController.php
app/Http/Controllers/Auth/EmailVerificationPromptController.php
app/Http/Controllers/Auth/EmailVerificationNotificationController.php
app/Http/Controllers/Auth/VerifyEmailController.php
resources/views/auth/reset-password.blade.php
resources/views/auth/verify-email.blade.php
resources/views/emails/auth/reset-password.blade.php
resources/views/emails/auth/reset-password-text.blade.php
```

This report is a documentation artifact, not an additional production subsystem.

## 27. Exact Test Files

New: `tests/Feature/Auth/PasswordAssistanceTest.php`, `tests/js/password-assistance.test.mjs`.

Modified:
```
tests/Feature/UserNotificationPreferencesTest.php
tests/Feature/NotificationPreferenceDeliveryTest.php
tests/Feature/NotificationDeliveryTest.php
tests/Feature/NotificationUrlResolverTest.php *
tests/Feature/UserPreferenceConcurrencyTest.php
tests/Feature/_user-preference-concurrency-worker.php
tests/Feature/UserCustomizationTest.php
tests/Feature/SendLocalInvoiceDeliveryRemindersTest.php
tests/Feature/LocalInvoice/LocalInvoiceTest.php *
tests/Feature/PaymentForecastAndReportingTest.php
tests/Feature/RenderedComponentTest.php
tests/Feature/Auth/KnownDeviceSecurityTest.php
tests/Feature/Auth/PasswordResetTest.php
tests/Feature/Auth/EmailVerificationTest.php
tests/Feature/Auth/PasswordPolicyTest.php
tests/Feature/Auth/AuthRateLimitingTest.php
tests/Feature/Auth/SessionSecurityTest.php
tests/Feature/Auth/LoginSecurityTest.php
```

Deleted: none. Reused focused files:
```
tests/Feature/NotificationControllerTest.php
tests/Feature/UserDashboardCustomizationTest.php
tests/Feature/UserRegionalPreferencesTest.php
tests/Feature/UserAccountNavigationTest.php
tests/Feature/ProfileTest.php
tests/Feature/ProfileSecurityTest.php
tests/Feature/FinanceVerificationV2Test.php
tests/Feature/FinanceDrpPaidTest.php
tests/Feature/UnifiedPaymentEngineTest.php
tests/Feature/LocalInvoice/LocalSupplierWholeGrSettlementTest.php
tests/Feature/LocalInvoice/LocalSupplierOverpaymentTransparencyTest.php
tests/Feature/AsyncExportQueueTest.php
tests/Feature/Auth/AuthAuditSecurityTest.php
tests/Feature/Auth/AuthenticationTest.php
tests/Feature/Auth/PasswordConfirmationTest.php
tests/Feature/Auth/PasswordUpdateTest.php
tests/Feature/Auth/SecurityHeadersTest.php
tests/Feature/Auth/TwoFactorAuthenticationTest.php
tests/Feature/Auth/RegistrationTest.php
```

All remaining tracked test files were included in the full manifest, including existing preference migration/isolation/hashid tests.

## 28. Dirty-Tree Protection

179 baseline dirty/untracked files outside scope verified byte-identical by SHA256. Mixed existing hunks preserved; formatter excluded mixed files. Index hash unchanged; staged=0. Approved NewDevice whole-file exception only. No reset/clean/stash/branch switch/staging/commit/push.

## 29. Tests Executed

Primary final commands (PowerShell temp path resolves to the user Temp directory):
```
php vendor/bin/phpunit tests/Feature/TestingEnvironmentDatabaseSafetyTest.php --no-progress --colors=never
php vendor/bin/phpunit -c "$env:TEMP/adasi-phase5-focused.xml" --no-progress --colors=never --log-junit "$env:TEMP/adasi-phase5-focused-junit.xml"
php vendor/bin/phpunit -c "$env:TEMP/adasi-phase5-tracked.xml" --no-progress --colors=never --display-phpunit-deprecations --log-junit "$env:TEMP/adasi-phase5-tracked-final-junit.xml"
node --test tests/js/password-assistance.test.mjs tests/js/calendar.test.mjs tests/js/unsaved-changes.test.mjs
npm.cmd run build
php artisan route:list --no-ansi --path=forgot-password
php artisan route:list --no-ansi --path=profile/notifications
php artisan route:list --no-ansi --path=reset-password
php artisan route:list --no-ansi --path=verify-email
php artisan view:cache
```

Scoped php -l/Pint/diff checks covered the exact approved PHP/file manifest. Earlier RED/GREEN filters and temporary detailed logs retained in Temp. No DB-backed suite ran concurrently with another suite.

## 30. Focused Results

37 files including all tracked Auth tests and new Assistance PHP test. Tests=414; assertions=3,472; failures=0; errors=0; skipped=0; duration=01:25.893; exit=0.

## 31. JS Test Results

Password Assistance 5/5 PASS. Combined copy/calendar/unsaved-change 11/11 PASS, 0 skipped/failures, exit=0, 173.065ms. Clipboard/manual fallback and focus preserved.

## 32. Tracked Project Regression

155 Git-tracked PHP test files, no exclusions. Tests=1,282; assertions=13,772; failures=0; errors=0; skipped=0; duration=07:58.520; exit=0. Two doc-comment metadata deprecations in unchanged `tests/Unit/Support/BankTransferMappingTest.php` remain out of scope. New untracked Assistance PHP test was separately covered by focused verification.

## 33. Filesystem / Untracked Timezone Boundary

Full filesystem discovery was not claimed PASS. Existing untracked timezone/BusinessTime tests untouched. `tests/Feature/Timezone/PresentationLayerTimezoneTest.php` still references the explicitly deleted class; it is obsolete external/untracked coverage and intentionally excluded from the tracked manifest.

## 34. Static / Build Verification

41 PHP files lint PASS; scoped Pint PASS; scoped diff check PASS; Blade view cache PASS; exact route set verified; Vite build PASS in 12.87s; JS PASS; active-mail source audit clean. Vite retained the existing logo runtime URL warning; actual logo loaded in browser. Generated build assets remain outside tracked source scope. No unrelated whole-tree findings repaired.

## 35. Browser Verification

Existing loopback QA harness used only verified `adasi_portal_test`, synthetic accounts, file test sessions, array mail and null broadcast. Nine audience routes, save/reload/reset, assistance/copy fallback/mailto encoding, Profile verification absence, Security fields, bell/read destination, keyboard/reflow/contrast verified. External email client was not launched; URL inspected. QA tab and server helper closed. No production/staging browser testing.

## 36. Database Decision

**NO APPLICATION MIGRATION.** No migration file created or manual application migrate/DDL run. Existing RefreshDatabase/DatabaseTruncation tests operate only on the verified disposable test database. Token/email metadata schema retained.

## 37. Dependency Decision

**NO NEW PACKAGE.** Composer/npm manifests and unrelated dirty dependency work preserved.

## 38. Explicit Deferred Items

Production support address, production/staging deployment/queue-state checks, old queued mail handling, screen-reader certification, physical print/zoom QA, unrelated timezone tests/Regional debt, and two existing PHPUnit deprecations. No destructive queue/data cleanup performed.

## 39. Scope Protection

No notification-center/polling redesign, new admin reset feature, BusinessTime expansion/re-homing, Regional remediation, external messaging integration, destructive application DB cleanup, package addition, or schema change.

## 40. Phase 5 Closure Decision

**PHASE 5 COMPLETE.** Reviewable working-tree implementation; no staging/commit/push. Waiting for explicit Gate 2 approval. This does not claim production deployment/readiness checks were performed.

STOP: waiting for `Approve Gate 2 — Phase 5`.
