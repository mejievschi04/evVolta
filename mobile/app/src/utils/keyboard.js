import { Platform } from "react-native";

export const keyboardAvoidingBehavior = Platform.OS === "ios" ? "padding" : "height";

export const keyboardVerticalOffset = Platform.OS === "ios" ? 4 : 0;

export const keyboardScrollProps = {
  keyboardShouldPersistTaps: "handled",
  keyboardDismissMode: "on-drag",
  automaticallyAdjustKeyboardInsets: true,
  showsVerticalScrollIndicator: false,
};
