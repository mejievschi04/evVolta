import React, { useEffect, useMemo, useState } from "react";
import {
  ActivityIndicator,
  Keyboard,
  KeyboardAvoidingView,
  Modal,
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
import { useSafeAreaInsets } from "react-native-safe-area-context";
import { estimateBudgetForKwh, formatMoney } from "../../utils/chargeLimit";
import { colors, layout, typography } from "../../theme";

export const CHARGE_MODES = {
  FULL: "full",
  KWH: "kwh",
  AMOUNT: "amount",
};

export default function ChargeLimitModal({
  visible,
  wallet,
  pricePerKwh,
  currency = "MDL",
  loading = false,
  onClose,
  onConfirm,
}) {
  const navigation = useNavigation();
  const insets = useSafeAreaInsets();
  const options = wallet?.charge_options ?? {};
  const balance = Number(wallet?.wallet_balance ?? 0);
  const rate = Number(pricePerKwh || options.price_per_kwh || 0);
  const minBudget = Number(options.min_budget ?? 10);
  const maxBudget = Number(options.max_budget ?? 50000);
  const minKwh = Number(options.min_target_kwh ?? 1);
  const maxKwh = Number(options.max_target_kwh ?? 500);

  const [mode, setMode] = useState(CHARGE_MODES.FULL);
  const [value, setValue] = useState("");
  const [keyboardHeight, setKeyboardHeight] = useState(0);

  useEffect(() => {
    if (!visible) return;
    setMode(CHARGE_MODES.FULL);
    setValue("");
  }, [visible]);

  useEffect(() => {
    if (!visible) {
      setKeyboardHeight(0);
      return undefined;
    }

    const showEvent = Platform.OS === "ios" ? "keyboardWillShow" : "keyboardDidShow";
    const hideEvent = Platform.OS === "ios" ? "keyboardWillHide" : "keyboardDidHide";
    const showSub = Keyboard.addListener(showEvent, (event) => {
      setKeyboardHeight(event.endCoordinates?.height ?? 0);
    });
    const hideSub = Keyboard.addListener(hideEvent, () => setKeyboardHeight(0));

    return () => {
      showSub.remove();
      hideSub.remove();
    };
  }, [visible]);

  const keyboardOpen = keyboardHeight > 0;

  const sanitizeLimitInput = (raw) => {
    const normalized = String(raw ?? "").replace(",", ".");
    const cleaned = normalized.replace(/[^0-9.]/g, "");
    const [whole, ...fractionParts] = cleaned.split(".");
    if (fractionParts.length === 0) return whole;
    return `${whole}.${fractionParts.join("").slice(0, mode === CHARGE_MODES.AMOUNT ? 2 : 3)}`;
  };

  const numericValue = Number(value);
  const roundedAmount = Number.isFinite(numericValue) ? Math.round(numericValue * 100) / 100 : NaN;
  const roundedKwh = Number.isFinite(numericValue) ? Math.round(numericValue * 1000) / 1000 : NaN;

  const estimatedBudget = useMemo(() => {
    if (mode !== CHARGE_MODES.KWH || !Number.isFinite(roundedKwh) || roundedKwh <= 0) {
      return null;
    }
    return estimateBudgetForKwh(roundedKwh, rate);
  }, [mode, roundedKwh, rate]);

  const estimatedKwhFromAmount = useMemo(() => {
    if (mode !== CHARGE_MODES.AMOUNT || !(rate > 0) || !Number.isFinite(roundedAmount) || roundedAmount <= 0) {
      return null;
    }
    return Math.round((roundedAmount / rate) * 100) / 100;
  }, [mode, roundedAmount, rate]);

  const fullHoldAmount = Math.min(Math.round(balance * 100) / 100, maxBudget);

  const validationError = useMemo(() => {
    if (balance < minBudget) {
      return `Sold insuficient. Minim ${formatMoney(minBudget, currency)}.`;
    }

    if (mode === CHARGE_MODES.FULL) {
      if (fullHoldAmount < minBudget) {
        return `Sold insuficient. Minim ${formatMoney(minBudget, currency)}.`;
      }
      return null;
    }

    if (value.trim() === "" || !Number.isFinite(numericValue) || numericValue <= 0) {
      return "Introdu o valoare valida.";
    }

    if (mode === CHARGE_MODES.AMOUNT) {
      if (roundedAmount < minBudget) {
        return `Minim ${formatMoney(minBudget, currency)}.`;
      }
      if (roundedAmount > maxBudget) {
        return `Maxim ${formatMoney(maxBudget, currency)}.`;
      }
      if (roundedAmount > balance) {
        return "Sold insuficient pentru aceasta suma.";
      }
      return null;
    }

    if (roundedKwh < minKwh) {
      return `Minim ${minKwh.toFixed(2)} kWh.`;
    }
    if (roundedKwh > maxKwh) {
      return `Maxim ${maxKwh.toFixed(0)} kWh.`;
    }
    if (estimatedBudget != null && estimatedBudget < minBudget) {
      return `Cantitatea trebuie sa acopere minim ${formatMoney(minBudget, currency)}.`;
    }
    if (estimatedBudget != null && estimatedBudget > maxBudget) {
      return `Maxim ${formatMoney(maxBudget, currency)} per sesiune.`;
    }
    if (estimatedBudget != null && estimatedBudget > balance) {
      return "Sold insuficient pentru aceasta cantitate.";
    }

    return null;
  }, [
    balance,
    currency,
    estimatedBudget,
    fullHoldAmount,
    maxBudget,
    maxKwh,
    minBudget,
    minKwh,
    mode,
    numericValue,
    roundedAmount,
    roundedKwh,
    value,
  ]);

  const presets =
    mode === CHARGE_MODES.AMOUNT
      ? options.suggested_budgets ?? [50, 100, 200, 500]
      : options.suggested_kwh ?? [10, 20, 30, 50];

  // Variantele rapide peste sold sau sub minim ar duce doar la eroare dupa apasare.
  const isAffordable = (presetMode, preset) => {
    if (presetMode === CHARGE_MODES.KWH) {
      const cost = estimateBudgetForKwh(preset, rate);
      return Number(preset) >= minKwh && Number(preset) <= maxKwh
        && cost >= minBudget && cost <= maxBudget && cost <= balance;
    }
    const amount = Number(preset);
    return amount >= minBudget && amount <= balance && amount <= maxBudget;
  };

  const defaultValueFor = (nextMode, preferred, list) => {
    if (isAffordable(nextMode, preferred)) return String(preferred);
    const affordable = list.filter((preset) => isAffordable(nextMode, preset));
    return affordable.length ? String(affordable[affordable.length - 1]) : "";
  };

  const selectMode = (nextMode) => {
    setMode(nextMode);
    if (nextMode === CHARGE_MODES.FULL) {
      setValue("");
      return;
    }
    if (nextMode === CHARGE_MODES.AMOUNT) {
      const list = options.suggested_budgets ?? [50, 100, 200, 500];
      setValue(defaultValueFor(nextMode, options.suggested_budgets?.[2] ?? 100, list));
      return;
    }
    const list = options.suggested_kwh ?? [10, 20, 30, 50];
    setValue(defaultValueFor(nextMode, options.suggested_kwh?.[1] ?? 20, list));
  };

  const handleConfirm = () => {
    if (validationError) return;

    if (mode === CHARGE_MODES.FULL) {
      onConfirm({ mode: CHARGE_MODES.FULL, value: fullHoldAmount });
      return;
    }

    if (mode === CHARGE_MODES.AMOUNT) {
      onConfirm({ mode, value: roundedAmount });
      return;
    }

    onConfirm({ mode, value: roundedKwh });
  };

  const summaryText = (() => {
    if (mode === CHARGE_MODES.FULL) {
      if (balance > maxBudget) {
        return `Se rezerva maxim ${formatMoney(maxBudget, currency)} din sold.`;
      }
      return null;
    }
    if (mode === CHARGE_MODES.KWH && estimatedBudget != null) {
      return `Cost estimat ${formatMoney(estimatedBudget, currency)} · ${rate.toFixed(2)} ${currency}/kWh`;
    }
    if (mode === CHARGE_MODES.AMOUNT && estimatedKwhFromAmount != null) {
      return `Energie estimata ~${estimatedKwhFromAmount.toFixed(1)} kWh · ${rate.toFixed(2)} ${currency}/kWh`;
    }
    return "Incarcarea se opreste automat la limita aleasa.";
  })();

  return (
    <Modal visible={visible} animationType="fade" transparent onRequestClose={onClose}>
      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === "ios" ? "padding" : undefined}
        keyboardVerticalOffset={0}
      >
        <View
          style={[
            styles.backdrop,
            keyboardOpen && styles.backdropKeyboard,
            {
              paddingBottom: keyboardOpen
                ? Math.max(insets.bottom, 8) + (Platform.OS === "android" ? keyboardHeight : 0)
                : Math.max(insets.bottom, 16),
            },
          ]}
        >
          <Pressable style={StyleSheet.absoluteFill} onPress={onClose} accessibilityRole="button" />
          <ScrollView
            style={styles.flex}
            keyboardShouldPersistTaps="handled"
            keyboardDismissMode="on-drag"
            showsVerticalScrollIndicator={false}
            contentContainerStyle={[
              styles.scrollContent,
              keyboardOpen && styles.scrollContentKeyboard,
            ]}
          >
            <View style={styles.card}>
              <View style={styles.cardHeader}>
                <View style={styles.iconBadge}>
                  <MaterialCommunityIcons name="lightning-bolt" size={22} color={colors.accentText} />
                </View>
                <View style={styles.headerText}>
                  <Text style={styles.title}>Cum incarci?</Text>
                  <View style={styles.balanceRow}>
                    <Text style={styles.subtitle}>Sold: {formatMoney(balance, currency)}</Text>
                    <Pressable
                      accessibilityLabel="Alimenteaza cont"
                      hitSlop={10}
                      onPress={() => {
                        onClose?.();
                        navigation.navigate("WalletTopup");
                      }}
                      style={({ pressed }) => [styles.topupBtn, pressed && styles.pressed]}
                    >
                      <MaterialCommunityIcons name="plus" size={16} color={colors.accentText} />
                    </Pressable>
                  </View>
                </View>
                <Pressable
                  accessibilityLabel="Inchide"
                  hitSlop={12}
                  onPress={onClose}
                  style={({ pressed }) => [styles.closeBtn, pressed && styles.pressed]}
                >
                  <MaterialCommunityIcons name="close" size={20} color={colors.textMuted} />
                </Pressable>
              </View>

              <View style={styles.modeGrid}>
                <ModeCard
                  active={mode === CHARGE_MODES.FULL}
                  icon="battery-charging-100"
                  title="Full"
                  caption="Automobil"
                  onPress={() => selectMode(CHARGE_MODES.FULL)}
                />
                <ModeCard
                  active={mode === CHARGE_MODES.KWH}
                  icon="flash"
                  title="kWh"
                  caption="Limita energie"
                  onPress={() => selectMode(CHARGE_MODES.KWH)}
                />
                <ModeCard
                  active={mode === CHARGE_MODES.AMOUNT}
                  icon="cash"
                  title="Suma"
                  caption={`Limita ${currency}`}
                  onPress={() => selectMode(CHARGE_MODES.AMOUNT)}
                />
              </View>

              {mode !== CHARGE_MODES.FULL ? (
                <View style={styles.limitBlock}>
                  <Text style={styles.label}>
                    {mode === CHARGE_MODES.AMOUNT ? `Suma (${currency})` : "Energie (kWh)"}
                  </Text>
                  <TextInput
                    style={styles.input}
                    value={value}
                    onChangeText={(text) => setValue(sanitizeLimitInput(text))}
                    keyboardType="decimal-pad"
                    placeholder={mode === CHARGE_MODES.AMOUNT ? "ex. 200" : "ex. 30"}
                    placeholderTextColor={colors.textMuted}
                    autoFocus
                  />

                  <View style={styles.presetRow}>
                    {presets.map((preset) => {
                      const active = String(preset) === value;
                      const affordable = isAffordable(mode, preset);
                      return (
                        <Pressable
                          key={`${mode}-${preset}`}
                          disabled={!affordable}
                          accessibilityState={{ disabled: !affordable, selected: active }}
                          style={({ pressed }) => [
                            styles.presetChip,
                            active && styles.presetChipActive,
                            !affordable && styles.presetChipDisabled,
                            pressed && styles.pressed,
                          ]}
                          onPress={() => setValue(String(preset))}
                        >
                          <Text style={[styles.presetText, active && styles.presetTextActive]}>
                            {mode === CHARGE_MODES.AMOUNT ? `${preset}` : `${preset} kWh`}
                          </Text>
                        </Pressable>
                      );
                    })}
                  </View>
                </View>
              ) : (
                <View style={styles.fullPanel}>
                  <MaterialCommunityIcons name="infinity" size={28} color={colors.accent} />
                  <Text style={styles.fullTitle}>Incarcare completa automobil</Text>
                  <Text style={styles.fullHint}>
                    Se rezerva {formatMoney(fullHoldAmount, currency)}
                    {balance > maxBudget ? " (maxim permis)" : " din sold"}. La final platesti doar energia livrata, restul revine in sold.
                  </Text>
                </View>
              )}

              {summaryText ? <Text style={styles.hint}>{summaryText}</Text> : null}
              {validationError ? <Text style={styles.error}>{validationError}</Text> : null}

              <View style={styles.actions}>
                <Pressable style={({ pressed }) => [styles.ghostBtn, pressed && styles.pressed]} onPress={onClose}>
                  <Text style={styles.ghostText}>Anuleaza</Text>
                </Pressable>
                <Pressable
                  style={({ pressed }) => [
                    styles.primaryBtn,
                    (loading || validationError) && styles.disabled,
                    pressed && styles.pressed,
                  ]}
                  onPress={handleConfirm}
                  disabled={loading || Boolean(validationError)}
                >
                  {loading ? (
                    <ActivityIndicator color={colors.accentText} />
                  ) : (
                    <>
                      <MaterialCommunityIcons name="play" size={18} color={colors.accentText} />
                      <Text style={styles.primaryText}>Porneste</Text>
                    </>
                  )}
                </Pressable>
              </View>
            </View>
          </ScrollView>
        </View>
      </KeyboardAvoidingView>
    </Modal>
  );
}

function ModeCard({ active, icon, title, caption, onPress }) {
  return (
    <Pressable
      style={({ pressed }) => [styles.modeCard, active && styles.modeCardActive, pressed && styles.pressed]}
      onPress={onPress}
    >
      <View style={[styles.modeIconWrap, active && styles.modeIconWrapActive]}>
        <MaterialCommunityIcons name={icon} size={18} color={active ? colors.accentText : colors.accent} />
      </View>
      <Text style={[styles.modeTitle, active && styles.modeTitleActive]}>{title}</Text>
      <Text style={[styles.modeCaption, active && styles.modeCaptionActive]}>{caption}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  flex: {
    flex: 1,
  },
  backdrop: {
    flex: 1,
    backgroundColor: colors.overlay,
    justifyContent: "center",
    paddingHorizontal: 18,
  },
  backdropKeyboard: {
    justifyContent: "flex-end",
  },
  scrollContent: {
    flexGrow: 1,
    justifyContent: "center",
    paddingVertical: 24,
  },
  scrollContentKeyboard: {
    justifyContent: "flex-end",
    paddingTop: 12,
    paddingBottom: 4,
  },
  card: {
    width: "100%",
    maxWidth: 440,
    alignSelf: "center",
    borderRadius: layout.cardRadiusXl,
    backgroundColor: colors.cardElevated,
    borderWidth: 1,
    borderColor: colors.borderAccent,
    paddingHorizontal: 16,
    paddingTop: 16,
    paddingBottom: 18,
    gap: 14,
  },
  cardHeader: {
    flexDirection: "row",
    alignItems: "center",
    gap: 12,
  },
  iconBadge: {
    width: 44,
    height: 44,
    borderRadius: 14,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accent,
  },
  headerText: {
    flex: 1,
    gap: 2,
  },
  title: {
    color: colors.text,
    ...typography.titleSm,
  },
  subtitle: {
    color: colors.textMuted,
    fontSize: 13,
    fontWeight: "700",
  },
  balanceRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
  },
  topupBtn: {
    width: 24,
    height: 24,
    borderRadius: 12,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accent,
  },
  closeBtn: {
    width: 36,
    height: 36,
    borderRadius: 12,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.bgSoft,
    borderWidth: 1,
    borderColor: colors.border,
  },
  modeGrid: {
    flexDirection: "row",
    gap: 8,
  },
  modeCard: {
    flex: 1,
    minHeight: 108,
    borderRadius: layout.cardRadiusMd,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.bgSoft,
    paddingHorizontal: 10,
    paddingVertical: 12,
    alignItems: "center",
    justifyContent: "center",
    gap: 6,
  },
  modeCardActive: {
    borderColor: colors.accent,
    backgroundColor: colors.accentSoft,
  },
  modeIconWrap: {
    width: 34,
    height: 34,
    borderRadius: 11,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "rgba(255,238,0,0.12)",
    borderWidth: 1,
    borderColor: "rgba(255,238,0,0.22)",
  },
  modeIconWrapActive: {
    backgroundColor: colors.accent,
    borderColor: colors.accent,
  },
  modeTitle: {
    color: colors.text,
    fontSize: 14,
    fontWeight: "900",
  },
  modeTitleActive: {
    color: colors.text,
  },
  modeCaption: {
    color: colors.textMuted,
    fontSize: 11,
    fontWeight: "700",
    textAlign: "center",
  },
  modeCaptionActive: {
    color: colors.textSoft,
  },
  limitBlock: {
    gap: 10,
  },
  label: {
    color: colors.textMuted,
    ...typography.label,
  },
  input: {
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.inputRadius,
    backgroundColor: colors.bg,
    color: colors.text,
    paddingHorizontal: 14,
    paddingVertical: 14,
    fontSize: 22,
    fontWeight: "900",
    letterSpacing: -0.4,
    textAlign: "center",
  },
  presetRow: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: 8,
    justifyContent: "center",
  },
  presetChip: {
    borderRadius: 14,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.bgSoft,
    paddingHorizontal: 14,
    paddingVertical: 10,
    minWidth: 72,
    alignItems: "center",
  },
  presetChipActive: {
    borderColor: colors.accent,
    backgroundColor: colors.accentSoft,
  },
  presetText: {
    color: colors.textMuted,
    fontSize: 13,
    fontWeight: "800",
  },
  presetTextActive: {
    color: colors.accent,
  },
  presetChipDisabled: {
    opacity: 0.35,
  },
  fullPanel: {
    borderRadius: layout.cardRadiusMd,
    borderWidth: 1,
    borderColor: colors.borderAccent,
    backgroundColor: colors.accentGlow,
    paddingVertical: 18,
    paddingHorizontal: 14,
    alignItems: "center",
    gap: 8,
  },
  fullTitle: {
    color: colors.text,
    fontSize: 16,
    fontWeight: "900",
    textAlign: "center",
  },
  fullHint: {
    color: colors.textSoft,
    fontSize: 12,
    textAlign: "center",
  },
  hint: {
    color: colors.textSoft,
    fontSize: 12,
    fontWeight: "700",
    lineHeight: 18,
    textAlign: "center",
  },
  error: {
    color: colors.danger,
    fontSize: 12,
    fontWeight: "800",
    textAlign: "center",
  },
  actions: {
    flexDirection: "row",
    gap: 10,
    marginTop: 2,
  },
  ghostBtn: {
    flex: 1,
    minHeight: layout.buttonHeight,
    borderRadius: layout.buttonRadius,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.bgSoft,
  },
  ghostText: {
    color: colors.text,
    ...typography.button,
  },
  primaryBtn: {
    flex: 1.55,
    minHeight: layout.buttonHeight,
    borderRadius: layout.buttonRadius,
    alignItems: "center",
    justifyContent: "center",
    flexDirection: "row",
    gap: 6,
    backgroundColor: colors.accent,
  },
  primaryText: {
    color: colors.accentText,
    ...typography.button,
  },
  disabled: {
    opacity: 0.55,
  },
  pressed: {
    opacity: 0.88,
  },
});
