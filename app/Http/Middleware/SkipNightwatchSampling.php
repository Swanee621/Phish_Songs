<?php

namespace App\Http\Middleware;

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
        if (in_array($request->ip(), $this->ignoredIps(), true)) {
            Nightwatch::dontSample();
        }

        return $next($request);
    }

    /**
     * @return array<int, string>
     */
    protected function ignoredIps(): array
    {
        $configured = (string) config('services.nightwatch.ignored_ips', '');

        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }
}
