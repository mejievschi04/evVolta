<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('wallet_balance', 12, 2)->default(0)->after('currency');
        });

        Schema::table('charging_sessions', function (Blueprint $table) {
            $table->decimal('charge_budget', 12, 2)->nullable()->after('kwh_consumed');
        });

        Schema::create('wallet_topups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('MDL');
            $table->string('status', 20)->default('pending');
            $table->string('payment_provider')->nullable();
            $table->string('payment_session_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_topups');

        Schema::table('charging_sessions', function (Blueprint $table) {
            $table->dropColumn('charge_budget');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('wallet_balance');
        });
    }
};
