<?php

declare(strict_types=1);

namespace App\Enums;

enum Sport: string
{
    case Swim = 'swim';
    case Bike = 'bike';
    case Run = 'run';
    case Brick = 'brick';
    case Strength = 'strength';

    /**
     * The three disciplines a triathlon plan splits its volume across.
     *
     * @return list<self>
     */
    public static function disciplines(): array
    {
        return [self::Swim, self::Bike, self::Run];
    }

    public function isDiscipline(): bool
    {
        return in_array($this, self::disciplines(), true);
    }

    /**
     * The intensity target workouts in this sport are written against by default.
     */
    public function primaryTargetMetric(): TargetMetric
    {
        return match ($this) {
            self::Swim => TargetMetric::CssPct,
            self::Bike => TargetMetric::FtpPct,
            self::Run => TargetMetric::ThresholdPacePct,
            self::Brick, self::Strength => TargetMetric::LthrPct,
        };
    }
}
