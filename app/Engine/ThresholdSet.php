<?php

declare(strict_types=1);

namespace App\Engine;

use App\Enums\ThresholdMetric;

/**
 * The athlete's current thresholds, as the engine consumes them. Any of them
 * may be unknown; consumers fall back to cruder methods when that happens.
 */
final readonly class ThresholdSet
{
    public function __construct(
        public ?float $ftpWatts = null,
        public ?float $thresholdPaceSecondsPerKm = null,
        public ?float $cssSecondsPer100m = null,
        public ?float $lthr = null,
    ) {}

    /**
     * @param  array<value-of<ThresholdMetric>, float|int|null>  $values
     */
    public static function fromArray(array $values): self
    {
        $get = static fn (ThresholdMetric $m): ?float => isset($values[$m->value]) ? (float) $values[$m->value] : null;

        return new self(
            ftpWatts: $get(ThresholdMetric::FtpWatts),
            thresholdPaceSecondsPerKm: $get(ThresholdMetric::ThresholdPaceSecondsPerKm),
            cssSecondsPer100m: $get(ThresholdMetric::CssSecondsPer100m),
            lthr: $get(ThresholdMetric::Lthr),
        );
    }

    public function get(ThresholdMetric $metric): ?float
    {
        return match ($metric) {
            ThresholdMetric::FtpWatts => $this->ftpWatts,
            ThresholdMetric::ThresholdPaceSecondsPerKm => $this->thresholdPaceSecondsPerKm,
            ThresholdMetric::CssSecondsPer100m => $this->cssSecondsPer100m,
            ThresholdMetric::Lthr => $this->lthr,
        };
    }
}
