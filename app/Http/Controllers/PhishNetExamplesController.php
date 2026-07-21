<?php

namespace App\Http\Controllers;

use App\Services\PhishNet\PhishNetRepository;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class PhishNetExamplesController extends Controller
{
    public function recentSetlists(): Response
    {
        return Inertia::render('examples/RecentSetlists');
    }

    public function setlistBrowser(): Response
    {
        return Inertia::render('examples/SetlistBrowser');
    }

    public function songChecker(): Response
    {
        return Inertia::render('SongChecker', [
            'excludedSongs' => config('services.phishnet.excluded_songs', []),
            'defaultMinPlayed' => config('app.default_min_played'),
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
}
