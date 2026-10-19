import Constants from "expo-constants";
import { Platform } from "react-native";

// Versiunea din app.json inclusa in build; serverul o compara cu versiunea minima.
export const APP_VERSION =
  Constants.expoConfig?.version || Constants.manifest?.version || "0.0.0";

export const APP_VERSION_HEADERS = {
  "X-App-Version": APP_VERSION,
  "X-App-Platform": Platform.OS,
};

/** Compara "1.0.10" cu "1.0.9" numeric, segment cu segment. */
export function compareVersions(a, b) {
  const left = String(a || "0").split(".").map((part) => parseInt(part, 10) || 0);
  const right = String(b || "0").split(".").map((part) => parseInt(part, 10) || 0);
  const length = Math.max(left.length, right.length);

  for (let index = 0; index < length; index += 1) {
    const diff = (left[index] ?? 0) - (right[index] ?? 0);
    if (diff !== 0) {
      return diff > 0 ? 1 : -1;
    }
  }

  return 0;
}

let updateState = null;
const listeners = new Set();

/** Odata cerut, ecranul de actualizare ramane pana la repornirea aplicatiei actualizate. */
export function markUpdateRequired({ message, storeUrl, minVersion } = {}) {
  if (updateState) {
    return;
  }

  updateState = {
    message: message || "Este disponibila o versiune noua V CHARGE. Actualizeaza aplicatia pentru a continua.",
    storeUrl: storeUrl || null,
    minVersion: minVersion || null,
  };
  listeners.forEach((listener) => listener());
}

export function getUpdateState() {
  return updateState;
}

export function subscribeUpdateState(listener) {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}
