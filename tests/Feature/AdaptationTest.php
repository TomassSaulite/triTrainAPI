<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Engine\Adaptation\Rules\ExtendedMissRule;
use App\Enums\RevisionReason;
use App\Enums\Sport;
use App\Enums\WorkoutKind;
use App\Enums\WorkoutStatus;
use App\Jobs\AdaptPlan;
use App\Models\Activity;
use App\Models\Athlete;
use App\Models\DailyLoad;
use App\Models\Plan;
use App\Models\PlannedWorkout;
use App\Models\Race;
use App\Models\Threshold;
use App\Services\Adaptation\AdaptationService;
use App\Services\Planning\PlanService;
use Carbon\CarbonImmutable;
use Database\Seeders\WorkoutTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * The adaptation loop against a generated half-distance plan starting
 * Wednesday 7 October 2026 (Mondays are rest days, long ride on Saturday).
 */
class AdaptationTest extends TestCase
{
    use RefreshDatabase;

    private Athlete $athlete;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 06:00:00');
        $this->seed(WorkoutTemplateSeeder::class);

        $this->athlete = Athlete::factory()->create(['weekly_hours' => 9]);
        Threshold::factory()->for($this->athlete)->ftp(250)->create();
        $race = Race::factory()->for($this->athlete)->create(['date' => '2027-03-14']);
        $this->plan = app(PlanService::class)->create($this->athlete, $race);
    }

    private function adapt(string $today): ?\App\Models\PlanRevision
    {
        $this->travelTo("{$today} 03:00:00");

        return app(AdaptationService::class)->adapt($this->plan->refresh(), CarbonImmutable::parse($today));
    }

    private function activityOn(string $date, float $tss = 50, Sport $sport = Sport::Strength): Activity
    {
        return Activity::factory()->for($this->athlete)->create([
            'sport' => $sport, 'started_at' => "{$date} 07:00:00", 'duration_s' => 3600, 'tss' => $tss, 'tss_method' => 'provided',
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, PlannedWorkout>
     */
    private function workoutsBetween(string $from, string $to)
    {
        return $this->plan->workouts()->whereNull('parent_id')->whereBetween('date', [$from, "{$to} 23:59:59"])->get();
    }

    public function test_the_nightly_sweep_marks_unstarted_past_sessions_missed(): void
    {
        $this->activityOn('2026-10-12');

        $this->adapt('2026-10-14');

        $this->assertSame(0, $this->plan->workouts()->open()->whereDate('date', '<', '2026-10-14')->count());
        $this->assertGreaterThan(0, $this->plan->workouts()->where('status', WorkoutStatus::Missed)->count());
    }

    public function test_a_missed_key_session_is_rescheduled_once_and_logged(): void
    {
        $this->activityOn('2026-10-12');
        $missedKey = $this->plan->workouts()->whereNull('parent_id')->whereDate('date', '2026-10-13')->where('is_key', true)->where('sport', 'bike')->firstOrFail();
        // Free up Thursday so there is a legal slot for a key ride.
        $this->plan->workouts()->whereDate('date', '2026-10-15')->update(['status' => WorkoutStatus::Dropped]);

        $revision = $this->adapt('2026-10-14');

        $this->assertSame(RevisionReason::Adapted, $revision->reason);
        $this->assertStringContainsString('Missed tempo bike on Tuesday', $revision->summary);
        $copy = PlannedWorkout::where('rescheduled_from_id', $missedKey->id)->sole();
        $this->assertSame(WorkoutStatus::Moved, $copy->status);
        $this->assertTrue($copy->date->isAfter('2026-10-13'));
        $this->assertSame(WorkoutStatus::Missed, $missedKey->refresh()->status);

        $this->assertNull($this->adapt('2026-10-14'), 'The same decision must not be made twice.');
    }

    public function test_three_days_off_ease_the_rest_of_the_week(): void
    {
        $this->activityOn('2026-10-12');
        $before = $this->workoutsBetween('2026-10-16', '2026-10-18')->pluck('target_tss', 'id');

        $revision = $this->adapt('2026-10-16');

        $this->assertStringContainsString('3 days missed', $revision->summary);
        $after = $this->workoutsBetween('2026-10-16', '2026-10-18')->pluck('target_tss', 'id');

        foreach ($before as $id => $tss) {
            $this->assertEqualsWithDelta($tss * ExtendedMissRule::REDUCED_LOAD, $after[$id], $tss * 0.2, "workout {$id}");
            $this->assertLessThan($tss, $after[$id]);
        }

        $this->assertNull($this->adapt('2026-10-16'));
    }

    public function test_a_week_off_regenerates_the_plan(): void
    {
        $this->activityOn('2026-10-07');
        $versionBefore = $this->plan->version;

        $revision = $this->adapt('2026-10-16');

        $this->assertSame(RevisionReason::Adapted, $revision->reason);
        $this->assertStringContainsString('re-planned from today', $revision->summary);
        $this->assertSame($versionBefore + 1, $this->plan->refresh()->version);
        $this->assertSame('regenerate', $revision->changes[0]['type']);
        $this->assertGreaterThan(0, $this->plan->workouts()->open()->whereDate('date', '>=', '2026-10-16')->count());
    }

    public function test_deep_fatigue_swaps_the_next_key_session_for_recovery(): void
    {
        $this->plan->workouts()->whereDate('date', '<', '2026-10-14')->update(['status' => WorkoutStatus::Completed]);

        foreach (['2026-10-12', '2026-10-13', '2026-10-14'] as $date) {
            DailyLoad::create(['athlete_id' => $this->athlete->id, 'date' => $date, 'tss' => 150, 'ctl' => 50, 'atl' => 85, 'tsb' => -35]);
        }

        $next = $this->plan->workouts()->whereNull('parent_id')->where('is_key', true)->whereDate('date', '>=', '2026-10-14')->orderBy('date')->orderBy('id')->firstOrFail();

        $revision = $this->adapt('2026-10-14');

        $this->assertContains('recover', array_column($revision->changes, 'type'));
        $next->refresh();
        $this->assertSame(WorkoutKind::Recovery, $next->kind);
        $this->assertFalse($next->is_key);
        $this->assertLessThanOrEqual(2700, $next->target_duration_s);
    }

    public function test_two_weeks_well_above_plan_raise_next_week(): void
    {
        foreach (['2026-10-12' => '2026-10-18', '2026-10-19' => '2026-10-25'] as $monday => $sunday) {
            $planned = $this->workoutsBetween($monday, $sunday)->sum('target_tss');
            $this->plan->workouts()->whereBetween('date', [$monday, "{$sunday} 23:59:59"])->update(['status' => WorkoutStatus::Completed]);
            $this->activityOn($sunday, $planned * 1.2);
        }

        $before = $this->workoutsBetween('2026-11-02', '2026-11-08')->sum('target_tss');
        $revision = $this->adapt('2026-10-26');

        $this->assertNotNull($revision);
        $this->assertContains('raise:2026-11-02', array_column($revision->changes, 'key'));
        $after = $this->workoutsBetween('2026-11-02', '2026-11-08')->sum('target_tss');
        $this->assertGreaterThan($before, $after);
        $this->assertLessThan($before * 1.1, $after);
    }

    public function test_recording_an_activity_runs_the_adaptation_loop(): void
    {
        $this->activityOn('2026-10-12');
        $this->travelTo('2026-10-16 18:00:00');

        $this->actingAs($this->athlete->user)->postJson('/api/v1/activities', [
            'sport' => 'strength', 'started_at' => '2026-10-16T07:00:00Z', 'duration_s' => 1800, 'tss' => 20,
        ])->assertCreated();

        $this->assertSame(RevisionReason::Adapted, $this->plan->revisions()->first()->reason);
    }

    public function test_the_nightly_command_adapts_plans_where_it_is_the_small_hours(): void
    {
        Bus::fake([AdaptPlan::class]);
        $this->athlete->update(['timezone' => 'Europe/Riga']);

        $this->travelTo('2026-10-14 12:00:00');
        $this->artisan('coach:nightly')->assertSuccessful();
        Bus::assertNotDispatched(AdaptPlan::class);

        // 00:00 UTC is 03:00 in Riga (UTC+3 in October).
        $this->travelTo('2026-10-15 00:00:00');
        $this->artisan('coach:nightly')->assertSuccessful();
        Bus::assertDispatched(AdaptPlan::class, fn (AdaptPlan $job) => $job->plan->is($this->plan));
    }

    public function test_the_nightly_command_can_adapt_every_plan_at_once(): void
    {
        Bus::fake([AdaptPlan::class]);
        $this->travelTo('2026-10-14 12:00:00');

        $this->artisan('coach:nightly --all')->assertSuccessful();

        Bus::assertDispatchedTimes(AdaptPlan::class, 1);
    }
}
