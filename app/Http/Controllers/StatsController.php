<?php

namespace App\Http\Controllers;

use App\Http\Requests\StatsRangeRequest;
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

    public function heatmap(StatsRangeRequest $request, VisitStats $stats): JsonResponse
    {
        return response()->json(['data' => $stats->heatmap($request->from(), $request->to())]);
    }

    public function travellers(StatsRangeRequest $request, VisitStats $stats): JsonResponse
    {
        return response()->json(['data' => $stats->travellers($request->from(), $request->to())]);
    }
}
