# Local PO/GR background imports

Operational PO and GR imports use `LocalProcurementImportService`: OpenSpout 4.28.5 for XLSX, streaming `fgetcsv` for CSV, and the installed PhpSpreadsheet XLS reader with 2,000-row read filters for BIFF XLS. Existing XLSX template downloads and other import domains are unchanged. Import PO + GR and its six routes/template/parser/service/UI have been removed. Upload PO first, then GR referencing registered POs. The old separate collection preview parsers remain for compatibility tests; do not use them for large operational imports.

## Limits and workflow

- XLSX/CSV/XLS, at most 50 MiB. XLSX/CSV support 70,000 meaningful data rows; binary XLS supports 65,535 data rows plus a header. Formatting and empty rows do not count. XLSX ZIP expansion remains bounded at 512 MiB/1,000 entries. Reject corrupt/encrypted files and renamed HTML/ZIP files presented as XLS.
- CSV keeps the Infor positions, including empty columns. Comma/semicolon/tab are detected from up to 20 logical records; ambiguous delimiters fail. UTF-8 BOM/no-BOM and UTF-16LE/BE BOM are supported, with Windows-1252 fallback warning. Quoted multiline cells count as one CSV record for source-row labels. Numbers use decimal points without thousands separators; no ambiguous regional conversion is performed.
- First worksheet only, with additional-sheet/header warnings. Infor GR unit remains column Q/index 16, whose sample heading is blank.
- Files are private polymorphic attachments of `LocalProcurementImport`. Queued job payloads contain IDs, stage and a run key, never worksheets/callbacks or spreadsheet rows.
- Sources retain their validated extension and MIME; workers inspect content again. XLS formulas are read as formula text and rejected, never calculated or executed. Date-calendar state is restored after each read window.
- Preview and confirm POST endpoints retain their existing URIs but acknowledge asynchronously with HTTP 202 and `status_url`. The frontend must reload after deployment; old session preview tokens must be replaced with a new preview.
- Preview requires explicit confirmation. Its 40-character token is owned, kind-bound and expires after 24 hours. JSON status/pagination is owner-only and private/no-store; raw integer URLs are rejected.
- Two active imports per operator. Preview/errors show at most 100 source/document groups per page. Each opening of the PO/GR modal starts fresh: no history dropdown or previous batch is loaded, and file selection, preview, errors and confirmation state are cleared. Closing the modal stops its polling and discards its UI state without cancelling or deleting backend batches; existing processing and retention rules still apply. Upload a file and use Validate, then explicitly Confirm before closing. Cancel is available before confirmation; use Reload register after completion.
- All rows are staged before global PO/GR conflict checks. GR groups spanning batches preserve ordered descriptions and exact quantity; mixed units fail without a meaningful consolidated quantity.
- Confirm rechecks current authorization, eligible suppliers, parent state and duplicates under row locks. Master and per-record audits commit atomically with COMPLETED. No upsert/ignore is used on financial masters. Existing invoice snapshots are untouched.
- Only PO/GR kinds can be started/confirmed. Unsupported historical kinds remain owner-readable without token/confirm URL. Queued unsupported kinds are cancelled by a generic guard; completed master/audit history remains. Cleanup retires unsupported active previews with normal retention, without deleting business records.

## Deployment

The formats/removal revision adds no package or schema migration. Existing staging migration must already be applied; inspect its ledger/schema before applying it on a new installation:

```powershell
php artisan migrate --path=database/migrations/2026_10_08_000001_create_local_procurement_import_staging.php
```

Imports require the same database queue connection as their state, with `after_commit=false`, and `DB_QUEUE_RETRY_AFTER=660`. Keep the existing `exports,default` worker. Add **one separate** imports worker:

```text
php artisan queue:work database --queue=imports --sleep=1 --tries=3 --timeout=300 --memory=384
```

On cPanel, use a separate `flock` file and log, never the export worker lock:

```cron
* * * * * /usr/bin/flock -n <APP>/storage/framework/import-worker.lock <PHP> <APP>/artisan queue:work database --queue=imports --sleep=1 --max-time=50 --tries=3 --timeout=300 --memory=384 >> <APP>/storage/logs/import-worker.log 2>&1
```

`--max-time` is checked between jobs; it does not interrupt an active import. Keep the scheduler running. `imports:cleanup` runs at 02:30 business time: it expires unused previews, recovers abandoned reading attempts, and removes terminal staging/uploads after three days while retaining summary/audit history. Never delete an active import or manually copy queue payloads. Fencing prevents older deliveries from publishing/failing a newer live attempt.

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
