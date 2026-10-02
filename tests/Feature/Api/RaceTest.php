<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Athlete;
use App\Models\Race;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RaceTest extends TestCase
{
    use RefreshDatabase;

    private Athlete $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete = Athlete::factory()->create();
        Sanctum::actingAs($this->athlete->user);
    }

    public function test_an_athlete_can_add_a_race_which_defaults_to_an_a_race(): void
    {
        $date = today()->addWeeks(16)->toDateString();

        $this->postJson('/api/v1/races', ['name' => 'Jurmala 70.3', 'distance' => 'half', 'date' => $date])
            ->assertCreated()
            ->assertJsonPath('data.priority', 'A')
            ->assertJsonPath('data.date', $date)
            ->assertJsonPath('data.days_to_go', 16 * 7);
    }

    public function test_races_must_be_in_the_future(): void
    {
        $this->postJson('/api/v1/races', ['name' => 'Past', 'distance' => 'half', 'date' => today()->toDateString()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    public function test_an_athlete_only_sees_their_own_races(): void
    {
        Race::factory()->for($this->athlete)->create();
        $other = Race::factory()->create();

        $this->getJson('/api/v1/races')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/races/{$other->id}")->assertForbidden();
    }

    public function test_a_race_can_be_updated_and_deleted(): void
    {
        $race = Race::factory()->for($this->athlete)->create();

        $this->patchJson("/api/v1/races/{$race->id}", ['priority' => 'B'])
            ->assertOk()
            ->assertJsonPath('data.priority', 'B');

        $this->deleteJson("/api/v1/races/{$race->id}")->assertNoContent();
        $this->assertModelMissing($race);
    }
}
