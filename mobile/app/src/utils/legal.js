/** Site public legal (store + in-app WebView). */
export const LEGAL_SITE_BASE = "https://v-charge.volta.md";

export function resolveLegalPageUrl(type = "terms") {
  return type === "privacy"
    ? `${LEGAL_SITE_BASE}/confidentialitate.html`
    : `${LEGAL_SITE_BASE}/termeni.html`;
}

/**
 * Normalizeaza URL-uri legale primite de la API catre paginile publice v-charge.
 * Pastreaza compatibilitate daca backend-ul inca intoarce /api/legal/*.
 */
export function withAppLegalParam(url) {
  if (!url || typeof url !== "string") {
    return url;
  }

  try {
    const parsed = new URL(url);
    if (
      parsed.pathname.includes("/legal/privacy") ||
      parsed.pathname.endsWith("confidentialitate.html")
    ) {
      return resolveLegalPageUrl("privacy");
    }
    if (
      parsed.pathname.includes("/legal/terms") ||
      parsed.pathname.endsWith("termeni.html")
    ) {
      return resolveLegalPageUrl("terms");
    }
    return url;
  } catch {
    if (url.includes("privacy") || url.includes("confidentialitate")) {
      return resolveLegalPageUrl("privacy");
    }
    if (url.includes("terms") || url.includes("termeni")) {
      return resolveLegalPageUrl("terms");
    }
    return url;
  }
}
