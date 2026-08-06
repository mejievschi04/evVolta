<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LegalAcceptanceService
{
    public function currentVersion(): string
    {
        return (string) config('legal.version');
    }

    public function hasCurrentAcceptance(User $user): bool
    {
        if ($user->isAnonymized()) {
            return false;
        }

        return $user->legal_accepted_at !== null
            && $user->legal_version === $this->currentVersion();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function assertAccepted(array $data): void
    {
        if (! filter_var($data['accept_terms'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ValidationException::withMessages([
                'accept_terms' => 'Trebuie sa accepti Termenii si Politica de confidentialitate.',
            ]);
        }
    }

    public function recordAcceptance(User $user, ?Request $request = null, string $source = 'mobile_api'): void
    {
        $user->forceFill([
            'legal_accepted_at' => now(),
            'legal_version' => $this->currentVersion(),
            'legal_accepted_ip' => $request?->ip(),
            'legal_accepted_user_agent' => mb_substr((string) $request?->userAgent(), 0, 512) ?: null,
            'legal_accepted_source' => $source,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function publicConfig(Request $request): array
    {
        return [
            'version' => $this->currentVersion(),
            'company_name' => config('legal.company_name'),
            'app_name' => config('legal.app_name', config('legal.company_name')),
            'contact_email' => config('legal.contact_email'),
            'support_phone' => config('legal.support_phone'),
            'effective_date' => config('legal.effective_date'),
            'rights_sla_days' => (int) config('privacy.rights_sla_days', 30),
            'supervisory_authority' => config('privacy.supervisory_authority'),
            'terms' => [
                'title' => 'Termeni si conditii',
                'url' => 'https://v-charge.volta.md/termeni.html',
            ],
            'privacy' => [
                'title' => 'Politica de confidentialitate',
                'url' => 'https://v-charge.volta.md/confidentialitate.html',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function statusForUser(User $user, Request $request): array
    {
        $config = $this->publicConfig($request);

        return [
            ...$config,
            'accepted' => $this->hasCurrentAcceptance($user),
            'accepted_version' => $user->legal_version,
            'accepted_at' => optional($user->legal_accepted_at)?->toIso8601String(),
            'required' => ! $this->hasCurrentAcceptance($user),
        ];
    }
}
