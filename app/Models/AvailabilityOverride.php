<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $athlete_id
 * @property Carbon $date
 * @property int $available_minutes
 * @property string|null $note
 * @property bool $easy_only
 */
#[Fillable(['date', 'available_minutes', 'note', 'easy_only'])]
class AvailabilityOverride extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'available_minutes' => 'integer',
            'easy_only' => 'boolean',
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
