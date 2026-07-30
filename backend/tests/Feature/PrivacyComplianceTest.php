<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PrivacyComplianceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_export_personal_data(): void
    {
        $user = $this->createAppUser([
            'email' => 'export@example.test',
            'name' => 'Export User',
            'wallet_balance' => 12.5,
        ]);

        $user->forceFill([
            'phone' => '+37360000000',
            'legal_accepted_at' => now(),
            'legal_version' => config('legal.version'),
        ])->save();

        $response = $this->actingAs($user, 'api')
            ->getJson('/api/me/privacy-export')
            ->assertOk()
            ->assertJsonPath('user.email', 'export@example.test')
            ->assertJsonPath('user.phone', '+37360000000')
            ->assertJsonStructure([
                'exported_at',
                'user',
                'sessions',
                'invoices',
                'reservations',
                'wallet_topups',
                'rights',
            ]);

        $this->assertArrayNotHasKey('password', $response->json('user'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'privacy.export',
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_account_deletion_anonymizes_user_and_keeps_invoice(): void
    {
        $user = $this->createAppUser([
            'email' => 'delete-me@example.test',
            'wallet_balance' => 0,
            'name' => 'Client Real',
        ]);
        $user->forceFill([
            'phone' => '+37361111111',
            'legal_accepted_at' => now(),
            'legal_version' => config('legal.version'),
        ])->save();

        $invoice = Invoice::query()->create([
            'user_id' => $user->id,
            'month' => '2026-07',
            'currency' => 'MDL',
            'invoice_type' => 'session',
            'invoice_number' => 'VC-202607-1',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-01',
            'total_kwh' => 5,
            'total_amount' => 20,
            'sessions_count' => 1,
            'status' => 'paid',
            'buyer_name' => 'Client Real',
            'buyer_email' => 'delete-me@example.test',
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/me/delete', [
                'password' => 'password123',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Contul a fost sters.');

        $user = User::withTrashed()->findOrFail($user->id);
        $this->assertNotNull($user->deleted_at);
        $this->assertNotNull($user->anonymized_at);
        $this->assertSame('Utilizator sters', $user->name);
        $this->assertNull($user->phone);
        $this->assertStringContainsString('@anonymized.vcharge.local', $user->email);

        $invoice->refresh();
        $this->assertSame('Utilizator sters', $invoice->buyer_name);
        $this->assertNull($invoice->buyer_email);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    public function test_outdated_legal_version_blocks_service_routes(): void
    {
        $user = $this->createAppUser(['wallet_balance' => 0]);
        $user->forceFill([
            'legal_accepted_at' => now()->subDay(),
            'legal_version' => '2020-01-01',
        ])->save();

        $this->actingAs($user, 'api')
            ->getJson('/api/stations')
            ->assertStatus(428)
            ->assertJsonPath('code', 'LEGAL_ACCEPTANCE_REQUIRED');

        $this->actingAs($user, 'api')
            ->postJson('/api/me/accept-legal', ['accept_terms' => true])
            ->assertOk()
            ->assertJsonPath('legal.accepted', true);

        $this->actingAs($user, 'api')
            ->getJson('/api/stations')
            ->assertOk();
    }

    public function test_me_returns_legal_status(): void
    {
        $user = $this->createAppUser();
        $user->forceFill([
            'legal_accepted_at' => now(),
            'legal_version' => config('legal.version'),
        ])->save();

        $this->actingAs($user, 'api')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('legal.version', config('legal.version'))
            ->assertJsonPath('legal.accepted', true)
            ->assertJsonPath('legal.required', false);
    }

    public function test_privacy_pages_include_export_and_authority(): void
    {
        $this->get('/api/legal/privacy?app=1')
            ->assertOk()
            ->assertSee('Exporta datele mele', false)
            ->assertSee('CNPD', false)
            ->assertSee('anonimizate', false);
    }

    public function test_login_stores_legal_acceptance_evidence(): void
    {
        $user = $this->createAppUser([
            'email' => 'evidence@example.test',
            'password' => Hash::make('password123'),
            'legal_accepted_at' => null,
            'legal_version' => null,
            'legal_accepted_source' => null,
        ]);

        $this->postJson('/api/login', [
            'email' => 'evidence@example.test',
            'password' => 'password123',
            'accept_terms' => true,
        ], [
            'User-Agent' => 'VChargePrivacyTest/1.0',
        ])->assertOk();

        $user->refresh();
        $this->assertNotNull($user->legal_accepted_at);
        $this->assertSame(config('legal.version'), $user->legal_version);
        $this->assertSame('login', $user->legal_accepted_source);
        $this->assertNotNull($user->legal_accepted_ip);
        $this->assertStringContainsString('VChargePrivacyTest', (string) $user->legal_accepted_user_agent);
    }
}
