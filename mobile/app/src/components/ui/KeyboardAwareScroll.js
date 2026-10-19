import React from "react";
import { KeyboardAvoidingView, ScrollView } from "react-native";
import { useAndroidNavButtons } from "../../hooks/useAndroidNavButtons";
import { keyboardAvoidingBehavior, keyboardScrollProps, keyboardVerticalOffset } from "../../utils/keyboard";

export function KeyboardAwareScroll({
  children,
  contentContainerStyle,
  extraBottomPadding = 24,
  keyboardOffset = keyboardVerticalOffset,
  scrollRef,
  style,
  ...scrollProps
}) {
  const { bottomInset } = useAndroidNavButtons();

  return (
    <KeyboardAvoidingView
      behavior={keyboardAvoidingBehavior}
      keyboardVerticalOffset={keyboardOffset}
      style={[{ flex: 1 }, style]}
    >
      <ScrollView
        ref={scrollRef}
        {...keyboardScrollProps}
        contentContainerStyle={[{ paddingBottom: Math.max(bottomInset, 12) + extraBottomPadding }, contentContainerStyle]}
        {...scrollProps}
      >
        {children}
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
