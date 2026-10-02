<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DailyLoadResource;
use App\Services\LoadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Fitness (CTL), fatigue (ATL) and form (TSB) over time.
 */
class LoadController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $range = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $athlete = $request->user()->athleteOrFail();
        $loads = $athlete->dailyLoads()
            ->whereDate('date', '>=', $range['from'] ?? $athlete->today()->subDays(90))
            ->whereDate('date', '<=', $range['to'] ?? $athlete->today())
            ->orderBy('date')
            ->get();

        return DailyLoadResource::collection($loads);
    }

    public function summary(Request $request, LoadService $load): JsonResponse
    {
        $athlete = $request->user()->athleteOrFail();
        $date = $athlete->today();
        $today = $load->stateOn($athlete, $date);
        $weekAgo = $load->stateOn($athlete, $date->subWeek());

        return response()->json(['data' => [
            'date' => $date->toDateString(),
            'ctl' => round($today->ctl, 1),
            'atl' => round($today->atl, 1),
            // Form going into today, as in daily_load: yesterday's fitness minus yesterday's fatigue.
            'tsb' => $athlete->dailyLoads()->whereDate('date', $date)->value('tsb')
                ?? round($load->stateOn($athlete, $date->subDay())->tsb(), 1),
            'ramp_7d' => round($today->ctl - $weekAgo->ctl, 1),
            'tss_7d' => round((float) $athlete->dailyLoads()->whereDate('date', '>', $date->subWeek())->sum('tss'), 1),
            'has_history' => $athlete->dailyLoads()->exists(),
        ]]);
    }
}
