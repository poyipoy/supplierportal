# Phase 6 — Accent Color Customization

## Status

**IMPLEMENTATION PLAN**

Target repository: `C:\laragon\www\adasi_portal_supplier`

Target branch: `customization-update`

Execution environment: **Antigravity**

Recommended skill stack:

- [orch-add-feature](slashCommand;orch-add-feature)
- [boost](slashCommand;boost)
- [search-first](slashCommand;search-first)
- [laravel-patterns](slashCommand;laravel-patterns)
- [laravel-tdd](slashCommand;laravel-tdd)
- [laravel-verification](slashCommand;laravel-verification)
- [laravel-security](slashCommand;laravel-security)
- [laravel-security-audit](slashCommand;laravel-security-audit)
- [vulnerability-scanner](slashCommand;vulnerability-scanner)
- [backend-security-coder](slashCommand;backend-security-coder)
- [accessibility](slashCommand;accessibility)

Execution principles:

**Evidence → Correctness → Verification → Simplicity → Maintainability**

**Understand first → Change minimally → Verify explicitly**

---

# 1. Goal

Add **Accent Color** as a new account-level preference under `Profile → Customization`.

The feature must let users choose from a finite, trusted set of repository-backed accent presets while preserving:

- Light / Dark / System theme behavior,
- semantic status colors,
- Dashboard customization,
- Regional preferences,
- Notification preferences,
- Security/Auth behavior,
- unrelated dirty work.

This is **not** a visual redesign.

---

# 2. Product Decisions

Accent Color must:

- use a trusted preset list,
- use stable preset keys,
- default to the application's current accent,
- persist account-wide,
- work with Light / Dark / System,
- reset with Customization reset,
- avoid first-paint accent flash,
- satisfy accessibility contrast requirements.

Do **not** add:

- arbitrary hex input,
- color picker,
- user-provided CSS,
- custom RGB/HSL input,
- per-role accents,
- per-supplier-context accents,
- per-theme separate accent preferences,
- new package/dependency.

---

# 3. Phase 5 Prerequisite

Gate 1 must verify Phase 5 is fully finalized before Phase 6 implementation.

Verify:

- branch,
- HEAD,
- latest commit,
- upstream,
- local/remote parity,
- staged count,
- dirty tree baseline.

If Phase 5 is not committed and pushed:

**STOP.**

Do not mix Phase 5 and Phase 6 in one commit.

---

# 4. Default Accent

The default preset must reproduce the current pre-Phase-6 appearance.

A legacy user with no Accent Color preference must see no unexpected color change.

Unknown / null / stale values must fall back safely to default.

---

# 5. Preset Policy

Gate 1 must audit the actual design system before locking presets.

Potential examples only:

- Default
- Blue
- Indigo
- Violet
- Emerald
- Amber
- Rose

Final set must be repository-backed and accessibility-tested.

Recommended final size: **4–7 presets**.

Do not implement a preset that fails contrast in either Light or Dark mode.

---

# 6. Semantic Color Boundary

Accent must affect interactive emphasis only.

Do not recolor semantic states such as:

- success,
- warning,
- danger,
- error,
- rejected,
- overdue,
- paid,
- failed,
- passed,
- QC states,
- invoice workflow states,
- claim statuses,
- validation errors.

The user must never confuse accent personalization with business status meaning.

---

# 7. Candidate Accent Surfaces

Gate 1 must inventory actual shared styling for:

- primary buttons,
- active sidebar/navigation,
- selected tabs,
- links where the current brand accent is used,
- checkbox/radio selected state,
- focus ring,
- pagination active state,
- non-semantic interactive badges,
- dashboard customization controls.

Do not blindly replace every blue/brand utility class.

Classify each current color as:

- accent,
- semantic,
- neutral,
- component-specific.

---

# 8. Theme Compatibility

One Accent Color preference must work across:

- Light,
- Dark,
- System → Light,
- System → Dark.

Accent should remain stable while theme changes.

Example concept:

`emerald` remains the user's preset, while trusted token values adapt for Light/Dark rendering.

Do not store separate accent values per theme.

---

# 9. Persistence

Reuse the current Customization / `UserPreference` architecture.

Gate 1 must inspect:

- `UserPreference`,
- `UserPreferenceService`,
- Customization controller/request,
- Customization route,
- Customization Blade,
- existing JS preference bootstrap,
- reset behavior,
- concurrency / revision logic.

Do not create a separate accent table or service unless source evidence proves unavoidable.

---

# 10. Migration Policy

Preferred result:

**NO MIGRATION**

But Gate 1 must prove it.

If current storage cannot safely persist one new stable accent key:

**STOP at Gate 1** and report the exact migration requirement.

Do not implement a migration without explicit approval.

---

# 11. Trusted Registry

Accent presets should be declared in one authoritative server-side registry.

Preferred approach:

- reuse existing customization config if present,
- otherwise create a bounded customization config only if justified.

Each preset should define repository-appropriate trusted metadata such as:

- stable key,
- label,
- Light token values,
- Dark token values,
- foreground token,
- hover/active/focus variants if required.

Persist only the stable key.

Never persist raw CSS values from request input.

---

# 12. Styling Architecture

Gate 1 must determine the current styling model:

- Tailwind utilities,
- CSS variables,
- shared component classes,
- dark-mode selectors,
- early theme bootstrap,
- compiled CSS entrypoints.

Preferred implementation is one shared accent-token layer.

If CSS custom properties already exist, extend them.

Do not introduce a parallel styling system.

---

# 13. Early Bootstrap / No Flash

Accent must be applied early enough to avoid visible default-accent flash.

Gate 1 must inspect current early preference application for:

- theme,
- sidebar,
- density,
- other initial UI state.

Preferred: extend the existing bootstrap safely.

Do not create a second independent bootstrap script unless necessary.

Invalid accent values must resolve to default before use.

Do not weaken CSP.

---

# 14. Customization UI

Add a section:

**Accent Color**

Preferred accessible control:

- native radio group,
- visible preset label,
- small visual swatch,
- explicit selected state not based on color alone.

Do not create an inaccessible custom color grid.

The swatch must not be the only way to identify the option.

---

# 15. Live Preview Decision

Gate 1 must decide whether live preview should exist.

Preferred rule:

- if current Customization already has safe live preview infrastructure, Accent Color may reuse it,
- otherwise use normal explicit Save behavior.

Do not create a complicated unsaved-state engine solely for Accent Color.

If preview is implemented:

- it remains client-side until Save,
- reload/cancel restores persisted state,
- failed save restores persisted state.

---

# 16. Validation

Server must accept only allowlisted preset keys.

Reject:

- unknown keys,
- raw hex,
- rgb/hsl values,
- CSS variable maps,
- class names,
- HTML,
- unrelated preference fields.

Current user only.

Preserve CSRF and ownership behavior.

---

# 17. Reset

Existing **Reset Customization** must restore Accent Color to default.

It must not reset:

- Notification preferences,
- Security/Auth data,
- unrelated application state.

Reuse existing Customization reset semantics.

---

# 18. Cross-Preference Preservation

Saving Accent Color must preserve:

- Theme,
- Density,
- Sidebar,
- Rows per page,
- Quick Access,
- Dashboard layout,
- Regional preferences,
- Notification preferences.

Saving another preference must preserve Accent Color.

No lost updates.

---

# 19. Concurrency

Reuse current row locking / revision / retry behavior.

Add representative concurrency coverage for Accent Color combined with another preference update.

Do not create a second concurrency mechanism.

---

# 20. Security Boundary

Review with:

- [laravel-security](slashCommand;laravel-security)
- [laravel-security-audit](slashCommand;laravel-security-audit)
- [vulnerability-scanner](slashCommand;vulnerability-scanner)
- [backend-security-coder](slashCommand;backend-security-coder)

Verify protection against:

- CSS injection,
- stored XSS,
- arbitrary class/style injection,
- cross-user writes,
- mass assignment,
- CSP weakening,
- malformed persisted values.

Trusted registry is authoritative.

---

# 21. Accessibility Boundary

Review with:

[accessibility](slashCommand;accessibility)

Verify:

- radio semantics,
- keyboard operation,
- visible focus,
- text labels,
- selected state not color-only,
- target sizing,
- 320 CSS px reflow,
- 400% equivalent reflow,
- Light/Dark contrast.

Every final preset must pass actual rendered combinations.

If a preset fails, adjust trusted tokens or remove the preset.

Do not lower accessibility requirements.

---

# 22. Performance

Targets:

- no extra recurring endpoint,
- no polling,
- no query per component,
- no runtime DB lookup from CSS,
- no N+1,
- no additional user-preference query if current snapshot is already loaded.

Measure Customization page query count before/after where practical.

---

# 23. Dashboard Boundary

Accent may visually affect shared generic controls.

Do not modify:

- widget registry,
- widget order,
- widget visibility semantics,
- dashboard role composition,
- dashboard persistence model.

---

# 24. Notifications Boundary

Phase 5 behavior must remain intact.

Do not modify:

- notification preference registry,
- delivery suppression,
- bell/polling,
- unread count,
- notification routing.

Generic shared styling may inherit Accent Color only where non-semantic.

---

# 25. Security Page Boundary

Do not alter:

- Change Password,
- 2FA,
- Active Sessions,
- Logout Other Devices,
- opaque session revocation token,
- Security routes/business logic.

Only generic shared visual token inheritance is allowed.

---

# 26. Regional / BusinessTime Boundary

Do not touch:

- TD-REG-01,
- TD-REG-02,
- TD-REG-03,
- TD-REG-04,
- BusinessTime,
- `@bizdt`,
- timezone experiments,
- Regional formatting implementation.

---

# 27. Language Preference Boundary

Do not implement Language Preference in Phase 6.

Language/localization is deferred to a separate future phase.

---

# 28. Dirty Working Tree Discipline

Before changing any candidate file:

- inspect its existing diff,
- classify unrelated hunks,
- preserve them exactly.

Never:

- reset,
- clean,
- stash,
- restore/discard unrelated changes,
- broad-format mixed files,
- switch branch.

No staging before Gate 2 approval.

---

# 29. Gate 1 Required Audit

Gate 1 must inspect:

## Preference architecture

- model,
- service,
- controller,
- request,
- routes,
- Blade,
- JS/bootstrap,
- reset,
- tests,
- concurrency.

## Styling architecture

- CSS entrypoints,
- Tailwind/config if present,
- theme tokens,
- dark mode,
- primary buttons,
- nav/sidebar active styles,
- focus styles,
- form controls,
- pagination,
- links,
- dashboard controls.

## Repository state

- branch,
- HEAD,
- upstream,
- ahead/behind,
- tracked modified,
- untracked,
- staged,
- mixed candidate files.

Gate 1 is **research only**.

---

# 30. Gate 1 Accent Inventory

Return:

| Surface | Current token/class/value | Shared? | Semantic or Accent? | Proposed Action |
|---|---|---|---|---|

At minimum cover:

- primary button,
- sidebar active item,
- nav active item,
- link,
- checkbox,
- radio,
- focus ring,
- pagination active state,
- tabs,
- dashboard interactive state.

---

# 31. Gate 1 Preset Proposal

After source inspection, propose the exact final presets:

| Key | Label | Light base | Dark base | Foreground | Contrast status | Notes |
|---|---|---|---|---|---|---|

Default must equal the current application accent.

Prefer 4–7 presets.

Exclude inaccessible presets.

---

# 32. Gate 1 Mandatory Decisions

Gate 1 must answer:

1. Is Phase 5 finalized/pushed?
2. Where are current Customization preferences stored?
3. Is migration required?
4. What exact accent key will be stored?
5. What is the current default accent source?
6. Which surfaces are truly accent-driven?
7. Which colors are semantic and protected?
8. What final preset list is accessible?
9. What token architecture should be used?
10. How does Light/Dark/System resolve accent?
11. Can early bootstrap be extended safely?
12. Does live preview fit existing architecture?
13. How does reset behave?
14. How is concurrency protected?
15. What exact production files are needed?
16. What exact test files are needed?
17. Which candidate files are mixed/dirty?
18. Is any package required?
19. Does CSP need any change?
20. What is the risk level?

---

# 33. Gate 1 Required Output

Return exactly:

# Gate 1 — Phase 6 Accent Color Customization

Status: PLAN ONLY; waiting for approval.

## 1. Repository Baseline

## 2. Phase 5 Prerequisite Verification

## 3. Existing Customization Architecture

## 4. Existing Preference Storage

## 5. Existing Early Bootstrap

## 6. Existing Theme Architecture

## 7. Existing Accent / Primary Token Inventory

## 8. Semantic Color Boundary

## 9. Proposed Accent Token Architecture

## 10. Proposed Preset Registry

## 11. Default Accent Decision

## 12. Customization UI Design

## 13. Live Preview Decision

State exactly:

- IMPLEMENT
or
- DO NOT IMPLEMENT

with repository-backed reason.

## 14. Persistence Design

## 15. Validation Design

## 16. Reset Design

## 17. Cross-Preference Preservation

## 18. Concurrency Design

## 19. Early Bootstrap / No-Flash Design

## 20. Light / Dark / System Behavior

## 21. Primary Button Integration

## 22. Sidebar / Navigation Integration

## 23. Form-Control / Focus Integration

## 24. Dashboard Integration Boundary

## 25. Notification Boundary

## 26. Security Page Boundary

## 27. CSP Analysis

## 28. Security Findings

## 29. Accessibility Findings

## 30. Performance Findings

## 31. Database Decision

Expected: **NO MIGRATION**

If migration is required: STOP.

## 32. Dependency Decision

Expected: **NO NEW PACKAGE**

## 33. Exact Production Files

Separate:

- New
- Modified
- Deleted

## 34. Exact Test Files

Separate:

- New
- Modified
- Reused

## 35. Dirty-Tree Overlap

## 36. TDD Matrix

## 37. Browser Verification Plan

## 38. Static / Build Verification Plan

## 39. Explicit Deferred Items

## 40. Risk Classification

## 41. Phase 6 Definition of Done

## 42. Gate 1 Recommendation

Do NOT implement.

Do NOT modify files.

Do NOT create files.

Do NOT delete files.

Do NOT stage.

Do NOT commit.

Do NOT push.

End exactly with:

STOP: waiting for `Approve Gate 1 — Phase 6`.

---

# 34. TDD Matrix Requirements

Gate 1 must provide:

| Slice | Initial RED / Characterization | Expected GREEN | Regression Protection |
|---|---|---|---|

At minimum:

1. default accent,
2. valid preset save,
3. invalid preset rejection,
4. raw CSS rejection,
5. persistence,
6. cross-preference preservation,
7. reset,
8. Light theme,
9. Dark theme,
10. System theme,
11. early bootstrap/no-flash contract,
12. selector accessibility,
13. preset contrast,
14. primary button,
15. sidebar/nav active state,
16. checkbox/radio/focus state,
17. dashboard regression,
18. Notifications regression,
19. Security regression,
20. concurrency,
21. query count,
22. dirty-tree protection.

---

# 35. Implementation Sequence After Approval

After explicit Gate 1 approval only:

1. characterization tests,
2. RED persistence/validation tests,
3. trusted preset registry,
4. backend preference save/reset integration,
5. shared accent token layer,
6. Light/Dark token resolution,
7. early bootstrap integration,
8. Customization selector,
9. optional live preview only if Gate 1 approved it,
10. concurrency/cross-preference tests,
11. accessibility verification,
12. security review,
13. focused regression,
14. frontend/build verification,
15. browser verification,
16. full tracked regression,
17. Gate 2,
18. STOP before staging.

No Phase 6A/6B/6C.

---

# 36. Characterization Before Production Changes

Lock current behavior for:

- default current accent,
- Light,
- Dark,
- System,
- primary button,
- active sidebar/nav,
- form focus,
- Customization reset,
- existing preference persistence.

The default Phase 6 preset must reproduce this behavior.

---

# 37. Backend TDD

Prove:

- valid preset persists,
- invalid preset rejected,
- raw hex rejected,
- CSS payload rejected,
- current user only,
- User A cannot modify User B,
- unrelated preferences preserved,
- reset returns accent to default,
- stale/invalid stored key safely falls back.

---

# 38. Frontend / Token Verification

Where current tooling supports it, verify:

- trusted preset maps to trusted tokens,
- default equals legacy current appearance,
- Light/Dark variant resolution,
- System theme switching,
- early bootstrap application,
- invalid stored accent fallback.

Avoid brittle compiled-CSS assertions unless the project already uses them.

---

# 39. Browser Matrix

Verify:

## Themes

- Light
- Dark
- System → Light
- System → Dark

## Presets

- Default
- every final approved preset

## Surfaces

- Customization page
- primary button
- sidebar/nav active state
- form control/focus
- dashboard
- Notifications page
- Security page

No preset may be considered complete if it fails a supported theme.

---

# 40. Static / Build Verification

Run:

- PHP lint on changed PHP files,
- scoped Pint,
- scoped `git diff --check`,
- route list only if routes changed,
- view cache,
- relevant JS tests,
- frontend build,
- CSS/token verification.

Do not fix unrelated repository-wide findings.

---

# 41. Focused Regression

Run focused tests for:

- Customization,
- dashboard preferences,
- Regional preservation,
- Notification preference preservation,
- account navigation,
- Security page,
- frontend assets,
- concurrency.

Report exact:

- tests,
- assertions,
- failures,
- errors,
- skipped,
- duration,
- exit code.

---

# 42. Full Tracked Regression

After focused tests pass:

- verify isolated test database,
- enumerate all tracked PHP test files from Git,
- run all tracked tests serially,
- do not manually exclude tracked tests.

Untracked BusinessTime/timezone experiments remain separate.

---

# 43. Phase 6 Definition of Done

Phase 6 is complete only when:

## Product

- Accent Color exists under Customization.
- Presets are finite and trusted.
- No arbitrary color input exists.
- Default matches previous application appearance.
- Preference persists account-wide.
- Reset restores default.

## Theme

- Light passes.
- Dark passes.
- System passes.
- Accent remains stable across theme changes.
- No unacceptable first-paint accent flash.

## Semantics

- interactive emphasis follows accent,
- semantic status colors remain unchanged.

## Security

- allowlisted preset keys only,
- no CSS injection,
- no XSS,
- current-user-only update,
- CSRF preserved,
- CSP not weakened.

## Accessibility

- selector keyboard accessible,
- text labels present,
- selected state not color-only,
- focus visible,
- all presets pass required contrast,
- mobile/reflow passes.

## Persistence

- unrelated preferences preserved,
- reset scoped correctly,
- concurrency safe,
- invalid stored values fall back.

## Performance

- no query per component,
- no extra recurring request,
- no polling,
- no N+1.

## Regression

- Dashboard intact,
- Regional intact,
- Notifications intact,
- Security intact,
- Phase 5 behavior intact,
- tracked regression passes.

## Scope

- no Language Preference,
- no Notification redesign,
- no Security redesign,
- no BusinessTime work,
- no Regional remediation,
- no new dependency.

Final status:

**PHASE 6 COMPLETE**

No Phase 6A.
No Phase 6B.
No Phase 6C.

---

# 44. Gate 2 Required Output

Return:

# Gate 2 — Phase 6 Accent Color Customization

## 1. Status

Expected:

PHASE 6 COMPLETE

## 2. Repository State

Branch:
HEAD:
Upstream:
Tracked modified:
Untracked:
Staged:

## 3. Final Accent Architecture

## 4. Final Preset Registry

| Key | Label | Light | Dark | Contrast result |
|---|---|---|---|---|

## 5. Default Accent

## 6. Preference Storage

## 7. Validation

## 8. Customization UI

## 9. Early Bootstrap / No Flash

## 10. Theme Matrix

## 11. Primary Button Integration

## 12. Sidebar / Navigation Integration

## 13. Form / Focus Integration

## 14. Semantic Color Protection

## 15. Reset Behavior

## 16. Cross-Preference Preservation

## 17. Concurrency

## 18. Security Review

## 19. Accessibility Review

Separate:

- source/automated
- browser/manual

## 20. Performance Measurements

## 21. Exact Production Files

New:
Modified:
Deleted:

## 22. Exact Test Files

New:
Modified:
Reused:

## 23. Dirty-Tree Protection

## 24. Tests Executed

## 25. Focused Results

Tests:
Assertions:
Failures:
Errors:
Skipped:
Duration:
Exit code:

## 26. Frontend / JS / CSS Verification

## 27. Tracked Project Regression

Tracked files:
Tests:
Assertions:
Failures:
Errors:
Skipped:
Duration:
Exit code:

## 28. Static / Build Verification

## 29. Browser Verification

## 30. Database Decision

Expected: NO MIGRATION

## 31. Dependency Decision

Expected: NO NEW PACKAGE

## 32. Explicit Deferred Items

Include:

Language Preference remains deferred.

## 33. Scope Protection

Confirm no:

- notification architecture changes,
- auth/security business changes,
- BusinessTime work,
- Regional remediation,
- dashboard business-logic changes,
- external dependencies.

## 34. Phase 6 Closure Decision

Expected:

PHASE 6 COMPLETE

Do NOT stage.
Do NOT commit.
Do NOT push.

Wait for explicit Gate 2 approval.

---

# 45. Finalization After Gate 2

Only after explicit Gate 2 approval:

1. inspect exact diff,
2. classify mixed files,
3. selectively stage Phase 6 only,
4. audit cached diff,
5. run cached diff check,
6. focused final verification,
7. one Phase 6 commit,
8. normal push,
9. verify local/remote parity,
10. preserve unrelated dirty work.

Never use:

- `git add .`
- `git add -A`
- `git commit -a`
- force push.

Suggested commit candidate:

`feat: add accent color customization`

Final status after successful push:

**PHASE 6 COMPLETE AND PUSHED**
