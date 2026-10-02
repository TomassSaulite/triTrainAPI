<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| System workout library
|--------------------------------------------------------------------------
|
| The templates the plan generator picks from. Targets are fractions of the
| athlete's thresholds (pace targets are fractions of threshold speed). Each
| template is written at a nominal length; the generator scales its main set
| to the slot's duration within [min_s, max_s].
|
| Templates with `distances` are only used for those race distances, and are
| preferred over generic ones there. Race pace differs a lot by distance:
| close to threshold for sprint/olympic, endurance pace for full distance.
|
*/

$ftp = fn (float $low, float $high) => ['metric' => 'ftp_pct', 'low' => $low, 'high' => $high];
$pace = fn (float $low, float $high) => ['metric' => 'threshold_pace_pct', 'low' => $low, 'high' => $high];
$css = fn (float $low, float $high) => ['metric' => 'css_pct', 'low' => $low, 'high' => $high];

$time = fn (string $type, int $minutes, array $target) => ['type' => $type, 'duration_s' => $minutes * 60, 'target' => $target];
$secs = fn (string $type, int $seconds, array $target) => ['type' => $type, 'duration_s' => $seconds, 'target' => $target];
$dist = fn (string $type, int $meters, array $target) => ['type' => $type, 'distance_m' => $meters, 'target' => $target];
$rest = fn (int $seconds) => ['type' => 'rest', 'duration_s' => $seconds];
$repeat = fn (int $count, array ...$steps) => ['type' => 'repeat', 'count' => $count, 'steps' => $steps];

$all = ['base', 'build', 'peak', 'taper'];
$minutes = fn (int $min, int $max) => ['min_s' => $min * 60, 'max_s' => $max * 60];

return [
    // ---------------------------------------------------------------- Bike
    [
        'slug' => 'bike-endurance', 'name' => 'Endurance ride', 'sport' => 'bike', 'kind' => 'endurance', 'phases' => $all,
        'description' => 'Steady aerobic riding in zone 2.',
        ...$minutes(45, 180),
        'steps' => [$time('warmup', 10, $ftp(0.55, 0.65)), $time('steady', 40, $ftp(0.65, 0.75)), $time('cooldown', 10, $ftp(0.50, 0.60))],
    ],
    [
        'slug' => 'bike-long', 'name' => 'Long ride', 'sport' => 'bike', 'kind' => 'long', 'phases' => $all,
        'description' => 'The week\'s long aerobic ride. Fuel as you would on race day.',
        ...$minutes(90, 330),
        'steps' => [$time('warmup', 15, $ftp(0.55, 0.65)), $time('steady', 120, $ftp(0.65, 0.75)), $time('cooldown', 10, $ftp(0.50, 0.60))],
    ],
    [
        'slug' => 'bike-long-race-efforts', 'name' => 'Long ride with race-pace blocks', 'sport' => 'bike', 'kind' => 'long', 'phases' => ['build', 'peak'], 'distances' => ['half'],
        'description' => 'Long aerobic ride with sustained blocks at half-distance race power.',
        ...$minutes(120, 330),
        'steps' => [
            $time('warmup', 20, $ftp(0.55, 0.65)),
            $repeat(3, $time('interval', 20, $ftp(0.75, 0.82)), $time('steady', 20, $ftp(0.65, 0.72))),
            $time('cooldown', 10, $ftp(0.50, 0.60)),
        ],
    ],
    [
        'slug' => 'bike-recovery', 'name' => 'Recovery spin', 'sport' => 'bike', 'kind' => 'recovery', 'phases' => $all,
        'description' => 'Very easy spinning to promote recovery.',
        ...$minutes(30, 60),
        'steps' => [$time('steady', 40, $ftp(0.45, 0.55))],
    ],
    [
        'slug' => 'bike-tempo', 'name' => 'Tempo intervals', 'sport' => 'bike', 'kind' => 'tempo', 'phases' => ['base', 'build'],
        'description' => 'Sustained tempo efforts to build muscular endurance.',
        ...$minutes(60, 120),
        'steps' => [
            $time('warmup', 15, $ftp(0.55, 0.65)),
            $repeat(3, $time('interval', 12, $ftp(0.76, 0.85)), $time('recovery', 4, $ftp(0.55, 0.65))),
            $time('cooldown', 10, $ftp(0.50, 0.60)),
        ],
    ],
    [
        'slug' => 'bike-sweet-spot', 'name' => 'Sweet spot 3 x 10', 'sport' => 'bike', 'kind' => 'threshold', 'phases' => ['build', 'peak'],
        'description' => 'Classic sweet-spot intervals just below FTP.',
        ...$minutes(60, 120),
        'steps' => [
            $time('warmup', 15, $ftp(0.55, 0.65)),
            $repeat(3, $time('interval', 10, $ftp(0.88, 0.93)), $time('recovery', 5, $ftp(0.50, 0.60))),
            $time('cooldown', 10, $ftp(0.50, 0.60)),
        ],
    ],
    [
        'slug' => 'bike-threshold', 'name' => 'Threshold 4 x 8', 'sport' => 'bike', 'kind' => 'threshold', 'phases' => ['build', 'peak'],
        'description' => 'Intervals at FTP to lift sustainable power.',
        ...$minutes(60, 105),
        'steps' => [
            $time('warmup', 15, $ftp(0.55, 0.65)),
            $repeat(4, $time('interval', 8, $ftp(0.95, 1.02)), $time('recovery', 4, $ftp(0.50, 0.60))),
            $time('cooldown', 10, $ftp(0.50, 0.60)),
        ],
    ],
    [
        'slug' => 'bike-vo2', 'name' => 'VO2max 5 x 3', 'sport' => 'bike', 'kind' => 'vo2', 'phases' => ['build'],
        'description' => 'Short hard efforts above FTP.',
        ...$minutes(55, 90),
        'steps' => [
            $time('warmup', 15, $ftp(0.55, 0.65)),
            $repeat(5, $time('interval', 3, $ftp(1.08, 1.15)), $time('recovery', 3, $ftp(0.50, 0.60))),
            $time('cooldown', 10, $ftp(0.50, 0.60)),
        ],
    ],
    [
        'slug' => 'bike-race-pace', 'name' => 'Half-distance race pace', 'sport' => 'bike', 'kind' => 'race_pace', 'phases' => ['peak', 'taper'], 'distances' => ['half'],
        'description' => 'Long blocks at goal half-distance power (75-82% FTP).',
        ...$minutes(60, 180),
        'steps' => [
            $time('warmup', 15, $ftp(0.55, 0.65)),
            $repeat(3, $time('interval', 20, $ftp(0.75, 0.82)), $time('recovery', 5, $ftp(0.55, 0.65))),
            $time('cooldown', 10, $ftp(0.50, 0.60)),
        ],
    ],
    [
        'slug' => 'bike-race-pace-short', 'name' => 'Sprint/olympic race pace', 'sport' => 'bike', 'kind' => 'race_pace', 'phases' => ['peak', 'taper'], 'distances' => ['sprint', 'olympic'],
        'description' => 'Blocks at short-course race power, just under FTP.',
        ...$minutes(50, 100),
        'steps' => [
            $time('warmup', 15, $ftp(0.55, 0.65)),
            $repeat(3, $time('interval', 10, $ftp(0.88, 0.95)), $time('recovery', 4, $ftp(0.50, 0.60))),
            $time('cooldown', 10, $ftp(0.50, 0.60)),
        ],
    ],
    [
        'slug' => 'bike-race-pace-full', 'name' => 'Full-distance race pace', 'sport' => 'bike', 'kind' => 'race_pace', 'phases' => ['peak', 'taper'], 'distances' => ['full'],
        'description' => 'Long, even blocks at Ironman power. Practise race fuelling.',
        ...$minutes(90, 300),
        'steps' => [
            $time('warmup', 15, $ftp(0.55, 0.65)),
            $repeat(3, $time('interval', 30, $ftp(0.68, 0.75)), $time('recovery', 5, $ftp(0.55, 0.62))),
            $time('cooldown', 10, $ftp(0.50, 0.60)),
        ],
    ],
    [
        'slug' => 'bike-long-race-efforts-full', 'name' => 'Long ride with Ironman-pace blocks', 'sport' => 'bike', 'kind' => 'long', 'phases' => ['build', 'peak'], 'distances' => ['full'],
        'description' => 'Long aerobic ride with extended blocks at full-distance race power.',
        ...$minutes(150, 360),
        'steps' => [
            $time('warmup', 20, $ftp(0.55, 0.65)),
            $repeat(3, $time('interval', 30, $ftp(0.68, 0.75)), $time('steady', 15, $ftp(0.62, 0.68))),
            $time('cooldown', 10, $ftp(0.50, 0.60)),
        ],
    ],
    [
        'slug' => 'bike-openers', 'name' => 'Race openers', 'sport' => 'bike', 'kind' => 'race_pace', 'phases' => ['taper'],
        'description' => 'Short, sharp efforts to feel fresh and ready.',
        ...$minutes(30, 60),
        'steps' => [
            $time('warmup', 15, $ftp(0.55, 0.65)),
            $repeat(3, $time('interval', 1, $ftp(1.00, 1.10)), $time('recovery', 2, $ftp(0.50, 0.60))),
            $time('steady', 10, $ftp(0.75, 0.82)),
            $time('cooldown', 5, $ftp(0.50, 0.60)),
        ],
    ],

    // ----------------------------------------------------------------- Run
    [
        'slug' => 'run-endurance', 'name' => 'Easy run', 'sport' => 'run', 'kind' => 'endurance', 'phases' => $all,
        'description' => 'Conversational aerobic running.',
        ...$minutes(30, 75),
        'steps' => [$time('warmup', 10, $pace(0.70, 0.76)), $time('steady', 30, $pace(0.76, 0.84)), $time('cooldown', 5, $pace(0.65, 0.72))],
    ],
    [
        'slug' => 'run-long', 'name' => 'Long run', 'sport' => 'run', 'kind' => 'long', 'phases' => $all,
        'description' => 'The week\'s long aerobic run.',
        ...$minutes(60, 150),
        'steps' => [$time('warmup', 10, $pace(0.70, 0.76)), $time('steady', 70, $pace(0.74, 0.82)), $time('cooldown', 5, $pace(0.65, 0.72))],
    ],
    [
        'slug' => 'run-long-progression', 'name' => 'Progression long run', 'sport' => 'run', 'kind' => 'long', 'phases' => ['build', 'peak'],
        'description' => 'Long run finishing at half-marathon effort.',
        ...$minutes(70, 150),
        'steps' => [
            $time('warmup', 10, $pace(0.70, 0.76)),
            $time('steady', 60, $pace(0.76, 0.82)),
            $time('interval', 15, $pace(0.86, 0.90)),
            $time('cooldown', 5, $pace(0.65, 0.72)),
        ],
    ],
    [
        'slug' => 'run-recovery', 'name' => 'Recovery jog', 'sport' => 'run', 'kind' => 'recovery', 'phases' => $all,
        'description' => 'Very easy jogging. Walk breaks are fine.',
        ...$minutes(20, 45),
        'steps' => [$time('steady', 30, $pace(0.65, 0.72))],
    ],
    [
        'slug' => 'run-strides', 'name' => 'Easy run with strides', 'sport' => 'run', 'kind' => 'technique', 'phases' => $all,
        'description' => 'Aerobic running with relaxed fast strides for form.',
        ...$minutes(30, 60),
        'steps' => [
            $time('warmup', 15, $pace(0.70, 0.78)),
            $repeat(6, $secs('interval', 20, $pace(1.05, 1.15)), $secs('recovery', 100, $pace(0.65, 0.72))),
            $time('steady', 10, $pace(0.76, 0.84)),
            $time('cooldown', 5, $pace(0.65, 0.72)),
        ],
    ],
    [
        'slug' => 'run-tempo', 'name' => 'Tempo run', 'sport' => 'run', 'kind' => 'tempo', 'phases' => ['base', 'build'],
        'description' => 'Comfortably hard sustained running.',
        ...$minutes(40, 75),
        'steps' => [
            $time('warmup', 15, $pace(0.70, 0.78)),
            $repeat(2, $time('interval', 12, $pace(0.88, 0.92)), $time('recovery', 3, $pace(0.68, 0.74))),
            $time('cooldown', 10, $pace(0.65, 0.72)),
        ],
    ],
    [
        'slug' => 'run-threshold', 'name' => 'Threshold 4 x 6', 'sport' => 'run', 'kind' => 'threshold', 'phases' => ['build', 'peak'],
        'description' => 'Cruise intervals at threshold pace.',
        ...$minutes(45, 75),
        'steps' => [
            $time('warmup', 15, $pace(0.70, 0.78)),
            $repeat(4, $time('interval', 6, $pace(0.98, 1.02)), $time('recovery', 2, $pace(0.68, 0.74))),
            $time('cooldown', 10, $pace(0.65, 0.72)),
        ],
    ],
    [
        'slug' => 'run-vo2', 'name' => 'VO2max 5 x 3', 'sport' => 'run', 'kind' => 'vo2', 'phases' => ['build'],
        'description' => 'Hard 3-minute repeats faster than threshold.',
        ...$minutes(45, 70),
        'steps' => [
            $time('warmup', 15, $pace(0.70, 0.78)),
            $repeat(5, $time('interval', 3, $pace(1.05, 1.10)), $time('recovery', 2, $pace(0.65, 0.70))),
            $time('cooldown', 10, $pace(0.65, 0.72)),
        ],
    ],
    [
        'slug' => 'run-race-pace', 'name' => 'Half-distance race pace', 'sport' => 'run', 'kind' => 'race_pace', 'phases' => ['peak', 'taper'], 'distances' => ['half'],
        'description' => 'Blocks at half-marathon effort, at the slightly lower pace you will hold off the bike.',
        ...$minutes(40, 90),
        'steps' => [
            $time('warmup', 15, $pace(0.70, 0.78)),
            $repeat(3, $time('interval', 10, $pace(0.86, 0.90)), $time('recovery', 3, $pace(0.68, 0.74))),
            $time('cooldown', 10, $pace(0.65, 0.72)),
        ],
    ],
    [
        'slug' => 'run-race-pace-short', 'name' => 'Sprint/olympic race pace', 'sport' => 'run', 'kind' => 'race_pace', 'phases' => ['peak', 'taper'], 'distances' => ['sprint', 'olympic'],
        'description' => 'Repeats at 10 km effort, the pace you will hold off the bike in a short race.',
        ...$minutes(40, 70),
        'steps' => [
            $time('warmup', 15, $pace(0.70, 0.78)),
            $repeat(4, $time('interval', 5, $pace(0.97, 1.02)), $time('recovery', 2, $pace(0.68, 0.74))),
            $time('cooldown', 10, $pace(0.65, 0.72)),
        ],
    ],
    [
        'slug' => 'run-race-pace-full', 'name' => 'Full-distance race pace', 'sport' => 'run', 'kind' => 'race_pace', 'phases' => ['peak', 'taper'], 'distances' => ['full'],
        'description' => 'Sustained running at marathon-off-the-bike effort.',
        ...$minutes(50, 120),
        'steps' => [
            $time('warmup', 15, $pace(0.70, 0.76)),
            $repeat(3, $time('interval', 15, $pace(0.80, 0.85)), $time('recovery', 3, $pace(0.68, 0.74))),
            $time('cooldown', 10, $pace(0.65, 0.72)),
        ],
    ],
    [
        'slug' => 'run-openers', 'name' => 'Race openers', 'sport' => 'run', 'kind' => 'race_pace', 'phases' => ['taper'],
        'description' => 'Easy running with a few short pick-ups to wake the legs up.',
        ...$minutes(20, 45),
        'steps' => [
            $time('warmup', 15, $pace(0.70, 0.78)),
            $repeat(4, $time('interval', 1, $pace(0.95, 1.02)), $time('recovery', 2, $pace(0.65, 0.72))),
            $time('cooldown', 5, $pace(0.65, 0.72)),
        ],
    ],
    [
        'slug' => 'brick-run', 'name' => 'Run off the bike', 'sport' => 'run', 'kind' => 'endurance', 'phases' => ['build', 'peak', 'taper'],
        'description' => 'Straight off the bike: settle into race rhythm.',
        ...$minutes(10, 45),
        'steps' => [$time('steady', 20, $pace(0.82, 0.88))],
    ],

    // ---------------------------------------------------------------- Swim
    [
        'slug' => 'swim-endurance', 'name' => 'Aerobic swim', 'sport' => 'swim', 'kind' => 'endurance', 'phases' => $all,
        'description' => 'Steady aerobic sets.',
        ...$minutes(30, 75),
        'steps' => [
            $dist('warmup', 300, $css(0.75, 0.85)),
            $repeat(3, $dist('steady', 300, $css(0.82, 0.88)), $rest(20)),
            $dist('cooldown', 200, $css(0.70, 0.80)),
        ],
    ],
    [
        'slug' => 'swim-long', 'name' => 'Endurance set', 'sport' => 'swim', 'kind' => 'long', 'phases' => $all,
        'description' => 'The week\'s long swim: sustained aerobic repeats.',
        ...$minutes(45, 90),
        'steps' => [
            $dist('warmup', 400, $css(0.75, 0.85)),
            $repeat(5, $dist('steady', 400, $css(0.85, 0.90)), $rest(30)),
            $dist('cooldown', 200, $css(0.70, 0.80)),
        ],
    ],
    [
        'slug' => 'swim-technique', 'name' => 'Technique and drills', 'sport' => 'swim', 'kind' => 'technique', 'phases' => $all,
        'description' => 'Drill-focused swim to improve efficiency.',
        ...$minutes(30, 60),
        'steps' => [
            $dist('warmup', 300, $css(0.75, 0.85)),
            $repeat(8, $dist('interval', 50, $css(0.70, 0.80)), $rest(20)),
            $repeat(4, $dist('interval', 100, $css(0.85, 0.90)), $rest(20)),
            $dist('cooldown', 200, $css(0.70, 0.80)),
        ],
    ],
    [
        'slug' => 'swim-recovery', 'name' => 'Easy swim', 'sport' => 'swim', 'kind' => 'recovery', 'phases' => $all,
        'description' => 'Loose, easy swimming.',
        ...$minutes(20, 40),
        'steps' => [$dist('steady', 1000, $css(0.70, 0.80))],
    ],
    [
        'slug' => 'swim-tempo', 'name' => 'Tempo 200s', 'sport' => 'swim', 'kind' => 'tempo', 'phases' => ['base', 'build'],
        'description' => 'Strong, controlled 200s.',
        ...$minutes(35, 70),
        'steps' => [
            $dist('warmup', 400, $css(0.75, 0.85)),
            $repeat(5, $dist('interval', 200, $css(0.90, 0.95)), $rest(20)),
            $dist('cooldown', 200, $css(0.70, 0.80)),
        ],
    ],
    [
        'slug' => 'swim-css', 'name' => 'CSS 100s', 'sport' => 'swim', 'kind' => 'threshold', 'phases' => ['build', 'peak'],
        'description' => 'Repeats at critical swim speed on short rest.',
        ...$minutes(35, 70),
        'steps' => [
            $dist('warmup', 400, $css(0.75, 0.85)),
            $repeat(10, $dist('interval', 100, $css(0.98, 1.02)), $rest(15)),
            $dist('cooldown', 200, $css(0.70, 0.80)),
        ],
    ],
    [
        'slug' => 'swim-speed', 'name' => 'Speed 50s', 'sport' => 'swim', 'kind' => 'vo2', 'phases' => ['build'],
        'description' => 'Fast 50s with full recovery.',
        ...$minutes(30, 60),
        'steps' => [
            $dist('warmup', 400, $css(0.75, 0.85)),
            $repeat(12, $dist('interval', 50, $css(1.05, 1.12)), $rest(30)),
            $dist('cooldown', 300, $css(0.70, 0.80)),
        ],
    ],
    [
        'slug' => 'swim-race-pace', 'name' => 'Race-pace 500s', 'sport' => 'swim', 'kind' => 'race_pace', 'phases' => ['peak', 'taper'],
        'description' => 'Continuous efforts at goal race pace.',
        ...$minutes(35, 70),
        'steps' => [
            $dist('warmup', 400, $css(0.75, 0.85)),
            $repeat(3, $dist('interval', 500, $css(0.92, 0.96)), $rest(30)),
            $dist('cooldown', 200, $css(0.70, 0.80)),
        ],
    ],
];
