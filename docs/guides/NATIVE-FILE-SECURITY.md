# Native file security and PO document processing

This guide describes source implementation, not evidence of production deployment. Native `VALIDATED` means approved format/resource inspection passed. It is not an antivirus verdict and does not prove absence of malware.

## Deployment boundary

The additive migrations `2026_10_09_000003_create_native_file_inspections.php` and `2026_10_09_000004_create_local_po_document_batches.php` were exercised only on `adasi_portal_test`. Their application to the development application database, restored staging or production requires a separate operational decision. Deploy the matching schema and application source together; the new services require these tables.

Pause affected upload admission while capturing the historical cutovers and installing the matching release. Keep financial rows, file paths, document history and private storage intact. Do not run rollback against published batch history: inspection and batch metadata are needed by download guards.

## Existing limits and policies

`config/native_file_security.php` contains domain profiles. The production-default PO ceilings remain 50 MiB uploaded ZIP, 100 raw entries, 100 accepted PDFs and 100 MiB actual expanded bytes. A ZIP PDF can inherit the 100 MiB aggregate compatibility ceiling; individual direct PDF upload remains 50 MiB. Compression ratio alone is not an admission rule.

Native format rules are stricter than MIME-only validation. They include extension pairing, PDF envelope checks, image dimensions, bounded Office containers/XML and OLE metadata chains. Legacy DOC/XLS active content and PDF client vulnerabilities remain residual risks. ZIP64 is disabled by default; bounded ZIP64 has an explicit test-only compatibility scenario.

The 60-second inspection deadline, metadata/name caps and image pixel limits are application safety defaults, not measured hosting guarantees. Representative documents must be checked before production rollout. The request upload count defaults to the effective PHP `max_file_uploads`; deployments may configure an explicit finite count after verification. Business import row counts, headings, first-sheet behavior, formulas, quantities and financial rules remain authoritative in their existing modules.

## Historical documents

`file_security_cutovers` captures the existing maximum ID in each supported document table during migration. Existing identities remain `HISTORICAL_UNVERIFIED`; newly missing inspection metadata has no historical bypass. Retained invoice files carry a historical-origin inspection only after checking the original identity. New copies such as MTC clones must pass current inspection. Authorization is always required separately.

File reads require an allowed verdict, matching private path and, for inspected files, matching bytes/hash. Incomplete ZIP batches cannot serve prepared documents. The unused `local` filesystem serving alias is disabled; do not reintroduce signed raw-path serving around these guards.

## Async admission is disabled

`config/po_documents.php` defaults to `async_enabled=false`, `host_verified=false`, with host-dependent limits unset. Do not enable admission merely because local tests or the tiny synthetic benchmark pass.

Verify and approve positive runtime/slice, disk/reserve, active/backlog/per-user, queue-age, retry, timeout/lease and retention values. Verify the actual database queue connection and `retry_after`, PHP CLI versus web SAPI, Fileinfo/ZipArchive/XMLReader/mbstring support, account quota, free bytes/inodes, process limits and hard timeout mechanism. `disk_free_space()` is not proof of remaining cPanel account quota. Include imports, exports, multipart temporary uploads, queued originals and retained failures in capacity planning.

Keep global database `after_commit=false`: existing exports/imports depend on same-database transactional enqueue. PO document jobs use their own after-commit handoff and recoverable dispatch intent. They use queue `po-documents` and scalar identities. Initial execution is serialized by private filesystem locks; do not assume multiple workers provide additional capacity.

A separately approved worker may listen to `po-documents` without changing `exports,default` or `imports`. Choose its timeout below effective `retry_after`, with overlap protection and PCNTL or an independently verified watchdog. `--max-time` is checked between jobs, not a hard job deadline. No production worker or cron was installed by this task.

`po-documents:reconcile` is scheduled in source every minute with `withoutOverlapping()`. It coordinates with admission/processing locks, repairs missed dispatch and expired leases, discards interrupted staging, preserves verified prepared files and removes abandoned originals from this component's own namespace. Accepted batch retention remains configuration-controlled. Generic non-ZIP crash-orphan sweeps are not implemented; ordinary exception compensation is implemented.

## Verification and capacity research

First verify `.env.testing` and `SELECT DATABASE()` equals `adasi_portal_test`. Run shared-MySQL suites serially. The controlled helpers never target the application database:

```powershell
php artisan test --filter=TestingEnvironmentDatabaseSafetyTest --compact --do-not-cache-result
php artisan test tests/Unit/NativeFileSecurityTest.php --compact --do-not-cache-result
php artisan test tests/Feature/LocalInvoice/LocalPoDocumentBatchTest.php --compact --do-not-cache-result
php tests/Support/native-file-security-benchmark.php
node --test --test-isolation=none tests/js/po-document-upload.test.mjs tests/js/async-form-submit.test.mjs
```

The benchmark uses only small generated PDFs at 1/100/250/500/750 counts and isolated test overrides. It does not authorize higher production limits. Collect representative metadata-only workload distributions without logging content, supplier/bank data, filenames or PO identifiers. Measure process RSS, PHP peak, stage times, queue delay, DB commit, disk/inodes, failure cleanup and mixed workload contention on separately approved restored staging.

`native-file-security-worker-smoke.php` has prepare/worker/verify/cleanup actions. Its generated hex key restricts the private temporary directory and dedicated test queue; it verifies the real test schema and cleans only its own rows/files. It is a small local worker smoke, not a hard interruption or cPanel capacity test.

## Approval and rollback

Permanent PO supplier/number immutability once PDFs exist is not applied. It changes the current edit contract and awaits the business approval requested under mission section 11. Publication locks/revalidation are implemented. GA resubmit versus document-read ownership remains an unresolved pre-existing business decision.

Stop admission and drain/fence active work before a release rollback. Keep additive schema, published files, inspection links and audit history. Do not restore unbounded extraction, bypass verdict guards or delete business attachments. Production readiness also requires browser/keyboard/screen-reader checks, hosting measurements and restored-staging failure recovery.
