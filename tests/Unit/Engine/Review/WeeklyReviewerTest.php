<?php

declare(strict_types=1);

namespace Tests\Unit\Engine\Review;

use App\Engine\Review\FeelSummary;
use App\Engine\Review\NextWeekFacts;
use App\Engine\Review\Verdict;
use App\Engine\Review\WeekFacts;
use App\Engine\Review\WeeklyReviewer;
use App\Enums\PhaseType;
use PHPUnit\Framework\TestCase;

class WeeklyReviewerTest extends TestCase
{
    /**
     * @param  list<string>  $missed
     * @param  array<string, array{planned_s: int, actual_s: int}>  $sportTime
     */
    private static function week(
        float $actualTss,
        array $missed = [],
        PhaseType $phase = PhaseType::Base,
        bool $recovery = false,
        array $sportTime = [],
        ?float $ctlBefore = null,
        ?float $ctlAfter = null,
        ?float $tsbAfter = null,
        float $plannedTss = 400.0,
        ?FeelSummary $feel = null,
    ): WeekFacts {
        return new WeekFacts($phase, $recovery, $plannedTss, $actualTss, 8 * 3600, 7 * 3600, 3, $missed, $sportTime, $ctlBefore, $ctlAfter, $tsbAfter, $feel);
    }

    private static function next(float $tss, PhaseType $phase = PhaseType::Base, bool $recovery = false, ?string $race = null): NextWeekFacts
    {
        return new NextWeekFacts($phase, $recovery, $tss, 9 * 3600, 3, $race);
    }

    public function test_a_complete_week_is_on_track(): void
    {
        $review = (new WeeklyReviewer)->review(self::week(368));

        $this->assertSame(Verdict::OnTrack, $review->verdict);
        $this->assertSame('You did 92% of the planned load and every key session. Right on track.', $review->headline);
        $this->assertNull($review->nextWeek);
    }

    public function test_missed_key_sessions_are_named(): void
    {
        $review = (new WeeklyReviewer)->review(self::week(380, ['Tempo intervals', 'Long run']));

        $this->assertSame(Verdict::KeysMissed, $review->verdict);
        $this->assertStringContainsString('missed 2 key sessions', $review->headline);
        $this->assertStringStartsWith('Missed: Tempo intervals, Long run.', $review->notes[0]);
    }

    public function test_too_little_load_is_under_whatever_the_key_sessions(): void
    {
        $review = (new WeeklyReviewer)->review(self::week(200, ['Long run']));

        $this->assertSame(Verdict::Under, $review->verdict);
        $this->assertSame('You did 50% of the planned load.', $review->headline);
    }

    public function test_overdoing_a_recovery_week_gets_a_warning(): void
    {
        $review = (new WeeklyReviewer)->review(self::week(500, recovery: true));

        $this->assertSame(Verdict::Over, $review->verdict);
        $this->assertStringContainsString('recovery less effective', implode(' ', $review->notes));
    }

    public function test_a_week_with_nothing_planned_is_rest(): void
    {
        $review = (new WeeklyReviewer)->review(self::week(0, plannedTss: 0));

        $this->assertSame(Verdict::Rest, $review->verdict);
    }

    public function test_it_calls_out_the_sport_that_fell_furthest_behind(): void
    {
        $review = (new WeeklyReviewer)->review(self::week(380, sportTime: [
            'swim' => ['planned_s' => 7200, 'actual_s' => 1800],
            'bike' => ['planned_s' => 14400, 'actual_s' => 14400],
            'run' => ['planned_s' => 1800, 'actual_s' => 0],
        ]));

        $this->assertContains('Swim fell behind: 30 min of the planned 2 h 00.', $review->notes);
        $this->assertStringNotContainsString('Run fell behind', implode(' ', $review->notes), 'Under an hour planned is not worth a note.');
    }

    public function test_fitness_and_form_are_explained(): void
    {
        $rising = (new WeeklyReviewer)->review(self::week(380, ctlBefore: 60.4, ctlAfter: 63.2, tsbAfter: -35));
        $this->assertContains('Fitness rose from 60 to 63.', $rising->notes);
        $this->assertStringContainsString('lot of fatigue (form -35)', implode(' ', $rising->notes));

        $recovery = (new WeeklyReviewer)->review(self::week(380, recovery: true, ctlBefore: 63, ctlAfter: 61));
        $this->assertContains('Fitness eased from 63 to 61, as planned while you absorb the work.', $recovery->notes);
    }

    public function test_next_week_describes_the_change_in_load(): void
    {
        $reviewer = new WeeklyReviewer;

        $this->assertSame('Next week the load goes up about 10% (9 h 00 and 440 TSS).', $reviewer->review(self::week(380), self::next(440))->nextWeek);
        $this->assertSame('Next week holds a similar load (9 h 00 and 410 TSS).', $reviewer->review(self::week(380), self::next(410))->nextWeek);
        $this->assertStringStartsWith('Next week is a recovery week', $reviewer->review(self::week(380), self::next(300, recovery: true))->nextWeek ?? '');
        $this->assertStringStartsWith('Recovery is done', $reviewer->review(self::week(300, recovery: true), self::next(440))->nextWeek ?? '');
    }

    public function test_next_week_announces_a_new_phase_or_a_race(): void
    {
        $reviewer = new WeeklyReviewer;

        $this->assertStringStartsWith('Next week starts the build phase: adding threshold work', $reviewer->review(self::week(380), self::next(440, PhaseType::Build))->nextWeek ?? '');
        $this->assertStringStartsWith('Next week is race week: Riga Marathon.', $reviewer->review(self::week(380), self::next(250, race: 'Riga Marathon'))->nextWeek ?? '');
    }

    public function test_unrated_sessions_get_a_nudge_to_rate_them(): void
    {
        $review = (new WeeklyReviewer)->review(self::week(380, feel: new FeelSummary(sessions: 6, rated: 0)));

        $this->assertStringStartsWith('Rate how your sessions felt', $review->notes[0]);
    }

    public function test_heavy_legs_pain_and_hard_easy_days_are_called_out(): void
    {
        $feel = new FeelSummary(sessions: 6, rated: 5, rpe: 6.2, muscles: 3.8, energy: 2.4, painAreas: ['left calf', ''], easyFeltHard: 2);

        $notes = implode(' ', (new WeeklyReviewer)->review(self::week(380, feel: $feel))->notes);

        $this->assertStringContainsString('You reported pain (left calf).', $notes);
        $this->assertStringContainsString('Your legs felt heavy this week (3.8 of 5).', $notes);
        $this->assertStringContainsString('2 easy sessions felt hard', $notes);
    }

    public function test_a_week_that_felt_good_is_said_so(): void
    {
        $feel = new FeelSummary(sessions: 6, rated: 4, rpe: 4.5, muscles: 2.0, energy: 1.8);

        $this->assertContains(
            'Your sessions felt good: legs and energy stayed fresh. The training is landing well.',
            (new WeeklyReviewer)->review(self::week(380, feel: $feel))->notes,
        );
    }
}
