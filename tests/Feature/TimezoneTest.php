<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Athlete;
use App\Models\Race;
use App\Services\Planning\PlanService;
use Database\Seeders\WorkoutTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Days belong to the athlete: an early run in Auckland happens on the
 * previous UTC date, an evening run in Los Angeles on the next.
 */
class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_follows_the_athletes_timezone(): void
    {
        $this->travelTo('2026-10-07 20:00:00');

        $this->assertSame('2026-10-08', Athlete::factory()->make(['timezone' => 'Pacific/Auckland'])->today()->toDateString());
        $this->assertSame('2026-10-07', Athlete::factory()->make(['timezone' => 'America/Los_Angeles'])->today()->toDateString());
    }

    public function test_an_activity_counts_on_the_athletes_local_day(): void
    {
        $this->travelTo('2026-10-08 06:00:00');
        $athlete = Athlete::factory()->create(['timezone' => 'Pacific/Auckland']);
        Sanctum::actingAs($athlete->user);

        // 07:00 on 8 October in Auckland is 18:00 on 7 October UTC.
        $this->postJson('/api/v1/activities', [
            'sport' => 'run', 'started_at' => '2026-10-07T18:00:00Z', 'duration_s' => 3600, 'tss' => 70,
        ])->assertCreated();

        $this->assertSame(70.0, $athlete->dailyLoads()->whereDate('date', '2026-10-08')->value('tss'));
        $this->assertNull($athlete->dailyLoads()->whereDate('date', '2026-10-07')->first());

        $this->getJson('/api/v1/calendar?from=2026-10-07&to=2026-10-08')
            ->assertJsonCount(0, 'data.0.activities')
            ->assertJsonCount(1, 'data.1.activities');
    }

    public function test_activities_match_workouts_on_the_local_day(): void
    {
        $this->travelTo('2026-10-07 06:00:00');
        $this->seed(WorkoutTemplateSeeder::class);
        $athlete = Athlete::factory()->create(['timezone' => 'America/Los_Angeles', 'weekly_hours' => 9]);
        $plan = app(PlanService::class)->create($athlete, Race::factory()->for($athlete)->create(['date' => '2027-03-14']));
        $run = $plan->workouts()->whereNull('parent_id')->where('sport', 'run')->whereDate('date', '>', '2026-10-08')->orderBy('date')->firstOrFail();
        $plan->workouts()->where('sport', 'run')->whereKeyNot($run->id)
            ->whereBetween('date', [$run->date->copy()->subDays(2), $run->date->copy()->addDays(2)])
            ->update(['status' => 'dropped']);

        // 19:00 local on the workout's day is 02:00 UTC the next day.
        $startedAt = $run->date->copy()->setTime(19, 0)->shiftTimezone('America/Los_Angeles')->utc();
        $this->travelTo($startedAt->copy()->addHours(2));
        Sanctum::actingAs($athlete->user);

        $this->postJson('/api/v1/activities', [
            'sport' => 'run', 'started_at' => $startedAt->toIso8601String(),
            'duration_s' => $run->target_duration_s, 'tss' => $run->target_tss,
        ])->assertCreated();

        $this->assertNotNull($run->refresh()->activity_id);
    }

    public function test_the_timezone_is_validated(): void
    {
        $athlete = Athlete::factory()->create();
        Sanctum::actingAs($athlete->user);

        $this->putJson('/api/v1/athlete', ['timezone' => 'Mars/Olympus'])->assertUnprocessable()->assertJsonValidationErrors('timezone');
        $this->putJson('/api/v1/athlete', ['timezone' => 'Europe/Riga'])->assertOk()->assertJsonPath('data.timezone', 'Europe/Riga');
    }

    public function test_activity_filters_use_the_athletes_local_days(): void
    {
        $this->travelTo('2026-10-08 06:00:00');
        $athlete = Athlete::factory()->create(['timezone' => 'Pacific/Auckland']);
        Sanctum::actingAs($athlete->user);
        // 07:00 on 8 October in Auckland, still 7 October in UTC.
        $athlete->activities()->create(['source' => 'manual', 'sport' => 'run', 'started_at' => '2026-10-07 18:00:00', 'duration_s' => 3600]);

        $this->getJson('/api/v1/activities?from=2026-10-08')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/activities?to=2026-10-07')->assertJsonCount(0, 'data');
    }
}
