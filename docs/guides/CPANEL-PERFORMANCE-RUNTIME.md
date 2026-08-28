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
```

Run `migrate --force` only after confirming the active database and backup, and only when the release contains approved migrations. It is intentionally not a database preflight command.

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
   Revert application source files and `public/build/` to the prior release commit, then clear/rebuild caches:
   ```sh
   <PHP_BINARY> artisan optimize:clear
   <PHP_BINARY> artisan config:cache
   <PHP_BINARY> artisan route:cache
   <PHP_BINARY> artisan view:cache
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

## 5. Short-lived queue worker Cron

Exports are dispatched to the `exports` queue. Password-reset mail, new-device alert mail, and queued repeated-lockout alerts use the `default` queue. The worker must listen to `exports,default` in that order. A worker that listens only to `default` leaves exports queued indefinitely; a worker that listens only to `exports` leaves security mail queued indefinitely.

Preferred template when the host provides `flock`:

```cron
* * * * * <FLOCK_BINARY> -n <APPLICATION_PATH>/storage/framework/async-export-worker.lock <PHP_BINARY> <APPLICATION_PATH>/artisan queue:work database --queue=exports,default --sleep=1 --stop-when-empty --max-time=50 --tries=3 --timeout=600 >> <APPLICATION_PATH>/storage/logs/queue-worker.log 2>&1
```

Minimal template when the provider guarantees Cron commands cannot overlap:

```cron
* * * * * cd <APPLICATION_PATH> && <PHP_BINARY> artisan queue:work database --queue=exports,default --sleep=1 --stop-when-empty --max-time=50 --tries=3 --timeout=600 >> storage/logs/queue-worker.log 2>&1
```

Do not use the minimal template merely because `flock` is unavailable. A running job can continue beyond `--max-time`; `--max-time` is checked between jobs and is not a per-job kill switch. If an export can run longer than the one-minute Cron interval, overlapping workers are possible unless cPanel or a lock prevents them. Ask the provider for an overlap-safe mechanism or use an approved lock wrapper.

The timeout relationship is deliberate:

```text
worker --timeout = 600 seconds
DB_QUEUE_RETRY_AFTER = 660 seconds
```

`retry_after` must remain greater than the worker timeout so a still-running job is not released to another worker. Re-measure before changing either value. `--tries=3` means jobs must remain retry-safe; failed jobs are retained in `failed_jobs` for operator review.

Operational checks:

```sh
<PHP_BINARY> artisan queue:failed
<PHP_BINARY> artisan schedule:list
```

Then trigger one small export from an authorized staging account and one queued security email. Verify the export UI lifecycle `queued -> processing -> completed`, the private download works, both queues return to their prior depth, and no new failed job appears. Record completion latency. Do not use production as a stress-test target.

Monitor at minimum:

- oldest pending job age;
- pending `exports` and `default` job count;
- failed job count and exception class;
- export completion duration;
- queue-worker log growth and filesystem quota.

Log rotation is provider-specific. Do not allow `queue-worker.log` to grow without a cPanel-supported rotation/retention policy.

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
- one password-reset mail job and one new-device alert mail job through the `default` queue;
- first-device in-app alert, same-device no-repeat behavior, and the `/profile#active-sessions` link;
- a fourth concurrent login evicting only the oldest active session;
- `/up` health endpoint;
- `storage/logs/laravel.log`, queue log, and `failed_jobs` after the checks.

Confirm supplier data isolation with accounts from two different suppliers. Do not interpret a 200 response alone as proof that the business output is correct.

## 10. Rollback

For an application rollback:

1. pause the queue Cron and allow an active locked worker to finish;
2. inspect pending `default` jobs and drain security notifications or keep their serialized notification classes compatible with the rollback release;
3. restore the previous matching application source, `public/build/manifest.json`, and `public/build/assets` set;
4. restore the previous `.htaccess` if the static policy itself is being rolled back;
5. run `artisan optimize:clear`, then `config:cache`, `route:cache`, and `view:cache` from the restored release;
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
| Browser cache headers | public-host curl headers | NOT MEASURED — ENVIRONMENT REQUIRED |
| Brotli/gzip | public-host `Content-Encoding` | NOT MEASURED — ENVIRONMENT REQUIRED |
| HTTP/2 | public-host protocol negotiation | NOT MEASURED — ENVIRONMENT REQUIRED |
| HTTPS | redirect, certificate, secure-cookie smoke | NOT MEASURED — ENVIRONMENT REQUIRED |
| Scheduler Cron | timestamped Cron evidence plus scheduled cleanup | NOT MEASURED — ENVIRONMENT REQUIRED |
| Queue Cron | end-to-end export, default security mail, and no overlap | NOT MEASURED — ENVIRONMENT REQUIRED |

Do not replace these statuses with "verified" based solely on repository configuration or the local Windows/Laragon environment.
