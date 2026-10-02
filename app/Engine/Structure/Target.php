<?php

declare(strict_types=1);

namespace App\Engine\Structure;

use App\Enums\TargetMetric;

/**
 * An intensity band relative to a threshold, e.g. 88-93% FTP. 1.0 is threshold
 * and higher is always harder (pace targets are fractions of threshold speed).
 */
final readonly class Target
{
    public function __construct(
        public TargetMetric $metric,
        public float $low,
        public float $high,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path): self
    {
        $metric = TargetMetric::tryFrom((string) ($data['metric'] ?? ''));

        if ($metric === null) {
            throw InvalidStructure::at("{$path}.metric", 'must be one of '.implode(', ', array_column(TargetMetric::cases(), 'value')).'.');
        }

        foreach (['low', 'high'] as $bound) {
            if (! is_numeric($data[$bound] ?? null) || $data[$bound] <= 0 || $data[$bound] > 2) {
                throw InvalidStructure::at("{$path}.{$bound}", 'must be a fraction of threshold between 0 and 2.');
            }
        }

        if ($data['low'] > $data['high']) {
            throw InvalidStructure::at($path, 'low must not exceed high.');
        }

        return new self($metric, (float) $data['low'], (float) $data['high']);
    }

    /**
     * @return array{metric: string, low: float, high: float}
     */
    public function toArray(): array
    {
        return ['metric' => $this->metric->value, 'low' => $this->low, 'high' => $this->high];
    }

    public function midpoint(): float
    {
        return ($this->low + $this->high) / 2;
    }
}
