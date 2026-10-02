<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\RevisionReason;
use App\Enums\WorkoutStatus;
use App\Models\Activity;
use App\Models\Athlete;
use App\Models\Plan;
use App\Models\Race;
use App\Models\Threshold;
use Database\Seeders\WorkoutTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WeeklyReviewTest extends TestCase
{
    use RefreshDatabase;

    private Athlete $athlete;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 08:00:00');
        $this->seed(WorkoutTemplateSeeder::class);

        $this->athlete = Athlete::factory()->create(['weekly_hours' => 9, 'birth_year' => 1990]);
        Threshold::factory()->for($this->athlete)->ftp(250)->create();
        Threshold::factory()->for($this->athlete)->runPace(270)->create();
        Threshold::factory()->for($this->athlete)->css(105)->create();
        $race = Race::factory()->for($this->athlete)->create(['date' => '2027-03-14', 'name' => 'Jurmala 70.3']);

        Sanctum::actingAs($this->athlete->user);
        $this->postJson("/api/v1/races/{$race->id}/plan")->assertCreated();
        $this->plan = $this->athlete->activePlan()->sole();
    }

    /**
     * Logs one bike ride mid-week carrying $share of the week's planned load.
     */
    private function doShareOfWeek(string $monday, float $share): void
    {
        $planned = $this->plan->workouts()->whereNull('parent_id')
            ->whereBetween('date', [$monday, date('Y-m-d', strtotime("{$monday} +6 days")).' 23:59:59'])
            ->sum('target_tss');

        Activity::factory()->for($this->athlete)->create([
            'sport' => 'bike', 'started_at' => date('Y-m-d', strtotime("{$monday} +2 days")).' 07:00:00',
            'duration_s' => 3 * 3600, 'tss' => round($planned * $share, 1),
        ]);
    }

    public function test_it_reviews_last_week_and_names_the_missed_key_sessions(): void
    {
        $this->travelTo('2026-10-19 08:00:00');
        $this->doShareOfWeek('2026-10-12', 0.9);
        $keys = $this->plan->workouts()->whereNull('parent_id')->where('is_key', true)
            ->whereBetween('date', ['2026-10-12', '2026-10-18 23:59:59'])->orderBy('date')->get();
        $keys->first()->update(['status' => WorkoutStatus::Completed]);

        $response = $this->getJson("/api/v1/plans/{$this->plan->id}/weekly-review")
            ->assertOk()
            ->assertJsonPath('data.week_start', '2026-10-12')
            ->assertJsonPath('data.finished', true)
            ->assertJsonPath('data.compliance', 0.9)
            ->assertJsonPath('data.key_sessions.planned', $keys->count())
            ->assertJsonPath('data.key_sessions.done', 1);

        $missed = $keys->skip(1)->pluck('title')->values()->all();
        $this->assertNotEmpty($missed, 'A base week has more than one key session.');
        $this->assertSame($missed, $response->json('data.key_sessions.missed'));
        $this->assertSame('keys_missed', $response->json('data.verdict'));
        $this->assertStringStartsWith('You did 90% of the planned load', $response->json('data.headline'));
        $this->assertStringStartsWith('Next week', $response->json('data.next_week'));
    }

    public function test_a_complete_week_is_on_track_and_explains_fitness(): void
    {
        $this->travelTo('2026-10-19 08:00:00');
        $this->doShareOfWeek('2026-10-12', 1.0);
        $this->plan->workouts()->whereBetween('date', ['2026-10-12', '2026-10-18 23:59:59'])->update(['status' => WorkoutStatus::Completed]);
        $this->athlete->dailyLoads()->create(['date' => '2026-10-11', 'tss' => 0, 'ctl' => 60.2, 'atl' => 60, 'tsb' => 0]);
        $this->athlete->dailyLoads()->create(['date' => '2026-10-18', 'tss' => 0, 'ctl' => 62.9, 'atl' => 70, 'tsb' => -8]);

        $this->getJson("/api/v1/plans/{$this->plan->id}/weekly-review")
            ->assertOk()
            ->assertJsonPath('data.verdict', 'on_track')
            ->assertJsonPath('data.fitness.ctl_after', 62.9)
            ->assertJsonFragment(['Fitness rose from 60 to 63.']);
    }

    public function test_it_lists_the_coachs_changes_but_not_the_athletes_own(): void
    {
        $this->travelTo('2026-10-14 08:00:00');
        $this->plan->recordRevision(RevisionReason::Adapted, 'Moved the long run after a missed session.');
        $this->plan->recordRevision(RevisionReason::Manual, 'Moved a ride.');
        $this->travelTo('2026-10-19 08:00:00');

        $response = $this->getJson("/api/v1/plans/{$this->plan->id}/weekly-review")->assertOk();

        $this->assertSame(['Moved the long run after a missed session.'], array_column($response->json('data.coach_changes'), 'summary'));
    }

    public function test_a_given_week_can_be_reviewed_while_it_is_under_way(): void
    {
        $this->getJson("/api/v1/plans/{$this->plan->id}/weekly-review?week=2026-10-07")
            ->assertOk()
            ->assertJsonPath('data.week_start', '2026-10-05')
            ->assertJsonPath('data.finished', false);
    }

    public function test_there_is_nothing_to_review_in_the_plans_first_week(): void
    {
        $this->getJson("/api/v1/plans/{$this->plan->id}/weekly-review")->assertOk()->assertJsonPath('data', null);
    }

    public function test_the_week_must_be_a_date_and_the_plan_the_athletes_own(): void
    {
        $this->getJson("/api/v1/plans/{$this->plan->id}/weekly-review?week=last")->assertUnprocessable();

        Sanctum::actingAs(Athlete::factory()->create()->user);
        $this->getJson("/api/v1/plans/{$this->plan->id}/weekly-review")->assertForbidden();
    }

    public function test_it_sums_up_how_the_week_felt(): void
    {
        $this->travelTo('2026-10-19 08:00:00');
        $this->doShareOfWeek('2026-10-12', 1.0);
        $activity = $this->athlete->activities()->sole();
        $this->putJson("/api/v1/activities/{$activity->id}/feedback", ['rpe' => 7, 'muscles' => 4, 'energy' => 4, 'pain' => true, 'pain_area' => 'Knee'])
            ->assertCreated();

        $response = $this->getJson("/api/v1/plans/{$this->plan->id}/weekly-review")
            ->assertOk()
            ->assertJsonPath('data.feel.rated', 1)
            ->assertJsonPath('data.feel.muscles', 4)
            ->assertJsonPath('data.feel.pain_reports', 1);

        $notes = implode(' ', $response->json('data.notes'));
        $this->assertStringContainsString('You reported pain (Knee).', $notes);
        $this->assertStringContainsString('Your legs and energy felt heavy', $notes);
    }
}
