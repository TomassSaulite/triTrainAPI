<?php

declare(strict_types=1);

namespace App\Engine\Review;

use App\Enums\PhaseType;

/**
 * Turns a finished week into the coach's weekly summary. Pure: everything it
 * needs arrives in WeekFacts and NextWeekFacts.
 */
class WeeklyReviewer
{
    /** Below this share of the planned load the week counts as under-done. */
    public const float UNDER = 0.85;

    /** Above this share the week counts as over-done. */
    public const float OVER = 1.15;

    /** Form below this is a lot of fatigue to carry. */
    public const float HEAVY_FATIGUE_TSB = -30.0;

    /** Form above this is fresh, which outside a taper means fitness is not being built. */
    public const float FRESH_TSB = 15.0;

    /** A sport is called out when less than this share of its planned time was done. */
    public const float SPORT_SHORTFALL = 0.5;

    /** ...and at least this much of it was planned. */
    public const int SPORT_SHORTFALL_MIN_S = 3600;

    /** An average legs or energy rating at or above this (of 5) is worth a word. */
    public const float HEAVY_FEEL = 3.5;

    /** At or below this, sessions felt good. */
    public const float GOOD_FEEL = 2.5;

    /** Praise for feeling good needs at least this many rated sessions. */
    public const int GOOD_FEEL_MIN_RATED = 3;

    /** This many easy sessions that felt hard is a warning sign. */
    public const int EASY_FELT_HARD = 2;

    /** Next week's load is "similar" within this share of this week's. */
    public const float SIMILAR_LOAD = 0.05;

    private const array PHASE_FOCUS = [
        'base' => 'building aerobic fitness with mostly easy volume',
        'build' => 'adding threshold work on top of your base',
        'peak' => 'race-specific sessions at the highest load of the plan',
        'taper' => 'cutting volume so you arrive fresh',
    ];

    public function review(WeekFacts $week, ?NextWeekFacts $next = null): WeeklyReview
    {
        $verdict = $this->verdict($week);

        return new WeeklyReview(
            $verdict,
            $this->headline($verdict, $week),
            $this->notes($verdict, $week),
            $next === null ? null : $this->nextWeek($week, $next),
        );
    }

    private function verdict(WeekFacts $week): Verdict
    {
        $compliance = $week->compliance();

        return match (true) {
            $compliance === null => Verdict::Rest,
            $compliance > self::OVER => Verdict::Over,
            $compliance < self::UNDER => Verdict::Under,
            $week->missedKeySessions !== [] => Verdict::KeysMissed,
            default => Verdict::OnTrack,
        };
    }

    private function headline(Verdict $verdict, WeekFacts $week): string
    {
        $done = self::percent($week->compliance() ?? 0.0);
        $missed = count($week->missedKeySessions);

        return match ($verdict) {
            Verdict::Rest => 'No training was planned this week.',
            Verdict::OnTrack => $week->keySessions > 0
                ? "You did {$done} of the planned load and every key session. Right on track."
                : "You did {$done} of the planned load. Right on track.",
            Verdict::KeysMissed => sprintf(
                'You did %s of the planned load but missed %s.',
                $done,
                $missed === 1 ? 'a key session' : "{$missed} key sessions",
            ),
            Verdict::Under => "You did {$done} of the planned load.",
            Verdict::Over => "You did {$done} of the planned load, more than the plan asked.",
        };
    }

    /**
     * @return list<string>
     */
    private function notes(Verdict $verdict, WeekFacts $week): array
    {
        $notes = [];

        if ($week->missedKeySessions !== []) {
            $notes[] = sprintf(
                'Missed: %s. Key sessions carry most of the training effect, so the coach protects the next ones.',
                implode(', ', $week->missedKeySessions),
            );
        }

        if ($verdict === Verdict::Under) {
            $notes[] = 'One lighter week costs little fitness. If life got busy, lower your weekly hours in Settings and the plan will fit around it.';
        }

        if ($verdict === Verdict::Over) {
            $notes[] = $week->isRecovery
                ? 'This was a recovery week; doing more makes the recovery less effective. Let the next easy days stay easy.'
                : 'Extra load builds fatigue faster than fitness. Keep the easy sessions easy so the key ones stay good.';
        }

        $shortfall = $this->sportShortfall($week);
        if ($shortfall !== null) {
            $notes[] = $shortfall;
        }

        array_push($notes, ...$this->feelNotes($week->feel));

        $fitness = $this->fitnessNote($week);
        if ($fitness !== null) {
            $notes[] = $fitness;
        }

        if ($week->tsbAfter !== null && $week->tsbAfter < self::HEAVY_FATIGUE_TSB) {
            $notes[] = sprintf('You are carrying a lot of fatigue (form %d). Sleep, eat well and keep the easy days easy.', round($week->tsbAfter));
        } elseif ($week->tsbAfter !== null && $week->tsbAfter > self::FRESH_TSB && $week->phase !== PhaseType::Taper) {
            $notes[] = sprintf('You are fresh (form +%d), a good moment for the next block of work.', round($week->tsbAfter));
        }

        return $notes;
    }

    /**
     * The sport that fell furthest behind, when it fell far enough to matter.
     */
    private function sportShortfall(WeekFacts $week): ?string
    {
        $worst = null;

        foreach ($week->sportTime as $sport => $time) {
            if ($time['planned_s'] < self::SPORT_SHORTFALL_MIN_S) {
                continue;
            }

            $share = $time['actual_s'] / $time['planned_s'];
            if ($share < self::SPORT_SHORTFALL && ($worst === null || $share < $worst[1])) {
                $worst = [$sport, $share, $time];
            }
        }

        if ($worst === null) {
            return null;
        }

        [$sport, , $time] = $worst;

        return sprintf(
            '%s fell behind: %s of the planned %s.',
            ucfirst($sport),
            self::duration($time['actual_s']),
            self::duration($time['planned_s']),
        );
    }

    /**
     * What the athlete's own ratings say about the week.
     *
     * @return list<string>
     */
    private function feelNotes(?FeelSummary $feel): array
    {
        if ($feel === null || $feel->sessions === 0) {
            return [];
        }

        if ($feel->rated === 0) {
            return ['Rate how your sessions felt: it tells the coach how the training is landing, often before the numbers do.'];
        }

        $notes = [];
        $areas = array_values(array_unique(array_filter($feel->painAreas)));

        if ($feel->painAreas !== []) {
            $notes[] = sprintf(
                'You reported pain%s. After a pain report the coach eases the next hard session of that sport; if it lasts, have it checked before training hard.',
                $areas === [] ? '' : ' ('.implode(', ', $areas).')',
            );
        }

        $heavy = array_filter([
            'legs' => $feel->muscles,
            'energy' => $feel->energy,
        ], fn (?float $v) => $v !== null && $v >= self::HEAVY_FEEL);

        if ($heavy !== []) {
            $notes[] = sprintf(
                'Your %s felt heavy this week (%s of 5). Sleep, food and easy days matter as much as the hard sessions now.',
                implode(' and ', array_keys($heavy)),
                implode(' and ', array_map(fn (float $v) => number_format($v, 1), $heavy)),
            );
        } elseif ($feel->rated >= self::GOOD_FEEL_MIN_RATED
            && ($feel->muscles ?? 0) <= self::GOOD_FEEL && ($feel->energy ?? 0) <= self::GOOD_FEEL && $feel->painAreas === []) {
            $notes[] = 'Your sessions felt good: legs and energy stayed fresh. The training is landing well.';
        }

        if ($feel->easyFeltHard >= self::EASY_FELT_HARD) {
            $notes[] = sprintf(
                '%d easy sessions felt hard (effort 6 or more). Easy days should feel easy: slow down, and look at sleep, stress and fuelling.',
                $feel->easyFeltHard,
            );
        }

        return $notes;
    }

    private function fitnessNote(WeekFacts $week): ?string
    {
        if ($week->ctlBefore === null || $week->ctlAfter === null) {
            return null;
        }

        $before = (int) round($week->ctlBefore);
        $after = (int) round($week->ctlAfter);

        return match (true) {
            $after > $before => "Fitness rose from {$before} to {$after}.",
            $after < $before && ($week->isRecovery || $week->phase === PhaseType::Taper) => "Fitness eased from {$before} to {$after}, as planned while you absorb the work.",
            $after < $before => "Fitness slipped from {$before} to {$after}.",
            default => "Fitness held at {$after}.",
        };
    }

    private function nextWeek(WeekFacts $week, NextWeekFacts $next): string
    {
        $load = sprintf('%s and %d TSS', self::duration($next->plannedSeconds), round($next->plannedTss));

        if ($next->race !== null) {
            return "Next week is race week: {$next->race}. The load comes down ({$load}) so you start fresh.";
        }

        if ($next->phase !== $week->phase) {
            return sprintf(
                'Next week starts the %s phase: %s (%s).',
                $next->phase->value,
                self::PHASE_FOCUS[$next->phase->value],
                $load,
            );
        }

        if ($next->isRecovery) {
            return "Next week is a recovery week ({$load}) so your body can absorb the last block.";
        }

        if ($week->isRecovery) {
            return "Recovery is done. Next week training picks up again ({$load}).";
        }

        if ($week->plannedTss <= 0) {
            return "Next week: {$load}.";
        }

        $change = $next->plannedTss / $week->plannedTss - 1;

        return match (true) {
            $change > self::SIMILAR_LOAD => sprintf('Next week the load goes up about %s (%s).', self::percent($change), $load),
            $change < -self::SIMILAR_LOAD => sprintf('Next week the load eases about %s (%s).', self::percent(-$change), $load),
            default => "Next week holds a similar load ({$load}).",
        };
    }

    private static function percent(float $share): string
    {
        return round($share * 100).'%';
    }

    private static function duration(int $seconds): string
    {
        $minutes = (int) round($seconds / 60);

        return $minutes < 60 ? "{$minutes} min" : sprintf('%d h %02d', intdiv($minutes, 60), $minutes % 60);
    }
}
