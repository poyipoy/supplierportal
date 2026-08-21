# Auth Security Deployment Runbook

This change adds optional two-factor authentication, layered login throttling, authentication audit logs, cross-driver session revocation, and security response headers.

## Before deployment

1. Back up the production database.
2. Store a recoverable copy of the existing `APP_KEY`. Do not rotate it during this deployment; TOTP secrets depend on it.
3. Confirm the cPanel domain is served directly over HTTPS using AutoSSL or Let's Encrypt.
4. Run `composer install --no-dev --optimize-autoloader` in the release directory.

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

## Password-reset email (cPanel SMTP)

Password reset emails are sent directly during the request, so `php artisan queue:work` is not required for this feature. Configure SMTP only in the cPanel staging/production environment. Do not put a mailbox password in the repository or share it through chat.

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
```

If cPanel's **Configure Mail Client** screen specifies port `587`, use `MAIL_PORT=587` and `MAIL_SCHEME=smtp` instead. After changing the deployed environment file, rebuild the configuration cache:

```sh
php artisan config:clear
php artisan config:cache
```

Then submit the Forgot Password form using a real staging account. Check both Inbox and Spam, open the HTTPS reset link from a separate device, set a compliant new password, and log in again. The branded reset email is sent synchronously; a transport failure is logged without the email address, token, or password.

## Additive migrations

Only these migrations belong to this release:

- `2026_08_13_000001_add_auth_security_fields_to_users_table.php`
- `2026_08_13_000002_create_auth_audit_logs_table.php`

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
- Enroll MFA, save all eight recovery codes, log out, and complete a new MFA challenge.
- Use one recovery code and verify it cannot be reused.
- Verify “Remember me” still requires MFA on a restored MFA-enabled session.
- Change a password and verify other devices are signed out.
- Open Authentication Audit as Admin and confirm other roles receive 403.
- Verify login and MFA responses contain no-store and report-only CSP headers.

## Rollback

Application code may be rolled back while leaving both additive migrations in place. Do not roll back the migrations after any MFA enrollment or audit data exists, because doing so deletes those values. Turnstile and CSP reporting can be disabled independently through environment configuration.
