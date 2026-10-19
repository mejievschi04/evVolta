import React, { useCallback, useEffect, useSyncExternalStore } from "react";
import { AppState, Image, Linking, Modal, Platform, StyleSheet, Text, View } from "react-native";
import apiClient from "../api/client";
import {
  APP_VERSION,
  compareVersions,
  getUpdateState,
  markUpdateRequired,
  subscribeUpdateState,
} from "../api/appVersion";
import { PrimaryButton } from "./ui/ScreenUi";
import { colors } from "../theme";

const brandLogo = require("../../../assets/v-charge-logo.png");

const FALLBACK_STORE_URLS = {
  android: "market://details?id=com.mjsky.voltaev",
  ios: "itms-apps://apps.apple.com/app/id6794557570",
};

/**
 * Blocheaza aplicatia cand versiunea instalata e sub minimul cerut de server.
 * Se verifica la pornire, la revenirea in aplicatie si la orice raspuns 426 al API-ului.
 */
export default function ForcedUpdateGate() {
  const update = useSyncExternalStore(subscribeUpdateState, getUpdateState);

  const checkMinVersion = useCallback(async () => {
    try {
      const response = await apiClient.get("/app/config", { _skipAuthRefresh: true });
      const minVersion = response.data?.min_version;
      if (minVersion && compareVersions(APP_VERSION, minVersion) < 0) {
        markUpdateRequired({ storeUrl: response.data?.store_url, minVersion });
      }
    } catch {
      // Fara retea sau backend vechi: raspunsul 426 al oricarei cereri ramane plasa de siguranta.
    }
  }, []);

  useEffect(() => {
    void checkMinVersion();
    const subscription = AppState.addEventListener("change", (state) => {
      if (state === "active") {
        void checkMinVersion();
      }
    });
    return () => subscription.remove();
  }, [checkMinVersion]);

  if (!update) {
    return null;
  }

  const openStore = () => {
    const fallback = FALLBACK_STORE_URLS[Platform.OS] || FALLBACK_STORE_URLS.android;
    Linking.openURL(update.storeUrl || fallback).catch(() => Linking.openURL(fallback).catch(() => null));
  };

  return (
    // onRequestClose gol: butonul Inapoi de pe Android nu inchide ecranul.
    <Modal visible animationType="fade" onRequestClose={() => {}} statusBarTranslucent>
      <View style={styles.screen}>
        <Image accessibilityLabel="V CHARGE" source={brandLogo} style={styles.logo} resizeMode="contain" />
        <Text style={styles.title}>Actualizare necesara</Text>
        <Text style={styles.message}>{update.message}</Text>
        <Text style={styles.version}>
          Versiunea ta: {APP_VERSION}
          {update.minVersion ? ` · minim necesar: ${update.minVersion}` : ""}
        </Text>
        <View style={styles.action}>
          <PrimaryButton icon="download" label="Actualizeaza aplicatia" onPress={openStore} />
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    backgroundColor: colors.bg,
    alignItems: "center",
    justifyContent: "center",
    paddingHorizontal: 32,
    gap: 12,
  },
  logo: {
    width: 110,
    height: 110,
    marginBottom: 8,
  },
  title: {
    color: colors.text,
    fontSize: 22,
    fontWeight: "900",
    textAlign: "center",
  },
  message: {
    color: colors.textSoft,
    fontSize: 15,
    lineHeight: 22,
    textAlign: "center",
  },
  version: {
    color: colors.textMuted,
    fontSize: 12,
    textAlign: "center",
  },
  action: {
    alignSelf: "stretch",
    marginTop: 12,
  },
});
