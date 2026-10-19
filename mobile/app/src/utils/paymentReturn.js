export function parseStripeReturnUrl(url) {
  if (!url || typeof url !== "string") {
    return null;
  }

  const cleaned = url.trim();
  // Accept legacy voltaev:// during app upgrades.
  if (!/^(?:vcharge|voltaev):\/\//i.test(cleaned)) {
    return null;
  }

  const withoutScheme = cleaned.replace(/^(?:vcharge|voltaev):\/\//i, "");
  const [pathPart, queryPart = ""] = withoutScheme.split("?");
  const segments = pathPart.split("/").filter(Boolean);

  if (segments[0] !== "pay") {
    return null;
  }

  const status = segments[1];
  if (status !== "success" && status !== "cancel") {
    return null;
  }

  const params = new URLSearchParams(queryPart);

  return {
    status,
    walletTopupId: Number(params.get("wallet_topup_id") || 0),
    invoiceId: Number(params.get("invoice_id") || 0),
    sessionId: params.get("session_id") || null,
  };
}
