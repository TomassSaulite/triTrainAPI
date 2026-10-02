<?php

declare(strict_types=1);

namespace App\Services\Planning;

use App\Engine\Planning\PlanGenerator;
use App\Enums\PlanStatus;
use App\Enums\RevisionReason;
use App\Enums\WorkoutStatus;
use App\Exceptions\PlanningException;
use App\Models\Athlete;
use App\Models\Plan;
use App\Models\PlanWeek;
use App\Models\Race;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates plans and re-plans them. History is immutable: only future,
 * unstarted workouts are ever replaced, and every change bumps the plan
 * version with a "what changed and why" revision.
 */
class PlanService
{
    public function __construct(
        private readonly PlanGenerator $generator,
        private readonly PlanRequestFactory $requests,
        private readonly TemplateRepository $templates,
        private readonly PlanWriter $writer,
    ) {}

    /**
     * Generates a plan for an A race, replacing the athlete's active plan.
     */
    public function create(Athlete $athlete, Race $race, ?CarbonImmutable $startDate = null): Plan
    {
        $start = ($startDate ?? $athlete->today())->startOfDay();

        if ($race->date->lessThanOrEqualTo($start->addDay())) {
            throw new PlanningException('The race is too close to plan for.');
        }

        $draft = $this->generator->generate(
            $this->requests->make($athlete, $race, $start),
            $this->templates->libraryFor($athlete),
        );

        return DB::transaction(function () use ($athlete, $race, $start, $draft): Plan {
            $athlete->plans()->where('status', PlanStatus::Active)->update(['status' => PlanStatus::Archived]);

            $plan = $athlete->plans()->create([
                'race_id' => $race->id,
                'start_date' => $start->toDateString(),
                'status' => PlanStatus::Active,
                'version' => 1,
                'generator_version' => PlanGenerator::VERSION,
                'starting_ctl' => $draft->startingCtl,
                'target_ctl' => $draft->targetCtl,
                'warnings' => $draft->warnings,
            ]);

            $this->writer->writeNew($plan, $draft);

            $plan->recordRevision(
                RevisionReason::Generated,
                sprintf('Plan created: %d weeks to %s, aiming for CTL %d.', count($draft->weeks), $race->name, round($draft->targetCtl)),
            );

            return $plan;
        });
    }

    /**
     * Throws away future unstarted workouts and plans again from $from with
     * the athlete's current fitness, thresholds and preferences.
     */
    /**
     * @param  list<array<string, mixed>>  $context  extra entries for the revision's change log
     */
    public function regenerate(Plan $plan, RevisionReason $reason, string $why, ?CarbonImmutable $from = null, array $context = []): Plan
    {
        $from = ($from ?? $plan->athlete->today())->startOfDay()->max($plan->start_date->toImmutable());
        $race = $plan->race;

        // A day that already has finished work is history: re-plan from the next one.
        if ($plan->workouts()->whereDate('date', $from)->whereNotIn('status', WorkoutStatus::open())->exists()) {
            $from = $from->addDay();
        }

        if (! $plan->isActive()) {
            throw new PlanningException('Only the active plan can be re-planned.');
        }

        if ($race->date->lessThanOrEqualTo($from->addDay())) {
            throw new PlanningException('The race is too close to re-plan.');
        }

        $athlete = $plan->athlete;
        $draft = $this->generator->generate(
            $this->requests->make($athlete, $race, $from, $plan->start_date->toImmutable()),
            $this->templates->libraryFor($athlete),
        );

        return DB::transaction(function () use ($plan, $draft, $from, $reason, $why, $context): Plan {
            $before = $this->weeklyLoad($plan, $from);

            $this->writer->writeFrom($plan, $draft, $from);
            $plan->update(['target_ctl' => $draft->targetCtl, 'warnings' => $draft->warnings]);

            $plan->recordRevision($reason, $why, [...$context, ...$this->loadChanges($before, $this->weeklyLoad($plan, $from))]);

            return $plan->refresh();
        });
    }

    public function archive(Plan $plan): Plan
    {
        $plan->update(['status' => PlanStatus::Archived]);

        return $plan;
    }

    /**
     * Planned TSS per week from $from, counting only open (changeable) sessions.
     *
     * @return array<string, float>
     */
    private function weeklyLoad(Plan $plan, CarbonImmutable $from): array
    {
        return $plan->weeks()
            ->whereDate('start_date', '>=', $from->startOfWeek())
            ->withSum(['workouts as open_tss' => fn ($q) => $q->open()->whereNull('parent_id')->whereDate('date', '>=', $from)], 'target_tss')
            ->get()
            ->mapWithKeys(fn (PlanWeek $w) => [$w->start_date->toDateString() => round((float) $w->getAttribute('open_tss'), 1)])
            ->all();
    }

    /**
     * @param  array<string, float>  $before
     * @param  array<string, float>  $after
     * @return list<array<string, mixed>>
     */
    private function loadChanges(array $before, array $after): array
    {
        $changes = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $week) {
            $old = $before[$week] ?? 0.0;
            $new = $after[$week] ?? 0.0;

            if (abs($old - $new) >= 1) {
                $changes[] = ['type' => 'week_load', 'week_start' => $week, 'from_tss' => $old, 'to_tss' => $new];
            }
        }

        usort($changes, fn (array $a, array $b) => $a['week_start'] <=> $b['week_start']);

        return $changes;
    }
}
