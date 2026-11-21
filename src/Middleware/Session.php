<?php

namespace Encore\Admin\Middleware;

use Illuminate\Http\Request;

class Session
{
    public function handle(Request $request, \Closure $next)
    {
        // Host cookie scope is retained unless the consumer explicitly opts in.
        $overrides = config('admin.session', []);
        $previous = [];
        foreach (['path', 'domain'] as $key) {
            if (array_key_exists($key, $overrides)) {
                $previous['session.'.$key] = config('session.'.$key);
                config(['session.'.$key => $overrides[$key]]);
            }
        }

        try {
            return $next($request);
        } finally {
            config($previous);
        }
    }
}
