<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForcedAppUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_nothing_is_blocked_while_min_version_is_unset(): void
    {
        config(['mobile.min_version' => null]);

        $this->getJson('/api/stations')->assertOk();
        $this->getJson('/api/app/config')->assertOk()->assertJsonPath('min_version', null);
    }

    public function test_outdated_or_unversioned_apps_get_426_with_store_link(): void
    {
        config(['mobile.min_version' => '1.0.6']);

        // Builds from before this check send no version header.
        $this->withHeaders(['User-Agent' => 'okhttp/4.12.0'])
            ->getJson('/api/stations')
            ->assertStatus(426)
            ->assertJsonPath('code', 'update_required')
            ->assertJsonPath('min_version', '1.0.6')
            ->assertJsonPath('store_url', config('mobile.store_urls.android'));

        $this->withHeaders(['X-App-Version' => '1.0.5', 'X-App-Platform' => 'ios'])
            ->postJson('/api/login', ['email' => 'a@b.md', 'password' => 'x', 'accept_terms' => true])
            ->assertStatus(426)
            ->assertJsonPath('store_url', config('mobile.store_urls.ios'));
    }

    public function test_current_and_newer_apps_pass(): void
    {
        config(['mobile.min_version' => '1.0.6']);

        foreach (['1.0.6', '1.0.10', '1.1.0', '2.0.0'] as $version) {
            $this->withHeaders(['X-App-Version' => $version, 'X-App-Platform' => 'android'])
                ->getJson('/api/stations')
                ->assertOk();
        }
    }

    public function test_payment_callback_reset_page_and_config_are_never_blocked(): void
    {
        config(['mobile.min_version' => '9.9.9']);

        $this->getJson('/api/app/config')
            ->assertOk()
            ->assertJsonPath('min_version', '9.9.9');
        $this->get('/api/password/reset/sometoken?email=a@b.md')->assertOk();
        // Unsigned callback is refused by its own check (403), not by the version gate (426).
        $this->postJson('/api/maib/callback', ['orderId' => 'x'])->assertStatus(403);
    }
}
