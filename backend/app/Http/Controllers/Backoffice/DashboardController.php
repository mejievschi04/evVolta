<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Mail\InvoiceMail;
use App\Models\AuditLog;
use App\Models\ChargingSession;
use App\Models\Invoice;
use App\Models\OcppCommand;
use App\Models\RegistrationRequest;
use App\Models\Station;
use App\Models\Tariff;
use App\Models\User;
use App\Models\WalletTopup;
use App\Services\AuditLogService;
use App\Services\BillingService;
use App\Services\ChargingStopService;
use App\Services\InvoiceDocumentService;
use App\Services\OcppService;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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
        private readonly ChargingStopService $chargingStopService,
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

    public function index(Request $request): JsonResponse
    {
        Station::markStaleOcppConnectionsOffline();

        [$from, $to, $granularity] = $this->resolveDashboardPeriod($request);
        $analytics = $this->buildDashboardAnalytics($from, $to, $granularity);

        return response()->json([
            'stats' => $analytics['stats'],
            'analytics' => $analytics['details'],
            'period' => $analytics['details']['period'],
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

    /**
     * @return array{0: Carbon, 1: Carbon, 2: 'day'|'hour'}
     */
    private function resolveDashboardPeriod(Request $request): array
    {
        $date = trim((string) $request->query('date', ''));
        $fromInput = trim((string) $request->query('from', ''));
        $toInput = trim((string) $request->query('to', ''));
        $days = (int) $request->query('days', 0);

        if ($date !== '') {
            $day = Carbon::parse($date)->startOfDay();

            return [$day, $day->copy()->endOfDay(), 'hour'];
        }

        if ($fromInput !== '' && $toInput !== '') {
            $from = Carbon::parse($fromInput)->startOfDay();
            $to = Carbon::parse($toInput)->endOfDay();

            if ($from->gt($to)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }

            $granularity = $from->isSameDay($to) ? 'hour' : 'day';

            return [$from, $to, $granularity];
        }

        $dayCount = max(1, min(90, $days > 0 ? $days : 14));
        $to = now()->copy()->endOfDay();
        $from = now()->copy()->subDays($dayCount - 1)->startOfDay();

        return [$from, $to, 'day'];
    }

    /**
     * @return array{stats: array<string, int|float>, details: array<string, mixed>}
     */
    private function buildDashboardAnalytics(Carbon $from, Carbon $to, string $granularity): array
    {
        $today = now()->toDateString();
        $monthStart = now()->copy()->startOfMonth();
        $weekStart = now()->copy()->startOfWeek();
        $currency = (string) config('billing.currency', 'MDL');

        $sessionsToday = ChargingSession::query()
            ->whereDate('start_time', $today)
            ->count();

        $energyForRange = function (Carbon $rangeFrom, ?Carbon $rangeTo = null): float {
            return round((float) ChargingSession::query()
                ->whereNotNull('end_time')
                ->where('start_time', '>=', $rangeFrom)
                ->when($rangeTo, fn ($query) => $query->where('start_time', '<=', $rangeTo))
                ->sum('kwh_consumed'), 2);
        };

        $sessionsForRange = function (Carbon $rangeFrom, ?Carbon $rangeTo = null): int {
            return ChargingSession::query()
                ->where('start_time', '>=', $rangeFrom)
                ->when($rangeTo, fn ($query) => $query->where('start_time', '<=', $rangeTo))
                ->count();
        };

        $paidRevenueForRange = function (Carbon $rangeFrom, ?Carbon $rangeTo = null): float {
            return round((float) Invoice::query()
                ->where('status', 'paid')
                ->whereNotNull('paid_at')
                ->where('paid_at', '>=', $rangeFrom)
                ->when($rangeTo, fn ($query) => $query->where('paid_at', '<=', $rangeTo))
                ->sum('total_amount'), 2);
        };

        $walletTopupsForRange = function (Carbon $rangeFrom, ?Carbon $rangeTo = null): float {
            return round((float) WalletTopup::query()
                ->where('status', 'paid')
                ->whereNotNull('paid_at')
                ->where('paid_at', '>=', $rangeFrom)
                ->when($rangeTo, fn ($query) => $query->where('paid_at', '<=', $rangeTo))
                ->sum('amount'), 2);
        };

        $closedInPeriod = ChargingSession::query()
            ->whereNotNull('end_time')
            ->where('start_time', '>=', $from)
            ->where('start_time', '<=', $to)
            ->get(['start_time', 'end_time', 'kwh_consumed']);

        $avgSessionKwh = round((float) $closedInPeriod->avg('kwh_consumed'), 2);
        $avgSessionMinutes = round((float) $closedInPeriod
            ->filter(fn (ChargingSession $session) => $session->start_time && $session->end_time)
            ->avg(fn (ChargingSession $session) => $session->start_time->diffInMinutes($session->end_time)), 1);

        $trend = [];
        if ($granularity === 'hour') {
            $day = $from->copy()->startOfDay();
            for ($hour = 0; $hour < 24; $hour++) {
                $bucketStart = $day->copy()->addHours($hour);
                $bucketEnd = $bucketStart->copy()->endOfHour();

                $trend[] = [
                    'date' => $bucketStart->toIso8601String(),
                    'label' => $bucketStart->format('H:i'),
                    'sessions' => ChargingSession::query()
                        ->where('start_time', '>=', $bucketStart)
                        ->where('start_time', '<=', $bucketEnd)
                        ->count(),
                    'kwh' => round((float) ChargingSession::query()
                        ->where('start_time', '>=', $bucketStart)
                        ->where('start_time', '<=', $bucketEnd)
                        ->whereNotNull('end_time')
                        ->sum('kwh_consumed'), 2),
                    'revenue' => round((float) Invoice::query()
                        ->where('status', 'paid')
                        ->where('paid_at', '>=', $bucketStart)
                        ->where('paid_at', '<=', $bucketEnd)
                        ->sum('total_amount'), 2),
                ];
            }
        } else {
            $cursor = $from->copy()->startOfDay();
            $endDay = $to->copy()->startOfDay();

            while ($cursor->lte($endDay)) {
                $dayString = $cursor->toDateString();

                $trend[] = [
                    'date' => $dayString,
                    'label' => $cursor->format('d M'),
                    'sessions' => ChargingSession::query()->whereDate('start_time', $dayString)->count(),
                    'kwh' => round((float) ChargingSession::query()
                        ->whereDate('start_time', $dayString)
                        ->whereNotNull('end_time')
                        ->sum('kwh_consumed'), 2),
                    'revenue' => round((float) Invoice::query()
                        ->where('status', 'paid')
                        ->whereDate('paid_at', $dayString)
                        ->sum('total_amount'), 2),
                ];

                $cursor->addDay();
            }
        }

        $topStations = ChargingSession::query()
            ->where('start_time', '>=', $from)
            ->where('start_time', '<=', $to)
            ->join('stations', 'stations.id', '=', 'charging_sessions.station_id')
            ->groupBy('charging_sessions.station_id', 'stations.name')
            ->select(
                'charging_sessions.station_id',
                'stations.name as station_name',
                DB::raw('COUNT(*) as sessions_count'),
                DB::raw('COALESCE(SUM(charging_sessions.kwh_consumed), 0) as total_kwh')
            )
            ->orderByDesc('sessions_count')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'station_id' => (int) $row->station_id,
                'station_name' => (string) $row->station_name,
                'sessions_count' => (int) $row->sessions_count,
                'total_kwh' => round((float) $row->total_kwh, 2),
            ])
            ->values()
            ->all();

        $paidInvoicesAmount = round((float) Invoice::query()
            ->where('status', 'paid')
            ->sum('total_amount'), 2);
        $unpaidInvoicesAmount = round((float) Invoice::query()
            ->where('status', '!=', 'paid')
            ->sum('total_amount'), 2);

        return [
            'stats' => [
                'users' => User::query()->count(),
                'stations' => Station::query()->count(),
                'availableStations' => Station::query()->where('status', Station::STATUS_AVAILABLE)->count(),
                'chargingStations' => Station::query()->where('status', Station::STATUS_CHARGING)->count(),
                'offlineStations' => Station::query()->where('status', Station::STATUS_OFFLINE)->count(),
                'connectedStations' => Station::query()->where('ocpp_connection_status', Station::OCPP_CONNECTION_CONNECTED)->count(),
                'sessionsToday' => $sessionsToday,
                'activeSessions' => ChargingSession::query()->whereNull('end_time')->count(),
                'totalRevenue' => round((float) Invoice::query()->sum('total_amount'), 2),
                'unpaidInvoices' => Invoice::query()->where('status', '!=', 'paid')->count(),
                'pendingRequests' => RegistrationRequest::query()->where('status', RegistrationRequest::STATUS_PENDING)->count(),
                'walletTopupsPaidToday' => WalletTopup::query()
                    ->where('status', 'paid')
                    ->whereDate('paid_at', $today)
                    ->count(),
                'walletTopupsVolumeToday' => round((float) WalletTopup::query()
                    ->where('status', 'paid')
                    ->whereDate('paid_at', $today)
                    ->sum('amount'), 2),
                'energyToday' => $energyForRange(now()->copy()->startOfDay()),
                'energyMonth' => $energyForRange($monthStart),
                'revenuePaidMonth' => $paidRevenueForRange($monthStart),
                'sessionsMonth' => $sessionsForRange($monthStart),
            ],
            'details' => [
                'currency' => $currency,
                'energy' => [
                    'today' => $energyForRange(now()->copy()->startOfDay()),
                    'week' => $energyForRange($weekStart),
                    'month' => $energyForRange($monthStart),
                ],
                'sessions' => [
                    'today' => $sessionsToday,
                    'week' => $sessionsForRange($weekStart),
                    'month' => $sessionsForRange($monthStart),
                    'closedMonth' => ChargingSession::query()
                        ->whereNotNull('end_time')
                        ->where('start_time', '>=', $monthStart)
                        ->count(),
                    'active' => ChargingSession::query()->whereNull('end_time')->count(),
                ],
                'revenue' => [
                    'invoicedTotal' => round((float) Invoice::query()->sum('total_amount'), 2),
                    'paidTotal' => $paidInvoicesAmount,
                    'unpaidTotal' => $unpaidInvoicesAmount,
                    'paidToday' => $paidRevenueForRange(now()->copy()->startOfDay()),
                    'paidWeek' => $paidRevenueForRange($weekStart),
                    'paidMonth' => $paidRevenueForRange($monthStart),
                    'paidInvoices' => Invoice::query()->where('status', 'paid')->count(),
                    'unpaidInvoices' => Invoice::query()->where('status', '!=', 'paid')->count(),
                ],
                'users' => [
                    'customer' => User::query()->where('account_type', User::ACCOUNT_TYPE_CUSTOMER)->count(),
                    'personal' => User::query()->where('account_type', User::ACCOUNT_TYPE_PERSONAL)->count(),
                    'walletBalanceTotal' => round((float) User::query()
                        ->where('account_type', User::ACCOUNT_TYPE_CUSTOMER)
                        ->sum('wallet_balance'), 2),
                ],
                'wallet' => [
                    'topupsToday' => round((float) WalletTopup::query()
                        ->where('status', 'paid')
                        ->whereDate('paid_at', $today)
                        ->sum('amount'), 2),
                    'topupsMonth' => round((float) WalletTopup::query()
                        ->where('status', 'paid')
                        ->where('paid_at', '>=', $monthStart)
                        ->sum('amount'), 2),
                    'topupsAllTime' => round((float) WalletTopup::query()
                        ->where('status', 'paid')
                        ->sum('amount'), 2),
                    'topupsPaidToday' => WalletTopup::query()
                        ->where('status', 'paid')
                        ->whereDate('paid_at', $today)
                        ->count(),
                ],
                'averages' => [
                    'sessionKwh30d' => $avgSessionKwh,
                    'sessionMinutes30d' => $avgSessionMinutes,
                ],
                'period' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'granularity' => $granularity,
                    'days' => $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1,
                ],
                'periodStats' => [
                    'sessions' => $sessionsForRange($from, $to),
                    'kwh' => $energyForRange($from, $to),
                    'revenue' => $paidRevenueForRange($from, $to),
                    'walletTopups' => $walletTopupsForRange($from, $to),
                    'closedSessions' => ChargingSession::query()
                        ->whereNotNull('end_time')
                        ->where('start_time', '>=', $from)
                        ->where('start_time', '<=', $to)
                        ->count(),
                ],
                'dailyTrend' => $trend,
                'topStationsMonth' => $topStations,
                'topStations' => $topStations,
            ],
        ];
    }

    public function stations(Request $request): JsonResponse
    {
        Station::markStaleOcppConnectionsOffline();

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
                    $station->setAttribute('display_status', $station->displayStatus());

                    return $station;
                }),
        ]);
    }

    public function showStation(Station $station): JsonResponse
    {
        Station::markStaleOcppConnectionsOffline();
        $station->refresh();

        $station->loadCount([
            'sessions',
            'sessions as active_sessions_count' => fn ($query) => $query->whereNull('end_time'),
        ]);

        $configuration = is_array($station->ocpp_configuration) ? $station->ocpp_configuration : [];
        $activeSessions = ChargingSession::query()
            ->where('station_id', $station->id)
            ->whereNull('end_time')
            ->with(['user:id,name,email'])
            ->latest('id')
            ->get();

        return response()->json([
            'data' => [
                'station' => [
                    ...$station->only([
                        'id',
                        'name',
                        'location',
                        'latitude',
                        'longitude',
                        'status',
                        'qr_code',
                        'power_kw',
                        'connector_type',
                        'ocpp_identity',
                        'ocpp_version',
                        'ocpp_connection_status',
                        'last_heartbeat_at',
                        'last_ocpp_message_at',
                        'meter_value_kwh',
                        'sessions_count',
                        'active_sessions_count',
                    ]),
                    'ocpp_connection_url' => $this->ocppService->connectionUrl($station),
                ],
                'live_status' => $station->liveStatus(),
                'hardware' => [
                    'vendor' => $configuration['chargePointVendor'] ?? null,
                    'model' => $configuration['chargePointModel'] ?? null,
                    'serial' => $configuration['chargePointSerialNumber']
                        ?? $configuration['chargeBoxSerialNumber']
                        ?? null,
                    'firmware' => $configuration['firmwareVersion'] ?? null,
                ],
                'connectors' => $this->stationConnectorDetails($station),
                'local_id_tags' => is_array($configuration['local_id_tags'] ?? null)
                    ? $configuration['local_id_tags']
                    : [],
                'active_sessions' => $activeSessions,
                'diagnostics_commands' => $this->stationDiagnosticsCommands($station),
                'diagnostics_ftp_url' => rtrim((string) config('services.ocpp.diagnostics_ftp_url', ''), '/'),
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stationDiagnosticsCommands(Station $station, int $limit = 12): array
    {
        return OcppCommand::query()
            ->where('station_id', $station->id)
            ->where('action', 'GetDiagnostics')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (OcppCommand $command): array {
                $response = is_array($command->response_payload) ? $command->response_payload : [];
                $payload = is_array($command->payload) ? $command->payload : [];
                $fileName = trim((string) ($response['fileName'] ?? ''));
                $uploadLocation = rtrim((string) ($payload['location'] ?? ''), '/');

                return [
                    'id' => $command->id,
                    'status' => $command->status,
                    'file_name' => $fileName !== '' ? $fileName : null,
                    'upload_status' => $response['upload_status'] ?? null,
                    'upload_location' => $uploadLocation !== '' ? $uploadLocation : null,
                    'download_url' => $fileName !== '' && $uploadLocation !== ''
                        ? $uploadLocation . '/' . ltrim($fileName, '/')
                        : null,
                    'error_message' => $command->error_message,
                    'created_at' => $command->created_at?->toIso8601String(),
                    'sent_at' => $command->sent_at?->toIso8601String(),
                    'acknowledged_at' => $command->acknowledged_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stationConnectorDetails(Station $station): array
    {
        $configuration = is_array($station->ocpp_configuration) ? $station->ocpp_configuration : [];
        $rawConnectors = $station->normalizedConnectorsForConfiguration($configuration);
        $liveSummaries = collect($station->liveStatus()['connectors'] ?? [])
            ->keyBy('id');

        return collect($station->expectedConnectorIds())
            ->map(function (int $connectorId) use ($rawConnectors, $liveSummaries, $station) {
                $connector = $rawConnectors[$connectorId] ?? ['connectorId' => $connectorId];
                $summary = $liveSummaries->get($connectorId, []);
                $liveMeter = is_array($connector['live_meter'] ?? null) ? $connector['live_meter'] : [];

                return [
                    'id' => $connectorId,
                    'label' => Station::connectorPortLabel($connectorId),
                    'status' => (string) ($connector['status'] ?? $summary['status'] ?? ''),
                    'availability' => $summary['availability'] ?? null,
                    'vehicle_connected' => (bool) ($summary['vehicle_connected'] ?? false),
                    'can_start' => (bool) ($summary['can_start'] ?? false),
                    'error_code' => $connector['errorCode'] ?? null,
                    'info' => $connector['info'] ?? null,
                    'timestamp' => $connector['timestamp'] ?? null,
                    'local_id_tag' => $station->localIdTagForConnector($connectorId),
                    'has_active_session' => $station->hasActiveSessionOnConnector($connectorId),
                    'telemetry' => [
                        'energy_kwh' => isset($liveMeter['energy_kwh']) ? (float) $liveMeter['energy_kwh'] : ($summary['energy_kwh'] ?? null),
                        'power_kw' => isset($liveMeter['power_kw']) ? (float) $liveMeter['power_kw'] : ($summary['power_kw'] ?? null),
                        'current_a' => isset($liveMeter['current_a']) ? (float) $liveMeter['current_a'] : ($summary['current_a'] ?? null),
                        'voltage_v' => isset($liveMeter['voltage_v']) ? (float) $liveMeter['voltage_v'] : ($summary['voltage_v'] ?? null),
                        'soc_percent' => isset($liveMeter['soc_percent']) ? (float) $liveMeter['soc_percent'] : ($summary['soc_percent'] ?? null),
                        'sampled_at' => $liveMeter['sampled_at'] ?? $summary['sampled_at'] ?? null,
                    ],
                ];
            })
            ->values()
            ->all();
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
            'ocpp_identity' => 'nullable|string|max:120|unique:stations,ocpp_identity',
            'ocpp_version' => 'nullable|string|in:1.6J,2.0.1',
        ]);

        $data['qr_code'] = $this->normalizeStationQrCode($data['name'], $data['qr_code'] ?? null);
        $data['ocpp_identity'] = $this->normalizeOcppIdentity($data['name'], $data['ocpp_identity'] ?? null);
        $data['ocpp_version'] = $data['ocpp_version'] ?? '1.6J';
        $data['ocpp_connection_status'] = $data['ocpp_identity']
            ? Station::OCPP_CONNECTION_DISCONNECTED
            : Station::OCPP_CONNECTION_NOT_CONFIGURED;
        $data['currency'] = 'MDL';

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
        $data['currency'] = 'MDL';

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

    public function requestStationDiagnostics(Request $request, Station $station): JsonResponse|RedirectResponse
    {
        try {
            $result = app(OcppService::class)->requestDiagnostics($station);
        } catch (RuntimeException $exception) {
            $status = $exception->getCode() >= 400 && $exception->getCode() < 600
                ? (int) $exception->getCode()
                : 422;

            return response()->json(['message' => $exception->getMessage()], $status);
        }

        $this->auditLogService->record(
            action: 'backoffice.station.diagnostics_requested',
            actor: $this->backofficeActor(),
            subjectType: Station::class,
            subjectId: $station->id,
            station: $station,
            metadata: [
                'command_id' => $result['command_id'] ?? null,
                'location' => $result['location'] ?? null,
            ]
        );

        return $this->respondMutation($request, 'GetDiagnostics trimis catre statie.', [
            'data' => $result,
        ]);
    }

    public function refreshStationStatus(Request $request, Station $station): JsonResponse|RedirectResponse
    {
        try {
            $activeSessions = ChargingSession::query()
                ->where('station_id', $station->id)
                ->whereNull('end_time')
                ->get();

            if ($activeSessions->isNotEmpty()) {
                foreach ($activeSessions as $session) {
                    $station = $this->ocppService->refreshSessionTelemetry(
                        $station,
                        (int) ($session->ocpp_connector_id ?: 1)
                    );
                }
            }

            $station = $this->ocppService->refreshConnectorStatus($station, 0, true);
            $station = $station->fresh();

            $this->auditLogService->record(
                action: 'backoffice.station.refresh_status',
                actor: $this->backofficeActor(),
                subjectType: Station::class,
                subjectId: $station->id,
                station: $station,
                session: $activeSessions->first(),
                metadata: [
                    'active_session_ids' => $activeSessions->pluck('id')->all(),
                    'connector_ids' => $activeSessions->pluck('ocpp_connector_id')->filter()->values()->all(),
                ]
            );

            return $this->respondMutation($request, 'Status OCPP actualizat.', [
                'data' => [
                    'live_status' => $station->liveStatus(),
                    'active_session_ids' => $activeSessions->pluck('id')->all(),
                ],
            ]);
        } catch (RuntimeException $exception) {
            return $this->respondMutationError(
                $request,
                $exception->getMessage(),
                $exception->getCode() >= 400 && $exception->getCode() < 600 ? (int) $exception->getCode() : 422
            );
        }
    }

    public function unlockStationConnector(Request $request, Station $station): JsonResponse|RedirectResponse
    {
        $payload = $request->validate([
            'connector_id' => 'sometimes|integer|min:1|max:9',
        ]);

        $activeSession = ChargingSession::query()
            ->where('station_id', $station->id)
            ->whereNull('end_time')
            ->latest('id')
            ->first();

        $liveStatus = $station->liveStatus();
        $connectorId = (int) (
            $payload['connector_id']
            ?? $activeSession?->ocpp_connector_id
            ?? $liveStatus['connected_connector_id']
            ?? 1
        );

        try {
            $result = $this->ocppService->unlockConnector($station, $connectorId);
        } catch (RuntimeException $exception) {
            return $this->respondMutationError(
                $request,
                $exception->getMessage(),
                $exception->getCode() >= 400 && $exception->getCode() < 600 ? (int) $exception->getCode() : 422
            );
        }

        $this->auditLogService->record(
            action: 'backoffice.station.unlock_connector',
            actor: $this->backofficeActor(),
            subjectType: Station::class,
            subjectId: $station->id,
            station: $station,
            session: $activeSession,
            metadata: [
                'connector_id' => $connectorId,
                'command_id' => $result['command_id'] ?? null,
            ]
        );

        return $this->respondMutation($request, 'UnlockConnector trimis catre statie.', [
            'data' => $result,
        ]);
    }

    public function stopActiveStationSession(Request $request, Station $station): JsonResponse|RedirectResponse
    {
        try {
            $result = DB::transaction(function () use ($station) {
                $session = ChargingSession::query()
                    ->where('station_id', $station->id)
                    ->whereNull('end_time')
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if (! $session) {
                    throw new RuntimeException('Statia nu are o sesiune activa.', 404);
                }

                $lockedStation = Station::query()
                    ->whereKey($station->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedStation) {
                    throw new RuntimeException('Statia nu a fost gasita.', 404);
                }

                $stopResult = $this->chargingStopService->requestStop($session, $lockedStation, 'backoffice');

                $this->auditLogService->record(
                    action: $stopResult['status'] === 'completed'
                        ? 'backoffice.session.stopped'
                        : 'backoffice.session.stop_requested',
                    actor: $this->backofficeActor(),
                    subjectType: ChargingSession::class,
                    subjectId: $session->id,
                    station: $stopResult['station'],
                    session: $stopResult['session'],
                    metadata: [
                        'stopped_from' => 'station_row',
                        'kwh_consumed' => $stopResult['session']->kwh_consumed,
                        'ocpp_mode' => config('services.ocpp.mode'),
                    ]
                );

                return $stopResult;
            });
        } catch (RuntimeException $exception) {
            return $this->respondMutationError(
                $request,
                $exception->getMessage(),
                $exception->getCode() >= 400 && $exception->getCode() < 600 ? (int) $exception->getCode() : 422
            );
        }

        return $this->respondMutation($request, $result['message'], [
            'data' => [
                'status' => $result['status'],
                'session_id' => $result['session']->id,
            ],
        ]);
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
                ->with(['user:id,name,email', 'station'])
                ->latest('start_time')
                ->limit(100)
                ->get(),
        ]);
    }

    public function stopSession(Request $request, ChargingSession $session): JsonResponse|RedirectResponse
    {
        try {
            $result = DB::transaction(function () use ($session) {
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
                $stopResult = $this->chargingStopService->requestStop($session, $station, 'backoffice');

                $this->auditLogService->record(
                    action: $stopResult['status'] === 'completed'
                        ? 'backoffice.session.stopped'
                        : 'backoffice.session.stop_requested',
                    actor: $this->backofficeActor(),
                    subjectType: ChargingSession::class,
                    subjectId: $session->id,
                    station: $stopResult['station'],
                    session: $stopResult['session'],
                    metadata: [
                        'status_before' => $statusBefore,
                        'status_after' => $stopResult['station']->status,
                        'duration_minutes' => $stopResult['duration_minutes'] ?? null,
                        'kwh_consumed' => $stopResult['session']->kwh_consumed,
                        'stopped_by' => 'backoffice',
                        'ocpp_mode' => config('services.ocpp.mode'),
                    ]
                );

                return $stopResult;
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

        return $this->respondMutation($request, $result['message']);
    }

    public function deleteSession(Request $request, ChargingSession $session): JsonResponse|RedirectResponse
    {
        try {
            DB::transaction(function () use ($session): void {
            $session = ChargingSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw new RuntimeException('Sesiunea nu a fost gasita.', 404);
            }

            $station = Station::query()
                ->whereKey($session->station_id)
                ->lockForUpdate()
                ->first();

            Invoice::query()
                ->where('source_session_id', $session->id)
                ->delete();

            if (! $session->end_time && $station) {
                $station->update(['status' => Station::STATUS_AVAILABLE]);
            }

            $this->auditLogService->record(
                action: 'backoffice.session.deleted',
                actor: $this->backofficeActor(),
                subjectType: ChargingSession::class,
                subjectId: $session->id,
                station: $station,
                session: $session,
                metadata: [
                    'user_id' => $session->user_id,
                    'station_id' => $session->station_id,
                    'was_active' => $session->end_time === null,
                ]
            );

            $session->delete();
            });
        } catch (RuntimeException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], $exception->getCode() >= 400 ? $exception->getCode() : 422);
            }

            return back()->withErrors([
                'session_delete' => $exception->getMessage(),
            ]);
        }

        return $this->respondMutation($request, 'Sesiunea a fost stearsa.');
    }

    public function users(Request $request): JsonResponse
    {
        $accountType = trim((string) $request->query('account_type', ''));

        $query = User::query()
            ->where('is_admin', false)
            ->withCount([
                'sessions',
                'invoices',
                'invoices as unpaid_invoices_count' => fn ($builder) => $builder->where('status', 'unpaid'),
            ])
            ->withSum(
                ['invoices as outstanding_balance' => fn ($builder) => $builder->where('status', 'unpaid')],
                'total_amount'
            )
            ->latest('id')
            ->limit(100);

        if (in_array($accountType, [User::ACCOUNT_TYPE_PERSONAL, User::ACCOUNT_TYPE_CUSTOMER], true)) {
            $query->where('account_type', $accountType);
        }

        $users = $query->get([
            'id',
            'name',
            'first_name',
            'last_name',
            'email',
            'currency',
            'account_type',
            'wallet_balance',
            'created_at',
        ])->map(fn (User $user) => [
            ...$user->only([
                'id',
                'name',
                'first_name',
                'last_name',
                'email',
                'currency',
                'account_type',
                'wallet_balance',
                'created_at',
            ]),
            'sessions_count' => (int) $user->sessions_count,
            'invoices_count' => (int) $user->invoices_count,
            'unpaid_invoices_count' => (int) $user->unpaid_invoices_count,
            'outstanding_balance' => round((float) ($user->outstanding_balance ?? 0), 2),
            'wallet_balance' => round((float) $user->wallet_balance, 2),
        ]);

        return response()->json([
            'data' => $users,
        ]);
    }

    public function showUser(User $user): JsonResponse
    {
        if ($user->is_admin) {
            abort(404);
        }

        $user->loadCount([
            'sessions',
            'invoices',
            'invoices as unpaid_invoices_count' => fn ($builder) => $builder->where('status', 'unpaid'),
            'invoices as paid_invoices_count' => fn ($builder) => $builder->where('status', 'paid'),
        ]);

        $invoices = $user->invoices()
            ->latest('id')
            ->limit(36)
            ->get([
                'id',
                'invoice_number',
                'invoice_type',
                'month',
                'currency',
                'period_start',
                'period_end',
                'total_kwh',
                'total_amount',
                'sessions_count',
                'status',
                'paid_at',
                'created_at',
            ]);

        $recentSessions = $user->sessions()
            ->with('station')
            ->latest('id')
            ->limit(12)
            ->get();

        $outstandingBalance = round((float) $user->invoices()->where('status', 'unpaid')->sum('total_amount'), 2);

        return response()->json([
            'data' => [
                'user' => [
                    ...$user->only([
                        'id',
                        'name',
                        'first_name',
                        'last_name',
                        'email',
                        'currency',
                        'account_type',
                        'wallet_balance',
                        'created_at',
                    ]),
                    'wallet_balance' => round((float) $user->wallet_balance, 2),
                    'sessions_count' => (int) $user->sessions_count,
                    'invoices_count' => (int) $user->invoices_count,
                    'unpaid_invoices_count' => (int) $user->unpaid_invoices_count,
                    'paid_invoices_count' => (int) $user->paid_invoices_count,
                    'outstanding_balance' => $outstandingBalance,
                ],
                'billing' => [
                    'outstanding_balance' => $outstandingBalance,
                    'unpaid_invoices_count' => (int) $user->unpaid_invoices_count,
                    'paid_invoices_count' => (int) $user->paid_invoices_count,
                    'total_billed' => round((float) $user->invoices()->sum('total_amount'), 2),
                    'total_kwh' => round((float) $user->sessions()->whereNotNull('end_time')->sum('kwh_consumed'), 2),
                ],
                'invoices' => $invoices,
                'recent_sessions' => $recentSessions,
                'wallet_topups' => $user->usesCardPayment()
                    ? $user->walletTopups()
                        ->latest('id')
                        ->limit(12)
                        ->get([
                            'id',
                            'amount',
                            'currency',
                            'status',
                            'payment_provider',
                            'payment_session_id',
                            'paid_at',
                            'created_at',
                        ])
                    : [],
            ],
        ]);
    }

    public function walletTopups(Request $request): JsonResponse
    {
        $status = trim((string) $request->query('status', ''));

        $baseQuery = WalletTopup::query()
            ->with(['user:id,name,email,account_type,wallet_balance']);

        $listQuery = (clone $baseQuery)->latest('id')->limit(200);

        if (in_array($status, ['pending', 'paid'], true)) {
            $listQuery->where('status', $status);
        }

        return response()->json([
            'data' => $listQuery->get()->map(fn (WalletTopup $topup) => [
                'id' => $topup->id,
                'user_id' => $topup->user_id,
                'amount' => round((float) $topup->amount, 2),
                'currency' => $topup->currency,
                'status' => $topup->status,
                'payment_provider' => $topup->payment_provider,
                'payment_session_id' => $topup->payment_session_id,
                'paid_at' => $topup->paid_at,
                'created_at' => $topup->created_at,
                'user' => $topup->user?->only([
                    'id',
                    'name',
                    'email',
                    'account_type',
                    'wallet_balance',
                ]),
            ]),
            'summary' => [
                'count_paid' => (int) (clone $baseQuery)->where('status', 'paid')->count(),
                'count_pending' => (int) (clone $baseQuery)->where('status', 'pending')->count(),
                'volume_paid' => round((float) (clone $baseQuery)->where('status', 'paid')->sum('amount'), 2),
                'volume_pending' => round((float) (clone $baseQuery)->where('status', 'pending')->sum('amount'), 2),
            ],
        ]);
    }

    public function storeUser(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'name' => 'nullable|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'string', 'min:6'],
            'account_type' => 'required|in:personal,customer',
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
            'currency' => 'MDL',
            'password' => Hash::make($data['password']),
        ]);
        $user->forceFill([
            'is_admin' => false,
            'account_type' => $data['account_type'],
        ])->save();

        $this->auditLogService->record(
            action: 'backoffice.user.created',
            actor: $this->backofficeActor(),
            subjectType: User::class,
            subjectId: $user->id,
            metadata: [
                'email' => $user->email,
                'name' => $user->name,
                'currency' => $user->currency,
                'account_type' => $user->account_type,
            ]
        );

        return $this->respondMutation($request, 'Utilizatorul a fost creat.', [
            'data' => $user->only(['id', 'name', 'first_name', 'last_name', 'email', 'currency', 'account_type', 'created_at']),
        ]);
    }

    public function registrationRequests(Request $request): JsonResponse
    {
        $status = trim((string) $request->query('status', ''));
        $allowedStatuses = [
            RegistrationRequest::STATUS_PENDING,
            RegistrationRequest::STATUS_APPROVED,
            RegistrationRequest::STATUS_REJECTED,
        ];

        $query = RegistrationRequest::query();

        if ($status !== '' && in_array($status, $allowedStatuses, true)) {
            $query->where('status', $status);
        }

        return response()->json([
            'data' => $query
                ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 WHEN 'rejected' THEN 2 ELSE 3 END")
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
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        DB::transaction(function () use ($registrationRequest, $data) {
            $user = User::query()->create([
                'name' => $registrationRequest->name,
                'email' => $registrationRequest->email,
                'password' => $data['password'],
                'currency' => 'MDL',
            ]);
            $user->forceFill([
                'is_admin' => false,
                'account_type' => User::ACCOUNT_TYPE_CUSTOMER,
            ])->save();

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

    public function deleteInvoice(Request $request, Invoice $invoice): JsonResponse|RedirectResponse
    {
        $this->auditLogService->record(
            action: 'backoffice.invoice.deleted',
            actor: $this->backofficeActor(),
            subjectType: Invoice::class,
            subjectId: $invoice->id,
            metadata: [
                'invoice_number' => $invoice->invoice_number,
                'user_id' => $invoice->user_id,
                'month' => $invoice->month,
                'total_amount' => $invoice->total_amount,
            ]
        );

        $invoice->delete();

        return $this->respondMutation($request, 'Factura a fost stearsa.');
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
                'currency' => 'MDL',
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
