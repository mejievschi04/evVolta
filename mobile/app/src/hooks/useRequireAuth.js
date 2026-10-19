import { useCallback } from "react";
import { Alert } from "react-native";
import { useAuth } from "../context/AuthContext";
import { navigate } from "../navigation/navigationRef";

/**
 * Ruleaza `action` doar daca userul e autentificat; altfel cere login.
 * @returns {(action?: () => void) => boolean}
 */
export function useRequireAuth(message = "Pentru aceasta actiune trebuie sa te autentifici.") {
  const { isAuthenticated } = useAuth();

  return useCallback(
    (action) => {
      if (isAuthenticated) {
        if (typeof action === "function") {
          action();
        }
        return true;
      }

      Alert.alert("Autentificare necesara", message, [
        { text: "Anuleaza", style: "cancel" },
        {
          text: "Autentifica-te",
          onPress: () => navigate("Login"),
        },
      ]);
      return false;
    },
    [isAuthenticated, message]
  );
}
