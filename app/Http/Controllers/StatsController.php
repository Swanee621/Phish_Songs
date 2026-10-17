<?php

namespace App\Http\Controllers;

use App\Http\Requests\StatsRangeRequest;
use App\Http\Requests\StatsVisitsRequest;
use App\Services\Stats\VisitStats;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class StatsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Stats');
    }

    public function bounds(VisitStats $stats): JsonResponse
    {
        return response()->json(['data' => $stats->bounds()]);
    }

    public function places(StatsRangeRequest $request, VisitStats $stats): JsonResponse
    {
        return response()->json(['data' => $stats->places($request->from(), $request->to())]);
    }

    public function visits(StatsVisitsRequest $request, VisitStats $stats): JsonResponse
    {
        return response()->json($stats->visits($request->from(), $request->to(), $request->page()));
    }
}
