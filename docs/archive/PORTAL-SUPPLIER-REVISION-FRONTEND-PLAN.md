# Portal Supplier Revision — Frontend Implementation Plan

**Status:** FINAL / EXECUTION-READY  
**Repository:** `poyipoy/supplierportal`  
**Baseline branch:** `master`  
**Baseline commit:** `ad524231de1cd41814d159ab3abe9fd38b048571`  
**Scope:** Purchasing + Supplier presentation/UI/UX only, coordinated with the separate Backend Implementation Plan  
**Stack:** Laravel Blade, Bootstrap 5, existing `tw-` utility classes, existing UI components, JavaScript/jQuery/AJAX  
**Reference:** User-provided rough wireframe for stacked `Requested` / `Offer` quotation rows.

---

# 1. Objective

Reorganize the Purchase Requisition and Supplier Quotation interfaces so that:

- dense material lists consume less vertical space;
- wider data is allowed to use horizontal scrolling instead of tall multi-line cells;
- HS Code, dimensions, status, weight, price, and amount are easier to scan;
- requested data and supplier offer data are visually aligned;
- supplier can express unavailable items and length ranges safely;
- detail/review values display no more than 2 decimal places;
- existing Portal Supplier visual language and components are preserved.

This plan does **not** replace the existing frontend stack or redesign unrelated modules.

---

# 2. Locked Product Decisions

| Area | Final decision |
|---|---|
| PR layout scope | Apply to **Create + Edit PR** |
| Diameter presentation order | **Outer Diameter before Inner Diameter** in all affected UI |
| PR Detail columns | `No / HS Code / HS Status / Material / Shape / Dimensions / Qty / KG Unit / Total Weight / Remark` |
| Quotation layout | **Two stacked rows per material: Requested then Offer** |
| Quotation columns | Fixed aligned columns; irrelevant dimensions show `—` / disabled |
| Offer Qty | Must **not exceed requested Qty** |
| Supplier can supply extra | Keep Offer Qty at requested maximum; supplier explains surplus in Notes |
| Length | One Offer field supports exact `2300` or range `2300-2500` |
| Offer weight | Editable |
| Edited/manual Offer weight | Show **Est Weight** indicator |
| Range length | Requires estimated/manual weight because no single exact geometric weight exists |
| Amount | Calculate Requested Amount and Offer Amount |
| Not Available | Per-item option; all items may be unavailable and quotation may still be submitted |
| Import modes | Preserve existing behavior; add explanation for each mode |
| Display precision | Read-only/detail/review KG and monetary values: **maximum 2 decimals**, not fixed 2 |
| Input/storage precision | Do not reduce; existing 4-decimal input/storage precision may remain |

---

# 3. Verified Current Frontend Baseline

1. Purchasing PR Create and Edit both use:
   - `resources/views/purchasing/pr/_item_row.blade.php`
   - `resources/views/purchasing/pr/_form_table_styles.blade.php`
   - `resources/views/purchasing/pr/_material_shape_script.blade.php`
2. Current PR form combines `Master Material & HS Code` in one sticky column, then Shape, Qty, 3 dynamic dimension slots, KG / Unit, Remark, and Action.
3. Current PR item model defines Flat = thickness/width/length, Round = outer diameter/length, Hollow = inner diameter/outer diameter/length. Presentation must become Outer D -> Inner D -> Length for Hollow without renaming database fields.
4. Current Purchasing PR Detail combines HS status with HS Code and Shape with Dimensions.
5. Current Supplier Quotation Create is one physical row per PR item and places offered dimensions/qty inside a large `Supplier Availability` cell.
6. Existing supplier quotation import modes are `Fill Empty Fields Only` and `Replace Imported Fields`.
7. Existing PR import modes are `Replace Current Rows` and `Append to Current Rows`.

These behaviors are the baseline; do not replace them with a new framework or component library.

---

# 4. Frontend Guardrails

1. Keep `layouts.app` and existing page shell.
2. Keep existing `x-ui.*` components where practical.
3. Keep Bootstrap 5 and existing `tw-` utility namespace.
4. No new frontend package or CDN.
5. Do not duplicate backend business calculations as authoritative logic.
6. Client-side calculations are preview only; server result is authoritative after save/reload.
7. No database/query logic in Blade.
8. Horizontal scrolling is explicitly allowed.
9. Prefer compact rows over wrapped/tall cells.
10. Do not reduce database/input precision to satisfy display formatting.
11. Do not change unrelated list/DataTables screens unless they show directly affected fields.

---

# 5. FRONTEND PHASE 1 — Purchasing PR Create/Edit Table

## 5.1 Target layout

Apply to:

- `resources/views/purchasing/pr/create.blade.php`
- `resources/views/purchasing/pr/edit.blade.php`
- `resources/views/purchasing/pr/_item_row.blade.php`
- `resources/views/purchasing/pr/_form_table_styles.blade.php`
- `resources/views/purchasing/pr/_material_shape_script.blade.php`

Target logical columns:

```text
No
Material
HS Code
Shape
Qty
Dimension 1
Dimension 2
Dimension 3
KG / Unit
Remark
Action
```

Dimension slot order:

```text
Flat   -> Thickness | Width   | Length
Round  -> Outer D.  | Length  | —
Hollow -> Outer D.  | Inner D.| Length
```

Database/request names remain `d_outer` and `d_inner`; only presentation order changes.

## 5.2 Add `No` column

- Add compact first column.
- Existing rows use visual `$index + 1`.
- Newly added rows are renumbered from current DOM order.
- Deleting a row must renumber all visible numbers.
- Visible number must never become a business key.

Recommended hook:

```text
renumberPrRows()
```

Call after add, delete, import Replace, import Append, and initial render.

## 5.3 Separate Material and HS Code

### Material column
Contains only:

- material search field;
- hidden `material_master_id`;
- material-search results;
- material validation error.

### HS Code column
Contains:

- HS Code input;
- manual override hidden state;
- compact status chip.

To reduce row height, prefer same-line/compact status such as:

```text
[ 7228.30 ] [Auto]
```

Status semantics remain:

- Auto matched
- Manual selection
- Ambiguous
- No rule
- Unmapped material
- Needs more data

## 5.4 Compact density

Goal: more material rows visible before vertical scrolling.

- Keep `tw-text-ui-xs`.
- Use `form-control-sm` / `form-select-sm`.
- Reduce body cell vertical padding to roughly `0.35rem–0.45rem`.
- Change Remark textarea default to `rows="1"`; retain manual resize.
- Avoid help text inside each table row.
- Keep controls comfortably clickable.

## 5.5 Column sizing

Recommended initial widths:

| Column | Width |
|---|---:|
| No | 48px |
| Material | 230–260px |
| HS Code | 150–170px |
| Shape | 110px |
| Qty | 70–80px |
| Dimension slot | 115–125px each |
| KG / Unit | 120–130px |
| Remark | 170–190px |
| Action | 56–64px |

Use horizontal scrolling instead of aggressive wrapping.

## 5.6 Sticky behavior

Recommended:

- No: sticky left `0`;
- Material: sticky after No;
- Action: sticky right `0`.

HS Code scrolls normally so the frozen region does not consume too much width.

## 5.7 Dimension script

Update `_material_shape_script.blade.php` to present:

```text
Flat   = thickness, width, length
Round  = d_outer, length
Hollow = d_outer, d_inner, length
```

Hidden canonical inputs and server field names remain unchanged.

## 5.8 Import integration

Imported PR rows must:

- render row No correctly;
- populate separated Material and HS Code columns;
- use new dimension presentation order;
- preserve existing HS status rendering;
- renumber after Replace/Append.

---

# 6. FRONTEND PHASE 2 — Purchasing PR Detail

File:

`resources/views/purchasing/pr/show.blade.php`

## 6.1 Final columns

```text
No
HS Code
HS Status
Material
Shape
Dimensions
Qty
KG / Unit
Total Weight
Remark
```

## 6.2 HS Code

Only the code is displayed in the HS Code cell.

## 6.3 HS Status

Dedicated compact status column:

- Manual
- Auto
- Legacy
- No Rule
- Ambiguous
- Unmapped
- Unresolved

Use actual persisted resolution/source status; do not invent new backend states.

## 6.4 Shape

Dedicated Shape column:

```text
Flat / Round / Hollow
```

## 6.5 Dimensions

Dedicated dimension value.

Examples:

```text
Flat:   10 × 500 × 2500
Round:  Ø 50 × 2500
Hollow: OD 100 × ID 60 × 2500
```

Hollow must always display Outer before Inner.

## 6.6 Alignment

- No, HS Status, Shape, Qty: center.
- numeric weight: right.
- Material and Remark: left.
- Dimensions: left preferred.
- avoid centered paragraph-like Remark text.

## 6.7 Density

Avoid stacking badge + shape + dimension text in one cell. Each concept now has its own compact column.

---

# 7. FRONTEND PHASE 3 — Supplier Quotation Stacked Requested/Offer Layout

Primary file:

`resources/views/supplier/quotations/create.blade.php`

## 7.1 Row architecture

Each PR item becomes two visual rows:

```text
┌────┬──────────┬───────────┬─────┬─────────── ... ───────┐
│ No │ Material │ Requested │ ... requested values ...     │
│    │          ├───────────┼───────────────────────────────┤
│    │          │ Offer     │ ... supplier fields ...      │
└────┴──────────┴───────────┴───────────────────────────────┘
```

Use `rowspan="2"` for:

- No;
- Material/core identity.

Add a dedicated text row type:

- Requested
- Offer

## 7.2 Final fixed columns

```text
No
Material
Row Type
Qty
Thickness
Outer D.
Inner D.
Width
Length
KG / Unit
Total KG
Price / KG
Amount
Notes
MTC
Availability
```

Irrelevant shape dimensions display `—`.

Estimated IDR should preferably be secondary text inside the Offer Amount cell or a summary/footer rather than another very wide primary column.

## 7.3 Material cell

Keep compact:

```text
SCM440
HS 7228.30 · Hollow
```

PR Remark may be a small secondary line. Do not repeat all dimensions here.

## 7.4 Requested row

Read-only values:

- Requested Qty;
- requested dimensions;
- Requested KG / Unit;
- Requested Total KG;
- supplier Price/KG mirrored read-only once entered;
- Requested Amount;
- PR Remark in Notes;
- MTC `—`;
- Availability `Requested`.

Irrelevant dimensions show `—` as plain text, not disabled empty controls.

## 7.5 Offer row

Editable values:

- Offer Qty;
- offered dimensions;
- exact/range Length;
- Offer KG / Unit;
- Offer Total KG;
- Price/KG;
- Offer Amount;
- Notes;
- MTC;
- Available / Not Available.

---

# 8. Offer Quantity UX

## 8.1 Hard maximum in UI

Set:

```html
max="{{ $item->quantity_value }}"
```

and also rely on backend enforcement.

## 8.2 Error/help text

If supplier enters more than requested:

```text
Offer Qty cannot exceed the requested Qty (10).
If you can supply more, keep Qty at 10 and mention the additional capacity in Notes.
```

Do not silently clamp.

## 8.3 Copy Requested

Existing Copy functionality remains.

Copy should set:

- Qty = requested Qty;
- exact requested dimensions;
- exact requested length;
- offered weight initially equal/derived from requested weight;
- weight source remains automatic until supplier edits.

Do not automatically copy Notes unless current behavior requires it.

---

# 9. Length Exact/Range UX

## 9.1 Input format

Offer Length becomes text-compatible rather than `type="number"`.

Accepted examples:

```text
2300
2300-2500
2300 - 2500
```

Use `inputmode="decimal"`.

Normalize valid range on blur to:

```text
2300-2500
```

## 9.2 Invalid examples

Reject in UI:

```text
abc
2500-
2500-2300
0-2500
2300--2500
```

Message:

```text
Enter one length (e.g. 2300) or a range from minimum to maximum (e.g. 2300-2500).
```

Server validation remains authoritative.

## 9.3 Detail display

Canonical range presentation:

```text
2300–2500 mm
```

---

# 10. Offer KG / Unit and `Est Weight`

## 10.1 Exact dimensions

Where backend can calculate exact offered geometry:

- prefill/return calculated Offer KG / Unit;
- source is automatic until edited.

## 10.2 Manual edit

After supplier manually changes Offer KG / Unit:

```text
[ 12.45 ] kg [Est Weight]
```

Persist manual state through a hidden/backend field contract.

## 10.3 Range length

If Length is a range:

- do not pretend there is one exact automatic weight;
- supplier must enter/confirm Offer KG / Unit;
- show `Est Weight`.

If range changes back to exact:

- allow recalculation;
- do not silently overwrite an existing manual value without an explicit recalculation/copy action.

## 10.4 Requested row

Requested KG / Unit remains PR data and is never labeled `Est Weight` because supplier changed their offer.

---

# 11. Amount Calculation UX

## 11.1 Requested

```text
Requested Total KG = Requested Qty × Requested KG / Unit
Requested Amount   = Requested Total KG × Supplier Price / KG
```

## 11.2 Offer

```text
Offer Total KG = Offer Qty × Offer KG / Unit
Offer Amount   = Offer Total KG × Supplier Price / KG
```

## 11.3 Reactive preview

Update when:

- Offer Qty changes;
- Offer KG changes;
- Price/KG changes;
- Availability changes;
- auto-weight recalculation completes.

Requested Amount only changes with Price/KG.

## 11.4 Unavailable

When Not Available:

```text
Offer Total KG -> —
Offer Amount   -> —
```

Item contributes zero to Offer grand total.

## 11.5 Footer

Use clear label:

```text
TOTAL OFFER AMOUNT
```

rather than ambiguous `GRAND TOTAL`.

---

# 12. Not Available UX

## 12.1 Control

Use explicit labeled control:

```text
[ ] Not Available
```

or existing styled select/toggle. Do not communicate state using color only.

## 12.2 When active

Disable/not require:

- Offer Qty;
- dimensions;
- Offer KG;
- Price/KG;
- MTC.

Keep Notes enabled.

Suggested Notes placeholder:

```text
Optional reason or alternative availability information...
```

## 12.3 Presentation

Show explicit `Not Available` state in Offer row. Requested row stays visible.

## 12.4 All unavailable

UI must allow final submission when every item is Not Available.

Confirmation:

```text
Submit quotation with all requested items marked Not Available?
```

---

# 13. Width, Scrolling, Sticky Behavior

The stacked table is intentionally wide.

- retain horizontal scrolling;
- retain thin scrollbar;
- update scroll hint to `Scroll horizontally to compare Requested and Offer values for each material.`;
- recommended sticky cells: No, Material, Row Type;
- keep sticky header behavior;
- use a stronger top border for each Requested row and subtle alternate surface for Offer row;
- do not convert every material into a separate card because it increases vertical height.

---

# 14. Supplier/Purchasing Detail and Review

Audit/update at minimum:

- `resources/views/supplier/quotations/show.blade.php`
- `resources/views/purchasing/quotations/show.blade.php`
- `resources/views/purchasing/comparison/*`
- other Purchasing quotation review Blade files that expose the same fields.

## 14.1 Comparison semantics shown to user

Detail must understand:

- exact offered Length;
- Length range;
- Not Available;
- estimated weight;
- Qty shortage;
- requested value inside offered range.

Compact detail format is acceptable:

```text
Requested: Qty 10 · OD 100 · ID 60 · L 2500
Offer:     Qty 8  · OD 100 · ID 60 · L 2300–2500
           Est Weight 12.4 kg/unit
```

## 14.2 Status labels

Expected labels include:

```text
Available
Not Available
Exact Match
Different Specification
Requested Within Offered Range
Quantity Shortage
```

New data must not produce Quantity Surplus because backend will prohibit Offer Qty > Requested Qty. Historical data must still render safely.

---

# 15. Import Modal Notes

## 15.1 Purchasing PR Import

File:

`resources/views/purchasing/pr/_import.blade.php`

Keep modes unchanged.

### Replace Current Rows

```text
Removes the material rows currently shown in this form and replaces them with the validated rows from the spreadsheet.
```

### Append to Current Rows

```text
Keeps the material rows currently shown and adds validated spreadsheet rows below them.
```

Display helper text immediately below mode select and update it when the selected mode changes.

## 15.2 Supplier Quotation Import

File:

`resources/views/supplier/quotations/create.blade.php`

### Fill Empty Fields Only

```text
Only fills offer fields that are still empty. Existing values entered in the form are preserved.
```

### Replace Imported Fields

```text
Replaces the matching offer fields for the same PR items using values from the spreadsheet. It does not create additional quotation items.
```

Do not rename quotation mode to Append.

---

# 16. Maximum-2-Decimal Display Formatting

## 16.1 Rule

Read-only/detail/review only:

```text
10      -> 10
10.5    -> 10.5
10.50   -> 10.5
10.556  -> 10.56
```

Not fixed `10.00`.

## 16.2 Apply to

Purchasing + Supplier web read-only surfaces for:

- KG / Unit;
- Total KG;
- read-only Price/KG;
- Amount;
- equivalent monetary detail values.

Audit at minimum:

- Purchasing PR Detail;
- Purchasing Quotation Detail;
- Supplier Quotation Detail;
- Purchasing comparison pages;
- relevant PO Detail tables if they show KG/price/amount.

## 16.3 Do not apply to

- editable inputs;
- hidden values;
- request payloads;
- database casts;
- calculation precision;
- import parsing;
- raw Excel numeric precision unless separately required.

Inputs may remain `step="0.0001"`.

## 16.4 Shared formatting

Do not duplicate trimming logic across many Blade files. Reuse an existing support formatter if present; otherwise the Backend Plan may add one minimal presentation helper.

---

# 17. Accessibility

1. Every Offer input retains material-specific `aria-label`.
2. Requested/Offer row type is text, not color only.
3. Not Available has a real accessible label/state.
4. Irrelevant dimensions show `—` rather than empty ambiguous cells.
5. Qty validation message is associated with its input.
6. Length format helper uses `aria-describedby` where applicable.
7. Calculated values may use restrained `aria-live="polite"`; avoid noisy live updates.
8. Sticky cells must not cover focused controls.
9. Horizontal table container remains keyboard-focusable.

---

# 18. Frontend File Plan

| File | Action |
|---|---|
| `resources/views/purchasing/pr/create.blade.php` | Modify colgroup/header for No + separate HS Code |
| `resources/views/purchasing/pr/edit.blade.php` | Same structure as Create |
| `resources/views/purchasing/pr/_item_row.blade.php` | No cell; split Material/HS; compact row |
| `resources/views/purchasing/pr/_form_table_styles.blade.php` | Width/sticky/density changes |
| `resources/views/purchasing/pr/_material_shape_script.blade.php` | Outer-before-Inner presentation; row renumber hook |
| `resources/views/purchasing/pr/_import.blade.php` | Mode explanations + renumber integration |
| `resources/views/purchasing/pr/show.blade.php` | Dedicated HS Status/Shape/Dimensions + max-2 display |
| `resources/views/supplier/quotations/create.blade.php` | Major Requested/Offer stacked redesign |
| `resources/views/supplier/quotations/show.blade.php` | Range/unavailable/estimated weight + precision |
| `resources/views/purchasing/quotations/show.blade.php` | New Offer semantics + precision |
| `resources/views/purchasing/comparison/*` | Range/unavailable/weight + precision |
| relevant Purchasing/Supplier PO detail Blade | Precision only where applicable |

Do not modify every list/index page merely because it exists.

---

# 19. JavaScript Responsibilities

## PR

```text
renumberPrRows()
updateMaterialDimensionHeaders()
applyMaterialShapeRules()
existing material preview
existing HS resolution behavior
```

Call row renumbering after add, delete, import, and initial load.

## Quotation

Recommended functions:

```text
parseOfferLength(value)
formatOfferLength(parsed)
updateOfferRowState(row)
updateOfferWeightState(row)
recalculateQuotationRow(row)
recalculateQuotationTotals()
validateOfferQuantity(row)
setItemUnavailable(row, unavailable)
copyRequestedToOffer(row)
```

JS calculations remain previews only.

---

# 20. Frontend Verification Plan

## Purchasing PR Create/Edit

- No column renders.
- Material and HS Code are separate.
- Flat order correct.
- Round starts Outer D.
- Hollow is Outer D -> Inner D -> Length.
- add row increments No.
- delete middle row renumbers.
- table remains compact.
- horizontal scroll works.

## PR Import

- Replace note visible and correct.
- Append note visible and correct.
- behavior unchanged.
- imported rows renumber.

## PR Detail

- HS status separate.
- Shape separate.
- Dimensions separate.
- weights max 2 decimals.

## Supplier Quotation

- each material produces Requested + Offer rows;
- irrelevant dimensions show `—`;
- Qty > Requested is blocked with correct guidance;
- exact length works;
- range works;
- invalid/reversed range is rejected;
- range/manual weight shows Est Weight;
- requested and offer total kg recalculate;
- requested and offer amount recalculate;
- Not Available disables applicable offer fields;
- Notes remains editable;
- unavailable item does not contribute to Offer total;
- all unavailable can submit;
- horizontal scroll and sticky columns work.

## Quotation Import

- Fill Empty description correct.
- Replace Imported description correct.
- mode behavior unchanged.

## Detail/review

- range renders correctly;
- Est Weight visible;
- Not Available visible;
- no read-only KG/price/amount exceeds 2 decimals.

---

# 21. Frontend Acceptance Criteria

- [ ] Create and Edit PR share the revised structure.
- [ ] PR has No column.
- [ ] HS Code has its own column.
- [ ] Material no longer contains HS input.
- [ ] Hollow displays Outer D before Inner D.
- [ ] PR rows are vertically more compact than baseline.
- [ ] Horizontal scrolling is intentional and usable.
- [ ] PR Detail uses agreed 10-column logical structure.
- [ ] Supplier quotation uses Requested + Offer rows per material.
- [ ] Requested/Offer values align under fixed columns.
- [ ] Offer Qty cannot exceed requested Qty.
- [ ] UI tells supplier to place surplus capability in Notes.
- [ ] Offer Length accepts exact/range.
- [ ] Manual/range weight shows Est Weight.
- [ ] Requested and Offer Total KG are calculated/displayed.
- [ ] Requested and Offer Amount are calculated/displayed.
- [ ] Not Available works per item.
- [ ] All-unavailable quotation is permitted by UI.
- [ ] Import options have clear explanations.
- [ ] Existing import behavior is unchanged.
- [ ] Read-only KG/price/amount display uses max 2 decimals.
- [ ] Input precision is unchanged.
- [ ] Existing visual system is preserved.
- [ ] No new frontend package/CDN.

---

# 22. Execution Order

1. Read `AGENTS.md`.
2. Reconcile current HEAD against baseline.
3. Implement PR Create/Edit table structure.
4. Update dimension presentation ordering and row numbering.
5. Update PR import helper text and numbering integration.
6. Update PR Detail.
7. Confirm backend field contract before supplier quotation edits.
8. Implement stacked Requested/Offer table.
9. Implement Qty/range/weight/unavailable UI states.
10. Wire preview calculations to backend-defined semantics.
11. Update supplier/purchasing detail/review presentation.
12. Apply max-2-decimal formatter to affected read-only surfaces.
13. Run Laravel feature tests and `npm run build`.
14. Perform manual browser smoke at desktop and narrower widths.

---

# 23. Non-Goals

Do not:

- redesign navigation/dashboard;
- change supplier invitation workflow;
- add quotation statuses;
- change MTC storage;
- change currencies/exchange-rate workflow;
- add a frontend framework;
- rewrite DataTables globally;
- change Excel import mode semantics;
- reduce stored numeric precision;
- replace desktop table with tall cards.

---

# 24. Coding-Agent Rules

1. Read the companion Backend Plan first.
2. Inspect actual HEAD before editing.
3. Preserve newer correct behavior if HEAD moved.
4. Do not create fake frontend-only persisted fields.
5. Do not duplicate financial/weight formulas as authoritative code.
6. Keep JS calculations as user feedback only.
7. Preserve existing design tokens/components.
8. Prefer minimal changes to shared files.
9. Run `npm run build`.
10. Run relevant Laravel feature tests.
11. Report manual smoke states completed.
12. Document every plan deviation and technical reason.
