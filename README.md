# Supplier Portal

Supplier Portal is a Laravel-based web application for managing supplier, material, procurement, inquiry, and document workflows.

## Main Features
- Supplier and material data management
- Procurement and inquiry workflow
- Price comparison and historical price tracking
- Document upload and attachment management
- Import and export data
- Server-side data processing for large datasets
- Role-based access and dashboard views

## Tech Stack
- Laravel
- PHP
- MySQL
- Blade
- JavaScript
- Bootstrap / DataTables

## Goal
This project aims to reduce manual spreadsheet-based work and provide a centralized system for supplier and procurement operations.

## Queue Worker and Scheduler

The application uses three database queues: `exports` (generated exports), `imports` (Local PO/GR imports) and `default` (realtime notification pushes). Each needs a worker. The canonical Cron lines, the two-worker fallback, timeouts and the rollback procedure are in [docs/guides/CPANEL-PERFORMANCE-RUNTIME.md](docs/guides/CPANEL-PERFORMANCE-RUNTIME.md) §5. In short, run the scheduler plus one short-lived worker per queue every minute, each with its own `flock` lock and log:

```cron
* * * * * cd <APP> && <PHP> artisan schedule:run >> /dev/null 2>&1
* * * * * cd <APP> && /usr/bin/flock -n <APP>/storage/framework/async-export-worker.lock <PHP> artisan queue:work database --queue=exports --sleep=1 --max-time=50 --tries=3 --timeout=600 >> <APP>/storage/logs/queue-worker.log 2>&1
* * * * * cd <APP> && /usr/bin/flock -n <APP>/storage/framework/import-worker.lock <PHP> artisan queue:work database --queue=imports --sleep=1 --max-time=50 --tries=3 --timeout=300 --memory=384 >> <APP>/storage/logs/import-worker.log 2>&1
* * * * * cd <APP> && /usr/bin/flock -n <APP>/storage/framework/default-worker.lock <PHP> artisan queue:work database --queue=default --sleep=1 --max-time=50 --tries=3 --timeout=60 >> <APP>/storage/logs/default-worker.log 2>&1
```

Keep `QUEUE_CONNECTION=database` and `DB_QUEUE_RETRY_AFTER=660` in the deployed environment and ensure `storage/app/private` is writable. After deployment, upload `public/build` from the same build as `manifest.json`, rebuild the caches (`config:cache`, `route:cache`, `view:cache`, `icons:cache`) and run `queue:restart`; `optimize:clear` alone leaves every cache empty. The full deployment and rollback order is in the same guide, §3–§3.3. Do not add `--stop-when-empty`: the worker stays available for up to 50 seconds so new jobs are picked up quickly, while `flock` prevents overlapping workers. Without a worker, jobs on that queue stay in the `queued` status. The scheduler removes completed or failed export records after their three-day retention period.
