<?php

namespace App\Http\Controllers;

use App\Services\PhishNet\PhishNetClient;
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

    public function currentYearSetlists(PhishNetClient $client): JsonResponse
    {
        return response()->json(['data' => $client->setlistsForYear((int) now()->year)]);
    }

    public function setlistsForYear(PhishNetClient $client, int $year): JsonResponse
    {
        return response()->json(['data' => $client->setlistsForYear($year)]);
    }

    public function setlistForDate(PhishNetClient $client, string $showdate): JsonResponse
    {
        return response()->json(['data' => $client->setlistForShowdate($showdate)]);
    }

    public function showYears(PhishNetClient $client): JsonResponse
    {
        return response()->json(['data' => $client->showYears()]);
    }

    public function venues(PhishNetClient $client): JsonResponse
    {
        return response()->json(['data' => $client->venues()]);
    }

    public function songs(PhishNetClient $client): JsonResponse
    {
        return response()->json(['data' => $client->songs()]);
    }

    public function venueShows(PhishNetClient $client, int $venue): JsonResponse
    {
        return response()->json(['data' => $client->showsForVenue($venue)]);
    }
}
