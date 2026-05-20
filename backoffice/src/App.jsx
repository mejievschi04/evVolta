import { useEffect, useMemo, useState } from 'react';
import {
  Activity,
  BatteryCharging,
  Bell,
  CheckCircle2,
  CircleDollarSign,
  ClipboardList,
  Clock3,
  Copy,
  Download,
  FileText,
  Filter,
  LayoutDashboard,
  LogOut,
  MapPin,
  MoreHorizontal,
  Plus,
  Receipt,
  RadioTower,
  Search,
  Settings,
  ShieldCheck,
  X,
  Users,
  Zap
} from 'lucide-react';
import voltaLogo from './assets/icons/Volta Logo 2@300x 1.png';

const sections = [
  { id: 'dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { id: 'stations', label: 'Statii', icon: Zap },
  { id: 'sessions', label: 'Sesiuni', icon: BatteryCharging },
  { id: 'users', label: 'Utilizatori', icon: Users },
  { id: 'requests', label: 'Cereri', icon: ClipboardList },
  { id: 'invoices', label: 'Facturi', icon: Receipt },
  { id: 'audit', label: 'Audit', icon: ShieldCheck },
  { id: 'settings', label: 'Setari', icon: Settings }
];

const endpoints = {
  dashboard: '/backoffice/dashboard',
  stations: '/backoffice/stations',
  sessions: '/backoffice/sessions',
  users: '/backoffice/users',
  requests: '/backoffice/registration-requests',
  invoices: '/backoffice/invoices',
  audit: '/backoffice/audit-logs'
};

const emptyData = {
  dashboard: null,
  stations: [],
  sessions: [],
  users: [],
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

  async function load() {
    setState((current) => ({ ...current, loading: true }));

    try {
      const dashboard = await fetchJson(endpoints.dashboard);
      const results = await Promise.all(
        Object.entries(endpoints)
          .filter(([key]) => key !== 'dashboard')
          .map(async ([key, url]) => [key, await fetchJson(url)])
      );
      const nextData = { ...emptyData, dashboard };

      for (const [key, payload] of results) {
        nextData[key] = key === 'dashboard' ? payload : payload.data ?? [];
      }

      setState({ data: nextData, loading: false, error: '', authRequired: false });
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

      setState({
        data: emptyData,
        loading: false,
        error: error.message || 'Nu am putut incarca datele reale.',
        authRequired: false
      });
    }
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

function statusLabel(status) {
  return {
    available: 'Disponibila',
    charging: 'In incarcare',
    offline: 'Offline',
    paid: 'Platita',
    unpaid: 'Neplatita',
    pending: 'In asteptare',
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
    charging: 'Conector ocupat',
    reserved: 'Rezervat',
    faulted: 'Eroare conector',
    unavailable: 'Indisponibil',
    stale: 'Heartbeat vechi'
  }[status] ?? status ?? 'Live necunoscut';
}

function formatLastSeen(seconds) {
  if (seconds === null || seconds === undefined) {
    return 'fara date live';
  }

  if (seconds < 60) {
    return `acum ${Math.max(0, Math.round(seconds))}s`;
  }

  const minutes = Math.round(seconds / 60);
  if (minutes < 60) {
    return `acum ${minutes} min`;
  }

  return `acum ${Math.round(minutes / 60)} h`;
}

function statusVariant(status) {
  if (['available', 'paid', 'approved', 'connected'].includes(status)) return 'success';
  if (['charging', 'pending', 'unpaid', 'disconnected', 'reserved'].includes(status)) return 'warning';
  if (['offline', 'rejected', 'faulted', 'unavailable', 'stale'].includes(status)) return 'danger';
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

function SectionButton({ section, active, onClick }) {
  const Icon = section.icon;

  return (
    <button className={`nav-item ${active ? 'active' : ''}`} onClick={() => onClick(section.id)} type="button">
      <Icon size={18} />
      <span>{section.label}</span>
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

function initialsFrom(value = 'Admin') {
  const words = value
    .split(/[\s@.]+/)
    .filter(Boolean)
    .slice(0, 2);

  return (words.map((word) => word[0]).join('') || 'EV').toUpperCase();
}

function EmptyState({ title = 'Nu exista date reale inca', detail = 'Porneste backend-ul si autentifica backoffice-ul ca sa incarcam informatiile.' }) {
  return (
    <div className="empty-state">
      <Activity size={24} />
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

function DashboardView({ dashboard, loading }) {
  const stats = dashboard?.stats;
  const stationStatus = dashboard?.stationStatus;
  const statusItems = [
    { key: 'available', label: 'Disponibile', value: stationStatus?.available ?? 0, variant: 'success' },
    { key: 'charging', label: 'In incarcare', value: stationStatus?.charging ?? 0, variant: 'warning' },
    { key: 'offline', label: 'Offline', value: stationStatus?.offline ?? 0, variant: 'danger' }
  ];
  const stationTotal = statusItems.reduce((total, item) => total + Number(item.value || 0), 0);

  if (loading) return <LoadingState />;

  return (
    <div className="view-stack">
      <section className="stats-grid">
        <StatCard label="Utilizatori" value={formatNumber(stats?.users)} helper="total conturi" icon={Users} />
        <StatCard label="Statii" value={formatNumber(stats?.stations)} helper={`${formatNumber(stats?.availableStations)} disponibile`} icon={Zap} />
        <StatCard label="Sesiuni azi" value={formatNumber(stats?.sessionsToday)} helper={`${formatNumber(stats?.activeSessions)} active`} icon={Activity} />
        <StatCard label="OCPP live" value={formatNumber(stats?.connectedStations)} helper="statii conectate" icon={RadioTower} />
      </section>

      <section className="content-grid">
        <div className="panel wide">
          <div className="panel-header">
            <div>
              <h2>Retea statii</h2>
              <p>Distributie live din backend</p>
            </div>
            <RadioTower size={20} />
          </div>
          <div className="network-panel">
            <div className="network-total">
              <span>Total statii</span>
              <strong>{formatNumber(stats?.stations)}</strong>
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
        </div>

        <div className="panel">
          <div className="panel-header">
            <div>
              <h2>Status statii</h2>
              <p>Retea curenta</p>
            </div>
            <Activity size={20} />
          </div>
          <div className="status-list">
            {statusItems.map((item) => (
              <div key={item.key}>
                <Badge variant={item.variant}>{item.label}</Badge>
                <strong>{formatNumber(item.value)}</strong>
              </div>
            ))}
          </div>
        </div>
      </section>
    </div>
  );
}

function StationsView({ rows, loading, onCreate, onEdit, onDelete, onDownloadQr, onPreviewQr }) {
  const [query, setQuery] = useState('');
  const visibleRows = rows.filter((station) => matchesQuery(station, query, [
    (item) => item.name,
    (item) => item.location,
    (item) => item.status,
    (item) => item.qr_code,
    (item) => item.ocpp_identity,
    (item) => item.ocpp_connection_status,
    (item) => item.live_status?.availability,
    (item) => item.live_status?.connector_status,
    (item) => item.connector_type
  ]));

  if (loading) return <LoadingState />;

  return (
    <div className="panel">
      <div className="panel-header">
        <div>
          <h2>Statii</h2>
          <p>Administrare puncte de incarcare</p>
        </div>
        <button className="primary-button" onClick={onCreate} type="button">
          <Plus size={18} />
          Statie noua
        </button>
      </div>
      <Toolbar value={query} onChange={setQuery} />
      {rows.length === 0 ? (
        <EmptyState title="Nu exista statii" detail="Cand backend-ul returneaza statii, apar aici automat." />
      ) : visibleRows.length === 0 ? (
        <EmptyState title="Nicio statie gasita" detail="Schimba termenul de cautare." />
      ) : (
        <div className="table">
          {visibleRows.map((station) => (
            <div className="table-row" key={station.id}>
              <div className="station-cell">
                <span className="station-dot" />
                <div>
                  <strong>{station.name}</strong>
                  <p><MapPin size={14} /> {station.location}</p>
                  <p><RadioTower size={14} /> {station.ocpp_identity ?? 'fara OCPP identity'}</p>
                  <p className="station-live-line">
                    {availabilityLabel(station.live_status?.availability)}
                    {' - '}
                    {formatLastSeen(station.live_status?.seconds_since_last_seen)}
                  </p>
                </div>
              </div>
              <div className="station-badges">
                <Badge variant={statusVariant(station.status)}>{statusLabel(station.status)}</Badge>
                <Badge variant={statusVariant(station.ocpp_connection_status)}>{connectionLabel(station.ocpp_connection_status)}</Badge>
                <Badge variant={statusVariant(station.live_status?.availability)}>{availabilityLabel(station.live_status?.availability)}</Badge>
              </div>
              <span>{formatNumber(station.power_kw)} kW</span>
              <strong>{formatNumber(station.sessions_count)} sesiuni</strong>
              <div className="row-actions compact-actions">
                {station.ocpp_connection_url && (
                  <button className="icon-button" onClick={() => navigator.clipboard?.writeText(station.ocpp_connection_url)} type="button" aria-label="Copiaza URL OCPP">
                    <Copy size={16} />
                  </button>
                )}
                <button className="icon-button" onClick={() => onPreviewQr(station)} type="button" aria-label="Preview QR">
                  <Search size={16} />
                </button>
                <button className="icon-button" onClick={() => onDownloadQr(station)} type="button" aria-label="Descarca QR">
                  <Download size={16} />
                </button>
                <button className="icon-button" onClick={() => onEdit(station)} type="button" aria-label="Editeaza statia">
                  <MoreHorizontal size={18} />
                </button>
                <button className="icon-button danger-icon" onClick={() => onDelete(station)} type="button" aria-label="Sterge statia">
                  <X size={16} />
                </button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function SessionsView({ rows, loading, onStop }) {
  const [query, setQuery] = useState('');
  const visibleRows = rows.filter((session) => matchesQuery(session, query, [
    (item) => item.user?.name,
    (item) => item.user?.email,
    (item) => item.station?.name,
    (item) => item.end_time ? 'inchisa' : 'activa'
  ]));

  return (
    <ListPanel
      loading={loading}
      title="Sesiuni"
      subtitle="Monitorizare incarcari"
      emptyTitle="Nu exista sesiuni"
      rows={visibleRows}
      searchValue={query}
      onSearchChange={setQuery}
      noResults={rows.length > 0 && visibleRows.length === 0}
      render={(session) => (
        <>
          <div>
            <strong>{session.user?.name ?? '-'}</strong>
            <p>{session.station?.name ?? '-'}</p>
          </div>
          <span>{formatNumber(session.kwh_consumed)} kWh</span>
          <Badge variant={session.end_time ? 'success' : 'warning'}>{session.end_time ? 'Inchisa' : 'Activa'}</Badge>
          <div className="row-actions end-actions">
            <strong>{session.start_time ? new Date(session.start_time).toLocaleString('ro-RO') : '-'}</strong>
            {!session.end_time && (
              <button className="secondary-button mini-button" onClick={() => onStop(session)} type="button">
                Opreste
              </button>
            )}
          </div>
        </>
      )}
    />
  );
}

function InvoicesView({ rows, loading, onDownload, onSend }) {
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
          </div>
        </>
      )}
    />
  );
}

function AuditView({ rows, loading }) {
  const [query, setQuery] = useState('');
  const visibleRows = rows.filter((entry) => matchesQuery(entry, query, [
    (item) => item.action,
    (item) => item.actor?.name,
    (item) => item.actor?.email,
    (item) => item.station?.name,
    (item) => item.subject_type
  ]));

  return (
    <ListPanel
      loading={loading}
      title="Audit"
      subtitle="Actiuni backoffice"
      emptyTitle="Nu exista intrari audit"
      rows={visibleRows}
      searchValue={query}
      onSearchChange={setQuery}
      noResults={rows.length > 0 && visibleRows.length === 0}
      render={(entry) => (
        <>
          <div>
            <strong>{entry.action}</strong>
            <p>{entry.actor?.name ?? 'Sistem'}</p>
          </div>
          <span>{entry.station?.name ?? entry.subject_type ?? '-'}</span>
          <Badge>{entry.created_at ? new Date(entry.created_at).toLocaleString('ro-RO') : '-'}</Badge>
          <ShieldCheck size={18} />
        </>
      )}
    />
  );
}

function UsersView({ rows, loading, dashboard, onCreate, onSaveSettings }) {
  const [query, setQuery] = useState('');
  const visibleRows = rows.filter((user) => matchesQuery(user, query, [
    (item) => item.name,
    (item) => item.email,
    (item) => item.currency
  ]));

  if (loading) return <LoadingState />;

  return (
    <div className="split-grid">
      <div className="panel">
        <div className="panel-header">
          <div>
            <h2>Utilizatori</h2>
            <p>Conturi clienti</p>
          </div>
          <button className="primary-button" onClick={onCreate} type="button">
            <Plus size={18} />
            User nou
          </button>
        </div>
        <Toolbar value={query} onChange={setQuery} />
        {rows.length === 0 ? (
          <EmptyState title="Nu exista utilizatori" />
        ) : visibleRows.length === 0 ? (
          <EmptyState title="Niciun user gasit" detail="Schimba termenul de cautare." />
        ) : (
          visibleRows.map((user) => (
            <div className="compact-row" key={user.id}>
              <span className="avatar">{(user.name ?? '?').slice(0, 2).toUpperCase()}</span>
              <div>
                <strong>{user.name ?? '-'}</strong>
                <p>{user.email ?? '-'}</p>
              </div>
              <Badge>{formatNumber(user.sessions_count)} sesiuni</Badge>
            </div>
          ))
        )}
      </div>
      <SettingsView dashboard={dashboard} compact onSubmit={onSaveSettings} />
    </div>
  );
}

function RequestsView({ rows, loading, onApprove, onReject }) {
  const [query, setQuery] = useState('');
  const visibleRows = rows.filter((request) => matchesQuery(request, query, [
    (item) => item.name,
    (item) => item.email,
    (item) => item.phone,
    (item) => item.status
  ]));

  if (loading) return <LoadingState />;

  return (
    <div className="panel">
      <div className="panel-header">
        <div>
          <h2>Cereri inregistrare</h2>
          <p>Aprobari conturi noi</p>
        </div>
        <ClipboardList size={20} />
      </div>
      <Toolbar value={query} onChange={setQuery} />
      {rows.length === 0 ? (
        <EmptyState title="Nu exista cereri" />
      ) : visibleRows.length === 0 ? (
        <EmptyState title="Nicio cerere gasita" detail="Schimba termenul de cautare." />
      ) : (
        visibleRows.map((request) => (
          <div className="request-row" key={request.id}>
            <div>
              <strong>{request.name}</strong>
              <p>{request.email}</p>
            </div>
            <Badge variant={statusVariant(request.status)}>{statusLabel(request.status)}</Badge>
            <div className="row-actions">
              <button className="secondary-button" onClick={() => onReject(request)} type="button">Respinge</button>
              <button className="primary-button" onClick={() => onApprove(request)} type="button">
                <CheckCircle2 size={17} />
                Aproba
              </button>
            </div>
          </div>
        ))
      )}
    </div>
  );
}

function SettingsView({ dashboard, compact = false, onSubmit }) {
  const currentUser = dashboard?.currentUser;
  const currentTariff = dashboard?.currentTariff;

  return (
    <form className="panel" onSubmit={onSubmit}>
      <div className="panel-header">
        <div>
          <h2>Setari</h2>
          <p>Tarif si profil operator</p>
        </div>
        <Settings size={20} />
      </div>
      <div className={compact ? 'settings-grid compact' : 'settings-grid'}>
        <label>
          Moneda
          <select defaultValue={currentUser?.currency ?? ''} name="currency">
            <option value="">Nesetat</option>
            <option>MDL</option>
            <option>EUR</option>
            <option>RON</option>
            <option>USD</option>
          </select>
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

function Toolbar({ value = '', onChange = () => {} }) {
  return (
    <div className="toolbar">
      <label className="search-box">
        <Search size={17} />
        <input onChange={(event) => onChange(event.target.value)} placeholder="Cauta in date reale" value={value} />
      </label>
      <button className="secondary-button" type="button">
        <Filter size={17} />
        Filtre
      </button>
    </div>
  );
}

function ListPanel({ title, subtitle, rows, render, loading, emptyTitle, searchValue = '', onSearchChange = () => {}, noResults = false }) {
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
      <Toolbar value={searchValue} onChange={onSearchChange} />
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
  const title = isStation ? (isEdit ? 'Editeaza statia' : 'Statie noua') : 'User nou';

  return (
    <div className="modal-backdrop" role="presentation">
      <form className="modal-panel" onSubmit={onSubmit}>
        <div className="panel-header">
          <div>
            <h2>{title}</h2>
            <p>{isStation ? 'Adauga un punct de incarcare' : 'Creeaza cont client'}</p>
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
              Moneda
              <select name="currency" defaultValue={entity?.currency ?? 'MDL'}>
                <option>MDL</option>
                <option>EUR</option>
                <option>RON</option>
                <option>USD</option>
              </select>
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
              <input defaultValue={entity?.qr_code ?? ''} name="qr_code" placeholder="Se genereaza automat daca ramane gol" />
            </label>
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
            <label>
              Moneda
              <select name="currency" defaultValue="MDL" required>
                <option>MDL</option>
                <option>EUR</option>
                <option>RON</option>
                <option>USD</option>
              </select>
            </label>
            <label className="full-field">
              Parola
              <input name="password" type="password" required placeholder="Minim 12 caractere, litere mari/mici si cifre" />
            </label>
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

function ActiveView({ activeSection, data, loading, actions }) {
  const views = {
    dashboard: <DashboardView dashboard={data.dashboard} loading={loading} />,
    stations: (
      <StationsView
        rows={data.stations}
        loading={loading}
        onCreate={actions.openStationForm}
        onEdit={actions.editStation}
        onDelete={actions.deleteStation}
        onDownloadQr={actions.downloadQr}
        onPreviewQr={actions.previewQr}
      />
    ),
    sessions: <SessionsView rows={data.sessions} loading={loading} onStop={actions.stopSession} />,
    users: <UsersView rows={data.users} loading={loading} dashboard={data.dashboard} onCreate={actions.openUserForm} onSaveSettings={actions.saveSettings} />,
    requests: <RequestsView rows={data.requests} loading={loading} onApprove={actions.approveRequest} onReject={actions.rejectRequest} />,
    invoices: <InvoicesView rows={data.invoices} loading={loading} onDownload={actions.downloadInvoice} onSend={actions.sendInvoice} />,
    audit: <AuditView rows={data.audit} loading={loading} />,
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
  const activeTitle = useMemo(
    () => sections.find((section) => section.id === activeSection)?.label ?? 'Dashboard',
    [activeSection]
  );
  const currentUser = data.dashboard?.currentUser;
  const operatorName = currentUser?.name || currentUser?.email || 'Admin';
  const dashboardStats = data.dashboard?.stats;

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
    const password = window.prompt('Parola pentru userul aprobat (minim 12 caractere, litere mari/mici si cifre):');
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
    openUserForm: () => {
      setActionError('');
      setActionMessage('');
      setModalEntity(null);
      setModalType('user');
    },
    deleteStation,
    stopSession,
    downloadQr: (station) => openStationQr(station),
    previewQr: (station) => openStationQr(station, true),
    downloadInvoice,
    sendInvoice,
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
            <button className="icon-button" type="button" aria-label="Notificari">
              <Bell size={18} />
            </button>
            <span className="operator" title={operatorName}>{initialsFrom(operatorName)}</span>
          </div>
        </header>
        {error && <div className="error-banner">{error}</div>}
        {actionMessage && <div className="success-banner">{actionMessage}</div>}
        {actionError && !modalType && <div className="error-banner">{actionError}</div>}
        <ActiveView activeSection={activeSection} data={data} loading={loading} actions={actions} />
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
    </main>
  );
}
