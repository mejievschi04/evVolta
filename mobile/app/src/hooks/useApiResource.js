import { useCallback, useRef, useState } from "react";

/**
 * @param {() => Promise<any>} fetchResource
 * @param {any} initialData
 * @returns {{ data: any, setData: Function, loading: boolean, refreshing: boolean, error: string, load: Function }}
 *
 * load(mode):
 * - omit / false / "auto": primul load arată spinner; următoarele sunt silent (fără flicker de layout)
 * - true / "pull": pull-to-refresh (indicator RefreshControl)
 * - "silent": reîncarcă fără niciun indicator
 */
export function useApiResource(fetchResource, initialData) {
  const [data, setData] = useState(initialData);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState("");
  const hasLoadedRef = useRef(false);

  const load = useCallback(
    async (mode = "auto") => {
      const normalized =
        mode === true
          ? "pull"
          : mode === false || mode == null || mode === "auto"
            ? hasLoadedRef.current
              ? "silent"
              : "initial"
            : mode;

      try {
        if (normalized === "initial") {
          setLoading(true);
        } else if (normalized === "pull") {
          setRefreshing(true);
        }

        const nextData = await fetchResource();
        setData(nextData);
        setError("");
        hasLoadedRef.current = true;
        return nextData;
      } catch (loadError) {
        const message =
          loadError?.response?.data?.message || loadError?.message || "Nu am putut incarca datele.";
        setError(message);
      } finally {
        setLoading(false);
        setRefreshing(false);
      }
    },
    [fetchResource]
  );

  return {
    data,
    setData,
    loading,
    refreshing,
    error,
    load,
  };
}
