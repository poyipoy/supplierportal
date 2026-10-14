# ADASI Splash — Readiness Correction

- Date: 13 October 2026
- Scope: approved readiness correction, local implementation/verification only
- Status: complete locally; no deployment or Git mutation

## Change

The controller now distinguishes native DOM readiness, application-core readiness, and render readiness. A native form/sentinel no longer closes the splash immediately through a zero-delay timer. Normal dismissal follows two successive animation-frame callbacks; these provide a render opportunity without a branding duration or mandatory animation cycle.

The component is discovered/validated and activated while visual readiness is still pending. Early prerequisite signals remain sticky. A page already visually ready before component insertion skips activation/focus, and removed state remains terminal.

The application acknowledgement waits until synchronous DOM-ready listeners and Alpine next-tick work finish. Its independent DOM-ready latch also handles late initialization when DOMContentLoaded has already fired but document.readyState is still interactive.

Native login remains independent of app.js and DOMContentLoaded. If styles withhold render frames, the absolute watchdog restores interaction and removes the overlay with a hard-timeout exit; it does not report healthy readiness.

No loader/authentication/server-marker/CSS/prototype changes. AdasiLoader remains responsible for credential/MFA verification requests. Successful authentication alone triggers the destination splash; failures return errors without a new splash. TTL, skip, timeout, fade, reduced motion, focus/inert restoration, and existing loader tokens are preserved.

## Files changed in this correction

1. resources/js/auth-splash.js
2. resources/js/app.js
3. tests/js/auth-splash.test.mjs
4. tests/Support/auth-splash-browser-check.mjs
5. UI-REDESIGN-RESULT/AUTH-SPLASH-READINESS-FIX-RESULT.md

Existing unrelated working-tree changes were retained. Actual .env was not edited; its existing locally enabled flag was preserved. Configuration default remains false.

## Baseline and current evidence

Before modifying runtime code, the full browser harness ran against the isolated adasi_portal_test database with disposable users.

| Successful authentication | Before: bootstrap to ready | After: bootstrap to ready | After: core to render-ready |
|---|---:|---:|---:|
| Normal password login | 188.2 ms | 224.8 ms | 25.6 ms |
| TOTP login | 106.0 ms | 116.9 ms | 14.6 ms |

Both successful-login paths already entered WAITING before this correction. They did not exhibit the direct-login ARMED-to-REMOVED behavior, and that distinction is preserved.

Fresh direct-login checks on the actual local URL after the correction:

| Visit | Native DOM ready | Activation | Render-ready | Removal |
|---|---:|---:|---:|---:|
| Cold | 470.3 ms | 475.8 ms | 769.7 ms | 1437.3 ms |
| Cached 1 | 323.8 ms | 326.3 ms | 346.0 ms | 996.2 ms |
| Cached 2 | 294.4 ms | 298.2 ms | 310.3 ms | 970.3 ms |

These are navigation-relative browser timings, not promised display durations. All three reported ready in WAITING, released interaction in RELEASED, and completed removal in REMOVED. Normal fade remains approximately 650 ms and does not block interaction. Very fast readiness still does not guarantee a visible letter rise.

The post-login timings are individual local samples with server/network/cache variation. The extra core-to-render interval is the requested render opportunity, not an artificial minimum. Controlled paired local core medians were 294.3 ms disabled versus 293.1 ms enabled. Test-data finance medians were 681.2 ms disabled versus 450.4 ms enabled; no speedup claim is made.

Evidence artifacts:

- storage/framework/testing/auth-splash/readiness-before.json
- storage/framework/testing/auth-splash/readiness-after.json

The after run includes actual success/failure login, failed/successful TOTP, logout, native login without the application module, withheld styles, CSP/JS failure, watchdog, bfcache, reduced motion, keyboard/focus/inert, loader handoff, ordinary menu navigation, flag rollback, prototype comparisons and reflow checks. Disposable users and browser session/profile artifacts were cleaned.

## Executed verification

- Isolation safety: 2 passed, 4 assertions; physical database verified as adasi_portal_test.
- New readiness regressions failed against the prior implementation before runtime edits.
- JavaScript splash/loader tests: 43 passed.
- Full authentication suite: 196 passed, 1356 assertions, including 54 splash cases.
- Frontend asset/layout suite: 12 passed, 165 assertions.
- Browser baseline: 33 passed. Browser after correction: 33 passed.
- Scoped Pint, JS syntax, Blade compilation, build and scoped diff/whitespace checks passed.
- Splash HTTP requests: zero. Existing SQL-sequence parity tests remained passing.
- Measured CLS: zero; no long tasks in controlled samples.
- Standalone source: 16161 bytes raw / 4129 bytes gzip; unchanged CSS: 3345 bytes raw / 1207 bytes gzip.

## Limits

Manual screen-reader review and staging/production verification remain unexecuted. The browser reproduced existing finance Chart-is-not-defined and browser-native view-transition diagnostics with the feature disabled; no unrelated chart/transition refactor was performed.

No database application records, dependencies, authentication behavior, animation design/timing, actual environment file, deployment, or Git state were changed by this correction.
