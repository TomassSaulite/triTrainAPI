<?php

declare(strict_types=1);

namespace App\Engine\Planning;

use App\Enums\PhaseType;
use DateTimeImmutable;

/**
 * Builds a periodized plan working backwards from race day: fitness target,
 * phases, weekly load, then sessions for every week.
 *
 * Pure: data in, data out. Persisting the result is someone else's job, and
 * re-planning is simply running it again from today over the unstarted weeks.
 */
final class PlanGenerator
{
    /**
     * Stored with every plan so old plans can be told apart after the
     * heuristics change.
     */
    public const string VERSION = '1.0.0';

    public function __construct(
        private readonly PhasePlanner $phases = new PhasePlanner,
        private readonly LoadPlanner $load = new LoadPlanner,
        private readonly SportSplitter $splitter = new SportSplitter,
        private readonly WeekBuilder $weeks = new WeekBuilder,
    ) {}

    public function generate(PlanRequest $request, TemplateLibrary $library): GeneratedPlan
    {
        $firstMonday = self::monday($request->startDate);
        $raceMonday = self::monday($request->raceDate);
        $weekCount = intdiv((int) $firstMonday->diff($raceMonday)->days, 7) + 1;

        // Phases are laid out over the whole plan; a re-plan only rebuilds its tail.
        $anchorMonday = self::monday($request->phaseAnchor());
        $skippedWeeks = intdiv((int) $anchorMonday->diff($firstMonday)->days, 7);
        $daysBeforeRace = (int) $request->raceDate->format('N') - 1;
        $allPhases = $this->phases->sequence($skippedWeeks + $weekCount, $request->distance, $daysBeforeRace);
        $phases = array_slice($allPhases, $skippedWeeks);
        $shares = $this->trainableShares($request, $firstMonday, $weekCount);
        $hours = $this->availableHours($request, $firstMonday, $shares);

        $mondays = array_map(fn (int $i) => $firstMonday->modify("+{$i} weeks"), array_keys($phases));
        $secondaryRaces = array_map($request->secondaryRaceInWeek(...), $mondays);
        $recoveries = array_map($request->recoveringFrom(...), $mondays);
        $weekFactors = array_map(
            fn (?SecondaryRace $race, ?SecondaryRace $recovering) => ($race?->weekLoadFactor() ?? 1.0) * ($recovering?->distance->recoveryWeekLoad() ?? 1.0),
            $secondaryRaces,
            $recoveries,
        );

        $loadPlan = $this->load->plan($request, $phases, $hours, $shares, $daysBeforeRace, $weekFactors);
        $sportShare = $this->splitter->shares($request->distance, $request->preferences, $request->weakestSport);

        $warnings = $loadPlan->warnings;

        if ($library->isEmpty()) {
            $warnings[] = 'The workout library is empty, so every session is a plain steady effort.';
        }

        $weeks = [];
        $firstBuild = array_search(PhaseType::Build, $allPhases, true);

        foreach ($phases as $i => $phase) {
            $planWeek = $skippedWeeks + $i;
            $context = new WeekContext(
                index: $planWeek,
                monday: $firstMonday->modify("+{$i} weeks"),
                phase: $phase,
                weekInPhase: $planWeek - (int) array_search($phase, $allPhases, true),
                weeksIntoBuild: $firstBuild === false || $planWeek < $firstBuild ? null : $planWeek - $firstBuild,
                load: $loadPlan->weeks[$i],
                availableHours: $hours[$i],
                isRaceWeek: $i === $weekCount - 1,
                sportShare: $this->weekShare($sportShare, $request->leadInRace($mondays[$i])),
                secondaryRace: $secondaryRaces[$i],
                recoveringFrom: $recoveries[$i],
                leadInFor: $request->leadInRace($mondays[$i]),
            );

            $weeks[] = $this->weeks->build($context, $request, $library);
            array_push($warnings, ...$this->weeks->warnings());
        }

        return new GeneratedPlan(
            startingCtl: $loadPlan->startingCtl,
            targetCtl: $loadPlan->targetCtl,
            projectedRaceDayTsb: $loadPlan->projectedRaceDayTsb,
            phases: $this->phaseDrafts($weeks, $request),
            weeks: $weeks,
            warnings: array_values(array_unique($warnings)),
        );
    }

    /**
     * In the lead-in to a single-sport B race (a spring marathon, say), time
     * moves toward that sport so its long session and volume grow.
     *
     * @param  array<value-of<\App\Enums\Sport>, float>  $shares
     * @return array<value-of<\App\Enums\Sport>, float>
     */
    private function weekShare(array $shares, ?SecondaryRace $leadIn): array
    {
        $sport = $leadIn?->distance->sport();

        return $sport === null ? $shares : $this->splitter->shift($shares, $sport, $leadIn->distance->leadInShift());
    }

    public static function monday(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTime(0, 0)->modify('-'.((int) $date->format('N') - 1).' days');
    }

    /**
     * Share of each week that falls inside the plan; only the first week can be
     * partial, when the plan starts mid-week.
     *
     * @return list<float>
     */
    private function trainableShares(PlanRequest $request, DateTimeImmutable $firstMonday, int $weekCount): array
    {
        $shares = array_fill(0, $weekCount, 1.0);
        $daysLeftInFirstWeek = 8 - (int) $request->startDate->format('N');
        $shares[0] = $weekCount === 1 ? 1.0 : $daysLeftInFirstWeek / 7;

        return $shares;
    }

    /**
     * Weekly hours, scaled for a partial first week and reduced by
     * availability overrides (travel, work trips).
     *
     * @param  list<float>  $shares
     * @return list<float>
     */
    private function availableHours(PlanRequest $request, DateTimeImmutable $firstMonday, array $shares): array
    {
        $trainingDays = max(1, 7 - count($request->preferences->restDays));
        $typicalDayMinutes = $request->weeklyHours * 60 / $trainingDays;
        $hours = [];

        foreach ($shares as $i => $share) {
            $lostMinutes = 0.0;

            for ($d = 0; $d < 7; $d++) {
                $minutes = $request->availableMinutesOn($firstMonday->modify('+'.($i * 7 + $d).' days'));

                if ($minutes !== null) {
                    $lostMinutes += max(0.0, $typicalDayMinutes - $minutes);
                }
            }

            $hours[] = max(0.0, $request->weeklyHours * $share - $lostMinutes / 60);
        }

        return $hours;
    }

    /**
     * @param  list<WeekDraft>  $weeks
     * @return list<PhaseDraft>
     */
    private function phaseDrafts(array $weeks, PlanRequest $request): array
    {
        $drafts = [];
        $current = null;
        $start = null;
        $end = null;

        foreach ($weeks as $week) {
            if ($week->phase !== $current) {
                if ($current !== null) {
                    $drafts[] = new PhaseDraft($current, $start, $end);
                }

                $current = $week->phase;
                $start = $week === $weeks[0] ? $request->startDate->setTime(0, 0) : $week->startDate;
            }

            $end = $week->startDate->modify('+6 days');
        }

        $drafts[] = new PhaseDraft($current, $start, min($end, $request->raceDate->setTime(0, 0)));

        return $drafts;
    }
}
