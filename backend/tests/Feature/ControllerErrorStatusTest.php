<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Services\OcppService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class ControllerErrorStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    public function test_database_error_on_start_is_reported_and_not_leaked(): void
    {
        Exceptions::fake();
        $user = $this->createAppUser();
        $station = $this->station();

        $this->mock(OcppService::class)
            ->shouldReceive('syncConnectorStateBeforeStart')
            ->andThrow($this->deadlock());

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/charging/start', ['station_id' => $station->id]);

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('charging_sessions', $response->getContent());
        // Logul trebuie sa contina cauza reala, nu un TypeError despre status HTTP.
        Exceptions::assertReported(QueryException::class);
        Exceptions::assertNotReported(\TypeError::class);
        Exceptions::assertNotReported(\InvalidArgumentException::class);
    }

    public function test_business_error_with_invalid_code_falls_back_to_valid_status(): void
    {
        $user = $this->createAppUser();
        $station = $this->station();

        $this->mock(OcppService::class)
            ->shouldReceive('syncConnectorStateBeforeStart')
            ->andThrow(new RuntimeException('Statia nu raspunde.', 23505));

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/start', ['station_id' => $station->id])
            ->assertStatus(500)
            ->assertJson(['message' => 'Statia nu raspunde.']);
    }

    public function test_business_error_keeps_its_http_code(): void
    {
        $user = $this->createAppUser();
        $station = $this->station();

        $this->mock(OcppService::class)
            ->shouldReceive('syncConnectorStateBeforeStart')
            ->andThrow(new RuntimeException('Statia nu este conectata.', 422));

        $this->actingAs($user, 'api')
            ->postJson('/api/charging/start', ['station_id' => $station->id])
            ->assertStatus(422)
            ->assertJson(['message' => 'Statia nu este conectata.']);
    }

    private function station(): Station
    {
        return Station::query()->create([
            'name' => 'Statia Erori',
            'location' => 'Test',
            'status' => Station::STATUS_AVAILABLE,
            'ocpp_identity' => 'ERR-' . bin2hex(random_bytes(3)),
            'qr_code' => 'ERR-' . bin2hex(random_bytes(3)),
        ]);
    }

    private function deadlock(): QueryException
    {
        $pdo = new class('SQLSTATE[40P01]: Deadlock detected') extends PDOException {
            public function __construct(string $message)
            {
                parent::__construct($message);
                $this->code = '40P01';
                $this->errorInfo = ['40P01', 7, 'deadlock detected'];
            }
        };

        return new QueryException('pgsql', 'update "charging_sessions" set x = ?', [1], $pdo);
    }
}
