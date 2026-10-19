import React, { useCallback, useMemo, useState } from "react";
import { Alert, Pressable, StyleSheet, Text, View } from "react-native";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { useNavigation } from "@react-navigation/native";
import {
  GhostButton,
  HeroAmount,
  HeroCard,
  PrimaryButton,
  SectionCard,
  StatusChip,
  splitAmount,
  ui,
} from "../ui/ScreenUi";
import apiClient from "../../api/client";
import { useScreenFocusLoad } from "../../hooks/useScreenFocusLoad";
import { formatInvoiceMonth, parseInvoicesResponse } from "../../utils/invoices";
import { getApiErrorMessage } from "../../utils/apiErrors";
import { openCheckoutInSystemBrowser } from "../../utils/openCheckoutBrowser";
import { colors, layout } from "../../theme";
import { CardSkeleton } from "../ui/Skeleton";

const PREVIEW_LIMIT = 3;

export default function MonthlyInvoicesPanel() {
  const navigation = useNavigation();
  const [invoiceData, setInvoiceData] = useState({ invoices: [], summary: null });
  const [loading, setLoading] = useState(true);
  const [activeActionId, setActiveActionId] = useState(null);

  const loadInvoices = useCallback(async () => {
    try {
      const response = await apiClient.get("/invoices");
      setInvoiceData(parseInvoicesResponse(response.data));
    } catch {
      // Pastreaza datele anterioare ca layout-ul sa nu dispara la refresh.
    } finally {
      setLoading(false);
    }
  }, []);

  useScreenFocusLoad(loadInvoices);

  const invoices = invoiceData.invoices;
  const summary = invoiceData.summary;

  const unpaid = useMemo(
    () => invoices.filter((item) => item.status !== "paid").slice(0, PREVIEW_LIMIT),
    [invoices]
  );

  const computedUnpaidTotal = useMemo(
    () =>
      invoices
        .filter((item) => item.status !== "paid")
        .reduce((sum, item) => sum + Number(item.total_amount || 0), 0),
    [invoices]
  );

  const unpaidTotal = summary?.outstanding_amount ?? computedUnpaidTotal;
  const currency = summary?.currency || invoices.find((item) => item.currency)?.currency || "MDL";
  const unpaidCount = summary?.unpaid_count ?? invoices.filter((item) => item.status !== "paid").length;
  const unpaidParts = splitAmount(unpaidTotal);
  const issueSchedule =
    summary?.issue_schedule || "Factura pentru luna trecuta se emite automat in data de 1 a fiecarei luni.";

  const handleCheckout = async (invoice) => {
    try {
      setActiveActionId(invoice.id);
      const response = await apiClient.post(`/invoices/${invoice.id}/checkout-session`);
      const checkoutUrl = response.data?.checkout_url;
      if (!checkoutUrl) {
        Alert.alert("Plata online", "Nu am putut genera pagina de plata.");
        return;
      }
      await openCheckoutInSystemBrowser(checkoutUrl);
      await loadInvoices();
    } catch (error) {
      Alert.alert("Plata online", getApiErrorMessage(error));
    } finally {
      setActiveActionId(null);
    }
  };

  const verifyPayment = async (invoice) => {
    try {
      setActiveActionId(invoice.id);
      await apiClient.post(`/invoices/${invoice.id}/verify-payment`);
      await loadInvoices();
      Alert.alert("Plata", "Statusul facturii a fost actualizat.");
    } catch (error) {
      Alert.alert("Verificare plata", getApiErrorMessage(error));
    } finally {
      setActiveActionId(null);
    }
  };

  if (loading && invoices.length === 0) {
    return <CardSkeleton height={160} />;
  }

  return (
    <SectionCard subtitle="Facturare lunara si plata online" title="Plati">
      <View style={styles.scheduleCard}>
        <MaterialCommunityIcons name="calendar-sync" size={16} color={colors.accent} />
        <Text style={styles.scheduleText}>{issueSchedule}</Text>
      </View>

      {unpaidCount > 0 ? (
        <HeroCard hint={`${unpaidCount} facturi neplatite`} icon="credit-card-outline" label="Restanta totala" tone="danger">
          <HeroAmount decimals={unpaidParts.decimals} suffix={currency} whole={unpaidParts.whole} />
        </HeroCard>
      ) : (
        <View style={styles.paidBanner}>
          <MaterialCommunityIcons name="check-circle-outline" size={18} color={colors.success} />
          <Text style={styles.paidBannerText}>Nu ai restanta. Factura lunara apare pe 1 ale lunii urmatoare.</Text>
        </View>
      )}

      {unpaid.map((invoice) => {
        const isProcessing = Boolean(invoice.payment_session_id);
        const monthLabel = formatInvoiceMonth(invoice.month, invoice.period_start);
        const amountParts = splitAmount(invoice.total_amount);

        return (
          <View key={invoice.id} style={styles.invoiceCard}>
            <View style={styles.invoiceTop}>
              <View style={styles.invoiceIcon}>
                <MaterialCommunityIcons name="calendar-month" size={16} color={colors.accentText} />
              </View>
              <View style={{ flex: 1 }}>
                <Text numberOfLines={1} style={styles.invoiceTitle}>
                  {monthLabel}
                </Text>
                <Text numberOfLines={1} style={styles.invoiceMeta}>
                  {invoice.invoice_number ?? `#${invoice.id}`}
                </Text>
              </View>
              <StatusChip label={isProcessing ? "In curs" : "Neplatita"} tone={isProcessing ? "info" : "danger"} />
            </View>

            <View style={styles.invoiceAmountRow}>
              <Text style={styles.invoiceAmountWhole}>{amountParts.whole}</Text>
              <Text style={styles.invoiceAmountDec}>.{amountParts.decimals}</Text>
              <Text style={styles.invoiceCurrency}>{invoice.currency || currency}</Text>
            </View>

            <View style={styles.invoiceActions}>
              <PrimaryButton
                compact
                disabled={activeActionId === invoice.id}
                label={activeActionId === invoice.id ? "..." : "Plateste"}
                loading={activeActionId === invoice.id}
                onPress={() => handleCheckout(invoice)}
              />
              {isProcessing ? (
                <GhostButton
                  disabled={activeActionId === invoice.id}
                  label="Verifica"
                  onPress={() => verifyPayment(invoice)}
                />
              ) : null}
            </View>
          </View>
        );
      })}

      {unpaidCount > PREVIEW_LIMIT || invoices.length > 0 ? (
        <Pressable
          onPress={() => navigation.navigate("Facturi")}
          style={({ pressed }) => [styles.linkButton, pressed && ui.pressed]}
        >
          <Text style={styles.linkButtonText}>Vezi toate facturile</Text>
          <MaterialCommunityIcons color={colors.accent} name="chevron-right" size={18} />
        </Pressable>
      ) : null}
    </SectionCard>
  );
}

const styles = StyleSheet.create({
  loadingCard: {
    borderRadius: layout.cardRadiusLg,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.card,
    padding: 24,
    alignItems: "center",
  },
  scheduleCard: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: 8,
    borderRadius: layout.cardRadiusSm,
    padding: 10,
    backgroundColor: "rgba(255,238,0,0.06)",
    borderWidth: 1,
    borderColor: "rgba(255,238,0,0.12)",
  },
  scheduleText: {
    flex: 1,
    color: colors.textMuted,
    fontSize: 11,
    fontWeight: "700",
    lineHeight: 16,
  },
  paidBanner: {
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
    borderRadius: layout.cardRadiusSm,
    paddingHorizontal: 12,
    paddingVertical: 10,
    backgroundColor: "rgba(77,255,161,0.08)",
    borderWidth: 1,
    borderColor: "rgba(77,255,161,0.16)",
  },
  paidBannerText: {
    flex: 1,
    color: colors.success,
    fontSize: 12,
    fontWeight: "800",
  },
  invoiceCard: {
    borderRadius: layout.cardRadiusMd,
    padding: 12,
    backgroundColor: colors.bgSoft,
    borderWidth: 1,
    borderColor: colors.border,
    gap: 10,
  },
  invoiceTop: {
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
  },
  invoiceIcon: {
    width: 34,
    height: 34,
    borderRadius: 11,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accent,
  },
  invoiceTitle: {
    color: colors.text,
    fontSize: 13,
    fontWeight: "900",
  },
  invoiceMeta: {
    color: colors.textMuted,
    fontSize: 11,
    fontWeight: "700",
    marginTop: 2,
  },
  invoiceAmountRow: {
    flexDirection: "row",
    alignItems: "flex-end",
    gap: 1,
  },
  invoiceAmountWhole: {
    color: colors.text,
    fontSize: 22,
    fontWeight: "900",
    letterSpacing: -0.4,
  },
  invoiceAmountDec: {
    color: colors.accent,
    fontSize: 13,
    fontWeight: "900",
    marginBottom: 3,
  },
  invoiceCurrency: {
    color: colors.textMuted,
    fontSize: 11,
    fontWeight: "800",
    marginBottom: 4,
    marginLeft: 3,
  },
  invoiceActions: {
    flexDirection: "row",
    gap: 8,
  },
  linkButton: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 2,
    paddingVertical: 4,
  },
  linkButtonText: {
    color: colors.accent,
    fontSize: 13,
    fontWeight: "900",
  },
});
