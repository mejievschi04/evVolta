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

    public function recordAcceptance(User $user): void
    {
        $user->forceFill([
            'legal_accepted_at' => now(),
            'legal_version' => $this->currentVersion(),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function publicConfig(Request $request): array
    {
        $baseUrl = $request->getSchemeAndHttpHost();

        return [
            'version' => $this->currentVersion(),
            'company_name' => config('legal.company_name'),
            'app_name' => config('legal.app_name', config('legal.company_name')),
            'contact_email' => config('legal.contact_email'),
            'terms' => [
                'title' => 'Termeni si conditii',
                // Servit prin /api ca sa fie public chiar daca nginx ruteaza /legal la SPA.
                'url' => $baseUrl.'/api/legal/terms?app=1',
            ],
            'privacy' => [
                'title' => 'Politica de confidentialitate',
                'url' => $baseUrl.'/api/legal/privacy?app=1',
            ],
        ];
    }
}
