<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Activity;
use App\Models\Athlete;
use App\Services\Adaptation\AdaptationService;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * What happens whenever an activity arrives, changes or disappears:
 * score it, bring derived load up to date, match it to the plan, look for a
 * possible new threshold, then let the adaptation rules adjust the rest of
 * the week.
 */
class ActivityPipeline
{
    public function __construct(
        private readonly LoadService $load,
        private readonly WorkoutMatcher $matcher,
        private readonly AdaptationService $adaptation,
        private readonly ThresholdSuggestionService $suggestions,
    ) {}

    public function process(Activity $activity, ?DateTimeInterface $rebuildFrom = null): void
    {
        $this->load->score($activity);
        $this->load->rebuild($activity->athlete, $rebuildFrom ?? $activity->started_at);
        $this->matcher->link($activity);
        $this->suggestions->detectFrom($activity);
        $this->adapt($activity->athlete);
    }

    /**
     * Processes a batch (e.g. a history import) with a single load rebuild.
     *
     * @param  list<Activity>  $activities
     */
    public function processMany(Athlete $athlete, array $activities): void
    {
        if ($activities === []) {
            return;
        }

        foreach ($activities as $activity) {
            $activity->setRelation('athlete', $athlete);
            $this->load->score($activity);
        }

        $earliest = min(array_map(fn (Activity $a) => $a->started_at, $activities));
        $this->load->rebuild($athlete, $earliest);

        foreach ($activities as $activity) {
            $this->matcher->link($activity);
            $this->suggestions->detectFrom($activity);
        }

        $this->adapt($athlete);
    }

    /**
     * Call before changing when or what an activity was, so it is matched afresh.
     */
    public function detach(Activity $activity): void
    {
        $this->matcher->unlink($activity);
    }

    public function remove(Activity $activity): void
    {
        $athlete = $activity->athlete;
        $startedAt = $activity->started_at;

        DB::transaction(function () use ($activity): void {
            $this->matcher->unlink($activity);
            $activity->delete();
        });

        $this->load->rebuild($athlete, $startedAt);
    }

    private function adapt(Athlete $athlete): void
    {
        $plan = $athlete->activePlan()->first();

        if ($plan !== null) {
            $this->adaptation->adapt($plan);
        }
    }
}
