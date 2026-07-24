import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Activity,
  BatteryCharging,
  Bell,
  Bug,
  Calendar,
  CheckCircle2,
  CircleDollarSign,
  ClipboardList,
  Clock3,
  Copy,
  Download,
  Eye,
  FileText,
  LayoutDashboard,
  LogOut,
  Map,
  MapPin,
  Minus,
  MoreHorizontal,
  Plus,
  Receipt,
  RadioTower,
  RefreshCw,
  Search,
  Settings,
  Square,
  TrendingUp,
  Unlock,
  ShieldCheck,
  X,
  Users,
  Wallet,
  Zap
} from 'lucide-react';
import voltaLogo from './assets/icons/Volta Logo 2@300x 1.png';

const sections = [
  { id: 'dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { id: 'stations', label: 'Statii', icon: Zap },
  { id: 'sessions', label: 'Sesiuni', icon: BatteryCharging },
  { id: 'reservations', label: 'Rezervari', icon: Calendar },
  { id: 'clients', label: 'Utilizatori', icon: Users },
  { id: 'wallet', label: 'Alimentari', icon: Wallet },
  { id: 'personal', label: 'Personal', icon: FileText },
  { id: 'invoices', label: 'Facturi', icon: Receipt },
  { id: 'audit', label: 'Audit', icon: ShieldCheck },
  { id: 'settings', label: 'Setari', icon: Settings }
];

const endpoints = {
  dashboard: '/backoffice/dashboard',
  stations: '/backoffice/stations',
  sessions: '/backoffice/sessions',
  reservations: '/backoffice/reservations',
  clients: '/backoffice/users?account_type=customer',
  wallet: '/backoffice/wallet-topups',
  personal: '/backoffice/users?account_type=personal',
  invoices: '/backoffice/invoices',
  audit: '/backoffice/audit-logs'
};

const emptyData = {
  dashboard: null,
  stations: [],
  sessions: [],
  reservations: [],
  clients: [],
  walletTopups: [],
  walletRefunds: [],
  walletSummary: null,
  personal: [],
  invoices: [],
  audit: []
};

let csrfToken = '';

class ApiError extends Error {
  constructor(message, status) {
    super(message);
    this.status = status;
  }
}

async function fetchJson(url) {
  const response = await fetch(url, {
    credentials: 'include',
    headers: { Accept: 'application/json' }
  });

  const payload = await response.json().catch(() => null);
  const contentType = response.headers.get('content-type') ?? '';
  if (!response.ok || !contentType.includes('application/json')) {
    throw new ApiError(payload?.message || 'Backoffice API indisponibil', response.status);
  }

  return payload;
}

async function getCsrfToken() {
  if (csrfToken) {
    return csrfToken;
  }

  const payload = await fetchJson('/backoffice/csrf');
  csrfToken = payload.token;

  return csrfToken;
}

async function mutateJson(url, data = {}) {
  const token = await getCsrfToken();
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': token
    },
    body: JSON.stringify(data)
  });
  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    const validationMessage = payload.errors
      ? Object.values(payload.errors).flat().join(' ')
      : payload.message;
    throw new ApiError(validationMessage || 'Actiunea nu a putut fi salvata.', response.status);
  }

  return payload;
}

function useBackofficeData() {
  const [state, setState] = useState({
    data: emptyData,
    loading: true,
    error: '',
    authRequired: false
  });

  const load = useCallback(async (silent = false) => {
    if (!silent) {
      setState((current) => ({ ...current, loading: true }));
    }

    const errors = [];
    let dashboardPayload = null;

    try {
      dashboardPayload = await fetchJson(`${endpoints.dashboard}?days=14`);
    } catch (error) {
      if (error.status === 401) {
        setState({
          data: emptyData,
          loading: false,
          error: '',
          authRequired: true
        });
        return;
      }

      errors.push(error.message || 'Dashboard indisponibil.');
    }

    const results = await Promise.allSettled(
      Object.entries(endpoints)
        .filter(([key]) => key !== 'dashboard')
        .map(async ([key, url]) => [key, await fetchJson(url)])
    );

    setState((current) => {
      const nextData = { ...current.data };

      if (dashboardPayload) {
        nextData.dashboard = dashboardPayload;
      }

      for (const result of results) {
        if (result.status === 'rejected') {
          const reason = result.reason;
          if (reason?.status === 401) {
            return {
              data: emptyData,
              loading: false,
              error: '',
              authRequired: true
            };
          }

          errors.push(reason?.message || 'Nu am putut incarca o sectiune.');
          continue;
        }

        const [key, payload] = result.value;

        if (key === 'wallet') {
          nextData.walletTopups = payload.data ?? [];
          nextData.walletRefunds = payload.refunds ?? [];
          nextData.walletSummary = payload.summary ?? null;
          continue;
        }

        nextData[key] = payload.data ?? [];
      }

      return {
        data: nextData,
        loading: false,
        error: errors[0] || '',
        authRequired: false
      };
    });
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  return { ...state, reload: load };
}

function formatNumber(value) {
  if (value === null || value === undefined) {
    return '-';
  }

  return new Intl.NumberFormat('ro-RO').format(value);
}

function formatMoney(value) {
  if (value === null || value === undefined) {
    return '-';
  }

  return `${new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 2 }).format(value)} MDL`;
}

function formatKwh(value, digits = 3) {
  if (value === null || value === undefined || !Number.isFinite(Number(value))) {
    return '-';
  }

  return new Intl.NumberFormat('ro-RO', {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits
  }).format(Number(value));
}

function formatTariffPrice(value) {
  if (value === null || value === undefined || value === '') {
    return '-';
  }

  const number = Number(value);
  if (!Number.isFinite(number)) {
    return '-';
  }

  return new Intl.NumberFormat('ro-RO', {
    minimumFractionDigits: 1,
    maximumFractionDigits: 2
  }).format(number);
}

function formatTariffInput(value) {
  if (value === null || value === undefined || value === '') {
    return '';
  }

  const number = Number(value);
  if (!Number.isFinite(number)) {
    return '';
  }

  return Number(number.toFixed(2)).toString();
}

function parseTariffInput(value) {
  const normalized = String(value ?? '').trim().replace(',', '.');
  if (normalized === '') {
    return null;
  }

  const number = Number(normalized);
  if (!Number.isFinite(number) || number < 0) {
    return null;
  }

  return Math.round(number * 100) / 100;
}

function formatDateTime(value) {
  if (!value) {
    return '-';
  }

  return new Date(value).toLocaleString('ro-RO');
}

const DEFAULT_MAP_CENTER = { latitude: 47.010452, longitude: 28.86381 };
const MAP_TILE_SIZE = 256;

function latLonToWorld(latitude, longitude, zoom, tileSize = MAP_TILE_SIZE) {
  const normalizedLatitude = Math.max(-85.05112878, Math.min(85.05112878, Number(latitude)));
  const normalizedLongitude = Math.max(-180, Math.min(180, Number(longitude)));
  const sinLatitude = Math.sin((normalizedLatitude * Math.PI) / 180);
  const scale = tileSize * 2 ** zoom;

  return {
    x: ((normalizedLongitude + 180) / 360) * scale,
    y: (0.5 - Math.log((1 + sinLatitude) / (1 - sinLatitude)) / (4 * Math.PI)) * scale
  };
}

function stationHasCoordinates(station) {
  const latitude = Number(station?.latitude);
  const longitude = Number(station?.longitude);

  return (
    Number.isFinite(latitude)
    && Number.isFinite(longitude)
    && latitude >= -90
    && latitude <= 90
    && longitude >= -180
    && longitude <= 180
  );
}

function stationMarkerColor(station) {
  const availability = station.display_status ?? station.live_status?.availability ?? station.status;

  if (availability === 'available') return '#7ddf8a';
  if (availability === 'charging') return '#ffee00';
  if (availability === 'reserved') return '#7cc7ff';
  if (['offline', 'faulted', 'unavailable', 'stale'].includes(availability)) return '#ff7b7b';

  return '#c8d46d';
}

function fitMapView(stations) {
  const mapped = stations.filter(stationHasCoordinates);

  if (mapped.length === 0) {
    return {
      centerLat: DEFAULT_MAP_CENTER.latitude,
      centerLon: DEFAULT_MAP_CENTER.longitude,
      zoom: 12
    };
  }

  const latitudes = mapped.map((station) => Number(station.latitude));
  const longitudes = mapped.map((station) => Number(station.longitude));
  const minLat = Math.min(...latitudes);
  const maxLat = Math.max(...latitudes);
  const minLon = Math.min(...longitudes);
  const maxLon = Math.max(...longitudes);
  const latSpan = Math.max(maxLat - minLat, 0.004);
  const lonSpan = Math.max(maxLon - minLon, 0.004);
  const span = Math.max(latSpan, lonSpan);

  let zoom = 15;
  if (span > 2) zoom = 8;
  else if (span > 0.8) zoom = 9;
  else if (span > 0.35) zoom = 10;
  else if (span > 0.15) zoom = 11;
  else if (span > 0.07) zoom = 12;
  else if (span > 0.03) zoom = 13;
  else if (span > 0.012) zoom = 14;

  return {
    centerLat: (minLat + maxLat) / 2,
    centerLon: (minLon + maxLon) / 2,
    zoom
  };
}

function sessionKwhDelivered(session) {
  const delivered = session?.kwh_delivered ?? session?.telemetry?.kwh_consumed ?? session?.kwh_consumed;
  return delivered ?? 0;
}

function sessionPowerKw(session) {
  return session?.power_kw_live ?? session?.telemetry?.power_kw ?? null;
}

function formatSessionStopInfo(session) {
  if (!session?.end_time) {
    return null;
  }

  const parts = [];

  if (session.stop_source === 'app') {
    parts.push('Oprire app');
  } else if (session.stop_source === 'ocpp') {
    parts.push('Oprire statie');
  } else if (session.stop_source === 'backoffice') {
    parts.push('Oprire backoffice');
  }

  if (session.ocpp_stop_reason) {
    parts.push(`motiv ${session.ocpp_stop_reason}`);
  }

  return parts.length > 0 ? parts.join(' · ') : null;
}

function formatSessionDuration(session) {
  const start = session?.start_time ? new Date(session.start_time).getTime() : null;

  if (!start) {
    return '-';
  }

  const end = session?.end_time ? new Date(session.end_time).getTime() : Date.now();
  const minutes = Math.max(0, Math.floor((end - start) / 60000));

  if (minutes < 60) {
    return `${minutes} min`;
  }

  const hours = Math.floor(minutes / 60);
  const remainder = minutes % 60;

  return remainder > 0 ? `${hours} h ${remainder} min` : `${hours} h`;
}

function ocppRelationLabel(relation) {
  return {
    session_stop: 'Oprire sesiune',
    session_start: 'Start sesiune',
    session_connector: 'Conector sesiune',
    other_connector: 'Alt conector',
    station_wide: 'Stație întreagă',
    session_command: 'Comandă sesiune',
    neutral: '—'
  }[relation] ?? relation ?? '—';
}

function ocppRelationClass(relation) {
  return {
    session_stop: 'ocpp-timeline-stop',
    other_connector: 'ocpp-timeline-other',
    session_connector: 'ocpp-timeline-session',
    station_wide: 'ocpp-timeline-reset',
    session_command: 'ocpp-timeline-command'
  }[relation] ?? '';
}

function effectiveStationStatus(station) {
  return station.display_status ?? station.live_status?.availability ?? station.status;
}

function effectiveOcppConnectionStatus(station) {
  return station.live_status?.connection_status ?? station.ocpp_connection_status;
}

function accountTypeLabel(accountType) {
  return accountType === 'personal' ? 'Personal' : 'Utilizator';
}

function statusLabel(status) {
  return {
    available: 'Disponibila',
    charging: 'In incarcare',
    offline: 'Offline',
    paid: 'Platita',
    unpaid: 'Neplatita',
    pending: 'In asteptare',
    failed: 'Esuata',
    approved: 'Aprobata',
    rejected: 'Respinsa'
  }[status] ?? status ?? '-';
}

function connectionLabel(status) {
  return {
    connected: 'OCPP conectata',
    disconnected: 'OCPP deconectata',
    not_configured: 'OCPP neconfigurat'
  }[status] ?? status ?? '-';
}

function availabilityLabel(status) {
  return {
    available: 'Conector liber',
    preparing: 'Pregatire',
    charging: 'Conector ocupat',
    reserved: 'Rezervat',
    faulted: 'Eroare conector',
    unavailable: 'Indisponibil',
    stale: 'Heartbeat vechi'
  }[status] ?? status ?? 'Live necunoscut';
}

function ocppCommandLabel(status) {
  return {
    pending: 'In coada',
    sent: 'Trimis',
    accepted: 'Acceptat',
    rejected: 'Respins',
    failed: 'Esuat'
  }[status] ?? status ?? '-';
}

function diagnosticsUploadLabel(status) {
  return {
    Idle: 'Inactiv',
    Uploading: 'Se incarca',
    Uploaded: 'Incarcat',
    UploadFailed: 'Upload esuat'
  }[status] ?? status ?? '-';
}

function diagnosticsResultSummary(command) {
  if (command.file_name) {
    return command.file_name;
  }

  if (command.upload_status) {
    return diagnosticsUploadLabel(command.upload_status);
  }

  if (['pending', 'sent'].includes(command.status)) {
    return 'Astept raspuns statie...';
  }

  if (command.status === 'accepted') {
    return 'Acceptat, fara fisier inca';
  }

  return command.error_message || '—';
}

function statusVariant(status) {
  if (['available', 'paid', 'approved', 'connected', 'accepted', 'Uploaded'].includes(status)) return 'success';
  if (['charging', 'pending', 'unpaid', 'disconnected', 'reserved', 'sent', 'Uploading'].includes(status)) return 'warning';
  if (['offline', 'rejected', 'faulted', 'unavailable', 'stale', 'failed', 'UploadFailed'].includes(status)) return 'danger';
  return 'neutral';
}

function matchesQuery(row, query, fields) {
  const normalized = query.trim().toLowerCase();
  if (!normalized) {
    return true;
  }

  return fields.some((field) => String(field(row) ?? '').toLowerCase().includes(normalized));
}

function Badge({ children, variant = 'neutral' }) {
  return <span className={`badge badge-${variant}`}>{children}</span>;
}

function TariffBadge({ value, fallback = 'Tarif' }) {
  if (value == null || value === '') {
    return <span className="tariff-badge tariff-badge-muted">{fallback}</span>;
  }

  return (
    <span className="tariff-badge">
      <Zap size={13} />
      <strong>{formatTariffPrice(value)}</strong>
      <span>lei/kWh</span>
    </span>
  );
}

function BrandBlock({ compact = false }) {
  return (
    <div className={`brand ${compact ? 'brand-compact' : ''}`}>
      <span className="brand-logo">
        <img alt="Volta" src={voltaLogo} />
      </span>
      <div>
        <strong>Volta EV</strong>
        {!compact ? <p className="brand-tagline">Backoffice</p> : null}
      </div>
    </div>
  );
}

function SectionButton({ section, active, badge, onClick }) {
  const Icon = section.icon;

  return (
    <button className={`nav-item ${active ? 'active' : ''}`} onClick={() => onClick(section.id)} type="button">
      <Icon size={18} />
      <span>{section.label}</span>
      {badge > 0 ? <span className="nav-badge">{badge}</span> : null}
    </button>
  );
}

function StatCard({ label, value, helper, icon: Icon }) {
  return (
    <article className="stat-card">
      <div className="stat-icon">
        <Icon size={20} />
      </div>
      <div>
        <p>{label}</p>
        <strong>{value}</strong>
        <span>{helper}</span>
      </div>
    </article>
  );
}

function TopMetric({ label, value, icon: Icon }) {
  return (
    <span className="top-metric">
      <Icon size={15} />
      <span>{label}</span>
      <strong>{value}</strong>
    </span>
  );
}

function DetailMetric({ label, value, helper }) {
  return (
    <div className="detail-metric">
      <span>{label}</span>
      <strong>{value}</strong>
      {helper ? <small>{helper}</small> : null}
    </div>
  );
}

function DashboardNetworkCard({ stats, stationStatus }) {
  const total = Number(stats?.stations ?? 0);
  const connected = Number(stationStatus?.connected ?? 0);
  const statusItems = [
    { key: 'available', label: 'Disponibile', value: stationStatus?.available ?? 0, variant: 'success' },
    { key: 'charging', label: 'In incarcare', value: stationStatus?.charging ?? 0, variant: 'warning' },
    { key: 'offline', label: 'Offline', value: stationStatus?.offline ?? 0, variant: 'danger' }
  ];
  const stationTotal = statusItems.reduce((sum, item) => sum + Number(item.value || 0), 0);

  return (
    <section className="panel">
      <div className="panel-header">
        <div>
          <h2>Retea statii</h2>
          <p>Status operational si OCPP live</p>
        </div>
        <RadioTower size={20} />
      </div>

      <div className="dash-network-layout">
        <div className="dash-network-total">
          <span>Total statii</span>
          <strong>{formatNumber(total)}</strong>
          <p>{formatNumber(connected)} conectate OCPP</p>
        </div>

        <div className="meter-stack">
          {statusItems.map((item) => {
            const width = stationTotal ? Math.max((Number(item.value) / stationTotal) * 100, item.value ? 8 : 0) : 0;

            return (
              <div className="meter-row" key={item.key}>
                <div>
                  <Badge variant={item.variant}>{item.label}</Badge>
                  <strong>{formatNumber(item.value)}</strong>
                </div>
                <span className={`meter-track meter-${item.variant}`}>
                  <span style={{ width: `${width}%` }} />
                </span>
              </div>
            );
          })}
        </div>
      </div>

      <div className="dash-ocpp-pills">
        <span className="dash-ocpp-pill tone-success">{formatNumber(stationStatus?.connected ?? 0)} online</span>
        <span className="dash-ocpp-pill tone-warning">{formatNumber(stationStatus?.disconnected ?? 0)} offline</span>
        <span className="dash-ocpp-pill">{formatNumber(stationStatus?.not_configured ?? 0)} neconfigurat</span>
      </div>
    </section>
  );
}

function StationModernCard({
  station,
  onOpenDetail,
  onEdit,
  onDelete,
  onDownloadQr,
  onPreviewQr,
  onDiagnostics,
  onRefreshStatus,
  onUnlockConnector,
  onStopActiveSession
}) {
  const status = effectiveStationStatus(station);
  const ocppStatus = effectiveOcppConnectionStatus(station);
  const connectors = station.live_status?.connectors ?? [];
  const isConnected = ocppStatus === 'connected';

  return (
    <article className={`station-card-modern tone-${statusVariant(status)}`}>
      <div className="station-card-top">
        <div className="station-card-icon">
          <Zap size={18} />
        </div>
        <div className="station-card-main">
          <button className="station-name-link" onClick={() => onOpenDetail(station)} type="button">
            <strong>{station.name}</strong>
          </button>
          <p className="station-card-location">
            <MapPin size={13} />
            {station.location || 'Fara adresa'}
          </p>
          <p className="station-card-identity">
            <RadioTower size={13} />
            {station.ocpp_identity || 'fara OCPP identity'}
          </p>
        </div>
        <div className="station-card-badges">
          <Badge variant={statusVariant(status)}>{statusLabel(status)}</Badge>
          <Badge variant={statusVariant(ocppStatus)}>{connectionLabel(ocppStatus)}</Badge>
        </div>
      </div>

      <div className="station-card-chips">
        <span className="station-chip">{station.power_kw ? `${formatKwh(station.power_kw, 1)} kW` : '— kW'}</span>
        <span className="station-chip">{station.connector_type || 'Type2'}</span>
        <span className="station-chip">{formatNumber(station.sessions_count)} sesiuni</span>
        {station.active_sessions_count > 0 ? (
          <span className="station-chip station-chip-live">{formatNumber(station.active_sessions_count)} active</span>
        ) : null}
      </div>

      {connectors.length > 0 ? <StationConnectorsLive connectors={connectors} /> : null}

      <div className="station-card-actions">
        <div className="station-card-actions-main">
          <button className="secondary-button mini-button" onClick={() => onOpenDetail(station)} type="button">
            <Eye size={14} />
            Detalii
          </button>
          {isConnected ? (
            <>
              <button className="secondary-button mini-button" onClick={() => onRefreshStatus(station)} type="button">
                <RefreshCw size={14} />
                Refresh
              </button>
              {station.active_sessions_count > 0 ? (
                <button className="secondary-button mini-button danger-text" onClick={() => onStopActiveSession(station)} type="button">
                  <Square size={14} />
                  Stop
                </button>
              ) : null}
            </>
          ) : null}
        </div>
        <div className="station-card-actions-icons">
          {isConnected ? (
            <button className="icon-button" onClick={() => onUnlockConnector(station)} type="button" title="UnlockConnector">
              <Unlock size={15} />
            </button>
          ) : null}
          {station.ocpp_connection_url ? (
            <button
              className="icon-button"
              onClick={() => navigator.clipboard?.writeText(station.ocpp_connection_url)}
              type="button"
              title="Copiaza URL OCPP"
            >
              <Copy size={15} />
            </button>
          ) : null}
          <button className="icon-button" onClick={() => onPreviewQr(station)} type="button" title="Preview QR">
            <Search size={15} />
          </button>
          <button className="icon-button" onClick={() => onDiagnostics(station)} type="button" title="GetDiagnostics">
            <ClipboardList size={15} />
          </button>
          <button className="icon-button" onClick={() => onDownloadQr(station)} type="button" title="Descarca QR">
            <Download size={15} />
          </button>
          <button className="icon-button" onClick={() => onEdit(station)} type="button" title="Editeaza">
            <MoreHorizontal size={16} />
          </button>
          <button className="icon-button danger-icon" onClick={() => onDelete(station)} type="button" title="Sterge">
            <X size={15} />
          </button>
        </div>
      </div>
    </article>
  );
}

const DEFAULT_DASHBOARD_PERIOD = { mode: 'preset', days: 14 };

function buildDashboardUrl(period = DEFAULT_DASHBOARD_PERIOD) {
  const base = endpoints.dashboard;

  if (period.mode === 'day' && period.date) {
    return `${base}?date=${period.date}`;
  }

  if (period.mode === 'range' && period.from && period.to) {
    return `${base}?from=${period.from}&to=${period.to}`;
  }

  return `${base}?days=${period.days ?? 14}`;
}

function isDefaultDashboardPeriod(period) {
  return period.mode === 'preset' && period.days === 14;
}

function formatDashboardPeriodLabel(periodInfo) {
  if (!periodInfo?.from) {
    return '14 zile';
  }

  if (periodInfo.granularity === 'hour') {
    return new Date(`${periodInfo.from}T12:00:00`).toLocaleDateString('ro-RO', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric'
    });
  }

  const from = new Date(`${periodInfo.from}T12:00:00`);
  const to = new Date(`${periodInfo.to}T12:00:00`);

  if (periodInfo.from === periodInfo.to) {
    return from.toLocaleDateString('ro-RO', { day: 'numeric', month: 'long', year: 'numeric' });
  }

  return `${from.toLocaleDateString('ro-RO', { day: 'numeric', month: 'short' })} – ${to.toLocaleDateString('ro-RO', {
    day: 'numeric',
    month: 'short',
    year: 'numeric'
  })}`;
}

function TrendBars({ items = [], valueKey = 'sessions', label = 'Sesiuni', granularity = 'day' }) {
  const peak = items.reduce((max, item) => Math.max(max, Number(item[valueKey] || 0)), 0);
  const hourly = granularity === 'hour';
  const barStyle = hourly
    ? undefined
    : { gridTemplateColumns: `repeat(${Math.max(items.length, 1)}, minmax(0, 1fr))` };

  return (
    <div className="trend-chart">
      <div className="trend-chart-head">
        <span>{label}</span>
        <strong>{formatNumber(items.reduce((sum, item) => sum + Number(item[valueKey] || 0), 0))} total</strong>
      </div>
      <div className={`trend-bars${hourly ? ' trend-bars-hourly' : ''}`} style={barStyle}>
        {items.map((item) => {
          const value = Number(item[valueKey] || 0);
          const height = peak ? Math.max((value / peak) * 100, value ? 10 : 0) : 0;

          return (
            <div className="trend-bar-col" key={item.date} title={`${item.label}: ${formatNumber(value)}`}>
              <div className="trend-bar-track">
                <span className="trend-bar-fill" style={{ height: `${height}%` }} />
              </div>
              <small>{item.label}</small>
            </div>
          );
        })}
      </div>
    </div>
  );
}

function DashboardPeriodBar({ period, onChange, loading }) {
  const today = new Date().toISOString().slice(0, 10);
  const [pickDate, setPickDate] = useState(today);
  const [rangeFrom, setRangeFrom] = useState('');
  const [rangeTo, setRangeTo] = useState('');

  return (
    <section className="dash-period-bar">
      <div className="dash-period-main">
        <div className="dash-period-title">
          <Calendar size={18} />
          <div>
            <strong>Perioada analizata</strong>
            <p>Alege interval preset, o zi sau un interval personalizat</p>
          </div>
        </div>
        <div className="dash-period-presets">
          {[7, 14, 30].map((days) => (
            <button
              className={`dash-period-pill${period.mode === 'preset' && period.days === days ? ' active' : ''}`}
              disabled={loading}
              key={days}
              onClick={() => onChange({ mode: 'preset', days })}
              type="button"
            >
              {days} zile
            </button>
          ))}
        </div>
      </div>
      <div className="dash-period-custom">
        <label className="dash-period-field">
          <span>Zi anume</span>
          <input
            max={today}
            onChange={(event) => {
              const nextDate = event.target.value;
              setPickDate(nextDate);
              if (nextDate) {
                onChange({ mode: 'day', date: nextDate });
              }
            }}
            type="date"
            value={period.mode === 'day' ? period.date : pickDate}
          />
        </label>
        <div className="dash-period-range">
          <label className="dash-period-field compact">
            <span>De la</span>
            <input max={today} onChange={(event) => setRangeFrom(event.target.value)} type="date" value={rangeFrom} />
          </label>
          <span className="dash-period-sep">—</span>
          <label className="dash-period-field compact">
            <span>Pana la</span>
            <input max={today} onChange={(event) => setRangeTo(event.target.value)} type="date" value={rangeTo} />
          </label>
          <button
            className="dash-period-apply"
            disabled={loading || !rangeFrom || !rangeTo}
            onClick={() => onChange({ mode: 'range', from: rangeFrom, to: rangeTo })}
            type="button"
          >
            Aplica
          </button>
        </div>
      </div>
    </section>
  );
}

function initialsFrom(value = 'Admin') {
  const words = value
    .split(/[\s@.]+/)
    .filter(Boolean)
    .slice(0, 2);

  return (words.map((word) => word[0]).join('') || 'EV').toUpperCase();
}

function EmptyState({
  title = 'Nu exista date reale inca',
  detail = 'Porneste backend-ul si autentifica backoffice-ul ca sa incarcam informatiile.',
  compact = false
}) {
  return (
    <div className={`empty-state${compact ? ' empty-state-compact' : ''}`}>
      <Activity size={compact ? 18 : 24} />
      <strong>{title}</strong>
      <p>{detail}</p>
    </div>
  );
}

function LoadingState() {
  return (
    <div className="empty-state loading-state">
      <span className="pulse-ring" />
      <strong>Incarc date reale</strong>
      <p>Conectare la endpoint-urile backoffice.</p>
    </div>
  );
}

function LoginView({ error, loading, onSubmit }) {
  return (
    <main className="login-shell">
      <form className="login-panel" onSubmit={onSubmit}>
        <BrandBlock compact />
        <p className="login-eyebrow">Administrare retea</p>
        <h1>Login admin</h1>
        <p className="login-copy">Autentifica-te cu un cont existent din backend pentru a administra reteaua Volta.</p>
        {error && (
          <div className="flash-banner flash-banner-error login-flash">
            <Bell size={18} />
            <span>{error}</span>
          </div>
        )}
        <div className="settings-grid compact">
          <label>
            Email
            <input name="email" type="email" autoComplete="email" required />
          </label>
          <label>
            Parola
            <input name="password" type="password" autoComplete="current-password" required />
          </label>
        </div>
        <button className="primary-button login-button" disabled={loading} type="submit">
          {loading ? 'Se autentifica' : 'Intra in backoffice'}
        </button>
      </form>
      <p className="login-powered">powered by Mejievski</p>
    </main>
  );
}

function DashboardView({ dashboard: initialDashboard, loading: parentLoading, activeSessions = [] }) {
  const [period, setPeriod] = useState(DEFAULT_DASHBOARD_PERIOD);
  const [dashboard, setDashboard] = useState(initialDashboard);
  const [periodLoading, setPeriodLoading] = useState(false);
  const [periodError, setPeriodError] = useState('');

  useEffect(() => {
    if (initialDashboard && isDefaultDashboardPeriod(period)) {
      setDashboard(initialDashboard);
    }
  }, [initialDashboard, period]);

  useEffect(() => {
    let cancelled = false;

    async function loadDashboardForPeriod() {
      setPeriodLoading(true);
      setPeriodError('');

      try {
        const payload = await fetchJson(buildDashboardUrl(period));
        if (!cancelled) {
          setDashboard(payload);
        }
      } catch (error) {
        if (!cancelled) {
          setPeriodError(error.message || 'Nu am putut incarca perioada selectata.');
        }
      } finally {
        if (!cancelled) {
          setPeriodLoading(false);
        }
      }
    }

    loadDashboardForPeriod();

    return () => {
      cancelled = true;
    };
  }, [period]);

  const stats = dashboard?.stats;
  const analytics = dashboard?.analytics;
  const stationStatus = dashboard?.stationStatus;
  const ocpp = dashboard?.ocpp;
  const periodInfo = analytics?.period ?? dashboard?.period;
  const periodStats = analytics?.periodStats;
  const granularity = periodInfo?.granularity ?? 'day';
  const periodLabel = formatDashboardPeriodLabel(periodInfo);
  const trendUnit = granularity === 'hour' ? 'ora' : 'zi';
  const topStations = analytics?.topStations ?? analytics?.topStationsMonth ?? [];
  const currency = analytics?.currency ?? 'MDL';
  const formatCurrency = (value) =>
    value === null || value === undefined
      ? '-'
      : `${new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 2 }).format(value)} ${currency}`;

  if (parentLoading && !dashboard) return <LoadingState />;

  return (
    <div className="view-stack dashboard-v2 ops-page">
      <DashboardPeriodBar loading={periodLoading} onChange={setPeriod} period={period} />
      {periodError && <div className="error-banner">{periodError}</div>}

      <section className="stats-grid dash-kpi-grid">
        <StatCard
          helper={`${formatNumber(periodStats?.closedSessions)} inchise · ${formatNumber(stats?.activeSessions)} active acum`}
          icon={Activity}
          label="Sesiuni in perioada"
          value={formatNumber(periodStats?.sessions)}
        />
        <StatCard
          helper={`medie ${formatKwh(analytics?.averages?.sessionKwh30d)} kWh/sesiune`}
          icon={BatteryCharging}
          label="Energie in perioada"
          value={`${formatKwh(periodStats?.kwh)} kWh`}
        />
        <StatCard
          helper={`${formatCurrency(periodStats?.walletTopups)} alimentari wallet`}
          icon={CircleDollarSign}
          label="Incasari in perioada"
          value={formatCurrency(periodStats?.revenue)}
        />
        <StatCard
          helper={`${formatNumber(analytics?.revenue?.unpaidInvoices)} facturi neplatite`}
          icon={Receipt}
          label="Restanta totala"
          value={formatCurrency(analytics?.revenue?.unpaidTotal)}
        />
      </section>

      <section className="content-grid dash-main-grid">
        <div className="dash-main-column">
          <section className={`panel${periodLoading ? ' panel-loading' : ''}`}>
            <div className="panel-header">
              <div>
                <h2>Performanta · {periodLabel}</h2>
                <p>
                  Sesiuni, energie livrata si incasari pe {trendUnit}
                  {periodInfo?.days ? ` · ${periodInfo.days} zile` : ''}
                </p>
              </div>
              <TrendingUp size={20} />
            </div>
            <div className="analytics-trends">
              <TrendBars
                granularity={granularity}
                items={analytics?.dailyTrend ?? []}
                label={`Sesiuni / ${trendUnit}`}
                valueKey="sessions"
              />
              <TrendBars
                granularity={granularity}
                items={analytics?.dailyTrend ?? []}
                label={`kWh livrati / ${trendUnit}`}
                valueKey="kwh"
              />
              <TrendBars
                granularity={granularity}
                items={analytics?.dailyTrend ?? []}
                label={`Incasari ${currency} / ${trendUnit}`}
                valueKey="revenue"
              />
            </div>
          </section>

          <section className="panel">
            <div className="panel-header">
              <div>
                <h2>Sesiuni active</h2>
                <p>Telemetrie live din contor</p>
              </div>
              <BatteryCharging size={20} />
            </div>
            {activeSessions.length === 0 ? (
              <EmptyState title="Nicio sesiune activa" detail="Cand un utilizator incarca, apare aici cu kWh si kW live." compact />
            ) : (
              <div className="table">
                {activeSessions.map((session) => (
                  <div className="table-row four" key={session.id}>
                    <div>
                      <strong>{session.user?.name ?? '-'}</strong>
                      <p>{session.station?.name ?? '-'}</p>
                    </div>
                    <span className="live-kwh">
                      {formatKwh(sessionKwhDelivered(session))} kWh
                      {sessionPowerKw(session) != null ? ` · ${formatKwh(sessionPowerKw(session), 2)} kW` : ''}
                    </span>
                    <Badge variant="warning">Activa</Badge>
                    <span>{session.start_time ? new Date(session.start_time).toLocaleString('ro-RO') : '-'}</span>
                  </div>
                ))}
              </div>
            )}
          </section>
        </div>

        <aside className="dash-side-column">
          <DashboardNetworkCard stats={stats} stationStatus={stationStatus} />

          <section className="panel">
            <div className="panel-header">
              <div>
                <h2>Sumar</h2>
                <p>Financiar, utilizatori si operational</p>
              </div>
              <Wallet size={20} />
            </div>
            <div className="detail-metrics-grid dash-side-metrics">
              <DetailMetric label="Total facturat" value={formatCurrency(analytics?.revenue?.invoicedTotal ?? stats?.totalRevenue)} helper={`${formatCurrency(analytics?.revenue?.paidTotal)} incasat`} />
              <DetailMetric label="Alimentari luna" value={formatCurrency(analytics?.wallet?.topupsMonth)} helper={`azi ${formatCurrency(analytics?.wallet?.topupsToday ?? stats?.walletTopupsVolumeToday)}`} />
              <DetailMetric label="Utilizatori" value={formatNumber(stats?.users)} helper={`${formatNumber(analytics?.users?.customer)} conturi`} />
              <DetailMetric label="kWh luna" value={`${formatKwh(analytics?.energy?.month)} kWh`} helper={`${formatNumber(analytics?.sessions?.month)} sesiuni`} />
              <DetailMetric label="OCPP" value={formatNumber(stats?.connectedStations)} helper={`mod ${ocpp?.mode ?? '-'}`} />
            </div>
          </section>

          <section className="panel">
            <div className="panel-header">
              <div>
                <h2>Top statii</h2>
                <p>{periodLabel}</p>
              </div>
              <MapPin size={20} />
            </div>
            {topStations.length === 0 ? (
              <EmptyState title="Nicio sesiune" detail="Topul apare dupa primele incarcari." compact />
            ) : (
              <div className="rank-list">
                {topStations.map((station, index) => (
                  <div className="rank-row" key={station.station_id}>
                    <span className="rank-index">#{index + 1}</span>
                    <div className="rank-copy">
                      <strong>{station.station_name}</strong>
                      <p>{formatNumber(station.sessions_count)} sesiuni · {formatKwh(station.total_kwh)} kWh</p>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </section>
        </aside>
      </section>
    </div>
  );
}

function StationConnectorsLive({ connectors = [] }) {
  if (connectors.length === 0) {
    return null;
  }

  return (
    <div className="connector-live-row">
      {connectors.map((connector) => (
        <span
          className={`connector-live-pill ${connector.has_active_session ? 'active' : ''}`}
          key={connector.id}
          title={connector.status || connector.availability || ''}
        >
          <strong>{connector.label ?? connector.id}</strong>
          <Badge variant={statusVariant(connector.availability ?? connector.status)}>
            {connector.status || availabilityLabel(connector.availability) || '—'}
          </Badge>
          {(connector.power_kw != null || connector.energy_kwh != null) && (
            <span className="connector-live-metric">
              {connector.power_kw != null
                ? `${formatKwh(connector.power_kw, 2)} kW`
                : `${formatKwh(connector.energy_kwh)} kWh`}
            </span>
          )}
        </span>
      ))}
    </div>
  );
}

function StationsMapPanel({ stations, onOpenDetail }) {
  const mappedStations = useMemo(() => stations.filter(stationHasCoordinates), [stations]);
  const autoView = useMemo(() => fitMapView(stations), [stations]);
  const [zoomOffset, setZoomOffset] = useState(0);
  const zoom = Math.max(8, Math.min(18, autoView.zoom + zoomOffset));
  const center = useMemo(
    () => latLonToWorld(autoView.centerLat, autoView.centerLon, zoom, MAP_TILE_SIZE),
    [autoView.centerLat, autoView.centerLon, zoom]
  );
  const centerTileX = Math.floor(center.x / MAP_TILE_SIZE);
  const centerTileY = Math.floor(center.y / MAP_TILE_SIZE);
  const tiles = useMemo(() => {
    const nextTiles = [];

    for (let dx = -3; dx <= 3; dx += 1) {
      for (let dy = -4; dy <= 4; dy += 1) {
        const x = centerTileX + dx;
        const y = centerTileY + dy;
        nextTiles.push({
          key: `${zoom}-${x}-${y}`,
          left: x * MAP_TILE_SIZE - center.x,
          top: y * MAP_TILE_SIZE - center.y,
          url: `https://tile.openstreetmap.org/${zoom}/${x}/${y}.png`
        });
      }
    }

    return nextTiles;
  }, [center.x, center.y, centerTileX, centerTileY, zoom]);
  const markers = useMemo(
    () => mappedStations.map((station) => {
      const world = latLonToWorld(station.latitude, station.longitude, zoom, MAP_TILE_SIZE);

      return {
        station,
        left: world.x - center.x,
        top: world.y - center.y,
        color: stationMarkerColor(station)
      };
    }),
    [mappedStations, center.x, center.y, zoom]
  );
  const missingCoordinates = stations.length - mappedStations.length;

  return (
    <div className="stations-map-wrap">
      {missingCoordinates > 0 && (
        <div className="map-hint-banner">
          {missingCoordinates} {missingCoordinates === 1 ? 'statie fara' : 'statii fara'} coordonate GPS.
          Editeaza statia si adauga lat/long pentru a o afisa pe harta.
        </div>
      )}

      <div className="stations-map">
        <div className="stations-map-canvas">
          {tiles.map((tile) => (
            <img
              alt=""
              className="map-tile"
              draggable={false}
              key={tile.key}
              src={tile.url}
              style={{
                left: `calc(50% + ${tile.left}px)`,
                top: `calc(50% + ${tile.top}px)`
              }}
            />
          ))}

          {markers.map(({ station, left, top, color }) => (
            <button
              className="map-marker"
              key={station.id}
              onClick={() => onOpenDetail(station)}
              style={{
                left: `calc(50% + ${left}px)`,
                top: `calc(50% + ${top}px)`,
                background: color
              }}
              title={`${station.name} · ${station.location ?? ''}`}
              type="button"
            >
              <span className="map-marker-core" />
            </button>
          ))}
        </div>

        <div className="map-controls">
          <button
            aria-label="Zoom in"
            className="secondary-button mini-button"
            onClick={() => setZoomOffset((current) => current + 1)}
            type="button"
          >
            <Plus size={14} />
          </button>
          <button
            aria-label="Zoom out"
            className="secondary-button mini-button"
            onClick={() => setZoomOffset((current) => current - 1)}
            type="button"
          >
            <Minus size={14} />
          </button>
          <button
            className="secondary-button mini-button"
            onClick={() => setZoomOffset(0)}
            type="button"
          >
            <RefreshCw size={14} />
          </button>
        </div>

        <div className="map-legend">
          <span><i className="legend-dot available" /> Disponibila</span>
          <span><i className="legend-dot charging" /> In incarcare</span>
          <span><i className="legend-dot offline" /> Offline / eroare</span>
        </div>

        <div className="map-attribution">© OpenStreetMap</div>
      </div>
    </div>
  );
}

const stationStatusFilters = [
  { id: 'all', label: 'Toate' },
  { id: 'available', label: 'Disponibile' },
  { id: 'charging', label: 'In incarcare' },
  { id: 'offline', label: 'Offline' },
  { id: 'connected', label: 'OCPP online' }
];

function StationsView({
  rows,
  loading,
  onCreate,
  onEdit,
  onDelete,
  onDownloadQr,
  onPreviewQr,
  onDiagnostics,
  onRefreshStatus,
  onUnlockConnector,
  onStopActiveSession,
  onOpenDetail
}) {
  const [query, setQuery] = useState('');
  const [viewMode, setViewMode] = useState('list');
  const [statusFilter, setStatusFilter] = useState('all');

  const summary = useMemo(
    () => ({
      total: rows.length,
      available: rows.filter((station) => effectiveStationStatus(station) === 'available').length,
      charging: rows.filter((station) => effectiveStationStatus(station) === 'charging').length,
      offline: rows.filter((station) => effectiveStationStatus(station) === 'offline').length,
      connected: rows.filter((station) => effectiveOcppConnectionStatus(station) === 'connected').length,
      activeSessions: rows.reduce((sum, station) => sum + Number(station.active_sessions_count || 0), 0)
    }),
    [rows]
  );

  const visibleRows = rows.filter((station) => {
    if (statusFilter === 'connected') {
      if (effectiveOcppConnectionStatus(station) !== 'connected') return false;
    } else if (statusFilter !== 'all' && effectiveStationStatus(station) !== statusFilter) {
      return false;
    }

    return matchesQuery(station, query, [
      (item) => item.name,
      (item) => item.location,
      (item) => item.status,
      (item) => item.qr_code,
      (item) => item.ocpp_identity,
      (item) => effectiveOcppConnectionStatus(item),
      (item) => item.live_status?.availability,
      (item) => item.live_status?.connector_status,
      (item) => item.connector_type
    ]);
  });

  if (loading && !rows.length) return <LoadingState />;

  return (
    <div className="view-stack stations-page ops-page">
      <div className="panel stations-panel ops-panel">
        <div className="panel-header stations-panel-header ops-panel-header">
          <div>
            <h2>Statii</h2>
            <p>Administrare retea, status OCPP si actiuni rapide</p>
          </div>
          <div className="panel-header-actions">
            <div className="view-toggle">
              <button
                className={viewMode === 'list' ? 'secondary-button active-filter' : 'secondary-button'}
                onClick={() => setViewMode('list')}
                type="button"
              >
                <RadioTower size={16} />
                Carduri
              </button>
              <button
                className={viewMode === 'map' ? 'secondary-button active-filter' : 'secondary-button'}
                onClick={() => setViewMode('map')}
                type="button"
              >
                <Map size={16} />
                Harta
              </button>
            </div>
            <button className="primary-button" onClick={onCreate} type="button">
              <Plus size={18} />
              Statie noua
            </button>
          </div>
        </div>

        <div className="ops-kpi-bar ops-kpi-cols-4">
          <div className="ops-kpi tone-success">
            <span>Libere</span>
            <strong>{formatNumber(summary.available)}</strong>
          </div>
          <div className="ops-kpi tone-warning">
            <span>In incarcare</span>
            <strong>{formatNumber(summary.charging)}</strong>
          </div>
          <div className="ops-kpi tone-danger">
            <span>Offline</span>
            <strong>{formatNumber(summary.offline)}</strong>
          </div>
          <div className="ops-kpi tone-live">
            <span>OCPP · {formatNumber(summary.activeSessions)} sesiuni</span>
            <strong>{formatNumber(summary.connected)}</strong>
          </div>
        </div>

        {viewMode === 'map' ? (
          <StationsMapPanel onOpenDetail={onOpenDetail} stations={visibleRows.length ? visibleRows : rows} />
        ) : (
          <>
            <div className="ops-control-bar">
              <Toolbar value={query} onChange={setQuery} />
              <div className="ops-filter-row">
                {stationStatusFilters.map((filter) => (
                  <button
                    className={statusFilter === filter.id ? 'filter-chip active-filter' : 'filter-chip'}
                    key={filter.id}
                    onClick={() => setStatusFilter(filter.id)}
                    type="button"
                  >
                    {filter.label}
                  </button>
                ))}
                <span className="ops-result-count">{formatNumber(visibleRows.length)} afisate</span>
              </div>
            </div>

            {rows.length === 0 ? (
              <EmptyState title="Nu exista statii" detail="Cand backend-ul returneaza statii, apar aici automat." />
            ) : visibleRows.length === 0 ? (
              <EmptyState title="Nicio statie gasita" detail="Schimba filtrul sau termenul de cautare." />
            ) : (
              <div className="stations-card-grid">
                {visibleRows.map((station) => (
                  <StationModernCard
                    key={station.id}
                    onDelete={onDelete}
                    onDiagnostics={onDiagnostics}
                    onDownloadQr={onDownloadQr}
                    onEdit={onEdit}
                    onOpenDetail={onOpenDetail}
                    onPreviewQr={onPreviewQr}
                    onRefreshStatus={onRefreshStatus}
                    onStopActiveSession={onStopActiveSession}
                    onUnlockConnector={onUnlockConnector}
                    station={station}
                  />
                ))}
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}

const sessionStatusFilters = [
  { id: '', label: 'Toate' },
  { id: 'active', label: 'Active' },
  { id: 'closed', label: 'Inchise' }
];

const reservationStatusLabels = {
  pending: 'In asteptare',
  confirmed: 'Confirmata',
  active: 'In folosinta',
  completed: 'Finalizata',
  cancelled: 'Anulata',
  expired: 'Expirata',
  no_show: 'Neprezentare'
};

function ReservationsView({ rows, loading }) {
  const [query, setQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState('');

  const summary = useMemo(() => {
    const activeLike = rows.filter((item) => ['pending', 'confirmed', 'active'].includes(item.status));
    const completed = rows.filter((item) => item.status === 'completed');
    const cancelled = rows.filter((item) => ['cancelled', 'expired', 'no_show'].includes(item.status));

    return {
      activeLike: activeLike.length,
      completed: completed.length,
      cancelled: cancelled.length
    };
  }, [rows]);

  const visibleRows = rows.filter((reservation) => {
    if (statusFilter && reservation.status !== statusFilter) return false;

    return matchesQuery(reservation, query, [
      (item) => item.user?.name,
      (item) => item.user?.email,
      (item) => item.station?.name,
      (item) => item.status,
      (item) => reservationStatusLabels[item.status]
    ]);
  });

  return (
    <ListPanel
      columns={['Utilizator / Statie', 'Interval', 'Status', 'Taxa']}
      emptyDetail="Rezervarile apar aici dupa ce utilizatorii rezervă un slot."
      emptyTitle="Nu exista rezervari"
      filters={(
        <>
          <button
            className={statusFilter === '' ? 'filter-chip active-filter' : 'filter-chip'}
            onClick={() => setStatusFilter('')}
            type="button"
          >
            Toate
          </button>
          {Object.entries(reservationStatusLabels).map(([id, label]) => (
            <button
              className={statusFilter === id ? 'filter-chip active-filter' : 'filter-chip'}
              key={id}
              onClick={() => setStatusFilter(id)}
              type="button"
            >
              {label}
            </button>
          ))}
        </>
      )}
      icon={Calendar}
      kpis={[
        { label: 'Active / programate', value: formatNumber(summary.activeLike), tone: 'warning' },
        { label: 'Finalizate', value: formatNumber(summary.completed), tone: 'success' },
        { label: 'Anulate / expirate', value: formatNumber(summary.cancelled) }
      ]}
      loading={loading}
      noResults={rows.length > 0 && visibleRows.length === 0}
      onSearchChange={setQuery}
      render={(reservation) => (
        <>
          <div className="ops-cell">
            <strong>{reservation.user?.name ?? '-'}</strong>
            <p>
              {reservation.station?.name ?? '-'}
              {reservation.connector_id ? ` · Port ${reservation.connector_id}` : ''}
            </p>
          </div>
          <div className="ops-cell">
            <strong>{formatDateTime(reservation.starts_at)}</strong>
            <span>→ {formatDateTime(reservation.ends_at)}</span>
          </div>
          <Badge>{reservationStatusLabels[reservation.status] ?? reservation.status}</Badge>
          <div className="ops-cell">
            {reservation.fee_amount > 0 ? (
              <strong>{formatMoney(reservation.fee_amount)}</strong>
            ) : (
              <span className="ops-muted">Gratuit</span>
            )}
          </div>
        </>
      )}
      resultCount={visibleRows.length}
      rows={visibleRows}
      rowClassName="ops-row ops-row-4"
      searchValue={query}
      subtitle="Sloturi rezervate, taxe si status"
      title="Rezervari"
    />
  );
}

function SessionOpsRow({ session, onStop, onDelete, onDownloadInvoice, onDebug }) {
  const isActive = !session.end_time;
  const kwh = sessionKwhDelivered(session);
  const powerKw = sessionPowerKw(session);
  const stopInfo = formatSessionStopInfo(session);
  const spent = session.billing?.amount_spent;
  const userLabel = session.user?.name ?? session.user?.email ?? 'Utilizator necunoscut';
  const stationLabel = [
    session.station?.name ?? 'Statie necunoscuta',
    session.ocpp_connector_id ? `C${session.ocpp_connector_id}` : null,
  ].filter(Boolean).join(' · ');
  const timeLabel = isActive
    ? (session.start_time ? formatDateTime(session.start_time) : 'In curs')
    : formatSessionDuration(session);
  const metaParts = [
    session.user?.email && session.user?.name ? session.user.email : null,
    stationLabel,
    stopInfo,
  ].filter(Boolean);

  return (
    <article className={`session-ops-row ${isActive ? 'is-active' : 'is-closed'}`}>
      <div className="session-ops-status">
        <Badge variant={isActive ? 'warning' : 'success'}>{isActive ? 'Activa' : 'Inchisa'}</Badge>
      </div>

      <div className="session-ops-identity">
        <strong>{userLabel}</strong>
        <p>{metaParts.join(' · ')}</p>
      </div>

      <div className="session-ops-energy">
        <strong className={isActive ? 'metric-live' : ''}>{formatKwh(kwh)} kWh</strong>
        {isActive && powerKw != null ? (
          <span className="metric-live">{formatKwh(powerKw, 2)} kW</span>
        ) : session.charge_budget > 0 ? (
          <span>Buget {formatMoney(session.charge_budget)}</span>
        ) : session.target_kwh > 0 ? (
          <span>Limita {formatKwh(session.target_kwh)} kWh</span>
        ) : null}
      </div>

      <div className="session-ops-cost">
        {!isActive && spent != null ? (
          <strong>{formatMoney(spent)}</strong>
        ) : (
          <span className="session-ops-muted">—</span>
        )}
      </div>

      <div className="session-ops-time">
        <strong>{timeLabel}</strong>
        {!isActive && session.start_time ? (
          <span>{formatDateTime(session.start_time)}</span>
        ) : isActive ? (
          <span>in curs</span>
        ) : null}
      </div>

      <div className="session-ops-actions">
        {isActive ? (
          <button className="secondary-button mini-button danger-text" onClick={() => onStop(session)} type="button">
            <Square size={14} />
            Opreste
          </button>
        ) : null}
        {session.invoice?.id && onDownloadInvoice ? (
          <button className="secondary-button mini-button" onClick={() => onDownloadInvoice(session.invoice)} type="button" title="Descarca factura">
            <Download size={14} />
            Factura
          </button>
        ) : null}
        <button className="icon-button" onClick={() => onDebug(session)} type="button" title="Debug OCPP">
          <Bug size={15} />
        </button>
        <button className="icon-button danger-icon" onClick={() => onDelete(session)} type="button" title="Sterge sesiunea">
          <X size={15} />
        </button>
      </div>
    </article>
  );
}

function SessionsView({ rows, loading, onStop, onDelete, onRefresh, onDownloadInvoice, onDebug }) {
  const [query, setQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState('');

  const summary = useMemo(() => {
    const active = rows.filter((session) => !session.end_time);
    const closed = rows.filter((session) => session.end_time);
    const totalKwh = rows.reduce((sum, session) => sum + Number(sessionKwhDelivered(session) || 0), 0);
    const totalRevenue = closed.reduce(
      (sum, session) => sum + Number(session.billing?.amount_spent || 0),
      0
    );

    return {
      total: rows.length,
      active: active.length,
      closed: closed.length,
      totalKwh,
      totalRevenue
    };
  }, [rows]);

  const visibleRows = useMemo(() => rows
    .filter((session) => {
      if (statusFilter === 'active' && session.end_time) return false;
      if (statusFilter === 'closed' && !session.end_time) return false;

      return matchesQuery(session, query, [
        (item) => item.user?.name,
        (item) => item.user?.email,
        (item) => item.station?.name,
        (item) => item.ocpp_transaction_id,
        (item) => item.end_time ? 'inchisa' : 'activa'
      ]);
    })
    .sort((left, right) => {
      const leftActive = !left.end_time;
      const rightActive = !right.end_time;
      if (leftActive !== rightActive) return leftActive ? -1 : 1;

      const leftTime = left.start_time ? new Date(left.start_time).getTime() : 0;
      const rightTime = right.start_time ? new Date(right.start_time).getTime() : 0;

      return rightTime - leftTime;
    }), [rows, query, statusFilter]);

  if (loading && !rows.length) return <LoadingState />;

  return (
    <div className="view-stack sessions-page ops-page">
      <div className="panel sessions-panel ops-panel">
        <div className="panel-header sessions-panel-header ops-panel-header">
          <div>
            <h2>Sesiuni</h2>
            <p>Monitorizare incarcare, consum OCPP si actiuni rapide</p>
          </div>
          <span className="ops-header-icon"><BatteryCharging size={20} /></span>
        </div>

        <div className="sessions-summary-bar ops-kpi-bar">
          <div className="sessions-kpi ops-kpi tone-warning">
            <span>Active acum</span>
            <strong>{formatNumber(summary.active)}</strong>
          </div>
          <div className="sessions-kpi ops-kpi tone-live">
            <span>Energie livrata</span>
            <strong>{formatKwh(summary.totalKwh, 1)} <small>kWh</small></strong>
          </div>
          <div className="sessions-kpi ops-kpi">
            <span>Incasat · {formatNumber(summary.closed)} finalizate</span>
            <strong>{formatMoney(summary.totalRevenue)}</strong>
          </div>
        </div>

        <div className="sessions-control-bar ops-control-bar">
          <Toolbar onRefresh={onRefresh} value={query} onChange={setQuery} />
          <div className="sessions-filter-row ops-filter-row">
            {sessionStatusFilters.map((filter) => (
              <button
                className={statusFilter === filter.id ? 'filter-chip active-filter' : 'filter-chip'}
                key={filter.id || 'all'}
                onClick={() => setStatusFilter(filter.id)}
                type="button"
              >
                {filter.label}
              </button>
            ))}
            <span className="sessions-result-count ops-result-count">{formatNumber(visibleRows.length)} afisate</span>
          </div>
        </div>

        {rows.length === 0 ? (
          <EmptyState
            detail="Cand apar sesiuni de incarcare, le vei vedea aici cu consum si status."
            title="Nu exista sesiuni"
          />
        ) : visibleRows.length === 0 ? (
          <EmptyState
            detail="Schimba filtrul sau termenul de cautare."
            title="Niciun rezultat"
          />
        ) : (
          <div className="sessions-ops-list">
            <div className="sessions-ops-head" aria-hidden="true">
              <span>Status</span>
              <span>Utilizator / Statie</span>
              <span>Energie</span>
              <span>Cost</span>
              <span>Timp</span>
              <span>Actiuni</span>
            </div>
            {visibleRows.map((session) => (
              <SessionOpsRow
                key={session.id}
                onDebug={onDebug}
                onDelete={onDelete}
                onDownloadInvoice={onDownloadInvoice}
                onStop={onStop}
                session={session}
              />
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

function InvoicesView({ rows, loading, onDownload, onSend, onDelete }) {
  const [query, setQuery] = useState('');
  const visibleRows = rows.filter((invoice) => matchesQuery(invoice, query, [
    (item) => item.invoice_number,
    (item) => item.user?.name,
    (item) => item.user?.email,
    (item) => item.month,
    (item) => item.status
  ]));

  const summary = useMemo(() => {
    const paid = rows.filter((invoice) => invoice.status === 'paid');
    const unpaid = rows.filter((invoice) => invoice.status !== 'paid');
    const total = unpaid.reduce((sum, invoice) => sum + Number(invoice.total_amount || 0), 0);

    return {
      paid: paid.length,
      unpaid: unpaid.length,
      outstanding: total
    };
  }, [rows]);

  return (
    <ListPanel
      columns={['Factura', 'Perioada', 'Status', 'Suma / Actiuni']}
      emptyTitle="Nu exista facturi"
      icon={Receipt}
      kpis={[
        { label: 'Platite', value: formatNumber(summary.paid), tone: 'success' },
        { label: 'Neplatite', value: formatNumber(summary.unpaid), tone: 'warning' },
        { label: 'Restanta', value: formatMoney(summary.outstanding), tone: 'danger' }
      ]}
      loading={loading}
      noResults={rows.length > 0 && visibleRows.length === 0}
      onSearchChange={setQuery}
      render={(invoice) => (
        <>
          <div className="ops-cell">
            <strong>{invoice.invoice_number ?? `#${invoice.id}`}</strong>
            <p>{invoice.user?.name ?? '-'}</p>
          </div>
          <div className="ops-cell">
            <strong>{invoice.month ?? '-'}</strong>
            <span>{invoiceTypeLabel(invoice.type)}</span>
          </div>
          <Badge variant={statusVariant(invoice.status)}>{statusLabel(invoice.status)}</Badge>
          <div className="ops-actions">
            <strong>{formatMoney(invoice.total_amount)}</strong>
            <button className="secondary-button mini-button" onClick={() => onDownload(invoice)} type="button">
              <Download size={14} />
              Descarca
            </button>
            <button className="primary-button mini-button" onClick={() => onSend(invoice)} type="button">
              Trimite
            </button>
            <button className="icon-button danger-icon" onClick={() => onDelete(invoice)} type="button" aria-label="Sterge factura">
              <X size={16} />
            </button>
          </div>
        </>
      )}
      resultCount={visibleRows.length}
      rows={visibleRows}
      rowClassName="ops-row ops-row-4"
      searchValue={query}
      subtitle="Plati, solduri si documente fiscale"
      title="Facturi"
    />
  );
}

function auditSubjectLabel(entry) {
  if (!entry?.subject_type) {
    return '-';
  }

  const short = String(entry.subject_type).split('\\').pop();
  return entry.subject_id ? `${short} #${entry.subject_id}` : short;
}

function AuditView({ rows, loading, onOpenDetail }) {
  const [query, setQuery] = useState('');
  const visibleRows = rows.filter((entry) => matchesQuery(entry, query, [
    (item) => item.action,
    (item) => item.actor?.name,
    (item) => item.actor?.email,
    (item) => item.station?.name,
    (item) => item.subject_type,
    (item) => auditSubjectLabel(item)
  ]));

  return (
    <ListPanel
      columns={['Actiune', 'Context', 'Cand', '']}
      emptyTitle="Nu exista intrari audit"
      icon={ShieldCheck}
      kpis={[
        { label: 'Intrari', value: formatNumber(rows.length) },
        { label: 'Afisate', value: formatNumber(visibleRows.length), tone: 'live' },
        { label: 'Ultima', value: rows[0]?.created_at ? formatDateTime(rows[0].created_at) : '—' }
      ]}
      loading={loading}
      noResults={rows.length > 0 && visibleRows.length === 0}
      onSearchChange={setQuery}
      render={(entry) => (
        <>
          <div className="ops-cell">
            <button className="station-name-link" onClick={() => onOpenDetail(entry)} type="button">
              <strong>{entry.action}</strong>
            </button>
            <p>{entry.actor?.name ?? 'Sistem'}{entry.actor?.email ? ` · ${entry.actor.email}` : ''}</p>
          </div>
          <div className="ops-cell">
            <strong>{entry.station?.name ?? auditSubjectLabel(entry)}</strong>
            <span>{auditSubjectLabel(entry)}</span>
          </div>
          <div className="ops-cell">
            <strong>{formatDateTime(entry.created_at)}</strong>
          </div>
          <div className="ops-actions">
            <button
              className="secondary-button mini-button"
              onClick={() => onOpenDetail(entry)}
              type="button"
              title="Detalii audit"
            >
              <Eye size={14} />
              Detalii
            </button>
          </div>
        </>
      )}
      resultCount={visibleRows.length}
      rows={visibleRows}
      rowClassName="ops-row ops-row-4"
      searchValue={query}
      subtitle="Actiuni backoffice si gateway"
      title="Audit"
    />
  );
}

function SessionOcppDebugModal({ detail, loading, error, onClose, onReload }) {
  if (!detail) {
    return null;
  }

  const session = detail.session ?? {};
  const analysis = detail.analysis ?? {};
  const timeline = detail.timeline ?? [];
  const stopContext = detail.stop_context;
  const connectorsNow = detail.connector_states_now ?? [];

  return (
    <div className="modal-backdrop" role="presentation">
      <div className="modal-panel modal-panel-wide ocpp-debug-modal">
        <div className="panel-header">
          <div>
            <h2>Debug OCPP · sesiune #{session.id}</h2>
            <p>
              {session.user?.name ?? '-'}
              {session.ocpp_connector_id ? ` · port ${session.ocpp_connector_id === 2 ? 'B' : session.ocpp_connector_id === 1 ? 'A' : `C${session.ocpp_connector_id}`}` : ''}
              {session.ocpp_transaction_id ? ` · tx ${session.ocpp_transaction_id}` : ''}
            </p>
          </div>
          <div className="row-actions">
            <button className="secondary-button mini-button" onClick={onReload} type="button">
              <RefreshCw size={14} />
              Reincarca
            </button>
            <button className="icon-button" onClick={onClose} type="button" aria-label="Inchide">
              <X size={18} />
            </button>
          </div>
        </div>

        {error && <div className="error-banner">{error}</div>}
        {loading && <LoadingState />}

        {!loading && (
          <>
            <div className="billing-summary-grid">
              <div className="billing-stat">
                <span>Stop source</span>
                <strong>{session.stop_source ?? '—'}</strong>
              </div>
              <div className="billing-stat">
                <span>Motiv OCPP</span>
                <strong>{session.ocpp_stop_reason ?? '—'}</strong>
              </div>
              <div className="billing-stat">
                <span>Start</span>
                <strong>{formatDateTime(session.start_time)}</strong>
              </div>
              <div className="billing-stat">
                <span>Sfarsit</span>
                <strong>{formatDateTime(session.end_time)}</strong>
              </div>
            </div>

            {analysis.hypothesis ? (
              <div className="ocpp-debug-hypothesis">
                <strong>Analiza:</strong> {analysis.hypothesis}
              </div>
            ) : null}

            <div className="detail-section">
              <h3>Conectori (stare curenta)</h3>
              <div className="ocpp-connector-grid">
                {connectorsNow.map((connector) => (
                  <div className="ocpp-connector-card" key={connector.connector_id}>
                    <strong>Port {connector.label ?? connector.connector_id}</strong>
                    <span>{connector.status ?? '—'}</span>
                    {connector.error_code && connector.error_code !== 'NoError' ? (
                      <span className="ocpp-error-chip">{connector.error_code}</span>
                    ) : null}
                    {connector.info ? <p>{connector.info}</p> : null}
                  </div>
                ))}
              </div>
            </div>

            {stopContext ? (
              <div className="detail-section">
                <h3>Context la oprire (snapshot)</h3>
                <div className="meta-grid">
                  <span>Trigger: <strong>{stopContext.trigger ?? '—'}</strong></span>
                  <span>Capturat: <strong>{formatDateTime(stopContext.captured_at)}</strong></span>
                </div>
                {Array.isArray(stopContext.connector_states) && stopContext.connector_states.length > 0 ? (
                  <div className="ocpp-connector-grid compact">
                    {stopContext.connector_states.map((connector) => (
                      <div className="ocpp-connector-card" key={`snap-${connector.connector_id}`}>
                        <strong>Port {connector.label ?? connector.connector_id}</strong>
                        <span>{connector.status ?? '—'}</span>
                      </div>
                    ))}
                  </div>
                ) : null}
              </div>
            ) : null}

            <div className="detail-section">
              <h3>Timeline OCPP</h3>
              <p className="detail-empty">
                Fereastra: {formatDateTime(detail.window?.from)} → {formatDateTime(detail.window?.to)}
                {' · '}
                {timeline.length} evenimente
              </p>
              {timeline.length === 0 ? (
                <p className="detail-empty">Nu exista mesaje OCPP in aceasta fereastra.</p>
              ) : (
                <div className="ocpp-timeline">
                  {timeline.map((entry) => (
                    <details className={`ocpp-timeline-row ${ocppRelationClass(entry.relation)}`} key={`${entry.kind}-${entry.id}-${entry.at}`}>
                      <summary>
                        <span className="ocpp-timeline-time">{formatDateTime(entry.at)}</span>
                        <span className="ocpp-timeline-badge">{entry.direction === 'inbound' ? 'IN' : 'OUT'}</span>
                        <span className="ocpp-timeline-action">{entry.action}</span>
                        <span className="ocpp-timeline-summary">{entry.summary}</span>
                        <span className="ocpp-timeline-relation">{ocppRelationLabel(entry.relation)}</span>
                      </summary>
                      <pre className="ocpp-timeline-payload">{JSON.stringify(entry.payload, null, 2)}</pre>
                      {entry.response_payload ? (
                        <pre className="ocpp-timeline-payload muted">{JSON.stringify(entry.response_payload, null, 2)}</pre>
                      ) : null}
                    </details>
                  ))}
                </div>
              )}
            </div>
          </>
        )}

        <div className="modal-actions">
          <button className="secondary-button" onClick={onClose} type="button">Inchide</button>
        </div>
      </div>
    </div>
  );
}

function AuditDetailModal({ detail, loading, error, onClose }) {
  if (!detail) {
    return null;
  }

  const entry = detail.entry ?? detail;
  const metadata = entry.metadata && typeof entry.metadata === 'object' ? entry.metadata : {};
  const metadataEntries = Object.entries(metadata);

  return (
    <div className="modal-backdrop" role="presentation">
      <div className="modal-panel modal-panel-wide">
        <div className="panel-header">
          <div>
            <h2>{entry.action ?? 'Audit'}</h2>
            <p>{formatDateTime(entry.created_at)} · #{entry.id}</p>
          </div>
          <button className="icon-button" onClick={onClose} type="button" aria-label="Inchide">
            <X size={18} />
          </button>
        </div>

        {error && <div className="error-banner">{error}</div>}
        {loading && <LoadingState />}

        {!loading && (
          <>
            <div className="billing-summary-grid">
              <div className="billing-stat">
                <span>Actor</span>
                <strong>{entry.actor?.name ?? 'Sistem'}</strong>
              </div>
              <div className="billing-stat">
                <span>Email actor</span>
                <strong>{entry.actor?.email ?? '-'}</strong>
              </div>
              <div className="billing-stat">
                <span>Subiect</span>
                <strong>{auditSubjectLabel(entry)}</strong>
              </div>
              <div className="billing-stat">
                <span>Statie</span>
                <strong>{entry.station?.name ?? '-'}</strong>
              </div>
            </div>

            {entry.station && (
              <div className="detail-section">
                <h3>Statie</h3>
                <div className="meta-grid">
                  <span>Nume: <strong>{entry.station.name}</strong></span>
                  <span>Locatie: <strong>{entry.station.location ?? '-'}</strong></span>
                  <span>Status: <strong>{statusLabel(entry.station.status)}</strong></span>
                  <span>QR: <strong>{entry.station.qr_code ?? '-'}</strong></span>
                </div>
              </div>
            )}

            {entry.session && (
              <div className="detail-section">
                <h3>Sesiune legata</h3>
                <div className="meta-grid">
                  <span>ID: <strong>#{entry.session.id}</strong></span>
                  <span>Utilizator: <strong>{entry.session.user?.name ?? '-'}</strong></span>
                  <span>Email: <strong>{entry.session.user?.email ?? '-'}</strong></span>
                  <span>Statie: <strong>{entry.session.station?.name ?? '-'}</strong></span>
                  <span>Start: <strong>{formatDateTime(entry.session.start_time)}</strong></span>
                  <span>kWh: <strong>{formatKwh(entry.session.kwh_consumed)}</strong></span>
                  {entry.session.ocpp_stop_reason ? (
                    <span>Motiv OCPP: <strong>{entry.session.ocpp_stop_reason}</strong></span>
                  ) : null}
                </div>
              </div>
            )}

            <div className="detail-section">
              <h3>Metadata</h3>
              {metadataEntries.length === 0 ? (
                <p className="detail-empty">Fara metadata suplimentar.</p>
              ) : (
                <div className="audit-metadata-grid">
                  {metadataEntries.map(([key, value]) => (
                    <div className="audit-metadata-row" key={key}>
                      <span>{key}</span>
                      <strong>{typeof value === 'object' ? JSON.stringify(value) : String(value)}</strong>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </>
        )}

        <div className="modal-actions">
          <button className="secondary-button" onClick={onClose} type="button">Inchide</button>
        </div>
      </div>
    </div>
  );
}

const walletStatusFilters = [
  { id: '', label: 'Toate' },
  { id: 'paid', label: 'Platite' },
  { id: 'pending', label: 'In asteptare' }
];

const walletViewFilters = [
  { id: 'topups', label: 'Alimentari' },
  { id: 'refunds', label: 'Retururi' }
];

function WalletTopupsView({ rows, refunds, summary, loading, onRefund }) {
  const [query, setQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [viewMode, setViewMode] = useState('topups');
  const visibleRows = rows.filter((topup) => {
    if (statusFilter && topup.status !== statusFilter) {
      return false;
    }

    return matchesQuery(topup, query, [
      (item) => item.user?.name,
      (item) => item.user?.email,
      (item) => item.status,
      (item) => item.payment_provider,
      (item) => item.payment_session_id,
      (item) => String(item.id)
    ]);
  });

  const visibleRefunds = (refunds ?? []).filter((refund) => matchesQuery(refund, query, [
    (item) => item.user?.name,
    (item) => item.user?.email,
    (item) => item.payment_provider,
    (item) => item.stripe_refund_id,
    (item) => String(item.id),
    (item) => String(item.wallet_topup_id)
  ]));

  return (
    <div className="view-stack ops-page">
      <div className="panel ops-panel">
        <div className="panel-header ops-panel-header">
          <div>
            <h2>Alimentari</h2>
            <p>Plati card si retururi initiate din backoffice</p>
          </div>
          <span className="ops-header-icon"><Wallet size={20} /></span>
        </div>

        <div className="ops-kpi-bar">
          <div className="ops-kpi tone-success">
            <span>Platite</span>
            <strong>{formatNumber(summary?.count_paid)} <small>· {formatMoney(summary?.volume_paid)}</small></strong>
          </div>
          <div className="ops-kpi tone-warning">
            <span>In asteptare</span>
            <strong>{formatNumber(summary?.count_pending)} <small>· {formatMoney(summary?.volume_pending)}</small></strong>
          </div>
          <div className="ops-kpi tone-danger">
            <span>Retururi</span>
            <strong>{formatNumber(summary?.refunds_count)} <small>· {formatMoney(summary?.volume_refunded)}</small></strong>
          </div>
        </div>

        <div className="ops-control-bar">
          <Toolbar value={query} onChange={setQuery} />
          <div className="ops-filter-row">
            {walletViewFilters.map((filter) => (
              <button
                className={viewMode === filter.id ? 'filter-chip active-filter' : 'filter-chip'}
                key={filter.id}
                onClick={() => {
                  setViewMode(filter.id);
                  setStatusFilter('');
                }}
                type="button"
              >
                {filter.label}
              </button>
            ))}
            {viewMode === 'topups' ? walletStatusFilters.map((filter) => (
              <button
                className={statusFilter === filter.id ? 'filter-chip active-filter' : 'filter-chip'}
                key={`status-${filter.id || 'all'}`}
                onClick={() => setStatusFilter(filter.id)}
                type="button"
              >
                {filter.label}
              </button>
            )) : null}
            <span className="ops-result-count">
              {formatNumber(viewMode === 'topups' ? visibleRows.length : visibleRefunds.length)} afisate
            </span>
          </div>
        </div>

        {viewMode === 'topups' ? (
          loading ? (
            <LoadingState />
          ) : rows.length === 0 ? (
            <EmptyState title="Nicio alimentare" detail="Cand utilizatorii platesc cu cardul, tranzactiile apar aici." />
          ) : visibleRows.length === 0 ? (
            <EmptyState title="Niciun rezultat" detail="Schimba filtrul sau cautarea." />
          ) : (
            <div className="ops-list">
              <div className="ops-list-head ops-row ops-row-4" aria-hidden="true">
                <span>Utilizator</span>
                <span>Suma</span>
                <span>Status</span>
                <span>Data / Actiuni</span>
              </div>
              {visibleRows.map((topup) => {
                const refundableAmount = Number(
                  topup.effective_refundable_amount ?? topup.refundable_amount ?? 0
                );
                const canRefund = topup.status === 'paid' && refundableAmount > 0;

                return (
                  <div className="ops-row ops-row-4" key={topup.id}>
                    <div className="ops-cell">
                      <strong>{topup.user?.name ?? `User #${topup.user_id}`}</strong>
                      <p>{topup.user?.email ?? '-'}</p>
                      {topup.user?.wallet_balance != null ? (
                        <span>Sold {formatMoney(topup.user.wallet_balance)}</span>
                      ) : null}
                    </div>
                    <div className="ops-cell">
                      <strong>{formatMoney(topup.amount)}</strong>
                      {topup.amount_refunded > 0 ? (
                        <span>
                          Returnat {formatMoney(topup.amount_refunded)}
                          {refundableAmount > 0 ? ` · ramas ${formatMoney(refundableAmount)}` : ''}
                        </span>
                      ) : null}
                    </div>
                    <Badge variant={statusVariant(topup.status)}>{statusLabel(topup.status)}</Badge>
                    <div className="ops-actions">
                      <div className="ops-cell">
                        <strong>{formatDateTime(topup.paid_at ?? topup.created_at)}</strong>
                        <span>
                          {topup.payment_provider ?? '—'}
                          {topup.payment_session_id ? ` · ${topup.payment_session_id.slice(0, 18)}…` : ''}
                        </span>
                      </div>
                      {canRefund ? (
                        <button
                          className="secondary-button mini-button danger-text"
                          onClick={() => onRefund(topup)}
                          type="button"
                        >
                          Retur bani
                        </button>
                      ) : null}
                    </div>
                  </div>
                );
              })}
            </div>
          )
        ) : loading ? (
          <LoadingState />
        ) : (refunds ?? []).length === 0 ? (
          <EmptyState title="Niciun retur" detail="Retururile initiate din aceasta pagina apar aici." />
        ) : visibleRefunds.length === 0 ? (
          <EmptyState title="Niciun rezultat" detail="Schimba cautarea." />
        ) : (
          <div className="ops-list">
            <div className="ops-list-head ops-row ops-row-4" aria-hidden="true">
              <span>Utilizator</span>
              <span>Suma</span>
              <span>Status</span>
              <span>Data</span>
            </div>
            {visibleRefunds.map((refund) => (
              <div className="ops-row ops-row-4" key={refund.id}>
                <div className="ops-cell">
                  <strong>{refund.user?.name ?? `User #${refund.user_id}`}</strong>
                  <p>{refund.user?.email ?? '-'}</p>
                  {refund.topup ? (
                    <span>Din alimentare {formatMoney(refund.topup.amount)} · #{refund.topup.id}</span>
                  ) : null}
                </div>
                <strong>-{formatMoney(refund.amount)}</strong>
                <Badge variant={statusVariant(refund.status)}>{statusLabel(refund.status)}</Badge>
                <div className="ops-cell">
                  <strong>{formatDateTime(refund.created_at)}</strong>
                  <span>
                    {refund.payment_provider ?? '—'}
                    {refund.stripe_refund_id ? ` · ${refund.stripe_refund_id.slice(0, 18)}…` : ''}
                  </span>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

function ClientsView({ rows, loading, onCreate, onOpenDetail, customerTariff }) {
  const [query, setQuery] = useState('');
  const visibleRows = rows.filter((user) => matchesQuery(user, query, [
    (item) => item.name,
    (item) => item.email,
    (item) => item.currency
  ]));
  const totalWallet = rows.reduce((sum, user) => sum + Number(user.wallet_balance || 0), 0);
  const totalSessions = rows.reduce((sum, user) => sum + Number(user.sessions_count || 0), 0);

  if (loading) return <LoadingState />;

  return (
    <div className="view-stack ops-page">
      <div className="panel ops-panel">
        <div className="panel-header ops-panel-header">
          <div>
            <h2>Utilizatori</h2>
            <p>Conturi cu sold si plata cu cardul</p>
          </div>
          <div className="panel-header-actions">
            <button className="primary-button" onClick={onCreate} type="button">
              <Plus size={18} />
              Utilizator nou
            </button>
          </div>
        </div>

        <div className="ops-kpi-bar">
          <div className="ops-kpi">
            <span>Conturi</span>
            <strong>{formatNumber(rows.length)}</strong>
          </div>
          <div className="ops-kpi tone-success">
            <span>Sold total</span>
            <strong>{formatMoney(totalWallet)}</strong>
          </div>
          <div className="ops-kpi tone-live">
            <span>Sesiuni</span>
            <strong>{formatNumber(totalSessions)}</strong>
          </div>
        </div>

        <div className="ops-control-bar">
          <Toolbar value={query} onChange={setQuery} />
          <div className="ops-filter-row">
            <span className="ops-result-count">{formatNumber(visibleRows.length)} afisate</span>
          </div>
        </div>

        {customerTariff != null ? (
          <div className="tariff-summary-strip">
            <div className="tariff-summary-item">
              <span className="tariff-summary-icon"><Zap size={16} /></span>
              <div>
                <span>Tarif activ utilizatori</span>
                <strong>{formatTariffPrice(customerTariff)} lei/kWh</strong>
              </div>
            </div>
          </div>
        ) : null}

        {rows.length === 0 ? (
          <EmptyState title="Nu exista utilizatori" />
        ) : visibleRows.length === 0 ? (
          <EmptyState title="Niciun utilizator gasit" detail="Schimba termenul de cautare." />
        ) : (
          <div className="ops-list">
            <div className="ops-list-head ops-row-user" aria-hidden="true">
              <span />
              <span>Utilizator</span>
              <span>Sold</span>
              <span>Tarif</span>
              <span>Sesiuni</span>
              <span>Actiuni</span>
            </div>
            {visibleRows.map((user) => (
              <div className="ops-row ops-row-user" key={user.id}>
                <span className="avatar">{(user.name ?? '?').slice(0, 2).toUpperCase()}</span>
                <div className="ops-cell">
                  <strong>{user.name ?? '-'}</strong>
                  <p>{user.email ?? '-'}</p>
                </div>
                <Badge variant="success">{formatMoney(user.wallet_balance)}</Badge>
                <TariffBadge fallback="Tarif utilizatori" value={customerTariff} />
                <strong>{formatNumber(user.sessions_count)}</strong>
                <div className="ops-actions">
                  <button className="secondary-button mini-button" onClick={() => onOpenDetail(user)} type="button">
                    <Eye size={14} />
                    Detalii
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

function PersonalView({ rows, loading, onCreate, onOpenDetail, personalTariff }) {
  const [query, setQuery] = useState('');
  const visibleRows = rows.filter((user) => matchesQuery(user, query, [
    (item) => item.name,
    (item) => item.email,
    (item) => item.currency
  ]));
  const totalWallet = rows.reduce((sum, user) => sum + Number(user.wallet_balance || 0), 0);
  const totalSessions = rows.reduce((sum, user) => sum + Number(user.sessions_count || 0), 0);

  if (loading) return <LoadingState />;

  return (
    <div className="view-stack ops-page">
      <div className="panel ops-panel">
        <div className="panel-header ops-panel-header">
          <div>
            <h2>Personal</h2>
            <p>Conturi interne cu tarif dedicat</p>
          </div>
          <div className="panel-header-actions">
            <button className="primary-button" onClick={onCreate} type="button">
              <Plus size={18} />
              Personal nou
            </button>
          </div>
        </div>

        <div className="ops-kpi-bar">
          <div className="ops-kpi">
            <span>Conturi</span>
            <strong>{formatNumber(rows.length)}</strong>
          </div>
          <div className="ops-kpi tone-success">
            <span>Sold total</span>
            <strong>{formatMoney(totalWallet)}</strong>
          </div>
          <div className="ops-kpi tone-live">
            <span>Sesiuni</span>
            <strong>{formatNumber(totalSessions)}</strong>
          </div>
        </div>

        <div className="ops-control-bar">
          <Toolbar value={query} onChange={setQuery} />
          <div className="ops-filter-row">
            <span className="ops-result-count">{formatNumber(visibleRows.length)} afisate</span>
          </div>
        </div>

        {personalTariff != null ? (
          <div className="tariff-summary-strip">
            <div className="tariff-summary-item tariff-summary-item-personal">
              <span className="tariff-summary-icon"><Zap size={16} /></span>
              <div>
                <span>Tarif activ personal</span>
                <strong>{formatTariffPrice(personalTariff)} lei/kWh</strong>
              </div>
            </div>
          </div>
        ) : null}

        {rows.length === 0 ? (
          <EmptyState title="Nu exista personal" detail="Adauga conturi de tip Personal." />
        ) : visibleRows.length === 0 ? (
          <EmptyState title="Niciun cont personal gasit" detail="Schimba termenul de cautare." />
        ) : (
          <div className="ops-list">
            <div className="ops-list-head ops-row-user" aria-hidden="true">
              <span />
              <span>Persoana</span>
              <span>Sold</span>
              <span>Tarif</span>
              <span>Sesiuni</span>
              <span>Actiuni</span>
            </div>
            {visibleRows.map((user) => (
              <div className="ops-row ops-row-user" key={user.id}>
                <span className="avatar">{(user.name ?? '?').slice(0, 2).toUpperCase()}</span>
                <div className="ops-cell">
                  <strong>{user.name ?? '-'}</strong>
                  <p>{user.email ?? '-'}</p>
                </div>
                <Badge variant="success">{formatMoney(user.wallet_balance)}</Badge>
                <TariffBadge fallback="Tarif personal" value={personalTariff} />
                <strong>{formatNumber(user.sessions_count)}</strong>
                <div className="ops-actions">
                  <button className="secondary-button mini-button" onClick={() => onOpenDetail(user)} type="button">
                    <Eye size={14} />
                    Detalii
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

function invoiceTypeLabel(type) {
  if (type === 'monthly') return 'Lunara';
  if (type === 'session') return 'Sesiune';
  if (type === 'wallet_topup') return 'Alimentare wallet';
  return type || '-';
}

function UserDetailModal({
  detail,
  loading,
  error,
  onClose,
  onDownloadInvoice,
  onCreditWallet,
  creditSaving,
  creditError,
  onUpdateUser,
  updateSaving,
  updateError,
  onDeleteUser,
  deleteSaving,
  deleteError,
}) {
  const [creditAmount, setCreditAmount] = useState('500');
  const [editForm, setEditForm] = useState({
    first_name: '',
    last_name: '',
    name: '',
    email: '',
    account_type: 'customer',
    password: '',
  });

  const user = detail?.user ?? detail ?? null;

  useEffect(() => {
    if (!user?.id) {
      return;
    }

    setEditForm({
      first_name: user.first_name ?? '',
      last_name: user.last_name ?? '',
      name: user.name ?? '',
      email: user.email ?? '',
      account_type: user.account_type ?? 'customer',
      password: '',
    });
  }, [
    user?.id,
    user?.first_name,
    user?.last_name,
    user?.name,
    user?.email,
    user?.account_type,
  ]);

  if (!detail) {
    return null;
  }

  const billing = detail.billing ?? {};
  const invoices = detail.invoices ?? [];
  const sessions = detail.recent_sessions ?? [];
  const walletTopups = detail.wallet_topups ?? [];
  const walletRefunds = detail.wallet_refunds ?? [];
  const walletSummary = detail.wallet_summary ?? {};
  const isPrepayAccount = user.account_type === 'customer' || user.account_type === 'personal';
  const effectivePrice = user.effective_price_per_kwh;

  return (
    <div className="modal-backdrop" role="presentation">
      <div className="modal-panel modal-panel-wide">
        <div className="panel-header">
          <div>
            <h2>{user.name ?? 'Utilizator'}</h2>
            <p>
              {user.email ?? '-'}
              {user.account_type ? ` · ${accountTypeLabel(user.account_type)}` : ''}
            </p>
          </div>
          <button className="icon-button" onClick={onClose} type="button" aria-label="Inchide">
            <X size={18} />
          </button>
        </div>

        {error && <div className="error-banner">{error}</div>}
        {loading && <LoadingState />}

        {!loading && (
          <>
            <div className="detail-section">
              <h3>Editeaza contul</h3>
              <p className="detail-empty">Actualizeaza datele de autentificare si tipul contului.</p>
              {updateError ? <div className="error-banner">{updateError}</div> : null}
              <form
                onSubmit={(event) => {
                  event.preventDefault();
                  onUpdateUser?.(user, editForm);
                }}
              >
                <div className="settings-grid">
                  <label>
                    Prenume
                    <input
                      name="first_name"
                      onChange={(event) => setEditForm((current) => ({ ...current, first_name: event.target.value }))}
                      value={editForm.first_name}
                    />
                  </label>
                  <label>
                    Nume
                    <input
                      name="last_name"
                      onChange={(event) => setEditForm((current) => ({ ...current, last_name: event.target.value }))}
                      value={editForm.last_name}
                    />
                  </label>
                  <label className="full-field">
                    Nume afisat
                    <input
                      name="name"
                      onChange={(event) => setEditForm((current) => ({ ...current, name: event.target.value }))}
                      placeholder="Optional daca completezi prenume + nume"
                      value={editForm.name}
                    />
                  </label>
                  <label className="full-field">
                    Email
                    <input
                      name="email"
                      onChange={(event) => setEditForm((current) => ({ ...current, email: event.target.value }))}
                      required
                      type="email"
                      value={editForm.email}
                    />
                  </label>
                  <label>
                    Tip cont
                    <select
                      name="account_type"
                      onChange={(event) => setEditForm((current) => ({ ...current, account_type: event.target.value }))}
                      required
                      value={editForm.account_type}
                    >
                      <option value="customer">Utilizator</option>
                      <option value="personal">Personal</option>
                    </select>
                  </label>
                  <label>
                    Parola noua
                    <input
                      autoComplete="new-password"
                      name="password"
                      onChange={(event) => setEditForm((current) => ({ ...current, password: event.target.value }))}
                      placeholder="Lasa gol pentru a pastra parola"
                      type="password"
                      value={editForm.password}
                    />
                  </label>
                </div>
                <div className="modal-actions">
                  <button className="primary-button" disabled={updateSaving} type="submit">
                    {updateSaving ? 'Se salveaza...' : 'Salveaza modificarile'}
                  </button>
                </div>
              </form>
            </div>

            <div className="billing-summary-grid">
              {isPrepayAccount ? (
                <>
                  <div className="billing-stat">
                    <span>Sold wallet</span>
                    <strong>{formatMoney(user.wallet_balance)}</strong>
                  </div>
                  <div className="billing-stat">
                    <span>Total alimentat</span>
                    <strong>{formatMoney(walletSummary.topups_paid_total)}</strong>
                  </div>
                  <div className="billing-stat">
                    <span>Total returnat</span>
                    <strong>{formatMoney(walletSummary.refunds_total)}</strong>
                  </div>
                </>
              ) : (
                <>
                  <div className="billing-stat">
                    <span>Datorie curenta</span>
                    <strong className={billing.outstanding_balance > 0 ? 'debt-value' : ''}>
                      {formatMoney(billing.outstanding_balance)}
                    </strong>
                  </div>
                  <div className="billing-stat">
                    <span>Facturi neplatite</span>
                    <strong>{formatNumber(billing.unpaid_invoices_count)}</strong>
                  </div>
                  <div className="billing-stat">
                    <span>Total facturat</span>
                    <strong>{formatMoney(billing.total_billed)}</strong>
                  </div>
                </>
              )}
              <div className="billing-stat">
                <span>Energie totala</span>
                <strong>{formatNumber(billing.total_kwh)} kWh</strong>
              </div>
              <div className="billing-stat">
                <span>Sesiuni</span>
                <strong>{formatNumber(user.sessions_count)}</strong>
              </div>
              {isPrepayAccount && (
                <div className="billing-stat billing-stat-tariff">
                  <span>Tarif aplicat</span>
                  <strong>{effectivePrice != null ? `${formatTariffPrice(effectivePrice)} lei/kWh` : '-'}</strong>
                </div>
              )}
            </div>

            {isPrepayAccount ? (
              <>
                <div className="detail-section">
                  <h3>Alimentare manuala</h3>
                  <p className="detail-empty">Adauga sold in wallet fara Stripe (test / compensare).</p>
                  {creditError ? <div className="error-banner">{creditError}</div> : null}
                  <form
                    onSubmit={(event) => {
                      event.preventDefault();
                      onCreditWallet?.(user, creditAmount);
                    }}
                  >
                    <div className="settings-grid">
                      <label className="full-field">
                        Suma (MDL)
                        <input
                          inputMode="decimal"
                          min="10"
                          name="amount"
                          onChange={(event) => setCreditAmount(event.target.value)}
                          placeholder="500"
                          required
                          step="0.01"
                          type="number"
                          value={creditAmount}
                        />
                      </label>
                    </div>
                    <div className="modal-actions">
                      <button className="primary-button" disabled={creditSaving} type="submit">
                        {creditSaving ? 'Se alimenteaza...' : 'Alimenteaza contul'}
                      </button>
                    </div>
                  </form>
                </div>
                <div className="detail-section">
                  <h3>Alimentari wallet</h3>
                  {walletTopups.length === 0 ? (
                    <p className="detail-empty">Nicio alimentare inca.</p>
                  ) : (
                    <div className="detail-table">
                      {walletTopups.map((topup) => (
                        <div className="detail-row" key={topup.id}>
                          <div>
                            <strong>{formatMoney(topup.amount)}</strong>
                            <p>{formatDateTime(topup.paid_at ?? topup.created_at)}</p>
                            {topup.amount_refunded > 0 ? (
                              <p className="request-meta">Returnat {formatMoney(topup.amount_refunded)}</p>
                            ) : null}
                          </div>
                          <Badge variant={statusVariant(topup.status)}>{statusLabel(topup.status)}</Badge>
                          <span>{topup.payment_provider ?? '—'}</span>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
                <div className="detail-section">
                  <h3>Retururi bani</h3>
                  {walletRefunds.length === 0 ? (
                    <p className="detail-empty">Niciun retur initiat din backoffice.</p>
                  ) : (
                    <div className="detail-table">
                      {walletRefunds.map((refund) => (
                        <div className="detail-row" key={refund.id}>
                          <div>
                            <strong>-{formatMoney(refund.amount)}</strong>
                            <p>{formatDateTime(refund.created_at)}</p>
                          </div>
                          <Badge variant={statusVariant(refund.status)}>{statusLabel(refund.status)}</Badge>
                          <span>{refund.payment_provider ?? '—'}</span>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              </>
            ) : null}

            {invoices.length > 0 ? (
              <div className="detail-section">
                <h3>Facturi (arhiva)</h3>
                <div className="detail-table">
                  {invoices.map((invoice) => (
                    <div className="detail-row" key={invoice.id}>
                      <div>
                        <strong>{invoice.invoice_number ?? `#${invoice.id}`}</strong>
                        <p>
                          {invoice.month ?? '-'} · {invoiceTypeLabel(invoice.invoice_type)}
                        </p>
                      </div>
                      <span>{formatNumber(invoice.total_kwh)} kWh</span>
                      <Badge variant={statusVariant(invoice.status)}>{statusLabel(invoice.status)}</Badge>
                      <div className="row-actions invoice-actions">
                        <strong>{formatMoney(invoice.total_amount)}</strong>
                        <button
                          className="secondary-button mini-button"
                          onClick={() => onDownloadInvoice(invoice)}
                          type="button"
                        >
                          Descarca
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            ) : null}

            <div className="detail-section">
              <h3>Sesiuni recente</h3>
              {sessions.length === 0 ? (
                <p className="detail-empty">Nicio sesiune inca.</p>
              ) : (
                <div className="detail-table">
                  {sessions.map((session) => (
                    <div className="detail-row" key={session.id}>
                      <div>
                        <strong>{session.station?.name ?? `Statia #${session.station_id}`}</strong>
                        <p>
                          {session.start_time ? new Date(session.start_time).toLocaleString('ro-RO') : '-'}
                        </p>
                        {formatSessionStopInfo(session) ? (
                          <p className="request-meta">{formatSessionStopInfo(session)}</p>
                        ) : null}
                      </div>
                      <span className="live-kwh">
            {formatKwh(sessionKwhDelivered(session))} kWh
            {!session.end_time && sessionPowerKw(session) != null
              ? ` · ${formatKwh(sessionPowerKw(session), 2)} kW`
              : ''}
          </span>
                      <Badge variant={session.end_time ? 'success' : 'warning'}>
                        {session.end_time ? 'Inchisa' : 'Activa'}
                      </Badge>
                    </div>
                  ))}
                </div>
              )}
            </div>

            <div className="detail-section detail-section-danger">
              <h3>Stergere cont</h3>
              <p className="detail-empty">
                Sterge definitiv contul utilizatorului. Soldul trebuie sa fie zero, fara incarcare activa sau facturi neplatite.
              </p>
              {deleteError ? <div className="error-banner">{deleteError}</div> : null}
              <div className="modal-actions">
                <button
                  className="secondary-button danger-text"
                  disabled={deleteSaving}
                  onClick={() => onDeleteUser?.(user)}
                  type="button"
                >
                  {deleteSaving ? 'Se sterge...' : 'Sterge contul definitiv'}
                </button>
              </div>
            </div>
          </>
        )}

        <div className="modal-actions">
          <button className="secondary-button" onClick={onClose} type="button">Inchide</button>
        </div>
      </div>
    </div>
  );
}

function StationDetailModal({
  detail,
  loading,
  error,
  onClose,
  onReload,
  onRefreshStatus,
  onUnlockConnector,
  onStopActiveSession,
  onDiagnostics
}) {
  const [showTech, setShowTech] = useState(false);

  if (!detail) {
    return null;
  }

  const station = detail.station ?? {};
  const hardware = detail.hardware ?? {};
  const connectors = detail.connectors ?? [];
  const activeSessions = detail.active_sessions ?? [];
  const diagnosticsCommands = detail.diagnostics_commands ?? [];
  const reservationPolicy = detail.reservation_policy ?? {};
  const upcomingReservations = detail.upcoming_reservations ?? [];

  return (
    <div className="modal-backdrop" role="presentation">
      <div className="modal-panel modal-panel-wide station-detail-modal">
        <div className="panel-header">
          <div>
            <h2>{station.name ?? 'Statie'}</h2>
            <p className="station-detail-subtitle">
              {station.location ?? '-'}
              {station.ocpp_identity ? ` · ${station.ocpp_identity}` : ''}
            </p>
            <div className="station-detail-meta">
              <Badge variant={statusVariant(effectiveOcppConnectionStatus(station))}>
                {connectionLabel(effectiveOcppConnectionStatus(station))}
              </Badge>
              {hardware.model && <span>{hardware.model}</span>}
              {station.qr_code && <span>QR {station.qr_code}</span>}
            </div>
          </div>
          <div className="row-actions">
            <button className="secondary-button mini-button" onClick={onReload} type="button">
              <RefreshCw size={15} />
            </button>
            <button className="icon-button" onClick={onClose} type="button" aria-label="Inchide">
              <X size={18} />
            </button>
          </div>
        </div>

        {error && <div className="error-banner">{error}</div>}
        {loading && <LoadingState />}

        {!loading && (
          <>
            <div className="detail-section detail-section-tight">
              <h3>Conectori</h3>
              {connectors.length === 0 ? (
                <p className="detail-empty">Nicio telemetrie inca. Apasa Refresh status.</p>
              ) : (
                <div className="connector-grid connector-grid-compact">
                  {connectors.map((connector) => (
                    <article className="connector-card connector-card-compact" key={connector.id}>
                      <div className="connector-card-head">
                        <strong>Port {connector.label}</strong>
                        <Badge variant={statusVariant(connector.availability ?? connector.status)}>
                          {connector.status || availabilityLabel(connector.availability) || '—'}
                        </Badge>
                      </div>
                      <div className="connector-metrics connector-metrics-compact">
                        <span className="live-kwh">{formatKwh(connector.telemetry?.energy_kwh)} kWh</span>
                        {connector.telemetry?.power_kw != null && (
                          <span>{formatKwh(connector.telemetry.power_kw, 2)} kW</span>
                        )}
                      </div>
                      {(connector.local_id_tag || connector.has_active_session) && (
                        <p className="request-meta connector-card-foot">
                          {connector.local_id_tag ? `RFID ${connector.local_id_tag}` : ''}
                          {connector.has_active_session ? ' · incarcare activa' : ''}
                        </p>
                      )}
                    </article>
                  ))}
                </div>
              )}
            </div>

            <div className="detail-section detail-section-tight">
              <h3>Rezervari</h3>
              <div className="detail-metrics-grid">
                <DetailMetric
                  label="Status"
                  value={reservationPolicy.enabled ? 'Activate' : 'Dezactivate'}
                />
                <DetailMetric label="Taxa" value={`${formatMoney(reservationPolicy.fee ?? 0)} MDL`} />
                <DetailMetric label="No-show" value={`${formatMoney(reservationPolicy.no_show_fee ?? 0)} MDL`} />
                <DetailMetric label="Durata max" value={`${reservationPolicy.max_duration_minutes ?? 0} min`} />
              </div>
              {upcomingReservations.length === 0 ? (
                <p className="detail-empty">Nicio rezervare viitoare.</p>
              ) : (
                <div className="detail-table session-detail-table">
                  {upcomingReservations.map((reservation) => (
                    <div className="detail-table-row" key={reservation.id}>
                      <div>
                        <strong>Port {reservation.connector_id}</strong>
                        <p className="request-meta">
                          {formatDateTime(reservation.starts_at)} → {formatDateTime(reservation.ends_at)}
                        </p>
                      </div>
                      <Badge>{reservationStatusLabels[reservation.status] ?? reservation.status}</Badge>
                    </div>
                  ))}
                </div>
              )}
            </div>

            {activeSessions.length > 0 && (
              <div className="detail-section detail-section-tight">
                <h3>Sesiuni active</h3>
                <div className="detail-table session-detail-table">
                  {activeSessions.map((session) => (
                    <div className="detail-row session-detail-row" key={session.id}>
                      <div>
                        <strong>{session.user?.name ?? '-'}</strong>
                        <p>Port {session.ocpp_connector_id === 2 ? 'B' : session.ocpp_connector_id === 1 ? 'A' : `C${session.ocpp_connector_id ?? '?'}`}</p>
                      </div>
                      <span className="live-kwh">
                        {formatKwh(sessionKwhDelivered(session))} kWh
                        {sessionPowerKw(session) != null ? ` · ${formatKwh(sessionPowerKw(session), 2)} kW` : ''}
                      </span>
                    </div>
                  ))}
                </div>
              </div>
            )}

            <div className="station-detail-extras">
              <button
                className="secondary-button mini-button"
                onClick={() => setShowTech((current) => !current)}
                type="button"
              >
                {showTech ? 'Ascunde detalii tehnice' : 'Detalii tehnice'}
              </button>

              {showTech && (
                <div className="meta-grid meta-grid-compact">
                  {hardware.firmware && <span>Firmware: <strong>{hardware.firmware}</strong></span>}
                  {hardware.vendor && <span>Vendor: <strong>{hardware.vendor}</strong></span>}
                  {hardware.serial && <span>Serial: <strong>{hardware.serial}</strong></span>}
                  {station.ocpp_version && <span>OCPP: <strong>{station.ocpp_version}</strong></span>}
                  {(station.last_heartbeat_at || detail.live_status?.last_heartbeat_at) && (
                    <span>Heartbeat: <strong>{formatDateTime(detail.live_status?.last_heartbeat_at ?? station.last_heartbeat_at)}</strong></span>
                  )}
                  {station.ocpp_connection_url && (
                    <span className="meta-wide meta-with-action">
                      WS: <strong>{station.ocpp_connection_url}</strong>
                      <button
                        className="secondary-button mini-button"
                        onClick={() => navigator.clipboard?.writeText(station.ocpp_connection_url)}
                        type="button"
                      >
                        <Copy size={14} />
                      </button>
                    </span>
                  )}
                </div>
              )}

              {diagnosticsCommands.length > 0 && (
                <details className="detail-collapsible">
                  <summary>Diagnostics ({diagnosticsCommands.length})</summary>
                  <div className="detail-table diagnostics-table">
                    {diagnosticsCommands.slice(0, 5).map((command) => (
                      <div className="detail-row diagnostics-row" key={command.id}>
                        <Badge variant={statusVariant(command.upload_status ?? command.status)}>
                          {command.upload_status
                            ? diagnosticsUploadLabel(command.upload_status)
                            : ocppCommandLabel(command.status)}
                        </Badge>
                        <span>{diagnosticsResultSummary(command)}</span>
                      </div>
                    ))}
                  </div>
                </details>
              )}
            </div>
          </>
        )}

        <div className="modal-actions station-detail-actions">
          {effectiveOcppConnectionStatus(station) === 'connected' && (
            <>
              <button className="secondary-button" onClick={() => onRefreshStatus(station)} type="button">
                <RefreshCw size={16} />
                Refresh status
              </button>
              <button className="secondary-button" onClick={() => onUnlockConnector(station)} type="button">
                <Unlock size={16} />
                Unlock
              </button>
              {activeSessions.length > 0 && (
                <button className="secondary-button danger-icon" onClick={() => onStopActiveSession(station)} type="button">
                  <Square size={16} />
                  Stop sesiune
                </button>
              )}
              <button className="secondary-button" onClick={() => onDiagnostics(station)} type="button">
                <ClipboardList size={16} />
                Diagnostics
              </button>
            </>
          )}
          <button className="secondary-button" onClick={onClose} type="button">Inchide</button>
        </div>
      </div>
    </div>
  );
}

function SettingsView({ dashboard, compact = false, onSubmit }) {
  const currentUser = dashboard?.currentUser;
  const currentTariff = dashboard?.currentTariff;
  const ocpp = dashboard?.ocpp;
  const [pricePerKwh, setPricePerKwh] = useState('');
  const [personalPricePerKwh, setPersonalPricePerKwh] = useState('');
  const [firstName, setFirstName] = useState('');
  const [lastName, setLastName] = useState('');

  useEffect(() => {
    setPricePerKwh(formatTariffInput(currentTariff?.price_per_kwh));
    setPersonalPricePerKwh(formatTariffInput(currentTariff?.personal_price_per_kwh ?? currentTariff?.price_per_kwh));
    setFirstName(currentUser?.first_name ?? '');
    setLastName(currentUser?.last_name ?? '');
  }, [
    currentTariff?.id,
    currentTariff?.price_per_kwh,
    currentTariff?.personal_price_per_kwh,
    currentTariff?.created_at,
    currentUser?.first_name,
    currentUser?.last_name
  ]);

  const previewCustomerTariff = pricePerKwh !== '' ? pricePerKwh : currentTariff?.price_per_kwh;
  const previewPersonalTariff = personalPricePerKwh !== '' ? personalPricePerKwh : currentTariff?.personal_price_per_kwh;

  return (
    <div className="view-stack ops-page">
    <form className="panel settings-panel ops-panel" onSubmit={onSubmit}>
      <div className="panel-header settings-panel-header ops-panel-header">
        <div>
          <h2>Setari</h2>
          <p>Tarife, profil operator si gateway OCPP</p>
        </div>
        <span className="ops-header-icon"><Settings size={20} /></span>
      </div>

      <section className="settings-section">
        <div className="settings-section-head">
          <Zap size={18} />
          <div>
            <h3>Tarife energie</h3>
            <p>Pret per kWh pentru utilizatori si personal</p>
          </div>
        </div>

        <div className="tariff-showcase">
          <article className="tariff-card tariff-card-customer">
            <span className="tariff-card-label">Utilizatori</span>
            <strong className="tariff-card-value">
              {previewCustomerTariff != null && previewCustomerTariff !== ''
                ? formatTariffPrice(previewCustomerTariff)
                : '—'}
            </strong>
            <span className="tariff-card-unit">lei / kWh</span>
          </article>
          <article className="tariff-card tariff-card-personal">
            <span className="tariff-card-label">Personal</span>
            <strong className="tariff-card-value">
              {previewPersonalTariff != null && previewPersonalTariff !== ''
                ? formatTariffPrice(previewPersonalTariff)
                : '—'}
            </strong>
            <span className="tariff-card-unit">lei / kWh</span>
          </article>
        </div>

        <div className={compact ? 'settings-grid compact tariff-edit-grid' : 'settings-grid tariff-edit-grid'}>
          <label>
            Moneda
            <input className="input-readonly" readOnly value="MDL (Leu moldovenesc)" />
          </label>
          <label>
            Tarif kWh utilizatori
            <div className="input-affix">
              <input
                inputMode="decimal"
                name="price_per_kwh"
                onChange={(event) => setPricePerKwh(event.target.value)}
                placeholder="4.0"
                required
                step="0.01"
                type="number"
                value={pricePerKwh}
              />
              <span>lei/kWh</span>
            </div>
          </label>
          <label>
            Tarif kWh personal
            <div className="input-affix">
              <input
                inputMode="decimal"
                name="personal_price_per_kwh"
                onChange={(event) => setPersonalPricePerKwh(event.target.value)}
                placeholder="2.5"
                required
                step="0.01"
                type="number"
                value={personalPricePerKwh}
              />
              <span>lei/kWh</span>
            </div>
          </label>
        </div>
      </section>

      <section className="settings-section">
        <div className="settings-section-head">
          <Users size={18} />
          <div>
            <h3>Profil operator</h3>
            <p>Datele afisate in backoffice</p>
          </div>
        </div>
        <div className={compact ? 'settings-grid compact' : 'settings-grid'}>
          <label>
            Prenume
            <input
              name="first_name"
              onChange={(event) => setFirstName(event.target.value)}
              placeholder="Prenume"
              value={firstName}
            />
          </label>
          <label>
            Nume
            <input
              name="last_name"
              onChange={(event) => setLastName(event.target.value)}
              placeholder="Nume"
              value={lastName}
            />
          </label>
        </div>
      </section>

      {ocpp ? (
        <section className="settings-section settings-section-muted">
          <div className="settings-section-head">
            <RadioTower size={18} />
            <div>
              <h3>Gateway OCPP</h3>
              <p>Conexiune statii si telemetrie</p>
            </div>
          </div>
          <div className="ocpp-info-grid">
            <div className="billing-stat ocpp-stat">
              <span>Mod OCPP</span>
              <strong>{ocpp.mode ?? '-'}</strong>
            </div>
            <div className="billing-stat ocpp-stat">
              <span>URL public WS</span>
              <strong className="ocpp-url">{ocpp.publicUrl ?? '-'}</strong>
            </div>
            <div className="billing-stat ocpp-stat">
              <span>Heartbeat</span>
              <strong>{ocpp.heartbeatInterval ?? '-'}s</strong>
            </div>
          </div>
        </section>
      ) : null}

      <div className="settings-actions">
        <button className="primary-button settings-save" type="submit">
          <CheckCircle2 size={18} />
          Salveaza setarile
        </button>
      </div>
    </form>
    </div>
  );
}

function Toolbar({ value = '', onChange = () => {}, onRefresh }) {
  return (
    <div className="toolbar">
      <label className="search-box">
        <Search size={17} />
        <input onChange={(event) => onChange(event.target.value)} placeholder="Cauta in date reale" value={value} />
      </label>
      {onRefresh ? (
        <button className="secondary-button" onClick={onRefresh} type="button">
          <Activity size={17} />
          Reincarca
        </button>
      ) : null}
    </div>
  );
}

function ListPanel({
  title,
  subtitle,
  rows,
  render,
  loading,
  emptyTitle,
  searchValue = '',
  onSearchChange = () => {},
  onRefresh,
  filters,
  noResults = false,
  icon: Icon = FileText,
  headerActions = null,
  kpis = null,
  columns = null,
  rowClassName = 'ops-row ops-row-4',
  resultCount = null,
  emptyDetail = null
}) {
  if (loading) return <LoadingState />;

  return (
    <div className="view-stack ops-page">
      <div className="panel ops-panel">
        <div className="panel-header ops-panel-header">
          <div>
            <h2>{title}</h2>
            <p>{subtitle}</p>
          </div>
          <div className="panel-header-actions">
            {headerActions}
            <span className="ops-header-icon"><Icon size={20} /></span>
          </div>
        </div>

        {kpis?.length ? (
          <div className={`ops-kpi-bar ops-kpi-cols-${kpis.length}`}>
            {kpis.map((kpi) => (
              <div className={`ops-kpi${kpi.tone ? ` tone-${kpi.tone}` : ''}`} key={kpi.label}>
                <span>{kpi.label}</span>
                <strong>{kpi.value}</strong>
              </div>
            ))}
          </div>
        ) : null}

        <div className="ops-control-bar">
          <Toolbar onRefresh={onRefresh} value={searchValue} onChange={onSearchChange} />
          {filters ? <div className="ops-filter-row">{filters}</div> : null}
          {resultCount != null ? (
            <span className="ops-result-count">{formatNumber(resultCount)} afisate</span>
          ) : null}
        </div>

        {noResults ? (
          <EmptyState title="Niciun rezultat" detail="Schimba filtrul sau termenul de cautare." />
        ) : rows.length === 0 ? (
          <EmptyState title={emptyTitle} detail={emptyDetail} />
        ) : (
          <div className="ops-list">
            {columns?.length ? (
              <div className={`ops-list-head ${rowClassName}`} aria-hidden="true">
                {columns.map((column) => (
                  <span key={column}>{column}</span>
                ))}
              </div>
            ) : null}
            {rows.map((row) => (
              <div className={rowClassName} key={row.id}>
                {render(row)}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

function WalletRefundModal({ topup, error, saving, onClose, onSubmit }) {
  if (!topup) {
    return null;
  }

  const refundableAmount = Number(topup.refundable_amount ?? 0);
  const walletBalance = Number(topup.user?.wallet_balance ?? 0);
  const maxRefund = Number(
    topup.effective_refundable_amount
      ?? Math.min(refundableAmount, Math.max(0, walletBalance))
  );
  const userLabel = topup.user?.email ?? topup.user?.name ?? `user #${topup.user_id}`;
  const provider = String(topup.payment_provider || 'local');
  const isCardProvider = provider === 'stripe' || provider === 'maib';

  return (
    <div className="modal-backdrop" role="presentation">
      <form className="modal-panel" onSubmit={onSubmit}>
        <div className="panel-header">
          <div>
            <h2>Retur bani</h2>
            <p>{userLabel} · alimentare {formatMoney(topup.amount)}</p>
          </div>
          <button className="icon-button" onClick={onClose} type="button" aria-label="Inchide">
            <X size={18} />
          </button>
        </div>

        {error && <div className="error-banner">{error}</div>}

        <div className="billing-summary-grid">
          <div className="billing-stat">
            <span>Disponibil retur</span>
            <strong>{formatMoney(maxRefund)}</strong>
          </div>
          <div className="billing-stat">
            <span>Sold utilizator</span>
            <strong>{formatMoney(walletBalance)}</strong>
          </div>
          {topup.amount_refunded > 0 ? (
            <div className="billing-stat">
              <span>Deja returnat</span>
              <strong>{formatMoney(topup.amount_refunded)}</strong>
            </div>
          ) : null}
        </div>

        <div className="settings-grid">
          <label className="full-field">
            Suma de returnat (MDL)
            <input
              defaultValue={maxRefund > 0 ? maxRefund.toFixed(2) : ''}
              inputMode="decimal"
              max={maxRefund}
              min="0.01"
              name="amount"
              placeholder="0.00"
              required
              step="0.01"
              type="number"
            />
          </label>
          <div className="full-field" style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <button
              className="secondary-button"
              disabled={maxRefund <= 0}
              onClick={(event) => {
                event.preventDefault();
                const input = event.currentTarget.form?.elements?.namedItem('amount');
                if (input && 'value' in input) {
                  input.value = maxRefund.toFixed(2);
                }
              }}
              type="button"
            >
              Retur total ({formatMoney(maxRefund)})
            </button>
          </div>
          <p className="field-hint full-field">
            {isCardProvider
              ? `Returnezi pe card (provider: ${provider}). Poti returna partial sau total, maxim ${formatMoney(maxRefund)}.`
              : `Debitezi soldul wallet (fara retur pe card). Maxim ${formatMoney(maxRefund)}.`}
            {walletBalance < refundableAmount
              ? ' Soldul utilizatorului limiteaza suma (a cheltuit o parte din alimentare).'
              : ''}
          </p>
        </div>

        <div className="modal-actions">
          <button className="secondary-button" onClick={onClose} type="button">Renunta</button>
          <button className="primary-button danger-text" disabled={saving || maxRefund <= 0} type="submit">
            {saving ? 'Se proceseaza' : 'Confirma returul'}
          </button>
        </div>
      </form>
    </div>
  );
}

function ActionModal({ type, entity, error, saving, onClose, onSubmit }) {
  if (!type) {
    return null;
  }

  const isStation = type === 'station-create' || type === 'station-edit';
  const isEdit = type === 'station-edit';
  const isPersonalUser = type === 'user-personal';
  const title = isStation
    ? (isEdit ? 'Editeaza statia' : 'Statie noua')
    : isPersonalUser
      ? 'Personal nou'
      : 'Utilizator nou';

  return (
    <div className="modal-backdrop" role="presentation">
      <form className="modal-panel" onSubmit={onSubmit}>
        <div className="panel-header">
          <div>
            <h2>{title}</h2>
            <p>
              {isStation
                ? 'Adauga un punct de incarcare'
                : isPersonalUser
                  ? 'Cont de tip Personal'
                  : 'Cont de tip Utilizator'}
            </p>
          </div>
          <button className="icon-button" onClick={onClose} type="button" aria-label="Inchide">
            <X size={18} />
          </button>
        </div>

        {error && <div className="error-banner">{error}</div>}

        {isStation ? (
          <div className="settings-grid">
            <label>
              Nume statie
              <input defaultValue={entity?.name ?? ''} name="name" required />
            </label>
            <label>
              Locatie
              <input defaultValue={entity?.location ?? ''} name="location" required />
            </label>
            <label>
              Latitudine
              <input defaultValue={entity?.latitude ?? ''} name="latitude" inputMode="decimal" placeholder="47.010452" />
            </label>
            <label>
              Longitudine
              <input defaultValue={entity?.longitude ?? ''} name="longitude" inputMode="decimal" placeholder="28.863810" />
            </label>
            <label>
              Status
              <select name="status" defaultValue={entity?.status ?? 'available'} required>
                <option value="available">Disponibila</option>
                <option value="charging">In incarcare</option>
                <option value="offline">Offline</option>
              </select>
            </label>
            <label>
              Putere kW
              <input defaultValue={entity?.power_kw ?? ''} name="power_kw" inputMode="decimal" placeholder="22" />
            </label>
            <label>
              Conector
              <input defaultValue={entity?.connector_type ?? ''} name="connector_type" placeholder="Type 2 / CCS" />
            </label>
            <label>
              OCPP identity
              <input defaultValue={entity?.ocpp_identity ?? ''} name="ocpp_identity" placeholder="volta-station-01" />
            </label>
            <label>
              OCPP versiune
              <select name="ocpp_version" defaultValue={entity?.ocpp_version ?? '1.6J'}>
                <option value="1.6J">OCPP 1.6J</option>
                <option value="2.0.1">OCPP 2.0.1</option>
              </select>
            </label>
            <label className="full-field">
              QR code
              <input
                defaultValue={entity?.qr_code ?? ''}
                name="qr_code"
                placeholder="Serial hardware (ex: 419400481F59D7) sau station:volta-1"
              />
            </label>
            {entity?.ocpp_configuration?.chargePointSerialNumber && (
              <p className="field-hint">
                Serial OCPP detectat: {entity.ocpp_configuration.chargePointSerialNumber}. Seteaza acelasi serial in QR code daca statia fizica nu citeste codul generat de backoffice.
              </p>
            )}
            {entity?.ocpp_connection_url && (
              <label className="full-field">
                URL conectare statie
                <input readOnly value={entity.ocpp_connection_url} />
              </label>
            )}
            <label>
              Rezervari activate
              <select name="reservations_enabled" defaultValue={entity?.reservations_enabled ? '1' : '0'}>
                <option value="0">Nu</option>
                <option value="1">Da</option>
              </select>
            </label>
            <label>
              Rezervare obligatorie la start
              <select name="reservation_require_for_start" defaultValue={entity?.reservation_require_for_start ? '1' : '0'}>
                <option value="0">Nu</option>
                <option value="1">Da</option>
              </select>
            </label>
            <label>
              Taxa rezervare (MDL)
              <input defaultValue={entity?.reservation_fee ?? 0} name="reservation_fee" inputMode="decimal" />
            </label>
            <label>
              Taxa no-show (MDL)
              <input defaultValue={entity?.reservation_no_show_fee ?? 0} name="reservation_no_show_fee" inputMode="decimal" />
            </label>
            <label>
              Durata max (minute)
              <input defaultValue={entity?.reservation_max_duration_minutes ?? 30} name="reservation_max_duration_minutes" inputMode="numeric" />
            </label>
            <label>
              Avans maxim (zile)
              <input defaultValue={entity?.reservation_advance_days ?? 14} name="reservation_advance_days" inputMode="numeric" />
            </label>
            <label>
              Grace period (minute)
              <input defaultValue={entity?.reservation_grace_minutes ?? 20} name="reservation_grace_minutes" inputMode="numeric" />
            </label>
          </div>
        ) : (
          <div className="settings-grid">
            <label>
              Prenume
              <input name="first_name" />
            </label>
            <label>
              Nume
              <input name="last_name" />
            </label>
            <label className="full-field">
              Nume afisat
              <input name="name" />
            </label>
            <label>
              Email
              <input name="email" type="email" required />
            </label>
            <label className="full-field">
              Parola
              <input name="password" type="password" required placeholder="Minim 6 caractere" />
            </label>
            <input
              name="account_type"
              type="hidden"
              value={isPersonalUser ? 'personal' : 'customer'}
            />
          </div>
        )}

        <div className="modal-actions">
          <button className="secondary-button" onClick={onClose} type="button">Renunta</button>
          <button className="primary-button" disabled={saving} type="submit">
            {saving ? 'Se salveaza' : 'Salveaza'}
          </button>
        </div>
      </form>
    </div>
  );
}

function ActiveView({ activeSection, data, loading, actions, onRefresh }) {
  const activeSessions = data.sessions.filter((session) => !session.end_time).slice(0, 8);
  const customerTariff = data.dashboard?.currentTariff?.price_per_kwh ?? null;
  const personalTariff = data.dashboard?.currentTariff?.personal_price_per_kwh ?? customerTariff;
  const views = {
    dashboard: <DashboardView activeSessions={activeSessions} dashboard={data.dashboard} loading={loading} />,
    stations: (
      <StationsView
        rows={data.stations}
        loading={loading}
        onCreate={actions.openStationForm}
        onEdit={actions.editStation}
        onDelete={actions.deleteStation}
        onDownloadQr={actions.downloadQr}
        onPreviewQr={actions.previewQr}
        onDiagnostics={actions.requestDiagnostics}
        onRefreshStatus={actions.refreshStationStatus}
        onUnlockConnector={actions.unlockStationConnector}
        onStopActiveSession={actions.stopActiveStationSession}
        onOpenDetail={actions.openStationDetail}
      />
    ),
    sessions: (
      <SessionsView
        rows={data.sessions}
        loading={loading}
        onStop={actions.stopSession}
        onDelete={actions.deleteSession}
        onDebug={actions.openSessionOcppDebug}
        onDownloadInvoice={actions.downloadInvoice}
        onRefresh={onRefresh}
      />
    ),
    reservations: <ReservationsView rows={data.reservations} loading={loading} />,
    clients: (
      <ClientsView
        rows={data.clients}
        loading={loading}
        onCreate={actions.openCustomerForm}
        onOpenDetail={actions.openUserDetail}
        customerTariff={customerTariff}
      />
    ),
    wallet: (
      <WalletTopupsView
        loading={loading}
        onRefund={actions.openWalletRefund}
        refunds={data.walletRefunds}
        rows={data.walletTopups}
        summary={data.walletSummary}
      />
    ),
    personal: (
      <PersonalView
        rows={data.personal}
        loading={loading}
        onCreate={actions.openPersonalForm}
        onOpenDetail={actions.openUserDetail}
        personalTariff={personalTariff}
      />
    ),
    invoices: <InvoicesView rows={data.invoices} loading={loading} onDownload={actions.downloadInvoice} onSend={actions.sendInvoice} onDelete={actions.deleteInvoice} />,
    audit: <AuditView rows={data.audit} loading={loading} onOpenDetail={actions.openAuditDetail} />,
    settings: (
      <div className="view-stack settings-view-stack">
        <SettingsView dashboard={data.dashboard} onSubmit={actions.saveSettings} />
      </div>
    )
  };

  return views[activeSection] ?? views.dashboard;
}

export default function App() {
  const [activeSection, setActiveSection] = useState('dashboard');
  const { data, loading, error, authRequired, reload } = useBackofficeData();
  const [modalType, setModalType] = useState('');
  const [modalEntity, setModalEntity] = useState(null);
  const [walletRefundTopup, setWalletRefundTopup] = useState(null);
  const [actionError, setActionError] = useState('');
  const [actionMessage, setActionMessage] = useState('');
  const [saving, setSaving] = useState(false);
  const [loginError, setLoginError] = useState('');
  const [loginLoading, setLoginLoading] = useState(false);
  const [userDetail, setUserDetail] = useState(null);
  const [userDetailLoading, setUserDetailLoading] = useState(false);
  const [userDetailError, setUserDetailError] = useState('');
  const [userCreditSaving, setUserCreditSaving] = useState(false);
  const [userCreditError, setUserCreditError] = useState('');
  const [userUpdateSaving, setUserUpdateSaving] = useState(false);
  const [userUpdateError, setUserUpdateError] = useState('');
  const [userDeleteSaving, setUserDeleteSaving] = useState(false);
  const [userDeleteError, setUserDeleteError] = useState('');
  const [stationDetail, setStationDetail] = useState(null);
  const [stationDetailLoading, setStationDetailLoading] = useState(false);
  const [stationDetailError, setStationDetailError] = useState('');
  const [stationDetailId, setStationDetailId] = useState(null);
  const [auditDetail, setAuditDetail] = useState(null);
  const [auditDetailLoading, setAuditDetailLoading] = useState(false);
  const [auditDetailError, setAuditDetailError] = useState('');
  const [sessionOcppDebug, setSessionOcppDebug] = useState(null);
  const [sessionOcppDebugLoading, setSessionOcppDebugLoading] = useState(false);
  const [sessionOcppDebugError, setSessionOcppDebugError] = useState('');
  const [sessionOcppDebugId, setSessionOcppDebugId] = useState(null);
  const activeTitle = useMemo(
    () => sections.find((section) => section.id === activeSection)?.label ?? 'Dashboard',
    [activeSection]
  );
  const currentUser = data.dashboard?.currentUser;
  const operatorName = currentUser?.name || currentUser?.email || 'Admin';
  const dashboardStats = data.dashboard?.stats;

  useEffect(() => {
    if (authRequired) {
      return undefined;
    }

    const timer = window.setInterval(() => {
      reload(true);
    }, 12000);

    return () => window.clearInterval(timer);
  }, [authRequired, reload]);

  async function loadSessionOcppDebug(sessionId, silent = false) {
    if (!silent) {
      setSessionOcppDebugLoading(true);
    }
    setSessionOcppDebugError('');

    try {
      const payload = await fetchJson(`/backoffice/sessions/${sessionId}/ocpp-debug`);
      setSessionOcppDebug(payload.data);
    } catch (error) {
      setSessionOcppDebugError(error.message || 'Nu am putut incarca debug-ul OCPP.');
    } finally {
      setSessionOcppDebugLoading(false);
    }
  }

  async function loadStationDetail(stationId, silent = false) {
    if (!silent) {
      setStationDetailLoading(true);
    }
    setStationDetailError('');

    try {
      const payload = await fetchJson(`/backoffice/stations/${stationId}`);
      setStationDetail(payload.data);
    } catch (error) {
      setStationDetailError(error.message || 'Nu am putut incarca detaliile statiei.');
    } finally {
      setStationDetailLoading(false);
    }
  }

  useEffect(() => {
    if (!stationDetailId) {
      return undefined;
    }

    const timer = window.setInterval(() => {
      loadStationDetail(stationDetailId, true);
    }, 8000);

    return () => window.clearInterval(timer);
  }, [stationDetailId]);

  async function runAction(action, successMessage) {
    setSaving(true);
    setActionError('');
    setActionMessage('');

    try {
      const payload = await action();
      setActionMessage(payload?.message || successMessage);
      setModalType('');
      setModalEntity(null);
      setWalletRefundTopup(null);
      await reload();
    } catch (error) {
      setActionError(error.message || 'Actiunea nu a reusit.');
    } finally {
      setSaving(false);
    }
  }

  function formDataToObject(form) {
    return Object.fromEntries(new FormData(form).entries());
  }

  async function handleLogin(event) {
    event.preventDefault();
    setLoginLoading(true);
    setLoginError('');

    try {
      await mutateJson('/backoffice/login', formDataToObject(event.currentTarget));
      await reload();
    } catch (error) {
      setLoginError(error.message || 'Login esuat.');
    } finally {
      setLoginLoading(false);
    }
  }

  async function handleModalSubmit(event) {
    event.preventDefault();
    const values = formDataToObject(event.currentTarget);
    const url = modalType === 'station-create'
      ? '/backoffice/stations'
      : modalType === 'station-edit'
        ? `/backoffice/stations/${modalEntity.id}/update`
        : modalType === 'user-personal' || modalType === 'user-customer'
          ? '/backoffice/users'
          : '/backoffice/users';

    await runAction(() => mutateJson(url, values), 'Salvat.');
  }

  async function deleteStation(station) {
    if (!window.confirm(`Stergi statia "${station.name}"?`)) {
      return;
    }

    await runAction(
      () => mutateJson(`/backoffice/stations/${station.id}/delete`),
      'Statia a fost stearsa.'
    );
  }

  async function stopSession(session) {
    await runAction(
      () => mutateJson(`/backoffice/sessions/${session.id}/stop`),
      'Sesiunea a fost oprita.'
    );
  }

  async function deleteSession(session) {
    const label = session.station?.name ?? `sesiunea #${session.id}`;
    if (!window.confirm(`Stergi ${label}? Facturile legate de sesiune vor fi sterse.`)) {
      return;
    }

    await runAction(
      () => mutateJson(`/backoffice/sessions/${session.id}/delete`),
      'Sesiunea a fost stearsa.'
    );
  }

  function openWalletRefund(topup) {
    setActionError('');
    setActionMessage('');
    setWalletRefundTopup(topup);
  }

  async function creditUserWallet(user, amount) {
    const parsedAmount = Number(String(amount ?? '').replace(',', '.'));
    if (!Number.isFinite(parsedAmount) || parsedAmount < 10) {
      setUserCreditError('Suma minima este 10 MDL.');
      return;
    }

    setUserCreditSaving(true);
    setUserCreditError('');
    setActionError('');
    setActionMessage('');

    try {
      const payload = await mutateJson(`/backoffice/users/${user.id}/wallet-credit`, {
        amount: parsedAmount,
      });
      setActionMessage(payload?.message || 'Cont alimentat.');
      const detailPayload = await fetchJson(`/backoffice/users/${user.id}`);
      setUserDetail(detailPayload.data);
      await reload(true);
    } catch (error) {
      setUserCreditError(error.message || 'Alimentarea nu a reusit.');
    } finally {
      setUserCreditSaving(false);
    }
  }

  async function updateUserAccount(user, values) {
    setUserUpdateSaving(true);
    setUserUpdateError('');
    setActionError('');
    setActionMessage('');

    const payload = {
      first_name: values.first_name?.trim() ?? '',
      last_name: values.last_name?.trim() ?? '',
      name: values.name?.trim() ?? '',
      email: values.email?.trim() ?? '',
      account_type: values.account_type,
    };

    if (values.password?.trim()) {
      payload.password = values.password;
    }

    try {
      const response = await mutateJson(`/backoffice/users/${user.id}/update`, payload);
      setActionMessage(response?.message || 'Utilizator actualizat.');
      const detailPayload = await fetchJson(`/backoffice/users/${user.id}`);
      setUserDetail(detailPayload.data);
      await reload(true);
    } catch (error) {
      setUserUpdateError(error.message || 'Actualizarea nu a reusit.');
    } finally {
      setUserUpdateSaving(false);
    }
  }

  async function deleteUserAccount(user) {
    const label = user.email ?? user.name ?? `utilizatorul #${user.id}`;
    if (!window.confirm(`Stergi definitiv ${label}? Actiunea este permanenta.`)) {
      return;
    }

    setUserDeleteSaving(true);
    setUserDeleteError('');
    setActionError('');
    setActionMessage('');

    try {
      const response = await mutateJson(`/backoffice/users/${user.id}/delete`);
      setActionMessage(response?.message || 'Cont sters.');
      setUserDetail(null);
      await reload(true);
    } catch (error) {
      setUserDeleteError(error.message || 'Stergerea contului nu a reusit.');
    } finally {
      setUserDeleteSaving(false);
    }
  }

  async function handleWalletRefundSubmit(event) {
    event.preventDefault();

    if (!walletRefundTopup) {
      return;
    }

    const values = formDataToObject(event.currentTarget);
    const amount = Number(String(values.amount ?? '').replace(',', '.'));
    const refundableAmount = Number(
      walletRefundTopup.effective_refundable_amount
        ?? walletRefundTopup.refundable_amount
        ?? 0
    );

    if (!Number.isFinite(amount) || amount <= 0) {
      setActionError('Introdu o suma valida pentru retur.');
      return;
    }

    if (amount > refundableAmount) {
      setActionError(`Maxim ${formatMoney(refundableAmount)} pot fi returnati din aceasta alimentare.`);
      return;
    }

    await runAction(
      () => mutateJson(`/backoffice/wallet-topups/${walletRefundTopup.id}/refund`, {
        amount: Math.round(amount * 100) / 100
      }),
      'Returul a fost initiat.'
    );
  }

  async function deleteInvoice(invoice) {
    const label = invoice.invoice_number ?? `#${invoice.id}`;
    if (!window.confirm(`Stergi factura ${label}?`)) {
      return;
    }

    await runAction(
      () => mutateJson(`/backoffice/invoices/${invoice.id}/delete`),
      'Factura a fost stearsa.'
    );
  }

  async function requestStationDiagnostics(station) {
    if (!window.confirm(`Trimit GetDiagnostics catre ${station.name}?`)) {
      return;
    }

    setSaving(true);
    setActionError('');
    setActionMessage('');

    try {
      const payload = await mutateJson(`/backoffice/stations/${station.id}/diagnostics`);
      setActionMessage(payload?.message || 'GetDiagnostics a fost trimis catre statie.');
      await reload(true);
      if (stationDetailId === station.id) {
        await loadStationDetail(station.id, true);
      }
    } catch (error) {
      setActionError(error.message || 'GetDiagnostics nu a putut fi trimis.');
    } finally {
      setSaving(false);
    }
  }

  async function refreshStationStatus(station) {
    await runAction(
      () => mutateJson(`/backoffice/stations/${station.id}/refresh-status`),
      'Status OCPP actualizat.'
    );
  }

  async function unlockStationConnector(station) {
    const connectorId = station.live_status?.connected_connector_id
      ?? station.live_status?.connector_id
      ?? 2;
    const custom = window.prompt(
      `UnlockConnector pentru ${station.name}. Conector (1-9):`,
      String(connectorId)
    );

    if (custom === null) {
      return;
    }

    const parsed = Number(custom);
    if (!Number.isFinite(parsed) || parsed < 1) {
      setActionError('Conector invalid.');
      return;
    }

    await runAction(
      () => mutateJson(`/backoffice/stations/${station.id}/unlock-connector`, { connector_id: parsed }),
      'UnlockConnector trimis catre statie.'
    );
  }

  async function stopActiveStationSession(station) {
    if (!window.confirm(`Opresti sesiunea activa pe ${station.name}?`)) {
      return;
    }

    await runAction(
      () => mutateJson(`/backoffice/stations/${station.id}/stop-active-session`),
      'Comanda de oprire trimisa.'
    );
  }

  function openStationQr(station, preview = false) {
    const path = preview ? 'qr-preview' : 'qr';
    window.open(`/backoffice/stations/${station.id}/${path}`, '_blank', 'noopener,noreferrer');
  }

  function downloadInvoice(invoice) {
    window.open(`/backoffice/invoices/${invoice.id}/download`, '_blank', 'noopener,noreferrer');
  }

  async function sendInvoice(invoice) {
    await runAction(
      () => mutateJson(`/backoffice/invoices/${invoice.id}/send`),
      'Factura a fost trimisa pe email.'
    );
  }

  async function saveSettings(event) {
    event.preventDefault();
    const raw = formDataToObject(event.currentTarget);
    const pricePerKwh = parseTariffInput(raw.price_per_kwh);
    const personalPricePerKwh = parseTariffInput(raw.personal_price_per_kwh);

    if (pricePerKwh === null || personalPricePerKwh === null) {
      setActionError('Introdu tarife valide (ex. 4.0 sau 0.35).');
      return;
    }

    await runAction(
      () => mutateJson('/backoffice/settings', {
        ...raw,
        price_per_kwh: pricePerKwh,
        personal_price_per_kwh: personalPricePerKwh
      }),
      'Setarile au fost salvate.'
    );
  }

  async function logout() {
    setActionError('');
    setActionMessage('');

    try {
      await mutateJson('/backoffice/logout');
      csrfToken = '';
      await reload();
    } catch (error) {
      setActionError(error.message || 'Logout esuat.');
    }
  }

  const actions = {
    openStationForm: () => {
      setActionError('');
      setActionMessage('');
      setModalEntity(null);
      setModalType('station-create');
    },
    editStation: (station) => {
      setActionError('');
      setActionMessage('');
      setModalEntity(station);
      setModalType('station-edit');
    },
    openCustomerForm: () => {
      setActionError('');
      setActionMessage('');
      setModalEntity(null);
      setModalType('user-customer');
    },
    openPersonalForm: () => {
      setActionError('');
      setActionMessage('');
      setModalEntity(null);
      setModalType('user-personal');
    },
    openStationDetail: async (station) => {
      setStationDetailId(station.id);
      setStationDetail({ station });
      await loadStationDetail(station.id);
    },
    openAuditDetail: async (entry) => {
      setAuditDetail({ entry });
      setAuditDetailLoading(true);
      setAuditDetailError('');

      try {
        const payload = await fetchJson(`/backoffice/audit-logs/${entry.id}`);
        setAuditDetail({ entry: payload.data });
      } catch (error) {
        setAuditDetailError(error.message || 'Nu am putut incarca detaliile audit.');
      } finally {
        setAuditDetailLoading(false);
      }
    },
    openSessionOcppDebug: async (session) => {
      setSessionOcppDebugId(session.id);
      setSessionOcppDebug({ session });
      await loadSessionOcppDebug(session.id);
    },
    openUserDetail: async (user) => {
      setUserDetail({ user });
      setUserDetailLoading(true);
      setUserDetailError('');

      try {
        const payload = await fetchJson(`/backoffice/users/${user.id}`);
        setUserDetail(payload.data);
      } catch (error) {
        setUserDetailError(error.message || 'Nu am putut incarca detaliile utilizatorului.');
      } finally {
        setUserDetailLoading(false);
      }
    },
    deleteStation,
    stopSession,
    deleteSession,
    downloadQr: (station) => openStationQr(station),
    previewQr: (station) => openStationQr(station, true),
    requestDiagnostics: requestStationDiagnostics,
    refreshStationStatus,
    unlockStationConnector,
    stopActiveStationSession,
    downloadInvoice,
    sendInvoice,
    deleteInvoice,
    openWalletRefund,
    creditUserWallet,
    updateUserAccount,
    saveSettings
  };

  if (authRequired) {
    return <LoginView error={loginError} loading={loginLoading} onSubmit={handleLogin} />;
  }

  return (
    <main className="admin-shell">
      <aside className="sidebar">
        <BrandBlock />
        <nav>
          {sections.map((section) => (
            <SectionButton
              active={activeSection === section.id}
              key={section.id}
              onClick={setActiveSection}
              section={section}
            />
          ))}
        </nav>
        <button className="logout-button" onClick={logout} type="button">
          <LogOut size={17} />
          Logout
        </button>
      </aside>

      <section className="workspace">
        <header className="topbar">
          <div className="topbar-title">
            <p className="eyebrow"><Clock3 size={15} /> {new Date().toLocaleDateString('ro-RO')}</p>
            <h1>{activeTitle}</h1>
          </div>
          <div className="topbar-actions">
            <div className="quick-metrics">
              <TopMetric label="Active" value={formatNumber(dashboardStats?.activeSessions)} icon={Activity} />
              <TopMetric label="Neplatite" value={formatNumber(dashboardStats?.unpaidInvoices)} icon={CircleDollarSign} />
              <TopMetric label="Online" value={formatNumber(dashboardStats?.availableStations)} icon={RadioTower} />
            </div>
            <span className="operator" title={operatorName}>{initialsFrom(operatorName)}</span>
          </div>
        </header>
        {error && (
          <div className="flash-banner flash-banner-error" role="alert">
            <Bell size={18} />
            <span>{error}</span>
          </div>
        )}
        {actionMessage && (
          <div className="flash-banner flash-banner-success" role="status">
            <CheckCircle2 size={18} />
            <span>{actionMessage}</span>
          </div>
        )}
        {actionError && !modalType && !walletRefundTopup && (
          <div className="flash-banner flash-banner-error" role="alert">
            <Bell size={18} />
            <span>{actionError}</span>
          </div>
        )}
        <ActiveView
          activeSection={activeSection}
          actions={actions}
          data={data}
          loading={loading}
          onRefresh={() => reload(true)}
        />
      </section>
      <ActionModal
        error={actionError}
        onClose={() => {
          setModalType('');
          setModalEntity(null);
          setActionError('');
        }}
        entity={modalEntity}
        onSubmit={handleModalSubmit}
        saving={saving}
        type={modalType}
      />
      <WalletRefundModal
        error={actionError}
        onClose={() => {
          setWalletRefundTopup(null);
          setActionError('');
        }}
        onSubmit={handleWalletRefundSubmit}
        saving={saving}
        topup={walletRefundTopup}
      />
      <UserDetailModal
        creditError={userCreditError}
        creditSaving={userCreditSaving}
        detail={userDetail}
        error={userDetailError}
        loading={userDetailLoading}
        onClose={() => {
          setUserDetail(null);
          setUserDetailError('');
          setUserCreditError('');
          setUserDeleteError('');
        }}
        onCreditWallet={creditUserWallet}
        onDeleteUser={deleteUserAccount}
        deleteError={userDeleteError}
        deleteSaving={userDeleteSaving}
        onDownloadInvoice={downloadInvoice}
        onUpdateUser={updateUserAccount}
        updateError={userUpdateError}
        updateSaving={userUpdateSaving}
      />
      <AuditDetailModal
        detail={auditDetail}
        error={auditDetailError}
        loading={auditDetailLoading}
        onClose={() => {
          setAuditDetail(null);
          setAuditDetailError('');
        }}
      />
      <SessionOcppDebugModal
        detail={sessionOcppDebug}
        error={sessionOcppDebugError}
        loading={sessionOcppDebugLoading}
        onClose={() => {
          setSessionOcppDebug(null);
          setSessionOcppDebugId(null);
          setSessionOcppDebugError('');
        }}
        onReload={() => sessionOcppDebugId && loadSessionOcppDebug(sessionOcppDebugId, true)}
      />
      <StationDetailModal
        detail={stationDetail}
        error={stationDetailError}
        loading={stationDetailLoading}
        onClose={() => {
          setStationDetail(null);
          setStationDetailId(null);
          setStationDetailError('');
        }}
        onDiagnostics={requestStationDiagnostics}
        onRefreshStatus={async (station) => {
          await refreshStationStatus(station);
          if (stationDetailId) {
            await loadStationDetail(stationDetailId, true);
          }
        }}
        onReload={() => stationDetailId && loadStationDetail(stationDetailId, true)}
        onStopActiveSession={async (station) => {
          await stopActiveStationSession(station);
          if (stationDetailId) {
            await loadStationDetail(stationDetailId, true);
          }
        }}
        onUnlockConnector={async (station) => {
          await unlockStationConnector(station);
          if (stationDetailId) {
            await loadStationDetail(stationDetailId, true);
          }
        }}
      />
    </main>
  );
}
