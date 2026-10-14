# Gate 1 — ADASI Authentication Splash Integration

**Final status: PENDING USER APPROVAL FOR GATE 2**

- Project: ADASI Supplier Portal / poyipoy/supplierportal
- Working directory: C:\laragon\www\adasi_portal_supplier
- Audit and planning finalization: 13 October 2026
- Baseline branch: remediation/security-review-20261010
- Baseline HEAD: bc56557b97768024b80874bcdf455542257dd2f8, plus the existing uncommitted working tree.

This document finalizes the completed Gate 1 audit. Gate 1 authorizes documentation only. All runtime edits below are future Gate 2 work; saving this plan does not approve implementation, deployment, or Git operations.

The finalization resolves two gaps in the earlier draft: login readiness must work without the Vite application entry, and one independent controller must own both splash presentation and temporary interaction restrictions. The animation/controller will therefore be conditionally inlined from small, fixed local source files, rather than depending on an import from app.js. The existing application bundle supplies the application-core readiness signal and keeps its existing loader responsibilities.

## 1. Verified architecture and evidence

### Reference authority and completed discovery

- AGENTS.md, CLAUDE.md, and claudes-cognitive-framework-for-laravel-development.md were read completely during the Gate 1 audit. No nested AGENTS.md was found. CLAUDE.md imports the root instructions.
- [Deep Research report](references/deep-research-report.md) was read completely. It is technical guidance, not proof of current repository behavior.
- [Approved HTML prototype](references/adasi-post-login-splash-loop.html) was read completely. It is authoritative for visual design, CSS, masking, animation sequence, and timing.
- The user explicitly confirmed that the available adasi-post-login-splash-loop.html is the final visual source. The literal filename with (1) is absent and is no longer a blocker.
- Prototype SHA-256: F3E5954B4B89211052D77810EF8E216C5A439FBAF8ECEF76AF3600F6C472EA38.
- Research report SHA-256: 35CBEC9CF645892A2BF1453AD62C0AF2BB4F22D76D63234725568DF8088F1F89.
- The requested ECC skills were available and read: orch-add-feature, search-first, laravel-patterns, laravel-tdd, laravel-verification, laravel-security, and accessibility. Their relevant planning disciplines were applied; project instructions and the user's gate definitions override generic implementation/commit steps.

### Current implementation

Line anchors describe the audited working tree and must be rechecked before Gate 2.

| Area | Verified implementation |
|---|---|
| Versions | Laravel 12.69.3; Alpine 3.15.12; Vite 7.3.6; Laravel Vite plugin 2.1.0; Google2FA 9.0.0 |
| Login | routes/web.php around 680: guest GET /login and POST /login, both no-store |
| Normal success | AuthenticatedSessionController::store() calls CompleteLoginService::complete() after successful credentials |
| MFA success | TwoFactorChallengeController::store() calls the same service only after valid TOTP/recovery-code verification |
| Completion service | app/Services/Auth/CompleteLoginService.php:24–59 logs in, regenerates session, stores security metadata, handles devices/session limits/token revocation/audit/notification |
| Logout | AuthenticatedSessionController::destroy() logs out, invalidates the session, regenerates CSRF, then redirects to login |
| Intended destinations | TwoFactorService::safeIntendedUrl() validates origin/scheme/port and rejects several polling/API/shared-document destinations |
| Redirects | /dashboard redirects by role; supplier context selection and EnforcePasswordChange may add another redirect |
| Remember Me | Remembered MFA sessions are challenged again. Non-MFA remembered hydration bypasses CompleteLoginService |
| Layouts | Login/MFA use layouts.auth; dashboards, supplier context chooser, and profile.security use layouts.app; receipt verification uses layouts.guest |
| Error layouts | Custom 403/404/419/429/500 pages extend layouts.auth |
| Assets | All three layouts load app.css and app.js; loader-logo precedes @vite |
| Vite | THREE entries: app.css, app.js, registration-receipt.js. The research report's two-entry assumption is stale |
| Core startup | app.js around 456 starts Alpine, then registers calendars, lazy import/GR modules, and upload initialization |
| Readiness today | Neither layouts.auth nor layouts.app has a splash-ready contract. Both depend on app.js for Alpine enhancement; neither currently has the required independent login dismissal |
| Optional requests | Calendar import, DataTables data, charts, notification polling, Pusher, images/fonts, and Turnstile completion are separate from core readiness |
| Existing loader | resources/js/adasi-loader.js tracks tokens for navigation/forms/selected AJAX, uses inline DataTable busy indicators, and resets on persisted pageshow |
| Loader timings | Show delay 150 ms; minimum visibility 300 ms; slow control 20 s; navigation release 10 s |
| Ownership hazard | Loader writes inert/focus/body aria-busy and clears them on deactivation. Its z-index is 9999, matching the prototype |
| CSP/cache | AddSecurityHeaders enforces CSP by default; current script-src/style-src permit inline content. Dynamic responses are private/no-store unless explicitly public |
| Native login gap | Password input has Alpine :type but no native type=password. It must be corrected for login readiness without app.js |
| Test infrastructure | PHPUnit/MySQL, Node tests, and dependency-free Chrome/Edge CDP scripts exist. Playwright/axe/Lighthouse packages are not installed |

Framework inspection confirmed that controller views render before middleware unwinds, and StartSession saves afterward. A component acknowledgement can therefore be checked before session save. Ordinary flash is aged on save and can disappear on intermediate redirects. Session pull/forget is not atomic across concurrent requests.

### Preservation and verification boundaries

The initial tree contains extensive existing changes, including CompleteLoginService, bootstrap/app.php, routes/web.php, all three layouts, CSS/JS, Vite, security configuration, and tests. EnforcePasswordChange and some tests are untracked. Preserve their current content; do not restore files from HEAD or apply whole-file replacements.

Executed during the completed audit: Laravel/version and authentication/dashboard route inspection, scoped git diff checks, and node --test --test-isolation=none tests/js/adasi-loader.test.mjs: **11 passed**.

Finalization rechecked the reference hashes, branch/HEAD, relevant source, layout startup, CSP, and working-tree status. Source inspection supports the design; it is not browser evidence. No new feature exists yet. PHP/database suites, authentication browser tests, build, staging, and production validation have not been executed for this feature.

## 2. Scope, trigger contract, and non-goals

| Scenario | Authentication splash |
|---|---|
| Clean direct document GET /login | login-page |
| Clean login refresh | New login-page splash |
| Login returning validation/authentication errors | Absent; expose errors |
| Login with status/warning/error authentication feedback | Absent; expose feedback |
| Credentials accepted, MFA pending | Absent |
| Valid normal login | post-login on resolved destination, once in sequential navigation |
| Valid TOTP or recovery-code login | Same post-login flow |
| Invalid/replayed/expired/throttled MFA | Absent |
| Successful operational POST /logout | post-logout on redirected login |
| Refresh login after consuming logout marker | login-page, not stale post-logout |
| Ordinary authenticated refresh or menu navigation | Absent |
| AJAX, server tabs, DataTables, polling, HEAD, downloads | Absent |
| Error pages | Absent |
| Profile MFA enrollment/regeneration or logout-other-devices | No new marker |
| Remembered non-MFA dashboard access | No new marker |
| Remembered MFA challenge completion | post-login only after successful challenge |
| Registration access/logout | No operational authentication splash |

No database tables/migrations/data changes, new dependencies, splash endpoints, polling, extra splash HTTP requests, authentication redesign, new navigation engine, or unrelated refactoring. Preserve credentials, CSRF, rate limits, Turnstile, MFA/recovery consumption, guards, known devices, session versions, intended redirects, policies, roles, and supplier isolation.

The splash cannot cover server response time before destination HTML arrives. Existing AdasiLoader remains responsible for outgoing form/navigation feedback and pending server responses when the application assets are available.

## 3. Selected architecture and fixed defaults

Laravel controls eligibility and one-time handoff. One small independent client controller owns animation, readiness coordination, interaction restrictions, watchdog, and cleanup. Main application initialization reports readiness to that controller; it does not create or restart the splash.

| Decision | Fixed choice |
|---|---|
| Configuration | config/ui.php, ui.auth_splash namespace |
| Feature flag | AUTH_SPLASH_ENABLED=false by default and in .env.example |
| Pending marker | Ordinary session ui.auth_splash |
| Marker TTL | 30 seconds from issuance; redirects never extend it |
| Hard deadline | 10,000 ms from independent head bootstrap execution |
| Skip reveal | 2,000 ms after visible activation, only if still waiting |
| Normal exit | Cancel wave and restore interaction synchronously; cosmetic 650 ms fade |
| Removal fallback | 750 ms after normal release; no dependence on transitionend |
| Minimum display/cycle | None |
| Reduced motion | Static wordmark; immediate removal on release |
| Failure/skip/Escape/bfcache exit | Immediate removal, no fade |
| Concurrency | Sequential one-time semantics; accepted cosmetic cross-tab race within TTL |
| Telemetry | Local Performance API marks/events only; no new requests or identity payload |

Use fixed, trusted resources/js/auth-splash.js and resources/css/auth-splash.css as the single sources for the small standalone controller and scoped styles. A conditional Blade head partial reads and inlines them. The JavaScript is a classic, dependency-free IIFE with no imports, exports, eval, Alpine, jQuery, or translation-service dependency. Do not import it again from app.js, add a Vite entry, or fetch it separately. Test that same source through Node's VM with fake DOM/timers.

Inlining this bounded, conditional component is a deliberate revision to the earlier draft: a watchdog or animation inside a static Vite import graph cannot run if that graph fails. Full app assets remain cached through Vite; only the small splash controller/styles are inline. Guard missing/unreadable source files and omit the splash instead of throwing a rendering error. Paths are fixed application paths, never request inputs; deployment must include both files.

The head partial sets request attribute ui.auth_splash_bootstrap_available only after both fixed source reads succeed. The body component requires that attribute as well as eligibility and the error guard. Raw source inclusion is limited to these trusted files; dynamic configuration uses escaped generic values. No user-controlled text is concatenated into script/style source.

Research example code must not be copied verbatim: its middleware under-filters requests, lacks actual render acknowledgement, and can consume inappropriate HTML responses; its CSS changes typography/easing/spacing/fade; its motion handler can clear safeguards; its one-shot pageshow listener can miss later restoration.

## 4. Readiness and sequence flows

### Independent, sticky readiness bridge

The conditional head bootstrap executes after charset/viewport/CSRF metadata and BEFORE preference scripts, external styles, i18n script, @vite, or other blocking resources. It immediately captures its native timer functions, establishes an absolute deadline, registers lifecycle handlers, and observes document insertion. It must not wait for a body element, DOMContentLoaded, Alpine, or an asset request to arm its watchdog.

Each participating layout emits a non-executing template sentinel with data-auth-splash-layout-ready and an explicit layout value. The head controller observes it through MutationObserver and also scans the current DOM when initialized. A template is used instead of a footer inline script because execution of a later script can itself wait for earlier stylesheets. These DOM conditions are sticky facts, not one-shot events.

DOMContentLoaded waits for deferred/module scripts, so it is insufficient as the only fallback when app.js is delayed or absent. This behavior is documented by [MDN](https://developer.mozilla.org/en-US/docs/Web/API/Document/DOMContentLoaded_event); DOM insertion can be observed independently through [MutationObserver](https://developer.mozilla.org/en-US/docs/Web/API/MutationObserver).

| Layout / destination | Readiness contract |
|---|---|
| layouts.auth on login route | Place sentinel immediately after .auth-shell and toast container, BEFORE bottom CDN/page scripts. Observe completed native login form, email/password/submit controls and sentinel. Publish native-login readiness without waiting for app.js, Alpine, DOMContentLoaded, Turnstile completion, images, or fonts |
| layouts.app | Place sentinel after @stack('scripts'), immediately before closing body. Require sentinel + DOMContentLoaded observed + app.js core-ready acknowledgement after Alpine next-tick work |
| layouts.auth for another eligible intended page | Use application-core contract rather than assuming that every auth-layout form is native-ready. MFA challenge/error pages remain excluded |
| layouts.guest for an eligible receipt destination | Sentinel after static content/toast and before optional scripts; use native-document readiness for the audited read-only receipt view |

For native login readiness, Gate 2 adds type=password while retaining :type. The optional reveal button starts disabled and Alpine enables it through :disabled=false; no-JS login retains working native inputs, submit, links, and CSRF. It does not depend on the reveal control. Turnstile remains an independent required interaction when configured; this plan never bypasses it.

At the end of app.js, after existing synchronous boot and DOM-ready registrations, register the core acknowledgement: after DOM-ready callbacks have run and Alpine.nextTick has settled, call window.AdasiAuthSplash?.markCoreReady(). Do not await calendar/lazy-import promises or request completion. The independent controller combines this with its DOMContentLoaded and layout-sentinel latches.

When the required facts first become true, request the next animation frame as a rendering opportunity and publish ready once. If already ready before activation, do not show the splash. If ready while waiting, cancel motion and release immediately at that milestone. A frame is not a guaranteed completed paint or an artificial duration. On return from a hidden document, recheck sticky facts/deadline before doing any animation work.

Set the controller's sticky ready flag before dispatching adasi:page-ready. Late consumers read the flag instead of relying only on the event. Late core initialization may acknowledge its work but can never transition a released/removed splash back to waiting. A hard-timeout/failure exit is labelled degraded fail-open, not healthy application readiness.

The bridge is window.AdasiAuthSplash with read-only state and ready getters, markCoreReady(), isWaiting(), hasVisual(), and removeVisual(exit). removeVisual() is the loader's idempotent post-release visual cleanup; it must not bypass WAITING. Keep activation/replay/internal failure handling private. Publish adasi:auth-splash-released only after interaction restoration, and adasi:auth-splash-hidden only after actual removal. Event payloads contain generic layout/reason/exit values only. No existing HTTP route, controller signature, model API, or loader token API changes.

### Authentication sequences

1. **Direct login:** clean document GET -> request eligibility -> conditional head bootstrap/component -> native form sentinel -> native-login ready -> cancel wave/release -> approved fade.
2. **Normal login:** existing credential verification -> CompleteLoginService finishes existing operations -> issue marker -> existing safe redirect -> follow target redirects -> final eligible layout acknowledges rendering -> consume marker -> application/native readiness -> release.
3. **MFA:** password acceptance -> existing challenge with no success marker -> valid TOTP/recovery verification -> CompleteLoginService -> same post-login sequence. Failed/replayed/expired/throttled attempts never issue success state.
4. **Logout:** CSRF-protected POST -> guard logout -> invalidate session -> regenerate CSRF -> issue post-logout marker in new session -> login document -> native-login readiness.
5. **Password/context redirect:** marker follows /dashboard -> profile.security or supplier context selector; show once on that page and do not wait for password change/context submission.
6. **Ordinary navigation:** no marker/component -> existing AdasiLoader tracks outgoing navigation/server work -> normal destination, with no auth splash.

## 5. Exact Gate 2 file changes

Paths are repository-relative. Add the described behavior to existing files without overwriting unrelated hunks.

| File | Planned change |
|---|---|
| config/ui.php — new | Flag false; TTL 30; deadline 10000; skip 2000; fade 650; cleanup 750 |
| .env.example | Document AUTH_SPLASH_ENABLED=false; do not edit actual .env during implementation |
| app/Services/Auth/CompleteLoginService.php | Issue generic post-login marker at END of successful complete(), after existing device/session/audit/notification work, and only when enabled |
| app/Http/Controllers/Auth/AuthenticatedSessionController.php | Issue post-logout marker after invalidate()/regenerateToken(), before the existing redirect |
| app/Http/Middleware/ShareAuthSplashState.php — new | Document eligibility, marker validation, redirect target binding, render acknowledgement and consumption |
| bootstrap/app.php | Import/register middleware immediately after ApplyUserLocale, before session-security/password-change middleware; link its priority after locale without changing existing security ordering |
| resources/views/components/ui/auth-splash.blade.php — new | One approved wordmark component, hidden by default, focus panel, separate status and skip control; acknowledge actual rendering |
| resources/views/partials/auth-splash-bootstrap.blade.php — new | Shared candidate/error guard, bounded static source reads, inline scoped styles/classic controller, generic configuration; no output if disabled/source unavailable |
| resources/views/layouts/auth.blade.php | Early head include; first-body component; login sentinel after content/toast and before CDN scripts; core-mode sentinel after scripts for other eligible destinations; suppress both hooks when $exception exists |
| resources/views/layouts/app.blade.php | Early head include before preference script; first-body component; core-mode sentinel after @stack('scripts'); preserve body Alpine shell and selectors |
| resources/views/layouts/guest.blade.php | Conditional early head/body hooks and native receipt sentinel; ordinary public receipt access gets no splash |
| resources/views/auth/login.blade.php | Native type=password plus unchanged :type; progressive enhancement of disabled reveal button; retain form method/action/CSRF/names/validation |
| resources/css/auth-splash.css — new | Exact namespaced prototype CSS plus hidden/expiry/accessibility rules; inline only through shared partial |
| resources/js/auth-splash.js — new | Standalone IIFE: animation, readiness latches, independent watchdog, interaction ownership, fade/removal, bfcache/view-transition cleanup |
| resources/js/app.js | Add core-ready acknowledgement after current startup registrations; DO NOT import or boot a second splash controller |
| resources/js/adasi-loader.js | Suppress only DOM presentation during waiting; reconcile on release; remove remaining splash visual before foreground loader presentation |
| lang/en/auth.php and lang/id/auth.php | Matching splash.loading and splash.skip keys; component renders escaped translated text |
| tests/Feature/Auth/AuthSplashTest.php — new | Trigger/marker/redirect/exclusion tests |
| tests/Feature/FrontendAssetLoadingTest.php | Conditional bootstrap/sentinel/source delivery and loader-logo asset contracts |
| tests/js/auth-splash.test.mjs — new | Execute identical inline source via VM; lifecycle/readiness/interaction/timer tests |
| tests/js/adasi-loader.test.mjs | Overlap, late activation, token preservation, and handoff tests |
| tests/Support/auth-splash-browser-check.mjs — new | Existing dependency-free CDP pattern; rendered fixtures and isolated test authentication coverage |

No edits to vite.config.js, package manifests/lockfiles, main app.css, database/schema, or authentication verification algorithms are required. The earlier draft's Vite splash import and main CSS import are superseded by the single independent conditional delivery above.

## 6. Visual contract and lifecycle state machine

### Frozen visual behavior

Extract only splash markup/styles and wave functions. Exclude preview dashboard, replay control, global body/reset rules, and global color-scheme changes.

- White #fff full-screen surface, red #ed1018 wordmark, original flat masking plane, z-index 9999.
- Wordmark height 1.14em; Arial Black / Segoe UI / Arial / sans-serif; weight 900; line-height 1.08; letter-spacing -.065em; right padding .07em; top padding .05em.
- Desktop font-size clamp(2rem, 8vw, 4.5rem); at width <=480 px, clamp(3.1rem, 15vw, 4.4rem).
- Original splash-inner width/padding, overflow clipping, surface-plane geometry, and skip-button styling. Scope explicit font/box-sizing rules so surrounding Inter/Bootstrap/theme styling cannot alter them.
- Transforms 130% -> 0 -> 130%, ease-in-out, no overshoot/bounce. Both directions follow A -> D -> A -> S -> I.

| Timing | Value |
|---|---:|
| Entry delay | 50 ms |
| Letter stagger | 90 ms |
| Rise duration | 1600 ms |
| Fully visible hold after last rise | 300 ms |
| Sink duration | 1600 ms |
| Pause after last sink | 100 ms |
| Normal fade | 650 ms |
| Rise starts | 50 / 140 / 230 / 320 / 410 ms |
| Sink starts | 2310 / 2400 / 2490 / 2580 / 2670 ms |
| Next cycle starts | 4370 ms |

The user agreed to preserve the normal 650 ms fade while cancelling letter motion and releasing interaction immediately. No cycle or minimum duration is required. Emergency exits and active user/loader handoff may remove the cosmetic fade early.

| State | Meaning / allowed transition |
|---|---|
| ABSENT | No server eligibility; no bootstrap/component work |
| ARMED | Head safeguards active; component not yet mounted; may become WAITING, RELEASED, or REMOVED |
| WAITING | Component visible; wave/static reduced-motion wordmark; temporary interaction ownership |
| RELEASED | No splash interaction restrictions; optional cosmetic 650 ms fade; only REMOVED follows |
| REMOVED | Terminal; cannot rearm or replay even if later scripts/events arrive |

At ready, clear wave timers, increment run ID, capture/freeze current computed letter transforms, cancel their CSS animations/classes, and prevent future cycles. Restore interaction in the same release call. Start the normal opacity/visibility fade only afterward. Finish on the overlay's own transition completion or the independent 750 ms cleanup fallback.

Reduced motion shows all letters statically without wave/fade. A runtime change cancels only wave work, never watchdog/skip/deadline state. Returning to normal motion may resume a fresh cycle only while WAITING.

## 7. Session marker, consumption, and redirect edge cases

Session ui.auth_splash contains an opaque cosmetic ID, allowed reason (post-login/post-logout), instant issued_at, and server-only target path/query. It is ordinary session data, not flash. The ID only distinguishes replacement markers; it is not an authentication token and never enters HTML.

1. Completion/logout writes only when enabled. Middleware binds newly issued markers to the actual successful same-origin redirect Location after $next; no new request parameter or redirect behavior.
2. Accept a document GET only: not AJAX/JSON/server tabs, not HEAD, and not a non-document Fetch Metadata destination/mode when supplied. With absent Fetch Metadata, use route/request checks; never require those headers for older browsers.
3. Compare normalized origin/path/query, excluding fragments. Preserve path encoding/query order consistently; do not reinterpret query data or store client-provided destinations as authorization.
4. On a matching target redirect, update target from its actual same-origin Location and keep original issued_at.
5. Require current authentication for post-login, guest login route for post-logout, and no pending MFA challenge. Validate marker shape, ID/reason/timestamp; reject future time and age >=30 s.
6. The head guard and body component both reject error-layout $exception. The body component acknowledges ui.auth_splash_rendered only when markup actually exists. Final 200 HTML + acknowledgement consumes the marker before StartSession saves.
7. Terminal target error/download/JSON/unsupported-layout responses discard the marker without splash. Unrelated document navigation clears it; background requests ignore it and never consume it.
8. AJAX/JSON login/logout submissions do not leave markers for later navigation. New-marker binding is limited to the existing successful document-navigation actions.
9. Re-read session state after $next and compare the current marker ID before updating/deleting. Never resurrect a captured marker after security middleware invalidation or overwrite a newer marker.
10. Feature disabled: forget splash marker, emit no markup/styles/controller, preserve authentication behavior. Re-enabling cannot revive old splash state.
11. Clean login eligibility is request-only and repeats on refresh. Suppress on nonempty errors bag or status/warning/error feedback. An app destination's mandatory-password warning does not suppress successful post-login there.
12. If a request throws outside the normal response path, clear the matching splash marker in exception cleanup and rethrow; do not change the exception response or auth state.

Place ShareAuthSplashState outside EnforceAuthSessionSecurity and EnforcePasswordChange so it observes early redirects. Post-response checks alone cannot prevent markup on already-rendered error layouts; the pre-render $exception guard is mandatory.

The user accepted sequential one-time behavior with a bounded cosmetic concurrency limitation. Same-session requests can read the same snapshot, and delayed session saves may duplicate/resurrect a marker. Target binding and TTL constrain this; do not promise strict cross-tab exactly-once or add session blocking/cache claims. [Laravel documents flash lifecycle and concurrent session behavior](https://laravel.com/framework/docs/12.x/session).

## 8. Existing AdasiLoader coordination

Keep createLoaderController token semantics, public show/hide/reset/trackNavigation methods, normal delays, AJAX exclusions, DataTable indicators, and bfcache reset.

Add a presentation gate in createDomView:

- On WAITING, record desired active/slow presentation but do not reveal the loader, announce status, move focus, or change inert/body aria-busy.
- Deactivation while suppressed clears its desired presentation without touching splash-owned state. It must not restore focus or clear inert it never acquired.
- On splash release, restore splash-owned interaction first. If the loader is logically active with pending work, immediately remove the remaining cosmetic splash and present the existing loader.
- If a foreground loader becomes active during the fade, remove the splash visual before presenting it. If its show-delay timer has not fired, retain that delay; do not activate early.
- Requests completed while suppressed cause no loader flash. Preserve every token and its owning completion handler; never reset the loader as splash cleanup.
- The loader overlay is exempt from the splash's content-root inert snapshot because its presentation is explicitly suppressed. It cannot become focusable/announced until the handoff.

When no auth splash exists, the presentation gate is a no-op. Native login may release before app.js loads; later loader initialization operates normally and cannot restart the splash. With failed app.js, the splash cannot supply the absent loader's ordinary-navigation functionality; native browser navigation still works.

## 9. Single fail-open interaction and accessibility model

The selected model is **temporary JS-owned blocking only while WAITING, followed by an interaction-free cosmetic fade**. There is no second controller, permanent server-side inert, or independent focus owner.

### Activation and ownership

- SSR emits the component with hidden and aria-hidden=true. Real page content has no splash-specific inert, aria-hidden, hidden workspace, or scroll lock.
- Before activation, the independent controller verifies its scoped inline styles through a CSS-ready custom property and checks required APIs. If unavailable, remain hidden and fail open; do not install polyfills.
- Arm the watchdog and cleanup first. Mount only after five letters, focus panel, status, and skip button are present. If ready/expired before mount, remove or retain hidden without a visible frame.
- Snapshot prior inert and aria-busy attributes for direct body content roots. Cover auth shell, app skip/sidebar/main roots, guest content, toast/drawer/lightbox siblings, and new direct content roots inserted while waiting. Exclude splash, loader overlay, script/style/link/template nodes.
- Set inert and aria-busy on those roots only. Do not set aria-hidden on page content or change body aria-busy; this avoids redundant hiding and ownership collisions with the existing loader.
- Focus the splash panel with preventScroll; preserve a valid return target and native autofocus intent. Contain Tab while waiting, with Escape always available. Show a keyboard-accessible skip button after 2 s.
- Use separate stable translated role=status / polite / atomic text; hide decorative letters from assistive technology. Keep controls outside the live status node so focus and live announcements do not compete.

The inert attribute removes content from interaction and focus/assistive-technology navigation, so its cleanup must restore previous values rather than setting every node to false. See [MDN inert](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Global_attributes/inert).

### Release and fade

- Release removes only restrictions acquired by the splash, disconnects its content observer, and restores exact prior state before any loader handoff.
- Hide/disable skip, silence/hide the splash from assistive technology, and set pointer-events:none. Restore previous connected focus or login email / eligible #main-content if focus still belongs to splash; never steal focus already moved by the user.
- An internal focus-restoration guard prevents that restoration from cancelling the normal fade. During fading, a user's pointer/keyboard input or subsequent external focus movement removes the remaining visual synchronously before its normal action. Thus interaction is never directed at controls still visually concealed by a white overlay.
- Skip/Escape/failure/bfcache remove immediately. No splash focus trap or inert survives RELEASED.
- If a foreground loader takes over, its own existing blocking/focus behavior begins only after splash cleanup/removal.

The approved 650 ms fade remains the normal visual exit when uninterrupted; it is not a minimum availability delay. The splash's pure white surface and red wordmark remain independent of light/dark themes.

## 10. Independent watchdog, CSP, and asset-failure behavior

The head controller arms its own captured native timeout before observer/animation setup or assets. Keep safety, skip, wave, readiness-frame, and fade timers separately owned. Clearing a wave or changing motion preference must not clear the watchdog. Catch setup/observer/animation callback errors locally and call idempotent emergency cleanup.

The hard watchdog remains armed through RELEASED until visual removal completes; readiness does not cancel the deadline. Cleanup restores each owned root independently and finishes removal in a finally path so an error on one disconnected node cannot strand the others.

At the absolute deadline, emergency cleanup removes the visual, cancels wave/frame work, restores all owned interaction, and emits a hard-timeout exit. If body parsing/asset startup is delayed past the deadline, later inserted markup stays hidden/removed. Check elapsed deadline on visibility/pageshow and before every mount/restart; never grant a fresh 10 s on late initialization.

Use a scoped CSS visibility/pointer expiry as a second safety net, set to the remaining absolute deadline on activation. Its animationend also invokes the same JS cleanup. Reduced-motion rules must not disable this safety animation. CSS alone cannot undo inert and is not represented as a complete recovery mechanism; the independently armed JS cleanup is required.

| Failure / edge case | Required result |
|---|---|
| JS disabled or inline script blocked by CSP | SSR hidden overlay stays hidden; no inert/focus changes |
| Inline styles blocked but controller runs | CSS-ready check fails; no activation |
| Inline source file missing/unreadable | Omit bootstrap/component; no server 500 solely for splash |
| app.js missing, 404, import error, or indefinitely delayed | Login still releases at native form sentinel; application destinations fail open by independent deadline |
| app.css/CDN styles fail or hang | Splash has its own styles; native login sentinel is not a deferred script; deadline remains armed |
| Optional chart/image/font/Turnstile/polling request hangs | Does not extend readiness or deadline |
| Early readiness before mount | No forced visible splash or cycle |
| Duplicate/late initialization | Reuse terminal state; no duplicate timers/overlay/observer |
| Controller callback error after inert acquisition | Independent cleanup restores all roots; no permanently invisible inert page |
| transitionend absent/interrupted | 750 ms removal fallback, or earlier hard deadline |
| Initial pageshow followed by persisted pageshow | Persistent listener handles restoration; no replay |
| pagehide / supported pageswap | Immediate cleanup; skip only a live splash's affected view transition |

Current CSP permits inline scripts/styles; the standalone runtime needs no eval or new origin. Do not weaken CSP or add exceptions. If deployment uses stricter policy, blocked inline content must remain fail-open; enabling the splash under a nonce-only policy requires separately verified nonce propagation. Current dynamic HTML private/no-store protection and static asset policy are preserved.

A browser that is frozen, suspended, or unable to execute/render cannot meet a wall-clock JS deadline. On resumption, release before continuing animation. Fail-open exposes the existing page; it does not claim to repair missing application assets or existing JS-only toast feedback.

## 11. Tests and acceptance criteria

### PHP coverage

Add AuthSplashTest with behavior-level assertions for:

- Clean login access/refresh; feedback/error suppression; disabled-default behavior.
- Valid non-MFA login, valid TOTP/recovery completion, and absence for invalid/replayed/expired/throttled authentication.
- Existing logout security order, guest state, new-session marker, one-time post-logout and subsequent clean login-page refresh.
- /dashboard redirects, every role destination, dual-scope chooser, mandatory-password destination, safe intended page and blocked external/polling intended destinations.
- Actual component acknowledgement and final HTML, not only a marker assertion.
- TTL boundary at 30 s, malformed/future markers, replaced marker IDs, session invalidation during $next, unrelated targets, cross-origin redirect rejection, and exception cleanup.
- HEAD/XHR/JSON/server tabs/downloads/non-layout/error responses; no false auth splash or later stale replay.
- Source unavailable, feature disable/re-enable, no sensitive marker/target/user/session values in splash markup.
- Both layout readiness modes and sentinel placement; native password fallback; three existing Vite entries and loader-logo-before-Vite retained.

Retain AuthenticationTest, TwoFactorAuthenticationTest, LoginSecurityTest, SessionSecurityTest, AuthRateLimitingTest, AdminCredentialChangeTest, SecurityHeadersTest, FrontendAssetLoadingTest, and translation parity/content coverage.

### JavaScript coverage

Use fake timers/DOM and the same classic source included by Blade:

- Exact five-letter rise/sink ordering and timings; 4370 ms loop; no bounce; frozen cancellation during first rise/mid-sink.
- Auth native sentinel releases with NO app.js/core signal and while DOMContentLoaded remains pending.
- App requires parser sentinel + DOM-ready + core acknowledgement; late/early order permutations give exactly one readiness publication.
- Sticky readiness before mount; fast-ready no display; no minimum duration/full-cycle wait.
- Independent deadline before body exists, late module/sentinel after timeout, source setup/callback failure, and duplicate boot.
- Reduced motion initially and toggled both ways; safety timers survive.
- Exact inert/busy restoration including preexisting true values; added roots, focus containment/restoration, skip/Escape, no body aria-busy mutation.
- Cosmetic fade user-input removal, internal restoration exemption, deadline interrupt, transition event filtering and fallback.
- Loader suppression/activation/deactivation while waiting; completed and pending tokens; show-delay during fade; focus/inert/busy handoff without two visible overlays.
- Initial pageshow then bfcache pageshow, pagehide/pageswap, hidden-tab deadline, terminal cleanup of observers/listeners/timers.

### Browser and accessibility coverage

Reuse existing dependency-free Chrome/Edge CDP patterns against Laravel-rendered fixtures and an isolated test environment. Do not add Playwright/axe/Lighthouse packages. Fixture inspection proves frontend behavior only; test real login/MFA/logout separately using disposable test data.

- Block app.js entirely, delay it indefinitely, and fail a static import: login form must become usable at native readiness, not wait 10 s. App-layout destination must expose degraded content by the hard deadline.
- Hang stylesheet/CDN and optional requests; test CSP script-only/style-only/both blocking and JavaScript disabled.
- Confirm credentials and logout POSTs stay CSRF-protected; failed authentication/MFA does not show success splash.
- Confirm ordinary dashboard refresh/navigation/AJAX/DataTables/downloads never trigger auth splash and existing loader feedback remains intact.
- Compare approved prototype at deterministic animation offsets during test-controlled delayed readiness; check mobile <=480 px, desktop, dark mode, narrow/short screens, and 200%/400% zoom.
- Keyboard and screen reader: stable status, no individual letter announcements, accessible skip/Escape, no background focus while waiting, visible controls at restored focus, and no leftover inert/busy state.
- Restore from bfcache and exercise existing view transitions; no historical splash replay.

A hot page can finish before the splash paints; that is valid. Delayed readiness used for visual inspection belongs only to tests, never a production minimum.

### Gate 2 command sequence

Before DB-backed tests, verify configuration/cached configuration cannot point at application data, then run TestingEnvironmentDatabaseSafetyTest. Run all MySQL adasi_portal_test suites serially; never run RefreshDatabase suites concurrently.

~~~powershell
php artisan test --filter=TestingEnvironmentDatabaseSafetyTest
php artisan test tests/Feature/Auth --compact
php artisan test --filter=FrontendAssetLoadingTest
php artisan test --filter=TranslationParityTest
php artisan test --filter=TranslationContentTest
node --test --test-isolation=none tests/js/auth-splash.test.mjs tests/js/adasi-loader.test.mjs
~~~

Also execute PHP syntax checks and scoped Pint --test on changed PHP, view:cache, relevant route:list, npm.cmd run build, and scoped git diff --check. Do not install static-analysis packages or run migrations/queue work as verification. Browser/manual/staging results must be reported separately.

## 12. Performance, risks, dependencies, and rollback

### Performance acceptance

- Zero new splash HTTP requests, endpoints, polling, packages, migrations, or business queries. Two bounded local source reads only on eligible splash documents.
- Incremental compressed standalone JS <=5 KB and scoped CSS <=3 KB; measure actual bytes. Do not inline the application bundle.
- Transform-based five-letter motion; no recurring layout properties, extra image/font fetch, or splash-attributable CLS.
- Ready cancels wave and releases interaction in the same callback. Normal fade/removal completes within 650/750 ms after release; emergency/interaction/foreground-loader handoff removes sooner.
- Independent deadline applies from head execution, including delayed body/assets; no deadline reset.
- No new splash-attributable long task. Compare repeated before/after login and representative supplier/finance/purchasing page measurements under the same desktop/mobile/network/CPU conditions; investigate any consistent >5% regression in median core-ready or LCP before enabling.
- Mark bootstrap-start, visible-start, ready, interaction-released, and actual-hidden separately. Generic reason/exit/layout/reduced-motion/duration only; no identity, URL, session, or MFA data. Telemetry never gates UI.

### Risks and dependencies

Preserve the existing dirty security/asset work. Recheck reference hashes and integration anchors at implementation time. Source availability, actual deployed CSP/cache headers, browser support, assets, and authentication/test environment access are release dependencies. The accepted same-session concurrency limitation remains cosmetic. The native password fallback is required for the independent login readiness claim.

Do not add a global overlay framework, session locking, packages, or analytics endpoints to address these risks.

### Rollback

Set AUTH_SPLASH_ENABLED=false through the existing deployment workflow and refresh configuration cache. Verify clean login, valid login/MFA, and logout without splash; disabled requests clear stale splash state only. Optional core acknowledgement/loader gating become no-ops.

Keep the previous compiled release available. A full code rollback uses a reviewed splash-specific reverse patch, preserving unrelated work. No DB rollback, queue change, or authentication-model migration is needed. Deployment/rollback execution is not authorized by Gate 1.

## 13. Gate 2 execution checklist

- [ ] Obtain explicit Gate 2 approval; recheck instructions, references, baseline and dirty hunks.
- [ ] Add failing server, standalone-controller, readiness, loader-overlap and native-fallback tests.
- [ ] Implement flag-off config, ordinary marker, target-following middleware and acknowledged conditional rendering.
- [ ] Implement the single independent head controller/styles and exact approved wordmark.
- [ ] Add auth/guest native sentinels and app core/sentinel readiness; test login with app.js absent.
- [ ] Implement one temporary interaction owner, safeguards, reduced motion, fade/input/loader handoff.
- [ ] Add native password/reveal enhancement and matching EN/ID labels.
- [ ] Run serial PHP tests, Node tests, lint, translations, view compilation, route inspection, build and scoped diff checks.
- [ ] Complete browser authentication, visual, accessibility, bfcache, CSP and asset-failure scenarios.
- [ ] Record performance budgets and verify feature-flag rollback in an appropriate environment.
- [ ] Present exact scoped changes and distinguish executed, inspected, inferred and unverified results.

Gate 2 is local implementation approval only. Git staging/commit/push, deployment, and production activation require their own authorization.

## 14. Decisions, unresolved items, and final gate

Locked decisions: available local HTML is final; normal fade remains 650 ms with immediate cancellation/interaction release; marker TTL is 30 s; feature is disabled by default; sequential session semantics and bounded cross-tab cosmetic risk are accepted.

This finalized plan replaces the earlier draft's Vite-dependent splash controller and mixed interaction ownership. It preserves the completed audit findings and existing authentication behavior while specifying independent native-login readiness, explicit app-core readiness, and one fail-open interaction lifecycle.

No unresolved implementation-design choice remains. Gate 2 approval is pending. Runtime tests/browser/performance/staging/deployment checks are future verification obligations, not evidence already obtained.

**Final status: PENDING USER APPROVAL FOR GATE 2**
