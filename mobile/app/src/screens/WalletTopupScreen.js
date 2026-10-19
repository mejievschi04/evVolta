import React, { useCallback, useMemo, useRef, useState } from "react";
import {
  Alert,
  Image,
  Keyboard,
  KeyboardAvoidingView,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from "react-native";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { useNavigation } from "@react-navigation/native";
import { SafeAreaView, useSafeAreaInsets } from "react-native-safe-area-context";
import apiClient from "../api/client";
import {
  EmptyStateCard,
  HeroAmount,
  HeroCard,
  PrimaryButton,
  SectionCard,
  SubScreenHeader,
  ui,
} from "../components/ui/ScreenUi";
import { LegalAcceptance } from "../components/ui/LegalAcceptance";
import { useAuth } from "../context/AuthContext";
import { useAndroidNavButtons } from "../hooks/useAndroidNavButtons";
import { useKeyboardScrollToInput } from "../hooks/useKeyboardScrollToInput";
import { useKeyboardVisible } from "../hooks/useKeyboardVisible";
import { useScreenFocusLoad } from "../hooks/useScreenFocusLoad";
import { formatMoney } from "../utils/chargeLimit";
import { usesWalletAccount } from "../utils/accountType";
import { getApiErrorMessage } from "../utils/apiErrors";
import { keyboardAvoidingBehavior, keyboardScrollProps, keyboardVerticalOffset } from "../utils/keyboard";
import { openCheckoutInSystemBrowser } from "../utils/openCheckoutBrowser";
import { colors, layout, shadows } from "../theme";

const PAYMENT_LOGOS = [
  { key: "maib", source: require("../../../assets/payment-logos/maib.png"), width: 78, height: 22 },
  { key: "visa", source: require("../../../assets/payment-logos/visa.png"), width: 46, height: 15 },
  { key: "mastercard", source: require("../../../assets/payment-logos/mastercard.png"), width: 40, height: 25 },
];

export default function WalletTopupScreen() {
  const navigation = useNavigation();
  const insets = useSafeAreaInsets();
  const scrollRef = useRef(null);
  const { registerInputLayout, scrollToInput } = useKeyboardScrollToInput(scrollRef);
  const { user } = useAuth();
  const [wallet, setWallet] = useState(null);
  const [walletLoading, setWalletLoading] = useState(false);
  const [customAmount, setCustomAmount] = useState("");
  const [selectedPreset, setSelectedPreset] = useState(null);
  const [acceptedTerms, setAcceptedTerms] = useState(false);
  const keyboardOpen = useKeyboardVisible();
  const { androidNavButtonsOffset } = useAndroidNavButtons();
  const usesPrepayWallet =
    usesWalletAccount(user?.account_type);
  const currency = wallet?.currency || user?.currency || "MDL";
  const minTopup = Number(wallet?.min_topup) || 100;
  const presets = [100, 200, 300, 500].filter((amount) => amount >= minTopup);
  const balance = Number(wallet?.wallet_balance ?? user?.wallet_balance ?? 0);

  const loadWallet = useCallback(async () => {
    if (!usesPrepayWallet) {
      setWallet(null);
      return;
    }

    try {
      const response = await apiClient.get("/wallet");
      setWallet(response.data);
    } catch {
      setWallet(null);
    }
  }, [usesPrepayWallet]);

  useScreenFocusLoad(loadWallet);

  const selectedAmount = useMemo(() => {
    if (customAmount.trim()) {
      const value = Number(customAmount.replace(",", "."));
      return Number.isFinite(value) ? value : null;
    }
    return selectedPreset;
  }, [customAmount, selectedPreset]);

  const balanceParts = useMemo(() => {
    const [whole, decimals] = balance.toFixed(2).split(".");
    return { whole, decimals };
  }, [balance]);

  const onCustomFocus = () => {
    scrollToInput("amount");
  };

  const handleTopup = async (amount) => {
    const value = Number(amount);
    if (!Number.isFinite(value) || value < minTopup) {
      Alert.alert("Alimentare", `Suma minima este ${minTopup} ${currency}.`);
      return;
    }

    if (!acceptedTerms) {
      Alert.alert("Alimentare", "Accepta Termenii si Politica de confidentialitate pentru a continua plata.");
      return;
    }

    Keyboard.dismiss();

    try {
      setWalletLoading(true);
      const response = await apiClient.post("/wallet/topup-checkout", { amount: value });
      const checkoutUrl = response.data?.checkout_url;
      if (!checkoutUrl) {
        Alert.alert("Alimentare", "Nu am primit link de plata.");
        return;
      }
      await openCheckoutInSystemBrowser(checkoutUrl);
    } catch (error) {
      if (error?.response?.data?.code === "name_required") {
        Alert.alert("Alimentare", getApiErrorMessage(error), [
          { text: "Mai tarziu", style: "cancel" },
          { text: "Completeaza", onPress: () => navigation.navigate("ProfileSettings") },
        ]);
        return;
      }
      Alert.alert("Alimentare", getApiErrorMessage(error));
    } finally {
      setWalletLoading(false);
    }
  };

  const selectPreset = (amount) => {
    Keyboard.dismiss();
    setSelectedPreset(amount);
    setCustomAmount("");
  };

  const onCustomChange = (value) => {
    setCustomAmount(value);
    setSelectedPreset(null);
  };

  if (!usesPrepayWallet) {
    return (
      <View style={styles.center}>
        <EmptyStateCard
          detail="Alimentarea nu este disponibila pentru acest tip de cont."
          icon="wallet-outline"
          title="Sold indisponibil"
        />
      </View>
    );
  }

  const canPay = Boolean(selectedAmount) && acceptedTerms && !walletLoading;
  const footerHeight = keyboardOpen ? 0 : 168 + Math.max(insets.bottom, 8) + androidNavButtonsOffset;

  return (
    <SafeAreaView style={styles.page} edges={["top"]}>
      <KeyboardAvoidingView
        style={styles.flex}
        behavior={keyboardAvoidingBehavior}
        keyboardVerticalOffset={keyboardVerticalOffset}
      >
        <ScrollView
          ref={scrollRef}
          contentContainerStyle={[styles.content, { paddingBottom: keyboardOpen ? 24 : footerHeight }]}
          {...keyboardScrollProps}
        >
          <SubScreenHeader onBack={() => navigation.goBack()} title="Alimentare" />

          {keyboardOpen ? (
            <View style={styles.heroCompact}>
              <View style={styles.heroCompactIcon}>
                <MaterialCommunityIcons name="wallet" size={16} color={colors.accentText} />
              </View>
              <View style={{ flex: 1 }}>
                <Text style={styles.heroCompactLabel}>Sold</Text>
                <Text style={styles.heroCompactAmount}>
                  {balanceParts.whole}.{balanceParts.decimals} {currency}
                </Text>
              </View>
            </View>
          ) : (
            <HeroCard hint="Folosit la pornirea incarcarii" icon="wallet" label="Sold disponibil">
              <HeroAmount decimals={balanceParts.decimals} suffix={currency} whole={balanceParts.whole} />
            </HeroCard>
          )}

          {!keyboardOpen ? (
            <SectionCard subtitle="Selecteaza o valoare rapida sau introdu manual." title="Alege suma">
              <View style={styles.presetGrid}>
                {presets.map((amount) => {
                  const active = selectedPreset === amount && !customAmount.trim();
                  return (
                    <Pressable
                      key={amount}
                      disabled={walletLoading}
                      onPress={() => selectPreset(amount)}
                      style={({ pressed }) => [
                        styles.presetCard,
                        active && styles.presetCardActive,
                        walletLoading && styles.buttonDisabled,
                        pressed && ui.pressed,
                      ]}
                    >
                      <Text style={[styles.presetPlus, active && styles.presetPlusActive]}>+</Text>
                      <Text style={[styles.presetAmount, active && styles.presetAmountActive]}>{amount}</Text>
                      <Text style={[styles.presetCurrency, active && styles.presetCurrencyActive]}>{currency}</Text>
                    </Pressable>
                  );
                })}
              </View>
            </SectionCard>
          ) : null}

          <View onLayout={registerInputLayout("amount")} style={styles.customCard}>
            <Text style={styles.customLabel}>Suma personalizata</Text>
            <View style={[styles.customInputWrap, customAmount.trim() ? styles.customInputWrapActive : null]}>
              <MaterialCommunityIcons name="cash-plus" size={18} color={colors.accent} />
              <TextInput
                keyboardType="decimal-pad"
                onChangeText={onCustomChange}
                onFocus={onCustomFocus}
                onSubmitEditing={Keyboard.dismiss}
                placeholder={`min. ${minTopup}`}
                placeholderTextColor={colors.textMuted}
                returnKeyType="done"
                style={styles.customInput}
                value={customAmount}
              />
              <Text style={styles.customSuffix}>{currency}</Text>
            </View>

            {keyboardOpen ? (
              <View style={styles.keyboardCta}>
                <LegalAcceptance
                  checked={acceptedTerms}
                  hint="Obligatoriu inainte de plata online"
                  onChange={setAcceptedTerms}
                  onOpenPrivacy={() => navigation.navigate("LegalDocument", { type: "privacy" })}
                  onOpenTerms={() => navigation.navigate("LegalDocument", { type: "terms" })}
                />
                <TopupSummary amount={selectedAmount} currency={currency} />
                <PrimaryButton
                  compact
                  disabled={!canPay}
                  icon="lock"
                  label="Continua la plata"
                  loading={walletLoading}
                  onPress={() => selectedAmount && handleTopup(selectedAmount)}
                />
              </View>
            ) : null}
          </View>

          {!keyboardOpen ? (
            <>
              <LegalAcceptance
                checked={acceptedTerms}
                hint="Obligatoriu inainte de plata online"
                onChange={setAcceptedTerms}
                onOpenPrivacy={() => navigation.navigate("LegalDocument", { type: "privacy" })}
                onOpenTerms={() => navigation.navigate("LegalDocument", { type: "terms" })}
              />
              <View style={styles.trustRow}>
                <TrustPill icon="shield-check" text="Plata securizata" />
              </View>
              <View accessibilityLabel="Metode de plata acceptate" style={styles.logoRow}>
                {PAYMENT_LOGOS.map((logo) => (
                  <Image
                    key={logo.key}
                    accessibilityIgnoresInvertColors
                    resizeMode="contain"
                    source={logo.source}
                    style={{ width: logo.width, height: logo.height }}
                  />
                ))}
              </View>
            </>
          ) : null}
        </ScrollView>

        {!keyboardOpen ? (
          <View style={[styles.footer, { paddingBottom: Math.max(insets.bottom, 8) + androidNavButtonsOffset }]}>
            <TopupSummary amount={selectedAmount} currency={currency} />
            <PrimaryButton
              disabled={!canPay}
              icon="lock"
              label="Continua la plata"
              loading={walletLoading}
              onPress={() => selectedAmount && handleTopup(selectedAmount)}
            />
          </View>
        ) : null}
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

function TopupSummary({ amount, currency }) {
  return (
    <View style={styles.footerSummary}>
      <Text style={styles.footerLabel}>Vei alimenta</Text>
      <Text style={styles.footerAmount}>{amount ? formatMoney(amount, currency) : "—"}</Text>
    </View>
  );
}

function TrustPill({ icon, text }) {
  return (
    <View style={styles.trustPill}>
      <MaterialCommunityIcons name={icon} size={12} color={colors.accent} />
      <Text style={styles.trustPillText}>{text}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  page: {
    flex: 1,
    backgroundColor: colors.bg,
  },
  flex: {
    flex: 1,
  },
  content: {
    paddingHorizontal: layout.pagePadding,
    paddingTop: 2,
    gap: 14,
  },
  center: {
    flex: 1,
    alignItems: "center",
    justifyContent: "center",
    padding: 24,
    backgroundColor: colors.bg,
  },
  heroCompact: {
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
    borderRadius: layout.cardRadiusMd,
    padding: 12,
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.borderSoft,
  },
  heroCompactIcon: {
    width: 32,
    height: 32,
    borderRadius: 10,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accent,
  },
  heroCompactLabel: {
    color: colors.textMuted,
    fontSize: 10,
    fontWeight: "800",
    letterSpacing: 1,
    textTransform: "uppercase",
  },
  heroCompactAmount: {
    color: colors.text,
    fontSize: 16,
    fontWeight: "900",
    letterSpacing: -0.3,
  },
  presetGrid: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: 8,
  },
  presetCard: {
    width: "47.5%",
    minHeight: 72,
    borderRadius: layout.cardRadiusSm,
    paddingHorizontal: 12,
    paddingVertical: 10,
    backgroundColor: colors.bgSoft,
    borderWidth: 1,
    borderColor: colors.borderSoft,
    justifyContent: "space-between",
  },
  presetCardActive: {
    backgroundColor: colors.accentSoft,
    borderColor: colors.borderAccent,
  },
  presetPlus: {
    color: colors.textMuted,
    fontSize: 14,
    fontWeight: "900",
    lineHeight: 16,
  },
  presetPlusActive: {
    color: colors.accent,
  },
  presetAmount: {
    color: colors.text,
    fontSize: 22,
    fontWeight: "900",
    letterSpacing: -0.6,
    marginTop: 2,
  },
  presetAmountActive: {
    color: colors.accent,
  },
  presetCurrency: {
    color: colors.textMuted,
    fontSize: 10,
    fontWeight: "800",
    marginTop: 1,
  },
  presetCurrencyActive: {
    color: "rgba(255,238,0,0.85)",
  },
  customCard: {
    gap: 6,
  },
  customLabel: {
    color: colors.textMuted,
    fontSize: 11,
    fontWeight: "800",
    letterSpacing: 0.4,
    textTransform: "uppercase",
  },
  customInputWrap: {
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
    borderRadius: layout.inputRadius,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
    paddingHorizontal: 12,
    paddingVertical: 2,
  },
  customInputWrapActive: {
    borderColor: colors.borderAccent,
    backgroundColor: colors.accentSoft,
  },
  customInput: {
    flex: 1,
    color: colors.text,
    fontSize: 20,
    fontWeight: "900",
    paddingVertical: 10,
    letterSpacing: -0.4,
  },
  customSuffix: {
    color: colors.textMuted,
    fontSize: 12,
    fontWeight: "800",
  },
  keyboardCta: {
    marginTop: 10,
    gap: 8,
  },
  trustRow: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: 6,
  },
  trustPill: {
    flexDirection: "row",
    alignItems: "center",
    gap: 5,
    borderRadius: 999,
    paddingHorizontal: 8,
    paddingVertical: 5,
    backgroundColor: colors.bgSoft,
    borderWidth: 1,
    borderColor: colors.borderSoft,
  },
  trustPillText: {
    color: colors.textMuted,
    fontSize: 10,
    fontWeight: "700",
  },
  logoRow: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    flexWrap: "wrap",
    gap: 14,
    paddingVertical: 4,
  },
  footer: {
    paddingHorizontal: layout.pagePadding,
    paddingTop: 10,
    backgroundColor: colors.overlay,
    borderTopWidth: 1,
    borderTopColor: colors.borderSoft,
    gap: 8,
    ...shadows.float,
  },
  footerSummary: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
  },
  footerLabel: {
    color: colors.textMuted,
    fontSize: 11,
    fontWeight: "700",
  },
  footerAmount: {
    color: colors.accent,
    fontSize: 14,
    fontWeight: "900",
  },
  buttonDisabled: {
    opacity: 0.5,
  },
});
