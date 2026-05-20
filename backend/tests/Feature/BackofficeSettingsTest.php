<?php

namespace Tests\Feature;

use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BackofficeSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_backoffice_settings_update_profile_and_tariff_keeps_mdl(): void
    {
        $admin = User::query()->create([
            'name' => 'Backoffice Admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password123'),
            'currency' => 'MDL',
        ]);

        $this->withSession([
            'backoffice_user_id' => $admin->id,
            'backoffice_user_name' => $admin->name,
        ])
            ->post('/backoffice/settings', [
                'first_name' => 'Ana',
                'last_name' => 'Popescu',
                'currency' => 'EUR',
                'price_per_kwh' => 0.4300,
            ])
            ->assertStatus(302)
            ->assertSessionHas('backoffice_user_name', 'Ana Popescu');

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'first_name' => 'Ana',
            'last_name' => 'Popescu',
            'currency' => 'MDL',
            'name' => 'Ana Popescu',
        ]);

        $this->assertDatabaseHas('tariffs', [
            'price_per_kwh' => 0.43,
        ]);

        $this->assertSame(1, Tariff::query()->count());
        $this->assertNotNull(Route::getRoutes()->getByName('backoffice.settings.update'));
        $this->assertSame(url('/backoffice/settings'), route('backoffice.settings.update'));
    }
}
