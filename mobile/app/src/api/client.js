import axios from "axios";
import { getApiErrorMessage, getNetworkErrorMessage } from "../utils/apiErrors";
import { API_BASE_URL } from "./apiConfig";
import { APP_VERSION_HEADERS, markUpdateRequired } from "./appVersion";
import { invalidateSession, refreshAccessToken } from "./authSession";
import {
  finishApiRequest,
  finishApiRetry,
  startApiRequest,
  startApiRetry,
} from "./statusStore";

const apiClient = axios.create({
  baseURL: API_BASE_URL,
  timeout: 10000,
  headers: APP_VERSION_HEADERS,
});

const RETRY_DELAY_MS = 400;
const MAX_NETWORK_RETRIES = 1;
const AUTH_SKIP_PATHS = ["/login", "/register", "/auth/google", "/auth/apple", "/refresh", "/logout", "/legal"];

function shouldRetryRequest(error) {
  const method = error?.config?.method?.toLowerCase();
  const retryCount = error?.config?._retryCount ?? 0;
  const isNetworkIssue = !error?.response || error?.code === "ECONNABORTED";

  return method === "get" && isNetworkIssue && retryCount < MAX_NETWORK_RETRIES;
}

function getBearerToken(config) {
  const headerToken = config?.headers?.Authorization;
  if (typeof headerToken === "string" && headerToken.startsWith("Bearer ")) {
    return headerToken.slice("Bearer ".length);
  }

  const defaultHeader = apiClient.defaults.headers.common.Authorization;
  if (typeof defaultHeader === "string" && defaultHeader.startsWith("Bearer ")) {
    return defaultHeader.slice("Bearer ".length);
  }

  return null;
}

function getDefaultBearerToken() {
  const defaultHeader = apiClient.defaults.headers.common.Authorization;
  if (typeof defaultHeader === "string" && defaultHeader.startsWith("Bearer ")) {
    return defaultHeader.slice("Bearer ".length);
  }

  return null;
}

function isAuthSkippedRequest(config) {
  const url = String(config?.url ?? "");
  return AUTH_SKIP_PATHS.some((path) => {
    if (url === path || url.endsWith(path)) {
      return true;
    }
    // /legal, /legal/terms, /legal/privacy
    return path === "/legal" && (url.includes("/legal/") || url.endsWith("/legal"));
  });
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

    // Versiune prea veche: serverul refuza orice cerere pana la actualizare.
    if (error?.response?.status === 426 && error.response.data?.code === "update_required") {
      markUpdateRequired({
        message: error.response.data.message,
        storeUrl: error.response.data.store_url,
        minVersion: error.response.data.min_version,
      });
    }

    if (
      originalRequest
      && error?.response?.status === 401
      && !originalRequest._skipAuthRefresh
      && !originalRequest._retryAfterRefresh
      && !isAuthSkippedRequest(originalRequest)
    ) {
      const currentToken = getBearerToken(originalRequest);
      const latestToken = getDefaultBearerToken();

      // Cerere plecata cu tokenul vechi, iar intre timp alta cerere a facut deja refresh:
      // reincercam cu tokenul nou in loc sa reinnoim din nou cu unul deja invalidat.
      if (currentToken && latestToken && latestToken !== currentToken) {
        originalRequest._retryAfterRefresh = true;
        originalRequest.headers = {
          ...originalRequest.headers,
          Authorization: `Bearer ${latestToken}`,
        };
        return apiClient(originalRequest);
      }

      if (currentToken) {
        try {
          originalRequest._retryAfterRefresh = true;
          const nextToken = await refreshAccessToken(currentToken);
          originalRequest.headers = {
            ...originalRequest.headers,
            Authorization: `Bearer ${nextToken}`,
          };
          setAuthToken(nextToken);
          return apiClient(originalRequest);
        } catch (refreshError) {
          // Delogare doar când serverul respinge refresh-ul (token invalid/expirat),
          // nu la erori de rețea temporare.
          const refreshStatus = refreshError?.response?.status;
          if (refreshStatus === 401 || refreshStatus === 403) {
            await invalidateSession();
          }
        }
      }
    }

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

export { API_BASE_URL };
export default apiClient;
