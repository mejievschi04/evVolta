import React from "react";
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from "react-native";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { colors, layout, motion, shadows, surfaces, typography } from "../../theme";

export function ScreenHeader({ title, subtitle, icon, rightSlot }) {
  return (
    <View style={ui.headerRow}>
      <View style={{ flex: 1 }}>
        <Text style={ui.headerTitle}>{title}</Text>
        {subtitle ? <Text style={ui.headerSub}>{subtitle}</Text> : null}
      </View>
      {icon ? (
        <View style={ui.headerIcon}>
          <MaterialCommunityIcons name={icon} size={20} color={colors.accentText} />
        </View>
      ) : null}
      {rightSlot}
    </View>
  );
}

export function SubScreenHeader({ title, onBack }) {
  return (
    <View style={ui.subHeaderRow}>
      <Pressable
        hitSlop={12}
        onPress={onBack}
        style={({ pressed }) => [ui.subHeaderBack, pressed && ui.subtlePressed]}
      >
        <MaterialCommunityIcons name="arrow-left" size={20} color={colors.text} />
      </Pressable>
      <Text style={ui.subHeaderTitle}>{title}</Text>
      <View style={ui.subHeaderBackSpacer} />
    </View>
  );
}

export function ProfileHeroCard({ name, email, accountLabel, initials }) {
  return (
    <View style={ui.profileHero}>
      <View style={ui.profileHeroTop}>
        <View style={ui.avatar}>
          <Text style={ui.avatarText}>{initials}</Text>
        </View>
        <View style={{ flex: 1 }}>
          <Text style={ui.profileName} numberOfLines={1}>
            {name}
          </Text>
          <Text style={ui.profileEmail} numberOfLines={1}>
            {email}
          </Text>
          <View style={ui.accountBadge}>
            <MaterialCommunityIcons name="shield-account-outline" size={12} color={colors.accent} />
            <Text style={ui.accountBadgeText}>{accountLabel}</Text>
          </View>
        </View>
      </View>
    </View>
  );
}

export function HeroCard({ icon, label, children, hint, tone = "accent" }) {
  const borderColor = tone === "danger" ? "rgba(255,77,103,0.22)" : colors.borderAccent;

  return (
    <View style={[ui.heroCard, { borderColor }]}>
      {icon ? (
        <View style={ui.heroIconWrap}>
          <MaterialCommunityIcons name={icon} size={18} color={colors.accentText} />
        </View>
      ) : null}
      {label ? <Text style={ui.heroLabel}>{label}</Text> : null}
      {children}
      {hint ? <Text style={ui.heroHint}>{hint}</Text> : null}
    </View>
  );
}

export function HeroAmount({ whole, decimals, suffix }) {
  return (
    <View style={ui.heroAmountRow}>
      <Text style={ui.heroAmountWhole}>{whole}</Text>
      <Text style={ui.heroAmountDec}>.{decimals}</Text>
      {suffix ? <Text style={ui.heroCurrency}>{suffix}</Text> : null}
    </View>
  );
}

export function MetricGrid({ items = [] }) {
  return (
    <View style={ui.metricGrid}>
      {items.map((item) => (
        <View key={item.label} style={ui.metricTile}>
          {item.icon ? (
            <MaterialCommunityIcons
              name={item.icon}
              size={14}
              color={item.tone === "danger" ? colors.danger : colors.accent}
            />
          ) : null}
          <Text style={ui.metricLabel}>{item.label}</Text>
          <Text style={[ui.metricValue, item.tone === "danger" && ui.metricValueDanger]} numberOfLines={1}>
            {item.value}
          </Text>
        </View>
      ))}
    </View>
  );
}

export function FilterChips({ options, value, onChange }) {
  return (
    <View style={ui.chipRow}>
      {options.map((option) => {
        const active = value === option.id;
        return (
          <Pressable
            key={option.id}
            onPress={() => onChange(option.id)}
            style={({ pressed }) => [ui.chip, active && ui.chipActive, pressed && ui.pressed]}
          >
            <Text style={[ui.chipText, active && ui.chipTextActive]}>{option.label}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

export function StatusChip({ label, tone = "neutral" }) {
  const toneStyle =
    tone === "success"
      ? ui.chipSuccess
      : tone === "warning"
        ? ui.chipWarning
        : tone === "danger"
          ? ui.chipDanger
          : tone === "info"
            ? ui.chipInfo
            : ui.chipNeutral;

  const dotStyle =
    tone === "success"
      ? ui.dotSuccess
      : tone === "warning"
        ? ui.dotWarning
        : tone === "danger"
          ? ui.dotDanger
          : tone === "info"
            ? ui.dotInfo
            : ui.dotNeutral;

  return (
    <View style={[ui.statusChip, toneStyle]}>
      <View style={[ui.statusDot, dotStyle]} />
      <Text style={ui.statusChipText}>{label}</Text>
    </View>
  );
}

export function SectionCard({ title, subtitle, children, action }) {
  return (
    <View style={ui.sectionCard}>
      {title || subtitle || action ? (
        <View style={ui.sectionHead}>
          <View style={{ flex: 1 }}>
            {title ? <Text style={ui.sectionTitle}>{title}</Text> : null}
            {subtitle ? <Text style={ui.sectionSub}>{subtitle}</Text> : null}
          </View>
          {action}
        </View>
      ) : null}
      {children}
    </View>
  );
}

export function ListRowCard({ icon, title, meta, trailing, children }) {
  return (
    <View style={ui.listRow}>
      <View style={ui.listRowTop}>
        {icon ? (
          <View style={ui.listRowIcon}>
            <MaterialCommunityIcons name={icon} size={18} color={colors.accentText} />
          </View>
        ) : null}
        <View style={{ flex: 1 }}>
          <Text style={ui.listRowTitle} numberOfLines={1}>
            {title}
          </Text>
          {meta ? (
            <Text style={ui.listRowMeta} numberOfLines={2}>
              {meta}
            </Text>
          ) : null}
        </View>
        {trailing}
      </View>
      {children}
    </View>
  );
}

export function NavRow({ icon, title, subtitle, onPress, danger }) {
  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => [ui.navRow, danger && ui.navRowDanger, pressed && ui.pressed]}
    >
      {icon ? (
        <View style={[ui.navRowIcon, danger && ui.navRowIconDanger]}>
          <MaterialCommunityIcons
            color={danger ? colors.danger : colors.accentText}
            name={icon}
            size={18}
          />
        </View>
      ) : null}
      <View style={{ flex: 1 }}>
        <Text style={[ui.navRowTitle, danger && ui.navRowTitleDanger]}>{title}</Text>
        {subtitle ? <Text style={ui.navRowSub}>{subtitle}</Text> : null}
      </View>
      {!danger ? (
        <MaterialCommunityIcons color={colors.textMuted} name="chevron-right" size={20} />
      ) : null}
    </Pressable>
  );
}

export function EmptyStateCard({ icon, title, detail }) {
  return (
    <View style={ui.emptyCard}>
      <View style={ui.emptyIconWrap}>
        <MaterialCommunityIcons name={icon} size={24} color={colors.textMuted} />
      </View>
      <Text style={ui.emptyTitle}>{title}</Text>
      {detail ? <Text style={ui.emptyDetail}>{detail}</Text> : null}
    </View>
  );
}

export function PrimaryButton({ label, onPress, disabled, loading, icon, compact }) {
  return (
    <Pressable
      disabled={disabled || loading}
      onPress={onPress}
      style={({ pressed }) => [
        ui.primaryButton,
        compact && ui.primaryButtonCompact,
        (disabled || loading) && ui.buttonDisabled,
        pressed && ui.pressed,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={colors.accentText} size="small" />
      ) : (
        <>
          {icon ? <MaterialCommunityIcons name={icon} size={16} color={colors.accentText} /> : null}
          <Text style={ui.primaryButtonText}>{label}</Text>
        </>
      )}
    </Pressable>
  );
}

export function GhostButton({ label, onPress, disabled, loading, icon, danger }) {
  return (
    <Pressable
      disabled={disabled || loading}
      onPress={onPress}
      style={({ pressed }) => [
        ui.ghostButton,
        danger && ui.ghostButtonDanger,
        (disabled || loading) && ui.buttonDisabled,
        pressed && ui.subtlePressed,
      ]}
    >
      {icon ? (
        <MaterialCommunityIcons name={icon} size={15} color={danger ? colors.danger : colors.text} />
      ) : null}
      <Text style={[ui.ghostButtonText, danger && ui.ghostButtonTextDanger]}>{label}</Text>
    </Pressable>
  );
}

export function IconField({ icon, label, children }) {
  return (
    <View style={ui.fieldBlock}>
      <Text style={ui.fieldLabel}>{label}</Text>
      <View style={ui.fieldWrap}>
        {icon ? <MaterialCommunityIcons name={icon} size={18} color={colors.accent} /> : null}
        <View style={ui.fieldContent}>{children}</View>
      </View>
    </View>
  );
}

export function initialsFrom(name = "EV") {
  const words = String(name)
    .split(/[\s@.]+/)
    .filter(Boolean)
    .slice(0, 2);

  return (words.map((word) => word[0]).join("") || "EV").toUpperCase();
}

export function splitAmount(value) {
  const [whole, decimals] = Number(value || 0).toFixed(2).split(".");
  return { whole, decimals };
}

export const ui = StyleSheet.create({
  headerRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: 12,
    marginBottom: 4,
  },
  headerTitle: {
    color: colors.text,
    fontSize: 26,
    fontWeight: "900",
    letterSpacing: -0.7,
  },
  headerSub: {
    color: colors.textMuted,
    marginTop: 3,
    fontSize: 12,
    fontWeight: "700",
    lineHeight: 17,
  },
  headerIcon: {
    width: 44,
    height: 44,
    borderRadius: 14,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accent,
    ...shadows.accent,
  },
  subHeaderRow: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    marginBottom: 2,
  },
  subHeaderBack: {
    width: 38,
    height: 38,
    borderRadius: 12,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.bgSoft,
    borderWidth: 1,
    borderColor: colors.borderSoft,
  },
  subHeaderBackSpacer: {
    width: 38,
  },
  subHeaderTitle: {
    color: colors.text,
    fontSize: 15,
    fontWeight: "900",
    letterSpacing: -0.3,
  },
  profileHero: {
    ...surfaces.hero,
    padding: 14,
    ...shadows.card,
  },
  profileHeroTop: {
    flexDirection: "row",
    alignItems: "center",
    gap: 12,
  },
  avatar: {
    width: 58,
    height: 58,
    borderRadius: 18,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accent,
    borderWidth: 2,
    borderColor: "rgba(255,255,255,0.14)",
  },
  avatarText: {
    color: colors.accentText,
    fontSize: 18,
    fontWeight: "900",
  },
  profileName: {
    color: colors.text,
    fontSize: 18,
    fontWeight: "900",
    letterSpacing: -0.3,
  },
  profileEmail: {
    color: colors.textMuted,
    marginTop: 2,
    fontSize: 12,
    fontWeight: "700",
  },
  accountBadge: {
    flexDirection: "row",
    alignItems: "center",
    gap: 5,
    alignSelf: "flex-start",
    marginTop: 8,
    paddingHorizontal: 10,
    paddingVertical: 5,
    borderRadius: 999,
    backgroundColor: colors.accentSoft,
    borderWidth: 1,
    borderColor: colors.borderAccent,
  },
  accountBadgeText: {
    color: colors.accent,
    fontSize: 11,
    fontWeight: "900",
  },
  heroCard: {
    ...surfaces.hero,
    paddingHorizontal: 14,
    paddingVertical: 14,
    gap: 2,
  },
  heroIconWrap: {
    width: 36,
    height: 36,
    borderRadius: 12,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accent,
    marginBottom: 8,
  },
  heroLabel: {
    ...typography.label,
    color: colors.textMuted,
    letterSpacing: 1.1,
  },
  heroAmountRow: {
    flexDirection: "row",
    alignItems: "flex-end",
    marginTop: 4,
    gap: 1,
  },
  heroAmountWhole: {
    color: colors.text,
    fontSize: 34,
    fontWeight: "900",
    letterSpacing: -1.2,
    lineHeight: 36,
  },
  heroAmountDec: {
    color: colors.accent,
    fontSize: 18,
    fontWeight: "900",
    marginBottom: 4,
  },
  heroCurrency: {
    color: colors.textMuted,
    fontSize: 12,
    fontWeight: "800",
    marginBottom: 6,
    marginLeft: 4,
  },
  heroHint: {
    color: "rgba(139,151,181,0.85)",
    fontSize: 11,
    fontWeight: "700",
    marginTop: 6,
    lineHeight: 16,
  },
  metricGrid: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: 8,
  },
  metricTile: {
    flexGrow: 1,
    flexBasis: "30%",
    minWidth: 96,
    borderRadius: layout.cardRadiusSm,
    paddingHorizontal: 10,
    paddingVertical: 10,
    backgroundColor: colors.bgSoft,
    borderWidth: 1,
    borderColor: colors.borderSoft,
    gap: 4,
  },
  metricLabel: {
    ...typography.label,
    fontSize: 10,
    color: colors.textMuted,
    letterSpacing: 0.5,
  },
  metricValue: {
    color: colors.text,
    fontSize: 15,
    fontWeight: "900",
    fontVariant: ["tabular-nums"],
  },
  metricValueDanger: {
    color: colors.danger,
  },
  chipRow: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: 8,
  },
  chip: {
    minHeight: 34,
    paddingHorizontal: 12,
    borderRadius: 999,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.bgSoft,
    borderWidth: 1,
    borderColor: colors.borderSoft,
  },
  chipActive: {
    backgroundColor: colors.accentSoft,
    borderColor: colors.borderAccent,
  },
  chipText: {
    color: colors.textMuted,
    fontSize: 12,
    fontWeight: "800",
  },
  chipTextActive: {
    color: colors.accent,
  },
  statusChip: {
    flexDirection: "row",
    alignItems: "center",
    gap: 6,
    paddingHorizontal: 10,
    paddingVertical: 6,
    borderRadius: 999,
    alignSelf: "flex-start",
  },
  statusDot: {
    width: 6,
    height: 6,
    borderRadius: 3,
  },
  dotSuccess: { backgroundColor: colors.success },
  dotWarning: { backgroundColor: colors.accent },
  dotDanger: { backgroundColor: colors.danger },
  dotInfo: { backgroundColor: colors.textMuted },
  dotNeutral: { backgroundColor: colors.textMuted },
  chipSuccess: { backgroundColor: colors.successSoft },
  chipWarning: { backgroundColor: colors.accentSoft },
  chipDanger: { backgroundColor: colors.dangerSoft },
  chipInfo: { backgroundColor: "rgba(255,255,255,0.06)" },
  chipNeutral: { backgroundColor: "rgba(255,255,255,0.05)" },
  statusChipText: {
    color: colors.text,
    fontSize: 11,
    fontWeight: "900",
  },
  sectionCard: {
    ...surfaces.card,
    borderRadius: layout.cardRadiusLg,
    padding: 14,
    gap: 12,
    ...shadows.card,
  },
  sectionHead: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: 10,
  },
  sectionTitle: {
    color: colors.text,
    fontSize: 15,
    fontWeight: "900",
    letterSpacing: -0.2,
  },
  sectionSub: {
    color: colors.textMuted,
    fontSize: 11,
    fontWeight: "700",
    marginTop: 2,
  },
  listRow: {
    borderRadius: layout.cardRadiusMd,
    padding: 12,
    backgroundColor: colors.bgSoft,
    borderWidth: 1,
    borderColor: colors.borderSoft,
    gap: 10,
  },
  listRowTop: {
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
  },
  listRowIcon: {
    width: 38,
    height: 38,
    borderRadius: 12,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accent,
  },
  listRowTitle: {
    color: colors.text,
    fontSize: 14,
    fontWeight: "900",
  },
  listRowMeta: {
    color: colors.textMuted,
    marginTop: 2,
    fontSize: 11,
    fontWeight: "700",
    lineHeight: 16,
  },
  navRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: 12,
    borderRadius: layout.cardRadiusMd,
    padding: 12,
    backgroundColor: colors.bgSoft,
    borderWidth: 1,
    borderColor: colors.borderSoft,
  },
  navRowDanger: {
    backgroundColor: colors.dangerSoft,
    borderColor: "rgba(255, 77, 103, 0.18)",
  },
  navRowIcon: {
    width: 38,
    height: 38,
    borderRadius: 12,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accent,
  },
  navRowIconDanger: {
    backgroundColor: "rgba(255, 77, 103, 0.18)",
  },
  navRowTitle: {
    color: colors.text,
    fontSize: 14,
    fontWeight: "900",
  },
  navRowTitleDanger: {
    color: colors.danger,
  },
  navRowSub: {
    color: colors.textMuted,
    marginTop: 2,
    fontSize: 11,
    fontWeight: "700",
  },
  emptyCard: {
    borderRadius: layout.cardRadiusMd,
    padding: 24,
    alignItems: "center",
    backgroundColor: colors.bgSoft,
    borderWidth: 1,
    borderColor: colors.borderSoft,
    gap: 8,
  },
  emptyIconWrap: {
    width: 48,
    height: 48,
    borderRadius: 16,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.borderSoft,
  },
  emptyTitle: {
    color: colors.text,
    fontSize: 15,
    fontWeight: "900",
  },
  emptyDetail: {
    color: colors.textMuted,
    fontSize: 12,
    fontWeight: "700",
    textAlign: "center",
    lineHeight: 18,
  },
  primaryButton: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 8,
    minHeight: layout.buttonHeight,
    borderRadius: layout.buttonRadius,
    backgroundColor: colors.accent,
    paddingHorizontal: 14,
    ...shadows.accent,
  },
  primaryButtonCompact: {
    minHeight: 40,
    borderRadius: 12,
  },
  primaryButtonText: {
    color: colors.accentText,
    fontSize: 13,
    fontWeight: "900",
  },
  ghostButton: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 8,
    minHeight: 42,
    borderRadius: layout.buttonRadius,
    backgroundColor: colors.bgSoft,
    borderWidth: 1,
    borderColor: colors.borderSoft,
    paddingHorizontal: 12,
  },
  ghostButtonDanger: {
    borderColor: "rgba(255,77,103,0.28)",
    backgroundColor: colors.dangerSoft,
  },
  ghostButtonText: {
    color: colors.text,
    fontSize: 12,
    fontWeight: "800",
  },
  ghostButtonTextDanger: {
    color: "#ff8ea0",
  },
  fieldBlock: {
    gap: 6,
  },
  fieldLabel: {
    ...typography.label,
    color: colors.textMuted,
    letterSpacing: 0.5,
  },
  fieldWrap: {
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: layout.inputRadius,
    backgroundColor: colors.bgSoft,
    paddingHorizontal: 12,
    minHeight: 48,
  },
  fieldContent: {
    flex: 1,
  },
  pressed: {
    opacity: motion.pressOpacity,
    transform: [{ scale: motion.pressScale }],
  },
  subtlePressed: {
    opacity: motion.subtlePressOpacity,
    transform: [{ scale: motion.subtlePressScale }],
  },
  buttonDisabled: {
    opacity: 0.55,
  },
});
