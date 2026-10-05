<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class SessionListAndTokenGraceTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_endpoint_returns_only_open_sessions_in_index_format(): void
    {
        $user = $this->createAppUser();
        $station = $this->station();
        $station->update(['ocpp_configuration' => [
            'NumberOfConnectors' => 2,
            'connectors' => [
                1 => ['connectorId' => 1, 'status' => 'Charging'],
                2 => ['connectorId' => 2, 'status' => 'Charging'],
            ],
        ]]);
        // Incarcare reala (tranzactie + port Charging): reconcilierea nu o inchide.
        $open = $this->chargingSession($user, $station, ended: false);
        $open->update(['ocpp_transaction_id' => (string) $open->id]);
        $this->chargingSession($user, $station, ended: true);
        $other = $this->chargingSession($this->createAppUser(), $station, ended: false);
        $other->update(['ocpp_connector_id' => 2, 'ocpp_transaction_id' => (string) $other->id]);

        $response = $this->actingAs($user, 'api')->getJson('/api/sessions/active')->assertOk();

        $this->assertSame([$open->id], array_column($response->json(), 'id'));
        $this->assertArrayHasKey('billing', $response->json()[0]);
        $this->assertArrayHasKey('telemetry', $response->json()[0]);
    }

    public function test_session_history_cost_does_not_grow_with_old_sessions(): void
    {
        $user = $this->createAppUser();
        $station = $this->station();

        $this->seedHistory($user, $station, 5);
        $few = $this->countQueries(fn () => $this->actingAs($user, 'api')->getJson('/api/sessions')->assertOk());

        $this->seedHistory($user, $station, 60);
        $many = $this->countQueries(function () use ($user) {
            $response = $this->actingAs($user, 'api')->getJson('/api/sessions')->assertOk();
            $this->assertCount(65, $response->json());
        });

        $this->assertLessThanOrEqual($few + 5, $many, "Queries grew from {$few} to {$many} for 60 extra old sessions");
    }

    public function test_replaced_token_still_works_briefly_after_refresh(): void
    {
        $user = $this->createAppUser();
        $oldToken = JWTAuth::fromUser($user);

        $this->withHeader('Authorization', 'Bearer '.$oldToken)->postJson('/api/refresh')->assertOk();
        $this->app['auth']->forgetGuards();

        // O cerere paralela cu tokenul vechi, ajunsa dupa refresh, nu trebuie sa delogheze clientul.
        $this->withHeader('Authorization', 'Bearer '.$oldToken)->getJson('/api/me')->assertOk();
    }

    public function test_replaced_token_expires_after_grace_period(): void
    {
        $user = $this->createAppUser();
        $oldToken = JWTAuth::fromUser($user);

        $this->withHeader('Authorization', 'Bearer '.$oldToken)->postJson('/api/refresh')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->travel(31)->seconds();

        $this->withHeader('Authorization', 'Bearer '.$oldToken)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_logout_ignores_grace_period(): void
    {
        $user = $this->createAppUser();
        $token = JWTAuth::fromUser($user);

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/logout')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/me')->assertUnauthorized();
    }

    private function station(): Station
    {
        return Station::query()->create([
            'name' => 'Statia Istoric',
            'location' => 'Test',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => 'HIST-' . bin2hex(random_bytes(3)),
            'qr_code' => 'HIST-' . bin2hex(random_bytes(3)),
        ]);
    }

    private function chargingSession(User $user, Station $station, bool $ended, int $daysAgo = 0): ChargingSession
    {
        return ChargingSession::query()->create([
            'user_id' => $user->id,
            'station_id' => $station->id,
            'ocpp_connector_id' => 1,
            'start_source' => 'app',
            'start_time' => now()->subDays($daysAgo)->subHour(),
            'end_time' => $ended ? now()->subDays($daysAgo) : null,
            'kwh_consumed' => 0,
            'charge_budget' => 0,
        ]);
    }

    private function seedHistory(User $user, Station $station, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->chargingSession($user, $station, ended: true, daysAgo: 30 + $i);
        }
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
