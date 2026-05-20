import React, { useMemo, useRef, useState } from "react";
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
import { useAuth } from "../context/AuthContext";
import { getApiErrorMessage } from "../utils/apiErrors";
import { colors, layout, typography } from "../theme";

export default function LoginScreen() {
  const navigation = useNavigation();
  const { login } = useAuth();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [loading, setLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const passwordInputRef = useRef(null);

  const canSubmit = useMemo(() => Boolean(email.trim() && password && !loading), [email, password, loading]);

  const handleLogin = async () => {
    const trimmedEmail = email.trim();
    if (!trimmedEmail || !password) {
      Alert.alert("Autentificare", "Completeaza e-mailul si parola.");
      return;
    }

    try {
      setLoading(true);
      await login(trimmedEmail, password);
    } catch (error) {
      Alert.alert("Autentificare esuata", getApiErrorMessage(error));
    } finally {
      setLoading(false);
    }
  };

  return (
    <View style={styles.page}>
      <SafeAreaView style={styles.flex} edges={["top", "bottom"]}>
        <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === "ios" ? "padding" : undefined}>
          <ScrollView
            contentContainerStyle={styles.scroll}
            keyboardShouldPersistTaps="handled"
            showsVerticalScrollIndicator={false}
          >
            <View style={styles.card}>
              <View style={styles.headerRow}>
                <View style={styles.logoShell}>
                  <MaterialCommunityIcons name="ev-station" size={26} color={colors.accentText} />
                </View>
                <View style={{ flex: 1 }}>
                  <Text style={styles.kicker}>VOLTA EV</Text>
                  <Text style={styles.title}>Autentificare</Text>
                </View>
              </View>

              <Text style={styles.sub}>Acceseaza rapid statiile, sesiunile si facturile.</Text>

              <Text style={styles.label}>E-mail</Text>
              <View style={styles.inputWrap}>
                <MaterialCommunityIcons name="email-outline" size={18} color={colors.textMuted} />
                <TextInput
                  style={styles.input}
                  value={email}
                  onChangeText={setEmail}
                  autoCapitalize="none"
                  autoCorrect={false}
                  autoComplete="email"
                  keyboardType="email-address"
                  textContentType="username"
                  placeholder="ex. ion@firma.md"
                  placeholderTextColor={colors.textMuted}
                  returnKeyType="next"
                  onSubmitEditing={() => passwordInputRef.current?.focus()}
                />
              </View>

              <Text style={styles.label}>Parola</Text>
              <View style={styles.inputWrap}>
                <MaterialCommunityIcons name="lock-outline" size={18} color={colors.textMuted} />
                <TextInput
                  ref={passwordInputRef}
                  style={styles.input}
                  value={password}
                  onChangeText={setPassword}
                  secureTextEntry={!showPassword}
                  autoCorrect={false}
                  autoComplete="password"
                  textContentType="password"
                  placeholder="Parola"
                  placeholderTextColor={colors.textMuted}
                  returnKeyType="go"
                  onSubmitEditing={handleLogin}
                />
                <Pressable onPress={() => setShowPassword((current) => !current)} hitSlop={12} style={styles.trailingIcon}>
                  <MaterialCommunityIcons
                    name={showPassword ? "eye-off-outline" : "eye-outline"}
                    size={20}
                    color={colors.textMuted}
                  />
                </Pressable>
              </View>

              <Pressable
                style={({ pressed }) => [
                  styles.primaryBtn,
                  !canSubmit && styles.primaryBtnDisabled,
                  pressed && canSubmit && styles.primaryBtnPressed,
                ]}
                onPress={handleLogin}
                disabled={!canSubmit}
              >
                {loading ? (
                  <View style={styles.loadingRow}>
                    <ActivityIndicator color={colors.accentText} />
                    <Text style={styles.primaryBtnText}>Se conecteaza...</Text>
                  </View>
                ) : (
                  <Text style={styles.primaryBtnText}>Autentificare</Text>
                )}
              </Pressable>

              <Pressable style={styles.secondaryBtn} onPress={() => navigation.navigate("Register")}>
                <Text style={styles.secondaryBtnText}>Cere cont nou</Text>
              </Pressable>
            </View>
          </ScrollView>
        </KeyboardAvoidingView>
      </SafeAreaView>
    </View>
  );
}

const styles = StyleSheet.create({
  page: { flex: 1, backgroundColor: colors.bg },
  flex: { flex: 1 },
  scroll: { flexGrow: 1, justifyContent: "center", paddingHorizontal: 20, paddingVertical: 28 },
  card: {
    borderRadius: layout.cardRadiusLg,
    padding: 20,
    borderWidth: 1,
    borderColor: "rgba(255,255,255,0.08)",
    backgroundColor: colors.bgSoft,
  },
  headerRow: { flexDirection: "row", alignItems: "center", gap: 12, marginBottom: 10 },
  logoShell: {
    width: 48,
    height: 48,
    borderRadius: 24,
    backgroundColor: colors.accent,
    alignItems: "center",
    justifyContent: "center",
  },
  kicker: { color: colors.accent, fontSize: 10, fontWeight: "900", letterSpacing: 1.2 },
  title: { color: colors.text, ...typography.titleMd },
  sub: { color: colors.textMuted, ...typography.subtitle, marginBottom: 14 },
  label: { color: colors.textMuted, ...typography.label, marginBottom: 6, marginTop: 4 },
  inputWrap: {
    flexDirection: "row",
    alignItems: "center",
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.inputRadius,
    backgroundColor: colors.card,
    paddingHorizontal: 12,
  },
  input: { flex: 1, color: colors.text, paddingHorizontal: 12, paddingVertical: 12, fontSize: 15 },
  trailingIcon: { paddingLeft: 8 },
  primaryBtn: {
    marginTop: 18,
    minHeight: layout.buttonHeight,
    borderRadius: layout.buttonRadius,
    backgroundColor: colors.accent,
    alignItems: "center",
    justifyContent: "center",
  },
  primaryBtnPressed: { opacity: 0.92 },
  primaryBtnDisabled: { opacity: 0.55 },
  primaryBtnText: { color: colors.accentText, ...typography.button },
  loadingRow: { flexDirection: "row", alignItems: "center", gap: 10 },
  secondaryBtn: {
    marginTop: 10,
    minHeight: layout.buttonHeight,
    borderRadius: layout.buttonRadius,
    alignItems: "center",
    justifyContent: "center",
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
  },
  secondaryBtnText: { color: colors.text, ...typography.button },
});
