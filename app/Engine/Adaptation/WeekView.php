<?php

declare(strict_types=1);

namespace App\Engine\Adaptation;

use App\Enums\Sport;
use DateTimeImmutable;

/**
 * The current and next week as they will look once earlier changes are
 * applied, answering "can this session go on that day?".
 */
final class WeekView
{
    private const int MAX_SESSIONS_PER_DAY = 2;

    /**
     * @var array<string, list<PlannedSession>> active sessions by Y-m-d
     */
    private array $byDate = [];

    /**
     * @param  list<PlanChange>  $changes
     */
    public function __construct(
        private readonly AdaptationContext $context,
        array $changes = [],
    ) {
        $dropped = [];
        $sessions = [];

        foreach ([...$context->thisWeek, ...$context->nextWeek] as $session) {
            $sessions[$session->id] = $session;
        }

        foreach ($changes as $change) {
            if ($change->type === ChangeType::Drop || $change->type === ChangeType::Recover) {
                $dropped[$change->sessionId] = true;
            }
        }

        foreach ($sessions as $session) {
            if ($session->isActive() && ! isset($dropped[$session->id])) {
                $this->add($session, $session->date);
            }
        }

        foreach ($changes as $change) {
            if ($change->type === ChangeType::Reschedule && isset($sessions[$change->sessionId])) {
                $this->add($sessions[$change->sessionId], $change->date);
            }
        }
    }

    /**
     * Whether $session can be done on $date: the athlete trains that day, there
     * is room, no other session of the sport, and, for key sessions on land,
     * no key session the day before, the day itself or the day after.
     */
    public function canPlace(PlannedSession $session, DateTimeImmutable $date, ?PlannedSession $ignoring = null): bool
    {
        $prefs = $this->context->preferences;
        $weekday = (int) $date->format('N');

        if ($date < $this->context->today
            || $date >= $this->context->raceDate->modify('-1 day')
            || $prefs->isRestDay($weekday)
            || in_array($date->format('Y-m-d'), $this->context->unavailableDates, true)
            || ($session->sport === Sport::Swim && ! $prefs->isPoolDay($weekday))) {
            return false;
        }

        $onDay = $this->sessionsOn($date, $ignoring);

        if (count($onDay) >= self::MAX_SESSIONS_PER_DAY) {
            return false;
        }

        foreach ($onDay as $other) {
            if ($this->overlapsSport($other, $session)) {
                return false;
            }
        }

        if (! $session->loadsLegs()) {
            return true;
        }

        foreach (['-1 day', '+0 days', '+1 day'] as $offset) {
            foreach ($this->sessionsOn($date->modify($offset), $ignoring) as $other) {
                if ($other->loadsLegs()) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return list<PlannedSession>
     */
    public function sessionsOn(DateTimeImmutable $date, ?PlannedSession $ignoring = null): array
    {
        return array_values(array_filter(
            $this->byDate[$date->format('Y-m-d')] ?? [],
            fn (PlannedSession $s) => $s->id !== $ignoring?->id,
        ));
    }

    private function add(PlannedSession $session, DateTimeImmutable $date): void
    {
        $this->byDate[$date->format('Y-m-d')][] = $session;
    }

    private function overlapsSport(PlannedSession $a, PlannedSession $b): bool
    {
        $sports = fn (PlannedSession $s) => $s->sport === Sport::Brick ? [Sport::Bike, Sport::Run] : [$s->sport];

        return array_intersect(
            array_map(fn (Sport $s) => $s->value, $sports($a)),
            array_map(fn (Sport $s) => $s->value, $sports($b)),
        ) !== [];
    }
}
