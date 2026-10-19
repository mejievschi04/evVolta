import { useCallback, useEffect, useRef } from "react";
import { Alert, AppState, Linking } from "react-native";
import apiClient from "../api/client";
import { useAuth } from "../context/AuthContext";
import { navigate } from "../navigation/navigationRef";
import { getApiErrorMessage } from "../utils/apiErrors";
import { parseStripeReturnUrl } from "../utils/paymentReturn";

// URL-ul cu care a fost pornita aplicatia ramane disponibil toata rularea; il tratam o
// singura data, altfel o relogare reafisa „alimentare reusita” pentru o plata veche.
let initialUrlHandled = false;

// Alimentari pentru care s-a afisat deja ecranul de succes (din link sau la revenire in aplicatie).
const shownTopupIds = new Set();

// Clientul poate inchide browserul dupa plata fara sa revina prin link: la revenirea in
// aplicatie verificam alimentarile recente ramase "pending", ca soldul sa apara imediat.
const PENDING_TOPUP_WINDOW_MS = 30 * 60 * 1000;
const MAX_PENDING_CHECKS = 3;
// Lasa link-ul de intoarcere (daca exista) sa fie tratat primul.
const RESUME_CHECK_DELAY_MS = 1500;

function showTopupSuccess(topup, balance, fallbackId) {
  const topupId = String(topup?.id ?? fallbackId);
  if (shownTopupIds.has(topupId)) {
    return;
  }
  shownTopupIds.add(topupId);

  navigate("WalletTopupSuccess", {
    amount: Number(topup?.amount ?? 0),
    balance,
    currency: topup?.currency || "MDL",
    topupId: topup?.id ?? fallbackId,
    paidAt: topup?.paid_at || new Date().toISOString(),
  });
}

export function usePaymentReturnHandler() {
  const { isAuthenticated, refreshUser } = useAuth();
  const handlingRef = useRef(new Set());

  const handleUrl = useCallback(
    async (url) => {
      const parsed = parseStripeReturnUrl(url);
      if (!parsed || !isAuthenticated) {
        return;
      }

      const dedupeKey = `${parsed.status}:${parsed.walletTopupId}:${parsed.invoiceId}`;
      if (handlingRef.current.has(dedupeKey)) {
        return;
      }
      handlingRef.current.add(dedupeKey);

      try {
        if (parsed.status === "cancel") {
          Alert.alert("Plata", "Plata a fost anulata. Poti incerca din nou.");
          return;
        }

        if (parsed.walletTopupId > 0) {
          const response = await apiClient.post(
            `/wallet/topups/${parsed.walletTopupId}/verify-payment`
          );
          await refreshUser().catch(() => null);

          const topup = response.data?.topup;
          const paid = topup?.status === "paid";
          const balance = Number(response.data?.wallet_balance ?? 0);

          if (paid) {
            showTopupSuccess(topup, balance, parsed.walletTopupId);
            return;
          }

          Alert.alert(
            "Alimentare",
            response.data?.message || "Plata este inca in curs de procesare."
          );
          return;
        }

        if (parsed.invoiceId > 0) {
          const response = await apiClient.post(`/invoices/${parsed.invoiceId}/verify-payment`);
          const paid = response.data?.invoice?.status === "paid";
          Alert.alert(
            "Factura",
            paid
              ? "Plata confirmata. Factura este marcata ca platita."
              : response.data?.message || "Plata este inca in curs de procesare."
          );
        }
      } catch (error) {
        Alert.alert("Plata", getApiErrorMessage(error));
      } finally {
        setTimeout(() => handlingRef.current.delete(dedupeKey), 4000);
      }
    },
    [isAuthenticated, refreshUser]
  );

  const checkingPendingRef = useRef(false);

  const checkPendingTopups = useCallback(async () => {
    if (!isAuthenticated || checkingPendingRef.current) {
      return;
    }
    checkingPendingRef.current = true;

    try {
      const response = await apiClient.get("/wallet/topups");
      const cutoff = Date.now() - PENDING_TOPUP_WINDOW_MS;
      const pending = (Array.isArray(response.data?.topups) ? response.data.topups : [])
        .filter((item) => item?.status === "pending" && new Date(item.created_at).getTime() >= cutoff)
        .slice(0, MAX_PENDING_CHECKS);

      for (const item of pending) {
        if (shownTopupIds.has(String(item.id))) {
          continue;
        }

        const verify = await apiClient.post(`/wallet/topups/${item.id}/verify-payment`);
        const topup = verify.data?.topup;
        if (topup?.status === "paid") {
          await refreshUser().catch(() => null);
          showTopupSuccess(topup, Number(verify.data?.wallet_balance ?? 0), item.id);
          return;
        }
      }
    } catch {
      // Verificare de fundal: reconcilierea de pe server ramane plasa de siguranta.
    } finally {
      checkingPendingRef.current = false;
    }
  }, [isAuthenticated, refreshUser]);

  useEffect(() => {
    if (!isAuthenticated) {
      return undefined;
    }

    let timer = null;
    const subscription = AppState.addEventListener("change", (nextState) => {
      if (nextState !== "active") {
        return;
      }
      clearTimeout(timer);
      timer = setTimeout(() => {
        void checkPendingTopups();
      }, RESUME_CHECK_DELAY_MS);
    });

    return () => {
      clearTimeout(timer);
      subscription.remove();
    };
  }, [checkPendingTopups, isAuthenticated]);

  useEffect(() => {
    if (!isAuthenticated) {
      return undefined;
    }

    const onUrl = ({ url }) => {
      void handleUrl(url);
    };

    const subscription = Linking.addEventListener("url", onUrl);
    if (!initialUrlHandled) {
      void Linking.getInitialURL().then((url) => {
        if (url && !initialUrlHandled) {
          initialUrlHandled = true;
          void handleUrl(url);
        }
      });
    }

    return () => subscription.remove();
  }, [handleUrl, isAuthenticated]);
}
