<?php

declare(strict_types=1);

namespace Tests\Unit\Engine\Adaptation;

use App\Engine\Adaptation\AdaptationContext;
use App\Engine\Adaptation\ChangeType;
use App\Engine\Adaptation\FeelRecord;
use App\Engine\Adaptation\PlannedSession;
use App\Engine\Adaptation\Rules\HowYouFeelRule;
use App\Engine\Planning\CoachPreferences;
use App\Enums\Sport;
use App\Enums\WorkoutKind;
use App\Enums\WorkoutStatus;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Today is Wednesday 4 November 2026. Key sessions ahead: bike intervals on
 * Thursday, a long run on Saturday and a long ride on Sunday.
 */
class HowYouFeelRuleTest extends TestCase
{
    private const string TODAY = '2026-11-04';

    private static function date(string $offset): DateTimeImmutable
    {
        return (new DateTimeImmutable(self::TODAY))->modify($offset);
    }

    /**
     * @param  list<FeelRecord>  $feel
     * @param  list<string>  $applied
     */
    private static function context(array $feel, array $applied = []): AdaptationContext
    {
        $session = fn (int $id, string $offset, Sport $sport, WorkoutKind $kind) => new PlannedSession($id, self::date($offset), $sport, $kind, true, 3600, 70, WorkoutStatus::Planned);

        return new AdaptationContext(
            today: self::date('+0 days'),
            raceDate: new DateTimeImmutable('2027-03-14'),
            preferences: new CoachPreferences,
            currentCtl: 60,
            thisWeek: [
                $session(1, '+1 day', Sport::Bike, WorkoutKind::Threshold),
                $session(2, '+3 days', Sport::Run, WorkoutKind::Long),
                $session(3, '+4 days', Sport::Bike, WorkoutKind::Long),
            ],
            nextWeek: [],
            recentDays: [],
            recentTsb: [0, 0, 0],
            completedWeeks: [],
            appliedKeys: $applied,
            recentFeel: $feel,
        );
    }

    private static function feel(int $id, string $offset, int $muscles = 2, int $energy = 2, bool $pain = false, Sport $sport = Sport::Run): FeelRecord
    {
        return new FeelRecord($id, self::date($offset), $sport, 5, $muscles, 2, $energy, 2, $pain, $pain ? 'left calf' : null);
    }

    public function test_pain_eases_the_next_key_session_of_that_sport(): void
    {
        $changes = (new HowYouFeelRule)->evaluate(self::context([self::feel(9, '-1 day', pain: true)]), []);

        $this->assertCount(1, $changes);
        $this->assertSame(ChangeType::Recover, $changes[0]->type);
        $this->assertSame(2, $changes[0]->sessionId, 'The long run, not the earlier bike session.');
        $this->assertSame('pain:9', $changes[0]->key);
        $this->assertStringStartsWith('You reported pain (left calf) after a run on Tuesday: long run on Saturday swapped for recovery.', $changes[0]->reason);
    }

    public function test_old_or_already_handled_pain_is_left_alone(): void
    {
        $rule = new HowYouFeelRule;

        $this->assertSame([], $rule->evaluate(self::context([self::feel(9, '-5 days', pain: true)]), []));
        $this->assertSame([], $rule->evaluate(self::context([self::feel(9, '-1 day', pain: true)], ['pain:9']), []));
    }

    public function test_three_worn_out_sessions_in_a_row_ease_the_next_key_session(): void
    {
        $feel = [self::feel(1, '-4 days', muscles: 4), self::feel(2, '-2 days', energy: 5), self::feel(3, '-1 day', muscles: 4, energy: 4)];

        $changes = (new HowYouFeelRule)->evaluate(self::context($feel), []);

        $this->assertCount(1, $changes);
        $this->assertSame(1, $changes[0]->sessionId);
        $this->assertSame('feel:2026-11-03', $changes[0]->key);
        $this->assertStringStartsWith('Your last 3 sessions left your legs heavy or your energy low', $changes[0]->reason);
    }

    public function test_one_good_session_breaks_the_streak(): void
    {
        $feel = [self::feel(1, '-4 days', muscles: 4), self::feel(2, '-2 days', muscles: 2), self::feel(3, '-1 day', muscles: 5)];

        $this->assertSame([], (new HowYouFeelRule)->evaluate(self::context($feel), []));
    }

    public function test_pain_and_a_worn_out_streak_ease_two_different_sessions(): void
    {
        $feel = [self::feel(1, '-3 days', muscles: 4), self::feel(2, '-2 days', muscles: 4), self::feel(3, '-1 day', muscles: 4, pain: true)];

        $changes = (new HowYouFeelRule)->evaluate(self::context($feel), []);

        $this->assertSame([2, 1], array_map(fn ($c) => $c->sessionId, $changes));
    }
}
