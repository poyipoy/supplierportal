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
    ],

    'audit' => [
        'retention_days' => (int) env('AUTH_AUDIT_RETENTION_DAYS', 180),
        'user_agent_max_length' => 512,
    ],

    'headers' => [
        'csp_report_uri' => env('CSP_REPORT_URI'),
    ],
];
