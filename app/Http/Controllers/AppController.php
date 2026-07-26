<?php

namespace App\Http\Controllers;

use App\Services\PhishNet\PhishNetRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AppController extends Controller
{
    /**
     * How many past performances of a song the dialog fetches per request, as
     * it scrolls rather than up front.
     */
    protected const RECENT_PERFORMANCES_PER_PAGE = 10;

    public function recentSetlists(): Response
    {
        return Inertia::render('RecentSetlists', [
            'clientSyncActiveInterval' => (int) config('phishnet.client.active_interval'),
        ]);
    }

    public function setlistBrowser(): Response
    {
        return Inertia::render('SetlistBrowser', [
            'clientSyncActiveInterval' => (int) config('phishnet.client.active_interval'),
        ]);
    }

    public function songChecker(): Response
    {
        return Inertia::render('SongChecker', [
            'excludedSongs' => config('services.phishnet.excluded_songs', []),
            'defaultMinPlayed' => config('app.default_min_played'),
            'clientSyncActiveInterval' => (int) config('phishnet.client.active_interval'),
        ]);
    }

    public function currentYearSetlists(PhishNetRepository $repository): JsonResponse
    {
        return response()->json(['data' => $repository->setlistsForYear((int) now()->year)]);
    }

    public function setlistsForYear(PhishNetRepository $repository, int $year): JsonResponse
    {
        return response()->json(['data' => $repository->setlistsForYear($year)]);
    }

    public function setlistForDate(PhishNetRepository $repository, string $showdate): JsonResponse
    {
        return response()->json(['data' => $repository->setlistForShowdate($showdate)]);
    }

    public function showYears(PhishNetRepository $repository): JsonResponse
    {
        return response()->json(['data' => $repository->showYears()]);
    }

    public function songs(PhishNetRepository $repository): JsonResponse
    {
        return response()->json(['data' => $repository->songs()]);
    }

    /**
     * One page of the most recent performances of a song, which the song dialog
     * shows underneath the performances from the tour on screen and extends as
     * the user scrolls.
     *
     * `exclude_tour` keeps that tour out of the results, so the dialog gets a
     * full page of history rather than slots partly spent repeating the list
     * directly above it. `offset` is how many rows the dialog already holds.
     *
     * One row beyond the page is fetched purely to answer `hasMore`, and
     * dropped before the response goes out: it saves the client a request that
     * comes back empty at the end of a song's history, and the server a second
     * query to count what is left.
     */
    public function songPerformances(Request $request, PhishNetRepository $repository, string $slug): JsonResponse
    {
        $offset = max(0, $request->integer('offset'));

        $rows = $repository->recentPerformances(
            $slug,
            self::RECENT_PERFORMANCES_PER_PAGE + 1,
            $request->integer('exclude_tour') ?: null,
            $offset,
        );

        $hasMore = count($rows) > self::RECENT_PERFORMANCES_PER_PAGE;

        return response()->json([
            'data' => array_slice($rows, 0, self::RECENT_PERFORMANCES_PER_PAGE),
            'meta' => [
                'offset' => $offset,
                'perPage' => self::RECENT_PERFORMANCES_PER_PAGE,
                'hasMore' => $hasMore,
            ],
        ]);
    }

    /**
     * The lightweight snapshot the browser polls to decide whether its data is
     * stale. The version hash moves when new setlist data lands; the poll
     * interval mirrors the server's own pacing so the page speeds up during a
     * show and idles otherwise.
     */
    public function liveStatus(PhishNetRepository $repository): JsonResponse
    {
        $state = $repository->liveState();
        $inShowWindow = $state['inShowWindow'];

        return response()->json(['data' => [
            'version' => $state['version'],
            'year' => $state['year'],
            'showdate' => $state['showdate'],
            'highlightShowdate' => $state['highlightShowdate'],
            'highlightUntil' => $state['highlightUntil'],
            'currentSongs' => $state['currentSongs'],
            'inShowWindow' => $inShowWindow,
            'pollInterval' => $inShowWindow
                ? (int) config('phishnet.client.active_interval')
                : (int) config('phishnet.client.interval'),
        ]]);
    }
}
