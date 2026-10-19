import React, { useEffect, useRef } from "react";
import { Animated, StyleSheet, View } from "react-native";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import { colors, layout } from "../../theme";

function Bone({ style }) {
  const opacity = useRef(new Animated.Value(0.45)).current;

  useEffect(() => {
    const loop = Animated.loop(
      Animated.sequence([
        Animated.timing(opacity, { toValue: 0.9, duration: 700, useNativeDriver: true }),
        Animated.timing(opacity, { toValue: 0.4, duration: 700, useNativeDriver: true }),
      ])
    );
    loop.start();
    return () => loop.stop();
  }, [opacity]);

  return <Animated.View style={[styles.bone, style, { opacity }]} />;
}

/** Skeleton pentru liste (Istoric / Facturi / Rezervari). */
export function ListScreenSkeleton({ rows = 5 }) {
  return (
    <View style={styles.listPage}>
      <Bone style={styles.headerTitle} />
      <Bone style={styles.headerSub} />
      <Bone style={styles.summaryCard} />
      <View style={styles.chipRow}>
        <Bone style={styles.chip} />
        <Bone style={styles.chip} />
        <Bone style={styles.chip} />
      </View>
      {Array.from({ length: rows }).map((_, index) => (
        <Bone key={`row-${index}`} style={styles.listRow} />
      ))}
    </View>
  );
}

/** Skeleton pentru harta Statii (header + dock card). */
export function StationsScreenSkeleton() {
  const insets = useSafeAreaInsets();

  return (
    <View style={styles.stationsPage}>
      <View style={[styles.stationsHeader, { paddingTop: insets.top + 8 }]}>
        <Bone style={styles.avatar} />
        <View style={styles.headerCopy}>
          <Bone style={styles.headerLineSm} />
          <Bone style={styles.headerLineMd} />
        </View>
        <Bone style={styles.walletBone} />
      </View>
      <View style={styles.mapBone} />
      <View style={[styles.dock, { paddingBottom: insets.bottom + 90 }]}>
        <View style={styles.pillRow}>
          <Bone style={styles.pill} />
          <Bone style={styles.pill} />
          <Bone style={styles.pill} />
        </View>
        <Bone style={styles.stationCard} />
      </View>
    </View>
  );
}

/** Skeleton compact pentru carduri inline (ex. panel facturi). */
export function CardSkeleton({ height = 120 }) {
  return <Bone style={{ height, width: "100%", borderRadius: layout.cardRadiusMd }} />;
}

const styles = StyleSheet.create({
  bone: {
    backgroundColor: colors.cardElevated,
    borderWidth: 1,
    borderColor: colors.borderSoft,
  },
  listPage: {
    flex: 1,
    backgroundColor: colors.bg,
    paddingHorizontal: layout.pagePadding,
    paddingTop: 18,
    gap: 12,
  },
  headerTitle: {
    height: 28,
    width: "42%",
    borderRadius: 10,
  },
  headerSub: {
    height: 14,
    width: "68%",
    borderRadius: 8,
    marginBottom: 6,
  },
  summaryCard: {
    height: 110,
    borderRadius: layout.cardRadiusMd,
  },
  chipRow: {
    flexDirection: "row",
    gap: 8,
    marginBottom: 4,
  },
  chip: {
    height: 32,
    width: 78,
    borderRadius: 999,
  },
  listRow: {
    height: 88,
    borderRadius: layout.cardRadiusMd,
  },
  stationsPage: {
    flex: 1,
    backgroundColor: colors.bg,
  },
  stationsHeader: {
    position: "absolute",
    top: 0,
    left: 0,
    right: 0,
    zIndex: 2,
    paddingHorizontal: layout.pagePadding,
    flexDirection: "row",
    alignItems: "center",
    gap: 12,
  },
  avatar: {
    width: 44,
    height: 44,
    borderRadius: 22,
  },
  headerCopy: {
    flex: 1,
    gap: 8,
  },
  headerLineSm: {
    height: 12,
    width: "40%",
    borderRadius: 6,
  },
  headerLineMd: {
    height: 16,
    width: "70%",
    borderRadius: 6,
  },
  walletBone: {
    width: 88,
    height: 52,
    borderRadius: 14,
  },
  mapBone: {
    flex: 1,
    backgroundColor: colors.bgSoft,
  },
  dock: {
    position: "absolute",
    left: 0,
    right: 0,
    bottom: 0,
    paddingHorizontal: layout.pagePadding,
    gap: 10,
  },
  pillRow: {
    flexDirection: "row",
    gap: 8,
  },
  pill: {
    height: 34,
    width: 96,
    borderRadius: 999,
  },
  stationCard: {
    height: 128,
    borderRadius: layout.cardRadiusMd,
  },
});
