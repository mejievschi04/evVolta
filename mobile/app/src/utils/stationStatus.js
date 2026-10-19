export function effectiveStationAvailability(station) {
  return station?.display_status ?? station?.live_status?.availability ?? station?.status ?? "offline";
}

export function effectiveOcppConnection(station) {
  return station?.live_status?.connection_status ?? station?.ocpp_connection_status ?? "not_configured";
}

export function stationCanStart(station) {
  const live = station?.live_status;
  const availability = effectiveStationAvailability(station);

  if (["offline", "faulted", "unavailable", "stale"].includes(availability)) {
    return false;
  }

  if ((live?.start_connector_candidates?.length ?? 0) > 0) {
    return true;
  }

  if (live?.can_start === false) {
    return false;
  }

  if (live?.can_start === true) {
    return true;
  }

  return availability === "available" || availability === "preparing";
}

export function startConnectorCandidates(station) {
  const fromLive = station?.live_status?.start_connector_candidates;
  if (Array.isArray(fromLive) && fromLive.length > 0) {
    return fromLive.map((connector) => ({
      id: connector.id,
      label: connector.label ?? (connector.id === 2 ? "B" : "A"),
      vehicle_connected: Boolean(connector.vehicle_connected),
      status: connector.status ?? null,
      can_start: true,
      occupied_by_other_user: false,
      my_active_session: false,
    }));
  }

  const connectors = station?.live_status?.connectors;
  if (!Array.isArray(connectors)) {
    return [];
  }

  return connectors
    .filter((connector) => connector.can_start_for_user)
    .map((connector) => ({
      id: connector.id,
      label: connector.label ?? (connector.id === 2 ? "B" : "A"),
      vehicle_connected: Boolean(connector.vehicle_connected),
      status: connector.status ?? null,
      can_start: true,
      occupied_by_other_user: Boolean(connector.occupied_by_other_user),
      my_active_session: Boolean(connector.my_active_session),
    }));
}

/**
 * Toate porturile fizice ale statiei — pentru selectie manuala A/B (fara autodetect).
 */
export function stationPortOptions(station) {
  const connectors = station?.live_status?.connectors;
  if (Array.isArray(connectors) && connectors.length > 0) {
    return connectors.map((connector) => {
      const status = String(connector.status ?? "");
      const vehicleConnected = Boolean(
        connector.vehicle_connected
        || ["Preparing", "SuspendedEV", "SuspendedEVSE", "Charging", "Finishing"].includes(status)
      );

      return {
        id: Number(connector.id),
        label: connector.label ?? (Number(connector.id) === 2 ? "B" : "A"),
        vehicle_connected: vehicleConnected,
        status: connector.status ?? null,
        can_start: connector.can_start_for_user !== false && connector.can_start !== false,
        occupied_by_other_user: Boolean(connector.occupied_by_other_user),
        my_active_session: Boolean(connector.my_active_session),
        is_stale_finishing: Boolean(connector.is_stale_finishing),
      };
    });
  }

  return startConnectorCandidates(station);
}

export function portSelectionMessage(portOptions = []) {
  const pluggedCount = portOptions.filter((connector) => connector.vehicle_connected).length;

  if (pluggedCount >= 2) {
    return "Ambele porturi au masina conectata. Selecteaza portul pe care vrei sa pornesti.";
  }

  if (pluggedCount === 1) {
    return "Portul cu masina e evidentiant. Selecteaza-l ca sa poti porni incarcarea.";
  }

  return "Conecteaza masina, apoi selecteaza portul A sau B.";
}

export function selectedPortCanStart(port) {
  if (!port) {
    return false;
  }

  if (port.occupied_by_other_user || port.my_active_session) {
    return false;
  }

  if (!port.vehicle_connected) {
    return false;
  }

  return port.can_start !== false;
}

export function availabilityLabel(status) {
  if (status === "available") return "Disponibila";
  if (status === "preparing") return "Gata de pornire";
  if (status === "charging") return "In incarcare";
  if (status === "reserved") return "Rezervata";
  if (status === "faulted") return "Eroare";
  if (status === "unavailable") return "Indisponibila";
  if (status === "offline") return "Offline";
  if (status === "stale") return "Fara semnal OCPP";
  return status ? String(status) : "—";
}

const CONNECTOR_STATUS_LABELS = {
  Available: "Liber",
  Preparing: "Masina conectata",
  Charging: "Se incarca",
  SuspendedEV: "Pauza (masina)",
  SuspendedEVSE: "Pauza (statie)",
  Finishing: "Incarcare oprita",
  Reserved: "Rezervat",
  Unavailable: "Indisponibil",
  Faulted: "Eroare port",
};

const CONNECTOR_STATUS_HINTS = {
  SuspendedEV: "Masina a pus incarcarea pe pauza (baterie plina, limita setata in masina sau programare).",
  SuspendedEVSE: "Statia a pus incarcarea pe pauza temporar.",
  Finishing: "Incarcarea s-a oprit, dar cablul este inca conectat.",
};

/** Status OCPP al portului (Charging, SuspendedEV...) tradus pentru utilizator. */
export function connectorStatusLabel(status) {
  if (!status) return "—";
  return CONNECTOR_STATUS_LABELS[status] ?? String(status);
}

export function connectorStatusHint(status) {
  return CONNECTOR_STATUS_HINTS[status] ?? null;
}

export function connectionLabel(status) {
  if (status === "connected") return "OCPP conectata";
  if (status === "disconnected") return "OCPP deconectata";
  if (status === "not_configured") return "OCPP neconfigurata";
  return "Status necunoscut";
}

export function markerColorForStation(station, palette) {
  const availability = effectiveStationAvailability(station);
  if (availability === "available" || availability === "preparing") return palette.success;
  if (availability === "charging") return palette.accent;
  if (availability === "reserved") return "#7CC7FF";
  return palette.danger;
}
