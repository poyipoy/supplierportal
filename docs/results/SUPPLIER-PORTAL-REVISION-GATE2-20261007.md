# Supplier Portal Revision — Gate 2

Final verification: 2026-10-08. Implementation is complete in the working tree. Application/staging/production rollout is not performed; this report does not claim production readiness.

## Git safety

- Branch: `master`; upstream: `origin/master`.
- Starting and final HEAD: `51b4a6be64ea863618bd39689ed4ea90d73709ff`.
- No branch switch, staging, commit, push, reset, clean, stash or unrelated revert.
- Initial unrelated changes preserved exactly: `app/Http/Controllers/Purchasing/ExportController.php`, `app/Http/Controllers/Supplier/ExportController.php`, `tests/Feature/MissionFourExportTest.php`. Their original filename terminology diffs remain unchanged.
- Supplied root implementation plan remains untracked and unchanged.
- Final status is recorded below. Every implementation change is unstaged.

## Business requirement coverage

| Requirements | Implementation and evidence |
|---|---|
| GR 1–8 | Positive QTY with max three fractional digits; lowercase canonical UOMS centralised on LocalGoodsReceipt; manual predefined dropdown; real Infor Q column import; mixed units rejected; nullable persisted UOM; immutable invoice-GR snapshot; separate QTY/UOM display. LocalGoodsReceiptInformationTest, LocalGrImportTest, LocalSupplierWholeGrSettlementTest and actual combined XLSX parser test passed. |
| Supplier DRP 9–16 | Verification Date = ready_to_pay_at; due and verification from/to use AND; whitelist sorting due_date/verification_date and asc/desc; default due_date ASC with id tie-breaker. UTC storage bounds follow business-day boundaries. Candidate pagination/filter/sort is independent of batch history and preserves query state. FinanceDrpCandidatesTest passed. |
| GA types/docs 17–21 | Canonical Entertainment, Business Travel, Reimburse/Claim; expand→map→contract MySQL migration; service requires valid private supporting file on each new Entertainment submission/revision; historical finance verification/read remains valid. Canonicalization/workflow and migration tests passed. |
| GA workflow 22–26 | Removed GA draft routes/actions/page/navigation. Finance selects READY_TO_PAY claims not actively reserved and creates one/multiple-claim GA batches using existing engine. Finance/Admin creator restriction enforced in service and routes; bank details revalidated; grouping, locking, Money calculations and zero fee preserved. Finance candidate search/category filter follows project table conventions. |
| Cleanup 27–30 | Explicit preflight command targets GA AND DRAFT only. Selected IDs plus --execute required. All-target dependency checks precede child-to-parent deletion; unexpected voucher/payment/paid/attachment artifacts block the transaction. Claims/history retained and eligible claims released. Boundary and actual downstream-FK tests passed. Application preflight found zero GA DRAFT targets; no application deletion was executed. |
| Refund 31–34 | Column-drop migration; removed validation/persistence/form/output/history/notification reference usage. Full amount, date, private proof, notes and duplicate guard retained. transfer_reference unchanged. Refund/settlement/isolation/notification regression passed; column absence verified in test DB. |
| Localisation/tests 35–36 | EN/ID keys and placeholders updated; obsolete active tokens removed; translation parity/content checks passed. Scoped semantic ledger synchronised for 36 task paths, preserving all unrelated ledger lines byte-for-byte. |

## Important implementation decisions

Infor evidence: `whinh3512m600_0520_20260924-134847_116644.xlsx` has P1 `Received Quantity`, Q1 blank and unit values in Q. The existing LocalGrImportTemplateExport confirms Q. No named UOM header was invented. The sample has 48 populated receipt lines, all pcs; it does not prove all future receipts homogeneous. Grouping key is case-insensitive GR number, with existing PO/date consistency checks. Mixed-unit groups are rejected, not summed or assigned an arbitrary unit. Supporting mixed units inside one GR requires a separately approved line-level schema/domain contract; see deployment notes.

Storage DECIMAL(...,4) remains compatible; application input boundary is three decimals. Legacy UOM/snapshot values stay null without guessed backfill. Retained reservation/consumption does not rewrite snapshots. Misleading cross-unit totals/hardcoded pcs were replaced with GR document counts; individual rows keep quantity and unit.

The repository-owned combined PO/GR template now uses explicit qty/uom instead of obsolete nominal gr_amount and synthesized qty=1. Old nominal-only GR rows reject safely; PO-only rows remain supported. Its real XLSX→parser path was tested.

Supplier legacy APPROVED eligibility is retained alongside READY_TO_PAY. Filters never alter history, paid registers or Purchasing history. GA's new flow preserves the payment_batches→payment_groups→payment_items architecture.

## Exact migrations

1. `database/migrations/2026_10_07_000001_add_uom_to_local_goods_receipts.php` — nullable `local_goods_receipts.uom` and `local_invoice_goods_receipts.gr_uom_snapshot`; no guessed backfill.
2. `database/migrations/2026_10_07_000002_canonicalize_ga_claim_types.php` — MySQL expansion, legacy data mapping, canonical contraction; repeatable up; down refuses ambiguous/lossy restoration while canonical rows exist.
3. `database/migrations/2026_10_07_000003_drop_supplier_overpayment_refund_reference.php` — drop refund column; down only recreates an empty nullable column and cannot restore historical values.

All three were exercised on dedicated MySQL `adasi_portal_test`. All three remain pending on application database `adasi_portal`; staging and production status not inspected. MySQL 8.0.30, PHP 8.2.30 and Laravel 12.66.0 were verified. The local MySQL server was restarted with its existing Laragon configuration after the resumed environment had no listener; no initialisation or application schema/data mutation was performed.

## Exact routes/actions

- Removed GET `/ga/drp-draft`, name `ga.drp-draft`, action `GaController::drpDraft`.
- Removed POST `/ga/drp-draft`, name `ga.drp-draft.store`, action `GaController::createDrpDraft`.
- Added POST `/finance/drp-ga`, name `finance.drp.ga.create`, action `FinanceDrpController::createGaBatch`, inside existing auth + role:finance,admin group.
- Existing GET `/finance/drp-ga` now includes eligible claim candidates.
- Added command `payments:cleanup-legacy-ga-drafts`, preflight default; exact `--batch-id` values and `--execute` required for deletion.

## Main changed components

- GR: LocalGoodsReceipt, LocalGrImport/LocalPoGrImport, LocalGrImportService/LocalPoGrImportService, LocalProcurementMasterService, SaveLocalGoodsReceiptRequest, LocalGrReservationService, combined template, procurement controllers/views, supplier PO views, shared invoice form/detail, multi-select component and import preview.
- DRP: FinanceDrpController, GaClaim eligibility scope, PaymentBatchService creator/bank guard, Finance Supplier/GA candidate views and routes.
- GA: GaClaim constants/labels, GaClaimService, GaController, claim create/revision, dashboard/sidebar/navigation; deleted `resources/views/ga/drp/draft.blade.php`.
- Refund: LocalInvoiceSettlementController, SupplierOverpaymentService, InvoiceNotificationService, Finance overpayment and shared invoice detail, related EN/ID dictionaries.
- Copy audit: `UI-REDESIGN-RESULT/PHASE-7-COPY-AUDIT.jsonl`, only 36 task source paths; unrelated entries unchanged.

## Tests added/updated

Added FinanceDrpCandidatesTest, GaClaimCanonicalizationTest, GaClaimTypeMigrationTest, LegacyGaDraftCleanupTest and LocalInvoice/LocalPoGrQuantityTemplateTest. Updated LocalGrImportTest, LocalGoodsReceiptInformationTest, whole-GR settlement/reservation/document/refund suites, FinanceDrpPaidTest, GA workflow, unified payment, regional/localisation/timezone/dashboard fixtures and TranslationContentTest. Full file inventory appears in final status.

GaClaimTypeMigrationTest runs without a wrapping transaction because MySQL DDL commits implicitly. It verifies the exact dedicated database before migrate:fresh. Its initial DatabaseMigrations teardown hit an unrelated historical quotation-index rollback failure; the harness now avoids rollback of unrelated migrations and resets RefreshDatabaseState. The final isolated migration test passed.

## Commands and results actually executed

| Check | Result |
|---|---|
| `php -v`; `composer --version`; `php artisan --version`; `composer validate --no-check-publish` | Passed; runtime versions above. |
| `php artisan test --filter=TestingEnvironmentDatabaseSafetyTest --compact --do-not-cache-result` | 2 passed, 4 assertions; SELECT DATABASE confirmed test DB, including after local server restart. |
| Initial FinanceDrpCandidatesTest RED run | Missing behavior reproduced after correcting fixture required timestamps. Subsequent focused run passed. |
| Focused candidates/canonicalisation/cleanup | 14 passed, 97 assertions at that checkpoint; later expanded tests also passed in broad regression. |
| GA/payment/localisation/dashboard/candidates suites | 54 passed, 644 assertions. |
| GR/refund/cleanup suites | Initially 63 passed, one stale validation-copy assertion failed; assertion updated and LocalGrImportTest later fully passed in broad regression. |
| `php artisan test --compact --exclude-group=schema-migration --filter='^(?!.*AdvancedExportPerformanceTest)' --do-not-cache-result --log-junit <temp>/adasi-revision-regression.xml` | 1,644 passed / 1,648 total; 4 failures, 0 errors, 0 skipped; 186,448 assertions; 703.08 seconds. Failure classification below. |
| Core changed/new tests in that broad run | FinanceDrpCandidates 7/46, GaClaimCanonicalization 5/30, LegacyGaDraftCleanup 6/42, LocalGoodsReceiptInformation 6/39, LocalGrImport 15/75, combined XLSX parser 1/4 — all passed (tests/assertions). |
| `php artisan test tests/Feature/GaClaimTypeMigrationTest.php --compact --do-not-cache-result` | Final run: 1 passed, 9 assertions. Earlier connection refusal and historical-teardown failures corrected as described. |
| `php artisan test tests/Unit/TranslationContentTest.php tests/Unit/TranslationParityTest.php --compact --do-not-cache-result` | After refund snapshot correction: 18 passed, 88,692 assertions. |
| Translation parity + BusinessTimeGuard | 5 passed, 35,461 assertions. |
| `php artisan test tests/Unit/UserFacingCopyInventoryTest.php tests/Feature/CustomAdasiToastTest.php --compact --do-not-cache-result --log-junit <temp>/adasi-revision-baseline.xml` | Final baseline reproduction: 34 passed / 37 total; 3 failures, 0 errors; 77,844 assertions. Task ledger paths are current; unrelated global drift and export assertions remain. |
| `node --test tests/js/calendar.test.mjs tests/js/unsaved-changes.test.mjs` | 6 passed; sandbox subprocess restriction resolved by approved execution. |
| Changed PHP `php -l` checks | Passed; includes new command/migrations/tests. |
| Scoped Pint on new/changed core code and tests | Passed. Wider Pint retains baseline formatting/line-ending findings; baseline source comparison confirms unrelated existing formatting. No broad formatter churn applied. |
| `php artisan view:cache` | Passed. |
| `php artisan route:list --path=drp --no-ansi`; `--path=drp-ga` | Passed; added Finance POST present, GA draft absent. HTTP absence/role tests passed. |
| `npm.cmd run build` | Final production build passed. Existing logo asset warning remains; initial sandbox spawn EPERM was resolved. |
| `php artisan payments:cleanup-legacy-ga-drafts` | Application preflight: zero targets, no records deleted. |
| `php artisan local-invoices:reconcile --json` | Application read-only report: issue_count 0. |
| `composer audit --locked --no-interaction` | Completed after network permission; failed with 4 existing advisories affecting 3 packages. No package upgrade performed. |
| `python .agents/skills/vulnerability-scanner/scripts/security_scan.py app --scan-type patterns` | One false positive: `$autoVerify = false` in VendorMasterService treated as SSL verify disabled. Source inspected; not an SSL setting. No changed-code vulnerability identified by independent reviews. |
| `git diff --check` | Passed, including final review. |

The initial whole-suite attempt including AdvancedExportPerformanceTest was interrupted after a long run; its 50,000-row benchmark has no completed result. It was excluded from the completed broad regression. Schema migration verification was run separately. No full-green claim is made.

## Remaining failures, risks and deployment evidence

The four broad-run failures were:

1. TranslationContentTest refund history snapshot: task-related stale expected text, corrected; all content/parity tests now pass.
2. UserFacingCopyInventoryTest global ledger coverage: task entries synchronised; **272 unrelated source paths remain stale**. All unrelated ledger lines preserved byte-for-byte, including existing ExportController entries. Unchanged source examples match HEAD. This global baseline gap remains.
3. CustomAdasiToastTest starting-export source assertion: the exact expected `const startExport = async (control) =>` token is absent in HEAD and current unchanged async-export.js. Baseline mismatch; not modified.
4. CustomAdasiToastTest requisition export presentation assertion: expected old copy key is absent in HEAD and current unchanged purchasing/pr/index.blade.php. Baseline mismatch; not modified.

The latter three were reproduced after scoped ledger sync. They remain outside this task and must not be represented as passing.

| Severity / priority | Finding and impact | Status |
|---|---|---|
| High / before release | Existing dependency advisories: Laravel debug-page XSS [GHSA-jh5r-qr3c-85q8](https://github.com/advisories/GHSA-jh5r-qr3c-85q8); CommonMark raw-HTML bypass [GHSA-97jj-33gv-5xf9](https://github.com/advisories/GHSA-97jj-33gv-5xf9) and table-parser DoS [GHSA-3q6v-r5mr-hxv8](https://github.com/advisories/GHSA-3q6v-r5mr-hxv8); Flysystem malformed-UTF8 path normalisation [GHSA-cxf4-7mrp-vvpr](https://github.com/advisories/GHSA-cxf4-7mrp-vvpr). Exposure depends on production configuration/use. | Requires separate dependency remediation/assessment; outside approved revision. |
| High / deployment gate | Enum mapping merges two legacy categories; refund references are permanently dropped; cleanup is destructive. | Test DB verified; backup/restored-staging validation and controlled application deployment still required. |
| Medium / before release | Browser keyboard/reflow/contrast, cross-role interactive flows, staging migration/cleanup, external queue/notifications, PDF visual print and production behavior not exercised interactively. Automated PDF and workflow regressions passed. | Not verified; do not equate automated checks with production readiness. |
| Low / follow-up | Global copy ledger and two export/toast test contracts stale; wider baseline Pint style findings retained. | Documented; no unrelated code/test weakening or bulk cleanup. |

Read [deployment notes](../guides/SUPPLIER-PORTAL-REVISION-DEPLOYMENT-20261007.md) for exact rollout/preflight/cleanup sequence and rollback limitations. No missing Infor evidence blocker remains. Mixed-unit receipts are intentionally unsupported/rejected rather than silently aggregated.

## Working-tree status at Gate 2 review (before the follow-up commit/push)

The snapshot below was captured while the revision was still unstaged, before the user's later commit/push instruction.

```text
 M UI-REDESIGN-RESULT/PHASE-7-COPY-AUDIT.jsonl
 M app/Exports/LocalPoGrImportTemplateExport.php
 M app/Http/Controllers/Finance/FinanceDrpController.php
 M app/Http/Controllers/Finance/LocalInvoiceSettlementController.php
 M app/Http/Controllers/Finance/LocalProcurementController.php
 M app/Http/Controllers/Ga/GaController.php
 M app/Http/Controllers/LocalSupplier/InvoiceController.php
 M app/Http/Controllers/LocalSupplier/PurchaseOrderController.php
 M app/Http/Controllers/Purchasing/ExportController.php
 M app/Http/Controllers/Supplier/ExportController.php
 M app/Http/Requests/LocalInvoice/SaveLocalGoodsReceiptRequest.php
 M app/Imports/LocalGrImport.php
 M app/Imports/LocalPoGrImport.php
 M app/Models/GaClaim.php
 M app/Models/LocalGoodsReceipt.php
 M app/Services/Ga/GaClaimService.php
 M app/Services/LocalInvoice/InvoiceNotificationService.php
 M app/Services/LocalInvoice/LocalGrImportService.php
 M app/Services/LocalInvoice/LocalGrReservationService.php
 M app/Services/LocalInvoice/LocalPoGrImportService.php
 M app/Services/LocalInvoice/LocalProcurementMasterService.php
 M app/Services/Payment/PaymentBatchService.php
 M app/Services/Payment/SupplierOverpaymentService.php
 M lang/en/common.php
 M lang/en/finance.php
 M lang/en/ga.php
 M lang/en/local_invoice.php
 M lang/en/local_procurement.php
 M lang/en/navigation.php
 M lang/en/notifications.php
 M lang/en/validation.php
 M lang/id/common.php
 M lang/id/finance.php
 M lang/id/ga.php
 M lang/id/local_invoice.php
 M lang/id/local_procurement.php
 M lang/id/navigation.php
 M lang/id/notifications.php
 M lang/id/validation.php
 M resources/views/components/ui/multi-select.blade.php
 M resources/views/finance/drp/ga.blade.php
 M resources/views/finance/drp/supplier.blade.php
 M resources/views/finance/invoices/show.blade.php
 M resources/views/finance/local-procurement/_import_gr_modal.blade.php
 M resources/views/finance/local-procurement/index.blade.php
 M resources/views/finance/local-procurement/show.blade.php
 M resources/views/finance/overpayments/index.blade.php
 M resources/views/ga/claims/create.blade.php
 M resources/views/ga/claims/revision.blade.php
 M resources/views/ga/dashboard.blade.php
 D resources/views/ga/drp/draft.blade.php
 M resources/views/local-invoices/detail.blade.php
 M resources/views/local-invoices/form.blade.php
 M resources/views/local-supplier/purchase-orders/index.blade.php
 M resources/views/local-supplier/purchase-orders/show.blade.php
 M resources/views/partials/sidebar.blade.php
 M routes/web.php
 M tests/Feature/FinanceDrpPaidTest.php
 M tests/Feature/GaClaimWorkflowTest.php
 M tests/Feature/LocalInvoice/InvoiceDeliveryScheduleValidationTest.php
 M tests/Feature/LocalInvoice/InvoiceFilenameAutoFillTest.php
 M tests/Feature/LocalInvoice/LocalGoodsReceiptInformationTest.php
 M tests/Feature/LocalInvoice/LocalGrImportTest.php
 M tests/Feature/LocalInvoice/LocalGrReservationConcurrencyTest.php
 M tests/Feature/LocalInvoice/LocalPurchaseOrderDocumentTest.php
 M tests/Feature/LocalInvoice/LocalSupplierOverpaymentTransparencyTest.php
 M tests/Feature/LocalInvoice/LocalSupplierWholeGrSettlementTest.php
 M tests/Feature/LocalizationLocalRenderingTest.php
 M tests/Feature/MissionFourExportTest.php
 M tests/Feature/RegionalGaClaimDisplayTest.php
 M tests/Feature/RegionalInvoiceFinancialDisplayTest.php
 M tests/Feature/Timezone/LocalInvoiceViewTimezoneTest.php
 M tests/Feature/UnifiedPaymentEngineTest.php
 M tests/Feature/UserDashboardRenderingTest.php
 M tests/Unit/TranslationContentTest.php
?? IMPLEMENTATION-PLAN-SUPPLIER-PORTAL-REVISION-20261007.md
?? app/Console/Commands/CleanupLegacyGaDraftBatches.php
?? database/migrations/2026_10_07_000001_add_uom_to_local_goods_receipts.php
?? database/migrations/2026_10_07_000002_canonicalize_ga_claim_types.php
?? database/migrations/2026_10_07_000003_drop_supplier_overpayment_refund_reference.php
?? docs/audits/SUPPLIER-PORTAL-REVISION-GATE1-20261007.md
?? docs/guides/SUPPLIER-PORTAL-REVISION-DEPLOYMENT-20261007.md
?? docs/results/SUPPLIER-PORTAL-REVISION-GATE2-20261007.md
?? tests/Feature/FinanceDrpCandidatesTest.php
?? tests/Feature/GaClaimCanonicalizationTest.php
?? tests/Feature/GaClaimTypeMigrationTest.php
?? tests/Feature/LegacyGaDraftCleanupTest.php
?? tests/Feature/LocalInvoice/LocalPoGrQuantityTemplateTest.php
```
