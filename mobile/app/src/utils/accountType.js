export function accountTypeLabel(accountType) {
  if (accountType === "service") {
    return "Serviciu";
  }

  if (accountType === "personal") {
    return "Personal";
  }

  if (accountType === "customer") {
    return "Client";
  }

  return "Utilizator";
}

export function usesWalletAccount(accountType) {
  return accountType === "customer" || accountType === "personal";
}

export function isFreeChargingAccount(accountType) {
  return accountType === "service";
}

export function formatTariffPriceLabel(pricePerKwh, currency = "MDL", { freeCharging = false } = {}) {
  if (freeCharging || Number(pricePerKwh) === 0) {
    return "Gratuit";
  }

  return `${Number(pricePerKwh || 0).toFixed(2)} ${currency}/kWh`;
}
