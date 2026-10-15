<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charging_sessions', function (Blueprint $table): void {
            // Tariff at start; null for older sessions (billed at the current tariff, as before).
            $table->decimal('price_per_kwh', 10, 4)->nullable()->after('target_kwh');
        });
    }

    public function down(): void
    {
        Schema::table('charging_sessions', function (Blueprint $table): void {
            $table->dropColumn('price_per_kwh');
        });
    }
};
