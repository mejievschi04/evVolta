import React from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { colors } from "../../theme";

export function RememberMeToggle({ checked, onChange }) {
  return (
    <Pressable
      accessibilityRole="checkbox"
      accessibilityState={{ checked }}
      hitSlop={8}
      onPress={() => onChange(!checked)}
      style={({ pressed }) => [styles.row, pressed && styles.pressed]}
    >
      <View style={[styles.checkbox, checked && styles.checkboxChecked]}>
        {checked ? <MaterialCommunityIcons color={colors.accentText} name="check" size={14} /> : null}
      </View>
      <Text style={styles.label}>Ramai autentificat pe acest telefon</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  row: {
    alignItems: "center",
    flexDirection: "row",
    gap: 10,
    paddingHorizontal: 2,
  },
  pressed: {
    opacity: 0.88,
  },
  checkbox: {
    alignItems: "center",
    backgroundColor: colors.bgSoft,
    borderColor: colors.border,
    borderRadius: 7,
    borderWidth: 1,
    height: 22,
    justifyContent: "center",
    width: 22,
  },
  checkboxChecked: {
    backgroundColor: colors.accent,
    borderColor: colors.accent,
  },
  label: {
    flex: 1,
    color: colors.textSoft,
    fontSize: 13,
    fontWeight: "700",
  },
});
