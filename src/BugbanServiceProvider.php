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
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/bugban.php', 'bugban');
    }

    public function boot()
    {
        $cfg = $this->app['config']['bugban'];
        $self = $this;

        $config = new Config(array(
            'api_key' => isset($cfg['api_key']) ? $cfg['api_key'] : '',
            'host' => isset($cfg['host']) ? $cfg['host'] : 'https://bugban.online',
            'environment' => isset($cfg['environment']) && $cfg['environment'] ? $cfg['environment'] : $this->app->environment(),
            'release' => isset($cfg['release']) ? $cfg['release'] : null,
            'enabled' => isset($cfg['enabled']) ? $cfg['enabled'] : true,
            'sample_rate' => isset($cfg['sample_rate']) ? $cfg['sample_rate'] : 1.0,
            'capture_requests' => isset($cfg['capture_requests']) ? $cfg['capture_requests'] : false,
            'redact' => isset($cfg['redact']) ? $cfg['redact'] : null,
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
                    $ctx['request'] = array(
                        'method' => $r->method(),
                        'url' => $r->fullUrl(),
                        'path' => '/' . ltrim($r->path(), '/'),
                        'query' => $r->query(),
                        'body' => $r->except(array('password', 'password_confirmation')),
                        'headers' => $this->redactHeaders($r->headers->all()),
                        'ip' => $r->ip(),
                    );
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

    private function redactHeaders($headers)
    {
        foreach (array('authorization', 'cookie', 'x-xsrf-token') as $h) {
            if (isset($headers[$h])) {
                $headers[$h] = array('[REDACTED]');
            }
        }
        return $headers;
    }

    private function configPath()
    {
        if (function_exists('config_path')) {
            return config_path('bugban.php');
        }
        return $this->app->basePath('config/bugban.php');
    }
}
