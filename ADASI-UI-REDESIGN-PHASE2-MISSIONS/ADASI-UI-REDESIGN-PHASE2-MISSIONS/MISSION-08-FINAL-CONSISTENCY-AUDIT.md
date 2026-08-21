# MISSION-08 — Final Consistency & Regression Audit
## ADASI Supplier Portal Phase 2

**Attach with:** `REDESIGN-PHASE2-GLOBAL-CONTRACT.md`  
**Depends on:** Missions 01–07 completed in the same working tree  
**Mission type:** Final audit, consistency repair, regression verification  
**Primary goal:** Ensure the whole application now feels like one coherent product and repair missed/under-redesigned areas.

---

# 0. Mission intent

This is **not** a new visual exploration mission.

Do not introduce another design direction.

Use the established Phase 2 system and audit the entire application for:

- missed active views
- inconsistent patterns
- partial migrations
- under-redesigned pages
- regression risks
- anti-AI-slop violations
- JS/DataTables coupling issues
- responsive inconsistencies
- accessibility gaps

Fix issues clearly within approved visual scope.

---

# 1. Re-audit full view inventory

Inventory every Blade view.

Classify:

- active routed views
- shared partials
- shared components
- inactive starter templates
- print/PDF views
- role-specific variants

Do not delete inactive templates just to reduce counts unless explicitly required.

Document active-view count and any known inactive exceptions.

---

# 2. Shell consistency audit

Across Purchasing, Supplier, QC, and Admin verify:

- same sidebar grammar
- expanded/collapsed states
- same icon rail behavior
- same topbar hierarchy
- same content gutters
- same mobile drawer behavior
- same breadcrumb
- same page-header grammar
- same notification/user-menu styling

Fix module-specific drift.

---

# 3. Page-header audit

Every active page should use the approved compact hierarchy unless a documented exception exists.

Find/fix:

- oversized headings
- hero-like descriptions
- decorative title icons
- inconsistent action placement
- duplicated local header markup
- large empty header regions

---

# 4. List-page audit

Check every active index/list page for:

- toolbar hierarchy
- high-frequency filters
- `More filters`
- balanced table density
- sticky toolbar/header where appropriate
- primary row action
- overflow actions
- status-chip consistency
- empty/no-results state
- horizontal-overflow handling

Catch any screen that still reads as raw Bootstrap CRUD.

---

# 5. Form audit

Check active create/edit forms for:

- section grouping
- label/help/error rhythm
- sticky action bar where appropriate
- responsive stacking
- long-form readability
- import/upload hierarchy
- validation feedback

Catch pages that remain card-heavy, fragmented, or visually unchanged.

---

# 6. Detail/show audit

Check active show/detail screens for:

- compact header
- primary summary hierarchy
- operational section order
- contextual right drawer where useful
- activity/history treatment
- attachment/document hierarchy
- action placement

Avoid unnecessary tabs and card stacks.

---

# 7. Dashboard audit

For Purchasing, Supplier, QC, and Admin verify:

- operational queue appears first
- no decorative KPI-card wall
- summary remains restrained
- no more than 2–3 meaningful charts unless explicitly justified
- no rainbow palette
- no generic AI SaaS dashboard composition
- actions are tied to real workflow states

If a dashboard still visually resembles its pre-Phase-2 composition, treat it as under-redesigned.

---

# 8. Anti-AI-slop sweep

Search and inspect for:

- gradients
- backdrop blur/glass
- large arbitrary radii
- large arbitrary shadows
- excessive pills
- decorative icon circles
- oversized icons
- decorative cards
- random colors
- excessive whitespace
- playful motion
- nested cards

Do not remove legitimate functional patterns such as:

- approved Auth overlay
- skeleton loading
- functional progress indication

Fix actual AI-slop violations.

---

# 9. Token-bypass and CSS audit

Search for:

- hard-coded colors
- arbitrary shadows
- arbitrary radii
- arbitrary spacing
- duplicate inline styles
- duplicate scoped CSS
- page-specific design rules that should now be shared

Consolidate only when safe.

Retain local CSS when legitimately required for:

- print/PDF
- charts
- JS/DataTables coupling
- specialized data-entry layouts

Document intentional exceptions.

---

# 10. Icon audit

Run:

```bash
grep -r "bi-" resources/views --include="*.blade.php"
grep -r 'class="bi' resources/views --include="*.blade.php"
grep -r "<x-lucide-" resources/views --include="*.blade.php"
```

Target:

- zero Bootstrap Icon references in active Blade
- zero direct Lucide calls in feature views
- application icons routed through `<x-ui.icon>`

Document any legitimate exception.

---

# 11. Microcopy audit

Sweep active UI for:

- Indonesian text
- mixed-language copy
- inconsistent capitalization
- vague button labels
- generic “Success!” messaging
- inconsistent business terminology

Keep professional English.

Preserve domain terms and codes.

Do not rename backend values merely for presentation.

---

# 12. AdasiToast/feedback audit

Audit transient feedback usage.

Search for:

- direct non-blocking `Swal.fire`
- `AdasiAlert.toast`
- `AdasiAlert.notification`
- duplicate flash/toast handling
- legacy adapters bypassing AdasiToast

Keep:

- confirmations/prompts/blocking decisions in AdasiAlert/SweetAlert
- field validation inline
- persistent notifications in Notification Center

Do not over-toast routine autosave events.

---

# 13. DataTables/JS regression audit

Inspect all modified DataTables and JS-bound screens.

Verify code-level preservation of:

- IDs
- classes used as selectors
- data attributes
- event bindings
- AJAX URLs
- field names
- table initialization hooks

Do not claim browser functionality has been visually verified.

If a markup change creates a clear code-level mismatch, fix it.

---

# 14. Responsive static audit

Review markup/CSS behavior for:

- 390px
- 768px
- 992px
- 1280px+

Focus on:

- sidebar
- icon rail
- topbar
- tables
- sticky toolbar
- sticky action bar
- dialogs
- drawers
- form grids
- chart containers
- Auth split layout

Mark rendered checks as manual QA.

---

# 15. Accessibility audit

Check:

- icon-only `aria-label`
- focus visibility
- logical heading order
- dialog/drawer labeling
- toast live regions
- non-color status cues
- table headers
- keyboard-reachable overflow actions
- disabled/loading semantics

Fix obvious regressions.

---

# 16. Business-logic diff review

Review `git diff` for accidental edits outside visual scope.

Pay special attention to:

- controllers
- services
- models
- migrations
- routes
- validation
- authorization
- query logic
- event/realtime code

If any such changes were made solely for visual convenience, revert or isolate them unless explicitly required and approved.

---

# 17. Final verification commands

Run:

```bash
php artisan view:clear
php artisan view:cache
npm.cmd run build
php artisan test
git diff --check
composer install
```

If PowerShell blocks `npm.ps1`, use `npm.cmd run build` without changing the machine execution policy.

Report actual exit/results.

Do not hide failures.

---

# 18. Manual visual QA checklist

Produce a concise but comprehensive manual QA checklist.

## Shell/Auth

- Login and auth variants
- sidebar expanded
- sidebar collapsed icon rail
- mobile drawer
- topbar notifications
- user menu
- focus behavior

## Purchasing

- dashboard
- PR index/create/edit/show/import
- supplier picker
- quotation list/detail/comparison
- PO list/create/show/documents/timeline
- claims
- periods
- reports
- conversations

## Supplier

- dashboard
- quotation period/create/edit/import/autosave/show
- PO
- claims
- price history
- announcements
- conversations

## QC

- dashboard
- waiting/history
- inspection create/edit/show
- NG evidence
- PDF

## Admin

- dashboard
- users
- exchange rates
- materials
- HS Code
- announcements
- read-only requisition

## Shared

- Profile/Security
- Notification Center
- Conversations/Chat drawer
- Export history
- empty/loading/error states
- dialogs/drawers

## Viewports

- 390px
- 768px
- 992px
- 1280px+

All rendered items remain:

```text
MANUAL_VISUAL_QA_REQUIRED
```

until user confirms.

---

# 19. Final anti-fake-redesign review

Explicitly inspect whether any major active page still resembles the old visual composition.

If yes, classify it as:

- intentionally preserved for functional safety
- missed
- under-redesigned

Fix missed/under-redesigned pages within approved scope.

Do not count icon/token/microcopy-only changes as sufficient.

---

# 20. Required final report

Create:

```text
UI-REDESIGN-RESULT/UI-REDESIGN-PHASE2-FINAL-RESULT.md
```

Include:

1. overall status
2. starting/current branch and HEAD
3. total changed files
4. mission-by-mission summary
5. shell redesign summary
6. Purchasing benchmark summary
7. Supplier summary
8. QC summary
9. Admin summary
10. Shared/Auth summary
11. dashboard architecture
12. list/table architecture
13. form/detail architecture
14. drawer/sticky-action patterns
15. anti-AI-slop audit
16. icon audit
17. microcopy audit
18. AdasiToast audit
19. scoped CSS/token audit
20. DataTables/JS compatibility notes
21. responsive static audit
22. accessibility audit
23. business-logic diff review
24. build result
25. test result
26. Composer result
27. `git diff --check`
28. manual QA checklist
29. remaining risks/deferred items

Do not claim visual QA passed.

---

# 21. Final acceptance criteria

Phase 2 is complete only if:

- visual change is materially significant
- shell is clearly redesigned
- dashboards are reconstructed around operational queues
- list pages use consistent toolbar/table grammar
- forms use sectioned single-page hierarchy
- detail pages have stronger information architecture
- contextual drawers are used appropriately
- anti-AI-slop rules hold across modules
- application remains familiar to existing ERP users
- no business logic was intentionally changed
- build/tests have no redesign-caused failures
- manual QA handoff is ready
