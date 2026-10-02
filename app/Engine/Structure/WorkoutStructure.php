<?php

declare(strict_types=1);

namespace App\Engine\Structure;

use App\Enums\StepType;

/**
 * A structured workout: an ordered list of steps and repeat blocks, stored as
 * JSON relative to thresholds and resolved to absolute numbers only on display
 * or export. Maps closely onto FIT workout steps.
 */
final readonly class WorkoutStructure
{
    public const int MAX_DEPTH = 3;

    /**
     * @param  list<Step|Repeat>  $blocks
     */
    public function __construct(
        public array $blocks,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidStructure
     */
    public static function fromArray(array $data): self
    {
        return new self(self::parseBlocks($data['steps'] ?? null, 'steps'));
    }

    /**
     * @return list<Step|Repeat>
     *
     * @internal shared with Repeat for nested blocks
     */
    public static function parseBlocks(mixed $steps, string $path): array
    {
        if (substr_count($path, 'steps') > self::MAX_DEPTH) {
            throw InvalidStructure::at($path, 'repeats are nested too deeply.');
        }

        if (! is_array($steps) || $steps === [] || ! array_is_list($steps)) {
            throw InvalidStructure::at($path, 'must be a non-empty list of steps.');
        }

        $blocks = [];

        foreach ($steps as $i => $step) {
            if (! is_array($step)) {
                throw InvalidStructure::at("{$path}.{$i}", 'must be an object.');
            }

            $blocks[] = ($step['type'] ?? null) === StepType::Repeat->value
                ? Repeat::fromArray($step, "{$path}.{$i}")
                : Step::fromArray($step, "{$path}.{$i}");
        }

        return $blocks;
    }

    /**
     * @return array{steps: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return ['steps' => array_map(fn (Step|Repeat $b) => $b->toArray(), $this->blocks)];
    }

    /**
     * Every step in execution order, with repeats expanded.
     *
     * @return list<Step>
     */
    public function flatten(): array
    {
        return self::expand($this->blocks);
    }

    /**
     * Grows or shrinks the main set by $factor: repeat counts and the length of
     * steady blocks change, warm-ups and cool-downs keep their length.
     */
    public function scaled(float $factor): self
    {
        return new self(array_map(fn (Step|Repeat $b) => $b->scaled($factor), $this->blocks));
    }

    /**
     * @param  list<Step|Repeat>  $blocks
     * @return list<Step>
     */
    private static function expand(array $blocks): array
    {
        $steps = [];

        foreach ($blocks as $block) {
            if ($block instanceof Step) {
                $steps[] = $block;

                continue;
            }

            $inner = self::expand($block->steps);

            for ($i = 0; $i < $block->count; $i++) {
                array_push($steps, ...$inner);
            }
        }

        return $steps;
    }
}
