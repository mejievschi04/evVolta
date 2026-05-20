<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('invoice_type')->default('monthly')->after('month');
            $table->string('invoice_number')->nullable()->unique()->after('invoice_type');
            $table->foreignId('source_session_id')->nullable()->unique()->after('user_id')
                ->constrained('charging_sessions')
                ->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'month']);
            $table->unique(['user_id', 'month', 'invoice_type']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'month', 'invoice_type']);
            $table->dropUnique(['invoice_number']);
            $table->dropUnique(['source_session_id']);
            $table->dropConstrainedForeignId('source_session_id');
            $table->dropColumn(['invoice_type', 'invoice_number']);
            $table->unique(['user_id', 'month']);
        });
    }
};
