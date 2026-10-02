<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RevisionReason;
use App\Jobs\ReplanActivePlan;
use App\Models\Athlete;
use App\Models\Plan;
use App\Models\Race;
use App\Services\Planning\PlanService;
use Database\Seeders\WorkoutTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReplanTriggersTest extends TestCase
{
    use RefreshDatabase;

    private Athlete $athlete;

    private Race $race;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 08:00:00');
        $this->seed(WorkoutTemplateSeeder::class);
        $this->athlete = Athlete::factory()->create(['weekly_hours' => 9]);
        $this->race = Race::factory()->for($this->athlete)->create(['date' => '2027-03-14']);
        $this->plan = app(PlanService::class)->create($this->athlete, $this->race);

        Sanctum::actingAs($this->athlete->user);
    }

    public function test_changing_the_training_profile_re_plans(): void
    {
        $this->putJson('/api/v1/athlete', ['weekly_hours' => 6])->assertOk();

        $revision = $this->plan->revisions()->first();
        $this->assertSame(RevisionReason::Regenerated, $revision->reason);
        $this->assertSame('Re-planned for your updated training profile.', $revision->summary);
    }

    public function test_a_profile_save_without_plan_changes_does_not_re_plan(): void
    {
        Bus::fake([ReplanActivePlan::class]);

        $this->putJson('/api/v1/athlete', ['weight_kg' => 70, 'timezone' => 'Europe/Riga'])->assertOk();

        Bus::assertNotDispatched(ReplanActivePlan::class);
    }

    public function test_adding_a_b_race_inside_the_plan_re_plans_around_it(): void
    {
        $this->postJson('/api/v1/races', ['name' => 'Autumn Olympic', 'distance' => 'olympic', 'date' => '2026-11-21', 'priority' => 'B'])
            ->assertCreated();

        $this->assertSame(0, $this->plan->workouts()->whereDate('date', '2026-11-21')->count());
        $this->assertStringContainsString('Autumn Olympic', $this->plan->revisions()->first()->summary);
    }

    public function test_races_outside_the_plan_window_are_ignored(): void
    {
        Bus::fake([ReplanActivePlan::class]);

        $this->postJson('/api/v1/races', ['name' => 'Next season', 'distance' => 'olympic', 'date' => '2027-06-01', 'priority' => 'C'])
            ->assertCreated();

        Bus::assertNotDispatched(ReplanActivePlan::class);
    }

    public function test_moving_the_a_race_re_plans_to_the_new_date(): void
    {
        $this->patchJson("/api/v1/races/{$this->race->id}", ['date' => '2027-03-28'])->assertOk();

        $this->assertSame(2, $this->plan->refresh()->version);
        $this->assertTrue($this->plan->workouts()->whereDate('date', '2027-03-20')->exists());
    }

    public function test_availability_changes_in_the_plan_re_plan(): void
    {
        $this->putJson('/api/v1/availability/2026-10-17', ['available_minutes' => 0])->assertCreated();

        $this->assertSame(0, $this->plan->workouts()->whereDate('date', '2026-10-17')->count());
        $this->assertStringContainsString('availability', $this->plan->revisions()->first()->summary);
    }

    public function test_past_availability_changes_are_ignored(): void
    {
        Bus::fake([ReplanActivePlan::class]);

        $this->putJson('/api/v1/availability/2026-10-01', ['available_minutes' => 0])->assertCreated();

        Bus::assertNotDispatched(ReplanActivePlan::class);
    }

    public function test_re_planning_too_close_to_the_race_keeps_the_plan(): void
    {
        $this->travelTo('2027-03-13 08:00:00');

        $this->putJson('/api/v1/athlete', ['weekly_hours' => 5])->assertOk();

        $this->assertSame(1, $this->plan->refresh()->version);
    }

    public function test_a_spring_marathon_defaults_to_a_b_race_and_reshapes_the_plan(): void
    {
        $this->postJson('/api/v1/races', ['name' => 'Riga Marathon', 'distance' => 'marathon', 'date' => '2027-01-24'])
            ->assertCreated()
            ->assertJsonPath('data.priority', 'B')
            ->assertJsonPath('data.distance', 'marathon');

        $this->assertSame(0, $this->plan->workouts()->whereDate('date', '2027-01-24')->count());
        $this->assertStringContainsString('Riga Marathon', $this->plan->revisions()->first()->summary);
    }

    public function test_a_running_race_cannot_be_an_a_race(): void
    {
        $this->postJson('/api/v1/races', ['name' => 'Riga Marathon', 'distance' => 'marathon', 'date' => '2027-01-24', 'priority' => 'A'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('priority');
    }
}
