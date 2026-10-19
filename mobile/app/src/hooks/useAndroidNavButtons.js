import { Platform } from "react-native";
import { useSafeAreaInsets } from "react-native-safe-area-context";

const ANDROID_NAV_BUTTONS_OFFSET = 16;

export function useAndroidNavButtons() {
  const insets = useSafeAreaInsets();
  const hasAndroidNavButtons = Platform.OS === "android" && insets.bottom === 0;
  const androidNavButtonsOffset = hasAndroidNavButtons ? ANDROID_NAV_BUTTONS_OFFSET : 0;

  return {
    hasAndroidNavButtons,
    androidNavButtonsOffset,
    bottomInset: Math.max(insets.bottom, 0) + androidNavButtonsOffset,
  };
}
