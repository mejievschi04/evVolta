<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocpp_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('station_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 20);
            $table->string('message_uid', 80)->nullable();
            $table->string('action', 80)->nullable();
            $table->string('status', 30)->default('received');
            $table->string('error_code', 80)->nullable();
            $table->text('error_description')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['station_id', 'created_at']);
            $table->index(['message_uid', 'direction']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocpp_messages');
    }
};
