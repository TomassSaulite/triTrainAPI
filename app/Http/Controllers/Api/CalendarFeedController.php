<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Athlete;
use App\Services\CalendarFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * A private link that calendar apps (Google, Apple, Outlook) subscribe to.
 * The link carries a secret token instead of a login, so it can be rotated
 * or turned off.
 */
class CalendarFeedController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => ['url' => $this->url($request->user()->athleteOrFail())]]);
    }

    /**
     * Turns the feed on, or replaces its link so the old one stops working.
     */
    public function store(Request $request): JsonResponse
    {
        $athlete = $request->user()->athleteOrFail();
        $athlete->forceFill(['calendar_token' => Str::random(40)])->save();

        return response()->json(['data' => ['url' => $this->url($athlete)]], 201);
    }

    public function destroy(Request $request): Response
    {
        $request->user()->athleteOrFail()->forceFill(['calendar_token' => null])->save();

        return response()->noContent();
    }

    /**
     * The feed itself, for calendar apps. No login: the token is the key.
     */
    public function feed(string $token, CalendarFeed $feed): Response
    {
        $athlete = Athlete::where('calendar_token', $token)->firstOrFail();

        return response($feed->render($athlete), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="tritrain.ics"',
            'Cache-Control' => 'private, max-age=900',
        ]);
    }

    private function url(Athlete $athlete): ?string
    {
        return $athlete->calendar_token === null ? null : route('calendar.feed', ['token' => $athlete->calendar_token]);
    }
}
