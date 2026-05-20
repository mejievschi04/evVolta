import axios from "axios";
import Constants from "expo-constants";
import { NativeModules, Platform } from "react-native";
import { getApiErrorMessage, getNetworkErrorMessage } from "../utils/apiErrors";
import {
  finishApiRequest,
  finishApiRetry,
  startApiRequest,
  startApiRetry,
} from "./statusStore";

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
const API_BASE_URL = `http://${apiHost}:8000/api`;

const apiClient = axios.create({
  baseURL: API_BASE_URL,
  timeout: 10000,
});

const RETRY_DELAY_MS = 400;
const MAX_NETWORK_RETRIES = 1;

function shouldRetryRequest(error) {
  const method = error?.config?.method?.toLowerCase();
  const retryCount = error?.config?._retryCount ?? 0;
  const isNetworkIssue = !error?.response || error?.code === "ECONNABORTED";

  return method === "get" && isNetworkIssue && retryCount < MAX_NETWORK_RETRIES;
}

apiClient.interceptors.request.use((config) => {
  startApiRequest();
  return config;
});

apiClient.interceptors.response.use(
  (response) => {
    finishApiRequest();
    return response;
  },
  async (error) => {
    finishApiRequest();
    const originalRequest = error?.config;

    if (originalRequest && shouldRetryRequest(error)) {
      originalRequest._retryCount = (originalRequest._retryCount ?? 0) + 1;
      startApiRetry();
      try {
        await new Promise((resolve) => setTimeout(resolve, RETRY_DELAY_MS));
      } finally {
        finishApiRetry();
      }
      return apiClient(originalRequest);
    }

    const networkMessage = getNetworkErrorMessage(error);
    const normalizedMessage = networkMessage || getApiErrorMessage(error);

    if (error?.response) {
      const currentData = error.response.data;
      if (currentData && typeof currentData === "object" && !Array.isArray(currentData)) {
        error.response.data = {
          ...currentData,
          message: currentData.message ?? normalizedMessage,
        };
      } else {
        error.response.data = {
          message: normalizedMessage,
          originalData: currentData,
        };
      }
    } else {
      error.response = {
        ...error.response,
        data: {
          message: normalizedMessage,
        },
      };
    }

    return Promise.reject(error);
  }
);

export const setAuthToken = (token) => {
  if (token) {
    apiClient.defaults.headers.common.Authorization = `Bearer ${token}`;
  } else {
    delete apiClient.defaults.headers.common.Authorization;
  }
};

export default apiClient;