<?php

declare(strict_types=1);

namespace Tests\Unit\Engine\Planning;

use App\Engine\Planning\Template;
use App\Engine\Planning\TemplateLibrary;
use App\Engine\Structure\WorkoutStructure;
use App\Enums\PhaseType;
use App\Enums\RaceDistance;
use App\Enums\Sport;
use App\Enums\WorkoutKind;
use PHPUnit\Framework\TestCase;

class TemplateLibraryTest extends TestCase
{
    private static function template(string $slug, Sport $sport, WorkoutKind $kind, array $phases, bool $personal = false, ?array $distances = null): Template
    {
        return new Template(
            id: null,
            slug: $slug,
            name: $slug,
            sport: $sport,
            kind: $kind,
            phases: $phases,
            minSeconds: 1800,
            maxSeconds: 7200,
            structure: WorkoutStructure::fromArray(['steps' => [
                ['type' => 'steady', 'duration_s' => 3600, 'target' => ['metric' => 'ftp_pct', 'low' => 0.6, 'high' => 0.7]],
            ]]),
            isPersonal: $personal,
            distances: $distances,
        );
    }

    public function test_it_picks_an_exact_match(): void
    {
        $library = new TemplateLibrary([
            self::template('a', Sport::Bike, WorkoutKind::Threshold, [PhaseType::Build]),
            self::template('b', Sport::Bike, WorkoutKind::Endurance, [PhaseType::Build]),
        ]);

        $this->assertSame('a', $library->pick(Sport::Bike, WorkoutKind::Threshold, PhaseType::Build)?->slug);
    }

    public function test_it_relaxes_the_phase_before_the_kind(): void
    {
        $library = new TemplateLibrary([
            self::template('threshold-peak', Sport::Bike, WorkoutKind::Threshold, [PhaseType::Peak]),
            self::template('tempo-build', Sport::Bike, WorkoutKind::Tempo, [PhaseType::Build]),
        ]);

        $this->assertSame('threshold-peak', $library->pick(Sport::Bike, WorkoutKind::Threshold, PhaseType::Build)?->slug);
    }

    public function test_it_falls_back_to_an_easier_kind(): void
    {
        $library = new TemplateLibrary([self::template('tempo', Sport::Run, WorkoutKind::Tempo, [PhaseType::Build])]);

        $this->assertSame('tempo', $library->pick(Sport::Run, WorkoutKind::Vo2, PhaseType::Build)?->slug);
    }

    public function test_it_never_crosses_sports(): void
    {
        $library = new TemplateLibrary([self::template('ride', Sport::Bike, WorkoutKind::Endurance, [PhaseType::Base])]);

        $this->assertNull($library->pick(Sport::Swim, WorkoutKind::Endurance, PhaseType::Base));
    }

    public function test_personal_templates_win(): void
    {
        $library = new TemplateLibrary([
            self::template('system', Sport::Bike, WorkoutKind::Long, [PhaseType::Base]),
            self::template('mine', Sport::Bike, WorkoutKind::Long, [PhaseType::Base], personal: true),
        ]);

        $this->assertSame('mine', $library->pick(Sport::Bike, WorkoutKind::Long, PhaseType::Base)?->slug);
    }

    public function test_rotation_cycles_through_candidates(): void
    {
        $library = new TemplateLibrary([
            self::template('b', Sport::Bike, WorkoutKind::Threshold, [PhaseType::Build]),
            self::template('a', Sport::Bike, WorkoutKind::Threshold, [PhaseType::Build]),
        ]);

        $picks = array_map(fn (int $i) => $library->pick(Sport::Bike, WorkoutKind::Threshold, PhaseType::Build, $i)?->slug, [0, 1, 2]);

        $this->assertSame(['a', 'b', 'a'], $picks);
    }

    public function test_a_preferred_slug_is_used_when_present(): void
    {
        $library = new TemplateLibrary([
            self::template('run-endurance', Sport::Run, WorkoutKind::Endurance, [PhaseType::Build]),
            self::template('brick-run', Sport::Run, WorkoutKind::Endurance, [PhaseType::Build]),
        ]);

        $this->assertSame('brick-run', $library->pick(Sport::Run, WorkoutKind::Endurance, PhaseType::Build, preferredSlug: 'brick-run')?->slug);
    }

    public function test_reserved_templates_are_only_used_when_asked_for(): void
    {
        $library = new TemplateLibrary([self::template('brick-run', Sport::Run, WorkoutKind::Endurance, [PhaseType::Build])]);

        $this->assertNull($library->pick(Sport::Run, WorkoutKind::Endurance, PhaseType::Build));
    }

    public function test_distance_specific_templates_win_for_their_distance_and_are_hidden_otherwise(): void
    {
        $library = new TemplateLibrary([
            self::template('generic', Sport::Bike, WorkoutKind::RacePace, [PhaseType::Peak]),
            self::template('full', Sport::Bike, WorkoutKind::RacePace, [PhaseType::Peak], distances: [RaceDistance::Full]),
        ]);

        $this->assertSame('full', $library->pick(Sport::Bike, WorkoutKind::RacePace, PhaseType::Peak, distance: RaceDistance::Full)?->slug);
        $this->assertSame('generic', $library->pick(Sport::Bike, WorkoutKind::RacePace, PhaseType::Peak, distance: RaceDistance::Sprint)?->slug);
        // Without a distance every template is a candidate.
        $this->assertEqualsCanonicalizing(['full', 'generic'], [
            $library->pick(Sport::Bike, WorkoutKind::RacePace, PhaseType::Peak, 0)?->slug,
            $library->pick(Sport::Bike, WorkoutKind::RacePace, PhaseType::Peak, 1)?->slug,
        ]);
    }

    public function test_alternatives_keep_to_the_family_phase_and_distance(): void
    {
        $library = new TemplateLibrary([
            self::template('tempo', Sport::Bike, WorkoutKind::Tempo, [PhaseType::Base]),
            self::template('threshold', Sport::Bike, WorkoutKind::Threshold, [PhaseType::Build]),
            self::template('openers', Sport::Bike, WorkoutKind::RacePace, [PhaseType::Taper]),
            self::template('easy', Sport::Bike, WorkoutKind::Endurance, [PhaseType::Base]),
            self::template('mine', Sport::Bike, WorkoutKind::Vo2, [PhaseType::Base], personal: true),
            self::template('full', Sport::Bike, WorkoutKind::Tempo, [PhaseType::Base], distances: [RaceDistance::Full]),
        ]);

        $slugs = array_map(fn ($t) => $t->slug, $library->alternatives(Sport::Bike, WorkoutKind::Tempo, RaceDistance::Half, PhaseType::Base));

        $this->assertSame(['mine', 'tempo', 'threshold'], $slugs);
        $this->assertContains('openers', array_map(fn ($t) => $t->slug, $library->alternatives(Sport::Bike, WorkoutKind::Tempo, null, PhaseType::Taper)));
    }
}
