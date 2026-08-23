# PERF-01 — Measurement & Instrumentation

## Status

`COMPLETE — LOCAL READ-ONLY BASELINE ESTABLISHED`

## Measurement Method

`php artisan performance:profile-critical` profiles representative controller/view and DataTables AJAX paths in a fresh CLI process. It authenticates an existing role fixture in-process, records Laravel query-log count and cumulative duration, wall time, PHP peak memory, and response bytes, and does not dispatch exports or write application data.

This is a repeatable application-path benchmark, not an HTTP load test. It excludes web-server transfer, middleware/session I/O, external CDN time, and browser rendering. Cold-cache and warm-cache runs must be recorded separately.

## Local Environment and Dataset

- PHP 8.2.30; Laravel 12.66.0; MySQL 8.0.30.
- Local drivers: file cache, file session, database queue, Pusher broadcasting.
- 2,067 purchase requisitions; 4,343 PR items; 2,132 quotations; 4,351 quotation items; 2,052 purchase orders.
- Only 26 QC inspections, 11 claims, 17 conversations, and 59 messages. QC, claims, and chat are not representative high-volume datasets.

## Warm Baseline

| Path | Queries | SQL ms | Response ms | Peak MB | Bytes |
|---|---:|---:|---:|---:|---:|
| Purchasing dashboard | 9 | 5.63 | 184.90 | 40 | 460,828 |
| Supplier dashboard | 12 | 6.11 | 121.39 | 42 | 454,733 |
| PR DataTable | 5 | 37.03 | 122.73 | 44 | 262,636 |
| PO DataTable | 12 | 6.46 | 86.59 | 44 | 221,735 |
| Inter-supplier comparison | 43 | 20.26 | 128.38 | 46 | 429,804 |
| Historical comparison | 14 | 36.27 | 156.15 | 46 | 513,860 |
| vs-best DataTable | 61 | 425.85 | 445.99 | 46 | 110,487 |
| Purchasing quotation list | 10 | 6.72 | 156.34 | 46 | 509,157 |
| QC history DataTable | 5 | 2.02 | 21.58 | 46 | 40,581 |
| Purchasing claim history | 5 | 1.62 | 13.40 | 46 | 47,704 |
| Notification counts | 2 | 1.29 | 5.45 | 46 | 192 |
| Notification detail summary | 2 | 0.93 | 13.03 | 46 | 50,607 |
| Chat unread count | 1 | 0.56 | 2.15 | 46 | 11 |

## Baseline Findings

- `VERIFIED BOTTLENECK`: inter-supplier comparison executes one lazy `items` query per eligible PR.
- `VERIFIED BOTTLENECK`: vs-best executes three expensive derived-query variants and per-row model lookups used only to build Hashid links.
- `VERIFIED BOTTLENECK`: every authenticated full-page render loads notification detail and hydrates conversations for the initial chat count, then the browser requests both count endpoints again.
- `OPTIMIZATION OPPORTUNITY`: PR DataTable hydrates `items` only to count them.
- `OPTIMIZATION OPPORTUNITY`: PO DataTable hydrates a large nested relationship graph and calculates list totals in PHP.
- `LIKELY BOTTLENECK — MEASUREMENT REQUIRED`: the chat drawer calls an unread-count query for each of up to 50 conversations.

## Dataset Safety

`StressTestSeeder` is not safe or repeatable on an existing database: it manually assigns IDs, uses fixed document numbers, has no transaction/cleanup/idempotency, references a non-column `total_weight` on a raw row, and leaves major modules unrepresented. `ProductionDummySeeder` deletes operational data. Neither was run.

Any future representative seeding or load test must use a disposable database such as `adasi_portal_perf`, resolve fixtures by stable business keys, and verify non-empty response shapes.

## Not Measured

- Core Web Vitals, browser waterfall, and interaction timing: `NOT MEASURED — ENVIRONMENT REQUIRED`.
- Production database cache/session overhead: `NOT MEASURED — ENVIRONMENT REQUIRED`.
- Production cPanel CPU, entry processes, I/O, memory, MySQL connections, OPcache, queue Cron, compression, and HTTP/2: `NOT MEASURED — ENVIRONMENT REQUIRED`.
- Destructive or concurrent stress testing: not run.

## Verification

- `php -l app/Console/Commands/ProfileCriticalPaths.php` — passed.
- `php artisan performance:profile-critical` — passed against the existing local database.
- No seeder, export dispatch, migration, queue worker, or load test was executed.
