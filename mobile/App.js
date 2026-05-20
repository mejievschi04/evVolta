import React from "react";
import { DarkTheme, NavigationContainer } from "@react-navigation/native";
import { AuthProvider } from "./app/src/context/AuthContext";
import RootNavigator from "./app/src/navigation/RootNavigator";
import { colors } from "./app/src/theme";
import { SafeAreaProvider } from "react-native-safe-area-context";

const appTheme = {
  ...DarkTheme,
  colors: {
    ...DarkTheme.colors,
    background: colors.bg,
    card: colors.bgSoft,
    text: colors.text,
    border: colors.border,
    primary: colors.accent,
  },
};

export default function App() {
  return (
    <SafeAreaProvider>
      <AuthProvider>
        <NavigationContainer theme={appTheme}>
          <RootNavigator />
        </NavigationContainer>
      </AuthProvider>
    </SafeAreaProvider>
  );
}
