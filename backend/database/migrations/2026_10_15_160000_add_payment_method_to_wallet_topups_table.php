<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_topups', function (Blueprint $table): void {
            // MAIB paymentMethod as reported (e.g. "Card", "MiaQr"); null for older top-ups until backfilled.
            $table->string('payment_method', 32)->nullable()->after('payment_provider');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_topups', function (Blueprint $table): void {
            $table->dropColumn('payment_method');
        });
    }
};
