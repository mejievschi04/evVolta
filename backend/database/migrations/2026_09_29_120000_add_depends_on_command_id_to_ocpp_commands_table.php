<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocpp_commands', function (Blueprint $table): void {
            $table->foreignId('depends_on_command_id')
                ->nullable()
                ->after('charging_session_id')
                ->constrained('ocpp_commands')
                ->nullOnDelete();

            $table->index(['depends_on_command_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('ocpp_commands', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('depends_on_command_id');
        });
    }
};
