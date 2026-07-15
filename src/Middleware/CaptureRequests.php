<?php

namespace Bugban\Laravel\Middleware;

use Bugban\Sdk\Bugban;
use Closure;

class CaptureRequests
{
    /** @var float */
    private $start;

    public function handle($request, Closure $next)
    {
        $this->start = microtime(true);
        return $next($request);
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
            'duration_ms' => (int) round((microtime(true) - $this->start) * 1000),
            'ip' => $request->ip(),
            'occurred_at' => date('c'),
            'meta' => array('route' => $route),
        ));
    }
}
