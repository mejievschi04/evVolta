<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_number_counters', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
        });

        $max = 0;
        if (Schema::hasTable('invoices')) {
            foreach (DB::table('invoices')->whereNotNull('invoice_number')->pluck('invoice_number') as $number) {
                if (preg_match('/^\d{1,7}$/', (string) $number)) {
                    $max = max($max, (int) $number);
                }
            }
        }

        DB::table('invoice_number_counters')->insert([
            'id' => 1,
            'next_value' => $max + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_number_counters');
    }
};
