<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkoutKind: string
{
    case Endurance = 'endurance';
    case Tempo = 'tempo';
    case Threshold = 'threshold';
    case Vo2 = 'vo2';
    case RacePace = 'race_pace';
    case Long = 'long';
    case Recovery = 'recovery';
    case Technique = 'technique';

    public function isQuality(): bool
    {
        return in_array($this, [self::Tempo, self::Threshold, self::Vo2, self::RacePace], true);
    }

    /**
     * Kinds that can stand in for each other when an athlete swaps a session:
     * hard sessions for hard ones, long for long, easy for easy.
     *
     * @return list<self>
     */
    public function family(): array
    {
        return match (true) {
            $this->isQuality() => [self::Tempo, self::Threshold, self::Vo2, self::RacePace],
            $this === self::Long => [self::Long, self::Endurance],
            default => [self::Endurance, self::Recovery, self::Technique],
        };
    }

    /**
     * Lower numbers are dropped first when a week has to shed a session.
     */
    public function priority(): int
    {
        return match ($this) {
            self::Recovery => 0,
            self::Technique => 1,
            self::Endurance => 2,
            self::Tempo => 3,
            self::Long => 4,
            self::Threshold, self::Vo2, self::RacePace => 5,
        };
    }
}
