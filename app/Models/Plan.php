<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanStatus;
use App\Enums\RevisionReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $athlete_id
 * @property int|null $race_id null once the race is deleted (the plan is then archived)
 * @property Carbon $start_date
 * @property PlanStatus $status
 * @property int $version
 * @property string $generator_version
 * @property float $starting_ctl
 * @property float $target_ctl
 * @property list<string>|null $warnings
 */
#[Fillable(['race_id', 'start_date', 'status', 'version', 'generator_version', 'starting_ctl', 'target_ctl', 'warnings'])]
class Plan extends Model
{
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'status' => PlanStatus::class,
            'version' => 'integer',
            'starting_ctl' => 'float',
            'target_ctl' => 'float',
            'warnings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Athlete, $this>
     */
    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    /**
     * @return BelongsTo<Race, $this>
     */
    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    /**
     * @return HasMany<PlanPhase, $this>
     */
    public function phases(): HasMany
    {
        return $this->hasMany(PlanPhase::class)->orderBy('start_date');
    }

    /**
     * @return HasMany<PlanWeek, $this>
     */
    public function weeks(): HasMany
    {
        return $this->hasMany(PlanWeek::class)->orderBy('week_index');
    }

    /**
     * @return HasMany<PlannedWorkout, $this>
     */
    public function workouts(): HasMany
    {
        return $this->hasMany(PlannedWorkout::class);
    }

    /**
     * @return HasMany<PlanRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(PlanRevision::class)->orderByDesc('version');
    }

    public function isActive(): bool
    {
        return $this->status === PlanStatus::Active;
    }

    /**
     * Logs a change to the plan, bumping its version unless this is the
     * revision that created it.
     *
     * @param  list<array<string, mixed>>  $changes
     */
    public function recordRevision(RevisionReason $reason, string $summary, array $changes = []): PlanRevision
    {
        if ($reason !== RevisionReason::Generated) {
            $this->increment('version');
        }

        return $this->revisions()->create([
            'version' => $this->version,
            'reason' => $reason,
            'summary' => $summary,
            'changes' => $changes,
        ]);
    }
}
