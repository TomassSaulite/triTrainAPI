<?php

declare(strict_types=1);

namespace App\Engine\Planning;

use App\Enums\Sport;
use InvalidArgumentException;

/**
 * The athlete-tunable knobs of the coach. Everything has a sensible default so
 * an athlete who never opens settings still gets a complete plan.
 *
 * Weekdays are ISO-8601 numbers: 1 = Monday ... 7 = Sunday.
 */
final readonly class CoachPreferences
{
    public const int DEFAULT_LONG_RIDE_DAY = 6;

    public const array DEFAULT_POOL_DAYS = [2, 3, 5];

    public const array DEFAULT_REST_DAYS = [1];

    public const float DEFAULT_MAX_RAMP_RATE = 5.0;

    /**
     * @param  list<int>  $poolDays
     * @param  list<int>  $restDays
     * @param  array<int, int>  $dayLimitsMinutes  weekday => max minutes of training
     * @param  array<value-of<Sport>, float>|null  $sportShare  overrides the race distance default
     */
    public function __construct(
        public int $longRideDay = self::DEFAULT_LONG_RIDE_DAY,
        public ?int $longRunDay = null,
        public array $poolDays = self::DEFAULT_POOL_DAYS,
        public array $restDays = self::DEFAULT_REST_DAYS,
        public array $dayLimitsMinutes = [],
        public ?int $recoveryWeekEvery = null,
        public float $maxRampRate = self::DEFAULT_MAX_RAMP_RATE,
        public ?float $targetCtl = null,
        public ?array $sportShare = null,
        public bool $bricks = true,
    ) {
        foreach ([$longRideDay, $longRunDay, ...$poolDays, ...$restDays, ...array_keys($dayLimitsMinutes)] as $day) {
            if ($day !== null && ($day < 1 || $day > 7)) {
                throw new InvalidArgumentException("Weekday must be between 1 and 7, got {$day}.");
            }
        }

        if (in_array($longRideDay, $restDays, true)) {
            throw new InvalidArgumentException('The long ride day cannot be a rest day.');
        }

        if ($longRunDay !== null && in_array($longRunDay, $restDays, true)) {
            throw new InvalidArgumentException('The long run day cannot be a rest day.');
        }

        if ($recoveryWeekEvery !== null && ($recoveryWeekEvery < 2 || $recoveryWeekEvery > 5)) {
            throw new InvalidArgumentException('A recovery week must come every 2 to 5 weeks.');
        }

        if ($sportShare !== null) {
            $total = array_sum($sportShare);

            if (abs($total - 1.0) > 0.01) {
                throw new InvalidArgumentException('Sport shares must add up to 1.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            longRideDay: (int) ($data['long_ride_day'] ?? self::DEFAULT_LONG_RIDE_DAY),
            longRunDay: isset($data['long_run_day']) ? (int) $data['long_run_day'] : null,
            poolDays: array_values(array_map(intval(...), $data['pool_days'] ?? self::DEFAULT_POOL_DAYS)),
            restDays: array_values(array_map(intval(...), $data['rest_days'] ?? self::DEFAULT_REST_DAYS)),
            dayLimitsMinutes: array_map(intval(...), $data['day_limits_minutes'] ?? []),
            recoveryWeekEvery: isset($data['recovery_week_every']) ? (int) $data['recovery_week_every'] : null,
            maxRampRate: (float) ($data['max_ramp_rate'] ?? self::DEFAULT_MAX_RAMP_RATE),
            targetCtl: isset($data['target_ctl']) ? (float) $data['target_ctl'] : null,
            sportShare: isset($data['sport_share']) ? array_map(floatval(...), $data['sport_share']) : null,
            bricks: (bool) ($data['bricks'] ?? true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'long_ride_day' => $this->longRideDay,
            'long_run_day' => $this->longRunDay,
            'pool_days' => $this->poolDays,
            'rest_days' => $this->restDays,
            'day_limits_minutes' => (object) $this->dayLimitsMinutes,
            'recovery_week_every' => $this->recoveryWeekEvery,
            'max_ramp_rate' => $this->maxRampRate,
            'target_ctl' => $this->targetCtl,
            'sport_share' => $this->sportShare,
            'bricks' => $this->bricks,
        ];
    }

    public function isRestDay(int $weekday): bool
    {
        return in_array($weekday, $this->restDays, true);
    }

    public function isPoolDay(int $weekday): bool
    {
        return in_array($weekday, $this->poolDays, true);
    }

    /**
     * Maximum training minutes on a weekday, or null when unrestricted.
     */
    public function dayLimit(int $weekday): ?int
    {
        if ($this->isRestDay($weekday)) {
            return 0;
        }

        return $this->dayLimitsMinutes[$weekday] ?? null;
    }
}
