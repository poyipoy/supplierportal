# MISSION-05 — QC Experience
## ADASI Supplier Portal Phase 2

**Attach with:** `REDESIGN-PHASE2-GLOBAL-CONTRACT.md`  
**Depends on:** Missions 01–04  
**Primary goal:** Redesign QC workflows using the established enterprise visual grammar, with strong inspection and evidence hierarchy.

---

# 0. Mission intent

QC must feel like the same product as Purchasing and Supplier.

Do not create a separate industrial/dark theme.

Reuse:

- light shell
- neutral surfaces
- ADASI blue accent
- compact page header
- balanced tables
- sharp radius
- almost-no-shadow language
- sectioned forms
- sticky actions
- contextual drawer

QC-specific colors should appear only when semantically meaningful, especially inspection outcomes.

---

# 1. Re-audit before editing

Identify active QC views/routes for:

- QC Dashboard
- waiting/pending inspection list
- inspection history
- inspection create/edit
- inspection show/detail
- NG evidence
- claims/documents related to QC
- QC PDF/print-facing UI
- other active QC screens

Audit file-upload and JS dependencies.

---

# 2. QC Dashboard — rebuild from first principles

Top region:

> **Inspection operational queue**

Examples only if current data supports them:

- inspections waiting
- overdue inspections
- NG items requiring follow-up
- evidence/documents requiring action

Use compact actionable list/table.

Do not lead with decorative KPI cards.

---

# 3. QC summary and charts

Use concise summary metrics after the queue.

Use 2–3 meaningful charts maximum if existing data supports them.

Possible examples:

- inspection volume trend
- OK/NG distribution
- pending-age trend
- supplier/material issue trend

Do not invent analytics.

Semantic red may represent actual NG/failure state, but do not turn entire sections red.

---

# 4. Waiting/history lists

Use established list grammar:

```text
compact header
↓
primary filters + More filters
↓
balanced table
```

Potentially important existing fields may include:

- reference
- supplier
- material
- arrival/date
- inspection state
- result
- assigned/reviewer context

Use actual data only.

Primary action visible; secondary actions in overflow.

---

# 5. Inspection form

Redesign as a sectioned single-page form.

Possible sections based on actual workflow:

- reference/material context
- inspection inputs
- measurements/checks
- result
- evidence
- remarks

Use sticky action bar where useful.

Do not add wizard/stepper unless current workflow is genuinely staged.

---

# 6. Inspection field hierarchy

Make critical inspection fields easy to scan.

Use:

- consistent label/value rhythm
- numeric alignment
- unit display
- error state
- clear OK/NG controls if current form has them

Avoid giant colored pass/fail buttons unless semantics require it.

---

# 7. NG evidence

This is a critical state.

Improve:

- upload affordance
- evidence preview/list
- file metadata
- remove/replace action
- validation/error handling
- required-state clarity

Do not use dramatic red surface everywhere.

Use semantic error treatment only where it improves comprehension.

---

# 8. Inspection detail

Use strong hierarchy:

```text
compact header + result state
↓
overview
↓
inspection data
↓
evidence
↓
history/context
```

Use contextual drawer for secondary audit/history where appropriate.

The inspection result must be immediately understandable without dominating the entire page visually.

---

# 9. Claims/documents

Reuse established list/detail/document patterns.

Do not create QC-specific card language.

Clarify:

- document/evidence identity
- relation to inspection
- status
- date
- action

---

# 10. PDF/print-facing UI

Where QC PDF/print output exists:

- preserve print correctness
- preserve business data
- improve typography/alignment only if safe
- keep print-specific CSS when legitimate

Do not force the web shell design into PDFs.

---

# 11. Empty/loading/error states

Use professional English.

Examples:

- No inspections awaiting review
- No inspection history found
- No evidence uploaded

No illustrations.

No emoji.

---

# 12. Responsive behavior

Ensure:

- inspection form stacks logically
- evidence upload works on smaller screens
- sticky actions remain usable
- waiting/history tables intentionally scroll
- result/status remains visible
- drawer adapts appropriately

---

# 13. Anti-fake-redesign gate

QC fails if:

- dashboard remains old card grid
- inspection form grouping is unchanged
- lists are only recolored
- NG evidence remains visually unclear

Need materially improved workflow composition.

---

# 14. Anti-AI-slop gate

Reject:

- giant OK/NG cards
- glowing severity badges
- red gradient failure panels
- decorative upload cards
- excessive status pills
- oversized inspection icons
- consumer checklist styling

---

# 15. Stitch exploration

If available, use:

- QC Dashboard
- inspection create
- inspection detail

Reject consumer-style upload/checklist patterns.

---

# 16. Verification

Run:

```bash
php artisan view:clear
php artisan view:cache
npm.cmd run build
git diff --check
```

Run targeted QC/inspection/evidence tests.

---

# 17. Required report

Create:

```text
UI-REDESIGN-RESULT/UI-REDESIGN-PHASE2-M05-QC-RESULT.md
```

Include:

1. files changed
2. QC Dashboard redesign
3. operational queue
4. waiting/history list changes
5. inspection form redesign
6. detail hierarchy
7. NG evidence handling
8. document/PDF considerations
9. responsive notes
10. anti-AI-slop review
11. tests/build result
12. `MANUAL_VISUAL_QA_REQUIRED`

---

# 18. Completion gate

QC must use the same Phase 2 design system while making inspection workflow materially clearer and more operational.
