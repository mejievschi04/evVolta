import React, { useEffect, useMemo, useRef, useState } from "react";
import { Alert, Pressable, StyleSheet, View } from "react-native";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { RememberMeToggle } from "../components/auth/RememberMeToggle";
import { SocialAuthButtons } from "../components/auth/SocialAuthButtons";
import { AuthField, AuthLayout, AuthTextLink } from "../components/ui/AuthLayout";
import { LegalAcceptance } from "../components/ui/LegalAcceptance";
import { PrimaryButton } from "../components/ui/ScreenUi";
import { getStoredRememberMe, useAuth } from "../context/AuthContext";
import { getApiErrorMessage } from "../utils/apiErrors";
import { resetToMain } from "../navigation/navigationRef";
import { colors } from "../theme";
import {
  mapAppleSignInError,
  mapGoogleSignInError,
  signInWithApple,
  signInWithGoogle,
} from "../utils/socialAuth";

export default function RegisterScreen({ navigation }) {
  const { register, loginWithApple, loginWithGoogle } = useAuth();
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [loading, setLoading] = useState(false);
  const [socialLoading, setSocialLoading] = useState(null);
  const [showPassword, setShowPassword] = useState(false);
  const [acceptedLegal, setAcceptedLegal] = useState(false);
  const [rememberMe, setRememberMe] = useState(false);
  const emailInputRef = useRef(null);
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
    () => Boolean(name.trim() && email.trim() && password.length >= 8 && acceptedLegal && !busy),
    [acceptedLegal, busy, email, name, password]
  );

  const finishAuth = () => {
    if (navigation.canGoBack()) {
      navigation.goBack();
      return;
    }
    resetToMain();
  };

  const handleRegister = async () => {
    const trimmedEmail = email.trim();
    const trimmedName = name.trim();

    if (!trimmedName) {
      Alert.alert("Creare cont", "Completeaza numele si prenumele.");
      return;
    }

    if (!trimmedEmail || password.length < 8) {
      Alert.alert("Creare cont", "Completeaza e-mailul si o parola de minim 8 caractere (litere si cifre).");
      return;
    }

    if (!acceptedLegal) {
      Alert.alert("Creare cont", "Trebuie sa accepti Termenii si Politica de confidentialitate.");
      return;
    }

    try {
      setLoading(true);
      await register({
        email: trimmedEmail,
        password,
        name: trimmedName,
        accept_terms: true,
        remember_me: rememberMe,
      });
      finishAuth();
    } catch (error) {
      Alert.alert("Creare cont esuata", getApiErrorMessage(error));
    } finally {
      setLoading(false);
    }
  };

  const ensureLegalAccepted = () => {
    if (acceptedLegal) {
      return true;
    }
    Alert.alert("Creare cont", "Trebuie sa accepti Termenii si Politica de confidentialitate.");
    return false;
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
        full_name: result.fullName || name.trim() || null,
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
      title="Creeaza cont"
      footer={
        <View style={styles.footerLinks}>
          <AuthTextLink
            label="Autentifica-te"
            onPress={() => navigation.navigate("Login")}
            prefix="Ai deja cont?"
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
        autoCapitalize="words"
        autoComplete="name"
        icon="account-outline"
        label="Nume si prenume"
        onChangeText={setName}
        onSubmitEditing={() => emailInputRef.current?.focus()}
        placeholder="Ion Popescu"
        returnKeyType="next"
        scrollKey="register_name"
        textContentType="name"
        value={name}
      />

      <AuthField
        autoCapitalize="none"
        autoComplete="email"
        icon="email-outline"
        inputRef={emailInputRef}
        keyboardType="email-address"
        label="E-mail"
        onChangeText={setEmail}
        onSubmitEditing={() => passwordInputRef.current?.focus()}
        placeholder="email@exemplu.com"
        returnKeyType="next"
        scrollKey="register_email"
        textContentType="username"
        value={email}
      />

      <AuthField
        autoComplete="password-new"
        icon="lock-outline"
        inputRef={passwordInputRef}
        label="Parola"
        onChangeText={setPassword}
        onSubmitEditing={handleRegister}
        placeholder="Minim 8 caractere (litere + cifre)"
        returnKeyType="go"
        scrollKey="register_password"
        secureTextEntry={!showPassword}
        textContentType="newPassword"
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
          label={loading ? "Se creeaza contul..." : "Creeaza cont"}
          loading={loading}
          onPress={handleRegister}
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
    gap: 16,
    marginTop: 4,
  },
  footerLinks: {
    alignItems: "center",
    gap: 2,
  },
});
