# Supplier Portal Revision — deployment notes

Apply only after reviewing Gate 2 and testing a restored staging database. No application migrations or destructive cleanup were run by this implementation task.

1. Back up the database. Pause web/queue GA writes during deployment: old writers must not insert legacy enum values between mapping and contraction. Deploy code and run the three new migrations together before reopening traffic.
2. UOM columns are nullable for existing records. Do not guess `pcs` or derive a historical invoice snapshot from a mutable GR master. New manual/import writes require the canonical dropdown/whitelist. Existing null values display as unavailable.
3. Infor import reads quantity from P and unit from Q (blank header in the supplied workbook). A mixed-unit receipt is rejected as a group. The single-row whole-GR model cannot represent its lines safely; accepting such data requires a separately approved GR line model with invoice snapshots per line. Do not sum quantities across units.
4. Download the separate Infor PO and GR XLSX templates. Imports accept XLSX, CSV and binary XLS with the same column positions, including blank columns; upload PO first, then GR referencing registered POs. The combined import workflow has been removed. See [Local PO/GR background imports](LOCAL-PROCUREMENT-IMPORTS.md) for limits, preview/confirm and worker deployment. Old nominal `gr_amount` rows must not be converted to a synthetic quantity.
5. Preflight legacy reservations:

   ```powershell
   php artisan payments:cleanup-legacy-ga-drafts
   php artisan payments:cleanup-legacy-ga-drafts --batch-id=123 --batch-id=456
   ```

   Review exact IDs and batch numbers, dependencies and target counts. Then execute only reviewed legacy IDs:

   ```powershell
   php artisan payments:cleanup-legacy-ga-drafts --batch-id=123 --batch-id=456 --execute
   ```

   Every selected row must still be GA DRAFT. All targets are preflighted in a transaction before any delete. Voucher/payment/paid metadata, downstream financial records, paid claims, unexpected attachments and non-GA payables block deletion. Investigate conflicts; do not bypass guards. Deletion removes items→groups→batches, retaining claims and their history/audit. READY_TO_PAY claims regain Finance candidate eligibility. Explicit IDs protect new Finance drafts from an indiscriminate cleanup run.
6. Verify Finance Supplier candidate date ranges/sort with WIB midnight boundaries, Finance GA multi-select creation and GA denial, new/resubmitted Entertainment uploads, GR unit/snapshot display, and full refund proof/date flow. Verify keyboard focus/calendar controls, responsive layouts, PDF output and exports in staging.
7. Re-run `php artisan local-invoices:reconcile --json` and monitor application logs after deployment.

Rollback limitations: refund-reference values are permanently dropped; recreating the nullable column cannot restore them. Canonical Business Travel merges two legacy categories, so the enum migration refuses rollback with canonical rows rather than guessing categories. Restoring original historical values requires an authoritative backup. Dropping UOM columns also loses newly captured unit/snapshot values. Do not perform a routine production rollback without a data recovery plan.

Four existing Composer advisories were detected on laravel/framework, league/commonmark and league/flysystem. Dependency remediation is outside this revision and remains a release risk to assess before deployment.
