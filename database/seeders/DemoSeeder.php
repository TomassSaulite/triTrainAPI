<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ActivitySource;
use App\Enums\Experience;
use App\Enums\RaceDistance;
use App\Enums\RacePriority;
use App\Enums\Sport;
use App\Enums\ThresholdMetric;
use App\Enums\ThresholdSource;
use App\Models\Activity;
use App\Models\Athlete;
use App\Models\User;
use App\Services\ActivityPipeline;
use App\Services\Planning\PlanService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * A ready-to-use athlete for local development and the Android app:
 * demo@tritrain.test / password, six weeks of history plus this week so far,
 * thresholds, an A race and a plan that began two weeks ago. The history
 * since then counts as training to plan, so there is a week to review.
 */
class DemoSeeder extends Seeder
{
    public const string EMAIL = 'demo@tritrain.test';

    /** Sessions this recent are left unrated, so the app asks about them. */
    private const int UNRATED_DAYS = 2;

    /**
     * A season of threshold history, oldest first: [metric, value, weeks ago, source].
     * FTP was last tested nine weeks ago, so the app prompts a retest.
     */
    private const array THRESHOLDS = [
        ['ftp_w', 228, 22, ThresholdSource::Test],
        ['ftp_w', 236, 14, ThresholdSource::AutoDetected],
        ['ftp_w', 245, 9, ThresholdSource::Test],
        ['threshold_pace_s_per_km', 288, 20, ThresholdSource::Test],
        ['threshold_pace_s_per_km', 281, 12, ThresholdSource::Estimated],
        ['threshold_pace_s_per_km', 275, 6, ThresholdSource::Test],
        ['css_s_per_100m', 113, 18, ThresholdSource::Test],
        ['css_s_per_100m', 108, 7, ThresholdSource::Test],
        ['lthr', 168, 6, ThresholdSource::Test],
    ];

    /** How many of the six weeks of history fall inside the plan. */
    private const int PLAN_WEEKS_DONE = 2;

    /**
     * A typical week of the demo athlete's history: [weekday, sport, minutes, extra metrics].
     */
    private const array WEEK = [
        [2, 'bike', 60, ['np_w' => 205]],
        [2, 'swim', 45, ['distance_m' => 2200]],
        [3, 'run', 45, ['distance_m' => 8500]],
        [4, 'bike', 75, ['np_w' => 190]],
        [5, 'swim', 40, ['distance_m' => 2000]],
        [6, 'bike', 150, ['np_w' => 175]],
        [7, 'run', 80, ['distance_m' => 14000]],
    ];

    public function run(PlanService $plans, ActivityPipeline $pipeline): void
    {
        User::where('email', self::EMAIL)->first()?->delete();

        $user = User::factory()->create(['name' => 'Demo Athlete', 'email' => self::EMAIL, 'password' => 'password']);
        $athlete = Athlete::factory()->for($user)->create([
            'birth_year' => 1990,
            'experience' => Experience::Intermediate,
            'weekly_hours' => 9,
            'weakest_sport' => Sport::Swim,
        ]);

        foreach (self::THRESHOLDS as [$metric, $value, $weeksAgo, $source]) {
            $athlete->thresholds()->create([
                'sport' => ThresholdMetric::from($metric)->sport(),
                'metric' => $metric,
                'value' => $value,
                'tested_at' => today()->subWeeks($weeksAgo),
                'source' => $source,
            ]);
        }

        $planStart = CarbonImmutable::today()->startOfWeek()->subWeeks(self::PLAN_WEEKS_DONE);
        $history = collect($this->history($athlete));
        [$beforePlan, $duringPlan] = $history->partition(fn (Activity $activity) => $activity->started_at->lt($planStart));

        $pipeline->processMany($athlete, $beforePlan->values()->all());

        $race = $athlete->races()->create([
            'name' => 'Demo 70.3',
            'distance' => RaceDistance::Half,
            'date' => today()->addWeeks(20)->next(CarbonImmutable::SUNDAY),
            'priority' => RacePriority::A,
        ]);

        $plans->create($athlete, $race, $planStart);
        $pipeline->processMany($athlete, $duringPlan->values()->all());
        // The last two days are left for the athlete to rate.
        $this->rate($history->filter(fn (Activity $a) => $a->started_at->gte($planStart->subWeeks(2))
            && $a->started_at->lt(CarbonImmutable::today()->subDays(self::UNRATED_DAYS))));

        $this->command?->info('Demo athlete ready: '.self::EMAIL.' / password');
    }

    /**
     * How the demo athlete rated their recent sessions: long sessions and the
     * days after them feel harder, and fatigue builds through each week.
     *
     * @param  \Illuminate\Support\Collection<int, Activity>  $activities
     */
    private function rate(\Illuminate\Support\Collection $activities): void
    {
        foreach ($activities->values() as $i => $activity) {
            $long = $activity->duration_s >= 75 * 60;
            $dayOfWeek = (int) $activity->started_at->format('N');
            $tiredness = min(5, 1 + intdiv($dayOfWeek, 3) + ($long ? 1 : 0));

            $feedback = $activity->feedback()->make([
                'rpe' => min(10, 3 + ($long ? 3 : 1) + $i % 2 + intdiv($dayOfWeek, 4)),
                'muscles' => $activity->sport === Sport::Swim ? max(1, $tiredness - 1) : $tiredness,
                'breathing' => $long ? 3 : 2,
                'energy' => max(1, $tiredness - $i % 2),
                'mood' => $i % 5 === 0 ? 3 : 2,
                'note' => $long && $i % 3 === 0 ? 'Windy out there, but held the power.' : null,
            ]);
            $feedback->athlete()->associate($activity->athlete_id);
            $feedback->save();
        }
    }

    /**
     * Six full weeks of the typical week, plus this week up to yesterday.
     *
     * @return list<Activity>
     */
    private function history(Athlete $athlete): array
    {
        $activities = [];
        $today = CarbonImmutable::today();
        $firstMonday = $today->startOfWeek()->subWeeks(6);

        for ($week = 0; $week <= 6; $week++) {
            foreach (self::WEEK as [$weekday, $sport, $minutes, $metrics]) {
                $start = $firstMonday->addWeeks($week)->addDays($weekday - 1)->setTime(7, 0);

                if ($start->gte($today)) {
                    continue;
                }

                $activities[] = $athlete->activities()->create([
                    'source' => ActivitySource::Manual,
                    'sport' => $sport,
                    'name' => ucfirst($sport),
                    'started_at' => $start,
                    'duration_s' => $minutes * 60,
                    ...$metrics,
                ]);
            }
        }

        return $activities;
    }
}
