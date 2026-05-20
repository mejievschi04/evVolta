<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->decimal('power_kw', 6, 2)->nullable()->after('location');
            $table->string('connector_type')->nullable()->after('power_kw');
            $table->string('currency', 8)->default('MDL')->after('connector_type');
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn(['power_kw', 'connector_type', 'currency']);
        });
    }
};
