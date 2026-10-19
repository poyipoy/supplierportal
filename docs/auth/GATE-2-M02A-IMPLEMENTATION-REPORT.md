# ADASI — Gate 2 M02A: Targeted Authentication Correctness Fixes

Date: 19 October 2026. Scope: approved limited Option A, F02/F03/F04 and F07 cleanup containment only.

**M02A COMPLETE — PENDING M02B REVIEW.** The final focused acceptance results pass. This report does not authorize M02B, M02C, M03, M04 or deployment.

## Baseline and preserved work

Repository: `poyipoy/supplierportal`. HEAD, local master, tracking `origin/master` and live `git ls-remote origin refs/heads/master` matched **317dc0092bc8e08a8eeaca40803a7b327877b88d** before modification. Remote was checked again at final verification and still matched. Framework: Laravel 12.69.3; disposable runtime PHP 8.2.30 / MySQL 8.0.30.

Inputs: [Gate 1 plan and Post-M01 Addendum A1–A9](GATE-1-LOGIN-TRANSACTION-FLOW-PLAN.md), [M01 report](GATE-2-M01-BASELINE-FEASIBILITY-REPORT.md), project instructions and the Laravel reasoning framework. Current routes, controllers, middleware, services, User, callers, framework and relevant tests were inspected. The original Option C was not implemented.

The two existing untracked input documents were preserved. M01 report SHA-256 remains `1c73e39ae2723d3a622e858f88730c45df71d8d4c9fb8d5f075c8a605a2e8d9e`. No checkout, reset, stash, commit, push, package installation, application migration, driver/configuration change or deployment was performed.

## Files changed

All runtime changes are inside A4.1:

| File | Responsibility |
|---|---|
| `app/Models/User.php` | Pure shared eligibility predicate and request-snapshot configuration comparison. |
| `app/Http/Requests/Auth/LoginRequest.php` | Reuse eligibility predicate; retain hashing, limiter and Turnstile ordering. |
| `app/Services/Auth/TwoFactorService.php` | Authenticated-login rejection, subject-scoped cleanup and login-specific entry into the single locked verifier. |
| `app/Http/Middleware/EnsurePendingTwoFactorChallenge.php` | Reject already-authenticated challenge requests before pending validation/verifier. |
| `app/Http/Controllers/Auth/TwoFactorChallengeController.php` | Defense before proof, generic admission-denial mapping, consumption/success audit ordering. |
| `app/Services/Auth/CompleteLoginService.php` | Guest/fresh configuration admission and common cleanup before guard mutation. Public completion signature unchanged. |
| `lang/en/auth.php`, `lang/id/auth.php` | Paired generic stale-challenge message for JSON 409. |

New authorized coverage: `tests/Feature/Auth/TargetedAuthenticationCorrectnessTest.php`. New deliverable: this report. No existing test, route, supplier approval/policy, profile MFA controller, `EnforceAuthSessionSecurity`, schema or production environment file was modified.

## Changes by finding

**F02 — obsolete pending:** `CompleteLoginService::complete()` now clears only `auth.two_factor_pending`, after final admission and before the guard writes. Both password-only and MFA completion use this boundary. MFA setup, password confirmation, locale, intended destination and other metadata are not flushed. An admission rejection before this boundary removes pending only when it belongs to the rejected subject; failed unrelated credentials preserve another valid pending challenge.

**F03 — authenticated account replacement:** the existing pending middleware, MFA POST controller and common completion each enforce the guest precondition. An authenticated challenge clears obsolete pending, returns the existing `/dashboard` resolver for HTML or a localized generic HTTP 409 for JSON, and never uses pending intended as the current account's destination. It does not log out, invalidate/regenerate the current session, consume proof, issue another guard login, reset the absolute timer or create another device/success event. Existing CSRF and throttle middleware remain authoritative: invalid CSRF still returns 419, and prior throttle exhaustion may still return 429.

**F04 — eligibility and freshness:** `User::isAuthenticationEligible()` requires `is_active` and `account_status === ACTIVE`. Password admission, fresh MFA admission, the row-locked login verifier and final completion use it. Final completion re-reads by `users.id` and compares password hash, session version, MFA secret and confirmation timestamp against the request's verified snapshot. The login verifier works on a clone so its attribute synchronization cannot overwrite that snapshot. Last-used TOTP step and recovery-list changes are excluded because valid proof consumption changes them. Configuration/eligibility mismatches produce the generic login error (HTML login redirect / JSON 422), without guard login or proof restoration.

There remains exactly one cryptographic/replay routine. `verifyUserCode(User, string)` preserves its generic profile contract; `verifyLoginCode()` adds admission checks under the existing row lock before consuming TOTP/recovery proof. Supplier approval, activation, role and scope decisions are unchanged.

**F07 — cleanup containment:** after the chosen supersession point, later exceptions do not restore obsolete pending or retry proof/guard operations. Unexpected storage/device/cap exceptions remain observable. Recovery consumption is audited when it happens, even if final admission later fails. TOTP challenge success is emitted after successful completion; rejected final admission does not emit a false MFA-success event. The existing guard login-success audit can legitimately exist when a later ancillary operation returns 500: the authentication may already have happened.

## Isolated verification environment

Owned disposable workspace:

    C:\Users\BAHRIALGI\AppData\Local\Temp\adasi-m02a-6bc543f709c6

Private MySQL binds `127.0.0.1:60954`, with a new datadir below that workspace. Port, datadir and server UUID are checked before fixture administration and shutdown. The application account has SELECT/INSERT/UPDATE/DELETE only on this private `adasi_portal_test` schema. It is not the existing development/test database of the same name. Root credentials are confined to fixture provisioning/verified shutdown and are not supplied to Laravel.

Verified server UUID: `46f1ed6c-cbbc-11f1-ae74-50ebf64ed7d2`. The owned server was shut down after each completed phase; final actual PID/listener checks found it stopped.

Source starts from `git archive` of the pinned SHA plus the approved changed files. Vendor/PHP come from the already installed disposable toolchain; no dependency download. No source `.env` is copied. Source/tools are read/execute via DACL; storage/bootstrap-cache are writable. PHP uses explicit extensions, `open_basedir`, disabled execution/socket helpers, inert outbound HTTP, array mail, null broadcast and synthetic credentials. Workers enter a Windows Job before application execution, with unchanged 50-second PHP / 55-second parent wall bounds and resource caps. Scratch growth is monitored, not a hard disk quota.

All twelve admission probes pass: outside canary read/write denied; source writes denied even after chmod attempt; private storage writable; no source `.env`; process/network helpers disabled; private database port/name/datadir verified; other-schema access denied. A negative development-port connector probe throws before PDO connect.

PHPUnit runs through fixture-only `Tests\TestCase` instrumentation: guarded connector, inert HTTP transport, Vite hot-file, and `RefreshDatabaseState::$migrated = true`. Existing RefreshDatabase suites therefore use transactions on the pre-provisioned auth schema without running migrations or obtaining DDL privileges. Fixture schema support was extended for supplier relationship reads, preferences, scopes and the empty reset-token table required by existing tests. This is an authentication fixture, not a complete portal schema or supplier/payment integration test.

Isolation is for trusted code regression, not hostile-code OS certification. There is no VM/AppContainer/network namespace. MySQL's Windows monitor/child boundary is not claimed to have full Job containment. Shutdown targets the verified owned server rather than assuming the monitor PID is the actual server PID.

## Executed verification

Final completed PHPUnit results, counting the latest result for each unique test/provider case:

| Suite | Cases | Assertions | Final result |
|---|---:|---:|---|
| TargetedAuthenticationCorrectnessTest (new) | 75 | 694 | PASS |
| AuthenticationTest | 8 | 29 | PASS |
| TwoFactorAuthenticationTest | 15 | 99 | PASS |
| LoginSecurityTest | 23 | 138 | PASS |
| SessionSecurityTest | 25 | 167 | PASS; prior intermittent assertion retained below |
| KnownDeviceSecurityTest | 12 | 59 | PASS |
| AuthAuditSecurityTest | 7 | 232 | PASS |
| AuthSplashTest, selected auth-only coverage | 42 | 147 | PASS |
| TranslationParityTest | 3 | 40,666 | PASS |
| **Total** | **210** | **42,231** | **0 final failures/errors** |

The 132 existing auth cases contribute 871 assertions. Translation assertions account for most of the total; they are not additional authentication scenarios. Four AuthSplash methods requiring full destination/dashboard integration or query-shape coverage were not selected: `successful_login_binds...`, `mandatory_password_redirect...`, `every_role_and_supplier_context...`, and `splash_adds_no_database_queries...`. No portal-wide suite or browser result is implied.

Six additional checks PASS through real HTTP Kernel requests in separate PHP processes, using encrypted response cookies and active CSRF, on both database and file session drivers: obsolete challenge after non-MFA completion preserves the current account (409), forged CSRF remains 419, and a post-guard controlled fault leaves no pending while the next independent request remains authenticated. These are sequential request checks, not overlapping concurrency tests.

Focused invocation, through the admitted supervisor rather than the repository's default database configuration:

```text
python <fixture>/scripts/run_m02a.py phpunit
python <fixture>/scripts/run_m02a.py focused
python <fixture>/scripts/run_m02a.py http
```

The first phase retained its failed setup/oracle cases. The final focused phase reran the corrected 75-case suite, the two relevant existing followups, and translation parity. Other completed compatible cases were not needlessly rerun. Each bounded PHPUnit worker uses `vendor/phpunit/phpunit/phpunit --configuration <fixture>/scripts/phpunit.xml --filter <selected methods> --log-junit <artifact> <test file>`.

Static checks: all nine changed/new PHP files pass `php -l` and scoped Pint; `git diff --check` passes. Source-copy verification compares **891 runtime source files** with the pinned archive plus the approved eight-file patch; every comparison passes. The new test's source hash also matches the executed fixture. No Blade/CSS/JS changes require a frontend build.

Final JUnit aggregation: `evidence/final-verification-summary.json`, SHA-256 `94f0d0f365613142fd14cd642aa563c8c400217bb9bb3cf176d23bf0ce474394`. It lists each logical case and its selected artifact. Earlier failures remain in `evidence/initial/`, `evidence/test-oracle-corrections/`, and the original grouped logs. HTTP records: `evidence/results-http.json`.

### A01–A16 acceptance crosswalk

| ID | Final result | Executed evidence / boundary |
|---|---|---|
| A01 | PASS | Pending absent at guard Login event and after password-only completion; setup/locale/password-confirmation metadata retained; database/file independent requests. |
| A02 | PASS | TOTP/recovery completion and replay rejection; common cleanup; existing enrollment/disable suites. |
| A03 | PASS | Eight GET/POST × HTML/JSON × same/different-account cases; verifier/guard events absent; current SID/CSRF/timer retained; separate controller/completion defense checks. |
| A04 | PASS | Exact generic JSON 409; missing/forged CSRF 419 before proof; encrypted-cookie independent requests on both drivers. |
| A05 | PASS | Staged A → successful non-MFA C → old challenge retains C and leaves A proof unconsumed. This assumes the request sees C authenticated. |
| A06 | PASS | Eight active/status combinations at password, MFA and final boundary; missing subjects; normalized dummy-hash miss/ineligible behavior. |
| A07 | PASS | Fourteen controlled serial mutations before the locked verifier; no TOTP/recovery consumption, no guard login, rejected pending cleared. |
| A08 | PASS | Fourteen MFA mutations before final admission plus six password-only mutations, including newly enabled MFA; reject changed configuration without restoring consumed proof. |
| A09 | PASS | Wrong unrelated credentials and ineligible final admission retain legitimate unrelated pending; successful supersession and post-guard fault remove obsolete pending. |
| A10 | PASS | Recalled MFA on protected HTML route and direct MFA entry converts to fresh guest pending before rejection; valid proof completes without a loop. |
| A11 | PASS | Credentialed JSON recalled MFA terminates with 401; remembered non-MFA normal recall preserved. |
| A12 | PASS | Invalid/replayed proof, named MFA limiter and non-sliding 600-second deadline, including exact equality. |
| A13 | PASS, F01 retained | Guest B credentials replace A pending; A recovery code does not complete B. No strict attempt-binding claim. |
| A14 | PASS | Existing 23-case LoginSecurity suite: unchanged limiter behavior, HTML/JSON 429, dummy hashing/normalization/tarpit, Passed/Invalid/ProviderError Turnstile paths. |
| A15 | PASS within auth scope | Internal/external intended handling, unchanged role/scope routes, pending supplier remains inactive/unapproved without new scope, immutable email guard. Full supplier approval/domain integration not executed. |
| A16 | PASS, F07 partial | Device/cap/audit/splash compatibility, metadata retention and controlled post-guard failure; consumed recovery audit retained; no false TOTP success/splash or automatic retry. Independent follow-up remains authenticated after 500. |

The mutation hooks above are serial boundary injections, not cross-process race barriers. **BLOCKED: none for required focused M02A verification. NOT EXECUTED: M02B overlap/concurrency, M02C, M03, M04, full portal/browser/staging/production verification.** Full I1–I7 do not receive PASS from this table.

Retained evidence is under `evidence/` and JUnit records under `runtime/` in the disposable workspace. Supervisor entry point: `scripts/run_m02a.py`; guarded PHPUnit entry point: `scripts/phpunit-worker.php` / `scripts/phpunit.xml`. Repository coverage remains in the new test class for subsequent verification in an independently admitted environment.

Early harness/test failures are preserved rather than overwritten:

- An initial DACL setup omitted executable file access; corrected before admission and application tests.
- Whole-class workers hit the 50-second bound. Icon cache was generated with the existing command in the fixture, and suites were split into smaller groups; resource limits were not increased.
- Missing fixture `suppliers`, preference columns and `password_reset_tokens` caused existing-suite errors. Only the disposable fixture definitions were corrected.
- New test setup initially omitted the encrypted session cookie, supplied a SessionManager instead of a Store to a direct request, and omitted credentialed JSON cookies. Those test preconditions were corrected; application behavior and security thresholds were not weakened.
- One existing idle-session assertion intermittently found its expired row deleted. Native session GC lottery can delete expired rows independently of the cap service; that explanation is source-supported inference for the original request. The exact original deletion was not instrumented. The unchanged focused rerun PASSes (1 case / 6 assertions); neither the lottery nor any cap/lifetime threshold was altered. The original FAIL and the test's flakiness remain explicit M02B review material, not proof of a repaired GC contract.

No login/MFA limiter key, threshold, pending deadline, TOTP window, recovery replay rule or concurrency cap was changed. Test-only bcrypt/dummy-hash rounds and tarpit default follow the existing repository test configuration; this is not a production timing benchmark.

## Remaining limitations and readiness for M02B

F01 guest pending replacement is intentionally retained. Valid credentials B may replace guest pending A; A's proof cannot complete B. No form/flow nonce or persistent proof revision was added.

F05 stale file-session resurrection, F06 late-cookie continuity, old-cookie guests and duplicate completion across requests/SIDs remain open. Cleanup is request-local and is not a global cancellation or one-use completion claim. Changes after the last trusted check are not fenced atomically. This request's snapshot does not detect security changes between the initial password request and a later MFA request when the existing pending format contains no proof revision.

F07 remains partial: OTP/recovery consumption, guard, session persistence, device/cap work, audit, notifications, splash and cookie issuance are not one transaction. A controlled post-guard 500 may leave the next request authenticated with proof consumed. There is no automatic retry, proof restoration or whole-operation rollback guarantee.

Global account-status enforcement for established or non-MFA recalled sessions was not expanded. Browser bfcache, visual/accessibility/splash timing, multi-host behavior and production/staging were not executed. Full I1–I7 are not declared satisfied.

M02B should review the corrected baseline, real overlapping changed boundaries, old/new SID/cookie schedules, driver behavior and the recorded expired-row test flakiness. No concurrency experiment, route blocking enablement, session coordinator, SID registry, cache authority or subsequent mission is executed by this task.

## Rollback

Revert only this report's eight runtime-file hunks and the new focused test/report if desired, preserving the two input documents and any later unrelated work. Use reviewed inverse hunks or a dedicated revert after these changes have a commit; do not reset the working tree or broadly restore files. There is no application schema rollback.

Do not restore serialized pending, consumed TOTP/recovery proof, old remember tokens or historical session payloads. Do not rotate APP_KEY, switch drivers, erase audits or mutate live data as rollback. Affected in-flight attempts may require a fresh login. Disposable fixture cleanup must verify its absolute path and owned server identity, and preserve evidence before removal.
