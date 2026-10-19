import React from "react";
import { ActivityIndicator, Image, StyleSheet, Text, View } from "react-native";
import { createNativeStackNavigator } from "@react-navigation/native-stack";
import { useAuth } from "../context/AuthContext";
import LoginScreen from "../screens/LoginScreen";
import RegisterScreen from "../screens/RegisterScreen";
import LegalDocumentScreen from "../screens/LegalDocumentScreen";
import LegalGateScreen from "../screens/LegalGateScreen";
import MainTabs from "./MainTabs";
import WalletTopupScreen from "../screens/WalletTopupScreen";
import WalletTopupSuccessScreen from "../screens/WalletTopupSuccessScreen";
import ProfileSettingsScreen from "../screens/ProfileSettingsScreen";
import { colors, typography } from "../theme";

const brandLogo = require("../../../assets/v-charge-logo.png");

const Stack = createNativeStackNavigator();

export default function RootNavigator() {
  const { isAuthenticated, isBootstrapping, needsLegalAcceptance } = useAuth();

  if (isBootstrapping) {
    return (
      <View style={styles.boot}>
        <Image
          accessibilityLabel="V CHARGE"
          source={brandLogo}
          style={styles.bootLogo}
          resizeMode="contain"
        />
        <Text style={styles.bootBrand}>V CHARGE</Text>
        <ActivityIndicator color={colors.accent} style={styles.bootSpinner} />
      </View>
    );
  }

  // Cont logat, dar trebuie re-acceptat legal — blocheaza app-ul pana accepta.
  if (isAuthenticated && needsLegalAcceptance) {
    return (
      <Stack.Navigator
        screenOptions={{
          headerStyle: { backgroundColor: colors.bgSoft },
          headerTintColor: colors.text,
          headerTitleStyle: { color: colors.text, fontWeight: "700" },
          contentStyle: { backgroundColor: colors.bg },
        }}
      >
        <Stack.Screen name="LegalGate" component={LegalGateScreen} options={{ headerShown: false }} />
        <Stack.Screen name="LegalDocument" component={LegalDocumentScreen} options={{ headerShown: false }} />
      </Stack.Navigator>
    );
  }

  // Guest + user autentificat: harta/tab-uri disponibile; login pe stack.
  return (
    <Stack.Navigator
      screenOptions={{
        headerStyle: { backgroundColor: colors.bgSoft },
        headerTintColor: colors.text,
        headerTitleStyle: { color: colors.text, fontWeight: "700" },
        contentStyle: { backgroundColor: colors.bg },
      }}
    >
      <Stack.Screen name="Main" component={MainTabs} options={{ headerShown: false }} />
      <Stack.Screen name="Login" component={LoginScreen} options={{ headerShown: false }} />
      <Stack.Screen name="Register" component={RegisterScreen} options={{ headerShown: false }} />
      <Stack.Screen name="LegalDocument" component={LegalDocumentScreen} options={{ headerShown: false }} />

      {isAuthenticated ? (
        <>
          <Stack.Screen name="WalletTopup" component={WalletTopupScreen} options={{ headerShown: false }} />
          <Stack.Screen name="WalletTopupSuccess" component={WalletTopupSuccessScreen} options={{ headerShown: false }} />
          <Stack.Screen name="ProfileSettings" component={ProfileSettingsScreen} options={{ headerShown: false }} />
        </>
      ) : null}
    </Stack.Navigator>
  );
}

const styles = StyleSheet.create({
  boot: {
    flex: 1,
    backgroundColor: colors.bg,
    alignItems: "center",
    justifyContent: "center",
    paddingHorizontal: 32,
  },
  bootLogo: {
    width: 120,
    height: 120,
    marginBottom: 16,
  },
  bootBrand: {
    ...typography.titleMd,
    color: colors.text,
    marginBottom: 28,
  },
  bootSpinner: {
    marginTop: 4,
  },
});
