<?php

declare(strict_types=1);

namespace Tests\Unit\Engine\Structure;

use App\Engine\Structure\InvalidStructure;
use App\Engine\Structure\Repeat;
use App\Engine\Structure\WorkoutStructure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WorkoutStructureTest extends TestCase
{
    /**
     * The example from the design doc: 3 x 10 min at 88-93% FTP.
     *
     * @return array<string, mixed>
     */
    public static function sweetSpot(): array
    {
        return [
            'steps' => [
                ['type' => 'warmup', 'duration_s' => 900, 'target' => ['metric' => 'ftp_pct', 'low' => 0.55, 'high' => 0.65]],
                ['type' => 'repeat', 'count' => 3, 'steps' => [
                    ['type' => 'interval', 'duration_s' => 600, 'target' => ['metric' => 'ftp_pct', 'low' => 0.88, 'high' => 0.93]],
                    ['type' => 'recovery', 'duration_s' => 300, 'target' => ['metric' => 'ftp_pct', 'low' => 0.50, 'high' => 0.60]],
                ]],
                ['type' => 'cooldown', 'duration_s' => 600, 'target' => ['metric' => 'ftp_pct', 'low' => 0.50, 'high' => 0.60]],
            ],
        ];
    }

    public function test_it_round_trips_the_documented_format(): void
    {
        $structure = WorkoutStructure::fromArray(self::sweetSpot());

        $this->assertSame(self::sweetSpot(), $structure->toArray());
    }

    public function test_flatten_expands_repeats_in_order(): void
    {
        $steps = WorkoutStructure::fromArray(self::sweetSpot())->flatten();

        $this->assertCount(8, $steps);
        $this->assertSame(
            ['warmup', 'interval', 'recovery', 'interval', 'recovery', 'interval', 'recovery', 'cooldown'],
            array_map(fn ($s) => $s->type->value, $steps),
        );
    }

    public function test_scaling_changes_repeat_counts_but_not_warmup_or_cooldown(): void
    {
        $scaled = WorkoutStructure::fromArray(self::sweetSpot())->scaled(1.4)->toArray();

        $this->assertSame(900, $scaled['steps'][0]['duration_s']);
        $this->assertSame(4, $scaled['steps'][1]['count']);
        $this->assertSame(600, $scaled['steps'][2]['duration_s']);
    }

    public function test_scaling_steady_steps_rounds_to_whole_minutes(): void
    {
        $structure = WorkoutStructure::fromArray(['steps' => [
            ['type' => 'steady', 'duration_s' => 3600, 'target' => ['metric' => 'ftp_pct', 'low' => 0.65, 'high' => 0.75]],
        ]]);

        $this->assertSame(4320, $structure->scaled(1.2)->flatten()[0]->durationSeconds);
    }

    public function test_repeats_never_scale_below_one(): void
    {
        $repeat = WorkoutStructure::fromArray(self::sweetSpot())->scaled(0.1)->blocks[1];

        $this->assertInstanceOf(Repeat::class, $repeat);
        $this->assertSame(1, $repeat->count);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidStructures(): iterable
    {
        $target = ['metric' => 'ftp_pct', 'low' => 0.6, 'high' => 0.7];

        yield 'no steps' => [['steps' => []], 'steps'];
        yield 'unknown type' => [['steps' => [['type' => 'sprint', 'duration_s' => 60, 'target' => $target]]], 'steps.0.type'];
        yield 'duration and distance' => [['steps' => [['type' => 'steady', 'duration_s' => 60, 'distance_m' => 100, 'target' => $target]]], 'steps.0'];
        yield 'neither duration nor distance' => [['steps' => [['type' => 'steady', 'target' => $target]]], 'steps.0'];
        yield 'missing target' => [['steps' => [['type' => 'steady', 'duration_s' => 60]]], 'steps.0.target'];
        yield 'unknown metric' => [['steps' => [['type' => 'steady', 'duration_s' => 60, 'target' => ['metric' => 'watts', 'low' => 1, 'high' => 1]]]], 'steps.0.target.metric'];
        yield 'inverted band' => [['steps' => [['type' => 'steady', 'duration_s' => 60, 'target' => ['metric' => 'ftp_pct', 'low' => 0.9, 'high' => 0.8]]]], 'steps.0.target'];
        yield 'absolute watts' => [['steps' => [['type' => 'steady', 'duration_s' => 60, 'target' => ['metric' => 'ftp_pct', 'low' => 200, 'high' => 220]]]], 'steps.0.target.low'];
        yield 'zero repeats' => [['steps' => [['type' => 'repeat', 'count' => 0, 'steps' => [['type' => 'rest', 'duration_s' => 30]]]]], 'steps.0.count'];
        yield 'empty repeat' => [['steps' => [['type' => 'repeat', 'count' => 2, 'steps' => []]]], 'steps.0.steps'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidStructures')]
    public function test_it_rejects_invalid_structures_with_a_path(array $data, string $path): void
    {
        $this->expectException(InvalidStructure::class);
        $this->expectExceptionMessageMatches('/^'.preg_quote($path, '/').':/');

        WorkoutStructure::fromArray($data);
    }

    public function test_rest_steps_need_no_target(): void
    {
        $structure = WorkoutStructure::fromArray(['steps' => [['type' => 'rest', 'duration_s' => 20]]]);

        $this->assertNull($structure->flatten()[0]->target);
    }
}
