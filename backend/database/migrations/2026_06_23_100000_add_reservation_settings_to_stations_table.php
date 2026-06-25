<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->boolean('reservations_enabled')->default(false)->after('currency');
            $table->boolean('reservation_require_for_start')->default(false)->after('reservations_enabled');
            $table->decimal('reservation_fee', 10, 2)->default(15)->after('reservation_require_for_start');
            $table->decimal('reservation_no_show_fee', 10, 2)->default(30)->after('reservation_fee');
            $table->unsignedSmallInteger('reservation_max_duration_minutes')->default(120)->after('reservation_no_show_fee');
            $table->unsignedSmallInteger('reservation_advance_days')->default(14)->after('reservation_max_duration_minutes');
            $table->unsignedSmallInteger('reservation_grace_minutes')->default(20)->after('reservation_advance_days');
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn([
                'reservations_enabled',
                'reservation_require_for_start',
                'reservation_fee',
                'reservation_no_show_fee',
                'reservation_max_duration_minutes',
                'reservation_advance_days',
                'reservation_grace_minutes',
            ]);
        });
    }
};
