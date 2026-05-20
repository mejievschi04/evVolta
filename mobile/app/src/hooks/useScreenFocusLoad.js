import { useCallback } from "react";
import { useFocusEffect } from "@react-navigation/native";

export function useScreenFocusLoad(loadCallback) {
  useFocusEffect(
    useCallback(() => {
      void loadCallback();
    }, [loadCallback])
  );
}