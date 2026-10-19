import { Linking, Platform } from "react-native";
import * as IntentLauncher from "expo-intent-launcher";

/**
 * Open MAIB / Stripe checkout in the system browser (Safari / Chrome app),
 * not an in-app WebView or Chrome Custom Tab when we can avoid it.
 */
export async function openCheckoutInSystemBrowser(url) {
  const checkoutUrl = String(url || "").trim();
  if (!checkoutUrl) {
    throw new Error("Link de plata lipsa.");
  }

  if (Platform.OS === "android") {
    try {
      // Opens the default browser app as a separate activity.
      await IntentLauncher.startActivityAsync("android.intent.action.VIEW", {
        data: checkoutUrl,
        flags: 268435456, // FLAG_ACTIVITY_NEW_TASK
      });
      return;
    } catch {
      // Fall through to Linking.
    }
  }

  const canOpen = await Linking.canOpenURL(checkoutUrl);
  if (!canOpen) {
    throw new Error("Nu pot deschide browserul pentru plata.");
  }

  await Linking.openURL(checkoutUrl);
}
