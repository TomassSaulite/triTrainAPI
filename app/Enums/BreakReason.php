<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why an athlete cannot train for a few days.
 */
enum BreakReason: string
{
    case Sick = 'sick';
    case Injured = 'injured';
    case Away = 'away';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Sick => 'Sick',
            self::Injured => 'Injured',
            self::Away => 'Away',
            self::Other => 'No training',
        };
    }

    /**
     * Whether the body needs easing back in afterwards, not just the diary.
     */
    public function needsEasingBack(): bool
    {
        return $this === self::Sick || $this === self::Injured;
    }
}
