# ADASI Authentication Splash — Minimum Duration Revision

- Date: 15 October 2026
- Status: implemented and verified locally; no deployment or Git state-changing operations.
- Contract: the user-approved 2,000 ms minimum supersedes the earlier no-minimum contract in the Gate 1 plan and readiness correction report. Those documents remain historical evidence.

## Behavior

All three existing triggers use the minimum: direct login access/refresh, successful authentication including MFA, and redirected login after logout. Normal navigation and failed authentication remain excluded.

The clock starts when the validated overlay activates (`visible-start`). Healthy DOM/core/render readiness still requires the existing signals and two successive animation frames; its event is published immediately. Normal interaction release waits for both readiness and the minimum, then cancels the letter animation and restores focus/inert before the existing nonblocking 650 ms fade.

Early readiness is retained when markup has not arrived. A late eligible component activates with its own minimum unless the absolute bootstrap deadline has elapsed. With the minimum explicitly set to zero, the previous early-ready skip behavior remains available.

Reduced motion bypasses the minimum and fade. Enabling reduced motion during the hold cancels its minimum requirement; missing readiness still cannot be manufactured by a timer. Escape, keyboard skip, errors, pagehide/pageswap, bfcache restoration, CSS expiry, and the absolute 10-second watchdog retain immediate fail-open cleanup. Background/foreground changes do not restart the minimum.

`config/ui.php` adds `ui.auth_splash.min_visible_ms = 2000`, passed through the independent inline bootstrap. The client accepts finite values from 0 through 10,000 ms, uses 2,000 for invalid/missing values, and accepts zero as a rollback to readiness-only dismissal. No new environment variable is introduced. The feature flag still defaults to false; the existing local setting was not changed.

AdasiLoader retains credential/MFA validation and normal navigation duties. Request tokens settle throughout the hold; only pending foreground work can take presentation after interaction release. No authentication, session marker, redirect, TTL, dependency, prototype, request, or query behavior was changed.

## Files changed in this revision

1. `config/ui.php`
2. `resources/views/partials/auth-splash-bootstrap.blade.php`
3. `resources/js/auth-splash.js`
4. `tests/js/auth-splash.test.mjs`
5. `tests/Feature/Auth/AuthSplashTest.php`
6. `tests/Support/auth-splash-browser-check.mjs`
7. This report.

The preceding CSS change hiding the skip control except on keyboard focus was preserved. Browser reflow checks now focus that control before measuring its visible target. All unrelated dirty work was retained.

## Executed verification

- RED: 13 new readiness/minimum test cases failed against the preceding controller; existing cases passed.
- Node splash/loader tests: **63 passed**, including exact 1,999/2,000 ms boundaries, custom/default/invalid/zero configuration, delayed activation, late readiness, sticky early readiness, repeated signals, error cleanup, immediate exits, reduced motion, watchdog, background return, and unchanged animation timing.
- Database safety: **2 passed / 4 assertions**, confirming the physical connection is `adasi_portal_test`.
- Authentication suite, serial: **197 passed / 1,367 assertions**.
- Frontend asset loading: **12 passed / 169 assertions**.
- PHP and JavaScript syntax, scoped Pint, Blade compilation, production Vite build, and scoped diff whitespace checks: passed.
- Isolated headless Chrome harness: **35 scenarios passed**, using disposable test accounts on `adasi_portal_test`; cleanup confirmed. No writes to the application database.

Browser coverage includes cold/cached login, missing/hanging modules and styles, CSP and JavaScript-disabled fallback, independent deadline, focus/inert restoration, reduced motion and accessibility tree, 200%/400% reflow, loader handoff, prototype desktop/mobile/dark styling, history restoration, successful/failed credentials and MFA, logout, CSRF, ordinary refresh/menu navigation, and flag-disabled behavior.

## Browser measurements

Elapsed time from visible-start to interaction release, rounded; the cosmetic fade follows separately:

| Path | Elapsed |
|---|---:|
| Login, cold | 2,006.6 ms |
| Login, cached | 2,007.6 ms |
| Healthy application core fixture | 2,001.1 ms |
| Password login destination | 2,013.1 ms |
| MFA login destination | 2,011.0 ms |
| Logout after password login | 2,003.2 ms |
| Logout after MFA login | 2,003.3 ms |

The healthy core fixture recorded render readiness at 313.6 ms and interaction release at 2,289.5 ms from navigation. This intentionally adds the approved branding wait rather than delaying the readiness signal. Its CLS was zero, with no long tasks or standalone splash network requests. The PHP suite also verifies unchanged query counts.

Paired warmed core medians were 326.7 ms disabled versus 320.6 ms enabled for the fixture; finance medians were 439.3 versus 462.1 ms. These local samples are not field-performance guarantees or evidence of a speedup. Standalone JavaScript is 17,760 bytes raw / 4,434 bytes gzip.

Raw measurements: `storage/framework/testing/auth-splash/minimum-duration.json` (ignored local test artifact, including milestone timestamps and removal events).

## Limits and rollback

- Manual screen-reader, staging, and production verification were not performed.
- The no-store history visit did not enter bfcache in this run; the separate cacheable fixture restored successfully, and persisted lifecycle cleanup is unit-tested.
- Finance `Chart is not defined` was also observed with the splash disabled; it was not changed by this revision.
- Browser scheduling can extend display time. Background tabs or a blocked renderer cannot guarantee two seconds of actual painted frames; lifecycle exits and the hard deadline take precedence.
- Set `min_visible_ms` to `0` to remove the branding wait, or disable the existing feature flag to remove the authentication splash. No `.env`, database, dependency, commit, push, or deployment changes were made.
