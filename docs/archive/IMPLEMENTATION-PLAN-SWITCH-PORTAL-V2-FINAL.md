# ADASI PORTAL — IMPLEMENTATION PLAN
# Switch Portal V2 — Unified Portal Context Switcher

**Repository:** `https://github.com/poyipoy/supplierportal`
**Base Branch:** `master`
**Target Branch:** `feature/switch-portal-v2`
**Planning Baseline:** `master` at commit `865337b` (as established during analysis)
**Implementation Mode:** Plan-first, then execution after plan acceptance
**Primary Objective:** Improve the portal-switching experience for authorized dual-scope suppliers without changing role semantics or the authorization model.

---

## 0. Executive Decision

The existing Switch Portal mechanism is functionally secure and already treats `supplier_scopes` as the real authorization source. V2 therefore does **not** redesign authorization. It improves the context-selection UX, makes the active business context visible, strengthens stale-context recovery, and removes avoidable ambiguity in shared pages.

The user-facing terminology is also corrected:

| Technical scope | User-facing portal name |
|---|---|
| `import` | **Material Procurement** |
| `local` | **Local Supplier** |

Do **not** rename the technical scope values, route namespaces, or role values as part of this implementation.

The resulting mental model is:

```text
User role = supplier
        ↓
Authorized business scopes (DB: supplier_scopes)
        ↓
Current portal context (session: supplier_context)
        ↓
Portal UI / navigation
```

**Switching a portal is context switching, not role switching and not privilege escalation.**

---

# 1. Confirmed Product Requirements

These requirements are decisions made during the requirements interview and supersede any conflicting suggestion in earlier analysis.

## 1.1 Eligible users

Only suppliers who possess **both** authorized scopes may use the portal switcher:

```text
role = supplier
AND
scope = import
AND
scope = local
```

Single-scope suppliers must not see the switcher.

## 1.2 UI placement

The switcher belongs in the **branding/header area**, not as a normal navigation item in the sidebar menu.

Target concept:

```text
ADASI Supplier Portal
[ Local Supplier ▾ ]
```

Opening the control exposes the available authorized portals:

```text
Portal
────────────────────────
✓ Local Supplier
  Material Procurement
```

## 1.3 User-facing names

Use:

- `Material Procurement`
- `Local Supplier`

Avoid `Import Supplier Portal`, because the `import` technical scope represents the material-procurement workflow and does not necessarily mean that the supplier itself is an import supplier.

## 1.4 Switching interaction

Use a compact dropdown with direct POST actions.

No AJAX is required.

No new JavaScript dependency is allowed.

The existing Bootstrap dropdown idiom should be reused.

A two-step interaction is acceptable as long as it is clear, but the preferred V2 behavior is effectively one interaction path:

```text
Current Portal dropdown
        ↓
Choose target portal
        ↓
POST switch
        ↓
Target portal dashboard
```

## 1.5 Switch destination

Use **Always Dashboard**.

Do not implement logical-page preservation or equivalent-page mapping.

Reason: Material Procurement and Local Supplier are structurally different operational domains, so page-to-page mapping would add complexity without sufficient user value.

## 1.6 Active portal visibility

The active portal must be visible persistently in the branding/header area on relevant pages.

The dropdown must also visually identify the active item.

## 1.7 Scope revocation behavior

If an administrator changes/removes supplier scope authorization, the affected account must not continue using the stale portal context.

The agreed behavior is:

```text
scope authorization materially changes
        ↓
active session is invalidated / user is required to re-authenticate
        ↓
login
        ↓
portal is resolved from current authorized scopes
```

This is intentionally stronger than merely redirecting a stale request to `/dashboard`.

## 1.8 Multi-tab behavior

Keep `supplier_context` session-level for V2.

Do **not** introduce per-tab context storage.

However, shared pages must not make domain-sensitive UI decisions solely from potentially stale session context when no portal-specific route context exists.

---

# 2. Scope and Non-Goals

## 2.1 In scope

1. Portal switcher UX redesign.
2. Persistent active portal indicator.
3. Direct portal switching from the branding/header area.
4. User-facing naming correction from `Import Supplier Portal` to `Material Procurement`.
5. Thin centralization of current/switch behavior where it genuinely reduces duplication.
6. Stale-context handling consistent with re-authentication behavior.
7. Shared-page context ambiguity hardening.
8. Regression tests for authorization, switching, stale context, and UI state.
9. Full Laravel regression verification after implementation.

## 2.2 Explicitly out of scope

- Renaming DB scope value `import`.
- Renaming route namespace `supplier.*`.
- Renaming `local-supplier.*`.
- Changing the `supplier` role.
- Introducing a generic multi-role switcher for finance, purchasing, GA, QC, or admin.
- Changing supplier scope assignment semantics.
- Introducing `config/supplier_portals.php` or a generic portal registry.
- Introducing a service container / plugin registry solely for this feature.
- Preserve Logical Destination behavior.
- Per-tab context architecture.
- New JavaScript dependencies.
- Database migration for the portal switch feature itself.
- Browser E2E automation.
- Unrelated UI redesign.

---

# 3. Existing Architecture — Preserve These Invariants

The following are architectural invariants and must remain true after implementation.

## 3.1 Authorization source of truth

`supplier_scopes` in the database remains the authoritative source for whether a supplier may access `import` or `local`.

The session value:

```text
session('supplier_context')
```

must never become an authorization source.

## 3.2 Role immutability during switch

Portal switching must never modify:

```text
users.role
```

A supplier remains:

```text
role = supplier
```

regardless of current portal context.

## 3.3 Existing route boundaries remain authoritative

The following route/middleware pattern remains intact:

```text
supplier.*
    auth
    role:supplier
    supplier.scope:import

local-supplier.*
    auth
    role:supplier
    supplier.scope:local
```

## 3.4 Direct URL access remains protected

Typed URLs, bookmarks, notifications, stale links, or manipulated session values must not bypass DB-backed scope authorization.

## 3.5 Technical identifiers remain stable

Do not rename:

```text
import
local
supplier.*
local-supplier.*
supplier_context
```

Only the human-facing presentation changes.

---

# 4. Target Behavior Model

## 4.1 Dual-scope supplier

Example:

```text
User role:
supplier

Authorized scopes:
import
local

Current context:
local
```

UI:

```text
[ Local Supplier ▾ ]
```

Dropdown:

```text
Portal
────────────────────
✓ Local Supplier
  Material Procurement
```

Selecting Material Procurement:

```text
POST /supplier-context
context=import
        ↓
server validates supplier scope
        ↓
session context = import
        ↓
redirect Material Procurement dashboard
```

## 4.2 Single-scope supplier

Example:

```text
role = supplier
scope = local
```

Behavior:

- No switcher rendered.
- `/dashboard` resolves directly to Local Supplier.
- Direct `import` URLs remain blocked by scope middleware.

## 4.3 Invalid/stale session context

Example:

```text
session context = local
actual authorized scopes = import
```

The stale session must not be treated as authority.

After re-authentication, the system resolves context from the current authorized scopes.

## 4.4 Zero-scope supplier

Example:

```text
role = supplier
scopes = []
```

The account is invalid/misconfigured for portal access.

Do not manufacture a default portal context.

Expected behavior remains a safe authorization failure rather than a redirect loop.

---

# 5. Shared Pages and Multi-Tab Contract

This area requires special care because session context is shared between tabs.

Example:

```text
Tab A → Local Supplier
Tab B → Material Procurement
```

Both tabs share the same session cookie.

The following principle must be enforced:

### Portal-specific routes

Route namespace is authoritative for presentation context.

```text
local-supplier.* → Local Supplier
supplier.*       → Material Procurement
```

### Shared routes

Examples include:

```text
/profile
/notifications
/shared routes
```

Do not let shared-page domain-sensitive UI depend blindly on `session('supplier_context')` when that could reflect another tab's most recent switch.

For V2:

- Keep shared functionality shared.
- Do not introduce per-tab state.
- Do not use stale session context to grant access.
- Where a shared component needs a portal label, derive it through a centralized, validated context helper rather than direct `session()` calls throughout Blade.

This is a UX consistency rule, not a substitute for route authorization.

---

# 6. Proposed Code Design

## 6.1 `PortalContext` responsibilities

**File:** `app/Support/PortalContext.php`

Keep the existing class as the central context resolver instead of creating a new service unless repository inspection proves the class has become an inappropriate boundary.

Target responsibilities:

```text
current(User)
resolve(User)
dashboard(User)
switchTo(Request, scope)
isLocal(User)
```

The implementation should remain minimal.

### `current()` requirement

It should not become a raw session wrapper that forces Blade to repeat fallback rules.

Preferred behavior:

```text
current(user)
    ↓
validated current context
    ↓
return context or null
```

The exact implementation must follow existing project conventions and must not accidentally convert session state into authorization.

### `switchTo()` requirement

Centralize the existing validation-and-write operation:

```text
validate requested scope
→ confirm user is authorized for scope
→ write session context
```

Do not move authorization into the UI layer.

## 6.2 `SupplierContextController`

**File:** `app/Http/Controllers/SupplierContextController.php`

Responsibilities:

- Accept requested context.
- Delegate authorization/context storage to `PortalContext`.
- Redirect to the target portal dashboard.

Avoid duplicated validation logic if `PortalContext::switchTo()` becomes the established boundary.

Existing route names must remain unchanged.

## 6.3 `SupplierScopeMiddleware`

**File:** `app/Http/Middleware/SupplierScopeMiddleware.php`

This middleware remains an authorization boundary.

Important distinction:

```text
Session context = state/hint
DB supplier scope = authorization
```

Do not weaken the DB scope check.

For stale/revoked access, the selected behavior is re-authentication at the account-security level, not a generic redirect-only workaround.

Do not introduce a redirect that could produce incorrect responses for JSON/AJAX requests.

If the repository's account-security/session-version mechanism is the established implementation for access-right changes, integrate with that existing mechanism rather than inventing a second invalidation mechanism.

## 6.4 Admin scope changes

**File:** `app/Http/Controllers/Admin/UserController.php` or the actual canonical scope-assignment boundary discovered during implementation.

When a supplier's authorization scope changes:

- Ensure the account's active sessions are invalidated using the project's established security-session mechanism.
- Avoid changing unrelated role/password session behavior.
- Do not invent a new session invalidation subsystem.

This requirement exists because scope removal changes authorization state.

## 6.5 Sidebar / branding area

**File:** `resources/views/partials/sidebar.blade.php`

Replace the standalone `Switch Portal` navigation item with the compact current-context control in the branding/header area.

The switcher must:

- render only for dual-scope suppliers;
- show the active human-facing portal name;
- expose both authorized portals;
- visually mark the current portal;
- submit directly to the existing switch endpoint;
- preserve CSRF protection;
- use existing UI/icon/dropdown patterns.

Do not add a separate JavaScript framework.

## 6.6 Fallback context page

**File:** `resources/views/local-supplier/context.blade.php`

Keep the route and page available as a safe fallback/landing surface.

Update its labels and active-state presentation to use the same terminology:

```text
Material Procurement
Local Supplier
```

Do not create two visually contradictory naming systems.

---

# 7. UX Specification

## 7.1 Branding/header state

Preferred visual hierarchy:

```text
ADASI
Supplier Portal

[ Local Supplier ▾ ]
```

The implementation must respect the existing sidebar dimensions, typography, spacing, and component conventions.

## 7.2 Dropdown state

Collapsed:

```text
[ Local Supplier ▾ ]
```

Expanded:

```text
Portal
────────────────────
✓ Local Supplier
  Material Procurement
```

Switching to the already-active portal may remain a no-op from the user's perspective. Do not introduce a confirmation modal unless existing interaction patterns require it.

## 7.3 Copy

Use business-oriented terminology:

- `Local Supplier`
- `Material Procurement`
- `Portal`

Avoid:

- `Import Supplier Portal`
- `Import Supplier` when referring to the workflow context
- technical scope names as primary end-user labels

Optional descriptions may be used only when they fit the existing design system and do not make the control unnecessarily large.

Recommended descriptions:

```text
Material Procurement
Quotation, PO, Shipment

Local Supplier
Invoice, Vendor Profile
```

Descriptions are optional; the main labels are mandatory.

---

# 8. Route and Context Resolution Rules

The following behavior must be explicitly preserved/tested:

| Situation | Expected result |
|---|---|
| Dual-scope supplier opens `/dashboard` with no valid context | Context selection / safe context resolution |
| Dual-scope supplier switches to Local | Local Supplier dashboard |
| Dual-scope supplier switches to Material Procurement | Material Procurement dashboard |
| Local-only supplier opens `/dashboard` | Local Supplier dashboard |
| Import-only supplier opens `/dashboard` | Material Procurement dashboard |
| Single-scope supplier cannot use another scope | Authorization failure |
| Scope is revoked | Session invalidation / re-authentication |
| Session contains forged scope | Must not grant access |
| Direct URL to unauthorized portal | Must be blocked |
| Zero-scope supplier | Safe authorization failure |
| Shared page | Must not grant domain access from session context |

No route names change.

---

# 9. Testing Strategy

Testing should be repository-level and Laravel-native. Browser automation is explicitly not required.

## 9.1 Existing tests to inspect first

Before writing new tests, locate and reuse existing coverage for:

- `SupplierContextController`;
- `PortalContext`;
- `SupplierScopeMiddleware`;
- `LocalInvoiceScopeIsolationTest`;
- supplier scope assignment / authorization;
- security session-version behavior.

Do not duplicate an existing scenario under another test name unless the behavior contract genuinely changes.

## 9.2 Required regression scenarios

### Context switching

1. Dual-scope supplier can select Local.
2. Dual-scope supplier can select Material Procurement.
3. Session context is updated only to an authorized scope.
4. Switching does not change `users.role`.
5. Switching does not modify supplier scope rows.
6. Switching redirects to the correct dashboard.

### Authorization

7. Local-only supplier cannot switch to Material Procurement.
8. Import-only supplier cannot switch to Local.
9. Forged/invalid context value is rejected.
10. Direct unauthorized route access remains blocked.
11. Inactive supplier cannot use the switcher.
12. Zero-scope supplier does not enter a redirect loop.

### Scope change / session invalidation

13. Removing one scope invalidates active authorization state according to the established security mechanism.
14. Re-authentication resolves the remaining valid scope.
15. Removing all scopes does not manufacture a default context.
16. Adding a scope does not silently elevate a different role or permission set.

### Shared-page / multi-tab behavior

17. Route-specific pages use route context correctly.
18. Shared pages do not grant access based on session context.
19. Direct navigation after another-tab context switch remains authorized only by DB scope.
20. Context presentation does not produce cross-core leakage.

### UI rendering

21. Dual-scope supplier sees the switcher.
22. Single-scope supplier does not see the switcher.
23. Active portal is correctly marked.
24. Human-facing labels are `Material Procurement` and `Local Supplier`.
25. Old `Import Supplier Portal` wording is not rendered by the new switcher.
26. Existing `/supplier-context` fallback page uses the same naming and active-state rules.

## 9.3 Test quality requirements

Each test must prove behavior, not merely execute the code path.

Prefer:

```text
Arrange
→ Act
→ Assert observable authorization/state/redirect
```

Avoid tests that merely assert the presence of a method or implementation detail unless the implementation detail itself is a security invariant.

---

# 10. Implementation Workstreams

## WS-01 — Repository Reconnaissance and Baseline Verification

**Goal:** Establish the exact current implementation and identify canonical extension points before modification.

Tasks:

1. Verify branch and baseline.
2. Read root project guidance and relevant security/testing rules.
3. Inspect `PortalContext`.
4. Inspect context controller.
5. Inspect scope middleware.
6. Inspect admin scope assignment/change flow.
7. Inspect sidebar branding area.
8. Inspect existing Bootstrap dropdown patterns.
9. Inspect fallback context view.
10. Locate all existing tests touching supplier context.
11. Verify current session invalidation mechanism.

Exit criteria:

- no unresolved architecture assumption remains for files that will be changed;
- no duplicate switch endpoint is proposed unnecessarily;
- exact canonical security boundary is identified.

## WS-02 — Context Resolution Consolidation

**Goal:** Make portal-context decisions easier to consume without introducing an unnecessary service layer.

Tasks:

1. Add/refine `PortalContext::current()`.
2. Add/refine `PortalContext::switchTo()`.
3. Ensure session is never used as authorization.
4. Keep `dashboard()` behavior compatible.
5. Remove redundant raw switch validation/write logic from callers where appropriate.
6. Add focused tests only where existing tests do not already prove behavior.

Exit criteria:

- one coherent context boundary;
- no route-name regression;
- authorization remains DB-backed.

## WS-03 — Switcher UX

**Goal:** Replace the secondary navigation experience with a clear branding/header control.

Tasks:

1. Add current portal indicator to branding/header area.
2. Replace old standalone `Switch Portal` item.
3. Use Bootstrap dropdown idiom already present in the application.
4. Render only for dual-scope suppliers.
5. Mark active item.
6. Apply user-facing naming.
7. Preserve CSRF and POST semantics.
8. Keep fallback context page.
9. Add minimal CSS only if existing utility/component classes are insufficient.

Exit criteria:

- clear active context;
- direct switching path;
- no duplicate navigation control;
- no unnecessary JS.

## WS-04 — Access-Change Session Security

**Goal:** Ensure scope changes take effect across active sessions using the application's existing security mechanism.

Tasks:

1. Identify the canonical session-version/security-change mechanism.
2. Treat supplier scope authorization changes as security-sensitive.
3. Increment/invalidate session state using the existing architecture where appropriate.
4. Avoid unrelated changes to password/role session logic.
5. Add regression tests for scope removal and re-authentication behavior.

Exit criteria:

- revoked scope cannot remain usable in an already-authenticated session beyond the intended security boundary;
- no custom parallel session invalidation mechanism is created.

## WS-05 — Shared Page Context Hardening

**Goal:** Prevent UI confusion from session-level multi-tab state without redesigning state storage.

Tasks:

1. Audit direct `session('supplier_context')` consumers.
2. Route portal-specific decisions through route-aware context resolution.
3. Ensure shared pages do not make authorization decisions from session context.
4. Centralize portal labeling where necessary.
5. Add regression coverage for shared pages only where a meaningful existing consumer is identified.

Exit criteria:

- shared pages remain shared;
- no portal access is granted based on session state;
- multi-tab behavior is documented and predictable.

## WS-06 — Regression and Verification

**Goal:** Prove the implementation did not regress supplier authorization or portal routing.

Tasks:

1. Run focused context/scope tests.
2. Run security-related supplier isolation tests.
3. Run the full Laravel suite.
4. Run `composer validate --strict`.
5. Run `composer audit`.
6. Run production asset build.
7. Review diff for unrelated changes.
8. Verify working tree status.
9. Verify target branch and commit history.

No browser automation is required.

---

# 11. Files / Areas Expected to Change

Expected, subject to code-first verification:

```text
app/Support/PortalContext.php
app/Http/Controllers/SupplierContextController.php
app/Http/Middleware/SupplierScopeMiddleware.php
app/Http/Controllers/Admin/UserController.php
resources/views/partials/sidebar.blade.php
resources/views/local-supplier/context.blade.php
```

Potential test files:

```text
tests/Feature/LocalInvoice/LocalInvoiceScopeIsolationTest.php
tests/Feature/... existing supplier context/security test(s)
```

The actual list must be based on the current repository. Do not modify a file merely because it appears in this planning list if inspection proves it does not need to change.

---

# 12. Change Isolation Rules

The implementation must not include:

- unrelated Blade cleanup;
- unrelated sidebar refactoring;
- route renaming;
- migration of technical scope values;
- generic portal registry architecture;
- unrelated auth refactoring;
- broad UI redesign;
- dependency additions without an explicit need.

Every changed file must be attributable to one of the workstreams.

---

# 13. Security Acceptance Criteria

The implementation is acceptable only when all statements below remain true:

1. `supplier_scopes` remains the authorization authority.
2. Session context cannot grant unauthorized access.
3. Role remains unchanged by switching.
4. Direct URLs remain protected.
5. Forged context values do not grant access.
6. Scope revocation takes effect according to the agreed re-authentication/security-session model.
7. Inactive suppliers remain blocked.
8. Cross-core data isolation remains intact.
9. Shared-page presentation cannot be interpreted as authorization.
10. No new privilege boundary is introduced in Blade or JavaScript.

---

# 14. UX Acceptance Criteria

The implementation is acceptable only when:

1. Dual-scope supplier clearly sees the active portal.
2. The switcher is placed in the branding/header area.
3. The user-facing labels are exactly/consistently based on:
   - `Material Procurement`
   - `Local Supplier`
4. The active portal is visibly marked.
5. Switching does not require visiting a separate full-page switch screen in the normal flow.
6. Switching always lands on the target portal dashboard.
7. Single-scope suppliers do not see an unnecessary switcher.
8. Existing shared layout remains visually consistent.
9. The control does not introduce a new visual language that conflicts with existing Bootstrap/UI conventions.

---

# 15. Operational Acceptance Criteria

Before declaring completion:

```text
[ ] Baseline verified
[ ] Repository guidance inspected
[ ] Existing context tests identified
[ ] Canonical security-session mechanism identified
[ ] Implementation completed only within approved scope
[ ] Focused tests pass
[ ] Full test suite passes
[ ] composer validate --strict passes
[ ] composer audit passes
[ ] npm build passes
[ ] Diff contains no unrelated files
[ ] Working tree clean
[ ] Final behavior compared against acceptance criteria
```

Do not claim production readiness from source inspection alone. Verification results must reflect commands actually executed.

---

# 16. Risk Matrix

| Risk | Level | Mitigation |
|---|---|---|
| Shared middleware behavior regression | High | Focused + full regression tests |
| Scope revocation/session invalidation regression | High | Reuse established auth-session mechanism + dedicated tests |
| Incorrect current-context indicator | Medium | Centralized context resolver + rendering tests |
| Sidebar layout regression | Medium | Reuse existing dropdown pattern and minimal CSS |
| Shared-page multi-tab confusion | Medium | Avoid domain-sensitive decisions from raw session state |
| Over-engineering portal registry | Medium | Explicitly defer generic config/registry |
| Route/URL compatibility regression | High | Preserve names and test redirects |
| Authorization bypass through forged context | High | Keep middleware DB scope checks and test direct manipulation |

---

# 17. Verification Protocol

Use the following verification order after implementation:

## Layer 1 — Static/source verification

Confirm:

- technical scope values unchanged;
- route names unchanged;
- no role mutation in switch flow;
- no raw session value used as authorization;
- switch UI rendered only for dual-scope users;
- labels use approved terminology.

## Layer 2 — Focused behavior tests

Run the context and authorization tests first.

## Layer 3 — Security regression

Run supplier scope/isolation and session-security tests.

## Layer 4 — Full regression

Run:

```bash
php artisan test
```

## Layer 5 — Dependency/build hygiene

Run:

```bash
composer validate --strict
composer audit
npm.cmd run build
```

Use the project's actual build command if repository guidance specifies a different one.

## Layer 6 — Diff audit

Verify:

```bash
git status --short
git diff --stat
git diff --check
git log --oneline --decorate -n 10
```

Confirm every file changed is attributable to this plan.

---

# 18. Definition of Done

The feature is complete when all of the following are true:

```text
USER EXPERIENCE
✓ Active portal is always understandable
✓ Switch is available directly from branding/header
✓ User-facing terminology is business-correct
✓ Switch leads to target dashboard

AUTHORIZATION
✓ DB scope remains authoritative
✓ Role never changes
✓ Unauthorized scope remains blocked
✓ Forged session context cannot grant access
✓ Scope revocation triggers the agreed session security response

ARCHITECTURE
✓ Existing route names remain intact
✓ No unnecessary abstraction layer is added
✓ No technical `import`/`local` rename is introduced
✓ Shared pages are not authorization-dependent on session context

TESTING
✓ New/updated tests cover all changed behavior
✓ Full suite passes
✓ Composer validation passes
✓ Composer audit passes
✓ Frontend build passes
✓ Diff is clean and scoped
```

---

# 19. Execution Guardrails for the Coding Agent

The implementation agent must:

1. Read the repository's current guidance before changing files.
2. Inspect actual source before accepting any item in this plan as an implementation detail.
3. Prefer the smallest change that satisfies the approved behavior.
4. Reuse existing components and conventions.
5. Not introduce new dependencies for a dropdown.
6. Not rename technical scope values.
7. Not alter user roles through context switching.
8. Not change authorization semantics merely to simplify the UI.
9. Add or update regression tests for every changed behavior.
10. Run verification commands before claiming completion.
11. Report FACT vs INFERENCE vs ASSUMPTION when an implementation choice is not directly established by source.
12. Do not perform unrelated refactoring.
13. Do not use browser E2E automation unless explicitly requested later.
14. Stop and report if implementation requirements conflict with an established security invariant in the repository.

---

# 20. Final Implementation Blueprint

```text
                         SUPPLIER USER
                              │
                              ▼
                 supplier_scopes (DB authority)
                              │
             ┌────────────────┴────────────────┐
             │                                 │
             ▼                                 ▼
      Material Procurement                Local Supplier
       technical: import                  technical: local
             │                                 │
             └────────────────┬────────────────┘
                              ▼
                 Current Context Resolver
                              │
                    session('supplier_context')
                              │
                   ┌──────────┴──────────┐
                   ▼                     ▼
             Portal UI              Route Middleware
                   │                     │
                   │                     └── DB scope check
                   │
                   └── branding/header switcher
                              │
                              ▼
                      Target Dashboard
```

The core engineering principle remains:

```text
Authorization = database scope
Context       = selected business workspace
Role          = unchanged identity/permission role
UI            = presentation of current authorized context
```

This is the intended V2 contract and should remain the reference point throughout implementation and verification.
