<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Athlete;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AthleteProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_coaching_endpoints_ask_for_a_profile_first(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/athlete')->assertConflict();
    }

    public function test_an_athlete_profile_is_created_with_default_preferences(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/athlete', [
            'experience' => 'intermediate',
            'weekly_hours' => 9,
            'birth_year' => 1990,
        ])
            ->assertCreated()
            ->assertJsonPath('data.experience', 'intermediate')
            ->assertJsonPath('data.preferences.long_ride_day', 6)
            ->assertJsonPath('data.preferences.max_ramp_rate', 5);
    }

    public function test_creating_a_profile_requires_experience_and_hours(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/athlete', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['experience', 'weekly_hours']);
    }

    public function test_preferences_are_merged_on_partial_update(): void
    {
        $athlete = Athlete::factory()->create();
        Sanctum::actingAs($athlete->user);

        $this->putJson('/api/v1/athlete', ['preferences' => ['long_ride_day' => 7]])->assertOk();
        $this->putJson('/api/v1/athlete', ['preferences' => ['pool_days' => [1, 4]]])
            ->assertOk()
            ->assertJsonPath('data.preferences.long_ride_day', 7)
            ->assertJsonPath('data.preferences.pool_days', [1, 4]);
    }

    public function test_the_long_ride_cannot_land_on_a_rest_day(): void
    {
        $athlete = Athlete::factory()->create();
        Sanctum::actingAs($athlete->user);

        $this->putJson('/api/v1/athlete', ['preferences' => ['rest_days' => [6]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('preferences.rest_days');
    }

    public function test_sport_share_must_add_up_to_one(): void
    {
        $athlete = Athlete::factory()->create();
        Sanctum::actingAs($athlete->user);

        $this->putJson('/api/v1/athlete', ['preferences' => ['sport_share' => ['swim' => 0.5, 'bike' => 0.5, 'run' => 0.5]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('preferences.sport_share');
    }
}
