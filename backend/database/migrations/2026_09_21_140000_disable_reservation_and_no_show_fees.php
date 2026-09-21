<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('stations')->update([
            'reservation_fee' => 0,
            'reservation_no_show_fee' => 0,
        ]);

        DB::table('reservations')->update([
            'fee_amount' => 0,
            'no_show_fee_amount' => 0,
        ]);
    }

    public function down(): void
    {
        // Fees were removed product-wide; no safe restore values.
    }
};
