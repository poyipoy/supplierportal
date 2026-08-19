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

Generated exports are processed through Laravel's dedicated `exports` database queue. On the current cPanel account, configure these cron jobs every minute:

```cron
* * * * * /usr/bin/flock -n /home/adaw2196/supplierportal/storage/framework/async-export-worker.lock /usr/local/bin/ea-php83 /home/adaw2196/supplierportal/artisan queue:work database --queue=exports,default --sleep=1 --max-time=50 --tries=3 --timeout=600 >> /home/adaw2196/supplierportal/storage/logs/queue-worker.log 2>&1
* * * * * /usr/local/bin/ea-php83 /home/adaw2196/supplierportal/artisan schedule:run >> /dev/null 2>&1
```

Keep `QUEUE_CONNECTION=database` and `DB_QUEUE_RETRY_AFTER=660` in the deployed environment, ensure `storage/app/private` is writable, and run `php artisan optimize:clear` once after deployment. Do not add `--stop-when-empty`: the worker stays available for up to 50 seconds so new exports are picked up quickly, while `flock` prevents overlapping workers. Without the worker, export requests remain in the `queued` status. The scheduler removes completed or failed export records after their three-day retention period.
