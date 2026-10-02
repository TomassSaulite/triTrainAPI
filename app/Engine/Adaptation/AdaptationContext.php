<?php

declare(strict_types=1);

namespace App\Engine\Adaptation;

use App\Engine\Planning\CoachPreferences;
use DateTimeImmutable;

/**
 * Everything the adaptation rules look at, as plain data.
 */
final readonly class AdaptationContext
{
    /**
     * @param  list<PlannedSession>  $thisWeek  every top-level session of the current week, any status
     * @param  list<PlannedSession>  $nextWeek  every top-level session of the following week
     * @param  list<DayRecord>  $recentDays  past days, oldest first, ending yesterday
     * @param  list<float>  $recentTsb  daily form, oldest first, ending today
     * @param  list<WeekLoadRecord>  $completedWeeks  finished plan weeks, oldest first
     * @param  list<string>  $unavailableDates  Y-m-d dates the athlete cannot train
     * @param  list<string>  $appliedKeys  adaptations already made, so rules fire once
     * @param  list<FeelRecord>  $recentFeel  how recent sessions felt, oldest first
     */
    public function __construct(
        public DateTimeImmutable $today,
        public DateTimeImmutable $raceDate,
        public CoachPreferences $preferences,
        public float $currentCtl,
        public array $thisWeek,
        public array $nextWeek,
        public array $recentDays,
        public array $recentTsb,
        public array $completedWeeks,
        public array $unavailableDates = [],
        public array $appliedKeys = [],
        public array $recentFeel = [],
    ) {}

    public function weekStart(): DateTimeImmutable
    {
        return $this->today->modify('-'.((int) $this->today->format('N') - 1).' days');
    }

    public function wasApplied(string $key): bool
    {
        return in_array($key, $this->appliedKeys, true);
    }
}
