<?php

namespace App\Http\Middleware;

use App\Services\GeoIp\VisitRecorder;
use App\Support\IgnoredIps;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Counts anonymous page views by city. Attached to the page routes only, so
 * data endpoints, the login and the stats page itself are never recorded.
 *
 * The visitor id is a random cookie value — enough to notice the same browser
 * turning up in two cities, with nothing in it that identifies a person. The
 * write happens in `terminate()`, after the response is out of the door, and
 * the id travels there on the request attributes because Laravel resolves a
 * fresh middleware instance for that call.
 */
class RecordVisit
{
    public const COOKIE = 'visitor_id';

    protected const COOKIE_MINUTES = 60 * 24 * 365;

    protected const ATTRIBUTE = 'visit.visitor_id';

    protected const BOT_PATTERN = '/bot|crawl|spider|slurp|preview|lighthouse|headless|curl|wget|python-requests/i';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldTrack($request)) {
            return $next($request);
        }

        $visitorId = $request->cookie(self::COOKIE);

        if (! is_string($visitorId) || ! Str::isUuid($visitorId)) {
            $visitorId = (string) Str::uuid();

            Cookie::queue(cookie(
                name: self::COOKIE,
                value: $visitorId,
                minutes: self::COOKIE_MINUTES,
                secure: null,
                httpOnly: true,
                sameSite: 'lax',
            ));
        }

        $request->attributes->set(self::ATTRIBUTE, $visitorId);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $visitorId = $request->attributes->get(self::ATTRIBUTE);

        if (! is_string($visitorId) || ! $response->isSuccessful()) {
            return;
        }

        try {
            app(VisitRecorder::class)->record(
                (string) $request->ip(),
                $visitorId,
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
