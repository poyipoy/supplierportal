# Gate 2 — Historical Price Comparison: Implementation Report

Contract: `docs/plans/GATE-1-HISTORICAL-COMPARISON-DEEP-CONTRACT-AUDIT-20261030.md` (Revision 3 + Errata E1-E3).
Branch: `master`, not committed. Readers: the product owner, the release reviewer and Purchasing.
Updated 2026-11-01 with the performance and database-compatibility closure (§4-§6).

## 1. Approval gates

| Gate | State |
|---|---|
| Purchasing Supervisor sign-off of G1-G28 | **OUTSTANDING.** The review pack is `docs/plans/GATE-2-HISTORICAL-GOLDEN-SIGNOFF-PACK-20261101.md`. D-1/D-2 product-owner approval is not this sign-off. |
| Edits on top of the 14 uncommitted regional-format files | Authorized for `_scripts.blade.php` only (Historical-only additions). |
| Test database | `adasi_portal_test`; `TestingEnvironmentDatabaseSafetyTest` green. |

## 2. What changed

**New**
- `app/Services/Pricing/QuotationHistoryService.php`:
  - read-only, BCMath;
  - one row per (PR Item × participating supplier);
  - contract C1-C5;
  - charts with one declared basis (E1);
  - drawer with the selected previous pinned (E3).
- `_historical_pr_mode.blade.php` and `_historical_pr_scripts.blade.php`.
- Tests:
  - `QuotationHistoryServiceTest` (G1-G28);
  - `HistoricalPrIdentityTest`;
  - `PriceComparisonHistoricalAccessTest`;
  - `PriceComparisonTabContextTest` (N1-N12);
  - `PurchaseHistoryPriceCharacterizationTest`;
  - `PriceComparisonCharacterizationTest`;
  - `QuotationHistoryFixtures`;
  - `tests/js/historical-quotation-chart.test.mjs`.

**Modified**
- `PriceComparisonController::historical()`: `clear_pr` PRG, plus a PR-mode branch. The legacy path is unchanged.
- `PurchasingNavigation`: `comparisonTabUrl()` and `forgetComparisonPrContext()`.
- `StatusHelper`: history label and tone.
- `x-purchasing.comparison-tabs`: context-aware hrefs.
- `_historical_content`: `@if($prMode)` wrapper.
- `_vs_best_content`: reset keeps `pr_id`/`currency`.
- `_scripts.blade.php`: +24 lines, 0 removed.
- `config/procurement.php`: D-3 stale-rate days.
- `lang/{en,id}/purchasing.php` and `status.php`.
- `tests/js/comparison-state.test.mjs`.

**Not touched:**
- `PurchaseHistoryPriceService`, `interSupplier()`, `saveItemAwards`, `PrItemAwardService`, PO generation;
- schema, migrations, data.

## 3. Verification run (2026-11-01, after the closure optimization)

| Check | Result |
|---|---|
| Golden G1-G28 + identity + access + navigation N1-N12 | **43 passed** |
| Related regression filter (comparison, hashid, regional, server tabs, translations, award suites) | **168 passed, 0 failed** |
| JS (`comparison-state`, `historical-quotation-chart`, `server-tabs`) | **17/17** |
| Pint on the changed service | pass |
| Protected files | 13 byte-identical to the baseline diff; `_scripts` 0 baseline lines removed |
| `UserFacingCopyInventoryTest` final ledger | fail: pre-existing, see §6 |

## 4. Performance

Environment: local Windows dev PC, MySQL 8.0.30, PHP 8.2.30 **without OPcache**. Synthetic C8.4 volume in the isolated test DB: 20,008 quotations, 150,160 lines, 30 suppliers, 120 materials, a heavy supplier with 3,001 quotations, and a target PR of 20 items × 8 suppliers. 25 runs, 5 discarded as warm-up. **No staging-class environment was available.**

**Root cause:**
- Server execution is small (31-39 ms).
- The cost was **transferring wide candidate rows**: 8,909 rows with about 45 columns, including the `notes` TEXT column and two LEFT JOINs needed only by the drawer.
- Second was **per-row PHP hydration**, in which `CarbonImmutable::parse` ran on every row.
- Earlier, unindexed per-row candidate scans in PHP and a plan driven by `purchase_requisitions` were fixed. That earlier step brought p95 from 1,690 to 974 ms.

**Phase profile, p50 / p95 ms:**

| Phase | Before closure | After closure |
|---|---|---|
| SQL execute to first row | 39.3 / 45.3 | 31.3 / 45.7 |
| SQL transfer | 160.4 / 177.0 | **92.6 / 147.6** |
| Hydration + effective spec | 126.9 / 142.0 | **81.5 / 109.9** |
| Matching + `specEqual` | 59.9 / 65.2 | 58.3 / 92.0 |
| Charts | 6.9 / 8.0 | 6.4 / 11.2 |
| JSON encode rows | 2.1 / 2.9 | 1.8 / 3.9 |
| Window-function processing | n/a: ranking is done in PHP, the SQL has no window function | n/a |
| **`forPr()` total** p50 / p90 / p95 / p99 | 596 / 673 / 711 / 737 | **433** / 560 / 721 / 1,185 |

**Closure optimization:**
- the candidate query selects only matching and row fields;
- drawer-only fields come from one query over at most 21 lines (`drawerDetails`);
- timestamps already in `Y-m-d H:i:s` skip parsing.

No index or migration was added. Output values are unchanged (G1-G28 pass).

**Index assessment:** none is proposed. Server execution is 31 ms, and `EXPLAIN ANALYZE` shows index range or primary-key lookups only: `pri_material_name_index` → `quotation_items_pr_item_id_quotation_id_index` → `quotations` PK → `purchase_requisitions` PK. A covering index cannot remove the transfer or PHP cost.

**Acceptance:**
- `forPr()` p95 ≤ 250 ms is **not met** locally, and the p95/p99 tail is dominated by host noise.
- It is **not decided**: the final decision needs staging-class hardware with OPcache.

## 5. Database compatibility

| Engine | State |
|---|---|
| MySQL 8.0.30 | Plan above; the JSON plan was captured during the run. G1-G28 pass. |
| **MariaDB 11.4** | **Not run.** No MariaDB or Docker on this host. **Compatibility is not claimed.** `STRAIGHT_JOIN`, `COALESCE` and the query constructs are documented in both engines, but that is not evidence. |

**Reproduce the MariaDB check:**
1. Run the CI `deep` job by `workflow_dispatch` on a branch containing this change. It runs the whole suite, including `QuotationHistoryServiceTest`, on `mariadb:11.4` (`.github/workflows/tests.yml:158-223`).
2. Or locally:
   ```
   docker run --rm -d -p 3307:3306 -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=yes -e MARIADB_DATABASE=adasi_portal_test mariadb:11.4
   DB_PORT=3307 php artisan test --filter="QuotationHistory|HistoricalPr|PriceComparisonTabContext|PriceComparisonHistoricalAccess"
   ```
3. Then run `ANALYZE FORMAT=JSON` on the captured candidate SQL with the synthetic volume, and record `SELECT VERSION()`.

## 6. `UserFacingCopyInventoryTest` baseline verification

Method: a temporary diagnostic using the test's own fingerprint logic, run on isolated LF worktrees at 62b5e1a5. The user's working tree was not touched.

| Tree | Expected | Ledger | Missing | Stale |
|---|---|---|---|---|
| Baseline 62b5e1a5 (clean, LF) | 24,438 | 24,069 | 4,732 | 4,363 |
| Current (LF) | 24,802 | 24,069 | 5,575 | 4,842 |
| Working tree (CRLF) | 24,802 | 24,069 | 5,575 | 4,842 |

**Classification: pre-existing baseline failure**, not environment-dependent (LF and CRLF trees give identical results). The current changes add +843 unreviewed candidates:
- about 554 from Historical: new views and service, plus line shifts in the controller, `StatusHelper` and `_scripts`. Ledger fingerprints include the line number;
- the rest from the user's regional edits.

The ledger was **not** regenerated. It needs a Phase 7 copy review.

## 7. Browser QA (2026-10-30, unchanged by the closure)

- N1, the chart modes, the material switch, N5 Back/Forward and the drawer keyboard flow all pass.
- Contrast is ≥ 4.5:1 in light and dark; targets are ≥ 32 px.
- Three defects found were fixed.

## 8. Remaining blockers

1. Purchasing Supervisor sign-off of G1-G28 (pack ready).
2. A performance decision on staging-class hardware with OPcache (250 ms p95 target).
3. The MariaDB 11.4 run (CI `deep` or Docker, §5).
4. The copy ledger needs a Phase 7 review (pre-existing).
5. Pre-existing: `ui-data-action--primary` uses the raw `--md-primary` in dark (2.77:1).
6. Release items: D-3, D-4, AC8 (offer letters), AC9 (blind re-performance), production data profile.

## 9. Verdict

| Area | Verdict |
|---|---|
| Implementation integrity | **Pass**: G1-G28, N1-N12 and characterization green; protected work intact |
| Performance acceptance | **Not decided**: improved, but the 250 ms target is unmet locally; staging is unavailable |
| Database compatibility | MySQL 8: pass. **MariaDB 11.4: not verified** |
| Purchasing approval | **Outstanding** |
| Production release readiness | **NO-GO** |
