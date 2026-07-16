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
            'capture_queries' => isset($cfg['capture_queries']) ? $cfg['capture_queries'] : true,
            'slow_query_ms' => isset($cfg['slow_query_ms']) ? $cfg['slow_query_ms'] : 1000,
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

        if ($this->app->runningInConsole()) {
            $this->publishes(array(
                __DIR__ . '/../config/bugban.php' => $this->configPath(),
            ), 'bugban-config');
        }

        if (!$config->isUsable()) {
            return;
        }

        // Auto-capture every exception Laravel reports (it logs them through the logger).
        $this->app['events']->listen(MessageLogged::class, function ($event) {
            $ctx = isset($event->context) ? $event->context : array();
            if (isset($ctx['exception']) && ($ctx['exception'] instanceof \Throwable || $ctx['exception'] instanceof \Exception)) {
                if (in_array($event->level, array('error', 'critical', 'alert', 'emergency'), true)) {
                    Bugban::capture($ctx['exception']);
                }
            }
        });

        if ($config->captureRequests) {
            $router = $this->app['router'];
            $router->pushMiddlewareToGroup('web', CaptureRequests::class);
            $router->pushMiddlewareToGroup('api', CaptureRequests::class);
        }

        // Slow-query (performance) monitoring — listen to every executed DB
        // query on every connection (MySQL/PostgreSQL/SQLite/...). The SDK
        // drops queries faster than slow_query_ms, so this stays cheap.
        if ($config->captureQueries) {
            try {
                $this->app['db']->listen(function ($query) {
                    try {
                        // Laravel >= 5.2 passes an Illuminate\Database\Events\QueryExecuted
                        // object; $query->time is already in milliseconds.
                        if (is_object($query) && isset($query->sql)) {
                            Bugban::recordQuery($query->sql, (float) $query->time, array(
                                'connection' => isset($query->connectionName) ? $query->connectionName : null,
                                'bindings' => (isset($query->bindings) && is_array($query->bindings)) ? $query->bindings : array(),
                            ));
                        }
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
     * Rich Laravel context: auth user, session id, and the current request.
     */
    public function laravelContext()
    {
        $ctx = array('request' => null, 'session' => null, 'user' => null, 'context' => array());
        try {
            if ($this->app->bound('request')) {
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
