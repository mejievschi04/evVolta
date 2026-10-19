export function resolveQrStationId(rawInput, stations) {
  const raw = String(rawInput || "").trim();
  const station = stations.find((item) => String(item.qr_code || "").trim() === raw);
  if (station) {
    return { stationId: String(station.id) };
  }

  const candidates = new Set();
  if (raw.toLowerCase().startsWith("station:")) {
    candidates.add(raw.split(":").slice(1).join(":"));
  }

  try {
    const url = new URL(raw);
    const fromQuery =
      url.searchParams.get("station_id")
      || url.searchParams.get("stationId")
      || url.searchParams.get("id");
    if (fromQuery) {
      candidates.add(fromQuery);
    }
    const pathLast = url.pathname.split("/").filter(Boolean).pop();
    if (pathLast) {
      candidates.add(pathLast);
    }
  } catch {
    // Not a URL.
  }

  try {
    const parsed = JSON.parse(raw);
    const fromJson = parsed?.station_id ?? parsed?.stationId ?? parsed?.id;
    if (fromJson != null) {
      candidates.add(String(fromJson));
    }
  } catch {
    // Not JSON.
  }

  candidates.add(raw);

  const numeric = Array.from(candidates)
    .map((value) => String(value).replace(/\D/g, ""))
    .find((value) => value && stations.some((item) => String(item.id) === value));

  return numeric ? { stationId: numeric } : { stationId: null };
}
