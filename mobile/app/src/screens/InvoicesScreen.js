import React, { useCallback, useMemo, useState } from "react";
import {
  Alert,
  ActivityIndicator,
  FlatList,
  Linking,
  Pressable,
  RefreshControl,
  StyleSheet,
  Text,
  View,
} from "react-native";
import { File, Paths } from "expo-file-system";
import * as Sharing from "expo-sharing";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import apiClient from "../api/client";
import { useApiResource } from "../hooks/useApiResource";
import { useAppResumeRefresh } from "../hooks/useAppResumeRefresh";
import { useScreenFocusLoad } from "../hooks/useScreenFocusLoad";
import { getApiErrorMessage } from "../utils/apiErrors";
import { colors, layout, motion, typography } from "../theme";

export default function InvoicesScreen() {
  const [activeActionId, setActiveActionId] = useState(null);

  const fetchInvoices = useCallback(async () => {
    const response = await apiClient.get("/invoices");
    return response.data;
  }, []);

  const {
    data: invoices,
    loading,
    refreshing,
    load: loadInvoices,
  } = useApiResource(fetchInvoices, []);

  useScreenFocusLoad(loadInvoices);
  useAppResumeRefresh(loadInvoices);

  const totals = useMemo(() => {
    const unpaidAmount = invoices
      .filter((item) => item.status !== "paid")
      .reduce((sum, item) => sum + Number(item.total_amount || 0), 0);
    const paidCount = invoices.filter((item) => item.status === "paid").length;
    const unpaidCount = invoices.filter((item) => item.status !== "paid").length;
    const currency = invoices.find((item) => item.currency)?.currency || "MDL";

    return {
      total: invoices.length,
      paidCount,
      unpaidCount,
      unpaidAmount,
      currency,
    };
  }, [invoices]);

  const handleCheckout = async (invoice) => {
    try {
      setActiveActionId(invoice.id);
      const response = await apiClient.post(`/invoices/${invoice.id}/checkout-session`);
      const checkoutUrl = response.data.checkout_url;

      if (!checkoutUrl) {
        Alert.alert("Plata online", "Nu am putut genera pagina de plata.");
        return;
      }

      await Linking.openURL(checkoutUrl);
      await loadInvoices(true).catch(() => null);
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
      await loadInvoices(true).catch(() => null);
    } catch (error) {
      Alert.alert("Verificare plata", getApiErrorMessage(error));
    } finally {
      setActiveActionId(null);
    }
  };

  const downloadInvoice = async (invoice) => {
    try {
      setActiveActionId(invoice.id);
      const response = await apiClient.get(`/invoices/${invoice.id}/download`, {
        responseType: "text",
      });

      const filename = invoiceFilename(response.headers?.["content-disposition"], invoice);
      const file = new File(Paths.cache, filename);
      file.write(String(response.data));

      if (!(await Sharing.isAvailableAsync())) {
        Alert.alert("Factura", "Factura a fost salvata local, dar partajarea nu este disponibila pe acest dispozitiv.");
        return;
      }

      await Sharing.shareAsync(file.uri, {
        mimeType: "text/html",
        dialogTitle: "Factura Volta EV",
        UTI: "public.html",
      });
    } catch (error) {
      Alert.alert("Factura", getApiErrorMessage(error));
    } finally {
      setActiveActionId(null);
    }
  };

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={colors.accent} />
      </View>
    );
  }

  return (
    <FlatList
      data={invoices}
      keyExtractor={(item) => String(item.id)}
      refreshControl={
        <RefreshControl
          refreshing={refreshing}
          onRefresh={() => {
            void loadInvoices(true).catch(() => null);
          }}
        />
      }
      contentContainerStyle={styles.content}
      ListHeaderComponent={
        <View style={styles.headerWrap}>
          <View style={styles.summaryCard}>
            <View style={styles.summaryTop}>
              <View>
                <Text style={styles.eyebrow}>Facturare</Text>
                <Text style={styles.summaryTitle}>{totals.total}</Text>
                <Text style={styles.summarySub}>Facturi totale generate</Text>
              </View>
              <View style={styles.summaryIcon}>
                <MaterialCommunityIcons name="file-document-multiple-outline" size={22} color={colors.accentText} />
              </View>
            </View>
            <View style={styles.statsRow}>
              <View style={styles.stat}>
                <Text style={styles.statLabel}>Achitate</Text>
                <Text style={styles.statValue}>{totals.paidCount}</Text>
              </View>
              <View style={styles.stat}>
                <Text style={styles.statLabel}>Neachitate</Text>
                <Text style={[styles.statValue, totals.unpaidCount ? styles.statValueDanger : null]}>
                  {totals.unpaidCount}
                </Text>
              </View>
              <View style={styles.stat}>
                <Text style={styles.statLabel}>Restanta</Text>
                <Text style={[styles.statValue, totals.unpaidAmount ? styles.statValueDanger : null]} numberOfLines={1}>
                  {formatMoney(totals.unpaidAmount)} {totals.currency}
                </Text>
              </View>
            </View>
          </View>
        </View>
      }
      ListEmptyComponent={<Text style={styles.emptyText}>Nu exista facturi momentan.</Text>}
      renderItem={({ item }) => {
        const currency = item.currency || "MDL";
        const isPaid = item.status === "paid";
        const isProcessing = !isPaid && Boolean(item.payment_session_id);

        return (
          <View style={styles.card}>
            <View style={styles.rowTop}>
              <View style={{ flex: 1 }}>
                <Text style={styles.title}>
                  {item.invoice_type === "session" ? "Factura de sesiune" : "Factura lunara"}
                </Text>
                <Text style={styles.meta} numberOfLines={1}>
                  Numar: {item.invoice_number ?? "-"}
                </Text>
              </View>
              <StatusBadge paid={isPaid} processing={isProcessing} />
            </View>

            <View style={styles.detailRow}>
              <Detail icon="flash" label="Consum" value={`${Number(item.total_kwh || 0).toFixed(2)} kWh`} />
              <Detail icon="cash" label="Total" value={`${formatMoney(item.total_amount)} ${currency}`} />
            </View>

            <Text style={styles.meta}>Perioada: {formatPeriod(item.period_start, item.period_end, item.month)}</Text>

            <View style={styles.actionsRow}>
              {!isPaid ? (
                <Pressable
                  style={({ pressed }) => [styles.primaryButton, pressed && styles.buttonPressed]}
                  onPress={() => handleCheckout(item)}
                  disabled={activeActionId === item.id}
                >
                  <Text style={styles.primaryButtonText}>
                    {activeActionId === item.id ? "Se deschide..." : "Plateste online"}
                  </Text>
                </Pressable>
              ) : null}

              {!isPaid && item.payment_session_id ? (
                <Pressable
                  style={({ pressed }) => [styles.secondaryButton, pressed && styles.subtleButtonPressed]}
                  onPress={() => verifyPayment(item)}
                  disabled={activeActionId === item.id}
                >
                  <Text style={styles.secondaryButtonText}>
                    {activeActionId === item.id ? "Se verifica..." : "Verifica plata"}
                  </Text>
                </Pressable>
              ) : null}
            </View>

            <Pressable
              style={({ pressed }) => [styles.downloadButton, pressed && styles.subtleButtonPressed]}
              onPress={() => downloadInvoice(item)}
              disabled={activeActionId === item.id}
            >
              <Text style={styles.downloadButtonText}>
                {activeActionId === item.id ? "Se pregateste..." : "Descarca factura"}
              </Text>
            </Pressable>

            {item.paid_at ? <Text style={styles.paidAt}>Platita la {formatDate(item.paid_at)}</Text> : null}
          </View>
        );
      }}
    />
  );
}

function Detail({ icon, label, value }) {
  return (
    <View style={styles.detail}>
      <MaterialCommunityIcons name={icon} size={16} color={colors.accent} />
      <View style={styles.detailTextBlock}>
        <Text style={styles.detailLabel}>{label}</Text>
        <Text style={styles.detailValue} numberOfLines={1}>
          {value}
        </Text>
      </View>
    </View>
  );
}

function StatusBadge({ paid, processing }) {
  const label = paid ? "Platita" : processing ? "In curs" : "Neplatita";
  const style = paid ? styles.badgePaid : processing ? styles.badgeProcessing : styles.badgeUnpaid;

  return (
    <View style={[styles.badge, style]}>
      <Text style={styles.badgeText}>{label}</Text>
    </View>
  );
}

function formatDate(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return date.toLocaleString("ro-RO", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function formatPeriod(start, end, month) {
  if (start || end) {
    return `${formatShortDate(start)} - ${formatShortDate(end)}`;
  }
  return month || "-";
}

function formatShortDate(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value || "-";
  return date.toLocaleDateString("ro-RO", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  });
}

function formatMoney(value) {
  return Number(value || 0).toFixed(2);
}

function invoiceFilename(contentDisposition, invoice) {
  const match = String(contentDisposition || "").match(/filename="?([^";]+)"?/i);
  const fallback = invoice.invoice_number ? `${invoice.invoice_number}.html` : `factura-${invoice.id}.html`;
  const filename = match?.[1] || fallback;

  return filename.replace(/[^a-zA-Z0-9._-]/g, "-").toLowerCase();
}

const styles = StyleSheet.create({
  center: {
    flex: 1,
    backgroundColor: colors.bg,
    justifyContent: "center",
    alignItems: "center",
  },
  content: {
    padding: layout.pagePadding,
    gap: 10,
    backgroundColor: colors.bg,
  },
  headerWrap: {
    marginBottom: 12,
  },
  summaryCard: {
    borderRadius: layout.cardRadiusLg,
    padding: 14,
    backgroundColor: colors.bgSoft,
    borderWidth: 1,
    borderColor: colors.border,
    gap: 12,
  },
  summaryTop: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
    gap: 12,
  },
  eyebrow: {
    color: colors.textMuted,
    fontSize: 12,
    fontWeight: "800",
  },
  summaryTitle: {
    color: colors.text,
    marginTop: 4,
    ...typography.titleLg,
  },
  summarySub: {
    color: colors.textMuted,
    marginTop: 4,
    fontSize: 12,
    fontWeight: "700",
  },
  summaryIcon: {
    width: 48,
    height: 48,
    borderRadius: 24,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accent,
  },
  statsRow: {
    flexDirection: "row",
    gap: 8,
  },
  stat: {
    flex: 1,
    borderRadius: layout.cardRadiusSm,
    padding: 10,
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
  },
  statLabel: {
    color: colors.textMuted,
    fontSize: 11,
    fontWeight: "800",
  },
  statValue: {
    color: colors.text,
    marginTop: 4,
    fontSize: 15,
    fontWeight: "900",
  },
  statValueDanger: {
    color: colors.danger,
  },
  emptyText: {
    color: colors.textMuted,
    padding: 12,
  },
  card: {
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.cardRadiusMd,
    padding: 14,
    backgroundColor: colors.card,
    gap: 8,
  },
  rowTop: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: 10,
  },
  title: {
    color: colors.text,
    ...typography.titleSm,
    marginBottom: 4,
  },
  meta: {
    color: colors.textMuted,
    marginTop: 2,
    ...typography.meta,
  },
  amount: {
    color: colors.accent,
    marginTop: 2,
    fontWeight: "800",
  },
  detailRow: {
    flexDirection: "row",
    gap: 8,
  },
  detail: {
    flex: 1,
    minHeight: 58,
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
    borderRadius: layout.cardRadiusSm,
    paddingHorizontal: 10,
    backgroundColor: colors.bgSoft,
    borderWidth: 1,
    borderColor: colors.border,
  },
  detailTextBlock: {
    flex: 1,
  },
  detailLabel: {
    color: colors.textMuted,
    fontSize: 11,
    fontWeight: "800",
  },
  detailValue: {
    color: colors.text,
    marginTop: 3,
    fontWeight: "900",
    fontSize: 13,
  },
  badge: {
    paddingHorizontal: 10,
    paddingVertical: 7,
    borderRadius: 999,
    alignSelf: "flex-start",
  },
  badgePaid: {
    backgroundColor: "rgba(255,238,0,0.15)",
  },
  badgeProcessing: {
    backgroundColor: "rgba(255,255,255,0.08)",
  },
  badgeUnpaid: {
    backgroundColor: "rgba(255,77,103,0.14)",
  },
  badgeText: {
    color: colors.text,
    fontWeight: "800",
    fontSize: 12,
  },
  actionsRow: {
    flexDirection: "row",
    gap: 10,
    marginTop: 4,
  },
  primaryButton: {
    flex: 1,
    minHeight: layout.buttonHeight,
    borderRadius: layout.buttonRadius,
    backgroundColor: colors.accent,
    alignItems: "center",
    justifyContent: "center",
    paddingHorizontal: 12,
  },
  primaryButtonText: {
    color: colors.accentText,
    ...typography.button,
  },
  secondaryButton: {
    flex: 1,
    minHeight: layout.buttonHeight,
    borderRadius: layout.buttonRadius,
    backgroundColor: colors.bgSoft,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: "center",
    justifyContent: "center",
    paddingHorizontal: 12,
  },
  secondaryButtonText: {
    color: colors.text,
    ...typography.button,
  },
  downloadButton: {
    minHeight: layout.buttonHeight,
    borderRadius: layout.buttonRadius,
    backgroundColor: colors.bgSoft,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: "center",
    justifyContent: "center",
    paddingHorizontal: 12,
  },
  downloadButtonText: {
    color: colors.text,
    ...typography.button,
  },
  buttonPressed: {
    opacity: motion.pressOpacity,
    transform: [{ scale: motion.pressScale }],
  },
  subtleButtonPressed: {
    opacity: motion.subtlePressOpacity,
    transform: [{ scale: motion.subtlePressScale }],
  },
  paidAt: {
    color: colors.textMuted,
    marginTop: 4,
    fontSize: 12,
  },
});
