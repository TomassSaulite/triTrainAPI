<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $plan_id
 * @property int $plan_phase_id
 * @property int $week_index
 * @property Carbon $start_date
 * @property float $target_tss
 * @property float $target_hours
 * @property bool $is_recovery
 */
#[Fillable(['plan_phase_id', 'week_index', 'start_date', 'target_tss', 'target_hours', 'is_recovery'])]
#[Table(timestamps: false)]
class PlanWeek extends Model
{
    protected function casts(): array
    {
        return [
            'week_index' => 'integer',
            'start_date' => 'date',
            'target_tss' => 'float',
            'target_hours' => 'float',
            'is_recovery' => 'boolean',
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
     * @return BelongsTo<PlanPhase, $this>
     */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(PlanPhase::class, 'plan_phase_id');
    }

    /**
     * @return HasMany<PlannedWorkout, $this>
     */
    public function workouts(): HasMany
    {
        return $this->hasMany(PlannedWorkout::class)->orderBy('date');
    }

    public function endDate(): Carbon
    {
        return $this->start_date->copy()->addDays(6);
    }
}
