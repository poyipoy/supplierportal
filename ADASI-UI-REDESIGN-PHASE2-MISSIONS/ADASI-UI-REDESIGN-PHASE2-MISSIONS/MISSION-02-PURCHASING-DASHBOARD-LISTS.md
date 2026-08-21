# MISSION-02 — Purchasing Dashboard & List Experiences
## ADASI Supplier Portal Phase 2

**Attach with:** `REDESIGN-PHASE2-GLOBAL-CONTRACT.md`  
**Depends on:** Mission 01 completed in the same working tree  
**Primary goal:** Establish Purchasing as the benchmark for role dashboards and operational list pages.

---

# 0. Mission intent

This mission must create clearly visible redesign impact.

Purchasing becomes the benchmark for later Supplier/QC/Admin pages.

Later modules must inherit its:

- page hierarchy
- toolbar grammar
- filter behavior
- table density
- row-action pattern
- dashboard visual grammar
- chart treatment
- empty/loading-state treatment

Do not create a Purchasing-only aesthetic.

---

# 1. Re-audit before editing

Identify current active Purchasing views/routes for:

- Purchasing dashboard
- PR index/list
- quotation/review list
- price comparison list/index
- PO index/list
- claims list
- period management
- reports
- export/history list views
- other data-heavy Purchasing views

Audit:

- DataTables initialization
- filters/query params
- row actions/authorization
- existing Chart.js datasets
- status chips
- empty states
- export actions
- sticky candidates

Do not assume filenames from old audits are exhaustive.

---

# 2. Purchasing dashboard — rebuild from first principles

The existing dashboard is a **data/function reference only**.

Reconstruct the composition.

Top priority:

> **What needs Purchasing attention now?**

The first meaningful region must be an **operational queue** presented as a compact actionable list/table.

Examples only if current backend/data actually supports them:

- PR awaiting review
- bidding closing soon
- quotation requiring action
- PO/document issue pending
- claim requiring follow-up

Do not invent states, counts, or due logic.

---

# 3. Operational queue design

Use a compact table/list, not stacked task cards.

Useful columns may include:

- reference/entity
- issue/reason/status
- age/due context
- responsible context if available
- primary action

Keep row height balanced.

Primary action should be obvious.

Secondary detail may open a contextual drawer when appropriate.

---

# 4. Dashboard operational summary

After the queue, include concise summary metrics only if supported by existing data.

Do not create a KPI-card wall.

Prefer a low-visual-weight summary strip/grid.

No decorative icon bubbles.

No colored metric cards unless color directly represents state.

---

# 5. Dashboard charts

Use **2–3 meaningful charts maximum**.

Possible examples only if existing data supports them:

- procurement activity trend
- PO status distribution
- supplier response trend
- spend trend
- PR progression

Do not invent analytics calculations.

Reuse current datasets/query semantics where possible.

Chart requirements:

- restrained palette
- ADASI blue + neutral/semantic accents
- clear titles
- usable legends
- no rainbow colors
- no decorative gradients
- responsive container behavior

---

# 6. Universal list-page composition

All Purchasing operational lists should follow:

```text
Compact page header
↓
Operational toolbar
↓
Data table
```

Do not place KPI cards above PR/PO/Quotation tables.

Do not wrap every table in a large floating card.

---

# 7. Toolbar pattern

Keep high-frequency filters visible.

Use where applicable:

- search
- status
- period/date
- one or two high-frequency domain filters
- `More filters`
- export/utility action

`More filters` may open a restrained dropdown/popover/panel.

Do not create a giant filter form above the table.

Preserve current request/query semantics.

Do not modify backend filtering behavior.

---

# 8. Row actions

Locked pattern:

- primary action visible
- secondary actions in kebab/overflow menu

Example:

```text
[View]  ⋮
```

Do not hide every action behind overflow.

Do not show five or six buttons in every row.

Preserve authorization and route targets exactly.

---

# 9. Table density and sticky behavior

Use **balanced enterprise density**.

For long lists:

- sticky toolbar
- sticky table header
- intentional horizontal scroll where needed

Ensure sticky regions do not overlap topbar or each other.

Do not change DataTables selectors or lifecycle behavior.

---

# 10. PR Index redesign

PR Index must materially change in:

- page composition
- toolbar layout
- filter hierarchy
- table header treatment
- row/action hierarchy
- status presentation
- spacing/density
- sticky behavior
- empty/no-results state

Do not merely place the existing DataTable into a new `x-ui.card`.

---

# 11. PO Index redesign

Use the same visual grammar as PR Index.

Adjust content for actual PO workflow, but do not invent a separate PO aesthetic.

Preserve:

- current actions
- role permissions
- DataTables/AJAX behavior
- document navigation

---

# 12. Quotation/review list redesign

Where applicable:

- clarify supplier identity
- clarify quotation state
- emphasize the next review action
- keep numeric information scannable
- use overflow for secondary actions
- retain comparison/navigation behavior

Do not turn quotation rows into large cards if table comparison is more efficient.

---

# 13. Claims, periods, reports, other lists

These should no longer look like generic Bootstrap CRUD screens.

Use the same:

- compact header
- toolbar grammar
- balanced table
- status-chip semantics
- row-action hierarchy
- empty/loading state

Short/simple lists may omit unnecessary toolbar controls.

---

# 14. Empty/loading/error states

Every redesigned list must have deliberate states:

- no data
- no filtered results
- loading
- error where applicable

Use professional English.

No illustrations.

No emoji.

---

# 15. DataTables safety

Before changing any DataTables-bound page, document:

- table ID
- classes used by JS
- DataTables initialization selector
- server-side/AJAX endpoint
- row action HTML expectations

Preserve those contracts.

Rebuild surrounding toolbar/header/layout safely.

---

# 16. Anti-fake-redesign gate

A list page fails this mission if the result is essentially:

```text
old header
+ old filters
+ same table
+ new icons
```

There must be material improvement in:

- composition
- toolbar
- filtering hierarchy
- table hierarchy
- action hierarchy
- density
- state presentation
- sticky behavior where useful

---

# 17. Anti-AI-slop gate

Reject:

- dashboard KPI-card wall
- colored icon circles
- gradient charts/cards
- overly soft rounded tables
- huge empty spaces
- floating filter cards
- decorative progress rings
- rainbow charts

Use restrained operational design.

---

# 18. Stitch exploration

If available, use Stitch on representative screens:

- Purchasing Dashboard
- PR Index
- PO Index or Quotation Index

Critique output against the Global Contract.

Do not copy generic SaaS dashboard card grids.

---

# 19. Responsive requirements

Static/code review for:

- desktop full-width tables
- sticky toolbar/header
- tablet toolbar wrapping
- mobile filter access
- horizontal table scroll
- row-action reachability

Do not hide critical columns/actions without an intentional alternative.

---

# 20. Verification

Run:

```bash
php artisan view:clear
php artisan view:cache
npm.cmd run build
git diff --check
```

Run targeted Purchasing/list/DataTables/dashboard tests.

Do not use browser automation.

---

# 21. Required report

Create:

```text
UI-REDESIGN-RESULT/UI-REDESIGN-PHASE2-M02-PURCHASING-LISTS-RESULT.md
```

Include:

1. files changed
2. dashboard before/after composition
3. operational queue design
4. summary/chart decisions
5. list-page grammar
6. toolbar/filter pattern
7. row-action pattern
8. sticky behavior
9. DataTables compatibility notes
10. empty/loading states
11. responsive considerations
12. anti-AI-slop review
13. tests/build results
14. `MANUAL_VISUAL_QA_REQUIRED`

---

# 22. Completion gate

Mission is complete only when Purchasing clearly establishes a reusable benchmark for:

- role dashboard
- operational queue
- data-heavy list
- filter toolbar
- balanced table
- row actions
- chart treatment
- empty/loading states

Do not fully redesign long forms/details here.
