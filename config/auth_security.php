<?php

return [
    'password' => [
        'min' => 12,
        'max' => 255,
        'uncompromised_in_production' => true,
    ],

    'login' => [
        'combination' => ['attempts' => 5, 'decay_seconds' => 60],
        'email' => ['attempts' => 12, 'decay_seconds' => 900],
        'ip' => ['attempts' => 30, 'decay_seconds' => 300],

        // Flags a credential-stuffing pattern: many distinct emails attempted
        // from the same IP in a short window (independent of per-identity
        // attempt counts above, which don't catch "wide" attacks).
        'distinct_email' => [
            'threshold' => (int) env('AUTH_DISTINCT_EMAIL_THRESHOLD', 5),
            'window_seconds' => (int) env('AUTH_DISTINCT_EMAIL_WINDOW', 300),
        ],

        // Application-wide failed-login velocity brake for distributed
        // credential stuffing. It only forces Turnstile and is deliberately
        // excluded from the definitions that can return HTTP 429.
        'global' => [
            'attempts' => (int) env('AUTH_GLOBAL_FAILED_LOGIN_THRESHOLD', 200),
            'decay_seconds' => (int) env('AUTH_GLOBAL_FAILED_LOGIN_WINDOW', 300),
        ],

        // Alerts admins when a single account is locked out repeatedly within
        // an hour, which suggests a targeted attack rather than a user simply
        // mistyping their password.
        'repeated_lockout_alert' => [
            'threshold' => (int) env('AUTH_REPEATED_LOCKOUT_ALERT_THRESHOLD', 3),
            'window_seconds' => 3600,
        ],
    ],

    'rate_limits' => [
        'email_security' => [
            'subject' => ['attempts' => 3, 'decay_seconds' => 900],
            'guest_ip' => ['attempts' => 10, 'decay_seconds' => 900],
        ],

        'credentials' => [
            'subject' => ['attempts' => 5, 'decay_seconds' => 300],
            'guest_ip' => ['attempts' => 10, 'decay_seconds' => 300],
        ],

        'mfa_code' => [
            'subject' => ['attempts' => 5, 'decay_seconds' => 300],
        ],

        'security_action' => [
            'subject' => ['attempts' => 3, 'decay_seconds' => 900],
        ],
    ],

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'verify_url' => env('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
        'timeout_seconds' => (int) env('TURNSTILE_TIMEOUT_SECONDS', 3),
        'failure_threshold' => 3,
    ],

    'two_factor' => [
        'pending_lifetime_seconds' => 600,
        'secret_length' => 32,
        'window' => 1,
        'recovery_code_count' => 8,
    ],

    'password_confirmation' => [
        'continuation_lifetime_seconds' => 600,
    ],

    'session' => [
        'absolute_timeout_minutes' => (int) env('AUTH_ABSOLUTE_TIMEOUT', 480),

        // Maximum number of concurrent active sessions allowed per account
        // (across all devices, including the one currently signing in). When
        // a new login pushes the count above this limit, the oldest sessions
        // (by last activity) are evicted first. 0 disables the cap.
        'max_concurrent_sessions' => (int) env('AUTH_MAX_CONCURRENT_SESSIONS', 3),
    ],

    'known_device' => [
        'cookie_name' => env('AUTH_KNOWN_DEVICE_COOKIE', 'adasi_known_device'),
        'lifetime_days' => (int) env('AUTH_KNOWN_DEVICE_LIFETIME_DAYS', 400),
    ],

    'audit' => [
        'retention_days' => (int) env('AUTH_AUDIT_RETENTION_DAYS', 180),
        'user_agent_max_length' => 512,
    ],

    'headers' => [
        'csp_report_uri' => env('CSP_REPORT_URI'),
    ],
];
