# Local PO/GR background imports

Operational PO and GR imports use `LocalProcurementImportService`: OpenSpout 4.28.5 for XLSX, streaming `fgetcsv` for CSV, and the installed PhpSpreadsheet XLS reader with 2,000-row read filters for BIFF XLS. Existing XLSX template downloads and other import domains are unchanged. Import PO + GR and its six routes/template/parser/service/UI have been removed. Upload PO first, then GR referencing registered POs. The old separate collection preview parsers remain for compatibility tests; do not use them for large operational imports.

## Limits and workflow

### Optional PR reference on local PO

PO files may append column Q, titled `PR`, after the existing A–P Infor columns. Each row contains at most one PR number; one PO has at most one PR, while the same PR may appear on several POs. XLSX/CSV/XLS files without Q remain accepted. Keep PR cells as text to preserve leading zeros; numeric conversion cannot recover zeros already lost in Excel.

Repeated PO rows must keep supplier, date, and total consistent. Empty PR cells can merge with one filled value; case-equivalent trimmed references are identical. Different references for the same PO reject the whole file. PR never causes amounts to be summed or changes invoice/GR/payment eligibility.

An existing matching PO with an empty PR may be filled during explicit Save. A different registered PR is rejected; blank imports never erase it. Preview uses `FILL_PR` and `po_pr_filled`, while completed summaries use the actual `filledPoPr` count. PR-only changes are actionable; entirely identical files remain no-ops. Parent locks, parameterized metadata-only writes, and audits share the existing whole-file transaction.

Deploy `2026_10_14_000001_add_pr_number_to_local_purchase_orders.php` before updated web/worker code, after reviewing the ledger and backing up the target database. This migration is additive, nullable and has a non-unique index; no backfill is performed. Its rollback refuses to remove populated references. Coordinate draining/restarting the imports worker and deploy the complete build with PHP/views/translations. Applying migrations to application/production databases requires separate authorization. Legacy staged previews without PR continue to work.

Master and supplier PO lists/details, invoice options and PO search expose only the PR on each visible PO. Supplier searches remain owner/status/GR-scoped; no cross-supplier PR catalogue exists. PR reassignment, Import Core PR linkage and financial-document PR snapshots remain outside this revision.

Manual PO creation and editing a PO with an empty PR use the opt-in editable mode of `x-ui.searchable-select`. Operators can choose an existing number or type a new one. Authorized Finance/Admin/Purchasing suggestions return only up to 20 case-insensitive unique PR numbers across suppliers after two characters, with 300 ms debounce; network failure never disables manual typing. Existing PR values remain readonly and are checked again under the PO lock. Omitted fields preserve them; replacement and erasure are rejected. Filling an empty PR records `po_pr_filled` in the same transaction as the PO edit. The endpoints are `finance.local-procurement.pr-suggestions` and `purchasing.local-procurement.pr-suggestions`; there is no supplier suggestion endpoint.

- XLSX/CSV/XLS, at most 50 MiB. XLSX/CSV support 70,000 meaningful data rows; binary XLS supports 65,535 data rows plus a header. Formatting and empty rows do not count. XLSX ZIP expansion remains bounded at 512 MiB/1,000 entries. Reject corrupt/encrypted files and renamed HTML/ZIP files presented as XLS.
- CSV keeps the Infor positions, including empty columns. Comma/semicolon/tab are detected from up to 20 logical records; ambiguous delimiters fail. UTF-8 BOM/no-BOM and UTF-16LE/BE BOM are supported, with Windows-1252 fallback warning. Quoted multiline cells count as one CSV record for source-row labels. Numbers use decimal points without thousands separators; no ambiguous regional conversion is performed.
- First worksheet only, with additional-sheet/header warnings. Infor GR unit remains column Q/index 16, whose sample heading is blank.
- Files are private polymorphic attachments of `LocalProcurementImport`. Queued job payloads contain IDs, stage and a run key, never worksheets/callbacks or spreadsheet rows.
- Sources retain their validated extension and MIME; workers inspect content again. XLS formulas are read as formula text and rejected, never calculated or executed. Date-calendar state is restored after each read window.
- Preview and confirm POST endpoints retain their existing URIs but acknowledge asynchronously with HTTP 202 and `status_url`. The frontend must reload after deployment; old session preview tokens must be replaced with a new preview.
- Preview requires explicit confirmation. Its 40-character token is owned, kind-bound and expires after 24 hours. JSON status/pagination is owner-only and private/no-store; raw integer URLs are rejected.
- Two active imports per operator, shared across PO and GR. Preview/errors show at most 100 source/document groups per page. Each opening clears file selection, preview, errors and confirmation state. A separate Unfinished imports panel lists the current user's active PO/GR imports through `imports.index?active=1`, excluding expired queued/ready previews. It never automatically selects or confirms a previous import. Continue explicitly restores the chosen preview/token; a PO/GR link switches to the matching modal. Cancel releases the chosen slot through the existing owner-only POST endpoint; IMPORTING cannot be cancelled. Refresh imports retrieves current state. Closing a modal stops its polling and discards its UI state without cancelling backend work. READY keeps a slot until confirmed/completed, cancelled, or expired.
- All rows are staged before global PO/GR conflict checks. GR groups spanning batches preserve ordered descriptions and exact quantity; mixed units fail without a meaningful consolidated quantity.
- Confirm rechecks current authorization, eligible suppliers, parent state and duplicates under row locks. Master and per-record audits commit atomically with COMPLETED. No upsert/ignore is used on financial masters. Existing invoice snapshots are untouched.
- Identical registered PO/GR records are skipped; conflicting values block the entire file. A preview with no new records becomes VALIDATION_FAILED with its existing counters and details retained, releasing its active slot. Legacy READY previews with zero new records receive HTTP 422 before confirm dispatch; incomplete new-record counters receive HTTP 409. If all records become existing after preview, the worker rejects the no-op transaction instead of publishing COMPLETED. Mixed imports create only new records, and completed counters/messages report the actual created and skipped totals.
- Only PO/GR kinds can be started/confirmed. Unsupported historical kinds remain owner-readable without token/confirm URL. Queued unsupported kinds are cancelled by a generic guard; completed master/audit history remains. Cleanup retires unsupported active previews with normal retention, without deleting business records.

## Deployment

The formats/removal revision adds no package or schema migration. Existing staging migration must already be applied; inspect its ledger/schema before applying it on a new installation:

```powershell
php artisan migrate --path=database/migrations/2026_10_08_000001_create_local_procurement_import_staging.php
```

Imports require the same database queue connection as their state, with `after_commit=false`, and `DB_QUEUE_RETRY_AFTER=660`. Keep the `exports` and `default` workers described in [CPANEL-PERFORMANCE-RUNTIME.md](CPANEL-PERFORMANCE-RUNTIME.md) §5, and add **one separate** imports worker (never with `--stop-when-empty`):

```text
php artisan queue:work database --queue=imports --sleep=1 --tries=3 --timeout=300 --memory=384
```

On cPanel, use a separate `flock` file and log, never the export worker lock:

```cron
* * * * * /usr/bin/flock -n <APP>/storage/framework/import-worker.lock <PHP> <APP>/artisan queue:work database --queue=imports --sleep=1 --max-time=50 --tries=3 --timeout=300 --memory=384 >> <APP>/storage/logs/import-worker.log 2>&1
```

`--max-time` is checked between jobs; it does not interrupt an active import.

Confirm holds its row locks for the whole file transaction (measured locally: about 20–30 s for 70,000 rows). While it runs, a supplier who submits an invoice on a PO in a GR file, or whose registered PO appears in a PO file, waits until the import commits. A PO import takes the supplier `users` locks before the PO locks, the same order invoice submission uses, so the two wait for each other instead of deadlocking. Run imports of tens of thousands of rows outside the hours when suppliers submit invoices. If confirm exceeds the 60 s budget, it is rolled back, marked FAILED once (`large_import.budget_exceeded`) and not retried; recheck the stored file and save again. Keep the scheduler running. `imports:cleanup` runs at 02:30 business time: it expires unused previews, recovers abandoned reading attempts, and removes terminal staging/uploads after three days while retaining summary/audit history. Never delete an active import or manually copy queue payloads. Fencing prevents older deliveries from publishing/failing a newer live attempt.

For this revision, pause only import admission via the internal cache key `local_procurement_imports.paused=true` while deploying. Existing preview/confirm jobs continue draining. Wait for active processing to finish, run `imports:cleanup` to retire unsupported previews, gracefully reload existing queue workers through their current supervision, rebuild assets/views, then forget the pause key. The key affects start/confirm only; other application modules remain available. Do not kill a busy worker or start a duplicate.

Verify web-SAPI `upload_max_filesize` >=50 MiB and `post_max_size`/proxy body limits above the multipart payload. Inspect PHP extensions DOM, fileinfo, filter, libxml, XMLReader and ZIP. Queue code deployment needs a graceful restart after current jobs finish. Windows lacks PCNTL here; enforce an external watchdog in deployment rather than relying on `--timeout` alone.

## Verification and monitoring

Run database safety first, MySQL suites serially on `adasi_portal_test`. Do not benchmark production. The isolated helper verifies its database before creating fixtures and removes only its own rows/files:

```powershell
php artisan test --filter=TestingEnvironmentDatabaseSafetyTest
php artisan test tests/Feature/LocalInvoice --compact --do-not-cache-result
php artisan test tests/Unit/LocalProcurementStreamingReaderTest.php --compact --do-not-cache-result
php tests/Support/local-procurement-import-benchmark.php GR 70000 500000
php tests/Support/local-procurement-import-benchmark.php PO 70000 500000
php tests/Support/local-procurement-import-benchmark.php GR 70000 0 60 new csv
php tests/Support/local-procurement-import-benchmark.php PO 65535 0 60 new xls
```

Arguments are kind, row count, existing GR count, transaction budget, scenario (`new`/`existing`), and format (`xlsx`/`csv`/`xls`). The fourth argument overrides the **test profiling** transaction budget only; never count a run above 60 seconds as an acceptance pass. Large XLS fixtures are generated by an isolated writer subprocess; its larger fixture-only heap is not the import worker's memory. The worker retains application limits. Measure process RSS/working set as well as PHP peak, preview/confirm duration and terminal retry deliveries, SQL count/time, queue age, failures, lock contention and disk quota. Targets: <=3 minutes at 20k, <=10 minutes at 70k excluding upload/queue wait, <=384 MiB process peak, <10 KiB payload, <=60 second master transaction. Never raise limits or introduce partial commits to hide failure.

Reserve exclusive use of the test schema while running these commands. If another task uses the shared test database, the benchmark also permits `LOCAL_IMPORT_BENCH_DB=adasi_import_formats_test`; prepare that isolated schema first and keep its tests serial. All other database names are rejected. Tests whose safety guard explicitly requires `adasi_portal_test` must run separately on that reserved database; do not weaken those guards to use another schema.

Benchmarks are local synthetic capacity evidence, not staging/production SLAs. Recheck realistic shared-string files, hosting limits, multi-operator contention and browser behavior on restored staging before release. Existing dependency advisories are tracked separately from this new reader dependency.

For rollback, stop admission, drain the imports queue, finish/cancel pending previews and remove retained staging/attachments through the retention workflow before removing the import code/schema. Do not roll back unrelated migrations or delete master/audit data.


## Live progress and GR detail pagination (12 October 2026)

The import modal polls `imports.status?progress_only=1` every three seconds while visible. This read-only response avoids preview/error pagination queries. A full response is fetched when review or a terminal outcome is reached. The active-import list reports the same advisory progress. Browser tab visibility pauses display polling; the existing background completion watcher continues to protect work after a modal is closed.

QUEUED and reading/validation without a known total use an indeterminate bar. Confirm dispatch starts IMPORTING, but the worker may still be waiting to execute. WAITING_SAVE distinguishes that state when telemetry is available. SAVING uses processed consolidated PO/GR record groups, including checked existing groups, rather than source worksheet rows. After the final batch, FINALIZING remains indeterminate until the transaction commits. COMPLETED is the only source of success/100%; rolled-back writes never become a successful import. Retry changes the execution identity and starts its counter at zero.

Advisory snapshots use the existing Laravel **file** cache store, even when the default application cache is database. Keys are bound to import and owner job key, TTL is one hour, terminal processing forgets the current snapshot, and existing import cleanup also forgets retained terminal snapshots. Missing/expired telemetry falls back to unknown progress. A snapshot unchanged for 60 seconds shows a last-update explanation; it does not mark the import failed or assert that the worker is alive. Cache/log failures cannot abort domain writes. No new package, migration, queue, cron, environment variable, or Pusher configuration is needed.

This configuration assumes one cPanel host: PHP web requests and the imports worker must use the same application release and file-cache path, with read/write permissions for both processes. Do not use world-writable permissions. If hosting moves to multiple nodes, this local file telemetry requires a deliberately shared store outside the financial transaction before use.

Deploy all changed PHP/views/translations and the entire `public/build/` directory together. Drain currently running imports before switching code, then gracefully restart the imports worker through the existing operational mechanism so new jobs load the new code. Preserve the scheduler and dedicated imports worker. This revision does not authorize running deployment, migration, cleanup, or queue restart against production automatically.

The GR list in Local PO details now has a role-protected `goods-receipts.data` GET feed for Finance, Purchasing and Supplier. DataTables pages/filter/order/search in SQL; page sizes are 10/25/50/100, numbering starts at the server offset plus one, and ordering includes an ID tie-breaker. Supplier feeds exclude cancelled GR and do not return edit metadata/actions. Totals remain for the full PO. The initial Blade response renders at most 25 GR as a bounded fallback; subsequent pages require JavaScript/DataTables. Operator edit/cancel forms retain their existing state/authorization/CSRF rules.

Browser QA fixtures can be regenerated serially on the test database:

```powershell
$env:LOCAL_IMPORT_UI_FIXTURES='1'
php artisan test tests/Feature/LocalInvoice/LocalImportUiFixtureTest.php --compact --do-not-cache-result
Remove-Item Env:LOCAL_IMPORT_UI_FIXTURES
node tests/Support/local-import-browser-check.mjs
```

The browser checker uses a temporary Chrome/Edge headless profile, real test-rendered Blade and built assets, and controlled read-only feeds. It verifies the pinned DataTables core SHA-384 before serving it to the test browser. Screenshots/results are under `storage/framework/testing/import-ui/browser/`. HTTP authorization, persistence, and live worker transaction visibility are verified separately by `LocalPoGrTableTest` and `LocalImportProgressTest`; browser fixtures are not production or end-to-end deployment evidence.

## Recheck a stored import file

The PO/GR modal shows In progress and Needs your attention. Failed validation remains discoverable while its original preview has not expired. Failed files do not consume an active slot until rechecked; READY files do consume one. Update status reads server state only. Closing the modal does not cancel an import.

After fixing or restoring referenced master data, open the original file and choose Recheck stored file. This queues a new review of the private uploaded copy without uploading again. If the spreadsheet on the computer changed, choose another file instead. Expiry remains 24 hours from upload; rechecks never extend it. Cancelled/completed/expired/cleaned imports cannot be rechecked. Missing or invalid stored files require a new upload.

Revalidation uses POST `finance.local-procurement.imports.revalidate` or the Purchasing equivalent, with CSRF, ownership/policy, throttle and `expected_attempt`. It checks eligibility, available capacity, stored-file readability/integrity and expiry, then atomically changes the import and inserts its preview job into the same database queue. A new generation invalidates old confirmation tokens. Existing legacy tokens remain valid until the first revalidation; never copy tokens into deployment logs or tickets. Master PO/GR records remain untouched until explicit Save; commit rechecks live domain state and rolls the entire file back on conflict.

Messages separate queue/processing failures from input errors, and error links use the operator's route domain. Completed imports keep their saved-count summary visible until View import results is chosen. Browser visibility and three-second light polling remain unchanged. Polling failures do not prove that a worker stopped.

Deploy PHP/routes/views/EN-ID/build together, drain running imports and use the existing worker restart process; no new migrations, packages, cron entries or environment fields are required. Verify a new production upload, missing-reference correction, recheck and save after deployment. Local fixtures alone do not prove production operation.

For focused local UI QA after regenerating fixtures, use `node tests/Support/local-import-ux-browser-check.mjs`. It uses pinned official CDN assets and controlled HTTP feeds; persistence and race tests are separate. Results/screenshots are under `storage/framework/testing/import-ui/ux-browser/`.
