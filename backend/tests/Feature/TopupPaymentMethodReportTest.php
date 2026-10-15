<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WalletTopup;
use App\Services\ReportDocumentService;
use App\Services\WalletService;
use App\Support\LocalTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopupPaymentMethodReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_method_is_stored_when_credited_and_filled_later_if_missing(): void
    {
        config(['billing.prepaid_wallet_enabled' => true]);
        $user = $this->createAppUser(['wallet_balance' => 0]);
        $topup = $this->topup($user, 'pending');

        $wallet = app(WalletService::class);
        $wallet->creditTopup($topup, 'chk-1', 'pay-1');
        $this->assertNull($topup->fresh()->payment_method);

        // A later callback brings the method without crediting twice.
        $wallet->creditTopup($topup->fresh(), 'chk-1', 'pay-1', 'MiaQr');
        $this->assertSame('MiaQr', $topup->fresh()->payment_method);
        $this->assertSame(100.0, (float) $user->fresh()->wallet_balance);
    }

    public function test_method_labels(): void
    {
        $this->assertSame('Card', WalletTopup::paymentMethodLabel('Card'));
        $this->assertSame('MIA', WalletTopup::paymentMethodLabel('MiaQr'));
        $this->assertSame('Nespecificat', WalletTopup::paymentMethodLabel(null));
    }

    public function test_report_lists_each_payment_with_method_payer_and_total(): void
    {
        $ion = $this->createAppUser(['name' => 'Ion Popescu', 'email' => 'ion@x.md']);
        $ana = $this->createAppUser(['name' => 'Ana Rusu', 'email' => 'ana@x.md']);
        $this->topup($ion, 'paid', 'Card', '2026-10-15 07:00:00', 100);
        $this->topup($ana, 'paid', 'MiaQr', '2026-10-15 09:30:00', 250);
        $this->topup($ion, 'pending', null, '2026-10-15 10:00:00', 500);

        $method = new \ReflectionMethod(ReportDocumentService::class, 'topupRows');
        $rows = $method->invoke(
            app(ReportDocumentService::class),
            LocalTime::day('2026-10-15'),
            LocalTime::day('2026-10-15')->endOfDay()
        )->all();

        $this->assertCount(2, $rows, 'Only paid top-ups');
        $this->assertSame(['10:00', 'Ion Popescu', 'Card', 100.0], [substr($rows[0]['paid_at'], -5), $rows[0]['user_name'], $rows[0]['method'], $rows[0]['amount']]);
        $this->assertSame(['12:30', 'Ana Rusu', 'MIA', 250.0], [substr($rows[1]['paid_at'], -5), $rows[1]['user_name'], $rows[1]['method'], $rows[1]['amount']]);

        $admin = $this->createAdminUser();
        $this->withSession(['backoffice_user_id' => $admin->id])
            ->get('/backoffice/reports/wallet-topups?from=2026-10-15&to=2026-10-15')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('Content-Disposition', 'attachment; filename="raport-alimentari-20261015-20261015.xlsx"');
    }

    private function topup(User $user, string $status, ?string $method = null, ?string $paidAtUtc = null, float $amount = 100): WalletTopup
    {
        return WalletTopup::query()->create([
            'user_id' => $user->id,
            'amount' => $amount,
            'currency' => 'MDL',
            'status' => $status,
            'payment_provider' => 'maib',
            'payment_method' => $method,
            'paid_at' => $paidAtUtc ? Carbon::parse($paidAtUtc, 'UTC') : null,
        ]);
    }
}
