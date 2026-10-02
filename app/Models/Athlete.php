<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsCoachPreferences;
use App\Enums\Experience;
use App\Enums\PlanStatus;
use App\Enums\Sport;
use Carbon\CarbonImmutable;
use Database\Factories\AthleteFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $user_id
 * @property string $timezone
 * @property string|null $calendar_token
 * @property int|null $birth_year
 * @property float|null $weight_kg
 * @property int|null $max_hr
 * @property Experience $experience
 * @property float $weekly_hours
 * @property Sport|null $weakest_sport
 * @property \App\Engine\Planning\CoachPreferences $prefs
 */
#[Fillable(['timezone', 'birth_year', 'weight_kg', 'max_hr', 'experience', 'weekly_hours', 'weakest_sport', 'prefs'])]
class Athlete extends Model
{
    /** @use HasFactory<AthleteFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'birth_year' => 'integer',
            'weight_kg' => 'float',
            'max_hr' => 'integer',
            'experience' => Experience::class,
            'weekly_hours' => 'float',
            'weakest_sport' => Sport::class,
            'prefs' => AsCoachPreferences::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Threshold, $this>
     */
    public function thresholds(): HasMany
    {
        return $this->hasMany(Threshold::class);
    }

    /**
     * @return HasMany<Race, $this>
     */
    public function races(): HasMany
    {
        return $this->hasMany(Race::class);
    }

    /**
     * @return HasMany<Plan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    /**
     * @return HasOne<Plan, $this>
     */
    public function activePlan(): HasOne
    {
        return $this->hasOne(Plan::class)
            ->where('status', PlanStatus::Active)
            ->latestOfMany();
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * @return HasMany<DailyLoad, $this>
     */
    public function dailyLoads(): HasMany
    {
        return $this->hasMany(DailyLoad::class);
    }

    /**
     * @return HasMany<AvailabilityOverride, $this>
     */
    public function availabilityOverrides(): HasMany
    {
        return $this->hasMany(AvailabilityOverride::class);
    }

    /**
     * @return HasMany<WorkoutTemplate, $this>
     */
    public function workoutTemplates(): HasMany
    {
        return $this->hasMany(WorkoutTemplate::class);
    }

    /**
     * @return HasMany<ThresholdSuggestion, $this>
     */
    public function thresholdSuggestions(): HasMany
    {
        return $this->hasMany(ThresholdSuggestion::class);
    }

    /**
     * @return HasMany<SessionFeedback, $this>
     */
    public function sessionFeedback(): HasMany
    {
        return $this->hasMany(SessionFeedback::class);
    }

    /**
     * @return HasOne<StravaConnection, $this>
     */
    public function stravaConnection(): HasOne
    {
        return $this->hasOne(StravaConnection::class);
    }

    public function ageOn(DateTimeInterface $date): ?int
    {
        return $this->birth_year === null ? null : (int) $date->format('Y') - $this->birth_year;
    }

    /**
     * Today's date where the athlete is. Plan dates are plain calendar dates
     * (midnight in the app timezone), so this is comparable with them.
     */
    public function today(): CarbonImmutable
    {
        return $this->localDate(CarbonImmutable::now());
    }

    /**
     * The athlete's calendar date at a moment in time, e.g. when an activity started.
     */
    public function localDate(DateTimeInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::instance($moment)->setTimezone($this->timezone)->toDateString());
    }

    /**
     * The UTC instants spanning the athlete's days $from to $to inclusive, for
     * querying timestamp columns such as activities.started_at.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function utcBounds(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $local = fn (DateTimeInterface $d) => CarbonImmutable::parse($d->format('Y-m-d'), $this->timezone);

        return [
            $local($from)->startOfDay()->utc(),
            $local($to)->endOfDay()->utc(),
        ];
    }
}
