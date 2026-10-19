import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { PrimaryButton } from "../ui/ScreenUi";
import { AuthLinkButton } from "../ui/AuthLayout";
import { navigate } from "../../navigation/navigationRef";
import { colors } from "../../theme";

export default function AuthGatePanel({
  title = "Autentificare necesara",
  body = "Poti vedea harta si statiile fara cont. Pentru aceasta actiune, autentifica-te.",
  icon = "account-lock-outline",
}) {
  return (
    <View style={styles.wrap}>
      <View style={styles.iconWrap}>
        <MaterialCommunityIcons name={icon} size={28} color={colors.accent} />
      </View>
      <Text style={styles.title}>{title}</Text>
      <Text style={styles.body}>{body}</Text>
      <PrimaryButton
        icon="login"
        label="Autentifica-te"
        onPress={() => navigate("Login")}
      />
      <AuthLinkButton
        icon="account-plus-outline"
        iconPosition="left"
        label="Creare cont"
        onPress={() => navigate("Register")}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: {
    flex: 1,
    justifyContent: "center",
    paddingHorizontal: 24,
    gap: 12,
  },
  iconWrap: {
    width: 56,
    height: 56,
    borderRadius: 18,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accentSoft,
    borderWidth: 1,
    borderColor: colors.borderAccent,
    marginBottom: 4,
  },
  title: {
    color: colors.text,
    fontSize: 22,
    fontWeight: "900",
  },
  body: {
    color: colors.textMuted,
    fontSize: 14,
    fontWeight: "600",
    lineHeight: 20,
    marginBottom: 8,
  },
});
