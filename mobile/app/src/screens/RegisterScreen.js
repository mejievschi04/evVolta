import React, { useMemo, useState } from "react";
import {
  ActivityIndicator,
  Alert,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from "react-native";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { useNavigation } from "@react-navigation/native";
import { SafeAreaView } from "react-native-safe-area-context";
import apiClient from "../api/client";
import { getApiErrorMessage } from "../utils/apiErrors";
import { colors, layout, typography } from "../theme";

function formatValidationMessage(error) {
  return getApiErrorMessage(error, "Incearca din nou.");
}

export default function RegisterScreen() {
  const navigation = useNavigation();
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [phone, setPhone] = useState("");
  const [message, setMessage] = useState("");
  const [loading, setLoading] = useState(false);

  const canSubmit = useMemo(() => {
    const trimmedName = name.trim();
    const trimmedEmail = email.trim();
    return Boolean(trimmedName && trimmedEmail && !loading);
  }, [email, loading, name]);

  const submit = async () => {
    const trimmedName = name.trim();
    const trimmedEmail = email.trim();

    if (!trimmedName || !trimmedEmail) {
      Alert.alert("Cerere cont", "Completeaza numele si e-mailul.");
      return;
    }

    try {
      setLoading(true);
      const { data } = await apiClient.post("/register-request", {
        name: trimmedName,
        email: trimmedEmail,
        phone: phone.trim() || null,
        message: message.trim() || null,
      });
      Alert.alert("Trimis", data?.message ?? "Cererea a fost inregistrata.", [
        { text: "OK", onPress: () => navigation.navigate("Login") },
      ]);
    } catch (error) {
      Alert.alert("Nu s-a putut trimite", formatValidationMessage(error));
    } finally {
      setLoading(false);
    }
  };

  return (
    <View style={styles.page}>
      <View style={styles.bgAccentTop} pointerEvents="none" />
      <View style={styles.bgAccentBottom} pointerEvents="none" />

      <SafeAreaView style={styles.flex} edges={["top", "bottom"]}>
        <KeyboardAvoidingView
          style={styles.flex}
          behavior={Platform.OS === "ios" ? "padding" : undefined}
        >
          <ScrollView
            contentContainerStyle={styles.scroll}
            keyboardShouldPersistTaps="handled"
            showsVerticalScrollIndicator={false}
          >
            <View style={styles.card}>
              <View style={styles.brandRow}>
                <View style={styles.logoShell}>
                  <MaterialCommunityIcons name="ev-station" size={24} color={colors.accentText} />
                </View>
                <View style={styles.brandTexts}>
                  <Text style={styles.badge}>CERERE CONT</Text>
                  <Text style={styles.title}>Inregistrare</Text>
                </View>
              </View>
              <Text style={styles.sub}>
                Contul se activeaza dupa ce un administrator aproba cererea ta. Vei primi acces cand ti se
                comunica parola initiala.
              </Text>
              <View style={styles.stepsRow}>
                <View style={styles.stepCard}>
                  <MaterialCommunityIcons name="account-check-outline" size={16} color={colors.accent} />
                  <Text style={styles.stepText}>Cerere</Text>
                </View>
                <View style={styles.stepCard}>
                  <MaterialCommunityIcons name="shield-check-outline" size={16} color={colors.accent} />
                  <Text style={styles.stepText}>Aprobare</Text>
                </View>
                <View style={styles.stepCard}>
                  <MaterialCommunityIcons name="login-variant" size={16} color={colors.accent} />
                  <Text style={styles.stepText}>Activare</Text>
                </View>
              </View>

              <Text style={styles.label}>Nume complet</Text>
              <TextInput
                style={styles.input}
                value={name}
                onChangeText={setName}
                autoCapitalize="words"
                placeholder="ex. Ion Popescu"
                placeholderTextColor={colors.textMuted}
              />

              <Text style={styles.label}>E-mail</Text>
              <TextInput
                style={styles.input}
                value={email}
                onChangeText={setEmail}
                autoCapitalize="none"
                autoCorrect={false}
                keyboardType="email-address"
                autoComplete="email"
                textContentType="emailAddress"
                placeholder="ex. ion@firma.md"
                placeholderTextColor={colors.textMuted}
              />

              <Text style={styles.label}>Telefon (optional)</Text>
              <TextInput
                style={styles.input}
                value={phone}
                onChangeText={setPhone}
                keyboardType="phone-pad"
                placeholder="+373 ..."
                placeholderTextColor={colors.textMuted}
              />

              <Text style={styles.label}>Mesaj pentru admin (optional)</Text>
              <TextInput
                style={[styles.input, styles.textArea]}
                value={message}
                onChangeText={setMessage}
                placeholder="ex. Vehicul flota, punct de lucru..."
                placeholderTextColor={colors.textMuted}
                multiline
                numberOfLines={4}
                textAlignVertical="top"
              />

              <Pressable
                style={({ pressed }) => [
                  styles.primaryBtn,
                  !canSubmit && styles.primaryBtnDisabled,
                  pressed && canSubmit && styles.primaryBtnPressed,
                ]}
                onPress={submit}
                disabled={!canSubmit}
              >
                {loading ? (
                  <View style={styles.loadingRow}>
                    <ActivityIndicator color={colors.accentText} />
                    <Text style={styles.primaryBtnText}>Se trimite...</Text>
                  </View>
                ) : (
                  <Text style={styles.primaryBtnText}>Trimite cererea</Text>
                )}
              </Pressable>

              <Pressable style={styles.linkWrap} onPress={() => navigation.navigate("Login")}>
                <Text style={styles.link}>Ai deja cont? Autentificare</Text>
              </Pressable>
            </View>
          </ScrollView>
        </KeyboardAvoidingView>
      </SafeAreaView>
    </View>
  );
}

const styles = StyleSheet.create({
  page: {
    flex: 1,
    backgroundColor: colors.bg,
  },
  flex: { flex: 1 },
  bgAccentTop: {
    position: "absolute",
    top: -160,
    right: -120,
    width: 340,
    height: 340,
    borderRadius: 999,
    backgroundColor: "rgba(255,238,0,0.1)",
  },
  bgAccentBottom: {
    position: "absolute",
    bottom: -160,
    left: -140,
    width: 360,
    height: 360,
    borderRadius: 999,
    backgroundColor: "rgba(255,238,0,0.07)",
  },
  scroll: {
    flexGrow: 1,
    paddingHorizontal: 20,
    paddingVertical: 28,
    justifyContent: "center",
  },
  card: {
    borderRadius: layout.cardRadiusLg,
    padding: 22,
    borderWidth: 1,
    borderColor: "rgba(255,255,255,0.08)",
    backgroundColor: colors.bgSoft,
    shadowColor: "#000",
    shadowOpacity: 0.3,
    shadowRadius: 22,
    shadowOffset: { width: 0, height: 14 },
    elevation: 6,
  },
  brandRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: 18,
    marginBottom: 12,
  },
  brandTexts: {
    flex: 1,
    justifyContent: "center",
    gap: 8,
  },
  logoShell: {
    width: 52,
    height: 52,
    borderRadius: 26,
    backgroundColor: colors.accent,
    alignItems: "center",
    justifyContent: "center",
  },
  badge: {
    alignSelf: "flex-start",
    color: colors.accentText,
    backgroundColor: colors.accent,
    fontSize: 10,
    fontWeight: "900",
    letterSpacing: 1.4,
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 8,
    overflow: "hidden",
  },
  title: {
    color: colors.text,
    ...typography.titleMd,
  },
  sub: {
    color: colors.textMuted,
    ...typography.subtitle,
    lineHeight: 19,
    marginBottom: 12,
  },
  stepsRow: {
    flexDirection: "row",
    gap: 8,
    marginBottom: 14,
  },
  stepCard: {
    flex: 1,
    minHeight: 42,
    borderRadius: layout.cardRadiusSm,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
    alignItems: "center",
    justifyContent: "center",
    gap: 2,
  },
  stepText: {
    color: colors.text,
    fontSize: 11,
    fontWeight: "800",
  },
  label: {
    color: colors.textMuted,
    ...typography.label,
    marginBottom: 6,
    marginTop: 4,
  },
  input: {
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.inputRadius,
    backgroundColor: colors.card,
    color: colors.text,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 15,
  },
  textArea: { minHeight: 100, paddingTop: 12 },
  primaryBtn: {
    marginTop: 18,
    backgroundColor: colors.accent,
    borderRadius: layout.buttonRadius,
    paddingVertical: 14,
    alignItems: "center",
  },
  primaryBtnPressed: {
    opacity: 0.92,
  },
  primaryBtnDisabled: {
    opacity: 0.55,
  },
  primaryBtnText: {
    color: colors.accentText,
    ...typography.button,
    letterSpacing: 0.6,
  },
  loadingRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
  },
  linkWrap: { marginTop: 16, alignItems: "center" },
  link: { color: colors.accent, ...typography.button },
});
