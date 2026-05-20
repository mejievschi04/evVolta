<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RegistrationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RegistrationRequestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|min:2|max:255',
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
                Rule::unique('registration_requests', 'email')->where(
                    fn ($query) => $query->where('status', RegistrationRequest::STATUS_PENDING)
                ),
            ],
            'phone' => 'nullable|string|min:7|max:32',
            'message' => 'nullable|string|max:2000',
        ], [
            'email.unique' => 'Acest e-mail este deja folosit sau exista o cerere in asteptare.',
        ]);

        RegistrationRequest::query()->create([
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'phone' => isset($data['phone']) ? trim($data['phone']) : null,
            'message' => isset($data['message']) ? trim($data['message']) : null,
            'status' => RegistrationRequest::STATUS_PENDING,
        ]);

        return response()->json([
            'message' => 'Cererea ta a fost trimisa. Un administrator iti va activa contul dupa verificare.',
        ], 201);
    }
}