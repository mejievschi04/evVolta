<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 40)->nullable()->after('email');
            }

            if (! Schema::hasColumn('users', 'anonymized_at')) {
                $table->timestamp('anonymized_at')->nullable()->after('legal_accepted_source');
            }

            if (! Schema::hasColumn('users', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'deleted_at')) {
                $table->dropSoftDeletes();
            }

            $drop = [];
            if (Schema::hasColumn('users', 'anonymized_at')) {
                $drop[] = 'anonymized_at';
            }
            if (Schema::hasColumn('users', 'phone')) {
                $drop[] = 'phone';
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
