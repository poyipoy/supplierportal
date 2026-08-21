# MISSION-04 — Supplier Experience
## ADASI Supplier Portal Phase 2

**Attach with:** `REDESIGN-PHASE2-GLOBAL-CONTRACT.md`  
**Depends on:** Missions 01–03 in the same working tree  
**Primary goal:** Propagate the established Phase 2 visual grammar into the Supplier role while materially redesigning supplier-specific workflows.

---

# 0. Mission intent

Supplier must clearly feel like the same product as Purchasing.

Do not invent a new module-specific style.

Reuse:

- shell
- compact page header
- toolbar grammar
- balanced table
- sectioned form
- sticky action bar
- right drawer
- status chips
- AdasiToast
- neutral surfaces
- ADASI blue accent
- sharp enterprise geometry

Differences should come from workflow and content, not a different visual language.

---

# 1. Re-audit before editing

Identify active Supplier views/routes for:

- Supplier Dashboard
- quotation period/index
- quotation create/edit
- quotation import
- autosave UI
- quotation show
- PO list/detail
- claims
- price history
- announcements
- conversations
- export/history
- other active Supplier views

Audit JS and autosave dependencies before markup changes.

---

# 2. Supplier Dashboard — rebuild from first principles

Top priority:

> **What requires the supplier's attention now?**

Use a compact actionable list/table.

Examples only if current data supports them:

- quotation invitation awaiting response
- quotation closing soon
- revision requested
- PO/document action pending
- claim requiring response

Do not invent states or deadlines.

---

# 3. Dashboard summary and charts

After the operational queue, show only concise summary information.

Use 2–3 meaningful charts maximum if current data supports them.

Possible examples:

- quotation activity
- response trend
- PO status
- price-history trend

Do not create a decorative analytics wall.

No rainbow colors.

---

# 4. Quotation period/index pages

Use the established list pattern:

```text
compact header
↓
primary filters + More filters
↓
operational table/list
```

No KPI-card wall.

Primary action visible.

Secondary actions in overflow.

Preserve current period/status semantics.

---

# 5. Quotation entry — core supplier workflow

Materially redesign for:

- data-entry clarity
- numeric alignment
- units
- editable values
- validation
- autosave visibility
- import controls
- sticky actions
- responsive behavior

Preserve existing backend amount calculations and field names.

Do not change quotation ownership or submission semantics.

---

# 6. Autosave presentation

Autosave should be visible but restrained.

Acceptable compact states:

- Saving…
- Saved
- Save failed

Avoid a toast on every keystroke.

Use inline state for routine autosave and AdasiToast only when a transient notification adds value.

Never show Saved before backend confirmation.

---

# 7. Quotation import

Use the established import grammar.

Clarify:

- template/download if present
- file selection
- instructions
- validation result
- import summary
- progress/feedback

Do not fake progress percentages.

---

# 8. Quotation Show

Use a strong detail hierarchy:

- compact header + state
- PR/request context
- item/pricing table
- totals/summary
- attachments/remarks if present
- history/secondary context

Avoid card stacking.

---

# 9. Supplier PO views

Reuse Purchasing PO visual grammar while respecting Supplier permissions.

Do not expose Purchasing-only controls.

Clarify:

- PO identity
- order status
- document status
- amount/weight context
- required supplier action

---

# 10. Claims

Use consistent list/detail/form patterns.

Prioritize:

- claim reference
- status
- date
- related PO/item
- evidence
- required action

Semantic colors only for meaningful status.

---

# 11. Price history

Treat price history as a decision-support screen, not a decorative analytics dashboard.

Prioritize:

- material identity
- period/date
- historical price
- current context
- chart/table consistency

Use restrained chart colors.

Preserve current historical data semantics.

---

# 12. Announcements

Use compact content-first presentation.

Avoid blog-like marketing cards.

Prioritize:

- title
- date/meta
- importance/state if present
- concise content

---

# 13. Conversations

Preserve realtime/message architecture.

Improve:

- thread readability
- participant context
- unread state
- timestamps
- composer hierarchy
- attachment/action affordance
- empty state

Do not redesign into a consumer messenger clone.

Avoid oversized bubbles, gradients, stickers, reactions-as-decoration.

---

# 14. Responsive Supplier behavior

Supplier workflows may be used on smaller devices more often.

Ensure:

- quotation entry remains usable
- item table overflow is intentional
- sticky actions remain reachable
- import controls remain usable
- PO detail is readable
- conversations/drawer adapt

Do not compromise desktop density.

---

# 15. Anti-fake-redesign gate

This mission fails if it only propagates tokens/components.

There must be material improvement in:

- Supplier Dashboard composition
- quotation entry hierarchy
- quotation index toolbar/table
- PO detail hierarchy
- price-history presentation
- claims/conversation ergonomics

---

# 16. Anti-AI-slop gate

Reject:

- supplier KPI-card wall
- colored supplier cards
- decorative chat styling
- gradient charts
- giant form cards
- excessive pill statuses
- marketing-like announcement cards

---

# 17. Stitch exploration

If available, use:

- Supplier Dashboard
- quotation entry
- price history or PO detail

Propagate established Purchasing grammar.

Reject module-specific novelty.

---

# 18. Verification

Run:

```bash
php artisan view:clear
php artisan view:cache
npm.cmd run build
git diff --check
```

Run targeted Supplier/quotation/autosave/import tests.

---

# 19. Required report

Create:

```text
UI-REDESIGN-PHASE2-M04-SUPPLIER-RESULT.md
```

Include:

1. files changed
2. Supplier Dashboard redesign
3. operational queue
4. quotation list/entry/show changes
5. autosave/import UX
6. PO/claims/price history changes
7. conversations/announcements
8. responsive notes
9. business-behavior preservation
10. anti-AI-slop review
11. tests/build result
12. `MANUAL_VISUAL_QA_REQUIRED`

---

# 20. Completion gate

Supplier must visibly inherit the same Phase 2 product language while improving its domain-specific workflows.
