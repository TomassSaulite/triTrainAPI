<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Sport;
use App\Enums\WorkoutKind;
use App\Enums\WorkoutStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $plan_id
 * @property int $plan_week_id
 * @property int|null $parent_id
 * @property int|null $rescheduled_from_id
 * @property int|null $workout_template_id
 * @property int|null $activity_id
 * @property Carbon $date
 * @property Sport $sport
 * @property WorkoutKind $kind
 * @property bool $is_key
 * @property string $title
 * @property int $target_duration_s
 * @property int|null $target_distance_m
 * @property float $target_tss
 * @property array<string, mixed>|null $structure
 * @property WorkoutStatus $status
 * @property float|null $compliance
 */
#[Fillable([
    'plan_week_id', 'parent_id', 'rescheduled_from_id', 'workout_template_id', 'activity_id', 'date', 'sport', 'kind', 'is_key',
    'title', 'target_duration_s', 'target_distance_m', 'target_tss', 'structure', 'status', 'compliance',
])]
class PlannedWorkout extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'sport' => Sport::class,
            'kind' => WorkoutKind::class,
            'is_key' => 'boolean',
            'target_duration_s' => 'integer',
            'target_distance_m' => 'integer',
            'target_tss' => 'float',
            'structure' => 'array',
            'status' => WorkoutStatus::class,
            'compliance' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<PlanWeek, $this>
     */
    public function week(): BelongsTo
    {
        return $this->belongsTo(PlanWeek::class, 'plan_week_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * The missed session this one makes up for.
     *
     * @return BelongsTo<self, $this>
     */
    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
    }

    /**
     * @return BelongsTo<WorkoutTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkoutTemplate::class, 'workout_template_id');
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /**
     * Workouts that are still ahead of the athlete and may be changed by re-planning.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('status', WorkoutStatus::open());
    }

    public function isBrick(): bool
    {
        return $this->sport === Sport::Brick;
    }
}
