<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How a session felt, in the athlete's words: overall effort (RPE 1-10),
 * muscles, breathing, energy and mood (1 best to 5 worst), and any pain.
 *
 * @property int $id
 * @property int $athlete_id
 * @property int $activity_id
 * @property int $rpe
 * @property int|null $muscles
 * @property int|null $breathing
 * @property int|null $energy
 * @property int|null $mood
 * @property bool $pain
 * @property string|null $pain_area
 * @property string|null $note
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['rpe', 'muscles', 'breathing', 'energy', 'mood', 'pain', 'pain_area', 'note'])]
class SessionFeedback extends Model
{
    protected $table = 'session_feedback';

    protected function casts(): array
    {
        return [
            'rpe' => 'integer',
            'muscles' => 'integer',
            'breathing' => 'integer',
            'energy' => 'integer',
            'mood' => 'integer',
            'pain' => 'boolean',
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
}
