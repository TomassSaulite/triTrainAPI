<?php

declare(strict_types=1);

namespace Tests\Unit\Engine\Planning;

use App\Engine\Load\LoadState;
use App\Engine\Planning\CoachPreferences;
use App\Engine\Planning\GeneratedPlan;
use App\Engine\Planning\LoadPlanner;
use App\Engine\Planning\PlanGenerator;
use App\Engine\Planning\PlanRequest;
use App\Engine\Planning\SecondaryRace;
use App\Engine\Planning\TemplateLibrary;
use App\Engine\Planning\WeekBuilder;
use App\Engine\Planning\WorkoutDraft;
use App\Engine\ThresholdSet;
use App\Enums\Experience;
use App\Enums\PhaseType;
use App\Enums\RaceDistance;
use App\Enums\RacePriority;
use App\Enums\Sport;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\SystemLibrary;

/**
 * Generates full plans for fixed athlete scenarios and checks the rules from
 * the design doc hold across every week.
 */
class PlanGeneratorTest extends TestCase
{
    private static function request(array $overrides = []): PlanRequest
    {
        return new PlanRequest(...[
            'distance' => RaceDistance::Half,
            'raceDate' => new DateTimeImmutable('2027-03-14'),
            'startDate' => new DateTimeImmutable('2026-10-07'),
            'experience' => Experience::Intermediate,
            'weeklyHours' => 9.0,
            'currentLoad' => new LoadState(45, 45),
            'thresholds' => new ThresholdSet(ftpWatts: 250, thresholdPaceSecondsPerKm: 270, cssSecondsPer100m: 105, lthr: 168),
            'age' => 36,
            ...$overrides,
        ]);
    }

    private static function generate(?PlanRequest $request = null, ?TemplateLibrary $library = null): GeneratedPlan
    {
        return (new PlanGenerator)->generate($request ?? self::request(), $library ?? SystemLibrary::load());
    }

    /**
     * @return list<WorkoutDraft> every schedulable workout, brick halves included
     */
    private static function sessions(GeneratedPlan $plan): array
    {
        $sessions = [];

        foreach ($plan->workouts() as $workout) {
            array_push($sessions, ...($workout->children === [] ? [$workout] : $workout->children));
        }

        return $sessions;
    }

    public function test_weeks_run_monday_to_race_week_with_phases_in_order(): void
    {
        $plan = self::generate();

        $this->assertCount(23, $plan->weeks);
        $this->assertSame('2026-10-05', $plan->weeks[0]->startDate->format('Y-m-d'));
        $this->assertSame('2027-03-08', $plan->weeks[22]->startDate->format('Y-m-d'));
        $this->assertSame(
            [PhaseType::Base, PhaseType::Build, PhaseType::Peak, PhaseType::Taper],
            array_map(fn ($p) => $p->type, $plan->phases),
        );
        $this->assertSame('2026-10-07', $plan->phases[0]->startDate->format('Y-m-d'));
        $this->assertSame('2027-03-14', $plan->phases[3]->endDate->format('Y-m-d'));
    }

    public function test_every_regular_week_lands_near_its_tss_target(): void
    {
        foreach (self::generate()->weeks as $week) {
            if ($week->phase === PhaseType::Taper) {
                $this->assertLessThanOrEqual($week->targetTss * 1.0 + 1, $week->plannedTss());

                continue;
            }

            $tolerance = WeekBuilder::TSS_TOLERANCE + 0.02;
            $this->assertEqualsWithDelta($week->targetTss, $week->plannedTss(), $week->targetTss * $tolerance, "week {$week->index}");
        }
    }

    public function test_weeks_stay_within_the_athletes_hours(): void
    {
        foreach (self::generate()->weeks as $week) {
            $this->assertLessThanOrEqual(9.0 * 1.05 * 3600, $week->plannedSeconds(), "week {$week->index}");
        }
    }

    public function test_nothing_is_scheduled_before_the_start_on_rest_days_or_from_the_day_before_the_race(): void
    {
        $plan = self::generate();

        foreach (self::sessions($plan) as $session) {
            $date = $session->date->format('Y-m-d');
            $this->assertGreaterThanOrEqual('2026-10-07', $date);
            $this->assertLessThan('2027-03-13', $date);
            $this->assertNotSame('1', $session->date->format('N'), "Workout on the Monday rest day {$date}");
        }
    }

    public function test_key_land_sessions_are_never_on_consecutive_days(): void
    {
        $keyDates = [];

        foreach (self::generate()->workouts() as $workout) {
            if ($workout->isKey && $workout->sport !== Sport::Swim) {
                $keyDates[$workout->date->format('Y-m-d')] = true;
            }
        }

        foreach (array_keys($keyDates) as $date) {
            $next = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
            $this->assertArrayNotHasKey($next, $keyDates, "Key sessions on {$date} and {$next}");
        }
    }

    public function test_bricks_appear_every_other_week_from_build_with_a_bike_and_run_half(): void
    {
        $plan = self::generate();
        $brickWeeks = [];

        foreach ($plan->weeks as $week) {
            foreach ($week->workouts as $workout) {
                if ($workout->sport === Sport::Brick) {
                    $brickWeeks[] = $week->index;
                    $this->assertSame([Sport::Bike, Sport::Run], array_map(fn ($c) => $c->sport, $workout->children));
                    $this->assertContains($week->phase, [PhaseType::Build, PhaseType::Peak]);
                }
            }
        }

        $this->assertNotEmpty($brickWeeks);

        foreach ($brickWeeks as $i => $index) {
            if ($i > 0) {
                $this->assertGreaterThanOrEqual(2, $index - $brickWeeks[$i - 1]);
            }
        }
    }

    public function test_bricks_can_be_switched_off(): void
    {
        $plan = self::generate(self::request(['preferences' => new CoachPreferences(bricks: false)]));

        $this->assertEmpty(array_filter($plan->workouts(), fn ($w) => $w->sport === Sport::Brick));
    }

    public function test_every_session_has_a_structure_and_positive_load(): void
    {
        foreach (self::sessions(self::generate()) as $session) {
            $this->assertNotNull($session->structure);
            $this->assertGreaterThan(0, $session->durationSeconds);
            $this->assertGreaterThan(0, $session->tss);
            $this->assertNotNull($session->templateId);
        }
    }

    public function test_an_availability_override_clears_the_day(): void
    {
        $plan = self::generate(self::request(['availability' => ['2026-11-07' => 0, '2026-11-11' => 0]]));

        foreach (self::sessions($plan) as $session) {
            $this->assertNotContains($session->date->format('Y-m-d'), ['2026-11-07', '2026-11-11']);
        }
    }

    public function test_a_short_day_caps_its_sessions_even_below_a_workouts_usual_minimum(): void
    {
        $date = '2026-11-10';
        $usual = array_filter(self::sessions(self::generate()), fn ($s) => $s->date->format('Y-m-d') === $date);
        $this->assertNotEmpty($usual);

        $plan = self::generate(self::request(['availability' => [$date => 30]]));
        $capped = array_filter(self::sessions($plan), fn ($s) => $s->date->format('Y-m-d') === $date);

        $this->assertNotEmpty($capped);
        $this->assertLessThanOrEqual(30 * 60, array_sum(array_map(fn ($s) => $s->durationSeconds, $capped)));
    }

    public function test_easy_days_hold_no_hard_session_for_the_legs(): void
    {
        $easyDays = ['2026-11-10', '2026-11-11', '2026-11-12'];
        $hardBefore = array_filter(
            self::sessions(self::generate()),
            fn ($s) => in_array($s->date->format('Y-m-d'), $easyDays, true) && $s->isKey && $s->sport !== Sport::Swim,
        );
        $this->assertNotEmpty($hardBefore, 'The usual plan has hard bike or run sessions on these days.');

        $plan = self::generate(self::request(['easyDays' => $easyDays]));

        foreach (self::sessions($plan) as $session) {
            if (in_array($session->date->format('Y-m-d'), $easyDays, true) && $session->sport !== Sport::Swim) {
                $this->assertFalse($session->isKey, "{$session->title} on {$session->date->format('D j M')} is a key session.");
            }
        }
    }

    /**
     * A week where little is free, so the scheduler has to use whatever days it can:
     * everything from Tuesday 10 to Sunday 15 November is blocked except the listed days.
     *
     * @param  array<string, int>  $open  dates left open, with their minutes
     */
    private static function tightWeek(array $open, array $easyDays = []): GeneratedPlan
    {
        $blocked = array_fill_keys(['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15'], 0);

        return self::generate(self::request([
            'preferences' => new CoachPreferences(poolDays: [1, 2, 3, 5]),
            'availability' => [...$blocked, ...$open],
            'easyDays' => $easyDays,
        ]));
    }

    /**
     * @return list<WorkoutDraft>
     */
    private static function sessionsOn(GeneratedPlan $plan, string $date): array
    {
        return array_values(array_filter(self::sessions($plan), fn ($s) => $s->date->format('Y-m-d') === $date));
    }

    public function test_an_easy_day_on_the_usual_rest_day_stays_a_rest_day(): void
    {
        $plan = self::tightWeek(['2026-11-09' => 90, '2026-11-14' => 240], easyDays: ['2026-11-09']);

        $this->assertSame([], self::sessionsOn($plan, '2026-11-09'));
    }

    public function test_the_first_days_back_hold_no_key_session_of_any_sport(): void
    {
        $tuesday = '2026-11-10';
        $usual = self::sessionsOn(self::tightWeek([$tuesday => 90, '2026-11-14' => 240]), $tuesday);
        $this->assertNotEmpty(array_filter($usual, fn ($s) => $s->isKey), 'Without the easy day a key session lands here.');

        $plan = self::tightWeek([$tuesday => 90, '2026-11-14' => 240], easyDays: [$tuesday]);

        foreach (self::sessionsOn($plan, $tuesday) as $session) {
            $this->assertFalse($session->isKey, "{$session->title} is a key session.");
        }
    }

    public function test_two_sessions_on_a_short_day_share_its_time(): void
    {
        $plan = self::tightWeek(['2026-11-12' => 90, '2026-11-14' => 240]);
        $sessions = self::sessionsOn($plan, '2026-11-12');

        $this->assertCount(2, $sessions);
        $this->assertLessThanOrEqual(90 * 60 * 1.05, array_sum(array_map(fn ($s) => $s->durationSeconds, $sessions)));
    }

    public function test_generation_is_deterministic(): void
    {
        $this->assertEquals(self::generate(), self::generate());
    }

    public function test_an_empty_library_still_produces_a_plan_and_says_so(): void
    {
        $plan = self::generate(library: new TemplateLibrary([]));

        $this->assertNotEmpty($plan->workouts());
        $this->assertContains('The workout library is empty, so every session is a plain steady effort.', $plan->warnings);
    }

    public function test_a_plan_starting_in_race_week_is_just_a_taper(): void
    {
        $plan = self::generate(self::request(['startDate' => new DateTimeImmutable('2027-03-08')]));

        $this->assertCount(1, $plan->weeks);
        $this->assertSame(PhaseType::Taper, $plan->weeks[0]->phase);
    }

    public function test_a_stronger_athlete_gets_more_load_than_a_novice(): void
    {
        $novice = self::generate(self::request(['experience' => Experience::Novice, 'weeklyHours' => 6.0]));
        $advanced = self::generate(self::request(['experience' => Experience::Advanced, 'weeklyHours' => 14.0]));

        $this->assertGreaterThan($novice->targetCtl, $advanced->targetCtl);
        $this->assertGreaterThan($novice->weeks[10]->plannedTss(), $advanced->weeks[10]->plannedTss());
    }

    public function test_a_re_plan_keeps_the_original_phase_layout(): void
    {
        $original = self::generate();
        $replan = self::generate(self::request([
            'startDate' => new DateTimeImmutable('2027-01-06'),
            'planStartDate' => new DateTimeImmutable('2026-10-07'),
        ]));

        $originalWeek = array_values(array_filter($original->weeks, fn ($w) => $w->startDate->format('Y-m-d') === '2027-01-04'))[0];

        $this->assertSame($originalWeek->index, $replan->weeks[0]->index);
        $this->assertSame($originalWeek->phase, $replan->weeks[0]->phase);
        $this->assertSame(PhaseType::Build, $replan->phases[0]->type);
        $this->assertSame('2027-01-06', $replan->phases[0]->startDate->format('Y-m-d'));
    }

    /**
     * @return array{0: \App\Engine\Planning\WeekDraft, 1: \App\Engine\Planning\WeekDraft}
     */
    private static function weekWithAndWithout(RacePriority $priority): array
    {
        // Saturday 21 November 2026 falls in a loading week of the base phase.
        $race = new SecondaryRace(new DateTimeImmutable('2026-11-21'), $priority, RaceDistance::Olympic);
        $with = self::generate(self::request(['secondaryRaces' => [$race]]));
        $without = self::generate();
        $find = fn (GeneratedPlan $p) => array_values(array_filter($p->weeks, fn ($w) => $w->startDate->format('Y-m-d') === '2026-11-16'))[0];

        return [$find($with), $find($without)];
    }

    public function test_a_b_race_gets_a_mini_taper(): void
    {
        [$week, $normal] = self::weekWithAndWithout(RacePriority::B);

        $this->assertEqualsWithDelta($normal->targetTss * SecondaryRace::WEEK_LOAD['B'], $week->targetTss, 1.0);

        $dates = array_map(fn ($w) => $w->date->format('Y-m-d'), $week->workouts);
        $this->assertNotContains('2026-11-20', $dates, 'Rest the day before a B race.');
        $this->assertNotContains('2026-11-21', $dates, 'The race is the session.');
        $this->assertEmpty(array_filter($week->workouts, fn ($w) => $w->kind === \App\Enums\WorkoutKind::Long));

        foreach ($week->workouts as $workout) {
            if ($workout->date->format('Y-m-d') === '2026-11-22') {
                $this->assertFalse($workout->isKey);
            }
        }
    }

    public function test_a_c_race_is_raced_as_training(): void
    {
        [$week, $normal] = self::weekWithAndWithout(RacePriority::C);

        $this->assertEqualsWithDelta($normal->targetTss * SecondaryRace::WEEK_LOAD['C'], $week->targetTss, 1.0);
        $this->assertNotContains('2026-11-21', array_map(fn ($w) => $w->date->format('Y-m-d'), $week->workouts));
    }

    public function test_races_after_the_a_race_are_ignored(): void
    {
        $late = new SecondaryRace(new DateTimeImmutable('2027-04-10'), RacePriority::B, RaceDistance::Olympic);

        $this->assertEquals(self::generate(), self::generate(self::request(['secondaryRaces' => [$late]])));
    }

    /**
     * @return list<string> titles of the race-pace sessions in the plan
     */
    private static function racePaceTitles(RaceDistance $distance): array
    {
        $plan = self::generate(self::request(['distance' => $distance]));

        return array_values(array_unique(array_map(
            fn (WorkoutDraft $w) => $w->title,
            array_filter(self::sessions($plan), fn (WorkoutDraft $w) => $w->kind === \App\Enums\WorkoutKind::RacePace),
        )));
    }

    public function test_race_pace_work_matches_the_race_distance(): void
    {
        $full = self::racePaceTitles(RaceDistance::Full);
        $olympic = self::racePaceTitles(RaceDistance::Olympic);

        $this->assertContains('Full-distance race pace', $full);
        $this->assertNotContains('Half-distance race pace', $full);
        $this->assertContains('Sprint/olympic race pace', $olympic);
        $this->assertNotContains('Half-distance race pace', $olympic);
        $this->assertContains('Half-distance race pace', self::racePaceTitles(RaceDistance::Half));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function raceWeekdays(): iterable
    {
        foreach (['2027-02-15', '2027-02-16', '2027-02-17', '2027-02-18', '2027-02-19', '2027-02-20', '2027-02-21'] as $date) {
            yield (new DateTimeImmutable($date))->format('l') => [$date];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('raceWeekdays')]
    public function test_the_athlete_arrives_fresh_whichever_weekday_the_race_is(string $raceDate): void
    {
        $plan = self::generate(self::request(['raceDate' => new DateTimeImmutable($raceDate)]));

        $this->assertGreaterThan(0, $plan->projectedRaceDayTsb);
        $this->assertLessThanOrEqual(LoadPlanner::RACE_DAY_TSB[1] + 5, $plan->projectedRaceDayTsb);
    }

    /**
     * An Ironman in August with a spring marathon as a B race.
     *
     * @return array{0: GeneratedPlan, 1: GeneratedPlan}
     */
    private static function ironmanWithAndWithoutMarathon(): array
    {
        $ironman = [
            'distance' => RaceDistance::Full,
            'raceDate' => new DateTimeImmutable('2027-08-22'),
            'startDate' => new DateTimeImmutable('2026-12-07'),
            'weeklyHours' => 12.0,
        ];
        $marathon = new SecondaryRace(new DateTimeImmutable('2027-05-09'), RacePriority::B, RaceDistance::Marathon);

        return [
            self::generate(self::request([...$ironman, 'secondaryRaces' => [$marathon]])),
            self::generate(self::request($ironman)),
        ];
    }

    /**
     * @return array{run: int, longest: int, keys: int}
     */
    private static function runStats(GeneratedPlan $plan, string $monday): array
    {
        $week = array_values(array_filter($plan->weeks, fn ($w) => $w->startDate->format('Y-m-d') === $monday))[0];
        $stats = ['run' => 0, 'longest' => 0, 'keys' => 0];

        foreach ($week->workouts as $workout) {
            foreach ($workout->children ?: [$workout] as $session) {
                if ($session->sport === Sport::Run) {
                    $stats['run'] += $session->durationSeconds;
                    $stats['keys'] += $session->isKey ? 1 : 0;
                    $stats['longest'] = max($stats['longest'], $session->kind === \App\Enums\WorkoutKind::Long ? $session->durationSeconds : 0);
                }
            }
        }

        return $stats;
    }

    public function test_a_spring_marathon_builds_run_volume_in_the_weeks_before_it(): void
    {
        [$with, $without] = self::ironmanWithAndWithoutMarathon();

        $leadIn = self::runStats($with, '2027-04-05');
        $normal = self::runStats($without, '2027-04-05');

        $this->assertGreaterThan($normal['run'] * 1.2, $leadIn['run']);
        $this->assertGreaterThan($normal['longest'] * 1.3, $leadIn['longest']);
    }

    public function test_the_week_after_a_marathon_keeps_running_easy(): void
    {
        [$with] = self::ironmanWithAndWithoutMarathon();

        $recovery = self::runStats($with, '2027-05-10');

        $this->assertSame(0, $recovery['keys']);
        $this->assertSame(0, $recovery['longest']);
        $this->assertNotContains('2027-05-09', array_map(fn ($w) => $w->date->format('Y-m-d'), $with->workouts()));
    }

    public function test_a_running_race_cannot_be_the_goal_of_a_plan(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::request(['distance' => RaceDistance::Marathon]);
    }
}
