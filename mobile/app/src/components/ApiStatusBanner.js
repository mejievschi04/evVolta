import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { useApiStatus } from "../hooks/useApiStatus";
import { colors } from "../theme";

export default function ApiStatusBanner() {
  const { pendingRequests, retryingRequests } = useApiStatus();

  if (retryingRequests > 0) {
    return (
      <View style={[styles.banner, styles.retryBanner]}>
        <Text style={styles.bannerText}>Semnal slab. Reincercam sincronizarea...</Text>
      </View>
    );
  }

  if (pendingRequests > 0) {
    return (
      <View style={styles.banner}>
        <Text style={styles.bannerText}>Se sincronizeaza datele...</Text>
      </View>
    );
  }

  return null;
}

const styles = StyleSheet.create({
  banner: {
    marginHorizontal: 12,
    marginTop: 8,
    marginBottom: 6,
    paddingVertical: 8,
    paddingHorizontal: 12,
    borderRadius: 12,
    backgroundColor: "rgba(255,238,0,0.12)",
    borderWidth: 1,
    borderColor: "rgba(255,238,0,0.28)",
  },
  retryBanner: {
    backgroundColor: "rgba(255,77,103,0.14)",
    borderColor: "rgba(255,77,103,0.35)",
  },
  bannerText: {
    color: colors.text,
    fontSize: 12,
    fontWeight: "700",
    textAlign: "center",
    letterSpacing: 0.2,
  },
});