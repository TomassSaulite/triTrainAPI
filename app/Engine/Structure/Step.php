<?php

declare(strict_types=1);

namespace App\Engine\Structure;

use App\Enums\StepType;

/**
 * One block of a workout, measured either in time or in distance (swim sets
 * are almost always distance).
 */
final readonly class Step
{
    public function __construct(
        public StepType $type,
        public ?int $durationSeconds = null,
        public ?int $distanceMeters = null,
        public ?Target $target = null,
        public ?string $note = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path): self
    {
        $type = StepType::tryFrom((string) ($data['type'] ?? ''));

        if ($type === null || $type === StepType::Repeat) {
            throw InvalidStructure::at("{$path}.type", 'is not a valid step type.');
        }

        $duration = $data['duration_s'] ?? null;
        $distance = $data['distance_m'] ?? null;

        if (($duration === null) === ($distance === null)) {
            throw InvalidStructure::at($path, 'needs exactly one of duration_s or distance_m.');
        }

        foreach (['duration_s' => $duration, 'distance_m' => $distance] as $field => $value) {
            if ($value !== null && (! is_int($value) || $value <= 0)) {
                throw InvalidStructure::at("{$path}.{$field}", 'must be a positive integer.');
            }
        }

        $target = isset($data['target']) ? Target::fromArray((array) $data['target'], "{$path}.target") : null;

        if ($target === null && $type !== StepType::Rest) {
            throw InvalidStructure::at("{$path}.target", 'is required for every step except rest.');
        }

        return new self($type, $duration, $distance, $target, isset($data['note']) ? (string) $data['note'] : null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type->value,
            'duration_s' => $this->durationSeconds,
            'distance_m' => $this->distanceMeters,
            'target' => $this->target?->toArray(),
            'note' => $this->note,
        ], fn ($value) => $value !== null);
    }

    public function isDistanceBased(): bool
    {
        return $this->distanceMeters !== null;
    }

    public function scaled(float $factor): self
    {
        if (! $this->type->isScalable()) {
            return $this;
        }

        return new self(
            $this->type,
            $this->durationSeconds === null ? null : self::round($this->durationSeconds * $factor, $this->durationSeconds >= 300 ? 60 : 15),
            $this->distanceMeters === null ? null : self::round($this->distanceMeters * $factor, $this->distanceMeters >= 400 ? 100 : 25),
            $this->target,
            $this->note,
        );
    }

    private static function round(float $value, int $increment): int
    {
        return max($increment, (int) (round($value / $increment) * $increment));
    }
}
