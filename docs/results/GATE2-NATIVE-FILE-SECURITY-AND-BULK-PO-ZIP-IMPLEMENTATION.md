# Gate 2 — Native file security and bulk PO ZIP implementation

Date: 9 October 2026. Repository: `C:\laragon\www\adasi_portal_supplier`.
This is a source implementation and local verification report. **Production readiness is conditional; production deployment is not authorized or verified.**

## 1. Scope, source inputs, and Git safety

Starting and ending branch: `master`.
Starting HEAD: `33258819c6283de284eb9201a446caaadb7484a5`; no branch switch or commit was made.

The initial inspection reported 136 modified and 4 deleted paths, with the untracked inventory changing during concurrent work. The preserved starting snapshot has **136 modified / 4 deleted / 83 untracked status entries**. Directory entries in `git status --short` are not individual file counts. Existing export, localization, import, registration, account and Supplier Audit changes were already present. Additional Supplier Audit/UI changes appeared during this task; the complete worktree diff must not be attributed to Gate 2.

The original authoritative business specification is `GATE1-PLAN-SECURE-DOCUMENT-UPLOAD-MALWARE-SCANNING-ADASi.md`, together with the user's Gate 2 mission and the preceding conversation's conditional audit. A standalone `GATE1-NATIVE-FILE-SECURITY-AND-BULK-PO-ZIP-AUDIT.md` was not available in this checkout. This report maps implementation to the explicit mission requirements and verified source; it does not invent an exact F01–F15 identifier mapping from an unavailable standalone artifact.

A before-edit file/hash/status snapshot is retained locally in ignored `storage/framework/cache/native-file-security-baseline/`. Surgical changes preserve pre-existing content rather than restoring HEAD. Hash comparisons confirm `context.md`, Composer manifests, `config/queue.php`, `ExportDispatcher.php`, and `LocalProcurementMasterService.php` stayed identical to the starting snapshot. `resources/css/app.css` changed through concurrent work and was not edited by this implementation.

No staging, commit, push, reset, stash, clean, branch switch, package installation, application/production migration, cron installation, or production configuration change occurred. Database-backed verification used only the positively verified `adasi_portal_test` database.

Final initial Gate 2 inspection before the section 17 follow-up: branch/HEAD unchanged; **178 modified / 4 deleted / 120 untracked status entries**, with zero staged paths. Task-path `git diff --stat` reports 56 tracked files changed, 1,375 insertions and 1,086 deletions; it excludes untracked files and includes pre-existing changes in shared task paths, so it is not an attribution total. The initial task manifest had 99 paths plus this report; section 17 adds one regression test. Global `git status --short`, `git diff --stat` and `git diff --check` were executed; the global whitespace failure and passing task-scoped check are recorded in section 10.

## 2. Instructions and skills used

Consulted project instructions: root `AGENTS.md`, `CLAUDE.md`, `claudes-cognitive-framework-for-laravel-development.md`, and `context.md`. No more-specific applicable AGENTS file was found.

Actual local skills used:

- `orch-add-feature`, with its referenced `orch-pipeline`, for orchestration, sequencing and review.
- `search-first` for discovering existing domain upload services and parser boundaries.
- `laravel-patterns` for transactions, policies, scoped inspection, queue jobs and additive schema.
- `laravel-security` for bounded inspection, isolation, fail-closed access and resource controls.
- `laravel-tdd` for adversarial and regression fixtures.
- `laravel-verification` for isolated database checks and executed quality gates.
- `accessibility` for status/progress markup, keyboard-compatible retry and EN/ID feedback.

No missing skill contents were invented. The skill pre-commit gate does not authorize Git mutation; the user expressly prohibited staging, commits and pushes.

## 3. Implementation by phase

| Phase | Source implementation | Verification and limits |
|---|---|---|
| 2A foundation | Domain profiles; extension/detected-MIME pairing; bounded PDF/image/Office/OLE checks; SHA-256; checked private streaming storage; persistent inspections; historical cutovers; guarded egress | Native/parser, service and access regression tests passed. Native validation is not antivirus scanning. |
| 2B PO ZIP | Bounded central-directory preflight and streamed extraction; generated paths; actual bytes/CRC/hash; domain match and duplicate rejection; whole-batch publication | Default-count 100-PDF publication passed. Synthetic 250/500/750 extraction passed only with isolated test overrides. |
| 2C async | Database-backed batches/manifests, dedicated database queue, dispatch intent after commit, leases/fencing, entry checkpoints, idempotent publication, reconciliation and retry/status endpoints | Local real database worker smoke and simulated recovery passed. **Admission remains disabled**; hard-kill and cPanel execution are unverified. |
| 2D domains | Inspection integrated into active upload/service boundaries and relevant document egress; parser admission before existing import readers | Focused domain regression executed; final combined result recorded below. No financial rules or permanent PO reassignment policy changed. |
| UI/accessibility | Separate upload/queued/processing/completed/rejected/failed feedback; polling/retry/session resume; 50 MiB PO modal; translations | JS tests, Blade compilation and build passed. Interactive browser/screen-reader verification is NOT EXECUTED. |

## 4. Native inspection and historical compatibility

`FileInspectionService` is scoped in `AppServiceProvider` so multi-file requests share a count/time budget. It rejects invalid uploads, unreadable/symlink sources, invalid extensions, excessive file size and mismatched detected MIME. It admits Office generic MIME only after bounded container/structure checks. It hashes source bytes, creates a PENDING inspection before private storage, checks the write result and stored size/hash, then sets VALIDATED. Stored PO ZIP originals remain PENDING until all intended PDF entries pass.

File names on disk remain generated UUID/hash names; client names are descriptive metadata. `FileInspectionResult` never returns an antivirus verdict. Rejected pre-storage requests create no successful document metadata. Storage failures compensate files and retain ERROR where persistence permits.

`FileAccessGuard` runs after existing policy/session authorization. It denies new missing inspections and PENDING/REJECTED/ERROR inspections, checks path/size/hash, and requires COMPLETED batch status for prepared PO PDFs. Admin access does not bypass these verdict checks. Existing historical identities are bounded by immutable per-table ID cutovers captured at migration time and classified HISTORICAL_UNVERIFIED. Retained invoice revisions can carry a verified historical-origin inspection; fresh uploads and MTC copies must pass current inspection.

The unused local signed raw-file serving alias is disabled (`config/filesystems.php`); private storage remains private. Existing owner/role policies and registration attempt membership remain in force. The registration reviewer additionally checks the requested document belongs to the relevant attempt snapshot.

PDF checks examine the version header, bounded EOF/startxref tail and referenced xref/object envelope. They are **not** a complete PDF semantic parser, active-content sanitizer, signature verifier, or malware detector. Image checks examine detected type and dimensions/pixel budgets without fully decoding attacker-controlled images.

OOXML uses a separate policy: bounded metadata and actual decompression, required package parts, XMLReader with external entity/DTD protections, decoded active-content attributes, cell/reference limits, and rejection of VBA/ActiveX/embedded OLE package paths. DOCX/XLSX are never subjected to the PO PDF-only entry policy. Legacy DOC/XLS use seek-based OLE FAT/DIFAT/miniFAT/directory-chain validation; supported imports retain existing BIFF/business parsing.

## 5. Domain integration coverage

All paths below are actual repository sources, with service/domain inspection in addition to existing FormRequests.

| Workflow | Admission / storage boundary | Access / compatibility |
|---|---|---|
| Registration + Company Profile | `SupplierRegistrationService.php` (native store at line 777); distinct registration/company profiles and compensation journal | Applicant session/attempt access and reviewer attempt membership, then guard; retained history unchanged |
| Supplier master | `LocalSupplier/VendorProfileController.php` | `SupplierMasterDocumentController.php` policy then guard |
| Invoice + tax invoice + delivery/supporting documents | `LocalInvoice/InvoiceDocumentService.php` (store line 98; historical inheritance line 46) | `LocalInvoiceDocumentController.php`; required types, revisions and financial submission logic retained |
| GA supporting files | `Ga/GaClaimService.php` (preflight lines 37/124 and store lines 72/158) | `GaClaimDocumentController.php`; existing role/read contract retained |
| Quotation MTC | `Supplier/QuotationController.php` upload and checked clone; guard before retained relink | Polymorphic attachment policy; current inspection required for new copies |
| Shipment documents | `ShipmentService.php` (store line 606) | Attachment policy and existing shipment lifecycle |
| QC evidence | `Qc/QcInspectionController.php` | Image-only domain profile; attachment policy unchanged |
| Material claim response | `Supplier/ClaimController.php` | Staging/response/attachment DB transaction and compensation; owner checks retained |
| Conversation files | `ConversationMessageController.php` | Membership/attachment policy; checked storage and compensation |
| Refund proofs | `Payment/SupplierOverpaymentService.php` (store line 35) | Existing settlement/refund rules; attachment policy; removed refund_reference not restored |
| Supplier Audit results | `SupplierAudit/SupplierAuditReviewService.php` (store line 121) | Existing audit result policy/history; pdf/xlsx/images profile |
| Single Local PO PDF | `LocalPoDocumentService.php:42` | Supplier-specific filename match, locked revalidation, audit and guard |
| Bulk PO ZIP | `LocalPoDocumentService.php:72`, `LocalPoDocumentBatchService.php` | Entire batch publishes together; old attachments preserved on failure |
| Local PO/GR XLSX/XLS/CSV | `LocalProcurementStreamingReader.php:27`, `LocalProcurementImportService.php:22`, `LocalProcurementCsvReader.php` | Original upload inspection link; existing preview/confirm/queue, physical row, formula/calendar and supplier matching contracts |
| PR and quotation spreadsheet preview/import | `SpreadsheetImportReader.php:14`, called by existing Purchasing PR/Supplier Quotation controllers | Native admission before checked temporary copy and Laravel Excel |
| Employee workbook import | `EmployeeExcelImportService.php` | Native admission before existing IOFactory load, sheet selection and business transaction |

Shared uploaded-document reads use `AttachmentController.php:16`. Generated export/PDF artifacts are not relabeled as uploaded-document inspection successes. Existing imports and exports keep their queues and business logic.

## 6. ZIP security and resource budgets

Source: `NativeArchiveInspector.php:27` preflight, streamed extraction near line 179, `streamEntry():395`, normalized names near line 451; `LocalPoDocumentBatchService.php` admission, checkpoints, locked publication and cleanup.

Implemented safeguards:

1. Walk bounded EOCD/central-directory metadata **before** libzip open, bound raw entries/name/metadata and validate container consistency.
2. Allow STORE/DEFLATE only; reject encrypted/unsupported/multi-disk containers and unsupported ZIP64 by default.
3. Reject traversal, absolute/drive paths, invalid UTF-8/control/unsafe names, normalized duplicate paths and Unicode/case basename collisions.
4. Reject symlink/special members, nested archive names and non-PDF content members. Existing directory/macOS metadata exceptions are preserved, count toward raw/metadata budgets and are not extracted.
5. Require each intended filename to match exactly one PO belonging to the selected eligible supplier; reject duplicate targets.
6. Stream decompressed entries into exclusive random private filenames; never use raw entry paths as filesystem destinations.
7. Count actual per-entry and aggregate bytes before consuming a chunk; verify declared size, CRC and SHA-256. Batch manifests also independently sum actual bytes before progressing.
8. Bound elapsed time and free-space checks; handle failed reads, zero/short writes and flush failures. There is no `stream_get_contents()` whole-entry allocation or `extractTo()` path extraction.
9. Prepare inspected private finals without attachment rows. In a final DB transaction, lock POs in stable order, revalidate ownership/matching/actor/supplier eligibility, and create all attachments/audits plus COMPLETED together.
10. On failed publication, roll back attachment/audit rows and compensate staged files. Cleanup failures remain tracked for reconciliation rather than falsely marked cleaned.
11. Duplicate dispatches and stale fencing tokens cannot republish a COMPLETED batch.

| Configuration | Default / boundary | Interpretation |
|---|---|---|
| ZIP upload | 50 MiB | Existing production ceiling preserved |
| Raw entries | 100 | Includes directories/ignored metadata |
| Accepted PDFs | 100 | Does not promise capacity above raw-entry ceiling |
| Actual per PDF | 100 MiB | Cannot exceed total; preserves former aggregate-based ZIP compatibility |
| Actual total expansion | 100 MiB | Existing production ceiling preserved, now enforced against actual bytes |
| Central metadata / name | 4 MiB / 1,024 bytes | Finite technical controls, not measured business distributions |
| Chunk | 64 KiB, centrally bounded | Implementation setting exercised locally; not a production throughput claim |
| Synchronous runtime | 60 seconds | Application safety deadline; hosting hard timeout unknown |
| Synchronous working budget | 250 MiB | Covers source plus staging/prepared overlap; does not raise 50/100/100 ceilings or prove cPanel quota |
| Minimum free-space reserve | 0 in synchronous compatibility config | Actual safe account reserve UNKNOWN; zero is not a capacity certificate |
| Processing concurrency | Serialized at 1 | Filesystem lock; additional workers do not automatically add capacity |
| Queue, per-user, backlog, retry, slice, lease, timeout, retention | Host-dependent values NULL; async OFF | Missing measurements fail async admission closed |

Runtime safety settings and stricter format checks require representative-document validation before rollout. `disk_free_space()` does not measure remaining cPanel account quota or inode quota. Multipart/web temporary storage, exports/imports, queued originals and retained failures must be included in host sizing.

## 7. Queue, recovery and publication design

`LocalPoDocumentBatch` lifecycle:
PENDING → PROCESSING → VALIDATED → PUBLISHING → COMPLETED; terminal failures REJECTED/FAILED.

Private immutable source ZIP hash, actor/supplier, locale, budget snapshot, dispatch intent, attempt/run/dispatch tokens, progress, reservation and per-entry prepared-file metadata are durable. Jobs use scalar identities on database queue `po-documents`, dispatched after transaction commit. Failed enqueue retains a recoverable dispatch intent.

Admission and processing use independent private locks; reconciliation obtains the same locks before cleanup/fencing. Retry rechecks owner policy and finite configured retry limits. Entry checkpoints avoid restarting already verified prepared entries. Extraction never holds a long DB transaction. Final publication is a DB transaction; filesystem preparation is explicitly separate and compensated.

A continuation can yield between entries, retaining verified prepared documents and scheduling a new token. **There is no arbitrary mid-entry DEFLATE resume.** Hosting must allow the largest permitted entry plus validation/publication to fit a verified slice/hard worker window, or async must remain disabled. Application deadline checks cannot themselves stop a blocked native call or a server-killed process.

`po-documents:reconcile` repairs missing dispatch, expired leases, interrupted stages, invalid/untracked prepared files and abandoned originals from this component's generated namespace, while preserving published attachments and verified prepared checkpoints. Cleanup checks return values and marks cleaned_at only after success. Generic non-ZIP hard-crash orphan reconciliation is not implemented; ordinary exception compensation is implemented.

Source scheduler registration is every minute with withoutOverlapping. This is **not** installation or alteration of production cron. Existing global database queue after_commit remains false; exports/imports retain their transactional enqueue contracts.

## 8. Additive migrations and deployment safety

- `database/migrations/2026_10_09_000003_create_native_file_inspections.php`: file_inspections, immutable legacy cutovers, nullable restricted inspection FKs on attachments/local_invoice_documents/ga_claim_documents/supplier_master_documents.
- `database/migrations/2026_10_09_000004_create_local_po_document_batches.php`: capacity lock row, batch/entry manifests, unique actor/request key, unique batch/entry index and batch/target PO, unique attachment linkage and recovery indexes.

Both were exercised through isolated test resources. **Neither was applied to application, staging or production databases.** Matching schema/source deployment and a paused upload window for cutover capture require separate authorization. They do not rewrite existing financial values or file paths.

Rollback guards refuse to destroy inspection or batch history that subsequent access depends upon. For operational rollback, stop admission, drain/fence work, preserve additive schema/links/files/audits, and deploy a compatible reviewed release. Do not blindly run migrate:rollback, restore unbounded extraction or remove verdict guards.

## 9. UI, localization and access

The PO modal now exposes the same 50 MiB backend upload ceiling. Multipart forms use existing async submission to preserve selected browser files on validation errors. The ZIP UI treats upload completion separately from queued/processing/publication completion, polls only the same origin, stops unauthorized polling, persists only a status URL in sessionStorage, and provides CSRF-protected retry.

Status uses a polite live region; progress has an accessible label and can be indeterminate while uploading; buttons remain native keyboard controls. EN/ID message sets are paired. Existing shell/design system and role navigation are preserved.

Verified: JS syntax and four JS tests; translation parity; Blade compilation; production asset build. NOT EXECUTED: browser rendering, keyboard/focus walkthrough, screen-reader announcements, reload/resume and retry interaction with an enabled staging worker. No browser QA completion claim is made.

## 10. Executed verification ledger

Results refer to actual local commands. Suites overlap; counts are **not** summed into a fabricated unique-test total.

| Check / command group | Result |
|---|---|
| .env.testing + live SELECT DATABASE safety | PASS: configured and actual database adasi_portal_test; safety suite 2 tests / 4 assertions |
| Final native/parser group (six Unit files listed below) | PASS: 34 tests / 4,220 assertions |
| Serial safety + batch + ZIP security + Hashid + Import/Local isolation | PASS: 62 tests / 290 assertions |
| Safety + expanded batch + native PO security + legacy PO document suite | PASS: 28 tests / 101 assertions |
| Final safety + expanded batch after actual-byte/cleanup changes | PASS: 13 tests / 47 assertions |
| Registration + core native feature group (earlier focused run) | PASS: 73 tests / 487 assertions |
| Domain/vendor/GA/invoice + access group | PASS: 38 tests / 197 assertions |
| PO/access/shipment/customs group | PASS: 58 tests / 291 assertions |
| Claim/refund/PR+quotation import/employee group | PASS: 37 tests / 327 assertions |
| Background local imports | PASS: all 17 tests also passed in the final combined regression |
| Supplier Audit result-specific regressions | PASS: all 8 review/result/UI tests in the final combined regression |
| TranslationParity + BusinessTime checks | PASS: 5 tests / 37,689 assertions |
| Final combined cross-domain regression | PASS: 207 tests / 1,435 assertions, 167.35 seconds |
| Real database queue smoke | PASS: prepare PENDING, one dedicated worker job, COMPLETED with one attachment and no remaining dedicated jobs; scoped fixture cleanup completed |
| node --check + node --test --test-isolation=none | PASS: 4 JS tests, 0 failures |
| Scoped PHP syntax | PASS: 83 PHP files |
| Scoped vendor/bin/pint --test | PASS after scoped formatting |
| php artisan view:cache | PASS, including final refresh |
| npm.cmd run build | PASS (9.33 seconds, 24 transformed modules); existing logo-adasi.png runtime-resolution warning |
| composer validate | PASS with existing exact-version constraint warnings; manifests unchanged |
| composer audit | BLOCKED by unavailable Packagist network; no vulnerability-free dependency claim |
| Whole worktree git diff --check | FAIL: context.md lines 3/5/6 trailing whitespace and app.css EOF blank line in unrelated dirty work |
| Gate 2 scoped git diff --check | PASS; unrelated whitespace deliberately preserved |
| Full PHP suite, coverage, production benchmark, browser QA | NOT EXECUTED |

Final native/parser command:
```powershell
php artisan test tests/Unit/NativeFileSecurityTest.php tests/Unit/SpreadsheetNativeAdmissionTest.php tests/Unit/BoundedCsvReaderTest.php tests/Unit/LocalProcurementCsvReaderTest.php tests/Unit/LocalProcurementStreamingReaderTest.php tests/Unit/LocalProcurementXlsReaderTest.php --compact --do-not-cache-result
```

Final PO command:
```powershell
php artisan test tests/Feature/TestingEnvironmentDatabaseSafetyTest.php tests/Feature/LocalInvoice/LocalPoDocumentBatchTest.php tests/Feature/LocalInvoice/LocalPoDocumentSecurityTest.php tests/Feature/LocalInvoice/LocalPurchaseOrderDocumentTest.php --compact --do-not-cache-result
```

Final cross-domain command:
```powershell
php artisan test tests/Feature/TestingEnvironmentDatabaseSafetyTest.php tests/Feature/SupplierRegistration tests/Feature/DomainNativeFileSecurityTest.php tests/Feature/FileAccessGuardTest.php tests/Feature/GaClaimWorkflowTest.php tests/Feature/LocalInvoiceSubmissionV2Test.php tests/Feature/SupplierVendorProfileTest.php tests/Feature/ShipmentDocumentsAndQcIntegrationTest.php tests/Feature/PoCustomsDocumentationShipmentSyncTest.php tests/Feature/QuotationMtcReplacementLifecycleTest.php tests/Feature/MaterialClaimSecurityAndUiTitleTest.php tests/Feature/LocalInvoice/LocalSupplierOverpaymentTransparencyTest.php tests/Feature/MissionFiveImportTest.php tests/Feature/Employee/EmployeeExcelImportTest.php tests/Feature/LocalInvoice/LocalProcurementBackgroundImportTest.php tests/Feature/SupplierAudit/SupplierAuditReviewTest.php --compact --do-not-cache-result
```

An earlier combined Supplier Audit regression had a UI assertion failure: `purchasing_show_page_renders_actions_by_status` expected the existing “Answer summary” label during concurrent Supplier Audit UX changes. No historical-baseline execution established that it was pre-existing. The final combined run on the latest worktree **passed that assertion**, together with all 207 cross-domain tests. Gate 2 did not rewrite that assertion to obtain green; the earlier observed failure is superseded by the final executed result.

Verification incident: two earlier shared-MySQL RefreshDatabase processes overlapped and produced invalid missing-table failures. Both were stopped; their results are invalidated. The safety/batch/isolation groups were rerun **serially**, producing the passing results above. Only the dedicated test schema was involved. A cleanup-failure test initially failed because its filesystem mock was not restored correctly; correcting the mock and rerunning verified the intended recovery behavior. These are not hidden passing runs.

## 11. Security and reliability test coverage

| Requirement | Implemented coverage / evidence |
|---|---|
| Real valid PDF, images, XLSX/XLS and DOCX where allowed | Valid fixtures and parser/domain regressions; fake header-only PDFs replaced with actual xref/EOF fixtures |
| 1 / 100 / 250 / 500 / 750 PDFs | Bounded extractor test exercises all counts with isolated overrides; 100-PDF full batch additionally checks 100 attachments/audits |
| Expansion bomb / per-entry / total / forged size | Metadata overflow plus actual counters and underdeclared-size rejection; controlled small fixtures only |
| Too many entries / collision / path attacks | Configured raw-count tests, duplicate flattened basenames, Unicode/case collision, traversal/absolute paths and nested archives |
| Encryption / methods / symlinks / corruption / ZIP64 | Explicit native tests; ZIP64 default denial and isolated opt-in fixture |
| Invalid PDF / MIME spoof / image dimensions / Office structure | Native tests, domain denial and pre-parser tests; UTF-16 macro package attributes tested after decoded XML inspection |
| Supplier ownership and authorization | Cross-supplier, status-owner, existing isolation suites and changed-owner-after-admission test |
| Atomicity / history / duplicate work | Unmatched PO preserving old attachment, injected second-attachment failure, rollback of all audits, duplicate job/idempotency tests |
| Recovery / cleanup / failed persistence | Expired lease + stale token, process-lock exclusion, missed dispatch, abandoned source sweep, failed cleanup/reconcile, domain DB/storage failure paths |
| Legacy access and incomplete verdicts | Cutover identity, new missing inspection denial even admin, PENDING/REJECTED/ERROR and changed bytes denial |
| CSV/XLSX/import contracts | Multiline/encoding/delimiter, record/cell/column budgets; first sheet, physical row, rich/shared text, formula/calendar, 70,001-row boundary and BIFF encryption tests |
| Progress/queue semantics | JS queued-not-completed state and poll completion; translation parity |
| Real disk quota full / inode exhaustion / raw short-write fault / interrupted native stream / killed worker / multi-process contention | NOT EXECUTED; checked code paths plus selected failure simulations do not prove these host conditions |

Legacy DOC/XLS content, all PDF active features, XML complexity and large spreadsheet parser peak memory cannot be described as fully safe based on structural admission. Full parser/container budgets and actual business limits remain distinct. Restored staging must verify representative sizes and adversarial failure windows before deployment.

## 12. Controlled capacity experiment

Executed `php tests/Support/native-file-security-benchmark.php` locally with tiny synthetic valid PDFs (588–590 bytes each). The helper resets PHP peak tracking per workload and never changes production configuration. It stages only controlled synthetic data and removes its temporary namespace.

| PDFs | ZIP bytes | Expanded bytes | Largest PDF bytes | PHP peak MiB | Metadata seconds | Extraction + PDF inspection seconds | Peak temp files | Peak temp bytes |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 1 | 417 | 588 | 588 | 26 | 0.0276 | 0.0316 | 2 | 1,005 |
| 100 | 39,777 | 58,890 | 589 | 28 | 0.0114 | 0.9631 | 101 | 98,667 |
| 250 | 99,906 | 147,390 | 590 | 28 | 0.0122 | 1.6693 | 251 | 247,296 |
| 500 | 200,171 | 294,890 | 590 | 28 | 0.0145 | 3.0233 | 501 | 495,061 |
| 750 | 300,425 | 442,390 | 590 | 30 | 0.0202 | 3.7596 | 751 | 742,815 |

These are **workload experiments, not production capacity targets or SLAs**. The measured extractor processes all entries in one pass; the resumable publication service revalidates archive metadata per entry, so its timing is different. The 100-PDF DB regression passed with small PDFs; its test duration includes test/application overhead and is not a measured production commit SLA.

UNKNOWN: representative ZIP/PDF distributions; OS RSS; standalone validation/matching/commit timings; quota/inode availability; queue waiting time; mixed imports/exports concurrency; real crash cleanup. No production benchmarks, large ZIP bombs or confidential document content experiments were run.

Recommended follow-up: aggregate metadata-only size/count/expanded-size/phase-duration/error/backlog histograms with no filename, PO identifier, supplier/bank detail or file contents. Approve a separately controlled restored-staging matrix covering 1/100/250/500/750 PDFs across representative largest-entry sizes and compressibility, plus limited mixed workload concurrency, hard interruption and cleanup. Increase production ceilings only after that evidence and separate business/operations approval.

## 13. Shared-hosting readiness checklist

Observed **local CLI only**: PHP 8.2.30; Zip extension 1.21.1 / libzip 1.7.1; Fileinfo, XMLReader and mbstring enabled. This does not establish production/web-SAPI capability. Unicode normalization uses the existing Normalizer capability and must be verified on the host.

| Host requirement | Production evidence |
|---|---|
| memory_limit, max_execution_time, upload_max_filesize, post_max_size, max_file_uploads | UNKNOWN |
| Web PHP version/INI/extensions versus CLI runtime | UNKNOWN |
| ZipArchive/libzip consistency/index-stream capability, Fileinfo, XMLReader, mbstring/Normalizer | UNKNOWN on cPanel |
| Account disk quota, filesystem free bytes, available inodes, private staging/web tmp | UNKNOWN |
| Database queue available, actual retry_after, job timeout, watchdog/PCNTL behavior | Repository configuration inspected; effective production values UNKNOWN |
| Cron windows, overlapping worker/process limits, backlog/retention budget | UNKNOWN |
| Single largest entry fitting slice and final publication fitting hard timeout | UNKNOWN |
| Browser/keyboard/screen-reader behavior on deployed release | NOT EXECUTED |

Async activation requires all readiness checks and finite positive budget configuration. No new production worker/cron exists merely because its source or local smoke passed.

## 14. Remaining risks, approvals, and rollout

| Priority | Item | Classification / action |
|---|---|---|
| High | Unmeasured cPanel quota/time/memory and workload distribution | UNKNOWN; blocks production capacity approval and async enablement |
| High | PO supplier/number reassignment after attachments exist | REQUIRES APPROVAL for a permanent edit restriction; locked publication revalidation is implemented, but historical ownership semantics are unchanged |
| High | Native inspection cannot establish absence of malware | VERIFIED limitation; safe private download and domain rules reduce risk without AV claims |
| Medium | Hard-kill, blocked I/O, disk/inode exhaustion and multiple real workers | IMPLEMENTED_NOT_VERIFIED recovery paths; requires restored-staging execution |
| Medium | Non-ZIP files abandoned after a process crash | DEFERRED generic orphan sweep; ordinary exception compensation and new-file verdict denial are implemented |
| Medium | Legacy DOC/XLS active content and complete PDF semantics | Residual risk; no new antivirus engine/package proposed |
| Medium | GA resubmit actor versus document-read ownership contract | Existing business decision remains unresolved; no silent policy broadening |
| Medium | Office/parser limits and image ceilings may reject unusual legitimate files | Requires representative-document compatibility check before rollout |
| Medium | Full-suite and interactive verification gaps | Final focused Supplier Audit UI test passed; the full PHP suite and browser QA were not executed, so no repository-wide green claim |
| Low | Global whitespace failures in unrelated dirty files | VERIFIED; task-scoped check passes, no unrelated cleanup performed |

Rollout sequence requiring a separate operational approval:

1. Review this source/schema report and restore representative staging data/files safely.
2. Verify host budgets/extensions, isolated test resources, UI/accessibility and crash/cleanup scenarios.
3. Pause affected uploads, capture historical cutovers and install additive schema with matching source; retain backups and history.
4. Run smoke for each domain with async still disabled and the 50/100/100 production ceilings intact.
5. Authorize a dedicated supervised po-documents worker and measured finite queue budgets separately; only then enable async.
6. Monitor metadata-only rejections/backlog/resource/cleanup metrics. Raise limits only through a further approved capacity decision.

No production release or business-rule approval is implied by a passing local test.

## 15. Exact task file manifest

The following files contain Gate 2 changes. Several were already modified or untracked before this task; the list is **not** a claim that all their worktree changes belong to Gate 2. Existing Supplier Audit files and shared routes/AGENTS retain concurrent edits.

### Application, configuration and UI

```text
AGENTS.md
app/Providers/AppServiceProvider.php
app/Http/Controllers/AttachmentController.php
app/Http/Controllers/Auth/SupplierRegistrationController.php
app/Http/Controllers/ConversationMessageController.php
app/Http/Controllers/Finance/LocalInvoiceSettlementController.php
app/Http/Controllers/Finance/LocalProcurementController.php
app/Http/Controllers/Ga/GaController.php
app/Http/Controllers/GaClaimDocumentController.php
app/Http/Controllers/LocalInvoiceDocumentController.php
app/Http/Controllers/LocalSupplier/VendorProfileController.php
app/Http/Controllers/Qc/QcInspectionController.php
app/Http/Controllers/Supplier/ClaimController.php
app/Http/Controllers/Supplier/QuotationController.php
app/Http/Controllers/Supplier/SupplierShipmentController.php
app/Http/Controllers/SupplierMasterDocumentController.php
app/Http/Controllers/SupplierRegistrationReviewController.php
app/Http/Middleware/DecodeHashids.php
app/Http/Requests/LocalInvoice/UploadLocalPoDocumentRequest.php
app/Models/Attachment.php
app/Models/SupplierMasterDocument.php
app/Services/Employee/EmployeeExcelImportService.php
app/Services/Ga/GaClaimService.php
app/Services/LocalInvoice/InvoiceDocumentService.php
app/Services/LocalInvoice/LocalPoDocumentService.php
app/Services/LocalInvoice/LocalProcurementCsvReader.php
app/Services/LocalInvoice/LocalProcurementImportService.php
app/Services/LocalInvoice/LocalProcurementStreamingReader.php
app/Services/Payment/SupplierOverpaymentService.php
app/Services/ShipmentService.php
app/Services/SupplierRegistrationService.php
app/Services/SupplierAudit/SupplierAuditReviewService.php
app/Support/SpreadsheetImportReader.php
config/filesystems.php
routes/web.php
routes/console.php
resources/js/app.js
resources/js/file-upload.js
resources/js/async-form-submit.js
resources/views/finance/local-procurement/_upload_po_modal.blade.php
resources/views/finance/overpayments/index.blade.php
resources/views/ga/claims/create.blade.php
resources/views/ga/claims/revision.blade.php
resources/views/qc/inspections/create.blade.php
resources/views/qc/inspections/show.blade.php
resources/views/supplier/claims/show.blade.php
resources/views/supplier/quotations/create.blade.php
resources/views/supplier/shipments/show.blade.php
resources/views/local-supplier/vendor-profile/show.blade.php
app/Data/FileSecurity/FileInspectionResult.php
app/Models/FileInspection.php
app/Models/LocalPoDocumentBatch.php
app/Models/LocalPoDocumentEntry.php
app/Policies/LocalPoDocumentBatchPolicy.php
app/Services/FileSecurity/FileInspectionService.php
app/Services/FileSecurity/NativeArchiveInspector.php
app/Services/FileSecurity/OleContainerInspector.php
app/Services/FileSecurity/FileAccessGuard.php
app/Services/LocalInvoice/LocalPoDocumentBatchService.php
app/Support/BoundedCsvReader.php
app/Jobs/ProcessLocalPoDocumentBatch.php
app/Http/Controllers/Finance/LocalPoDocumentBatchController.php
app/Console/Commands/ReconcileLocalPoDocuments.php
config/native_file_security.php
config/po_documents.php
database/migrations/2026_10_09_000003_create_native_file_inspections.php
database/migrations/2026_10_09_000004_create_local_po_document_batches.php
lang/en/file_security.php
lang/id/file_security.php
lang/en/file_security_resources.php
lang/id/file_security_resources.php
lang/en/js.php
lang/id/js.php
lang/en/po_documents.php
lang/id/po_documents.php
resources/js/po-document-upload.js
```

### Tests and controlled helpers

```text
tests/Feature/GaClaimWorkflowTest.php
tests/Feature/LocalInvoiceSubmissionV2Test.php
tests/Feature/SupplierVendorProfileTest.php
tests/Feature/LocalInvoice/LocalPurchaseOrderDocumentTest.php
tests/Feature/LocalInvoice/LocalSupplierOverpaymentTransparencyTest.php
tests/Feature/PoCustomsDocumentationShipmentSyncTest.php
tests/Feature/QuotationMtcReplacementLifecycleTest.php
tests/Feature/ShipmentDocumentsAndQcIntegrationTest.php
tests/Feature/SupplierRegistration/SupplierRegistrationConcurrencyTest.php
tests/Feature/SupplierRegistration/SupplierRegistrationCredentialAccessTest.php
tests/Feature/SupplierRegistration/SupplierRegistrationReviewTest.php
tests/Feature/SupplierRegistration/SupplierRegistrationSecurityTest.php
tests/Feature/SupplierRegistration/SupplierRegistrationTest.php
tests/Feature/SupplierAudit/SupplierAuditReviewTest.php
tests/Support/NativeFileFixtures.php
tests/Unit/NativeFileSecurityTest.php
tests/Unit/SpreadsheetNativeAdmissionTest.php
tests/Unit/BoundedCsvReaderTest.php
tests/Feature/DomainNativeFileSecurityTest.php
tests/Feature/FileAccessGuardTest.php
tests/Feature/LocalInvoice/LocalPoDocumentSecurityTest.php
tests/Feature/LocalInvoice/LocalPoZipStorageMessageTest.php
tests/Feature/LocalInvoice/LocalPoDocumentBatchTest.php
tests/js/po-document-upload.test.mjs
tests/js/file-upload-size.test.mjs
tests/js/async-form-submit.test.mjs
tests/Support/native-file-security-benchmark.php
tests/Support/native-file-security-worker-smoke.php
```

### Documentation

```text
docs/guides/NATIVE-FILE-SECURITY.md
docs/results/GATE2-NATIVE-FILE-SECURITY-AND-BULK-PO-ZIP-IMPLEMENTATION.md
```

New services/models/jobs/policy/command, paired locale files and the two additive migrations are explicitly identified in sections 3–8 and this manifest. Controlled helpers create only their own test/temporary resources; no production migrations were executed.

## 16. Final status

- **IMPLEMENTED_AND_VERIFIED:** native format foundation, fail-closed/historical access regression, bounded PO extraction, baseline-count 100-PDF DB publication, ownership revalidation, atomic attachment/audit rollback, duplicate-delivery protections, local database queue smoke, selected recovery/cleanup simulations, domain integrations with focused regression, paired feedback and scoped lint/build checks.
- **IMPLEMENTED_NOT_VERIFIED:** cPanel runtime, hard-interruption recovery under real worker kill, quota/inode/short-write fault behavior, realistic capacity/concurrency and interactive browser/accessibility workflows.
- **BLOCKED:** production readiness, asynchronous production admission, higher production ZIP ceilings and permanent PO reassignment policy pending business approval. Repository-wide verification remains incomplete because the full suite and interactive checks were not executed.
- **DEFERRED:** optional future actual antivirus adapter, generic non-ZIP crash-orphan sweep and separately approved representative production/staging capacity research.

Gate 2 source implementation is delivered for review with explicit verification gaps. **Production readiness is not claimed.** Existing dirty work was preserved; nothing was staged, committed or pushed.

## 17. Follow-up: explicit ZIP storage-limit errors

The user requested a clear error when a ZIP exceeds storage capacity. EN/ID messages now distinguish decompressed PDF byte limits and working/available storage limits from a busy queue. The existing maximum upload-size message remains unchanged. No configured limits, ownership rules, concurrency guards, cleanup or publication boundaries changed.

Admission returns a localized field validation error for synchronous working-space/free-space failures and asynchronous reserved-storage overflow. Processing preserves the safe `storage_limit` failure code; authorized status polling renders its message in the requesting user's locale rather than replacing it with a generic rejected status. Only explicitly recognized storage messages are mapped to that code; internal exception text and filesystem paths are not exposed. The PO live status prioritizes the file validation error over a generic response summary and keeps the selected browser file.

Changed paths in this follow-up:

```text
app/Services/FileSecurity/NativeArchiveInspector.php
app/Services/LocalInvoice/LocalPoDocumentBatchService.php
app/Http/Controllers/Finance/LocalPoDocumentBatchController.php
lang/en/file_security.php
lang/id/file_security.php
lang/en/po_documents.php
lang/id/po_documents.php
resources/js/po-document-upload.js
tests/js/po-document-upload.test.mjs
tests/Feature/LocalInvoice/LocalPoZipStorageMessageTest.php
docs/results/GATE2-NATIVE-FILE-SECURITY-AND-BULK-PO-ZIP-IMPLEMENTATION.md
```

Verification: the new feature tests first reproduced generic capacity errors and loss of the worker storage failure reason, while the new JS test reproduced a generic summary overriding the specific file error. Fixture setup was corrected to supply existing user-preference defaults and globally unique PO numbers; business constraints were not weakened.

Final serial command on the verified isolated `adasi_portal_test` connection:

```powershell
php artisan test tests/Feature/TestingEnvironmentDatabaseSafetyTest.php tests/Feature/LocalInvoice/LocalPoZipStorageMessageTest.php tests/Feature/LocalInvoice/LocalPoDocumentSecurityTest.php tests/Feature/LocalInvoice/LocalPoDocumentBatchTest.php tests/Feature/LocalInvoice/LocalPurchaseOrderDocumentTest.php tests/Unit/NativeFileSecurityTest.php tests/Unit/TranslationParityTest.php --compact --do-not-cache-result
```

**PASS: 54 tests / 37,913 assertions, 44.49 seconds.** Includes six storage-message tests, Finance/Purchasing and EN/ID field errors, normal-form session errors, async budget rejection before dispatch, storage failure during processing/polling in both locales, busy-queue message preservation, existing atomicity/security regressions and translation parity. Small controlled budget/reserve overrides simulate insufficient storage without filling a real disk.

Also PASS: five JS tests, JavaScript syntax, PHP syntax and scoped Pint for eight PHP files, and `npm.cmd run build` (25 modules, 7.99 seconds). Independent read-only source review found no actionable issues. Scoped diff/whitespace checks passed. Browser/screen-reader and actual cPanel disk-quota tests remain NOT EXECUTED. Async remains disabled, production readiness remains unverified, and no migration, staging, commit or push was performed in this follow-up.

## 18. Follow-up: oversized ZIP rejected without visible feedback

The user reported selecting/uploading a ZIP above 70 MB with no visible reaction. Source inspection and failing JS regressions confirmed a concrete frontend defect: the single-file size check assigned `clientError`, then called `clearAll()`, which erased the error. Reordering those operations preserves the localized error while retaining the existing rejection and clearing behavior. Choosing a valid replacement clears the error; the 50 MiB boundary remains unchanged.

The existing async form handler now handles HTTP 413 explicitly, including HTML responses from PHP/web-server body limits. It restores loader/buttons and uses the existing localized, accessible file-error/summary flow without removing the selected file. This does not change PHP/server limits or assert that the user's observed request actually returned 413.

Changed paths: `resources/js/file-upload.js`, `resources/js/async-form-submit.js`, `lang/en/js.php`, `lang/id/js.php`, `tests/js/file-upload-size.test.mjs`, `tests/js/async-form-submit.test.mjs`, `tests/Feature/LocalInvoice/LocalPoZipStorageMessageTest.php`, and this report.

Executed verification:

```powershell
node --test --test-isolation=none tests/js/file-upload-size.test.mjs tests/js/async-form-submit.test.mjs tests/js/po-document-upload.test.mjs
php artisan test tests/Feature/TestingEnvironmentDatabaseSafetyTest.php tests/Feature/LocalInvoice/LocalPoZipStorageMessageTest.php tests/Feature/LocalInvoice/LocalPurchaseOrderDocumentTest.php tests/Unit/TranslationParityTest.php --compact --do-not-cache-result
```

**PASS: 9 JS tests; 23 PHP tests / 37,801 assertions (18.84 seconds).** Oversized selection/drop and HTML 413 were first reproduced as failing tests. Tests use size metadata for the 70 MiB scenario, avoiding a large allocation or upload benchmark. Backend rejection was verified in EN/ID against the actual isolated `adasi_portal_test` connection. JS/PHP syntax, scoped Pint, scoped diff checks and `npm.cmd run build` also passed (25 modules, 3.16 seconds). Independent source review found no actionable issues.

Manual browser/screen-reader confirmation remains NOT EXECUTED. Reload with Ctrl+F5 before repeating the user's selection test so the rebuilt bundle is used. No production configuration, upload ceiling, async enablement, migration, staging, commit or push changed.
