# Bugban SDK — Laravel

Automatic exception, request, auth-user & session capture for Laravel (5.5 → 12), on top of the framework-agnostic [`bugban/php-sdk`](../php-sdk) core.

## Install
```bash
composer require bugban/laravel
```
The service provider is auto-discovered. Optionally publish the config:
```bash
php artisan vendor:publish --tag=bugban-config
```

## Configure — `.env`
```
BUGBAN_API_KEY=bb_xxxxxxxx
BUGBAN_HOST=https://bugban.online
BUGBAN_CAPTURE_REQUESTS=true      # optional: per-request performance logs
BUGBAN_CAPTURE_QUERIES=true       # optional: slow-query monitoring (default: on)
BUGBAN_SLOW_QUERY_MS=1000         # optional: report queries slower than this (ms)
BUGBAN_CAPTURE_LOGS=true          # optional: forward Log::error()+ to Bugban (default: off)
BUGBAN_LOG_LEVEL=error            # optional: minimum PSR level forwarded (default: error)
```

That's it. Every exception Laravel reports is sent to Bugban together with the authenticated user, session id and request. No code changes required.

## Manual capture
```php
try {
    // ...
} catch (\Throwable $e) {
    \Bugban\Sdk\Bugban::capture($e, ['order_id' => 123]);
}
```

## Log capture
With `BUGBAN_CAPTURE_LOGS=true`, a Monolog handler is pushed onto the default log channel and forwards every record at/above `BUGBAN_LOG_LEVEL` (default `error`) to Bugban — so `Log::error('...')`, `Log::critical('...')` and *caught-and-logged* errors that never re-throw are no longer missed. File logging is unaffected (the handler bubbles). Records that carry a `Throwable` in their context are skipped, because Laravel's reported exceptions are already captured — this avoids reporting the same uncaught exception twice.

## How it works
- Hooks Laravel's `MessageLogged` event and forwards any logged exception (level `error`+).
- Resolves rich context (auth user, session, request) lazily via the core `context_resolver`.
- When `BUGBAN_CAPTURE_REQUESTS=true`, pushes request timing to `/api/ingest/requests` via a terminable middleware added to the `web` and `api` groups.
- **Slow queries**: listens to `DB::listen` on every connection (MySQL/PostgreSQL/SQLite/...); queries slower than `BUGBAN_SLOW_QUERY_MS` (default 1000 ms) are batched and sent non-blocking at shutdown to `/api/ingest/queries`, with the app caller file/line, connection name and redacted bindings.

PHP 7.1 → 8.4 compatible.
