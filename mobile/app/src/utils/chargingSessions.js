/**
 * Find a connector on the station where the user can start another session
 * while they already have one or more active sessions at the same station.
 *
 * @param {object|null} station
 * @param {Array<{ ocpp_connector_id?: number|null }>} activeSessionsAtStation
 * @returns {object|null}
 */
export function findNextStartableConnector(station, activeSessionsAtStation = []) {
  const connectors = station?.live_status?.connectors;
  if (!Array.isArray(connectors) || connectors.length === 0) {
    return null;
  }

  const occupiedIds = new Set(
    activeSessionsAtStation
      .map((session) => Number(session.ocpp_connector_id))
      .filter((id) => Number.isFinite(id) && id > 0)
  );

  return (
    connectors.find((connector) => {
      const id = Number(connector.id);
      if (!Number.isFinite(id) || id <= 0 || occupiedIds.has(id)) {
        return false;
      }

      if (connector.can_start === false) {
        return false;
      }

      const availability = String(connector.availability ?? "").toLowerCase();
      const status = String(connector.status ?? "").toLowerCase();

      return (
        ["available", "preparing"].includes(availability) ||
        ["available", "preparing", "finishing"].includes(status) ||
        Boolean(connector.is_stale_finishing)
      );
    }) ?? null
  );
}

/**
 * Port pe care userul poate apasa Continua (SuspendedEV / SuspendedEVSE / Finishing).
 */
export function findResumableConnector(station, preferredConnectorId = null) {
  const connectors = station?.live_status?.connectors;
  if (!Array.isArray(connectors) || connectors.length === 0) {
    return null;
  }

  const preferred = preferredConnectorId != null ? Number(preferredConnectorId) : null;

  const match = (connector) => {
    const status = String(connector.status ?? "").toLowerCase();
    const resumable =
      Boolean(connector.can_resume) ||
      ["suspendedev", "suspendedevse", "finishing"].includes(status);
    if (!resumable) {
      return false;
    }
    if (connector.occupied_by_other_user) {
      return false;
    }
    return true;
  };

  if (preferred) {
    const exact = connectors.find((connector) => Number(connector.id) === preferred && match(connector));
    if (exact) {
      return exact;
    }
  }

  return connectors.find(match) ?? null;
}

export function activeSessionsAtStation(activeSessions, stationId) {
  if (!stationId) {
    return [];
  }

  return (activeSessions ?? []).filter(
    (session) => String(session.station_id) === String(stationId) && !session.end_time
  );
}

/**
 * Prefer the session that is actually charging (has OCPP tx), else the newest row.
 *
 * @param {Array<Record<string, unknown>>} sessions
 * @returns {Record<string, unknown>|null}
 */
export function pickPrimaryActiveSession(sessions = []) {
  const open = (sessions ?? []).filter((session) => !session?.end_time);
  if (open.length === 0) {
    return null;
  }

  const withTransaction = open.filter((session) => session.ocpp_transaction_id);
  const pool = withTransaction.length > 0 ? withTransaction : open;

  return [...pool].sort((left, right) => Number(right.id) - Number(left.id))[0] ?? null;
}
