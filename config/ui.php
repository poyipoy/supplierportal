<?php

return [
    'auth_splash' => [
        'enabled' => env('AUTH_SPLASH_ENABLED', false),
        'pending_ttl_seconds' => 30,
        'hard_timeout_ms' => 10000,
        'slow_after_ms' => 2000,
        'fade_ms' => 650,
        'cleanup_ms' => 750,
    ],
];
