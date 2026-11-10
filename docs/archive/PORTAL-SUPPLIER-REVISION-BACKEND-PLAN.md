# Portal Supplier Revision — Backend Implementation Plan

**Status:** FINAL / EXECUTION-READY  
**Repository:** `poyipoy/supplierportal`  
**Baseline branch:** `master`  
**Baseline commit:** `ad524231de1cd41814d159ab3abe9fd38b048571`  
**Scope:** Data model, validation, calculation, persistence, import/export compatibility, comparison semantics, automated tests  
**Companion:** `PORTAL-SUPPLIER-REVISION-FRONTEND-PLAN.md`

---

# 1. Objective

Extend the existing PR/Quotation backend to support the approved UI/business behavior without replacing established architecture.

Required backend capabilities:

1. Preserve PR data model while supporting presentation order Outer Diameter before Inner Diameter.
2. Support Supplier Offer Length as exact value or numeric min/max range.
3. Support supplier-specific Offer KG / Unit.
4. Distinguish automatic offered weight from estimated/manual weight.
5. Calculate Requested Total KG, Offer Total KG, Requested Amount, and Offer Amount.
6. Make Offer Amount authoritative for newly saved/revised quotation item totals.
7. Enforce `Offer Qty <= Requested Qty`.
8. Support per-item `Not Available`.
9. Allow a submitted quotation where every item is Not Available.
10. Preserve existing import mode semantics.
11. Keep historical quotation rows readable.
12. Keep storage/calculation precision; max-2-decimal rule is presentation-only.

---

# 2. Locked Product Decisions

| Area | Final decision |
|---|---|
| PR Create/Edit | Both revised |
| Diameter UI order | Outer before Inner |
| Quotation visual model | Requested row + Offer row |
| Offer Qty | Never greater than requested |
| Extra supply capacity | Put in Notes; do not increase Offer Qty beyond request |
| Length exact | Supported |
| Length range | Supported as structured min/max |
| Range raw string in DB | **No** |
| Existing `available_length` | Preserve for exact/legacy compatibility |
| Offer KG / Unit | Editable |
| Manual Offer weight | Mark as `Est Weight` |
| Range length weight | Estimated/manual |
| Requested Amount | Requested total kg × supplier price/kg |
| Offer Amount | Offer total kg × supplier price/kg |
| Grand quotation total | Sum Offer Amount for available items |
| Not Available | Per quotation item |
| All items unavailable | Valid formal submission |
| Import mode behavior | No semantic change |
| Display precision | Presentation only; max 2 decimals |

---

# 3. Verified Current Backend Baseline

## 3.1 `PrItem`

Current model already contains:

```text
shape
thickness
d_inner
d_outer
width
length
weight_needed
weight_calculation_status
remark
```

Current relevance:

```text
Flat   -> thickness, width, length
Round  -> d_outer, length
Hollow -> d_inner, d_outer, length
```

The backend field names are correct and must remain unchanged.

## 3.2 `QuotationItem`

Current model contains:

```text
quotation_id
pr_item_id
price_per_kg
amount
available_qty
available_thickness
available_d_inner
available_d_outer
available_width
available_length
notes
```

Current casts use 4-decimal availability/price/amount precision.

Current `calculateAmount()` uses:

```text
requested PR total_weight × price_per_kg
```

This is insufficient for new Offer-specific amount semantics.

## 3.3 Current availability comparison

Current logic can classify:

- Quantity Not Specified
- Quantity Shortage
- Quantity Surplus
- Quantity Match
- Not Specified
- Different Specification
- Exact Match

New logic must prevent new Quantity Surplus, support Not Available, and understand length ranges.

## 3.4 Current quotation persistence

`QuotationController::store()` currently:

- validates exact item set;
- re-queries each `PrItem`;
- calculates `amount` from PR requested total weight;
- deletes/recreates quotation items in a transaction;
- preserves MTC attachments by reattaching them;
- supports draft/submitted/revision flow.

Retain the transaction and exact-PR-item protection.

## 3.5 Current quotation import

Existing infrastructure includes:

- `QuotationItemsImport`;
- `QuotationImportTemplateExport`;
- preview endpoint;
- UI application modes `Fill Empty` and `Replace Imported Fields`.

Do not convert quotation import to Append semantics.

---

# 4. Backend Guardrails

1. Read root `AGENTS.md` before coding.
2. No direct push to `master`.
3. No historical migration edits.
4. New DB changes use new reversible migrations.
5. Preserve supplier data isolation.
6. Preserve exact `pr_item_id` ownership validation.
7. Keep multi-table save transaction.
8. Never trust Requested Qty/weight/dimensions sent by browser.
9. Re-query `PrItem` server-side.
10. Never trust browser-calculated Total KG or Amount.
11. No new Composer/npm package.
12. Reuse existing material weight calculation logic; do not duplicate formulas.
13. Preserve existing numeric business precision.
14. `Not Available` is explicit state, not inferred from zeros.
15. Do not use `price_per_kg = 0` as the primary unavailable sentinel.
16. Do not mass-recalculate historical submitted quotations.
17. Preserve final quotation authorization/revision restrictions.
18. Preserve currency/exchange-rate snapshot behavior.

---

# 5. BACKEND PHASE 1 — PR Presentation Contract Only

No PR schema migration is required for:

- HS Code becoming a separate UI column;
- No column;
- Outer-before-Inner display.

## 5.1 Dimension presentation order

Do not rename `d_outer` / `d_inner` and do not rewrite old rows.

Preferred approach: add an explicit presentation-order contract on `PrItem`, separate from canonical relevance if canonical order may be used elsewhere.

Example:

```php
public const PRESENTATION_DIMENSIONS = [
    self::SHAPE_FLAT => ['thickness', 'width', 'length'],
    self::SHAPE_ROUND => ['d_outer', 'length'],
    self::SHAPE_HOLLOW => ['d_outer', 'd_inner', 'length'],
];
```

and:

```php
public static function presentationDimensionFields(?string $shape): array
```

Use this for forms/display.

Do not blindly reorder `RELEVANT_DIMENSIONS` unless repository audit proves order has no behavioral impact on sanitization, calculator, import, or tests.

---

# 6. BACKEND PHASE 2 — Quotation Item Schema

Create a new migration, e.g.:

```text
database/migrations/2026_08_28_XXXXXX_add_offer_fields_to_quotation_items_table.php
```

Do not edit the existing availability migration.

## 6.1 New fields

Add:

```text
is_available
available_length_min
available_length_max
offered_weight_per_unit
offered_weight_source
```

### `is_available`

```text
boolean, default true
```

### `available_length_min`

Nullable decimal using the same precision/scale as existing `available_length`.

### `available_length_max`

Nullable decimal using the same precision/scale as existing `available_length`.

### `offered_weight_per_unit`

Nullable decimal using the same business precision/scale as `pr_items.weight_needed`.

Do not reduce to 2 decimals.

### `offered_weight_source`

Nullable string.

Allowed new-record values:

```text
auto
estimated
```

`null` represents legacy/not-yet-established state.

Avoid DB enum unless the repository consistently uses it.

## 6.2 Price nullability

Audit current `quotation_items.price_per_kg` definition.

If non-nullable, add a reversible migration change allowing null when:

```text
is_available = false
```

Do not use fake price zero just to satisfy schema.

## 6.3 Amount

Existing `amount` may remain non-null.

Unavailable item stores:

```text
amount = 0
```

State is still determined by `is_available`.

## 6.4 Rollback compatibility

Down migration may remove additive fields.

If `price_per_kg` nullability is changed, do not blindly restore NOT NULL while unavailable rows with null prices exist. Document safe rollback procedure.

---

# 7. Length Exact/Range Domain

## 7.1 Persistence contract

### Exact input

```text
2300
```

Persist:

```text
available_length     = 2300
available_length_min = null
available_length_max = null
```

### Range input

```text
2300-2500
```

Persist:

```text
available_length     = null
available_length_min = 2300
available_length_max = 2500
```

This preserves `available_length` for exact/legacy data.

## 7.2 Mutual exclusivity

Newly saved data must never contain both exact and min/max values simultaneously.

## 7.3 Shared parser

Because the same syntax is required in HTTP and Excel import, a small shared parser is justified.

Suggested namespace/name:

```text
App\Support\Materials\DimensionRange
```

or nearest existing convention.

Contract should normalize to exact or range structure.

Accepted:

```text
2300
2300-2500
2300 - 2500
```

Optionally normalize Unicode en dash.

Reject:

- nonnumeric;
- incomplete range;
- zero/negative;
- min > max;
- malformed multiple hyphens.

## 7.4 Display accessor

Expose a model/helper value such as:

```text
available_length_display
```

so views do not reconstruct persistence logic.

---

# 8. Offered Weight Domain

## 8.1 Meaning

`offered_weight_per_unit` is the supplier-side KG / Unit used for Offer Total KG.

It is separate from PR `weight_needed`.

## 8.2 Source semantics

```text
auto
estimated
```

### Auto

Use when exact offered geometry can be calculated by existing material weight logic and supplier has not manually overridden it.

### Estimated

Use when:

- supplier manually edits Offer KG / Unit;
- offered Length is a range;
- exact automatic calculation cannot produce one deterministic value and supplier provides an estimate.

Frontend maps `estimated` to:

```text
Est Weight
```

## 8.3 Reuse existing weight calculator

Audit/reuse:

```text
App\Services\Materials\MaterialWeightCalculator
```

Do not duplicate weight formulas in `QuotationItem`, controller, import class, or JS.

If calculator currently only accepts a PR-specific structure, refactor the smallest reusable boundary so it can receive material/shape/exact dimensions without mutating the PR item.

Add PR regression tests if calculator signature changes.

## 8.4 Range rule

If a required dimension is a range, do not calculate from min, max, or midpoint.

Weight must be supplier-provided/confirmed and marked:

```text
offered_weight_source = estimated
```

---

# 9. Quantity Validation

## 9.1 Hard rule

For available item:

```text
1 <= offered_qty <= requested_qty
```

Requested Qty comes from persisted `PrItem` only.

## 9.2 Error

Recommended:

```text
The offered quantity cannot exceed the requested quantity of :qty. If you can supply more, enter the requested quantity and describe the additional capacity in Notes.
```

## 9.3 Server implementation

Use authoritative cross-record validation through one of:

- Form Request `after()` validation;
- Validator `after()` callback;
- a small custom rule using the authoritative PR item.

Do not rely on HTML `max`.

## 9.4 Historical surplus

Do not mass-edit historical rows where `available_qty > requested_qty`.

New rule applies to new/revised saves after deployment.

---

# 10. Not Available Domain

## 10.1 Persistence

Explicit:

```text
is_available = false
```

## 10.2 Sanitization

For unavailable item, authoritative normalized values:

```text
is_available = false
available_qty = null
available_thickness = null
available_d_inner = null
available_d_outer = null
available_width = null
available_length = null
available_length_min = null
available_length_max = null
offered_weight_per_unit = null
offered_weight_source = null
price_per_kg = null
amount = 0
```

Keep `notes`.

MTC is not required. Preserve existing attachment-relink behavior during revision unless explicit remove functionality already exists.

## 10.3 Item set

Unavailable items remain quotation items. Do not remove them from the 1:1 response set.

## 10.4 All unavailable

Quotation may still submit with existing quotation status:

```text
submitted
```

Do not add `no_bid` or another workflow status.

## 10.5 Total

Unavailable item contributes:

```text
Offer Total KG = 0
Offer Amount = 0
```

---

# 11. Calculation Semantics

Use explicit method names so Requested and Offer are never confused.

## 11.1 Requested Total KG

Authoritative existing:

```php
$prItem->total_weight
```

## 11.2 Offer Total KG

For new available offer:

```text
available_qty × offered_weight_per_unit
```

Do not accept a client total.

## 11.3 Requested Amount

Derived comparison:

```text
PrItem total_weight × price_per_kg
```

Suggested method/accessor:

```text
calculateRequestedAmount(...)
requested_amount
```

If price is null, Requested Amount is null/`—`.

## 11.4 Offer Amount

For available new/updated row:

```text
Offer Total KG × price_per_kg
```

Store result into existing:

```text
quotation_items.amount
```

Unavailable:

```text
amount = 0
```

## 11.5 Refactor ambiguous `calculateAmount()`

Current method semantically calculates Requested Amount.

Preferred explicit methods:

```php
calculateRequestedAmount(PrItem $prItem, mixed $pricePerKg)
calculateOfferAmount(mixed $offerTotalWeight, mixed $pricePerKg)
```

Update callers/tests.

## 11.6 Historical records

Do not recalculate existing submitted quotation rows in migration.

Existing stored positive `amount` remains authoritative historical record.

For legacy rows lacking new offered-weight fields:

- render safely using PR weight as display fallback if necessary;
- do not overwrite stored amount;
- keep `resolved_amount` preferring stored positive amount.

For newly saved/revised rows, `amount` means Offer Amount.

Document this transition in tests/comments.

---

# 12. Quotation Item Normalization

Extend current shape-aware sanitization.

Available normalized output should include:

```text
is_available
available_qty
available_thickness
available_d_inner
available_d_outer
available_width
available_length
available_length_min
available_length_max
offered_weight_per_unit
offered_weight_source
```

Non-relevant dimensions remain null.

Unavailable items use the full clearing contract from Phase 10.

---

# 13. Request Field Contract

Recommended per item:

```text
items[n][pr_item_id]
items[n][is_available]
items[n][available_qty]
items[n][available_thickness]
items[n][available_d_outer]
items[n][available_d_inner]
items[n][available_width]
items[n][available_length_input]
items[n][offered_weight_per_unit]
items[n][offered_weight_manual_override]
items[n][price_per_kg]
items[n][notes]
items[n][mtc_file]
```

Do not trust or require client fields for:

```text
requested_total_weight
requested_amount
offer_total_weight
offer_amount
```

All totals are recalculated server-side.

---

# 14. Validation Strategy

Current `QuotationController` is already large.

Preferred: introduce/extend a dedicated Form Request only if consistent with repository conventions and it materially reduces controller complexity.

Otherwise extract only normalization/calculation into a focused service; do not introduce repository/DTO layers speculatively.

## 14.1 Base rules

Per item:

```text
pr_item_id      required, integer, distinct, belongs to this PR
is_available    required, boolean
notes           nullable, string
mtc_file        existing file rules
```

Available offer fields:

```text
available_qty              numeric/integer rule + authoritative max requested
available_thickness        numeric as shape requires
available_d_outer          numeric as shape requires
available_d_inner          numeric as shape requires
available_width            numeric as shape requires
available_length_input     exact/range parser rule
offered_weight_per_unit    numeric > 0
price_per_kg               conditional based on availability/current draft-final convention
```

## 14.2 Irrelevant dimensions

A tampered request sending Inner D for Flat must still be sanitized to null.

## 14.3 Draft vs final

Preserve current draft/final behavior as much as possible.

Minimum required change:

- Not Available item is exempt from offer price/dimension/weight requirements.
- Available item on final submit must provide enough authoritative data for Offer Amount: Qty, Offer KG/Unit, Price/KG.

If current draft rules intentionally require price, keep that behavior unless the Not Available exemption requires relaxation. Document any draft-validation change.

---

# 15. Persistence Flow in `QuotationController::store()`

For each validated item:

```text
1. Load authoritative PrItem for this PR.
2. Resolve is_available.
3. Validate Offer Qty <= requested Qty.
4. Parse exact/range Length.
5. Sanitize only relevant dimensions.
6. Resolve offered weight + source.
7. Calculate Offer Total KG server-side.
8. Calculate Offer Amount server-side.
9. Persist QuotationItem.
10. Preserve/upload MTC using existing behavior.
```

Unavailable branch:

```text
price = null
offer amount = 0
offer fields cleared
notes retained
```

Never persist browser-calculated amount.

---

# 16. Quotation Totals and Downstream Consumers

Audit `Quotation` accessors/aggregations for:

```text
total_amount
total_idr
```

New behavior:

```text
total_amount = SUM(items.amount)
```

where new `amount` is Offer Amount and unavailable rows are 0.

Requested comparison total is not the official quotation total.

Mandatory regression audit: trace all consumers of:

```text
quotation_items.amount
Quotation::total_amount
```

especially:

- Purchasing comparison;
- quotation detail;
- PO creation;
- exports;
- acceptance/review calculations.

Do not blindly modify PO calculations. Confirm each consumer intentionally should use Offer Amount before changing it.

---

# 17. Availability Comparison Semantics

Extend `QuotationItem::availability_comparison`.

## 17.1 Quantity

New records:

- null -> Not Specified only where draft permits;
- `< requested` -> Quantity Shortage;
- `== requested` -> Quantity Match;
- `> requested` -> validation failure; never newly persisted.

Historical surplus data may retain a safe legacy Surplus display.

## 17.2 Not Available

If `is_available=false`, comparison must return/display `Not Available`, not `Different Specification`.

## 17.3 Exact dimensions

Keep existing tolerance behavior.

## 17.4 Range Length

If Requested Length lies inside Offer range and all other relevant dimensions match:

```text
Requested Within Offered Range
```

If requested length lies outside range:

```text
Different Specification
```

If another dimension differs, final specification is still Different Specification even if requested Length is within range.

---

# 18. Estimated Weight State

Expose simple domain state/accessor:

```text
is_estimated_weight
```

true when:

```text
offered_weight_source === 'estimated'
```

Do not make estimated weight itself a specification mismatch or new workflow status.

---

# 19. Import Compatibility

Affected:

- `app/Imports/QuotationItemsImport.php`
- `app/Exports/QuotationImportTemplateExport.php`
- `QuotationController::importPreview()`
- import tests.

## 19.1 Mode semantics unchanged

Preview remains independent from UI apply mode.

Do not add quotation Append.

## 19.2 Length

Existing numeric exact Length remains accepted.

Also accept:

```text
2300-2500
```

through the same shared parser used by web form.

## 19.3 Template columns

Recommended updated headings:

```text
PR Item ID
Availability
Price/Kg
Available Qty
Thickness
Outer Diameter
Inner Diameter
Width
Length
Offer Weight/Kg
Notes
```

`Availability` canonical values:

```text
Available
Not Available
```

Backward compatibility:

- missing Availability -> Available;
- numeric Length -> exact;
- old spreadsheet layout should remain parseable where practical.

## 19.4 Qty validation

Import preview rejects `available_qty > requested_qty` with row/column-specific error.

## 19.5 Not Available import

For Not Available row with offer numeric values:

preferred behavior:

- warn;
- sanitize numeric offer fields away;
- preserve Notes.

## 19.6 Security

Preserve existing spreadsheet size/type/formula-injection protections.

---

# 20. Export / Detail Compatibility

Audit quotation detail exports if they currently expose offer dimensions.

Where applicable include:

- Availability / Not Available;
- Offer Qty;
- exact/range Length;
- Offer KG / Unit;
- Weight Source / Est Weight;
- Offer Total KG;
- Price/KG;
- Offer Amount.

Do not alter unrelated list-export schemas unless necessary.

Keep raw spreadsheet numeric precision; web max-2-decimal rule is not a storage/export rounding requirement.

---

# 21. Maximum-2-Decimal Presentation Support

Search for an existing formatter first.

If none exists, add one minimal shared support method such as:

```php
NumberFormat::maxDecimals($value, 2)
```

Expected output:

```text
10      -> 10
10.5    -> 10.5
10.556  -> 10.56
```

It may support thousands separators for money while still trimming unnecessary trailing zeros.

Never use this helper for authoritative calculations or model casts.

---

# 22. Model Changes

## `app/Models/PrItem.php`

Potential only:

- presentation-dimension order helper/constant.

No PR persistence change.

## `app/Models/QuotationItem.php`

Modify:

- fillable;
- casts;
- availability normalization;
- exact/range Length accessors;
- Offer Total KG calculation;
- Requested Amount calculation;
- Offer Amount calculation;
- estimated-weight accessor;
- availability comparison.

Do not turn the model into a generic parser; shared parsing belongs in a small support class when reused.

## `app/Models/Quotation.php`

Audit/adjust only totals/accessors affected by Offer Amount semantics.

---

# 23. Service Changes

Audit:

`app/Services/Materials/MaterialWeightCalculator.php`

Goal: reuse exact material formulas for exact offered geometry.

If necessary, add a focused service:

```text
App\Services\Quotations\QuotationOfferCalculator
```

Only if it prevents duplicated cross-controller/import calculation logic.

Responsibilities may include:

- exact offered weight via existing calculator;
- Offer Total KG;
- Offer Amount.

Do not move persistence/authorization into it.

---

# 24. Controller Changes

Primary:

`app/Http/Controllers/Supplier/QuotationController.php`

Modify:

- validation;
- import preview contract;
- authoritative Qty max check;
- exact/range parsing;
- Not Available sanitization;
- offered weight source resolution;
- Offer Amount persistence.

Keep:

- supplier visibility check;
- revision authorization;
- transaction;
- currency validation;
- exchange-rate snapshot;
- MTC behavior;
- PR status transition;
- Purchasing notification.

---

# 25. Detail/Comparison Data Contract

Views should not duplicate domain logic.

Expose/access safely:

```text
requested_quantity
offered_quantity
requested_weight_per_unit
offered_weight_per_unit
requested_total_weight
offered_total_weight
requested_amount
offer amount / resolved_amount
available_length_display
is_estimated_weight
is_available
availability_comparison
```

Not every value requires a DB column; prefer derived values where appropriate.

---

# 26. Automated Test Plan

Use existing suites as baseline and add focused cases.

---

# 27. Quotation Availability Tests

Update `tests/Feature/QuotationAvailabilityTest.php`.

## QA1 — Qty equal requested

Pass.

## QA2 — Qty below requested

Pass; classify Quantity Shortage.

## QA3 — Qty above requested

Reject; DB unchanged.

## QA4 — surplus via Notes

Offer Qty stays requested maximum and Notes contains additional-capacity explanation. Pass.

## QA5 — exact length

Persist exact `available_length`; range columns null.

## QA6 — range length

Input `2300-2500`; exact column null, min/max persisted.

## QA7 — reversed range

`2500-2300` rejected.

## QA8 — requested length inside range

Comparison returns Requested Within Offered Range when all other dimensions match.

## QA9 — requested length outside range

Comparison returns Different Specification.

---

# 28. Offered Weight Tests

## W1 — exact auto weight

Exact offered dimensions use existing calculator and persist source `auto`.

## W2 — manual override

Supplier edited weight persists and source = `estimated`.

## W3 — range forces estimated

Range Length + supplier weight -> source `estimated`.

## W4 — insufficient weight for final offer

Final submission fails if an available offer cannot produce authoritative Offer Amount.

## W5 — PR untouched

Saving Supplier Offer never changes `pr_items.weight_needed`.

---

# 29. Amount Tests

## A1 — Requested Amount

Given:

```text
requested qty = 10
requested kg/unit = 2.5
price = 100
```

Expected:

```text
requested total kg = 25
requested amount = 2500
```

## A2 — Offer Amount

Given:

```text
offer qty = 8
offer kg/unit = 2.4
price = 100
```

Expected:

```text
offer total kg = 19.2
offer amount = 1920
```

## A3 — stored amount

New available quotation row stores Offer Amount in `quotation_items.amount`.

## A4 — unavailable

Amount = 0.

## A5 — total

Grand quotation total sums only Offer Amounts; unavailable rows contribute 0.

## A6 — historical

Existing positive stored amount remains readable without migration-time recalculation.

---

# 30. Not Available Tests

## NA1 — one unavailable item

Persist state false, clear numeric offer fields, amount 0, preserve Notes.

## NA2 — mixed items

Available items calculate; unavailable item excluded from total.

## NA3 — all unavailable

Final submission succeeds with existing `submitted` status.

## NA4 — tampered unavailable numeric fields

Server sanitizes them; hidden values cannot affect total.

---

# 31. Import Tests

Update existing import tests, including `MissionFiveImportTest` if still current.

- old exact-length template still parses;
- range parses;
- Qty above Requested fails preview;
- Not Available imports;
- unavailable extra numeric values are sanitized/warned;
- existing Fill Empty / Replace Imported behavior remains unchanged;
- no additional QuotationItem is appended.

---

# 32. PR Regression Tests

Run/update `PurchaseRequisitionMaterialAutomationTest`.

Ensure:

- Outer/Inner UI ordering does not alter sanitization;
- Hollow weight formula unchanged;
- HS resolution unchanged;
- PR weight manual/auto behavior unchanged;
- PR import business behavior unchanged.

---

# 33. Security / Authorization Regression

Test:

1. Supplier cannot quote uninvited PR.
2. Supplier cannot modify another supplier's quotation.
3. `pr_item_id` must belong to selected PR.
4. forged Requested Qty/weight/amount is ignored/rejected.
5. final submitted quotation remains read-only unless revision requested.
6. import preview keeps supplier/PR isolation.
7. MTC attachment ownership remains scoped.

---

# 34. Backend File Plan

Expected after HEAD reconciliation:

| File | Action |
|---|---|
| `database/migrations/...add_offer_fields_to_quotation_items_table.php` | NEW |
| `app/Models/PrItem.php` | Presentation-order helper only if needed |
| `app/Models/QuotationItem.php` | New fields/calculations/range/comparison |
| `app/Models/Quotation.php` | Audit/adjust total aggregation if required |
| `app/Http/Controllers/Supplier/QuotationController.php` | Validation/persistence/import |
| `app/Services/Materials/MaterialWeightCalculator.php` | Minimal reusable offer calculation support if needed |
| `app/Services/Quotations/QuotationOfferCalculator.php` | NEW only if justified |
| `app/Support/...DimensionRange.php` | NEW shared parser |
| `app/Imports/QuotationItemsImport.php` | Range/availability/weight/Qty validation |
| `app/Exports/QuotationImportTemplateExport.php` | New optional offer columns |
| quotation detail export classes | Modify only if current output requires new fields |
| shared number formatter | NEW only if no equivalent exists |
| `tests/Feature/QuotationAvailabilityTest.php` | Modify |
| `tests/Feature/MissionFiveImportTest.php` | Modify |
| `tests/Feature/PurchaseRequisitionMaterialAutomationTest.php` | Regression/update |
| other quotation calculation tests | Add/modify focused cases |

Avoid touching unrelated authentication hardening.

---

# 35. Route/API Impact

Prefer **no new public route**.

Reuse existing:

- quotation create/store;
- import preview;
- import template;
- existing material preview/calculation path where safe.

If exact offered-weight AJAX calculation cannot reuse an existing authorized endpoint, add one supplier-authenticated route scoped to accessible PR/item and backed by existing weight calculator.

Do not expose unauthenticated generic calculator endpoints.

---

# 36. Deployment Sequence

1. Back up production DB.
2. Confirm current HEAD.
3. Review migration against production MySQL schema.
4. Confirm application code is compatible with legacy rows/new nullable fields.
5. Deploy using existing cPanel procedure.
6. Typical non-atomic sequence:

```bash
php artisan down --retry=60
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan up
```

7. Smoke:
   - PR create/edit;
   - exact quotation;
   - range quotation;
   - Not Available;
   - all unavailable;
   - import;
   - Purchasing review;
   - PO/comparison downstream totals.

---

# 37. Rollback

## Application rollback

Additive columns may remain if code rolls back.

Prefer leaving them in place rather than immediately running down migration.

## Price nullability

Do not restore NOT NULL until confirming no unavailable rows have null price.

## Amount semantics

Do not run a bulk rollback that converts new Offer Amounts back to Requested Amounts.

Stored quotation amounts are transactional business records.

Rollback code should safely read stored amount.

---

# 38. Risks and Mitigations

| Risk | Mitigation |
|---|---|
| Existing `amount` meaning changes for new saves | Explicit requested-vs-offer methods + audit all downstream consumers |
| Old rows lack offered weight | Legacy fallback; no mass recalculation |
| Range cannot auto-calculate exact weight | Require/mark estimated supplier weight |
| Supplier tampers Qty above requested | Server compares to persisted PrItem |
| Client tampers totals | Ignore client totals; recalculate server-side |
| Not Available contributes accidentally | Explicit state clears commercial fields and stores amount 0 |
| Price column non-nullable | New reversible nullable migration if required |
| Range import breaks old files | Numeric exact path stays supported |
| Reordering canonical dimension list breaks formulas | Separate presentation-order contract |
| Historical surplus violates new rule | Enforce only on new/revised saves |
| MTC lost during item recreation | Preserve current reattachment logic |
| Draft behavior changes unexpectedly | Focused workflow regression tests |

---

# 39. Validation Commands

Focused:

```bash
php artisan test tests/Feature/QuotationAvailabilityTest.php
php artisan test tests/Feature/MissionFiveImportTest.php
php artisan test tests/Feature/PurchaseRequisitionMaterialAutomationTest.php
```

Run other quotation tests discovered during audit.

Then:

```bash
composer test
vendor/bin/pint --test
npm run build
```

Migration/route checks:

```bash
php artisan migrate:status
php artisan migrate --pretend
php artisan route:list --path=quotations
php artisan route:list --path=requisitions
```

Do not use destructive DB commands on shared/staging/production data.

---

# 40. Acceptance Criteria

## PR

- [ ] No PR migration created solely for visual order.
- [ ] Outer-before-Inner does not rename DB/request fields.
- [ ] PR automation remains correct.

## Length

- [ ] Exact Offer Length remains supported.
- [ ] Range persists as min/max, not raw string.
- [ ] Existing `available_length` stays backward compatible.
- [ ] Invalid/reversed range rejected.
- [ ] Comparison understands requested-within-range.

## Qty

- [ ] Backend rejects Offer Qty > Requested Qty.
- [ ] Requested Qty comes from DB.
- [ ] Qty below/equal Requested works.
- [ ] Extra capacity can be described in Notes.

## Weight

- [ ] Offer KG/Unit persists separately from PR weight.
- [ ] Exact auto calculation reuses existing calculator.
- [ ] Manual override source = estimated.
- [ ] Range weight source = estimated.
- [ ] Supplier Offer never mutates PR weight.

## Amount

- [ ] Requested Amount = requested total kg × price.
- [ ] Offer Amount = offer total kg × price.
- [ ] New `quotation_items.amount` stores Offer Amount.
- [ ] Unavailable amount = 0.
- [ ] Grand total uses Offer Amount.
- [ ] Historical amount not mass recalculated.

## Availability

- [ ] Explicit `is_available` state exists.
- [ ] Not Available clears numeric offer/commercial fields server-side.
- [ ] Notes remain available.
- [ ] All-unavailable final quotation can submit.
- [ ] No new quotation workflow status.

## Import

- [ ] Existing import mode semantics unchanged.
- [ ] Old exact-length import still works.
- [ ] Range import works.
- [ ] Qty-over-requested import fails preview.
- [ ] Not Available import works.
- [ ] Supplier isolation remains.

## Precision

- [ ] DB/model precision remains business precision.
- [ ] Calculations are not rounded to 2 merely for UI.
- [ ] Shared formatter, if added, is presentation-only.

## Quality

- [ ] Focused tests pass.
- [ ] Full test suite passes or failures are reported accurately.
- [ ] Pint passes.
- [ ] Frontend build passes.
- [ ] Migration reviewed for production MySQL.
- [ ] No unrelated refactor.

---

# 41. Definition of Done

Backend is DONE only when:

1. current HEAD is reconciled against this plan;
2. migration is additive/backward compatible;
3. Offer Qty max is enforced server-side;
4. exact/range Length is structurally normalized;
5. offered weight and source persist correctly;
6. range/manual weight is distinguishable as estimated;
7. Offer Amount is server-authoritative;
8. Requested Amount remains available for comparison;
9. Not Available works for single/mixed/all items;
10. new business rules have feature tests;
11. import compatibility is tested;
12. historical records remain readable;
13. downstream total/PO/comparison consumers are audited;
14. no unexecuted test is claimed as passing.

---

# 42. Coding-Agent Execution Rules

1. Read `AGENTS.md`. 
2. Read both Frontend and Backend plans fully.
3. Inspect current HEAD and compare with `ad524231`.
4. Audit current migration definitions before choosing exact SQL types.
5. Do not edit old migrations.
6. Do not rename PR dimension fields.
7. Do not trust browser totals.
8. Do not allow Offer Qty above persisted Requested Qty.
9. Do not encode Not Available as fake zero price/qty.
10. Do not duplicate material weight formulas.
11. Do not calculate range weight from midpoint/min/max.
12. Preserve transaction and MTC attachment behavior.
13. Preserve supplier authorization/data isolation.
14. Preserve current quotation statuses.
15. Preserve import mode semantics.
16. Implement in phases with focused tests after each phase.
17. Before completion, audit every consumer of `quotation_items.amount`, `Quotation::total_amount`, `available_length`, and `availability_comparison`.
18. Report exact files changed, migrations, commands, test results, and deviations.
19. If current HEAD reveals a material conflict in the existing amount/PO business rule, stop only that conflicting subtask and report the concrete repository fact instead of guessing.
