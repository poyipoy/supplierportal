# PERF-08 — cPanel Runtime and Static Delivery

## Mission status

`REPOSITORY_IMPLEMENTATION_COMPLETE — CPANEL_VALIDATION_REQUIRED`

The portable repository work is complete. Production OPcache, transport, compression, Cron reliability, and cPanel resource behavior remain `NOT MEASURED — ENVIRONMENT REQUIRED` because no production/cPanel access was used.

Canonical deployment instructions: [cPanel Performance and Runtime Runbook](../guides/CPANEL-PERFORMANCE-RUNTIME.md).

## Scope and files inspected

- `.env.example`
- `public/.htaccess`
- `public/build/manifest.json`
- `vite.config.js`
- `bootstrap/app.php`
- `routes/console.php`
- `config/queue.php`, `config/cache.php`, `config/session.php`, `config/broadcasting.php`
- `composer.json`
- `README.md`
- `docs/guides/AUTH-SECURITY-DEPLOYMENT.md`
- local Apache/PHP response and module information

No production system, production database, cPanel account, DNS, TLS certificate, CDN configuration, or browser automation was accessed.

## Findings and classification

| Finding | Classification | Evidence / decision |
|---|---|---|
| Vite and stable public assets had no explicit browser-cache policy locally | `OPTIMIZATION OPPORTUNITY` | Before the change, the Vite CSS response had ETag/Last-Modified but no Cache-Control. |
| Production Brotli/gzip, HTTP/2, and OPcache state | `LIKELY BOTTLENECK — MEASUREMENT REQUIRED` | These depend on the cPanel/LiteSpeed/Apache and web-SAPI configuration. The local host cannot prove production state. |
| Queue and scheduler Cron reliability | `LIKELY BOTTLENECK — MEASUREMENT REQUIRED` | Repository schedules exist and exports use the database `exports` queue, but production Cron execution is external state. |
| Redis, Supervisor/systemd, Reverb, and a CDN as mandatory dependencies | `NOT CURRENTLY JUSTIFIED` | The database drivers, short-lived Cron worker, Pusher, and single-node cPanel architecture satisfy the deployment target. |
| `.env.example` documented Reverb/log while the active frontend/backend intent is Pusher | `CONFIGURATION_DOCUMENTATION_DRIFT` | The example now documents Pusher values and retains polling fallback. |
| `TRUSTED_PROXIES` is read via direct `env()` in `bootstrap/app.php` | `CONFIG CACHE COMPATIBILITY RISK` | Direct runtime environment access is outside configuration. The documented direct-SSL baseline leaves it empty, so current intended topology is unaffected; proxy enablement is deferred until configuration-backed. |
| Existing README contains account-specific Cron paths | `DOCUMENTATION DRIFT` | The new canonical guide uses placeholders that must be discovered in cPanel. Existing paths were not treated as portable or verified-current. |

## Changes made

1. `.env.example` now identifies Pusher Channels as the active provider and supplies only Pusher placeholders; Reverb is no longer presented as the cPanel deployment target.
2. `public/.htaccess` now assigns one-year immutable caching only to content-hashed Vite assets, a one-day revalidating cache to stable public assets, and guarded gzip compression for text types when `mod_deflate` exists. Brotli remains a provider/LiteSpeed setting to avoid an order-dependent dual Apache filter chain.
3. Added one canonical cPanel runtime runbook covering discovery, deployment caches, scheduler, queue worker/locking/timeouts, OPcache/PHP limits, static delivery, transport, smoke checks, rollback, and an evidence checklist.

The cache rules do not apply public/immutable caching to application HTML, JSON endpoints, dynamic downloads, or private storage.

## Local evidence before and after

Local environment: Apache 2.4.54 on Windows/Laragon, PHP 8.2.30.

| Check | Before | After |
|---|---|---|
| Vite CSS `Cache-Control` | absent | `public, max-age=31536000, immutable` |
| Stable `public/assets` JS `Cache-Control` | absent | `public, max-age=86400, must-revalidate` |
| Dynamic `/up` cache policy | `no-cache, private` | unchanged; no public/immutable override |
| Login cache policy | `no-store, private` | unchanged; no public/immutable override |
| Local compression | absent | absent because local Apache does not load deflate; the gzip directive is module-guarded |
| Local HTTP protocol | HTTP/1.1 | unchanged; production HTTP/2 remains environment-dependent |

The local Apache module list confirmed `headers`, `setenvif`, and `ssl`; it did not list `brotli`, `deflate`, or `http2`. Brotli and deflate were not configured together in `.htaccess`, avoiding a host-dependent filter ordering/double-compression risk.

## Verification actually executed

- `php artisan schedule:list` — verified the two daily scheduled commands.
- Isolated `php artisan config:cache` — passed.
- Isolated `php artisan route:cache` — passed.
- Isolated `php artisan view:cache` — passed; 201 compiled views, then the isolated caches were cleared.
- `php artisan route:list --path=notifications` while isolated caches were active — passed and listed five notification routes.
- HTTP header checks against local Vite CSS, stable public JS, `/up`, and `/login` — passed for cache scoping.
- Local Apache module inspection — compression/HTTP2 modules unavailable.

An initial isolated-cache attempt used a Windows absolute override that Laravel normalized relative to the application and failed to create the target. It did not create a repository cache or alter application state. The subsequent workspace-relative isolated paths passed and were cleared.

## Risks and deferred work

- Production cache-command compatibility is strongly supported locally but not production-verified.
- Compression directives have not executed locally because the relevant module is absent. cPanel/LiteSpeed must be checked through public response headers.
- `--max-time=50` does not terminate a currently executing export; a lock or provider-level non-overlap guarantee is required when Cron runs every minute.
- `DB_QUEUE_RETRY_AFTER=660` must stay greater than the worker `--timeout=600` unless execution data supports a coordinated change.
- `DB_QUEUE_CONNECTION` must remain unset or resolve to the same Laravel connection as `export_jobs`; this is required by the atomic export handoff verified in PERF-06.
- Stable assets can remain cached for up to one day. Urgent assets should be versioned/renamed or moved into Vite's hashed build.
- The direct `TRUSTED_PROXIES` environment lookup should be moved behind configuration before a proxy topology relies on it; that code change is outside PERF-08's scoped file set.
- cPanel CPU, entry-process, memory, I/O, MySQL connection, and filesystem quota graphs remain unavailable.

## Final mission verdict

Repository-level runtime/static-delivery changes are implemented and cache compatibility is verified locally. The mission cannot be marked fully production-verified until the cPanel checklist records OPcache, public compression/cache headers, HTTP/2/HTTPS, scheduler execution, and end-to-end queue processing.
