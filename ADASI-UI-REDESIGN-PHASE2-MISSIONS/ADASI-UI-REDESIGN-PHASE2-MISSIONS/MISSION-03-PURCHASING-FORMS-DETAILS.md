# MISSION-03 — Purchasing Forms & Detail Workflows
## ADASI Supplier Portal Phase 2

**Attach with:** `REDESIGN-PHASE2-GLOBAL-CONTRACT.md`  
**Depends on:** Missions 01–02  
**Primary goal:** Redesign Purchasing long forms, details, comparisons, and contextual workflows without changing business behavior.

---

# 0. Mission intent

This mission targets complex operational work.

The goal is not decorative forms.

The goal is to make procurement workflows:

- easier to scan
- easier to complete
- easier to review
- less visually fragmented
- more predictable

Preserve all backend contracts.

---

# 1. Re-audit before editing

Identify active Purchasing views for:

- PR Create
- PR Edit
- PR Show
- PR import
- supplier picker
- quotation detail/review
- quotation comparison
- PO Create
- PO Show
- PO documents
- PO timeline/history
- Purchasing-owned claims forms/details
- embedded conversations/contextual panels
- other complex Purchasing forms/details

Audit JS-bound controls before restructuring markup.

---

# 2. PR Create/Edit — locked composition

Use:

> **Sectioned single-page form + sticky action bar.**

Possible high-level structure based on actual existing fields:

```text
GENERAL INFORMATION
────────────────────────────────
fields

MATERIAL REQUIREMENTS
────────────────────────────────
item entry/table

SUPPLIER SELECTION
────────────────────────────────
supplier controls

ADDITIONAL INFORMATION / ATTACHMENTS
────────────────────────────────
only if present

sticky actions
```

Do not invent fields or workflow stages.

---

# 3. Form hierarchy

Use:

- typography
- dividers
- spacing
- consistent field grids
- clear helper/error text

Avoid:

- card for every subsection
- icon beside every section title
- giant vertical spacing
- decorative background bands

Cards are allowed only for genuinely distinct interactive regions.

---

# 4. Sticky action bar

PR/PO long forms should expose existing key actions persistently.

Possible actions only if current workflow already supports them:

- Cancel/Back
- Save Draft
- Submit
- Update

Requirements:

- primary action obvious
- secondary action restrained
- loading/disabled state clear
- does not cover final fields
- works at smaller viewports
- safe z-index with toast/drawer/topbar

Do not invent new save semantics.

---

# 5. PR material-entry experience

Preserve dynamic shape/dimension/business logic.

Improve:

- column hierarchy
- dimension readability
- units
- numeric alignment
- validation state
- row add/remove affordance
- remark/helper presentation
- responsive overflow

Do not rename backend input names.

Do not break JavaScript that maps active dimension fields.

---

# 6. Supplier picker

Redesign presentation while preserving selection behavior.

Target:

- clear search
- compact supplier rows
- visible selected state
- useful supplier metadata
- concise confirmation action
- professional empty state

Prefer list/table efficiency over decorative supplier cards.

---

# 7. Import workflow

Preserve import behavior.

Improve:

- file selection hierarchy
- instructions
- template/download affordance if present
- validation-error visibility
- result summary
- progress/feedback integration

Use AdasiToast for transient progress/feedback.

Never fake percentage.

---

# 8. PR Show redesign

Do not rewrap the old cards.

Use a hierarchy such as:

```text
compact header
↓
primary summary/meta
↓
materials / core operational detail
↓
supplier / quotation context
↓
documents / activity / history
```

Use tabs only when they reduce complexity.

Do not create tabs for every section.

---

# 9. Contextual right drawer

Use the Mission 01 drawer pattern where it improves context.

Appropriate candidates:

- activity history
- supplier response detail
- lightweight audit detail
- related record metadata

Keep complex edit workflows on dedicated pages.

---

# 10. Quotation comparison

Treat this as a high-density decision screen.

Prioritize:

- side-by-side comparability
- supplier distinction without rainbow color
- numeric alignment
- price/weight/amount readability
- sticky headers or key columns where useful
- clear current/selected state if existing workflow supports it
- chart/table relationship

Do not invent recommendation logic or winning-supplier logic.

Preserve current formulas/data.

---

# 11. PO Create

Use the same sectioned-form grammar established by PR.

Preserve:

- selected quotations
- supplier mapping
- totals
- documents
- validation
- route/controller contracts

Do not add decorative checkout-like steps.

---

# 12. PO Show

Reconstruct hierarchy around actual existing data.

Possible order:

- reference/status header
- supplier/order summary
- monetary/weight summary
- documents
- timeline/history
- related PR/quotation references

Use contextual drawer for secondary history/document metadata when useful.

---

# 13. Documents and timeline

Documents should prioritize:

- file name/type
- status
- timestamps
- relevant action

Timeline/history should prioritize:

- event
- actor
- timestamp
- concise detail

Avoid oversized vertical timeline decoration.

---

# 14. Validation UX

Keep field-level validation.

Improve:

- field error visibility
- section-level summary only if helpful
- logical focus/scroll behavior if already supported
- professional English feedback

Do not replace form errors with toast-only messages.

---

# 15. Mobile/tablet behavior

Ensure:

- fields stack logically
- sticky actions remain reachable
- item tables intentionally scroll
- remove/edit actions remain available
- supplier picker fits viewport
- drawer adapts appropriately
- comparison tables remain usable

Desktop remains primary.

---

# 16. Anti-fake-redesign gate

PR Create is not redesigned if it remains the same form/table with:

- new border
- new spacing
- Lucide icons

It must materially improve:

- section structure
- action placement
- information hierarchy
- form ergonomics
- validation readability
- long-page navigation

The same standard applies to PR Show, comparison, and PO Show.

---

# 17. Anti-AI-slop gate

Reject:

- wizard added for visual novelty
- giant section cards
- huge rounded input groups
- decorative section icons
- colored supplier cards
- excessive shadows
- checkout/e-commerce aesthetics

Keep familiar enterprise form/table behavior.

---

# 18. Stitch exploration

If available, use representative screens:

- PR Create
- PR Show
- quotation comparison or PO Show

Critique for density and familiarity.

Reject consumer checkout/wizard patterns.

---

# 19. Verification

Run:

```bash
php artisan view:clear
php artisan view:cache
npm.cmd run build
git diff --check
```

Run targeted PR/PO/quotation/import/form tests.

Verify no business semantics changed.

---

# 20. Required report

Create:

```text
UI-REDESIGN-RESULT/UI-REDESIGN-PHASE2-M03-PURCHASING-FORMS-RESULT.md
```

Include:

1. files changed
2. PR Create/Edit structure
3. material-entry changes
4. supplier picker/import changes
5. sticky action behavior
6. PR Show hierarchy
7. quotation comparison changes
8. PO Create/Show changes
9. drawer usage
10. JS-sensitive areas preserved
11. responsive notes
12. anti-AI-slop review
13. tests/build result
14. `MANUAL_VISUAL_QA_REQUIRED`

---

# 21. Completion gate

Mission is complete only when Purchasing now defines reusable patterns for:

- long operational form
- material-entry table
- detail/show page
- contextual drawer
- comparison workflow
- sticky action bar
- complex responsive data entry
