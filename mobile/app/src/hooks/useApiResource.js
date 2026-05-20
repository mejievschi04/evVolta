import { useCallback, useState } from "react";

export function useApiResource(fetchResource, initialData) {
  const [data, setData] = useState(initialData);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async (isRefresh = false) => {
    try {
      if (isRefresh) {
        setRefreshing(true);
      } else {
        setLoading(true);
      }
      const nextData = await fetchResource();
      setData(nextData);
      return nextData;
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [fetchResource]);

  return {
    data,
    setData,
    loading,
    refreshing,
    load,
  };
}