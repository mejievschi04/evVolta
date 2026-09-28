<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('wallet_refunds')
            && Schema::hasColumn('wallet_refunds', 'stripe_refund_id')
            && ! Schema::hasColumn('wallet_refunds', 'provider_refund_id')
        ) {
            Schema::table('wallet_refunds', function (Blueprint $table) {
                $table->renameColumn('stripe_refund_id', 'provider_refund_id');
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('wallet_refunds')
            && Schema::hasColumn('wallet_refunds', 'provider_refund_id')
            && ! Schema::hasColumn('wallet_refunds', 'stripe_refund_id')
        ) {
            Schema::table('wallet_refunds', function (Blueprint $table) {
                $table->renameColumn('provider_refund_id', 'stripe_refund_id');
            });
        }
    }
};
