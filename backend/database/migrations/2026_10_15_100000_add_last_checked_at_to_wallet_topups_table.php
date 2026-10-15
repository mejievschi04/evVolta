<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_topups', function (Blueprint $table): void {
            $table->timestamp('last_checked_at')->nullable()->after('paid_at');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('wallet_topups', function (Blueprint $table): void {
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn('last_checked_at');
        });
    }
};
