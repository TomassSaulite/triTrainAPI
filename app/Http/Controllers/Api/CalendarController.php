<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\AvailabilityOverrideResource;
use App\Http\Resources\PlannedWorkoutResource;
use App\Http\Resources\RaceResource;
use App\Models\Activity;
use App\Models\AvailabilityOverride;
use App\Models\PlannedWorkout;
use App\Models\Race;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Planned workouts of the active plan next to what was actually done and the
 * races on the calendar, day by day: what the app's calendar view renders.
 */
class CalendarController extends Controller
{
    public const int MAX_DAYS = 92;

    public function __invoke(Request $request): JsonResponse
    {
        $range = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $athlete = $request->user()->athleteOrFail();
        $from = isset($range['from']) ? CarbonImmutable::parse($range['from']) : $athlete->today()->startOfWeek();
        $to = isset($range['to']) ? CarbonImmutable::parse($range['to']) : $from->addDays(6);
        abort_if($from->diffInDays($to) >= self::MAX_DAYS, 422, 'Ask for at most '.self::MAX_DAYS.' days at a time.');
        $plan = $athlete->activePlan()->first();

        $workouts = $plan === null ? collect() : $plan->workouts()
            ->whereNull('parent_id')
            ->whereBetween('date', [$from->toDateString(), $to->endOfDay()->toDateTimeString()])
            ->with('children')
            ->orderBy('date')
            ->orderByDesc('is_key')
            ->get()
            ->groupBy(fn (PlannedWorkout $w) => $w->date->toDateString());

        $activities = $athlete->activities()
            ->whereBetween('started_at', $athlete->utcBounds($from, $to))
            ->with('plannedWorkout')
            ->orderBy('started_at')
            ->get()
            ->groupBy(fn (Activity $a) => $athlete->localDate($a->started_at)->toDateString());

        $races = $athlete->races()
            ->whereBetween('date', [$from->toDateString(), $to->endOfDay()->toDateTimeString()])
            ->get()
            ->groupBy(fn (Race $r) => $r->date->toDateString());

        $availability = $athlete->availabilityOverrides()
            ->whereBetween('date', [$from->toDateString(), $to->endOfDay()->toDateTimeString()])
            ->get()
            ->keyBy(fn (AvailabilityOverride $o) => $o->date->toDateString());

        $days = [];

        foreach (CarbonPeriod::create($from, $to) as $day) {
            $key = $day->toDateString();
            $days[] = [
                'date' => $key,
                'workouts' => PlannedWorkoutResource::collection($workouts->get($key, collect())),
                'activities' => ActivityResource::collection($activities->get($key, collect())),
                'races' => RaceResource::collection($races->get($key, collect())),
                'availability' => $availability->has($key) ? new AvailabilityOverrideResource($availability->get($key)) : null,
            ];
        }

        return response()->json([
            'data' => $days,
            'meta' => ['plan_id' => $plan?->id, 'plan_version' => $plan?->version, 'from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }
}
