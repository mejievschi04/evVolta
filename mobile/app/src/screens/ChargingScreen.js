import React, { useCallback, useEffect, useMemo, useState } from "react";
import {
  ActivityIndicator,
  Alert,
  Animated,
  Easing,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from "react-native";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { File, Paths } from "expo-file-system";
import * as Sharing from "expo-sharing";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import apiClient from "../api/client";
import ChargeLimitModal from "../components/charging/ChargeLimitModal";
import StationQrScannerModal from "../components/charging/StationQrScannerModal";
import { useAuth } from "../context/AuthContext";
import AuthGatePanel from "../components/auth/AuthGatePanel";
import { useApiResource } from "../hooks/useApiResource";
import { useAppResumeRefresh } from "../hooks/useAppResumeRefresh";
import { useScreenFocusLoad } from "../hooks/useScreenFocusLoad";
import { useSessionLivePoll } from "../hooks/useSessionLivePoll";
import { buildChargingStartPayload } from "../utils/chargeLimit";
import { activeSessionsAtStation, findNextStartableConnector, findResumableConnector, pickPrimaryActiveSession } from "../utils/chargingSessions";
import { getApiErrorMessage } from "../utils/apiErrors";
import { formatTariffPriceLabel, isFreeChargingAccount, usesWalletAccount } from "../utils/accountType";
import { downloadInvoiceFile } from "../utils/invoices";
import { resolveQrStationId } from "../utils/qrStation";
import {
  availabilityLabel,
  connectionLabel,
  connectorStatusHint,
  connectorStatusLabel,
  effectiveOcppConnection,
  effectiveStationAvailability,
  portSelectionMessage,
  selectedPortCanStart,
  startConnectorCandidates,
  stationCanStart,
  stationPortOptions,
} from "../utils/stationStatus";
import { colors, motion } from "../theme";

/** Paleta aliniata cu theme.js */
const C = {
  bg: colors.bg,
  bgSoft: colors.bgSoft,
  card: colors.card,
  accent: colors.accent,
  accentSoft: colors.accentSoft,
  text: colors.text,
  textMuted: colors.textMuted,
  onAccent: colors.accentText,
  border: colors.border,
  borderSoft: colors.borderSoft,
  borderAccent: colors.borderAccent,
};

const PHASE = {
  IDLE: "idle",
  READY: "ready",
  CHARGING: "charging",
  COMPLETE: "complete",
};

const TAB_BAR_HEIGHT = 72;
const TAB_BAR_BOTTOM_MARGIN = 12;
const TAB_BAR_GAP = 14;
const ACTION_BAR_HEIGHT = 72;

function tabBarClearance(insets) {
  return Math.max(insets.bottom, TAB_BAR_BOTTOM_MARGIN) + TAB_BAR_HEIGHT + TAB_BAR_GAP;
}

export default function ChargingScreen() {
  const insets = useSafeAreaInsets();
  const {
    user,
    activeSession,
    activeSessions,
    setActiveSession,
    refreshActiveSession,
    preferredStationId,
    setPreferredStationId,
    isAuthenticated,
  } = useAuth();
  const [stationId, setStationId] = useState("");
  const [lastResult, setLastResult] = useState(null);
  const [stoppingSessionId, setStoppingSessionId] = useState(null);
  const [loading, setLoading] = useState(false);
  const [limitModalOpen, setLimitModalOpen] = useState(false);
  const [scannerOpen, setScannerOpen] = useState(false);
  const [resolvingQr, setResolvingQr] = useState(false);
  const [walletLoadFailed, setWalletLoadFailed] = useState(false);
  const [startAnotherPort, setStartAnotherPort] = useState(false);
  const [selectedStartConnectorId, setSelectedStartConnectorId] = useState(null);
  const [secondsElapsed, setSecondsElapsed] = useState(0);
  const [chargePulse] = useState(() => new Animated.Value(0));

  const fetchChargingData = useCallback(async () => {
    const [stationsResponse, tariffResponse] = await Promise.all([
      apiClient.get("/stations"),
      apiClient.get("/tariff/current"),
    ]);

    let wallet = null;

    if (usesWalletAccount(user?.account_type)) {
      try {
        const walletResponse = await apiClient.get("/wallet");
        wallet = walletResponse.data;
        setWalletLoadFailed(false);
      } catch {
        wallet = null;
        setWalletLoadFailed(true);
      }
    } else {
      setWalletLoadFailed(false);
    }

    return {
      stations: Array.isArray(stationsResponse.data) ? stationsResponse.data : [],
      tariff: tariffResponse.data,
      wallet,
    };
  }, [user?.account_type]);

  const {
    data: chargingData,
    loading: chargingLoading,
    load: loadChargingData,
  } = useApiResource(fetchChargingData, {
    stations: [],
    tariff: null,
    wallet: null,
  });

  useScreenFocusLoad(loadChargingData);
  useAppResumeRefresh(
    useCallback(() => {
      void loadChargingData("silent").catch(() => null);
    }, [loadChargingData])
  );

  const stations = Array.isArray(chargingData.stations) ? chargingData.stations : [];
  const tariff = chargingData.tariff;
  const wallet = chargingData.wallet;
  const pricePerKwh = Number(tariff?.price_per_kwh || wallet?.charge_options?.price_per_kwh || 0);
  const currency = tariff?.currency || wallet?.currency || "MDL";
  const freeCharging = Boolean(tariff?.free_charging || wallet?.free_charging || isFreeChargingAccount(user?.account_type));
  const requiresPrepaid = Boolean(wallet?.requires_prepaid) && !freeCharging;
  const priceChipLabel = formatTariffPriceLabel(pricePerKwh, currency, { freeCharging });

  const resolvedStationId = activeSession?.station_id && !startAnotherPort
    ? String(activeSession.station_id)
    : stationId || (activeSession?.station_id ? String(activeSession.station_id) : "");
  const selectedStation = useMemo(() => {
    if (!resolvedStationId) return null;
    return stations.find((item) => String(item.id) === String(resolvedStationId)) ?? null;
  }, [resolvedStationId, stations]);

  const sessionsAtStation = useMemo(
    () => activeSessionsAtStation(activeSessions, resolvedStationId),
    [activeSessions, resolvedStationId]
  );

  const nextStartConnector = useMemo(
    () => findNextStartableConnector(selectedStation, sessionsAtStation),
    [selectedStation, sessionsAtStation]
  );

  const startCandidates = useMemo(
    () => startConnectorCandidates(selectedStation),
    [selectedStation]
  );

  const portOptions = useMemo(
    () => stationPortOptions(selectedStation),
    [selectedStation]
  );

  const canStartAnotherPort = Boolean(
    startAnotherPort && resolvedStationId && sessionsAtStation.length > 0 && nextStartConnector
  );

  // Fara autodetect: userul alege mereu portul. Pastram selectia daca portul inca exista.
  useEffect(() => {
    if (portOptions.length === 0) {
      setSelectedStartConnectorId(null);
      return;
    }

    if (selectedStartConnectorId == null) {
      return;
    }

    const stillExists = portOptions.some(
      (port) => Number(port.id) === Number(selectedStartConnectorId)
    );
    if (!stillExists) {
      setSelectedStartConnectorId(null);
    }
  }, [portOptions, selectedStartConnectorId]);

  const selectedPort = useMemo(
    () => portOptions.find((port) => Number(port.id) === Number(selectedStartConnectorId)) ?? null,
    [portOptions, selectedStartConnectorId]
  );

  const displaySession = canStartAnotherPort
    ? null
    : activeSession && (!resolvedStationId || String(activeSession.station_id) === String(resolvedStationId))
      ? activeSession
      : pickPrimaryActiveSession(sessionsAtStation) ?? activeSession;

  useEffect(() => {
    if (displaySession?.station_id) {
      setStationId(String(displaySession.station_id));
      return;
    }
    if (!displaySession && preferredStationId) {
      setStationId(String(preferredStationId));
    }
  }, [displaySession, preferredStationId]);

  useEffect(() => {
    if (!displaySession?.start_time) {
      setSecondsElapsed(0);
      return undefined;
    }
    const updateElapsed = () => {
      const start = new Date(displaySession.start_time).getTime();
      setSecondsElapsed(Math.max(0, Math.floor((Date.now() - start) / 1000)));
    };
    updateElapsed();
    const timer = setInterval(updateElapsed, 1000);
    return () => clearInterval(timer);
  }, [displaySession]);

  useEffect(() => {
    if (!displaySession) {
      chargePulse.setValue(0);
      return undefined;
    }
    const loop = Animated.loop(
      Animated.sequence([
        Animated.timing(chargePulse, { toValue: 1, duration: 1100, easing: Easing.out(Easing.quad), useNativeDriver: false }),
        Animated.timing(chargePulse, { toValue: 0, duration: 1100, easing: Easing.in(Easing.quad), useNativeDriver: false }),
      ])
    );
    loop.start();
    return () => loop.stop();
  }, [displaySession, chargePulse]);

  const durationLabel = useMemo(() => {
    const h = Math.floor(secondsElapsed / 3600);
    const m = Math.floor((secondsElapsed % 3600) / 60);
    const s = secondsElapsed % 60;
    if (h > 0) return `${h}:${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
    return `${m}:${String(s).padStart(2, "0")}`;
  }, [secondsElapsed]);

  const handleSessionCompleted = useCallback((result) => {
    setLastResult(result);
    setStoppingSessionId(null);
    setStartAnotherPort(false);
    setSelectedStartConnectorId(null);
    void refreshActiveSession().catch(() => null);
    void loadChargingData("silent").catch(() => null);
  }, [loadChargingData, refreshActiveSession]);

  const handleDismissSummary = useCallback(() => {
    setLastResult(null);
    setStationId("");
    setStartAnotherPort(false);
    setSelectedStartConnectorId(null);
    setPreferredStationId?.(null);
  }, [setPreferredStationId]);

  useEffect(() => {
    if (!stoppingSessionId || lastResult) {
      return undefined;
    }

    const stillActive = (Array.isArray(activeSessions) ? activeSessions : [])
      .some((item) => String(item?.id) === String(stoppingSessionId))
      || String(activeSession?.id ?? "") === String(stoppingSessionId);
    if (stillActive) {
      return undefined;
    }

    let cancelled = false;
    apiClient.get(`/sessions/${stoppingSessionId}/live`)
      .then((response) => {
        if (cancelled) return;
        const data = response.data ?? {};
        const session = data.session_completed?.session ?? (data.session?.end_time ? data.session : null);
        if (session) {
          handleSessionCompleted({ session, invoice: data.session_completed?.invoice ?? null });
        } else {
          setStoppingSessionId(null);
        }
      })
      .catch(() => {
        if (!cancelled) setStoppingSessionId(null);
      });

    return () => {
      cancelled = true;
    };
  }, [stoppingSessionId, lastResult, activeSessions, activeSession, handleSessionCompleted]);

  const { liveStatus: sessionLiveStatus, canResume: liveCanResume, connectorStatus: liveConnectorStatus } = useSessionLivePoll({
    session: displaySession,
    setActiveSession,
    onSessionCompleted: handleSessionCompleted,
  });

  const liveStatus = displaySession
    ? sessionLiveStatus
    : sessionLiveStatus ?? selectedStation?.live_status;

  const connectorStatus = String(
    liveConnectorStatus
      || liveStatus?.connector_status
      || ""
  );
  const resumableConnector = useMemo(() => {
    const preferred = displaySession?.ocpp_connector_id
      ?? lastResult?.session?.ocpp_connector_id
      ?? null;
    return findResumableConnector(selectedStation, preferred);
  }, [selectedStation, displaySession, lastResult]);

  const showResume =
    Boolean(resolvedStationId)
    && (
      liveCanResume
      || ["SuspendedEV", "SuspendedEVSE", "Finishing"].includes(connectorStatus)
      || Boolean(resumableConnector)
    )
    && (Boolean(displaySession) || Boolean(lastResult) || Boolean(resumableConnector));

  const phase = canStartAnotherPort
    ? PHASE.READY
    : displaySession
      ? PHASE.CHARGING
      : lastResult
        ? PHASE.COMPLETE
        : resolvedStationId
          ? PHASE.READY
          : PHASE.IDLE;
  const isStopping = Boolean(
    stoppingSessionId && displaySession && String(displaySession.id) === String(stoppingSessionId)
  );
  const canStart = phase === PHASE.READY && (
    canStartAnotherPort
      ? Boolean(nextStartConnector)
      : stationCanStart(selectedStation)
        && Boolean(selectedStartConnectorId)
        && selectedPortCanStart(selectedPort)
  );
  const requiresPortSelection = phase === PHASE.READY
    && !canStartAnotherPort
    && portOptions.length > 0;
  const effectiveStartConnectorId = canStartAnotherPort
    ? nextStartConnector?.id
    : selectedStartConnectorId;
  const sessionTelemetry = displaySession?.telemetry ?? {};
  const chargeBudget = Number(displaySession?.charge_budget ?? sessionTelemetry.charge_budget ?? 0);
  const targetKwh = Number(displaySession?.target_kwh ?? sessionTelemetry.target_kwh ?? 0);
  const budgetSpent = Number(sessionTelemetry.budget_spent ?? 0);
  const kwhRemaining = sessionTelemetry.kwh_remaining;
  const powerKwLive = displaySession
    ? (sessionTelemetry.power_kw ?? null)
    : (sessionTelemetry.power_kw ?? liveStatus?.power_kw ?? null);
  const socPercent = displaySession
    ? (sessionTelemetry.soc_percent ?? null)
    : (sessionTelemetry.soc_percent ?? liveStatus?.soc_percent ?? null);

  const kwhConsumed = Number(sessionTelemetry.kwh_consumed ?? displaySession?.kwh_consumed ?? 0);
  const estimatedCost = (budgetSpent > 0 ? budgetSpent : kwhConsumed * pricePerKwh).toFixed(2);
  const kwhParts = kwhConsumed.toFixed(2).split(".");

  const handleQrCodeScanned = useCallback(
    async (rawCode) => {
      setResolvingQr(true);

      try {
        const response = await apiClient.post("/stations/resolve-qr", { code: rawCode });
        const nextStationId = response.data?.station_id;

        if (!nextStationId) {
          throw new Error("Codul scanat nu corespunde unei statii inregistrate.");
        }

        setStationId(String(nextStationId));
        setScannerOpen(false);

        const stationMatch = stations.find((item) => String(item.id) === String(nextStationId));
        const existingAtStation = activeSessionsAtStation(activeSessions, nextStationId);
        if (existingAtStation.length > 0 && findNextStartableConnector(stationMatch, existingAtStation)) {
          setStartAnotherPort(true);
        } else {
          setStartAnotherPort(false);
        }
      } catch (error) {
        const localMatch = resolveQrStationId(rawCode, stations);
        if (localMatch.stationId) {
          setStationId(localMatch.stationId);
          setScannerOpen(false);
          const stationMatch = stations.find((item) => String(item.id) === String(localMatch.stationId));
          const existingAtStation = activeSessionsAtStation(activeSessions, localMatch.stationId);
          if (existingAtStation.length > 0 && findNextStartableConnector(stationMatch, existingAtStation)) {
            setStartAnotherPort(true);
          } else {
            setStartAnotherPort(false);
          }
          return;
        }

        const message = getApiErrorMessage(error) || "Codul scanat nu corespunde unei statii inregistrate.";
        throw new Error(message);
      } finally {
        setResolvingQr(false);
      }
    },
    [stations, activeSessions]
  );

  const handleOpenScanner = useCallback(() => {
    setScannerOpen(true);
  }, []);

  useEffect(() => {
    if (displaySession || !resolvedStationId) {
      return undefined;
    }

    const timer = setInterval(() => {
      void loadChargingData("silent").catch(() => null);
    }, 5000);

    return () => clearInterval(timer);
  }, [displaySession, resolvedStationId, loadChargingData]);

  const startCharging = async (selection) => {
    // Sesiunile active de dinainte: la timeout acceptam ca „pornita” doar o sesiune noua.
    const knownSessionIds = new Set(
      (Array.isArray(activeSessions) ? activeSessions : []).map((item) => String(item?.id))
    );
    const requestedConnectorId = effectiveStartConnectorId;

    try {
      setLoading(true);
      const connectorId = requestedConnectorId;

      if (!connectorId && requiresPortSelection) {
        Alert.alert("Alege portul", portSelectionMessage(portOptions));
        return;
      }

      if (!canStartAnotherPort && !selectedPortCanStart(selectedPort)) {
        Alert.alert(
          "Port fara masina",
          selectedPort?.vehicle_connected
            ? "Portul selectat nu este disponibil pentru pornire."
            : "Selecteaza portul la care e conectata masina."
        );
        return;
      }

      const payload = buildChargingStartPayload(resolvedStationId, selection, connectorId);
      const response = await apiClient.post("/charging/start", payload);
      setActiveSession(response.data.session);
      setStartAnotherPort(false);
      setLastResult(null);
      setLimitModalOpen(false);
      void refreshActiveSession().catch(() => null);
      void loadChargingData("silent").catch(() => null);
    } catch (error) {
      const data = error?.response?.data;
      if (data?.requires_connector_selection && Array.isArray(data.connectors) && data.connectors.length > 1) {
        Alert.alert("Alege portul", data.message || "Selecteaza portul A sau B inainte de pornire.");
        return;
      }

      // Fara raspuns (timeout / retea): serverul poate sa fi pornit totusi sesiunea.
      // Verificam inainte sa spunem clientului ca pornirea a esuat.
      if (!error?.response?.status) {
        const startedSession = await findSessionStartedDespiteError(knownSessionIds, requestedConnectorId);
        if (startedSession) {
          setActiveSession(startedSession);
          setStartAnotherPort(false);
          setLastResult(null);
          setLimitModalOpen(false);
          void refreshActiveSession().catch(() => null);
          return;
        }
      }

      Alert.alert("Pornire esuata", getApiErrorMessage(error));
    } finally {
      setLoading(false);
    }
  };

  const findSessionStartedDespiteError = async (knownSessionIds, connectorId) => {
    try {
      let response;
      try {
        response = await apiClient.get("/sessions/active");
      } catch (error) {
        if (error?.response?.status !== 404) {
          return null;
        }
        response = await apiClient.get("/sessions");
      }
      const sessions = Array.isArray(response.data) ? response.data : [];

      return sessions.find((item) => !item?.end_time
        && !knownSessionIds.has(String(item?.id))
        && String(item?.station_id) === String(resolvedStationId)
        && (!connectorId || Number(item?.ocpp_connector_id) === Number(connectorId))) ?? null;
    } catch {
      return null;
    }
  };

  const handleStartPress = () => {
    if (usesWalletAccount(user?.account_type) && walletLoadFailed) {
      Alert.alert("Wallet indisponibil", "Nu am putut verifica soldul. Incearca din nou.");
      void loadChargingData("silent").catch(() => null);
      return;
    }

    if (requiresPrepaid) {
      setLimitModalOpen(true);
      return;
    }
    void startCharging(null);
  };

  const handleStop = async () => {
    try {
      setLoading(true);
      let nextStationId = resolvedStationId;
      if (!nextStationId) {
        const refreshedSession = await refreshActiveSession().catch(() => null);
        nextStationId = refreshedSession?.station_id ? String(refreshedSession.station_id) : "";
      }
      if (!nextStationId) {
        Alert.alert("Oprire esuata", "Sesiunea activa nu este sincronizata. Incearca din nou.");
        return;
      }
      const stopPayload = {
        station_id: Number(nextStationId),
      };

      if (displaySession?.ocpp_connector_id) {
        stopPayload.connector_id = Number(displaySession.ocpp_connector_id);
      } else if (displaySession?.id) {
        stopPayload.session_id = Number(displaySession.id);
      }

      const response = await apiClient.post("/charging/stop", stopPayload);
      if (response.data?.status === "stopping") {
        setLastResult(null);
        const stoppingSession = response.data.session ?? displaySession;
        if (response.data.session) {
          setActiveSession(response.data.session);
        }
        setStoppingSessionId(stoppingSession?.id ?? null);
        return;
      }

      setActiveSession(null);
      handleSessionCompleted({
        session: response.data?.session ?? displaySession,
        invoice: response.data?.invoice ?? null,
      });
    } catch (error) {
      Alert.alert("Oprire esuata", getApiErrorMessage(error));
    } finally {
      setLoading(false);
    }
  };

  // O atingere accidentala nu trebuie sa opreasca o incarcare in curs.
  const confirmStop = () => {
    Alert.alert("Oprire incarcare", "Sigur vrei sa opresti incarcarea?", [
      { text: "Anuleaza", style: "cancel" },
      { text: "Opreste", style: "destructive", onPress: () => void handleStop() },
    ]);
  };

  const handleUnlock = async () => {
    if (!resolvedStationId) {
      Alert.alert("Deblocare", "Nu am identificat statia activa.");
      return;
    }

    try {
      setLoading(true);
      const connectorId = displaySession?.ocpp_connector_id ?? 1;
      await apiClient.post(`/stations/${resolvedStationId}/unlock-connector`, {
        connector_id: Number(connectorId),
      });
      Alert.alert("Deblocare", "Comanda de deblocare a fost trimisa catre statie.");
    } catch (error) {
      Alert.alert("Deblocare esuata", getApiErrorMessage(error));
    } finally {
      setLoading(false);
    }
  };

  const handleResume = async () => {
    if (!resolvedStationId) {
      Alert.alert("Continua", "Nu am identificat statia.");
      return;
    }

    try {
      setLoading(true);
      const payload = {
        station_id: Number(resolvedStationId),
      };

      if (displaySession?.id) {
        payload.session_id = Number(displaySession.id);
      }
      const connectorId =
        displaySession?.ocpp_connector_id
        ?? resumableConnector?.id
        ?? lastResult?.session?.ocpp_connector_id;
      if (connectorId) {
        payload.connector_id = Number(connectorId);
      }

      const response = await apiClient.post("/charging/resume", payload);
      setActiveSession(response.data.session);
      setLastResult(null);
      setStartAnotherPort(false);
      void refreshActiveSession().catch(() => null);
      void loadChargingData("silent").catch(() => null);
      Alert.alert("Continua", response.data.message || "Repornire trimisa pe acelasi port.");
    } catch (error) {
      Alert.alert("Continua esuat", getApiErrorMessage(error));
    } finally {
      setLoading(false);
    }
  };

  const clearance = tabBarClearance(insets);
  const showActionBar = phase !== PHASE.IDLE && phase !== PHASE.COMPLETE;
  const bottomPad = showActionBar ? clearance + ACTION_BAR_HEIGHT + 24 : clearance + 20;

  if (!isAuthenticated) {
    return (
      <View style={[styles.page, { paddingHorizontal: 16, paddingTop: 8 }]}>
        <AuthGatePanel
          title="Autentifica-te pentru incarcare"
          body="Harta si statiile sunt disponibile fara cont. Scaneaza QR si porneste sesiunea dupa autentificare."
          icon="qrcode-scan"
        />
      </View>
    );
  }

  return (
    <View style={styles.page}>
      <ScrollView
        contentContainerStyle={[styles.scroll, { paddingBottom: bottomPad }]}
        showsVerticalScrollIndicator={false}
      >
        <ScreenHeader phase={phase} />

        {phase === PHASE.IDLE ? (
          <IdlePanel onScan={handleOpenScanner} />
        ) : phase === PHASE.COMPLETE ? (
          <SessionSummary
            lastResult={lastResult}
            station={selectedStation}
            currency={currency}
            pricePerKwh={pricePerKwh}
            freeCharging={freeCharging}
            onDone={handleDismissSummary}
            showResume={showResume}
            onResume={handleResume}
            resumeLoading={loading}
          />
        ) : (
          <SessionDashboard
            phase={phase}
            station={selectedStation}
            liveStatus={liveStatus}
            connectorStatus={connectorStatus}
            showResume={showResume}
            onResume={handleResume}
            resumeLoading={loading}
            durationLabel={durationLabel}
            kwhParts={kwhParts}
            estimatedCost={estimatedCost}
            pricePerKwh={pricePerKwh}
            priceChipLabel={priceChipLabel}
            currency={currency}
            chargePulse={chargePulse}
            chargeBudget={chargeBudget}
            targetKwh={targetKwh}
            budgetSpent={budgetSpent}
            kwhRemaining={kwhRemaining}
            powerKwLive={powerKwLive}
            socPercent={socPercent}
            nextStartConnector={nextStartConnector}
            startCandidates={startCandidates}
            portOptions={portOptions}
            requiresPortSelection={requiresPortSelection}
            selectedStartConnectorId={selectedStartConnectorId}
            onSelectStartConnector={setSelectedStartConnectorId}
            activeSessionsAtStationCount={sessionsAtStation.length}
            onStartAnotherPort={() => setStartAnotherPort(true)}
            onUnlock={phase === PHASE.CHARGING ? handleUnlock : undefined}
            unlockLoading={loading}
          />
        )}
      </ScrollView>

      {showActionBar ? (
        <FloatingActions
          clearance={clearance}
          phase={phase}
          loading={loading}
          stopping={isStopping}
          canStart={canStart}
          showResume={showResume && (phase === PHASE.CHARGING || phase === PHASE.READY)}
          resolvedStationId={resolvedStationId}
          canStartAnotherPort={canStartAnotherPort}
          nextStartConnector={nextStartConnector}
          selectedStartConnectorId={selectedStartConnectorId}
          selectedPort={selectedPort}
          startCandidates={startCandidates}
          portOptions={portOptions}
          onScan={handleOpenScanner}
          onStart={handleStartPress}
          onResume={handleResume}
          onStop={confirmStop}
          onCancelSecondPort={() => setStartAnotherPort(false)}
        />
      ) : null}

      <ChargeLimitModal
        visible={limitModalOpen}
        wallet={wallet}
        pricePerKwh={pricePerKwh}
        currency={currency}
        loading={loading}
        onClose={() => setLimitModalOpen(false)}
        onConfirm={(selection) => {
          void startCharging(selection);
        }}
      />

      <StationQrScannerModal
        onClose={() => setScannerOpen(false)}
        onCodeScanned={handleQrCodeScanned}
        resolving={resolvingQr}
        visible={scannerOpen}
      />
    </View>
  );
}

function ScreenHeader({ phase }) {
  const meta = {
    [PHASE.IDLE]: { title: "Conecteaza statia", sub: "Scaneaza codul QR de pe statie", icon: "qrcode-scan", tone: "idle" },
    [PHASE.READY]: { title: "Gata de pornire", sub: "Verifica mufa si apasa Pornire", icon: "ev-station", tone: "ready" },
    [PHASE.CHARGING]: { title: "Sesiune activa", sub: "Energie livrata in timp real", icon: "lightning-bolt", tone: "live" },
    [PHASE.COMPLETE]: { title: "Incarcare finalizata", sub: "Rezumatul sesiunii tale", icon: "check-circle", tone: "ready" },
  }[phase];

  return (
    <View style={styles.header}>
      <View style={styles.headerMain}>
        <Text style={styles.headerEyebrow}>V CHARGE</Text>
        <Text style={styles.headerTitle}>{meta.title}</Text>
        <Text style={styles.headerSub}>{meta.sub}</Text>
      </View>
      <View style={[styles.headerBadge, styles[`headerBadge_${meta.tone}`]]}>
        <MaterialCommunityIcons
          name={meta.icon}
          size={18}
          color={meta.tone === "idle" ? C.text : C.accent}
        />
      </View>
    </View>
  );
}

function IdlePanel({ onScan }) {
  return (
    <View style={styles.glassCard}>
      <View style={styles.idleHero}>
        <View style={styles.idleIconRing}>
          <MaterialCommunityIcons name="qrcode-scan" size={40} color={C.onAccent} />
        </View>
        <View style={styles.idleCopy}>
          <Text style={styles.idleTitle}>Scaneaza statia</Text>
          <Text style={styles.idleSub}>Foloseste camera pentru codul QR de pe statie.</Text>
        </View>
      </View>

      <View style={styles.flowTrack}>
        <FlowStep n={1} label="Statie" desc="Selectare" active />
        <FlowStep n={2} label="Pornire" desc="Sesiune" />
        <FlowStep n={3} label="Incarcare" desc="Energie" />
      </View>

      <Pressable style={({ pressed }) => [styles.primaryBtn, pressed && styles.btnPressed]} onPress={onScan}>
        <MaterialCommunityIcons name="qrcode-scan" size={20} color={C.onAccent} />
        <Text style={styles.primaryBtnText}>Scaneaza QR</Text>
      </Pressable>
    </View>
  );
}

function SessionDashboard({
  phase,
  station,
  liveStatus,
  connectorStatus,
  showResume,
  onResume,
  resumeLoading,
  durationLabel,
  kwhParts,
  estimatedCost,
  pricePerKwh,
  priceChipLabel,
  currency,
  chargePulse,
  chargeBudget,
  targetKwh,
  budgetSpent,
  kwhRemaining,
  powerKwLive,
  socPercent,
  nextStartConnector,
  startCandidates,
  portOptions = [],
  requiresPortSelection,
  selectedStartConnectorId,
  onSelectStartConnector,
  activeSessionsAtStationCount,
  onStartAnotherPort,
  onUnlock,
  unlockLoading,
}) {
  const isCharging = phase === PHASE.CHARGING;
  const availability = availabilityLabel(effectiveStationAvailability(station));
  const ocppStatus = effectiveOcppConnection(station);
  const rawConnectorStatus = connectorStatus || liveStatus?.connector_status || "";
  const statusLabel = connectorStatusLabel(rawConnectorStatus);
  const pauseHint = connectorStatusHint(rawConnectorStatus);
  const barWidth = chargePulse.interpolate({
    inputRange: [0, 1],
    outputRange: ["8%", "88%"],
  });
  const ports = portOptions.length > 0 ? portOptions : startCandidates;

  return (
    <View style={styles.dashboard}>
      <View style={styles.stationStrip}>
        <View style={styles.stationStripIcon}>
          <MaterialCommunityIcons name="map-marker-radius" size={20} color={C.onAccent} />
        </View>
        <View style={{ flex: 1 }}>
          <Text style={styles.stationStripName}>{station?.name || "Statie"}</Text>
          <Text style={styles.stationStripLoc} numberOfLines={1}>
            {station?.location || "—"}
          </Text>
        </View>
        <Pill label={isCharging ? "ACTIV" : availability} />
      </View>

      <View style={styles.chipRow}>
        <Chip icon="flash" text={`${station?.power_kw || "—"} kW`} />
        <Chip icon="ev-plug-type2" text={station?.connector_type || "—"} />
        <Chip icon="cash" text={priceChipLabel || `${pricePerKwh.toFixed(2)} ${currency}/kWh`} />
      </View>

      {showResume ? (
        <View style={styles.resumeBanner}>
          <MaterialCommunityIcons name="pause-circle-outline" size={22} color={C.accent} />
          <View style={{ flex: 1 }}>
            <Text style={styles.resumeTitle}>Incarcare in pauza</Text>
            <Text style={styles.resumeBody}>
              {pauseHint ?? "Incarcarea este oprita momentan."} Apasa Continua ca sa repornesti pe acelasi port.
            </Text>
          </View>
          <Pressable
            disabled={resumeLoading}
            onPress={onResume}
            style={({ pressed }) => [styles.resumeBtn, resumeLoading && styles.btnDisabled, pressed && styles.btnPressed]}
          >
            {resumeLoading ? (
              <ActivityIndicator color={C.onAccent} />
            ) : (
              <Text style={styles.resumeBtnText}>Continua</Text>
            )}
          </Pressable>
        </View>
      ) : null}

      {isCharging ? (
        <View style={styles.energyCard}>
          <View style={styles.energyCardHead}>
            <View style={styles.liveDot} />
            <Text style={styles.energyCardLabel}>Energie livrata</Text>
          </View>

          <View style={styles.kwhDisplay}>
            <Text style={styles.kwhInt}>{kwhParts[0]}</Text>
            <Text style={styles.kwhDec}>.{kwhParts[1]}</Text>
            <Text style={styles.kwhUnit}>kWh</Text>
          </View>

          <View style={styles.flowBarTrack}>
            <Animated.View style={[styles.flowBarFill, { width: barWidth }]} />
          </View>

          <View style={styles.bentoRow}>
            <BentoCell icon="cash-multiple" label="Cost estimat" value={estimatedCost} suffix={currency} accent />
            <BentoCell icon="clock-outline" label="Durata" value={durationLabel} />
          </View>
          <View style={styles.bentoRow}>
            <BentoCell
              icon="flash"
              label="Putere live"
              value={powerKwLive != null ? Number(powerKwLive).toFixed(2) : "—"}
              suffix="kW"
            />
            <BentoCell
              icon="battery-charging"
              label="Baterie"
              value={socPercent != null ? String(Math.round(Number(socPercent))) : "—"}
              suffix={socPercent != null ? "%" : ""}
            />
          </View>
          <View style={styles.bentoRow}>
            <BentoCell icon="transmission-tower" label="OCPP" value={connectionShort(ocppStatus)} />
            <BentoCell
              icon="ev-station"
              label="Conector"
              value={statusLabel}
            />
          </View>
          {chargeBudget > 0 ? (
            <View style={styles.limitCard}>
              <Text style={styles.limitTitle}>
                {targetKwh > 0
                  ? `Limita ${targetKwh.toFixed(1)} kWh`
                  : `Buget ${chargeBudget.toFixed(2)} ${currency}`}
              </Text>
              <Text style={styles.limitMeta}>
                Consumat {budgetSpent.toFixed(2)} {currency}
                {targetKwh > 0 && kwhRemaining != null ? ` · Ramas ${Number(kwhRemaining).toFixed(2)} kWh` : ""}
              </Text>
            </View>
          ) : null}

          {onUnlock ? (
            <Pressable
              disabled={unlockLoading}
              onPress={onUnlock}
              style={({ pressed }) => [styles.unlockRow, unlockLoading && styles.btnDisabled, pressed && styles.btnPressed]}
            >
              <MaterialCommunityIcons name="lock-open-variant-outline" size={16} color={C.accent} />
              <Text style={styles.unlockText}>
                {unlockLoading ? "Se trimite..." : "Deblocheaza mufa (UnlockConnector)"}
              </Text>
            </Pressable>
          ) : null}

          {nextStartConnector && activeSessionsAtStationCount > 0 && onStartAnotherPort ? (
            <Pressable
              onPress={onStartAnotherPort}
              style={({ pressed }) => [styles.secondPortBtn, pressed && styles.btnPressed]}
            >
              <MaterialCommunityIcons name="ev-plug-type2" size={18} color={C.accent} />
              <Text style={styles.secondPortBtnText}>
                Porneste pe {nextStartConnector.label || `portul ${nextStartConnector.id}`}
              </Text>
            </Pressable>
          ) : null}
        </View>
      ) : (
        <View style={styles.readyPanel}>
          <View style={styles.readyIconWrap}>
            <MaterialCommunityIcons name="power-plug" size={32} color={C.accent} />
          </View>
          <Text style={styles.readyHeadline}>
            {ports.length > 0 ? "Alege portul tau" : "Conecteaza vehiculul"}
          </Text>
          <Text style={styles.readyBody}>
            {ports.length > 0
              ? portSelectionMessage(ports)
              : "Statia e selectata. Apasa Refresh sau asteapta statusul OCPP."}
          </Text>

          {ports.length > 0 ? (
            <View style={styles.portPickerRow}>
              {ports.map((connector) => {
                const selected = Number(selectedStartConnectorId) === Number(connector.id);
                const plugged = Boolean(connector.vehicle_connected);
                const blocked = Boolean(connector.occupied_by_other_user || connector.my_active_session);
                return (
                  <Pressable
                    key={connector.id}
                    disabled={blocked}
                    onPress={() => onSelectStartConnector(Number(connector.id))}
                    style={({ pressed }) => [
                      styles.portPickerBtn,
                      plugged && styles.portPickerBtnPlugged,
                      selected && styles.portPickerBtnSelected,
                      blocked && styles.portPickerBtnBlocked,
                      pressed && !blocked && styles.btnPressed,
                    ]}
                  >
                    <MaterialCommunityIcons
                      name={plugged ? "car-electric" : "ev-plug-type2"}
                      size={22}
                      color={selected ? C.onAccent : plugged ? C.accent : C.textMuted}
                    />
                    <Text
                      style={[
                        styles.portPickerLabel,
                        plugged && styles.portPickerLabelPlugged,
                        selected && styles.portPickerLabelSelected,
                        blocked && styles.portPickerLabelBlocked,
                      ]}
                    >
                      Port {connector.label || connector.id}
                    </Text>
                    <Text
                      style={[
                        styles.portPickerMeta,
                        selected && styles.portPickerMetaSelected,
                      ]}
                    >
                      {blocked
                        ? "Ocupat"
                        : plugged
                          ? "Masina conectata"
                          : "Fara masina"}
                    </Text>
                  </Pressable>
                );
              })}
            </View>
          ) : null}

          <View style={styles.readyHintRow}>
            <MaterialCommunityIcons name="information-outline" size={14} color={C.text} style={styles.mutedIcon} />
            <Text style={styles.readyHint}>{connectionLabel(ocppStatus)}</Text>
          </View>
        </View>
      )}
    </View>
  );
}

function FloatingActions({
  clearance,
  phase,
  loading,
  stopping = false,
  canStart,
  showResume,
  resolvedStationId,
  canStartAnotherPort,
  nextStartConnector,
  selectedStartConnectorId,
  selectedPort,
  startCandidates,
  portOptions = [],
  onScan,
  onStart,
  onResume,
  onStop,
  onCancelSecondPort,
}) {
  const isCharging = phase === PHASE.CHARGING;
  const ports = portOptions.length > 0 ? portOptions : startCandidates;
  const selectedCandidate = selectedPort
    ?? ports.find((item) => Number(item.id) === Number(selectedStartConnectorId));
  const startLabel = canStartAnotherPort
    ? (nextStartConnector?.label ? `Porneste ${nextStartConnector.label}` : "Porneste incarcarea")
    : selectedCandidate?.label
      ? (
        selectedPortCanStart(selectedCandidate)
          ? `Porneste port ${selectedCandidate.label}`
          : selectedCandidate.vehicle_connected
            ? `Port ${selectedCandidate.label} indisponibil`
            : `Port ${selectedCandidate.label} — fara masina`
      )
      : "Alege un port";

  return (
    <View style={[styles.floatingWrap, { bottom: clearance }]}>
      <View style={styles.floatingBar}>
        {isCharging ? (
          <>
            <Pressable style={({ pressed }) => [styles.floatingSecondary, pressed && styles.btnPressed]} onPress={onScan}>
              <MaterialCommunityIcons name="qrcode-scan" size={20} color={C.text} />
            </Pressable>
            {showResume && !stopping ? (
              <Pressable
                style={({ pressed }) => [styles.floatingResume, loading && styles.btnDisabled, pressed && styles.btnPressed]}
                onPress={onResume}
                disabled={loading || !resolvedStationId}
              >
                {loading ? (
                  <ActivityIndicator color={C.onAccent} />
                ) : (
                  <>
                    <MaterialCommunityIcons name="play" size={20} color={C.onAccent} />
                    <Text style={styles.floatingResumeText}>Continua</Text>
                  </>
                )}
              </Pressable>
            ) : null}
            <Pressable
              style={({ pressed }) => [styles.floatingStop, (loading || stopping || !resolvedStationId) && styles.btnDisabled, pressed && styles.btnPressed]}
              onPress={onStop}
              disabled={loading || stopping || !resolvedStationId}
            >
              {stopping ? (
                <>
                  <ActivityIndicator color={C.onAccent} />
                  <Text style={styles.floatingStopText}>Se opreste...</Text>
                </>
              ) : loading ? (
                <ActivityIndicator color={C.onAccent} />
              ) : (
                <>
                  <View style={styles.floatingStopIcon}>
                    <MaterialCommunityIcons name="stop" size={18} color={C.onAccent} />
                  </View>
                  <Text style={styles.floatingStopText}>Opreste</Text>
                </>
              )}
            </Pressable>
          </>
        ) : canStartAnotherPort ? (
          <>
            <Pressable style={({ pressed }) => [styles.floatingSecondary, pressed && styles.btnPressed]} onPress={onCancelSecondPort}>
              <MaterialCommunityIcons name="close" size={20} color={C.text} />
            </Pressable>
            <Pressable
              style={({ pressed }) => [styles.floatingPrimary, (!canStart || loading) && styles.btnDisabled, pressed && styles.btnPressed]}
              onPress={onStart}
              disabled={!canStart || loading}
            >
              {loading ? (
                <ActivityIndicator color={C.onAccent} />
              ) : (
                <>
                  <MaterialCommunityIcons name="lightning-bolt" size={22} color={C.onAccent} />
                  <Text style={styles.floatingPrimaryText}>{startLabel}</Text>
                </>
              )}
            </Pressable>
          </>
        ) : (
          <>
            <Pressable style={({ pressed }) => [styles.floatingSecondary, pressed && styles.btnPressed]} onPress={onScan}>
              <MaterialCommunityIcons name="qrcode-scan" size={20} color={C.text} />
            </Pressable>
            {showResume ? (
              <Pressable
                style={({ pressed }) => [styles.floatingResume, loading && styles.btnDisabled, pressed && styles.btnPressed]}
                onPress={onResume}
                disabled={loading || !resolvedStationId}
              >
                {loading ? (
                  <ActivityIndicator color={C.onAccent} />
                ) : (
                  <>
                    <MaterialCommunityIcons name="play" size={20} color={C.onAccent} />
                    <Text style={styles.floatingResumeText}>Continua</Text>
                  </>
                )}
              </Pressable>
            ) : (
              <Pressable
                style={({ pressed }) => [styles.floatingPrimary, (!canStart || loading) && styles.btnDisabled, pressed && styles.btnPressed]}
                onPress={onStart}
                disabled={!canStart || loading}
              >
                {loading ? (
                  <ActivityIndicator color={C.onAccent} />
                ) : (
                  <>
                    <MaterialCommunityIcons name="lightning-bolt" size={22} color={C.onAccent} />
                    <Text style={styles.floatingPrimaryText}>{startLabel}</Text>
                  </>
                )}
              </Pressable>
            )}
          </>
        )}
      </View>
    </View>
  );
}

function formatSessionDuration(startTime, endTime) {
  const start = startTime ? new Date(startTime).getTime() : NaN;
  const end = endTime ? new Date(endTime).getTime() : Date.now();
  if (!Number.isFinite(start) || !Number.isFinite(end)) return "—";
  const totalSeconds = Math.max(0, Math.floor((end - start) / 1000));
  const h = Math.floor(totalSeconds / 3600);
  const m = Math.floor((totalSeconds % 3600) / 60);
  const s = totalSeconds % 60;
  if (h > 0) return `${h}h ${String(m).padStart(2, "0")}m`;
  if (m > 0) return `${m}m ${String(s).padStart(2, "0")}s`;
  return `${s}s`;
}

function SessionSummary({
  lastResult,
  station,
  currency,
  pricePerKwh,
  freeCharging,
  onDone,
  showResume,
  onResume,
  resumeLoading,
}) {
  const [downloading, setDownloading] = useState(false);
  const session = lastResult?.session ?? {};
  const telemetry = session.telemetry ?? {};
  const kwhValue = Number(session.kwh_consumed ?? session.kwh_delivered ?? telemetry.kwh_consumed ?? 0);
  const invoice = lastResult?.invoice ?? session.invoice;
  const sessionPrice = Number(telemetry.price_per_kwh ?? pricePerKwh ?? 0);
  const amountSpent = Number(
    session.billing?.amount_spent
      ?? invoice?.total_amount
      ?? telemetry.budget_spent
      ?? kwhValue * sessionPrice
  );
  const summaryCurrency = session.billing?.currency ?? invoice?.currency ?? currency;
  const costLabel = freeCharging ? "Gratuit" : `${amountSpent.toFixed(2)} ${summaryCurrency}`;
  const durationValue = formatSessionDuration(session.start_time, session.end_time);
  const stationName = session.station?.name ?? station?.name ?? "Statie";
  const portLabel = telemetry.connector_label
    ?? (session.ocpp_connector_id ? `Port ${session.ocpp_connector_id}` : null);

  const handleDownload = async () => {
    if (!invoice?.id) {
      return;
    }

    try {
      setDownloading(true);
      await downloadInvoiceFile(apiClient, invoice, { Sharing, File, Paths, Alert, getApiErrorMessage });
    } catch (error) {
      Alert.alert("Factura", getApiErrorMessage(error));
    } finally {
      setDownloading(false);
    }
  };

  return (
    <View style={styles.summaryWrap}>
      <View style={[styles.glassCard, styles.summaryCard]}>
        <View style={styles.summaryHero}>
          <View style={styles.summaryIconRing}>
            <MaterialCommunityIcons name="check-bold" size={34} color={C.onAccent} />
          </View>
          <Text style={styles.summaryTitle}>Sesiune incheiata</Text>
          <Text style={styles.summarySub} numberOfLines={1}>
            {portLabel ? `${stationName} · ${portLabel}` : stationName}
          </Text>
        </View>

        <View style={styles.summaryStats}>
          <View style={[styles.summaryStat, styles.summaryStatAccent]}>
            <MaterialCommunityIcons name="lightning-bolt" size={18} color={C.accent} />
            <Text style={styles.summaryStatValue}>
              {kwhValue.toFixed(2)}
              <Text style={styles.summaryStatUnit}> kWh</Text>
            </Text>
            <Text style={styles.summaryStatLabel}>Energie incarcata</Text>
          </View>
          <View style={styles.summaryStat}>
            <MaterialCommunityIcons name="cash" size={18} color={C.text} style={styles.mutedIcon} />
            <Text style={styles.summaryStatValue} numberOfLines={1} adjustsFontSizeToFit>
              {costLabel}
            </Text>
            <Text style={styles.summaryStatLabel}>Cost</Text>
          </View>
          <View style={styles.summaryStat}>
            <MaterialCommunityIcons name="timer-outline" size={18} color={C.text} style={styles.mutedIcon} />
            <Text style={styles.summaryStatValue} numberOfLines={1} adjustsFontSizeToFit>
              {durationValue}
            </Text>
            <Text style={styles.summaryStatLabel}>Durata</Text>
          </View>
        </View>

        {invoice?.id ? (
          <Pressable
            disabled={downloading}
            onPress={handleDownload}
            style={({ pressed }) => [styles.summaryInvoice, pressed && styles.btnPressed]}
          >
            <MaterialCommunityIcons name="file-document-outline" size={18} color={C.accent} />
            <Text style={styles.summaryInvoiceText} numberOfLines={1}>
              {invoice.invoice_number ? `Factura ${invoice.invoice_number}` : "Descarca factura"}
            </Text>
            {downloading ? (
              <ActivityIndicator color={C.accent} />
            ) : (
              <MaterialCommunityIcons name="download-outline" size={18} color={C.accent} />
            )}
          </Pressable>
        ) : null}
      </View>

      {showResume ? (
        <Pressable
          disabled={resumeLoading}
          onPress={onResume}
          style={({ pressed }) => [styles.summarySecondaryBtn, resumeLoading && styles.btnDisabled, pressed && styles.btnPressed]}
        >
          {resumeLoading ? (
            <ActivityIndicator color={C.accent} />
          ) : (
            <>
              <MaterialCommunityIcons name="play" size={18} color={C.accent} />
              <Text style={styles.summarySecondaryText}>Continua pe acelasi port</Text>
            </>
          )}
        </Pressable>
      ) : null}

      <Pressable onPress={onDone} style={({ pressed }) => [styles.primaryBtn, pressed && styles.btnPressed]}>
        <MaterialCommunityIcons name="check" size={20} color={C.onAccent} />
        <Text style={styles.primaryBtnText}>Gata</Text>
      </Pressable>
    </View>
  );
}

function FlowStep({ n, label, desc, active }) {
  return (
    <View style={[styles.flowStep, active && styles.flowStepActive]}>
      <View style={[styles.flowNum, active && styles.flowNumActive]}>
        <Text style={[styles.flowNumText, active && styles.flowNumTextActive]}>{n}</Text>
      </View>
      <Text style={[styles.flowLabel, active && styles.flowLabelActive]}>{label}</Text>
      <Text style={styles.flowDesc}>{desc}</Text>
    </View>
  );
}

function Pill({ label }) {
  return (
    <View style={styles.pill}>
      <Text style={styles.pillText}>{label}</Text>
    </View>
  );
}

function Chip({ icon, text }) {
  return (
    <View style={styles.chip}>
      <MaterialCommunityIcons name={icon} size={14} color={C.accent} />
      <Text style={styles.chipText} numberOfLines={1}>
        {text}
      </Text>
    </View>
  );
}

function BentoCell({ icon, label, value, suffix, accent }) {
  return (
    <View style={[styles.bento, accent && styles.bentoAccent]}>
      <MaterialCommunityIcons name={icon} size={16} color={accent ? C.accent : C.text} style={accent ? null : styles.mutedIcon} />
      <Text style={styles.bentoLabel}>{label}</Text>
      <Text style={[styles.bentoValue, accent && styles.bentoValueAccent]}>
        {value}
        {suffix ? <Text style={styles.bentoSuffix}> {suffix}</Text> : null}
      </Text>
    </View>
  );
}

function connectionShort(status) {
  if (status === "connected") return "Online";
  if (status === "disconnected") return "Offline";
  if (status === "not_configured") return "Simulare";
  return "—";
}

const styles = StyleSheet.create({
  page: { flex: 1, backgroundColor: C.bg },
  scroll: { flexGrow: 1, paddingHorizontal: 16, paddingTop: 6, gap: 14 },
  mutedIcon: { opacity: 0.55 },

  header: {
    flexDirection: "row",
    alignItems: "flex-start",
    justifyContent: "space-between",
    gap: 12,
    marginBottom: 2,
  },
  headerMain: { flex: 1 },
  headerEyebrow: {
    color: C.accent,
    fontSize: 10,
    fontWeight: "900",
    letterSpacing: 1.6,
  },
  headerTitle: {
    color: C.text,
    fontSize: 26,
    fontWeight: "900",
    marginTop: 4,
    letterSpacing: -0.5,
  },
  headerSub: {
    color: C.textMuted,
    fontSize: 13,
    fontWeight: "600",
    marginTop: 4,
    lineHeight: 18,
  },
  headerBadge: {
    width: 44,
    height: 44,
    borderRadius: 14,
    borderWidth: 1,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: C.card,
    borderColor: C.borderSoft,
  },
  headerBadge_idle: { borderColor: C.border },
  headerBadge_ready: { borderColor: C.borderAccent, backgroundColor: C.accentSoft },
  headerBadge_live: { borderColor: C.borderAccent, backgroundColor: C.accentSoft },

  glassCard: {
    borderRadius: 22,
    borderWidth: 1,
    borderColor: C.borderSoft,
    backgroundColor: C.card,
    padding: 18,
    gap: 16,
  },
  idleHero: { flexDirection: "row", alignItems: "center", gap: 14 },
  idleIconRing: {
    width: 72,
    height: 72,
    borderRadius: 22,
    backgroundColor: C.accent,
    alignItems: "center",
    justifyContent: "center",
  },
  idleCopy: { flex: 1 },
  idleTitle: { color: C.text, fontSize: 18, fontWeight: "900" },
  idleSub: { color: C.textMuted, fontSize: 13, fontWeight: "600", marginTop: 4, lineHeight: 18 },

  flowTrack: {
    flexDirection: "row",
    justifyContent: "space-between",
    paddingVertical: 4,
  },
  flowStep: {
    flex: 1,
    alignItems: "center",
    gap: 4,
    opacity: 0.55,
  },
  flowStepActive: { opacity: 1 },
  flowNum: {
    width: 28,
    height: 28,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: C.border,
    alignItems: "center",
    justifyContent: "center",
  },
  flowNumActive: {
    backgroundColor: C.accent,
    borderColor: C.accent,
  },
  flowNumText: { color: C.textMuted, fontSize: 12, fontWeight: "900" },
  flowNumTextActive: { color: C.onAccent },
  flowLabel: { color: C.textMuted, fontSize: 11, fontWeight: "800" },
  flowLabelActive: { color: C.text },
  flowDesc: { color: C.textMuted, fontSize: 9, fontWeight: "600", opacity: 0.8 },

  primaryBtn: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 8,
    backgroundColor: C.accent,
    borderRadius: 16,
    minHeight: 54,
  },
  primaryBtnText: { color: C.onAccent, fontSize: 15, fontWeight: "900" },

  dashboard: { gap: 12 },
  stationStrip: {
    flexDirection: "row",
    alignItems: "center",
    gap: 12,
    padding: 14,
    borderRadius: 20,
    backgroundColor: C.card,
    borderWidth: 1,
    borderColor: C.borderSoft,
  },
  stationStripIcon: {
    width: 42,
    height: 42,
    borderRadius: 13,
    backgroundColor: C.accent,
    alignItems: "center",
    justifyContent: "center",
  },
  stationStripName: { color: C.text, fontSize: 17, fontWeight: "900" },
  stationStripLoc: { color: C.textMuted, fontSize: 12, fontWeight: "600", marginTop: 2 },
  pill: {
    borderRadius: 999,
    borderWidth: 1,
    borderColor: C.borderAccent,
    backgroundColor: C.accentSoft,
    paddingHorizontal: 10,
    paddingVertical: 5,
  },
  pillText: { fontSize: 10, fontWeight: "900", letterSpacing: 0.5, color: C.accent },

  chipRow: { flexDirection: "row", flexWrap: "wrap", gap: 8 },
  chip: {
    flexDirection: "row",
    alignItems: "center",
    gap: 6,
    paddingHorizontal: 12,
    paddingVertical: 8,
    borderRadius: 999,
    backgroundColor: C.bgSoft,
    borderWidth: 1,
    borderColor: C.borderSoft,
  },
  chipText: { color: C.text, fontSize: 12, fontWeight: "700" },

  energyCard: {
    borderRadius: 22,
    padding: 18,
    gap: 14,
    backgroundColor: C.card,
    borderWidth: 1,
    borderColor: C.borderAccent,
    overflow: "hidden",
  },
  energyCardHead: { flexDirection: "row", alignItems: "center", gap: 8 },
  liveDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    backgroundColor: C.accent,
  },
  energyCardLabel: {
    color: C.textMuted,
    fontSize: 11,
    fontWeight: "800",
    letterSpacing: 1,
    textTransform: "uppercase",
  },
  kwhDisplay: {
    flexDirection: "row",
    alignItems: "flex-end",
    justifyContent: "center",
    gap: 2,
  },
  kwhInt: {
    color: C.text,
    fontSize: 64,
    fontWeight: "900",
    fontVariant: ["tabular-nums"],
    letterSpacing: -2,
    lineHeight: 68,
  },
  kwhDec: {
    color: C.accent,
    fontSize: 32,
    fontWeight: "900",
    marginBottom: 10,
    fontVariant: ["tabular-nums"],
  },
  kwhUnit: {
    color: C.textMuted,
    fontSize: 16,
    fontWeight: "800",
    marginBottom: 14,
    marginLeft: 4,
  },
  flowBarTrack: {
    height: 6,
    borderRadius: 999,
    backgroundColor: C.bgSoft,
    borderWidth: 1,
    borderColor: C.borderSoft,
    overflow: "hidden",
  },
  flowBarFill: {
    height: "100%",
    borderRadius: 999,
    backgroundColor: C.accent,
    minWidth: 8,
  },
  bentoRow: { flexDirection: "row", gap: 8 },
  unlockRow: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 8,
    marginTop: 4,
    paddingVertical: 12,
    paddingHorizontal: 14,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: C.borderAccent,
    backgroundColor: C.accentSoft,
  },
  unlockText: {
    color: C.accent,
    fontSize: 12,
    fontWeight: "800",
  },
  secondPortBtn: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 8,
    marginTop: 8,
    paddingVertical: 12,
    paddingHorizontal: 14,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: C.borderAccent,
    backgroundColor: C.card,
  },
  secondPortBtnText: {
    color: C.accent,
    fontSize: 13,
    fontWeight: "800",
  },
  bento: {
    flex: 1,
    borderRadius: 16,
    padding: 12,
    gap: 4,
    backgroundColor: C.bgSoft,
    borderWidth: 1,
    borderColor: C.borderSoft,
  },
  bentoAccent: {},
  bentoLabel: {
    color: C.textMuted,
    fontSize: 10,
    fontWeight: "700",
    textTransform: "uppercase",
    letterSpacing: 0.4,
    marginTop: 2,
  },
  bentoValue: {
    color: C.text,
    fontSize: 20,
    fontWeight: "900",
    fontVariant: ["tabular-nums"],
  },
  bentoValueAccent: { color: C.accent },
  bentoSuffix: { fontSize: 13, fontWeight: "700", color: C.textMuted },
  limitCard: {
    marginTop: 10,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: "rgba(255,238,0,0.22)",
    backgroundColor: "rgba(255,238,0,0.08)",
    paddingHorizontal: 12,
    paddingVertical: 10,
    gap: 4,
  },
  limitTitle: { color: C.accent, fontSize: 13, fontWeight: "900" },
  limitMeta: { color: C.text, fontSize: 12, fontWeight: "700", opacity: 0.82 },

  readyPanel: {
    alignItems: "center",
    padding: 28,
    borderRadius: 22,
    borderWidth: 1,
    borderColor: C.borderSoft,
    backgroundColor: C.card,
    gap: 10,
  },
  readyIconWrap: {
    width: 64,
    height: 64,
    borderRadius: 32,
    borderWidth: 1,
    borderColor: C.borderAccent,
    backgroundColor: C.accentSoft,
    alignItems: "center",
    justifyContent: "center",
    marginBottom: 4,
  },
  readyHeadline: { color: C.text, fontSize: 20, fontWeight: "900" },
  readyBody: {
    color: C.textMuted,
    fontSize: 14,
    fontWeight: "600",
    textAlign: "center",
    lineHeight: 20,
    paddingHorizontal: 8,
  },
  readyHintRow: { flexDirection: "row", alignItems: "center", gap: 6, marginTop: 4 },
  readyHint: { color: C.textMuted, fontSize: 12, fontWeight: "600" },
  portPickerRow: {
    flexDirection: "row",
    gap: 10,
    marginTop: 8,
    width: "100%",
  },
  portPickerBtn: {
    flex: 1,
    alignItems: "center",
    justifyContent: "center",
    gap: 6,
    paddingVertical: 14,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: C.borderAccent,
    backgroundColor: C.bgSoft,
  },
  portPickerBtnPlugged: {
    borderColor: C.accent,
    backgroundColor: C.accentSoft,
    borderWidth: 2,
  },
  portPickerBtnSelected: {
    backgroundColor: C.accent,
    borderColor: C.accent,
  },
  portPickerBtnBlocked: {
    opacity: 0.45,
  },
  portPickerLabel: {
    color: C.accent,
    fontSize: 14,
    fontWeight: "900",
  },
  portPickerLabelPlugged: {
    color: C.accent,
  },
  portPickerLabelSelected: {
    color: C.onAccent,
  },
  portPickerLabelBlocked: {
    color: C.textMuted,
  },
  portPickerMeta: {
    color: C.textMuted,
    fontSize: 11,
    fontWeight: "700",
  },
  portPickerMetaSelected: {
    color: C.onAccent,
    opacity: 0.9,
  },

  summaryWrap: { gap: 12 },
  summaryCard: { borderColor: C.borderAccent, gap: 18 },
  summaryHero: { alignItems: "center", gap: 6, paddingTop: 6 },
  summaryIconRing: {
    width: 72,
    height: 72,
    borderRadius: 36,
    backgroundColor: C.accent,
    alignItems: "center",
    justifyContent: "center",
    marginBottom: 6,
  },
  summaryTitle: { color: C.text, fontSize: 22, fontWeight: "900", letterSpacing: -0.3 },
  summarySub: { color: C.textMuted, fontSize: 13, fontWeight: "700" },
  summaryStats: { flexDirection: "row", gap: 10 },
  summaryStat: {
    flex: 1,
    minWidth: 0,
    gap: 6,
    padding: 12,
    borderRadius: 16,
    borderWidth: 1,
    borderColor: C.borderSoft,
    backgroundColor: C.bgSoft,
  },
  summaryStatAccent: { borderColor: C.borderAccent, backgroundColor: C.accentSoft },
  summaryStatValue: { color: C.text, fontSize: 18, fontWeight: "900" },
  summaryStatUnit: { color: C.textMuted, fontSize: 12, fontWeight: "800" },
  summaryStatLabel: { color: C.textMuted, fontSize: 11, fontWeight: "700" },
  summaryInvoice: {
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
    paddingHorizontal: 14,
    paddingVertical: 12,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: C.borderAccent,
    backgroundColor: C.accentSoft,
  },
  summaryInvoiceText: { flex: 1, color: C.text, fontSize: 13, fontWeight: "800" },
  summarySecondaryBtn: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 8,
    minHeight: 50,
    borderRadius: 16,
    borderWidth: 1,
    borderColor: C.borderAccent,
    backgroundColor: C.card,
  },
  summarySecondaryText: { color: C.accent, fontSize: 14, fontWeight: "900" },

  resumeBanner: {
    flexDirection: "row",
    alignItems: "center",
    gap: 12,
    padding: 14,
    borderRadius: 16,
    backgroundColor: C.accentSoft,
    borderWidth: 1,
    borderColor: C.borderAccent,
  },
  resumeTitle: { color: C.text, fontSize: 14, fontWeight: "900" },
  resumeBody: { color: C.textMuted, fontSize: 12, fontWeight: "600", marginTop: 2, lineHeight: 16 },
  resumeBtn: {
    paddingHorizontal: 14,
    paddingVertical: 10,
    borderRadius: 12,
    backgroundColor: C.accent,
    minWidth: 88,
    alignItems: "center",
  },
  resumeBtnText: { color: C.onAccent, fontSize: 13, fontWeight: "900" },
  floatingResume: {
    flex: 1,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 8,
    backgroundColor: C.accent,
    borderRadius: 14,
    minHeight: 52,
  },
  floatingResumeText: { color: C.onAccent, fontSize: 15, fontWeight: "900" },

  floatingWrap: {
    position: "absolute",
    left: 16,
    right: 16,
  },
  floatingBar: {
    flexDirection: "row",
    gap: 10,
    padding: 8,
    borderRadius: 20,
    backgroundColor: C.card,
    borderWidth: 1,
    borderColor: C.borderSoft,
  },
  floatingPrimary: {
    flex: 1,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 8,
    backgroundColor: C.accent,
    borderRadius: 14,
    minHeight: 52,
  },
  floatingPrimaryText: { color: C.onAccent, fontSize: 15, fontWeight: "900" },
  floatingSecondary: {
    width: 52,
    height: 52,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: C.borderSoft,
    backgroundColor: C.bgSoft,
    alignItems: "center",
    justifyContent: "center",
  },
  floatingStop: {
    flex: 1,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 10,
    backgroundColor: C.accent,
    borderRadius: 14,
    minHeight: 52,
  },
  floatingStopIcon: {
    width: 28,
    height: 28,
    borderRadius: 14,
    backgroundColor: C.onAccent,
    alignItems: "center",
    justifyContent: "center",
  },
  floatingStopText: { color: C.onAccent, fontSize: 15, fontWeight: "900" },

  btnDisabled: { opacity: 0.45 },
  btnPressed: { opacity: motion.pressOpacity, transform: [{ scale: motion.pressScale }] },

  scanModal: { flex: 1, backgroundColor: C.bg },
  scanVignette: { ...StyleSheet.absoluteFillObject, backgroundColor: C.bg, opacity: 0.72 },
  scanFrameWrap: { flex: 1, alignItems: "center", justifyContent: "center", gap: 20 },
  scanFrame: {
    width: 248,
    height: 248,
    position: "relative",
  },
  corner: { position: "absolute", width: 32, height: 32, borderColor: C.accent },
  cTL: { top: 0, left: 0, borderTopWidth: 4, borderLeftWidth: 4, borderTopLeftRadius: 12 },
  cTR: { top: 0, right: 0, borderTopWidth: 4, borderRightWidth: 4, borderTopRightRadius: 12 },
  cBL: { bottom: 0, left: 0, borderBottomWidth: 4, borderLeftWidth: 4, borderBottomLeftRadius: 12 },
  cBR: { bottom: 0, right: 0, borderBottomWidth: 4, borderRightWidth: 4, borderBottomRightRadius: 12 },
  scanHint: { color: C.text, fontSize: 14, fontWeight: "700" },
  scanSheet: {
    backgroundColor: C.card,
    borderTopLeftRadius: 28,
    borderTopRightRadius: 28,
    paddingHorizontal: 20,
    paddingTop: 12,
    borderTopWidth: 1,
    borderColor: C.borderSoft,
  },
  scanSheetHandle: {
    width: 40,
    height: 4,
    borderRadius: 2,
    backgroundColor: C.accent,
    alignSelf: "center",
    marginBottom: 16,
  },
  scanSheetTitle: { color: C.text, fontSize: 17, fontWeight: "900", marginBottom: 14 },
  scanSheetRow: { flexDirection: "row", gap: 10 },
  scanGhost: {
    flex: 1,
    minHeight: 50,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: C.borderSoft,
    backgroundColor: C.bgSoft,
    alignItems: "center",
    justifyContent: "center",
  },
  scanGhostText: { color: C.accent, fontWeight: "800", fontSize: 15 },
  scanPrimary: {
    flex: 1,
    minHeight: 50,
    borderRadius: 14,
    backgroundColor: C.accent,
    alignItems: "center",
    justifyContent: "center",
  },
  scanPrimaryText: { color: C.onAccent, fontWeight: "900", fontSize: 15 },
});
