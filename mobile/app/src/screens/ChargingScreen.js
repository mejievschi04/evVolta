import React, { useCallback, useEffect, useMemo, useState } from "react";
import { Alert, Animated, Easing, Modal, Pressable, ScrollView, StyleSheet, Text, View } from "react-native";
import { CameraView, useCameraPermissions } from "expo-camera";
import { useFocusEffect } from "@react-navigation/native";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import apiClient from "../api/client";
import { useAuth } from "../context/AuthContext";
import { useApiResource } from "../hooks/useApiResource";
import { useAppResumeRefresh } from "../hooks/useAppResumeRefresh";
import { getApiErrorMessage } from "../utils/apiErrors";
import { colors, layout, motion, typography } from "../theme";

export default function ChargingScreen() {
  const insets = useSafeAreaInsets();
  const { activeSession, setActiveSession, refreshActiveSession, preferredStationId } = useAuth();
  const [stationId, setStationId] = useState("");
  const [lastResult, setLastResult] = useState(null);
  const [loading, setLoading] = useState(false);
  const [secondsElapsed, setSecondsElapsed] = useState(0);
  const [scannerOpen, setScannerOpen] = useState(false);
  const [permission, requestPermission] = useCameraPermissions();
  const [scanLocked, setScanLocked] = useState(false);
  const [livePulse] = useState(() => new Animated.Value(1));
  const [timerOrbit] = useState(() => new Animated.Value(0));

  const fetchChargingData = useCallback(async () => {
    const [stationsResponse, tariffResponse] = await Promise.all([
      apiClient.get("/stations"),
      apiClient.get("/tariff/current"),
    ]);

    return {
      stations: stationsResponse.data,
      tariff: tariffResponse.data,
    };
  }, []);

  const {
    data: chargingData,
    load: loadChargingData,
  } = useApiResource(fetchChargingData, {
    stations: [],
    tariff: null,
  });

  const stations = chargingData.stations;
  const tariff = chargingData.tariff;
  const pricePerKwh = Number(tariff?.price_per_kwh || 0);
  const currency = tariff?.currency || "MDL";

  useAppResumeRefresh(loadChargingData);

  useEffect(() => {
    if (activeSession?.station_id) {
      setStationId(String(activeSession.station_id));
      return;
    }

    if (!activeSession && preferredStationId) {
      setStationId(String(preferredStationId));
    }
  }, [activeSession, preferredStationId]);

  const openScanner = useCallback(async () => {
    if (!permission?.granted) {
      const result = await requestPermission();
      if (!result.granted) {
        Alert.alert("Permisiune camera", "Activeaza camera pentru scanare QR.");
        return;
      }
    }
    setScannerOpen(true);
  }, [permission?.granted, requestPermission]);

  useFocusEffect(
    useCallback(() => {
      if (!stationId && !activeSession && !preferredStationId) {
        void openScanner();
      }
    }, [stationId, activeSession, preferredStationId, openScanner])
  );

  useEffect(() => {
    if (!activeSession?.start_time) {
      setSecondsElapsed(0);
      return undefined;
    }

    const updateElapsed = () => {
      const start = new Date(activeSession.start_time).getTime();
      setSecondsElapsed(Math.max(0, Math.floor((Date.now() - start) / 1000)));
    };

    updateElapsed();
    const timer = setInterval(updateElapsed, 1000);
    return () => clearInterval(timer);
  }, [activeSession]);

  useEffect(() => {
    if (!activeSession) {
      livePulse.setValue(1);
      return undefined;
    }

    const animation = Animated.loop(
      Animated.sequence([
        Animated.timing(livePulse, {
          toValue: 1.18,
          duration: 700,
          easing: Easing.out(Easing.quad),
          useNativeDriver: true,
        }),
        Animated.timing(livePulse, {
          toValue: 1,
          duration: 700,
          easing: Easing.in(Easing.quad),
          useNativeDriver: true,
        }),
      ])
    );

    animation.start();
    return () => animation.stop();
  }, [activeSession, livePulse]);

  useEffect(() => {
    if (!activeSession) {
      timerOrbit.setValue(0);
      return undefined;
    }

    const orbitAnimation = Animated.loop(
      Animated.timing(timerOrbit, {
        toValue: 1,
        duration: 3200,
        easing: Easing.linear,
        useNativeDriver: true,
      })
    );
    orbitAnimation.start();
    return () => orbitAnimation.stop();
  }, [activeSession, timerOrbit]);

  const timerLabel = useMemo(() => {
    const h = Math.floor(secondsElapsed / 3600);
    const m = Math.floor((secondsElapsed % 3600) / 60);
    const s = secondsElapsed % 60;
    return [h, m, s].map((n) => String(n).padStart(2, "0")).join(":");
  }, [secondsElapsed]);

  const resolvedStationId = activeSession?.station_id ? String(activeSession.station_id) : stationId;
  const selectedStation = useMemo(() => {
    if (!resolvedStationId) return null;
    return stations.find((item) => String(item.id) === String(resolvedStationId)) ?? null;
  }, [resolvedStationId, stations]);

  const liveStatus = selectedStation?.live_status;
  const canStart = Boolean(resolvedStationId) && !activeSession && liveStatus?.can_start !== false;

  const handleQrScanned = ({ data }) => {
    if (scanLocked) {
      return;
    }
    setScanLocked(true);

    const raw = String(data || "").trim();
    const station = stations.find((item) => String(item.qr_code || "").trim() === raw);
    if (station) {
      setStationId(String(station.id));
      setScannerOpen(false);
      setScanLocked(false);
      return;
    }

    const candidates = new Set();

    if (raw.toLowerCase().startsWith("station:")) {
      candidates.add(raw.split(":").slice(1).join(":"));
    }

    try {
      const url = new URL(raw);
      const fromQuery = url.searchParams.get("station_id") || url.searchParams.get("stationId") || url.searchParams.get("id");
      if (fromQuery) candidates.add(fromQuery);
      const pathLast = url.pathname.split("/").filter(Boolean).pop();
      if (pathLast) candidates.add(pathLast);
    } catch {
      // not a URL
    }

    try {
      const parsed = JSON.parse(raw);
      const fromJson = parsed?.station_id ?? parsed?.stationId ?? parsed?.id;
      if (fromJson !== undefined && fromJson !== null) {
        candidates.add(String(fromJson));
      }
    } catch {
      // not JSON
    }

    candidates.add(raw);

    const numeric = Array.from(candidates)
      .map((value) => String(value).replace(/\D/g, ""))
      .find((value) => value && stations.some((item) => String(item.id) === value));

    if (numeric) {
      setStationId(numeric);
      setScannerOpen(false);
      setScanLocked(false);
      return;
    }

    Alert.alert("Cod QR invalid", "Codul scanat nu corespunde unei statii.");
    setTimeout(() => setScanLocked(false), 700);
  };

  const handleStart = async () => {
    try {
      setLoading(true);
      const response = await apiClient.post("/charging/start", {
        station_id: Number(resolvedStationId),
      });
      setActiveSession(response.data.session);
      setLastResult(null);
    } catch (error) {
      Alert.alert("Pornire esuata", getApiErrorMessage(error));
    } finally {
      setLoading(false);
    }
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
        Alert.alert("Oprire esuata", "Sesiunea activa nu este sincronizata inca. Incearca din nou in cateva secunde.");
        return;
      }

      const response = await apiClient.post("/charging/stop", {
        station_id: Number(nextStationId),
      });
      setLastResult(response.data);
      setActiveSession(null);
      await refreshActiveSession().catch(() => null);
    } catch (error) {
      Alert.alert("Oprire esuata", getApiErrorMessage(error));
    } finally {
      setLoading(false);
    }
  };

  return (
    <ScrollView
      contentContainerStyle={[styles.container, { paddingBottom: Math.max(insets.bottom, 12) + 92 }]}
      showsVerticalScrollIndicator={false}
    >
      {!resolvedStationId && !activeSession ? (
        <View style={styles.waitingCard}>
          <View style={styles.waitingIcon}>
            <MaterialCommunityIcons name="qrcode-scan" size={42} color={colors.accentText} />
          </View>
          <Text style={styles.waitingTitle}>Scaneaza statia ca sa incepi</Text>
          <Text style={styles.waitingSubtitle}>
            Inceputul fluxului este prin QR. Dupa scanare vezi statusul live si poti porni sesiunea.
          </Text>
          <Pressable style={({ pressed }) => [styles.mainButton, pressed && styles.buttonPressed]} onPress={openScanner}>
            <Text style={styles.mainButtonText}>Deschide scanner</Text>
          </Pressable>
        </View>
      ) : (
        <>
          <View style={styles.heroCard}>
            <View style={styles.heroGlowPrimary} />
            <View style={styles.heroGlowSecondary} />
            <View style={styles.heroTop}>
              <View style={styles.heroTitleRow}>
                <View style={styles.heroIconWrap}>
                  <MaterialCommunityIcons
                    name={activeSession ? "ev-station" : "qrcode-scan"}
                    size={18}
                    color={colors.accentText}
                  />
                </View>
                <Text style={styles.heroKicker}>{activeSession ? "INCARCARE ACTIVA" : "PREGATIT DE PORNIRE"}</Text>
              </View>
              <View style={styles.statusWrap}>
                <Animated.View
                  style={[
                    styles.statusDot,
                    activeSession ? styles.dotActive : styles.dotIdle,
                    activeSession
                      ? {
                          transform: [{ scale: livePulse }],
                          opacity: livePulse.interpolate({
                            inputRange: [1, 1.18],
                            outputRange: [0.7, 1],
                          }),
                        }
                      : null,
                  ]}
                />
                <Text style={styles.statusText}>{activeSession ? "LIVE" : "READY"}</Text>
              </View>
            </View>
            <Text style={styles.heroStation}>{selectedStation?.name || `Statie #${resolvedStationId}`}</Text>
            <Text style={styles.heroMeta}>{connectionLabel(liveStatus?.connection_status)} · {availabilityLabel(liveStatus?.availability)}</Text>
            <View style={styles.metricsRow}>
              <Metric label="Pret" value={`${pricePerKwh.toFixed(2)} ${currency}/kWh`} />
              <Metric label="Ultim heartbeat" value={formatLastSeen(liveStatus?.seconds_since_last_seen)} />
            </View>
            {activeSession ? (
              <View style={styles.timerWrap}>
                <Text style={styles.timerLabel}>TIMER SESIUNE</Text>
                <View style={styles.timerPanel}>
                  <Animated.View
                    style={[
                      styles.timerOrbit,
                      {
                        transform: [
                          {
                            rotate: timerOrbit.interpolate({
                              inputRange: [0, 1],
                              outputRange: ["0deg", "360deg"],
                            }),
                          },
                        ],
                      },
                    ]}
                  />
                  <View style={styles.timerCore}>
                    <Text style={styles.timer}>{timerLabel}</Text>
                  </View>
                </View>
                <Text style={styles.timerMeta}>Consum {Number(activeSession.kwh_consumed || 0).toFixed(2)} kWh</Text>
              </View>
            ) : null}
          </View>

          <View style={styles.actionsCard}>
            <Pressable
              onPress={openScanner}
              style={({ pressed }) => [styles.secondaryButton, pressed && styles.subtleButtonPressed]}
            >
              <Text style={styles.secondaryButtonText}>Rescaneaza statia</Text>
            </Pressable>

            {activeSession ? (
              <Pressable
                style={({ pressed }) => [styles.stopButton, (loading || !resolvedStationId) && styles.actionDisabled, pressed && styles.buttonPressed]}
                onPress={handleStop}
                disabled={loading || !resolvedStationId}
              >
                <Text style={styles.stopButtonText}>{loading ? "Se opreste..." : "Opreste sesiunea"}</Text>
              </Pressable>
            ) : (
              <Pressable
                style={({ pressed }) => [styles.mainButton, (!canStart || loading) && styles.actionDisabled, pressed && styles.buttonPressed]}
                onPress={handleStart}
                disabled={!canStart || loading}
              >
                <Text style={styles.mainButtonText}>{loading ? "Se porneste..." : "Porneste incarcare"}</Text>
              </Pressable>
            )}
          </View>
        </>
      )}

      {lastResult ? (
        <View style={styles.resultCard}>
          <Text style={styles.resultTitle}>Ultima sesiune</Text>
          <Text style={styles.resultText}>Consum: {lastResult.session?.kwh_consumed} kWh</Text>
          <Text style={styles.resultText}>
            Total: {Number(lastResult.invoice?.total_amount || 0).toFixed(2)} {lastResult.invoice?.currency || currency}
          </Text>
        </View>
      ) : null}

      <Modal visible={scannerOpen} animationType="slide">
        <View style={styles.modalContainer}>
          <CameraView
            style={styles.camera}
            facing="back"
            barcodeScannerSettings={{ barcodeTypes: ["qr"] }}
            onBarcodeScanned={handleQrScanned}
          />
          <View style={[styles.scanOverlay, { paddingBottom: Math.max(insets.bottom, 12) + 8 }]}>
            <Text style={styles.scanTitle}>Scaneaza codul QR al statiei</Text>
            <Pressable
              style={({ pressed }) => [styles.closeScanButton, pressed && styles.buttonPressed]}
              onPress={() => {
                setScannerOpen(false);
                setScanLocked(false);
              }}
            >
              <Text style={styles.closeScanText}>Inchide</Text>
            </Pressable>
          </View>
        </View>
      </Modal>
    </ScrollView>
  );
}

function Metric({ label, value }) {
  return (
    <View style={styles.metricCard}>
      <Text style={styles.metricLabel}>{label}</Text>
      <Text style={styles.metricValue}>{value}</Text>
    </View>
  );
}

function availabilityLabel(status) {
  if (status === "available") return "disponibil";
  if (status === "charging") return "ocupat";
  if (status === "reserved") return "rezervat";
  if (status === "faulted") return "eroare";
  if (status === "unavailable") return "indisponibil";
  if (status === "stale") return "fara heartbeat";
  return status || "necunoscut";
}

function connectionLabel(status) {
  if (status === "connected") return "OCPP conectata";
  if (status === "disconnected") return "OCPP deconectata";
  if (status === "not_configured") return "OCPP neconfigurat";
  return status || "OCPP necunoscut";
}

function formatLastSeen(seconds) {
  if (seconds === null || seconds === undefined) {
    return "fara date live";
  }

  if (seconds < 60) {
    return `acum ${Math.max(0, Math.round(seconds))}s`;
  }

  const minutes = Math.round(seconds / 60);
  if (minutes < 60) {
    return `acum ${minutes} min`;
  }

  return `acum ${Math.round(minutes / 60)} h`;
}

const styles = StyleSheet.create({
  container: {
    flexGrow: 1,
    backgroundColor: colors.bg,
    padding: layout.pagePadding,
    gap: 12,
  },
  waitingCard: {
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.cardRadiusLg,
    backgroundColor: colors.card,
    padding: 18,
    alignItems: "center",
    gap: 12,
  },
  waitingIcon: {
    width: 78,
    height: 78,
    borderRadius: 39,
    backgroundColor: colors.accent,
    alignItems: "center",
    justifyContent: "center",
  },
  waitingTitle: {
    color: colors.text,
    ...typography.titleMd,
    textAlign: "center",
  },
  waitingSubtitle: {
    color: colors.textMuted,
    ...typography.meta,
    textAlign: "center",
  },
  heroCard: {
    position: "relative",
    overflow: "hidden",
    borderWidth: 1,
    borderColor: "rgba(255,238,0,0.28)",
    borderRadius: layout.cardRadiusLg,
    backgroundColor: "#0E1322",
    padding: 14,
    gap: 10,
    shadowColor: colors.accent,
    shadowOpacity: 0.18,
    shadowRadius: 16,
    shadowOffset: { width: 0, height: 8 },
    elevation: 6,
  },
  heroGlowPrimary: {
    position: "absolute",
    width: 190,
    height: 190,
    borderRadius: 95,
    right: -62,
    top: -76,
    backgroundColor: "rgba(255,238,0,0.11)",
  },
  heroGlowSecondary: {
    position: "absolute",
    width: 130,
    height: 130,
    borderRadius: 65,
    left: -44,
    bottom: -44,
    backgroundColor: "rgba(124,199,255,0.1)",
  },
  heroTop: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
  },
  heroTitleRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
  },
  heroIconWrap: {
    width: 28,
    height: 28,
    borderRadius: 14,
    backgroundColor: colors.accent,
    alignItems: "center",
    justifyContent: "center",
    shadowColor: colors.accent,
    shadowOpacity: 0.35,
    shadowRadius: 10,
    shadowOffset: { width: 0, height: 0 },
  },
  heroKicker: {
    color: "#BFC8DD",
    fontSize: 11,
    fontWeight: "900",
    letterSpacing: 1.2,
  },
  statusWrap: {
    flexDirection: "row",
    alignItems: "center",
    gap: 6,
    borderWidth: 1,
    borderColor: "rgba(255,255,255,0.14)",
    backgroundColor: "rgba(8,11,20,0.55)",
    borderRadius: 999,
    paddingHorizontal: 8,
    paddingVertical: 3,
  },
  statusDot: {
    width: 10,
    height: 10,
    borderRadius: 5,
  },
  dotActive: {
    backgroundColor: colors.success,
  },
  dotIdle: {
    backgroundColor: colors.accent,
  },
  statusText: {
    color: "#C6D0E5",
    fontSize: 11,
    fontWeight: "800",
    letterSpacing: 0.8,
  },
  heroStation: {
    color: colors.text,
    ...typography.titleMd,
  },
  heroMeta: {
    color: "#9CA8C0",
    ...typography.meta,
  },
  metricsRow: {
    flexDirection: "row",
    gap: 8,
  },
  metricCard: {
    flex: 1,
    borderRadius: layout.cardRadiusSm,
    borderWidth: 1,
    borderColor: "rgba(255,255,255,0.1)",
    backgroundColor: "rgba(21,30,51,0.95)",
    padding: 10,
    minHeight: 62,
  },
  metricLabel: {
    color: colors.textMuted,
    ...typography.meta,
  },
  metricValue: {
    color: colors.text,
    ...typography.label,
    marginTop: 4,
  },
  timerWrap: {
    marginTop: 4,
    paddingTop: 8,
    borderTopWidth: 1,
    borderTopColor: "rgba(255,255,255,0.12)",
  },
  timerLabel: {
    color: "#8F9BB5",
    fontSize: 10,
    fontWeight: "900",
    letterSpacing: 1.1,
    marginBottom: 4,
  },
  timer: {
    color: colors.accent,
    fontSize: 34,
    fontWeight: "900",
    letterSpacing: 2.2,
    fontVariant: ["tabular-nums"],
    textShadowColor: "rgba(255,238,0,0.35)",
    textShadowOffset: { width: 0, height: 0 },
    textShadowRadius: 10,
  },
  timerPanel: {
    position: "relative",
    marginTop: 4,
    borderWidth: 1,
    borderColor: "rgba(255,238,0,0.28)",
    borderRadius: 14,
    backgroundColor: "rgba(9,12,20,0.7)",
    minHeight: 112,
    alignItems: "center",
    justifyContent: "center",
    paddingHorizontal: 10,
    shadowColor: colors.accent,
    shadowOpacity: 0.2,
    shadowRadius: 12,
    shadowOffset: { width: 0, height: 0 },
  },
  timerOrbit: {
    position: "absolute",
    width: 104,
    height: 104,
    borderRadius: 52,
    borderWidth: 2,
    borderColor: "rgba(255,255,255,0.09)",
    borderTopColor: "rgba(255,238,0,0.95)",
    borderRightColor: "rgba(124,199,255,0.9)",
  },
  timerCore: {
    minWidth: 230,
    alignItems: "center",
    justifyContent: "center",
  },
  timerMeta: {
    color: "#9CA8C0",
    ...typography.meta,
  },
  actionsCard: {
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.cardRadiusMd,
    backgroundColor: colors.card,
    padding: 12,
    gap: 8,
  },
  mainButton: {
    backgroundColor: colors.accent,
    borderRadius: layout.buttonRadius,
    minHeight: layout.buttonHeight,
    alignItems: "center",
    justifyContent: "center",
  },
  mainButtonText: {
    color: colors.accentText,
    ...typography.button,
  },
  secondaryButton: {
    borderRadius: layout.buttonRadius,
    minHeight: layout.buttonHeight,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.bgSoft,
    alignItems: "center",
    justifyContent: "center",
  },
  secondaryButtonText: {
    color: colors.text,
    ...typography.label,
  },
  stopButton: {
    backgroundColor: colors.danger,
    borderRadius: layout.buttonRadius,
    minHeight: layout.buttonHeight,
    alignItems: "center",
    justifyContent: "center",
  },
  stopButtonText: {
    color: "#FFFFFF",
    ...typography.button,
  },
  actionDisabled: {
    opacity: 0.55,
  },
  buttonPressed: {
    opacity: motion.pressOpacity,
    transform: [{ scale: motion.pressScale }],
  },
  subtleButtonPressed: {
    opacity: motion.subtlePressOpacity,
    transform: [{ scale: motion.subtlePressScale }],
  },
  resultCard: {
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.cardRadiusMd,
    backgroundColor: colors.card,
    padding: 12,
  },
  resultTitle: {
    color: colors.accent,
    ...typography.label,
    marginBottom: 6,
  },
  resultText: {
    color: colors.text,
    ...typography.meta,
    marginTop: 3,
  },
  modalContainer: {
    flex: 1,
    backgroundColor: "#000",
  },
  camera: {
    flex: 1,
  },
  scanOverlay: {
    position: "absolute",
    left: 0,
    right: 0,
    bottom: 0,
    padding: 20,
    backgroundColor: "rgba(7,9,15,0.88)",
    borderTopWidth: 1,
    borderTopColor: colors.border,
  },
  scanTitle: {
    color: colors.text,
    fontSize: 16,
    fontWeight: "700",
    marginBottom: 12,
  },
  closeScanButton: {
    backgroundColor: colors.accent,
    borderRadius: layout.buttonRadius,
    paddingVertical: 12,
    alignItems: "center",
  },
  closeScanText: {
    color: colors.accentText,
    fontWeight: "800",
  },
});
