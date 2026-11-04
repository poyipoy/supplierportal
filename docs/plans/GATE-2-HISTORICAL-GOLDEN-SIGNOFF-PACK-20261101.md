# Historical Price Comparison: Golden Cases for Purchasing Supervisor Sign-off

**Status: AWAITING SIGN-OFF.** Passing automated tests and the product owner's approval of D-1 and D-2 do **not** replace this sign-off.

**For the reviewer:**
- For each case, check that the expected result is what Purchasing would conclude by hand.
- Mark each case Agree or Disagree and add a note.
- Every case is executed by `tests/Feature/Procurement/QuotationHistoryServiceTest.php`. The values were calculated by hand, not copied from the system.

## Common setup

- **Exchange rates (IDR per 1 unit):** USD 17,700 (from 21 May 2026), JPY 108 (from 1 May 2026), CNY 2,250 (from 28 May 2026).
- **Current PR:** PR-G, created 1 Oct 2026, Incoterm CIF, Port Tanjung Priok. Earlier PRs are CIF / Tanjung Priok unless a case says otherwise.
- **Current quotations:** 12-18 Oct 2026. The evaluation date is 20 Oct 2026.
- **"Same specification"** means every dimension matches: Flat = thickness × width × length; Round = diameter × length; Hollow = outer × inner diameter × length.
- **Difference** = current − previous. **%** = difference ÷ previous × 100.

## Cases

| # | Situation | Expected result | Status shown | Agree? |
|---|---|---|---|---|
| G1 | SKD11 Flat 30×300×2000, supplier A: now USD 3.80; previous same spec USD 3.60 (10 Jun) | +0.20, **+5.56%** | Comparable | ☐ |
| G2 | As G1, plus a later quote for 50×300×2000 at USD 3.85 (10 Sep) | Previous stays USD 3.60 (same spec beats more recent); 2 earlier, 1 comparable | Comparable | ☐ |
| G3 | Same PR also has SKD11 50×400×1500 at USD 3.90; earlier same spec USD 3.85 | +0.05, **+1.30%**; its own row, never mixed with G1 | Comparable | ☐ |
| G4 | SKD11 30×**200×1000** now USD 3.80; earlier only 30×300×2000 USD 3.60 | Earlier quote **as reference only**, width and length differences listed, **no %**; the drawer does not call it "selected previous" | Different specification | ☐ |
| G5 | S45C Round Ø60×6000, supplier B: now **JPY 210**; previous **CNY 13.20** (12 Aug) | 210×108 = IDR 22,680 vs 13.20×2,250 = IDR 29,700; −7,020, **−23.64%** "incl. FX effect"; both rates shown, flagged older than 31 days | Comparable | ☐ |
| G6 | As G5, but the earlier CNY quote has no exchange rate recorded | Earlier quote shown, **no %**; never treated as rate 1 or 0 | FX unavailable | ☐ |
| G7 | SS400 Flat 12 mm now; earlier only 16 mm (USD 1.18) | Reference only, no % | Different specification | ☐ |
| G8 | SUS304 **Round** Ø40 now; earlier only SUS304 **Flat** | No previous (shapes never mixed) | Insufficient history | ☐ |
| G9 | Supplier C marked the item "not available", or quoted nothing for it | No comparison | Not offered | ☐ |
| G10 | SKD61, supplier C, no earlier quote | No comparison | Insufficient history | ☐ |
| G11 | As G1, plus a **rejected** earlier quote USD 3.50 (1 Jul) | Previous = USD 3.50, labelled **Rejected**; +0.30, **+8.57%**; award rules unchanged | Comparable | ☐ |
| G12 | As G1, plus later drafts, revision-requested, deleted quotes, and a quote on a deleted PR | All ignored; previous stays USD 3.60 | Comparable | ☐ |
| G13 | As G1, but the earlier weight was supplier-stated | +5.56%; weight basis is a note only | Comparable | ☐ |
| G14 | Supplier A quoted 3.55 (3 Jun), 3.60 (10 Jun), now 3.80 | Trend shows **three points** on their real dates, nothing averaged | n/a | ☐ |
| G15 | PO benchmark (Historical Purchase Price) for SKD11 30 mm | Identical to the figures before this change | n/a | ☐ |
| G16 | Current quote resubmitted on 18 Oct | Uses the 18 Oct version only | as G1 | ☐ |
| G17 | Current length 2000 mm; earlier offered length **range 1800-2200** | A range does not prove 2000: reference only, no % | Different specification | ☐ |
| G18 | Earlier legacy quote left dimensions blank (its PR asked 30×300×2000) | Treated as "as requested" (labelled); +5.56% | Comparable | ☐ |
| G19 | Earlier PR was **FOB**, current is CIF | Reference only, **no %** | Basis differs | ☐ |
| G20 | Earlier PR has no Incoterm or Port recorded | +5.56% labelled **Indicative**; names the missing fields | Indicative, basis unverified | ☐ |
| G21 | Supplier E has **two** current quotations on PR-G | One row per item; both listed with links; **no price chosen, no %** | Ambiguous current quotation | ☐ |
| G22 | Supplier A's current quote was rejected | Row kept, Rejected label, +5.56% | Comparable | ☐ |
| G23 | Current item has no valid shape (blank or "Square") | No comparison | Specification incomplete | ☐ |
| G24 | Current spec complete, no earlier quote, current PR without Incoterm | Insufficient history (**not** a basis status) | Insufficient history | ☐ |
| G25 | Both current and earlier PRs have no Incoterm or Port | +5.56% **Indicative**, all four missing fields named | Indicative, basis unverified | ☐ |
| G26 | One chart: USD, JPY, CNY suppliers, **IDR 60,000 without a rate**, and supplier D in USD without rates | Chart in IDR: A 67,260 / 63,720; B 22,680 / 29,700; **C 60,000 shown**; D left off the chart (FX unavailable for chart) but its table row is Comparable +5.41% (same currency) | n/a | ☐ |
| G27 | Supplier F's one quotation has **two lines** for the same item | One row, both lines listed, no price chosen | Ambiguous current quotation | ☐ |
| G28 | The matching earlier quote is older than 22 later different-spec quotes | Comparable +5.56%; the drawer pins the matching quote first and shows the 20 newest others | Comparable | ☐ |

## Sign-off

| | |
|---|---|
| Purchasing Supervisor (name) | |
| Decision | ☐ Approve all ☐ Approve with noted exceptions ☐ Reject |
| Cases disagreed (numbers and notes) | |
| Date | |
| Signature | |

After sign-off, record the decision in `docs/results/GATE-2-HISTORICAL-COMPARISON-20261101-REPORT.md` (§1).
