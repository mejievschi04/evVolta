import { useEffect } from "react";
import { AppState } from "react-native";

export function useAppResumeRefresh(refreshCallback) {
  useEffect(() => {
    const subscription = AppState.addEventListener("change", (state) => {
      if (state === "active") {
        void refreshCallback();
      }
    });

    return () => subscription.remove();
  }, [refreshCallback]);
}