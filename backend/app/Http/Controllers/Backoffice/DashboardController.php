<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Mail\InvoiceMail;
use App\Models\AuditLog;
use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\RegistrationRequest;
use App\Models\Station;
use App\Models\Tariff;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\BillingService;
use App\Services\InvoiceDocumentService;
use App\Services\OcppService;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    private const AUDIT_ENTITY_OPTIONS = [
        'charging-session' => ChargingSession::class,
        'registration-request' => RegistrationRequest::class,
        'station' => Station::class,
        'tariff' => Tariff::class,
        'invoice' => Invoice::class,
        'user' => User::class,
    ];

    private const STATION_STATUS_OPTIONS = [
        '' => 'Toate statusurile',
        Station::STATUS_AVAILABLE => 'Disponibile',
        Station::STATUS_CHARGING => 'In incarcare',
        Station::STATUS_OFFLINE => 'Neconectate',
    ];

    private const SESSION_STATUS_OPTIONS = [
        '' => 'Toate sesiunile',
        'active' => 'Doar active',
        'closed' => 'Doar inchise',
    ];

    private const INVOICE_STATUS_OPTIONS = [
        '' => 'Toate facturile',
        'unpaid' => 'Neplatite',
        'paid' => 'Platite',
    ];

    private const REQUEST_STATUS_OPTIONS = [
        '' => 'Toate cererile',
        RegistrationRequest::STATUS_PENDING => 'In asteptare',
        RegistrationRequest::STATUS_APPROVED => 'Aprobate',
        RegistrationRequest::STATUS_REJECTED => 'Respinse',
    ];

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly BillingService $billingService,
        private readonly InvoiceDocumentService $invoiceDocumentService,
        private readonly OcppService $ocppService,
    )
    {
    }

    private function backofficeActor(): ?User
    {
        $userId = session('backoffice_user_id');

        return $userId ? User::query()->find($userId) : null;
    }

    public function index(): JsonResponse
    {
        $sessionsToday = ChargingSession::query()
            ->whereDate('start_time', now()->toDateString())
            ->count();

        return response()->json([
            'stats' => [
                'users' => User::query()->count(),
                'stations' => Station::query()->count(),
                'availableStations' => Station::query()->where('status', Station::STATUS_AVAILABLE)->count(),
                'chargingStations' => Station::query()->where('status', Station::STATUS_CHARGING)->count(),
                'offlineStations' => Station::query()->where('status', Station::STATUS_OFFLINE)->count(),
                'connectedStations' => Station::query()->where('ocpp_connection_status', Station::OCPP_CONNECTION_CONNECTED)->count(),
                'sessionsToday' => $sessionsToday,
                'activeSessions' => ChargingSession::query()->whereNull('end_time')->count(),
                'totalRevenue' => (float) Invoice::query()->sum('total_amount'),
                'unpaidInvoices' => Invoice::query()->where('status', 'unpaid')->count(),
                'pendingRequests' => RegistrationRequest::query()->where('status', RegistrationRequest::STATUS_PENDING)->count(),
            ],
            'stationStatus' => [
                'available' => Station::query()->where('status', Station::STATUS_AVAILABLE)->count(),
                'charging' => Station::query()->where('status', Station::STATUS_CHARGING)->count(),
                'offline' => Station::query()->where('status', Station::STATUS_OFFLINE)->count(),
                'connected' => Station::query()->where('ocpp_connection_status', Station::OCPP_CONNECTION_CONNECTED)->count(),
                'disconnected' => Station::query()->where('ocpp_connection_status', Station::OCPP_CONNECTION_DISCONNECTED)->count(),
                'not_configured' => Station::query()->where('ocpp_connection_status', Station::OCPP_CONNECTION_NOT_CONFIGURED)->count(),
            ],
            'ocpp' => [
                'mode' => config('services.ocpp.mode'),
                'publicUrl' => config('services.ocpp.public_url'),
                'heartbeatInterval' => config('services.ocpp.heartbeat_interval'),
            ],
            'currentTariff' => Tariff::query()->latest('id')->first(['id', 'price_per_kwh', 'created_at']),
            'currentUser' => $this->backofficeActor()?->only(['id', 'name', 'first_name', 'last_name', 'currency', 'email']),
        ]);
    }

    public function stations(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Station::query()
                ->withCount([
                    'sessions',
                    'sessions as active_sessions_count' => fn ($query) => $query->whereNull('end_time'),
                ])
                ->orderBy('name')
                ->limit(100)
                ->get()
                ->map(function (Station $station) {
                    $station->setAttribute('ocpp_connection_url', $this->ocppService->connectionUrl($station));
                    $station->setAttribute('live_status', $station->liveStatus());

                    return $station;
                }),
        ]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        return response()->json([
            'data' => AuditLog::query()
                ->with(['actor:id,name,email', 'station:id,name', 'session:id,user_id,station_id,start_time'])
                ->latest('id')
                ->limit(100)
                ->get(),
        ]);
    }

    public function auditLog(AuditLog $auditLog): JsonResponse
    {
        return response()->json([
            'data' => $auditLog->load([
                'actor:id,name,email',
                'station:id,name,location,status,qr_code',
                'session.user:id,name,email',
                'session.station:id,name,location,status',
            ]),
        ]);
    }

    private function respondMutation(Request $request, string $message, array $payload = []): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                ...$payload,
            ]);
        }

        return back()->with('success', $message);
    }

    private function respondMutationError(Request $request, string $message, int $status = 422): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
            ], $status);
        }

        return back()->withErrors(['error' => $message]);
    }

    public function storeStation(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'status' => 'required|in:available,charging,offline',
            'qr_code' => 'nullable|string|max:255',
            'power_kw' => 'nullable|numeric|min:0',
            'connector_type' => 'nullable|string|max:100',
            'currency' => 'nullable|string|size:3|in:MDL,EUR,USD,RON',
            'ocpp_identity' => 'nullable|string|max:120|unique:stations,ocpp_identity',
            'ocpp_version' => 'nullable|string|in:1.6J,2.0.1',
        ]);

        $data['qr_code'] = $this->normalizeStationQrCode($data['name'], $data['qr_code'] ?? null);
        $data['ocpp_identity'] = $this->normalizeOcppIdentity($data['name'], $data['ocpp_identity'] ?? null);
        $data['ocpp_version'] = $data['ocpp_version'] ?? '1.6J';
        $data['ocpp_connection_status'] = $data['ocpp_identity']
            ? Station::OCPP_CONNECTION_DISCONNECTED
            : Station::OCPP_CONNECTION_NOT_CONFIGURED;

        $station = Station::query()->create($data);

        $this->auditLogService->record(
            action: 'backoffice.station.created',
            actor: $this->backofficeActor(),
            subjectType: Station::class,
            subjectId: $station->id,
            station: $station,
            metadata: [
                'name' => $station->name,
                'location' => $station->location,
                'status' => $station->status,
                'ocpp_identity' => $station->ocpp_identity,
            ]
        );

        return $this->respondMutation($request, 'Statia a fost adaugata.', [
            'data' => $station,
        ]);
    }

    public function updateStation(Request $request, Station $station): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'status' => 'required|in:available,charging,offline',
            'qr_code' => 'nullable|string|max:255',
            'power_kw' => 'nullable|numeric|min:0',
            'connector_type' => 'nullable|string|max:100',
            'currency' => 'nullable|string|size:3|in:MDL,EUR,USD,RON',
            'ocpp_identity' => 'nullable|string|max:120|unique:stations,ocpp_identity,' . $station->id,
            'ocpp_version' => 'nullable|string|in:1.6J,2.0.1',
        ]);

        $data['qr_code'] = $this->normalizeStationQrCode($data['name'], $data['qr_code'] ?? null);
        $data['ocpp_identity'] = $this->normalizeOcppIdentity($data['name'], $data['ocpp_identity'] ?? null);
        $data['ocpp_version'] = $data['ocpp_version'] ?? $station->ocpp_version ?? '1.6J';
        if ($station->ocpp_identity !== $data['ocpp_identity']) {
            $data['ocpp_connection_status'] = $data['ocpp_identity']
                ? Station::OCPP_CONNECTION_DISCONNECTED
                : Station::OCPP_CONNECTION_NOT_CONFIGURED;
            $data['last_heartbeat_at'] = null;
            $data['last_ocpp_message_at'] = null;
        }

        $station->update($data);

        $this->auditLogService->record(
            action: 'backoffice.station.updated',
            actor: $this->backofficeActor(),
            subjectType: Station::class,
            subjectId: $station->id,
            station: $station,
            metadata: [
                'name' => $station->name,
                'location' => $station->location,
                'status' => $station->status,
                'ocpp_identity' => $station->ocpp_identity,
            ]
        );

        return $this->respondMutation($request, 'Statia a fost actualizata.', [
            'data' => $station,
        ]);
    }

    public function deleteStation(Request $request, Station $station): JsonResponse|RedirectResponse
    {
        $this->auditLogService->record(
            action: 'backoffice.station.deleted',
            actor: $this->backofficeActor(),
            subjectType: Station::class,
            subjectId: $station->id,
            station: $station,
            metadata: [
                'name' => $station->name,
                'location' => $station->location,
                'status' => $station->status,
            ]
        );

        $station->delete();

        return $this->respondMutation($request, 'Statia a fost stearsa.');
    }

    public function downloadStationQr(Station $station): Response
    {
        $result = $this->buildStationQr($station, 320);

        return response($result->getString(), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="station-' . $station->id . '-qr.png"',
        ]);
    }

    public function previewStationQr(Station $station): Response
    {
        $result = $this->buildStationQr($station, 140);

        return response($result->getString(), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    private function buildStationQr(Station $station, int $size): \Endroid\QrCode\Writer\Result\ResultInterface
    {
        $qrValue = $this->normalizeStationQrCode($station->name, $station->qr_code);
        $writer = new PngWriter();
        $qrCode = new QrCode(
            data: $qrValue,
            size: $size,
            margin: 12
        );

        return $writer->write($qrCode);
    }

    private function normalizeStationQrCode(string $name, ?string $qrCode): string
    {
        $qrCode = trim((string) $qrCode);

        if ($qrCode !== '') {
            return $qrCode;
        }

        return 'station:' . Str::slug($name);
    }

    private function normalizeOcppIdentity(string $name, ?string $identity): string
    {
        $identity = trim((string) $identity);

        if ($identity !== '') {
            return $identity;
        }

        return 'volta-' . Str::slug($name);
    }

    public function sessions(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ChargingSession::query()
                ->with(['user:id,name,email', 'station:id,name,location,status'])
                ->latest('start_time')
                ->limit(100)
                ->get(),
        ]);
    }

    public function stopSession(Request $request, ChargingSession $session): JsonResponse|RedirectResponse
    {
        try {
            DB::transaction(function () use ($session) {
                $session = ChargingSession::query()
                    ->whereKey($session->id)
                    ->lockForUpdate()
                    ->first();

                if (! $session) {
                    throw new RuntimeException('Sesiunea nu a fost gasita.', 404);
                }

                if ($session->end_time) {
                    throw new RuntimeException('Sesiunea este deja inchisa.', 422);
                }

                $station = Station::query()
                    ->whereKey($session->station_id)
                    ->lockForUpdate()
                    ->first();

                if (! $station) {
                    throw new RuntimeException('Statia nu a fost gasita.', 404);
                }

                $statusBefore = $station->status;
                $endTime = now();
                $minutes = max(1, (int) $session->start_time->diffInMinutes($endTime));
                $kwhConsumed = round($minutes * 0.12, 2);

                $session->update([
                    'end_time' => $endTime,
                    'kwh_consumed' => $kwhConsumed,
                ]);

                $this->billingService->createSessionInvoice($session->fresh());

                $station->update(['status' => Station::STATUS_AVAILABLE]);

                $this->auditLogService->record(
                    action: 'backoffice.session.stopped',
                    actor: $this->backofficeActor(),
                    subjectType: ChargingSession::class,
                    subjectId: $session->id,
                    station: $station,
                    session: $session,
                    metadata: [
                        'status_before' => $statusBefore,
                        'status_after' => Station::STATUS_AVAILABLE,
                        'duration_minutes' => $minutes,
                        'kwh_consumed' => $kwhConsumed,
                        'stopped_by' => 'backoffice',
                    ]
                );
            });
        } catch (RuntimeException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], $exception->getCode() >= 400 ? $exception->getCode() : 422);
            }

            return back()->withErrors([
                'session_stop' => $exception->getMessage(),
            ]);
        }

        return $this->respondMutation($request, 'Sesiunea a fost oprita din admin.');
    }

    public function users(Request $request): JsonResponse
    {
        return response()->json([
            'data' => User::query()
                ->withCount(['sessions', 'invoices'])
                ->latest('id')
                ->limit(100)
                ->get(['id', 'name', 'first_name', 'last_name', 'email', 'currency', 'created_at']),
        ]);
    }

    public function storeUser(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'name' => 'nullable|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'currency' => 'required|string|size:3|in:MDL,EUR,USD,RON',
            'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()],
        ]);

        $name = trim((string) ($data['name'] ?? ''));
        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));
        $displayName = $name !== '' ? $name : trim(implode(' ', array_filter([$firstName, $lastName])));

        $user = User::query()->create([
            'name' => $displayName !== '' ? $displayName : $data['email'],
            'first_name' => $firstName !== '' ? $firstName : null,
            'last_name' => $lastName !== '' ? $lastName : null,
            'email' => $data['email'],
            'currency' => $data['currency'],
            'password' => Hash::make($data['password']),
        ]);

        $this->auditLogService->record(
            action: 'backoffice.user.created',
            actor: $this->backofficeActor(),
            subjectType: User::class,
            subjectId: $user->id,
            metadata: [
                'email' => $user->email,
                'name' => $user->name,
                'currency' => $user->currency,
            ]
        );

        return $this->respondMutation($request, 'Utilizatorul a fost creat.', [
            'data' => $user->only(['id', 'name', 'first_name', 'last_name', 'email', 'currency', 'created_at']),
        ]);
    }

    public function registrationRequests(Request $request): JsonResponse
    {
        return response()->json([
            'data' => RegistrationRequest::query()
                ->latest('id')
                ->limit(100)
                ->get(),
        ]);
    }

    public function approveRegistrationRequest(Request $request, RegistrationRequest $registrationRequest): JsonResponse|RedirectResponse
    {
        if ($registrationRequest->status !== RegistrationRequest::STATUS_PENDING) {
            return $this->respondMutationError($request, 'Cererea nu mai este in asteptare.');
        }

        if (User::query()->where('email', $registrationRequest->email)->exists()) {
            return $this->respondMutationError($request, 'Exista deja un utilizator cu acest e-mail.');
        }

        $data = $request->validate([
            'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers(), 'confirmed'],
        ]);

        DB::transaction(function () use ($registrationRequest, $data) {
            $user = User::query()->create([
                'name' => $registrationRequest->name,
                'email' => $registrationRequest->email,
                'password' => $data['password'],
            ]);

            $registrationRequest->update([
                'status' => RegistrationRequest::STATUS_APPROVED,
                'processed_at' => now(),
            ]);

            $this->auditLogService->record(
                action: 'backoffice.registration.approved',
                actor: $this->backofficeActor(),
                subjectType: RegistrationRequest::class,
                subjectId: $registrationRequest->id,
                metadata: [
                    'user_id' => $user->id,
                    'email' => $registrationRequest->email,
                ]
            );
        });

        return $this->respondMutation($request, 'Utilizatorul a fost creat si cererea a fost aprobata.');
    }

    public function rejectRegistrationRequest(Request $request, RegistrationRequest $registrationRequest): JsonResponse|RedirectResponse
    {
        if ($registrationRequest->status !== RegistrationRequest::STATUS_PENDING) {
            return $this->respondMutationError($request, 'Cererea nu mai este in asteptare.');
        }

        $registrationRequest->update([
            'status' => RegistrationRequest::STATUS_REJECTED,
            'processed_at' => now(),
        ]);

        $this->auditLogService->record(
            action: 'backoffice.registration.rejected',
            actor: $this->backofficeActor(),
            subjectType: RegistrationRequest::class,
            subjectId: $registrationRequest->id,
            metadata: [
                'email' => $registrationRequest->email,
            ]
        );

        return $this->respondMutation($request, 'Cererea a fost respinsa.');
    }

    public function invoices(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Invoice::query()
                ->with('user:id,name,email')
                ->latest('id')
                ->limit(100)
                ->get(),
        ]);
    }

    public function downloadInvoice(Invoice $invoice): Response
    {
        $html = $this->invoiceDocumentService->html($invoice);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $this->invoiceDocumentService->filename($invoice) . '"',
        ]);
    }

    public function sendInvoice(Request $request, Invoice $invoice): JsonResponse|RedirectResponse
    {
        $invoice->loadMissing('user:id,name,email,currency');

        if (! $invoice->user?->email) {
            return $this->respondMutationError($request, 'Factura nu are un client cu email valid.');
        }

        $html = $this->invoiceDocumentService->html($invoice);
        $filename = $this->invoiceDocumentService->filename($invoice);
        $subject = 'Factura ' . ($invoice->invoice_number ?: '#' . $invoice->id) . ' - Volta EV Charging';
        $body = $this->invoiceDocumentService->emailBody($invoice);

        Mail::to($invoice->user->email, $invoice->user->name)
            ->send(new InvoiceMail($subject, $body, $html, $filename));

        $this->auditLogService->record(
            action: 'backoffice.invoice.sent',
            actor: $this->backofficeActor(),
            subjectType: Invoice::class,
            subjectId: $invoice->id,
            metadata: [
                'invoice_number' => $invoice->invoice_number,
                'email' => $invoice->user->email,
            ]
        );

        return $this->respondMutation($request, 'Factura a fost trimisa pe email.');
    }

    public function updateTariff(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'price_per_kwh' => 'required|numeric|min:0',
        ]);

        $tariff = Tariff::query()->create([
            'price_per_kwh' => $data['price_per_kwh'],
        ]);

        $this->auditLogService->record(
            action: 'backoffice.tariff.updated',
            actor: $this->backofficeActor(),
            subjectType: Tariff::class,
            subjectId: $tariff->id,
            metadata: [
                'price_per_kwh' => $tariff->price_per_kwh,
            ]
        );

        return $this->respondMutation($request, 'Tariful a fost actualizat.', [
            'data' => $tariff,
        ]);
    }

    public function updateSettings(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'currency' => 'required|string|size:3|in:MDL,EUR,USD,RON',
            'price_per_kwh' => 'required|numeric|min:0',
        ]);

        $userId = session('backoffice_user_id');
        $user = $userId ? User::query()->find($userId) : null;

        if (! $user) {
            return $this->respondMutationError($request, 'Sesiunea de administrare nu mai este valida.', 401);
        }

        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));
        $firstName = $firstName !== '' ? $firstName : $user->first_name;
        $lastName = $lastName !== '' ? $lastName : $user->last_name;
        $updatedName = trim(implode(' ', array_filter([
            $firstName,
            $lastName,
        ])));

        DB::transaction(function () use ($user, $data, $firstName, $lastName, $updatedName): void {
            $user->update([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'currency' => $data['currency'],
                'name' => $updatedName !== '' ? $updatedName : $user->name,
            ]);

            Tariff::query()->create([
                'price_per_kwh' => $data['price_per_kwh'],
            ]);
        });

        $user->refresh();
        session()->put('backoffice_user_name', $user->display_name);

        $latestTariff = Tariff::query()->latest('id')->first();

        $this->auditLogService->record(
            action: 'backoffice.settings.updated',
            actor: $this->backofficeActor(),
            subjectType: User::class,
            subjectId: $user->id,
            metadata: [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'currency' => $user->currency,
                'price_per_kwh' => $latestTariff?->price_per_kwh,
            ]
        );

        return $this->respondMutation($request, 'Setarile au fost actualizate.', [
            'data' => [
                'user' => $user->only(['id', 'name', 'first_name', 'last_name', 'currency', 'email']),
                'tariff' => $latestTariff,
            ],
        ]);
    }
}
