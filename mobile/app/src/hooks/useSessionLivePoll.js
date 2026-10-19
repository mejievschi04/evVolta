import { useCallback, useEffect, useRef, useState } from "react";
import { useIsFocused } from "@react-navigation/native";
import apiClient from "../api/client";

const DEFAULT_INTERVAL_MS = 3000;

export function useSessionLivePoll({
  session,
  setActiveSession,
  onSessionCompleted,
  intervalMs = DEFAULT_INTERVAL_MS,
}) {
  const isFocused = useIsFocused();
  const [liveStatus, setLiveStatus] = useState(null);
  const [canResume, setCanResume] = useState(false);
  const [connectorStatus, setConnectorStatus] = useState(null);
  const onCompletedRef = useRef(onSessionCompleted);
  onCompletedRef.current = onSessionCompleted;
  // O cerere poate dura mai mult decat intervalul: fara aceste garzi, un raspuns vechi
  // („inca activa”) sosit dupa cel „incheiata” reactiva sesiunea in UI.
  const inFlightRef = useRef(false);
  const completedIdRef = useRef(null);
  const currentIdRef = useRef(null);

  const sessionId = session?.id;
  currentIdRef.current = sessionId ?? null;
  const enabled = isFocused && Boolean(sessionId) && !session?.end_time;

  const pollLive = useCallback(async () => {
    if (!sessionId || inFlightRef.current || completedIdRef.current === sessionId) {
      return;
    }

    inFlightRef.current = true;
    try {
      const response = await apiClient.get(`/sessions/${sessionId}/live`);
      const data = response.data ?? {};

      if (currentIdRef.current !== sessionId || completedIdRef.current === sessionId) {
        return;
      }

      const completed = data.session_completed?.session
        ? data.session_completed
        : data.session?.end_time
          ? { session: data.session, invoice: null }
          : null;

      if (completed) {
        completedIdRef.current = sessionId;
        setActiveSession(null);
        setLiveStatus(null);
        setCanResume(false);
        setConnectorStatus(null);
        onCompletedRef.current?.({
          session: completed.session,
          invoice: completed.invoice ?? null,
        });
        return;
      }

      if (data.session) {
        setActiveSession(data.session);
      }

      if (data.live_status) {
        setLiveStatus(data.live_status);
      }

      setCanResume(Boolean(data.can_resume));
      setConnectorStatus(
        data.connector_status
          ?? data.live_status?.connector_status
          ?? null
      );
    } catch {
      // Polling errors are non-fatal; next tick retries.
    } finally {
      inFlightRef.current = false;
    }
  }, [sessionId, setActiveSession]);

  useEffect(() => {
    if (!enabled) {
      setLiveStatus(null);
      setCanResume(false);
      setConnectorStatus(null);
      return undefined;
    }

    void pollLive();
    const timer = setInterval(() => {
      void pollLive();
    }, intervalMs);

    return () => clearInterval(timer);
  }, [enabled, intervalMs, pollLive]);

  return { liveStatus, canResume, connectorStatus, refreshLive: pollLive };
}
