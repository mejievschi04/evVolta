import React, { useEffect, useMemo, useRef, useState } from "react";
import { Alert, Pressable, StyleSheet, View } from "react-native";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { RememberMeToggle } from "../components/auth/RememberMeToggle";
import { SocialAuthButtons } from "../components/auth/SocialAuthButtons";
import { AuthField, AuthLayout, AuthTextLink } from "../components/ui/AuthLayout";
import { LegalAcceptance } from "../components/ui/LegalAcceptance";
import { PrimaryButton } from "../components/ui/ScreenUi";
import { getStoredRememberMe, useAuth } from "../context/AuthContext";
import apiClient from "../api/client";
import { getApiErrorMessage } from "../utils/apiErrors";
import { resetToMain } from "../navigation/navigationRef";
import { colors } from "../theme";
import {
  mapAppleSignInError,
  mapGoogleSignInError,
  signInWithApple,
  signInWithGoogle,
} from "../utils/socialAuth";

export default function LoginScreen({ navigation }) {
  const { login, loginWithApple, loginWithGoogle } = useAuth();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [loading, setLoading] = useState(false);
  const [socialLoading, setSocialLoading] = useState(null);
  const [showPassword, setShowPassword] = useState(false);
  const [acceptedLegal, setAcceptedLegal] = useState(false);
  const [rememberMe, setRememberMe] = useState(false);
  const passwordInputRef = useRef(null);

  useEffect(() => {
    let cancelled = false;
    getStoredRememberMe().then((value) => {
      if (!cancelled) {
        setRememberMe(value);
      }
    });
    return () => {
      cancelled = true;
    };
  }, []);

  const busy = loading || Boolean(socialLoading);
  const canSubmit = useMemo(
    () => Boolean(email.trim() && password && acceptedLegal && !busy),
    [acceptedLegal, busy, email, password]
  );

  const finishAuth = () => {
    if (navigation.canGoBack()) {
      navigation.goBack();
      return;
    }
    resetToMain();
  };

  const ensureLegalAccepted = () => {
    if (acceptedLegal) {
      return true;
    }
    Alert.alert("Autentificare", "Trebuie sa accepti Termenii si Politica de confidentialitate.");
    return false;
  };

  const handleLogin = async () => {
    const trimmedEmail = email.trim();
    if (!trimmedEmail || !password) {
      Alert.alert("Autentificare", "Completeaza e-mailul si parola.");
      return;
    }

    if (!ensureLegalAccepted()) {
      return;
    }

    try {
      setLoading(true);
      await login(trimmedEmail, password, { accept_terms: true, remember_me: rememberMe });
      finishAuth();
    } catch (error) {
      Alert.alert("Autentificare esuata", getApiErrorMessage(error));
    } finally {
      setLoading(false);
    }
  };

  const handleForgotPassword = () => {
    const trimmedEmail = email.trim();
    if (!trimmedEmail) {
      Alert.alert("Resetare parola", "Introdu e-mailul contului in campul de mai sus, apoi apasa din nou.");
      return;
    }

    Alert.alert("Resetare parola", `Trimitem un link de resetare la ${trimmedEmail}?`, [
      { text: "Anuleaza", style: "cancel" },
      {
        text: "Trimite",
        onPress: async () => {
          try {
            const response = await apiClient.post("/password/forgot", { email: trimmedEmail });
            Alert.alert("Resetare parola", response.data?.message || "Verifica e-mailul.");
          } catch (error) {
            Alert.alert("Resetare parola", getApiErrorMessage(error));
          }
        },
      },
    ]);
  };

  const handleGoogle = async () => {
    if (!ensureLegalAccepted()) {
      return;
    }

    try {
      setSocialLoading("google");
      const result = await signInWithGoogle();
      await loginWithGoogle(result.idToken, { accept_terms: true, remember_me: rememberMe });
      finishAuth();
    } catch (error) {
      const message = mapGoogleSignInError(error) || getApiErrorMessage(error);
      if (message) {
        Alert.alert("Google", message);
      }
    } finally {
      setSocialLoading(null);
    }
  };

  const handleApple = async () => {
    if (!ensureLegalAccepted()) {
      return;
    }

    try {
      setSocialLoading("apple");
      const result = await signInWithApple();
      await loginWithApple(result.identityToken, {
        accept_terms: true,
        full_name: result.fullName,
        remember_me: rememberMe,
      });
      finishAuth();
    } catch (error) {
      const message = mapAppleSignInError(error) || getApiErrorMessage(error);
      if (message) {
        Alert.alert("Apple", message);
      }
    } finally {
      setSocialLoading(null);
    }
  };

  return (
    <AuthLayout
      poweredBy="powered by Mejievski"
      title="Bine ai revenit"
      footer={
        <View style={styles.footerLinks}>
          <AuthTextLink
            label="Creeaza cont"
            onPress={() => navigation.navigate("Register")}
            prefix="Nu ai cont?"
          />
          <AuthTextLink
            icon="arrow-right"
            label="Continua fara cont"
            muted
            onPress={() => finishAuth()}
          />
        </View>
      }
    >
      <AuthField
        autoCapitalize="none"
        autoComplete="email"
        icon="email-outline"
        keyboardType="email-address"
        label="E-mail"
        onChangeText={setEmail}
        onSubmitEditing={() => passwordInputRef.current?.focus()}
        placeholder="email@exemplu.com"
        returnKeyType="next"
        scrollKey="email"
        textContentType="username"
        value={email}
      />

      <AuthField
        autoComplete="password"
        icon="lock-outline"
        inputRef={passwordInputRef}
        label="Parola"
        onChangeText={setPassword}
        onSubmitEditing={handleLogin}
        placeholder="Introdu parola"
        returnKeyType="go"
        scrollKey="password"
        secureTextEntry={!showPassword}
        textContentType="password"
        trailing={
          <Pressable onPress={() => setShowPassword((value) => !value)} hitSlop={12}>
            <MaterialCommunityIcons
              color={colors.textMuted}
              name={showPassword ? "eye-off-outline" : "eye-outline"}
              size={20}
            />
          </Pressable>
        }
        value={password}
      />

      <View style={styles.forgot}>
        <AuthTextLink label="Ai uitat parola?" muted onPress={handleForgotPassword} />
      </View>

      <View style={styles.actions}>
        <LegalAcceptance
          compact
          checked={acceptedLegal}
          onChange={setAcceptedLegal}
          onOpenPrivacy={() => navigation.navigate("LegalDocument", { type: "privacy" })}
          onOpenTerms={() => navigation.navigate("LegalDocument", { type: "terms" })}
        />

        <RememberMeToggle checked={rememberMe} onChange={setRememberMe} />

        <PrimaryButton
          disabled={!canSubmit}
          label={loading ? "Se conecteaza..." : "Continua"}
          loading={loading}
          onPress={handleLogin}
        />

        <SocialAuthButtons
          disabled={!acceptedLegal || busy}
          loadingProvider={socialLoading}
          onApplePress={handleApple}
          onGooglePress={handleGoogle}
        />
      </View>
    </AuthLayout>
  );
}

const styles = StyleSheet.create({
  actions: {
    gap: 12,
    marginTop: 2,
  },
  forgot: {
    alignItems: "flex-end",
    marginTop: -6,
  },
  footerLinks: {
    alignItems: "center",
    gap: 2,
  },
});
