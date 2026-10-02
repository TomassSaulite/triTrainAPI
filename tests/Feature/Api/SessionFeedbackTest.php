<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\RevisionReason;
use App\Enums\WorkoutKind;
use App\Models\Activity;
use App\Models\Athlete;
use App\Models\Plan;
use App\Models\Race;
use App\Models\Threshold;
use App\Services\Planning\PlanService;
use Database\Seeders\WorkoutTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SessionFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private Athlete $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-14 12:00:00');
        $this->athlete = Athlete::factory()->create(['weekly_hours' => 9]);
        Sanctum::actingAs($this->athlete->user);
    }

    private function run_(string $startedAt = '2026-10-13 07:00:00'): Activity
    {
        return Activity::factory()->for($this->athlete)->create(['sport' => 'run', 'started_at' => $startedAt, 'duration_s' => 3600]);
    }

    private function plan(): Plan
    {
        $this->seed(WorkoutTemplateSeeder::class);
        Threshold::factory()->for($this->athlete)->runPace(270)->create();
        $race = Race::factory()->for($this->athlete)->create(['date' => '2027-03-14']);

        return app(PlanService::class)->create($this->athlete, $race);
    }

    public function test_a_session_can_be_rated_and_re_rated(): void
    {
        $activity = $this->run_();
        $url = "/api/v1/activities/{$activity->id}/feedback";

        $this->putJson($url, ['rpe' => 7, 'muscles' => 4, 'breathing' => 3, 'energy' => 2, 'mood' => 1, 'note' => 'Windy'])
            ->assertCreated()
            ->assertJsonPath('data.rpe', 7)
            ->assertJsonPath('data.muscles', 4)
            ->assertJsonPath('data.pain', false)
            ->assertJsonPath('plan_change', null);

        $this->putJson($url, ['rpe' => 6, 'pain' => true, 'pain_area' => 'Left knee'])
            ->assertOk()
            ->assertJsonPath('data.rpe', 6)
            ->assertJsonPath('data.muscles', null)
            ->assertJsonPath('data.pain_area', 'Left knee');

        $this->getJson("/api/v1/activities/{$activity->id}")->assertJsonPath('data.feedback.rpe', 6);
        $this->assertSame(1, $this->athlete->sessionFeedback()->count());

        $this->deleteJson($url)->assertNoContent();
        $this->getJson("/api/v1/activities/{$activity->id}")->assertJsonPath('data.feedback', null);
    }

    public function test_ratings_are_validated_and_pain_area_needs_pain(): void
    {
        $activity = $this->run_();
        $url = "/api/v1/activities/{$activity->id}/feedback";

        $this->putJson($url, ['rpe' => 11, 'muscles' => 0])->assertUnprocessable()->assertJsonValidationErrors(['rpe', 'muscles']);
        $this->putJson($url, ['rpe' => 5, 'pain_area' => 'Back'])->assertCreated()->assertJsonPath('data.pain_area', null);
    }

    public function test_the_history_lists_ratings_oldest_first_with_their_session(): void
    {
        $older = $this->run_('2026-10-10 07:00:00');
        $newer = $this->run_('2026-10-13 07:00:00');
        $this->putJson("/api/v1/activities/{$newer->id}/feedback", ['rpe' => 8]);
        $this->putJson("/api/v1/activities/{$older->id}/feedback", ['rpe' => 4]);
        $this->run_('2026-06-01 07:00:00');

        $this->getJson('/api/v1/feedback?days=28')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.date', '2026-10-10')
            ->assertJsonPath('data.0.rpe', 4)
            ->assertJsonPath('data.1.sport', 'run')
            ->assertJsonPath('data.1.duration_s', 3600);

        $this->getJson('/api/v1/feedback?days=0')->assertUnprocessable();
    }

    public function test_reporting_pain_eases_the_next_key_session_of_that_sport(): void
    {
        $plan = $this->plan();
        $nextKeyRun = $plan->workouts()->whereNull('parent_id')->where('sport', 'run')->where('is_key', true)
            ->where('date', '>=', '2026-10-14')->orderBy('date')->firstOrFail();

        $response = $this->putJson("/api/v1/activities/{$this->run_()->id}/feedback", ['rpe' => 6, 'pain' => true, 'pain_area' => 'Achilles'])
            ->assertCreated();

        $this->assertStringContainsString('You reported pain (Achilles) after a run', $response->json('plan_change'));
        $nextKeyRun->refresh();
        $this->assertSame(WorkoutKind::Recovery, $nextKeyRun->kind);
        $this->assertFalse($nextKeyRun->is_key);
        $this->assertSame(RevisionReason::Adapted, $plan->revisions()->first()->reason);
    }

    public function test_other_athletes_activities_cannot_be_rated(): void
    {
        $activity = Activity::factory()->for(Athlete::factory())->create();

        $this->putJson("/api/v1/activities/{$activity->id}/feedback", ['rpe' => 5])->assertForbidden();
    }
}
