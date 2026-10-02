<?php

declare(strict_types=1);

namespace Tests\Unit\Engine\Planning;

use App\Engine\Load\LoadState;
use App\Engine\Planning\CoachPreferences;
use App\Engine\Planning\LoadPlanner;
use App\Engine\Planning\PhasePlanner;
use App\Engine\Planning\PlanRequest;
use App\Enums\Experience;
use App\Enums\PhaseType;
use App\Enums\RaceDistance;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class LoadPlannerTest extends TestCase
{
    private static function request(array $overrides = []): PlanRequest
    {
        return new PlanRequest(...[
            'distance' => RaceDistance::Half,
            'raceDate' => new DateTimeImmutable('2027-04-18'),
            'startDate' => new DateTimeImmutable('2026-11-02'),
            'experience' => Experience::Intermediate,
            'weeklyHours' => 12.0,
            'currentLoad' => new LoadState(40, 40),
            ...$overrides,
        ]);
    }

    private static function plan(PlanRequest $request, int $weeks = 24, float $hours = 12.0): \App\Engine\Planning\LoadPlan
    {
        $phases = (new PhasePlanner)->sequence($weeks, $request->distance);

        return (new LoadPlanner)->plan($request, $phases, array_fill(0, $weeks, $hours), array_fill(0, $weeks, 1.0), 6);
    }

    public function test_target_ctl_comes_from_distance_and_experience(): void
    {
        $planner = new LoadPlanner;

        $this->assertSame(65.0, $planner->targetCtl(self::request(['experience' => Experience::Novice])));
        $this->assertSame(75.0, $planner->targetCtl(self::request()));
        $this->assertSame(85.0, $planner->targetCtl(self::request(['experience' => Experience::Advanced])));
    }

    public function test_a_target_ctl_preference_wins(): void
    {
        $request = self::request(['preferences' => new CoachPreferences(targetCtl: 58)]);

        $this->assertSame(58.0, (new LoadPlanner)->targetCtl($request));
    }

    public function test_older_athletes_default_to_two_to_one_loading(): void
    {
        $planner = new LoadPlanner;

        $this->assertSame(4, $planner->recoveryEvery(self::request(['age' => 35])));
        $this->assertSame(3, $planner->recoveryEvery(self::request(['age' => 55])));
        $this->assertSame(2, $planner->recoveryEvery(self::request(['age' => 55, 'preferences' => new CoachPreferences(recoveryWeekEvery: 2)])));
    }

    public function test_every_fourth_week_is_a_recovery_week_at_reduced_load(): void
    {
        $weeks = self::plan(self::request())->weeks;

        $this->assertFalse($weeks[2]->isRecovery);
        $this->assertTrue($weeks[3]->isRecovery);
        $this->assertTrue($weeks[7]->isRecovery);
        $this->assertEqualsWithDelta($weeks[2]->tss * LoadPlanner::RECOVERY_WEEK_FACTOR, $weeks[3]->tss, 0.2);
    }

    public function test_fitness_never_ramps_faster_than_the_cap(): void
    {
        $plan = self::plan(self::request(['preferences' => new CoachPreferences(maxRampRate: 4)]));
        $previous = 40.0;

        foreach ($plan->weeks as $week) {
            $this->assertLessThanOrEqual($previous + 4.0 + 0.11, $week->projectedCtl);
            $previous = $week->projectedCtl;
        }
    }

    public function test_an_unreachable_target_is_lowered_and_explained(): void
    {
        $plan = self::plan(self::request(['currentLoad' => new LoadState(20, 20)]), weeks: 10);

        $this->assertLessThan(75.0, $plan->targetCtl);
        $this->assertStringContainsString('not reachable', $plan->warnings[0]);
    }

    public function test_weekly_load_is_capped_by_available_hours(): void
    {
        $plan = self::plan(self::request(), hours: 5.0);

        foreach ($plan->weeks as $i => $week) {
            $this->assertLessThanOrEqual(5.0 * PhaseType::Peak->tssPerHour() + 0.1, $week->tss, "week {$i}");
        }

        $this->assertNotEmpty(array_filter($plan->warnings, fn (string $w) => str_contains($w, 'available hours')));
    }

    public function test_the_taper_lands_race_day_form_in_the_target_band(): void
    {
        $plan = self::plan(self::request());

        $this->assertGreaterThanOrEqual(LoadPlanner::RACE_DAY_TSB[0], $plan->projectedRaceDayTsb);
        $this->assertLessThanOrEqual(LoadPlanner::RACE_DAY_TSB[1], $plan->projectedRaceDayTsb);
    }

    public function test_the_week_before_the_taper_is_never_a_recovery_week(): void
    {
        $weeks = self::plan(self::request(), weeks: 13)->weeks;

        $this->assertFalse($weeks[11]->isRecovery);
    }
}
