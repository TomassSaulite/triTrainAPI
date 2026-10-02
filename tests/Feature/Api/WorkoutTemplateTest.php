<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Athlete;
use App\Models\WorkoutTemplate;
use Database\Seeders\WorkoutTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkoutTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Athlete $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkoutTemplateSeeder::class);
        $this->athlete = Athlete::factory()->create();
        Sanctum::actingAs($this->athlete->user);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'name' => 'Over-unders',
            'sport' => 'bike',
            'kind' => 'threshold',
            'phases' => ['build', 'peak'],
            'min_s' => 3600,
            'max_s' => 5400,
            'structure' => ['steps' => [
                ['type' => 'warmup', 'duration_s' => 900, 'target' => ['metric' => 'ftp_pct', 'low' => 0.55, 'high' => 0.65]],
                ['type' => 'repeat', 'count' => 4, 'steps' => [
                    ['type' => 'interval', 'duration_s' => 120, 'target' => ['metric' => 'ftp_pct', 'low' => 0.92, 'high' => 0.95]],
                    ['type' => 'interval', 'duration_s' => 60, 'target' => ['metric' => 'ftp_pct', 'low' => 1.05, 'high' => 1.08]],
                ]],
                ['type' => 'cooldown', 'duration_s' => 600, 'target' => ['metric' => 'ftp_pct', 'low' => 0.5, 'high' => 0.6]],
            ]],
        ];
    }

    public function test_the_system_library_is_visible_to_every_athlete(): void
    {
        $this->getJson('/api/v1/workout-templates?sport=swim')
            ->assertOk()
            ->assertJsonPath('data.0.sport', 'swim')
            ->assertJsonPath('data.0.is_system', true);
    }

    public function test_an_athlete_can_add_a_personal_template(): void
    {
        $this->postJson('/api/v1/workout-templates', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.kind', 'threshold');

        $template = $this->athlete->workoutTemplates()->sole();
        $this->assertGreaterThan(0.6, $template->intensity_factor);
        $this->assertStringStartsWith('over-unders-', $template->slug);
    }

    public function test_invalid_structures_are_reported_with_their_path(): void
    {
        $payload = $this->payload();
        $payload['structure']['steps'][1]['steps'][0]['target']['low'] = 250;

        $this->postJson('/api/v1/workout-templates', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('structure')
            ->assertJsonPath('errors.structure.0', fn (string $m) => str_contains($m, 'steps.1.steps.0.target.low'));
    }

    public function test_system_templates_are_read_only(): void
    {
        $system = WorkoutTemplate::whereNull('athlete_id')->first();

        $this->patchJson("/api/v1/workout-templates/{$system->id}", ['name' => 'Mine now'])->assertForbidden();
        $this->deleteJson("/api/v1/workout-templates/{$system->id}")->assertForbidden();
    }

    public function test_other_athletes_personal_templates_are_hidden(): void
    {
        $other = WorkoutTemplate::factory()->for(Athlete::factory())->create();

        $this->getJson("/api/v1/workout-templates/{$other->id}")->assertForbidden();
        $this->getJson('/api/v1/workout-templates?mine=1')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_max_duration_cannot_be_below_min_duration(): void
    {
        $this->postJson('/api/v1/workout-templates', [...$this->payload(), 'min_s' => 5400, 'max_s' => 3600])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('max_s');
    }
}
