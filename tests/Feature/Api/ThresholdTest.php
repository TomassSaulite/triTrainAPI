<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Athlete;
use App\Models\Threshold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ThresholdTest extends TestCase
{
    use RefreshDatabase;

    private Athlete $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete = Athlete::factory()->create();
        Sanctum::actingAs($this->athlete->user);
    }

    public function test_recording_a_threshold_derives_the_sport_from_the_metric(): void
    {
        $this->postJson('/api/v1/thresholds', ['metric' => 'ftp_w', 'value' => 260])
            ->assertCreated()
            ->assertJsonPath('data.sport', 'bike')
            ->assertJsonPath('data.source', 'test')
            ->assertJsonPath('data.tested_at', today()->toDateString());
    }

    public function test_implausible_values_are_rejected(): void
    {
        $this->postJson('/api/v1/thresholds', ['metric' => 'ftp_w', 'value' => 2600])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('value');
    }

    public function test_athletes_cannot_claim_a_value_was_auto_detected(): void
    {
        $this->postJson('/api/v1/thresholds', ['metric' => 'ftp_w', 'value' => 260, 'source' => 'auto_detected'])
            ->assertUnprocessable();
    }

    public function test_current_returns_the_newest_value_per_metric(): void
    {
        Threshold::factory()->for($this->athlete)->ftp(240)->create(['tested_at' => '2026-01-01']);
        Threshold::factory()->for($this->athlete)->ftp(255)->create(['tested_at' => '2026-03-01']);
        Threshold::factory()->for($this->athlete)->runPace(270)->create(['tested_at' => '2026-02-01']);

        $this->getJson('/api/v1/thresholds/current')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['metric' => 'ftp_w', 'value' => 255]);
    }

    public function test_history_lists_every_entry_newest_first(): void
    {
        Threshold::factory()->for($this->athlete)->ftp(240)->create(['tested_at' => '2026-01-01']);
        Threshold::factory()->for($this->athlete)->ftp(255)->create(['tested_at' => '2026-03-01']);

        $this->getJson('/api/v1/thresholds?metric=ftp_w')
            ->assertOk()
            ->assertJsonPath('data.0.value', 255)
            ->assertJsonPath('data.1.value', 240);
    }

    public function test_an_athlete_cannot_delete_someone_elses_threshold(): void
    {
        $other = Threshold::factory()->create();

        $this->deleteJson("/api/v1/thresholds/{$other->id}")->assertForbidden();
        $this->assertModelExists($other);
    }

    public function test_status_says_which_thresholds_are_due_a_retest_and_how(): void
    {
        $this->travelTo('2026-10-07 08:00:00');
        Threshold::factory()->for($this->athlete)->ftp(240)->create(['tested_at' => '2026-07-01']);
        Threshold::factory()->for($this->athlete)->ftp(250)->create(['tested_at' => '2026-07-20']);
        Threshold::factory()->for($this->athlete)->runPace(270)->create(['tested_at' => '2026-09-20']);

        $data = collect($this->getJson('/api/v1/thresholds/status')->assertOk()->json('data'))->keyBy('metric');

        $this->assertSame(['value' => 250, 'age_days' => 79, 'status' => 'due'], array_intersect_key($data['ftp_w'], array_flip(['status', 'value', 'age_days'])));
        $this->assertSame('ok', $data['threshold_pace_s_per_km']['status']);
        $this->assertSame('missing', $data['css_s_per_100m']['status']);
        $this->assertStringContainsString('20 minutes', $data['ftp_w']['protocol']);
    }

    public function test_the_whole_history_can_be_loaded_in_one_page(): void
    {
        Threshold::factory()->for($this->athlete)->ftp(250)->count(20)->create();

        $this->getJson('/api/v1/thresholds?metric=ftp_w&per_page=100')->assertOk()->assertJsonCount(20, 'data');
        $this->getJson('/api/v1/thresholds?per_page=500')->assertUnprocessable();
    }
}
