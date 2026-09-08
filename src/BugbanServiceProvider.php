<?php

namespace Bugban\Laravel;

use Bugban\Laravel\Middleware\CaptureRequests;
use Bugban\Sdk\Bugban;
use Bugban\Sdk\Client;
use Bugban\Sdk\Config;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\ServiceProvider;

class BugbanServiceProvider extends ServiceProvider
{
    /** @var array Keys to redact from request body/query/headers/cookies. */
    private $redactKeys = array('password', 'password_confirmation', 'token', 'secret', 'authorization', 'cookie', 'api_key');

    /**
     * @var bool Reentrancy guard. True while we run EXPLAIN on a slow query;
     * the EXPLAIN query itself fires DB::listen, so the listener bails when set.
     */
    private static $explaining = false;

    /** @var string|null Name of the artisan command currently running (console only). */
    /** @var array|null Queue job currently being processed by this worker (class, queue, attempts). */
    public $currentJob = null;

    public $currentCommand = null;

    /**
     * Report an exception that reached Laravel's own handler (unhandled).
     * Guarded: an older bugban/php-sdk without captureUnhandled() must degrade
     * to capture(), never fatal — the v1.5.2 production-crash rule.
     *
     * @param \Throwable|\Exception $e
     * @return void
     */
    public static function reportUnhandled($e, array $extra = array())
    {
        if (method_exists('Bugban\\Sdk\\Bugban', 'captureUnhandled')) {
            Bugban::captureUnhandled($e, $extra);
        } else {
            Bugban::capture($e, $extra);
        }
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/bugban.php', 'bugban');
    }

    public function boot()
    {
        $cfg = $this->app['config']['bugban'];
        $self = $this;

        if (isset($cfg['redact']) && is_array($cfg['redact'])) {
            $this->redactKeys = $cfg['redact'];
        }

        $config = new Config(array(
            'api_key' => isset($cfg['api_key']) ? $cfg['api_key'] : '',
            'host' => isset($cfg['host']) ? $cfg['host'] : 'https://bugban.online',
            'environment' => isset($cfg['environment']) && $cfg['environment'] ? $cfg['environment'] : $this->app->environment(),
            'release' => isset($cfg['release']) ? $cfg['release'] : null,
            'enabled' => isset($cfg['enabled']) ? $cfg['enabled'] : true,
            'sample_rate' => isset($cfg['sample_rate']) ? $cfg['sample_rate'] : 1.0,
            'capture_requests' => isset($cfg['capture_requests']) ? $cfg['capture_requests'] : false,
            'capture_logs' => isset($cfg['capture_logs']) ? $cfg['capture_logs'] : false,
            'log_level' => isset($cfg['log_level']) && $cfg['log_level'] ? $cfg['log_level'] : 'error',
            'capture_queries' => isset($cfg['capture_queries']) ? $cfg['capture_queries'] : true,
            'capture_runs' => isset($cfg['capture_runs']) ? $cfg['capture_runs'] : true,
            'auto_update' => isset($cfg['auto_update']) ? $cfg['auto_update'] : false,
            'slow_query_ms' => isset($cfg['slow_query_ms']) ? $cfg['slow_query_ms'] : 1000,
            'explain_queries' => isset($cfg['explain_queries']) ? $cfg['explain_queries'] : true,
            'allow_query_test' => isset($cfg['allow_query_test']) ? $cfg['allow_query_test'] : true,
            'redact' => isset($cfg['redact']) ? $cfg['redact'] : null,
            'app_name' => (isset($cfg['app_name']) && $cfg['app_name']) ? $cfg['app_name'] : $this->appName(),
            'framework' => 'laravel',
            'framework_version' => $this->frameworkVersion(),
            'sdk' => 'bugban/laravel',
            'context_resolver' => function () use ($self) {
                return $self->laravelContext();
            },
        ));

        $client = new Client($config);
        Bugban::setClient($client);
        $this->app->instance(Client::class, $client);

        // Query test runner: re-runs one of this app's own captured SELECTs on
        // its own connection so the panel can show whether a fix helped. Always
        // inside a rolled-back transaction; only the row COUNT is returned.
        // DEFENSIVE: the core SDK may be older than this adapter (a stale
        // composer.lock, a leftover manual libs/ copy loaded first, or opcache
        // still holding the old class). Monitoring must never take the host
        // application down, so probe before calling anything new.
        if (!method_exists($client, 'setQueryRunner')) {
            return;
        }

        // $returnRows: the tester asks for the actual rows when it runs EXPLAIN,
        // so the panel can show a CURRENT index verdict instead of one captured
        // before the developer's fix. Otherwise only the row count is needed.
        $client->setQueryRunner(function ($sql, array $bindings, $returnRows = false) {
            $connection = \Illuminate\Support\Facades\DB::connection();
            $connection->beginTransaction();
            try {
                $rows = $connection->select($sql, $bindings);
                if (! is_array($rows)) {
                    return $returnRows ? array() : 0;
                }
                if ($returnRows) {
                    return array_map(function ($r) { return (array) $r; }, $rows);
                }

                return count($rows);
            } finally {
                try {
                    $connection->rollBack();
                } catch (\Exception $e) {
                    // Nothing was written; a failed rollback is not fatal.
                }
            }
        });

        if ($this->app->runningInConsole()) {
            $this->publishes(array(
                __DIR__ . '/../config/bugban.php' => $this->configPath(),
            ), 'bugban-config');
            $this->commands(array('Bugban\\Laravel\\Console\\UpdateCommand'));
            $this->scheduleAutoUpdate($cfg, $config);
        }

        if (!$config->isUsable()) {
            return;
        }

        // Remember which artisan command is running so console errors carry
        // "artisan <command>" + the command class instead of a fake "GET /".
        if ($this->app->runningInConsole() && class_exists('Illuminate\\Console\\Events\\CommandStarting')) {
            $self = $this;
            $this->app['events']->listen('Illuminate\\Console\\Events\\CommandStarting', function ($event) use ($self) {
                $self->currentCommand = isset($event->command) ? (string) $event->command : null;
                if ($self->currentCommand !== null && $self->currentCommand !== '') {
                    // Per-run record: the real artisan command name, not argv guesswork.
                    Bugban::setCommand($self->currentCommand);
                }
            });
            if (class_exists('Illuminate\\Console\\Events\\CommandFinished')) {
                $this->app['events']->listen('Illuminate\\Console\\Events\\CommandFinished', function ($event) {
                    Bugban::setExitCode(isset($event->exitCode) ? (int) $event->exitCode : 0);
                });
            }
            if (class_exists('Illuminate\\Console\\Events\\ScheduledTaskStarting')) {
                // Tasks run inline by `schedule:run` (closures / callables): each one
                // becomes its own unit of work instead of hiding behind "schedule:run".
                $this->app['events']->listen('Illuminate\\Console\\Events\\ScheduledTaskStarting', function ($event) use ($self) {
                    $name = $self->scheduledTaskName($event);
                    if ($name !== null) {
                        Bugban::beginJob($name, array('kind' => 'scheduled_task'), 'scheduler');
                    }
                });
                $this->app['events']->listen('Illuminate\\Console\\Events\\ScheduledTaskFinished', function ($event) {
                    Bugban::endJob(0);
                });
                if (class_exists('Illuminate\\Console\\Events\\ScheduledTaskFailed')) {
                    $this->app['events']->listen('Illuminate\\Console\\Events\\ScheduledTaskFailed', function ($event) {
                        $err = isset($event->exception) && is_object($event->exception) ? get_class($event->exception) . ': ' . $event->exception->getMessage() : 'failed';
                        Bugban::endJob(1, $err);
                    });
                }
            }
        }

        // Queue worker: remember the job being processed so errors inside it say
        // "App\Jobs\SendInvoice" instead of just "artisan queue:work", and so the
        // run record / slow queries are attributed per JOB, not per worker process.
        if ($this->app->runningInConsole() && class_exists('Illuminate\\Queue\\Events\\JobProcessing')) {
            $self = $this;
            $this->app['events']->listen('Illuminate\\Queue\\Events\\JobProcessing', function ($event) use ($self) {
                $job = $self->describeJob($event);
                $self->currentJob = $job;
                if ($job !== null) {
                    Bugban::beginJob($job['class'], array('queue' => $job['queue'], 'attempts' => $job['attempts'], 'connection' => $job['connection']));
                }
            });
            $this->app['events']->listen('Illuminate\\Queue\\Events\\JobProcessed', function ($event) use ($self) {
                $self->currentJob = null;
                Bugban::endJob(0);
            });
            $this->app['events']->listen('Illuminate\\Queue\\Events\\JobFailed', function ($event) use ($self) {
                $self->currentJob = null;
                $err = isset($event->exception) && is_object($event->exception) ? get_class($event->exception) . ': ' . $event->exception->getMessage() : 'failed';
                Bugban::endJob(1, $err);
            });
            if (class_exists('Illuminate\\Queue\\Events\\JobExceptionOccurred')) {
                // Fired before a retry is scheduled; the job did not finish cleanly.
                $this->app['events']->listen('Illuminate\\Queue\\Events\\JobExceptionOccurred', function ($event) use ($self) {
                    $self->currentJob = null;
                    $err = isset($event->exception) && is_object($event->exception) ? get_class($event->exception) . ': ' . $event->exception->getMessage() : 'exception';
                    Bugban::endJob(1, $err);
                });
            }
        }

        // Auto-capture every exception Laravel reports (it logs them through the logger).
        $this->app['events']->listen(MessageLogged::class, function ($event) {
            $ctx = isset($event->context) ? $event->context : array();
            if (isset($ctx['exception']) && ($ctx['exception'] instanceof \Throwable || $ctx['exception'] instanceof \Exception)) {
                if (in_array($event->level, array('error', 'critical', 'alert', 'emergency'), true)) {
                    // Laravel's handler logs every exception nobody caught → these are
                    // UNHANDLED in Bugsnag terms. Explicit Bugban::capture() stays handled.
                    self::reportUnhandled($ctx['exception']);
                }
            }
        });

        // Auto-forward Log::error()/critical()/... records (and caught-and-logged errors
        // that never re-throw) to Bugban. Push a Monolog handler onto the default log
        // channel; it bubbles (file logging still happens) and the SDK's recursion guard
        // keeps forwarding from looping. Records carrying a Throwable are skipped by the
        // handler because the MessageLogged listener above already reports those.
        if ($config->captureLogs) {
            try {
                $logger = \Illuminate\Support\Facades\Log::getLogger();
                if ($logger instanceof \Monolog\Logger) {
                    $logger->pushHandler(new BugbanLogHandler($config->logLevel, true));
                }
            } catch (\Exception $e) {
                // never break the host app
            } catch (\Throwable $e) {
                // non-fatal
            }
        }

        if ($config->captureRequests) {
            $router = $this->app['router'];
            $router->pushMiddlewareToGroup('web', CaptureRequests::class);
            $router->pushMiddlewareToGroup('api', CaptureRequests::class);
        }

        // Slow-query (performance) monitoring — listen to every executed DB
        // query on every connection (MySQL/PostgreSQL/SQLite/...). The SDK
        // drops queries faster than slow_query_ms, so this stays cheap.
        if ($config->captureQueries) {
            $explainEnabled = $config->explainQueries;
            $slowQueryMs = $config->slowQueryMs;
            try {
                $this->app['db']->listen(function ($query) use ($self, $explainEnabled, $slowQueryMs) {
                    try {
                        // The EXPLAIN query itself fires this listener — bail so it
                        // is neither captured nor re-explained.
                        if (self::$explaining) {
                            return;
                        }
                        // Laravel >= 5.2 passes an Illuminate\Database\Events\QueryExecuted
                        // object; $query->time is already in milliseconds.
                        if (!(is_object($query) && isset($query->sql))) {
                            return;
                        }
                        $sql = $query->sql;
                        $time = (float) $query->time;
                        $connName = isset($query->connectionName) ? $query->connectionName : null;
                        $bindings = (isset($query->bindings) && is_array($query->bindings)) ? $query->bindings : array();

                        $meta = array('connection' => $connName, 'bindings' => $bindings);

                        // Only EXPLAIN slow, plain SELECTs — and never let it throw.
                        if ($explainEnabled && $time >= $slowQueryMs && $self->bugbanIsSelect($sql)) {
                            $explain = $self->bugbanExplainQuery($connName, $sql, $bindings);
                            if (is_array($explain)) {
                                $meta['explain'] = $explain;
                            }
                        }

                        Bugban::recordQuery($sql, $time, $meta);
                    } catch (\Exception $e) {
                        // never break the host app
                    } catch (\Throwable $e) {
                    }
                });
            } catch (\Exception $e) {
                // never break the host app
            } catch (\Throwable $e) {
            }
        }
    }

    /**
     * @param string $sql
     * @return bool True for a plain SELECT statement.
     */
    public function bugbanIsSelect($sql)
    {
        return stripos(ltrim((string) $sql), 'select') === 0;
    }

    /**
     * Run EXPLAIN on the SAME connection and normalize it via ExplainParser.
     * Guarded by a static reentrancy flag (the EXPLAIN query re-enters
     * DB::listen) and wrapped so it NEVER throws — on any failure returns null
     * and the query is reported without explain.
     *
     * @param string|null $connName
     * @param string      $sql
     * @param array       $bindings
     * @return array|null
     */
    public function bugbanExplainQuery($connName, $sql, $bindings)
    {
        if (self::$explaining) {
            return null;
        }
        self::$explaining = true;
        $explain = null;
        try {
            $conn = $this->app['db']->connection($connName);
            $driver = method_exists($conn, 'getDriverName') ? $conn->getDriverName() : null;

            if ($driver === 'sqlite') {
                $prefix = 'EXPLAIN QUERY PLAN ';
            } elseif ($driver === 'mysql' || $driver === 'mariadb' || $driver === 'pgsql') {
                $prefix = 'EXPLAIN ';
            } else {
                // Unknown/unsupported driver (e.g. sqlsrv) — skip explain.
                self::$explaining = false;
                return null;
            }

            $rows = $conn->select($prefix . $sql, is_array($bindings) ? $bindings : array());
            $explain = \Bugban\Sdk\Support\ExplainParser::parse($driver, $this->bugbanRowsToArray($rows));
        } catch (\Exception $e) {
            $explain = null;
        } catch (\Throwable $e) {
            $explain = null;
        }
        self::$explaining = false;
        return $explain;
    }

    /**
     * Normalize a result set (array of stdClass rows) into assoc arrays.
     *
     * @param mixed $rows
     * @return array
     */
    private function bugbanRowsToArray($rows)
    {
        $out = array();
        if (!is_array($rows)) {
            return $out;
        }
        foreach ($rows as $r) {
            if (is_array($r)) {
                $out[] = $r;
            } elseif (is_object($r)) {
                $out[] = (array) $r;
            }
        }
        return $out;
    }

    /**
     * Rich Laravel context: auth user, session id, and the current request.
     */
    public function laravelContext()
    {
        $ctx = array('request' => null, 'session' => null, 'user' => null, 'context' => array());
        try {
            if ($this->app->runningInConsole()) {
                // In the console Laravel binds a placeholder "GET http://localhost"
                // request; reporting that would point the developer at a page that
                // was never hit. Describe the command line instead.
                $ctx['request'] = $this->consoleRequest();
            } elseif ($this->app->bound('request')) {
                $r = $this->app['request'];
                if (is_object($r) && method_exists($r, 'method')) {
                    // $r->all() merges JSON body + form input; redact secrets.
                    $input = method_exists($r, 'all') ? $r->all() : array();
                    $ctx['request'] = array(
                        'method' => $r->method(),
                        'url' => $r->fullUrl(),
                        'path' => '/' . ltrim($r->path(), '/'),
                        'query' => $this->redactInput(is_array($r->query()) ? $r->query() : (array) $r->query()),
                        'body' => $this->redactInput(is_array($input) ? $input : array()),
                        'headers' => $this->redactHeaders($r->headers->all()),
                        'cookies' => $this->redactInput($this->cookiesOf($r)),
                        'ip' => $r->ip(),
                        'content_type' => method_exists($r, 'header') ? $r->header('Content-Type') : null,
                        'user_agent' => method_exists($r, 'userAgent') ? $r->userAgent() : null,
                        'referer' => method_exists($r, 'header') ? $r->header('referer') : null,
                        'protocol' => isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : null,
                        'host' => method_exists($r, 'getHost') ? $r->getHost() : null,
                    );
                    $this->attachRoute($ctx['request'], $r);
                    if (method_exists($r, 'hasSession') && $r->hasSession()) {
                        $ctx['session'] = array('id' => $r->session()->getId());
                    }
                }
            }
            $auth = $this->app['auth'];
            if ($auth->check()) {
                $u = $auth->user();
                $ctx['user'] = array(
                    'id' => method_exists($u, 'getAuthIdentifier') ? $u->getAuthIdentifier() : null,
                    'email' => isset($u->email) ? $u->email : null,
                    'name' => isset($u->name) ? $u->name : null,
                );
            }
        } catch (\Exception $e) {
            // never break the host app while collecting context
        } catch (\Throwable $e) {
        }

        return $ctx;
    }

    /**
     * "Request" block for artisan/queue/scheduler runs: method CLI, the full
     * command line as path, the command name as route and its class as action —
     * the panel's header renders it with the same code path as HTTP requests.
     *
     * @return array
     */
    private function consoleRequest()
    {
        $req = null;
        if (method_exists('Bugban\\Sdk\\Support\\ContextCollector', 'cliRequest')) {
            $req = \Bugban\Sdk\Support\ContextCollector::cliRequest();
        }
        if (!is_array($req)) {
            $argv = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : array('artisan');
            $line = implode(' ', array_map('strval', $argv));
            $req = array('method' => 'CLI', 'path' => basename((string) $argv[0]) . (count($argv) > 1 ? ' ' . implode(' ', array_slice($argv, 1)) : ''), 'url' => $line);
        }
        if (is_array($this->currentJob) && !empty($this->currentJob['class'])) {
            // Inside a queue worker the interesting unit is the JOB. The argv line
            // (worker command) stays in url/path; route/action name the job.
            $req['route'] = $this->currentJob['class'];
            $req['action'] = $this->currentJob['class'] . '@handle';
            $req['source'] = 'queue';
            if (!empty($this->currentJob['queue'])) {
                $req['queue'] = $this->currentJob['queue'];
            }
            if (isset($this->currentJob['attempts'])) {
                $req['attempts'] = $this->currentJob['attempts'];
            }
            return $req;
        }
        $name = $this->currentCommand;
        if ($name === null && isset($req['route'])) {
            $name = $req['route'];
        }
        if ($name !== null) {
            $req['route'] = $name;
            try {
                $kernel = $this->app->make('Illuminate\\Contracts\\Console\\Kernel');
                if (method_exists($kernel, 'all')) {
                    $all = $kernel->all();
                    if (isset($all[$name]) && is_object($all[$name])) {
                        $req['action'] = get_class($all[$name]);
                    }
                }
            } catch (\Exception $e) {
                // command registry unavailable — name alone is still useful
            } catch (\Throwable $e) {
            }
        }
        return $req;
    }

    /**
     * Class / queue / attempt of a queue job from a JobProcessing event.
     * Works for class jobs, closure jobs and Mailable/Listener wrappers.
     *
     * @return array|null
     */
    public function describeJob($event)
    {
        try {
            if (!isset($event->job) || !is_object($event->job)) {
                return null;
            }
            $job = $event->job;
            $class = method_exists($job, 'resolveName') ? (string) $job->resolveName() : (method_exists($job, 'getName') ? (string) $job->getName() : get_class($job));
            if ($class === '') {
                return null;
            }
            return array(
                'class' => $class,
                'queue' => method_exists($job, 'getQueue') ? (string) $job->getQueue() : null,
                'attempts' => method_exists($job, 'attempts') ? (int) $job->attempts() : null,
                'connection' => isset($event->connectionName) ? (string) $event->connectionName : null,
            );
        } catch (\Exception $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Human name of a scheduled task: the artisan command it wraps, or the
     * closure's description, or its summary.
     *
     * @return string|null
     */
    public function scheduledTaskName($event)
    {
        try {
            if (!isset($event->task) || !is_object($event->task)) {
                return null;
            }
            $task = $event->task;
            if (!empty($task->description)) {
                return (string) $task->description;
            }
            if (!empty($task->command)) {
                $cmd = trim((string) $task->command);
                // "'/usr/bin/php8.2' 'artisan' emails:send" is a CHILD PROCESS:
                // it boots the app itself and reports its own run (real CPU,
                // memory, queries). Recording it here too would produce a second,
                // mislabelled row with the parent's numbers. Only non-artisan
                // exec() tasks are recorded from the scheduler.
                if (preg_match('~artisan[\'"]?\s+\S+~', $cmd)) {
                    return null;
                }
                return substr($cmd, 0, 200);
            }
            if (method_exists($task, 'getSummaryForDisplay')) {
                return (string) $task->getSummaryForDisplay();
            }
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }
        return null;
    }

    /**
     * Add route name + controller action to the request array when available.
     *
     * @param array $request (by reference)
     * @param object $r Laravel Request
     */
    private function attachRoute(array &$request, $r)
    {
        if (!method_exists($r, 'route')) {
            return;
        }
        try {
            $route = $r->route();
        } catch (\Exception $e) {
            return;
        } catch (\Throwable $e) {
            return;
        }
        if (!is_object($route)) {
            return;
        }
        if (method_exists($route, 'getName')) {
            $request['route'] = $route->getName();
        }
        if (method_exists($route, 'getActionName')) {
            $request['action'] = $route->getActionName();
        }
        if (method_exists($route, 'uri')) {
            $request['route_uri'] = $route->uri();
        }
    }

    /**
     * @param object $r Laravel Request
     * @return array
     */
    private function cookiesOf($r)
    {
        if (method_exists($r, 'cookie')) {
            $cookies = $r->cookie();
            if (is_array($cookies)) {
                return $cookies;
            }
        }
        return isset($_COOKIE) && is_array($_COOKIE) ? $_COOKIE : array();
    }

    /**
     * Recursively redact configured secret keys from an input array.
     *
     * @param array $data
     * @return array
     */
    private function redactInput(array $data)
    {
        $keys = array_map('strtolower', $this->redactKeys);
        $out = array();
        foreach ($data as $k => $v) {
            if (in_array(strtolower((string) $k), $keys, true)) {
                $out[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $out[$k] = $this->redactInput($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function redactHeaders($headers)
    {
        if (!is_array($headers)) {
            return array();
        }
        $keys = array_map('strtolower', $this->redactKeys);
        $keys = array_merge($keys, array('cookie', 'x-xsrf-token'));
        foreach ($headers as $name => $value) {
            if (in_array(strtolower((string) $name), $keys, true)) {
                $headers[$name] = array('[REDACTED]');
            }
        }
        return $headers;
    }

    /**
     * The application name (config('app.name')) — for the install ping.
     *
     * @return string|null
     */
    /**
     * BUGBAN_AUTO_UPDATE=true: run `bugban:update --yes` once a day from the
     * scheduler. Shares the core's 24h marker, so a cron/queue process that
     * already updated today makes this a no-op. Never throws.
     */
    private function scheduleAutoUpdate(array $cfg, Config $config)
    {
        try {
            $on = isset($cfg['auto_update']) ? filter_var($cfg['auto_update'], FILTER_VALIDATE_BOOLEAN) : false;
            if (!$on || !$config->isUsable() || !class_exists('Illuminate\\Console\\Scheduling\\Schedule')) {
                return;
            }
            $this->callAfterResolving('Illuminate\\Console\\Scheduling\\Schedule', function ($schedule) {
                $event = $schedule->command('bugban:update --yes --no-interaction')->daily();
                if (method_exists($event, 'withoutOverlapping')) {
                    $event->withoutOverlapping();
                }
                if (method_exists($event, 'runInBackground')) {
                    $event->runInBackground();
                }
            });
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }
    }

    private function appName()
    {
        try {
            $name = $this->app['config']['app.name'];
            return (is_string($name) && $name !== '') ? $name : null;
        } catch (\Exception $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The Laravel framework version — for the install ping.
     *
     * @return string|null
     */
    private function frameworkVersion()
    {
        try {
            if (method_exists($this->app, 'version')) {
                $v = $this->app->version();
                return (is_string($v) && $v !== '') ? $v : null;
            }
        } catch (\Exception $e) {
            // ignore
        } catch (\Throwable $e) {
            // ignore
        }
        return null;
    }

    private function configPath()
    {
        if (function_exists('config_path')) {
            return config_path('bugban.php');
        }
        return $this->app->basePath('config/bugban.php');
    }
}
