export const colors = {
  bg: "#05070D",
  bgSoft: "#0C1019",
  card: "#121827",
  cardElevated: "#161E2E",
  border: "#232D42",
  borderSoft: "rgba(255, 255, 255, 0.07)",
  borderAccent: "rgba(255, 238, 0, 0.22)",
  text: "#F4F7FF",
  textMuted: "#8B97B5",
  textSoft: "#C5CEE4",
  accent: "#FFEE00",
  accentSoft: "rgba(255, 238, 0, 0.12)",
  accentGlow: "rgba(255, 238, 0, 0.06)",
  accentText: "#080B12",
  danger: "#FF4D67",
  dangerSoft: "rgba(255, 77, 103, 0.12)",
  success: "#4DFFA1",
  successSoft: "rgba(77, 255, 161, 0.12)",
  overlay: "rgba(5, 7, 13, 0.92)",
  glass: "rgba(12, 16, 25, 0.88)",
};

export const layout = {
  pagePadding: 16,
  cardRadiusSm: 14,
  cardRadiusMd: 18,
  cardRadiusLg: 22,
  cardRadiusXl: 26,
  inputRadius: 14,
  buttonRadius: 14,
  buttonHeight: 48,
  tabBarHeight: 72,
};

export const shadows = {
  card: {
    shadowColor: "#000",
    shadowOpacity: 0.28,
    shadowOffset: { width: 0, height: 10 },
    shadowRadius: 20,
    elevation: 8,
  },
  float: {
    shadowColor: "#000",
    shadowOpacity: 0.35,
    shadowOffset: { width: 0, height: 14 },
    shadowRadius: 24,
    elevation: 12,
  },
  accent: {
    shadowColor: "#FFEE00",
    shadowOpacity: 0.22,
    shadowOffset: { width: 0, height: 6 },
    shadowRadius: 14,
    elevation: 6,
  },
};

export const typography = {
  titleLg: { fontSize: 30, fontWeight: "900", letterSpacing: -0.8 },
  titleMd: { fontSize: 24, fontWeight: "900", letterSpacing: -0.5 },
  titleSm: { fontSize: 16, fontWeight: "900", letterSpacing: -0.2 },
  subtitle: { fontSize: 13, fontWeight: "700" },
  label: { fontSize: 11, fontWeight: "800", letterSpacing: 0.6, textTransform: "uppercase" },
  meta: { fontSize: 12, fontWeight: "700" },
  button: { fontSize: 14, fontWeight: "900" },
  eyebrow: { fontSize: 10, fontWeight: "900", letterSpacing: 1.6, textTransform: "uppercase" },
};

export const motion = {
  pressOpacity: 0.9,
  subtlePressOpacity: 0.82,
  pressScale: 0.98,
  subtlePressScale: 0.99,
};

/** Shared surface styles for cards and panels */
export const surfaces = {
  card: {
    backgroundColor: colors.card,
    borderWidth: 1,
    borderColor: colors.borderSoft,
    borderRadius: layout.cardRadiusMd,
  },
  hero: {
    backgroundColor: colors.cardElevated,
    borderWidth: 1,
    borderColor: colors.borderAccent,
    borderRadius: layout.cardRadiusLg,
    overflow: "hidden",
  },
  glass: {
    backgroundColor: colors.glass,
    borderWidth: 1,
    borderColor: colors.borderSoft,
    borderRadius: layout.cardRadiusLg,
    overflow: "hidden",
  },
};
