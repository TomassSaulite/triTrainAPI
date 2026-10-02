# TriTrain API

The backend of TriTrain, a triathlon training app that works like a customizable coach. It
turns a race date, weekly hours and current fitness into a periodized swim/bike/run plan,
then re-plans the remaining weeks as completed workouts sync in.

Laravel owns everything that makes decisions: plan generation, load metrics, the adaptation
loop and Strava ingest. The Android app owns everything the athlete touches: the calendar,
workout detail, Health Connect import and FIT export to the watch.

```
 Strava ──webhook──▶ ┌──────────── Laravel ─────────────┐ ◀── REST ── Android app ──FIT──▶ Watch
                     │ ingest job: fetch, score, match  │              ▲
 Health Connect ───▶ │            │                     │              │
  (via the app)      │            ▼                     │      calendar + workouts
                     │ plan engine (pure PHP)           │
                     │  generate plan · adapt weeks     │
                     │            │                     │
                     │            ▼                     │
                     │ MySQL / SQLite: plans, workouts, │
                     │ activities, daily load           │
                     └──────────────────────────────────┘
```

## What it does

- **Plans backwards from race day.** Sets a fitness (CTL) target from distance and experience,
  lays out base, build, peak and taper, ramps weekly load within a CTL ramp cap with 3:1 (or 2:1)
  recovery weeks, caps every week by the athlete's hours, and fills each week with structured
  sessions from a workout library.
- **Coaches to the athlete's life.** Long ride and run days, pool days, rest days, daily time
  limits, travel days, recovery cadence, ramp rate, target fitness, sport split, bricks on or
  off, and personal workout templates all shape the plan.
- **Stores workouts relative to thresholds** ("88–93% FTP"), so a new FTP, threshold pace or
  CSS test updates every future workout. Targets are only turned into watts, paces and heart
  rates for display and FIT export.
- **Scores everything.** TSS from power, pace (swim IF cubed), heart rate or duration, plus
  CTL/ATL/TSB curves rebuilt whenever history changes.
- **Adapts as you train.** Each synced activity is matched to its planned session, scored for
  compliance, fed into load, and then the rules run: make up missed key sessions without
  back-to-back key days, ease the week after 3+ days off, re-plan after 7+, swap in recovery
  when form drops below TSB −30, raise the load after two weeks above 110% of plan, and suggest
  (never silently apply) new thresholds. Every change bumps the plan version and is logged
  with a reason the app can show.
- **Shows up in your calendar.** A private iCalendar feed puts every session and race in
  Google, Apple or Outlook calendar, and follows the plan as it adapts.
- **Syncs with Strava** (OAuth, webhooks, 42-day backfill) and takes idempotent batches from
  Health Connect.

## Getting started

Requirements: PHP 8.3+ with `pdo_sqlite` (or MySQL), Composer.

```bash
composer setup              # install, .env, app key, migrate
php artisan db:seed         # workout library (+ demo athlete when APP_ENV=local)
composer dev                # php artisan serve
php artisan queue:work      # activity processing, Strava ingest, adaptation
php artisan schedule:work   # nightly coach: missed days + adaptation at 03:00 athlete time
```

The demo athlete is `demo@tritrain.test` / `password`, with six weeks of history, thresholds,
an A race 20 weeks out and a generated plan.

Production deploys must run `php artisan db:seed --class=WorkoutTemplateSeeder`. It is
idempotent and installs or refreshes the system workout library the generator depends on.

### Strava

1. Create an API application at <https://www.strava.com/settings/api> and set its
   *Authorization Callback Domain* to this API's host.
2. Fill `STRAVA_CLIENT_ID`, `STRAVA_CLIENT_SECRET`, a random `STRAVA_WEBHOOK_VERIFY_TOKEN`
   and, optionally, `STRAVA_APP_RETURN_URL` (an app deep link such as `tritrain://strava`).
3. Register the webhook subscription once:

   ```bash
   curl -X POST https://www.strava.com/api/v3/push_subscriptions \
     -F client_id=$STRAVA_CLIENT_ID -F client_secret=$STRAVA_CLIENT_SECRET \
     -F callback_url=https://your-host/api/v1/webhooks/strava \
     -F verify_token=$STRAVA_WEBHOOK_VERIFY_TOKEN
   ```

## Using the API

All endpoints live under `/api/v1` and take a Sanctum bearer token from `/auth/register` or
`/auth/login`. A typical first run:

```
POST /auth/register                 → token
PUT  /athlete                       experience, weekly hours, preferences
POST /thresholds                    ftp_w, threshold_pace_s_per_km, css_s_per_100m, lthr
POST /races                         name, distance, date
POST /races/{race}/plan             generate the plan
GET  /calendar?from=…&to=…          planned workouts next to activities, by day
GET  /planned-workouts/{id}/export  FIT-ready steps with absolute targets
```

See [docs/api.md](docs/api.md) for every endpoint and [docs/engine.md](docs/engine.md) for how
plans are built and adapted.

## Code tour

| Path | What lives there |
| --- | --- |
| `app/Engine` | The pure plan engine: no database or HTTP, data in and data out. |
| `app/Engine/Load` | TSS scoring and the CTL/ATL/TSB model. |
| `app/Engine/Structure` | The workout step format: validation, scaling, analysis, resolution, FIT steps. |
| `app/Engine/Planning` | Phases, weekly load, sport split, week scheduling, template sizing, the generator. |
| `app/Engine/Adaptation` | Activity matching, compliance, the adaptation rules, threshold detection. |
| `app/Engine/Race` | Race-day strategy: pacing per leg, predicted splits and fueling. |
| `app/Engine/Review` | The coach's weekly summary of what was done and what comes next. |
| `app/Services` | Glue between Eloquent and the engine: load rebuilds, matching, planning, adaptation. |
| `app/Integrations/Strava` | OAuth client, activity mapping, import and backfill. |
| `app/Http` | Controllers, form requests, resources. Policies keep athletes to their own data. |
| `database/data/workout_templates.php` | The system workout library. |

### Conventions

- Every PHP file declares strict types. Value objects are `final readonly`.
- The engine never touches Eloquent. Services translate models into engine inputs and
  write the engine's drafts back.
- Heuristics are named constants next to the code that uses them. The design doc calls them
  starting points to tune, not validated coaching rules.
- History is immutable: only future, unstarted workouts are ever changed, and every change
  writes a plan revision.

## Quality

```bash
composer test               # PHPUnit: engine unit tests and API feature tests
composer lint               # Pint (fix); composer lint:check to verify
```

The engine is tested against fixed athlete scenarios. The rules from the design doc (no
back-to-back key days, swims on pool days, ±5% weekly TSS, ramp cap, race-day form…) are
asserted across whole generated plans. CI runs style checks and tests on PHP 8.3 and 8.4.

## Known limitations and next steps

- Strava does not expose best 20-minute power in its activity summary, so FTP suggestions come
  from manual, FIT or Health Connect uploads that include `best_20min_power_w`.
- Garmin Training API push (needs partner approval) and strength sessions are on the roadmap.
