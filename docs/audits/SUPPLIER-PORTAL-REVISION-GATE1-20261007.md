# Supplier Portal Revision — Gate 1

Baseline: `master`, HEAD `51b4a6be64ea863618bd39689ed4ea90d73709ff`, upstream `origin/master`. No staged changes. Existing unrelated modifications: Purchasing/ExportController.php, Supplier/ExportController.php, MissionFourExportTest.php (export filename terminology only). The supplied implementation plan is untracked. Preserve all four files.

All root instructions, CLAUDE.md, cognitive framework, context.md and applicable PHP/common rules inspected. Only root AGENTS.md found. Actual implementation overrides stale context.md nominal GR and generic document-storage descriptions. Current runtime: PHP 8.2.30, Laravel 12.66.0, MySQL 8.0.30. Testing configuration and live SELECT DATABASE confirmed `adasi_portal_test`; database safety test passed 2 tests, 4 assertions. No application database writes performed.

## GR

Parser: LocalGrImportService. Real workbook `whinh3512m600_0520_20260924-134847_116644.xlsx`: P1 Received Quantity; Q1 blank; Q contains pcs. Template LocalGrImportTemplateExport also supplies pcs in Q. Source UOM is positional Q (zero-based 16), not an invented named header. 48 populated sample lines have pcs; no mixed-unit group in this sample. Import groups case-insensitive GR number alone, then checks PO/date. Add homogeneous-unit guard: reject mixed groups entirely, never sum incompatible quantities. This does not establish an ERP guarantee of homogeneous GRs.

LocalGoodsReceipt qty DECIMAL(...,4); invoice relation gr_qty_snapshot; neither has UOM. Add nullable uom/gr_uom_snapshot without guessed legacy backfill; require canonical UOM for new writes. Retained reservations and consumption must not rewrite snapshots. Manual controller/service/view and separate import/preview/confirm are affected. Combined import has stale nominal GR input and synthesized qty=1; replace its own template contract with explicit qty/UOM rather than pretending nominal amount is quantity. Remove meaningless cross-unit totals/hardcoded pcs.

## Supplier DRP

FinanceDrpController indexSupplier calls eligibleForPaymentBatch (READY_TO_PAY plus historical APPROVED), excludes ACTIVE items on UNPAID groups in active batches, applies supplier hash filter, currently latest(id)->get. Preserve eligibility including legacy APPROVED. Candidate filters only; batch list remains latest(id), paginate(15). Map due_date/verification_date to due_date/ready_to_pay_at with strict direction whitelist, due_date ASC + id ASC default, business-day UTC bounds on timestamp. Paginate candidates separately to avoid unbounded hydration and independent batch pagination. Use calendar components and accessible sorting links.

## GA

Enum currently Entertain Sales/UPD Sales/UPD GA/Reimburse/Claim. Add new migration expand→map→contract, guarded rollback. Service accepts optional supporting file, private per-revision storage; require document on new Entertainment submit/resubmit, leave historical finance verification/read unchanged. Remove GaController drpDraft/createDrpDraft, GET/POST ga/drp-draft, sidebar/dashboard/page. Finance indexGa currently only batch history; add READY_TO_PAY candidate pool using service reservation predicate and Finance POST creation. PaymentBatchService currently permits GA creators: restrict to Finance/Admin. Retain existing grouping, Money calculations, transactions, locks and zero fee.

## Legacy GA DRAFT cleanup

Verified schema: payment_groups.batch_id CASCADE; payment_items.group_id CASCADE; local_invoice_vouchers restrict batch/group/item, local_invoice_payments restrict item. Transfers/refunds downstream of payment. GA execution/voucher metadata is held on PaymentGroup. Reject unexpected finalization, voucher, transfer, paid metadata and supplier downstream records. Export classes restrict supplier; inspect polymorphic export/job/audit references as well. Use explicit preflight command with dry-run and exact operator-selected IDs plus --execute, protecting future Finance drafts; no development or production execution during implementation. Narrow predicate GA AND DRAFT; child-to-parent safe deletion and claim re-eligibility tests.

## Refund

refund_reference required in SettlementController/service, persisted, shown in two UI surfaces, interpolated in history/notification, present in translations/tests. Drop through new isolated migration and remove runtime usages; preserve full exact amount, date, proof private upload, notes, duplicate guards, audit and transfer_reference.

## Implementation sequence

Focused tests first; implement GR, Supplier DRP, GA claims/Finance creation, cleanup command and refund independently; run all database tests serially; scoped syntax/Pint, view compilation, route inspection, build, security review and final diff/token audit. No stage/commit/push. Missing browser/staging/production evidence will be separately reported. User's explicit execution request authorizes work after audit; no renewed plan approval required.

Research/reuse: existing Laravel query/validation, BusinessTime, domain services and calendar components are sufficient; no new package. GitHub CLI unavailable; official Laravel 12 query builder/schema documentation and installed framework are used for API evidence.
