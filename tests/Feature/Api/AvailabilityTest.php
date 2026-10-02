<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Athlete;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Athlete $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete = Athlete::factory()->create();
        Sanctum::actingAs($this->athlete->user);
    }

    public function test_an_override_is_created_then_replaced_for_the_same_date(): void
    {
        $date = today()->addDays(3)->toDateString();

        $this->putJson("/api/v1/availability/{$date}", ['available_minutes' => 0, 'note' => 'Flight'])->assertCreated();
        $this->putJson("/api/v1/availability/{$date}", ['available_minutes' => 30])
            ->assertOk()
            ->assertJsonPath('data.available_minutes', 30);

        $this->assertSame(1, $this->athlete->availabilityOverrides()->count());
    }

    public function test_overrides_are_listed_from_today(): void
    {
        $this->athlete->availabilityOverrides()->create(['date' => today()->subDay(), 'available_minutes' => 0]);
        $this->athlete->availabilityOverrides()->create(['date' => today()->addDay(), 'available_minutes' => 45]);

        $this->getJson('/api/v1/availability')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_an_override_can_be_removed(): void
    {
        $date = today()->addDays(3);
        $this->athlete->availabilityOverrides()->create(['date' => $date, 'available_minutes' => 0]);

        $this->deleteJson("/api/v1/availability/{$date->toDateString()}")->assertNoContent();

        $this->assertSame(0, $this->athlete->availabilityOverrides()->count());
    }

    public function test_malformed_dates_are_not_found(): void
    {
        $this->putJson('/api/v1/availability/2026-02-30', ['available_minutes' => 0])->assertNotFound();
    }

    public function test_a_sick_break_marks_every_day_and_eases_back_in_with_one_replan(): void
    {
        Bus::fake();
        $this->travelTo('2026-10-07 08:00:00');
        $this->athlete->availabilityOverrides()->create(['date' => '2026-10-11', 'available_minutes' => 90, 'note' => 'Club ride']);

        $this->postJson('/api/v1/availability/break', ['from' => '2026-10-07', 'to' => '2026-10-09', 'reason' => 'sick', 'note' => 'Flu'])
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.easy_only', false)
            ->assertJsonPath('data.3.easy_only', true);

        $days = $this->athlete->availabilityOverrides()->orderBy('date')->get()
            ->map(fn ($o) => [$o->date->toDateString(), $o->available_minutes, $o->note])->all();
        $this->assertSame([
            ['2026-10-07', 0, 'Sick: Flu'],
            ['2026-10-08', 0, 'Sick: Flu'],
            ['2026-10-09', 0, 'Sick: Flu'],
            ['2026-10-10', 45, 'Easing back after being sick'],
            ['2026-10-11', 90, 'Club ride'],
        ], $days, 'The day that already had its own availability keeps it.');
    }

    public function test_easing_back_skips_the_usual_rest_day(): void
    {
        $this->travelTo('2026-10-07 08:00:00');

        // Friday to Sunday; Monday is the default rest day.
        $this->postJson('/api/v1/availability/break', ['from' => '2026-10-09', 'to' => '2026-10-11', 'reason' => 'injured'])->assertOk();

        $easy = $this->athlete->availabilityOverrides()->where('easy_only', true)->orderBy('date')->get()
            ->map(fn ($o) => $o->date->toDateString())->all();
        $this->assertSame(['2026-10-13', '2026-10-14'], $easy);
    }

    public function test_being_away_needs_no_easing_back(): void
    {
        $this->travelTo('2026-10-07 08:00:00');

        $this->postJson('/api/v1/availability/break', ['from' => '2026-10-08', 'to' => '2026-10-10', 'reason' => 'away'])
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.note', 'Away');
    }

    public function test_breaks_must_be_in_the_future_and_not_too_long(): void
    {
        $this->travelTo('2026-10-07 08:00:00');

        $this->postJson('/api/v1/availability/break', ['from' => '2026-10-06', 'to' => '2026-10-08', 'reason' => 'sick'])
            ->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->postJson('/api/v1/availability/break', ['from' => '2026-10-07', 'to' => '2026-11-30', 'reason' => 'injured'])
            ->assertUnprocessable();
        $this->postJson('/api/v1/availability/break', ['from' => '2026-10-07', 'to' => '2026-10-08', 'reason' => 'bored'])
            ->assertJsonValidationErrors('reason');
    }
}
