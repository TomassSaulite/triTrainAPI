<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\BreakReason;
use App\Http\Controllers\Controller;
use App\Http\Resources\AvailabilityOverrideResource;
use App\Services\Planning\Replanner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Per-date exceptions to the athlete's usual week (travel, work trips).
 * Dates are the resource identifier: one override per day.
 */
class AvailabilityController extends Controller
{
    /** The longest break that can be entered at once. */
    public const int MAX_BREAK_DAYS = 28;

    /** After being sick or injured for this many days or more... */
    public const int EASE_BACK_AFTER_DAYS = 2;

    /** ...the next days are capped at this many minutes... */
    public const int EASE_BACK_MINUTES = 45;

    /** ...for this many days. */
    public const int EASE_BACK_DAYS = 2;

    public function __construct(
        private readonly Replanner $replanner,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $range = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $athlete = $request->user()->athleteOrFail();
        $overrides = $athlete->availabilityOverrides()
            ->whereDate('date', '>=', $range['from'] ?? $athlete->today())
            ->when($range['to'] ?? null, fn ($q, $to) => $q->whereDate('date', '<=', $to))
            ->orderBy('date')
            ->get();

        return AvailabilityOverrideResource::collection($overrides);
    }

    public function upsert(Request $request, string $date): AvailabilityOverrideResource
    {
        $day = $this->parseDate($date);
        $data = $request->validate([
            'available_minutes' => ['required', 'integer', 'between:0,600'],
            'note' => ['nullable', 'string', 'max:255'],
            'easy_only' => ['sometimes', 'boolean'],
        ]);

        $athlete = $request->user()->athleteOrFail();
        $overrides = $athlete->availabilityOverrides();
        $override = $overrides->clone()->whereDate('date', $day)->first() ?? $overrides->make(['date' => $day]);
        $override->fill($data)->save();

        if ($override->wasRecentlyCreated || $override->wasChanged(['available_minutes', 'easy_only'])) {
            $this->replanner->inputsChanged($athlete, "Re-planned for your availability on {$day->format('D j M')}.", $day);
        }

        return new AvailabilityOverrideResource($override);
    }

    /**
     * Marks several days in a row as no-training days (sick, injured, away)
     * and re-plans once. After two or more days sick or injured, the two days
     * after the break are capped short so the athlete eases back in; days
     * that already have their own availability are left as they are.
     */
    public function storeBreak(Request $request): AnonymousResourceCollection
    {
        $athlete = $request->user()->athleteOrFail();
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$athlete->today()->toDateString()],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'reason' => ['required', Rule::enum(BreakReason::class)],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);
        $days = (int) $from->diffInDays($to) + 1;
        abort_if($days > self::MAX_BREAK_DAYS, 422, 'A break can be at most '.self::MAX_BREAK_DAYS.' days; enter a longer one in parts or re-plan.');

        $reason = BreakReason::from($data['reason']);
        $note = $reason->label().(empty($data['note']) ? '' : ": {$data['note']}");

        $overrides = DB::transaction(function () use ($athlete, $from, $to, $days, $reason, $note) {
            $saved = [];

            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                $override = $athlete->availabilityOverrides()->whereDate('date', $day)->first()
                    ?? $athlete->availabilityOverrides()->make(['date' => $day->copy()]);
                $override->fill(['available_minutes' => 0, 'note' => $note, 'easy_only' => false])->save();
                $saved[] = $override;
            }

            if ($reason->needsEasingBack() && $days >= self::EASE_BACK_AFTER_DAYS) {
                // The next training days, skipping the athlete's usual rest days.
                $easeDays = [];
                for ($day = $to->copy()->addDay(); count($easeDays) < self::EASE_BACK_DAYS && $day->lte($to->copy()->addWeek()); $day->addDay()) {
                    if (! $athlete->prefs->isRestDay((int) $day->format('N'))) {
                        $easeDays[] = $day->copy();
                    }
                }

                foreach ($easeDays as $day) {
                    if (! $athlete->availabilityOverrides()->whereDate('date', $day)->exists()) {
                        $saved[] = $athlete->availabilityOverrides()->create([
                            'date' => $day,
                            'available_minutes' => self::EASE_BACK_MINUTES,
                            'easy_only' => true,
                            'note' => 'Easing back after being '.strtolower($reason->label()),
                        ]);
                    }
                }
            }

            return $saved;
        });

        $this->replanner->inputsChanged(
            $athlete,
            sprintf('Re-planned around a break (%s) from %s to %s.', strtolower($reason->label()), $from->format('D j M'), $to->format('D j M')),
            $from,
        );

        return AvailabilityOverrideResource::collection($overrides);
    }

    public function destroy(Request $request, string $date): Response
    {
        $athlete = $request->user()->athleteOrFail();
        $day = $this->parseDate($date);

        if ($athlete->availabilityOverrides()->whereDate('date', $day)->delete() > 0) {
            $this->replanner->inputsChanged($athlete, "Re-planned: back to your usual week on {$day->format('D j M')}.", $day);
        }

        return response()->noContent();
    }

    private function parseDate(string $date): Carbon
    {
        $parsed = Carbon::createFromFormat('!Y-m-d', $date);

        abort_if($parsed === null || $parsed->toDateString() !== $date, 404);

        return $parsed;
    }
}
