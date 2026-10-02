<?php

declare(strict_types=1);

namespace App\Services;

use App\Engine\ThresholdSet;
use App\Enums\ThresholdMetric;
use App\Enums\ThresholdSource;
use App\Models\Athlete;
use App\Models\Threshold;
use DateTimeInterface;
use Illuminate\Support\Collection;

class ThresholdService
{
    /** A threshold older than this is due a retest: fitness has moved on since. */
    public const int STALE_AFTER_DAYS = 56;

    /**
     * The newest threshold row per metric, optionally as of a past date so
     * historical activities are scored against the thresholds valid back then.
     *
     * @return Collection<value-of<ThresholdMetric>, Threshold>
     */
    public function latestPerMetric(Athlete $athlete, ?DateTimeInterface $asOf = null): Collection
    {
        return $athlete->thresholds()
            ->when($asOf, fn ($query) => $query->whereDate('tested_at', '<=', $asOf))
            ->orderByDesc('tested_at')
            ->orderByDesc('id')
            ->get()
            ->toBase()
            ->unique(fn (Threshold $t) => $t->metric->value)
            ->keyBy(fn (Threshold $t) => $t->metric->value);
    }

    public function setFor(Athlete $athlete, ?DateTimeInterface $asOf = null): ThresholdSet
    {
        $latest = $this->latestPerMetric($athlete, $asOf);

        // An activity older than every test is still best scored with the oldest known values.
        if ($asOf !== null && $latest->count() < count(ThresholdMetric::cases())) {
            $latest = $latest->union($this->latestPerMetric($athlete));
        }

        return ThresholdSet::fromArray($latest->map(fn (Threshold $t) => $t->value)->all());
    }

    /**
     * Each threshold's age and whether it is due a retest. A threshold older
     * than STALE_AFTER_DAYS no longer reflects the athlete's fitness, so the
     * plan's targets drift; a missing one means targets fall back to effort.
     *
     * @return list<array{metric: ThresholdMetric, value: float|null, tested_at: string|null, source: ThresholdSource|null, age_days: int|null, status: 'ok'|'due'|'missing', protocol: string}>
     */
    public function status(Athlete $athlete): array
    {
        $today = $athlete->today();
        $latest = $this->latestPerMetric($athlete);

        return array_map(function (ThresholdMetric $metric) use ($latest, $today): array {
            $threshold = $latest->get($metric->value);
            $age = $threshold === null ? null : (int) $threshold->tested_at->diffInDays($today);

            return [
                'metric' => $metric,
                'value' => $threshold?->value,
                'tested_at' => $threshold?->tested_at->toDateString(),
                'source' => $threshold?->source,
                'age_days' => $age,
                'status' => match (true) {
                    $threshold === null => 'missing',
                    $age > self::STALE_AFTER_DAYS => 'due',
                    default => 'ok',
                },
                'protocol' => $metric->testProtocol(),
            ];
        }, ThresholdMetric::cases());
    }
}
