# MISSION-06 — Admin Experience
## ADASI Supplier Portal Phase 2

**Attach with:** `REDESIGN-PHASE2-GLOBAL-CONTRACT.md`  
**Depends on:** Missions 01–05  
**Primary goal:** Transform Admin screens from generic CRUD/admin-template presentation into the same mature ADASI enterprise design language.

---

# 0. Mission intent

Admin screens are especially vulnerable to raw Bootstrap CRUD appearance.

This mission must materially redesign them.

Do not settle for:

- new icons
- new table header color
- smaller radius
- wrapped Bootstrap modals

Admin should feel like a deliberate enterprise control plane.

---

# 1. Re-audit before editing

Identify active Admin views/routes for:

- Admin Dashboard
- users
- exchange rates
- announcements
- materials
- HS Code management/rules
- master data
- read-only requisition/detail views
- audit/security-related screens if present
- settings/configuration pages

Use active routes/current checkout as source of truth.

Audit modals, DataTables, selectors, and authorization before changes.

---

# 2. Admin Dashboard — rebuild from first principles

Top region should prioritize actual admin attention.

Examples only if existing data supports them:

- master data requiring review
- stale exchange rate
- incomplete records
- pending admin action
- existing audit/system condition

Do not invent system health metrics.

Use 2–3 charts maximum only if current backend already provides meaningful data.

No KPI-card wall.

---

# 3. CRUD list pattern

Use the same Phase 2 list grammar:

```text
compact header
↓
search/primary filters + More filters
↓
operational table
```

Primary row action visible.

Secondary actions in overflow.

Create/add action belongs in page header or toolbar depending on context.

Do not make every CRUD page a card containing a Bootstrap table.

---

# 4. User management

Improve:

- identity hierarchy
- role visibility
- active/inactive state
- search/filter behavior
- row actions
- create/edit form structure
- modal/dialog hierarchy where short enough

Preserve authorization semantics.

Do not invent roles or permissions.

---

# 5. Exchange rates

Keep the screen data-dense and operational.

Improve:

- current rate visibility
- effective date
- source/meta if already present
- update action
- history if already available
- form/modal hierarchy

Avoid financial-dashboard decoration.

Do not change exchange-rate calculation semantics.

---

# 6. Materials & HS Code management

These are high-density master-data screens.

Prioritize:

- search
- filters
- sortable/scannable columns
- code hierarchy
- description hierarchy
- status
- create/edit flow
- import if present

Use wide tables where necessary.

Do not hide important master-data fields merely to avoid horizontal scroll.

---

# 7. Master-data forms

Use shared form grammar.

Short forms may use modal/dialog.

Long/complex forms should use a dedicated sectioned page.

Do not put large forms into cramped modals simply to keep everything “compact.”

Preserve validation and field names.

---

# 8. Announcements

Use compact enterprise content-management presentation.

Prioritize:

- title
- publish/status state
- audience/context if present
- dates
- clear edit/publish actions

Avoid blog/marketing-style cards.

---

# 9. Read-only requisition/detail views

Reuse Purchasing detail grammar.

Do not create an Admin-specific duplicate aesthetic.

Preserve read-only restrictions.

Do not expose editing actions accidentally.

---

# 10. Audit/security views

Where present:

- use balanced table density
- prioritize actor/action/time/context
- use filters appropriate to existing data
- avoid decorative severity treatment

Do not change audit data semantics.

---

# 11. Empty/error/permission states

Use professional English.

Make no-data/no-result states clear.

Do not expose implementation details.

Use existing feedback architecture rather than raw Bootstrap alerts where appropriate.

---

# 12. Responsive behavior

Ensure:

- master-data tables scroll intentionally
- modals fit viewport
- filters remain usable
- create/edit actions remain reachable
- user/material code columns remain readable

Desktop remains priority.

---

# 13. Anti-fake-redesign gate

Admin mission fails if CRUD pages still look like generic Bootstrap admin tables.

There must be material improvement in:

- dashboard priority
- page composition
- toolbar/filter hierarchy
- table structure
- row actions
- form/modal hierarchy
- master-data density

---

# 14. Anti-AI-slop gate

Reject:

- settings-card wall
- giant admin KPI cards
- decorative gear icons
- colorful master-data categories without meaning
- oversized modal radius
- soft consumer preferences UI
- pastel status decoration

---

# 15. Stitch exploration

If available, use:

- Admin Dashboard
- User Management
- Materials or HS Code management

Reject generic SaaS “settings” layouts with excessive cards.

---

# 16. Verification

Run:

```bash
php artisan view:clear
php artisan view:cache
npm.cmd run build
git diff --check
```

Run targeted Admin/user/master-data tests.

---

# 17. Required report

Create:

```text
UI-REDESIGN-RESULT/UI-REDESIGN-PHASE2-M06-ADMIN-RESULT.md
```

Include:

1. files changed
2. Admin Dashboard redesign
3. CRUD/list pattern
4. user-management changes
5. exchange-rate changes
6. materials/HS Code changes
7. form/modal changes
8. read-only/audit consistency
9. responsive notes
10. anti-AI-slop review
11. tests/build result
12. `MANUAL_VISUAL_QA_REQUIRED`

---

# 18. Completion gate

Admin should no longer visually read as a collection of generic Bootstrap CRUD pages. It must clearly belong to the same Phase 2 product system.
