<?php

declare(strict_types=1);

namespace App\Integrations\Strava;

use App\Models\StravaConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Strava v3 API. Refreshes access tokens on demand.
 */
class StravaClient
{
    public const string AUTHORIZE_URL = 'https://www.strava.com/oauth/authorize';

    public const string TOKEN_URL = 'https://www.strava.com/oauth/token';

    public const string DEAUTHORIZE_URL = 'https://www.strava.com/oauth/deauthorize';

    public const string API_URL = 'https://www.strava.com/api/v3';

    public const string SCOPE = 'read,activity:read_all';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {}

    public function authorizeUrl(string $redirectUri, string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'approval_prompt' => 'auto',
            'scope' => self::SCOPE,
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code): StravaToken
    {
        return StravaToken::fromResponse($this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]));
    }

    public function refresh(StravaConnection $connection): StravaToken
    {
        return StravaToken::fromResponse($this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
        ]));
    }

    public function deauthorize(StravaConnection $connection): void
    {
        Http::asForm()->post(self::DEAUTHORIZE_URL, ['access_token' => $this->accessToken($connection)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function activity(StravaConnection $connection, int $activityId): array
    {
        return $this->get($connection, "/activities/{$activityId}")->json();
    }

    /**
     * Summary activities started after $after, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function activitiesSince(StravaConnection $connection, int $after, int $page = 1, int $perPage = 100): array
    {
        return $this->get($connection, '/athlete/activities', ['after' => $after, 'page' => $page, 'per_page' => $perPage])->json();
    }

    /**
     * A valid access token, refreshing and storing a new one when it is about to expire.
     */
    public function accessToken(StravaConnection $connection): string
    {
        if ($connection->tokenExpiresSoon()) {
            $token = $this->refresh($connection);
            $connection->update([
                'access_token' => $token->accessToken,
                'refresh_token' => $token->refreshToken,
                'expires_at' => $token->expiresAt,
            ]);
        }

        return $connection->access_token;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(StravaConnection $connection, string $path, array $query = []): Response
    {
        try {
            return $this->http()->withToken($this->accessToken($connection))->get(self::API_URL.$path, $query)->throw();
        } catch (RequestException $e) {
            throw new StravaException("Strava request to {$path} failed with status {$e->response->status()}.", previous: $e);
        }
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     */
    private function tokenRequest(array $params): array
    {
        try {
            return $this->http()->asForm()->post(self::TOKEN_URL, [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                ...$params,
            ])->throw()->json();
        } catch (RequestException $e) {
            throw new StravaException("Strava token request failed with status {$e->response->status()}.", previous: $e);
        }
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(15)->retry(2, 500, throw: false);
    }
}
