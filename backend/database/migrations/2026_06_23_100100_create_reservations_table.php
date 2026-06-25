<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
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

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
