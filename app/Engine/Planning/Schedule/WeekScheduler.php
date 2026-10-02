<?php

declare(strict_types=1);

namespace App\Engine\Planning\Schedule;

use App\Engine\Planning\CoachPreferences;
use App\Enums\Sport;
use App\Enums\WorkoutKind;

/**
 * Places a week's sessions on days, following the skeleton rules in order:
 *
 * 1. The long ride goes on its preferred day; a rest or recovery day follows it
 *    (as it does a race).
 * 2. The long run goes on its preferred day, or one or two clear days away
 *    from the long ride.
 * 3. Key sessions never land on back-to-back days. When no free day is left a
 *    quality session doubles up with another quality session rather than
 *    stacking key days, and failing that it becomes an easy session.
 * 4. Swims only go on pool days.
 * 5. Easy sessions fill the emptiest days, never two of one sport on a day.
 *
 * An explicitly chosen long run day is honoured even when it breaks a rule:
 * the athlete is the boss of their calendar.
 */
final class WeekScheduler
{
    /**
     * Sessions shorter than this are not worth squeezing into a busy day.
     */
    public const int MIN_SESSION_SECONDS = 1200;

    /**
     * Fallback order for the long ride when its preferred day is unavailable.
     */
    private const array LONG_RIDE_FALLBACK = [6, 7, 5, 4, 3, 2, 1];

    /**
     * @var array<int, Day>
     */
    private array $days = [];

    /**
     * @param  list<Day>  $days  Monday to Sunday
     * @param  list<SessionSlot>  $slots
     */
    public function schedule(array $days, array $slots, CoachPreferences $preferences): WeekSchedule
    {
        $this->days = [];

        foreach ($days as $day) {
            $this->days[$day->weekday] = $day;
        }

        $unplaced = [];

        foreach ($this->inPlacementOrder($slots) as $slot) {
            if (! $this->place($slot, $preferences)) {
                $unplaced[] = $slot;
            }
        }

        return new WeekSchedule($this->placements(), $unplaced);
    }

    private function place(SessionSlot $slot, CoachPreferences $preferences): bool
    {
        $day = match ($slot->role) {
            SlotRole::LongRide => $this->longRideDay($slot, $preferences),
            SlotRole::LongRun => $this->longRunDay($slot, $preferences),
            default => $this->bestDay($slot, allowKeyDay: false),
        };

        if ($day === null && $slot->isKey) {
            $day = $this->bestDay($slot, allowKeyDay: true);
        }

        if ($day === null && $slot->isKey) {
            $slot = $slot->downgraded();
            $day = $this->bestDay($slot, allowKeyDay: false);
        }

        if ($day === null) {
            return false;
        }

        $this->assign($day, $slot);

        return true;
    }

    private function longRideDay(SessionSlot $slot, CoachPreferences $preferences): ?Day
    {
        $order = array_unique([$preferences->longRideDay, ...self::LONG_RIDE_FALLBACK]);

        foreach ($order as $weekday) {
            if ($this->fits($this->days[$weekday], $slot, allowKeyDay: false)) {
                return $this->days[$weekday];
            }
        }

        return null;
    }

    private function longRunDay(SessionSlot $slot, CoachPreferences $preferences): ?Day
    {
        $preferred = $preferences->longRunDay === null ? null : $this->days[$preferences->longRunDay];

        if ($preferred !== null && $this->fitsIgnoringSpacing($preferred, $slot)) {
            return $preferred;
        }

        $ride = $this->longRideWeekday() ?? $preferences->longRideDay;

        foreach ([$ride - 2, $ride + 2, $ride - 3, $ride + 3] as $weekday) {
            if (isset($this->days[$weekday]) && $this->fits($this->days[$weekday], $slot, allowKeyDay: false)) {
                return $this->days[$weekday];
            }
        }

        return $this->bestDay($slot, allowKeyDay: false);
    }

    private function bestDay(SessionSlot $slot, bool $allowKeyDay): ?Day
    {
        $best = null;
        $bestScore = PHP_INT_MAX;

        foreach ($this->days as $day) {
            if (! $this->fits($day, $slot, $allowKeyDay)) {
                continue;
            }

            $score = $this->score($day, $slot);

            if ($score < $bestScore) {
                [$best, $bestScore] = [$day, $score];
            }
        }

        return $best;
    }

    /**
     * Lower is better. Spreads sessions out, keeps quality mid-week and away
     * from other hard days, and keeps the day after the long ride easy.
     */
    private function score(Day $day, SessionSlot $slot): int
    {
        $score = count($day->slots) * 10;

        if ($slot->isKey) {
            $score += abs($day->weekday - 3);
            $score += $day->hasLegKey() ? 5 : 0;
        } else {
            $score += $day->hasLegKey() ? 6 : 0;
        }

        if ($day->easyOnly) {
            $score += $slot->kind === WorkoutKind::Recovery ? -15 : 20;
        }

        return $score;
    }

    private function fits(Day $day, SessionSlot $slot, bool $allowKeyDay): bool
    {
        if (! $this->fitsIgnoringSpacing($day, $slot)) {
            return false;
        }

        if ($day->keyFree && ($slot->isKey || ! in_array($slot->kind, [WorkoutKind::Recovery, WorkoutKind::Technique, WorkoutKind::Endurance], true))) {
            return false;
        }

        if ($day->easyOnly && ! in_array($slot->kind, [WorkoutKind::Recovery, WorkoutKind::Technique], true) && ! $slot->isKey) {
            return $slot->sport === Sport::Swim;
        }

        if (! $slot->loadsLegs()) {
            return true;
        }

        if ($day->easyOnly || (! $allowKeyDay && $day->hasLegKey())) {
            return false;
        }

        // Doubling up is for two quality sessions, never quality on top of a long one.
        if ($day->hasLegKey() && ($this->hasLongSession($day) || in_array($slot->role, [SlotRole::LongRide, SlotRole::LongRun], true))) {
            return false;
        }

        foreach ([$day->weekday - 1, $day->weekday + 1] as $neighbour) {
            if (isset($this->days[$neighbour]) && $this->days[$neighbour]->hasLegKey()) {
                return false;
            }
        }

        return true;
    }

    private function fitsIgnoringSpacing(Day $day, SessionSlot $slot): bool
    {
        if (! $day->available || $day->isFull()) {
            return false;
        }

        if ($slot->sport === Sport::Swim && ! $day->isPoolDay) {
            return false;
        }

        if ($day->hasSport($slot->sport) || ($slot->isBrick() && $day->hasSport(Sport::Run))) {
            return false;
        }

        $remaining = $day->remainingSeconds();

        return $remaining === null || $remaining >= min($slot->totalSeconds(), self::MIN_SESSION_SECONDS);
    }

    private function assign(Day $day, SessionSlot $slot): void
    {
        $day->slots[] = $slot;

        if ($slot->role === SlotRole::LongRide && isset($this->days[$day->weekday + 1])) {
            $this->days[$day->weekday + 1]->easyOnly = true;
        }
    }

    private function hasLongSession(Day $day): bool
    {
        foreach ($day->slots as $slot) {
            if ($slot->role === SlotRole::LongRide || $slot->role === SlotRole::LongRun) {
                return true;
            }
        }

        return false;
    }

    private function longRideWeekday(): ?int
    {
        foreach ($this->days as $day) {
            foreach ($day->slots as $slot) {
                if ($slot->role === SlotRole::LongRide) {
                    return $day->weekday;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<SessionSlot>  $slots
     * @return list<SessionSlot>
     */
    private function inPlacementOrder(array $slots): array
    {
        $rank = fn (SessionSlot $s): int => match ($s->role) {
            SlotRole::LongRide => 0,
            SlotRole::LongRun => 1,
            SlotRole::Quality => 2,
            SlotRole::Swim => $s->isKey ? 3 : 4,
            SlotRole::Easy => 5,
        };

        $indexed = array_map(null, $slots, array_keys($slots));
        usort($indexed, fn (array $a, array $b) => [$rank($a[0]), $a[1]] <=> [$rank($b[0]), $b[1]]);

        return array_column($indexed, 0);
    }

    /**
     * @return list<Placement>
     */
    private function placements(): array
    {
        $placements = [];

        foreach ($this->days as $day) {
            $used = $day->usedSeconds();

            foreach ($day->slots as $slot) {
                $placements[] = new Placement($slot, $day->date, $this->maxSecondsFor($day, $slot, $used));
            }
        }

        return $placements;
    }

    /**
     * The share of a capacity-limited day this session may use: proportional
     * to its planned length, so the shares never add up to more than the day,
     * however much the sessions are later rescaled.
     */
    private function maxSecondsFor(Day $day, SessionSlot $slot, int $used): ?int
    {
        if ($day->capacitySeconds === null) {
            return null;
        }

        return (int) floor($day->capacitySeconds * $slot->totalSeconds() / max(1, $used));
    }
}
