<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WorkoutStatus;
use App\Models\Athlete;
use App\Models\Plan;
use App\Models\PlannedWorkout;
use App\Models\Race;
use App\Models\Threshold;
use App\Services\Planning\PlanService;
use Database\Seeders\WorkoutTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityMatchingTest extends TestCase
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
        Threshold::factory()->for($this->athlete)->runPace(270)->create();
        $race = Race::factory()->for($this->athlete)->create(['date' => '2027-03-14']);
        $this->plan = app(PlanService::class)->create($this->athlete, $race);

        Sanctum::actingAs($this->athlete->user);
    }

    private function plannedOn(string $date, string $sport): PlannedWorkout
    {
        return $this->plan->workouts()->whereNull('parent_id')->whereDate('date', $date)->where('sport', $sport)->firstOrFail();
    }

    /**
     * Leaves $planned as the only run within the matching window.
     */
    private function dropOtherRunsAround(PlannedWorkout $planned): void
    {
        $this->plan->workouts()
            ->where('sport', 'run')
            ->whereKeyNot($planned->id)
            ->whereBetween('date', [$planned->date->copy()->subDay(), $planned->date->copy()->addDay()])
            ->update(['status' => WorkoutStatus::Dropped]);
    }

    private function record(string $sport, string $startedAt, int $seconds, float $tss): int
    {
        return $this->postJson('/api/v1/activities', [
            'sport' => $sport,
            'started_at' => $startedAt,
            'duration_s' => $seconds,
            'tss' => $tss,
        ])->assertCreated()->json('data.id');
    }

    public function test_an_activity_completes_the_matching_workout(): void
    {
        $this->travelTo('2026-10-08 20:00:00');
        $planned = $this->plannedOn('2026-10-08', 'run');

        $id = $this->record('run', '2026-10-08T07:00:00Z', $planned->target_duration_s, $planned->target_tss);

        $planned->refresh();
        $this->assertSame($id, $planned->activity_id);
        $this->assertSame(WorkoutStatus::Completed, $planned->status);
        $this->assertSame(1.0, $planned->compliance);
        $this->getJson("/api/v1/activities/{$id}")->assertJsonPath('data.planned_workout_id', $planned->id);
    }

    public function test_a_short_effort_counts_as_partial(): void
    {
        $this->travelTo('2026-10-08 20:00:00');
        $planned = $this->plannedOn('2026-10-08', 'run');
        $this->dropOtherRunsAround($planned);

        $this->record('run', '2026-10-08T07:00:00Z', (int) ($planned->target_duration_s * 0.6), $planned->target_tss * 0.6);

        $this->assertSame(WorkoutStatus::Partial, $planned->refresh()->status);
    }

    public function test_an_activity_a_day_late_still_matches(): void
    {
        $this->travelTo('2026-10-09 20:00:00');
        $planned = $this->plannedOn('2026-10-08', 'run');

        $this->record('run', '2026-10-09T18:00:00Z', $planned->target_duration_s, $planned->target_tss);

        $this->assertNotNull($planned->refresh()->activity_id);
    }

    public function test_unplanned_activities_stay_unmatched_but_count_toward_load(): void
    {
        $this->travelTo('2026-10-08 20:00:00');

        $id = $this->record('strength', '2026-10-08T07:00:00Z', 2700, 30);

        $this->getJson("/api/v1/activities/{$id}")->assertJsonPath('data.planned_workout_id', null);
        $this->assertSame(30.0, $this->athlete->dailyLoads()->whereDate('date', '2026-10-08')->value('tss'));
    }

    public function test_deleting_a_matched_activity_reopens_or_misses_the_workout(): void
    {
        $this->travelTo('2026-10-08 20:00:00');
        $planned = $this->plannedOn('2026-10-08', 'run');
        $id = $this->record('run', '2026-10-08T07:00:00Z', $planned->target_duration_s, $planned->target_tss);

        $this->travelTo('2026-10-10 08:00:00');
        $this->deleteJson("/api/v1/activities/{$id}")->assertNoContent();

        $planned->refresh();
        $this->assertNull($planned->activity_id);
        $this->assertSame(WorkoutStatus::Missed, $planned->status);
    }

    public function test_both_halves_complete_a_brick(): void
    {
        $brick = $this->plan->workouts()->where('sport', 'brick')->with('children')->firstOrFail();
        [$bike, $run] = [$brick->children[0], $brick->children[1]];
        $this->travelTo($brick->date->copy()->setTime(20, 0));
        $day = $brick->date->toDateString();

        $this->record('bike', "{$day}T08:00:00Z", $bike->target_duration_s, $bike->target_tss);
        $this->assertSame(WorkoutStatus::Planned, $brick->refresh()->status);

        $this->record('run', "{$day}T11:00:00Z", $run->target_duration_s, $run->target_tss);
        $this->assertSame(WorkoutStatus::Completed, $brick->refresh()->status);
        $this->assertSame(1.0, $brick->compliance);
    }
}
