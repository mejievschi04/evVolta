import React, { useCallback, useEffect, useMemo, useState } from "react";
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
  FilterChips,
  GhostButton,
  ListRowCard,
  ScreenHeader,
  StatusChip,
  splitAmount,
} from "../components/ui/ScreenUi";
import apiClient from "../api/client";
import { useApiResource } from "../hooks/useApiResource";
import { useAppResumeRefresh } from "../hooks/useAppResumeRefresh";
import { useScreenFocusLoad } from "../hooks/useScreenFocusLoad";
import { getApiErrorMessage } from "../utils/apiErrors";
import { downloadInvoiceFile } from "../utils/invoices";
import { colors, layout, motion } from "../theme";
import { ListScreenSkeleton } from "../components/ui/Skeleton";

const FILTERS = [
  { id: "all", label: "Toate" },
  { id: "active", label: "Active" },
  { id: "closed", label: "Finalizate" },
];

const HISTORY_LIMIT = 100;

export default function HistorySessionsScreen() {
  const bottomPadding = useTabScreenBottomPadding();
  const [filter, setFilter] = useState("all");
  const [downloadingInvoiceId, setDownloadingInvoiceId] = useState(null);

  const fetchSessions = useCallback(async () => {
    // Doar ultimele sesiuni; totalurile vin de la server pentru tot istoricul.
    const [listResponse, summary] = await Promise.all([
      apiClient.get("/sessions", { params: { limit: HISTORY_LIMIT } }),
      apiClient
        .get("/sessions/summary")
        .then((response) => response.data)
        .catch(() => null),
    ]);

    return {
      sessions: Array.isArray(listResponse.data) ? listResponse.data : [],
      summary: summary && typeof summary === "object" ? summary : null,
    };
  }, []);

  const {
    data: history,
    loading,
    refreshing,
    load: loadSessions,
  } = useApiResource(fetchSessions, { sessions: [], summary: null });
  const serverSummary = history.summary;

  // Pagini incarcate cu "Incarca mai multe"; se reseteaza cand prima pagina se reimprospateaza.
  const [olderSessions, setOlderSessions] = useState([]);
  const [hasMore, setHasMore] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);

  useEffect(() => {
    setOlderSessions([]);
    setHasMore(history.sessions.length >= HISTORY_LIMIT);
  }, [history]);

  const sessions = useMemo(() => {
    const seen = new Set();
    return [...history.sessions, ...olderSessions].filter((item) => {
      const key = String(item.id);
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  }, [history.sessions, olderSessions]);

  const loadMore = async () => {
    if (loadingMore || !hasMore) return;
    try {
      setLoadingMore(true);
      const response = await apiClient.get("/sessions", {
        params: { limit: HISTORY_LIMIT, offset: history.sessions.length + olderSessions.length },
      });
      const page = Array.isArray(response.data) ? response.data : [];
      setOlderSessions((current) => [...current, ...page]);
      setHasMore(page.length >= HISTORY_LIMIT);
    } catch (error) {
      Alert.alert("Istoric", getApiErrorMessage(error));
    } finally {
      setLoadingMore(false);
    }
  };

  useScreenFocusLoad(loadSessions);
  useAppResumeRefresh(
    useCallback(() => {
      void loadSessions("silent").catch(() => null);
    }, [loadSessions])
  );

  const filteredSessions = useMemo(() => {
    const sorted = [...sessions].sort(
      (a, b) => new Date(b.start_time).getTime() - new Date(a.start_time).getTime()
    );

    if (filter === "active") return sorted.filter((item) => !item.end_time);
    if (filter === "closed") return sorted.filter((item) => item.end_time);
    return sorted;
  }, [filter, sessions]);

  const totals = useMemo(() => {
    if (serverSummary) {
      return {
        sessions: Number(serverSummary.sessions ?? 0),
        active: Number(serverSummary.active ?? 0),
        closed: Number(serverSummary.closed ?? 0),
        totalKwh: Number(serverSummary.total_kwh ?? 0),
        totalSpent: Number(serverSummary.total_spent ?? 0),
        currency: serverSummary.currency || "MDL",
      };
    }

    // Server mai vechi fara /sessions/summary: calcul local din lista primita.
    const completed = sessions.filter((item) => item.end_time);
    const totalSpent = completed.reduce(
      (sum, item) => sum + Number(item.billing?.amount_spent ?? item.invoice?.total_amount ?? 0),
      0
    );
    const currency =
      completed.find((item) => item.billing?.currency || item.invoice?.currency)?.billing?.currency
      || completed.find((item) => item.invoice?.currency)?.invoice?.currency
      || "MDL";

    return {
      sessions: sessions.length,
      active: sessions.filter((item) => !item.end_time).length,
      closed: completed.length,
      totalKwh: completed.reduce((sum, item) => sum + Number(item.kwh_consumed || 0), 0),
      totalSpent,
      currency,
    };
  }, [serverSummary, sessions]);

  const handleDownloadInvoice = async (invoice) => {
    if (!invoice?.id) {
      return;
    }

    try {
      setDownloadingInvoiceId(invoice.id);
      await downloadInvoiceFile(apiClient, invoice, { Sharing, File, Paths, Alert, getApiErrorMessage });
    } catch (error) {
      Alert.alert("Factura", getApiErrorMessage(error));
    } finally {
      setDownloadingInvoiceId(null);
    }
  };

  if (loading && !sessions.length) {
    return <ListScreenSkeleton />;
  }

  return (
    <FlatList
      data={filteredSessions}
      keyExtractor={(item) => String(item.id)}
      contentContainerStyle={[styles.content, { paddingBottom: bottomPadding }]}
      refreshControl={
        <RefreshControl
          refreshing={refreshing}
          onRefresh={() => {
            void loadSessions(true).catch(() => null);
          }}
          tintColor={colors.accent}
        />
      }
      showsVerticalScrollIndicator={false}
      ListHeaderComponent={
        <View style={styles.headerWrap}>
          <ScreenHeader icon="history" title="Istoric" />

          <SummaryCard totals={totals} />

          <FilterChips onChange={setFilter} options={FILTERS} value={filter} />
        </View>
      }
      ListFooterComponent={
        hasMore ? (
          <View style={styles.loadMore}>
            <GhostButton
              label={loadingMore ? "Se incarca..." : "Incarca mai multe"}
              loading={loadingMore}
              disabled={loadingMore}
              onPress={() => void loadMore()}
            />
          </View>
        ) : null
      }
      ListEmptyComponent={
        <EmptyStateCard
          detail="Sesiunile incheiate apar aici."
          icon="history"
          title="Nicio sesiune"
        />
      }
      renderItem={({ item }) => (
        <View style={styles.sessionItem}>
          <SessionCard
            downloadingInvoiceId={downloadingInvoiceId}
            onDownloadInvoice={handleDownloadInvoice}
            session={item}
          />
        </View>
      )}
    />
  );
}

function SummaryCard({ totals }) {
  const kwhParts = splitAmount(totals.totalKwh);
  const spentParts = splitAmount(totals.totalSpent);

  return (
    <View style={styles.summaryCard}>
      <SummaryStat
        icon="lightning-bolt"
        label="Consumat"
        suffix="kWh"
        value={`${kwhParts.whole}.${kwhParts.decimals}`}
      />
      <View style={styles.summaryDivider} />
      <SummaryStat
        icon="cash-multiple"
        label="Cheltuit"
        suffix={totals.currency}
        value={`${spentParts.whole}.${spentParts.decimals}`}
      />
      <View style={styles.summaryDivider} />
      <SummaryStat
        icon="counter"
        label="Sesiuni"
        suffix={totals.active > 0 ? `${totals.active} activa` : " "}
        value={String(totals.sessions)}
      />
    </View>
  );
}

function SummaryStat({ icon, label, value, suffix }) {
  return (
    <View style={styles.summaryStat}>
      <MaterialCommunityIcons color={colors.accent} name={icon} size={16} />
      <Text style={styles.summaryLabel} numberOfLines={1}>{label}</Text>
      <Text style={styles.summaryValue} numberOfLines={1} adjustsFontSizeToFit>{value}</Text>
      <Text style={styles.summarySuffix} numberOfLines={1}>{suffix}</Text>
    </View>
  );
}

function SessionCard({ session, onDownloadInvoice, downloadingInvoiceId }) {
  const active = !session.end_time;
  const kwh = Number(session.kwh_consumed || 0);
  const duration = sessionDuration(session.start_time, session.end_time);
  const amountSpent = session.billing?.amount_spent ?? session.invoice?.total_amount ?? null;
  const currency = session.billing?.currency || session.invoice?.currency || "MDL";
  const invoice = session.invoice;
  const invoiceBusy = invoice?.id && downloadingInvoiceId === invoice.id;

  return (
    <ListRowCard
      icon="ev-station"
      meta={`${formatSessionDate(session.start_time)} · ${duration}`}
      title={session.station?.name ?? "Statie"}
      trailing={
        <StatusChip label={active ? "Activa" : "Finalizata"} tone={active ? "warning" : "success"} />
      }
    >
      <View style={styles.sessionFooter}>
        <StatPill icon="lightning-bolt" label={`${formatKwh(kwh)} kWh`} />
        <StatPill
          accent
          icon="cash"
          label={
            amountSpent != null
              ? `${formatMoney(amountSpent)} ${currency}`
              : active
                ? "In curs"
                : `0.00 ${currency}`
          }
        />
        {invoice?.id ? (
          <Pressable
            accessibilityLabel="Descarca factura"
            disabled={invoiceBusy}
            hitSlop={8}
            onPress={() => onDownloadInvoice(invoice)}
            style={({ pressed }) => [styles.invoiceIconButton, pressed && styles.pressed]}
          >
            <MaterialCommunityIcons
              color={colors.accent}
              name={invoiceBusy ? "progress-download" : "download-outline"}
              size={18}
            />
          </Pressable>
        ) : null}
      </View>
    </ListRowCard>
  );
}

function StatPill({ icon, label, accent }) {
  return (
    <View style={[styles.statPill, accent && styles.statPillAccent]}>
      <MaterialCommunityIcons color={accent ? colors.accent : colors.textMuted} name={icon} size={13} />
      <Text style={[styles.statPillText, accent && styles.statPillTextAccent]}>{label}</Text>
    </View>
  );
}

function sessionDuration(start, end) {
  if (!start || !end) return activeLabel(end);
  const startDate = new Date(start);
  const endDate = new Date(end);
  if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime())) return "—";

  const minutes = Math.max(1, Math.round((endDate.getTime() - startDate.getTime()) / 60000));
  if (minutes < 60) return `${minutes} min`;
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;
  return rest ? `${hours}h ${rest}m` : `${hours}h`;
}

function activeLabel(end) {
  return end ? "—" : "In curs";
}

function formatSessionDate(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value || "-";

  const now = new Date();
  const sameDay =
    date.getDate() === now.getDate()
    && date.getMonth() === now.getMonth()
    && date.getFullYear() === now.getFullYear();
  const yesterday = new Date(now);
  yesterday.setDate(now.getDate() - 1);
  const isYesterday =
    date.getDate() === yesterday.getDate()
    && date.getMonth() === yesterday.getMonth()
    && date.getFullYear() === yesterday.getFullYear();

  const time = date.toLocaleString("ro-RO", { hour: "2-digit", minute: "2-digit" });
  if (sameDay) return `Azi, ${time}`;
  if (isYesterday) return `Ieri, ${time}`;

  return date.toLocaleString("ro-RO", {
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function formatKwh(value) {
  return Number(value || 0).toFixed(2);
}

function formatMoney(value) {
  return Number(value || 0).toFixed(2);
}

const styles = StyleSheet.create({
  page: { flex: 1, backgroundColor: colors.bg },
  center: { flex: 1, backgroundColor: colors.bg, justifyContent: "center", alignItems: "center" },
  content: { padding: layout.pagePadding, gap: 10, backgroundColor: colors.bg },
  loadMore: { paddingVertical: 12 },
  headerWrap: { gap: 12, marginBottom: 4 },
  sessionItem: { marginBottom: 10 },
  summaryCard: {
    flexDirection: "row",
    alignItems: "stretch",
    borderRadius: layout.cardRadiusMd,
    paddingVertical: 14,
    paddingHorizontal: 8,
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.borderSoft,
  },
  summaryStat: {
    flex: 1,
    alignItems: "center",
    gap: 2,
    paddingHorizontal: 4,
  },
  summaryLabel: {
    color: colors.textMuted,
    fontSize: 10,
    fontWeight: "800",
    textTransform: "uppercase",
    letterSpacing: 0.4,
    textAlign: "center",
    marginTop: 4,
  },
  summaryValue: {
    color: colors.text,
    fontSize: 22,
    fontWeight: "900",
    letterSpacing: -0.4,
    textAlign: "center",
    fontVariant: ["tabular-nums"],
    marginTop: 2,
  },
  summarySuffix: {
    color: colors.textMuted,
    fontSize: 11,
    fontWeight: "700",
    textAlign: "center",
    minHeight: 14,
  },
  summaryDivider: {
    width: 1,
    marginHorizontal: 10,
    backgroundColor: colors.borderSoft,
  },
  sessionFooter: {
    flexDirection: "row",
    alignItems: "center",
    flexWrap: "wrap",
    gap: 8,
    marginTop: 10,
  },
  statPill: {
    flexDirection: "row",
    alignItems: "center",
    gap: 5,
    paddingHorizontal: 10,
    paddingVertical: 7,
    borderRadius: 999,
    backgroundColor: colors.bgSoft,
    borderWidth: 1,
    borderColor: colors.borderSoft,
  },
  statPillAccent: {
    backgroundColor: "rgba(255,238,0,0.06)",
    borderColor: "rgba(255,238,0,0.14)",
  },
  statPillText: {
    color: colors.text,
    fontSize: 12,
    fontWeight: "800",
    fontVariant: ["tabular-nums"],
  },
  statPillTextAccent: {
    color: colors.accent,
  },
  invoiceIconButton: {
    width: 36,
    height: 36,
    marginLeft: "auto",
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
