import React, { useCallback, useMemo } from "react";
import { ActivityIndicator, RefreshControl, ScrollView, StyleSheet, Text, View } from "react-native";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import apiClient from "../api/client";
import { useApiResource } from "../hooks/useApiResource";
import { useAppResumeRefresh } from "../hooks/useAppResumeRefresh";
import { useScreenFocusLoad } from "../hooks/useScreenFocusLoad";
import { colors, layout, typography } from "../theme";

export default function HistorySessionsScreen() {
  const insets = useSafeAreaInsets();

  const fetchSessions = useCallback(async () => {
    const response = await apiClient.get("/sessions");
    return response.data;
  }, []);

  const {
    data: sessions,
    loading,
    refreshing,
    load: loadSessions,
  } = useApiResource(fetchSessions, []);

  useScreenFocusLoad(loadSessions);
  useAppResumeRefresh(
    useCallback(() => {
      void loadSessions(true).catch(() => null);
    }, [loadSessions])
  );

  const totals = useMemo(() => {
    const completed = sessions.filter((item) => item.end_time);
    return {
      sessions: sessions.length,
      active: sessions.filter((item) => !item.end_time).length,
      totalKwh: completed.reduce((sum, item) => sum + Number(item.kwh_consumed || 0), 0),
    };
  }, [sessions]);

  if (loading && !sessions.length) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={colors.accent} />
      </View>
    );
  }

  return (
    <ScrollView
      style={styles.page}
      contentContainerStyle={[styles.content, { paddingBottom: Math.max(insets.bottom, 12) + 104 }]}
      refreshControl={
        <RefreshControl
          refreshing={refreshing}
          onRefresh={() => {
            void loadSessions(true).catch(() => null);
          }}
        />
      }
      showsVerticalScrollIndicator={false}
    >
      <View style={styles.summaryCard}>
        <Text style={styles.eyebrow}>Istoric sesiuni</Text>
        <Text style={styles.summaryTitle}>{formatKwh(totals.totalKwh)} kWh</Text>
        <View style={styles.metricsRow}>
          <Metric label="Sesiuni" value={String(totals.sessions)} />
          <Metric label="Active" value={String(totals.active)} />
        </View>
      </View>

      <View style={styles.list}>
        {sessions.length ? (
          sessions.map((item) => <SessionCard key={item.id} session={item} />)
        ) : (
          <View style={styles.emptyCard}>
            <MaterialCommunityIcons name="history" size={28} color={colors.textMuted} />
            <Text style={styles.emptyTitle}>Nu exista sesiuni</Text>
          </View>
        )}
      </View>
    </ScrollView>
  );
}

function Metric({ label, value }) {
  return (
    <View style={styles.metricCard}>
      <Text style={styles.metricLabel}>{label}</Text>
      <Text style={styles.metricValue}>{value}</Text>
    </View>
  );
}

function SessionCard({ session }) {
  const active = !session.end_time;

  return (
    <View style={styles.card}>
      <View style={styles.cardTop}>
        <View style={{ flex: 1 }}>
          <Text style={styles.title}>{session.station?.name ?? "Statie"}</Text>
          <Text style={styles.meta} numberOfLines={1}>
            {formatDateTime(session.start_time)}
            {session.station?.location ? ` · ${session.station.location}` : ""}
          </Text>
        </View>
        <View style={[styles.badge, active ? styles.badgeAccent : styles.badgeSuccess]}>
          <Text style={styles.badgeText}>{active ? "Activa" : "Finalizata"}</Text>
        </View>
      </View>
      <Text style={styles.meta}>Consum: {formatKwh(session.kwh_consumed)} kWh</Text>
    </View>
  );
}

function formatDateTime(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value || "-";
  return date.toLocaleString("ro-RO", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" });
}

function formatKwh(value) {
  return Number(value || 0).toFixed(2);
}

const styles = StyleSheet.create({
  page: { flex: 1, backgroundColor: colors.bg },
  center: { flex: 1, backgroundColor: colors.bg, justifyContent: "center", alignItems: "center" },
  content: { padding: layout.pagePadding, gap: 10 },
  summaryCard: {
    borderRadius: layout.cardRadiusLg,
    padding: 14,
    backgroundColor: colors.bgSoft,
    borderWidth: 1,
    borderColor: colors.border,
    gap: 8,
  },
  eyebrow: { color: colors.textMuted, ...typography.label },
  summaryTitle: { color: colors.text, ...typography.titleMd },
  metricsRow: { flexDirection: "row", gap: 8 },
  metricCard: {
    flex: 1,
    borderRadius: layout.cardRadiusSm,
    padding: 10,
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
  },
  metricLabel: { color: colors.textMuted, fontSize: 11, fontWeight: "800" },
  metricValue: { color: colors.text, marginTop: 4, fontSize: 15, fontWeight: "900" },
  list: { gap: 8 },
  card: {
    borderRadius: layout.cardRadiusMd,
    padding: 12,
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
    gap: 6,
  },
  cardTop: { flexDirection: "row", alignItems: "center", gap: 8 },
  title: { color: colors.text, ...typography.titleSm },
  meta: { color: colors.textMuted, ...typography.meta },
  badge: { borderRadius: 999, paddingHorizontal: 9, paddingVertical: 5 },
  badgeSuccess: { backgroundColor: "rgba(77,255,161,0.14)" },
  badgeAccent: { backgroundColor: "rgba(255,238,0,0.16)" },
  badgeText: { color: colors.text, fontSize: 11, fontWeight: "900" },
  emptyCard: {
    borderRadius: layout.cardRadiusMd,
    padding: 20,
    alignItems: "center",
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.border,
    gap: 8,
  },
  emptyTitle: { color: colors.text, ...typography.titleSm },
});
