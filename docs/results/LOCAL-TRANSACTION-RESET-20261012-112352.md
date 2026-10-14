# Local transaction reset — 12 October 2026

Status: **completed**. User explicitly authorized implementation of the reviewed plan.

## Target and results

- Database: `adasi_portal`, MySQL 8.0.30, `127.0.0.1:3306`, server `LAPTOP-J52IM0PD`, Laravel environment `local`.
- Emptied all **59 explicitly approved transaction tables**, removing **272,415 rows**.
- Selectively removed **7 transaction file inspections** and **2 conversation mutes**; total database rows removed: **272,424**.
- Deleted **115 unique transaction files**, totaling **26,566,918 bytes**; **zero cleanup failures remain**.
- **45 candidate paths were already absent** before cleanup. Unreferenced files outside the approved manifest were retained.
- Preserved **29 tables byte-for-byte at the row-checksum level**, all schema definitions, foreign keys and auto-increment values.
- Preserved all document sequence rows and `file_security_cutovers`, plus the **4 master file inspections**.
- Preserved **19 users**, **10 Supplier profiles**, **100 employees**, and **10 master documents/files**. Each master file SHA-256 matches its original value.
- The Supplier account that was PENDING remains inactive/PENDING, with its registration attempt/access/audit removed as explicitly selected. Its old review/revision workflow is no longer available.

## Backup and evidence

Full backup directory: [20261012-112352](../../storage/app/transaction-reset-backups/20261012-112352/).

- [Database dump](../../storage/app/transaction-reset-backups/20261012-112352/database.sql): **85,769,825 bytes**, 90 table definitions and completed dump footer verified.
- Dump SHA-256: `613fd6551c5d2762e9f3d1c63c6e1e572292fed557d25b6b32b737c9c881792c`.
- Full private-storage backup: **823 files / 890,091,046 bytes**. Every copied file was checksum-verified; the source inventory was rechecked after copying.
- [Frozen scope](../../storage/app/transaction-reset-backups/20261012-112352/frozen.json): per-table baseline counts, schema/FK hashes, identity counters, protected-row hashes, file manifest and master-file hashes.
- [Backup verification](../../storage/app/transaction-reset-backups/20261012-112352/backup-verified.json).
- [Committed database result](../../storage/app/transaction-reset-backups/20261012-112352/database-committed.json).
- [File cleanup result](../../storage/app/transaction-reset-backups/20261012-112352/file-cleanup.json).
- [Final verification](../../storage/app/transaction-reset-backups/20261012-112352/final-verification.json).
- [Invoice reconciliation](../../storage/app/transaction-reset-backups/20261012-112352/reconciliation.json).

Backups and the one-off operational script are in ignored storage. No application source, migrations, seeds, constraints, credentials, or Git history were modified.

## Approved deletion scope

- Import: `purchase_requisitions`, `purchase_requisition_suppliers`, `pr_items`, `quotations`, `quotation_items`, `pr_item_awards`, `purchase_orders`, `po_quotations`, `po_documents`, `po_item_progress_updates`, `shipments`, `shipment_items`, `shipment_documents`, `qc_inspections`, `qc_items`, `material_claims`, `claim_attachments`.
- Local procurement/import: `local_purchase_orders`, `local_goods_receipts`, `local_procurement_imports`, `local_procurement_import_rows`, `local_procurement_import_records`, `local_po_document_batches`, `local_po_document_entries`.
- Local invoices: `local_invoices`, `local_invoice_revisions`, `local_invoice_documents`, `local_invoice_receipts`, `local_invoice_status_histories`, `local_invoice_physical_verifications`, `local_invoice_verifications`, `local_invoice_goods_receipts`, `local_invoice_vouchers`, `local_invoice_payments`, `local_invoice_payment_transfers`.
- GA/payment: `ga_claims`, `ga_claim_documents`, `ga_claim_receipts`, `ga_claim_status_histories`, `payment_batches`, `payment_groups`, `payment_items`, `supplier_overpayment_refunds`, `local_finance_audit_logs`.
- Supplier processes: `supplier_audits`, `supplier_audit_answers`, `supplier_audit_status_histories`, `supplier_change_requests`, `supplier_registration_attempts`, `supplier_registration_access`, `supplier_registration_audits`.
- Supporting operations: `attachments`, `conversations`, `messages`, `notifications`, `export_jobs`, `jobs`, `job_batches`, `failed_jobs`.

Deletion used one transaction and child-before-parent dependency ordering with foreign-key checks enabled throughout. No TRUNCATE, reset of IDs, migrations, seeders, model events or outgoing notifications were used.

## Execution incidents and recovery

The first attempt was interrupted by a MySQL **normal SHUTDOWN requested by user root**, recorded in the existing MySQL log at 11:29:02 WIB. A concurrent startup attempt failed while the old process still held `ibdata1`. After the old process completed shutdown, the existing MySQL executable/configuration was started again. The original frozen database state, identity counters and file manifest matched exactly after recovery; no first-attempt deletion persisted and no backup restore was needed.

The second attempt committed successfully. Initial sandbox cleanup deleted 90 files and encountered filesystem permission failures for 25 files. Retrying only the remaining approved manifest entries with elevated execution removed all 25, preserving cumulative cleanup evidence. These intermediate failures are resolved.

## Verification and resumed operation

Executed successfully:

- PHP syntax check of the one-off operational script.
- Empty-table checks for all 59 targets.
- Exact protected-row checksums for all 29 preserved tables and retained selective rows.
- Schema, auto-increment, sequence and file-security cutover comparisons.
- Foreign-key orphan checks, including composite constraints.
- SHA-256 comparisons for all ten master files.
- `php artisan local-invoices:reconcile --json`: **issue_count = 0**.
- Maintenance mode exited; GET `http://127.0.0.1:8000/login`: **HTTP 200**, login form present.
- Export/default worker and imports worker restored with their original arguments and project working directory; both processes alive with empty stderr logs.
- The 219 pre-existing Git status entries were unchanged before adding this result document. Existing application changes were preserved.

Not executed: authenticated browser/dashboard QA, full feature suite, staging or production checks, or a full backup restore rehearsal. The database dump and storage copies were verified as described above.

## Recovery procedure

If recovery is explicitly required later, first stop application writes and the two ADASI queue workers again. Verify the backup checksums and confirm the exact loopback `adasi_portal` target. Restore the complete SQL dump into that database using the existing local credentials and restore private files from the same backup point. Then compare against `frozen.json` and run read-only invoice reconciliation before resuming the application. Restoring the dump replaces subsequent local database changes; it must not be performed as a routine verification step.
