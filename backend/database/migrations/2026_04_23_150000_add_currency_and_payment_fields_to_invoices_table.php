<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('currency', 8)->default('MDL')->after('month');
            $table->string('payment_provider')->nullable()->after('status');
            $table->string('payment_session_id')->nullable()->unique()->after('payment_provider');
            $table->timestamp('paid_at')->nullable()->after('payment_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['payment_session_id']);
            $table->dropColumn(['currency', 'payment_provider', 'payment_session_id', 'paid_at']);
        });
    }
};
