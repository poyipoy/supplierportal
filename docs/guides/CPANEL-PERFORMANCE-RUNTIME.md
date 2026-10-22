# cPanel Performance and Runtime Runbook

This runbook is the canonical operational guide for deploying the Supplier Portal to a single-node cPanel account. It assumes Laravel 12, PHP 8.2 or newer, MySQL/MariaDB, the database cache/session/queue drivers, and Pusher Channels with polling fallback. It does not require Redis, Supervisor, systemd, Reverb, containers, or a permanent queue daemon.

## 1. Values that must be discovered in cPanel

Do not copy a PHP binary or account path from another server. Record these values from **cPanel > Terminal**, **Cron Jobs**, **MultiPHP Manager/PHP Selector**, and the domain configuration:

```text
APPLICATION_PATH=/absolute/path/to/application
PUBLIC_DOCUMENT_ROOT=/absolute/path/to/application/public
PHP_BINARY=/absolute/path/to/the-domain-php-binary
FLOCK_BINARY=/absolute/path/to/flock     # optional; confirm it exists first
PORTAL_URL=https://portal.example.com
```

The domain document root must be the application's `public` directory. `storage`, `.env`, source files, and private attachments must not be web-accessible.

Verify that the CLI selected for Cron is the same PHP major/minor and has the extensions required by the domain:

```sh
<PHP_BINARY> -v
<PHP_BINARY> -m
<PHP_BINARY> artisan about
```

The CLI result does not prove the PHP-FPM/LiteSpeed web SAPI uses identical settings. Confirm web-SAPI values through cPanel's PHP Info/PHP Selector rather than leaving a public `phpinfo()` file behind.

## 2. Production environment baseline

Start from `.env.example`, replace every `CHANGE_ME` value, and keep the deployed `.env` outside version control. The relevant performance/runtime baseline is:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://portal.example.com

CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database
# Leave DB_QUEUE_CONNECTION unset, or set it to the same connection used by export_jobs.
DB_QUEUE_RETRY_AFTER=660
QUEUE_FAILED_DRIVER=database-uuids

BROADCAST_CONNECTION=pusher
PUSHER_APP_ID=<from-pusher>
PUSHER_APP_KEY=<from-pusher>
PUSHER_APP_SECRET=<from-pusher>
PUSHER_APP_CLUSTER=<from-pusher>
PUSHER_HOST=
PUSHER_PORT=443
PUSHER_SCHEME=https
```

Pusher is the active realtime enhancement. Notification and chat unread counts still poll every 30 seconds when Pusher is unavailable. Do not start or expose a Reverb server for this deployment.

Database cache/session/queue are the portable shared-hosting baseline. Redis may be evaluated later only if the hosting provider offers a low-latency managed/local service and measurements justify the migration. Do not move cache, session, and queue drivers simultaneously.

The export launcher uses a same-connection transaction so each export state transition and database-queue root job commit or roll back together. `DB_QUEUE_CONNECTION` must therefore resolve to the same Laravel connection used by `export_jobs`, and the database queue must retain `after_commit=false`. A separate queue database or external queue driver requires an outbox/idempotency redesign before migration; do not remove the runtime guard merely to make deployment appear successful.

Keep `TRUSTED_PROXIES` empty when cPanel terminates HTTPS directly. The current bootstrap reads this value directly from the environment instead of a configuration file. Because direct `env()` access is not a reliable `.env` source after `config:cache`, do not enable proxy trust through `.env` while configuration is cached until that implementation is moved behind project configuration. Never use a wildcard trusted-proxy value.

## 3. Deployment and Laravel caches

Deploy a matching set of application source, `public/build/manifest.json`, and every referenced file under `public/build/assets`. A manifest from one build with assets from another causes production 404s.

Run from the application directory with the verified PHP binary:

```sh
composer install --no-dev --optimize-autoloader
<PHP_BINARY> artisan optimize:clear
<PHP_BINARY> artisan migrate --force
<PHP_BINARY> artisan config:cache
<PHP_BINARY> artisan route:cache
<PHP_BINARY> artisan view:cache
<PHP_BINARY> artisan icons:cache
<PHP_BINARY> artisan queue:restart
```

Run `migrate --force` only after confirming the active database and backup, and only when the release contains approved migrations. It is intentionally not a database preflight command.

Never end a deployment with `optimize:clear` alone: it leaves every cache empty, so each request recompiles configuration, routes and views. `<PHP_BINARY> artisan optimize` builds the same caches in one command (it also runs `event:cache`, which is harmless here because event auto-discovery is off). `icons:cache` writes the blade-icons manifest so the first icon in each PHP process does not scan the icon directories.

- `view:cache` is mandatory whenever a release changes Blade components. Since Gate 2 Stage 3, `<x-ui.icon>` is the class component `App\View\Components\Ui\Icon`; views compiled by an older release keep working through `resources/views/components/ui/icon.blade.php`, but only recompiled views use the faster class. A rollback to a release without that class **must** recompile views (see §3.1 and §10), otherwise every page with an icon fails with "class not found".
- `queue:restart` makes Cron workers exit after their current job, so the next invocation runs the new job code (import commit, notification push). It does not interrupt a running export or import.

All four caches were built successfully on the Stage 3 code locally (21 October 2026) and then cleared; this proves the commands, not the production host.

Read-only/cache checks after deployment:

```sh
<PHP_BINARY> artisan about
<PHP_BINARY> artisan route:list --path=notifications
<PHP_BINARY> artisan schedule:list
```

Do not edit `.env` after caching configuration without rebuilding the configuration cache:

```sh
<PHP_BINARY> artisan config:clear
<PHP_BINARY> artisan config:cache
```

If the host uses release directories or symlinks, build caches from the active release path. Ensure `storage` and `bootstrap/cache` are writable by the PHP process.

### 3.1 Rollback and Migration Recovery Procedures

If a deployment must be rolled back:

1. **Application Code & Asset Rollback:**
   Revert application source files and `public/build/` to the prior release commit, then clear/rebuild caches. `optimize:clear` removes the compiled views, which is what makes a rollback across the `<x-ui.icon>` class change safe:
   ```sh
   <PHP_BINARY> artisan optimize:clear
   <PHP_BINARY> artisan config:cache
   <PHP_BINARY> artisan route:cache
   <PHP_BINARY> artisan view:cache
   <PHP_BINARY> artisan icons:cache
   <PHP_BINARY> artisan queue:restart
   ```

2. **Schema Rollback Pre-requisites:**
   - **Forward-compatible Schema:** In most incidents, rolling back application code without rolling back additive migrations (like `2026_08_28_000002_add_offer_fields_to_quotation_items_table`) is safest because previous application code ignores unknown columns.
   - **Full Database Rollback with `price_per_kg NULL` Safety:**
     Migration `2026_08_28_000002` contains a safety guard preventing accidental data corruption if unavailable items have `price_per_kg = NULL`.
     If full schema rollback is strictly required, the operator must explicitly sanitize `NULL` prices before running rollback:
     ```sql
     -- Sanitize NULL prices before rollback if required
     UPDATE quotation_items SET price_per_kg = 0 WHERE price_per_kg IS NULL;
     ```
     Then execute:
     ```sh
     <PHP_BINARY> artisan migrate:rollback --step=1
     ```

### 3.2 Read-only host evidence

Run as the hosting account user before the first performance release and record the output in §11. Every command only reads. The PHP extension, PCNTL, `flock` and writable-path checks are in [PRODUCTION-CRON-COMMANDS-20261012.md](PRODUCTION-CRON-COMMANDS-20261012.md) §3; run them with the CLI binary used by Cron, and read the web SAPI values from cPanel PHP Info/MultiPHP INI (they can differ).

```sh
cd <APPLICATION_PATH>
<PHP_BINARY> artisan about --only=environment,cache,drivers
<PHP_BINARY> -i | grep -Ei '^(opcache\.(enable|enable_cli|memory_consumption|max_accelerated_files|validate_timestamps|revalidate_freq)|memory_limit|max_execution_time) '
ulimit -u; ulimit -n
crontab -l
mysql --defaults-extra-file=<CLIENT_CNF> -e "SELECT VERSION(); SHOW VARIABLES WHERE Variable_name IN ('max_connections','innodb_lock_wait_timeout','wait_timeout');"
<PHP_BINARY> -r '$m = json_decode(file_get_contents("public/build/manifest.json"), true); $missing = []; foreach ($m as $entry) { foreach (array_merge([$entry["file"]], $entry["css"] ?? []) as $file) { if (! is_file("public/build/".$file)) { $missing[] = $file; } } } echo $missing ? "MISSING:\n".implode("\n", $missing) : "manifest OK (".count($m)." entries)", "\n";'
```

- `ulimit -u` is the per-user process limit. Each Cron worker is one PHP process (plus `flock`); three workers and the scheduler need four concurrent PHP processes at the top of each minute, on top of web PHP processes. If the limit or the provider's entry-process limit cannot accommodate that, use the two-worker fallback in §5.2.
- PCNTL must be available to the CLI binary: without it, `--timeout` cannot stop a hung job and only `retry_after` (660 s) recovers it.
- `<CLIENT_CNF>` is an option file readable only by the account (`chmod 600`), so the database password never appears in the shell history or process list. Do not paste `.env` values into tickets or chat.
- The production engine was recorded as `11.4.13-MariaDB-cll-lve` (owner screenshot, 21 October 2026). MariaDB 11.4 supports the window functions, `JSON_EXTRACT`/`JSON_UNQUOTE` and `FORCE INDEX` used by Gate 2; it does not support MySQL's `EXPLAIN ANALYZE` (use `ANALYZE FORMAT=JSON` on staging only).
- The manifest check must print `manifest OK`; any `MISSING` line means `public/build` was uploaded from a different build than `manifest.json` and pages will lose their JavaScript.

### 3.3 Gate 2 performance release (Stages 1–3)

The Gate 2 changes contain no migrations and no `.env` change. Order:

1. Record the current Cron lines verbatim (`crontab -l`) and run §3.2.
2. Pause import admission with the cache key `local_procurement_imports.paused=true` and let running imports finish, as described in [LOCAL-PROCUREMENT-IMPORTS.md](LOCAL-PROCUREMENT-IMPORTS.md). Exports may keep running.
3. Upload the application source and the complete `public/build` set from the same build (`npm.cmd run build` on the release commit). Keep the previous `public/build/assets` files until the rollback window closes; the hashed names do not collide.
4. Run the §3 command block without `migrate --force` (no migration is part of this release), including `view:cache`, `icons:cache` and `queue:restart`.
5. Remove the pause key, then run the smoke checks in §9, plus: one PO and one GR import preview and confirm, a dashboard with the layout editor, a list page with the advanced export modal and a date filter, `/profile/customization`, `/profile/notifications`, and a chat with more than 50 messages using "Load earlier messages".
6. Optional Cron change (separate change window, one worker at a time): split `exports,default` into `exports` and `default` as in §5.2, after §3.2 shows the process budget allows it.

Rollback: steps 2–4 with the previous release (§3.1); the previous Cron lines from step 1 if step 6 was applied. No data migration is needed in either direction.

## 4. Scheduler Cron

The repository schedules authentication-audit pruning at 02:10 and expired-export cleanup at 02:20. Configure one scheduler entry every minute:

```cron
* * * * * cd <APPLICATION_PATH> && <PHP_BINARY> artisan schedule:run >> /dev/null 2>&1
```

Verify it with:

```sh
<PHP_BINARY> artisan schedule:list
```

During staging validation, temporarily send Cron output to a file under `storage/logs`, confirm at least two invocations, then return routine output to `/dev/null`. The scheduled commands already use Laravel's `withoutOverlapping()` lock; the configured database cache must remain available for that lock.

## 5. Queue workers on cPanel (short-lived Cron workers)

This section is the canonical worker configuration. Other guides and the README point here.

### 5.1 Which queues exist

Verified against the code on 21 October 2026:

| Queue | Producers | Job timeout | Notes |
|---|---|---|---|
| `exports` | `ExportDispatcher` → `ProcessExportJob`, `GenerateWorkbookJob`, `FinalizeExportJob` | 600 s | Export state and queue row are written in one transaction on the same connection |
| `imports` | `ProcessLocalProcurementImport` (PO/GR preview and confirm) | 300 s | Confirm runs one whole-file transaction with a 60 s commit budget |
| `default` | `BroadcastNotificationCreated` for every `SystemNotification` (realtime Pusher push) and any job dispatched without a queue name | short | No queued mail or notification class exists today |
| `po-documents` | `LocalPoDocumentBatchService` | not set | Off (`po_documents.async_enabled=false`); do not add a worker until it is approved |

Earlier versions of this guide said `default` carried password-reset and new-device mail. Those are not queued in the current code. `default` now carries the realtime notification pushes. If no worker serves `default`, notifications are still stored and the 30-second badge polling still works, but no realtime toast or push arrives.

### 5.2 Recommended topology: three workers

One worker per queue, each with its own lock file and log. A long export (up to 600 s) or import (up to 300 s) then never delays a realtime push.

```cron
* * * * * cd <APPLICATION_PATH> && <FLOCK_BINARY> -n <APPLICATION_PATH>/storage/framework/async-export-worker.lock <PHP_BINARY> artisan queue:work database --queue=exports --sleep=1 --max-time=50 --tries=3 --timeout=600 >> <APPLICATION_PATH>/storage/logs/queue-worker.log 2>&1
* * * * * cd <APPLICATION_PATH> && <FLOCK_BINARY> -n <APPLICATION_PATH>/storage/framework/import-worker.lock <PHP_BINARY> artisan queue:work database --queue=imports --sleep=1 --max-time=50 --tries=3 --timeout=300 --memory=384 >> <APPLICATION_PATH>/storage/logs/import-worker.log 2>&1
* * * * * cd <APPLICATION_PATH> && <FLOCK_BINARY> -n <APPLICATION_PATH>/storage/framework/default-worker.lock <PHP_BINARY> artisan queue:work database --queue=default --sleep=1 --max-time=50 --tries=3 --timeout=60 >> <APPLICATION_PATH>/storage/logs/default-worker.log 2>&1
```

Plus the scheduler entry from §4. That is four Cron entries.

Keep the existing lock name `async-export-worker.lock` for the export worker. A worker started by the old `exports,default` line then still holds the same lock, so the new export worker cannot start beside it during the switch. The new `default` worker may briefly run beside an old `exports,default` worker; both reserve rows from the database queue, so a job is still processed once.

**Fallback when the host does not allow a third resident process:** two workers.

```cron
* * * * * cd <APPLICATION_PATH> && <FLOCK_BINARY> -n <APPLICATION_PATH>/storage/framework/async-export-worker.lock <PHP_BINARY> artisan queue:work database --queue=default,exports --sleep=1 --max-time=50 --tries=3 --timeout=600 >> <APPLICATION_PATH>/storage/logs/queue-worker.log 2>&1
* * * * * cd <APPLICATION_PATH> && <FLOCK_BINARY> -n <APPLICATION_PATH>/storage/framework/import-worker.lock <PHP_BINARY> artisan queue:work database --queue=imports --sleep=1 --max-time=50 --tries=3 --timeout=300 --memory=384 >> <APPLICATION_PATH>/storage/logs/import-worker.log 2>&1
```

`default,exports` takes pushes before *queued* exports, but a push still waits while that process is *running* an export (up to 600 s). Choose this only when the three-worker layout exceeds the account's process or memory limit.

### 5.3 Do not use `--stop-when-empty`

`--stop-when-empty` makes a Cron worker exit as soon as the queue is empty. Every new job then waits for the next Cron minute, so exports, imports and pushes sit in "Queued" for 0–60 s. Without it, the worker keeps polling (`--sleep=1`) until `--max-time=50` ends it, and Cron starts the next one. The remaining gap is about 10 s per minute.

Idle cost per worker: one PHP CLI process for about 50 s of each minute (tens of MiB) and one `jobs` poll per second. Measure it against the account's process and memory limits before enabling all three workers.

`--max-time` is checked between jobs, so a worker that is running a job keeps going past 50 s. `flock -n` stops a second copy from starting meanwhile. Do not drop `flock` unless the provider guarantees that Cron commands never overlap.

### 5.4 Timeouts and retries

```text
exports job timeout   600 s
imports job timeout   300 s   (confirm transaction budget 60 s, preview lease 600 s)
default worker        60 s
DB_QUEUE_RETRY_AFTER  660 s   (connection-wide; must stay above every job timeout)
```

If `retry_after` were shorter than a running job, another worker could take the same job while it is still running. Re-measure before changing any of these values. Jobs are retried up to three times. An import whose confirm transaction exceeds the 60 s budget is rolled back, marked `FAILED` once, and recorded in `failed_jobs` without further retries.

Timeouts need the PCNTL extension in the CLI PHP. Check `<PHP_BINARY> -m | grep pcntl` on the host; without it `--timeout` cannot interrupt a stuck job.

### 5.5 Deploy, restart, rollback

1. Before changing Cron, copy the existing lines verbatim into the change record.
2. Deploy code, rebuild caches (§3), then run `<PHP_BINARY> artisan queue:restart`. Running workers finish their current job and exit; Cron starts workers with the new code.
3. For imports, pause admission first (`local_procurement_imports.paused=true` in the cache), wait for active imports to finish, deploy, restart, then remove the pause (see `LOCAL-PROCUREMENT-IMPORTS.md`).
4. Change one Cron line at a time and watch its log for two minutes.
5. Rollback: restore the recorded lines, run `queue:restart`, and check that no job is stuck in `jobs` with `reserved_at` set for longer than 660 s.

### 5.6 Verification and monitoring

```sh
<PHP_BINARY> artisan queue:failed
<PHP_BINARY> artisan schedule:list
ps -u <ACCOUNT_USER> -o pid,etime,args | grep '[a]rtisan queue:work'
```

On staging, check one small export (`queued → processing → completed`, private download works), one PO and one GR import, and one realtime notification push while a long export is running. Record the push latency. Confirm that each queue returns to its previous depth and that no new failed job appears. Do not use production as a stress-test target.

Monitor at minimum: oldest pending job age per queue, pending count per queue, failed jobs by exception class, export and import duration, and log growth. Log rotation is provider-specific; do not let the worker logs grow without a rotation or retention policy.

Status: this topology was prepared from the repository only. The live Cron lines, `flock` path, PCNTL and the account's process and memory limits are **not verified**.

### 5.7 Alternative: long-lived workers (VPS only)

Where permanent daemons are supported (Supervisor/systemd on a VPS), run one long-lived program per queue with the same `--queue`, `--timeout` and `--tries` values as §5.2, without `--max-time`. Set the Supervisor `stopwaitsecs` above the longest job timeout (at least 660 s for exports) so a deployment restart does not kill a running export. Run `php artisan queue:restart` on every deployment, and keep the scheduler Cron entry.

## 6. OPcache and PHP limits

Confirm these values in the domain's **web SAPI**, not only CLI:

```text
PHP version >= 8.2
opcache.enable = On
opcache.memory_consumption
opcache.max_accelerated_files
opcache.validate_timestamps
memory_limit
max_execution_time
max_input_vars
post_max_size
upload_max_filesize
max_file_uploads
```

Use provider-supported PHP Selector/MultiPHP INI controls. Do not copy arbitrary VPS OPcache values into `.user.ini`. If `opcache.validate_timestamps=0`, identify the cPanel action that restarts/resets the domain PHP process after each deployment; otherwise old bytecode can remain active. If the provider does not expose OPcache configuration, open a hosting ticket and record the answer.

Upload limits must be at least as large as the application's validated request size plus multipart overhead. Increasing PHP limits does not fix unbounded import/export memory behavior.

## 7. Browser caching and compression

`public/.htaccess` now applies guarded, portable policies:

- `public/build/assets/*`: one-year `public, immutable` caching because Vite filenames are content-hashed;
- `public/assets/*`: one-day caching with revalidation because these filenames are stable;
- HTML, authenticated pages, JSON endpoints, downloads, and private storage: no static public-cache override;
- gzip for text when `mod_deflate` exists.

Brotli should be enabled through the cPanel/LiteSpeed/provider control when available, then verified at the public hostname. The repository does not stack Apache `BROTLI_COMPRESS` and `DEFLATE` filters because filter ordering varies by host and a dual filter chain can be order-dependent. Provider-level Brotli may take precedence over the guarded gzip fallback.

Do not extend immutable caching to stable filenames. If a file under `public/assets` must update immediately, version/rename it or move it into the Vite build and update all references.

Use an actual filename from `public/build/manifest.json` for these checks:

```sh
curl -sSI https://portal.example.com/build/assets/app-HASH.css
curl -sSI https://portal.example.com/assets/js/async-export.js
curl -sSI https://portal.example.com/login
curl -sS --compressed -D - -o /dev/null https://portal.example.com/build/assets/app-HASH.js
```

Expected:

```text
Vite asset: Cache-Control includes max-age=31536000 and immutable
Stable public asset: Cache-Control includes max-age=86400 and must-revalidate
Login/authenticated HTML: no public/immutable cache directive
Compressed text response: Content-Encoding is br (provider) or gzip (Apache fallback), and Vary includes Accept-Encoding
```

Some CDNs or LiteSpeed layers may transform headers. Verify both the origin (when safely reachable) and the public hostname, and record which layer supplied compression/cache headers.

## 8. HTTPS, HTTP/2, and optional CDN

Verify transport from a client with current curl support:

```sh
curl -sSI http://portal.example.com/
curl -sSI --http2 https://portal.example.com/up
```

Expected:

- HTTP redirects to the canonical HTTPS URL with 301 or 308;
- the HTTPS certificate is valid for the hostname and complete chain;
- the HTTPS response negotiates HTTP/2 where the provider supports it;
- `/up` returns 200 without exposing application internals.

Cloudflare is optional, not required. If adopted, use Full (strict) TLS and cache public static assets only. Bypass edge caching for authenticated HTML, login/logout, role prefixes (`/admin`, `/purchasing`, `/supplier`, `/qc`), notification/chat endpoints, private downloads, and CSRF-sensitive requests. Do not use a "cache everything" rule for the application.

## 9. Smoke checks

After caches and Cron are configured, use staging or a controlled production smoke window:

- login and logout;
- Purchasing and Supplier dashboards;
- PR list and one authorized PR detail/create flow;
- quotation list/form;
- PO list and authorized PO PDF/detail;
- QC and claims;
- notification summary/unread/read actions;
- chat drawer and unread count;
- one small queued export through download;
- one realtime notification push through the `default` queue;
- first-device in-app alert, same-device no-repeat behavior, and the `/profile#active-sessions` link;
- a fourth concurrent login evicting only the oldest active session;
- `/up` health endpoint;
- `storage/logs/laravel.log`, queue log, and `failed_jobs` after the checks.

Confirm supplier data isolation with accounts from two different suppliers. Do not interpret a 200 response alone as proof that the business output is correct.

## 10. Rollback

For an application rollback:

1. pause the queue Cron and allow an active locked worker to finish;
2. inspect pending `default`, `exports` and `imports` jobs; drain them or keep their serialized job classes compatible with the rollback release;
3. restore the previous matching application source, `public/build/manifest.json`, and `public/build/assets` set;
4. restore the previous `.htaccess` if the static policy itself is being rolled back;
5. run `artisan optimize:clear`, then `config:cache`, `route:cache`, `view:cache`, `icons:cache` and `queue:restart` from the restored release (recompiling views is required when rolling back across the `<x-ui.icon>` class change, §3);
6. resume scheduler/worker Cron;
7. repeat the smoke checks and inspect logs/failed jobs.

Do not roll back a migration without reviewing whether production data now depends on it. Vite's content-hashed filenames allow old and new assets to coexist temporarily; do not delete the previous build set until the rollback window is closed.

## 11. Deployment evidence checklist

| Check | Required evidence | Status before cPanel validation |
|---|---|---|
| PHP 8.2+ web SAPI | cPanel PHP Info/PHP Selector | NOT MEASURED — ENVIRONMENT REQUIRED |
| OPcache enabled | web-SAPI OPcache values | NOT MEASURED — ENVIRONMENT REQUIRED |
| Composer optimized autoload | deployment command log | NOT MEASURED — ENVIRONMENT REQUIRED |
| Config cache | successful production command and smoke | NOT MEASURED — ENVIRONMENT REQUIRED |
| Route cache | successful production command and route smoke | NOT MEASURED — ENVIRONMENT REQUIRED |
| View cache | successful production command and rendered-page smoke | NOT MEASURED — ENVIRONMENT REQUIRED |
| Icons cache | `icons:cache` output; `bootstrap/cache/blade-icons.php` present | NOT MEASURED — ENVIRONMENT REQUIRED |
| Build set consistency | §3.2 manifest check prints `manifest OK` | NOT MEASURED — ENVIRONMENT REQUIRED |
| PCNTL on the Cron CLI binary | §3.2 / cron guide §3 output | NOT MEASURED — ENVIRONMENT REQUIRED |
| Process budget for Cron workers | `ulimit -u` and provider entry-process limit | NOT MEASURED — ENVIRONMENT REQUIRED |
| Database engine and limits | `SELECT VERSION()`, `max_connections`, `innodb_lock_wait_timeout` | Engine RECORDED 21 Oct 2026 from owner screenshot (`11.4.13-MariaDB-cll-lve`); limits NOT MEASURED |
| Current Cron lines | `crontab -l` before any change | RECORDED 21 Oct 2026 from owner screenshot (scheduler, `exports,default`, `imports`, none with `--stop-when-empty`); re-record before editing |
| Browser cache headers | public-host curl headers | NOT MEASURED — ENVIRONMENT REQUIRED |
| Brotli/gzip | public-host `Content-Encoding` | NOT MEASURED — ENVIRONMENT REQUIRED |
| HTTP/2 | public-host protocol negotiation | NOT MEASURED — ENVIRONMENT REQUIRED |
| HTTPS | redirect, certificate, secure-cookie smoke | NOT MEASURED — ENVIRONMENT REQUIRED |
| Scheduler Cron | timestamped Cron evidence plus scheduled cleanup | NOT MEASURED — ENVIRONMENT REQUIRED |
| Queue Cron | end-to-end export, import, realtime push on `default`, and no overlap | NOT MEASURED — ENVIRONMENT REQUIRED |

Do not replace these statuses with "verified" based solely on repository configuration or the local Windows/Laragon environment.
