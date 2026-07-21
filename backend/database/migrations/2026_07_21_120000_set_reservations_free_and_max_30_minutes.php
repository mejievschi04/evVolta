<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stations')) {
            return;
        }

        DB::table('stations')->update([
            'reservation_fee' => 0,
            'reservation_no_show_fee' => 0,
            'reservation_max_duration_minutes' => 30,
        ]);
    }

    public function down(): void
    {
        // Intentionally empty — previous per-station values are not restored.
    }
};
