<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(static fn () => Password::min(8)->letters()->numbers());

        RateLimiter::for('auth', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email', '')));

            return Limit::perMinute(60)->by(($email !== '' ? $email : 'guest').'|'.$request->ip());
        });

        // Password guessing: per account across all IPs, plus per IP across accounts.
        RateLimiter::for('login', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email', '')));

            return [
                Limit::perMinute(10)->by('login-email|'.$email)->response(self::tooManyAttempts(...)),
                Limit::perMinute(30)->by('login-ip|'.$request->ip())->response(self::tooManyAttempts(...)),
            ];
        });

        RateLimiter::for('password-reset', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email', '')));

            return [
                Limit::perMinute(3)->by('reset-email|'.$email)->response(self::tooManyAttempts(...)),
                Limit::perMinute(10)->by('reset-ip|'.$request->ip())->response(self::tooManyAttempts(...)),
            ];
        });

        ResetPassword::toMailUsing(function ($user, string $token) {
            $minutes = (int) config('auth.passwords.users.expire', 60);

            return (new MailMessage)
                ->subject('Resetare parola - V CHARGE')
                ->greeting('Buna'.($user->name ? ', '.$user->name : '').'.')
                ->line('Am primit o cerere de resetare a parolei pentru contul tau V CHARGE.')
                // Served under /api: the only prefix every deploy's nginx routes to PHP.
                ->action('Seteaza o parola noua', url('/api/password/reset/'.$token).'?email='.urlencode($user->email))
                ->line("Linkul este valabil {$minutes} de minute.")
                ->line('Daca nu ai cerut resetarea, ignora acest email. Parola ta ramane neschimbata.')
                ->salutation('Echipa V CHARGE');
        });
    }

    private static function tooManyAttempts(Request $request, array $headers): \Symfony\Component\HttpFoundation\Response
    {
        return response()->json([
            'message' => 'Prea multe incercari. Incearca din nou peste un minut.',
        ], 429, $headers);
    }
}
