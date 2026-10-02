<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\PlanStatus;
use App\Enums\WorkoutStatus;
use App\Models\Activity;
use App\Models\Athlete;
use App\Models\Plan;
use App\Models\PlannedWorkout;
use App\Models\Race;
use App\Models\Threshold;
use Database\Seeders\WorkoutTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlanTest extends TestCase
{
    use RefreshDatabase;

    private Athlete $athlete;

    private Race $race;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 08:00:00');
        $this->seed(WorkoutTemplateSeeder::class);

        $this->athlete = Athlete::factory()->create(['weekly_hours' => 9, 'birth_year' => 1990]);
        Threshold::factory()->for($this->athlete)->ftp(250)->create();
        Threshold::factory()->for($this->athlete)->runPace(270)->create();
        Threshold::factory()->for($this->athlete)->css(105)->create();
        $this->race = Race::factory()->for($this->athlete)->create(['date' => '2027-03-14', 'name' => 'Jurmala 70.3']);

        Sanctum::actingAs($this->athlete->user);
    }

    private function createPlan(): Plan
    {
        $this->postJson("/api/v1/races/{$this->race->id}/plan")->assertCreated();

        return $this->athlete->activePlan()->sole();
    }

    public function test_generating_a_plan_writes_phases_weeks_workouts_and_a_first_revision(): void
    {
        $response = $this->postJson("/api/v1/races/{$this->race->id}/plan")
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.race.name', 'Jurmala 70.3')
            ->assertJsonCount(4, 'data.phases')
            ->assertJsonCount(23, 'data.weeks');

        $plan = Plan::sole();
        $this->assertGreaterThan(150, $plan->workouts()->count());
        $this->assertSame(0, $plan->workouts()->whereDate('date', '<', '2026-10-07')->count());
        $this->assertSame(1, $plan->revisions()->count());
        $this->assertGreaterThan(0, $response->json('data.weeks.1.planned_tss'));
    }

    public function test_bricks_are_stored_as_a_parent_with_bike_and_run_children(): void
    {
        $plan = $this->createPlan();
        $brick = $plan->workouts()->where('sport', 'brick')->firstOrFail();

        $this->assertSame(['bike', 'run'], $brick->children()->orderBy('id')->get()->pluck('sport.value')->all());
        $this->assertNull($brick->structure);
    }

    public function test_only_a_races_get_a_plan(): void
    {
        $this->race->update(['priority' => 'B']);

        $this->postJson("/api/v1/races/{$this->race->id}/plan")->assertUnprocessable();
    }

    public function test_a_new_plan_archives_the_previous_one(): void
    {
        $first = $this->createPlan();
        $this->createPlan();

        $this->assertSame(PlanStatus::Archived, $first->refresh()->status);
        $this->getJson('/api/v1/plans')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_current_returns_the_active_plan_or_404(): void
    {
        $this->getJson('/api/v1/plans/current')->assertNotFound();

        $plan = $this->createPlan();

        $this->getJson('/api/v1/plans/current')->assertOk()->assertJsonPath('data.id', $plan->id);
    }

    public function test_other_athletes_plans_are_forbidden(): void
    {
        $plan = $this->createPlan();
        Sanctum::actingAs(Athlete::factory()->create()->user);

        $this->getJson("/api/v1/plans/{$plan->id}")->assertForbidden();
        $this->postJson("/api/v1/races/{$this->race->id}/plan")->assertForbidden();
    }

    public function test_regenerating_keeps_history_and_logs_what_changed(): void
    {
        $plan = $this->createPlan();
        $done = $plan->workouts()->whereNull('parent_id')->whereDate('date', '2026-10-07')->firstOrFail();
        $activity = Activity::factory()->for($this->athlete)->create(['started_at' => '2026-10-07 07:00:00']);
        $done->update(['status' => WorkoutStatus::Completed, 'activity_id' => $activity->id]);

        $this->travelTo('2026-10-21 08:00:00');
        $this->athlete->update(['weekly_hours' => 6]);

        $this->postJson("/api/v1/plans/{$plan->id}/regenerate", ['reason' => 'Less time at work for a while.'])
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        $this->assertModelExists($done);
        $this->assertSame(0, $plan->workouts()->whereDate('date', '>=', '2026-10-21')->whereNotIn('status', WorkoutStatus::open())->count());
        $this->assertSame(23, $plan->weeks()->count());

        $revision = $plan->revisions()->first();
        $this->assertSame(2, $revision->version);
        $this->assertSame('Less time at work for a while.', $revision->summary);
        $this->assertNotEmpty($revision->changes);
        $this->assertLessThan(
            array_sum(array_column($revision->changes, 'from_tss')),
            array_sum(array_column($revision->changes, 'to_tss')),
        );

        $this->getJson("/api/v1/plans/{$plan->id}/revisions")->assertOk()->assertJsonPath('data.0.version', 2);
    }

    public function test_regenerating_after_todays_workout_is_done_starts_tomorrow(): void
    {
        $plan = $this->createPlan();
        $this->travelTo('2026-10-08 19:00:00');
        $plan->workouts()->whereDate('date', '2026-10-08')->update(['status' => WorkoutStatus::Completed]);
        $today = $plan->workouts()->whereDate('date', '2026-10-08')->count();

        $this->postJson("/api/v1/plans/{$plan->id}/regenerate")->assertOk();

        $this->assertSame($today, $plan->workouts()->whereDate('date', '2026-10-08')->count());
        $this->assertGreaterThan(0, $plan->workouts()->open()->whereDate('date', '2026-10-09')->count());
    }

    public function test_regenerating_keeps_phases_contiguous(): void
    {
        $plan = $this->createPlan();
        $this->travelTo('2027-01-06 08:00:00');

        $this->postJson("/api/v1/plans/{$plan->id}/regenerate")->assertOk();

        $phases = $plan->phases()->get();
        $this->assertSame(['base', 'build', 'peak', 'taper'], $phases->pluck('type.value')->all());

        foreach ($phases->slice(1)->values() as $i => $phase) {
            $this->assertSame($phases[$i]->end_date->addDay()->toDateString(), $phase->start_date->toDateString());
        }

        $this->assertSame(0, $plan->weeks()->whereDoesntHave('phase')->count());
    }

    public function test_archived_plans_cannot_be_regenerated(): void
    {
        $plan = $this->createPlan();
        $this->postJson("/api/v1/plans/{$plan->id}/archive")->assertOk()->assertJsonPath('data.status', 'archived');

        $this->postJson("/api/v1/plans/{$plan->id}/regenerate")->assertUnprocessable();
    }

    public function test_the_calendar_shows_planned_workouts_and_activities_by_day(): void
    {
        $this->createPlan();
        Activity::factory()->for($this->athlete)->create(['started_at' => '2026-10-07 07:00:00']);

        $response = $this->getJson('/api/v1/calendar?from=2026-10-05&to=2026-10-11')
            ->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonPath('data.0.date', '2026-10-05')
            ->assertJsonCount(0, 'data.0.workouts')
            ->assertJsonCount(1, 'data.2.activities');

        $this->assertNotEmpty($response->json('data.2.workouts'));
    }

    public function test_a_b_race_in_the_plan_window_gets_a_mini_taper_and_shows_in_the_calendar(): void
    {
        Race::factory()->for($this->athlete)->create(['name' => 'Autumn Olympic', 'distance' => 'olympic', 'date' => '2026-11-21', 'priority' => 'B']);

        $plan = $this->createPlan();

        $this->assertSame(0, $plan->workouts()->whereIn('date', ['2026-11-20 00:00:00', '2026-11-21 00:00:00'])->count());
        $this->getJson('/api/v1/calendar?from=2026-11-21&to=2026-11-21')
            ->assertOk()
            ->assertJsonPath('data.0.races.0.name', 'Autumn Olympic')
            ->assertJsonPath('data.0.races.0.priority', 'B');
    }

    public function test_progress_compares_planned_and_done_per_week(): void
    {
        $plan = $this->createPlan();
        $this->travelTo('2026-10-19 08:00:00');
        $planned = $plan->workouts()->whereNull('parent_id')->whereBetween('date', ['2026-10-12', '2026-10-18 23:59:59'])->sum('target_tss');
        Activity::factory()->for($this->athlete)->create(['sport' => 'bike', 'started_at' => '2026-10-14 07:00:00', 'duration_s' => 3600, 'tss' => $planned / 2]);

        $response = $this->getJson("/api/v1/plans/{$plan->id}/progress")->assertOk()->assertJsonCount(23, 'data');

        $week = $response->json('data.1');
        $this->assertSame('2026-10-12', $week['start_date']);
        $this->assertEqualsWithDelta($planned, $week['planned']['tss'], 0.1);
        $this->assertSame(1, $week['actual']['activities']);
        $this->assertSame(0.5, $week['compliance']);
        $this->assertSame(3600, $week['by_sport']['bike']['actual_duration_s']);
        $this->assertNull($response->json('data.5.compliance'), 'Future weeks have no compliance yet.');
    }

    public function test_deleting_the_plans_race_archives_the_plan_and_keeps_its_history(): void
    {
        $plan = $this->createPlan();
        $workouts = $plan->workouts()->count();

        $this->deleteJson("/api/v1/races/{$this->race->id}")->assertNoContent();

        $plan->refresh();
        $this->assertSame(PlanStatus::Archived, $plan->status);
        $this->assertNull($plan->race_id);
        $this->assertSame($workouts, $plan->workouts()->count());
        $this->getJson('/api/v1/plans/current')->assertNotFound();
        $this->getJson("/api/v1/plans/{$plan->id}")->assertOk()->assertJsonPath('data.status', 'archived');
    }

    public function test_the_calendar_shows_each_days_own_availability(): void
    {
        $this->athlete->availabilityOverrides()->create(['date' => '2026-10-09', 'available_minutes' => 0, 'note' => 'Sick: Flu']);

        $this->getJson('/api/v1/calendar?from=2026-10-08&to=2026-10-09')
            ->assertOk()
            ->assertJsonPath('data.0.availability', null)
            ->assertJsonPath('data.1.availability.note', 'Sick: Flu')
            ->assertJsonPath('data.1.availability.available_minutes', 0);
    }

    public function test_the_calendar_rejects_huge_ranges(): void
    {
        $this->getJson('/api/v1/calendar?from=2026-01-01&to=2026-12-31')->assertUnprocessable();
    }

    public function test_a_workout_detail_resolves_targets_against_current_thresholds(): void
    {
        $plan = $this->createPlan();
        $ride = $plan->workouts()->where('sport', 'bike')->whereNull('parent_id')->firstOrFail();

        $response = $this->getJson("/api/v1/planned-workouts/{$ride->id}")->assertOk();

        $this->assertSame('watts', $response->json('data.resolved_structure.steps.0.resolved.unit'));
        $this->assertSame('ftp_pct', $response->json('data.structure.steps.0.target.metric'));
    }

    public function test_export_returns_fit_steps_for_each_half_of_a_brick(): void
    {
        $plan = $this->createPlan();
        $brick = $plan->workouts()->where('sport', 'brick')->firstOrFail();

        $this->getJson("/api/v1/planned-workouts/{$brick->id}/export")
            ->assertOk()
            ->assertJsonCount(2, 'data.workouts')
            ->assertJsonPath('data.workouts.0.sport', 'bike')
            ->assertJsonPath('data.workouts.1.steps.0.target_type', 'speed');
    }

    public function test_an_athlete_can_move_a_workout_which_is_logged(): void
    {
        $plan = $this->createPlan();
        $workout = $plan->workouts()->whereNull('parent_id')->whereDate('date', '2026-10-15')->firstOrFail();

        $this->patchJson("/api/v1/planned-workouts/{$workout->id}", ['date' => '2026-10-16'])
            ->assertOk()
            ->assertJsonPath('data.date', '2026-10-16')
            ->assertJsonPath('data.status', 'moved');

        $this->assertSame(2, $plan->refresh()->version);
        $this->assertStringStartsWith('Moved', $plan->revisions()->first()->summary);
    }

    public function test_moving_into_the_past_or_past_the_race_is_rejected(): void
    {
        $plan = $this->createPlan();
        $workout = $plan->workouts()->whereNull('parent_id')->whereDate('date', '>', '2026-10-08')->firstOrFail();

        $this->patchJson("/api/v1/planned-workouts/{$workout->id}", ['date' => '2026-10-01'])->assertUnprocessable();
        $this->patchJson("/api/v1/planned-workouts/{$workout->id}", ['date' => '2027-03-20'])->assertUnprocessable();
    }

    public function test_a_brick_half_cannot_be_moved_on_its_own(): void
    {
        $plan = $this->createPlan();
        $half = PlannedWorkout::whereNotNull('parent_id')->firstOrFail();

        $this->patchJson("/api/v1/planned-workouts/{$half->id}", ['date' => $half->date->addDay()->toDateString()])
            ->assertUnprocessable();
    }

    public function test_skipping_a_workout_drops_it(): void
    {
        $plan = $this->createPlan();
        $workout = $plan->workouts()->whereNull('parent_id')->whereDate('date', '>', '2026-10-08')->firstOrFail();

        $this->postJson("/api/v1/planned-workouts/{$workout->id}/skip")
            ->assertOk()
            ->assertJsonPath('data.status', 'dropped');
    }

    private function openWorkout(Plan $plan, string $kind): PlannedWorkout
    {
        return $plan->workouts()->whereNull('parent_id')->where('kind', $kind)->where('sport', '!=', 'brick')
            ->whereDate('date', '>', '2026-10-08')->orderBy('date')->firstOrFail();
    }

    public function test_alternatives_offer_workouts_of_the_same_sport_and_family(): void
    {
        $plan = $this->createPlan();
        $tempo = $this->openWorkout($plan, 'tempo');

        $alternatives = collect($this->getJson("/api/v1/planned-workouts/{$tempo->id}/alternatives")->assertOk()->json('data'));

        $this->assertNotEmpty($alternatives);
        $this->assertNotContains($tempo->workout_template_id, $alternatives->pluck('id'));
        $this->assertEmpty($alternatives->whereIn('kind', ['endurance', 'recovery', 'long']));
    }

    public function test_a_session_can_be_swapped_for_another_workout_of_the_same_length(): void
    {
        $plan = $this->createPlan();
        $tempo = $this->openWorkout($plan, 'tempo');
        $choice = $this->getJson("/api/v1/planned-workouts/{$tempo->id}/alternatives")->json('data.0');

        $this->postJson("/api/v1/planned-workouts/{$tempo->id}/swap", ['template_id' => $choice['id']])
            ->assertOk()
            ->assertJsonPath('data.title', $choice['name'])
            ->assertJsonPath('data.template_id', $choice['id']);

        $this->assertEqualsWithDelta($tempo->target_duration_s, $tempo->refresh()->target_duration_s, $tempo->target_duration_s * 0.25);
        $this->assertStringStartsWith('Swapped', $plan->revisions()->first()->summary);
    }

    public function test_swapping_for_an_unsuitable_workout_is_refused(): void
    {
        $plan = $this->createPlan();
        $tempo = $this->openWorkout($plan, 'tempo');
        $swim = \App\Models\WorkoutTemplate::where('sport', 'swim')->firstOrFail();

        $this->postJson("/api/v1/planned-workouts/{$tempo->id}/swap", ['template_id' => $swim->id])->assertUnprocessable();
    }

    public function test_a_session_can_be_made_shorter(): void
    {
        $plan = $this->createPlan();
        $ride = $this->openWorkout($plan, 'endurance');
        $before = $ride->target_duration_s;

        $this->patchJson("/api/v1/planned-workouts/{$ride->id}", ['duration_s' => (int) ($before * 0.7)])->assertOk();

        $this->assertLessThan($before, $ride->refresh()->target_duration_s);
        $this->assertStringStartsWith('Changed', $plan->revisions()->first()->summary);
    }

    public function test_moving_needs_a_date_or_a_length(): void
    {
        $plan = $this->createPlan();
        $ride = $this->openWorkout($plan, 'endurance');

        $this->patchJson("/api/v1/planned-workouts/{$ride->id}", [])->assertUnprocessable()->assertJsonValidationErrors(['date', 'duration_s']);
    }
}
