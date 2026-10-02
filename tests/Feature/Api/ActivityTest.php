<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Activity;
use App\Models\Athlete;
use App\Models\Threshold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityTest extends TestCase
{
    use RefreshDatabase;

    private Athlete $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete = Athlete::factory()->create(['weekly_hours' => 8]);
        Sanctum::actingAs($this->athlete->user);
    }

    public function test_a_manual_ride_is_scored_from_power(): void
    {
        Threshold::factory()->for($this->athlete)->ftp(250)->create();

        $this->postJson('/api/v1/activities', [
            'sport' => 'bike',
            'started_at' => now()->subHours(3)->toIso8601String(),
            'duration_s' => 7200,
            'np_w' => 200,
        ])
            ->assertCreated()
            ->assertJsonPath('data.tss', 128)
            ->assertJsonPath('data.tss_method', 'power')
            ->assertJsonPath('data.source', 'manual');
    }

    public function test_old_activities_are_scored_with_the_thresholds_valid_back_then(): void
    {
        Threshold::factory()->for($this->athlete)->ftp(200)->create(['tested_at' => today()->subMonths(3)]);
        Threshold::factory()->for($this->athlete)->ftp(250)->create(['tested_at' => today()->subWeek()]);

        $this->postJson('/api/v1/activities', [
            'sport' => 'bike',
            'started_at' => now()->subMonth()->toIso8601String(),
            'duration_s' => 3600,
            'np_w' => 200,
        ])->assertCreated()->assertJsonPath('data.tss', 100);
    }

    public function test_activities_older_than_every_test_use_the_oldest_known_value(): void
    {
        Threshold::factory()->for($this->athlete)->ftp(250)->create(['tested_at' => today()->subWeek()]);

        $this->postJson('/api/v1/activities', [
            'sport' => 'bike',
            'started_at' => now()->subMonth()->toIso8601String(),
            'duration_s' => 3600,
            'np_w' => 250,
        ])->assertCreated()->assertJsonPath('data.tss_method', 'power');
    }

    public function test_run_pace_is_derived_from_distance_when_missing(): void
    {
        Threshold::factory()->for($this->athlete)->runPace(240)->create();

        $this->postJson('/api/v1/activities', [
            'sport' => 'run',
            'started_at' => now()->subHour()->toIso8601String(),
            'duration_s' => 3000,
            'distance_m' => 10000,
        ])
            ->assertCreated()
            ->assertJsonPath('data.tss_method', 'pace')
            ->assertJsonPath('data.intensity_factor', 0.8);
    }

    public function test_athlete_supplied_tss_is_kept(): void
    {
        $this->postJson('/api/v1/activities', [
            'sport' => 'strength',
            'started_at' => now()->subHour()->toIso8601String(),
            'duration_s' => 2700,
            'tss' => 35,
        ])
            ->assertCreated()
            ->assertJsonPath('data.tss', 35)
            ->assertJsonPath('data.tss_method', 'provided');
    }

    public function test_recording_an_activity_rebuilds_daily_load_through_today(): void
    {
        $this->postJson('/api/v1/activities', [
            'sport' => 'run',
            'started_at' => now()->subDays(3)->toIso8601String(),
            'duration_s' => 3600,
            'tss' => 70,
        ])->assertCreated();

        $this->assertSame(4, $this->athlete->dailyLoads()->count());

        $this->getJson('/api/v1/load')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.tss', 70);
    }

    public function test_deleting_an_activity_removes_its_load(): void
    {
        $id = $this->postJson('/api/v1/activities', [
            'sport' => 'run',
            'started_at' => now()->subDays(2)->toIso8601String(),
            'duration_s' => 3600,
            'tss' => 70,
        ])->json('data.id');

        $this->deleteJson("/api/v1/activities/{$id}")->assertNoContent();

        $this->assertSame(0, $this->athlete->dailyLoads()->count());
    }

    public function test_synced_activities_cannot_be_edited(): void
    {
        $activity = Activity::factory()->for($this->athlete)->create(['source' => 'strava', 'external_id' => '1']);

        $this->patchJson("/api/v1/activities/{$activity->id}", ['duration_s' => 600])->assertForbidden();
    }

    public function test_future_activities_are_rejected(): void
    {
        $this->postJson('/api/v1/activities', [
            'sport' => 'run',
            'started_at' => now()->addDay()->toIso8601String(),
            'duration_s' => 3600,
        ])->assertUnprocessable()->assertJsonValidationErrors('started_at');
    }

    public function test_the_load_summary_falls_back_to_the_weekly_hours_estimate(): void
    {
        $this->getJson('/api/v1/load/summary')
            ->assertOk()
            ->assertJsonPath('data.ctl', 60)
            ->assertJsonPath('data.tsb', 0)
            ->assertJsonPath('data.has_history', false);
    }

    public function test_the_summary_reports_form_the_same_way_as_the_daily_series(): void
    {
        $this->postJson('/api/v1/activities', [
            'sport' => 'run', 'started_at' => now()->subDays(1)->toIso8601String(), 'duration_s' => 5400, 'tss' => 150,
        ])->assertCreated();

        $todayRow = $this->athlete->dailyLoads()->whereDate('date', today())->sole();

        $this->getJson('/api/v1/load/summary')->assertOk()->assertJsonPath('data.tsb', $todayRow->tsb);
    }
}
