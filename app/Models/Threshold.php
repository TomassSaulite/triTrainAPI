<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Sport;
use App\Enums\ThresholdMetric;
use App\Enums\ThresholdSource;
use Database\Factories\ThresholdFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A threshold test result. Rows are never edited in place: a new test adds a
 * new row and the newest row (by tested_at) wins.
 *
 * @property int $id
 * @property int $athlete_id
 * @property Sport|null $sport
 * @property ThresholdMetric $metric
 * @property float $value
 * @property Carbon $tested_at
 * @property ThresholdSource $source
 */
#[Fillable(['sport', 'metric', 'value', 'tested_at', 'source'])]
class Threshold extends Model
{
    /** @use HasFactory<ThresholdFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sport' => Sport::class,
            'metric' => ThresholdMetric::class,
            'value' => 'float',
            'tested_at' => 'date',
            'source' => ThresholdSource::class,
        ];
    }

    /**
     * @return BelongsTo<Athlete, $this>
     */
    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }
}
