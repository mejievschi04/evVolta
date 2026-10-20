<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_reset_flow_changes_password(): void
    {
        Notification::fake();
        $user = $this->createAppUser([
            'email' => 'forgot@example.test',
            'password' => Hash::make('oldpass123'),
        ]);

        $this->postJson('/api/password/forgot', ['email' => 'forgot@example.test'])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token, $user) {
            $token = $notification->token;
            $mail = $notification->toMail($user);

            return $mail->subject === 'Resetare parola - V Charge'
                && str_contains($mail->actionUrl, '/api/password/reset/'.$token);
        });

        $this->get('/api/password/reset/'.$token.'?email=forgot@example.test')
            ->assertOk()
            ->assertSee('Seteaza o parola noua')
            ->assertHeader('Content-Security-Policy');

        $this->post('/api/password/reset', [
            'token' => $token,
            'email' => 'forgot@example.test',
            'password' => 'newpass456',
            'password_confirmation' => 'newpass456',
        ])->assertOk()->assertSee('Parola a fost schimbata');

        $this->assertTrue(Hash::check('newpass456', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_reset', 'subject_id' => $user->id]);

        // The token is single-use.
        $this->post('/api/password/reset', [
            'token' => $token,
            'email' => 'forgot@example.test',
            'password' => 'another789',
            'password_confirmation' => 'another789',
        ])->assertStatus(422)->assertSee('Link invalid sau expirat');
    }

    public function test_forgot_does_not_reveal_whether_account_exists(): void
    {
        Notification::fake();
        $admin = $this->createAdminUser(['email' => 'boss@example.test']);

        $unknown = $this->postJson('/api/password/forgot', ['email' => 'nobody@example.test'])->assertOk()->json('message');
        $adminAnswer = $this->postJson('/api/password/forgot', ['email' => 'boss@example.test'])->assertOk()->json('message');

        $this->assertSame($unknown, $adminAnswer);
        Notification::assertNothingSent();
        $this->assertNotNull($admin);
    }

    public function test_weak_or_mismatched_password_is_rejected_on_the_form(): void
    {
        $this->createAppUser(['email' => 'weak@example.test']);

        $this->post('/api/password/reset', [
            'token' => 'whatever',
            'email' => 'weak@example.test',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertStatus(422)->assertSee('class="error"', false);
    }

    public function test_login_is_throttled_per_account(): void
    {
        $this->createAppUser(['email' => 'target@example.test']);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', [
                'email' => 'target@example.test',
                'password' => 'wrong-'.$i,
                'accept_terms' => true,
            ])->assertUnauthorized();
        }

        $this->postJson('/api/login', [
            'email' => 'target@example.test',
            'password' => 'wrong-again',
            'accept_terms' => true,
        ])->assertStatus(429)->assertJsonPath('message', 'Prea multe incercari. Incearca din nou peste un minut.');
    }

    public function test_password_change_revokes_existing_tokens(): void
    {
        $user = $this->createAppUser([
            'email' => 'stolen@example.test',
            'password' => Hash::make('oldpass123'),
        ]);

        $oldToken = $this->postJson('/api/login', [
            'email' => 'stolen@example.test',
            'password' => 'oldpass123',
            'accept_terms' => true,
        ])->assertOk()->json('access_token');
        $this->withHeader('Authorization', 'Bearer '.$oldToken)->getJson('/api/me')->assertOk();

        $user->fresh()->forceFill(['password' => 'newpass456'])->save();
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$oldToken)->getJson('/api/me')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$oldToken)->postJson('/api/refresh')->assertUnauthorized();
        $this->app['auth']->forgetGuards();

        $newToken = $this->postJson('/api/login', [
            'email' => 'stolen@example.test',
            'password' => 'newpass456',
            'accept_terms' => true,
        ])->assertOk()->json('access_token');
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$newToken)->getJson('/api/me')->assertOk();
        $refreshed = $this->withHeader('Authorization', 'Bearer '.$newToken)->postJson('/api/refresh')->assertOk()->json('access_token');
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$refreshed)->getJson('/api/me')->assertOk();
    }

    public function test_tokens_without_version_claim_keep_working_until_password_changes(): void
    {
        $user = $this->createAppUser();
        $legacyToken = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($user);

        $this->withHeader('Authorization', 'Bearer '.$legacyToken)->getJson('/api/me')->assertOk();
    }
}
