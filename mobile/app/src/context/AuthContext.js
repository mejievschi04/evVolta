import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import AsyncStorage from "@react-native-async-storage/async-storage";
import { AppState } from "react-native";
import apiClient, { setAuthToken } from "../api/client";
import { pickPrimaryActiveSession } from "../utils/chargingSessions";
import {
  configureAuthSession,
  ensureFreshAccessToken,
  getStoredAccessToken,
  clearStoredAccessToken,
  invalidateSession,
  parseJwtExpiryMs,
  persistAccessToken,
} from "../api/authSession";

const AuthContext = createContext(null);
const USER_STORAGE_KEY = "vcharge.auth.user";
const LEGACY_USER_STORAGE_KEY = "volta.ev.auth.user";
export const REMEMBER_ME_STORAGE_KEY = "vcharge.auth.remember";

export async function getStoredRememberMe() {
  const value = await AsyncStorage.getItem(REMEMBER_ME_STORAGE_KEY);
  if (value === null) {
    return false;
  }
  return value === "1" || value === "true";
}

export async function setStoredRememberMe(remember) {
  await AsyncStorage.setItem(REMEMBER_ME_STORAGE_KEY, remember ? "1" : "0");
}

export const AuthProvider = ({ children }) => {
  const [token, setToken] = useState(null);
  const [user, setUser] = useState(null);
  const [activeSession, setActiveSession] = useState(null);
  const [activeSessions, setActiveSessions] = useState([]);
  const [preferredStationId, setPreferredStationId] = useState(null);
  const [sessionRedirectVersion, setSessionRedirectVersion] = useState(0);
  const [isBootstrapping, setIsBootstrapping] = useState(true);
  const [legal, setLegal] = useState(null);

  const clearLocalSession = useCallback(async () => {
    setToken(null);
    setUser(null);
    setActiveSession(null);
    setActiveSessions([]);
    setPreferredStationId(null);
    setLegal(null);
    setAuthToken(null);
    await clearStoredAccessToken();
    await AsyncStorage.removeItem(USER_STORAGE_KEY);
    await AsyncStorage.removeItem(LEGACY_USER_STORAGE_KEY);
  }, []);

  const persistUser = useCallback(async (nextUser, { persistStorage = true } = {}) => {
    setUser(nextUser);
    if (!persistStorage) {
      await AsyncStorage.removeItem(USER_STORAGE_KEY);
      await AsyncStorage.removeItem(LEGACY_USER_STORAGE_KEY).catch(() => undefined);
      return;
    }

    await AsyncStorage.setItem(USER_STORAGE_KEY, JSON.stringify(nextUser));
    await AsyncStorage.removeItem(LEGACY_USER_STORAGE_KEY).catch(() => undefined);
  }, []);

  const applyAccessToken = useCallback(async (nextToken, { persistStorage = true } = {}) => {
    setToken(nextToken);
    setAuthToken(nextToken);
    if (!persistStorage) {
      await clearStoredAccessToken();
      return;
    }
    await persistAccessToken(nextToken, true);
  }, []);

  const refreshUser = useCallback(async () => {
    const response = await apiClient.get("/me");
    const nextUser = response.data.user;
    if (response.data?.legal) {
      setLegal(response.data.legal);
    }
    await persistUser(nextUser);
    return nextUser;
  }, [persistUser]);

  const acceptLegal = useCallback(async () => {
    const response = await apiClient.post("/me/accept-legal", { accept_terms: true });
    if (response.data?.legal) {
      setLegal(response.data.legal);
    }
    if (response.data?.user) {
      await persistUser(response.data.user);
    }
    return response.data?.legal;
  }, [persistUser]);

  const needsLegalAcceptance = Boolean(token && legal?.required);

  const updateProfile = useCallback(
    async (profile) => {
      const response = await apiClient.patch("/me", profile);
      const nextUser = response.data.user;
      await persistUser(nextUser);
      return nextUser;
    },
    [persistUser]
  );

  const refreshActiveSession = useCallback(async (options = {}) => {
    const { bumpRedirect = false } = options;

    if (!token) {
      setActiveSessions([]);
      setActiveSession(null);
      return null;
    }

    try {
      let response;
      try {
        // Doar sesiunile deschise: rapid indiferent de istoric.
        response = await apiClient.get("/sessions/active");
      } catch (error) {
        // Backend mai vechi fara endpoint-ul nou.
        if (error?.response?.status !== 404) {
          throw error;
        }
        response = await apiClient.get("/sessions");
      }
      const sessions = Array.isArray(response.data) ? response.data : [];
      const openSessions = sessions.filter((item) => !item.end_time);
      const primarySession = pickPrimaryActiveSession(openSessions);
      setActiveSessions(openSessions);
      setActiveSession((current) => {
        if (current && openSessions.some((item) => item.id === current.id)) {
          return openSessions.find((item) => item.id === current.id) ?? primarySession;
        }

        return primarySession;
      });

      if (primarySession && bumpRedirect) {
        setSessionRedirectVersion((current) => current + 1);
      }

      return primarySession;
    } catch {
      // O eroare de retea / timeout nu inseamna ca incarcarea s-a oprit: pastram starea
      // cunoscuta in loc sa afisam „fara incarcare” cat timp masina se incarca.
      // 401 e tratat de interceptor (refresh sau delogare, care goleste sesiunea local).
      // Nu aruncam: apelantii (login, bootstrap, revenire din fundal) se bazeaza pe asta.
      return null;
    }
  }, [token]);

  useEffect(() => {
    configureAuthSession({
      onTokenUpdated: (nextToken) => {
        setToken(nextToken);
        setAuthToken(nextToken);
      },
      onSessionInvalidated: () => {
        void clearLocalSession();
      },
    });
  }, [clearLocalSession]);

  useEffect(() => {
    let cancelled = false;

    const bootstrapAuth = async () => {
      try {
        const [storedToken, storedUserRaw] = await Promise.all([
          getStoredAccessToken(),
          AsyncStorage.getItem(USER_STORAGE_KEY).then(async (value) => {
            if (value) {
              return value;
            }

            const legacy = await AsyncStorage.getItem(LEGACY_USER_STORAGE_KEY);
            if (legacy) {
              await AsyncStorage.setItem(USER_STORAGE_KEY, legacy);
              await AsyncStorage.removeItem(LEGACY_USER_STORAGE_KEY).catch(() => undefined);
            }

            return legacy;
          }),
        ]);

        if (cancelled) {
          return;
        }

        if (storedUserRaw) {
          setUser(JSON.parse(storedUserRaw));
        }

        if (storedToken) {
          let freshToken = storedToken;
          try {
            freshToken = await ensureFreshAccessToken(storedToken);
          } catch {
            const expiryMs = parseJwtExpiryMs(storedToken);
            // Păstrăm sesiunea la erori de rețea dacă tokenul local e încă valid.
            if (!expiryMs || expiryMs <= Date.now()) {
              await clearLocalSession();
              return;
            }
          }

          await applyAccessToken(freshToken, {
            persistStorage: await getStoredRememberMe(),
          });

          await Promise.all([
            refreshActiveSession({ bumpRedirect: true }),
            refreshUser().catch(() => null),
          ]);
        }
      } catch {
        // Nu delogăm la erori generice de bootstrap (rețea / storage).
      } finally {
        if (!cancelled) {
          setIsBootstrapping(false);
        }
      }
    };

    bootstrapAuth();

    return () => {
      cancelled = true;
    };
  }, [applyAccessToken, clearLocalSession, refreshActiveSession, refreshUser]);

  useEffect(() => {
    const subscription = AppState.addEventListener("change", (nextState) => {
      if (nextState !== "active" || !token) {
        return;
      }

      void (async () => {
        const remember = await getStoredRememberMe();
        try {
          const freshToken = await ensureFreshAccessToken(token);
          if (freshToken !== token) {
            await applyAccessToken(freshToken, { persistStorage: remember });
          }
        } catch {
          const expiryMs = parseJwtExpiryMs(token);
          if (!expiryMs || expiryMs <= Date.now()) {
            await invalidateSession();
            return;
          }
        }

        await Promise.all([
          refreshActiveSession({ bumpRedirect: true }),
          refreshUser().catch(() => null),
        ]);
      })();
    });

    return () => subscription.remove();
  }, [applyAccessToken, refreshActiveSession, refreshUser, token]);

  const login = async (email, password, options = {}) => {
    const remember = Boolean(options.remember_me);
    const response = await apiClient.post("/login", {
      email,
      password,
      accept_terms: options.accept_terms ?? false,
      remember_me: remember,
    });
    await applyAuthResponse(response, { remember });
  };

  const loginWithGoogle = async (idToken, options = {}) => {
    const remember = Boolean(options.remember_me);
    const response = await apiClient.post("/auth/google", {
      id_token: idToken,
      accept_terms: options.accept_terms ?? false,
      remember_me: remember,
    });
    await applyAuthResponse(response, { remember });
  };

  const loginWithApple = async (identityToken, options = {}) => {
    const remember = Boolean(options.remember_me);
    const response = await apiClient.post("/auth/apple", {
      identity_token: identityToken,
      full_name: options.full_name || undefined,
      accept_terms: options.accept_terms ?? false,
      remember_me: remember,
    });
    await applyAuthResponse(response, { remember });
  };

  const applyAuthResponse = async (response, { remember = false } = {}) => {
    const nextToken = response.data.access_token;
    const nextUser = response.data.user;
    if (response.data?.legal) {
      setLegal(response.data.legal);
    }

    await setStoredRememberMe(remember);
    await applyAccessToken(nextToken, { persistStorage: remember });
    await persistUser(nextUser, { persistStorage: remember });

    await refreshActiveSession({ bumpRedirect: true });
  };

  const register = async (payload) => {
    const remember = Boolean(payload?.remember_me);
    const response = await apiClient.post("/register", {
      ...payload,
      remember_me: remember,
    });
    await applyAuthResponse(response, { remember });
  };

  const logout = async () => {
    try {
      await apiClient.post("/logout", null, { _skipAuthRefresh: true });
    } catch {
      // Local logout still proceeds if API is unreachable.
    }

    await clearLocalSession();
  };

  const deleteAccount = useCallback(
    async (passwordOrOptions) => {
      const payload =
        typeof passwordOrOptions === "string"
          ? { password: passwordOrOptions, confirm_delete: true }
          : passwordOrOptions?.confirm_delete
            ? { confirm_delete: true }
            : {
                password: passwordOrOptions?.password,
                confirm_delete: true,
              };

      await apiClient.post("/me/delete", payload, { _skipAuthRefresh: true });
      await clearLocalSession();
    },
    [clearLocalSession]
  );

  const value = useMemo(
    () => ({
      token,
      user,
      legal,
      needsLegalAcceptance,
      activeSession,
      activeSessions,
      setActiveSession,
      preferredStationId,
      setPreferredStationId,
      refreshActiveSession,
      refreshUser,
      acceptLegal,
      updateProfile,
      deleteAccount,
      sessionRedirectVersion,
      isBootstrapping,
      isAuthenticated: Boolean(token),
      login,
      loginWithGoogle,
      loginWithApple,
      register,
      logout,
    }),
    [
      token,
      user,
      legal,
      needsLegalAcceptance,
      activeSession,
      activeSessions,
      preferredStationId,
      sessionRedirectVersion,
      isBootstrapping,
      refreshUser,
      acceptLegal,
      updateProfile,
      deleteAccount,
      refreshActiveSession,
    ]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
};

export const useAuth = () => useContext(AuthContext);
