import React from "react";
import { StatusBar } from "react-native";
import { DarkTheme, NavigationContainer } from "@react-navigation/native";
import { AuthProvider } from "./app/src/context/AuthContext";
import ForcedUpdateGate from "./app/src/components/ForcedUpdateGate";
import PaymentReturnBridge from "./app/src/components/PaymentReturnBridge";
import RootNavigator from "./app/src/navigation/RootNavigator";
import { navigationRef } from "./app/src/navigation/navigationRef";
import { colors } from "./app/src/theme";
import { SafeAreaProvider } from "react-native-safe-area-context";

const appTheme = {
  ...DarkTheme,
  colors: {
    ...DarkTheme.colors,
    background: colors.bg,
    card: colors.card,
    text: colors.text,
    border: colors.border,
    primary: colors.accent,
  },
};

export default function App() {
  return (
    <SafeAreaProvider>
      <StatusBar barStyle="light-content" />
      <AuthProvider>
        <NavigationContainer ref={navigationRef} theme={appTheme}>
          <PaymentReturnBridge />
          <RootNavigator />
        </NavigationContainer>
        <ForcedUpdateGate />
      </AuthProvider>
    </SafeAreaProvider>
  );
}
