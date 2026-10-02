<?php

declare(strict_types=1);

namespace Tests\Unit\Engine\Adaptation;

use App\Engine\Adaptation\AdaptationContext;
use App\Engine\Adaptation\Adapter;
use App\Engine\Adaptation\ChangeType;
use App\Engine\Adaptation\DayRecord;
use App\Engine\Adaptation\PlanChange;
use App\Engine\Adaptation\PlannedSession;
use App\Engine\Adaptation\Rules\ExtendedMissRule;
use App\Engine\Adaptation\Rules\FatigueRule;
use App\Engine\Adaptation\Rules\MissedKeySessionRule;
use App\Engine\Adaptation\Rules\OverComplianceRule;
use App\Engine\Adaptation\WeekLoadRecord;
use App\Engine\Planning\CoachPreferences;
use App\Enums\Sport;
use App\Enums\WorkoutKind;
use App\Enums\WorkoutStatus;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Scenarios run against a fixed week: Monday 2 November 2026 is a rest day,
 * the long ride is on Saturday, pool days are Tuesday, Wednesday and Friday.
 */
class AdapterTest extends TestCase
{
    private const string MONDAY = '2026-11-02';

    private static function day(int $weekday): DateTimeImmutable
    {
        return (new DateTimeImmutable(self::MONDAY))->modify('+'.($weekday - 1).' days');
    }

    private static function session(int $id, int $weekday, Sport $sport, WorkoutKind $kind, bool $key = false, WorkoutStatus $status = WorkoutStatus::Planned, float $tss = 60): PlannedSession
    {
        return new PlannedSession($id, self::day($weekday), $sport, $kind, $key, 3600, $tss, $status);
    }

    /**
     * A standard week with the Tuesday bike intervals missed.
     *
     * @return list<PlannedSession>
     */
    private static function week(WorkoutStatus $tuesdayBike = WorkoutStatus::Missed): array
    {
        return [
            self::session(1, 2, Sport::Bike, WorkoutKind::Threshold, true, $tuesdayBike),
            self::session(2, 2, Sport::Swim, WorkoutKind::Technique, false, WorkoutStatus::Completed),
            self::session(3, 3, Sport::Run, WorkoutKind::Endurance, false, WorkoutStatus::Completed),
            self::session(4, 4, Sport::Run, WorkoutKind::Long, true),
            self::session(5, 5, Sport::Swim, WorkoutKind::Long),
            self::session(6, 5, Sport::Run, WorkoutKind::Recovery, tss: 25),
            self::session(7, 6, Sport::Bike, WorkoutKind::Long, true),
            self::session(8, 7, Sport::Run, WorkoutKind::Recovery, tss: 30),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private static function context(array $overrides = []): AdaptationContext
    {
        return new AdaptationContext(...[
            'today' => self::day(3),
            'raceDate' => new DateTimeImmutable('2027-03-14'),
            'preferences' => new CoachPreferences,
            'currentCtl' => 55.0,
            'thisWeek' => self::week(),
            'nextWeek' => [],
            'recentDays' => [
                new DayRecord(self::day(1), 0, 0, 0),
                new DayRecord(self::day(2), 2, 1, 1),
            ],
            'recentTsb' => [-10.0, -12.0, -15.0],
            'completedWeeks' => [],
            ...$overrides,
        ]);
    }

    /**
     * @return list<PlanChange>
     */
    private static function adapt(AdaptationContext $context): array
    {
        return (new Adapter)->adapt($context);
    }

    public function test_a_quiet_week_needs_no_changes(): void
    {
        $this->assertSame([], self::adapt(self::context(['thisWeek' => self::week(WorkoutStatus::Completed)])));
    }

    public function test_a_missed_key_session_moves_to_a_free_day_without_back_to_back_keys(): void
    {
        // Wednesday sits between the missed Tuesday and an easy Thursday, so it is free for a key ride.
        $week = [
            self::session(1, 2, Sport::Bike, WorkoutKind::Threshold, true, WorkoutStatus::Missed),
            self::session(3, 3, Sport::Run, WorkoutKind::Endurance, false, WorkoutStatus::Completed),
            self::session(4, 4, Sport::Swim, WorkoutKind::Long),
            self::session(5, 5, Sport::Run, WorkoutKind::Endurance),
            self::session(7, 6, Sport::Bike, WorkoutKind::Long, true),
            self::session(8, 7, Sport::Run, WorkoutKind::Recovery, tss: 30),
        ];

        $changes = self::adapt(self::context(['thisWeek' => $week]));

        $this->assertCount(1, $changes);
        $this->assertSame(ChangeType::Reschedule, $changes[0]->type);
        $this->assertSame(1, $changes[0]->sessionId);
        $this->assertSame('2026-11-04', $changes[0]->date->format('Y-m-d'));
        $this->assertSame(MissedKeySessionRule::NAME, $changes[0]->rule);
    }

    public function test_a_packed_week_never_gets_back_to_back_key_days(): void
    {
        // Every open day is next to Thursday's long run or Saturday's long ride.
        $this->assertSame([], self::adapt(self::context()));
    }

    public function test_without_a_free_slot_the_lowest_priority_session_makes_room(): void
    {
        $week = [
            self::session(1, 2, Sport::Bike, WorkoutKind::Threshold, true, WorkoutStatus::Missed),
            self::session(3, 3, Sport::Run, WorkoutKind::Endurance, false, WorkoutStatus::Completed),
            self::session(9, 3, Sport::Swim, WorkoutKind::Technique, false, WorkoutStatus::Completed),
            self::session(4, 4, Sport::Swim, WorkoutKind::Long),
            self::session(5, 4, Sport::Bike, WorkoutKind::Endurance),
            self::session(7, 6, Sport::Bike, WorkoutKind::Long, true),
            self::session(8, 7, Sport::Run, WorkoutKind::Recovery, tss: 30),
        ];

        $changes = self::adapt(self::context(['thisWeek' => $week, 'unavailableDates' => ['2026-11-06']]));

        $this->assertSame([ChangeType::Drop, ChangeType::Reschedule], array_map(fn ($c) => $c->type, $changes));
        $this->assertSame(5, $changes[0]->sessionId);
        $this->assertSame('2026-11-05', $changes[1]->date->format('Y-m-d'));
    }

    public function test_a_missed_key_session_is_only_rescheduled_once(): void
    {
        $this->assertSame([], self::adapt(self::context(['appliedKeys' => ['reschedule:1']])));

        $copy = new PlannedSession(99, self::day(7), Sport::Bike, WorkoutKind::Threshold, true, 3600, 60, WorkoutStatus::Moved, rescheduledFromId: 1);
        $this->assertSame([], self::adapt(self::context(['thisWeek' => [...self::week(), $copy]])));
    }

    public function test_missed_easy_sessions_are_not_made_up(): void
    {
        $week = self::week(WorkoutStatus::Completed);
        $week[2] = self::session(3, 2, Sport::Run, WorkoutKind::Endurance, false, WorkoutStatus::Missed);

        $this->assertSame([], self::adapt(self::context(['thisWeek' => $week])));
    }

    public function test_three_days_off_ease_the_rest_of_the_week_instead_of_making_up_sessions(): void
    {
        $context = self::context([
            'today' => self::day(5),
            'recentDays' => [
                new DayRecord(self::day(1), 0, 0, 0),
                new DayRecord(self::day(2), 2, 2, 0),
                new DayRecord(self::day(3), 1, 1, 0),
                new DayRecord(self::day(4), 1, 1, 0),
            ],
        ]);

        $changes = self::adapt($context);

        $this->assertNotEmpty($changes);

        foreach ($changes as $change) {
            $this->assertSame(ChangeType::Scale, $change->type);
            $this->assertSame(ExtendedMissRule::REDUCED_LOAD, $change->factor);
        }

        $this->assertEqualsCanonicalizing([5, 6, 7, 8], array_map(fn ($c) => $c->sessionId, $changes));
    }

    public function test_a_week_off_regenerates_the_plan_and_nothing_else(): void
    {
        $days = array_map(fn (int $i) => new DayRecord((new DateTimeImmutable(self::MONDAY))->modify("-{$i} days"), 1, 1, 0), range(9, 1));

        $changes = self::adapt(self::context(['recentDays' => $days, 'recentTsb' => [-40.0, -40.0, -40.0]]));

        $this->assertCount(1, $changes);
        $this->assertSame(ChangeType::Regenerate, $changes[0]->type);
    }

    public function test_deep_fatigue_turns_the_next_key_session_into_recovery(): void
    {
        $changes = self::adapt(self::context([
            'thisWeek' => self::week(WorkoutStatus::Completed),
            'recentTsb' => [-31.0, -33.0, -35.0],
        ]));

        $this->assertCount(1, $changes);
        $this->assertSame(ChangeType::Recover, $changes[0]->type);
        $this->assertSame(4, $changes[0]->sessionId);
        $this->assertSame(FatigueRule::NAME, $changes[0]->rule);
    }

    public function test_fatigue_needs_three_days_below_the_limit(): void
    {
        $this->assertSame([], self::adapt(self::context([
            'thisWeek' => self::week(WorkoutStatus::Completed),
            'recentTsb' => [-25.0, -33.0, -35.0],
        ])));
    }

    public function test_two_weeks_over_plan_raise_next_week_by_five_percent(): void
    {
        $next = array_map(fn (PlannedSession $s) => new PlannedSession($s->id + 100, $s->date->modify('+7 days'), $s->sport, $s->kind, $s->isKey, 3600, 50, WorkoutStatus::Planned), self::week(WorkoutStatus::Completed));

        $changes = self::adapt(self::context([
            'thisWeek' => self::week(WorkoutStatus::Completed),
            'nextWeek' => $next,
            'completedWeeks' => [
                new WeekLoadRecord(new DateTimeImmutable('2026-10-19'), 400, 460),
                new WeekLoadRecord(new DateTimeImmutable('2026-10-26'), 400, 450),
            ],
        ]));

        $this->assertCount(8, $changes);
        $this->assertSame(OverComplianceRule::RAISE, $changes[0]->factor);
        $this->assertSame('raise:2026-11-09', $changes[0]->key);
    }

    public function test_the_raise_is_held_back_by_the_ramp_cap(): void
    {
        // CTL 55 with a 5/week ramp allows about 612 TSS next week; 600 x 1.05 would exceed it.
        $next = [new PlannedSession(200, self::day(2)->modify('+7 days'), Sport::Bike, WorkoutKind::Long, true, 3600, 600, WorkoutStatus::Planned)];

        $changes = self::adapt(self::context([
            'thisWeek' => self::week(WorkoutStatus::Completed),
            'nextWeek' => $next,
            'completedWeeks' => [
                new WeekLoadRecord(new DateTimeImmutable('2026-10-19'), 400, 460),
                new WeekLoadRecord(new DateTimeImmutable('2026-10-26'), 400, 460),
            ],
        ]));

        $this->assertLessThan(OverComplianceRule::RAISE, $changes[0]->factor);
        $this->assertStringContainsString('ramp limit', $changes[0]->reason);
    }

    public function test_one_good_week_is_not_enough(): void
    {
        $this->assertSame([], self::adapt(self::context([
            'thisWeek' => self::week(WorkoutStatus::Completed),
            'nextWeek' => [new PlannedSession(200, self::day(2)->modify('+7 days'), Sport::Bike, WorkoutKind::Long, true, 3600, 60, WorkoutStatus::Planned)],
            'completedWeeks' => [
                new WeekLoadRecord(new DateTimeImmutable('2026-10-19'), 400, 380),
                new WeekLoadRecord(new DateTimeImmutable('2026-10-26'), 400, 460),
            ],
        ])));
    }
}
