<?php

declare(strict_types=1);

namespace App\Engine\Planning;

use App\Enums\PhaseType;
use App\Enums\RaceDistance;
use App\Enums\Sport;
use App\Enums\WorkoutKind;

/**
 * The catalogue the generator fills slots from. Only templates suiting the
 * race distance are considered, and distance-specific ones beat generic ones.
 * Lookups relax step by step (other phase, then an easier kind) so a sparse
 * library still yields a plan, and the athlete's own templates win over the
 * system ones.
 */
final class TemplateLibrary
{
    /**
     * Templates whose slug starts with this are only used when asked for by
     * slug, e.g. the run half of a brick.
     */
    public const string RESERVED_PREFIX = 'brick-';

    /**
     * When no template of a kind exists, the next kind tried.
     */
    private const array FALLBACK_KIND = [
        'vo2' => 'threshold',
        'race_pace' => 'threshold',
        'threshold' => 'tempo',
        'tempo' => 'endurance',
        'long' => 'endurance',
        'technique' => 'endurance',
        'recovery' => 'endurance',
    ];

    /**
     * The race distance of the lookup in progress.
     */
    private ?RaceDistance $distance = null;

    /**
     * @param  list<Template>  $templates
     */
    public function __construct(
        private readonly array $templates,
    ) {}

    /**
     * Picks a template for a slot. $rotation spreads choices across weeks so a
     * plan does not repeat the same session every time.
     */
    public function pick(Sport $sport, WorkoutKind $kind, PhaseType $phase, int $rotation = 0, ?string $preferredSlug = null, ?RaceDistance $distance = null): ?Template
    {
        $this->distance = $distance;

        if ($preferredSlug !== null) {
            $preferred = $this->matching($sport, fn (Template $t) => $t->slug === $preferredSlug);

            if ($preferred !== []) {
                return $this->choose($preferred, $rotation);
            }
        }

        for ($candidate = $kind; $candidate !== null; $candidate = $this->fallback($candidate)) {
            $inPhase = $this->matching($sport, fn (Template $t) => $t->kind === $candidate && $t->supports($phase) && ! $this->isReserved($t));

            if ($inPhase !== []) {
                return $this->choose($inPhase, $rotation);
            }

            $anyPhase = $this->matching($sport, fn (Template $t) => $t->kind === $candidate && ! $this->isReserved($t));

            if ($anyPhase !== []) {
                return $this->choose($anyPhase, $rotation);
            }
        }

        return null;
    }

    /**
     * Every template that could replace a session: same sport, a kind from the
     * same family, suiting the race distance, and not a taper-only session
     * outside the taper. The athlete's own come first, then those meant for
     * the phase.
     *
     * @return list<Template>
     */
    public function alternatives(Sport $sport, WorkoutKind $kind, ?RaceDistance $distance = null, ?PhaseType $phase = null): array
    {
        $family = $kind->family();
        $found = array_values(array_filter(
            $this->templates,
            fn (Template $t) => $t->sport === $sport
                && in_array($t->kind, $family, true)
                && $t->suits($distance)
                && ($phase === null || $phase === PhaseType::Taper || ! $t->isTaperOnly())
                && ! $this->isReserved($t),
        ));

        $rank = fn (Template $t) => [! $t->isPersonal, $phase !== null && ! $t->supports($phase), $t->kind !== $kind, $t->name];
        usort($found, fn (Template $a, Template $b) => $rank($a) <=> $rank($b));

        return $found;
    }

    public function isEmpty(): bool
    {
        return $this->templates === [];
    }

    /**
     * @param  callable(Template): bool  $predicate
     * @return list<Template>
     */
    private function matching(Sport $sport, callable $predicate): array
    {
        $found = array_values(array_filter(
            $this->templates,
            fn (Template $t) => $t->sport === $sport && $t->suits($this->distance) && $predicate($t),
        ));

        $preferences = [fn (Template $t) => $t->isPersonal];

        if ($this->distance !== null) {
            $preferences[] = fn (Template $t) => $t->isDistanceSpecific();
        }

        foreach ($preferences as $preferred) {
            $narrowed = array_values(array_filter($found, $preferred));
            $found = $narrowed !== [] ? $narrowed : $found;
        }

        return $found;
    }

    /**
     * @param  non-empty-list<Template>  $candidates
     */
    private function choose(array $candidates, int $rotation): Template
    {
        usort($candidates, fn (Template $a, Template $b) => strcmp($a->slug, $b->slug));

        return $candidates[$rotation % count($candidates)];
    }

    private function isReserved(Template $template): bool
    {
        return str_starts_with($template->slug, self::RESERVED_PREFIX);
    }

    private function fallback(WorkoutKind $kind): ?WorkoutKind
    {
        $next = self::FALLBACK_KIND[$kind->value] ?? null;

        return $next === null ? null : WorkoutKind::from($next);
    }
}
