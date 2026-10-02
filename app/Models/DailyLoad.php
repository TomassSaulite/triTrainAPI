<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Derived data: rebuilt from activities whenever history changes.
 *
 * @property int $id
 * @property int $athlete_id
 * @property Carbon $date
 * @property float $tss
 * @property float $ctl
 * @property float $atl
 * @property float $tsb
 */
#[Fillable(['athlete_id', 'date', 'tss', 'ctl', 'atl', 'tsb'])]
#[Table(name: 'daily_load', timestamps: false)]
class DailyLoad extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'tss' => 'float',
            'ctl' => 'float',
            'atl' => 'float',
            'tsb' => 'float',
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
