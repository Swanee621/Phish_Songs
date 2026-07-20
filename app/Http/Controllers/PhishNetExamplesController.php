<?php

namespace App\Http\Controllers;

use App\Services\PhishNet\PhishNetClient;
use App\Services\PhishNet\PhishNetRepository;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class PhishNetExamplesController extends Controller
{
    public function jamChartExplorer(): Response
    {
        return Inertia::render('examples/JamChartExplorer');
    }

    public function recentSetlists(): Response
    {
        return Inertia::render('examples/RecentSetlists');
    }

    public function setlistBrowser(): Response
    {
        return Inertia::render('examples/SetlistBrowser');
    }

    public function venueExplorer(): Response
    {
        return Inertia::render('examples/VenueExplorer');
    }

    public function songExplorer(): Response
    {
        return Inertia::render('SongExplorer', [
            'excludedSongs' => config('services.phishnet.excluded_songs', []),
            'defaultMinPlayed' => config('app.default_min_played'),
        ]);
    }

    public function jamCharts(PhishNetClient $client): JsonResponse
    {
        return response()->json(['data' => $client->jamCharts()]);
    }

    public function jamChart(PhishNetClient $client, string $slug): JsonResponse
    {
        return response()->json(['data' => $client->jamChartForSlug($slug)]);
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

    public function venues(PhishNetRepository $repository): JsonResponse
    {
        return response()->json(['data' => $repository->venues()]);
    }

    public function songs(PhishNetRepository $repository): JsonResponse
    {
        return response()->json(['data' => $repository->songs()]);
    }

    public function venueShows(PhishNetRepository $repository, int $venue): JsonResponse
    {
        return response()->json(['data' => $repository->showsForVenue($venue)]);
    }
}
