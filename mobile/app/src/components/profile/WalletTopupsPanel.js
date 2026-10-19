import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { EmptyStateCard, SectionCard, StatusChip, splitAmount } from "../ui/ScreenUi";
import { colors } from "../../theme";

export default function WalletTopupsPanel({ topups, summary, currency }) {
  const rows = Array.isArray(topups) ? topups.slice(0, 8) : [];
  const displayCurrency = summary?.currency || currency || "MDL";

  return (
    <SectionCard subtitle="Ultimele alimentari Stripe confirmate" title="Istoric alimentari">
      {summary ? (
        <View style={styles.summaryRow}>
          <SummaryPill
            icon="check-decagram"
            label="Confirmate"
            value={String(summary.paid_count ?? 0)}
          />
          <SummaryPill
            icon="cash-plus"
            label="Total alimentat"
            value={`${Number(summary.total_credited ?? 0).toFixed(2)} ${displayCurrency}`}
          />
        </View>
      ) : null}

      {rows.length ? (
        <View style={styles.list}>
          {rows.map((item) => (
            <TopupRow key={item.id} currency={item.currency || displayCurrency} topup={item} />
          ))}
        </View>
      ) : (
        <EmptyStateCard
          detail="Dupa prima alimentare cu cardul, tranzactiile apar aici."
          icon="wallet-outline"
          title="Nicio alimentare"
        />
      )}
    </SectionCard>
  );
}

function SummaryPill({ icon, label, value }) {
  return (
    <View style={styles.summaryPill}>
      <MaterialCommunityIcons name={icon} size={14} color={colors.accent} />
      <View style={{ flex: 1 }}>
        <Text style={styles.summaryLabel}>{label}</Text>
        <Text style={styles.summaryValue}>{value}</Text>
      </View>
    </View>
  );
}

function TopupRow({ topup, currency }) {
  const amountParts = splitAmount(topup.amount);
  const amount = Number(topup.amount || 0);
  const refunded = Number(topup.amount_refunded || 0);
  const fullyRefunded = refunded > 0 && amount > 0 && refunded >= amount - 0.001;
  const partiallyRefunded = refunded > 0 && !fullyRefunded;
  const isPaid = topup.status === "paid" && refunded <= 0;
  const isPending = topup.status === "pending";

  const status = fullyRefunded
    ? { label: "Retur", tone: "danger" }
    : partiallyRefunded
      ? { label: "Retur partial", tone: "danger" }
      : isPaid
        ? { label: "Confirmata", tone: "success" }
        : isPending
          ? { label: "In asteptare", tone: "warning" }
          : topup.status === "expired"
            ? { label: "Expirata", tone: "neutral" }
            : { label: topup.status, tone: "neutral" };

  return (
    <View style={styles.row}>
      <View style={styles.rowIcon}>
        <MaterialCommunityIcons
          name={fullyRefunded || partiallyRefunded ? "cash-refund" : "credit-card-outline"}
          size={16}
          color={colors.accentText}
        />
      </View>
      <View style={{ flex: 1 }}>
        <Text style={styles.rowTitle}>
          +{amountParts.whole}.{amountParts.decimals} {currency}
        </Text>
        <Text style={styles.rowMeta}>
          {formatDateTime(topup.paid_at || topup.created_at)}
          {topup.payment_provider ? ` · ${providerLabel(topup.payment_provider)}` : ""}
          {refunded > 0 ? ` · retur ${refunded.toFixed(2)} ${currency}` : ""}
        </Text>
      </View>
      <StatusChip label={status.label} tone={status.tone} />
    </View>
  );
}

function providerLabel(provider) {
  if (provider === "stripe") return "Stripe";
  if (provider === "local") return "Local";
  return provider;
}

function formatDateTime(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value || "-";
  return date.toLocaleString("ro-RO", {
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  });
}

const styles = StyleSheet.create({
  summaryRow: {
    flexDirection: "row",
    gap: 8,
  },
  summaryPill: {
    flex: 1,
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
    padding: 10,
    borderRadius: 12,
    backgroundColor: colors.bgSoft,
    borderWidth: 1,
    borderColor: colors.border,
  },
  summaryLabel: {
    color: colors.textMuted,
    fontSize: 10,
    fontWeight: "800",
    textTransform: "uppercase",
    letterSpacing: 0.4,
  },
  summaryValue: {
    color: colors.text,
    marginTop: 2,
    fontSize: 13,
    fontWeight: "900",
    fontVariant: ["tabular-nums"],
  },
  list: {
    gap: 8,
  },
  row: {
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
    padding: 10,
    borderRadius: 12,
    backgroundColor: colors.bgSoft,
    borderWidth: 1,
    borderColor: colors.border,
  },
  rowIcon: {
    width: 34,
    height: 34,
    borderRadius: 10,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accent,
  },
  rowTitle: {
    color: colors.text,
    fontSize: 14,
    fontWeight: "900",
    fontVariant: ["tabular-nums"],
  },
  rowMeta: {
    color: colors.textMuted,
    marginTop: 2,
    fontSize: 11,
    fontWeight: "700",
  },
});
