<?php

return [
    'password' => [
        'min' => (int) env('AUTH_PASSWORD_MIN_LENGTH', 12),
        'max' => 255,
        'uncompromised_in_production' => true,

        // Allowed number of appearances in the HaveIBeenPwned corpus before a
        // password is rejected outright.
        'uncompromised_threshold' => 3,
    ],

    'login' => [
        'combination' => ['attempts' => 5, 'decay_seconds' => 60],

        // Aggregated across every IP, so a distributed credential-stuffing
        // pool cannot buy extra guesses by rotating source addresses. Five is
        // the hard per-account ceiling for a 15 minute window.
        'email' => ['attempts' => 5, 'decay_seconds' => 900],
        'ip' => ['attempts' => 30, 'decay_seconds' => 300],

        // Rotating-proxy backstop: collapses the client IP to its /24 (IPv4)
        // or /64 (IPv6) network before counting. Deliberately looser than the
        // single-IP limit above (30) so it never shadows it — a legitimate
        // office NAT should trip the IP limiter first, not this one.
        'subnet' => ['attempts' => 50, 'decay_seconds' => 300],

        // Synthetic response delay applied only on the failure branch, to
        // destroy scanner throughput. Bounded so a flood of failures cannot
        // starve PHP-FPM workers by pinning them all in usleep().
        'tarpit' => [
            'enabled' => (bool) env('AUTH_TARPIT_ENABLED', true),
            'threshold' => 3,
            'delay_step_ms' => 1000,
            'max_delay_ms' => 2000,
        ],

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

    // Precomputed bcrypt hash of a random 32-character string, used as the
    // verification target when the submitted email has no account or the
    // account is inactive. Hash::check() then burns the same CPU cycles as a
    // real wrong-password attempt, so response time no longer discloses
    // whether an address is registered. The cost MUST match hashing.bcrypt
    // rounds (12 in production, overridden per-environment in phpunit.xml),
    // otherwise the two branches diverge again.
    'dummy_hash' => env('AUTH_DUMMY_HASH', '$2y$12$e8p2xPjG.oQZkGgJ7K7Ie.4gPZ9Z7V1X2Y3Z4A5B6C7D8E9F0G1H2'),

    // Office network policy forbids outbound mail triggered by auth events.
    // Security alerts are delivered in-app and to auth_audit_logs instead;
    // the mail channel stays opt-in per environment.
    'notifications' => [
        'mail_enabled' => (bool) env('AUTH_SECURITY_EMAIL_NOTIFICATIONS', false),
        'in_app_enabled' => true,
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
        'csp_enforce' => (bool) env('CSP_ENFORCE', false),
    ],
];
