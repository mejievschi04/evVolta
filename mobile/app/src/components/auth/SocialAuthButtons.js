import React, { useEffect, useState } from "react";
import { ActivityIndicator, Platform, Pressable, StyleSheet, Text, View } from "react-native";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { colors, layout, typography } from "../../theme";
import { isAppleAuthAvailable } from "../../utils/socialAuth";

export function SocialAuthButtons({
  disabled = false,
  loadingProvider = null,
  onApplePress,
  onGooglePress,
}) {
  const [appleAvailable, setAppleAvailable] = useState(Platform.OS === "ios");

  useEffect(() => {
    let mounted = true;
    isAppleAuthAvailable().then((available) => {
      if (mounted) {
        setAppleAvailable(available);
      }
    });
    return () => {
      mounted = false;
    };
  }, []);

  const blocked = disabled || Boolean(loadingProvider);

  return (
    <View style={styles.wrap}>
      <View style={styles.dividerRow}>
        <View style={styles.dividerLine} />
        <Text style={styles.dividerText}>sau continua cu</Text>
        <View style={styles.dividerLine} />
      </View>

      <View style={styles.buttonsRow}>
        <SocialButton
          accessibilityLabel="Continua cu Google"
          blocked={blocked}
          icon="google"
          iconColor="#EA4335"
          label={appleAvailable ? "Google" : "Continua cu Google"}
          loading={loadingProvider === "google"}
          onPress={onGooglePress}
        />

        {appleAvailable ? (
          <SocialButton
            accessibilityLabel="Continua cu Apple"
            blocked={blocked}
            icon="apple"
            iconColor={colors.text}
            label="Apple"
            loading={loadingProvider === "apple"}
            onPress={onApplePress}
          />
        ) : null}
      </View>
    </View>
  );
}

function SocialButton({ accessibilityLabel, blocked, icon, iconColor, label, loading, onPress }) {
  return (
    <Pressable
      accessibilityLabel={accessibilityLabel}
      accessibilityRole="button"
      disabled={blocked}
      onPress={onPress}
      style={({ pressed }) => [
        styles.button,
        blocked && !loading && styles.buttonDisabled,
        pressed && styles.buttonPressed,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={colors.text} />
      ) : (
        <>
          <MaterialCommunityIcons color={iconColor} name={icon} size={20} />
          <Text style={styles.buttonText} numberOfLines={1}>
            {label}
          </Text>
        </>
      )}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  wrap: {
    gap: 10,
  },
  dividerRow: {
    alignItems: "center",
    flexDirection: "row",
    gap: 10,
  },
  buttonsRow: {
    flexDirection: "row",
    gap: 10,
  },
  dividerLine: {
    backgroundColor: colors.border,
    flex: 1,
    height: StyleSheet.hairlineWidth,
  },
  dividerText: {
    ...typography.meta,
    color: colors.textMuted,
  },
  button: {
    flex: 1,
    alignItems: "center",
    justifyContent: "center",
    flexDirection: "row",
    gap: 8,
    height: layout.buttonHeight,
    paddingHorizontal: 12,
    borderRadius: layout.buttonRadius,
    borderWidth: 1,
    borderColor: colors.borderSoft,
    backgroundColor: colors.bgSoft,
  },
  buttonDisabled: {
    opacity: 0.55,
  },
  buttonPressed: {
    opacity: 0.88,
    transform: [{ scale: 0.98 }],
  },
  buttonText: {
    ...typography.button,
    color: colors.text,
  },
});
