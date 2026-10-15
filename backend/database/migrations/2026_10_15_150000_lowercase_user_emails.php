<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Logins and uniqueness compare emails exactly, so stored emails must be lowercase.
 * Rows whose lowercase form already belongs to another account are left as-is and logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('users')
            ->whereNotNull('email')
            ->whereRaw('email <> lower(email)')
            ->get(['id', 'email']);

        foreach ($rows as $row) {
            $lower = strtolower(trim((string) $row->email));

            $taken = DB::table('users')
                ->where('email', $lower)
                ->where('id', '!=', $row->id)
                ->exists();

            if ($taken) {
                Log::warning('users.email_lowercase_conflict', ['user_id' => $row->id, 'email' => $row->email]);

                continue;
            }

            DB::table('users')->where('id', $row->id)->update(['email' => $lower]);
        }
    }

    public function down(): void
    {
        // Original casing is not needed and not restored.
    }
};
