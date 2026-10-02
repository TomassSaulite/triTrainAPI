<?php

declare(strict_types=1);

namespace Tests\Unit\Engine\Adaptation;

use App\Engine\Adaptation\ActivityMatcher;
use App\Engine\Adaptation\CompletedActivity;
use App\Engine\Adaptation\PlannedSession;
use App\Enums\Sport;
use App\Enums\WorkoutKind;
use App\Enums\WorkoutStatus;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ActivityMatcherTest extends TestCase
{
    private static function session(int $id, string $date, Sport $sport, int $minutes, WorkoutStatus $status = WorkoutStatus::Planned, bool $key = false): PlannedSession
    {
        return new PlannedSession($id, new DateTimeImmutable($date), $sport, WorkoutKind::Endurance, $key, $minutes * 60, 50.0, $status);
    }

    private static function runActivity(string $date, int $minutes): CompletedActivity
    {
        return new CompletedActivity(99, Sport::Run, new DateTimeImmutable($date), $minutes * 60, 60.0);
    }

    public function test_it_matches_the_same_sport_on_the_same_day(): void
    {
        $match = (new ActivityMatcher)->match(self::runActivity('2026-11-03 07:00', 45), [
            self::session(1, '2026-11-03', Sport::Bike, 45),
            self::session(2, '2026-11-03', Sport::Run, 50),
        ]);

        $this->assertSame(2, $match?->id);
    }

    public function test_the_closest_duration_wins_within_a_day_either_side(): void
    {
        $match = (new ActivityMatcher)->match(self::runActivity('2026-11-03 18:00', 90), [
            self::session(1, '2026-11-03', Sport::Run, 40),
            self::session(2, '2026-11-04', Sport::Run, 85),
        ]);

        $this->assertSame(2, $match?->id);
    }

    public function test_same_day_breaks_a_duration_tie(): void
    {
        $match = (new ActivityMatcher)->match(self::runActivity('2026-11-03 07:00', 60), [
            self::session(1, '2026-11-02', Sport::Run, 60),
            self::session(2, '2026-11-03', Sport::Run, 60),
        ]);

        $this->assertSame(2, $match?->id);
    }

    public function test_sessions_further_than_a_day_away_never_match(): void
    {
        $this->assertNull((new ActivityMatcher)->match(self::runActivity('2026-11-03 07:00', 60), [
            self::session(1, '2026-11-05', Sport::Run, 60),
            self::session(2, '2026-11-01', Sport::Run, 60),
        ]));
    }

    public function test_completed_or_already_matched_sessions_are_skipped(): void
    {
        $matched = new PlannedSession(3, new DateTimeImmutable('2026-11-03'), Sport::Run, WorkoutKind::Long, true, 3600, 70.0, WorkoutStatus::Planned, activityId: 7);

        $this->assertNull((new ActivityMatcher)->match(self::runActivity('2026-11-03 07:00', 60), [
            self::session(1, '2026-11-03', Sport::Run, 60, WorkoutStatus::Completed),
            self::session(2, '2026-11-03', Sport::Run, 60, WorkoutStatus::Dropped),
            $matched,
        ]));
    }

    public function test_moved_sessions_can_still_be_matched(): void
    {
        $match = (new ActivityMatcher)->match(self::runActivity('2026-11-03 07:00', 60), [
            self::session(1, '2026-11-03', Sport::Run, 60, WorkoutStatus::Moved),
        ]);

        $this->assertSame(1, $match?->id);
    }

    public function test_a_late_sync_can_still_claim_a_session_marked_missed(): void
    {
        $match = (new ActivityMatcher)->match(self::runActivity('2026-11-04 07:00', 60), [
            self::session(1, '2026-11-03', Sport::Run, 60, WorkoutStatus::Missed),
        ]);

        $this->assertSame(1, $match?->id);
    }
}
