<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivitySource;
use App\Enums\Sport;
use App\Enums\TssMethod;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $athlete_id
 * @property ActivitySource $source
 * @property string|null $external_id
 * @property Sport $sport
 * @property string|null $name
 * @property Carbon $started_at
 * @property int $duration_s
 * @property int|null $distance_m
 * @property int|null $avg_hr
 * @property int|null $np_w
 * @property int|null $best_20min_power_w
 * @property float|null $avg_pace
 * @property float|null $tss
 * @property TssMethod|null $tss_method
 * @property float|null $intensity_factor
 * @property string|null $fit_path
 */
#[Fillable([
    'source', 'external_id', 'sport', 'name', 'started_at', 'duration_s', 'distance_m', 'avg_hr', 'np_w',
    'best_20min_power_w', 'avg_pace', 'tss', 'tss_method', 'intensity_factor', 'fit_path',
])]
class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'source' => ActivitySource::class,
            'sport' => Sport::class,
            'started_at' => 'datetime',
            'duration_s' => 'integer',
            'distance_m' => 'integer',
            'avg_hr' => 'integer',
            'np_w' => 'integer',
            'best_20min_power_w' => 'integer',
            'avg_pace' => 'float',
            'tss' => 'float',
            'tss_method' => TssMethod::class,
            'intensity_factor' => 'float',
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
     * @return HasOne<PlannedWorkout, $this>
     */
    public function plannedWorkout(): HasOne
    {
        return $this->hasOne(PlannedWorkout::class);
    }

    /**
     * @return HasOne<SessionFeedback, $this>
     */
    public function feedback(): HasOne
    {
        return $this->hasOne(SessionFeedback::class);
    }
}
