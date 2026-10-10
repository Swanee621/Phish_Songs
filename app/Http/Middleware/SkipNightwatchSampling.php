<?php

namespace App\Http\Middleware;

use App\Support\IgnoredIps;
use Closure;
use Illuminate\Http\Request;
use Laravel\Nightwatch\Facades\Nightwatch;
use Symfony\Component\HttpFoundation\Response;

/**
 * Throws away the Nightwatch trace for requests coming from a maintainer's own
 * browser, so day-to-day use of the site does not fill the dashboard.
 *
 * `dontSample()` discards everything buffered for the request rather than
 * shipping it. Nightwatch decides sampling in its own global middleware at the
 * start of every request, so this has to run after that one — sitting in the
 * `web` group is what guarantees it does.
 */
class SkipNightwatchSampling
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('data/*') || IgnoredIps::contains($request->ip())) {
            Nightwatch::dontSample();
        }

        return $next($request);
    }
}
