<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The parts of a session the athlete rates, each from 1 (best) to 5 (worst).
 */
enum FeelDimension: string
{
    case Muscles = 'muscles';
    case Breathing = 'breathing';
    case Energy = 'energy';
    case Mood = 'mood';

    public const int BEST = 1;

    public const int WORST = 5;

    /**
     * What each rating means, from 1 to 5.
     *
     * @return list<string>
     */
    public function labels(): array
    {
        return match ($this) {
            self::Muscles => ['Fresh', 'Fine', 'Tired', 'Heavy', 'Sore'],
            self::Breathing => ['Easy', 'Steady', 'Working', 'Hard', 'Gasping'],
            self::Energy => ['Great', 'Good', 'OK', 'Low', 'Empty'],
            self::Mood => ['Loved it', 'Good', 'Neutral', 'A struggle', 'Hated it'],
        };
    }
}
