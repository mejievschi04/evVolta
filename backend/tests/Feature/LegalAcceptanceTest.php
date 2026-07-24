<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LegalAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_legal_config_is_available(): void
    {
        $this->getJson('/api/legal')
            ->assertOk()
            ->assertJsonPath('version', config('legal.version'))
            ->assertJsonPath('terms.title', 'Termeni si conditii')
            ->assertJsonPath('privacy.title', 'Politica de confidentialitate');
    }

    public function test_terms_and_privacy_pages_render(): void
    {
        $this->get('/legal/terms')
            ->assertOk()
            ->assertSee('Termeni si conditii', false);

        $this->get('/legal/privacy')
            ->assertOk()
            ->assertSee('Politica de confidentialitate', false);
    }

    public function test_register_requires_terms_acceptance(): void
    {
        $this->postJson('/api/register', [
            'email' => 'new-user@example.test',
            'password' => 'password123',
            'name' => 'Utilizator Nou',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accept_terms');

        $this->postJson('/api/register', [
            'email' => 'new-user@example.test',
            'password' => 'password123',
            'name' => 'Utilizator Nou',
            'accept_terms' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('user.email', 'new-user@example.test');

        $user = User::query()->where('email', 'new-user@example.test')->first();
        $this->assertNotNull($user?->legal_accepted_at);
        $this->assertSame(config('legal.version'), $user?->legal_version);
    }

    public function test_login_requires_terms_acceptance(): void
    {
        $this->createAppUser([
            'email' => 'driver@example.test',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'driver@example.test',
            'password' => 'password123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accept_terms');

        $this->postJson('/api/login', [
            'email' => 'driver@example.test',
            'password' => 'password123',
            'accept_terms' => true,
        ])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'user']);
    }

    public function test_login_records_legal_acceptance_for_legacy_users(): void
    {
        $user = $this->createAppUser([
            'email' => 'legacy@example.test',
            'password' => Hash::make('password123'),
        ]);

        $this->assertNull($user->legal_accepted_at);

        $this->postJson('/api/login', [
            'email' => 'legacy@example.test',
            'password' => 'password123',
            'accept_terms' => true,
        ])->assertOk();

        $user->refresh();
        $this->assertNotNull($user->legal_accepted_at);
        $this->assertSame(config('legal.version'), $user->legal_version);
    }
}
