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

## Background runs (Artisan, scheduler, queue)
Nothing to configure. Every Artisan command, scheduled task and queue job becomes a *run* in the panel's **Processes** tab (duration, CPU, memory, query count, slow queries, exit code, error). The provider listens to `CommandStarting`, `ScheduledTaskStarting` and `JobProcessing`, so a `queue:work` worker is split into its jobs and a `schedule:run` into its tasks. Slow queries captured inside a job are attributed to that job. Turn it off with `BUGBAN_CAPTURE_RUNS=false`.

## Updating the SDK
```bash
php artisan bugban:update --check     # exit 10 when a newer version is published
php artisan bugban:update             # composer require bugban/php-sdk:^<latest> bugban/laravel:^<latest>
php artisan bugban:update --dry-run
```
Automatic: `BUGBAN_AUTO_UPDATE=true` schedules `bugban:update --yes` daily (needs your `schedule:run` cron; `withoutOverlapping`, runs in background). Restart `queue:work` / Horizon afterwards so workers load the new code.

## How it works
- Hooks Laravel's `MessageLogged` event and forwards any logged exception (level `error`+).
- Resolves rich context (auth user, session, request) lazily via the core `context_resolver`.
- When `BUGBAN_CAPTURE_REQUESTS=true`, pushes request timing to `/api/ingest/requests` via a terminable middleware added to the `web` and `api` groups.
- **Slow queries**: listens to `DB::listen` on every connection (MySQL/PostgreSQL/SQLite/...); queries slower than `BUGBAN_SLOW_QUERY_MS` (default 1000 ms) are batched and sent non-blocking at shutdown to `/api/ingest/queries`, with the app caller file/line, connection name and redacted bindings.

PHP 7.1 → 8.4 compatible.
