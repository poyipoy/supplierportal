<?php

return [
    'enabled' => (bool) env('SSO_LOGIN_ENABLED', false),
    'client_id' => env('SSO_CLIENT_ID', ''),
    'state_prefix' => env('SSO_STATE_PREFIX', 'ADASI'),
    'transaction_ttl_seconds' => 600,
    'max_pending_transactions' => 5,
    'scope' => 'openid',
];
