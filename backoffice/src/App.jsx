import { useEffect, useMemo, useState } from 'react';
import {
  Activity,
  BatteryCharging,
  Bell,
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
  { id: 'clients', label: 'Clienti', icon: Users },
  { id: 'wallet', label: 'Alimentari', icon: Wallet },
  { id: 'personal', label: 'Personal', icon: FileText },
  { id: 'requests', label: 'Cereri', icon: ClipboardList },
  { id: 'invoices', label: 'Facturi', icon: Receipt },
  { id: 'audit', label: 'Audit', icon: ShieldCheck },
  { id: 'settings', label: 'Setari', icon: Settings }
];

const endpoints = {
  dashboard: '/backoffice/dashboard',
  stations: '/backoffice/stations',
  sessions: '/backoffice/sessions',
  clients: '/backoffice/users?account_type=customer',
  wallet: '/backoffice/wallet-topups',
  personal: '/backoffice/users?account_type=personal',
  requests: '/backoffice/registration-requests',
  invoices: '/backoffice/invoices',
  audit: '/backoffice/audit-logs'
};

const emptyData = {
  dashboard: null,
  stations: [],
  sessions: [],
  clients: [],
  walletTopups: [],
  walletSummary: null,
  personal: [],
  requests: [],
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

  async function load(silent = false) {
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
  }

  useEffect(() => {
    load();
  }, []);

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

function formatSecondsAgo(seconds) {
  if (seconds === null || seconds === undefined || !Number.isFinite(Number(seconds))) {
    return '-';
  }

  const value = Number(seconds);
  if (value < 60) {
    return `${value}s`;
  }

  return `${Math.floor(value / 60)}m ${value % 60}s`;
}

function sessionKwhDelivered(session) {
  const delivered = session?.kwh_delivered ?? session?.telemetry?.kwh_consumed ?? session?.kwh_consumed;
  return delivered ?? 0;
}

function sessionPowerKw(session) {
  return session?.power_kw_live ?? session?.telemetry?.power_kw ?? null;
}

function effectiveStationStatus(station) {
  return station.display_status ?? station.live_status?.availability ?? station.status;
}

function effectiveOcppConnectionStatus(station) {
  return station.live_status?.connection_status ?? station.ocpp_connection_status;
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

function BrandBlock({ compact = false }) {
  return (
    <div className={`brand ${compact ? 'brand-compact' : ''}`}>
      <span className="brand-logo">
        <img alt="Volta" src={voltaLogo} />
      </span>
      <div>
        <strong>Volta EV</strong>
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
        <h1>Login admin</h1>
        <p className="login-copy">Autentifica-te cu un cont existent din backend pentru a administra reteaua Volta.</p>
        {error && <div className="error-banner">{error}</div>}
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
    <div className="view-stack dashboard-v2">
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
              <DetailMetric label="Utilizatori" value={formatNumber(stats?.users)} helper={`${formatNumber(analytics?.users?.customer)} prepay`} />
              <DetailMetric label="Cereri" value={formatNumber(stats?.pendingRequests)} helper="in asteptare" />
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
    <div className="view-stack stations-page">
      <div className="panel stations-panel">
        <div className="panel-header stations-panel-header">
          <div>
            <h2>Statii</h2>
            <p>Administrare retea, status OCPP si actiuni rapide</p>
            <div className="stations-inline-stats">
              <span><strong>{formatNumber(summary.total)}</strong> total</span>
              <span className="tone-success"><strong>{formatNumber(summary.available)}</strong> libere</span>
              <span className="tone-warning"><strong>{formatNumber(summary.charging)}</strong> ocupate</span>
              <span className="tone-danger"><strong>{formatNumber(summary.offline)}</strong> offline</span>
              <span className="tone-live"><strong>{formatNumber(summary.connected)}</strong> OCPP</span>
              <span><strong>{formatNumber(summary.activeSessions)}</strong> sesiuni active</span>
            </div>
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

        {viewMode === 'map' ? (
          <StationsMapPanel onOpenDetail={onOpenDetail} stations={visibleRows.length ? visibleRows : rows} />
        ) : (
          <>
            <div className="stations-control-bar">
              <Toolbar value={query} onChange={setQuery} />
              <div className="stations-filter-row">
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
              </div>
              <span className="stations-result-count">{formatNumber(visibleRows.length)} afisate</span>
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

function SessionsView({ rows, loading, onStop, onDelete, onRefresh }) {
  const [query, setQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const visibleRows = rows.filter((session) => {
    if (statusFilter === 'active' && session.end_time) return false;
    if (statusFilter === 'closed' && !session.end_time) return false;

    return matchesQuery(session, query, [
      (item) => item.user?.name,
      (item) => item.user?.email,
      (item) => item.station?.name,
      (item) => item.ocpp_transaction_id,
      (item) => item.end_time ? 'inchisa' : 'activa'
    ]);
  });

  return (
    <ListPanel
      loading={loading}
      title="Sesiuni"
      subtitle="kWh din contor OCPP (ca pe display statie)"
      emptyTitle="Nu exista sesiuni"
      rows={visibleRows}
      searchValue={query}
      onSearchChange={setQuery}
      noResults={rows.length > 0 && visibleRows.length === 0}
      onRefresh={onRefresh}
      filters={(
        <div className="status-filters">
          {sessionStatusFilters.map((filter) => (
            <button
              className={statusFilter === filter.id ? 'secondary-button active-filter' : 'secondary-button'}
              key={filter.id || 'all'}
              onClick={() => setStatusFilter(filter.id)}
              type="button"
            >
              {filter.label}
            </button>
          ))}
        </div>
      )}
      render={(session) => (
        <>
          <div>
            <strong>{session.user?.name ?? '-'}</strong>
            <p>
              {session.station?.name ?? '-'}
              {session.ocpp_connector_id ? ` · C${session.ocpp_connector_id}` : ''}
              {session.ocpp_transaction_id ? ` · tx ${session.ocpp_transaction_id}` : ''}
            </p>
            {session.charge_budget > 0 && (
              <p className="request-meta">
                Buget {formatMoney(session.charge_budget)}
                {session.target_kwh > 0 ? ` · limita ${formatKwh(session.target_kwh)} kWh` : ''}
              </p>
            )}
          </div>
          <span className="live-kwh">
            {formatKwh(sessionKwhDelivered(session))} kWh
            {!session.end_time && sessionPowerKw(session) != null
              ? ` · ${formatKwh(sessionPowerKw(session), 2)} kW`
              : ''}
          </span>
          <Badge variant={session.end_time ? 'success' : 'warning'}>{session.end_time ? 'Inchisa' : 'Activa'}</Badge>
          <div className="row-actions end-actions">
            <strong>{session.start_time ? new Date(session.start_time).toLocaleString('ro-RO') : '-'}</strong>
            {!session.end_time && (
              <button className="secondary-button mini-button" onClick={() => onStop(session)} type="button">
                Opreste
              </button>
            )}
            <button className="icon-button danger-icon" onClick={() => onDelete(session)} type="button" aria-label="Sterge sesiunea">
              <X size={16} />
            </button>
          </div>
        </>
      )}
    />
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

  return (
    <ListPanel
      loading={loading}
      title="Facturi"
      subtitle="Plati si solduri"
      emptyTitle="Nu exista facturi"
      rows={visibleRows}
      searchValue={query}
      onSearchChange={setQuery}
      noResults={rows.length > 0 && visibleRows.length === 0}
      render={(invoice) => (
        <>
          <div>
            <strong>{invoice.invoice_number ?? `#${invoice.id}`}</strong>
            <p>{invoice.user?.name ?? '-'}</p>
          </div>
          <span>{invoice.month ?? '-'}</span>
          <Badge variant={statusVariant(invoice.status)}>{statusLabel(invoice.status)}</Badge>
          <div className="row-actions invoice-actions">
            <strong>{formatMoney(invoice.total_amount)}</strong>
            <button className="secondary-button mini-button" onClick={() => onDownload(invoice)} type="button">
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
      loading={loading}
      title="Audit"
      subtitle="Actiuni backoffice si gateway"
      emptyTitle="Nu exista intrari audit"
      rows={visibleRows}
      searchValue={query}
      onSearchChange={setQuery}
      noResults={rows.length > 0 && visibleRows.length === 0}
      render={(entry) => (
        <>
          <div>
            <button className="station-name-link" onClick={() => onOpenDetail(entry)} type="button">
              <strong>{entry.action}</strong>
            </button>
            <p>{entry.actor?.name ?? 'Sistem'}{entry.actor?.email ? ` · ${entry.actor.email}` : ''}</p>
          </div>
          <span>{entry.station?.name ?? auditSubjectLabel(entry)}</span>
          <Badge>{formatDateTime(entry.created_at)}</Badge>
          <button
            className="icon-button"
            onClick={() => onOpenDetail(entry)}
            type="button"
            aria-label="Detalii audit"
            title="Detalii audit"
          >
            <Eye size={16} />
          </button>
        </>
      )}
    />
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

function WalletTopupsView({ rows, summary, loading }) {
  const [query, setQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
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

  return (
    <div className="panel">
      <div className="panel-header">
        <div>
          <h2>Alimentari wallet</h2>
          <p>Stripe si credite prepay pentru clienti</p>
        </div>
        <Wallet size={20} />
      </div>

      <div className="billing-summary-grid">
        <div className="billing-stat">
          <span>Platite</span>
          <strong>{formatNumber(summary?.count_paid)} · {formatMoney(summary?.volume_paid)}</strong>
        </div>
        <div className="billing-stat">
          <span>In asteptare</span>
          <strong>{formatNumber(summary?.count_pending)} · {formatMoney(summary?.volume_pending)}</strong>
        </div>
      </div>

      <div className="status-filters">
        {walletStatusFilters.map((filter) => (
          <button
            className={statusFilter === filter.id ? 'secondary-button active-filter' : 'secondary-button'}
            key={filter.id || 'all'}
            onClick={() => setStatusFilter(filter.id)}
            type="button"
          >
            {filter.label}
          </button>
        ))}
      </div>

      <Toolbar value={query} onChange={setQuery} />

      {loading ? (
        <LoadingState />
      ) : rows.length === 0 ? (
        <EmptyState title="Nicio alimentare" detail="Cand clientii platesc prin Stripe, tranzactiile apar aici." />
      ) : visibleRows.length === 0 ? (
        <EmptyState title="Niciun rezultat" detail="Schimba filtrul sau cautarea." />
      ) : (
        <div className="table">
          {visibleRows.map((topup) => (
            <div className="table-row four" key={topup.id}>
              <div>
                <strong>{topup.user?.name ?? `User #${topup.user_id}`}</strong>
                <p>{topup.user?.email ?? '-'}</p>
              </div>
              <span className="live-kwh">{formatMoney(topup.amount)}</span>
              <Badge variant={statusVariant(topup.status)}>{statusLabel(topup.status)}</Badge>
              <div>
                <strong>{formatDateTime(topup.paid_at ?? topup.created_at)}</strong>
                <p className="request-meta">
                  {topup.payment_provider ?? '—'}
                  {topup.payment_session_id ? ` · ${topup.payment_session_id.slice(0, 18)}…` : ''}
                </p>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function ClientsView({ rows, loading, onCreate, onOpenDetail }) {
  const [query, setQuery] = useState('');
  const visibleRows = rows.filter((user) => matchesQuery(user, query, [
    (item) => item.name,
    (item) => item.email,
    (item) => item.currency
  ]));

  if (loading) return <LoadingState />;

  return (
    <div className="panel">
      <div className="panel-header">
        <div>
          <h2>Clienti</h2>
          <p>Conturi cu plata la card, per sesiune</p>
        </div>
        <button className="primary-button" onClick={onCreate} type="button">
          <Plus size={18} />
          Client nou
        </button>
      </div>
      <Toolbar value={query} onChange={setQuery} />
      {rows.length === 0 ? (
        <EmptyState title="Nu exista clienti" />
      ) : visibleRows.length === 0 ? (
        <EmptyState title="Niciun client gasit" detail="Schimba termenul de cautare." />
      ) : (
        visibleRows.map((user) => (
          <div className="compact-row user-row client-row" key={user.id}>
            <span className="avatar">{(user.name ?? '?').slice(0, 2).toUpperCase()}</span>
            <div>
              <strong>{user.name ?? '-'}</strong>
              <p>{user.email ?? '-'}</p>
            </div>
            <Badge variant="success">Sold {formatMoney(user.wallet_balance)}</Badge>
            <Badge>{formatNumber(user.sessions_count)} sesiuni</Badge>
            <Badge variant={user.unpaid_invoices_count > 0 ? 'warning' : 'success'}>
              {formatNumber(user.unpaid_invoices_count)} neplatite
            </Badge>
            <strong className={user.outstanding_balance > 0 ? 'debt-value' : ''}>
              {formatMoney(user.outstanding_balance)}
            </strong>
            <button className="secondary-button mini-button" onClick={() => onOpenDetail(user)} type="button">
              Detalii
            </button>
          </div>
        ))
      )}
    </div>
  );
}

function PersonalView({ rows, loading, onCreate, onOpenDetail }) {
  const [query, setQuery] = useState('');
  const visibleRows = rows.filter((user) => matchesQuery(user, query, [
    (item) => item.name,
    (item) => item.email,
    (item) => item.currency
  ]));
  const totalDebt = rows.reduce((sum, user) => sum + Number(user.outstanding_balance || 0), 0);

  if (loading) return <LoadingState />;

  return (
    <div className="panel">
      <div className="panel-header">
        <div>
          <h2>Personal</h2>
          <p>
            {rows.length} angajati
            {totalDebt > 0 ? ` · datorii totale ${formatMoney(totalDebt)}` : ' · fara datorii'}
          </p>
        </div>
        <button className="primary-button" onClick={onCreate} type="button">
          <Plus size={18} />
          Angajat nou
        </button>
      </div>
      <Toolbar value={query} onChange={setQuery} />
      {rows.length === 0 ? (
        <EmptyState title="Nu exista personal" detail="Adauga angajati cu facturare lunara." />
      ) : visibleRows.length === 0 ? (
        <EmptyState title="Niciun angajat gasit" detail="Schimba termenul de cautare." />
      ) : (
        visibleRows.map((user) => (
          <div className="compact-row user-row personal-row" key={user.id}>
            <span className="avatar">{(user.name ?? '?').slice(0, 2).toUpperCase()}</span>
            <div>
              <strong>{user.name ?? '-'}</strong>
              <p>{user.email ?? '-'}</p>
            </div>
            <Badge>{formatNumber(user.sessions_count)} sesiuni</Badge>
            <Badge>{formatNumber(user.invoices_count)} facturi</Badge>
            <Badge variant={user.unpaid_invoices_count > 0 ? 'warning' : 'success'}>
              {formatNumber(user.unpaid_invoices_count)} neplatite
            </Badge>
            <strong className={user.outstanding_balance > 0 ? 'debt-value' : ''}>
              {formatMoney(user.outstanding_balance)}
            </strong>
            <button className="secondary-button mini-button" onClick={() => onOpenDetail(user)} type="button">
              Detalii
            </button>
          </div>
        ))
      )}
    </div>
  );
}

function invoiceTypeLabel(type) {
  if (type === 'monthly') return 'Lunara';
  if (type === 'session') return 'Sesiune';
  return type || '-';
}

function UserDetailModal({ detail, loading, error, onClose, onDownloadInvoice }) {
  if (!detail) {
    return null;
  }

  const user = detail.user ?? detail;
  const billing = detail.billing ?? {};
  const invoices = detail.invoices ?? [];
  const sessions = detail.recent_sessions ?? [];
  const walletTopups = detail.wallet_topups ?? [];

  return (
    <div className="modal-backdrop" role="presentation">
      <div className="modal-panel modal-panel-wide">
        <div className="panel-header">
          <div>
            <h2>{user.name ?? 'Utilizator'}</h2>
            <p>
              {user.email ?? '-'}
              {user.account_type === 'customer' ? ' · client card' : user.account_type === 'personal' ? ' · personal' : ''}
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
            <div className="billing-summary-grid">
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
                <span>Facturi platite</span>
                <strong>{formatNumber(billing.paid_invoices_count)}</strong>
              </div>
              <div className="billing-stat">
                <span>Total facturat</span>
                <strong>{formatMoney(billing.total_billed)}</strong>
              </div>
              <div className="billing-stat">
                <span>Energie totala</span>
                <strong>{formatNumber(billing.total_kwh)} kWh</strong>
              </div>
              <div className="billing-stat">
                <span>Sesiuni</span>
                <strong>{formatNumber(user.sessions_count)}</strong>
              </div>
              {user.account_type === 'customer' && (
                <div className="billing-stat">
                  <span>Sold wallet</span>
                  <strong>{formatMoney(user.wallet_balance)}</strong>
                </div>
              )}
            </div>

            {user.account_type === 'customer' && (
              <div className="detail-section">
                <h3>Alimentari wallet</h3>
                {walletTopups.length === 0 ? (
                  <p className="detail-empty">Nicio alimentare Stripe inca.</p>
                ) : (
                  <div className="detail-table">
                    {walletTopups.map((topup) => (
                      <div className="detail-row" key={topup.id}>
                        <div>
                          <strong>{formatMoney(topup.amount)}</strong>
                          <p>{formatDateTime(topup.paid_at ?? topup.created_at)}</p>
                        </div>
                        <Badge variant={statusVariant(topup.status)}>{statusLabel(topup.status)}</Badge>
                        <span>{topup.payment_provider ?? '—'}</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )}

            <div className="detail-section">
              <h3>Facturi</h3>
              {invoices.length === 0 ? (
                <p className="detail-empty">Nicio factura inca.</p>
              ) : (
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
              )}
            </div>

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

const requestStatusFilters = [
  { id: '', label: 'Toate' },
  { id: 'pending', label: 'In asteptare' },
  { id: 'approved', label: 'Aprobate' },
  { id: 'rejected', label: 'Respinse' }
];

function RequestsView({ rows, loading, onApprove, onReject }) {
  const [query, setQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const pendingCount = rows.filter((request) => request.status === 'pending').length;
  const sortedRows = [...rows].sort((left, right) => {
    const order = { pending: 0, approved: 1, rejected: 2 };
    const statusDiff = (order[left.status] ?? 9) - (order[right.status] ?? 9);

    if (statusDiff !== 0) {
      return statusDiff;
    }

    return right.id - left.id;
  });
  const visibleRows = sortedRows.filter((request) => {
    if (statusFilter && request.status !== statusFilter) {
      return false;
    }

    return matchesQuery(request, query, [
      (item) => item.name,
      (item) => item.email,
      (item) => item.phone,
      (item) => item.status,
      (item) => statusLabel(item.status)
    ]);
  });

  if (loading) return <LoadingState />;

  return (
    <div className="panel">
      <div className="panel-header">
        <div>
          <h2>Cereri inregistrare</h2>
          <p>{pendingCount > 0 ? `${pendingCount} in asteptare` : 'Istoric cereri procesate'}</p>
        </div>
        <ClipboardList size={20} />
      </div>
      <div className="status-filters">
        {requestStatusFilters.map((filter) => (
          <button
            className={statusFilter === filter.id ? 'secondary-button active-filter' : 'secondary-button'}
            key={filter.id || 'all'}
            onClick={() => setStatusFilter(filter.id)}
            type="button"
          >
            {filter.label}
          </button>
        ))}
      </div>
      <Toolbar value={query} onChange={setQuery} />
      {rows.length === 0 ? (
        <EmptyState title="Nu exista cereri" />
      ) : visibleRows.length === 0 ? (
        <EmptyState title="Nicio cerere gasita" detail="Schimba filtrul sau termenul de cautare." />
      ) : (
        visibleRows.map((request) => {
          const isPending = request.status === 'pending';

          return (
            <div className="request-row" key={request.id}>
              <div>
                <strong>{request.name}</strong>
                <p>{request.email}</p>
                {request.phone && <p>{request.phone}</p>}
                {request.processed_at && (
                  <p className="request-meta">
                    Procesata: {new Date(request.processed_at).toLocaleString('ro-RO')}
                  </p>
                )}
              </div>
              <Badge variant={statusVariant(request.status)}>{statusLabel(request.status)}</Badge>
              <div className="row-actions">
                {isPending ? (
                  <>
                    <button className="secondary-button" onClick={() => onReject(request)} type="button">Respinge</button>
                    <button className="primary-button" onClick={() => onApprove(request)} type="button">
                      <CheckCircle2 size={17} />
                      Aproba
                    </button>
                  </>
                ) : (
                  <span className="request-meta">Cont creat in Utilizatori</span>
                )}
              </div>
            </div>
          );
        })
      )}
    </div>
  );
}

function SettingsView({ dashboard, compact = false, onSubmit }) {
  const currentUser = dashboard?.currentUser;
  const currentTariff = dashboard?.currentTariff;
  const ocpp = dashboard?.ocpp;

  return (
    <form className="panel" onSubmit={onSubmit}>
      <div className="panel-header">
        <div>
          <h2>Setari</h2>
          <p>Tarif, profil operator si gateway OCPP</p>
        </div>
        <Settings size={20} />
      </div>
      {ocpp && (
        <div className="ocpp-info-grid">
          <div className="billing-stat">
            <span>Mod OCPP</span>
            <strong>{ocpp.mode ?? '-'}</strong>
          </div>
          <div className="billing-stat">
            <span>URL public WS</span>
            <strong className="ocpp-url">{ocpp.publicUrl ?? '-'}</strong>
          </div>
          <div className="billing-stat">
            <span>Heartbeat</span>
            <strong>{ocpp.heartbeatInterval ?? '-'}s</strong>
          </div>
        </div>
      )}
      <div className={compact ? 'settings-grid compact' : 'settings-grid'}>
        <label>
          Moneda
          <input readOnly value="MDL (Leu moldovenesc)" />
        </label>
        <label>
          Tarif kWh
          <input defaultValue={currentTariff?.price_per_kwh ?? ''} inputMode="decimal" name="price_per_kwh" placeholder="-" />
        </label>
        <label>
          Prenume
          <input defaultValue={currentUser?.first_name ?? ''} name="first_name" placeholder="-" />
        </label>
        <label>
          Nume
          <input defaultValue={currentUser?.last_name ?? ''} name="last_name" placeholder="-" />
        </label>
      </div>
      <button className="primary-button settings-save" type="submit">Salveaza</button>
    </form>
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

function ListPanel({ title, subtitle, rows, render, loading, emptyTitle, searchValue = '', onSearchChange = () => {}, onRefresh, filters, noResults = false }) {
  if (loading) return <LoadingState />;

  return (
    <div className="panel">
      <div className="panel-header">
        <div>
          <h2>{title}</h2>
          <p>{subtitle}</p>
        </div>
        <FileText size={20} />
      </div>
      {filters}
      <Toolbar onRefresh={onRefresh} value={searchValue} onChange={onSearchChange} />
      {noResults ? (
        <EmptyState title="Niciun rezultat" detail="Schimba termenul de cautare." />
      ) : rows.length === 0 ? (
        <EmptyState title={emptyTitle} />
      ) : (
        <div className="table">
          {rows.map((row) => (
            <div className="table-row four" key={row.id}>
              {render(row)}
            </div>
          ))}
        </div>
      )}
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
      ? 'Angajat nou'
      : 'Client nou';

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
                  ? 'Cont personal cu factura lunara'
                  : 'Cont client cu plata la card'}
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
        onRefresh={onRefresh}
      />
    ),
    clients: (
      <ClientsView
        rows={data.clients}
        loading={loading}
        onCreate={actions.openCustomerForm}
        onOpenDetail={actions.openUserDetail}
      />
    ),
    wallet: (
      <WalletTopupsView
        loading={loading}
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
      />
    ),
    requests: <RequestsView rows={data.requests} loading={loading} onApprove={actions.approveRequest} onReject={actions.rejectRequest} />,
    invoices: <InvoicesView rows={data.invoices} loading={loading} onDownload={actions.downloadInvoice} onSend={actions.sendInvoice} onDelete={actions.deleteInvoice} />,
    audit: <AuditView rows={data.audit} loading={loading} onOpenDetail={actions.openAuditDetail} />,
    settings: <SettingsView dashboard={data.dashboard} onSubmit={actions.saveSettings} />
  };

  return views[activeSection] ?? views.dashboard;
}

export default function App() {
  const [activeSection, setActiveSection] = useState('dashboard');
  const { data, loading, error, authRequired, reload } = useBackofficeData();
  const [modalType, setModalType] = useState('');
  const [modalEntity, setModalEntity] = useState(null);
  const [actionError, setActionError] = useState('');
  const [actionMessage, setActionMessage] = useState('');
  const [saving, setSaving] = useState(false);
  const [loginError, setLoginError] = useState('');
  const [loginLoading, setLoginLoading] = useState(false);
  const [userDetail, setUserDetail] = useState(null);
  const [userDetailLoading, setUserDetailLoading] = useState(false);
  const [userDetailError, setUserDetailError] = useState('');
  const [stationDetail, setStationDetail] = useState(null);
  const [stationDetailLoading, setStationDetailLoading] = useState(false);
  const [stationDetailError, setStationDetailError] = useState('');
  const [stationDetailId, setStationDetailId] = useState(null);
  const [auditDetail, setAuditDetail] = useState(null);
  const [auditDetailLoading, setAuditDetailLoading] = useState(false);
  const [auditDetailError, setAuditDetailError] = useState('');
  const activeTitle = useMemo(
    () => sections.find((section) => section.id === activeSection)?.label ?? 'Dashboard',
    [activeSection]
  );
  const currentUser = data.dashboard?.currentUser;
  const operatorName = currentUser?.name || currentUser?.email || 'Admin';
  const dashboardStats = data.dashboard?.stats;
  const pendingRequests = dashboardStats?.pendingRequests ?? 0;

  useEffect(() => {
    if (authRequired) {
      return undefined;
    }

    const timer = window.setInterval(() => {
      reload(true);
    }, 12000);

    return () => window.clearInterval(timer);
  }, [authRequired]);

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

  async function approveRequest(request) {
    if (request.status !== 'pending') {
      return;
    }

    const password = window.prompt('Parola pentru userul aprobat (minim 6 caractere):');
    if (!password) {
      return;
    }

    await runAction(
      () => mutateJson(`/backoffice/registration-requests/${request.id}/approve`, {
        password,
        password_confirmation: password
      }),
      'Cererea a fost aprobata.'
    );
  }

  async function rejectRequest(request) {
    if (request.status !== 'pending') {
      return;
    }

    await runAction(
      () => mutateJson(`/backoffice/registration-requests/${request.id}/reject`),
      'Cererea a fost respinsa.'
    );
  }

  async function saveSettings(event) {
    event.preventDefault();
    await runAction(
      () => mutateJson('/backoffice/settings', formDataToObject(event.currentTarget)),
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
    approveRequest,
    rejectRequest,
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
              badge={section.id === 'requests' ? pendingRequests : 0}
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
            {pendingRequests > 0 ? (
              <button
                className="icon-button alert-button"
                onClick={() => setActiveSection('requests')}
                type="button"
                aria-label="Cereri in asteptare"
              >
                <Bell size={18} />
                <span className="nav-badge">{pendingRequests}</span>
              </button>
            ) : null}
            <span className="operator" title={operatorName}>{initialsFrom(operatorName)}</span>
          </div>
        </header>
        {error && <div className="error-banner">{error}</div>}
        {actionMessage && <div className="success-banner">{actionMessage}</div>}
        {actionError && !modalType && <div className="error-banner">{actionError}</div>}
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
      <UserDetailModal
        detail={userDetail}
        error={userDetailError}
        loading={userDetailLoading}
        onClose={() => {
          setUserDetail(null);
          setUserDetailError('');
        }}
        onDownloadInvoice={downloadInvoice}
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
