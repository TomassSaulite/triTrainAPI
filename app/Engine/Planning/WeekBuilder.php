<?php

declare(strict_types=1);

namespace App\Engine\Planning;

use App\Engine\Planning\Schedule\Day;
use App\Engine\Planning\Schedule\Placement;
use App\Engine\Planning\Schedule\SessionSlot;
use App\Engine\Planning\Schedule\SlotRole;
use App\Engine\Planning\Schedule\WeekScheduler;
use App\Enums\PhaseType;
use App\Enums\Sport;
use App\Enums\WorkoutKind;
use DateTimeImmutable;

/**
 * Fills one week: decides which sessions it needs and how long each should
 * be, places them on days, picks templates, then rescales the sessions until
 * the week lands within 5% of its TSS target.
 */
final class WeekBuilder
{
    /** A session may run this much over its day's time limit before it is swapped for a plainer one. */
    private const float DAY_LIMIT_SLACK = 1.05;

    public const float TSS_TOLERANCE = 0.05;

    private const int MAX_CORRECTIONS = 8;

    /**
     * Share of a discipline's weekly time for its long and quality sessions.
     */
    private const float LONG_SHARE_BIKE = 0.45;

    private const float LONG_SHARE_RUN = 0.35;

    /**
     * Before a running race the long run carries more of the run time.
     */
    private const float LONG_SHARE_RUN_LEAD_IN = 0.45;

    private const float QUALITY_SHARE = 0.27;

    /**
     * Typical length of an easy session, used to decide how many to plan.
     */
    private const array EASY_SESSION_SECONDS = ['bike' => 3600, 'run' => 2700, 'swim' => 2700];

    private const int MAX_EASY_SESSIONS = 2;

    private const int MAX_SWIMS = 4;

    /**
     * Run straight off the bike in brick weeks, as a share of run time.
     */
    private const float BRICK_RUN_SHARE = 0.15;

    private const array BRICK_RUN_BOUNDS = [900, 1800];

    private const string BRICK_RUN_SLUG = 'brick-run';

    /**
     * Race-week sessions stay short whatever the load target says.
     */
    private const int RACE_WEEK_QUALITY_SECONDS = 2700;

    private const int RACE_WEEK_EASY_SECONDS = 2400;

    /**
     * Slack allowed over the week's available hours when correcting load.
     */
    private const float HOURS_SLACK = 1.05;

    /**
     * @var list<string>
     */
    private array $warnings = [];

    public function __construct(
        private readonly WorkoutSizer $sizer = new WorkoutSizer,
        private readonly WeekScheduler $scheduler = new WeekScheduler,
    ) {}

    public function build(WeekContext $week, PlanRequest $request, TemplateLibrary $library): WeekDraft
    {
        $this->warnings = [];
        $days = $this->days($week, $request);
        $poolDays = count(array_filter($days, fn (Day $d) => $d->available && $d->isPoolDay));

        $slots = $this->slots($week, $request, $poolDays);
        $schedule = $this->scheduler->schedule($days, $slots, $request->preferences);

        // A partial first week or race week is short by design; only full weeks are worth flagging.
        $isFullWeek = $week->monday >= $request->startDate && ! $week->isRaceWeek;

        if ($isFullWeek) {
            foreach ($schedule->unplaced as $slot) {
                $this->warnings[] = $slot->sport === Sport::Swim
                    ? 'Not every swim fits on your pool days; add a pool day to get the full swim volume.'
                    : "Some {$slot->sport->value} sessions do not fit your available days; free up another day or raise your daily limits.";
            }
        }

        $sessions = array_map(fn (Placement $p) => $this->fill($p, $week, $request, $library), $schedule->placements);
        $sessions = $this->correctLoad($sessions, $week, $request);

        return new WeekDraft(
            index: $week->index,
            startDate: $week->monday,
            phase: $week->phase,
            isRecovery: $week->load->isRecovery,
            targetTss: $week->load->tss,
            targetHours: $week->load->hours,
            projectedCtl: $week->load->projectedCtl,
            workouts: array_map(fn (array $s) => $s['draft'], $sessions),
        );
    }

    /**
     * Notes for the athlete raised while building the last week.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return list<Day>
     */
    private function days(WeekContext $week, PlanRequest $request): array
    {
        $prefs = $request->preferences;
        $lastTrainingDay = $request->raceDate->modify('-2 days');
        $days = [];

        $race = $week->secondaryRace;

        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $date = $week->monday->modify('+'.($weekday - 1).' days');
            $override = $request->availableMinutesOn($date);
            $inPlan = $date >= $request->startDate && $date <= $lastTrainingDay && ! $this->isRaceDay($race, $date);
            $limit = $override ?? $prefs->dayLimit($weekday);

            $restDay = $prefs->isRestDay($weekday);
            $available = match (true) {
                $override === null => ! $restDay,
                // An easy-only day limits training; it never turns a rest day into a training day.
                $request->isEasyDay($date) => $override > 0 && ! $restDay,
                default => $override > 0,
            };

            $day = new Day(
                weekday: $weekday,
                date: $date,
                available: $inPlan && $available,
                isPoolDay: $prefs->isPoolDay($weekday),
                capacitySeconds: $limit === null ? null : $limit * 60,
            );
            $day->keyFree = $request->isEasyDay($date);
            $day->easyOnly = $day->keyFree || ($race !== null && self::sameDay($date, $race->date->modify('+1 day')));
            $days[] = $day;
        }

        return $days;
    }

    /**
     * The race itself is the day's session; a B race also gets a rest day before it.
     */
    private function isRaceDay(?SecondaryRace $race, DateTimeImmutable $date): bool
    {
        if ($race === null) {
            return false;
        }

        return self::sameDay($date, $race->date)
            || ($race->isMiniTaper() && self::sameDay($date, $race->date->modify('-1 day')));
    }

    private static function sameDay(DateTimeImmutable $a, DateTimeImmutable $b): bool
    {
        return $a->format('Y-m-d') === $b->format('Y-m-d');
    }

    /**
     * @return list<SessionSlot>
     */
    private function slots(WeekContext $week, PlanRequest $request, int $poolDays): array
    {
        $total = $week->load->hours * 3600;
        $budget = fn (Sport $sport) => (int) round($total * ($week->sportShare[$sport->value] ?? 0.0));

        if ($week->isRaceWeek) {
            return $this->raceWeekSlots($budget, $poolDays);
        }

        $recovery = $week->load->isRecovery;
        $miniTaper = $week->secondaryRace?->isMiniTaper() ?? false;
        $brick = ! $recovery
            && $week->secondaryRace === null
            && $request->preferences->bricks
            && $week->weeksIntoBuild !== null
            && $week->weeksIntoBuild % 2 === 1
            && in_array($week->phase, [PhaseType::Build, PhaseType::Peak], true);

        $slots = [
            ...$this->bikeSlots($budget(Sport::Bike), $week, $recovery, $brick ? $this->brickRunSeconds($budget(Sport::Run)) : null),
            ...$this->runSlots($budget(Sport::Run) - ($brick ? $this->brickRunSeconds($budget(Sport::Run)) : 0), $week, $recovery, $week->leadInFor?->distance->sport() === Sport::Run),
            ...$this->swimSlots($budget(Sport::Swim), $week, $recovery, $poolDays),
        ];

        // A B race is the week's long effort: its long ride and run become shorter easy sessions.
        if ($miniTaper) {
            $slots = array_map(fn (SessionSlot $s) => in_array($s->role, [SlotRole::LongRide, SlotRole::LongRun], true)
                ? $this->easyVersion($s)
                : $s, $slots);
        }

        // The week after a marathon (or a long triathlon) keeps that sport easy:
        // no intervals and no long session until the legs have come back.
        $recovering = $week->recoveringFrom;

        if ($recovering !== null) {
            $sport = $recovering->distance->sport();
            $slots = array_map(fn (SessionSlot $s) => ($sport === null || $s->sport === $sport || ($s->isBrick() && $sport === Sport::Run))
                && ($s->isKey || $s->role === SlotRole::LongRide || $s->role === SlotRole::LongRun)
                    ? $this->easyVersion($s)
                    : $s, $slots);
        }

        return $slots;
    }

    /**
     * @return list<SessionSlot>
     */
    private function bikeSlots(int $seconds, WeekContext $week, bool $recovery, ?int $brickRunSeconds): array
    {
        [$long, $quality, $easy] = $this->splitDiscipline($seconds, self::LONG_SHARE_BIKE, Sport::Bike);

        return [
            new SessionSlot(SlotRole::LongRide, Sport::Bike, WorkoutKind::Long, ! $recovery, $long, $brickRunSeconds),
            $recovery
                ? new SessionSlot(SlotRole::Easy, Sport::Bike, WorkoutKind::Endurance, false, $quality)
                : new SessionSlot(SlotRole::Quality, Sport::Bike, $this->qualityKind($week), true, $quality),
            ...array_map(fn (int $s) => new SessionSlot(SlotRole::Easy, Sport::Bike, WorkoutKind::Endurance, false, $s), $easy),
        ];
    }

    /**
     * @return list<SessionSlot>
     */
    private function runSlots(int $seconds, WeekContext $week, bool $recovery, bool $runLeadIn = false): array
    {
        [$long, $quality, $easy] = $this->splitDiscipline($seconds, $runLeadIn ? self::LONG_SHARE_RUN_LEAD_IN : self::LONG_SHARE_RUN, Sport::Run);

        return [
            new SessionSlot(SlotRole::LongRun, Sport::Run, WorkoutKind::Long, ! $recovery, $long),
            $recovery
                ? new SessionSlot(SlotRole::Easy, Sport::Run, WorkoutKind::Technique, false, $quality)
                : new SessionSlot(SlotRole::Quality, Sport::Run, $this->qualityKind($week), true, $quality),
            ...array_map(fn (int $s) => new SessionSlot(SlotRole::Easy, Sport::Run, WorkoutKind::Endurance, false, $s), $easy),
        ];
    }

    /**
     * The same session as a shorter, easy one (no brick, not key).
     */
    private function easyVersion(SessionSlot $slot): SessionSlot
    {
        $long = in_array($slot->role, [SlotRole::LongRide, SlotRole::LongRun], true);

        return new SessionSlot(SlotRole::Easy, $slot->sport, WorkoutKind::Endurance, false, (int) ($slot->targetSeconds / ($long ? 2 : 1)));
    }

    /**
     * One quality swim and one endurance set, then technique and aerobic swims
     * as time and pool days allow.
     *
     * @return list<SessionSlot>
     */
    private function swimSlots(int $seconds, WeekContext $week, bool $recovery, int $poolDays): array
    {
        if ($poolDays === 0) {
            $this->warnings[] = 'No pool days are available, so no swims were planned.';

            return [];
        }

        $count = max(1, min(self::MAX_SWIMS, $poolDays, (int) round($seconds / self::EASY_SESSION_SECONDS['swim'])));
        $kinds = array_slice([
            [$recovery ? WorkoutKind::Technique : $this->qualityKind($week), ! $recovery, 1.0],
            [WorkoutKind::Long, false, 1.3],
            [WorkoutKind::Technique, false, 0.9],
            [WorkoutKind::Endurance, false, 1.0],
        ], 0, $count);

        $lengths = $this->split($seconds, array_column($kinds, 2));

        return array_map(
            fn (array $kind, int $length) => new SessionSlot(SlotRole::Swim, Sport::Swim, $kind[0], $kind[1], $length),
            $kinds,
            $lengths,
        );
    }

    /**
     * Race week: short race-pace openers and a little easy work, all before
     * the rest day ahead of the race.
     *
     * @param  callable(Sport): int  $budget
     * @return list<SessionSlot>
     */
    private function raceWeekSlots(callable $budget, int $poolDays): array
    {
        $slots = [];

        foreach ([Sport::Bike, Sport::Run] as $sport) {
            [$quality, $easy] = $this->split($budget($sport), [0.6, 0.4]);
            $slots[] = new SessionSlot(
                SlotRole::Quality, $sport, WorkoutKind::RacePace, true, min($quality, self::RACE_WEEK_QUALITY_SECONDS),
                preferredSlug: "{$sport->value}-openers",
            );
            $slots[] = new SessionSlot(SlotRole::Easy, $sport, WorkoutKind::Recovery, false, min($easy, self::RACE_WEEK_EASY_SECONDS));
        }

        if ($poolDays > 0) {
            $slots[] = new SessionSlot(SlotRole::Swim, Sport::Swim, WorkoutKind::RacePace, true, min($budget(Sport::Swim), self::RACE_WEEK_QUALITY_SECONDS));
        }

        return $slots;
    }

    /**
     * Long session, quality session and as many easy sessions as the remaining
     * time supports.
     *
     * @return array{0: int, 1: int, 2: list<int>}
     */
    private function splitDiscipline(int $seconds, float $longShare, Sport $sport): array
    {
        $rest = $seconds * (1 - $longShare - self::QUALITY_SHARE);
        $easyCount = max(0, min(self::MAX_EASY_SESSIONS, (int) round($rest / self::EASY_SESSION_SECONDS[$sport->value])));

        $weights = [$longShare, self::QUALITY_SHARE, ...array_fill(0, $easyCount, $easyCount === 0 ? 0 : $rest / $seconds / $easyCount)];
        $lengths = $this->split($seconds, $weights);

        return [$lengths[0], $lengths[1], array_slice($lengths, 2)];
    }

    private function brickRunSeconds(int $runSeconds): int
    {
        [$min, $max] = self::BRICK_RUN_BOUNDS;

        return (int) max($min, min($max, round($runSeconds * self::BRICK_RUN_SHARE / 300) * 300));
    }

    /**
     * Intensity focus by phase: tempo in base, threshold with some VO2 work in
     * build, race-specific work in peak and taper.
     */
    private function qualityKind(WeekContext $week): WorkoutKind
    {
        return match ($week->phase) {
            PhaseType::Base => WorkoutKind::Tempo,
            PhaseType::Build => $week->weekInPhase % 3 === 2 ? WorkoutKind::Vo2 : WorkoutKind::Threshold,
            PhaseType::Peak => $week->weekInPhase % 2 === 0 ? WorkoutKind::RacePace : WorkoutKind::Threshold,
            PhaseType::Taper => WorkoutKind::RacePace,
        };
    }

    /**
     * Splits $total proportionally to $weights.
     *
     * @param  list<float>  $weights
     * @return list<int>
     */
    private function split(int $total, array $weights): array
    {
        $sum = array_sum($weights);

        return array_map(fn (float $w) => $sum > 0 ? (int) round($total * $w / $sum) : 0, $weights);
    }

    /**
     * @return array{placement: Placement, template: ?Template, run: ?Template, seconds: int, draft: WorkoutDraft}
     */
    private function fill(Placement $placement, WeekContext $week, PlanRequest $request, TemplateLibrary $library): array
    {
        $slot = $placement->slot;
        $rotation = $week->index;
        $template = $library->pick($slot->sport, $slot->kind, $week->phase, $rotation, $slot->preferredSlug, $request->distance);
        $run = $slot->isBrick()
            ? $library->pick(Sport::Run, WorkoutKind::Endurance, $week->phase, $rotation, self::BRICK_RUN_SLUG, $request->distance)
            : null;

        $session = ['placement' => $placement, 'template' => $template, 'run' => $run, 'seconds' => $slot->targetSeconds];

        return [...$session, 'draft' => $this->draft($session, $slot->targetSeconds, $request)];
    }

    /**
     * @param  array{placement: Placement, template: ?Template, run: ?Template}  $session
     */
    private function draft(array $session, int $seconds, PlanRequest $request): WorkoutDraft
    {
        $placement = $session['placement'];
        $slot = $placement->slot;
        $maxSeconds = $placement->maxSeconds === null ? null : $placement->maxSeconds - ($slot->brickRunSeconds ?? 0);

        $main = $this->workout($slot->sport, $slot->kind, $slot->isKey, $session['template'], $seconds, $maxSeconds, $placement, $request);

        if (! $slot->isBrick()) {
            return $main;
        }

        $runSeconds = (int) round($slot->brickRunSeconds * $seconds / max(1, $slot->targetSeconds));
        $run = $this->workout(Sport::Run, WorkoutKind::Endurance, false, $session['run'], $runSeconds, null, $placement, $request);

        return new WorkoutDraft(
            date: $placement->date,
            sport: Sport::Brick,
            kind: WorkoutKind::Long,
            isKey: $slot->isKey,
            title: "Brick: {$main->title} + {$run->title}",
            durationSeconds: $main->durationSeconds + $run->durationSeconds,
            distanceMeters: null,
            tss: round($main->tss + $run->tss, 1),
            structure: null,
            children: [$main, $run],
        );
    }

    private function workout(Sport $sport, WorkoutKind $kind, bool $isKey, ?Template $template, int $seconds, ?int $maxSeconds, Placement $placement, PlanRequest $request): WorkoutDraft
    {
        $seconds = $maxSeconds === null ? $seconds : min($seconds, $maxSeconds);

        $sized = $template === null
            ? null
            : $this->sizer->fit($template, $seconds, $request->thresholds, $maxSeconds);

        // Some workouts cannot shrink far enough (fixed warm-up, whole repeats). On a
        // short day a plain session of the right kind and length beats running over.
        if ($sized !== null && $maxSeconds !== null && $sized->analysis->durationSeconds > $maxSeconds * self::DAY_LIMIT_SLACK) {
            $template = null;
            $sized = null;
        }

        $sized ??= $this->sizer->fallback($sport, $kind, $seconds, $request->thresholds);

        return new WorkoutDraft(
            date: $placement->date,
            sport: $sport,
            kind: $template->kind ?? $kind,
            isKey: $isKey,
            title: $template->name ?? ucfirst(str_replace('_', ' ', $kind->value)).' '.$sport->value,
            durationSeconds: $sized->analysis->durationSeconds,
            distanceMeters: $sized->analysis->distanceMeters,
            tss: $sized->analysis->tss,
            structure: $sized->structure,
            templateId: $template?->id,
        );
    }

    /**
     * Rescales every session until the week's TSS is within tolerance of the
     * target, or until template limits or the athlete's hours stop it getting
     * closer. Race week is only ever scaled down.
     *
     * @param  list<array{placement: Placement, template: ?Template, run: ?Template, seconds: int, draft: WorkoutDraft}>  $sessions
     * @return list<array{placement: Placement, template: ?Template, run: ?Template, seconds: int, draft: WorkoutDraft}>
     */
    private function correctLoad(array $sessions, WeekContext $week, PlanRequest $request): array
    {
        $targetTss = $week->load->tss;
        $maxSeconds = $week->availableHours * 3600 * self::HOURS_SLACK;
        $best = $sessions;
        $bestError = INF;
        $damping = 1.0;
        $lastDirection = 0;

        for ($i = 0; $i < self::MAX_CORRECTIONS && $targetTss > 0; $i++) {
            $planned = array_sum(array_map(fn (array $s) => $s['draft']->tss, $sessions));
            $seconds = array_sum(array_map(fn (array $s) => $s['draft']->durationSeconds, $sessions));
            $error = abs($planned - $targetTss) / $targetTss;

            if ($seconds <= $maxSeconds && $error < $bestError) {
                [$best, $bestError] = [$sessions, $error];
            }

            if ($planned <= 0 || $error <= self::TSS_TOLERANCE) {
                break;
            }

            $factor = min($targetTss / $planned, $maxSeconds / max(1, $seconds));

            if ($week->isRaceWeek) {
                $factor = min(1.0, $factor);
            }

            // Sessions change in whole repeats, so halve the step whenever it overshoots.
            $direction = $factor <=> 1.0;
            $damping *= $direction !== $lastDirection && $lastDirection !== 0 ? 0.5 : 1.0;
            $lastDirection = $direction;
            $factor = 1 + ($factor - 1) * $damping;

            if (abs($factor - 1) < 0.01) {
                break;
            }

            $sessions = array_map(function (array $session) use ($factor, $request): array {
                $seconds = (int) round($session['seconds'] * $factor);

                return [...$session, 'seconds' => $seconds, 'draft' => $this->draft($session, $seconds, $request)];
            }, $sessions);
        }

        $sessions = $bestError === INF ? $sessions : $best;

        usort($sessions, fn (array $a, array $b) => $a['draft']->date <=> $b['draft']->date);

        return $sessions;
    }
}
