import React, { useCallback, useState } from "react";
import {
  Alert,
  FlatList,
  Pressable,
  RefreshControl,
  StyleSheet,
  Text,
  View,
} from "react-native";
import { File, Paths } from "expo-file-system";
import * as Sharing from "expo-sharing";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { useTabScreenBottomPadding } from "../hooks/useTabScreenBottomPadding";
import {
  EmptyStateCard,
  ScreenHeader,
  splitAmount,
} from "../components/ui/ScreenUi";
import apiClient from "../api/client";
import { useApiResource } from "../hooks/useApiResource";
import { useAppResumeRefresh } from "../hooks/useAppResumeRefresh";
import { useScreenFocusLoad } from "../hooks/useScreenFocusLoad";
import { getApiErrorMessage } from "../utils/apiErrors";
import { formatInvoiceMonth, formatInvoicePeriod, downloadInvoiceFile, parseInvoicesResponse } from "../utils/invoices";
import { colors, layout, motion } from "../theme";
import { ListScreenSkeleton } from "../components/ui/Skeleton";

const EMPTY_INVOICE_DATA = { invoices: [], summary: null, statistics: null };

export default function InvoicesScreen() {
  return <PersonalInvoicesScreen />;
}

function PersonalInvoicesScreen() {
  const bottomPadding = useTabScreenBottomPadding();
  const [activeActionId, setActiveActionId] = useState(null);

  const fetchInvoices = useCallback(async () => {
    const response = await apiClient.get("/invoices");
    return parseInvoicesResponse(response.data);
  }, []);

  const {
    data: invoiceData,
    loading,
    refreshing,
    load: loadInvoices,
  } = useApiResource(fetchInvoices, EMPTY_INVOICE_DATA);

  const invoices = invoiceData.invoices;

  useScreenFocusLoad(loadInvoices);
  useAppResumeRefresh(
    useCallback(() => {
      void loadInvoices("silent").catch(() => null);
    }, [loadInvoices])
  );

  const downloadInvoice = async (invoice) => {
    try {
      setActiveActionId(invoice.id);
      await downloadInvoiceFile(apiClient, invoice, { Sharing, File, Paths, Alert, getApiErrorMessage });
    } catch (error) {
      Alert.alert("Factura", getApiErrorMessage(error));
    } finally {
      setActiveActionId(null);
    }
  };

  if (loading && !invoices.length) {
    return <ListScreenSkeleton />;
  }

  return (
    <FlatList
      data={invoices}
      keyExtractor={(item) => String(item.id)}
      contentContainerStyle={[styles.content, { paddingBottom: bottomPadding }]}
      refreshControl={
        <RefreshControl
          refreshing={refreshing}
          onRefresh={() => {
            void loadInvoices(true).catch(() => null);
          }}
          tintColor={colors.accent}
        />
      }
      showsVerticalScrollIndicator={false}
      ListHeaderComponent={
        <View style={styles.headerWrap}>
          <ScreenHeader icon="file-document-outline" title="Facturi" />
        </View>
      }
      ListEmptyComponent={
        <EmptyStateCard
          detail="Facturile apar dupa fiecare sesiune de incarcare."
          icon="file-document-outline"
          title="Nu exista facturi"
        />
      }
      renderItem={({ item }) => (
        <InvoiceCard activeActionId={activeActionId} invoice={item} onDownload={downloadInvoice} />
      )}
    />
  );
}

function InvoiceCard({ invoice, activeActionId, onDownload }) {
  const currency = invoice.currency || "MDL";
  const isTopup = invoice.invoice_type === "wallet_topup";
  const busy = activeActionId === invoice.id;
  const monthLabel = formatInvoiceMonth(invoice.month, invoice.period_start);
  const title = isTopup ? "Alimentare sold" : invoice.invoice_type === "session" ? "Sesiune" : monthLabel;
  const amountParts = splitAmount(invoice.total_amount);
  const meta = [
    invoice.invoice_number ?? `#${invoice.id}`,
    formatInvoicePeriod(invoice),
    isTopup ? null : `${Number(invoice.total_kwh || 0).toFixed(2)} kWh`,
  ].filter(Boolean).join(" · ");

  return (
    <View style={styles.card}>
      <View style={styles.cardTop}>
        <View style={styles.cardIcon}>
          <MaterialCommunityIcons
            name={isTopup ? "wallet-outline" : "lightning-bolt"}
            size={18}
            color={colors.accentText}
          />
        </View>
        <View style={{ flex: 1 }}>
          <Text style={styles.cardTitle} numberOfLines={1}>{title}</Text>
          <Text style={styles.cardMeta} numberOfLines={1}>{meta}</Text>
        </View>
      </View>

      <View style={styles.cardFoot}>
        <Text style={styles.amount}>
          {amountParts.whole}.{amountParts.decimals}
          <Text style={styles.amountCurrency}> {currency}</Text>
        </Text>
        <Pressable
          accessibilityLabel="Descarca factura"
          disabled={busy}
          hitSlop={8}
          onPress={() => onDownload(invoice)}
          style={({ pressed }) => [styles.downloadButton, pressed && styles.pressed]}
        >
          <MaterialCommunityIcons
            name={busy ? "progress-download" : "download-outline"}
            size={18}
            color={colors.accent}
          />
        </Pressable>
      </View>
    </View>
  );
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
    gap: 12,
    marginBottom: 4,
  },
  card: {
    borderWidth: 1,
    borderColor: colors.borderSoft,
    borderRadius: layout.cardRadiusLg,
    padding: 14,
    backgroundColor: colors.card,
    gap: 10,
    marginBottom: 10,
  },
  cardTop: {
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
  },
  cardIcon: {
    width: 38,
    height: 38,
    borderRadius: 12,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accent,
  },
  cardTitle: {
    color: colors.text,
    fontSize: 14,
    fontWeight: "900",
  },
  cardMeta: {
    color: colors.textMuted,
    marginTop: 2,
    fontSize: 11,
    fontWeight: "700",
  },
  cardFoot: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    gap: 10,
  },
  amount: {
    color: colors.text,
    fontSize: 18,
    fontWeight: "900",
    letterSpacing: -0.3,
  },
  amountCurrency: {
    color: colors.textMuted,
    fontSize: 12,
    fontWeight: "800",
  },
  downloadButton: {
    width: 36,
    height: 36,
    borderRadius: 10,
    alignItems: "center",
    justifyContent: "center",
    borderWidth: 1,
    borderColor: "rgba(255,238,0,0.18)",
    backgroundColor: colors.bgSoft,
  },
  pressed: {
    opacity: motion.pressOpacity,
    transform: [{ scale: motion.pressScale }],
  },
});
