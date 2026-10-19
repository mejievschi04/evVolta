<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserPublicIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_ids_are_six_digits_unique_and_not_reused_after_deletion(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $first->delete();
        $third = User::factory()->create();

        $ids = [$first->public_id, $second->public_id, $third->public_id];
        $this->assertCount(3, array_unique($ids));
        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression('/^[1-9][0-9]{5}$/', (string) $id);
        }
        $second->update(['name' => 'Updated']);
        $this->assertSame($ids[1], $second->fresh()->public_id);
        $this->assertSame($ids[1], $second->toArray()['public_id']);
    }

    public function test_migration_assigns_ids_to_existing_and_deleted_users(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $second->delete();
        $migration = require database_path('migrations/2026_10_19_120000_add_public_id_to_users.php');
        $migration->down();
        $migration->up();

        $this->assertSame(100000, $first->fresh()->public_id);
        $this->assertSame(100001, User::withTrashed()->findOrFail($second->id)->public_id);
        $this->assertSame(100002, User::factory()->create()->public_id);
    }

    public function test_schema_accepts_user_inserts_from_older_running_processes(): void
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Legacy worker', 'email' => 'legacy-worker@example.test',
            'password' => 'test-only-unused-password',
        ]);
        $this->assertNull(DB::table('users')->where('id', $id)->value('public_id'));
        $newUser = User::factory()->create();
        $this->assertMatchesRegularExpression('/^[1-9][0-9]{5}$/', (string) $newUser->public_id);
    }

    public function test_exhaustion_fails_without_creating_a_user(): void
    {
        DB::table('user_id_counter')->where('id', 1)->update(['last_id' => 999999]);
        $this->expectException(\RuntimeException::class);
        User::factory()->create();
    }
}
