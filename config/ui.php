<?php

return [
    'auth_splash' => [
        'enabled' => (bool) env('AUTH_SPLASH_ENABLED', true),
        'pending_ttl_seconds' => 30,
        'min_visible_ms' => (int) env('AUTH_SPLASH_MIN_VISIBLE_MS', 600),
        'max_visible_ms' => (int) env('AUTH_SPLASH_MAX_VISIBLE_MS', 60000),
        'media_fallback_ms' => (int) env('AUTH_SPLASH_MEDIA_FALLBACK_MS', 500),
        'hard_timeout_ms' => (int) env('AUTH_SPLASH_MAX_VISIBLE_MS', 60000),
        'slow_after_ms' => 2000,
        'fade_ms' => (int) env('AUTH_SPLASH_FADE_MS', 500),
        'cleanup_ms' => (int) env('AUTH_SPLASH_CLEANUP_MS', 600),
    ],

    'workspace_shell' => [
        'enabled' => (bool) env('WORKSPACE_SHELL_ENABLED', true),
        'max_sessions' => (int) env('WORKSPACE_SHELL_MAX_SESSIONS', env('WORKSPACE_SHELL_MAX_TABS', 36)),
        'max_tabs' => (int) env('WORKSPACE_SHELL_MAX_SESSIONS', env('WORKSPACE_SHELL_MAX_TABS', 36)),
        'max_live_frames' => (int) env('WORKSPACE_SHELL_MAX_LIVE_FRAMES', 18),
        'stale_after_seconds' => (int) env('WORKSPACE_SHELL_STALE_AFTER_SECONDS', 180),
    ],

    'environment_tag' => [
        'enabled' => (bool) env('UI_ENVIRONMENT_TAG_ENABLED', false),
        'label' => env('UI_ENVIRONMENT_TAG_LABEL'),
    ],

    'navigation' => [
        'slow_loader_threshold_ms' => (int) env('UI_SLOW_LOADER_THRESHOLD_MS', 2300),
        'page_fade_ms' => (int) env('UI_PAGE_FADE_MS', 500),
    ],
];
