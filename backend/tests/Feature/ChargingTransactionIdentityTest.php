<?php

namespace Tests\Feature;

use App\Models\ChargingSession;
use Tests\TestCase;

class ChargingTransactionIdentityTest extends TestCase
{
    public function test_preassigned_app_id_does_not_become_a_fake_remote_stop_id(): void
    {
        $session = new ChargingSession();
        $session->setRawAttributes(['id' => 27, 'ocpp_transaction_id' => '27', 'kwh_consumed' => 0]);
        $this->assertFalse($session->hasConfirmedOcppTransaction());
        $this->assertSame(0, $session->remoteStopTransactionId());
        $session->live_metrics = ['assigned_transaction_id' => 27];
        $this->assertTrue($session->hasConfirmedOcppTransaction());
        $this->assertSame(27, $session->remoteStopTransactionId());
    }

    public function test_energy_delivery_prevents_treating_a_session_as_never_started(): void
    {
        $session = new ChargingSession();
        $session->setRawAttributes(['id' => 27, 'ocpp_transaction_id' => null, 'kwh_consumed' => 1.2]);
        $this->assertTrue($session->hasConfirmedOcppTransaction());
        $this->assertSame(0, $session->remoteStopTransactionId());
    }
}
