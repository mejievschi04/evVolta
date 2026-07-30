<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('legal_accepted_ip', 64)->nullable()->after('legal_version');
            $table->string('legal_accepted_user_agent', 512)->nullable()->after('legal_accepted_ip');
            $table->string('legal_accepted_source', 32)->nullable()->after('legal_accepted_user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'legal_accepted_ip',
                'legal_accepted_user_agent',
                'legal_accepted_source',
            ]);
        });
    }
};
