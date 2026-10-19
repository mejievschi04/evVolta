import { useCallback, useRef } from "react";
import { Platform } from "react-native";

export function useKeyboardScrollToInput(scrollRef, { offset = 12, delay } = {}) {
  const positions = useRef({});
  const scrollDelay = delay ?? (Platform.OS === "ios" ? 80 : 120);

  const registerInputLayout = useCallback((key) => {
    return (event) => {
      positions.current[key] = event.nativeEvent.layout.y;
    };
  }, []);

  const scrollToInput = useCallback(
    (key) => {
      const y = positions.current[key];
      if (y == null) {
        return;
      }

      setTimeout(() => {
        scrollRef.current?.scrollTo({
          y: Math.max(0, y - offset),
          animated: true,
        });
      }, scrollDelay);
    },
    [offset, scrollDelay, scrollRef]
  );

  return { registerInputLayout, scrollToInput };
}
