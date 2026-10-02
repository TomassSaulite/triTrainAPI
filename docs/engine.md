# The plan engine

`app/Engine` is plain PHP. It takes value objects in and returns value objects out, with no
database or HTTP calls, so every decision can be unit-tested against fixed athlete scenarios.
Services in `app/Services` translate Eloquent models into engine inputs and write the results
back.

All numbers below are named constants next to the code that uses them. They are starting
heuristics to tune, not validated coaching rules.

## Load (`Engine/Load`)

**TSS** (100 = one hour at threshold). `TssCalculator` uses the best data available:

| Data | Intensity factor | TSS |
| --- | --- | --- |
| Bike power | NP / FTP | hours × IF² × 100 |
| Run pace | threshold pace / pace (a speed ratio) | hours × IF² × 100 |
| Swim pace | CSS / pace | hours × **IF³** × 100 |
| Heart rate | avg HR / LTHR | hours × IF² × 100 |
| Nothing usable | per-sport default (0.60–0.75) | hours × IF² × 100 |

Activities are scored against the thresholds valid on the day they happened.

**Fitness model** (`LoadModel`): CTL is the 42-day exponentially weighted average of daily
TSS, ATL the 7-day one, and TSB_d = CTL_{d−1} − ATL_{d−1}. Athletes without history start
from weekly hours × 52.5 TSS/h ÷ 7. The model also has a closed-form projection and its
inverse, the daily TSS needed to move CTL from *a* to *b* in *n* days, which the planner uses.

## Workout structure (`Engine/Structure`)

Steps and repeat blocks, relative to thresholds (see the [API reference](api.md#structure)).
`WorkoutStructure` validates the format and reports the path of the first problem. It also
expands repeats and scales the main set: repeat counts and steady blocks change, warm-up and
cool-down keep their length. `StructureAnalyzer` estimates duration and TSS, timing distance
steps from CSS or threshold pace. `StructureResolver` and `FitStepExporter` produce absolute
targets.

## Plan generation (`Engine/Planning`)

`PlanGenerator::generate(PlanRequest, TemplateLibrary): GeneratedPlan` works backwards from
race day.

1. **Weeks.** Monday-aligned, from the start week to race week. A mid-week start makes a
   partial first week.
2. **Phases** (`PhasePlanner`). Taper is 1 week (2 for full distance), one week more when the
   race is Monday to Wednesday so the taper still has enough days. Peak is 2 weeks, or 3
   when 12+ weeks remain. Build takes 40% of the rest, base the remainder. A re-plan passes
   the original start date so phases stay anchored to the whole plan.
3. **Target CTL** (`LoadPlanner`). The athlete's override, or a point in the distance's range
   by experience (novice low, intermediate middle, advanced high): sprint 40–60, olympic
   50–70, half 65–85, full 85–110.
4. **Weekly load.**
   - Loading weeks aim for min(target, CTL + ramp cap) by the end of the week. The weekly TSS
     comes from the exact inverse of the 42-day model. (The doc's 7 × CTL shorthand would
     undershoot any ramp.)
   - Every Nth week is a recovery week at 65% of the last loading week. N is 4 (3:1), 3 (2:1)
     from age 50, or the athlete's choice. The week before the taper is never a recovery week.
   - Taper weeks drop to 50% (one week), 70% then 40% (two) or 80%, 65% then 40% (three) of
     the last loading week. Race week's load is sized to the days before the race (less the
     rest day before it), which lands race-day TSB around +5 to +20 whichever weekday the race
     is on.
   - Every week is capped at available hours × TSS per hour for the phase (50 base, 56 build,
     60 peak, 52 taper). Hours shrink for a partial first week and for availability overrides.
   - If the projected peak falls short of the target, the target is lowered and a warning
     explains why.
5. **Sport split** (`SportSplitter`). The distance default (half: 17.5% swim, 49% bike,
   33.5% run), the athlete's explicit split, or a 7.5-point shift toward their weakest
   discipline.
6. **Week skeleton** (`WeekBuilder` and `WeekScheduler`).
   - Bike and run each get a long session (45% / 35% of their time), a key quality session (27%)
     and up to two easy sessions. Swims come as quality, endurance set, technique, then
     aerobic, as time and pool days allow.
   - Quality by phase: tempo in base; threshold in build, with VO2 every third week; race pace
     alternating with threshold in peak; race pace in taper. Recovery weeks have no key
     sessions.
   - From build onward the long ride becomes a brick every other week. The run off the bike
     takes 15% of run time, between 15 and 30 minutes.
   - Placement order and rules:
     1. The long ride goes on its day, and the next day stays easy (recovery, technique or
        swim only).
     2. The long run goes on its preferred day, otherwise 1–2 clear days from the ride.
     3. Key bike and run sessions never fall on consecutive days. When no free day is left, a
        quality session doubles up with another quality session, never on a long-session day.
        Failing that, it is downgraded to easy.
     4. Swims only go on pool days.
     5. Easy sessions fill the emptiest days: at most two sessions a day, never two of one
        sport.
     6. Rest days, daily limits and availability overrides are respected. Nothing is scheduled
        the day before the race.
   - Race week gets short openers and a little easy work.
   - A B race week keeps 75% of its load: the day before the race is a rest day, the race
     replaces the long ride and run (which become shorter easy sessions), and the day after
     is easy only. A C race week keeps 90%, and the race simply takes its day. No bricks in
     either. The factor applies after the hours cap, so it still bites when hours are the
     limit.
   - Running races (5K to marathon) can sit inside a triathlon plan as B or C races. Before a
     B running race (6 weeks for a marathon, 3 for a half marathon, 2 for a 10K, 1 for a 5K)
     time shifts toward running (up to 12 points) and the long run takes 45% of run time instead
     of 35%. The week after a marathon (65% load), half marathon or full-distance triathlon
     (85%) keeps that sport easy. A race week or a post-race week restarts the recovery-week
     cadence.
7. **Fill slots.** `TemplateLibrary` picks a template by sport, kind and phase, among those
   suiting the race distance. The athlete's own templates win, then distance-specific ones
   (race pace near threshold for sprint/olympic, around 70% FTP for full distance). Lookups fall back to another phase, then an easier kind (VO2 →
   threshold → tempo → endurance), and rotate between candidates week to week. `WorkoutSizer`
   scales the main set to the slot. Finally the whole week is rescaled until it lands within
   ±5% of its TSS target without exceeding the athlete's hours; race week is only scaled down.

The result is rows for `plan_phases`, `plan_weeks` and `planned_workouts`. A re-plan deletes
future unstarted rows and runs again from today (`PlanService::regenerate`).

### Short and easy days

A day's time limit (from the weekly schedule or an availability override) caps the sessions on
it, even below a workout's usual minimum, but never below 10 minutes. Two sessions on a limited
day share its time in proportion to their planned length. When a workout cannot shrink far
enough (fixed warm-up, whole repeats), a plain session of the same kind and the right length
takes its place.

- The day after a long ride or a B race is *easy only*: recovery, technique and swim sessions,
  never a hard bike or run.
- The first days back after illness or injury are stricter: no key session of any sport, only
  recovery, technique or endurance work. They skip the athlete's usual rest days, and an easy
  day never turns a rest day into a training day.

## Adaptation (`Engine/Adaptation`)

Each processed activity runs match → score → load → rules. An hourly job catches days with no
activity, running for each athlete at 03:00 in their own timezone. All day boundaries
(activity dates, daily load, "today") are the athlete's local days.

- **Matching** (`ActivityMatcher`). Same sport, within ±1 day, closest duration wins; same day
  and then key sessions break ties. A session already marked missed can still be claimed by an
  activity that synced late. Unmatched activities still count toward load.
- **Compliance** (`ComplianceScorer`). Actual ÷ planned TSS: ≥80% completed, 50–80%
  partial, below 50% (or nothing by the end of the day) missed. The two halves of a brick
  combine by load.
- **Rules** (`Adapter`), checked in this order:
  1. *Missed key session* → copy it to the next free slot this week that keeps key land
     sessions off consecutive days. Otherwise it takes the place of the week's
     lowest-priority open session, never stacked. The missed original stays in history.
  2. *3+ days missed* → the rest of the week drops to 70%. *7+ days* → re-plan from today and
     re-check the CTL target; this supersedes every other rule. Missed key sessions are not
     made up during such a break.
  3. *TSB below −30 for 3 days* → the next key session becomes a short recovery session.
  4. *How you feel* (`HowYouFeelRule`), from the athlete's session ratings → pain reported
     in the last 3 days turns the next key session of that sport (within a week) into a
     recovery session; the last 3 rated sessions of the past week all rating legs or energy
     4 or worse ("heavy", "low") does the same for the next key session of any sport.
     Ratings often show fatigue before TSB does. Saving a rating runs the rules straight away.
  5. *Two weeks above 110% of plan* → next week goes up 5%, never past the ramp cap.
  6. *Possible new threshold* (`ThresholdDetector`) → a suggestion the athlete accepts or
     dismisses. Thresholds never change silently.

Each decision carries a key (`reschedule:812`, `reduce:2026-11-02`, …) stored in the plan
revision's change log, so the same decision is never made twice. History is immutable: only
future, unstarted workouts change, and every change bumps `plans.version`.

## Weekly review (`Engine/Review`)

`WeeklyReviewer` turns a week's facts (`WeekFacts`: planned and done load and time, key
sessions missed, time per sport, fitness and form at the end) and the following week
(`NextWeekFacts`) into a `WeeklyReview`: a verdict, a headline, notes and a line about next
week.

- The verdict compares done with planned TSS: under 85% is `under`, over 115% is `over`;
  between, a missed key session makes it `keys_missed`, otherwise `on_track`.
- Notes name the missed key sessions, the sport that fell furthest behind (under half its
  planned time, when at least an hour was planned), how fitness moved over the week, and
  form below −30 (heavy fatigue) or above +15 outside the taper (fresh).
- Overdoing a recovery week gets its own warning: recovery only works when it is easy.
- The athlete's ratings (`FeelSummary`) add notes: a nudge to rate when nothing was rated,
  pain reports, legs or energy averaging 3.5 of 5 or worse, two or more easy sessions rated
  effort 6 or more, or praise when at least three rated sessions all felt good.
- Next week is described by what matters most: a race in it, a new phase, a recovery week,
  the return from one, or else the change in load against this week.

## Race strategy (`Engine/Race`)

`RaceStrategist` plans race day from `RaceCourse` (the standard legs and transition time of
each distance), the athlete's thresholds, experience and weight.

- Each leg has a sustainable intensity range for the distance, as a share of threshold: CSS
  speed for the swim, FTP for the bike, threshold speed for the run (a 70.3 bike is 72–79% of
  FTP, a marathon 85–89% of threshold speed). Novices aim a quarter of the way into the
  range, intermediates at the middle, advanced athletes three quarters of the way.
- Swim time adds 3% for open water. Bike time comes from `BikeSpeedModel`, a flat-road power
  balance with typical age-grouper drag, rolling resistance and bike weight, so hilly or windy
  courses will be slower.
- Fueling scales with the predicted finish time (typical times when it cannot be predicted):
  a carb-load from 2.5 hours, 40–60 g of carbohydrate per hour on shorter bike legs and
  60–90 g on long ones, electrolytes from 4 hours.
