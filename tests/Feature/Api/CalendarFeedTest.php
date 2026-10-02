<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\WorkoutStatus;
use App\Models\Athlete;
use App\Models\Race;
use App\Models\Threshold;
use App\Services\Planning\PlanService;
use Database\Seeders\WorkoutTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CalendarFeedTest extends TestCase
{
    use RefreshDatabase;

    private Athlete $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 08:00:00');
        $this->athlete = Athlete::factory()->create(['weekly_hours' => 9]);
        Sanctum::actingAs($this->athlete->user);
    }

    public function test_the_feed_is_off_until_turned_on_and_its_link_can_be_rotated_or_revoked(): void
    {
        $this->getJson('/api/v1/calendar-feed')->assertOk()->assertJsonPath('data.url', null);

        $first = $this->postJson('/api/v1/calendar-feed')->assertCreated()->json('data.url');
        $this->assertMatchesRegularExpression('#/api/v1/calendar/feed/[A-Za-z0-9]{40}\.ics$#', $first);
        $this->get($first)->assertOk();

        $second = $this->postJson('/api/v1/calendar-feed')->json('data.url');
        $this->assertNotSame($first, $second);
        $this->get($first)->assertNotFound();

        $this->deleteJson('/api/v1/calendar-feed')->assertNoContent();
        $this->get($second)->assertNotFound();
        $this->getJson('/api/v1/athlete')->assertJsonMissingPath('data.calendar_token');
    }

    public function test_the_feed_lists_planned_sessions_and_races(): void
    {
        $this->seed(WorkoutTemplateSeeder::class);
        Threshold::factory()->for($this->athlete)->ftp(250)->create();
        $race = Race::factory()->for($this->athlete)->create(['date' => '2027-03-14', 'name' => 'Jurmala 70.3', 'priority' => 'A']);
        $plan = app(PlanService::class)->create($this->athlete, $race);
        $done = $plan->workouts()->whereNull('parent_id')->orderBy('date')->firstOrFail();
        $done->update(['status' => WorkoutStatus::Completed]);
        $skipped = $plan->workouts()->whereNull('parent_id')->orderByDesc('date')->firstOrFail();
        $skipped->update(['status' => WorkoutStatus::Dropped]);

        $url = $this->postJson('/api/v1/calendar-feed')->json('data.url');
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $ics = str_replace("\r\n ", '', $response->getContent());

        $sessions = $plan->workouts()->whereNull('parent_id')->where('status', '!=', WorkoutStatus::Dropped)->count();
        $this->assertSame($sessions + 1, substr_count($ics, 'BEGIN:VEVENT'), 'Every session that is not skipped, plus the race.');
        $this->assertStringContainsString("UID:workout-{$done->id}@tritrain", $ics);
        $this->assertStringContainsString('SUMMARY:✓ '.ucfirst($done->sport->value).': '.$done->title, $ics);
        $this->assertStringNotContainsString("UID:workout-{$skipped->id}@tritrain", $ics);
        $this->assertStringContainsString("SUMMARY:A race: Jurmala 70.3\r\n", $ics);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20270314', $ics);
    }

    public function test_unknown_tokens_are_not_found(): void
    {
        $this->get('/api/v1/calendar/feed/'.str_repeat('a', 40).'.ics')->assertNotFound();
    }
}
