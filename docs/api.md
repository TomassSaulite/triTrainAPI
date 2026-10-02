# API reference

Base URL: `/api/v1`. Send `Accept: application/json`. Every endpoint except registration,
login and the two Strava callbacks needs `Authorization: Bearer <token>`.

Single resources come back as `{"data": {...}}` and lists as `{"data": [...]}`. Paginated lists
add `links` and `meta`. Validation errors are `422` with `{"message", "errors": {field: [...]}}`.
Coaching endpoints answer `409` until the athlete profile exists, and `403` for someone else's
records.

Dates are `Y-m-d`; timestamps are ISO-8601 UTC. Durations are seconds, distances metres. Run
pace is seconds per km, swim pace seconds per 100 m.

## Auth

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/auth/register` | `name, email, password, password_confirmation, device_name` → `201 {token, user}` |
| POST | `/auth/login` | `email, password, device_name` → `{token, user}` |
| POST | `/auth/logout` | Revokes the current token. |
| GET | `/me` | The user with their athlete profile. |

Registration and login are limited to 10 requests per minute per IP.

## Athlete profile and coach preferences

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/athlete` | Profile with the full `preferences` object. |
| PUT | `/athlete` | Creates the profile (`201`) or partially updates it. `preferences` are merged into the stored ones. |

Profile fields: `timezone` (IANA name such as `Europe/Riga`, default `UTC`; it decides which
day an activity belongs to and when the athlete's day is over), `experience` (novice /
intermediate / advanced, required on create),
`weekly_hours` (2–30, required on create), `birth_year`, `weight_kg`, `max_hr`,
`weakest_sport` (swim / bike / run).

Preferences (all optional; weekdays are ISO, 1 = Monday):

| Key | Default | Effect |
| --- | --- | --- |
| `long_ride_day` | `6` | Day of the long ride (or brick). |
| `long_run_day` | `null` | `null` lets the coach place it 1–2 clear days from the long ride. An explicit day is always honoured. |
| `pool_days` | `[2, 3, 5]` | Swims are only scheduled on these days. |
| `rest_days` | `[1]` | Never scheduled (up to 3). |
| `day_limits_minutes` | `{}` | e.g. `{"2": 60, "4": 60}` caps training time on those weekdays. |
| `recovery_week_every` | `null` | 2–5. `null` means 4 (3:1), or 3 (2:1) from age 50. |
| `max_ramp_rate` | `5` | Most CTL gained per week (1–8). |
| `target_ctl` | `null` | Overrides the peak fitness picked from distance and experience. |
| `sport_share` | `null` | `{"swim": 0.2, "bike": 0.45, "run": 0.35}`, summing to 1. |
| `bricks` | `true` | Long ride becomes a bike-run brick every other week from build. |

Changing a plan input (`experience`, `weekly_hours`, `weakest_sport`, `birth_year` or any
preference) re-plans the active plan from today. So does a race or availability change that
falls between today and the A race. Re-plans are queued, collapse into one per athlete, and are
logged as `regenerated` revisions with the reason. Close to race day the plan is left as it is.

## Thresholds

History is append-only: a new test adds a row and the newest per metric is in use.

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/thresholds?metric=&per_page=` | Paginated history, newest first; `per_page` up to 200 (default 15). |
| GET | `/thresholds/current` | Newest value per metric. |
| GET | `/thresholds/status` | Every metric with its current `value`, `tested_at`, `source`, `age_days`, `status` (`ok`, `due` when older than 8 weeks, `missing`) and a `protocol` describing how to test it. |
| POST | `/thresholds` | `metric, value, tested_at?, source? (test / estimated)` |
| DELETE | `/thresholds/{id}` | Remove a mistaken entry. |

Metrics: `ftp_w` (50–600), `threshold_pace_s_per_km` (150–600), `css_s_per_100m` (50–300),
`lthr` (100–220 bpm).

### Threshold suggestions

The coach never changes thresholds by itself. When an activity suggests a new one (best 20-min
power × 0.95 above FTP, or 30+ minutes of running faster than threshold pace), a suggestion
appears here.

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/threshold-suggestions?status=pending` | One pending suggestion per metric, holding the best evidence. |
| POST | `/threshold-suggestions/{id}/accept` | Records an `auto_detected` threshold → `201` threshold. |
| POST | `/threshold-suggestions/{id}/dismiss` | |

## Races

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/races?upcoming=1` | |
| POST | `/races` | `name, distance, date (future), priority? (A / B / C)` |
| GET / PATCH / DELETE | `/races/{id}` | Deleting the race a plan was built for archives that plan; its history stays. |
| GET | `/races/{id}/strategy` | The race-day plan: pacing per leg, predicted splits and fueling. See below. |

Distances: triathlons `sprint`, `olympic`, `half`, `full`, and running races `5k`, `10k`,
`half_marathon`, `marathon`. Only a triathlon can be the A race. New triathlons default to A,
running races to B.

The race strategy is worked out from the athlete's current thresholds, experience and weight:

```json
{
  "legs": [
    {"sport": "swim", "distance_m": 1900, "target": {"unit": "s_per_100m", "easy": 114, "hard": 108, "target": 111, "intensity": 0.945},
     "predicted_s": 2172, "advice": "Start controlled for the first 200 m, ..."},
    {"sport": "bike", "distance_m": 90000, "target": {"unit": "watts", "easy": 180, "hard": 198, "target": 189, "intensity": 0.755}, "predicted_s": 9787, "advice": "..."},
    {"sport": "run", "distance_m": 21097, "target": {"unit": "s_per_km", "easy": 318, "hard": 300, "target": 309, "intensity": 0.875}, "predicted_s": 6519, "advice": "..."}
  ],
  "transitions_s": 420,
  "finish_s": 18898,
  "fueling": {"before": ["..."], "during": ["On the bike: 75 to 90 g of carbohydrate per hour, about 220 g over the ride, ..."]},
  "missing": []
}
```

`easy` and `hard` bound the range for the distance; `target` is where the athlete's experience
puts them in it. A leg without a threshold has `target` and `predicted_s` `null`, `finish_s` is
`null` unless every leg is predicted, and `missing` says what to add.

The A race drives the plan. B and C races before it shape their weeks: a B race gets a mini-taper
(75% load, rest the day before, the race replaces the long sessions, easy the day after) and a C
race is raced as training (90% load). A B running race also gets a lead-in: for the 6 weeks
before a marathon (3 for a half marathon, 2 for a 10K, 1 for a 5K) training time shifts toward
running and the long run grows. After a marathon, half marathon or full-distance triathlon,
the following week keeps less load and that sport stays easy (no intervals, no long session).
Races also appear in the calendar.

## Availability overrides

One-off changes to the usual week, such as travel or a busy day at work. Each change re-plans
the active plan from today.

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/availability?from=&to=` | From today by default. |
| PUT | `/availability/{Y-m-d}` | `available_minutes` (0 = can't train), `note?`, `easy_only?` (only easy sessions that day) |
| DELETE | `/availability/{Y-m-d}` | |
| POST | `/availability/break` | `from`, `to` (from today, at most 28 days), `reason` (`sick`, `injured`, `away`, `other`), `note?`. Marks every day as a no-training day and re-plans once. After two or more days sick or injured, the two days after the break are capped at 45 minutes and easy sessions only, to ease back in, unless they already have their own availability. Returns the days written. |

## Plans

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/races/{race}/plan` | Generates a plan for an A race and archives the active one. `201` plan. |
| GET | `/plans` | All plans, newest first. |
| GET | `/plans/current` | The active plan, or `404`. |
| GET | `/plans/{id}` | Plan with `race`, `phases` and `weeks` (`target_tss`, `target_hours`, `planned_tss`, `is_recovery`). |
| POST | `/plans/{id}/regenerate` | `reason?`. Re-plans from today (tomorrow if today's training is done), keeping history. |
| POST | `/plans/{id}/archive` | |
| GET | `/plans/{id}/revisions` | The "what changed and why" log, newest first. |
| GET | `/plans/{id}/progress` | Per week: `planned` and `actual` TSS, time and counts, session outcomes (`completed`, `partial`, `missed`, `upcoming`), `by_sport` planned vs actual time, and `compliance` (actual ÷ planned TSS, `null` for weeks not yet started). |
| GET | `/plans/{id}/weekly-review` | `week?` (any date in the week, `Y-m-d`; default last week). The coach's summary of a week, or `data: null` when the week is not in the plan. See below. |

A plan carries `version`, `starting_ctl`, `target_ctl` and `warnings`: things the athlete
should know, such as a lowered target or sessions that did not fit the available days.

The weekly review reads a week the way a coach would:

```json
{
  "week_start": "2026-10-12", "week_end": "2026-10-18", "phase": "base", "is_recovery": false,
  "finished": true,
  "verdict": "keys_missed",
  "headline": "You did 90% of the planned load but missed a key session.",
  "notes": ["Missed: Long run. Key sessions carry most of the training effect, so the coach protects the next ones.",
            "Fitness rose from 60 to 63."],
  "next_week": "Next week the load goes up about 8% (8 h 40 and 455 TSS).",
  "planned": {"tss": 421, "duration_s": 30600, "sessions": 8},
  "actual": {"tss": 379, "duration_s": 28800, "activities": 7},
  "compliance": 0.9,
  "key_sessions": {"planned": 3, "done": 2, "missed": ["Long run"]},
  "fitness": {"ctl_before": 60.2, "ctl_after": 62.9, "tsb_after": -8},
  "feel": {"sessions": 7, "rated": 5, "rpe": 5.8, "muscles": 3.2, "breathing": 2.4, "energy": 2.6, "mood": 2, "pain_reports": 0},
  "coach_changes": [{"version": 5, "summary": "Missed tempo bike on Tuesday; moved to Thursday.", "created_at": "..."}]
}
```

`verdict` is one of `on_track`, `keys_missed`, `under` (below 85% of the planned load),
`over` (above 115%) and `rest` (nothing planned). `coach_changes` lists the coach's own
re-plans and adaptations since the week began; the athlete's edits are left out.

Each revision has a `reason` (`generated`, `regenerated`, `adapted`, `manual`), a readable
`summary` and machine-readable `changes`:

```json
{
  "version": 4,
  "reason": "adapted",
  "summary": "Missed tempo bike on Tuesday; moved to Thursday.",
  "changes": [
    {"type": "reschedule", "rule": "missed_key_session", "key": "reschedule:812",
     "reason": "Missed tempo bike on Tuesday; moved to Thursday.", "workout_id": 812, "date": "2026-10-15"}
  ]
}
```

Change types: `reschedule`, `drop`, `scale` (with `factor`), `recover`, `regenerate`, `moved`,
`dropped` and `week_load` (`week_start`, `from_tss`, `to_tss`, written by re-plans).

## Calendar and planned workouts

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/calendar?from=&to=` | Defaults to the current week; up to 92 days. Each day lists the active plan's workouts, the activities done, any races and its `availability` override (or `null`). |
| GET | `/planned-workouts/{id}` | Workout with `structure` (relative) and `resolved_structure` (current thresholds). |
| GET | `/planned-workouts/{id}/export` | FIT-ready steps, one entry per half for a brick. |
| PATCH | `/planned-workouts/{id}` | `date` moves the workout (today until the day before the race); `duration_s` (600–21600) makes it shorter or longer by scaling its main set. A brick half can be resized but not moved alone. |
| GET | `/planned-workouts/{id}/alternatives` | Workouts that could replace it: same sport, same family (hard / long / easy), suiting the race distance; your own first. |
| POST | `/planned-workouts/{id}/swap` | `template_id` from the alternatives; the new workout is sized to the session's length. |
| POST | `/planned-workouts/{id}/skip` | Drops it. |

Workouts have a `status`: `planned`, `moved`, `completed` (≥80% of planned TSS), `partial`
(50–80%), `missed` or `dropped`. A brick is a parent with `sport: "brick"` and its bike and run
halves in `children`; move or skip the parent.

### Structure

Targets are fractions of a threshold where 1.0 is threshold and higher is always harder.
Pace targets are fractions of threshold *speed*.

```json
{"steps": [
  {"type": "warmup", "duration_s": 900, "target": {"metric": "ftp_pct", "low": 0.55, "high": 0.65}},
  {"type": "repeat", "count": 3, "steps": [
    {"type": "interval", "duration_s": 600, "target": {"metric": "ftp_pct", "low": 0.88, "high": 0.93}},
    {"type": "recovery", "duration_s": 300, "target": {"metric": "ftp_pct", "low": 0.50, "high": 0.60}}
  ]},
  {"type": "cooldown", "duration_s": 600, "target": {"metric": "ftp_pct", "low": 0.50, "high": 0.60}}
]}
```

Step types: `warmup`, `steady`, `interval`, `recovery`, `rest` (no target), `cooldown`,
`repeat`. A step has either `duration_s` or `distance_m`. Metrics: `ftp_pct`,
`threshold_pace_pct`, `css_pct`, `lthr_pct`.

`resolved_structure` adds `resolved: {unit, low, high}` to each step (`watts`, `bpm`,
`s_per_km`, `s_per_100m`; for paces `low` is the easier, slower end), or `null` when the
threshold is unknown.

### Export

```json
{"index": 1, "type": "interval", "intensity": "active", "duration_type": "time", "duration_value": 720,
 "target_type": "power", "target_low": 186, "target_high": 208,
 "relative_target": {"metric": "ftp_pct", "low": 0.76, "high": 0.85}, "notes": null}
{"index": 3, "type": "repeat", "duration_type": "repeat_until_steps_cmplt", "repeat_from_index": 1, "repeat_count": 2}
```

`target_type` is `power` (W), `speed` (m/s), `heart_rate` (bpm) or `open` when the threshold
is unknown. Steps map one-to-one onto FIT workout steps.

## Activities and load

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/activities?from=&to=&sport=` | Paginated, newest first, with `planned_workout_id`. |
| POST | `/activities` | Manual entry: `sport, started_at, duration_s, distance_m?, avg_hr?, np_w?, best_20min_power_w?, avg_pace?, tss?` |
| POST | `/activities/import` | `source (health_connect / fit), activities[]` with `external_id`, max 200. Idempotent: returns `{created, updated, unchanged}`. |
| GET | `/activities/{id}` | |
| PATCH / DELETE | `/activities/{id}` | Only manual activities can be edited; any can be deleted. |
| PUT | `/activities/{id}/feedback` | How the session felt: `rpe` (1–10), `muscles?`, `breathing?`, `energy?`, `mood?` (each 1 best to 5 worst), `pain?`, `pain_area?`, `note?`. Replaces any earlier rating (`201` the first time). The coach then re-checks the plan; `plan_change` is the summary of what it changed, or `null`. |
| DELETE | `/activities/{id}/feedback` | |
| GET | `/feedback?days=` | Ratings of the last `days` days (default 56, max 365), oldest first, each with the session's `date`, `sport`, `activity_name`, `duration_s` and `planned_kind`. |
| GET | `/load?from=&to=` | Daily `tss, ctl, atl, tsb` (last 90 days by default). |
| GET | `/load/summary` | Today's CTL, ATL, TSB, 7-day ramp and TSS. |

Activities include their `feedback` (or `null`). The rating scales, 1 to 5:

| Scale | 1 | 2 | 3 | 4 | 5 |
| --- | --- | --- | --- | --- | --- |
| `muscles` | Fresh | Fine | Tired | Heavy | Sore |
| `breathing` | Easy | Steady | Working | Hard | Gasping |
| `energy` | Great | Good | OK | Low | Empty |
| `mood` | Loved it | Good | Neutral | A struggle | Hated it |

Processing is queued. After an activity is stored it is scored (TSS from power, pace, heart rate
or duration, unless `tss` was supplied), daily load is rebuilt from its date, it is matched to a
planned workout (same sport, ±1 day, closest duration), and the adaptation rules run.

## Calendar feed

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/calendar-feed` | `{url}` of the athlete's private calendar feed, or `url: null` when it is off. |
| POST | `/calendar-feed` | Turns the feed on, or replaces the link (the old one stops working). `201 {url}`. |
| DELETE | `/calendar-feed` | Turns the feed off. |
| GET | `/calendar/feed/{token}.ics` | **No login**: the token is the key. An iCalendar feed with every planned session (skipped ones left out, done ones marked ✓) and race as all-day events, from two weeks back to the end of the plan. Calendar apps are asked to refresh every 4 hours. |

Google Calendar, Apple Calendar and Outlook can all subscribe to the URL ("add calendar from
URL"); on Apple devices the same URL with `webcal://` opens the subscribe dialog directly.

## Strava

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/strava` | Connection status. |
| POST | `/strava/connect` | `{url}` to open in a browser; the link is valid for 15 minutes. |
| GET | `/strava/callback` | Strava redirects here. Redirects to `STRAVA_APP_RETURN_URL?status=…` when configured, otherwise JSON. Statuses: `connected`, `denied`, `invalid_state`, `missing_scope`, `exchange_failed`. |
| DELETE | `/strava` | Deauthorizes and forgets the connection. |
| GET / POST | `/webhooks/strava` | Strava's subscription handshake and events (public). |

Connecting imports the last 42 days (`STRAVA_BACKFILL_DAYS`). After that, new, edited and
deleted Strava activities sync through the webhook.

## Workout templates

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/workout-templates?sport=&kind=&mine=1` | System library plus the athlete's own. |
| POST | `/workout-templates` | `name, sport, kind, phases[], min_s, max_s, structure, distances[]?, description?, is_active?` |
| GET / PATCH / DELETE | `/workout-templates/{id}` | The system library is read-only. |

The generator prefers the athlete's own templates when one fits a slot: same sport and kind,
in its phases. Templates with `distances` (sprint / olympic / half / full) are only used for
plans toward those distances, and are preferred over generic templates there. It scales each template's main set to the slot within `[min_s, max_s]`.

Kinds: `endurance`, `tempo`, `threshold`, `vo2`, `race_pace`, `long`, `recovery`, `technique`.
