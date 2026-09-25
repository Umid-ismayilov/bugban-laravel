<?php

namespace Bugban\Laravel\Middleware;

use Bugban\Sdk\Bugban;
use Closure;

class CaptureRequests
{
    const START_ATTR = '_bugban_start';

    public function handle($request, Closure $next)
    {
        // Laravel resolves a NEW middleware instance for terminate() unless it
        // is bound as a singleton, so the start time must live on the request,
        // not on $this (<= 1.7.1 sent an epoch timestamp as duration_ms).
        if (isset($request->attributes)) {
            $request->attributes->set(self::START_ATTR, microtime(true));
        }
        return $next($request);
    }

    /**
     * @return int|null
     */
    private function durationMs($request)
    {
        $start = null;
        if (isset($request->attributes)) {
            $start = $request->attributes->get(self::START_ATTR);
        }
        if (!$start && defined('LARAVEL_START')) {
            $start = LARAVEL_START;
        }
        if (!$start && isset($_SERVER['REQUEST_TIME_FLOAT'])) {
            $start = $_SERVER['REQUEST_TIME_FLOAT'];
        }
        if (!$start) {
            return null;
        }
        $ms = (int) round((microtime(true) - (float) $start) * 1000);
        return ($ms >= 0 && $ms <= 86400000) ? $ms : null;
    }

    public function terminate($request, $response)
    {
        $client = Bugban::client();
        if (!$client) {
            return;
        }

        $status = (is_object($response) && method_exists($response, 'getStatusCode'))
            ? $response->getStatusCode()
            : null;

        $route = method_exists($request, 'route') && $request->route()
            ? $request->route()->getName()
            : null;

        $client->captureRequest(array(
            'method' => $request->method(),
            'path' => '/' . ltrim($request->path(), '/'),
            'status_code' => $status,
            'duration_ms' => $this->durationMs($request),
            'ip' => $request->ip(),
            'occurred_at' => date('c'),
            'meta' => array('route' => $route),
        ));
    }
}
