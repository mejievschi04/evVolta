import React, { useMemo } from "react";
import { StyleSheet, Text, View } from "react-native";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { useNavigation, useRoute } from "@react-navigation/native";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import { PrimaryButton, SubScreenHeader } from "../components/ui/ScreenUi";
import { formatMoney } from "../utils/chargeLimit";
import { colors, layout } from "../theme";

function formatPaidAt(value) {
  if (!value) {
    return null;
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return String(value);
  }

  const pad = (n) => String(n).padStart(2, "0");
  return `${pad(date.getDate())}.${pad(date.getMonth() + 1)}.${date.getFullYear()} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export default function WalletTopupSuccessScreen() {
  const navigation = useNavigation();
  const route = useRoute();
  const insets = useSafeAreaInsets();

  const amount = Number(route.params?.amount ?? 0);
  const balance = Number(route.params?.balance ?? 0);
  const currency = route.params?.currency || "MDL";
  const topupId = route.params?.topupId;
  const paidAt = formatPaidAt(route.params?.paidAt);
  const orderNumber = topupId ? `wallet-topup-${topupId}` : null;

  const rows = useMemo(() => {
    const items = [];
    if (orderNumber) {
      items.push({ label: "Numar comanda", value: orderNumber });
    }
    items.push({ label: "Descriere", value: "Alimentare sold V CHARGE × 1" });
    if (amount > 0) {
      items.push({ label: "Suma alimentata", value: formatMoney(amount, currency), accent: true });
    }
    if (paidAt) {
      items.push({ label: "Data platii", value: paidAt });
    }
    items.push({ label: "Sold actual", value: formatMoney(balance, currency) });
    return items;
  }, [amount, balance, currency, orderNumber, paidAt]);

  const goHome = () => {
    navigation.reset({
      index: 0,
      routes: [{ name: "Main" }],
    });
  };

  return (
    <View style={[styles.page, { paddingTop: insets.top }]}>
      <SubScreenHeader onBack={goHome} title="Alimentare reusita" />

      <View style={styles.content}>
        <View style={styles.hero}>
          <View style={styles.iconWrap}>
            <MaterialCommunityIcons color={colors.accentText} name="check-circle-outline" size={44} />
          </View>
          <Text style={styles.title}>Contul a fost suplinit</Text>
          <Text style={styles.subtitle}>
            Plata a fost confirmata. Soldul este actualizat si poti porni o incarcare.
          </Text>
        </View>

        <View style={styles.card}>
          {rows.map((row, index) => (
            <View
              key={row.label}
              style={[styles.row, index < rows.length - 1 ? styles.rowBorder : null]}
            >
              <Text style={styles.label}>{row.label}</Text>
              <Text style={row.accent ? styles.valueAccent : styles.value}>{row.value}</Text>
            </View>
          ))}
        </View>

        <PrimaryButton icon="home-outline" label="Mergi la pagina principala" onPress={goHome} />
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  page: {
    flex: 1,
    backgroundColor: colors.bg,
  },
  content: {
    flex: 1,
    paddingHorizontal: layout.pagePadding,
    paddingTop: 12,
    gap: 18,
  },
  hero: {
    alignItems: "center",
    gap: 10,
    paddingVertical: 18,
  },
  iconWrap: {
    width: 80,
    height: 80,
    borderRadius: 28,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accent,
    marginBottom: 4,
  },
  title: {
    color: colors.text,
    fontSize: 24,
    fontWeight: "900",
    textAlign: "center",
    letterSpacing: -0.4,
  },
  subtitle: {
    color: colors.textMuted,
    fontSize: 14,
    fontWeight: "700",
    textAlign: "center",
    lineHeight: 20,
    maxWidth: 320,
  },
  card: {
    borderRadius: layout.cardRadiusMd,
    borderWidth: 1,
    borderColor: colors.borderSoft,
    backgroundColor: colors.card,
    paddingHorizontal: 16,
    paddingVertical: 8,
  },
  row: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    gap: 12,
    paddingVertical: 12,
  },
  rowBorder: {
    borderBottomWidth: 1,
    borderBottomColor: colors.borderSoft,
  },
  label: {
    color: colors.textMuted,
    fontSize: 12,
    fontWeight: "700",
    flexShrink: 0,
  },
  value: {
    color: colors.text,
    fontSize: 14,
    fontWeight: "900",
    textAlign: "right",
    flex: 1,
  },
  valueAccent: {
    color: colors.accent,
    fontSize: 16,
    fontWeight: "900",
    textAlign: "right",
    flex: 1,
  },
});
