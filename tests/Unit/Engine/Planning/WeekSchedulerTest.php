<?php

declare(strict_types=1);

namespace Tests\Unit\Engine\Planning;

use App\Engine\Planning\CoachPreferences;
use App\Engine\Planning\Schedule\Day;
use App\Engine\Planning\Schedule\Placement;
use App\Engine\Planning\Schedule\SessionSlot;
use App\Engine\Planning\Schedule\SlotRole;
use App\Engine\Planning\Schedule\WeekSchedule;
use App\Engine\Planning\Schedule\WeekScheduler;
use App\Enums\Sport;
use App\Enums\WorkoutKind;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class WeekSchedulerTest extends TestCase
{
    /**
     * A typical build week: long ride and run, two quality sessions, three
     * swims, and easy sessions.
     *
     * @return list<SessionSlot>
     */
    private static function typicalWeek(): array
    {
        return [
            new SessionSlot(SlotRole::LongRide, Sport::Bike, WorkoutKind::Long, true, 10800),
            new SessionSlot(SlotRole::LongRun, Sport::Run, WorkoutKind::Long, true, 5400),
            new SessionSlot(SlotRole::Quality, Sport::Bike, WorkoutKind::Threshold, true, 4500),
            new SessionSlot(SlotRole::Quality, Sport::Run, WorkoutKind::Threshold, true, 3000),
            new SessionSlot(SlotRole::Swim, Sport::Swim, WorkoutKind::Threshold, true, 2700),
            new SessionSlot(SlotRole::Swim, Sport::Swim, WorkoutKind::Long, false, 3600),
            new SessionSlot(SlotRole::Swim, Sport::Swim, WorkoutKind::Technique, false, 2400),
            new SessionSlot(SlotRole::Easy, Sport::Bike, WorkoutKind::Endurance, false, 3600),
            new SessionSlot(SlotRole::Easy, Sport::Run, WorkoutKind::Endurance, false, 2700),
        ];
    }

    /**
     * @param  array<int, int>  $limits  weekday => minutes
     * @return list<Day>
     */
    private static function days(CoachPreferences $prefs, array $unavailable = [], array $limits = []): array
    {
        $monday = new DateTimeImmutable('2026-11-02');

        return array_map(fn (int $weekday) => new Day(
            weekday: $weekday,
            date: $monday->modify('+'.($weekday - 1).' days'),
            available: ! $prefs->isRestDay($weekday) && ! in_array($weekday, $unavailable, true),
            isPoolDay: $prefs->isPoolDay($weekday),
            capacitySeconds: isset($limits[$weekday]) ? $limits[$weekday] * 60 : null,
        ), range(1, 7));
    }

    private static function schedule(CoachPreferences $prefs, array $unavailable = [], array $limits = [], ?array $slots = null): WeekSchedule
    {
        return (new WeekScheduler)->schedule(self::days($prefs, $unavailable, $limits), $slots ?? self::typicalWeek(), $prefs);
    }

    /**
     * @return array<int, list<SessionSlot>>
     */
    private static function byWeekday(WeekSchedule $schedule): array
    {
        $days = array_fill(1, 7, []);

        foreach ($schedule->placements as $p) {
            $days[(int) $p->date->format('N')][] = $p->slot;
        }

        return $days;
    }

    private static function weekdayOf(WeekSchedule $schedule, SlotRole $role): ?int
    {
        foreach ($schedule->placements as $p) {
            if ($p->slot->role === $role) {
                return (int) $p->date->format('N');
            }
        }

        return null;
    }

    public function test_the_long_ride_goes_on_its_preferred_day(): void
    {
        $schedule = self::schedule(new CoachPreferences(longRideDay: 7));

        $this->assertSame(7, self::weekdayOf($schedule, SlotRole::LongRide));
    }

    public function test_the_long_run_is_one_or_two_clear_days_from_the_long_ride(): void
    {
        $schedule = self::schedule(new CoachPreferences);
        $gap = abs(self::weekdayOf($schedule, SlotRole::LongRide) - self::weekdayOf($schedule, SlotRole::LongRun));

        $this->assertContains($gap, [2, 3]);
    }

    public function test_an_explicit_long_run_day_is_honoured(): void
    {
        $schedule = self::schedule(new CoachPreferences(longRideDay: 6, longRunDay: 7));

        $this->assertSame(7, self::weekdayOf($schedule, SlotRole::LongRun));
    }

    public function test_key_days_are_never_back_to_back(): void
    {
        $days = self::byWeekday(self::schedule(new CoachPreferences));
        $isKeyDay = fn (array $slots) => array_filter($slots, fn (SessionSlot $s) => $s->loadsLegs()) !== [];

        for ($d = 1; $d < 7; $d++) {
            $this->assertFalse($isKeyDay($days[$d]) && $isKeyDay($days[$d + 1]), "Key sessions on weekdays {$d} and ".($d + 1));
        }
    }

    public function test_the_day_after_the_long_ride_stays_easy(): void
    {
        $slots = [...self::typicalWeek(), new SessionSlot(SlotRole::Easy, Sport::Run, WorkoutKind::Recovery, false, 1800)];
        $sunday = self::byWeekday(self::schedule(new CoachPreferences(longRideDay: 6), slots: $slots))[7];

        $this->assertNotEmpty($sunday, 'The recovery session should go on the day after the long ride.');

        foreach ($sunday as $slot) {
            $this->assertFalse($slot->loadsLegs());
            $this->assertTrue($slot->sport === Sport::Swim || in_array($slot->kind, [WorkoutKind::Recovery, WorkoutKind::Technique], true));
        }
    }

    public function test_swims_only_land_on_pool_days(): void
    {
        $schedule = self::schedule(new CoachPreferences(poolDays: [2, 4, 5]));

        foreach ($schedule->placements as $p) {
            if ($p->slot->sport === Sport::Swim) {
                $this->assertContains((int) $p->date->format('N'), [2, 4, 5]);
            }
        }
    }

    public function test_rest_days_and_unavailable_days_stay_empty(): void
    {
        $days = self::byWeekday(self::schedule(new CoachPreferences(restDays: [1]), unavailable: [3]));

        $this->assertSame([], $days[1]);
        $this->assertSame([], $days[3]);
    }

    public function test_no_day_has_two_sessions_of_one_sport_or_more_than_two_sessions(): void
    {
        foreach (self::byWeekday(self::schedule(new CoachPreferences)) as $weekday => $slots) {
            $this->assertLessThanOrEqual(Day::MAX_SESSIONS, count($slots));
            $sports = array_map(fn (SessionSlot $s) => $s->sport->value, $slots);
            $this->assertSame(array_unique($sports), $sports, "weekday {$weekday}");
        }
    }

    public function test_a_day_limit_bounds_the_time_available_to_its_sessions(): void
    {
        $schedule = self::schedule(new CoachPreferences, limits: [2 => 60, 3 => 60, 4 => 60, 5 => 60]);

        foreach ($schedule->placements as $placement) {
            $weekday = (int) $placement->date->format('N');

            if (in_array($weekday, [2, 3, 4, 5], true)) {
                $this->assertNotNull($placement->maxSeconds);
                $this->assertLessThanOrEqual(3600, $placement->maxSeconds);
            }
        }
    }

    public function test_key_sessions_that_cannot_be_spaced_are_downgraded_not_dropped(): void
    {
        // Only three training days, all adjacent.
        $schedule = self::schedule(new CoachPreferences(longRideDay: 4, poolDays: [3, 4, 5], restDays: [1, 2, 6, 7]));
        $placedSports = array_map(fn (Placement $p) => $p->slot->sport, $schedule->placements);

        $this->assertContains(Sport::Run, $placedSports);
        $this->assertSame(1, count(array_filter($schedule->placements, fn (Placement $p) => $p->slot->loadsLegs() && $p->slot->sport === Sport::Bike)));
    }
}
