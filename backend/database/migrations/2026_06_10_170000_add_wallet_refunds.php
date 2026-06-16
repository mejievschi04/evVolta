<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_topups', function (Blueprint $table) {
            $table->string('payment_intent_id')->nullable()->after('payment_session_id');
            $table->decimal('amount_refunded', 12, 2)->default(0)->after('amount');
        });

        Schema::create('wallet_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_topup_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('MDL');
            $table->string('status', 20)->default('completed');
            $table->string('payment_provider')->nullable();
            $table->string('stripe_refund_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_refunds');

        Schema::table('wallet_topups', function (Blueprint $table) {
            $table->dropColumn(['payment_intent_id', 'amount_refunded']);
        });
    }
};
