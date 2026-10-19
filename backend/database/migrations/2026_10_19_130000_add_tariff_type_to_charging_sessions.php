<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charging_sessions', function (Blueprint $table) {
            $table->string('tariff_type', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('charging_sessions', function (Blueprint $table) {
            $table->dropColumn('tariff_type');
        });
    }
};
