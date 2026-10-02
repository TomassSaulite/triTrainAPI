<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PhaseType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $plan_id
 * @property PhaseType $type
 * @property Carbon $start_date
 * @property Carbon $end_date
 */
#[Fillable(['type', 'start_date', 'end_date'])]
#[Table(timestamps: false)]
class PlanPhase extends Model
{
    protected function casts(): array
    {
        return [
            'type' => PhaseType::class,
            'start_date' => 'date',
            'end_date' => 'date',
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
     * @return HasMany<PlanWeek, $this>
     */
    public function weeks(): HasMany
    {
        return $this->hasMany(PlanWeek::class);
    }
}
