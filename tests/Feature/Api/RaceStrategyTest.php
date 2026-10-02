<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Athlete;
use App\Models\Race;
use App\Models\Threshold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RaceStrategyTest extends TestCase
{
    use RefreshDatabase;

    private Athlete $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete = Athlete::factory()->create(['weight_kg' => 72, 'experience' => 'intermediate']);
        Sanctum::actingAs($this->athlete->user);
    }

    public function test_it_paces_a_race_from_the_current_thresholds(): void
    {
        Threshold::factory()->for($this->athlete)->ftp(250)->create();
        Threshold::factory()->for($this->athlete)->runPace(270)->create();
        Threshold::factory()->for($this->athlete)->css(105)->create();
        $race = Race::factory()->for($this->athlete)->create(['distance' => 'half']);

        $response = $this->getJson("/api/v1/races/{$race->id}/strategy")
            ->assertOk()
            ->assertJsonCount(3, 'data.legs')
            ->assertJsonPath('data.legs.1.sport', 'bike')
            ->assertJsonPath('data.legs.1.target.unit', 'watts')
            ->assertJsonPath('data.legs.1.target.target', 189)
            ->assertJsonPath('data.legs.2.distance_m', 21097)
            ->assertJsonPath('data.transitions_s', 420)
            ->assertJsonPath('data.missing', []);

        $this->assertGreaterThan(4 * 3600, $response->json('data.finish_s'));
        $this->assertLessThan(6 * 3600, $response->json('data.finish_s'));
        $this->assertNotEmpty($response->json('data.fueling.during'));
    }

    public function test_without_thresholds_it_still_gives_advice_and_says_what_is_missing(): void
    {
        $race = Race::factory()->for($this->athlete)->create(['distance' => '10k']);

        $this->getJson("/api/v1/races/{$race->id}/strategy")
            ->assertOk()
            ->assertJsonPath('data.legs.0.target', null)
            ->assertJsonPath('data.finish_s', null)
            ->assertJsonPath('data.missing.0', 'Add your run threshold pace for a run pace and split.');
    }

    public function test_other_athletes_races_are_forbidden(): void
    {
        $race = Race::factory()->for(Athlete::factory())->create();

        $this->getJson("/api/v1/races/{$race->id}/strategy")->assertForbidden();
    }
}
