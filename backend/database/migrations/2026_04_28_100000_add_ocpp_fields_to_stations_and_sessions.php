<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table): void {
            $table->string('ocpp_identity')->nullable()->unique()->after('qr_code');
            $table->string('ocpp_version', 20)->default('1.6J')->after('ocpp_identity');
            $table->string('ocpp_connection_status', 30)->default('not_configured')->after('ocpp_version');
            $table->timestamp('last_heartbeat_at')->nullable()->after('ocpp_connection_status');
            $table->timestamp('last_ocpp_message_at')->nullable()->after('last_heartbeat_at');
            $table->decimal('meter_value_kwh', 12, 3)->nullable()->after('last_ocpp_message_at');
            $table->json('ocpp_configuration')->nullable()->after('meter_value_kwh');
        });

        Schema::table('charging_sessions', function (Blueprint $table): void {
            $table->string('ocpp_transaction_id')->nullable()->after('station_id');
            $table->string('ocpp_id_tag')->nullable()->after('ocpp_transaction_id');
            $table->decimal('meter_start_kwh', 12, 3)->nullable()->after('ocpp_id_tag');
            $table->decimal('meter_stop_kwh', 12, 3)->nullable()->after('meter_start_kwh');
            $table->string('start_source', 30)->default('app')->after('meter_stop_kwh');
            $table->string('stop_source', 30)->nullable()->after('start_source');

            $table->index('ocpp_transaction_id');
            $table->index(['station_id', 'ocpp_transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::table('charging_sessions', function (Blueprint $table): void {
            $table->dropIndex(['ocpp_transaction_id']);
            $table->dropIndex(['station_id', 'ocpp_transaction_id']);
            $table->dropColumn([
                'ocpp_transaction_id',
                'ocpp_id_tag',
                'meter_start_kwh',
                'meter_stop_kwh',
                'start_source',
                'stop_source',
            ]);
        });

        Schema::table('stations', function (Blueprint $table): void {
            $table->dropUnique(['ocpp_identity']);
            $table->dropColumn([
                'ocpp_identity',
                'ocpp_version',
                'ocpp_connection_status',
                'last_heartbeat_at',
                'last_ocpp_message_at',
                'meter_value_kwh',
                'ocpp_configuration',
            ]);
        });
    }
};
