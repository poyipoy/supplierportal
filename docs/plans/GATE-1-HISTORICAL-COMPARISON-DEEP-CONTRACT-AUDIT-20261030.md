# GATE 1 — HISTORICAL COMPARISON DEEP CONTRACT AUDIT

**Revision 3: FINAL CONTRACT CLOSURE (2026-10-30), with Errata E1-E3.** This revision supersedes revisions 1 and 2. It is planning only; no code, migration or data change has been made.

> **Errata (final consistency pass, marked ▲E in the body):**
> - **E1:** C5 Current vs Previous. Current is plotted for statuses 4-9, so Insufficient history is shown as Current only. Previous is plotted only for 8-9. Statuses 1-3 plot nothing. This aligns C5 with G26 (S-C IDR 60,000 plotted). AC6 is updated.
> - **E2:** D shared fixture. Chronology corrected: PR-G is created 10-01, current quotations fall on 10-12 to 10-18, and the evaluation date is frozen at 10-20. All G1-G28 dates are rechecked and no calculation changes. `stale=true` is now asserted for G5, G6 and G26 under the D-3 default. AC12 is added.
> - **E3:** payload. `previous.detail_url` is explicit, and the drawer pins the selected Previous even when it falls outside the 20 newest records (at most 21 records, one query). G28 and AC11 are added; AC1 now covers G1-G28.
> - **No new implementation blocker.** The Gate 2 verdict is unchanged: CONDITIONAL GO pending golden sign-off (now G1-G28) and the protected-work precondition.

**Intended repository path:** `docs/plans/GATE-1-HISTORICAL-COMPARISON-DEEP-CONTRACT-AUDIT-20261030.md`. It is **not written yet**: plan mode allows only this plan file. Save it there after approval.

Readers: the product owner, the Purchasing supervisor (golden sign-off), Finance (release FX checks) and the Gate 2 implementer.

Notes:
- antislop: during mode. The title and status line keep the owner's em dash wording; the rest of the document avoids em dashes.
- `/multi-backend` was blocked because its Codex runtime is not installed. That review was done inline.

**Evidence tags:**

| Tag | Meaning | Limits |
|---|---|---|
| **[REPO]** | Read in code, migrations or tests | Shows what the code does, not what the data contains |
| **[DEV-DB]** | Read-only observation of local `adasi_portal` (MySQL 8.0.30, seeded), including one `EXPLAIN ANALYZE` | Not representative of volume or content |
| **[PROD]** | Production | **Not available. No production validation has been done.** |
| E1 / E3 / E4 / E5 | Offer letters / GR and invoice price lines / purchaser spreadsheets / published FX rates | Still unmet |

---

## A. Final decision ledger

| ID | Decision | State | Effect on the contract |
|---|---|---|---|
| Q-1 | Comparability requires **every** shape-relevant dimension | CLOSED | C2 |
| Q-4 | PR context = one grouped table; Historical Purchase Price is a separate chart mode on the existing PO benchmark; legacy Historical without `pr_id` | CLOSED, LOCKED | C7 |
| Q-6 | Payment terms are informational only | CLOSED | C3 |
| D-1 | **APPROVED (Option A).** Spec-equal + basis unverified (missing Incoterm/Port) → "Comparable, basis unverified", with diff and % shown and labeled **Indicative** and the unverified fields named. A proven difference stays "Basis differs" with no % | CLOSED | C3, C4, G20, G25 |
| D-2 | **APPROVED (Option A).** Rejected quotations are valid history and can be the primary Previous. They carry a "Rejected" label and their original status and source. Award eligibility is unchanged | CLOSED | C4, G11 |
| D-3 | Stale snapshot-rate threshold (default 31 days, info flag only) | OPEN, Finance, **release** | Display flag; code is written with a config default |
| D-4 | Official FX oracle (BI Kurs Transaksi derived mid vs weekly KMK) | OPEN, Finance, **release** | Oracle for AC8 only; no code depends on it |

---

## B. Final contract (authoritative)

### C1. Row identity

**Definitions:**
- **Participating quotation:** status ∈ {submitted, accepted, rejected} and not soft-deleted. This is the same set as the Inter-Supplier matrix, `PriceComparisonController.php:150-153` [REPO].
- **Participating supplier:** a supplier with ≥ 1 participating quotation on the PR.

**One row per (PR Item, participating supplier).**
- Unfiltered, the table has exactly |PR items| × |participating suppliers| rows.
- With the optional `supplier_id` filter, it has |PR items| rows.
- There is **no pagination in PR mode**: the row count is bounded by one PR, not by history size.

**Resolving the current source:**

| Count | Result |
|---|---|
| Participating quotations for (PR, supplier) > 1 | **Ambiguous** on every item row of that supplier |
| Exactly 1, and lines for (quotation, PR item) > 1 | **Ambiguous** on that item only |
| Exactly 1, and lines = 0, or the line is unavailable, or the price is NULL / ≤ 0 | **Not offered** |
| Exactly 1 and lines = 1 | that line is the current source |

**Ambiguous rows:**
- Every source is kept in `sources[]`: quotation hash, status, timestamp, line ordinal, native price and currency.
- Each source gets a read-only link to the quotation detail.
- There is no current price, previous price, diff or %, and no source is chosen.
- Why this can happen: no unique index exists on `quotations(pr_id, supplier_id)` or `quotation_items(quotation_id, pr_item_id)`; only app-level locking at `Supplier/QuotationController.php:599-600` prevents duplicates [REPO]. None exist today [DEV-DB].
- The schema and the Inter-Supplier matrix are unchanged.

### C2. Specification comparability

- **Relevant fields** are taken from `PrItem::RELEVANT_DIMENSIONS[canonicalShape(shape)]` [REPO `PrItem.php:83-87,147-162`]:
  - Flat: thickness, width, length
  - Round: d_outer, length
  - Hollow: d_inner, d_outer, length
- **Effective value per field and side**, mirroring `QuotationItem::orderedSpecification()` [REPO `QuotationItem.php:289-307`]:
  - **Length:** `available_length_min` and `available_length_max` both set give a **range**; otherwise `available_length` as a scalar.
  - **Other fields:** `available_f`.
  - **Offer silent:** the PR value, labeled **"as requested"**.
  - **Neither present:** **unresolved**.
- **Spec-equal** means every relevant field is equal:
  - scalar vs scalar within `AVAILABILITY_TOLERANCE` 0.0001 [REPO `QuotationItem.php:15`];
  - range vs range when both bounds are equal;
  - **scalar ≠ range**;
  - an unresolved field is never equal.
- **A non-spec-equal candidate** with the same material identity and shape class is shown as a **reference** (price, spec diff, date, link) with **no diff or %**.
- **Material identity:**
  - master ID when both sides have one, otherwise the exact name;
  - shape class `LOWER(TRIM(shape))`, NULL-safe;
  - current PR Items are never merged by name.

### C3. Commercial basis

- **Incoterm and Port of Discharge** are read from each side's **PR**:
  - Columns `purchase_requisitions.incoterm` (CFR/CIF/FOB) and `port_of_discharge`, both nullable with CHECK constraints (`2026_10_14_000002` [REPO]).
  - Required at submit by `LogisticsTerms::assertSubmittable()` [REPO]. Legacy PRs are NULL: 8 of 9 [DEV-DB].
  - **ASSUMPTION:** the supplier priced on the PR's terms, since quotations have no Incoterm field [REPO]. Verified by AC8.

| Field state (Incoterm, Port) | Result |
|---|---|
| Both set and equal | **same** |
| Both set and different | **different** → Basis differs |
| Either NULL | **unknown**; NULL = NULL is never equal → Indicative |

- **Informational notes only:** payment terms (free text), weight basis (`offered_weight_source`), validity, estimated delivery.
- A different **currency** is handled by C5, not by basis.

### C4. Status precedence (corrected: Ambiguous first)

| # | Status | Condition | Previous shown | Diff / % |
|---|---|---|---|---|
| 1 | **Ambiguous current quotation** | C1 | no | no |
| 2 | **Not offered** | single source; no line, unavailable, or price NULL/≤0 | no | no |
| 3 | **Specification incomplete** | current shape non-canonical, or a current relevant field unresolved | no | no |
| 4 | **Insufficient history** | no earlier candidate with the same material and shape class | no | no |
| 5 | **Different specification** | candidates exist, none spec-equal | as **reference** | no |
| 6 | **Basis differs** | Previous (C4.1) has a proven Incoterm/Port difference | as **reference** | no |
| 7 | **FX unavailable** | a needed foreign conversion (C5) is missing | as **reference** | no |
| 8 | **Comparable, basis unverified** | spec-equal, no proven difference, at least one of Incoterm/Port unknown | yes | **yes, labeled "Indicative"**, with unverified fields named |
| 9 | **Comparable** | spec-equal, every basis field the same | yes | yes |

**C4.1 Previous:**
- **Candidates:**
  - same supplier;
  - status ∈ {submitted, accepted, **rejected**} (D-2);
  - quotation and PR not deleted;
  - a PR other than the current one;
  - line available;
  - price > 0;
  - `COALESCE(submitted_at, created_at)` strictly earlier than the current one.
- **Previous** is the most recent **spec-equal** candidate (tie-break: ts DESC, quotation id DESC, line id DESC). It is chosen **before** the basis check, so an older candidate with matching terms is never silently preferred.
- **Rejected** previous quotations carry a "Rejected" chip. `AWARD_ELIGIBLE_STATUSES` and Award Selection are untouched [REPO].

**Gate on any %:** the % appears only when all of these hold:
- statuses 1-7 do not apply;
- Previous exists and has price > 0;
- the C5 comparison is valid;
- no proven basis difference exists.

**Pairwise validation of the order:**

| Pair | Winner and reason |
|---|---|
| 1 vs 2 | Ambiguous wins, so duplicates are never hidden behind "Not offered" (correction) |
| 2 vs 3 | Not offered wins: there is no line to inspect |
| 3 vs 4 | 1-3 are current-side facts, evaluated before history. Missing history is always 4 and never a basis status |
| 4 vs 5 | Mutually exclusive by definition |
| 5 vs 6 | Basis is evaluated only on a spec-equal Previous |
| 6 vs 7 | A proven basis difference blocks the % whatever the FX state |
| 7 vs 8 | Any % requires valid FX |
| 8 vs 9 | Separated by basis completeness only |

### C5. Currency and charts (corrected: IDR is identity)

**Conversion of one value into IDR:**

| Value currency | Rule |
|---|---|
| **IDR** | **Identity.** The native value is used and no snapshot is needed, even when `exchange_rate_id` is NULL |
| **Foreign** | `price × snapshot.rate_to_idr` from **that quotation's own** `exchange_rate_id` |

- A missing snapshot, or `rate_to_idr ≤ 0`, means **conversion unavailable**.
- No synthetic rate is ever created, and a missing foreign rate is never replaced by 1 or 0.
- JPY is stored per 1 yen [REPO]. Any Bank Indonesia (BI) per-100 figure used as an oracle is divided by 100.

**Table comparison:**
- **Same currency on both sides** (any currency): compare native. No snapshot is needed.
- **Different currencies:** convert both sides to IDR with the identity/snapshot rule, and label the % "incl. FX effect".
- If either required foreign conversion is unavailable → **FX unavailable**: Previous shown as reference, no %.

**Charts: exactly one declared basis each.**
1. **Choose the basis.** If every plotted candidate value shares one currency, the basis is that native currency. Otherwise the basis is IDR.
2. **Normalize to the basis.**
   - Native-basis values stay as they are.
   - Under an IDR basis, IDR values use identity and foreign values use their own snapshot.
   - A foreign value without a snapshot is **omitted**, never plotted as 0, and listed under the chart as "FX unavailable for chart basis".
3. **Label the basis.** The axis title and caption state it ("USD/kg", or "IDR/kg: IDR native, other currencies at each quotation's exchange-rate snapshot"). The tooltip shows the original value, currency, method (identity/snapshot), rate and `valid_from`.
4. **Invariant I-K:** after normalization every plotted point has currency = basis. A JS test asserts this over every chart config.

**Chart modes (one material at a time):**
- **Current vs Previous** (default):
  - ▲E1 **Current** is plotted for every row with a valid single current offer: statuses **4-9**. Statuses 4-7 are drawn as **Current only**, with no Previous bar.
  - **Previous** is plotted only for statuses 8-9. A Previous value is never invented.
  - Statuses **1-3** (Ambiguous, Not offered, Specification incomplete) plot nothing and are listed under the chart with their status.
  - Indicative points use a distinct marker plus "Indicative" in the legend and tooltip (not color alone).
  - Every supplier without a Previous bar is listed with the reason.
  - The C5 normalization rules still apply. A Current value that needs an unavailable foreign conversion is omitted and listed "FX unavailable for chart basis".
  - **A row's table status is not changed by being omitted from the chart.** For example, same-currency USD rows without snapshots stay Comparable in the table.
- **Quotation Price Trend:**
  - Per supplier: the current point plus earlier lines that are spec-equal to that supplier's current spec and have no proven basis difference.
  - x = business date of `COALESCE(submitted_at, created_at)` on a linear epoch-ms axis.
  - No averaging and no interpolation.
  - Excluded counts are given per reason.
- **Historical Purchase Price:** the existing PO benchmark, unchanged, with its own labeled evaluation-date basis. It never shares an axis with quotation points.

### C6. Navigation and PR context (corrected)

**Principle:** the active PR is whatever the **current page URL** says. Session-remembered URLs never activate a PR in Historical or Vs Best.
- Verified: only the comparison tabs open Historical. The sidebar, quick access and PR detail all open Inter-Supplier [REPO `sidebar.blade.php:242`, `config/quick_access.php:86`, `pr/show.blade.php:299`].

**Tab href rules.** The server applies them in `x-purchasing.comparison-tabs`; the client mirrors them in `syncComparisonState()`, which runs on initial load and after every `adasi:server-tabs:updated` swap, using the response URL.

| Tab | `pr_id` | `pr_item`, `chart` | `currency`, `material_name`, `shape` |
|---|---|---|---|
| Inter-Supplier | from the current URL if present; otherwise the remembered URL (existing behavior) | removed | existing rule (present sets, absent removes) |
| Historical | from the current URL if present; **otherwise removed** | removed | existing rule |
| Vs Best | from the current URL if present; **otherwise removed** (ignored by its queries) | removed | existing rule |

- **Vs Best links:** its filter syncs and its reset button (`_vs_best_content.blade.php:45`) keep `pr_id` and `currency`.
- **Historical in PR mode:**
  - It resolves every PR Item and participating supplier with no filter interaction and renders the default Current vs Previous chart.
  - The chart item is `pr_item` if valid; else the first PR Item matching the carried `material_name` + `shape`; else the first PR Item, with a polite note when the carried material is not in the PR.
- **Clear PR** is a plain link, a full navigation, to `historical?currency=X&clear_pr=1`. The controller:
  1. calls `PurchasingNavigation::forgetComparisonPrContext()`. For the three comparison routes and for `purchasing.last_list_url` (only when it is a comparison URL), this strips `pr_id`, `pr_item`, `chart`, `material_name`, `shape`, `history_page` and `clear_pr`. No other Purchasing navigation state is touched;
  2. redirects 302 to `historical?currency=X`, so refresh and Back never repeat the action.
  
  The precedent for a session-only GET is `locale.switch` [REPO `web.php:321`]. The side effect is limited to the user's own remembered filters and is idempotent.
- **History:**
  - Tab switches and PR-mode filter submits push history.
  - Chart and material switches use `replaceState`, keeping `_serverTabs`.
  - `popstate` refetches the entry's URL, the server renders exactly that state, and hrefs are re-synced. The middleware re-remembers that URL [REPO `RememberPurchasingListUrl`].
- **Return from detail:**
  - View Detail links carry `return_url` = the current Historical URL (`PurchasingNavigation::toRoute`; fragment requests use `last_list_url`, which the middleware has already set to this URL [REPO]).
  - After a `replaceState` chart or material switch, `syncComparisonState()` also rewrites `return_url` on `[data-return-url]` links, so returning restores chart focus.
  - `backUrl()` accepts the URL only through `isSafeUrl()` [REPO].
- **Direct URL and refresh:**
  - A valid `pr_id` gives PR mode.
  - A raw, invalid or soft-deleted PR gives 404 (`HasHashids::resolveRouteBinding` applies SoftDeletes [REPO]).
  - A PR without participating quotations shows an empty state that names the cause.
  - No `pr_id` gives legacy Historical, byte-identical.
- **No change** to `RememberPurchasingListUrl` or `listUrl()`. The single added helper is `forgetComparisonPrContext()`.

### C7. Locked layout

- With `pr_id`: one grouped multi-material, multi-supplier quotation table; no PO line table.
- Historical Purchase Price chart mode: existing `PurchaseHistoryPriceService` output, unchanged.
- Without `pr_id`: legacy, byte-identical.

### C8. Cardinality and cost

**Observed [DEV-DB]** (MySQL 8.0.30, PR 2114, `EXPLAIN ANALYZE`):
- candidates came through `quot_supplier_status_index`;
- the CASE/`LOWER(TRIM())` material and shape predicate ran **after** expansion;
- pre-window rows ≈ Σ over suppliers of (L_s × PR items);
- the sketch without a spec rule picked a t50 line as "previous" for a t30 item.

**Contract:**
1. Pre-filter candidates on the PR's material set with sargable predicates (`material_master_id IN … OR material_name IN …`).
2. `is_match` (C2) is used only in the window ORDER BY. History is not truncated.
3. Output rows equal the C1 count, asserted in a test.
4. **Synthetic volume run** in a separate local database (never the app database, never production):
   - **Data:** 30 suppliers, 20k quotations, 150k lines, 120 materials, one heavy supplier with 3k earlier quotations, and a target PR of 20 items × 8 suppliers.
   - **Plans:** MySQL 8 `EXPLAIN ANALYZE` and MariaDB 11.4 `ANALYZE FORMAT=JSON`.
   - **Targets:**
     - pre-window rows ≤ 2 × the matching candidate lines;
     - no full scan of `quotation_items`/`pr_items`;
     - history query p95 over 20 runs ≤ 250 ms;
     - PR-mode page ≤ 1 s server time.
   - The existing `ProfileCriticalPaths` command already profiles Historical [REPO `app/Console/Commands/ProfileCriticalPaths.php:145`]; extend it rather than adding a new tool.
   - Add an index only if a target fails, and only with its own migration review.
5. The constant query count stays as a separate test.

### Payload (`view=json` → `quotationHistory`)

```
rows[]: { pr_item_hash, supplier:{hash,name}, status, indicative:bool, unverified_fields:[{side,field}],
  sources:[{quotation_hash, status, submitted_at, line_ordinal, price_per_kg, currency}],   // ≥2 only when Ambiguous
  current:{quotation_hash,status,price_per_kg,currency,submitted_at,spec} | null,
  previous:{quotation_hash,pr_number,status,price_per_kg,currency,submitted_at,spec} | null,
  reference_only:bool, spec_diff:[...],
  basis:{incoterm:{current,previous,state}, port:{...}},
  comparison:{currency, method: native|idr_conversion, fx:[{side,currency,method: identity|snapshot,rate_to_idr,valid_from,stale}]},
  notes:{payment_terms, weight_basis}, diff, diff_pct, history_count, comparable_count, detail_urls[] }
charts.{current_previous|quote_trend}: { basis, axis_label, series[{supplier, points[{x?, y, native, currency, method, indicative}]}],
  excluded:[{supplier, reason}] }
```

▲E3 **Direct access to the selected Previous:**
- `current` and `previous` each carry their own `detail_url` (quotation hash + `return_url`). The row therefore always links straight to the selected Previous, wherever it ranks in history.
- `itemHistory()` returns the 20 newest earlier records **plus the selected Previous pinned first** when it is not among them. That is at most 21 records, still from one query (`ROW_NUMBER() <= 20 OR line = selected`).
- The drawer labels the pinned record "Selected previous" and shows "Showing 20 newest of N, plus the selected previous". The drawer does not grow beyond that.

Payload rules:
- `diff` and `diff_pct` are non-null only for statuses 8-9.
- `null` is never coerced to 0.
- Hashes only.
- Decimal strings come from BCMath.

---

## C. Corrections made in this closure

| # | Defect | Correction |
|---|---|---|
| X1 | G26 excluded an IDR quotation whose snapshot was NULL | IDR is identity (C5). G26 is corrected |
| X2 | Not offered was evaluated before Ambiguous, hiding duplicates | Ambiguous is now first (C4); G21 and G27 are verified |
| X3 | The rev 2 `pr_id` "set-only" rule could silently reactivate a remembered PR in Historical or Vs Best | The current URL is the only activator. Clear PR with PRG (C6) |
| X4 | **Found in closure:** G26 mixed materials (SKD11, S45C) on one chart, against the one-material rule | G26 is rebuilt on one PR Item |
| X5 | **Found in closure:** the Vs Best reset link dropped `pr_id`, breaking the Inter → Vs Best → Historical chain | Reset keeps `pr_id` and `currency` |
| X6 | **Found in closure:** View Detail `return_url` went stale after a `replaceState` chart switch | `syncComparisonState()` rewrites `return_url` |
| X7 | **Found in closure:** a same-currency foreign row without snapshots was ambiguous between the table and the chart | Table native = valid; chart omission is listed and never changes the table status |
| X8 | **Found in closure:** the row-count invariant was undefined under `supplier_id` and pagination | Defined (C1); no pagination in PR mode |
| X9 | D-1 and D-2 were open | Closed (A) |

---

## D. Golden dataset (independently hand-calculated)

**Shared fixture:**
- **Rates:** R-USD 17,700 (valid 2026-05-21), R-JPY 108 (2026-05-01), R-CNY 2,250 (2026-05-28).
- **Current PR:** PR-G (created and submitted 2026-10-01, status bidding), Incoterm CIF, Port TANJUNG_PRIOK.
- **Prior PRs:** CIF / TANJUNG_PRIOK unless stated.
- **Timestamps:** business-time dates, stored through `BusinessTime::toStorage`.
- ▲E2 **Chronology** (each PR is created before its quotations; every rate is valid before the quotations that use it):

  | Record | Date |
  |---|---|
  | Rates valid from | USD 05-21, JPY 05-01, CNY 05-28 |
  | Prior PRs created | before their quotations (05-25, 06-01, 06-25, 08-01, 09-01) |
  | Prior quotations | 06-03, 06-10, 07-01, 08-12, 09-05, 09-10 |
  | Current PR-G created | 10-01 |
  | Current quotations | 10-12 to 10-18 (G16 resubmit 10-18) |
  | G22 rejection (`reviewed_at`) | 10-19 |
  | **Evaluation date** (frozen `BusinessTime` in tests) | **2026-10-20** |

  - The G12 excluded records sit at 08-01: earlier than the current quotation, so they are excluded **by status**, not by time.
  - G25 uses the legacy current PR-L, created 09-01 with its quotation at 09-05; prior 06-10.
  - No expected price, diff or % changes.
  - Under the D-3 default (31 days), every snapshot used in G5, G6 and G26 has `stale=true`. This is info only, the table status is unchanged, and the assertion is added to those cases.
- **Isolation:** one supplier per case unless stated.
- **Construction:** rows are built with `Model::create`. Expected values below were calculated by hand, not taken from application output.

### D1. Unchanged cases (rechecked against C1-C5, no contradiction)

| # | Expected |
|---|---|
| G1 | SKD11 Flat t30×300×2000, S-A USD 3.80 vs 3.60 (06-10, accepted) → Comparable, +0.20, **+5.56%** (0.20/3.60 = 5.5556) |
| G2 | + t50 3.85 (09-10) → previous stays 3.60; history 2, comparable 1 |
| G3 | Same name, t50×400×1500: 3.90 vs 3.85 → **+1.30%** (1.2987); never merged with G1 |
| G4 | t30×200×1000 vs t30×300×2000 → Different specification, reference 3.60, no % |
| G5 | S45C Round d60×6000: JPY 210 vs CNY 13.20 → 22,680 vs 29,700 IDR, −7,020, **−23.64%** "incl. FX effect", Comparable |
| G6 | G5 with prior CNY snapshot NULL → FX unavailable, reference shown, no % |
| G7 | Different specification |
| G8 | Insufficient history |
| G10 | Insufficient history |
| G12 | Later draft / revision_requested / deleted / on a deleted PR → ignored |
| G13 | Weight-basis note only, +5.56% |
| G14 | Trend: 3 points, none averaged, basis USD/kg |
| G15 | PO benchmark identical to the pre-change snapshot |
| G16 | Resubmit: ts = 10-18 |
| G17 | Scalar vs range → Different specification |
| G18 | "As requested" spec-equal, +5.56% |
| G19 | Prior FOB → Basis differs, reference, no % |
| G22 | Current rejected → +5.56% with a Rejected chip |
| G23 | Specification incomplete |
| G24 | Complete spec, no candidates, current Incoterm NULL → **Insufficient history** (not a basis status) |

**G9 (Not offered, rechecked under the new precedence):**
- G9a: S-C, one quotation, line `is_available=false` → **Not offered**.
- G9b: S-C, one quotation, no line for the item → **Not offered**.
- Neither is Ambiguous, because there is a single source.

### D2. Modified cases (full detail)

**G11: rejected previous (D-2)**
- **Raw records:**
  - S-A current Q-A1 (PR-G, submitted 2026-10-15, USD, R-USD): SKD11 Flat t30×300×2000, 3.80, auto.
  - Q-A0 (PR-P1, **accepted**, 2026-06-10, USD): same spec, 3.60.
  - Q-A9 (PR-P2, **rejected**, 2026-07-01, USD): same spec, 3.50.
- **Candidates:** Q-A0 and Q-A9, both spec-equal.
- **Selected Previous:** Q-A9 (most recent).
- **Status:** Comparable (CIF/PRIOK both sides).
- **Values:** native USD on both sides. diff +0.30, **+8.57%** (0.30/3.50 = 8.5714).
- **Labels:** previous chip "Rejected"; history 2, comparable 2.
- **Chart (isolated):** basis USD/kg. Previous 3.50, Current 3.80.
- **Assertions:**
  - `previous.status == 'rejected'`;
  - `Quotation::AWARD_ELIGIBLE_STATUSES` is unchanged;
  - `saveItemAwards` still rejects Q-A9.

**G20: Indicative, legacy previous terms (D-1)**
- **Raw records:** G1, but PR-P1 has incoterm NULL and port NULL. PR-G is CIF/PRIOK.
- **Candidates:** Q-A0. Selected: Q-A0.
- **Status:** **Comparable, basis unverified**.
- **Values:** USD native. +0.20, **+5.56%** with **"Indicative"**.
- **Fields:** `indicative=true`; `unverified_fields=[{previous,incoterm},{previous,port_of_discharge}]`.
- **Message:** "Indicative: Incoterm and Port of Discharge not recorded on the previous PR."
- **Chart:** Previous 3.60 plotted with the Indicative marker; Current 3.80.

**G21: duplicate participating quotations**
- **Raw records:** S-E has Q-E1 (submitted 2026-10-12, USD 4.00 for item SKD11 t30, no line for item S45C) and Q-E2 (accepted 2026-10-14, USD 4.10 for SKD11 t30 and 2.20 for S45C), both on PR-G. Prior history for S-E: USD 3.90 (06-10).
- **Output:** one row for (SKD11, S-E) and one for (S45C, S-E). Both are **Ambiguous** (supplier-level). The S45C row is **not** "Not offered".
- **Sources:** both rows list Q-E1 and Q-E2, each with its detail link. current, previous, diff and % are null; history counts are not computed.
- **Message:** "2 quotations from this supplier on this PR. Comparison withheld; review both sources."
- **Chart:** S-E is excluded and listed as "Ambiguous current quotation".
- **Matrix:** the Inter-Supplier matrix is unchanged.

**G25: Indicative, legacy on both sides (D-1)**
- **Raw records:**
  - Current PR-L, created 2026-09-01 before the submit guard, incoterm and port NULL.
  - S-A Q (2026-09-05, USD 3.80).
  - Prior PR-P1 with NULL terms, Q (2026-06-10, USD 3.60), same spec.
- **Status:** Comparable, basis unverified.
- **Values:** +0.20, **+5.56% Indicative**.
- **Fields:** `unverified_fields` lists incoterm and port on **both** sides.

**G26: mixed currencies on one chart item, IDR native (corrected)**
- **Chart item:** PR-G SKD11 Flat t30×300×2000. All priors are on CIF/PRIOK PRs with the same spec.

| Supplier | Current | Previous | Table result | Chart (IDR basis) |
|---|---|---|---|---|
| S-A | USD 3.80, R-USD | USD 3.60, R-USD | native +0.20, +5.56%, Comparable | 3.80×17,700 = **67,260**; 3.60×17,700 = **63,720** |
| S-B | JPY 210, R-JPY | CNY 13.20, R-CNY | IDR 22,680 vs 29,700, −23.64%, Comparable | **22,680**; **29,700** |
| S-C | **IDR 60,000, `exchange_rate_id` NULL** | none | Insufficient history | **60,000** (identity, method=identity); Previous: no bar, listed "Insufficient history" |
| S-D | USD 3.90, snapshot NULL | USD 3.70, snapshot NULL | **native +0.20, +5.41%** (0.20/3.70 = 5.4054), Comparable | **omitted**, listed "FX unavailable for chart basis" |

- **Basis:** IDR, because the plotted currencies are mixed.
- **Axis:** "IDR/kg: IDR native, other currencies at each quotation's exchange-rate snapshot".
- **Assertions:**
  - no plotted y is 0;
  - every point's currency is IDR after normalization;
  - S-C is present with value 60,000.

**G27: duplicate quotation lines**
- **Raw records:** S-F Q-F1 (PR-G, single quotation) has **two** lines for SKD11 t30 (USD 3.70 and 3.75) and one line for S45C (USD 2.10).
- **Output:** row (SKD11, S-F) is **Ambiguous**. `sources` lists Q-F1 line #1 3.70 and line #2 3.75; both links go to the Q-F1 detail. No current, previous or %.
- **Row (S45C, S-F)** is evaluated normally (item-level ambiguity only).

**G28 ▲E3: selected Previous outside the 20 newest**
- **Raw records:**
  - S-G current (PR-G, 10-15): SKD11 Flat t30×300×2000, USD 3.80.
  - One spec-equal prior: USD 3.60 on 2026-06-10.
  - **22** later priors from 06-11 to 10-10, same material and shape but **t50** (not spec-equal), each USD 3.85.
- **Expected row:** Comparable, prev 3.60, +0.20, **+5.56%**; history 23, comparable 1; `previous.detail_url` present.
- **Expected drawer:** 21 records, the pinned 06-10 record first ("Selected previous"), then the 20 newest t50 records. The note reads "20 of 23 + selected previous".
- **Query check:** the drawer still uses one query.

---

## E. Gate 2 implementation boundary

**Create:**
- `app/Services/Pricing/QuotationHistoryService.php`: read-only, BCMath. `forPr()` is one set-based query (C8) plus the PHP C1/C4 resolution; `itemHistory()` serves the drawer and trend.
- `tests/Fixtures/historical-quotation-golden.json`.
- Tests: `QuotationHistoryServiceTest`, `HistoricalPrIdentityTest`, `PriceComparisonTabContextTest`, `PurchaseHistoryPriceCharacterizationTest`, `PriceComparisonHistoricalAccessTest`, `tests/js/historical-quotation-chart.test.mjs`.

**Modify (minimum):**
- `PriceComparisonController::historical()`: PR-mode branch and `clear_pr` PRG.
- `app/Support/PurchasingNavigation.php`: `forgetComparisonPrContext()` only.
- `resources/views/components/purchasing/comparison-tabs.blade.php`: C6 href rules.
- `_historical_content.blade.php`: PR mode, table, drawer, chart switch, Clear PR.
- `_vs_best_content.blade.php`: reset keeps `pr_id`/`currency`.
- `_scripts.blade.php`: sync on load and after swap, `pr_id` rules, `return_url` rewrite, chart builders, cleanup.
- `app/Support/StatusHelper.php`.
- `lang/{en,id}/purchasing.php`, `lang/{en,id}/status.php`.
- `tests/js/comparison-state.test.mjs`.

**Not touched:**
- `PurchaseHistoryPriceService`, `interSupplier()`, `saveItemAwards`, `PrItemAwardService`, `PurchaseOrderGenerationService`, `RememberPurchasingListUrl`, migrations and schema.
- `AppServiceProvider` (the views already get `$regionalFormatter`).
- `_inter_supplier_content.blade.php` (the optional per-item shortcut is **dropped** to stay out of a protected file).

**Protected uncommitted work** (14 files, including `_scripts.blade.php`, `_inter_supplier_content`, `_price_indicators`, `AppServiceProvider`, `AGENTS.md`):
- No stash, checkout, discard, overwrite or commit of those changes.
- **Gate 2 precondition:** the owner lands that work first, **or** explicitly authorizes edits on top. Every hunk in a shared file is reviewed so that only Historical hunks are added.

---

## F. GO / NO-GO

| Gate | Verdict | Basis |
|---|---|---|
| **Business contract closure** | **GO** | D-1 and D-2 approved; X1-X9 resolved; precedence, FX, chart and payload cross-checked (C4 pairwise table, D1) |
| **Gate 2 implementation** | **CONDITIONAL GO** | Starts once **(1)** the Purchasing supervisor signs off the golden JSON values (outstanding, **not done**) and **(2)** the 14-file work has landed or edits on top are authorized (C/E). D-3 and D-4 do **not** block code: D-3 ships as a config default and D-4 is oracle-only |
| **Production release** | **NO-GO** | Pending all of the outstanding items listed below |

**Release prerequisites (all outstanding):**
- AC2-AC7 and AC10 executed and green (**no test has run** in Gate 1);
- AC8: E1 sample, including the Incoterm-matches-PR check [PROD];
- AC9: blind re-performance [PROD];
- D-3 and D-4 decided by Finance;
- the synthetic C8.4 run on both engines;
- a read-only production data profile on a replica: NULL-terms share, duplicates, currency mix, quotes per supplier.

**Acceptance criteria (each verified by someone other than the author):**

| # | Criterion |
|---|---|
| AC1 | Supervisor sign-off of G1-G28 (▲E: G28 added; fixture dates per E2) |
| AC2 | The golden test reproduces every value exactly |
| AC3 | Row count = items × participating suppliers, keys unique, Ambiguous lists ≥ 2 sources (G21, G27), and Not offered stays correct for single sources (G9) |
| AC4 | Characterization of `summarize`/`lines`/Vs Best/legacy JSON unchanged against the pre-change commit |
| AC5 | Navigation N1-N12 |
| AC6 | No chart mixes currencies; IDR native is kept (G26); no zero substituted; ▲E1 every status 4-9 row has a Current point (subject to C5 normalization), statuses 1-3 have none, and no Previous point exists outside statuses 8-9 |
| AC11 ▲E3 | The selected Previous is directly linked from its row and pinned in the drawer even when it is older than the 20 newest records (G28) |
| AC12 ▲E2 | Fixture chronology: every PR is created before its quotations, every snapshot is valid before its quotation, and every timestamp is ≤ the frozen evaluation date 2026-10-20 (asserted in the fixture builder) |
| AC7 | C8.4 targets met, plans saved under `docs/results/` |
| AC8 | E1 field-by-field sample [PROD] |
| AC9 | Blind re-performance [PROD] |
| AC10 | Non-purchasing roles (incl. admin) → 403; raw/foreign `pr_id`/`pr_item` → 404; `<script>` in notes/names escaped |

**Navigation cases (AC5):**

| # | Case | Expected | Verified by |
|---|---|---|---|
| N1 | Inter(PR A, no filter touched) → click Historical | `pr_id=A` in href server-side; PR mode; default chart | feature + browser |
| N2 | Historical PR A → Clear PR | 302 to legacy Historical; remembered comparison URLs have no `pr_id` | feature |
| N3 | Clear PR → Inter-Supplier → Historical | Inter shows no PR; Historical is legacy | feature + browser |
| N4 | Inter PR A → pick PR B → Historical | `pr_id=B` | feature + JS |
| N5 | Back / Forward across N1, N4 and a chart switch | each entry restores its own URL state | browser |
| N6 | Refresh, and a direct `historical?pr_id=` URL; raw, invalid or deleted PR | PR mode / 404 | feature |
| N7 | View Detail → back after a chart switch | same PR, item and chart restored | browser + JS (`return_url` rewrite) |
| N8 | Session holds `historical?pr_id=A`; user opens Vs Best plainly → Historical | legacy, no PR A activation | feature |
| N9 | Inter A → Vs Best → reset → Historical | `pr_id=A` kept | feature |
| N10 | Clear PR keeps `currency` and leaves non-comparison Purchasing nav untouched | session assertions | feature |
| N11 | Explicit material focus carried | chart item matches material + shape; otherwise first item + note | feature |
| N12 | A legacy Historical render does not alter Inter-Supplier's remembered PR (unless Clear PR) | session assertion | feature |

---

## G. Final Gate 2 handoff (dependency-ordered)

0. **Preconditions:** AC1 signed; protected work landed or edits on top authorized; no other `artisan test` process running; `TestingEnvironmentDatabaseSafetyTest` green.
1. **Characterization (must pass on unchanged code):** `summarize`/`lines` snapshots, Vs Best JSON, legacy Historical JSON key set, award-form input set.
2. **Golden fixture + `QuotationHistoryServiceTest` (red)** → implement `QuotationHistoryService` → green. Then `HistoricalPrIdentityTest`.
3. **Controller PR-mode branch and payload.** `PriceComparisonHistoricalAccessTest` and the hashid 404 cases.
4. **Navigation:** tab component rules, `forgetComparisonPrContext()` + `clear_pr` PRG, Vs Best reset, `_scripts` sync and `return_url` rewrite. `PriceComparisonTabContextTest` and the JS state tests.
5. **UI:** `_historical_content` (table, drawer, chart modes with declared basis), `StatusHelper`, lang en/id. Chart JS test, `TranslationParityTest`, `UserFacingCopyInventoryTest`.
6. **Regression gate:**
   - `php artisan test --filter="QuotationHistory|HistoricalPrIdentity|PriceComparison|PurchaseHistoryPrice|HashidUrlSecurityTest|RegionalComparisonDisplayTest|TranslationParityTest|UserFacingCopyInventoryTest"`
   - `node --test tests/js/comparison-state.test.mjs tests/js/historical-quotation-chart.test.mjs tests/js/server-tabs.test.mjs tests/js/regional-comparison.test.mjs`
   - `php -l`, `pint --test`, `view:cache`, `npm.cmd run build`, `git diff --check`, `graphify update .`
7. **Independent validation:**
   - C8.4 synthetic run on MySQL 8 + MariaDB 11.4;
   - manual browser N1-N12, light and dark themes, keyboard drawer, antislop Delivery Gate;
   - then the release items in F (AC8, AC9, D-3, D-4, production profile).

`PLANNING COMPLETE — NO IMPLEMENTATION — PENDING GATE 2 APPROVAL`
