import React, { useMemo, useState } from "react";
import {
  ActivityIndicator,
  Alert,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from "react-native";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { useAuth } from "../context/AuthContext";
import { getApiErrorMessage } from "../utils/apiErrors";
import { colors, layout, motion, typography } from "../theme";

export default function ProfileScreen() {
  const insets = useSafeAreaInsets();
  const { user, updateProfile, logout } = useAuth();
  const [name, setName] = useState(user?.name || "");
  const [phone, setPhone] = useState(user?.phone || "");
  const [loading, setLoading] = useState(false);

  const canSave = useMemo(() => Boolean(name.trim()) && !loading, [name, loading]);

  const handleSave = async () => {
    const trimmedName = name.trim();
    if (!trimmedName) {
      Alert.alert("Profil", "Numele este obligatoriu.");
      return;
    }

    try {
      setLoading(true);
      await updateProfile({
        name: trimmedName,
        phone: phone.trim() || null,
      });
      Alert.alert("Profil", "Datele au fost actualizate.");
    } catch (error) {
      Alert.alert("Profil", getApiErrorMessage(error));
    } finally {
      setLoading(false);
    }
  };

  return (
    <ScrollView
      style={styles.page}
      contentContainerStyle={[styles.content, { paddingBottom: Math.max(insets.bottom, 12) + 96 }]}
      keyboardShouldPersistTaps="handled"
    >
      <View style={styles.heroCard}>
        <View style={styles.heroTop}>
          <View style={styles.avatarShell}>
            <MaterialCommunityIcons name="account-circle" size={44} color={colors.accentText} />
          </View>
          <View style={{ flex: 1 }}>
            <Text style={styles.heroName} numberOfLines={1}>
              {user?.name || "Utilizator"}
            </Text>
            <Text style={styles.heroEmail} numberOfLines={1}>
              {user?.email || "-"}
            </Text>
          </View>
        </View>
      </View>

      <View style={styles.card}>
        <Text style={styles.title}>Profil</Text>
        <Text style={styles.subtitle}>Gestioneaza datele tale de cont.</Text>

        <Text style={styles.label}>Email</Text>
        <View style={styles.readonlyField}>
          <Text style={styles.readonlyText}>{user?.email || "-"}</Text>
        </View>

        <Text style={styles.label}>Nume</Text>
        <TextInput
          style={styles.input}
          value={name}
          onChangeText={setName}
          placeholder="Nume complet"
          placeholderTextColor={colors.textMuted}
        />

        <Text style={styles.label}>Telefon</Text>
        <TextInput
          style={styles.input}
          value={phone}
          onChangeText={setPhone}
          keyboardType="phone-pad"
          placeholder="+373 ..."
          placeholderTextColor={colors.textMuted}
        />

        <Pressable
          style={({ pressed }) => [
            styles.primaryButton,
            !canSave && styles.buttonDisabled,
            pressed && styles.buttonPressed,
          ]}
          onPress={handleSave}
          disabled={!canSave}
        >
          {loading ? (
            <ActivityIndicator color={colors.accentText} />
          ) : (
            <Text style={styles.primaryButtonText}>Salveaza modificarile</Text>
          )}
        </Pressable>
      </View>

      <Pressable
        style={({ pressed }) => [styles.logoutButton, pressed && styles.subtleButtonPressed]}
        onPress={() =>
          Alert.alert("Deconectare", "Sigur vrei sa te deconectezi?", [
            { text: "Anuleaza", style: "cancel" },
            {
              text: "Deconectare",
              style: "destructive",
              onPress: () => {
                void logout().catch(() => null);
              },
            },
          ])
        }
      >
        <Text style={styles.logoutButtonText}>Deconectare</Text>
      </Pressable>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  page: {
    flex: 1,
    backgroundColor: colors.bg,
  },
  content: {
    padding: layout.pagePadding,
    gap: 12,
  },
  card: {
    borderRadius: layout.cardRadiusLg,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
    padding: 14,
  },
  heroCard: {
    borderRadius: layout.cardRadiusLg,
    borderWidth: 1,
    borderColor: "rgba(255,255,255,0.08)",
    backgroundColor: colors.bgSoft,
    padding: 12,
  },
  heroTop: {
    flexDirection: "row",
    alignItems: "center",
    gap: 12,
  },
  avatarShell: {
    width: 62,
    height: 62,
    borderRadius: 31,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accent,
  },
  heroName: {
    color: colors.text,
    fontSize: 18,
    fontWeight: "900",
  },
  heroEmail: {
    color: colors.textMuted,
    marginTop: 2,
    fontSize: 12,
    fontWeight: "700",
  },
  title: {
    color: colors.text,
    ...typography.titleMd,
  },
  subtitle: {
    color: colors.textMuted,
    ...typography.subtitle,
    marginTop: 4,
    marginBottom: 10,
  },
  label: {
    color: colors.textMuted,
    ...typography.label,
    marginBottom: 6,
    marginTop: 8,
  },
  input: {
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.inputRadius,
    backgroundColor: colors.bgSoft,
    color: colors.text,
    paddingHorizontal: 12,
    paddingVertical: 11,
  },
  readonlyField: {
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.inputRadius,
    backgroundColor: colors.bgSoft,
    paddingHorizontal: 12,
    paddingVertical: 11,
  },
  readonlyText: {
    color: colors.text,
    fontWeight: "700",
  },
  primaryButton: {
    minHeight: layout.buttonHeight,
    borderRadius: layout.buttonRadius,
    marginTop: 16,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accent,
  },
  primaryButtonText: {
    color: colors.accentText,
    ...typography.button,
  },
  buttonDisabled: {
    opacity: 0.6,
  },
  buttonPressed: {
    opacity: motion.pressOpacity,
    transform: [{ scale: motion.pressScale }],
  },
  subtleButtonPressed: {
    opacity: motion.subtlePressOpacity,
    transform: [{ scale: motion.subtlePressScale }],
  },
  logoutButton: {
    minHeight: layout.buttonHeight,
    borderRadius: layout.buttonRadius,
    alignItems: "center",
    justifyContent: "center",
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.bgSoft,
  },
  logoutButtonText: {
    color: colors.danger,
    ...typography.button,
  },
});
