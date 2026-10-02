<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\HandleStravaEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Strava push subscription endpoint.
 *
 * @see https://developers.strava.com/docs/webhooks/
 */
class StravaWebhookController extends Controller
{
    /**
     * Subscription handshake: echo the challenge when the verify token matches.
     * PHP turns the dots in "hub.challenge" into underscores.
     */
    public function verify(Request $request): JsonResponse
    {
        $expected = (string) config('services.strava.webhook_verify_token');

        abort_unless(
            $request->query('hub_mode') === 'subscribe'
                && $expected !== ''
                && hash_equals($expected, (string) $request->query('hub_verify_token')),
            403,
        );

        return response()->json(['hub.challenge' => $request->query('hub_challenge')]);
    }

    /**
     * Strava expects a 200 within two seconds, so all work is queued.
     */
    public function receive(Request $request): JsonResponse
    {
        $event = $request->validate([
            'object_type' => ['required', 'string', 'in:activity,athlete'],
            'object_id' => ['required', 'integer'],
            'aspect_type' => ['required', 'string', 'in:create,update,delete'],
            'owner_id' => ['required', 'integer'],
            'updates' => ['sometimes', 'array'],
        ]);

        HandleStravaEvent::dispatch($event);

        return response()->json(['received' => true]);
    }
}
