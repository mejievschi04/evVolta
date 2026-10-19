import React, { useMemo, useState } from "react";
import { Alert, StyleSheet, Text, View } from "react-native";
import { AuthLayout } from "../components/ui/AuthLayout";
import { LegalAcceptance } from "../components/ui/LegalAcceptance";
import { PrimaryButton } from "../components/ui/ScreenUi";
import { useAuth } from "../context/AuthContext";
import { getApiErrorMessage } from "../utils/apiErrors";
import { colors } from "../theme";

export default function LegalGateScreen({ navigation }) {
  const { acceptLegal, legal, logout } = useAuth();
  const [acceptedLegal, setAcceptedLegal] = useState(false);
  const [loading, setLoading] = useState(false);

  const canSubmit = useMemo(() => acceptedLegal && !loading, [acceptedLegal, loading]);

  const handleAccept = async () => {
    if (!acceptedLegal) {
      Alert.alert("Confidentialitate", "Trebuie sa accepti Termenii si Politica de confidentialitate.");
      return;
    }

    try {
      setLoading(true);
      await acceptLegal();
    } catch (error) {
      Alert.alert("Confidentialitate", getApiErrorMessage(error));
    } finally {
      setLoading(false);
    }
  };

  return (
    <AuthLayout
      poweredBy="powered by Mejievski"
      subtitle={`Versiune legala ${legal?.version || "curenta"}. Continua doar dupa ce citesti documentele.`}
      title="Actualizare legala"
      footer={
        <Text style={styles.logout} onPress={() => void logout().catch(() => null)}>
          Deconectare
        </Text>
      }
    >
      <View style={styles.card}>
        <Text style={styles.copy}>
          Pentru a respecta normele de protectie a datelor, trebuie sa accepti versiunea curenta
          a Termenilor si Politicii de confidentialitate inainte de a folosi aplicatia.
        </Text>
      </View>

      <View style={styles.actions}>
        <LegalAcceptance
          checked={acceptedLegal}
          onChange={setAcceptedLegal}
          onOpenPrivacy={() => navigation.navigate("LegalDocument", { type: "privacy" })}
          onOpenTerms={() => navigation.navigate("LegalDocument", { type: "terms" })}
        />

        <PrimaryButton
          disabled={!canSubmit}
          label={loading ? "Se salveaza..." : "Accept si continua"}
          loading={loading}
          onPress={handleAccept}
        />
      </View>
    </AuthLayout>
  );
}

const styles = StyleSheet.create({
  card: {
    padding: 14,
    borderRadius: 14,
    borderWidth: 1,
    borderColor: colors.borderSoft,
    backgroundColor: "rgba(18, 24, 39, 0.72)",
  },
  copy: {
    color: colors.textSoft,
    fontSize: 13,
    fontWeight: "600",
    lineHeight: 19,
  },
  actions: {
    gap: 16,
    marginTop: 4,
  },
  logout: {
    color: colors.textMuted,
    textAlign: "center",
    fontSize: 13,
    fontWeight: "700",
    marginTop: 8,
  },
});
