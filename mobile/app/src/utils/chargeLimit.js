export function estimateBudgetForKwh(kwh, pricePerKwh) {
  const value = Number(kwh) * Number(pricePerKwh);
  return Math.round(value * 100) / 100;
}

export function buildChargingStartPayload(stationId, selection, connectorId = null) {
  const payload = { station_id: Number(stationId) };

  if (connectorId != null && Number.isFinite(Number(connectorId))) {
    payload.connector_id = Number(connectorId);
  }

  if (selection?.mode === "kwh") {
    const kwh = Number(selection.value);
    if (Number.isFinite(kwh) && kwh > 0) {
      payload.target_kwh = Math.round(kwh * 1000) / 1000;
    }
  } else if (selection?.mode === "amount" || selection?.mode === "full") {
    // FULL = hold wallet balance as budget (no kWh cap), capped by API max.
    const amount = Number(selection.value);
    if (Number.isFinite(amount) && amount > 0) {
      payload.budget_amount = Math.round(amount * 100) / 100;
    }
  }

  return payload;
}

export function formatMoney(amount, currency = "MDL") {
  const value = Number(amount);
  if (!Number.isFinite(value)) return `— ${currency}`;
  return `${value.toFixed(2)} ${currency}`;
}
