<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ActivitySource;
use App\Models\Athlete;
use App\Models\Threshold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityImportTest extends TestCase
{
    use RefreshDatabase;

    private Athlete $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete = Athlete::factory()->create();
        Threshold::factory()->for($this->athlete)->runPace(270)->create();
        Sanctum::actingAs($this->athlete->user);
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(int $duration = 3000): array
    {
        return [
            'source' => 'health_connect',
            'activities' => [
                ['external_id' => 'hc-1', 'sport' => 'run', 'started_at' => now()->subDays(2)->toIso8601String(), 'duration_s' => $duration, 'distance_m' => 10000],
                ['external_id' => 'hc-2', 'sport' => 'swim', 'started_at' => now()->subDay()->toIso8601String(), 'duration_s' => 2400, 'distance_m' => 2000],
            ],
        ];
    }

    public function test_a_batch_is_imported_scored_and_counted(): void
    {
        $this->postJson('/api/v1/activities/import', self::payload())
            ->assertCreated()
            ->assertExactJson(['data' => ['created' => 2, 'updated' => 0, 'unchanged' => 0]]);

        $run = $this->athlete->activities()->where('external_id', 'hc-1')->sole();
        $this->assertSame(ActivitySource::HealthConnect, $run->source);
        $this->assertSame(0.9, $run->intensity_factor);
        $this->assertSame(3, $this->athlete->dailyLoads()->count());
    }

    public function test_re_sending_a_batch_is_harmless_and_changes_are_applied(): void
    {
        $this->postJson('/api/v1/activities/import', self::payload())->assertCreated();

        $this->postJson('/api/v1/activities/import', self::payload())
            ->assertOk()
            ->assertJsonPath('data.unchanged', 2);

        $this->postJson('/api/v1/activities/import', self::payload(duration: 3300))
            ->assertOk()
            ->assertJsonPath('data.updated', 1);

        $this->assertSame(2, $this->athlete->activities()->count());
        $this->assertSame(3300, $this->athlete->activities()->where('external_id', 'hc-1')->value('duration_s'));
    }

    public function test_only_device_sources_can_be_imported(): void
    {
        $this->postJson('/api/v1/activities/import', [...self::payload(), 'source' => 'strava'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source');
    }

    public function test_external_ids_must_be_unique_within_a_batch(): void
    {
        $payload = self::payload();
        $payload['activities'][1]['external_id'] = 'hc-1';

        $this->postJson('/api/v1/activities/import', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('activities.0.external_id');
    }
}
