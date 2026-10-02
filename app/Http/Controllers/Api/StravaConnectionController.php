<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Integrations\Strava\StravaClient;
use App\Integrations\Strava\StravaException;
use App\Jobs\BackfillStravaActivities;
use App\Models\Athlete;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * Connecting a Strava account with OAuth. The app opens the URL from
 * connect() in a browser; Strava redirects back to callback().
 */
class StravaConnectionController extends Controller
{
    /**
     * How long the athlete has to approve access on Strava's site.
     */
    private const int STATE_TTL_SECONDS = 900;

    public function __construct(
        private readonly StravaClient $strava,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $connection = $request->user()->athleteOrFail()->stravaConnection;

        return response()->json(['data' => [
            'connected' => $connection !== null,
            'strava_athlete_id' => $connection?->strava_athlete_id,
            'scope' => $connection?->scope,
            'connected_at' => $connection?->created_at,
        ]]);
    }

    public function connect(Request $request): JsonResponse
    {
        $state = Crypt::encryptString(json_encode([
            'athlete_id' => $request->user()->athleteOrFail()->id,
            'expires' => now()->addSeconds(self::STATE_TTL_SECONDS)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));

        return response()->json(['data' => [
            'url' => $this->strava->authorizeUrl(route('strava.callback'), $state),
        ]]);
    }

    public function callback(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->filled('error')) {
            return $this->finish('denied', 'Strava access was not granted.', 422);
        }

        $athlete = $this->athleteFromState((string) $request->query('state'));
        $scope = (string) $request->query('scope');

        if ($athlete === null) {
            return $this->finish('invalid_state', 'This link has expired; start connecting again from the app.', 422);
        }

        if (! str_contains($scope, 'activity:read')) {
            return $this->finish('missing_scope', 'TriTrain needs permission to read your activities.', 422);
        }

        try {
            $token = $this->strava->exchangeCode((string) $request->query('code'));
        } catch (StravaException) {
            return $this->finish('exchange_failed', 'Strava did not accept the authorization. Please try again.', 502);
        }

        $connection = $athlete->stravaConnection()->updateOrCreate([], [
            'strava_athlete_id' => $token->stravaAthleteId,
            'access_token' => $token->accessToken,
            'refresh_token' => $token->refreshToken,
            'expires_at' => $token->expiresAt,
            'scope' => $scope,
        ]);

        BackfillStravaActivities::dispatch($connection->id);

        return $this->finish('connected', 'Strava connected. Your recent activities are being imported.');
    }

    public function destroy(Request $request): Response
    {
        $connection = $request->user()->athleteOrFail()->stravaConnection;

        if ($connection !== null) {
            rescue(fn () => $this->strava->deauthorize($connection), report: false);
            $connection->delete();
        }

        return response()->noContent();
    }

    private function athleteFromState(string $state): ?Athlete
    {
        try {
            $payload = json_decode(Crypt::decryptString($state), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (($payload['expires'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return Athlete::find($payload['athlete_id'] ?? null);
    }

    private function finish(string $status, string $message, int $code = 200): JsonResponse|RedirectResponse
    {
        $returnUrl = config('services.strava.app_return_url');

        if ($returnUrl) {
            return redirect()->away($returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').http_build_query(['status' => $status]));
        }

        return response()->json(['status' => $status, 'message' => $message], $code);
    }
}
