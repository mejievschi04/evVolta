import { useSyncExternalStore } from "react";
import { getApiStatusSnapshot, subscribe } from "../api/statusStore";

export function useApiStatus() {
  return useSyncExternalStore(subscribe, getApiStatusSnapshot, getApiStatusSnapshot);
}