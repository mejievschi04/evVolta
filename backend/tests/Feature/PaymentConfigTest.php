<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_receives_card_payment_config(): void
    {
        config(['services.stripe.secret' => 'sk_test_example']);

        $user = $this->createAppUser([
            'email' => 'customer@example.test',
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/payments/config')
            ->assertOk()
            ->assertJsonPath('provider', 'stripe')
            ->assertJsonPath('card_payments_enabled', true)
            ->assertJsonPath('account_type', 'customer');
    }

    public function test_personal_user_has_card_payments_disabled(): void
    {
        config(['services.stripe.secret' => 'sk_test_example']);

        $user = $this->createPersonalUser([
            'email' => 'personal@example.test',
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/payments/config')
            ->assertOk()
            ->assertJsonPath('card_payments_enabled', false)
            ->assertJsonPath('account_type', 'personal');
    }
}
