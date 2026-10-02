<?php

declare(strict_types=1);

namespace Tests\Unit\Engine\Load;

use App\Engine\Load\LoadModel;
use App\Engine\Load\LoadState;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class LoadModelTest extends TestCase
{
    private LoadModel $model;

    protected function setUp(): void
    {
        $this->model = new LoadModel;
    }

    public function test_a_single_day_moves_ctl_and_atl_by_their_time_constants(): void
    {
        $state = $this->model->step(new LoadState, 84.0);

        $this->assertEqualsWithDelta(2.0, $state->ctl, 1e-9);
        $this->assertEqualsWithDelta(12.0, $state->atl, 1e-9);
    }

    public function test_series_has_one_point_per_day_and_treats_gaps_as_rest(): void
    {
        $from = new DateTimeImmutable('2026-01-01');
        $to = new DateTimeImmutable('2026-01-03');

        $points = $this->model->series(['2026-01-01' => 70.0, '2026-01-03' => 70.0], $from, $to);

        $this->assertCount(3, $points);
        $this->assertSame(0.0, $points[1]->tss);
        $this->assertSame('2026-01-03', $points[2]->date->format('Y-m-d'));
    }

    public function test_tsb_is_yesterdays_fitness_minus_yesterdays_fatigue(): void
    {
        $from = new DateTimeImmutable('2026-01-01');
        $points = $this->model->series(['2026-01-01' => 100.0], $from, $from->modify('+1 day'), new LoadState(50, 50));

        $this->assertSame(0.0, $points[0]->tsb);
        $this->assertEqualsWithDelta($points[0]->ctl - $points[0]->atl, $points[1]->tsb, 1e-9);
    }

    public function test_constant_load_converges_on_the_daily_tss(): void
    {
        $from = new DateTimeImmutable('2026-01-01');
        $to = $from->modify('+364 days');
        $daily = [];

        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            $daily[$d->format('Y-m-d')] = 60.0;
        }

        $last = $this->model->series($daily, $from, $to)[364];

        $this->assertEqualsWithDelta(60.0, $last->ctl, 0.1);
        $this->assertEqualsWithDelta(60.0, $last->atl, 0.01);
    }

    public function test_projection_matches_stepping_day_by_day(): void
    {
        $start = new LoadState(40, 55);
        $stepped = $start;

        for ($i = 0; $i < 7; $i++) {
            $stepped = $this->model->step($stepped, 65.0);
        }

        $projected = $this->model->project($start, 65.0, 7);

        $this->assertEqualsWithDelta($stepped->ctl, $projected->ctl, 1e-9);
        $this->assertEqualsWithDelta($stepped->atl, $projected->atl, 1e-9);
    }

    public function test_daily_tss_to_reach_inverts_the_projection(): void
    {
        $daily = $this->model->dailyTssToReach(50.0, 55.0, 7);
        $reached = $this->model->project(new LoadState(50.0, 50.0), $daily, 7);

        $this->assertEqualsWithDelta(55.0, $reached->ctl, 1e-9);
        $this->assertGreaterThan(55.0, $daily);
    }

    public function test_it_estimates_fitness_from_weekly_hours(): void
    {
        $this->assertSame(60.0, $this->model->estimateCtlFromWeeklyHours(8.0));
    }
}
