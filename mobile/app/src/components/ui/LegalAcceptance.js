import React, { useEffect, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import apiClient from "../../api/client";
import { colors, layout } from "../../theme";

export function LegalAcceptance({ checked, onChange, onOpenTerms, onOpenPrivacy, hint, compact = false }) {
  const [labels, setLabels] = useState({
    terms: "Termeni si conditii",
    privacy: "Politica de confidentialitate",
  });

  useEffect(() => {
    let cancelled = false;

    void apiClient
      .get("/legal", { _skipAuthRefresh: true })
      .then((response) => {
        if (cancelled) {
          return;
        }

        setLabels({
          terms: response.data?.terms?.title || "Termeni si conditii",
          privacy: response.data?.privacy?.title || "Politica de confidentialitate",
        });
      })
      .catch(() => null);

    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <View style={[styles.card, checked && styles.cardChecked, compact && styles.cardCompact]}>
      <Pressable
        accessibilityRole="checkbox"
        accessibilityState={{ checked }}
        hitSlop={8}
        onPress={() => onChange(!checked)}
        style={({ pressed }) => [styles.checkbox, checked && styles.checkboxChecked, pressed && styles.pressed]}
      >
        {checked ? <MaterialCommunityIcons color={colors.accentText} name="check" size={14} /> : null}
      </Pressable>
      <View style={styles.copy}>
        <Text style={[styles.text, compact && styles.textCompact]}>
          {compact ? "Accept" : "Am citit si accept"}{" "}
          <Text onPress={onOpenTerms} style={styles.link}>
            {labels.terms}
          </Text>
          {" "}si{" "}
          <Text onPress={onOpenPrivacy} style={styles.link}>
            {labels.privacy}
          </Text>
          .
        </Text>
        {compact ? null : (
          <Text style={styles.hint}>
            {hint || "Obligatoriu pentru autentificare si creare cont"}
          </Text>
        )}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: 12,
    padding: 14,
    borderRadius: layout.cardRadiusSm,
    borderWidth: 1,
    borderColor: colors.borderSoft,
    backgroundColor: "rgba(18, 24, 39, 0.72)",
  },
  cardCompact: {
    alignItems: "center",
    gap: 10,
    padding: 0,
    paddingHorizontal: 2,
    borderWidth: 0,
    backgroundColor: "transparent",
  },
  cardChecked: {
    borderColor: "rgba(255, 238, 0, 0.28)",
    backgroundColor: "rgba(255, 238, 0, 0.06)",
  },
  checkbox: {
    width: 22,
    height: 22,
    borderRadius: 7,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.bgSoft,
    alignItems: "center",
    justifyContent: "center",
    marginTop: 1,
  },
  checkboxChecked: {
    borderColor: colors.accent,
    backgroundColor: colors.accent,
  },
  copy: {
    flex: 1,
    gap: 4,
  },
  text: {
    color: colors.textSoft,
    fontSize: 13,
    fontWeight: "600",
    lineHeight: 19,
  },
  textCompact: {
    fontSize: 12,
    lineHeight: 16,
  },
  hint: {
    color: colors.textMuted,
    fontSize: 11,
    fontWeight: "600",
    lineHeight: 15,
  },
  link: {
    color: colors.accent,
    fontWeight: "800",
  },
  pressed: {
    opacity: 0.88,
  },
});
