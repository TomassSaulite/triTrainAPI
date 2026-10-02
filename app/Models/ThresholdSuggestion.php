<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SuggestionStatus;
use App\Enums\ThresholdMetric;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A possible new threshold spotted in an activity. The coach never changes
 * thresholds silently: the athlete accepts or dismisses each suggestion.
 *
 * @property int $id
 * @property int $athlete_id
 * @property int|null $activity_id
 * @property ThresholdMetric $metric
 * @property float|null $current_value
 * @property float $suggested_value
 * @property string $rationale
 * @property SuggestionStatus $status
 * @property Carbon|null $resolved_at
 */
#[Fillable(['activity_id', 'metric', 'current_value', 'suggested_value', 'rationale', 'status', 'resolved_at'])]
class ThresholdSuggestion extends Model
{
    protected function casts(): array
    {
        return [
            'metric' => ThresholdMetric::class,
            'current_value' => 'float',
            'suggested_value' => 'float',
            'status' => SuggestionStatus::class,
            'resolved_at' => 'datetime',
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
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function isPending(): bool
    {
        return $this->status === SuggestionStatus::Pending;
    }
}
