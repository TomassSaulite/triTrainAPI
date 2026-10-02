<?php

declare(strict_types=1);

namespace App\Engine\Structure;

use App\Enums\StepType;

/**
 * A set of steps performed $count times, e.g. 3 x (10 min on, 5 min easy).
 */
final readonly class Repeat
{
    public const int MAX_COUNT = 50;

    /**
     * @param  list<Step|Repeat>  $steps
     */
    public function __construct(
        public int $count,
        public array $steps,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path): self
    {
        $count = $data['count'] ?? null;

        if (! is_int($count) || $count < 1 || $count > self::MAX_COUNT) {
            throw InvalidStructure::at("{$path}.count", 'must be an integer from 1 to '.self::MAX_COUNT.'.');
        }

        return new self($count, WorkoutStructure::parseBlocks($data['steps'] ?? null, "{$path}.steps"));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => StepType::Repeat->value,
            'count' => $this->count,
            'steps' => array_map(fn (Step|Repeat $s) => $s->toArray(), $this->steps),
        ];
    }

    public function scaled(float $factor): self
    {
        return new self(max(1, (int) round($this->count * $factor)), $this->steps);
    }
}
