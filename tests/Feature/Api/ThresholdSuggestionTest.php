<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\SuggestionStatus;
use App\Enums\ThresholdSource;
use App\Models\Athlete;
use App\Models\Threshold;
use App\Services\ThresholdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ThresholdSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private Athlete $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete = Athlete::factory()->create();
        Threshold::factory()->for($this->athlete)->ftp(250)->create();
        Sanctum::actingAs($this->athlete->user);
    }

    private function ride(int $best20): void
    {
        $this->postJson('/api/v1/activities', [
            'sport' => 'bike',
            'started_at' => now()->subHours(2)->toIso8601String(),
            'duration_s' => 5400,
            'np_w' => 230,
            'best_20min_power_w' => $best20,
        ])->assertCreated();
    }

    public function test_a_breakthrough_ride_creates_a_pending_suggestion_but_no_threshold(): void
    {
        $this->ride(290);

        $this->getJson('/api/v1/threshold-suggestions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.metric', 'ftp_w')
            ->assertJsonPath('data.0.suggested_value', 276)
            ->assertJsonPath('data.0.current_value', 250);

        $this->assertSame(250.0, app(ThresholdService::class)->setFor($this->athlete)->ftpWatts);
    }

    public function test_only_one_pending_suggestion_per_metric_is_kept_with_the_best_value(): void
    {
        $this->ride(280);
        $this->ride(300);
        $this->ride(285);

        $suggestion = $this->athlete->thresholdSuggestions()->sole();
        $this->assertSame(285.0, $suggestion->suggested_value);
    }

    public function test_accepting_records_an_auto_detected_threshold(): void
    {
        $this->ride(290);
        $suggestion = $this->athlete->thresholdSuggestions()->sole();

        $this->postJson("/api/v1/threshold-suggestions/{$suggestion->id}/accept")
            ->assertCreated()
            ->assertJsonPath('data.value', 276)
            ->assertJsonPath('data.source', ThresholdSource::AutoDetected->value);

        $this->assertSame(SuggestionStatus::Accepted, $suggestion->refresh()->status);
        $this->assertSame(276.0, app(ThresholdService::class)->setFor($this->athlete)->ftpWatts);
    }

    public function test_dismissing_leaves_thresholds_alone_and_cannot_be_repeated(): void
    {
        $this->ride(290);
        $suggestion = $this->athlete->thresholdSuggestions()->sole();

        $this->postJson("/api/v1/threshold-suggestions/{$suggestion->id}/dismiss")
            ->assertOk()
            ->assertJsonPath('data.status', 'dismissed');

        $this->postJson("/api/v1/threshold-suggestions/{$suggestion->id}/accept")->assertUnprocessable();
        $this->assertSame(1, $this->athlete->thresholds()->count());
    }

    public function test_athletes_cannot_act_on_someone_elses_suggestion(): void
    {
        $other = Athlete::factory()->create()->thresholdSuggestions()->create([
            'metric' => 'ftp_w', 'suggested_value' => 300, 'rationale' => 'x', 'status' => 'pending',
        ]);

        $this->postJson("/api/v1/threshold-suggestions/{$other->id}/accept")->assertForbidden();
    }
}
