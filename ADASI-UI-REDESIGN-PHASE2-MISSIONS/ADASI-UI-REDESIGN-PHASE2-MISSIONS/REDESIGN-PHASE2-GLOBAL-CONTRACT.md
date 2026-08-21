# REDESIGN-PHASE2-GLOBAL-CONTRACT
## ADASI Supplier Portal — Phase 2 Full Visual Redesign Contract

**Repo:** `poyipoy/supplierportal`  
**Purpose:** Mandatory shared contract for Mission 01–08.  
**Execution target:** Gemini 3.7, sequential runs in the same working tree.  
**Git policy:** No automatic commit/push/merge/rebase/branch operations.  
**Visual QA:** Manual by the user; no browser automation.

---

# 0. How to use this contract

Attach this file together with the current mission-specific file on **every** Phase 2 run.

Example:

```text
REDESIGN-PHASE2-GLOBAL-CONTRACT.md
+
MISSION-02-PURCHASING-DASHBOARD-LISTS.md
```

The mission-specific file defines **what to redesign**. This contract defines **how the redesign must behave and look**.

Instruction precedence:

1. application safety
2. business behavior and functional contracts
3. current repository architecture
4. this Global Contract
5. mission-specific scope
6. design-tool suggestions
7. general aesthetic preference

---

# 1. Phase 2 objective

Phase 1 already delivered important foundation work: Lucide through `<x-ui.icon>`, AdasiToast, professional English microcopy, token cleanup, shared component normalization, Bootstrap compatibility, and static verification.

Phase 2 is **not another cleanup pass**.

The objective is:

> **Perform an actual full visual redesign of the ADASI Supplier Portal while preserving business behavior, data contracts, workflow familiarity, and runtime compatibility.**

Treat the current application as:

```text
FUNCTIONAL REFERENCE
NOT
VISUAL REFERENCE
```

Do not preserve an existing composition merely because it works.

---

# 2. What counts as a real redesign

A page does **not** count as redesigned if its primary changes are only:

- icon replacement
- component substitution
- spacing adjustment
- token normalization
- microcopy changes
- color cleanup
- border/radius changes
- shadow cleanup
- raw Bootstrap → `x-ui.*`
- minor alignment fixes

A page **does** count as redesigned when it materially improves:

- visual composition
- information hierarchy
- page structure
- toolbar organization
- filter ergonomics
- table presentation
- action hierarchy
- form grouping
- responsive behavior
- secondary-detail handling
- workflow ergonomics
- dashboard prioritization
- density and scannability

Hard rule:

> **Preserve behavior, data, workflow familiarity, and functional contracts — not the current visual composition.**

---

# 3. Locked visual direction

Target: **modern, premium, sharp enterprise UI that remains familiar to existing ERP users.**

Reference qualities:

- SAP Fiori — enterprise clarity
- Odoo — operational familiarity
- GitHub — restrained utility
- Linear — precision and disciplined interaction
- mature B2B procurement systems

Do not copy any product literally.

## 3.1 Surface and color

Use:

- neutral white
- neutral gray
- dark neutral text
- restrained borders
- ADASI blue only as a controlled accent
- semantic colors only for actual state

ADASI blue is for:

- primary actions
- active navigation
- selected/focus states
- meaningful interactive emphasis

Do not flood the application with blue-tinted containers.

## 3.2 Radius and elevation

Target: **sharp enterprise**.

Use:

- small radius
- subtle container shaping
- borders before shadows
- almost no decorative elevation

Avoid:

- 16–24px radius everywhere
- floating cards with deep shadows
- layered shadow systems
- glow

## 3.3 Shell

Locked direction:

- light sidebar
- fixed desktop width
- collapsible to icon rail
- subtle active state
- minimal topbar
- adaptive full-width content shell
- compact page headers
- desktop-first density
- tablet/mobile fully usable

## 3.4 List pages

Locked direction:

- no KPI-card wall above operational tables
- compact page header
- high-frequency filters visible
- secondary filters behind `More filters`
- balanced table density
- primary row action visible
- secondary actions in overflow/kebab menu
- sticky toolbar + sticky table header for long lists when useful
- adaptive full-width table region

## 3.5 Forms

Locked pattern:

> **Sectioned single-page form with sticky action bar.**

Use typography, spacing, and dividers to group sections. Do not place every field group inside a separate card.

## 3.6 Detail/show pages

Use:

- compact page header
- clear primary metadata
- strong operational hierarchy
- flat sections
- contextual right-side drawer for secondary details when appropriate

Drawer candidates:

- activity/history
- supplier response
- lightweight audit detail
- related-record context

Complex workflows may remain dedicated pages.

## 3.7 Dashboards

Dashboards may be rebuilt from first principles.

Top priority:

> **Operational queue — what needs attention now?**

Then:

- concise operational summary
- 2–3 meaningful charts
- important recent activity if useful

Avoid KPI-card walls.

---

# 4. Anti-AI-slop — HARD ACCEPTANCE CRITERION

This remains mandatory in every mission.

Do not introduce:

- gratuitous gradients
- glassmorphism
- backdrop blur for decoration
- glow or neon
- oversized rounded cards
- excessive pill components
- decorative icon circles
- giant icons
- random pastel palettes
- nested card stacks
- excessive whitespace
- decorative illustrations
- emoji
- confetti
- playful bounce/spring animations
- deep decorative shadows
- marketing landing-page patterns
- decorative badges without semantic purpose
- abstract blobs
- rainbow status treatments
- generic AI SaaS dashboard compositions
- hero copy inside internal ERP pages

Use instead:

- typography-led hierarchy
- compact spacing
- neutral surfaces
- subtle borders
- balanced density
- functional Lucide icons
- restrained semantic color
- explicit action hierarchy
- flat operational sections
- cards only when grouping is meaningful

If a visual treatment does not improve comprehension, hierarchy, state, or actionability, remove it.

---

# 5. Existing Phase 1 foundation must be reused

Preserve and reuse:

- `<x-ui.icon>`
- Lucide-based icon system
- AdasiToast
- professional English microcopy direction
- semantic tokens in `resources/css/app.css`
- Bootstrap 5 compatibility
- DataTables behavior
- Alpine.js behavior
- persistent Notification Center architecture
- tests unless a minimal visual-coupling update is required

Do not reintroduce Bootstrap Icons.

Do not scatter direct `<x-lucide-*>` usages if the abstraction already exists.

---

# 6. Bootstrap compatibility

Bootstrap 5 remains a compatibility layer.

Do not remove it globally.

Preserve where load-bearing:

- `data-bs-*`
- dropdowns
- modals
- DataTables
- legacy DOM hooks
- jQuery/DataTables selectors
- existing AJAX selectors

If markup is JS-bound, preserve required IDs/classes/data attributes/DOM relationships and rebuild the **surrounding visual composition** safely.

---

# 7. Business logic freeze

Do not intentionally change:

- routes
- controllers
- services
- models
- migrations
- DB schema
- validation rules
- authorization
- authentication
- supplier isolation
- Hashid behavior
- procurement workflow
- quotation semantics
- PO behavior
- QC logic
- event/realtime semantics
- Reverb/broadcasting behavior
- Eloquent query behavior
- backend enums/constants
- ownership semantics

If a visual redesign appears to require business-logic changes, stop and report the blocker instead of silently changing behavior.

---

# 8. Professional English UI

All visible UI copy remains professional English.

Preserve established domain terminology where changing it could alter meaning:

- PR
- PO
- HS Code
- QC
- MTC
- ADASI
- document/material codes
- established business acronyms
- proper names

Do not rename backend values merely to change presentation.

---

# 9. Design tools

When available, use:

1. Material Design 3 MCP
2. Coolors MCP
3. Google Stitch

## M3 MCP

Use for:

- focus/state layers
- control sizing
- accessibility
- elevation discipline
- progress behavior
- responsive interaction
- motion restraint

## Coolors MCP

Use for:

- semantic contrast
- text/surface contrast
- border contrast
- status role validation

Do not generate a random replacement palette.

## Google Stitch

Use only on representative screens.

Workflow:

```text
Explore
→ Critique
→ Reject generic output
→ Adapt to ADASI
→ Implement
→ Propagate
```

Do not paste Stitch-generated code blindly.

If unavailable, state one of:

```text
M3_MCP_UNAVAILABLE
COOLORS_MCP_UNAVAILABLE
STITCH_UNAVAILABLE
```

Do not fabricate tool usage.

---

# 10. Responsive contract

Desktop is the primary ERP environment, but tablet/mobile must remain fully usable.

Required:

- sidebar becomes appropriate drawer/compact mode
- tables intentionally scroll/adapt
- no hidden critical actions
- sticky regions do not block content
- dialogs/drawers fit viewport
- forms remain completable
- filters remain accessible
- focus order remains usable
- touch targets remain reasonable

Do not simply shrink desktop layout.

---

# 11. Accessibility contract

Maintain or improve:

- visible keyboard focus
- `aria-label` for icon-only controls
- semantic buttons/links
- modal/drawer focus handling
- toast live regions
- color contrast
- non-color status cues
- readable table headers
- logical heading hierarchy

Do not sacrifice accessibility for density.

---

# 12. Git policy

Do not automatically run:

```text
git commit
git push
git merge
git rebase
git checkout -b
git branch -D
```

User owns commits.

Allowed verification:

```bash
git status
git diff
git diff --check
```

---

# 13. No browser automation

Do not use:

- Playwright
- Puppeteer
- Selenium
- browser MCP
- automated screenshot loops
- automated viewport crawling
- automated visual-diff systems

The user performs visual QA manually.

Mark rendered/visual items as:

```text
MANUAL_VISUAL_QA_REQUIRED
```

---

# 14. Verification discipline

At the end of every mission run relevant checks.

Minimum:

```bash
php artisan view:clear
php artisan view:cache
npm.cmd run build
git diff --check
```

Run targeted tests for the modified area.

At final audit also run:

```bash
php artisan test
composer install
```

Report actual results. Do not hide failures.

---

# 15. Cross-mission consistency

Each later mission inherits patterns established earlier.

- Mission 01 defines shell/core architecture.
- Missions 02–03 establish Purchasing as the primary product benchmark.
- Missions 04–07 propagate the same visual language.
- Mission 08 audits and repairs drift.

Allowed module differences:

- information priority
- domain-specific content
- workflow-specific columns
- role-specific dashboards
- semantic states

Not allowed:

- different radius language
- different card language
- different page-header grammar
- different filter grammar
- different button hierarchy
- different icon style
- different spacing system
- different status-chip treatment

---

# 16. Required report format

Every mission must produce the report requested in that mission.

At minimum include:

1. scope completed
2. files changed
3. materially redesigned pages
4. before-vs-after structural changes
5. shared patterns introduced/reused
6. business-behavior preservation notes
7. JS/DataTables compatibility notes
8. responsive considerations
9. anti-AI-slop review
10. tests/build checks
11. risks
12. `MANUAL_VISUAL_QA_REQUIRED`

---

# 17. Final standard

The Phase 2 result should feel:

- materially different from the pre-redesign portal
- more structured and premium
- still familiar to existing users
- faster to scan
- cleaner under dense data
- less card-heavy
- more operational
- consistent across roles

It must not look like:

- a Bootstrap admin template
- a generic AI-generated SaaS dashboard
- a marketing website
- a consumer productivity app

It should look like:

> **A mature enterprise procurement and supplier-collaboration platform with a deliberate ADASI visual language.**
