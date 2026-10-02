<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PhaseType;
use App\Enums\RaceDistance;
use App\Enums\Sport;
use App\Enums\WorkoutKind;
use Database\Factories\WorkoutTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reusable workout the generator picks from. Rows without an athlete form
 * the shared system library; athletes can add their own to customise the coach.
 *
 * @property int $id
 * @property int|null $athlete_id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property Sport $sport
 * @property WorkoutKind $kind
 * @property list<value-of<PhaseType>> $phases
 * @property list<value-of<RaceDistance>>|null $distances
 * @property int $min_s
 * @property int $max_s
 * @property float $intensity_factor
 * @property array<string, mixed> $structure
 * @property bool $is_active
 */
#[Fillable(['slug', 'name', 'description', 'sport', 'kind', 'phases', 'distances', 'min_s', 'max_s', 'intensity_factor', 'structure', 'is_active'])]
class WorkoutTemplate extends Model
{
    /** @use HasFactory<WorkoutTemplateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sport' => Sport::class,
            'kind' => WorkoutKind::class,
            'phases' => 'array',
            'distances' => 'array',
            'min_s' => 'integer',
            'max_s' => 'integer',
            'intensity_factor' => 'float',
            'structure' => 'array',
            'is_active' => 'boolean',
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
     * Templates an athlete may use: the system library plus their own.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function availableTo(Builder $query, Athlete $athlete): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('athlete_id')->orWhere('athlete_id', $athlete->id));
    }

    public function isSystem(): bool
    {
        return $this->athlete_id === null;
    }
}
