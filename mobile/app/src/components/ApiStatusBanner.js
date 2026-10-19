import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { useApiStatus } from "../hooks/useApiStatus";
import { colors } from "../theme";

/** Doar semnal slab / retry — fara banner „Se sincronizeaza datele”. */
export default function ApiStatusBanner() {
  const { retryingRequests } = useApiStatus();

  if (retryingRequests <= 0) {
    return null;
  }

  return (
    <View style={[styles.banner, styles.retryBanner]}>
      <Text style={styles.bannerText}>Semnal slab. Reincercam...</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  banner: {
    marginHorizontal: 14,
    marginTop: 6,
    marginBottom: 4,
    paddingVertical: 8,
    paddingHorizontal: 12,
    borderRadius: 14,
    backgroundColor: colors.accentSoft,
    borderWidth: 1,
    borderColor: colors.borderAccent,
  },
  retryBanner: {
    backgroundColor: colors.dangerSoft,
    borderColor: "rgba(255,77,103,0.28)",
  },
  bannerText: {
    color: colors.textSoft,
    fontSize: 12,
    fontWeight: "700",
    textAlign: "center",
    letterSpacing: 0.1,
  },
});
