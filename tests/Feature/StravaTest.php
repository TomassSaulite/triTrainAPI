<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ActivitySource;
use App\Enums\TssMethod;
use App\Integrations\Strava\StravaClient;
use App\Integrations\Strava\StravaImporter;
use App\Jobs\BackfillStravaActivities;
use App\Jobs\HandleStravaEvent;
use App\Models\Activity;
use App\Models\Athlete;
use App\Models\StravaConnection;
use App\Models\Threshold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StravaTest extends TestCase
{
    use RefreshDatabase;

    private Athlete $athlete;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.strava.client_id' => '123',
            'services.strava.client_secret' => 'secret',
            'services.strava.webhook_verify_token' => 'verify-me',
            'services.strava.app_return_url' => null,
        ]);

        $this->athlete = Athlete::factory()->create();
        Http::preventStrayRequests();
    }

    private function connection(array $attributes = []): StravaConnection
    {
        return $this->athlete->stravaConnection()->create([
            'strava_athlete_id' => 555,
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHours(5),
            'scope' => 'read,activity:read_all',
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function stravaRide(int $id = 9001, array $overrides = []): array
    {
        return [
            'id' => $id,
            'name' => 'Morning Ride',
            'sport_type' => 'Ride',
            'start_date' => now()->subHours(3)->toIso8601String(),
            'moving_time' => 7200,
            'distance' => 60000.4,
            'average_heartrate' => 141.6,
            'device_watts' => true,
            'weighted_average_watts' => 200,
            'average_speed' => 8.3,
            ...$overrides,
        ];
    }

    private function state(int $expiresIn = 600): string
    {
        return Crypt::encryptString(json_encode(['athlete_id' => $this->athlete->id, 'expires' => now()->addSeconds($expiresIn)->getTimestamp()]));
    }

    public function test_connect_returns_a_strava_authorization_url(): void
    {
        Sanctum::actingAs($this->athlete->user);

        $url = $this->postJson('/api/v1/strava/connect')->assertOk()->json('data.url');

        $this->assertStringStartsWith(StravaClient::AUTHORIZE_URL, $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('123', $query['client_id']);
        $this->assertSame(route('strava.callback'), $query['redirect_uri']);
        $this->assertNotEmpty($query['state']);
    }

    public function test_the_callback_stores_encrypted_tokens_and_starts_a_backfill(): void
    {
        Bus::fake([BackfillStravaActivities::class]);
        Http::fake([StravaClient::TOKEN_URL => Http::response([
            'access_token' => 'new-access', 'refresh_token' => 'new-refresh',
            'expires_at' => now()->addHours(6)->getTimestamp(), 'athlete' => ['id' => 555],
        ])]);

        $this->getJson('/api/v1/strava/callback?'.http_build_query(['code' => 'abc', 'scope' => 'read,activity:read_all', 'state' => $this->state()]))
            ->assertOk()
            ->assertJsonPath('status', 'connected');

        $connection = $this->athlete->stravaConnection()->sole();
        $this->assertSame('new-access', $connection->access_token);
        $this->assertNotSame('new-access', DB::table('strava_connections')->value('access_token'));
        Bus::assertDispatched(BackfillStravaActivities::class);
    }

    public function test_the_callback_rejects_an_expired_state(): void
    {
        $this->getJson('/api/v1/strava/callback?'.http_build_query(['code' => 'abc', 'scope' => 'activity:read_all', 'state' => $this->state(-10)]))
            ->assertUnprocessable()
            ->assertJsonPath('status', 'invalid_state');
    }

    public function test_the_callback_requires_activity_access(): void
    {
        $this->getJson('/api/v1/strava/callback?'.http_build_query(['code' => 'abc', 'scope' => 'read', 'state' => $this->state()]))
            ->assertUnprocessable()
            ->assertJsonPath('status', 'missing_scope');
    }

    public function test_the_callback_redirects_to_the_app_when_configured(): void
    {
        config(['services.strava.app_return_url' => 'tritrain://strava']);

        $this->get('/api/v1/strava/callback?error=access_denied')->assertRedirect('tritrain://strava?status=denied');
    }

    public function test_the_webhook_subscription_handshake(): void
    {
        $this->getJson('/api/v1/webhooks/strava?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=xyz')
            ->assertOk()
            ->assertExactJson(['hub.challenge' => 'xyz']);

        $this->getJson('/api/v1/webhooks/strava?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=xyz')->assertForbidden();
    }

    public function test_webhook_events_are_queued(): void
    {
        Bus::fake([HandleStravaEvent::class]);

        $this->postJson('/api/v1/webhooks/strava', [
            'object_type' => 'activity', 'object_id' => 9001, 'aspect_type' => 'create', 'owner_id' => 555, 'event_time' => 1,
        ])->assertOk();

        Bus::assertDispatched(HandleStravaEvent::class, fn ($job) => $job->event['object_id'] === 9001);
    }

    public function test_a_created_activity_is_fetched_scored_and_stored(): void
    {
        $this->connection();
        Threshold::factory()->for($this->athlete)->ftp(250)->create();
        Http::fake(['www.strava.com/api/v3/activities/9001' => Http::response(self::stravaRide())]);

        HandleStravaEvent::dispatchSync(['object_type' => 'activity', 'object_id' => 9001, 'aspect_type' => 'create', 'owner_id' => 555]);

        $activity = $this->athlete->activities()->sole();
        $this->assertSame(ActivitySource::Strava, $activity->source);
        $this->assertSame('9001', $activity->external_id);
        $this->assertSame(60000, $activity->distance_m);
        $this->assertSame(142, $activity->avg_hr);
        $this->assertSame(TssMethod::Power, $activity->tss_method);
        $this->assertSame(128.0, $activity->tss);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer access'));
    }

    public function test_an_updated_activity_is_re_imported_in_place(): void
    {
        $this->connection();
        Http::fake(['www.strava.com/api/v3/activities/9001' => Http::sequence()
            ->push(self::stravaRide())
            ->push(self::stravaRide(overrides: ['name' => 'Renamed', 'moving_time' => 3600]))]);

        $importer = app(StravaImporter::class);
        $importer->import($this->athlete->stravaConnection, 9001);
        $importer->import($this->athlete->stravaConnection, 9001);

        $activity = $this->athlete->activities()->sole();
        $this->assertSame('Renamed', $activity->name);
        $this->assertSame(3600, $activity->duration_s);
    }

    public function test_an_expired_token_is_refreshed_before_calling_the_api(): void
    {
        $this->connection(['expires_at' => now()->subMinute()]);
        Http::fake([
            StravaClient::TOKEN_URL => Http::response(['access_token' => 'fresh', 'refresh_token' => 'refresh-2', 'expires_at' => now()->addHours(6)->getTimestamp()]),
            'www.strava.com/api/v3/activities/9001' => Http::response(self::stravaRide()),
        ]);

        app(StravaImporter::class)->import($this->athlete->stravaConnection, 9001);

        $this->assertSame('fresh', $this->athlete->stravaConnection->refresh()->access_token);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/activities/9001') && $r->hasHeader('Authorization', 'Bearer fresh'));
    }

    public function test_unsupported_sports_are_not_imported(): void
    {
        $this->connection();
        Http::fake(['www.strava.com/api/v3/activities/9001' => Http::response(self::stravaRide(overrides: ['sport_type' => 'Yoga']))]);

        app(StravaImporter::class)->import($this->athlete->stravaConnection, 9001);

        $this->assertSame(0, $this->athlete->activities()->count());
    }

    public function test_a_deleted_activity_is_removed(): void
    {
        $this->connection();
        Activity::factory()->for($this->athlete)->create(['source' => 'strava', 'external_id' => '9001']);

        HandleStravaEvent::dispatchSync(['object_type' => 'activity', 'object_id' => 9001, 'aspect_type' => 'delete', 'owner_id' => 555]);

        $this->assertSame(0, $this->athlete->activities()->count());
    }

    public function test_revoking_access_on_strava_removes_the_connection(): void
    {
        $this->connection();

        HandleStravaEvent::dispatchSync([
            'object_type' => 'athlete', 'object_id' => 555, 'aspect_type' => 'update', 'owner_id' => 555,
            'updates' => ['authorized' => 'false'],
        ]);

        $this->assertNull($this->athlete->stravaConnection()->first());
    }

    public function test_events_for_unknown_athletes_are_ignored(): void
    {
        HandleStravaEvent::dispatchSync(['object_type' => 'activity', 'object_id' => 1, 'aspect_type' => 'create', 'owner_id' => 999]);

        Http::assertNothingSent();
    }

    public function test_backfill_imports_recent_history_and_builds_load(): void
    {
        $this->connection();
        Http::fake(['www.strava.com/api/v3/athlete/activities*' => Http::response([
            self::stravaRide(1, ['start_date' => now()->subDays(10)->toIso8601String()]),
            self::stravaRide(2, ['sport_type' => 'Run', 'start_date' => now()->subDays(5)->toIso8601String(), 'average_speed' => 3.3]),
            self::stravaRide(3, ['sport_type' => 'Walk']),
        ])]);

        $count = app(StravaImporter::class)->backfill($this->athlete->stravaConnection, now()->subDays(42)->toImmutable());

        $this->assertSame(2, $count);
        $this->assertSame(11, $this->athlete->dailyLoads()->count());
        $this->assertSame(303.03, $this->athlete->activities()->where('external_id', '2')->value('avg_pace'));
    }

    public function test_disconnecting_deauthorizes_and_deletes_the_connection(): void
    {
        $this->connection();
        Http::fake([StravaClient::DEAUTHORIZE_URL => Http::response([])]);
        Sanctum::actingAs($this->athlete->user);

        $this->deleteJson('/api/v1/strava')->assertNoContent();

        $this->assertNull($this->athlete->stravaConnection()->first());
        Http::assertSent(fn (Request $r) => $r->url() === StravaClient::DEAUTHORIZE_URL);
    }
}
