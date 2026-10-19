import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  ActivityIndicator,
  Linking,
  NativeModules,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  View,
} from "react-native";
import { WebView } from "react-native-webview";
import * as Location from "expo-location";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { useNavigation } from "@react-navigation/native";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import apiClient from "../api/client";
import { useAuth } from "../context/AuthContext";
import { useAppResumeRefresh } from "../hooks/useAppResumeRefresh";
import { useApiResource } from "../hooks/useApiResource";
import { useScreenFocusLoad } from "../hooks/useScreenFocusLoad";
import { formatMoney } from "../utils/chargeLimit";
import {
  availabilityLabel,
  effectiveStationAvailability,
  markerColorForStation,
} from "../utils/stationStatus";
import { StationsScreenSkeleton } from "../components/ui/Skeleton";
import { formatTariffPriceLabel, usesWalletAccount } from "../utils/accountType";
import { colors, layout, motion } from "../theme";

const MAP_STYLE_URL = "https://tiles.openfreemap.org/styles/positron";
const DEFAULT_CENTER = { latitude: 47.010452, longitude: 28.86381 };
const DEFAULT_ZOOM = 14;
const HAS_NATIVE_MAPLIBRE = Boolean(NativeModules.MLRNModule);
const MAPLIBRE = HAS_NATIVE_MAPLIBRE ? loadMapLibreComponents() : null;

function loadMapLibreComponents() {
  try {
    return require("@maplibre/maplibre-react-native");
  } catch {
    return null;
  }
}

const TAB_BAR_SPACE = 82;
const DOCK_GAP = 10;

export default function StationsScreen() {
  const navigation = useNavigation();
  const insets = useSafeAreaInsets();
  const { user } = useAuth();
  const usesPrepayWallet = usesWalletAccount(user?.account_type);
  const cameraRef = useRef(null);
  const [selectedStationId, setSelectedStationId] = useState(null);
  const [mapTarget, setMapTarget] = useState(null);
  const { location, locationReady, locationError } = useMapLocation(DEFAULT_CENTER);

  const fetchStations = useCallback(async () => {
    const [stationsResponse, tariffResponse] = await Promise.all([
      apiClient.get("/stations"),
      apiClient.get("/tariff/current"),
    ]);

    const stations = Array.isArray(stationsResponse.data) ? stationsResponse.data : [];

    let wallet = null;
    if (usesPrepayWallet) {
      try {
        const walletResponse = await apiClient.get("/wallet");
        wallet = walletResponse.data;
      } catch {
        wallet = null;
      }
    }

    return {
      stations,
      tariff: tariffResponse.data,
      wallet,
    };
  }, [usesPrepayWallet]);

  const {
    data: stationData,
    loading,
    refreshing,
    error: stationsError,
    load: loadStations,
  } = useApiResource(fetchStations, {
    stations: [],
    tariff: null,
    wallet: null,
  });

  useScreenFocusLoad(loadStations);
  useAppResumeRefresh(
    useCallback(() => {
      void loadStations("silent").catch(() => null);
    }, [loadStations])
  );

  const mapStations = useMemo(
    () => stationData.stations.map(withMapCoordinate),
    [stationData.stations]
  );
  const selectedStation = useMemo(() => {
    if (!mapStations.length) return null;
    return mapStations.find((station) => station.id === selectedStationId) ?? mapStations[0];
  }, [mapStations, selectedStationId]);

  useEffect(() => {
    if (!mapStations.length) {
      setSelectedStationId(null);
      return;
    }

    if (!selectedStationId || !mapStations.some((station) => station.id === selectedStationId)) {
      setSelectedStationId(mapStations[0].id);
      setMapTarget(mapStations[0]);
    }
  }, [mapStations, selectedStationId]);

  const stationsGeoJSON = useMemo(
    () => ({
      type: "FeatureCollection",
      features: mapStations.map((station) => ({
        type: "Feature",
        geometry: {
          type: "Point",
          coordinates: [station.mapLongitude, station.mapLatitude],
        },
        properties: {
          stationId: station.id,
          markerColor: markerColorForStation(station, colors),
        },
      })),
    }),
    [mapStations]
  );

  const selectedCoordinate = selectedStation
    ? [selectedStation.mapLongitude, selectedStation.mapLatitude]
    : [DEFAULT_CENTER.longitude, DEFAULT_CENTER.latitude];
  const fallbackPoint = mapTarget
    ?? selectedStation
    ?? {
      name: "V CHARGE",
      mapLatitude: location?.latitude ?? DEFAULT_CENTER.latitude,
      mapLongitude: location?.longitude ?? DEFAULT_CENTER.longitude,
    };
  const tariff = stationData.tariff;
  const wallet = stationData.wallet;
  const currency = tariff?.currency || wallet?.currency || "MDL";
  const priceLabel = tariff
    ? formatTariffPriceLabel(tariff.price_per_kwh, currency, {
        freeCharging: Boolean(tariff.free_charging) || user?.account_type === "service",
      })
    : "-";
  const walletBalance = wallet?.wallet_balance ?? user?.wallet_balance ?? 0;
  const dockBottom = Math.max(insets.bottom, 10) + TAB_BAR_SPACE;
  const focusStation = (station) => {
    setSelectedStationId(station.id);
    setMapTarget(station);
    if (MAPLIBRE) {
      cameraRef.current?.flyTo([station.mapLongitude, station.mapLatitude], 500);
    }
  };

  const focusUser = () => {
    if (!location) return;
    setMapTarget({
      name: "Locatia ta",
      mapLatitude: location.latitude,
      mapLongitude: location.longitude,
    });
    if (MAPLIBRE) {
      cameraRef.current?.flyTo([location.longitude, location.latitude], 500);
    }
  };

  const openDirections = (station) => {
    if (!station) return;

    const { mapLatitude: latitude, mapLongitude: longitude } = station;
    const url = Platform.select({
      ios: `maps://app?daddr=${latitude},${longitude}&dirflg=d`,
      android: `google.navigation:q=${latitude},${longitude}`,
      default: `https://www.google.com/maps/dir/?api=1&destination=${latitude},${longitude}`,
    });
    const fallbackUrl = `https://www.google.com/maps/dir/?api=1&destination=${latitude},${longitude}`;

    Linking.openURL(url || fallbackUrl).catch(() => Linking.openURL(fallbackUrl));
  };

  if (loading && !mapStations.length) {
    return <StationsScreenSkeleton />;
  }

  if (!mapStations.length && stationsError) {
    return (
      <View style={styles.center}>
        <MaterialCommunityIcons name="ev-station" size={32} color={colors.textMuted} />
        <Text style={styles.errorTitle}>Statii indisponibile</Text>
        <Text style={styles.errorText}>{stationsError}</Text>
        <Pressable
          style={({ pressed }) => [styles.retryButton, pressed && styles.subtlePress]}
          onPress={() => {
            void loadStations().catch(() => null);
          }}
        >
          <Text style={styles.retryButtonText}>Incearca din nou</Text>
        </Pressable>
      </View>
    );
  }

  return (
    <View style={styles.page}>
      {MAPLIBRE ? (
        <NativeMap
          cameraRef={cameraRef}
          selectedCoordinate={selectedCoordinate}
          stationsGeoJSON={stationsGeoJSON}
          mapStations={mapStations}
          locationReady={locationReady}
          locationError={locationError}
          onSelectStation={focusStation}
        />
      ) : (
        <WebTileMap point={fallbackPoint} selectedStation={selectedStation} stations={mapStations} />
      )}

      {stationsError && mapStations.length ? (
        <View style={styles.inlineErrorBanner}>
          <Text style={styles.inlineErrorText}>{stationsError}</Text>
        </View>
      ) : null}

      <View style={styles.screenHeader}>
        <View style={styles.screenHeaderRow}>
          <View style={styles.screenHeaderIdentity}>
            <View style={styles.avatarShell}>
              <Text style={styles.avatarText}>{userInitials(user?.name)}</Text>
            </View>
            <View style={styles.screenHeaderCopy}>
              <Text style={styles.screenHeaderGreeting}>{greetingLabel()}</Text>
              <Text style={styles.screenHeaderName} numberOfLines={1}>
                {firstName(user)}
              </Text>
            </View>
          </View>
          {usesPrepayWallet ? (
            <Pressable
              accessibilityLabel="Alimenteaza cont"
              accessibilityRole="button"
              onPress={() => navigation.navigate("WalletTopup")}
              style={({ pressed }) => [styles.walletCard, pressed && styles.walletCardPressed]}
            >
              <Text style={styles.walletCardAmount} numberOfLines={1}>
                {formatMoney(walletBalance, currency)}
              </Text>
              <View style={styles.walletCardPlus}>
                <MaterialCommunityIcons name="plus" size={16} color={colors.accentText} />
              </View>
            </Pressable>
          ) : (
            <View style={styles.brandMark}>
              <MaterialCommunityIcons name="lightning-bolt" size={16} color={colors.accentText} />
            </View>
          )}
        </View>
      </View>

      <View style={[styles.bottomDock, { bottom: dockBottom }]}>
        <View style={styles.stationRailWrap}>
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.stationRail}>
            {mapStations.map((station) => {
              const active = selectedStation?.id === station.id;
              return (
                <Pressable
                  key={station.id}
                  style={({ pressed }) => [
                    styles.stationPill,
                    active && styles.stationPillActive,
                    pressed && styles.subtlePress,
                  ]}
                  onPress={() => focusStation(station)}
                >
                  <View style={[styles.dot, { backgroundColor: markerColorForStation(station, colors) }]} />
                  <Text style={[styles.stationPillText, active && styles.stationPillTextActive]} numberOfLines={1}>
                    {station.name}
                  </Text>
                </Pressable>
              );
            })}
          </ScrollView>
        </View>

        <View style={styles.cardWrap}>
          {selectedStation ? (
            <View style={styles.stationCard}>
              <View style={styles.headerRow}>
                <View style={styles.titleBlock}>
                  <Text style={styles.stationName} numberOfLines={1}>
                    {selectedStation.name}
                  </Text>
                  <Text style={styles.stationLocation} numberOfLines={1}>
                    {selectedStation.location}
                  </Text>
                </View>
                <Pressable
                  style={({ pressed }) => [styles.directionInlineButton, pressed && styles.buttonPress]}
                  onPress={() => openDirections(selectedStation)}
                >
                  <MaterialCommunityIcons name="navigation-variant" size={18} color={colors.text} />
                </Pressable>
              </View>
              <View style={styles.metaRow}>
                <View style={styles.statusPill}>
                  <Text style={styles.statusPillText}>
                    {availabilityLabel(effectiveStationAvailability(selectedStation))}
                  </Text>
                </View>
                <View style={styles.pricePill}>
                  <MaterialCommunityIcons name="cash-fast" size={12} color={colors.accent} />
                  <Text style={styles.priceText} numberOfLines={1}>
                    {priceLabel}
                  </Text>
                </View>
              </View>
            </View>
          ) : (
            <View style={styles.stationCard}>
              <Text style={styles.stationName}>Nu exista statii</Text>
              <Text style={styles.stationLocation}>Adauga statii din backoffice.</Text>
            </View>
          )}
        </View>
      </View>
    </View>
  );
}

function NativeMap({
  cameraRef,
  selectedCoordinate,
  stationsGeoJSON,
  mapStations,
  locationReady,
  locationError,
  onSelectStation,
}) {
  const { Camera, CircleLayer, MapView, ShapeSource, UserLocation } = MAPLIBRE;

  return (
    <MapView style={styles.map} mapStyle={MAP_STYLE_URL} logoEnabled={false} attributionEnabled={false}>
      <Camera
        ref={cameraRef}
        centerCoordinate={selectedCoordinate}
        animationMode="flyTo"
        animationDuration={500}
        defaultSettings={{
          centerCoordinate: selectedCoordinate,
          zoomLevel: DEFAULT_ZOOM,
        }}
      />
      {locationReady && !locationError ? <UserLocation visible /> : null}
      <ShapeSource
        id="stations"
        shape={stationsGeoJSON}
        onPress={(event) => {
          const stationId = event.features?.[0]?.properties?.stationId;
          const station = mapStations.find((item) => String(item.id) === String(stationId));
          if (station) onSelectStation(station);
        }}
      >
        <CircleLayer
          id="station-points"
          style={{
            circleColor: ["get", "markerColor"],
            circleRadius: 9,
            circleStrokeColor: "#07090F",
            circleStrokeWidth: 3,
          }}
        />
      </ShapeSource>
    </MapView>
  );
}

function WebTileMap({ point, stations, selectedStation }) {
  const html = useMemo(
    () => buildTileMapHtml(point, stations, selectedStation),
    [point, selectedStation, stations]
  );

  return (
    <WebView
      style={styles.map}
      source={{ html, baseUrl: "https://tile.openstreetmap.org/" }}
      originWhitelist={["*"]}
      javaScriptEnabled
      domStorageEnabled
      cacheEnabled
      mixedContentMode="always"
      startInLoadingState
      renderLoading={() => (
        <View style={styles.center}>
          <ActivityIndicator color={colors.accent} />
        </View>
      )}
    />
  );
}

function MapLibreUnavailable() {
  return (
    <View style={styles.center}>
      <View style={styles.mapUnavailableCard}>
        <MaterialCommunityIcons name="map-outline" size={24} color={colors.accent} />
        <Text style={styles.mapUnavailableTitle}>MapLibre indisponibil</Text>
        <Text style={styles.mapUnavailableText}>
          Ruleaza aplicatia in dev build nativ ca sa activezi harta MapLibre.
        </Text>
      </View>
    </View>
  );
}

function RoundButton({ icon, onPress, loading = false }) {
  return (
    <Pressable style={({ pressed }) => [styles.roundButton, pressed && styles.buttonPress]} onPress={onPress}>
      {loading ? (
        <ActivityIndicator color={colors.text} size="small" />
      ) : (
        <MaterialCommunityIcons name={icon} size={20} color={colors.text} />
      )}
    </Pressable>
  );
}

function useMapLocation(defaultCenter) {
  const [location, setLocation] = useState(defaultCenter);
  const [locationError, setLocationError] = useState(null);
  const [locationReady, setLocationReady] = useState(false);

  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        const { status } = await Location.requestForegroundPermissionsAsync();
        if (cancelled) return;

        if (status !== "granted") {
          setLocationError("permission_denied");
          setLocation(defaultCenter);
          return;
        }

        const current = await Location.getCurrentPositionAsync({
          accuracy: Location.Accuracy.Balanced,
        });
        if (cancelled) return;

        setLocation({
          latitude: current.coords.latitude,
          longitude: current.coords.longitude,
        });
        setLocationError(null);
      } catch {
        if (!cancelled) {
          setLocation(defaultCenter);
          setLocationError("location_unavailable");
        }
      } finally {
        if (!cancelled) setLocationReady(true);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [defaultCenter]);

  return { location, locationError, locationReady };
}

function withMapCoordinate(station, index) {
  const latitude = Number(station.latitude);
  const longitude = Number(station.longitude);
  const hasCoordinates =
    Number.isFinite(latitude) &&
    Number.isFinite(longitude) &&
    latitude >= -90 &&
    latitude <= 90 &&
    longitude >= -180 &&
    longitude <= 180;
  const fallbackOffset = index * 0.0018;

  return {
    ...station,
    mapLatitude: hasCoordinates ? latitude : DEFAULT_CENTER.latitude + fallbackOffset,
    mapLongitude: hasCoordinates ? longitude : DEFAULT_CENTER.longitude + fallbackOffset,
  };
}

function userInitials(name) {
  const parts = String(name || "U")
    .trim()
    .split(/\s+/)
    .filter(Boolean);

  if (parts.length >= 2) {
    return `${parts[0][0] ?? ""}${parts[1][0] ?? ""}`.toUpperCase();
  }

  return (parts[0]?.slice(0, 2) || "U").toUpperCase();
}

function greetingLabel() {
  const hour = new Date().getHours();
  if (hour < 12) return "Bună dimineața";
  if (hour < 18) return "Bună ziua";
  return "Bună seara";
}

function firstName(user) {
  const explicit = String(user?.first_name ?? "").trim();
  if (explicit) return explicit;
  const fromName = String(user?.name ?? "").trim().split(/\s+/)[0];
  return fromName || "Utilizator";
}

function buildTileMapHtml(point, stations, selectedStation) {
  const zoom = 15;
  const tileSize = 256;
  const centerLat = Number(point?.mapLatitude ?? DEFAULT_CENTER.latitude);
  const centerLon = Number(point?.mapLongitude ?? DEFAULT_CENTER.longitude);
  const center = latLonToWorld(centerLat, centerLon, zoom, tileSize);
  const centerTileX = Math.floor(center.x / tileSize);
  const centerTileY = Math.floor(center.y / tileSize);
  const tiles = [];

  for (let dx = -3; dx <= 3; dx += 1) {
    for (let dy = -4; dy <= 4; dy += 1) {
      const x = centerTileX + dx;
      const y = centerTileY + dy;
      tiles.push({
        x,
        y,
        left: x * tileSize - center.x,
        top: y * tileSize - center.y,
        url: `https://tile.openstreetmap.org/${zoom}/${x}/${y}.png`,
      });
    }
  }

  const markers = stations.map((station) => {
    const world = latLonToWorld(station.mapLatitude, station.mapLongitude, zoom, tileSize);
    const active = selectedStation && String(selectedStation.id) === String(station.id);
    return {
      active,
      color: markerColorForStation(station, colors),
      label: station.name,
      left: world.x - center.x,
      top: world.y - center.y,
    };
  });

  return `<!doctype html>
<html>
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" />
  <style>
    html, body, #map { margin: 0; width: 100%; height: 100%; overflow: hidden; background: #dfe8ec; }
    #map { position: relative; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
    .tile {
      position: absolute;
      width: ${tileSize}px;
      height: ${tileSize}px;
      object-fit: cover;
      user-select: none;
      -webkit-user-drag: none;
    }
    .marker {
      position: absolute;
      width: 18px;
      height: 18px;
      margin-left: -9px;
      margin-top: -9px;
      border-radius: 9px;
      border: 3px solid #07090f;
      box-shadow: 0 5px 14px rgba(0,0,0,.32);
      z-index: 4;
    }
    .marker.active {
      width: 24px;
      height: 24px;
      margin-left: -12px;
      margin-top: -12px;
      border-radius: 12px;
      box-shadow: 0 0 0 5px rgba(255,238,0,.24), 0 8px 18px rgba(0,0,0,.34);
      z-index: 5;
    }
    .label {
      position: absolute;
      transform: translate(-50%, 18px);
      max-width: 130px;
      padding: 5px 8px;
      border-radius: 999px;
      background: rgba(7,9,15,.82);
      color: #fff;
      font-size: 11px;
      font-weight: 800;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      z-index: 6;
      box-shadow: 0 6px 18px rgba(0,0,0,.22);
    }
    .attribution {
      position: absolute;
      left: 8px;
      bottom: 8px;
      z-index: 7;
      color: rgba(7,9,15,.72);
      font-size: 10px;
      background: rgba(255,255,255,.72);
      border-radius: 7px;
      padding: 3px 6px;
    }
  </style>
</head>
<body>
  <div id="map">
    ${tiles.map((tile) => `<img class="tile" src="${tile.url}" style="left: calc(50% + ${tile.left}px); top: calc(50% + ${tile.top}px);" />`).join("")}
    ${markers.map((marker) => `<div class="marker${marker.active ? " active" : ""}" style="left: calc(50% + ${marker.left}px); top: calc(50% + ${marker.top}px); background: ${marker.color};"></div>${marker.active ? `<div class="label" style="left: calc(50% + ${marker.left}px); top: calc(50% + ${marker.top}px);">${escapeHtml(marker.label)}</div>` : ""}`).join("")}
    <div class="attribution">OpenStreetMap</div>
  </div>
</body>
</html>`;
}

function latLonToWorld(latitude, longitude, zoom, tileSize) {
  const normalizedLatitude = Math.max(-85.05112878, Math.min(85.05112878, Number(latitude)));
  const normalizedLongitude = Math.max(-180, Math.min(180, Number(longitude)));
  const sinLatitude = Math.sin((normalizedLatitude * Math.PI) / 180);
  const scale = tileSize * 2 ** zoom;

  return {
    x: ((normalizedLongitude + 180) / 360) * scale,
    y: (0.5 - Math.log((1 + sinLatitude) / (1 - sinLatitude)) / (4 * Math.PI)) * scale,
  };
}

function escapeHtml(value) {
  return String(value || "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

const styles = StyleSheet.create({
  page: {
    flex: 1,
    backgroundColor: colors.bg,
  },
  map: {
    ...StyleSheet.absoluteFillObject,
  },
  center: {
    flex: 1,
    backgroundColor: colors.bg,
    alignItems: "center",
    justifyContent: "center",
    padding: 24,
    gap: 10,
  },
  errorTitle: {
    color: colors.text,
    fontSize: 16,
    fontWeight: "900",
    marginTop: 4,
  },
  errorText: {
    color: colors.textMuted,
    fontSize: 12,
    fontWeight: "700",
    textAlign: "center",
    lineHeight: 18,
  },
  retryButton: {
    marginTop: 8,
    minHeight: 42,
    paddingHorizontal: 16,
    borderRadius: 12,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accent,
  },
  retryButtonText: {
    color: colors.accentText,
    fontSize: 13,
    fontWeight: "900",
  },
  inlineErrorBanner: {
    position: "absolute",
    top: 118,
    left: 12,
    right: 12,
    zIndex: 12,
    borderRadius: 12,
    paddingHorizontal: 12,
    paddingVertical: 10,
    backgroundColor: "rgba(255,77,103,0.14)",
    borderWidth: 1,
    borderColor: "rgba(255,77,103,0.24)",
  },
  inlineErrorText: {
    color: colors.danger,
    fontSize: 12,
    fontWeight: "800",
    textAlign: "center",
  },
  mapUnavailableCard: {
    width: "88%",
    borderRadius: layout.cardRadiusMd,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
    padding: 14,
    alignItems: "center",
    gap: 6,
  },
  mapUnavailableTitle: {
    color: colors.text,
    fontSize: 16,
    fontWeight: "900",
  },
  mapUnavailableText: {
    color: colors.textMuted,
    textAlign: "center",
    fontSize: 12,
    fontWeight: "700",
  },
  roundButton: {
    width: 38,
    height: 38,
    borderRadius: 19,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "rgba(21,27,41,0.92)",
    borderWidth: 1,
    borderColor: "rgba(255,255,255,0.12)",
  },
  screenHeader: {
    position: "absolute",
    top: 8,
    left: 12,
    right: 12,
    zIndex: 20,
    borderRadius: 22,
    overflow: "hidden",
    backgroundColor: colors.glass,
    borderWidth: 1,
    borderColor: colors.borderSoft,
    shadowColor: "#000",
    shadowOpacity: 0.28,
    shadowOffset: { width: 0, height: 10 },
    shadowRadius: 18,
    elevation: 10,
  },
  screenHeaderRow: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    gap: 12,
    paddingHorizontal: 12,
    paddingVertical: 11,
  },
  screenHeaderIdentity: {
    flex: 1,
    minWidth: 0,
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
  },
  avatarShell: {
    width: 42,
    height: 42,
    borderRadius: 21,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accent,
    borderWidth: 2,
    borderColor: "rgba(255,255,255,0.18)",
  },
  avatarText: {
    color: colors.accentText,
    fontSize: 14,
    fontWeight: "900",
    letterSpacing: 0.4,
  },
  screenHeaderCopy: {
    flex: 1,
    minWidth: 0,
  },
  screenHeaderGreeting: {
    color: colors.textMuted,
    fontSize: 11,
    fontWeight: "700",
  },
  screenHeaderName: {
    color: colors.text,
    fontSize: 16,
    fontWeight: "900",
    marginTop: 1,
    letterSpacing: -0.2,
  },
  walletCard: {
    flexShrink: 0,
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
    height: 36,
    borderRadius: 18,
    paddingLeft: 12,
    paddingRight: 4,
    backgroundColor: "rgba(255,238,0,0.1)",
    borderWidth: 1,
    borderColor: "rgba(255,238,0,0.22)",
  },
  walletCardPressed: {
    opacity: 0.88,
    transform: [{ scale: 0.98 }],
  },
  walletCardPlus: {
    width: 28,
    height: 28,
    borderRadius: 14,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accent,
  },
  walletCardAmount: {
    color: colors.accent,
    fontSize: 13,
    fontWeight: "900",
    letterSpacing: -0.2,
  },
  brandMark: {
    width: 38,
    height: 38,
    borderRadius: 14,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accent,
  },
  bottomDock: {
    position: "absolute",
    left: 12,
    right: 12,
    zIndex: 12,
    gap: DOCK_GAP,
  },
  stationRailWrap: {
    width: "100%",
  },
  stationRail: {
    paddingHorizontal: 0,
    gap: 6,
    alignItems: "center",
  },
  stationPill: {
    height: 34,
    maxWidth: 148,
    borderRadius: 17,
    paddingHorizontal: 10,
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
    backgroundColor: colors.overlay,
    borderWidth: 1,
    borderColor: colors.borderSoft,
  },
  stationPillActive: {
    backgroundColor: colors.accent,
    borderColor: colors.accent,
  },
  stationPillText: {
    flexShrink: 1,
    color: colors.text,
    fontSize: 11,
    fontWeight: "900",
  },
  stationPillTextActive: {
    color: colors.accentText,
  },
  dot: {
    width: 8,
    height: 8,
    borderRadius: 4,
  },
  cardWrap: {
    width: "100%",
  },
  stationCard: {
    borderRadius: layout.cardRadiusMd,
    paddingHorizontal: 12,
    paddingVertical: 10,
    gap: 6,
    backgroundColor: colors.glass,
    borderWidth: 1,
    borderColor: colors.borderSoft,
  },
  headerRow: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    gap: 10,
  },
  titleBlock: {
    flex: 1,
  },
  stationName: {
    color: colors.text,
    fontSize: 14,
    fontWeight: "900",
  },
  stationLocation: {
    color: colors.textMuted,
    fontSize: 11,
    fontWeight: "700",
    marginTop: 1,
  },
  metaRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: 6,
  },
  statusPill: {
    borderRadius: 999,
    backgroundColor: "rgba(77,255,161,0.12)",
    borderWidth: 1,
    borderColor: "rgba(77,255,161,0.28)",
    paddingHorizontal: 8,
    paddingVertical: 4,
  },
  statusPillText: {
    color: colors.success,
    fontSize: 10,
    fontWeight: "800",
  },
  pricePill: {
    flex: 1,
    minWidth: 0,
    minHeight: 24,
    borderRadius: 999,
    paddingHorizontal: 8,
    flexDirection: "row",
    alignItems: "center",
    gap: 4,
    backgroundColor: "rgba(21,27,41,0.9)",
    borderWidth: 1,
    borderColor: "rgba(255,255,255,0.08)",
  },
  priceText: {
    flexShrink: 1,
    color: colors.text,
    fontSize: 10,
    fontWeight: "900",
  },
  directionInlineButton: {
    width: 36,
    height: 36,
    borderRadius: 12,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "rgba(21,27,41,0.92)",
    borderWidth: 1,
    borderColor: "rgba(255,255,255,0.12)",
  },
  buttonPress: {
    opacity: motion.pressOpacity,
    transform: [{ scale: motion.pressScale }],
  },
  subtlePress: {
    opacity: motion.subtlePressOpacity,
    transform: [{ scale: motion.subtlePressScale }],
  },
});
