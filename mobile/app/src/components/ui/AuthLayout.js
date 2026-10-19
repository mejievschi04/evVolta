import React, { createContext, useContext, useRef } from "react";
import {
  Image,
  KeyboardAvoidingView,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from "react-native";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { SafeAreaView } from "react-native-safe-area-context";
import { useAndroidNavButtons } from "../../hooks/useAndroidNavButtons";
import { useKeyboardScrollToInput } from "../../hooks/useKeyboardScrollToInput";
import { keyboardAvoidingBehavior, keyboardScrollProps, keyboardVerticalOffset } from "../../utils/keyboard";
import { colors, layout, motion, shadows, typography } from "../../theme";

const brandLogo = require("../../../../assets/v-charge-logo.png");

const AuthKeyboardContext = createContext(null);

export function AuthLayout({ title, subtitle, children, footer, poweredBy }) {
  const scrollRef = useRef(null);
  const { bottomInset } = useAndroidNavButtons();
  const keyboardScroll = useKeyboardScrollToInput(scrollRef);

  return (
    <View style={styles.page}>
      <View pointerEvents="none" style={styles.glowLarge} />
      <View pointerEvents="none" style={styles.glowSmall} />
      <SafeAreaView style={styles.flex} edges={["top", "bottom"]}>
        <AuthKeyboardContext.Provider value={keyboardScroll}>
          <KeyboardAvoidingView
            style={styles.flex}
            behavior={keyboardAvoidingBehavior}
            keyboardVerticalOffset={keyboardVerticalOffset}
          >
            <ScrollView
              ref={scrollRef}
              contentContainerStyle={[
                styles.scroll,
                { paddingBottom: Math.max(bottomInset, 12) + (poweredBy ? 32 : 16) },
              ]}
              {...keyboardScrollProps}
            >
              <View style={styles.header}>
                <View style={styles.logoTile}>
                  <Image
                    accessibilityLabel="V CHARGE"
                    source={brandLogo}
                    style={styles.logo}
                    resizeMode="cover"
                  />
                </View>
                <Text style={styles.brand}>V CHARGE</Text>
                <Text style={styles.title}>{title}</Text>
                {subtitle ? <Text style={styles.subtitle}>{subtitle}</Text> : null}
              </View>

              <View style={styles.form}>{children}</View>

              {footer ? <View style={styles.footer}>{footer}</View> : null}
            </ScrollView>
          </KeyboardAvoidingView>
        </AuthKeyboardContext.Provider>

        {poweredBy ? (
          <Text style={[styles.poweredBy, { bottom: Math.max(bottomInset, 12) }]}>{poweredBy}</Text>
        ) : null}
      </SafeAreaView>
    </View>
  );
}

export function AuthField({
  label,
  icon,
  value,
  onChangeText,
  placeholder,
  trailing,
  secureTextEntry,
  keyboardType,
  autoCapitalize,
  autoComplete,
  textContentType,
  returnKeyType,
  onSubmitEditing,
  inputRef,
  scrollKey,
  onFocus,
}) {
  const keyboardScroll = useContext(AuthKeyboardContext);
  const fieldKey = scrollKey || label;

  return (
    <View
      style={styles.field}
      onLayout={keyboardScroll ? keyboardScroll.registerInputLayout(fieldKey) : undefined}
    >
      <Text style={styles.fieldLabel}>{label}</Text>
      <View style={styles.inputWrap}>
        {icon ? (
          <View style={styles.inputIcon}>
            <MaterialCommunityIcons name={icon} size={18} color={colors.accent} />
          </View>
        ) : null}
        <TextInput
          ref={inputRef}
          style={styles.input}
          value={value}
          onChangeText={onChangeText}
          onFocus={() => {
            keyboardScroll?.scrollToInput(fieldKey);
            onFocus?.();
          }}
          placeholder={placeholder}
          placeholderTextColor="rgba(139, 151, 181, 0.55)"
          secureTextEntry={secureTextEntry}
          keyboardType={keyboardType}
          autoCapitalize={autoCapitalize}
          autoCorrect={false}
          autoComplete={autoComplete}
          textContentType={textContentType}
          returnKeyType={returnKeyType}
          onSubmitEditing={onSubmitEditing}
        />
        {trailing ? <View style={styles.trailing}>{trailing}</View> : null}
      </View>
    </View>
  );
}

export function AuthTextLink({ prefix, label, icon, muted = false, onPress }) {
  return (
    <Pressable
      accessibilityRole="button"
      hitSlop={8}
      onPress={onPress}
      style={({ pressed }) => [styles.textLink, pressed && styles.pressed]}
    >
      <Text style={styles.textLinkCopy}>
        {prefix ? <Text style={styles.textLinkPrefix}>{prefix} </Text> : null}
        <Text style={muted ? styles.textLinkMuted : styles.textLinkAction}>{label}</Text>
      </Text>
      {icon ? (
        <MaterialCommunityIcons name={icon} size={16} color={muted ? colors.textMuted : colors.accent} />
      ) : null}
    </Pressable>
  );
}

export function AuthLinkButton({ label, icon, iconPosition = "right", onPress }) {
  return (
    <Pressable
      style={({ pressed }) => [styles.linkBtn, pressed && styles.pressed]}
      onPress={onPress}
    >
      {icon && iconPosition === "left" ? (
        <MaterialCommunityIcons name={icon} size={17} color={colors.accent} />
      ) : null}
      <Text style={styles.linkBtnText}>{label}</Text>
      {icon && iconPosition === "right" ? (
        <MaterialCommunityIcons name={icon} size={17} color={colors.accent} />
      ) : null}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  page: {
    flex: 1,
    backgroundColor: colors.bg,
    overflow: "hidden",
  },
  glowLarge: {
    position: "absolute",
    top: -220,
    alignSelf: "center",
    width: 520,
    height: 520,
    borderRadius: 260,
    backgroundColor: "rgba(255, 238, 0, 0.07)",
  },
  glowSmall: {
    position: "absolute",
    top: -90,
    alignSelf: "center",
    width: 260,
    height: 260,
    borderRadius: 130,
    backgroundColor: "rgba(255, 238, 0, 0.06)",
  },
  flex: {
    flex: 1,
  },
  scroll: {
    flexGrow: 1,
    justifyContent: "center",
    paddingHorizontal: 20,
    paddingTop: 16,
    gap: 22,
  },
  header: {
    alignItems: "center",
    paddingHorizontal: 4,
  },
  logoTile: {
    width: 64,
    height: 64,
    borderRadius: 20,
    overflow: "hidden",
    borderWidth: 1,
    borderColor: "rgba(255, 238, 0, 0.35)",
    backgroundColor: "#000",
    marginBottom: 14,
    ...shadows.accent,
  },
  logo: {
    width: "100%",
    height: "100%",
  },
  brand: {
    ...typography.eyebrow,
    color: colors.accent,
    fontSize: 11,
    letterSpacing: 3,
  },
  title: {
    color: colors.text,
    fontSize: 26,
    fontWeight: "900",
    letterSpacing: -0.6,
    lineHeight: 32,
    marginTop: 6,
    textAlign: "center",
  },
  subtitle: {
    color: colors.textMuted,
    fontSize: 13,
    fontWeight: "600",
    textAlign: "center",
    lineHeight: 18,
    marginTop: 4,
    maxWidth: 300,
  },
  form: {
    gap: 14,
    padding: 16,
    borderRadius: layout.cardRadiusXl,
    borderWidth: 1,
    borderColor: colors.borderSoft,
    backgroundColor: "rgba(18, 24, 39, 0.72)",
    ...shadows.card,
  },
  field: {
    gap: 6,
  },
  fieldLabel: {
    color: colors.textSoft,
    fontSize: 12,
    fontWeight: "700",
    marginLeft: 2,
  },
  inputWrap: {
    flexDirection: "row",
    alignItems: "center",
    borderWidth: 1,
    borderColor: colors.borderSoft,
    borderRadius: layout.inputRadius,
    backgroundColor: colors.bgSoft,
    paddingHorizontal: 12,
    minHeight: 50,
    gap: 10,
  },
  inputIcon: {
    width: 28,
    height: 28,
    borderRadius: 9,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.accentSoft,
  },
  input: {
    flex: 1,
    color: colors.text,
    paddingVertical: 11,
    fontSize: 16,
    fontWeight: "600",
  },
  trailing: {
    paddingLeft: 4,
  },
  footer: {
    alignItems: "center",
  },
  textLink: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 4,
    paddingVertical: 6,
  },
  textLinkCopy: {
    fontSize: 14,
    fontWeight: "700",
  },
  textLinkPrefix: {
    color: colors.textMuted,
  },
  textLinkAction: {
    color: colors.accent,
    fontWeight: "900",
  },
  textLinkMuted: {
    color: colors.textSoft,
  },
  linkBtn: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: 6,
    minHeight: 40,
    paddingHorizontal: 14,
    paddingVertical: 8,
    borderRadius: 999,
    borderWidth: 1,
    borderColor: colors.borderSoft,
    backgroundColor: "rgba(18, 24, 39, 0.55)",
  },
  linkBtnText: {
    color: colors.textSoft,
    fontSize: 13,
    fontWeight: "700",
  },
  pressed: {
    opacity: motion.pressOpacity,
    transform: [{ scale: motion.pressScale }],
  },
  poweredBy: {
    position: "absolute",
    left: 0,
    right: 0,
    bottom: 12,
    color: "rgba(139, 151, 181, 0.55)",
    fontSize: 11,
    fontWeight: "600",
    letterSpacing: 0.5,
    textAlign: "center",
  },
});
