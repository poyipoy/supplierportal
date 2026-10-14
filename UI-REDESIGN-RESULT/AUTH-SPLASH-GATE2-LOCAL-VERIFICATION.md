# ADASI Authentication Splash — Gate 2 local implementation

- Date: 13 October 2026
Baseline: remediation/security-review-20261010, bc56557b97768024b80874bcdf455542257dd2f8 plus existing uncommitted work.

**Status: local implementation complete; ready for staging verification with the feature disabled until activation is authorized. No deployment performed.**

Specification: [finalized Gate 1 plan](../docs/auth/GATE-1-ADASI-AUTH-SPLASH-IMPLEMENTATION-PLAN.md). The plan and both approved references remain unchanged.

## Implemented behavior

- One reusable wordmark splash for clean login GET/refresh, successful normal/TOTP/recovery-code login, and successful operational logout.
- Failed authentication/MFA, authentication feedback, errors, ordinary authenticated refresh/navigation, AJAX/server tabs/DataTables/downloads, HEAD, and unrelated security operations do not trigger the authentication splash.
- End-of-success login marker and post-invalidation/logout marker use the existing session, expire at 30 seconds, follow actual same-origin redirect locations, and are consumed/discarded on the final destination. Existing intended routing and security operations are preserved.
- Fixed local CSS/JS sources are conditionally inlined before blocking assets. Native login does not depend on app.js, Alpine, DOMContentLoaded, or optional requests. Application destinations require their layout sentinel, DOM readiness, and core Alpine acknowledgement.
- The independent controller owns timers, animation, temporary inert/busy state, focus, skip, deadline and cleanup. Core readiness cancels letter motion and releases interaction immediately; the agreed 650 ms fade is cosmetic and removable before user interaction or foreground loader handoff.
- AdasiLoader continues tracking tokens and normal navigation/server work. Its DOM presentation is held while the splash owns the page; completed work does not flash on handoff, and pending work resumes without two competing overlays.
- Original white/red typography, sizing, spacing, clipping, no-bounce A-D-A-S-I sequence, 90 ms stagger, 1600 ms rise/sink, 50 ms entry, 300 ms hold, 100 ms pause, and 650 ms fade are preserved. Reduced motion remains static.
- Independent 10-second deadline and 2-second skip control, JS/CSS/CSP fail-open behavior, idempotent terminal state, bfcache cleanup, and native password input fallback are implemented.

### Corrections proven during verification

1. Completed loader tokens inside its existing minimum-visibility interval could flash after splash release. A failing regression was added; presentation now checks actual pending work.
2. Alpine's sidebar inert binding could conflict with the temporary splash restriction. Attribute intent is reconciled while waiting, and desktop/mobile state is restored on release. Loader settlement also restores its acquired inert/busy snapshots instead of clearing a closed sidebar. Verified in a real finance layout at 1366 and 375 px.
3. The existing i18n classic script followed render-blocking styles. It now precedes external styles, after the independent splash bootstrap, so a hanging CDN stylesheet cannot stop native login form parsing.
4. Chrome can withhold animation frames under hanging styles. Native readiness races its frame opportunity with a captured zero-delay task, preventing a false 10-second wait. This adds no minimum display duration.

These changes stay within readiness/fail-open integration; no authentication verification algorithm or unrelated business module was changed.

## Complete file inventory

The following are the 24 task files. Existing unrelated dirty modifications in shared files were retained.

| File | Task action |
|---|---|
| .env.example | Modified: default-off feature setting |
| config/ui.php | Created |
| app/Services/Auth/CompleteLoginService.php | Modified |
| app/Http/Controllers/Auth/AuthenticatedSessionController.php | Modified |
| app/Http/Middleware/ShareAuthSplashState.php | Created |
| bootstrap/app.php | Modified |
| lang/en/auth.php | Modified |
| lang/id/auth.php | Modified |
| resources/views/components/ui/auth-splash.blade.php | Created |
| resources/views/partials/auth-splash-bootstrap.blade.php | Created |
| resources/views/layouts/auth.blade.php | Modified |
| resources/views/layouts/app.blade.php | Modified |
| resources/views/layouts/guest.blade.php | Modified |
| resources/views/auth/login.blade.php | Modified |
| resources/css/auth-splash.css | Created |
| resources/js/auth-splash.js | Created |
| resources/js/app.js | Modified |
| resources/js/adasi-loader.js | Modified |
| tests/Feature/Auth/AuthSplashTest.php | Created |
| tests/Feature/FrontendAssetLoadingTest.php | Modified |
| tests/js/auth-splash.test.mjs | Created |
| tests/js/adasi-loader.test.mjs | Modified |
| tests/Support/auth-splash-browser-check.mjs | Created |
| UI-REDESIGN-RESULT/AUTH-SPLASH-GATE2-LOCAL-VERIFICATION.md | Created |

Generated Vite artifacts, compiled Blade files, disposable browser fixtures/results/screenshots/CDN cache under storage/framework/testing/auth-splash, and temporary browser profiles are verification artifacts, not additional application-source edits. Browser session/profile and exact disposable test users were cleaned up.

No actual .env edit, package/lockfile/Vite-entry modification, application-data mutation, application migration command, Git stage/commit/push/merge/rebase, or deployment was performed. Test fixtures used the isolated adasi_portal_test connection after physical database verification.

## Executed verification

| Check | Final result |
|---|---|
| TestingEnvironmentDatabaseSafetyTest | 2 passed, 4 assertions; physical SELECT DATABASE() verified adasi_portal_test |
| Entire tests/Feature/Auth | 196 passed, 1,356 assertions |
| AuthSplashTest subset of that suite | 54 passed, 268 assertions |
| FrontendAssetLoadingTest | 12 passed, 165 assertions after final layout-order change |
| Translation parity/content | 20 passed, 97,298 assertions |
| Standalone splash and loader Node tests | 39 passed |
| Real browser acceptance harness | 33 scenarios passed on the final compiled assets; no failed scenario |
| Changed PHP syntax | Passed |
| Scoped Pint --test | Passed |
| Blade view:cache | Passed |
| Relevant login/MFA/logout route inspection | Passed; security middleware preserved |
| npm.cmd run build | Passed; existing three Vite entries retained |
| Scoped diff/whitespace and JS syntax checks | Passed |
| Actual local config:show ui | enabled=false, TTL=30, deadline=10000, skip=2000, fade=650, cleanup=750 |

PHP suites ran serially. Initial red tests demonstrated missing functionality. The initial sandbox build failed with spawn EPERM; the authorized local build succeeded outside that restriction. Initial browser-harness navigation waits/network delivery were corrected before the final passing run. No test is reported as passing from an earlier failed run.

The browser harness exercises actual Laravel login, failed credentials, failed and successful TOTP, logout, CSRF, destination refresh, ordinary menu navigation, and sidebar ownership/foreground-loader settlement using exact disposable finance users. It also exercises Blade-rendered native/core fixtures for absent/hung app.js and CDN assets, script/style CSP blocking, JavaScript disabled, watchdog, keyboard/skip/Escape, exact state restoration, reduced motion, accessibility tree, reflow-equivalent 200%/400% viewport checks, loader overlap, feature rollback, prototype comparison, and browser history.

The test-only cacheable fixture entered bfcache and restored without splash replay. Normal no-store history did not enter bfcache in this Chrome run; its existing cache policy was not weakened.

Reproducible local evidence: [browser result JSON](../storage/framework/testing/auth-splash/result.json), [desktop screenshot](../storage/framework/testing/auth-splash/wordmark-1366-light.png), [mobile screenshot](../storage/framework/testing/auth-splash/wordmark-375-light.png). Artifacts are local/ignored and may be regenerated with the browser harness.

## Performance and accessibility findings

| Measurement | Observed local evidence |
|---|---|
| Standalone JavaScript source | 15,292 bytes raw / 4,027 bytes gzip; below 5 KiB budget |
| Scoped CSS source | 3,345 bytes raw / 1,207 bytes gzip; below 3 KiB budget |
| Splash-specific HTTP requests | 0 |
| Added DB queries | 0 in identical warmed SQL-sequence comparisons on login and authenticated profile.security |
| Controlled core fixture median | Disabled 309.6 ms; enabled 295.8 ms; -4.46% |
| Real test-data finance page median | Disabled 690.4 ms; enabled 455.8 ms |
| Layout shift | CLS 0 on measured fixture and finance samples |
| Long tasks | None on measured samples |
| Visual fidelity | Desktop/mobile computed wordmark styles match prototype; screenshots inspected |
| Accessibility | Decorative letters absent from accessibility tree; stable status, keyboard skip/Escape, focus containment/restoration, reduced motion, exact inert/busy restoration verified |

Performance uses five warmed alternating samples per flag state after discarding a warmup, in the local headless browser/test-data environment. The negative finance difference is not a claimed speed improvement; browser/network/cache scheduling contributes variance. No meaningful regression was observed. These measurements do not establish production LCP/INP, large-data dashboard performance, or field percentile guarantees.

## Remaining limitations and staging handoff

- Manual screen-reader verification was unavailable in the headless run. Check actual announcements and keyboard/zoom behavior with a screen reader during staging accessibility review; automated accessibility-tree inspection is not a WCAG-compliance claim.
- A finance Chart-is-not-defined diagnostic remained in the final run and was reproduced with the feature disabled. Earlier passing runs also recorded browser-native view-transition abort diagnostics with the feature disabled. They did not prevent authentication, splash cleanup, or the measured readiness flow. They remain separate page/asset integration findings; this task does not refactor them or claim an entirely clean browser console.
- Sequential one-time marker behavior is verified. Strict cross-tab atomic consumption is not promised; the accepted cosmetic same-session race is bounded by the target and 30-second TTL.
- Staging/production CSP, CDN/cache/proxy behavior, deployment source-file availability, real-user performance, and production authentication were not verified. No deployment was performed.

**Ready for staging verification:** yes. Keep AUTH_SPLASH_ENABLED=false until activation is explicitly authorized in the target environment. The required local checks pass; staging should confirm screen-reader behavior, real network/assets, dynamic no-store responses, existing view-transition/chart integration, flag rollback, and role/context destinations before production activation.
