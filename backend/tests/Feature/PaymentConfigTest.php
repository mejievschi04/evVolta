<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_receives_card_payment_config(): void
    {
        config([
            'services.payment.provider' => 'maib',
            'services.maib.project_key' => 'test-project',
            'services.maib.project_secret' => 'test-secret',
        ]);

        $user = $this->createAppUser([
            'email' => 'customer@example.test',
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/payments/config')
            ->assertOk()
            ->assertJsonPath('provider', 'maib')
            ->assertJsonPath('card_payments_enabled', true)
            ->assertJsonPath('account_type', 'customer');
    }

    public function test_personal_user_has_prepaid_wallet_enabled_like_customers(): void
    {
        config([
            'services.payment.provider' => 'maib',
            'services.maib.project_key' => 'test-project',
            'services.maib.project_secret' => 'test-secret',
        ]);

        $user = $this->createPersonalUser([
            'email' => 'personal@example.test',
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/payments/config')
            ->assertOk()
            ->assertJsonPath('card_payments_enabled', true)
            ->assertJsonPath('account_type', 'personal');
    }
}
