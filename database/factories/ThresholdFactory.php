<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Sport;
use App\Enums\ThresholdMetric;
use App\Enums\ThresholdSource;
use App\Models\Athlete;
use App\Models\Threshold;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Threshold>
 */
class ThresholdFactory extends Factory
{
    public function definition(): array
    {
        return [
            'athlete_id' => Athlete::factory(),
            'sport' => Sport::Bike,
            'metric' => ThresholdMetric::FtpWatts,
            'value' => 250,
            'tested_at' => now()->subWeeks(2)->toDateString(),
            'source' => ThresholdSource::Test,
        ];
    }

    public function ftp(float $watts): static
    {
        return $this->state(['sport' => Sport::Bike, 'metric' => ThresholdMetric::FtpWatts, 'value' => $watts]);
    }

    public function css(float $secondsPer100m): static
    {
        return $this->state(['sport' => Sport::Swim, 'metric' => ThresholdMetric::CssSecondsPer100m, 'value' => $secondsPer100m]);
    }

    public function runPace(float $secondsPerKm): static
    {
        return $this->state(['sport' => Sport::Run, 'metric' => ThresholdMetric::ThresholdPaceSecondsPerKm, 'value' => $secondsPerKm]);
    }

    public function lthr(float $bpm): static
    {
        return $this->state(['sport' => null, 'metric' => ThresholdMetric::Lthr, 'value' => $bpm]);
    }
}
