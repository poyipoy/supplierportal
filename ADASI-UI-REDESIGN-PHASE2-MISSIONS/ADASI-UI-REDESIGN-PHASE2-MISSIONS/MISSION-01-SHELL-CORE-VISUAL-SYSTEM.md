# MISSION-01 — Shell & Core Visual Architecture
## ADASI Supplier Portal Phase 2

**Attach with:** `REDESIGN-PHASE2-GLOBAL-CONTRACT.md`  
**Mission type:** Foundational visual reconstruction  
**Primary goal:** Create the new application shell and reusable visual architecture for all later missions.

---

# 0. Mission intent

This mission must produce a **materially different application shell**.

Do not merely:

- recolor the current sidebar
- tighten padding
- swap icons
- reduce shadow
- rename classes
- wrap old content in new containers

The current shell is a functional reference, not a visual reference.

A successful result should immediately feel like a redesigned enterprise product before any feature page is fully redesigned.

---

# 1. Re-audit before editing

Identify the actual current files and dependencies for:

- `resources/views/layouts/app.blade.php`
- sidebar
- navbar/topbar
- chat drawer
- notification dropdown
- page-header component
- breadcrumb component
- button/icon-button components
- drawer/dialog components
- data-table component
- section/card components
- CSS tokens in `resources/css/app.css`
- responsive breakpoints
- Bootstrap dropdown/modal dependencies
- Alpine behavior
- sidebar collapse state
- existing z-index/focus logic

Record any JS-bound selectors before changing markup.

---

# 2. Scope

Materially redesign:

- authenticated application shell
- desktop sidebar
- collapsed icon rail
- mobile sidebar/drawer behavior
- topbar/navbar
- authenticated content frame
- global page gutters
- breadcrumb
- compact page header
- shared operational toolbar foundation
- shared table visual foundation
- shared form-section foundation
- sticky action bar foundation
- contextual right drawer foundation
- shared spacing/radius/elevation rules required by later missions

Do not fully redesign Purchasing/Supplier/QC/Admin pages in this mission.

Feature pages may receive only compatibility adjustments required by the new shell.

---

# 3. Desktop sidebar redesign

Locked direction:

- light surface
- fixed width
- collapsible to icon rail
- subtle active state
- sharp enterprise geometry
- small radius
- minimal shadow
- strong alignment

The expanded sidebar should contain:

- clear product/brand context
- role-specific nav groups
- consistent icon/text alignment
- restrained group labels
- subtle active row treatment
- predictable hover/focus states

Do not create large colored navigation pills.

Do not place each nav item inside a card.

## Collapsed icon rail

When collapsed:

- preserve route access
- icons remain centered/aligned
- current active destination remains understandable
- labels are available via accessible tooltip/title pattern
- icon-only controls have `aria-label`
- group separators remain legible without clutter

Do not use giant 40–48px navigation bubbles unless required for touch-only mobile controls.

---

# 4. Sidebar state and behavior

Preserve existing behavior/state logic where possible.

If collapse state is persisted, preserve that contract.

Do not introduce backend persistence solely for visual preference.

Ensure desktop collapse and mobile drawer are distinct behaviors.

Avoid a layout jump when toggling.

---

# 5. Topbar redesign

Locked direction:

**Minimal enterprise utility bar.**

Prioritize:

- context/title region only if it adds value
- notifications
- user menu
- mobile sidebar trigger

Keep chat only if current architecture exposes it there and it remains useful.

Avoid:

- oversized global search added without product need
- decorative brand blocks
- large topbar height
- many utility icons
- floating rounded toolbar appearance

Preserve Bootstrap dropdown behavior where load-bearing.

Redesign dropdown surfaces so they follow the new sharp enterprise system.

---

# 6. Content shell

Desktop content region must be:

- adaptive full-width
- suitable for wide operational tables
- consistent in horizontal gutters
- consistent below topbar
- free from accidental overlap with sidebar/topbar

Do not enforce a narrow marketing-style max width on operational pages.

Provide a deliberate optional constrained width only for content that genuinely benefits from it, such as Auth/Profile forms.

---

# 7. Page header foundation

Redesign the shared page-header composition.

Target:

```text
Breadcrumb

Page title                                      Primary action
────────────────────────────────────────────────────────────
```

Optional:

- one short metadata line
- secondary actions

Avoid:

- hero-like header
- oversized title
- large descriptive paragraph by default
- decorative icon beside every title
- KPI cards inside the header

The page header should support full-width ERP pages without wasting vertical space.

---

# 8. Breadcrumb foundation

Breadcrumb should be:

- compact
- muted
- secondary to title
- keyboard accessible
- visually stable across all modules

Avoid pill breadcrumb styling.

---

# 9. Shared operational toolbar foundation

Create/refine a reusable toolbar grammar for list pages.

Must support:

- search
- high-frequency primary filters
- `More filters`
- utility actions such as export
- responsive wrapping
- optional sticky behavior

Toolbar should look like part of the operational page, not a floating SaaS command palette.

Use subtle boundary/background treatment only if necessary.

---

# 10. Shared table visual foundation

Create/refine the shared table language used by later missions.

Locked density: **balanced enterprise**.

Requirements:

- readable header hierarchy
- restrained header background
- clear column alignment
- subtle row separators
- meaningful hover/focus state
- numeric columns aligned appropriately
- status-chip compatibility
- primary action + overflow action support
- sticky header support
- horizontal overflow behavior

For DataTables-bound tables:

- preserve IDs
- preserve selectors
- preserve required DOM structure
- preserve initialization hooks

Do not replace a DataTables table with arbitrary component markup if that breaks JS.

---

# 11. Shared form-section foundation

Create one reusable visual grammar for long forms.

Pattern:

```text
SECTION TITLE
optional helper text
────────────────────────────────
field group
field group
```

Use:

- typography
- vertical rhythm
- divider/boundary
- predictable field grid

Avoid:

- card around every section
- icon next to every section title
- large empty gaps

---

# 12. Sticky action bar foundation

Create a reusable pattern for long forms.

Must support:

- primary submit/update action
- cancel/back
- optional save draft if existing workflow supports it
- disabled/loading state
- responsive stacking
- safe bottom spacing
- safe z-index

Use border/surface separation rather than heavy shadow.

It must not cover the last form fields.

---

# 13. Contextual right drawer foundation

Create/refine the shared drawer style for contextual secondary information.

Use cases in later missions:

- activity/history
- supplier response
- audit context
- lightweight related record detail

Requirements:

- preserve page context
- restrained desktop width
- clear heading hierarchy
- compact metadata
- accessible close action
- focus handling
- mobile full-height adaptation where needed

Do not turn complex forms into drawers by default.

---

# 14. Shared component redesign permission

This mission may materially change internal markup/styling of shared components such as:

- `page-header`
- `sidebar-item`
- `button`
- `icon-button`
- `data-table`
- `drawer`
- `dialog`
- `breadcrumb`
- `tabs`
- `section`
- `card`

Existing component APIs are not visually authoritative.

Preserve compatibility where possible.

If API changes are necessary:

- keep them minimal
- update affected callsites
- document them in the result report

---

# 15. Token work allowed

Refine shared tokens only as needed to support the new shell.

Maintain:

- neutral white/gray surface language
- ADASI blue accent
- small radius
- borders over shadows
- shared focus ring
- compact motion
- existing Lucide size scale

Do not create a second design-token system.

---

# 16. Anti-fake-redesign gate

This mission fails if the shell remains substantially the same and the main changes are:

- icon migration
- spacing cleanup
- token cleanup
- smaller radius
- color changes

The shell must materially change in:

- sidebar composition
- collapse behavior presentation
- topbar composition
- content framing
- page-header rhythm
- toolbar/table foundation
- form/drawer foundation

---

# 17. Anti-AI-slop gate

Explicitly reject:

- glass sidebar
- dark gradient sidebar
- glowing active nav
- oversized nav pills
- floating topbar card
- huge corner radius
- icon bubbles
- decorative shadows
- pastel backgrounds
- abstract graphics

Use restrained enterprise composition.

---

# 18. Responsive requirements

Check code-level behavior for:

- 390px
- 768px
- 992px
- 1280px+

Ensure:

- expanded desktop sidebar
- collapsed icon rail
- mobile drawer
- topbar wrapping
- content gutters
- drawer width
- sticky toolbar/action-bar behavior

Rendered QA remains manual.

---

# 19. Verification

Run:

```bash
php artisan view:clear
php artisan view:cache
npm.cmd run build
git diff --check
```

Run relevant rendered component/layout tests if available.

Do not use browser automation.

---

# 20. Required report

Create:

```text
UI-REDESIGN-RESULT/UI-REDESIGN-PHASE2-M01-SHELL-RESULT.md
```

Include:

1. files changed
2. shell architecture before/after
3. sidebar expanded/collapsed behavior
4. topbar changes
5. content-shell changes
6. page-header/breadcrumb changes
7. toolbar foundation
8. table foundation
9. form/sticky-action foundation
10. drawer foundation
11. shared component API changes
12. Bootstrap/Alpine compatibility notes
13. responsive considerations
14. anti-AI-slop review
15. tests/build results
16. `MANUAL_VISUAL_QA_REQUIRED`

---

# 21. Completion gate

Mission is complete only when later missions can reuse one stable pattern for:

- shell
- page header
- toolbar
- table
- form sections
- sticky action bar
- contextual drawer

Do not proceed by redesigning feature pages here merely to create visible change.
