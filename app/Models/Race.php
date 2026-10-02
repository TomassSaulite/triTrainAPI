<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanStatus;
use App\Enums\RaceDistance;
use App\Enums\RacePriority;
use Database\Factories\RaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $athlete_id
 * @property string $name
 * @property RaceDistance $distance
 * @property Carbon $date
 * @property RacePriority $priority
 */
#[Fillable(['name', 'distance', 'date', 'priority'])]
class Race extends Model
{
    /** @use HasFactory<RaceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'distance' => RaceDistance::class,
            'date' => 'date',
            'priority' => RacePriority::class,
        ];
    }

    /**
     * Plans are training history: deleting the race they were built for
     * archives them (their race_id is then cleared) rather than deleting them.
     * So an active plan always has its race.
     */
    protected static function booted(): void
    {
        static::deleting(function (Race $race): void {
            $race->plans()->where('status', PlanStatus::Active)->update(['status' => PlanStatus::Archived]);
        });
    }

    /**
     * @return BelongsTo<Athlete, $this>
     */
    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    /**
     * @return HasMany<Plan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }
}
