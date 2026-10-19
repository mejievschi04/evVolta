import Constants from "expo-constants";
import { Platform } from "react-native";
import * as AppleAuthentication from "expo-apple-authentication";
import { GoogleSignin, statusCodes } from "@react-native-google-signin/google-signin";

let googleConfigured = false;

function extra() {
  return Constants.expoConfig?.extra ?? {};
}

export function getGoogleWebClientId() {
  return (
    process.env.EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID ||
    extra().googleWebClientId ||
    ""
  ).trim();
}

export function configureGoogleSignIn() {
  const webClientId = getGoogleWebClientId();
  if (!webClientId || googleConfigured) {
    return Boolean(webClientId);
  }

  GoogleSignin.configure({
    webClientId,
    iosClientId: (
      process.env.EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID ||
      extra().googleIosClientId ||
      ""
    ).trim() || undefined,
    offlineAccess: false,
  });
  googleConfigured = true;
  return true;
}

export async function signInWithGoogle() {
  if (!configureGoogleSignIn()) {
    const error = new Error(
      "Google Sign-In nu este configurat. Adauga EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID in mobile/.env."
    );
    error.code = "missing_config";
    throw error;
  }

  if (Platform.OS === "android") {
    await GoogleSignin.hasPlayServices({ showPlayServicesUpdateDialog: true });
  }

  const response = await GoogleSignin.signIn();
  if (response?.type === "cancelled") {
    const error = new Error("Autentificare Google anulata.");
    error.code = "cancelled";
    throw error;
  }

  if (response?.type !== "success" || !response.data) {
    throw new Error("Autentificare Google esuata.");
  }

  let idToken = response.data.idToken;
  if (!idToken) {
    const tokens = await GoogleSignin.getTokens();
    idToken = tokens?.idToken;
  }

  if (!idToken) {
    throw new Error("Nu am primit token Google.");
  }

  return {
    idToken,
    email: response.data.user?.email || null,
    name: response.data.user?.name || null,
  };
}

export function mapGoogleSignInError(error) {
  if (!error) {
    return "Autentificare Google esuata.";
  }
  if (error.code === "cancelled" || error.code === statusCodes.SIGN_IN_CANCELLED) {
    return null;
  }
  if (error.code === "missing_config") {
    return error.message;
  }
  if (error.code === statusCodes.IN_PROGRESS) {
    return "Autentificarea Google este deja in curs.";
  }
  if (error.code === statusCodes.PLAY_SERVICES_NOT_AVAILABLE) {
    return "Google Play Services nu este disponibil pe acest dispozitiv.";
  }
  return error.message || "Autentificare Google esuata.";
}

export async function isAppleAuthAvailable() {
  if (Platform.OS !== "ios") {
    return false;
  }
  try {
    return await AppleAuthentication.isAvailableAsync();
  } catch {
    return false;
  }
}

export async function signInWithApple() {
  const available = await isAppleAuthAvailable();
  if (!available) {
    const error = new Error("Sign in with Apple nu este disponibil pe acest dispozitiv.");
    error.code = "unavailable";
    throw error;
  }

  const credential = await AppleAuthentication.signInAsync({
    requestedScopes: [
      AppleAuthentication.AppleAuthenticationScope.FULL_NAME,
      AppleAuthentication.AppleAuthenticationScope.EMAIL,
    ],
  });

  if (!credential?.identityToken) {
    throw new Error("Nu am primit token Apple.");
  }

  const fullName = [credential.fullName?.givenName, credential.fullName?.familyName]
    .filter(Boolean)
    .join(" ")
    .trim();

  return {
    identityToken: credential.identityToken,
    email: credential.email || null,
    fullName: fullName || null,
  };
}

export function mapAppleSignInError(error) {
  if (!error) {
    return "Autentificare Apple esuata.";
  }
  if (error.code === "ERR_REQUEST_CANCELED" || error.code === "unavailable") {
    return error.code === "unavailable" ? error.message : null;
  }
  return error.message || "Autentificare Apple esuata.";
}
