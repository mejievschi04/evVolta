import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import AsyncStorage from "@react-native-async-storage/async-storage";
import { AppState } from "react-native";
import apiClient, { setAuthToken } from "../api/client";

const AuthContext = createContext(null);
const TOKEN_STORAGE_KEY = "volta.ev.auth.token";
const USER_STORAGE_KEY = "volta.ev.auth.user";

export const AuthProvider = ({ children }) => {
  const [token, setToken] = useState(null);
  const [user, setUser] = useState(null);
  const [activeSession, setActiveSession] = useState(null);
  const [preferredStationId, setPreferredStationId] = useState(null);
  const [sessionRedirectVersion, setSessionRedirectVersion] = useState(0);
  const [isBootstrapping, setIsBootstrapping] = useState(true);

  const persistUser = useCallback(async (nextUser) => {
    setUser(nextUser);
    await AsyncStorage.setItem(USER_STORAGE_KEY, JSON.stringify(nextUser));
  }, []);

  const refreshUser = useCallback(async () => {
    const response = await apiClient.get("/me");
    const nextUser = response.data.user;
    await persistUser(nextUser);
    return nextUser;
  }, [persistUser]);

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

    try {
      const response = await apiClient.get("/sessions");
      const currentSession = response.data.find((item) => !item.end_time) ?? null;
      setActiveSession(currentSession);

      if (currentSession && bumpRedirect) {
        setSessionRedirectVersion((current) => current + 1);
      }

      return currentSession;
    } catch {
      setActiveSession(null);
      return null;
    }
  }, []);

  useEffect(() => {
    let cancelled = false;

    const bootstrapAuth = async () => {
      try {
        const [storedToken, storedUser] = await Promise.all([
          AsyncStorage.getItem(TOKEN_STORAGE_KEY),
          AsyncStorage.getItem(USER_STORAGE_KEY),
        ]);

        if (cancelled) {
          return;
        }

        if (storedToken) {
          setToken(storedToken);
          setAuthToken(storedToken);
        }

        if (storedUser) {
          setUser(JSON.parse(storedUser));
        }

        if (storedToken) {
          await Promise.all([
            refreshActiveSession({ bumpRedirect: true }),
            refreshUser().catch(() => null),
          ]);
        }
      } catch {
        try {
          await AsyncStorage.multiRemove([TOKEN_STORAGE_KEY, USER_STORAGE_KEY]);
        } catch {
          // Ignore storage cleanup errors and fall back to logged-out state.
        }
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
  }, [refreshActiveSession, refreshUser]);

  useEffect(() => {
    const subscription = AppState.addEventListener("change", (nextState) => {
      if (nextState === "active" && token) {
        void refreshActiveSession({ bumpRedirect: true }).catch(() => null);
        void refreshUser().catch(() => null);
      }
    });

    return () => subscription.remove();
  }, [refreshActiveSession, refreshUser, token]);

  const login = async (email, password) => {
    const response = await apiClient.post("/login", { email, password });
    const nextToken = response.data.access_token;
    const nextUser = response.data.user;

    setToken(nextToken);
    setAuthToken(nextToken);

    await AsyncStorage.multiSet([
      [TOKEN_STORAGE_KEY, nextToken],
      [USER_STORAGE_KEY, JSON.stringify(nextUser)],
    ]);
    setUser(nextUser);

    await refreshActiveSession({ bumpRedirect: true });
  };

  const logout = async () => {
    setToken(null);
    setUser(null);
    setActiveSession(null);
    setPreferredStationId(null);
    setAuthToken(null);
    await AsyncStorage.multiRemove([TOKEN_STORAGE_KEY, USER_STORAGE_KEY]);
  };

  const value = useMemo(
    () => ({
      token,
      user,
      activeSession,
      setActiveSession,
      preferredStationId,
      setPreferredStationId,
      refreshActiveSession,
      refreshUser,
      updateProfile,
      sessionRedirectVersion,
      isBootstrapping,
      isAuthenticated: Boolean(token),
      login,
      logout,
    }),
    [
      token,
      user,
      activeSession,
      preferredStationId,
      sessionRedirectVersion,
      isBootstrapping,
      refreshUser,
      updateProfile,
      refreshActiveSession,
    ]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
};

export const useAuth = () => useContext(AuthContext);
