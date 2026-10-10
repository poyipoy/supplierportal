# Approved local procurement import plan

User approved 70,000 rows per XLSX file and background processing for all sizes on 8 October 2026.

1. Use PHP 8.2-compatible OpenSpout 4.28.5 in a single streaming pass, preserve Infor positional mapping, formulas, dates and physical row numbers.
2. Create additive import state/source/consolidated staging tables. Store uploads privately; replace full session/JSON datasets with owned tokens and pagination.
3. Preserve preview-before-confirm, whole-file atomicity, create/add-only behavior, UOM whitelist/three-decimal quantity, existing model/audit snapshots and domain authorization.
4. Validate global groups across batches, batch lookups on existing indexes, lock current parent/duplicate records and bulk-write 500 records/audits within one transaction.
5. Use a dedicated database imports queue, scalar job payloads, retry fencing, preview expiry, private retention cleanup and bilingual progress UI.
6. Test 2,001/20,000/70,000 acceptance and 70,001 rejection, domain failures, rollback, retries, ownership, formula safety, date calendars, shared/rich strings and bounded rendering.
7. Benchmark against at least 500,000 existing GRs. Gates: 20k <=3 minutes; 70k <=10 minutes; process peak <=384 MiB; payload <10 KiB; commit <=60 seconds. Exclude upload/queue wait from processing timings.
8. Run serial MySQL regressions, PHP lint, scoped Pint, translations, JS/build, route/view checks and diff review. Prepare one imports worker alongside exports/default. Preserve unrelated work and leave changes unstaged.

Implementation/deployment details: [guide](../guides/LOCAL-PROCUREMENT-IMPORTS.md). Results: [report](../results/LOCAL-PROCUREMENT-LARGE-IMPORT-RESULT-20261008.md).
