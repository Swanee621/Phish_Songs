<?php

namespace App\Http\Middleware;

use App\Services\GeoIp\VisitRecorder;
use App\Support\IgnoredIps;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Counts anonymous page views by city. Attached to the page routes only, so
 * data endpoints, the login and the stats page itself are never recorded.
 *
 * Nothing is set on the browser: each visit is a row on its own with no way
 * to tie it to another. The write happens in `terminate()`, after the
 * response is out of the door, and the decision to count the request travels
 * there on the request attributes because Laravel resolves a fresh middleware
 * instance for that call.
 */
class RecordVisit
{
    protected const ATTRIBUTE = 'visit.record';

    protected const BOT_PATTERN = '/bot|crawl|spider|slurp|preview|lighthouse|headless|curl|wget|python-requests/i';

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldTrack($request)) {
            $request->attributes->set(self::ATTRIBUTE, true);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->attributes->get(self::ATTRIBUTE) !== true || ! $response->isSuccessful()) {
            return;
        }

        try {
            app(VisitRecorder::class)->record(
                (string) $request->ip(),
                '/'.ltrim($request->path(), '/'),
                now(),
            );
        } catch (Throwable $e) {
            // Stats must never take a page down with them.
            report($e);
        }
    }

    /**
     * Real page views by anonymous people: not HEAD probes, not Inertia partial
     * reloads of a page already counted, not the maintainer, not crawlers.
     */
    protected function shouldTrack(Request $request): bool
    {
        return $request->isMethod('GET')
            && ! $request->hasHeader('X-Inertia-Partial-Component')
            && $request->user() === null
            && ! IgnoredIps::contains($request->ip())
            && preg_match(self::BOT_PATTERN, (string) $request->userAgent()) !== 1;
    }
}
