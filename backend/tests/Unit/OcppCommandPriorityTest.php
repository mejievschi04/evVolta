<?php

namespace Tests\Unit;

use App\Models\OcppCommand;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class OcppCommandPriorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_priority_sends_start_and_stop_before_trigger_message(): void
    {
        $station = Station::query()->create([
            'name' => 'Priority test',
            'location' => 'Depot',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:priority-test',
            'ocpp_identity' => 'priority-test',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
        ]);

        OcppCommand::query()->create([
            'station_id' => $station->id,
            'message_uid' => 'trigger-1',
            'action' => 'TriggerMessage',
            'status' => OcppCommand::STATUS_PENDING,
            'payload' => ['requestedMessage' => 'StatusNotification', 'connectorId' => 1],
        ]);
        OcppCommand::query()->create([
            'station_id' => $station->id,
            'message_uid' => 'trigger-2',
            'action' => 'TriggerMessage',
            'status' => OcppCommand::STATUS_PENDING,
            'payload' => ['requestedMessage' => 'StatusNotification', 'connectorId' => 2],
        ]);
        OcppCommand::query()->create([
            'station_id' => $station->id,
            'message_uid' => 'start-1',
            'action' => 'RemoteStartTransaction',
            'status' => OcppCommand::STATUS_PENDING,
            'payload' => ['connectorId' => 2, 'idTag' => 'A5CD0CBD'],
        ]);
        OcppCommand::query()->create([
            'station_id' => $station->id,
            'message_uid' => 'stop-1',
            'action' => 'RemoteStopTransaction',
            'status' => OcppCommand::STATUS_PENDING,
            'payload' => ['transactionId' => 42],
        ]);

        $next = OcppCommand::query()
            ->where('station_id', $station->id)
            ->readyToSend()
            ->orderByDispatchPriority()
            ->limit(2)
            ->pluck('action')
            ->all();

        $this->assertSame(['RemoteStartTransaction', 'RemoteStopTransaction'], $next);
    }

    public function test_dispatch_batch_size_config_limits_pending_commands(): void
    {
        Config::set('services.ocpp.command_batch_size', 3);

        $station = Station::query()->create([
            'name' => 'Batch test',
            'location' => 'Depot',
            'status' => Station::STATUS_AVAILABLE,
            'qr_code' => 'station:batch-test',
            'ocpp_identity' => 'batch-test',
            'ocpp_version' => '1.6J',
            'ocpp_connection_status' => Station::OCPP_CONNECTION_CONNECTED,
        ]);

        foreach (range(1, 5) as $index) {
            OcppCommand::query()->create([
                'station_id' => $station->id,
                'message_uid' => "trigger-{$index}",
                'action' => 'TriggerMessage',
                'status' => OcppCommand::STATUS_PENDING,
                'payload' => ['requestedMessage' => 'MeterValues'],
            ]);
        }

        $batch = OcppCommand::query()
            ->where('station_id', $station->id)
            ->readyToSend()
            ->orderByDispatchPriority()
            ->limit(max(1, (int) config('services.ocpp.command_batch_size', 6)))
            ->get();

        $this->assertCount(3, $batch);
    }
}
