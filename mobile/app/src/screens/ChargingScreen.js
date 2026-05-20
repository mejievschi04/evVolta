import React, { useCallback, useEffect, useMemo, useState } from "react";
import {
  ActivityIndicator,
  Alert,
  Animated,
  Easing,
  Modal,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from "react-native";
import { CameraView, useCameraPermissions } from "expo-camera";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import apiClient from "../api/client";
import { useAuth } from "../context/AuthContext";
import { useApiResource } from "../hooks/useApiResource";
import { useAppResumeRefresh } from "../hooks/useAppResumeRefresh";
import { useScreenFocusLoad } from "../hooks/useScreenFocusLoad";
import { getApiErrorMessage } from "../utils/apiErrors";
import { colors, motion } from "../theme";

/** Paleta stricta: fundal, accent, text (+ accentText pe butoane galbene) */
const C = {
  bg: colors.bg,
  accent: colors.accent,
  text: colors.text,
  onAccent: colors.accentText,
};

const PHASE = {
  IDLE: "idle",
  READY: "ready",
  CHARGING: "charging",
};

const TAB_BAR_HEIGHT = 68;
const TAB_BAR_BOTTOM_MARGIN = 12;
const TAB_BAR_GAP = 14;
const ACTION_BAR_HEIGHT = 72;

function tabBarClearance(insets) {
  return Math.max(insets.bottom, TAB_BAR_BOTTOM_MARGIN) + TAB_BAR_HEIGHT + TAB_BAR_GAP;
}

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
  const [chargePulse] = useState(() => new Animated.Value(0));

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
    loading: chargingLoading,
    load: loadChargingData,
  } = useApiResource(fetchChargingData, {
    stations: [],
    tariff: null,
  });

  useScreenFocusLoad(loadChargingData);
  useAppResumeRefresh(
    useCallback(() => {
      void loadChargingData(true).catch(() => null);
    }, [loadChargingData])
  );

  const stations = Array.isArray(chargingData.stations) ? chargingData.stations : [];
  const tariff = chargingData.tariff;
  const pricePerKwh = Number(tariff?.price_per_kwh || 0);
  const currency = tariff?.currency || "MDL";

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
        Alert.alert("Permisiune camera", "Activeaza camera pentru scanarea codului statiei.");
        return;
      }
    }
    setScannerOpen(true);
  }, [permission?.granted, requestPermission]);

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
  }, [activeSession, chargePulse]);

  const durationLabel = useMemo(() => {
    const h = Math.floor(secondsElapsed / 3600);
    const m = Math.floor((secondsElapsed % 3600) / 60);
    const s = secondsElapsed % 60;
    if (h > 0) return `${h}:${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
    return `${m}:${String(s).padStart(2, "0")}`;
  }, [secondsElapsed]);

  const resolvedStationId = activeSession?.station_id ? String(activeSession.station_id) : stationId;
  const selectedStation = useMemo(() => {
    if (!resolvedStationId) return null;
    return stations.find((item) => String(item.id) === String(resolvedStationId)) ?? null;
  }, [resolvedStationId, stations]);

  const liveStatus = selectedStation?.live_status;
  const phase = activeSession ? PHASE.CHARGING : resolvedStationId ? PHASE.READY : PHASE.IDLE;
  const canStart =
    phase === PHASE.READY &&
    (liveStatus?.can_start !== false || selectedStation?.status === "available");

  const kwhConsumed = Number(activeSession?.kwh_consumed || 0);
  const estimatedCost = (kwhConsumed * pricePerKwh).toFixed(2);
  const kwhParts = kwhConsumed.toFixed(2).split(".");

  const applyQrCode = useCallback(
    (rawInput) => {
      const result = resolveQrStationId(rawInput, stations);
      if (result.stationId) {
        setStationId(result.stationId);
        setScannerOpen(false);
        return true;
      }
      return false;
    },
    [stations]
  );

  const handleQrScanned = ({ data }) => {
    if (scanLocked) return;
    setScanLocked(true);
    if (applyQrCode(data)) {
      setScanLocked(false);
      return;
    }
    Alert.alert("Cod invalid", "Codul scanat nu corespunde unei statii.");
    setTimeout(() => setScanLocked(false), 700);
  };

  const handleQrTest = useCallback(() => {
    if (chargingLoading) {
      Alert.alert("Mod test", "Se incarca lista statiilor. Incearca din nou.");
      return;
    }
    const testStation = stations[0];
    if (!testStation?.id) {
      Alert.alert("Mod test", "Nu exista statii disponibile.");
      void loadChargingData().catch(() => null);
      return;
    }
    setScanLocked(false);
    setStationId(String(testStation.id));
    setScannerOpen(false);
  }, [chargingLoading, loadChargingData, stations]);

  const handleStart = async () => {
    try {
      setLoading(true);
      const response = await apiClient.post("/charging/start", { station_id: Number(resolvedStationId) });
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
        Alert.alert("Oprire esuata", "Sesiunea activa nu este sincronizata. Incearca din nou.");
        return;
      }
      const response = await apiClient.post("/charging/stop", { station_id: Number(nextStationId) });
      setLastResult(response.data);
      setActiveSession(null);
      await refreshActiveSession().catch(() => null);
    } catch (error) {
      Alert.alert("Oprire esuata", getApiErrorMessage(error));
    } finally {
      setLoading(false);
    }
  };

  const clearance = tabBarClearance(insets);
  const bottomPad = phase !== PHASE.IDLE ? clearance + ACTION_BAR_HEIGHT + 24 : clearance + 20;

  return (
    <View style={styles.page}>
      <ScrollView
        contentContainerStyle={[styles.scroll, { paddingBottom: bottomPad }]}
        showsVerticalScrollIndicator={false}
      >
        <ScreenHeader phase={phase} />

        {phase === PHASE.IDLE ? (
          <IdlePanel
            chargingLoading={chargingLoading}
            hasStations={stations.length > 0}
            onScan={openScanner}
            onTest={handleQrTest}
          />
        ) : (
          <SessionDashboard
            phase={phase}
            station={selectedStation}
            liveStatus={liveStatus}
            durationLabel={durationLabel}
            kwhParts={kwhParts}
            estimatedCost={estimatedCost}
            pricePerKwh={pricePerKwh}
            currency={currency}
            chargePulse={chargePulse}
          />
        )}

        {lastResult ? (
          <CompletionCard lastResult={lastResult} currency={currency} onDismiss={() => setLastResult(null)} />
        ) : null}
      </ScrollView>

      {phase !== PHASE.IDLE ? (
        <FloatingActions
          clearance={clearance}
          phase={phase}
          loading={loading}
          canStart={canStart}
          resolvedStationId={resolvedStationId}
          onScan={openScanner}
          onStart={handleStart}
          onStop={handleStop}
        />
      ) : null}

      <ScanModal
        visible={scannerOpen}
        insets={insets}
        chargingLoading={chargingLoading}
        hasStations={stations.length > 0}
        scanLocked={scanLocked}
        onScan={handleQrScanned}
        onTest={handleQrTest}
        onClose={() => {
          setScannerOpen(false);
          setScanLocked(false);
        }}
      />
    </View>
  );
}

function ScreenHeader({ phase }) {
  const meta = {
    [PHASE.IDLE]: { title: "Conecteaza statia", sub: "Scaneaza codul sau mod test", icon: "qrcode-scan", tone: "idle" },
    [PHASE.READY]: { title: "Gata de pornire", sub: "Verifica mufa si apasa Pornire", icon: "ev-station", tone: "ready" },
    [PHASE.CHARGING]: { title: "Sesiune activa", sub: "Energie livrata in timp real", icon: "lightning-bolt", tone: "live" },
  }[phase];

  return (
    <View style={styles.header}>
      <View style={styles.headerMain}>
        <Text style={styles.headerEyebrow}>VOLTA EV</Text>
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

function IdlePanel({ chargingLoading, hasStations, onScan, onTest }) {
  return (
    <View style={styles.glassCard}>
      <View style={styles.idleHero}>
        <View style={styles.idleIconRing}>
          <MaterialCommunityIcons name="qrcode-scan" size={40} color={C.onAccent} />
        </View>
        <View style={styles.idleCopy}>
          <Text style={styles.idleTitle}>Identifica statia</Text>
          <Text style={styles.idleSub}>Scaneaza codul de pe VOLTA 1 sau foloseste modul test.</Text>
        </View>
      </View>

      <View style={styles.flowTrack}>
        <FlowStep n={1} label="Scanare" desc="Cod statie" active />
        <FlowStep n={2} label="Pornire" desc="Sesiune" />
        <FlowStep n={3} label="Incarcare" desc="Energie" />
      </View>

      <Pressable style={({ pressed }) => [styles.primaryBtn, pressed && styles.btnPressed]} onPress={onScan}>
        <MaterialCommunityIcons name="camera-outline" size={20} color={C.onAccent} />
        <Text style={styles.primaryBtnText}>Scaneaza codul</Text>
      </Pressable>

      <Pressable
        style={({ pressed }) => [styles.linkBtn, (chargingLoading || !hasStations) && styles.btnDisabled, pressed && styles.btnPressed]}
        onPress={onTest}
        disabled={chargingLoading}
      >
        <MaterialCommunityIcons name="flask-outline" size={16} color={C.accent} />
        <Text style={styles.linkBtnText}>{chargingLoading ? "Se incarca..." : "Continua fara scanare (test)"}</Text>
      </Pressable>
    </View>
  );
}

function SessionDashboard({ phase, station, liveStatus, durationLabel, kwhParts, estimatedCost, pricePerKwh, currency, chargePulse }) {
  const isCharging = phase === PHASE.CHARGING;
  const availability = availabilityLabel(liveStatus?.availability || station?.status);
  const barWidth = chargePulse.interpolate({
    inputRange: [0, 1],
    outputRange: ["8%", "88%"],
  });

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
        <Chip icon="cash" text={`${pricePerKwh.toFixed(2)} ${currency}/kWh`} />
      </View>

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
            <BentoCell icon="flash" label="Putere" value={String(station?.power_kw || "—")} suffix="kW" />
            <BentoCell icon="transmission-tower" label="Conexiune" value={connectionShort(liveStatus?.connection_status)} />
          </View>
        </View>
      ) : (
        <View style={styles.readyPanel}>
          <View style={styles.readyIconWrap}>
            <MaterialCommunityIcons name="power-plug" size={32} color={C.accent} />
          </View>
          <Text style={styles.readyHeadline}>Conecteaza vehiculul</Text>
          <Text style={styles.readyBody}>
            Statia e selectata. Apasa Pornire dupa ce mufa e fixata corect.
          </Text>
          <View style={styles.readyHintRow}>
            <MaterialCommunityIcons name="information-outline" size={14} color={C.text} style={styles.mutedIcon} />
            <Text style={styles.readyHint}>{connectionLabel(liveStatus?.connection_status)}</Text>
          </View>
        </View>
      )}
    </View>
  );
}

function FloatingActions({ clearance, phase, loading, canStart, resolvedStationId, onScan, onStart, onStop }) {
  const isCharging = phase === PHASE.CHARGING;

  return (
    <View style={[styles.floatingWrap, { bottom: clearance }]}>
      <View style={styles.floatingBar}>
        {isCharging ? (
          <Pressable
            style={({ pressed }) => [styles.floatingStop, (loading || !resolvedStationId) && styles.btnDisabled, pressed && styles.btnPressed]}
            onPress={onStop}
            disabled={loading || !resolvedStationId}
          >
            {loading ? (
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
        ) : (
          <>
            <Pressable style={({ pressed }) => [styles.floatingSecondary, pressed && styles.btnPressed]} onPress={onScan}>
              <MaterialCommunityIcons name="qrcode-scan" size={20} color={C.text} />
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
                  <Text style={styles.floatingPrimaryText}>Porneste incarcarea</Text>
                </>
              )}
            </Pressable>
          </>
        )}
      </View>
    </View>
  );
}

function CompletionCard({ lastResult, currency, onDismiss }) {
  const total = Number(lastResult.invoice?.total_amount || 0).toFixed(2);
  const invoiceCurrency = lastResult.invoice?.currency || currency;

  return (
    <View style={styles.completeCard}>
      <MaterialCommunityIcons name="check-circle" size={22} color={C.accent} />
      <View style={{ flex: 1 }}>
        <Text style={styles.completeTitle}>Finalizat</Text>
        <Text style={styles.completeMeta}>
          {Number(lastResult.session?.kwh_consumed || 0).toFixed(2)} kWh · {total} {invoiceCurrency}
        </Text>
      </View>
      <Pressable onPress={onDismiss} hitSlop={12}>
        <MaterialCommunityIcons name="close" size={18} color={C.text} style={styles.mutedIcon} />
      </Pressable>
    </View>
  );
}

function ScanModal({ visible, insets, chargingLoading, hasStations, scanLocked, onScan, onTest, onClose }) {
  return (
    <Modal visible={visible} animationType="fade" statusBarTranslucent>
      <View style={styles.scanModal}>
        <CameraView
          style={StyleSheet.absoluteFill}
          facing="back"
          barcodeScannerSettings={{ barcodeTypes: ["qr"] }}
          onBarcodeScanned={scanLocked ? undefined : onScan}
        />
        <View style={styles.scanVignette} />
        <View style={styles.scanFrameWrap}>
          <View style={styles.scanFrame}>
            <View style={[styles.corner, styles.cTL]} />
            <View style={[styles.corner, styles.cTR]} />
            <View style={[styles.corner, styles.cBL]} />
            <View style={[styles.corner, styles.cBR]} />
          </View>
          <Text style={styles.scanHint}>Plaseaza codul in cadru</Text>
        </View>

        <View style={[styles.scanSheet, { paddingBottom: Math.max(insets.bottom, 16) + 12 }]}>
          <View style={styles.scanSheetHandle} />
          <Text style={styles.scanSheetTitle}>Scanare statie</Text>
          <View style={styles.scanSheetRow}>
            <Pressable
              style={({ pressed }) => [styles.scanGhost, (chargingLoading || !hasStations) && styles.btnDisabled, pressed && styles.btnPressed]}
              onPress={onTest}
              disabled={chargingLoading}
            >
              <Text style={styles.scanGhostText}>{chargingLoading ? "..." : "Proba"}</Text>
            </Pressable>
            <Pressable style={({ pressed }) => [styles.scanPrimary, pressed && styles.btnPressed]} onPress={onClose}>
              <Text style={styles.scanPrimaryText}>Inchide</Text>
            </Pressable>
          </View>
        </View>
      </View>
    </Modal>
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

function resolveQrStationId(rawInput, stations) {
  const raw = String(rawInput || "").trim();
  const station = stations.find((item) => String(item.qr_code || "").trim() === raw);
  if (station) return { stationId: String(station.id) };

  const candidates = new Set();
  if (raw.toLowerCase().startsWith("station:")) candidates.add(raw.split(":").slice(1).join(":"));
  try {
    const url = new URL(raw);
    const fromQuery = url.searchParams.get("station_id") || url.searchParams.get("stationId") || url.searchParams.get("id");
    if (fromQuery) candidates.add(fromQuery);
    const pathLast = url.pathname.split("/").filter(Boolean).pop();
    if (pathLast) candidates.add(pathLast);
  } catch {
    /* not url */
  }
  try {
    const parsed = JSON.parse(raw);
    const fromJson = parsed?.station_id ?? parsed?.stationId ?? parsed?.id;
    if (fromJson != null) candidates.add(String(fromJson));
  } catch {
    /* not json */
  }
  candidates.add(raw);

  const numeric = Array.from(candidates)
    .map((v) => String(v).replace(/\D/g, ""))
    .find((v) => v && stations.some((item) => String(item.id) === v));

  return numeric ? { stationId: numeric } : { stationId: null };
}

function availabilityLabel(status) {
  if (status === "available") return "Disponibil";
  if (status === "charging") return "Ocupat";
  if (status === "reserved") return "Rezervat";
  if (status === "faulted") return "Eroare";
  if (status === "unavailable") return "Indisponibil";
  if (status === "stale") return "Deconectat";
  return status ? String(status) : "—";
}

function connectionLabel(status) {
  if (status === "connected") return "Conectat la statie";
  if (status === "disconnected") return "Deconectat de la statie";
  if (status === "not_configured") return "Mod simulare";
  return "Status necunoscut";
}

function connectionShort(status) {
  if (status === "connected") return "Conectat";
  if (status === "disconnected") return "Deconectat";
  return "Simulare";
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
    color: C.text,
    opacity: 0.55,
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
    backgroundColor: C.bg,
    borderColor: C.accent,
  },
  headerBadge_idle: { borderColor: C.text },
  headerBadge_ready: {},
  headerBadge_live: {},

  glassCard: {
    borderRadius: 24,
    borderWidth: 1,
    borderColor: C.accent,
    backgroundColor: C.bg,
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
  idleSub: { color: C.text, opacity: 0.55, fontSize: 13, fontWeight: "600", marginTop: 4, lineHeight: 18 },

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
    borderColor: C.text,
    opacity: 0.35,
    alignItems: "center",
    justifyContent: "center",
  },
  flowNumActive: {
    backgroundColor: C.accent,
    borderColor: C.accent,
    opacity: 1,
  },
  flowNumText: { color: C.text, opacity: 0.55, fontSize: 12, fontWeight: "900" },
  flowNumTextActive: { color: C.onAccent, opacity: 1 },
  flowLabel: { color: C.text, opacity: 0.55, fontSize: 11, fontWeight: "800" },
  flowLabelActive: { color: C.text, opacity: 1 },
  flowDesc: { color: C.text, opacity: 0.45, fontSize: 9, fontWeight: "600" },

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
  linkBtn: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 6,
    paddingVertical: 8,
  },
  linkBtnText: { color: C.accent, fontSize: 12, fontWeight: "700" },

  dashboard: { gap: 12 },
  stationStrip: {
    flexDirection: "row",
    alignItems: "center",
    gap: 12,
    padding: 14,
    borderRadius: 20,
    backgroundColor: C.bg,
    borderWidth: 1,
    borderColor: C.accent,
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
  stationStripLoc: { color: C.text, opacity: 0.55, fontSize: 12, fontWeight: "600", marginTop: 2 },
  pill: {
    borderRadius: 999,
    borderWidth: 1,
    borderColor: C.accent,
    backgroundColor: C.bg,
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
    backgroundColor: C.bg,
    borderWidth: 1,
    borderColor: C.accent,
  },
  chipText: { color: C.text, fontSize: 12, fontWeight: "700" },

  energyCard: {
    borderRadius: 24,
    padding: 18,
    gap: 14,
    backgroundColor: C.bg,
    borderWidth: 1,
    borderColor: C.accent,
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
    color: C.text,
    opacity: 0.55,
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
    color: C.text,
    opacity: 0.55,
    fontSize: 16,
    fontWeight: "800",
    marginBottom: 14,
    marginLeft: 4,
  },
  flowBarTrack: {
    height: 6,
    borderRadius: 999,
    backgroundColor: C.bg,
    borderWidth: 1,
    borderColor: C.accent,
    overflow: "hidden",
  },
  flowBarFill: {
    height: "100%",
    borderRadius: 999,
    backgroundColor: C.accent,
    minWidth: 8,
  },
  bentoRow: { flexDirection: "row", gap: 8 },
  bento: {
    flex: 1,
    borderRadius: 16,
    padding: 12,
    gap: 4,
    backgroundColor: C.bg,
    borderWidth: 1,
    borderColor: C.accent,
  },
  bentoAccent: {},
  bentoLabel: {
    color: C.text,
    opacity: 0.55,
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
  bentoSuffix: { fontSize: 13, fontWeight: "700", color: C.text, opacity: 0.55 },

  readyPanel: {
    alignItems: "center",
    padding: 28,
    borderRadius: 24,
    borderWidth: 1,
    borderColor: C.accent,
    backgroundColor: C.bg,
    gap: 10,
  },
  readyIconWrap: {
    width: 64,
    height: 64,
    borderRadius: 32,
    borderWidth: 1,
    borderColor: C.accent,
    backgroundColor: C.bg,
    alignItems: "center",
    justifyContent: "center",
    marginBottom: 4,
  },
  readyHeadline: { color: C.text, fontSize: 20, fontWeight: "900" },
  readyBody: {
    color: C.text,
    opacity: 0.55,
    fontSize: 14,
    fontWeight: "600",
    textAlign: "center",
    lineHeight: 20,
    paddingHorizontal: 8,
  },
  readyHintRow: { flexDirection: "row", alignItems: "center", gap: 6, marginTop: 4 },
  readyHint: { color: C.text, opacity: 0.55, fontSize: 12, fontWeight: "600" },

  completeCard: {
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
    padding: 14,
    borderRadius: 16,
    backgroundColor: C.bg,
    borderWidth: 1,
    borderColor: C.accent,
  },
  completeTitle: { color: C.text, fontSize: 14, fontWeight: "900" },
  completeMeta: { color: C.text, opacity: 0.55, fontSize: 12, fontWeight: "700", marginTop: 2 },

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
    backgroundColor: C.bg,
    borderWidth: 1,
    borderColor: C.accent,
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
    borderColor: C.accent,
    backgroundColor: C.bg,
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
    backgroundColor: C.bg,
    borderTopLeftRadius: 28,
    borderTopRightRadius: 28,
    paddingHorizontal: 20,
    paddingTop: 12,
    borderTopWidth: 1,
    borderColor: C.accent,
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
    borderColor: C.accent,
    backgroundColor: C.bg,
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
