<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('users')->count() > 900000) {
            throw new RuntimeException('The six-digit user ID range is exhausted.');
        }

        Schema::create('user_id_counter', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('last_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('public_id')->nullable()->unique();
        });

        $lastId = 99999;
        DB::table('users')->orderBy('id')->chunkById(500, function ($users) use (&$lastId) {
            foreach ($users as $user) {
                DB::table('users')->where('id', $user->id)->update(['public_id' => ++$lastId]);
            }
        });
        DB::table('user_id_counter')->insert(['id' => 1, 'last_id' => $lastId]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
        Schema::dropIfExists('user_id_counter');
    }
};
