import AsyncStorage from "@react-native-async-storage/async-storage";
import * as SecureStore from "expo-secure-store";
import { Platform } from "react-native";
import axios from "axios";
import { API_BASE_URL } from "./apiConfig";
import { APP_VERSION_HEADERS } from "./appVersion";

export const TOKEN_STORAGE_KEY = "vcharge.auth.token";
const LEGACY_TOKEN_STORAGE_KEY = "volta.ev.auth.token";
const REFRESH_BUFFER_MS = 5 * 60 * 1000;
const useSecureStore = Platform.OS !== "web";

const refreshClient = axios.create({
  baseURL: API_BASE_URL,
  timeout: 10000,
  headers: APP_VERSION_HEADERS,
});

let refreshPromise = null;
let onTokenUpdated = null;
let onSessionInvalidated = null;

export function configureAuthSession(handlers = {}) {
  onTokenUpdated = handlers.onTokenUpdated ?? null;
  onSessionInvalidated = handlers.onSessionInvalidated ?? null;
}

export function parseJwtExpiryMs(token) {
  const parts = token?.split(".");
  if (!parts || parts.length < 2) {
    return null;
  }

  try {
    const base64 = parts[1].replace(/-/g, "+").replace(/_/g, "/");
    const padded = base64 + "=".repeat((4 - (base64.length % 4)) % 4);
    const json = globalThis.atob(padded);
    const payload = JSON.parse(json);

    return typeof payload.exp === "number" ? payload.exp * 1000 : null;
  } catch {
    return null;
  }
}

export function shouldRefreshToken(token, now = Date.now()) {
  const expiryMs = parseJwtExpiryMs(token);
  if (!expiryMs) {
    return false;
  }

  return expiryMs - now <= REFRESH_BUFFER_MS;
}

async function writeToken(token) {
  if (useSecureStore) {
    await SecureStore.setItemAsync(TOKEN_STORAGE_KEY, token);
    // Remove legacy plaintext / old-brand copies if present.
    await AsyncStorage.removeItem(TOKEN_STORAGE_KEY).catch(() => undefined);
    await AsyncStorage.removeItem(LEGACY_TOKEN_STORAGE_KEY).catch(() => undefined);
    await SecureStore.deleteItemAsync(LEGACY_TOKEN_STORAGE_KEY).catch(() => undefined);
    return;
  }

  await AsyncStorage.setItem(TOKEN_STORAGE_KEY, token);
  await AsyncStorage.removeItem(LEGACY_TOKEN_STORAGE_KEY).catch(() => undefined);
}

async function readToken() {
  if (useSecureStore) {
    const secureToken = await SecureStore.getItemAsync(TOKEN_STORAGE_KEY);
    if (secureToken) {
      return secureToken;
    }

    const legacySecureToken = await SecureStore.getItemAsync(LEGACY_TOKEN_STORAGE_KEY);
    if (legacySecureToken) {
      await SecureStore.setItemAsync(TOKEN_STORAGE_KEY, legacySecureToken);
      await SecureStore.deleteItemAsync(LEGACY_TOKEN_STORAGE_KEY).catch(() => undefined);
      return legacySecureToken;
    }

    const legacyPlainToken =
      (await AsyncStorage.getItem(TOKEN_STORAGE_KEY)) ||
      (await AsyncStorage.getItem(LEGACY_TOKEN_STORAGE_KEY));
    if (legacyPlainToken) {
      await SecureStore.setItemAsync(TOKEN_STORAGE_KEY, legacyPlainToken);
      await AsyncStorage.removeItem(TOKEN_STORAGE_KEY).catch(() => undefined);
      await AsyncStorage.removeItem(LEGACY_TOKEN_STORAGE_KEY).catch(() => undefined);
      return legacyPlainToken;
    }

    return null;
  }

  return (
    (await AsyncStorage.getItem(TOKEN_STORAGE_KEY)) ||
    (await AsyncStorage.getItem(LEGACY_TOKEN_STORAGE_KEY))
  );
}

async function deleteToken() {
  if (useSecureStore) {
    await SecureStore.deleteItemAsync(TOKEN_STORAGE_KEY).catch(() => undefined);
    await SecureStore.deleteItemAsync(LEGACY_TOKEN_STORAGE_KEY).catch(() => undefined);
  }

  await AsyncStorage.removeItem(TOKEN_STORAGE_KEY).catch(() => undefined);
  await AsyncStorage.removeItem(LEGACY_TOKEN_STORAGE_KEY).catch(() => undefined);
}

export async function getStoredAccessToken() {
  return readToken();
}

export async function clearStoredAccessToken() {
  await deleteToken();
}

export async function persistAccessToken(token, persistStorage = true) {
  if (persistStorage) {
    await writeToken(token);
  } else {
    await deleteToken();
  }

  onTokenUpdated?.(token);
  return token;
}

export async function refreshAccessToken(currentToken) {
  if (!currentToken) {
    throw new Error("Missing access token.");
  }

  if (refreshPromise) {
    return refreshPromise;
  }

  refreshPromise = refreshClient
    .post(
      "/refresh",
      {},
      {
        headers: {
          Authorization: `Bearer ${currentToken}`,
        },
      }
    )
    .then(async (response) => {
      const nextToken = response.data?.access_token;
      if (!nextToken) {
        throw new Error("Refresh response missing access token.");
      }

      const rememberRaw = await AsyncStorage.getItem("vcharge.auth.remember");
      const persistStorage = rememberRaw === null || rememberRaw === "1" || rememberRaw === "true";

      return persistAccessToken(nextToken, persistStorage);
    })
    .finally(() => {
      refreshPromise = null;
    });

  return refreshPromise;
}

export async function ensureFreshAccessToken(currentToken) {
  if (!currentToken || !shouldRefreshToken(currentToken)) {
    return currentToken;
  }

  return refreshAccessToken(currentToken);
}

export async function invalidateSession({ clearStorage = true } = {}) {
  if (clearStorage) {
    await deleteToken();
  }

  onSessionInvalidated?.();
}
