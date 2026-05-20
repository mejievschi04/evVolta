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
import { colors, motion } from "../theme";

const C = {
  bg: colors.bg,
  accent: colors.accent,
  text: colors.text,
  onAccent: colors.accentText,
};

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
            <View style={styles.header}>
              <View style={styles.headerBadge}>
                <MaterialCommunityIcons name="ev-station" size={40} color={C.onAccent} />
              </View>
              <Text style={styles.eyebrow}>VOLTA EV</Text>
              <Text style={styles.title}>Autentificare</Text>
            </View>

            <View style={styles.formCard}>
              <Field
                label="E-mail"
                icon="email-outline"
                value={email}
                onChangeText={setEmail}
                placeholder="ex. ion@firma.md"
                keyboardType="email-address"
                autoCapitalize="none"
                autoComplete="email"
                textContentType="username"
                returnKeyType="next"
                onSubmitEditing={() => passwordInputRef.current?.focus()}
              />

              <Field
                ref={passwordInputRef}
                label="Parola"
                icon="lock-outline"
                value={password}
                onChangeText={setPassword}
                placeholder="Parola"
                secureTextEntry={!showPassword}
                autoComplete="password"
                textContentType="password"
                returnKeyType="go"
                onSubmitEditing={handleLogin}
                trailing={
                  <Pressable onPress={() => setShowPassword((v) => !v)} hitSlop={12}>
                    <MaterialCommunityIcons
                      name={showPassword ? "eye-off-outline" : "eye-outline"}
                      size={20}
                      color={C.text}
                      style={styles.mutedIcon}
                    />
                  </Pressable>
                }
              />

              <Pressable
                style={({ pressed }) => [
                  styles.primaryBtn,
                  !canSubmit && styles.btnDisabled,
                  pressed && canSubmit && styles.btnPressed,
                ]}
                onPress={handleLogin}
                disabled={!canSubmit}
              >
                {loading ? (
                  <View style={styles.loadingRow}>
                    <ActivityIndicator color={C.onAccent} />
                    <Text style={styles.primaryBtnText}>Se conecteaza...</Text>
                  </View>
                ) : (
                  <Text style={styles.primaryBtnText}>Autentificare</Text>
                )}
              </Pressable>
            </View>

            <Pressable
              style={({ pressed }) => [styles.linkBtn, pressed && styles.btnPressed]}
              onPress={() => navigation.navigate("Register")}
            >
              <Text style={styles.linkBtnText}>Cere cont nou</Text>
              <MaterialCommunityIcons name="arrow-right" size={18} color={C.accent} />
            </Pressable>
          </ScrollView>
        </KeyboardAvoidingView>
      </SafeAreaView>
    </View>
  );
}

const Field = React.forwardRef(function Field(
  {
    label,
    icon,
    value,
    onChangeText,
    placeholder,
    trailing,
    secureTextEntry,
    keyboardType,
    autoCapitalize,
    autoComplete,
    textContentType,
    returnKeyType,
    onSubmitEditing,
  },
  ref
) {
  return (
    <View style={styles.field}>
      <Text style={styles.fieldLabel}>{label}</Text>
      <View style={styles.inputWrap}>
        <MaterialCommunityIcons name={icon} size={18} color={C.accent} />
        <TextInput
          ref={ref}
          style={styles.input}
          value={value}
          onChangeText={onChangeText}
          placeholder={placeholder}
          placeholderTextColor="rgba(234, 240, 255, 0.4)"
          secureTextEntry={secureTextEntry}
          keyboardType={keyboardType}
          autoCapitalize={autoCapitalize}
          autoCorrect={false}
          autoComplete={autoComplete}
          textContentType={textContentType}
          returnKeyType={returnKeyType}
          onSubmitEditing={onSubmitEditing}
        />
        {trailing}
      </View>
    </View>
  );
});

const styles = StyleSheet.create({
  page: { flex: 1, backgroundColor: C.bg },
  flex: { flex: 1 },
  scroll: {
    flexGrow: 1,
    paddingHorizontal: 20,
    paddingTop: 48,
    paddingBottom: 32,
    gap: 24,
  },
  mutedIcon: { opacity: 0.55 },

  header: {
    alignItems: "center",
    gap: 6,
    paddingHorizontal: 8,
  },
  headerBadge: {
    width: 88,
    height: 88,
    borderRadius: 28,
    backgroundColor: C.accent,
    alignItems: "center",
    justifyContent: "center",
    marginBottom: 8,
  },
  eyebrow: {
    color: C.accent,
    fontSize: 10,
    fontWeight: "900",
    letterSpacing: 1.6,
  },
  title: {
    color: C.text,
    fontSize: 28,
    fontWeight: "900",
    letterSpacing: -0.5,
  },

  formCard: {
    borderRadius: 24,
    borderWidth: 1,
    borderColor: C.accent,
    backgroundColor: C.bg,
    padding: 18,
    gap: 14,
  },
  field: { gap: 6 },
  fieldLabel: {
    color: C.text,
    opacity: 0.55,
    fontSize: 11,
    fontWeight: "800",
    letterSpacing: 0.6,
    textTransform: "uppercase",
  },
  inputWrap: {
    flexDirection: "row",
    alignItems: "center",
    borderWidth: 1,
    borderColor: C.accent,
    borderRadius: 14,
    backgroundColor: C.bg,
    paddingHorizontal: 12,
    minHeight: 50,
  },
  input: {
    flex: 1,
    color: C.text,
    paddingHorizontal: 10,
    paddingVertical: 12,
    fontSize: 15,
    fontWeight: "600",
  },
  primaryBtn: {
    marginTop: 4,
    minHeight: 52,
    borderRadius: 14,
    backgroundColor: C.accent,
    alignItems: "center",
    justifyContent: "center",
  },
  primaryBtnText: {
    color: C.onAccent,
    fontSize: 15,
    fontWeight: "900",
  },
  loadingRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
  },

  linkBtn: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 6,
    paddingVertical: 10,
  },
  linkBtnText: {
    color: C.accent,
    fontSize: 14,
    fontWeight: "800",
  },

  btnDisabled: { opacity: 0.45 },
  btnPressed: { opacity: motion.pressOpacity, transform: [{ scale: motion.pressScale }] },
});
