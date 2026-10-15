<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValidationLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_messages_are_romanian(): void
    {
        app()->setLocale('ro');

        $this->postJson('/api/register', ['name' => 'Ion Popescu', 'email' => 'bad', 'password' => 'x', 'accept_terms' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'E-mail trebuie sa fie o adresa de e-mail valida. (si inca 2 erori)')
            ->assertJsonPath('errors.password.0', 'Parola trebuie sa aiba cel putin 8 caractere.');
    }

    public function test_email_differing_only_in_case_is_a_duplicate_not_a_crash(): void
    {
        app()->setLocale('ro');
        $this->createAppUser(['email' => 'ion@x.md']);

        $this->postJson('/api/register', ['email' => '  Ion@X.md ', 'password' => 'abc12345', 'accept_terms' => true])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Exista deja un cont cu acest e-mail.');
    }

    public function test_login_ignores_email_case(): void
    {
        $this->createAppUser(['email' => 'Maria@X.md', 'password' => \Illuminate\Support\Facades\Hash::make('abc12345')]);
        $this->assertDatabaseHas('users', ['email' => 'maria@x.md']);

        $this->postJson('/api/login', ['email' => 'MARIA@x.md', 'password' => 'abc12345', 'accept_terms' => true])
            ->assertOk();
    }

    public function test_registration_requires_a_name(): void
    {
        app()->setLocale('ro');

        $this->postJson('/api/register', ['email' => 'noname@x.md', 'password' => 'abc12345', 'accept_terms' => true])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Campul nume si prenume este obligatoriu.');

        $this->postJson('/api/register', [
            'first_name' => 'Ion',
            'last_name' => 'Popescu',
            'email' => 'named@x.md',
            'password' => 'abc12345',
            'accept_terms' => true,
        ])->assertCreated()->assertJsonPath('user.name', 'Ion Popescu');
    }

    public function test_top_up_requires_a_real_name(): void
    {
        $user = $this->createAppUser(['name' => 'old@x.md']);

        $this->actingAs($user, 'api')
            ->postJson('/api/wallet/topup-checkout', ['amount' => 100])
            ->assertStatus(422)
            ->assertJsonPath('code', 'name_required');

        $this->assertDatabaseMissing('wallet_topups', ['user_id' => $user->id]);
    }
}
