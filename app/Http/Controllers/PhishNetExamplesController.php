<?php

namespace App\Http\Controllers;

use App\Services\PhishNet\PhishNetRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PhishNetExamplesController extends Controller
{
    /**
     * How many past performances of a song the dialog lists.
     */
    protected const RECENT_PERFORMANCES = 5;

    public function recentSetlists(): Response
    {
        return Inertia::render('RecentSetlists', [
            'clientSyncInterval' => (int) config('phishnet.client.interval'),
            'clientSyncActiveInterval' => (int) config('phishnet.client.active_interval'),
        ]);
    }

    public function setlistBrowser(): Response
    {
        return Inertia::render('SetlistBrowser', [
            'clientSyncInterval' => (int) config('phishnet.client.interval'),
            'clientSyncActiveInterval' => (int) config('phishnet.client.active_interval'),
        ]);
    }

    public function songChecker(): Response
    {
        return Inertia::render('SongChecker', [
            'excludedSongs' => config('services.phishnet.excluded_songs', []),
            'defaultMinPlayed' => config('app.default_min_played'),
            'clientSyncInterval' => (int) config('phishnet.client.interval'),
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
     * The handful of most recent performances of one song, which the song
     * dialog shows underneath the performances from the tour on screen.
     *
     * `exclude_tour` keeps that tour out of the results, so the dialog gets a
     * full five of history rather than five slots partly spent repeating the
     * list directly above it.
     */
    public function songPerformances(Request $request, PhishNetRepository $repository, string $slug): JsonResponse
    {
        return response()->json([
            'data' => $repository->recentPerformances(
                $slug,
                self::RECENT_PERFORMANCES,
                $request->integer('exclude_tour') ?: null,
            ),
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
