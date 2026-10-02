<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RevisionReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The "what changed and why" log: one row per plan version.
 *
 * @property int $id
 * @property int $plan_id
 * @property int $version
 * @property RevisionReason $reason
 * @property string $summary
 * @property list<array<string, mixed>> $changes
 * @property Carbon $created_at
 */
#[Fillable(['version', 'reason', 'summary', 'changes'])]
class PlanRevision extends Model
{
    public const null UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'reason' => RevisionReason::class,
            'changes' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
