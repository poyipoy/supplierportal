# Auth Security Deployment Runbook

This auth stack includes optional two-factor authentication, layered login throttling, authentication audit logs, persistent known-device alerts, database-session inventory, and security response headers.

## Before deployment

1. Back up the production database.
2. Store a recoverable copy of the existing `APP_KEY`. Do not rotate it during this deployment; TOTP secrets depend on it.
3. Confirm the cPanel domain is served directly over HTTPS using AutoSSL or Let's Encrypt.
4. Run `composer install --no-dev --optimize-autoloader` in the release directory.
5. Confirm the database queue worker is active for `exports,default` and inspect `php artisan queue:failed`.

## Environment

Use these production values as a baseline:

```dotenv
APP_ENV=production
APP_URL=https://portal.example.com
CACHE_STORE=database
SESSION_DRIVER=database
SESSION_LIFETIME=480
AUTH_ABSOLUTE_TIMEOUT=480
AUTH_AUDIT_RETENTION_DAYS=180
AUTH_MAX_CONCURRENT_SESSIONS=3
AUTH_DISTINCT_EMAIL_THRESHOLD=5
AUTH_DISTINCT_EMAIL_WINDOW=300
AUTH_GLOBAL_FAILED_LOGIN_THRESHOLD=200
AUTH_GLOBAL_FAILED_LOGIN_WINDOW=300
AUTH_REPEATED_LOCKOUT_ALERT_THRESHOLD=3
AUTH_KNOWN_DEVICE_COOKIE=adasi_known_device
AUTH_KNOWN_DEVICE_LIFETIME_DAYS=400
TRUSTED_PROXIES=
```

Production code forces session encryption, Secure cookies, HttpOnly cookies, and `SameSite=lax`. Keep `TRUSTED_PROXIES` empty for direct cPanel SSL. If a proxy is introduced later, use only comma-separated explicit IP or CIDR values; wildcard values are ignored.

Turnstile is disabled when either key is empty:

```dotenv
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET_KEY=
TURNSTILE_TIMEOUT_SECONDS=3
```

An optional CSP report collector can be supplied with `CSP_REPORT_URI`. The policy remains report-only in this release.

The global failed-login velocity brake detects distributed credential stuffing and forces Turnstile while active. It does not create an application-wide HTTP 429 or account lockout. Production Turnstile credentials must therefore be configured and smoke-tested.

The known-device cookie is an encrypted, first-party, HttpOnly cookie with `SameSite=lax`, Secure enabled in production, and a sliding 400-day lifetime. The database stores only a SHA-256 hash of the random browser token. Clearing cookies, using a private profile, reinstalling the browser, or creating a new browser profile causes the next successful login to be treated as a new device. The first successful login from each browser after this migration may therefore produce one expected bootstrap alert.

## Queued security email (cPanel SMTP)

Password-reset email and new-device security email are queued on the `default` queue. Configure SMTP only in the cPanel staging/production environment. Do not put a mailbox password in the repository or share it through chat.

Use the public HTTPS staging domain for `APP_URL`; reset links that use a local `.test` address cannot be opened from another device.

```dotenv
APP_URL=https://portal-staging.example.com
MAIL_MAILER=smtp
MAIL_HOST=mail.example.com
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=no-reply@example.com
MAIL_PASSWORD=<mailbox-password-from-cpanel>
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME="ADASI Supplier Portal"
QUEUE_CONNECTION=database
```

If cPanel's **Configure Mail Client** screen specifies port `587`, use `MAIL_PORT=587` and `MAIL_SCHEME=smtp` instead. After changing the deployed environment file, rebuild the configuration cache:

```sh
php artisan config:clear
php artisan config:cache
```

The production worker must listen to `exports,default` so exports keep priority while queued security mail is also processed:

```sh
php artisan queue:work database --queue=exports,default --sleep=1 --stop-when-empty --max-time=50 --tries=3 --timeout=600
```

Then submit the Forgot Password form using a real staging account. Check both Inbox and Spam, open the HTTPS reset link from a separate device, set a compliant new password, and log in again. Confirm the queued job leaves the `jobs` table and no new entry appears in `failed_jobs`.

## Additive migrations

The auth hardening stack relies on these additive migrations:

- `2026_08_13_000001_add_auth_security_fields_to_users_table.php`
- `2026_08_13_000002_create_auth_audit_logs_table.php`
- `2026_08_28_000001_create_auth_known_devices_table.php`

Run them after confirming the active connection points to production and the backup is complete:

```sh
php artisan migrate --force
```

Then deploy the application code and refresh caches:

```sh
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Scheduler

Configure cPanel Cron Jobs to run every minute:

```cron
* * * * * cd /path/to/application && php artisan schedule:run >> /dev/null 2>&1
```

Authentication audit logs older than 180 days are pruned daily. Manual fallback:

```sh
php artisan model:prune --model="App\Models\AuthAuditLog"
```

## Smoke tests

- Login and logout with an active Admin account without MFA.
- Confirm inactive and invalid credentials return the same generic failure.
- Confirm known and unknown Forgot Password requests return the same generic response.
- Submit the same invalid reset token for a registered and unregistered email and confirm the visible error is identical.
- Confirm an inactive account receives no reset notification and cannot complete a reset with a valid stale token.
- Enroll MFA, save all eight recovery codes, log out, and complete a new MFA challenge.
- Use one recovery code and verify it cannot be reused.
- Verify “Remember me” still requires MFA on a restored MFA-enabled session.
- Change a password and verify other devices are signed out.
- Sign in from a browser for the first time and confirm both the in-app notification and queued email arrive.
- Sign in again with the same browser cookie after changing IP or User-Agent and confirm the new-device alert does not repeat.
- Open the alert and confirm `/profile#active-sessions` resolves to the Active Sessions list.
- Create a fourth active session and confirm only the oldest active session is evicted; idle-expired rows must not consume a slot.
- Open Authentication Audit as Admin and confirm other roles receive 403.
- Verify login and MFA responses contain no-store and report-only CSP headers.

## Rollback

Application code may be rolled back while leaving all additive auth migrations in place. In particular, leave `auth_known_devices` in place after production has used it; older code ignores the extra table. Do not roll back migrations after MFA enrollment, audit, or known-device data exists.

Before rolling back code that removes notification classes, pause the queue worker, let the active worker finish, inspect pending `default` jobs, and drain compatible security notifications or retain class compatibility in the rollback release. Queued notification payloads serialize class references. The long-lived known-device cookie is harmless when rollback code no longer reads it. Turnstile and CSP reporting can be disabled independently through environment configuration.
