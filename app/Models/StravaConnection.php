<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $athlete_id
 * @property int $strava_athlete_id
 * @property string $access_token
 * @property string $refresh_token
 * @property Carbon $expires_at
 * @property string|null $scope
 */
#[Fillable(['strava_athlete_id', 'access_token', 'refresh_token', 'expires_at', 'scope'])]
#[Hidden(['access_token', 'refresh_token'])]
class StravaConnection extends Model
{
    protected function casts(): array
    {
        return [
            'strava_athlete_id' => 'integer',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Athlete, $this>
     */
    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function tokenExpiresSoon(): bool
    {
        return $this->expires_at->isBefore(now()->addMinutes(5));
    }
}
