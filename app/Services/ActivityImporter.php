<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivitySource;
use App\Models\Activity;
use App\Models\Athlete;
use Illuminate\Support\Facades\DB;

/**
 * Upserts a batch of device-side activities by (source, external_id) and runs
 * them through the pipeline together.
 */
class ActivityImporter
{
    public function __construct(
        private readonly ActivityPipeline $pipeline,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{created: int, updated: int, unchanged: int}
     */
    public function import(Athlete $athlete, ActivitySource $source, array $rows): array
    {
        $existing = $athlete->activities()
            ->where('source', $source)
            ->whereIn('external_id', array_column($rows, 'external_id'))
            ->get()
            ->keyBy('external_id');

        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        $changed = [];

        DB::transaction(function () use ($athlete, $source, $rows, $existing, &$counts, &$changed): void {
            foreach ($rows as $row) {
                /** @var Activity $activity */
                $activity = $existing->get($row['external_id']) ?? $athlete->activities()->make(['source' => $source]);
                $activity->fill($row);

                if ($activity->exists && ! $activity->isDirty()) {
                    $counts['unchanged']++;

                    continue;
                }

                $counts[$activity->exists ? 'updated' : 'created']++;

                if ($activity->exists && $activity->isDirty(['started_at', 'sport'])) {
                    $this->pipeline->detach($activity);
                }

                $activity->tss = null;
                $activity->save();
                $changed[] = $activity;
            }
        });

        $this->pipeline->processMany($athlete, $changed);

        return $counts;
    }
}
