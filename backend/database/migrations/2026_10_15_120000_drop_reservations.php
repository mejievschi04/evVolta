<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Reservations were removed from the product. Before dropping the data, release any connector
 * a charger may still hold for a reservation, so ports do not stay blocked after deploy.
 */
return new class extends Migration
{
    private const STATION_COLUMNS = [
        'reservations_enabled',
        'reservation_require_for_start',
        'reservation_fee',
        'reservation_no_show_fee',
        'reservation_max_duration_minutes',
        'reservation_advance_days',
        'reservation_grace_minutes',
    ];

    public function up(): void
    {
        if (Schema::hasTable('reservations')) {
            $now = now();

            // A queued ReserveNow would otherwise reach the charger after deploy and block the port.
            DB::table('ocpp_commands')
                ->where('action', 'ReserveNow')
                ->where('status', 'pending')
                ->update([
                    'status' => 'failed',
                    'error_message' => 'Rezervarile au fost eliminate.',
                    'acknowledged_at' => $now,
                    'updated_at' => $now,
                ]);

            $blocking = DB::table('reservations')
                ->join('stations', 'stations.id', '=', 'reservations.station_id')
                ->whereIn('reservations.status', ['pending', 'confirmed', 'active'])
                ->where('reservations.ends_at', '>', $now)
                ->whereNotNull('stations.ocpp_identity')
                ->get(['reservations.station_id', 'reservations.ocpp_reservation_id']);

            foreach ($blocking as $reservation) {
                // Same row OcppService::queueOutboundCommand() writes; delivered when the station is connected.
                DB::table('ocpp_commands')->insert([
                    'station_id' => $reservation->station_id,
                    'message_uid' => (string) Str::uuid(),
                    'action' => 'CancelReservation',
                    'status' => 'pending',
                    'available_at' => $now,
                    'payload' => json_encode(['reservationId' => (int) $reservation->ocpp_reservation_id]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            Schema::drop('reservations');
        }

        $columns = array_values(array_filter(
            self::STATION_COLUMNS,
            fn (string $column) => Schema::hasColumn('stations', $column),
        ));

        if ($columns !== []) {
            Schema::table('stations', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    public function down(): void
    {
        // Schema only; the dropped reservation history cannot be restored.
        Schema::table('stations', function (Blueprint $table): void {
            $table->boolean('reservations_enabled')->default(false)->after('currency');
            $table->boolean('reservation_require_for_start')->default(false)->after('reservations_enabled');
            $table->decimal('reservation_fee', 10, 2)->default(0)->after('reservation_require_for_start');
            $table->decimal('reservation_no_show_fee', 10, 2)->default(0)->after('reservation_fee');
            $table->unsignedSmallInteger('reservation_max_duration_minutes')->default(30)->after('reservation_no_show_fee');
            $table->unsignedSmallInteger('reservation_advance_days')->default(14)->after('reservation_max_duration_minutes');
            $table->unsignedSmallInteger('reservation_grace_minutes')->default(20)->after('reservation_advance_days');
        });

        Schema::create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('connector_id');
            $table->unsignedInteger('ocpp_reservation_id');
            $table->string('id_tag', 32);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 32)->default('pending');
            $table->decimal('fee_amount', 10, 2)->default(0);
            $table->boolean('fee_charged')->default(false);
            $table->decimal('no_show_fee_amount', 10, 2)->default(0);
            $table->boolean('no_show_charged')->default(false);
            $table->foreignId('charging_session_id')->nullable()->constrained('charging_sessions')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['station_id', 'connector_id', 'status', 'starts_at', 'ends_at']);
            $table->index(['user_id', 'status', 'starts_at']);
            $table->unique(['station_id', 'ocpp_reservation_id']);
        });
    }
};
