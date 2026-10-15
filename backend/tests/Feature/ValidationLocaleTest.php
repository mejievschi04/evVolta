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

        $this->postJson('/api/register', ['email' => 'bad', 'password' => 'x', 'accept_terms' => true])
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
}
