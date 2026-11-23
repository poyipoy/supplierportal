# Implementation Plan (v2 — Deep Spec): cPanel-Native Performance & Security Hardening — ADASI Supplier Portal

*Date: 22 November 2026 | Target: cPanel Shared / Managed Hosting (Single Server, No Redis, No Rust, No Octane) | Deploy Pipeline: Manual FileZilla Upload + Post-Deploy Artisan via cPanel Cron | Scale: < 10 Internal Users + 10–50 Suppliers | Mode: Evidence-First & Oracle-Gated*

## 1. Strict Scope & Non-Negotiable Invariants

Eliminate system-wide latency on cPanel without changing stack (PHP/Laravel 12 + MySQL), without adding external daemons, and without breaking multi-tab `WorkspaceShell`, "Logout All Devices", or RBAC/tenant isolation.

### Hard Invariants
1. **Zero `env()` Outside `config/`**: `php artisan optimize` (`config:cache`) must never silently disable `TRUSTED_PROXIES`, HTTPS detection, or rate-limit IP resolution (`bootstrap/app.php:68`).
2. **FileZilla-Safe OPcache**: `opcache.validate_timestamps=1` with `opcache.revalidate_freq=60` is mandatory because FileZilla overwrites PHP files in-place without restarting PHP-FPM/LSAPI.
3. **Inode-Safe Caching on Shared Hosting**: Switching `CACHE_STORE=file` is only safe if expired files in `storage/framework/cache/data` (created by `ThrottleRequests`, `WrapWorkspaceShell` 15s prerender, and versioned `NotificationSummaryService` keys) are actively pruned — Laravel's `FileStore` has **no** built-in expired-file garbage collector.
4. **Zero Stale Authorization / Maintenance / Preference State**: Cross-request caching for `UserPreferenceService::for()` and `MaintenanceResolver::effectiveRecords()` must invalidate deterministically on every write (`saved`/`deleted` + `maintenance:activate-due`).
5. **Database Safety**: Never run `migrate:fresh`, `db:wipe`, or destructive cleanup without a verified backup; automated tests run strictly on `adasi_portal_test`.

## 2. Raw Starting Materials (`[Verified]` From Repo & 99.2 MB SQL Dump)

| Raw Source | Exact Location | Verified Technical Fact |
| :--- | :--- | :--- |
| **Built-in Profiler** | `app/Console/Commands/ProfileCriticalPaths.php` (`php artisan performance:profile-critical --json --runs=5`) | Executes real in-process GET requests through the full HTTP kernel (middleware, route binding, policies, view composers) across all 5 roles inside rolled-back transactions and outputs `query_count`, `sql_ms`, `p50_ms`, `p95_ms`, `peak_mb`, `response_bytes`. |
| **Cache-Breaking Bug** | `bootstrap/app.php:68` | Calls `env('TRUSTED_PROXIES', '')` directly outside `config/`; silently returns `''` once `php artisan optimize` caches config ([Laravel 12 Deployment](https://laravel.com/framework/docs/12.x/deployment)). |
| **Per-Request Uncached Middleware Queries** | `app/Http/Middleware/ApplyUserLocale.php:20` → `app/Services/UserPreferenceService.php:76`; `app/Http/Middleware/EnforceMaintenance.php:37` → `app/Services/Maintenance/MaintenanceResolver.php:24` | Every authenticated request (HTML, iframe, DataTables AJAX, 30s badge poll) queries `user_preferences`, and every non-admin request queries `maintenance_records` + `modules` with zero cross-request cache. |
| **Background Polling Amplification** | `resources/views/layouts/app.blade.php:661-670` | Fires 2 concurrent fetches (`notifications.unread-count` + `conversations.unread-count`) every 30s whenever Pusher WebSocket is disconnected — without checking `document.hidden`, burning cPanel Entry Processes (EP) on idle/background tabs. |
| **SQL Dump Bloat & Inactive Cron Proof** | `adasi_portal (10).sql` (99.2 MB, 113 tables) | **92.3% (91.62 MB / 308,883 rows)** is 2 staging tables: `local_procurement_import_rows` (**60.38 MB / 206,002 rows**) and `local_procurement_import_records` (**31.24 MB / 102,881 rows**). All 7 stuck `jobs` (IDs 1257–1263) are `BroadcastNotificationCreated`; 554 unpurged `oauth_*` rows exist. `routes/console.php` already defines `imports:cleanup`, `passport:purge`, and `storage:inode-guard` (`CleanupTempStorageCommand.php`), proving `schedule:run` is not running on cPanel. |
| **Index Drift** | `adasi_portal (10).sql` (`ALTER TABLE` index blocks) | `notifications` lacks `(notifiable_type, notifiable_id, read_at, created_at)`; `jobs` lacks `(queue, reserved_at, available_at)`; duplicate identical indexes exist on `quotation_items` (`quotation_items_pr_item_id_quotation_id_index` & `quotation_items_pr_item_quotation_index`) and `payment_items` (`payment_items_payable_type_payable_id_status_index` & `idx_payment_items_reservation`). |
| **Cache Prefix Split & Inode Guard** | `adasi_portal (10).sql` (`cache` table) & `app/Console/Commands/CleanupTempStorageCommand.php` | `cache` rows contain two historical prefixes (`adasi-portal-supplier-cache-*` and `adasi-supplier-portal-cache-*`). `storage:inode-guard` exists to protect cPanel inode quotas. |
| **Web Server & Security Boundary** | `public/.htaccess` & `config/ui.php` | `public/.htaccess` already sets 1-year `immutable` caching on `build/assets/` and `mod_deflate`, but lacks `<FilesMatch>` deny rules for `.env`/`.git`/`composer.*`/`artisan`. `config/ui.php` defaults `max_live_frames = 18` and `max_sessions = 36`. |

## 3. Deterministic Execution Plan (4 Phases)

### Phase 0 — Capture Machine-Readable Baseline (Before Any Edit)
1. Run locally against a copy of the production-like dataset:
   ```bash
   php artisan performance:profile-critical --json --runs=5 > storage/logs/perf-baseline.json
   ```
2. Record live cPanel TTFB (`curl -w` or Chrome DevTools Network) on 5 representative URLs (Dashboard, PR DataTable, PO DataTable, Local Invoices, WorkspaceShell tab switch) and note cPanel *Resource Usage* (Entry Processes, I/O, Inodes).

### Phase 1 — Eliminate Config-Cache Bug, Harden cPanel Boundary & Fix Deploy Pipeline
1. **Fix `TRUSTED_PROXIES` (`bootstrap/app.php` + `config/auth_security.php`)**:
   - Move `env('TRUSTED_PROXIES', '')` into `config/auth_security.php` (`'trusted_proxies' => env('TRUSTED_PROXIES', '')`) and read `config('auth_security.trusted_proxies')` (or parse inside a config-backed middleware/bootstrapper) so `php artisan optimize` never zeroes it out.
   - Add a PHPUnit regression test verifying `TRUSTED_PROXIES` remains active under cached config.
2. **Harden `public/.htaccess`**:
   - Add explicit deny rule before `mod_rewrite`:
     ```apache
     <FilesMatch "^\.(env|git|htaccess|htpasswd)|^(composer\.(json|lock)|artisan|package(-lock)?\.json)$">
         Require all denied
     </FilesMatch>
     ```
   - Keep existing `build/assets/` `max-age=31536000, immutable` and `mod_deflate`; optionally add `mod_brotli` wrapped in `<IfModule mod_brotli.c>`.
3. **cPanel Runtime & `.env` Configuration**:
   - Enable **PHP 8.2/8.3** + **OPcache** in cPanel *Select PHP Version → Options*: `opcache.enable=1`, `opcache.memory_consumption=128`, `opcache.interned_strings_buffer=16`, `opcache.max_accelerated_files=10000`, `opcache.validate_timestamps=1`, `opcache.revalidate_freq=60` ([OPcache for Laravel](https://deploynix.io/blog/opcache-configuration-for-laravel-the-free-performance-boost-youre-ignoring), [Laravel 12 Shared Hosting](https://www.antmediahost.com/blog/laravel-12-optimasi-shared-hosting/)).
   - Set production `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`, `CACHE_STORE=file` (keeping `SESSION_DRIVER=database` so "Logout All Devices" remains intact), `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`, `BROADCAST_CONNECTION=null` (stops `BroadcastNotificationCreated` jobs from piling up when Pusher/Reverb is not used on cPanel), `WORKSPACE_SHELL_MAX_LIVE_FRAMES=6`, `WORKSPACE_SHELL_MAX_SESSIONS=12`, `WORKSPACE_SHELL_STALE_AFTER_SECONDS=300`.
   - Enforce `0750`/`0755` permissions on `storage/` and `bootstrap/cache/` (never `0777`).
4. **Standardize FileZilla + Post-Deploy Cron SOP**:
   - **Pre-upload (local)**: Run `npm.cmd run build` and `composer install --no-dev --optimize-autoloader` (in a clean deploy staging copy, or `composer dump-autoload --optimize`) before uploading via FileZilla. **Exclude** `.env`, `bootstrap/cache/*.php`, `storage/logs/*`, and `node_modules/`.
   - **Post-deploy (one-shot cPanel Cron Job)**:
     ```bash
     /usr/local/bin/ea-php82 /home/<user_cpanel>/supplierportal/artisan optimize:clear && /usr/local/bin/ea-php82 /home/<user_cpanel>/supplierportal/artisan optimize
     ```

### Phase 2 — Cut Per-Request Query & Background EP Amplification
1. **Cross-Request Cache in `UserPreferenceService::for()` (`app/Services/UserPreferenceService.php`)**:
   - Layer `Cache::remember("user_preferences:{$user->id}", 300, ...)` beneath `$request->attributes` memoization.
   - Invalidate via `Cache::forget("user_preferences:{$user->id}")` on every `UserPreference` write (service update methods + `UserPreference::saved`/`deleted` listeners in `AppServiceProvider`).
2. **Cross-Request Cache in `MaintenanceResolver::effectiveRecords()` (`app/Services/Maintenance/MaintenanceResolver.php`)**:
   - When `$this->now === null`, wrap the query in `Cache::remember('maintenance:effective_records', 30, ...)`.
   - Invalidate on `MaintenanceRecord::saved`/`deleted` and inside `maintenance:activate-due` when transitioning records.
3. **Inode-Safe File Cache Pruning (`app/Services/FileSecurity/InodeGuardCleanupService.php`)**:
   - Verify whether `storage/framework/cache/data` is swept by `storage:inode-guard`; if not, add a dedicated expired-file sweep (reading the 10-byte Unix timestamp header of Laravel `FileStore` files and deleting only expired entries) scheduled hourly in `routes/console.php` so `CACHE_STORE=file` can never exhaust cPanel inodes.
4. **Visibility-Aware Badge Polling (`resources/views/layouts/app.blade.php:663-670`)**:
   - Skip the 30s `updateBadges()` interval when `document.hidden === true`, and trigger a single debounced `updateBadges()` when `visibilitychange` returns to `'visible'`.
5. **Single Permanent cPanel Cron + Non-Overlapping Queue Worker (`config/queue.php` & `routes/console.php`)**:
   - Add `'cpanel_cron_worker' => (bool) env('CPANEL_CRON_QUEUE_WORKER', false)` in `config/queue.php`.
   - Schedule in `routes/console.php` ([Laravel 12 Scheduling](https://laravel.com/framework/docs/12.x/scheduling), [cPanel Queue Cron](https://dev.to/app_zain_444423744db73517/menjalankan-laravel-queue-di-cpanel-menggunakan-cronjob-39cl)):
     ```php
     Schedule::command('queue:work --queue=exports,imports,default --stop-when-empty --max-time=50 --tries=3')
         ->everyMinute()
         ->when(fn () => (bool) config('queue.cpanel_cron_worker', false))
         ->withoutOverlapping(2);
     ```
   - Configure **1 permanent cPanel Cron Job** (`* * * * *`):
     ```bash
     /usr/local/bin/ea-php82 /home/<user_cpanel>/supplierportal/artisan schedule:run >> /dev/null 2>&1
     ```

### Phase 3 — Database Index Migration & 91.62 MB Bloat Reclamation
1. **Create 1 Non-Destructive Index Migration** ([MySQL Index Optimization for Laravel](https://dev.to/addwebsolutionpvtltd/mysql-index-optimization-for-complex-laravel-queries-1abc)):
   - Add `notifications_notifiable_read_created_idx (notifiable_type, notifiable_id, read_at, created_at)` on `notifications`.
   - Add `jobs_queue_reserved_available_idx (queue, reserved_at, available_at)` on `jobs`.
   - Drop duplicate indexes: `quotation_items_pr_item_quotation_index` on `quotation_items` and `idx_payment_items_reservation` on `payment_items` (with existence guards so rollback/re-run is idempotent).
2. **Reclaim 91.62 MB Staging Bloat (Backup First!)**:
   - Run `artisan imports:cleanup`, `artisan passport:purge`, and clear stale `BroadcastNotificationCreated` rows from `jobs` (once `BROADCAST_CONNECTION=null` is confirmed).
   - Review `config/local_procurement_imports.php` `retention_days` (recommend `7` days on shared hosting) so `local_procurement_import_rows` and `local_procurement_import_records` stay bounded.
   - Run `OPTIMIZE TABLE local_procurement_import_rows, local_procurement_import_records;` and `SHOW TABLE STATUS WHERE Engine != 'InnoDB';` in phpMyAdmin.

## 4. Objective Verification Oracles (No Subjective Sign-Off)

The optimization is only **proven complete** when all 5 independent oracles pass:

1. **In-Process Query & Latency Oracle (`php artisan performance:profile-critical --json --runs=5`)**:
   - Diff `perf-after.json` vs `perf-baseline.json`: every authenticated path shows **≥ 2 fewer SQL queries** (`user_preferences` + `maintenance_records` eliminated on warm cache) and lower `sql_ms` / `p50_ms` with `status = 200` and `0` errors.
2. **Database Index Execution Plan Oracle (`EXPLAIN` in MySQL)**:
   - `EXPLAIN SELECT id, data, read_at FROM notifications WHERE notifiable_type = 'App\\Models\\User' AND notifiable_id = 1 AND read_at IS NULL ORDER BY created_at DESC LIMIT 30` uses `notifications_notifiable_read_created_idx` with **no `Using filesort`**.
   - `SELECT table_name, ROUND((data_length + index_length)/1024/1024, 2) AS mb FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY mb DESC LIMIT 5` confirms total DB size dropped from **~99 MB to < 10 MB** and `0` non-InnoDB tables.
3. **Config-Cache, Cache-Invalidation & DB-Safety Oracle (`php artisan test`)**:
   - `php artisan test --filter="TestingEnvironmentDatabaseSafetyTest|UserPreference|Maintenance|NotificationSummary|TrustedProxies"` exits `0`, proving cache invalidation never serves stale preferences/maintenance and `TRUSTED_PROXIES` survives `config:cache`.
4. **Live cPanel HTTP & Security Header Oracle (`curl`)**:
   - `curl -s -o /dev/null -w "%{http_code} %{time_starttransfer}\n" https://<domain>/.env` → `403` or `404` (same for `/.git/HEAD`, `/composer.json`, `/artisan`).
   - `curl -sI https://<domain>/build/assets/<hashed-app.css>` returns `Cache-Control: public, max-age=31536000, immutable` and `Content-Encoding: gzip` or `br`.
   - Warm TTFB (`time_starttransfer`) on core authenticated pages drops to **~150–350 ms**.
5. **Cron, Queue & Inode Health Oracle (cPanel + MySQL)**:
   - After 5 minutes of live cron execution: `SELECT COUNT(*) FROM jobs;` drains to `0` (no stuck `BroadcastNotificationCreated`), `failed_jobs` adds `0` new rows, and `php artisan storage:inode-guard --dry-run` confirms expired cache files are pruned cleanly without unbounded inode growth.
