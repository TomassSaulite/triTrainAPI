<?php

declare(strict_types=1);

namespace App\Engine\Adaptation\Rules;

use App\Engine\Adaptation\AdaptationContext;
use App\Engine\Adaptation\AdaptationRule;
use App\Engine\Adaptation\ChangeType;
use App\Engine\Adaptation\FeelRecord;
use App\Engine\Adaptation\PlanChange;
use App\Engine\Adaptation\PlannedSession;
use App\Enums\Sport;

/**
 * Listens to how sessions felt, which often shows fatigue before the numbers do.
 *
 * - Pain reported in the last few days: the next key session of that sport
 *   becomes a recovery session.
 * - The last few rated sessions all left the legs heavy or the tank empty: the
 *   next key session becomes a recovery session.
 */
final class HowYouFeelRule implements AdaptationRule
{
    public const string NAME = 'how_you_feel';

    /** Pain reported this many days back or less is acted on. */
    public const int PAIN_DAYS = 3;

    /** Worn out means legs or energy rated at least this (4 = heavy / low). */
    public const int WORN_OUT = 4;

    /** ...in each of this many most recent rated sessions... */
    public const int WORN_OUT_SESSIONS = 3;

    /** ...all within this many days. */
    public const int WORN_OUT_DAYS = 7;

    /** Only key sessions this soon are changed. */
    public const int LOOKAHEAD_DAYS = 7;

    public function evaluate(AdaptationContext $context, array $earlier): array
    {
        $changes = [];
        $touched = array_map(fn (PlanChange $c) => $c->sessionId, $earlier);

        foreach ($this->recentPain($context) as $feel) {
            $key = "pain:{$feel->id}";
            $sport = $feel->sport === Sport::Brick ? null : $feel->sport;
            $next = $context->wasApplied($key) ? null : $this->nextKeySession($context, $touched, $sport);

            if ($next !== null) {
                $touched[] = $next->id;
                $changes[] = new PlanChange(
                    ChangeType::Recover, self::NAME, $key,
                    sprintf(
                        'You reported pain%s after %s on %s: %s on %s swapped for recovery. If it lasts, see a physio before the next hard session.',
                        $feel->painArea === null ? '' : " ({$feel->painArea})",
                        self::session($feel->sport),
                        $feel->date->format('l'),
                        MissedKeySessionRule::label($next),
                        $next->date->format('l'),
                    ),
                    $next->id,
                );
            }
        }

        $wornOut = $this->wornOutStreak($context);
        if ($wornOut !== null) {
            $key = 'feel:'.end($wornOut)->date->format('Y-m-d');
            $next = $context->wasApplied($key) ? null : $this->nextKeySession($context, $touched, null);

            if ($next !== null) {
                $changes[] = new PlanChange(
                    ChangeType::Recover, self::NAME, $key,
                    sprintf(
                        'Your last %d sessions left your legs heavy or your energy low: %s on %s swapped for recovery so you can absorb the work.',
                        count($wornOut), MissedKeySessionRule::label($next), $next->date->format('l'),
                    ),
                    $next->id,
                );
            }
        }

        return $changes;
    }

    private static function session(Sport $sport): string
    {
        return match ($sport) {
            Sport::Swim => 'a swim',
            Sport::Bike => 'a ride',
            Sport::Run => 'a run',
            Sport::Brick => 'a brick',
            Sport::Strength => 'strength work',
        };
    }

    /**
     * @return list<FeelRecord>
     */
    private function recentPain(AdaptationContext $context): array
    {
        $since = $context->today->modify('-'.self::PAIN_DAYS.' days');

        return array_values(array_filter($context->recentFeel, fn (FeelRecord $f) => $f->pain && $f->date >= $since));
    }

    /**
     * The most recent rated sessions when every one of them was hard going.
     *
     * @return list<FeelRecord>|null
     */
    private function wornOutStreak(AdaptationContext $context): ?array
    {
        $since = $context->today->modify('-'.self::WORN_OUT_DAYS.' days');
        $rated = array_values(array_filter($context->recentFeel, fn (FeelRecord $f) => $f->wornOut() !== null && $f->date >= $since));
        $last = array_slice($rated, -self::WORN_OUT_SESSIONS);

        if (count($last) < self::WORN_OUT_SESSIONS) {
            return null;
        }

        foreach ($last as $feel) {
            if ($feel->wornOut() < self::WORN_OUT) {
                return null;
            }
        }

        return $last;
    }

    /**
     * @param  list<int|null>  $touched  sessions other changes already move or ease
     */
    private function nextKeySession(AdaptationContext $context, array $touched, ?Sport $sport): ?PlannedSession
    {
        $until = $context->today->modify('+'.self::LOOKAHEAD_DAYS.' days');
        $upcoming = array_filter(
            [...$context->thisWeek, ...$context->nextWeek],
            fn (PlannedSession $s) => $s->isOpen() && $s->isKey
                && $s->date >= $context->today && $s->date <= $until
                && ($sport === null || $s->sport === $sport)
                && ! in_array($s->id, $touched, true),
        );

        usort($upcoming, fn (PlannedSession $a, PlannedSession $b) => [$a->date, $a->id] <=> [$b->date, $b->id]);

        return $upcoming[0] ?? null;
    }
}
