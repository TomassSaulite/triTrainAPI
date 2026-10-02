<?php

declare(strict_types=1);

namespace App\Services;

use App\Engine\Adaptation\ThresholdDetector;
use App\Enums\SuggestionStatus;
use App\Enums\ThresholdSource;
use App\Exceptions\PlanningException;
use App\Models\Activity;
use App\Models\Threshold;
use App\Models\ThresholdSuggestion;
use Illuminate\Support\Facades\DB;

/**
 * Turns detected threshold changes into suggestions and applies the ones the
 * athlete accepts. Thresholds never change without the athlete saying so.
 */
class ThresholdSuggestionService
{
    public function __construct(
        private readonly ThresholdDetector $detector,
        private readonly ThresholdService $thresholds,
    ) {}

    /**
     * @return list<ThresholdSuggestion>
     */
    public function detectFrom(Activity $activity): array
    {
        $athlete = $activity->athlete;
        $drafts = $this->detector->detect(
            $activity->sport,
            $activity->duration_s,
            $activity->best_20min_power_w,
            $activity->avg_pace,
            $this->thresholds->setFor($athlete),
        );

        $suggestions = [];

        foreach ($drafts as $draft) {
            $pending = $athlete->thresholdSuggestions()
                ->where('metric', $draft->metric)
                ->where('status', SuggestionStatus::Pending)
                ->first();

            // Keep a single open suggestion per metric: the best evidence so far.
            if ($pending !== null && ! $this->isBetter($draft->metric->value, $draft->suggestedValue, $pending->suggested_value)) {
                continue;
            }

            $suggestion = $pending ?? $athlete->thresholdSuggestions()->make(['status' => SuggestionStatus::Pending]);
            $suggestion->fill([
                'activity_id' => $activity->id,
                'metric' => $draft->metric,
                'current_value' => $draft->currentValue,
                'suggested_value' => $draft->suggestedValue,
                'rationale' => $draft->rationale,
            ])->save();

            $suggestions[] = $suggestion;
        }

        return $suggestions;
    }

    public function accept(ThresholdSuggestion $suggestion): Threshold
    {
        $this->assertPending($suggestion);

        return DB::transaction(function () use ($suggestion): Threshold {
            $suggestion->update(['status' => SuggestionStatus::Accepted, 'resolved_at' => now()]);

            return $suggestion->athlete->thresholds()->create([
                'sport' => $suggestion->metric->sport(),
                'metric' => $suggestion->metric,
                'value' => $suggestion->suggested_value,
                'tested_at' => $suggestion->activity === null
                    ? $suggestion->athlete->today()
                    : $suggestion->athlete->localDate($suggestion->activity->started_at),
                'source' => ThresholdSource::AutoDetected,
            ]);
        });
    }

    public function dismiss(ThresholdSuggestion $suggestion): void
    {
        $this->assertPending($suggestion);

        $suggestion->update(['status' => SuggestionStatus::Dismissed, 'resolved_at' => now()]);
    }

    /**
     * Higher power is better; for paces, lower (faster) is better.
     */
    private function isBetter(string $metric, float $candidate, float $existing): bool
    {
        return str_ends_with($metric, '_w') ? $candidate > $existing : $candidate < $existing;
    }

    private function assertPending(ThresholdSuggestion $suggestion): void
    {
        if (! $suggestion->isPending()) {
            throw new PlanningException('This suggestion has already been '.$suggestion->status->value.'.');
        }
    }
}
