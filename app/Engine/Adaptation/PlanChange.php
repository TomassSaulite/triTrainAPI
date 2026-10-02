<?php

declare(strict_types=1);

namespace App\Engine\Adaptation;

use DateTimeImmutable;

/**
 * One adjustment the adaptation loop wants made, with the reason the athlete
 * will see in the plan's "what changed" log.
 */
final readonly class PlanChange
{
    /**
     * @param  string  $rule  the rule that asked for it
     * @param  string  $key  identifies the decision so it is only made once
     */
    public function __construct(
        public ChangeType $type,
        public string $rule,
        public string $key,
        public string $reason,
        public ?int $sessionId = null,
        public ?DateTimeImmutable $date = null,
        public ?float $factor = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type->value,
            'rule' => $this->rule,
            'key' => $this->key,
            'reason' => $this->reason,
            'workout_id' => $this->sessionId,
            'date' => $this->date?->format('Y-m-d'),
            'factor' => $this->factor,
        ], fn ($v) => $v !== null);
    }
}
