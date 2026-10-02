<?php

declare(strict_types=1);

namespace App\Engine\Adaptation;

use App\Enums\Sport;
use DateTimeImmutable;

/**
 * How the athlete said a session felt. Ratings run from 1 (best) to 5
 * (worst); RPE from 1 to 10.
 */
final readonly class FeelRecord
{
    public function __construct(
        public int $id,
        public DateTimeImmutable $date,
        public Sport $sport,
        public int $rpe,
        public ?int $muscles = null,
        public ?int $breathing = null,
        public ?int $energy = null,
        public ?int $mood = null,
        public bool $pain = false,
        public ?string $painArea = null,
    ) {}

    /**
     * How worn out the athlete felt: the worse of legs and energy, or null
     * when neither was rated.
     */
    public function wornOut(): ?int
    {
        $ratings = array_filter([$this->muscles, $this->energy], fn (?int $r) => $r !== null);

        return $ratings === [] ? null : max($ratings);
    }
}
