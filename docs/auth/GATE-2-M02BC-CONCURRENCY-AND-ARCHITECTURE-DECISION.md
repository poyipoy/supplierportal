# ADASI — Gate 2 M02BC: Concurrency and Architecture Decision

Date: 19 October 2026. Combined M02B/M02C scope.

**M02BC REVISE — ARCHITECTURE DECISION PENDING**

The approved concurrency/regression execution is complete. M02A remains preserved. Request-local correctness and identical-proof replay checks pass, but stronger lifecycle/consistency properties have 33 recorded FAIL assertions. Independent attempt authority is justified for one-claim/terminal requirements; the loss-safe session/cookie/acknowledgement contract is not yet ready. **M03 full-flow implementation is BLOCKED by the specific design gates in section 9**, rather than by missing testing access. No M03 implementation or deployment is authorized. This one consolidated report replaces the proposed split M02B/M02C decision work without amending the historical inputs or adding another mission sequence.

## 1. Verified baseline and M02A preservation

Repository: `https://github.com/poyipoy/supplierportal`, branch **master only**. HEAD, local master, tracking `origin/master`, and live `git ls-remote origin refs/heads/master` matched **317dc0092bc8e08a8eeaca40803a7b327877b88d** before testing. No newer master was observed at initialization. The tested source is that pinned archive plus the existing M02A patch; it is not an unmodified master test result.

Initial working tree: eight modified production files, the untracked focused M02A test and three untracked auth documents. All twelve files were copied and SHA-256 frozen before testing. The nine runtime/test hashes match the M02A execution manifest in its independently retained fixture. The M02A report's 210 cases / 42,231 assertions are historical evidence; they are not counted as newly executed M02BC tests.

`AGENTS.md`, `CLAUDE.md`, the Laravel reasoning framework, all three auth inputs and the Post-M01 Addendum were read. No more specific AGENTS file was found in the inspected auth/test/doc paths. Current source, callers, middleware order and framework hooks were reconciled with the reports. No checkout, stash, reset, merge, commit, push or dependency installation was performed. No M02A hunk, existing test or existing document was amended.

Preservation manifest and original diff: `<fixture>/evidence/preservation/manifest.json` and `working-tree.diff`. Final byte comparisons and remote verification are recorded in section 14.

## 2. Disposable environment and admission

New workspace:

    C:\Users\BAHRIALGI\AppData\Local\Temp\adasi-m02bc-5786ef79c746

Private MySQL: **127.0.0.1:62929**, new datadir `<fixture>/db`, server UUID **8c53ccd9-cbc0-11f1-a9de-50ebf64ed7d2**. It is a different instance from both M01/M02A and the existing development database. The private schema is named `adasi_portal_test`; the application credential has DML only on that schema. Root is used by the trusted supervisor solely for private provisioning, identity verification, lock-wait observation and verified shutdown. No production/development database or secrets are used.

PHP 8.2.30, MySQL 8.0.30 and installed Laravel 12.69.3 dependencies were copied without downloading packages. Source is a pinned archive plus the preserved patch. No `.env` is copied. The auth-only fixture contains users, sessions, cache/cache_locks, auth audit/device tables, notifications/preferences, supplier scopes, an empty supplier relationship table and reset-token table. It is not a complete portal schema. No application migrations or seeders run.

Each execution batch independently verifies port/datadir/UUID, then all twelve M01 admission probes: outside-canary read/write denied; source write denied before/after chmod; designated storage writable; no source `.env`; execution and generic network helpers disabled; exact private database identity; other-schema query denied. The Laravel connector rejects a development-port configuration before PDO opens a connection. Workers start only after Windows Job admission.

Controls retained: explicit empty environment allowlist, read/execute source/tool DACL, writable fixture storage/bootstrap cache, PHP `open_basedir`, inert/fake HTTP, array mail, null broadcast and synthetic keys/users. CPU hard cap 25%, ten active processes, 512 MiB per-process / 1536 MiB job committed-memory limits, 45 seconds user CPU per process, PHP wall bound 50 seconds, supervisor wall bound 55 seconds per worker and 900 seconds per batch. Scratch growth is monitored at 64 MiB over the batch baseline, not an OS hard quota.

These are trusted-code regression controls, not VM/AppContainer or hostile-code network isolation certification. Generic PHP guards do not prohibit every native networking API. MySQL monitor/child containment is not assumed: port/datadir/UUID and actual PID identify the owned service before shutdown. The pre-existing Laragon MySQL processes are not stopped or queried.

## 3. Test method and classification

Real Laravel HTTP Kernel requests run in independent PHP processes with real FileSessionHandler / DatabaseSessionHandler, encrypted response cookies and forced-active CSRF. Process barriers pause after session load, before the locked verifier, after proof commit, before final admission, after the final SELECT, and after cap inventory SELECT. Mutators use separate connections/processes; `performance_schema.data_lock_waits` on the private instance confirms actual user-row lock waiting before release. PID/time gate files provide overlap evidence. Sleeps poll signals; they are not used as evidence of overlap.

Response-cookie orders are applied to separate cookie-jar copies, followed by independent requests. This measures server/HTTP cookie semantics, not real-browser TCP ordering, bfcache, autofill, accessibility or visual splash timing. Already-issued response delivery is explicitly separate from in-boundary overlap.

**PASS** means the stated assertion held. **FAIL** means the stated security/consistency property did not hold; a reproduced known limitation is not relabeled a security PASS. Boundary/fault characterization and mechanism-only experiments are tagged separately. A hypothetical Login Transaction Flow test is never counted as PASS. PHPUnit test/provider cases, supervisor assertion records and raw diagnostic failures are separate count units.

Temporary instrumentation wraps existing services and handlers; it does not edit production source. A handler/header experiment is explicitly a native-extension feasibility check, not a Login Transaction Flow implementation. Per-batch harness snapshots, failed attempts, interrupted progress, logs, barriers and results remain under the fixture.

## 4. Executed test register

The execution does not repeat the whole M01 characterization. Each batch answers a changed-boundary or remaining-concurrency hypothesis. Final logical supervisor checks: **97 records — 64 PASS / 33 FAIL**, comprising **96 focused lifecycle/concurrency/mechanism checks (63 PASS / 33 FAIL)** and **one setup smoke check PASS**. These are assertions over schedules, not 97 independent vulnerabilities or PHPUnit cases. Some assertions intentionally test stronger required properties which the preserved M02A design does not supply; their FAIL status is retained.

| Executed family | Checks | PASS | FAIL | Interpretation |
|---|---:|---:|---:|---|
| Current-auth rejection, guest replacement, late writer, cookie orders and logout; both drivers | 20 | 7 | 13 | Demonstrates request-local fixes and residual context/driver/transport limits. |
| Eligibility/configuration: row-lock and final-admission mutation; password-to-MFA revision; post-read version mutation | 28 | 25 | 3 | 24 intended-boundary rejections PASS; 3 cross-request revision protections FAIL; final-read/next-request revocation characterization PASS. |
| Native blocking, same/different SID, distinct proofs, bounded wait, lease expiry, participating logout | 14 | 11 | 3 | Four session/cache combinations checked; two cross-SID uniqueness FAILs and one lease diagnostic FAIL. |
| Completion faults, false-write acknowledgement, distinct-code overlap, cap and concurrent limiter | 22 | 12 | 10 | Distinguishes consumed proof, actual next-request auth, pending, audit, cookie and cap state. |
| Native handler/header seam, normal and partial-marker-loss; both drivers | 6 | 4 | 2 | Mechanism feasibility only; does not implement/certify a flow. |
| Overlapping identical TOTP/recovery proof; both drivers | 4 | 4 | 0 | Existing row-locked proof one-use preserved. |
| Same authenticated SID late writer with deliberately seeded obsolete pending | 2 | 0 | 2 | Both drivers can re-add pending when the row/SID remains live; current account and unused proof are retained. |
| **Focused subtotal** | **96** | **63** | **33** | No FAIL reclassified as security PASS. |
| Smoke control: real Kernel MFA | 1 | 1 | 0 | Fixture/bootstrap control, not additional concurrency coverage. |

The same-SID obsolete-pending case deliberately seeds a legacy/obsolete state; it does not show that a clean M02A login itself creates that state. It demonstrates a cleanup-versus-live-row late-update limitation, not an authenticated account switch. The database deleted-row result must not be generalized to all database writes.

### Exact completed phase artifacts

Under `<fixture>/evidence/`:

- `results-smoke-f00be65a.json` — 1 control record.
- `results-overlaps-21d84445.json` — 20 records.
- `results-eligibility-1888d587.json` — 13 completed records before an empty-event-map harness error.
- `progress-eligibility-tail-0b57602b.json` plus `interrupted-eligibility-tail-0b57602b.json` — 5 completed PASS records before the supervisor interruption.
- `results-eligibility-tail2-8f0ea34a.json` — 10 remaining records. The three eligibility artifacts combine to 28 distinct cases.
- `results-native-80e8e0fb.json` — 11 raw records; 4 initial probe-lock records are excluded as invalid setup, leaving 7 valid records. The previously static application-route blocking tests remain valid.
- `results-native-tail-7ee787bd.json` — 1 completed lease diagnostic; the following probe barrier failed and was corrected.
- `results-probe-locks-a40593cd.json` — 6 corrected native probe/different-SID/logout records, with explicit probe blocking and lock activity verified. Native family total: 14 valid logical records.
- `results-faults-260af2e1.json` — 22 records.
- `results-mechanisms-01ce2f7c.json` — 6 mechanism-only records.
- `results-replay-d371b6f5.json` — 4 records.
- `results-authenticated-writer-ceb25ca9.json` — 2 live-SID late-writer records.

The two failed probe setups and the short-lease barrier miss are harness failures, not evidence of a security PASS/FAIL on an unexecuted assertion. A resumed eight-second lease diagnostic actually observed a blocked waiter, lease expiry and two subsequent guard completions; it is a fixture-only lease stress test, not a production threshold change.

### Focused regression and GC results

Newly executed ordinary PHPUnit coverage, on the independently admitted clean fixture:

| Suite | Cases | Assertions | Result |
|---|---:|---:|---|
| TargetedAuthenticationCorrectnessTest | 75 | 694 | PASS |
| AuthenticationTest | 8 | 29 | PASS |
| TwoFactorAuthenticationTest | 15 | 99 | PASS |
| LoginSecurityTest | 23 | 138 | PASS |
| SessionSecurityTest | 25 | 167 | PASS under unchanged default lottery |
| KnownDeviceSecurityTest | 12 | 59 | PASS |
| AuthAuditSecurityTest: strict metadata allowlist | 1 | 4 | PASS |
| AuthSplashTest: selected failure/MFA/session/pending coverage | 6 | 49 | PASS |
| **Ordinary clean regression total** | **165** | **1,239** | **165 PASS, 0 FAIL/errors** |

This is 75 dedicated M02A cases plus 90 selected existing compatibility cases, newly executed here. The historical M02A 210 cases / 42,231 assertions are not added. Translation/browser/full-dashboard tests are not implicitly included. The selected methods and provider cases are enumerated in the JUnit manifest. Existing thresholds and application/test source remain unchanged.

Two additional executions of the unchanged expired-row test are **diagnostics**, not an alternate security acceptance mode:

| Fixture-only GC control | Cases | Assertions | Existing test result | Causal evidence |
|---|---:|---:|---|---|
| Never collect (`[0,100]`) | 1 | 6 | PASS | Expired row remains; active slots unaffected. |
| Always collect (`[100,100]`) | 1 | 5 | **FAIL retained** | Actual `DELETE FROM sessions WHERE last_activity <= ?` logged from the same PHP PID; active rows remain, expired row disappears. |

Both controls are confined to the fixture-only TestCase and the named diagnostic worker. Production lottery/lifetime/cap are unchanged. This reproduces the test's nondeterministic assumption: native GC is allowed to delete an expired row, so requiring that row to remain is not the cap invariant. The original M02A failing request was not instrumented; this is causal reproduction of the mechanism, not retrospective proof of its exact SQL. Correcting the regression oracle belongs to an approved implementation/test change, not to silently forcing GC off for the ordinary suite.

Initial regression preconditions also failed: earlier synthetic concurrency rows contaminated whole-table count assertions. Before stopping that run, **70 completed JUnit case executions recorded 42 PASS / 28 FAIL and 599 assertions**. They are retained in `evidence/regression-precondition-contamination/`, with one empty/incomplete XML explicitly uncounted. The synthetic dataset was backed up to `synthetic-concurrency-data-before-cleanup.sql`; after private port/datadir/UUID verification, only this owned fixture was emptied using its DML-only account. Fresh user/audit/device counts were asserted zero before the clean run. No application migration/TRUNCATE, development database access, code change, weakened assertion or consumed-proof restoration was used.

Thus raw **completed JUnit executions** across the initial contaminated attempt, final ordinary suite and GC diagnostics total **237 records / 1,849 assertions: 208 PASS / 29 FAIL**. They include reruns and must not be called 237 unique acceptance cases. Final ordinary regression is 165/165; the 28 invalid-precondition failures and one causal GC diagnostic failure are not erased. The separate 97 supervisor assertions are a different count unit.

**BLOCKED:** no remaining concurrency execution prerequisite after admission recovery. **NOT EXECUTED:** Login Transaction Flow feature tests, production/staging/multi-host verification, real browser delivery/back/bfcache/accessibility, true process-crash durability, complete portal domain suites, M03 implementation and M04 release checks. Those are not implied by a Kernel fixture PASS. Critical M03 design blockers are listed separately in section 9.

## 5. Confirmed findings and driver differences

The observations below are new M02BC executions on the preserved M02A source. They do not assign production exploit frequency or prove these conditions occur under a particular deployment.

| Finding | Observed boundary | Meaning |
|---|---|---|
| F01 guest replacement | Valid password B replaces pending A while guest; A recovery proof does not authenticate B. | Intentional M02A policy; no anonymous MFA bypass. Strict form context would require an explicit additional contract. |
| F02/F03 authenticated request | Current authenticated C is retained, proof is not consumed, no verifier/second guard event, metadata retained. | M02A's request-local correction works when the request actually sees C. |
| F02L live-SID late update | With obsolete pending deliberately seeded in an authenticated session, a late reader rewrites that pending after challenge rejection on both database and file. C stays authenticated and the code remains unused. | Database avoids deleted-row recreation in one schedule; it is not an authority against overwriting a row that still exists. |
| F03G old guest snapshot | A request that loaded guest pending A before C login can later consume A's valid proof and complete A. Both server sessions remain; last applied cookie selects the browser account. | Guest checks are not cross-request completion authority. Database sessions alone do not prevent this. |
| F04 in-request boundaries | Concurrent mutation before the row-locked verifier rejects without consumption; mutation after proof but before final admission rejects guard login without restoring proof. | Freshness is enforced at the selected checks, not linearizable revocation against every later write. |
| F04R password-to-MFA revision | Version/password/MFA-secret changes between the password request and a later MFA request can be adopted by the later fresh snapshot. | Pending lacks a persistent revision of the originally accepted proof; this is the explicit M02A residual. |
| F05 late guest GET | Database late UPDATE on a deleted row does not recreate it in this schedule; file late write recreates pending, which can consume a different valid recovery code. | Driver observation, not a general database-session cancellation guarantee. |
| F05L logout overlap | In the tested file schedule, a previously authenticated GET rewrites the deleted session and its late cookie restores the logged-out identity. Database does not recreate its deleted row in this schedule. | Logout continuity/integrity limitation requiring attention independently of URL design. The request already possessed a legitimate authenticated context. |
| F06 response ordering | Both competing-account responses and old guest GET cookies can select a different SID or lose continuity. The winner's server session can remain intact. | State integrity, usable-session continuity and response delivery must be assessed separately. Server locks do not order browser delivery. |
| F07 completion failure | Before admission, consumed proof can coexist with retained pending. After guard, 500 can coexist with a usable authenticated next request. Handler `write=false` can leave a 302 success response without a persisted usable session. Distinct valid codes can complete concurrently. | No proof restoration was observed; neither global completion uniqueness nor whole-operation rollback is provided. |
| CAP concurrent admission | Two separate browser logins pause after reading the same two existing active sessions, then both complete; four SQL session rows remain under max=3. | New M02BC observation on the preserved patch. No threshold change; not attributed to M02A as an introduced regression without an origin runtime comparison. |

No supplier-data access, cross-role privilege escalation, stolen-cookie attack, production exploit, or MFA bypass without appropriate credentials/proof is claimed. Newly measured schedules are not called regressions introduced by M02A without an origin-versus-patch runtime comparison of that exact schedule.

## 6. Threat and invariant assessment

The evaluation retains the original Gate 1 contracts, including the in-boundary cookie part of I4. An option that does not satisfy them is marked open, not made compliant by narrowing the wording.

| Invariant | Current M02A assessment |
|---|---|
| I1: flow ID grants neither authentication nor MFA access without owner, CSRF and proof | No flow is implemented; full flow binding NOT IMPLEMENTED, not a vacuous PASS. Existing CSRF/proof controls remain. |
| I2: terminal transactions cannot return to CREATED/MFA_REQUIRED | OPEN: no independent terminal authority. Stale pending restoration is observed. |
| I3: at most one accepted successful completion claim per flow | NOT IMPLEMENTED as a formal flow; the stronger one-active-attempt property FAILs in the demonstrated same/different-SID distinct-proof schedules. Native proof one-use is a different guarantee. |
| I4: old requests cannot restore proof/pending/owner or overwrite winner cookies while still inside coordination | FAIL for the demonstrated schedules. File pending/logout restoration and both-driver cookie effects remain. Already-issued headers remain a separate unavoidable transport limit. |
| I5: existing limiter keys unchanged | Source preserved; concurrent bucket and focused regression results recorded below. No new flow budget exists. |
| I6: guard completion uses a fresh eligible snapshot | PASS at the M02A checks that were tested; OPEN for password-to-MFA proof revision and changes after the last trusted read. No distributed atomic revocation guarantee. |
| I7: existing security/splash metadata retained | Bounded current-request evidence passes; no claim that arbitrary stale session writers preserve all latest metadata. |

### Native-extension mechanism result

Six separate mechanism-only assertions were executed. With the retirement marker present, a native SessionHandler decorator and ordinary middleware suppress the old write and outgoing session/recaller/device/XSRF cookies in the delayed-login and logout schedules: **4 PASS** across file/database. No StartSession replacement, flow record/state machine or completion-claim service is implemented by this experiment.

Deleting only the old SID's retirement marker before the late writer resumes causes **2 FAIL** results: the late cookie is emitted again; database loses winner continuity, and file can restore pending as well. Therefore the experiment proves a usable extension seam for those schedules, **not a secure finished coordinator**. It specifically falsifies an optimistic retirement-blacklist design under partial cache loss. Larger lease/retention numbers do not change the meaning of a missing marker.

The unchanged original I4 still requires an all-writer, loss-safe, coordinated persistence/cookie contract. These four mechanism PASS records do not override the two loss failures, the lease counterexample, or the current application failures. Supported framework seams reduce implementation uncertainty; they do not establish that a complex registry is either necessary or safe.

## 7. Option A/B/C comparison

The table compares actual A with design capabilities of B/C. B/C are **NOT IMPLEMENTED** and have no feature-test PASS status. Performance statements are operation-cost estimates; no production p50/p95 benchmark was performed.

| Criterion | A: preserved targeted fixes | B: minimal session-bound attempt | C: lean independent cache record |
|---|---|---|---|
| Security guarantees | Verified request-local admission/replay protections; no global attempt authority. | Opaque form nonce plus stage/revision can reject mismatched current forms. Stale payloads can still contain a matching old nonce. | Can make terminal state/claim authoritative outside stale session data, if loss/mismatch fails closed. Does not inherently fence cookies/session writes. |
| Problem coverage | F02/F03 within current authenticated request, F04 in-request checks, F07 cleanup after supersession. | F01 strict context, frozen intended/remember, password-to-MFA revision and correlated audit. | B benefits plus global accepted claim and terminal replay rejection. F05/F06 still require independently proved session integration. |
| Session consistency | Last-writer effects remain. | Same payload lifecycle; native blocking only protects participating same-SID requests. | Independent record can reject old proof even if stale payload returns, but a restored authenticated session does not automatically consult the flow. |
| MFA compatibility | Existing verifier/row lock preserved and tested. | Add initial proof revision; reuse one verifier, keep profile calls unchanged. | Reserve before proof and never reopen after consumption/uncertainty; keep native verifier. |
| Concurrency | No cross-SID claim; cap check and session save are separate. | Same-SID lock helps; different-SID/fork/late-writer counterexamples remain. | Lock/conditional claim can resolve flow competition; expiring lease alone is insufficient. |
| Runtime overhead | One fresh user read at completion; existing service cost. | Small bounded session fields; lock wait on participating routes. | Additional record read/write and lock/claim operations; any global writer fence has broader cost. Must measure. |
| Code complexity | Lowest, eight-file patch. | Controller/request/view/pending-service changes; no new storage engine. | One bounded flow service is feasible; robust session/cookie fencing is a separate, potentially large responsibility. |
| Storage | Existing session/audit/device stores. | Existing bounded session fields. | Versioned transient namespace in a verified common store; explicit loss/expiry/retention contract. No application migration justified merely for URL/context. |
| Shared hosting | Existing behavior; file session inventory mismatch remains. | Native locks available only on supported shared store/filesystem. Hard execution bound must fit lease. | Requires shared consistent authority, verified lock semantics and failure behavior. Unknown host capabilities prevent blanket compatibility. |
| Maintenance | Smallest recurring cost. | More state/UX tests; comparatively contained. | Claims, expiry, partial loss and coordinated persistence/cookie tests impose ongoing maintenance. |
| Failure recovery | 500 may leave authentication; proof remains consumed. | Can show stale/restart, but stale payload may reopen an attempt. | Terminal restart can prevent re-execution; cannot atomically roll back OTP, guard, audit, notifications and browser delivery. |
| Rollback | Reviewed inverse M02A hunks; preserve live data. | Reject attempt-tagged pending on disable; fresh login, no proof restore. | Drain/terminalize claimed attempts and preserve fail-closed compatibility while disabling; namespace expiry only after safe horizon. |
| User experience | Fixes observed stale authenticated challenge; pending guest replacement still permitted. | Clear stale-form/expiry/restart behavior; simple optional opaque URL. | Deterministic server-side attempt result under supported faults; more forced restarts on store outage/ambiguous completion. |

**Decision boundary:** A is enough only for its explicitly limited corrections. B is enough for local stale-form UX if its weaker cross-request guarantees are openly accepted; it does not meet the unchanged I2–I4 requirements. Independent authority in C is justified if the original one-claim/terminal invariants remain required. Choosing C is not evidence that a full solution satisfies I4, nor permission to recreate the historical large coordinator.

No custom StartSession replacement, complex SID catalog, tombstone subsystem, new auth framework, OAuth/OIDC/PKCE, application migration or production driver switch is approved. Native middleware/handler extension seams are evaluated before considering any coordinator. A native handler decorator is still security-sensitive integration; fewer filenames do not eliminate its proof obligations.

## 8. Final architecture decision and ten explicit answers

**Architecture conclusion:** retain M02A and reject implementing a session-only flow as if it satisfied I1–I7. A lean independent authority is justified for terminal state and one accepted claim, but that does not yet make the whole design implementation-ready. The strict I4 session/cookie/persistence boundary must be resolved rather than hidden inside a nominally small flow service. The native-extension experiments and final blocker disposition are recorded with their results below.

1. **Are M02A corrections sufficient for a safe targeted release?** They pass their bounded current-request corrections and remain useful. They are insufficient for an unqualified security/release GO against this mission's concurrency requirements. File logout restoration, cross-SID completion and response ordering require a bounded correction or explicit release-risk decision plus M04; production topology is not verified. Do not silently redefine M02A's accepted scope or call every residual a newly introduced regression.
2. **Which reproduced problems remain?** Guest context replacement, old guest snapshot completion after another account login, stale pending/file logout restoration, cookie-order continuity/identity effects, absent password-to-MFA proof revision, and lack of whole-completion atomicity. Additional cap/persistence findings are classified in the final register.
3. **Is Login Transaction Flow necessary for the original benefits?** No for URL length, localized restart messages, audit correlation or request-local eligibility fixes. Explicit attempt lifecycle becomes justified when one accepted completion and authoritative terminal replay protection are actually required; those are meaningful server-side benefits, independent of how long the URL looks.
4. **Would lightweight session-based flow suffice?** Only for current-session form identity, immutable context and proof revision. It cannot be selected as the complete solution while I2–I4 remain unchanged: both the flow nonce and pending can be present in a stale payload, and native locks differ across SIDs.
5. **Is authoritative cache storage justified?** Yes for an independent transient attempt state/claim under the full requirements. It must use a verified shared store, checked writes, no fallback to a legacy path, no reconstruction of missing proof, and explicit handling of partial loss. This justifies the responsibility, not an untested particular implementation or production store switch.
6. **Is a custom session coordinator required?** A session/cookie consistency boundary is required for strict I4; replacing StartSession is not established as necessary. Native middleware and handler decorators offer seams. A retirement blacklist alone is not a complete authority/failure policy. Do not approve a complex SID-family registry or large coordinator merely because ordinary route blocking is insufficient.
7. **Can Laravel's session lifecycle be preserved?** Yes as an integration constraint: keep native manager/handlers, CSRF, regeneration, guard, serialization and middleware lifecycle. The exact acknowledgement/fencing extension still needs a complete fail-closed contract; preserving the lifecycle does not imply leaving every write unobserved.
8. **What user-visible improvement is selected?** Preserve the M02A behavior now. A future approved attempt contract should give explicit stale/expired/restart feedback, freeze the account/intended/remember context and avoid silent context replacement. Do not introduce OAuth-looking decoration or redesign the login/splash.
9. **What guarantees cannot reasonably be provided?** Exactly-once across OTP DB, cache, session, audit, notifications and browser delivery; rollback of consumed recovery/TOTP; revocation of already-issued headers; perfect cookie continuity across all network response orders; protection from stolen cookies/XSS through flow IDs; immediate linearizable revocation for nonparticipating account writers. In-boundary I4 is a remaining requirement, not excused by these external limits.
10. **Smallest implementation-ready scope for M03?** No full-flow scope is declared ready while critical I4/failure gates remain unresolved. The consolidated candidate boundary in section 9 is concrete about responsibilities, files, exclusions and acceptance, but is **BLOCKED for implementation** until those gates have a complete reviewed contract. This mission does not start implementation or invent another mission sequence.

URL decision: no URL change in M02BC. If the bounded C design is later approved, use only `/login?flow=<43-character base64url random identifier>` and the same opaque identifier on MFA forms. Generate 32 random bytes; keep owner secret, user/proof revision, stage, deadlines, intended and remember server-side. The identifier is neither SID nor CSRF/auth capability. No `client_id`, OAuth `state`, PKCE challenge, signature or timestamp decoration is needed merely for length. The copied URL must not import any context into another session.

## 9. M03 consolidated implementation contract

**M03: BLOCKED for full-flow implementation; NOT AUTHORIZED.** This is a requirements boundary for one consolidated M03, not speculative implementation instructions or a new set of missions. Resolve the critical gates within review of this M02BC decision; do not silently begin coding around them.

### 9.1 Resolved scope and file boundaries

| Responsibility | Candidate files if explicitly approved | Required contract |
|---|---|---|
| One bounded attempt/context | New `app/Services/Auth/LoginTransactionService.php`; `config/auth_security.php` | One versioned authoritative record per logical owner, immutable initial context, terminal claim, absolute expiry; no repository/interface hierarchy or schema/package. |
| Password, restart and MFA integration | Existing AuthenticatedSessionController, LoginRequest, TwoFactorService, TwoFactorChallengeController and EnsurePendingTwoFactorChallenge; `routes/web.php` | Same route names/CSRF/limiter keys; stale form/context rejected before proof; explicit CSRF-protected restart; one crypto routine, generic profile verifier unchanged. |
| Proof revision/final completion | Existing User and CompleteLoginService; trusted recall path in EnforceAuthSessionSecurity | Freeze keyed password/version/MFA-configuration revision when password or trusted recaller proof is accepted; compare at locked MFA and final admission. No consumed-code restoration or automatic completion retry. |
| Audit correlation | Existing AuthAuditLogger, LogAuthenticationEvent, AuthAuditLog and paired security translations | Bounded non-capability correlation and separate completion outcome; retain historical guard login-success meaning. Never log password/OTP/recovery, owner secret, raw SID, recaller token or full sensitive URL. |
| Minimal UI | Existing login/MFA views; `lang/en/auth.php`, `lang/id/auth.php`; scoped auth headers | Opaque URL only if a real lifecycle is approved; accessible stale/expired/restart feedback, no redesign; no-store/no-referrer including errors; existing splash/loader contract unchanged. |
| Session write/cookie boundary | `bootstrap/app.php` and at most a narrowly justified middleware/provider/handler adapter, exact design unresolved | Native lifecycle preserved; all relevant stale writers, success/failure/exception paths and cookie preparation covered. No unconditional StartSession replacement or new cookie/SID registry by default. |
| Cap/known-device consistency | Existing SessionInventoryService and completion integration only if the final cap counterexample requires correction | Cross-browser cap admission must account for the not-yet-saved candidate under a consistent boundary; keep max=3, avoid unbounded user-row locks across notifications/network. No hidden change to the configured threshold. |
| Focused tests | `tests/Feature/Auth/` and `tests/Support/` | Preserve M02A coverage; add true independent-process cases for the final design and the recorded counterexamples. No proposed-feature test receives PASS before implementation. |

These are maximum responsibility boundaries, not authority to modify every listed file. Confirm the actual smallest diff after the missing contracts are resolved. Profile enrollment, supplier review/activation, role/scope rules, payment modules, production `.env`, global driver defaults and application schema stay out of scope.

### 9.2 Lifecycle and completion requirements that must not change

- One active attempt per established logical pre-auth owner. Initial concurrent no-cookie requests cannot be assumed to be one owner; no private context can be imported solely from the URL.
- Minimum states: CREATED, MFA_REQUIRED, CLAIMED/COMPLETING and terminal COMPLETED/CANCELLED/EXPIRED. The exact claim is globally unique and cannot be reopened after accepted proof or uncertainty. A missing record is not permission to recreate an old attempt.
- Keep current 600-second pending MFA allowance and exact equality semantics unless a separate intentional change is approved and tested. Define the initial-attempt deadline, absolute maximum and terminal/coordination retention from enforced request bounds; do not silently adopt historical Gate 1 numbers or introduce sliding expiry.
- Password failures retain current identity limiter behavior and same deadline. Refresh is read/resume, not a deadline extension or budget reset. Strict context changes require explicit restart; restart never resets email/IP/subnet/global/MFA buckets.
- Validate session owner, expected form/stage, expiry and frozen proof revision before verifier use. Reserve/claim semantics distinguish definitely invalid proof (may retry within original attempt) from consumed/uncertain proof (terminal fresh attempt). All configuration is server-side.
- Use common completion once. Clear only obsolete pending after valid admission; preserve security, locale, confirmation, device, cap and splash metadata. Known caught ancillary failures retain existing isolation. Unexpected faults stay observable; consumed codes and historical audit are never undone.
- Recalled MFA HTML first follows trusted logout/invalidate/conversion, then gets a fresh bound attempt. Recalled MFA JSON keeps 401. Non-MFA recall does not run interactive completion twice. Disabling the feature must still reject pending created under the enabled contract.
- A default-off feature flag is required if a flow is implemented. Enabled-store failure must never silently fall back to an unbound legacy login. Do not automatically enable, change drivers or modify a production environment.

### 9.3 Critical gates before M03 can be ready

| Gate | Exact unresolved decision/proof | Why it blocks |
|---|---|---|
| G1: all-writer I4 boundary | Specify how pre-enrollment requests, different SIDs, current authenticated writers, logout/invalidation and exception responses atomically decide whether they may save and emit cookies. A controller or flow lock is insufficient. | A flow claim alone does not prevent file logout resurrection or stale-cookie overwrite. |
| G2: loss and retention | Specify fail-closed positive authority when only an alias/retirement marker/record disappears, including bounded lifetime and actual enforced request maximum. Distinguish a fresh unknown session from a stale formerly enrolled one without reconstructing proof. | A blacklist whose absence means allowed cannot certify I4 under partial loss. No production worker bound/store topology has been proved. |
| G3: lease loss | Define a fence/CAS or an enforceable critical-section lifetime that prevents a delayed owner from mutating after lock expiry, including process suspension and a still-running worker. Do not rely on periodic refresh or a larger lease alone as proof. | The fixture lease diagnostic demonstrates that an expired native lock can admit another completion. |
| G4: persistence and failure | Define acknowledgement of candidate native-session persistence, outbound auth/device cookie handling and terminal result on false/throwing writes, with no global compensation or proof retry. | Native Store ignores handler `write=false`; guard audit precedes completion/persistence. Success cannot be inferred from absence of exception. |
| G5: release boundary | Select the supported deployed store/topology and the explicitly approved correction scope, including any cap finding. A driver change needs its own operational authorization. | File and database outcomes differ; inventory always uses SQL sessions. A localhost fixture is not deployment proof. |

M03 is not ready merely because state names, a new service filename or an opaque URL can be specified. If review instead chooses only bounded A/B corrections, record the unchanged full-flow invariants as unmet and obtain an explicit scope/risk decision; do not relabel that option compliant. The next planned stages remain **one consolidated M03 if required**, then **M04 final verification**.

### 9.4 Acceptance and rollback contract

Implementation acceptance must turn every relevant FAIL in section 4 into a positive security assertion, preserve all existing authentication controls, reject foreign/stale/malformed forms before proof, enforce one accepted claim under independent processes, and pass partial-store-loss plus lease/persistence fault tests. No threshold, deadline, code-replay rule or cap may be weakened to obtain PASS.

Rollback of any later authorized M03 must stop new allocations, drain/terminalize in-flight claims, preserve valid unrelated sessions and audits, and reject enabled-format pending after disable. Do not restore consumed codes, old session payloads, deleted sessions or remember tokens. Do not rotate APP_KEY, flush shared stores or drop tables. A reviewed inverse patch/commit revert and scoped namespace expiry are required; ambiguous attempts restart.

## 10. M04 final verification requirements

M04 is the single final security/regression/release-readiness stage, after an explicitly approved consolidated M03 if required. It must:

1. Verify the reviewed master plus implementation SHA/diff, dependencies and preserved work; compare with the recorded counterexamples, not historical test totals alone.
2. Run every selected design's positive, negative, missing/malformed/duplicate/foreign/stale/expired input case. For a flow, preserve the original T01–T33 and I1–I7 gates, including copied URL without owner, partial record/claim/alias loss and feature-disabled old pending. A feature that does not exist remains NOT EXECUTED.
3. Repeat independent-process same/different SID, pre-enrollment writer, final-read mutation, logout, distinct/same proof, response-order, false-write/exception, lease loss and process-death schedules. Verify exact guard/proof/audit/device/cookie counts and candidate-session ownership. Do not infer cookie delivery order from locks.
4. Prove password-to-MFA revision binding and consistent active/ACTIVE checks without weakening supplier approval, roles/scopes or generic profile MFA. Preserve dummy hashing, normalization, Turnstile ProviderError compatibility and original limiter keys/budgets.
5. Resolve cap admission against not-yet-saved candidates; verify max=3 under concurrent browsers using the actual supported session inventory. File-mode SQL inventory mismatch cannot be declared fixed by a flow. Separate known-device deduplication from cap correctness.
6. Correct the expired-row regression oracle only if the controlled GC evidence proves its assumption invalid: assert that expired rows do not count/evict active slots, rather than requiring native GC never to delete an expired row. Add deterministic GC-on/off coverage without changing production lottery, lifetime or cap merely to make tests pass.
7. Execute relevant existing auth/security, supplier-scope/authorization, audit/translation/route and splash suites in a verified disposable full-schema environment as needed. Perform build/lint only for actual changes; no unneeded dependency install or application database reset.
8. Perform real-browser refresh/back/bfcache, shared tabs, separate contexts, remembered login, cookie-disabled/rejected-cookie, accessible error/restart focus and splash readiness/minimum/reduced-motion checks. Kernel cookie jars do not supply these results.
9. Verify restored-staging deployment topology, common stores/locks, namespaces, session inventory, actual database privileges, worker hard bounds, clock, HTTPS/cookie/proxy settings, and any operational driver change under separate authorization. No production probing or deployment is implied.
10. Rehearse default-off/on, outage, rolling-version/drain and rollback with CREATED/MFA/CLAIMED/terminal attempts. Preserve consumed codes and audit history. Measure overhead and lock contention on that deployment shape; do not invent latency numbers from fixture timing.

M04 release GO requires the selected security contract to pass, explicit classification of residual external limits, and no unresolved mandatory security FAIL/BLOCKED case. A diagnostic PASS or an alternate weaker mode never overrides an I4/claim failure. Real browser and deployment evidence are separate from these kernel fixtures.

## 11. Files changed

Intended persistent M02BC diff: **this consolidated report only**. No new permanent test file is added. Temporary concurrency scripts and the fixture-only TestCase instrumentation are identified as evidence artifacts, not production source. Every existing M02A modification, focused test and untracked document remains preserved.

## 12. Evidence, commands and failed attempts

Entry points: `<fixture>/scripts/run_bc.py`, `cases.py`, `instrument.php`, the guarded `worker.php` and bounded `phpunit-worker.php`. Retention is local; no service is publicly hosted. Raw fixture inputs contain synthetic credentials/cookies only; published summaries contain hashes/counts and no production secret.

Executed batches, all through the admitted temporary supervisor:

```text
python <fixture>/scripts/run_bc.py smoke
python <fixture>/scripts/run_bc.py overlaps
python <fixture>/scripts/run_bc.py eligibility
python <fixture>/scripts/run_bc.py eligibility-tail
python <fixture>/scripts/run_bc.py eligibility-tail2
python <fixture>/scripts/run_bc.py native
python <fixture>/scripts/run_bc.py native-tail
python <fixture>/scripts/run_bc.py probe-locks
python <fixture>/scripts/run_bc.py faults
python <fixture>/scripts/run_bc.py mechanisms
python <fixture>/scripts/run_bc.py replay
python <fixture>/scripts/run_bc.py authenticated-writer
python <fixture>/scripts/run_bc.py regressions
python <fixture>/scripts/clean_regression_fixture.py
python <fixture>/scripts/run_bc.py regressions-clean
python <fixture>/scripts/aggregate.py
```

These commands are evidence of this run, not permission to reuse an old instance without admission. Source/test assertions must not be run through the repository's default development/test connection as a shortcut.

Early harness/precondition failures remain visible:

- Empty PHP event maps encoded as lists caused a Python `.get` error after 13 valid F04 records; normalization was corrected, no expectation changed.
- A supervisor interruption across continuation retained five completed F04 records; unfinished cases resumed with fresh synthetic users and private listener absence was checked.
- A two-second fixture lease expired before a `lock-blocked` barrier was observed. That assertion was not assigned PASS/FAIL. The later eight-second fixture diagnostic observed actual contention and two completions after lease expiry; neither value changes application thresholds.
- Newly added diagnostic route names were not initially present in Laravel's name lookup at the point blocking was applied. Four old/new-SID probe PASS records are excluded as invalid setup. Explicitly blocking the probe instance and verifying lock activity produced the six corrected records. Static application-route tests were unaffected.
- After the environment switched to a workspace sandbox token, one admission attempt could not write private PHP storage and stopped **before application tests**. The owned server was shut down. Scoped execution access for the already-authorized fixture was approved, then all admission probes passed again; no check was relaxed. This was not an automatic approval-review rejection.
- The contaminated regression attempt, its 28 failures, partial XML and private-data backup are retained as described in section 4. Cleanup used only the independently verified disposable schema and preserved its prior evidence.

Four `error-*.txt` artifacts record the completed supervisors that stopped on harness/admission issues. The interrupted F04 and initial regression runs are retained separately. Failed setup attempts are not hidden by the successful aggregate.

Final machine-readable summary: `<fixture>/evidence/final-summary.json`, SHA-256 **6b644701492e92ffc7f422b3905ea9e74eb760700a1666e5c768f09a95b5ce32**. It lists the exact selected logical checks, excluded initial probes, ordinary JUnit/provider cases, GC diagnostics, contaminated attempts and result-file hashes. `scripts/aggregate.py` defines the count rules. Per-batch `harness-<phase>/` snapshots preserve script revisions; no claim is made that every attempt ran one immutable harness revision. `progress-*.json`, `results-*.json`, JUnit XML, GC JSONL, gate PID/time signals and supervisor logs provide the underlying evidence. PowerShell supervisor logs may be UTF-16; result JSON/JUnit are the canonical machine-readable records.

## 13. Blockers and rollback considerations

No production rollback is needed for this M02BC report. Remove only this new report if reverting this mission; preserve all M02A work and historical evidence. Stop only the verified owned fixture service; do not reset/clean the repository, rotate APP_KEY, flush shared stores, restore consumed proofs or overwrite old auth documents. M03 implementation and deployment require separate authorization.

## 14. Final verification

- **PASS:** latest remote master was checked again after execution and remains `317dc0092bc8e08a8eeaca40803a7b327877b88d`; HEAD and branch master match. No fetch/checkout/merge or alternate branch was used.
- **PASS:** all twelve preserved initial files remain byte-for-byte identical to the pre-test manifest. The nine M02A runtime/test hashes still match the M02A execution input. No M02A hunk, test or historical document was amended.
- **PASS:** all **891** runtime source-copy files match the pinned archive plus the preserved eight-file patch. The focused test hash also matches. Only the declared fixture TestCase, service/handler wrappers and temporary scripts add instrumentation; no production source is patched for testing.
- **PASS:** the private server's graceful shutdown was followed by independent OS checks: actual child PID **12092** absent, listener **62929** absent, `PRIVATE_SERVER_STOPPED`, and client admission revoked (`admitted=false`). Existing Laragon MySQL services were not stopped.
- **PASS:** exact case counts and exclusions are generated by the final summary; no proposed flow is assigned a feature PASS. Ordinary clean regressions pass; the 33 consistency assertions, one GC diagnostic failure and initial invalid-precondition failures remain explicit.
- **PASS:** final document UTF-8, trailing-whitespace, local reference, scope and `git diff --check` validation. The only new persistent M02BC file is this report; no new persistent test is added. No frontend build is necessary for a report-only diff.
- **BLOCKED:** full-flow M03 implementation readiness, specifically G1–G5. This is a design/security-contract gate, not a claim that verification could not safely proceed.
- **NOT EXECUTED:** feature implementation, M04 release readiness, production/staging, browser/multi-host and true crash-durability cases specified above. There is no production release approval.

## 15. Reference scope

Pinned installed framework source determines the tested behavior. Official major-version references were rechecked for mechanism semantics: [Laravel session blocking and custom handlers](https://laravel.com/docs/12.x/session#session-blocking), [Laravel atomic locks](https://laravel.com/docs/12.x/cache#atomic-locks), and [OWASP session management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html). These documents are not proof that a proposed flow or target deployment satisfies I1–I7.

---

# Final architecture resolution addendum — G1–G5

19 October 2026. **Final recommendation: GO FOR APPROVAL — bounded Option A corrections in one consolidated M03. Reject a Login Transaction Flow for that implementation scope.**

This is the final decision inside M02BC, not another mission. It supersedes the architectural recommendation and proposed file boundaries in sections 7–9 and their implementation-blocker disposition in section 14. Their earlier REVISE outcome remains historical. Sections 1–6 and the executed evidence, counts, failures and hashes remain unchanged: **97 checks, 64 PASS / 33 FAIL; ordinary regression 165/165 PASS**. No historical FAIL is converted to PASS by selecting a smaller scope.

**The distinction requiring approval is explicit:** the proposed M03 fixes concrete proof-revision, current-request failure and SQL session-admission defects; it does **not** deliver the full Gate 1 transaction feature or satisfy all I1–I7. If full I2–I4 compliance is mandatory for this release, this bounded GO does not apply: the full-flow proposal remains NO GO. Production enablement also remains NO GO until M03/M04 and the deployment checks below pass. No code implementation, configuration change, database access, new runtime test or deployment is performed by this addendum.

## Baseline, evidence and decision basis

Latest remote master was checked through live `git ls-remote origin refs/heads/master` for this resolution; HEAD/tracking master still match **317dc0092bc8e08a8eeaca40803a7b327877b88d**. The sandboxed network attempt failed; the scoped read-only remote check succeeded. No fetch, checkout or replacement of M02A occurred. Laravel remains pinned to **12.69.3 / 58ea544a2a80dc03c168e13a5dc9a1d176a88717**.

The pre-addendum report is preserved as an exact **51,477-byte prefix**, SHA-256 **ba1348b0344ec2c94f8ef634185241ac6ae624bc32b9f437792de08437fb19b9**. A parent-side temporary snapshot records that prefix, all thirteen existing dirty/untracked files and 1,284 runtime/test file hashes. Only this report is changed in this resolution. Input plans/reports, M02A production changes, its focused test and the earlier fixture/evidence remain untouched.

The decision uses the recorded counterexamples and newly inspected pinned source, not a rerun of successful characterization. Relevant verified seams:

- `SessionManager::extend()` and `buildSession()` let an application handler subclass retain the existing `database` driver name, native Store/EncryptedStore and serialization. StartSession replacement is unnecessary for the selected corrections.
- `StartSession::handleStatefulRequest()` loads the session, runs the inner pipeline, prepares its cookie and invokes Store save. `Store::save()` discards the handler write boolean.
- `DatabaseSessionHandler::write()` itself returns true after insert/update and ignores update row count. Therefore checking only that boolean is insufficient: a deleted-row UPDATE can report true while persisting nothing. Its protected hooks and primary-connection query builder permit explicit readback without reimplementing serialization.
- Its native `performInsert()` also catches every QueryException and tries UPDATE. The checked adapter must override that fallback: a deadlock can roll back the database transaction, so silently continuing that path is not an admission retry contract.
- Routing Pipeline can render exceptions into a response inside middleware. Failure handling must inspect a request-scoped failure result on the returned response path, not rely solely on catching a propagated exception.
- `AddQueuedCookiesToResponse` attaches queued cookies after its inner pipeline. An ordinary outer middleware can inspect the final response and remove cookies issued by a failed request. This is a failure boundary, not a cross-request cookie-order fence.
- `SessionInventoryService` uses the configured SQL sessions table; its pre-save count has no reservation for the candidate. Serializing only the controller check would still release before the candidate exists.

These are source-level design facts. The proposed corrections below are **NOT IMPLEMENTED / NOT EXECUTED**. Existing mechanism results remain four normal-seam PASS and two marker-loss FAIL; neither certifies a finished coordinator.

## Chosen architecture and rejected alternatives

**Select Option A with three bounded responsibilities:** bind MFA admission to the originally accepted credential configuration; contain current-request errors after proof consumption; and acknowledge SQL session persistence with cap admission in the same short database transaction as the final session write. Correct the demonstrated expired-row test oracle under controlled GC coverage.

| Option | Final disposition | Reason |
|---|---|---|
| A — existing authentication plus targeted corrections | **Selected for M03 approval**, with the explicit residuals below. | Addresses demonstrated defects through existing services and one checked native-handler boundary. No public flow identifier or new persistent authority is needed for these bounded benefits. |
| B — session-bound flow/nonce | **Not selected.** | Can improve form UX, but stale payloads retain the nonce. It does not supply terminal authority, one accepted completion or loss-safe I4. Adding it now would give the appearance of a transaction without those guarantees. |
| C — lean cache-backed transaction | **Rejected for this M03 scope**, not declared impossible in principle. | Independent authority is justified if full terminal/claim requirements are retained as the implementation scope. However, a record and atomic claim do not solve all-writer session restoration/cookies, marker loss, lease loss or persistence acknowledgement. The additional consistency protocol is disproportionate to the bounded corrections selected here and is not certified by the existing experiments. |

There will be **no `/login?flow=...` feature**, cosmetic long URL, restart subsystem, OAuth-style parameters, cache attempt/claim ledger, SID-family registry, tombstone subsystem or application migration in the selected M03. Existing login/MFA routes and forms remain. If a full flow is ever separately required, its URL may contain one opaque identifier only; that is not part of this implementation contract.

### G1 — session and cookie consistency: bounded resolution, full I4 explicitly unmet

The minimum responsibility for full I4 is larger than route blocking: every relevant writer must carry a positively validated context/generation through load, regeneration, save and cookie preparation; stale commits must be rejected atomically; and loss of authority must not admit an old writer. Request participants include pre-enrollment GETs, old/new SIDs, current authenticated writers, logout, exception responses and queued cookies. A lock that ends in a controller, a session nonce or a missing retirement marker cannot establish this. Native middleware/handler seams exist, so there is no proof that replacing StartSession is necessary. A complete all-writer authority/commit protocol is **not selected or claimed** by this narrower decision.

Exact selected boundary:

| Lifecycle point | M03 contract | Guarantee boundary |
|---|---|---|
| Session load | Keep native StartSession and DatabaseSessionHandler reads, expiry and encryption. Tag storage exceptions for the outer failure responder; do not resolve the guard before session startup. | No whole-request lock or new ownership reconstructed from URL/cache. |
| Regeneration | Keep native guard/session regeneration and its ExistenceAwareInterface behavior; retain M02A metadata and CSRF rotation. | No old/new SID family serialization is claimed. |
| Login completion | Keep common fresh eligible admission and cleanup. Record an immutable request-local admission snapshot and candidate identity for the checked write. | A guard event is not persistence acknowledgement. |
| Final save | Exactly one native Store save. The checked handler receives the already serialized/encrypted data and performs the transaction specified under G5. | No manual early Store save, second save, custom serializer or duplicate flash aging. |
| Logout/invalidation | Keep native logout/invalidate/token rotation and SQL deletion. Surface storage failures; do not restore deleted payloads or compensate by logging another account out. | Database deleted-row behavior is preserved; file logout resurrection is not certified fixed. |
| Exceptions/cookies | An ordinary web middleware runs outside EncryptCookies → AddQueuedCookiesToResponse → StartSession. It sees the rendered response and failure attributes, strips failed-request auth/session cookies and supplies the defined failure response. | Handles this request's known failure; does not suppress every successful stale request across generations. |
| Old/new SIDs and live-row late writers | Preserve the counterexamples as residual tests/observations. No new locks or aliases pretend to unify them. | F03G/F02L, general F05/F06 and full I4 remain open. |

The outer middleware must not call Auth/session APIs before StartSession, mutate unrelated session metadata or expire the browser cookie as a substitute for suppressing a failed response's Set-Cookie. Its ordering is an explicit full-stack acceptance test. Native route blocking remains an inspected/tested primitive, **not automatically enabled** as an unproved fix.

### G2 — partial store loss: positive admission for the selected scope

There is no new cache authority whose missing marker could mean permission. The selected positive evidence is:

1. A valid server-side pending MFA record containing a recognized format version and keyed fingerprint of the accepted credential configuration; fresh eligible user/configuration checks are still required.
2. For an authenticated SQL session write, successful native persistence and exact primary-row readback inside the database admission transaction, followed by acknowledged commit. A cookie, handler true return or earlier guard event is insufficient.

Pending version/fingerprint/user linkage missing, malformed or mismatched means generic fresh login **before OTP consumption**. It is never recreated from query parameters, remembered input or a cached proof. Extend the existing pending format, not a new attempt state machine. The fingerprint uses a versioned canonical tuple of user ID, password hash, auth_session_version, MFA secret and confirmation instant, protected with a server-keyed HMAC and compared in constant time. Keep plaintext/hash input material out of session, URL, audit and response; store only the resulting digest/version alongside the existing server pending. Exclude last-used TOTP step and recovery-list changes because valid verification mutates them.

Capture the fingerprint when password proof is accepted or trusted recaller conversion creates pending; check it under the existing user row lock **before consumption** and at final admission. Preserve generic `verifyUserCode()` for profile/enrollment callers. A fresh later user snapshot must not silently replace the original expected fingerprint.

Keep the existing pending lifetime of 600 seconds and its exact equality behavior; refresh/errors do not extend it. Unknown/legacy pending without the new format is discarded with a localized generic restart/login response. This guard remains active if the checked-persistence flag is disabled. There is no new claim/generation/retirement retention period or cleanup command.

| Partial deletion | Selected behavior | Remaining limitation |
|---|---|---|
| Pending proof revision/record | Reject and require fresh login; no proof lookup/reconstruction from a public ID. | A different in-flight request may still hold a previously loaded valid copy. |
| SQL session row | Missing row cannot count as an acknowledged authenticated write. A loaded-existing row that disappears must not be reinserted by a fallback. | Native recaller is a separate valid credential path, not restoration from a missing row. |
| Future attempt/claim record | None is introduced. No full-flow terminal/claim guarantee is claimed. | I2/I3 are unmet, not vacuously PASS. |
| Future session generation/retirement marker | None is introduced; no blacklist-miss authorization exists. | Full generation fencing and global retired-context rejection remain unmet. |
| Existing cache/limiter data | No new auth fallback or bucket reset. Preserve existing behavior and report outage. | This does not harden existing limiter durability against arbitrary cache deletion. |

This is a positive, fail-closed **admission** contract, not positive lifecycle authority for every active/retired browser context. A safe universal fresh-versus-stale distinction after arbitrary metadata loss has not been established for the full feature, and none is invented here.

### G3 — lease expiration: remove lease dependence from cap correctness

The selected cap/persistence critical section uses an InnoDB transaction and `users.id` row lock on the same primary PDO connection as the native session write. It does **not** use an expiring cache lock as the source of write authority. A paused transaction continues to hold its database lock until commit/rollback/connection termination; a waiter may time out, but is not allowed to proceed merely because a cache TTL elapsed. After connection loss/deadlock/unknown commit, abort that admission, mark failure and never retry the guard, proof or native write automatically.

Use one transaction attempt. Pinned Laravel `Connection::handleQueryException()` rethrows query failures while in a transaction instead of reconnecting/replaying that statement; the checked handler must not catch this and perform a standalone fallback write. A failed resumed writer must not continue after its transaction has ended. No user-row transaction spans password hashing, OTP verification, notifications, HTTP calls or the whole web request.

Specifically override the native handler's broad insert-error-to-update fallback using its protected insert hook: insert with the native prepared payload/query builder, propagate database failures and reject duplicate/missing-candidate collisions. Never let the parent's QueryException catch resume SQL after a deadlock may have rolled back the transaction. Assert the same primary PDO is still in its transaction before admission mutations/acknowledgement; connection/transaction loss aborts the request path. Keep PDO exception error mode, verify successful transaction completion, and classify uncertain commit as failure. This is part of the narrow adapter, not a new connection framework or serializer.

Native cache locks, generation counters and conditional cache state transitions remain candidate primitives for a *different full-flow contract*. A longer lease or periodic refresh alone is explicitly rejected by the observed two-completion diagnostic. This addendum does not assert ownership fencing for an arbitrary paused HTTP worker or all old/new-SID requests.

The transaction-lock semantics and rollback requirements are supported by [MySQL locking reads](https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html) and [InnoDB error handling](https://dev.mysql.com/doc/refman/8.0/en/innodb-error-handling.html); application integration still needs the acceptance tests below. Database timeouts bound availability, not a lease after which another writer may bypass the row lock.

### G4 — persistence and completion: checked native write and truthful outcomes

Register a subclass of native DatabaseSessionHandler through Laravel's existing SessionManager extension point, retaining the `database` driver name and native Store/EncryptedStore. No replacement StartSession, cookie format, serialization or schema. [Laravel documents this extension seam](https://laravel.com/docs/12.x/session#adding-custom-session-drivers); pinned source determines the exact integration.

At final native save, require both a non-false parent result and exact readback of the candidate row on the primary connection: matching ID, guard owner/user_id, opaque `base64_encode($data)` payload and valid last_activity. Never log the data. A zero affected-row count is not automatically failure if the row already contains the exact expected bytes/owner; a missing/mismatched row is failure even when the parent returned true. A false result, exception, failed readback, cap failure or ambiguous commit is not acknowledged success. Read/invalidating storage exceptions also set a request-scoped failure result before propagating, so an internally rendered error cannot hide it.

Prepare the native default payload/owner outside the SQL critical section, freeze it for this one write through the parent's protected payload hook, and reuse it inside the transaction. Do not first resolve a recaller or emit a guard event from inside a session-write transaction. If owner/payload preparation initiates authentication or changes the SID/session after Store already serialized the data, reject that write rather than save a stale serialization or call Store save again. Reset all prepared/request state after the call. This preserves native encoding while preventing hidden guard/event work in the database critical section; it requires a specific regression test.

MFA cleanup is narrower than a terminal transaction: once verification returns known consumed proof, discard only that subject's pending before post-proof audit/completion callbacks, including failures before guard admission. Do not automatically retry with the consumed code, restore it or leave a reusable `mfa_passed` flag. An unrelated pending on a pre-proof rejection retains M02A containment. An old writer elsewhere may still restore its payload: this is explicitly not global cancellation/I2.

Failure response contract:

- The outer middleware observes the returned response **and** storage/completion flags, since Routing Pipeline can render exceptions internally. Capture only request-scoped issuance/admission facts, not a persistent service singleton.
- A known failed/uncertain interactive completion must not return its success Location/JSON redirect or a success splash response. Use localized generic HTML/JSON 503 with no-store/private headers; reuse the existing security-header component. No account identity or precise internal cause is exposed. Preserve existing pre-proof 419/429 status/header conventions while suppressing unsafe newly issued auth cookies if their persistence also failed.
- Remove newly issued/replaced session, recaller, known-device and XSRF Set-Cookie entries from that failed response and unqueue their request-local issuance. Suppression sends no expired cookie and does not restore old values from another response. Do not alter unrelated cookies, call global logout, rotate tokens as compensation or invalidate another request's session.
- Forget the local guard user if needed without invoking another login/logout lifecycle. A candidate newly generated by this request may be deleted only conditionally by its exact ID, owner and acknowledged payload after verifying ownership; do not delete an established or subsequently changed session. Failed compensation is logged, not retried as login. A pre-existing valid session/recaller may still authenticate a later request: **503 does not universally mean guest**.
- Existing `login_success` remains the guard-mutation event; `mfa_recovery_code_used` remains actual consumption. Neither means a usable browser session was saved. Add one failure outcome event, `session_persistence_failed`, with an existing allowlisted reason such as `write_failed`, `write_unacknowledged`, `admission_failed` or `commit_unknown`. Reuse AuthSecurityEvent/AuthAuditLogger and their error isolation; add paired event labels. Do not add a flow/correlation subsystem. Audit write failure cannot trigger proof replay.
- Device registration, notices, audit and remember-token changes already committed are not all rolled back. Avoid a new-device **delivery** attempt for a completion already known to have failed; existing best-effort side effects and early events are documented as such. If messages were already sent before a later failure, do not claim they can be recalled or delivered exactly once.

There is no global transaction-terminal instant in Option A. The request's proof consumption is irreversible; database session/cap admission becomes locally committed at the SQL transaction commit; browser delivery and independent audit/notification effects remain outside it. Streamed/early-flushed authentication responses are unsupported. No automatic session-save retry or second manual Store save may be added to fix acknowledgement.

### G5 — deployment and cap: one final-write admission transaction

**Minimum supported enabled topology:** native **database sessions** and a shared **database cache/limiter store**, with all web workers using the same primary MySQL/InnoDB database, session table and namespace. `session.connection`, the authentication User connection and SessionInventoryService connection must resolve to the same transaction/PDO connection, not merely servers believed to be equivalent. Use existing users/sessions/cache tables and indexes; no migration is justified. Readback/counts use the primary, not replicas.

The checked handler applies the following short transaction to **every authenticated session persistence**, including interactive completion, new non-MFA recall candidates and background writes that could otherwise recreate/increase counted sessions:

1. Resolve the authenticated principal and expected request facts without parsing the serialized payload. Begin one transaction on the native handler's connection; lock that existing `users` row. Missing principal or changed interactive admission configuration fails closed. Preserve established/non-MFA recalled-session policies rather than silently introducing a new global account_status rule.
2. Invoke the native handler write once with the already prepared data; read back and verify it on that same connection. Loaded-existing/deleted sessions must not be reinserted. Anonymous writes cannot create an authenticated cap slot.
3. Only now call the existing cap query with the current candidate excluded from eviction, so the current candidate is already counted physically. Reuse the existing active-cutoff/limit semantics; add deterministic ordering for tied activity. Keep the configured limit **3** in the counterexample; do not raise it or discard an active session from the count to satisfy the test.
4. Evict the necessary older active rows and rotate remember tokens when eviction actually occurs, within the same SQL transaction. Lock order is user row → session writes/deletes. All authenticated persistence uses this path; direct insertion/resurrection outside it is unsupported. No network/event callbacks or cookie queuing run inside the critical transaction. Separate the existing revocation service's database token rotation from its recaller-queuing step, retaining its existing public combined method for legacy callers; do not call that combined side-effect method inside the transaction or rotate twice.
5. Commit, then publish the request-local acknowledgement/eviction result. Queue the current request's necessary recaller update using the committed token and emit `concurrent_session_limit_enforced` once using the existing revocation/audit services after commit; do not repeat the guard login or deliver a second login notice. AddQueuedCookiesToResponse still runs outside native save and can collect that queue. Best-effort audit failure does not undo the cap or cause re-admission.

Remove the **pre-save** cap call in CompleteLoginService only when the checked contract is enabled; otherwise it would still be the defective admission boundary and could produce duplicate eviction/events. Existing public service signatures/callers remain compatible. New observable behavior requiring approval: the invariant is enforced at authenticated persistence, including a background write when legacy sessions already exceed the limit; HTTP requests are not serialized for their full duration.

This closes the max-3-becomes-4 design gap: while writer one holds the user lock, writer two cannot admit a candidate from the same earlier inventory snapshot. After writer one commits, writer two counts its committed candidate before admitting/evicting for its own. If either transaction fails, it does not leave a half-admitted row/eviction set. It does not guarantee that every newly admitted session survives later legitimate eviction or that a cap-evicted browser retains continuity.

No expiring cache lease is needed for that property. Shared-host SQL lock waits, PDO connection lifetime, worker/gateway deadlines and exception behavior must be bounded and verified before enablement; a lock-wait timeout is failure, not permission to write without the lock. No global MySQL configuration is changed by implementation. Ordinary PHP-FPM/request-scoped execution is the initial target; persistent-worker/Octane support requires its own lifecycle verification in M04, not an assumption.

**File sessions are not an enabled target.** They neither supply the SQL candidate/inventory transaction nor remove the observed logout/pending restoration. When the checked contract is explicitly enabled on an unsupported driver/connection/store, reject before credential/proof processing with a controlled unavailable response; do not silently fall back or change drivers. A default-off flag permits implementation/testing without asserting that the unknown production topology is ready. Choosing/deploying database sessions/cache on a live host requires separate operational approval.

## Exact selected guarantees and retained I1–I7 requirements

The following are **M03 design/acceptance targets**, not new PASS results:

| Invariant | Selected guarantee and residual |
|---|---|
| I1 — public flow identifier never substitutes for owner/CSRF/proof | No public flow is built. Existing CSRF/credential/MFA checks remain; full flow-owner binding is NOT IMPLEMENTED, not vacuous PASS. |
| I2 — terminal attempt cannot reopen | **Unmet.** Current-request consumed-proof cleanup and missing-revision rejection improve containment, but there is no authoritative terminal attempt. Old pending copies can still exist. |
| I3 — one accepted successful completion per flow | **Unmet.** Same TOTP/recovery is still one-use under the existing row lock; distinct valid proofs and cross-SID completion are not a global claim. |
| I4 — no stale context restoration or in-boundary winner-cookie overwrite | **Unmet in general.** Known failed writes emit no success cookies, and SQL missing-row persistence is checked. Live-row lost updates, old/new-SID context, file mode and successful late-response ordering are not universally fenced. Already-issued headers remain separately unretractable. |
| I5 — original limiter keys | **Preserve and test.** No flow/restart allocation and no counter reset/key changes. Arbitrary shared-cache loss remains an existing-store limitation. |
| I6 — fresh eligible admission | **Strengthen** initial-password/recaller-to-MFA configuration binding plus existing fresh/locked/final checks and checked interactive persistence. Changes after the last trusted boundary remain subject to existing revocation, not linearizable global revocation. |
| I7 — security/splash metadata | **Preserve on the normal path:** one native save, unchanged encryption/flash aging/CSRF/locale/splash semantics. A failed request has no success splash response. Arbitrary concurrent stale writers are still an I4 residual. |

The original 33 FAIL assertions remain historical evidence. Some counterexamples become targeted regression oracles; others intentionally remain documented open requirements. File cases must never be relabeled PASS because enabled support is restricted to database sessions. A limited M03/M04 result is not a full I1–I7 certification or a general safe-release declaration.

## Consolidated M03 production boundaries

The following is one implementation contract, pending explicit authorization. Keep the existing M02A behavior except these reviewed additions; do not opportunistically refactor auth.

| File boundary | Required responsibility |
|---|---|
| `app/Services/Auth/TwoFactorService.php` | Version/fingerprint pending format at trusted staging, login-only expected-revision check under existing row lock; generic profile verifier unchanged. |
| `app/Http/Controllers/Auth/TwoFactorChallengeController.php` | Enforce the stored revision and immediate subject-scoped cleanup after known consumption, including failure before common completion; retain generic HTML/JSON errors. |
| `app/Services/Auth/CompleteLoginService.php` | Preserve final admission/guard/rotation, register immutable request facts, delegate enabled cap admission to final persistence, avoid duplicate cap/events. |
| `app/Services/Auth/SessionInventoryService.php` | Reuse cap/query/cutoff logic under the existing user's final-write transaction; preserve signatures, revocation tokens and max=3. |
| `app/Services/Auth/SessionRevocationService.php` | Reuse/split database token rotation and post-commit recaller queuing internally; retain the public combined legacy method and rotate exactly once per actual eviction transaction. |
| **New** `app/Services/Auth/CheckedDatabaseSessionHandler.php` | Native handler subclass, checked readback and one SQL cap/persistence transaction; report request-scoped failure/acknowledgement. No custom Store or serializer. |
| **New** `app/Http/Middleware/RejectFailedSessionPersistence.php` | Outer web failure-response/cookie boundary and unsupported-enabled-configuration refusal; no pre-session guard resolution or success-path global serialization. |
| `app/Providers/AuthSecurityServiceProvider.php`, `bootstrap/app.php` | Register the enabled handler using the existing SessionManager extension and enforce/test middleware ordering. Reuse the existing provider; no new provider layer. |
| `config/auth_security.php` | One default-off `session.checked_persistence` flag controlling the coupled checked-handler/cap integration. No runtime fallback when enabled storage fails. |
| `app/Models/AuthAuditLog.php`, `lang/en/security.php`, `lang/id/security.php` | Register/localize the single failure outcome; retain old machine event meanings. Existing logger/listener metadata allowlist already supports reason/count, so no new logger hierarchy/correlation store. |
| `lang/en/auth.php`, `lang/id/auth.php` | Paired generic unavailable/restart copy as needed; preserve existing field/error conventions. |
| Focused auth tests/support worker; `tests/Feature/Auth/SessionSecurityTest.php` | Add independent-process admission/failure cases and deterministic GC coverage. Replace only the disproved expired-row persistence assumption, not the active-slot/cap assertions or production lottery. Preserve historical test evidence. |

Explicit exclusions: User eligibility predicate already supplied by M02A, LoginRequest/password/dummy hash, route names, views/JS/splash timing, supplier approval/role/scope, generic profile MFA, production `.env`, `config/session.php`/`config/cache.php` defaults, schema, packages, full-flow service/coordinator and persistent SID/generation state. Inspect callers before implementing; any newly discovered need outside these boundaries returns to this contract for explicit scope approval, not an automatic extra mission.

## Security and concurrency acceptance for M03/M04

No case below has been executed for the proposed implementation. M03 must supply focused regression coverage; M04 is the only remaining final security/release-readiness stage.

| Acceptance boundary | Required result |
|---|---|
| Initial proof revision | Password/version/secret/confirmation changes between requests reject MFA before consuming TOTP/recovery; malformed/missing/legacy revision restarts. Consumption counters do not invalidate the legitimate fingerprint. Test password and trusted recall staging. |
| Post-proof failure | Exceptions immediately after verification, during consumption audit, before final admission and after guard never restore that consumed proof or retain that subject's pending in the current request. Separately retain the late-writer residual rather than claiming global cancellation. |
| Native acknowledgement | Inject handler false, true-with-zero-update/missing row, payload/owner mismatch, insert failure and ambiguous commit. No success Location/JSON/splash/auth-cookie issuance; one native save only. Confirm the next request's actual state, not just the guard object. |
| Native payload preparation | Cached principal and ordinary anonymous/error paths cause no guard login inside the write transaction. If a late recaller changes session/SID after serialization, reject the candidate and emit no success cookie rather than persisting mismatched data; no second save/flash aging. |
| Max-3-becomes-4 | Independent processes pause before final native writes with two active rows and two candidates. After both admissions commit, at most three active SQL rows, correct eviction/token/audit counts; no threshold change. Repeat for non-MFA recall and an authenticated background write. |
| Transaction loss | Pause owner with user-row lock held; waiter times out without fallback. Abort owner connection/transaction and resume worker: no standalone save, proof retry or second completion. Inject deadlock/QueryException specifically at native insert and verify there is no parent fallback UPDATE after rollback. Test lock-wait, deadlock and commit-ack loss separately. |
| Row disappearance | Delete a previously loaded row in another process; final write cannot silently acknowledge/reinsert its authenticated payload. Check response cookies and actual stored state. Existing live-row/CAS and successful-cookie ordering residuals remain explicit. |
| Middleware/exception path | Resolve actual order around EncryptCookies/AddQueuedCookies/StartSession. Force Routing Pipeline to return a rendered failure; outer middleware still observes flags and removes only failed-request auth cookies. Keep pre-proof CSRF 419 and limiter 429 conventions/headers. |
| Metadata and effects | Normal flows retain session/CSRF/version/timer/locale/confirmation/device/splash data and one guard login. Failure outcome contains no secrets; legacy guard/consumption events describe actual effects. No new-device delivery retry or exactly-once claim. |
| Format and flag compatibility | Default off preserves baseline session driver/cap path; the new pending-format guard still rejects missing/unknown revision. Enabled unsupported topology fails closed before proof; storage outage never switches to legacy mode. |
| Existing regressions | Re-run relevant M02A 75 cases, auth/MFA/remember/profile/limiter/Turnstile/device/audit/splash and route/translation checks safely. Document any intentionally tightened pending format in the new cases; do not weaken prior security assertions. |
| GC oracle | GC-on and GC-off both preserve active sessions/slot behavior and do not emit a false cap eviction merely due to expired rows. Expired-row physical retention is not required. No production lottery/lifetime change. |
| Residual record | Rerun only counterexamples affected by the changes and report outcomes. I2/I3/full I4 remain OPEN unless actually proved; do not convert their historical FAILs to PASS or omit them from release-risk review. |

M04 additionally verifies real browser response ordering/back/bfcache/cookies/accessibility/splash and restored-staging topology, latency/lock contention, disable/rollback and operational timeouts. Passing source checks or a localhost SQL fixture is not production enablement evidence.

## Storage assumptions, enablement and rollback

**Implementation readiness:** source-level design for the bounded responsibilities is resolved. It requires neither an external provider nor unavailable production access. It remains subject to implementation and the explicit smaller-scope approval below; none of the proposed runtime guarantees is marked tested.

**Production enablement prerequisites, still NOT VERIFIED:** exact supported session/cache drivers and shared primary connection; InnoDB users/sessions and existing indexes; privileges and actual migration ledger; all writers using the same checked version; session encryption/cookie/HTTPS/proxy configuration; bounded SQL waits/PDO/worker/gateway lifetime; no streaming/early flush; and M04 evidence including the real concurrent cap result. Verify host capability instead of changing global SQL settings or assuming a file-to-database switch has already been approved. These are normal enablement gates, not a reason to call the bounded design itself unresolved.

The default-off flag switches the checked handler and cap delegation together. Its disabled branch is an explicit managed baseline, **not an automatic outage fallback** and not a claim that the old cap race is fixed. Pending revision validation/consumed-proof containment remains independent of that flag.

Rollback/disable contract:

1. Stop new affected auth admission during the controlled change; drain in-flight requests/transactions and avoid mixed handler/cap versions. Do not disable the handler while CompleteLoginService still delegates cap to it.
2. Disabling the checked flag preserves native database payload/encryption/cookies and established valid sessions, but reintroduces the known baseline cap/acknowledgement limitations. Require explicit acceptance of that limited rollback state; do not continue advertising the new guarantees.
3. Keep the new pending revision rejection guard when disabling the checked flag. A full code rollback to pre-contract code must first block new MFA staging and drain/expire existing pending beyond the original 600-second equality boundary plus the verified in-flight bound, or retain a compatibility rejection patch. Do not strip fields to make enabled-format proof acceptable to older code.
4. Preserve audits, known devices, legitimately evicted sessions and consumed codes. Do not restore old serialized payloads, re-add recovery codes, rotate APP_KEY, flush shared caches or drop tables. Conditional cleanup is restricted to a proven request-owned new candidate as above.
5. No production driver/configuration change or deployment is authorized here. Any required store switch, enablement, maintenance/drain or rollback execution is separately approved operational work.

## Explicit approval and final disposition

Approve one **M03 — Consolidated Implementation** containing only the boundaries above, followed by **M04 — Final Security Verification & Release Readiness**. Approval must explicitly accept:

- The bounded Option A scope instead of a Login Transaction Flow; no long/cosmetic URL.
- I2, I3 and full I4 remain unmet; I1 full-flow binding remains unimplemented. Their original requirements and failed evidence are preserved, not reduced or certified.
- The versioned pending format requires fresh login for old/missing revisions; profile MFA, existing password/limiter/Turnstile and supplier rules remain.
- SQL final-write admission/checked failure response, including cap enforcement on authenticated persistence, one new failure event and the coupled default-off flag.
- Database-only enabled support, separate production enablement/rollback acceptance and mandatory M04 verification. File-mode residuals and already-issued-response limits remain explicit.

**GO FOR APPROVAL: the bounded M03 contract is ready to review; implementation has not started. NO GO for a full Login Transaction Flow or production enablement.** If approval requires full I1–I7 rather than this explicitly narrower alternative, do not execute this M03 contract or describe it as compliant. This is the final M02BC architecture disposition; no additional planning mission is created.

Resolution verification: live remote/HEAD match the SHA above; the complete 51,477-byte historical report prefix and all twelve other initially dirty/untracked files remain byte-identical. All 1,284 runtime/test files in the resolution snapshot are unchanged. UTF-8, whitespace, link/scope and `git diff --check` checks pass. Verification in this resolution is read-only source/Git/reference review and document checks only; no PHP application, database, PHPUnit, browser or previous characterization run was started. The proposed M03 behavior retains NOT IMPLEMENTED / NOT EXECUTED status.
