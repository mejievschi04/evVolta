import Constants from "expo-constants";
import { NativeModules, Platform } from "react-native";

function extractHost(value) {
  if (!value || typeof value !== "string") {
    return null;
  }
  const trimmed = value.trim();
  if (!trimmed) {
    return null;
  }

  const urlMatch = trimmed.match(/^[a-zA-Z]+:\/\/([^/:]+)/);
  if (urlMatch?.[1]) {
    return urlMatch[1];
  }

  return trimmed.split(":")[0] || null;
}

function isLocalhost(host) {
  return host === "localhost" || host === "127.0.0.1" || host === "::1";
}

const fallbackHost = Platform.OS === "android" ? "10.0.2.2" : "127.0.0.1";
const envApiHost = process.env.EXPO_PUBLIC_API_HOST?.trim();

const hostCandidates = [
  envApiHost,
  extractHost(Constants.expoConfig?.hostUri),
  extractHost(Constants.expoGoConfig?.debuggerHost),
  extractHost(Constants.manifest?.debuggerHost),
  extractHost(Constants.manifest2?.extra?.expoClient?.hostUri),
  extractHost(NativeModules?.SourceCode?.scriptURL),
].filter(Boolean);

const firstNonLocalhostHost = hostCandidates.find((host) => !isLocalhost(host));
const apiHost = firstNonLocalhostHost || hostCandidates[0] || fallbackHost;

export function resolveApiBaseUrl() {
  const envApiUrl = process.env.EXPO_PUBLIC_API_URL?.trim();
  if (envApiUrl) {
    return envApiUrl.replace(/\/+$/, "");
  }

  const configuredApiHost = process.env.EXPO_PUBLIC_API_HOST?.trim();
  if (configuredApiHost) {
    const host = extractHost(configuredApiHost) || configuredApiHost;
    return `http://${host}:8000/api`;
  }

  return `http://${apiHost}:8000/api`;
}

export const API_BASE_URL = resolveApiBaseUrl();
