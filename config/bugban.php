<?php

return array(
    // Public project key from the Bugban panel (Projects -> your project).
    'api_key' => env('BUGBAN_API_KEY', ''),

    // Your Bugban platform host.
    'host' => env('BUGBAN_HOST', 'https://bugban.online'),

    'environment' => env('BUGBAN_ENV', env('APP_ENV', 'production')),
    'release' => env('BUGBAN_RELEASE', null),
    'enabled' => env('BUGBAN_ENABLED', true),

    // 1.0 = send everything; 0.25 = sample 25% of events.
    'sample_rate' => env('BUGBAN_SAMPLE_RATE', 1.0),

    // Also push per-request performance logs to /api/ingest/requests.
    'capture_requests' => env('BUGBAN_CAPTURE_REQUESTS', false),

    // Keys scrubbed from request/session/context before sending.
    'redact' => array('password', 'password_confirmation', 'token', 'secret', 'authorization', 'cookie', 'api_key'),
);
