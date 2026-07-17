<?php

return array(
    // Public project key from the Bugban panel (Projects -> your project).
    'api_key' => env('BUGBAN_API_KEY', ''),

    // Your Bugban platform host.
    'host' => env('BUGBAN_HOST', 'https://bugban.online'),

    // Shown in the Bugban panel after the one-time install ping (defaults to config('app.name')).
    'app_name' => env('BUGBAN_APP_NAME', null),

    'environment' => env('BUGBAN_ENV', env('APP_ENV', 'production')),
    'release' => env('BUGBAN_RELEASE', null),
    'enabled' => env('BUGBAN_ENABLED', true),

    // 1.0 = send everything; 0.25 = sample 25% of events.
    'sample_rate' => env('BUGBAN_SAMPLE_RATE', 1.0),

    // Also push per-request performance logs to /api/ingest/requests.
    'capture_requests' => env('BUGBAN_CAPTURE_REQUESTS', false),

    // Auto-forward log records (Log::error(), Log::critical(), caught-and-logged errors)
    // to Bugban as handled events, so problems logged but never thrown aren't missed.
    // log_level is the minimum PSR level forwarded: debug|info|notice|warning|error|
    // critical|alert|emergency. Records that carry a Throwable in context are skipped
    // here (Laravel's reported exceptions are already captured), avoiding double-reports.
    'capture_logs' => env('BUGBAN_CAPTURE_LOGS', false),
    'log_level' => env('BUGBAN_LOG_LEVEL', 'error'),

    // Slow-query (performance) monitoring: report DB queries slower than
    // slow_query_ms milliseconds to /api/ingest/queries. Works for every
    // Laravel DB connection (MySQL, PostgreSQL, SQLite, ...).
    'capture_queries' => env('BUGBAN_CAPTURE_QUERIES', true),
    'slow_query_ms' => env('BUGBAN_SLOW_QUERY_MS', 1000),

    // When a slow SELECT is captured, also run EXPLAIN on the same connection
    // and report whether an index is used, so the panel can flag full-table
    // scans. Safe: SELECT-only, guarded against reentrancy, and never throws.
    'explain_queries' => env('BUGBAN_EXPLAIN_QUERIES', true),

    // Keys scrubbed from request/session/context before sending.
    'redact' => array('password', 'password_confirmation', 'token', 'secret', 'authorization', 'cookie', 'api_key'),
);
