import { useSafeAreaInsets } from "react-native-safe-area-context";
import { layout } from "../theme";
import { useAndroidNavButtons } from "./useAndroidNavButtons";

export function useTabScreenBottomPadding(extra = 12) {
  const insets = useSafeAreaInsets();
  const { androidNavButtonsOffset } = useAndroidNavButtons();

  return layout.tabBarHeight + Math.max(insets.bottom, 10) + androidNavButtonsOffset + extra;
}
