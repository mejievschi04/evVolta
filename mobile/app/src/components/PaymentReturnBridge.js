import { usePaymentReturnHandler } from "../hooks/usePaymentReturnHandler";

export default function PaymentReturnBridge() {
  usePaymentReturnHandler();
  return null;
}
