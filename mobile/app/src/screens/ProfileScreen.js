import React, { useCallback, useState } from "react";
import { Pressable, ScrollView, StyleSheet, Text, View } from "react-native";
import { useNavigation } from "@react-navigation/native";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { useTabScreenBottomPadding } from "../hooks/useTabScreenBottomPadding";
import {
  NavRow,
  ProfileHeroCard,
  ScreenHeader,
  SectionCard,
  initialsFrom,
} from "../components/ui/ScreenUi";
import apiClient from "../api/client";
import { useAuth } from "../context/AuthContext";
import { useScreenFocusLoad } from "../hooks/useScreenFocusLoad";
import AuthGatePanel from "../components/auth/AuthGatePanel";
import { accountTypeLabel, isFreeChargingAccount, usesWalletAccount } from "../utils/accountType";
import { colors, layout } from "../theme";

export default function ProfileScreen() {
  const navigation = useNavigation();
  const bottomPadding = useTabScreenBottomPadding();
  const { user, isAuthenticated } = useAuth();
  const [walletBalance, setWalletBalance] = useState(null);

  const usesPrepayWallet = usesWalletAccount(user?.account_type);

  const loadWallet = useCallback(async () => {
    if (!isAuthenticated || !usesPrepayWallet) {
      setWalletBalance(null);
      return;
    }

    try {
      const walletResponse = await apiClient.get("/wallet");
      setWalletBalance(walletResponse.data?.wallet_balance ?? user?.wallet_balance ?? 0);
    } catch {
      setWalletBalance(user?.wallet_balance ?? 0);
    }
  }, [isAuthenticated, usesPrepayWallet, user?.wallet_balance]);

  useScreenFocusLoad(loadWallet);

  if (!isAuthenticated) {
    return (
      <View style={[styles.page, styles.guestPage, { paddingBottom: bottomPadding }]}>
        <ScreenHeader icon="account-circle-outline" title="Profil" />
        <AuthGatePanel
          title="Intra in cont"
          body="Harta si statiile sunt disponibile fara cont. Autentifica-te pentru wallet, rezervari, facturi si setari."
          icon="account-circle-outline"
        />
      </View>
    );
  }

  const currency = user?.currency || "MDL";
  const balance = walletBalance ?? user?.wallet_balance ?? 0;
  const accountLabel = accountTypeLabel(user?.account_type);

  return (
    <ScrollView
      style={styles.page}
      contentContainerStyle={[styles.content, { paddingBottom: bottomPadding }]}
      showsVerticalScrollIndicator={false}
    >
      <ScreenHeader icon="account-circle-outline" title="Profil" />

      <ProfileHeroCard
        accountLabel={accountLabel}
        email={user?.email || "-"}
        initials={initialsFrom(user?.name)}
        name={user?.name || "Utilizator"}
      />

      {isFreeChargingAccount(user?.account_type) ? (
        <View style={styles.walletCard}>
          <View style={styles.walletIcon}>
            <MaterialCommunityIcons color={colors.accentText} name="flash" size={16} />
          </View>
          <Text style={styles.walletValue}>Incarcare gratuita</Text>
        </View>
      ) : null}

      {usesPrepayWallet ? (
        <Pressable
          accessibilityLabel="Alimenteaza cont"
          accessibilityRole="button"
          onPress={() => navigation.navigate("WalletTopup")}
          style={({ pressed }) => [styles.walletCard, pressed && styles.pressed]}
        >
          <View style={styles.walletIcon}>
            <MaterialCommunityIcons color={colors.accentText} name="wallet" size={16} />
          </View>
          <View style={styles.walletCopy}>
            <Text style={styles.walletLabel}>Sold</Text>
            <Text style={styles.walletValue} numberOfLines={1}>
              {Number(balance).toFixed(2)} {currency}
            </Text>
          </View>
          <View style={styles.walletPlus}>
            <MaterialCommunityIcons color={colors.accentText} name="plus" size={16} />
          </View>
        </Pressable>
      ) : null}

      <SectionCard>
        <View style={styles.menu}>
          <NavRow
            icon="cog-outline"
            title="Setari cont"
            onPress={() => navigation.navigate("ProfileSettings")}
          />
        </View>
      </SectionCard>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  page: {
    flex: 1,
    backgroundColor: colors.bg,
  },
  guestPage: {
    paddingHorizontal: layout.pagePadding,
    paddingTop: 6,
  },
  content: {
    padding: layout.pagePadding,
    gap: 14,
  },
  walletCard: {
    flexDirection: "row",
    alignItems: "center",
    gap: 12,
    minHeight: 64,
    borderRadius: layout.cardRadiusLg,
    paddingHorizontal: 14,
    paddingVertical: 12,
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.borderSoft,
  },
  walletIcon: {
    width: 36,
    height: 36,
    borderRadius: 12,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accent,
  },
  walletCopy: {
    flex: 1,
    minWidth: 0,
    gap: 2,
  },
  walletLabel: {
    color: colors.textMuted,
    fontSize: 11,
    fontWeight: "800",
  },
  walletValue: {
    color: colors.text,
    fontSize: 16,
    fontWeight: "900",
    letterSpacing: -0.2,
  },
  walletPlus: {
    width: 28,
    height: 28,
    borderRadius: 14,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accent,
  },
  pressed: {
    opacity: 0.88,
  },
  menu: {
    gap: 8,
  },
});
