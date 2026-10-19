import React, { useRef } from "react";
import { Alert, StyleSheet, Text, TextInput, View } from "react-native";
import { useNavigation } from "@react-navigation/native";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import { File, Paths } from "expo-file-system";
import * as Sharing from "expo-sharing";
import {
  GhostButton,
  IconField,
  NavRow,
  PrimaryButton,
  SectionCard,
  SubScreenHeader,
} from "../components/ui/ScreenUi";
import { KeyboardAwareScroll } from "../components/ui/KeyboardAwareScroll";
import { useAuth } from "../context/AuthContext";
import { useKeyboardScrollToInput } from "../hooks/useKeyboardScrollToInput";
import { getApiErrorMessage } from "../utils/apiErrors";
import { accountTypeLabel } from "../utils/accountType";
import apiClient from "../api/client";
import { colors, layout } from "../theme";

function editableName(value) {
  const name = String(value || "");
  return name.includes("@") ? "" : name;
}

export default function ProfileSettingsScreen() {
  const navigation = useNavigation();
  const insets = useSafeAreaInsets();
  const scrollRef = useRef(null);
  const { registerInputLayout, scrollToInput } = useKeyboardScrollToInput(scrollRef);
  const { user, updateProfile, logout, deleteAccount } = useAuth();
  const requiresPasswordToDelete = user?.auth_providers?.requires_password_to_delete !== false;
  // Conturile vechi aveau emailul in loc de nume: campul porneste gol ca sa fie completat.
  const [name, setName] = React.useState(editableName(user?.name));
  const [phone, setPhone] = React.useState(user?.phone || "");
  const [deletePassword, setDeletePassword] = React.useState("");
  const [loading, setLoading] = React.useState(false);
  const [deleteLoading, setDeleteLoading] = React.useState(false);
  const [exportLoading, setExportLoading] = React.useState(false);

  React.useEffect(() => {
    setName(editableName(user?.name));
    setPhone(user?.phone || "");
  }, [user?.name, user?.phone]);

  const canSave = React.useMemo(() => Boolean(name.trim()) && !loading, [name, loading]);
  const accountTypeLabelText = accountTypeLabel(user?.account_type);

  const handleSave = async () => {
    const trimmedName = name.trim();
    if (!trimmedName || trimmedName.includes("@")) {
      Alert.alert("Setari cont", "Completeaza numele si prenumele.");
      return;
    }

    try {
      setLoading(true);
      await updateProfile({
        name: trimmedName,
        phone: phone.trim() || null,
      });
      Alert.alert("Setari cont", "Datele au fost actualizate.");
    } catch (error) {
      Alert.alert("Setari cont", getApiErrorMessage(error));
    } finally {
      setLoading(false);
    }
  };

  const handleLogout = () => {
    Alert.alert("Deconectare", "Sigur vrei sa te deconectezi?", [
      { text: "Anuleaza", style: "cancel" },
      {
        text: "Deconectare",
        style: "destructive",
        onPress: () => {
          void logout().catch(() => null);
        },
      },
    ]);
  };

  const handleDeleteAccount = () => {
    const trimmedPassword = deletePassword.trim();

    if (requiresPasswordToDelete && !trimmedPassword) {
      Alert.alert("Stergere cont", "Introdu parola pentru confirmare.");
      return;
    }

    Alert.alert(
      "Stergere cont",
      "Actiunea este permanenta. Datele de identificare vor fi anonimizate. Evidentele fiscale pot fi pastrate anonim pe perioada legala. Exporta datele inainte daca ai nevoie.",
      [
        { text: "Anuleaza", style: "cancel" },
        {
          text: "Sterge contul",
          style: "destructive",
          onPress: () => {
            void (async () => {
              try {
                setDeleteLoading(true);
                if (requiresPasswordToDelete) {
                  await deleteAccount(trimmedPassword);
                } else {
                  await deleteAccount({ confirm_delete: true });
                }
              } catch (error) {
                Alert.alert("Stergere cont", getApiErrorMessage(error));
              } finally {
                setDeleteLoading(false);
              }
            })();
          },
        },
      ]
    );
  };

  const handleExportData = async () => {
    try {
      setExportLoading(true);
      const response = await apiClient.get("/me/privacy-export");
      const payload = response.data ?? {};
      const fileName = `v-charge-privacy-export-${user?.id ?? "me"}.json`;
      const file = new File(Paths.cache, fileName);
      file.write(JSON.stringify(payload, null, 2));
      if (!(await Sharing.isAvailableAsync())) {
        Alert.alert("Export date", "Fisierul a fost salvat local, dar partajarea nu este disponibila pe acest dispozitiv.");
        return;
      }
      await Sharing.shareAsync(file.uri, {
        dialogTitle: "Export date personale V CHARGE",
      });
    } catch (error) {
      Alert.alert("Export date", getApiErrorMessage(error));
    } finally {
      setExportLoading(false);
    }
  };

  return (
    <View style={[styles.page, { paddingTop: insets.top }]}>
      <SubScreenHeader onBack={() => navigation.goBack()} title="Setari cont" />
      <KeyboardAwareScroll contentContainerStyle={styles.content} scrollRef={scrollRef}>
        <SectionCard subtitle="Informatii afisate in aplicatie" title="Date personale">
          <IconField icon="email-outline" label="Email">
            <Text style={styles.readonlyText}>{user?.email || "-"}</Text>
          </IconField>

          <View onLayout={registerInputLayout("name")}>
            <IconField icon="account-outline" label="Nume">
              <TextInput
                onChangeText={setName}
                onFocus={() => scrollToInput("name")}
                placeholder="Nume complet"
                placeholderTextColor={colors.textMuted}
                style={styles.input}
                value={name}
              />
            </IconField>
          </View>

          <View onLayout={registerInputLayout("phone")}>
            <IconField icon="phone-outline" label="Telefon">
              <TextInput
                keyboardType="phone-pad"
                onChangeText={setPhone}
                onFocus={() => scrollToInput("phone")}
                placeholder="+373 ..."
                placeholderTextColor={colors.textMuted}
                style={styles.input}
                value={phone}
              />
            </IconField>
          </View>

          <PrimaryButton
            disabled={!canSave}
            label="Salveaza modificarile"
            loading={loading}
            onPress={handleSave}
          />
        </SectionCard>

        <SectionCard subtitle="Tip cont si acces aplicatie" title="Cont">
          <View style={styles.accountRow}>
            <Text style={styles.accountLabel}>Tip cont</Text>
            <Text style={styles.accountValue}>{accountTypeLabelText}</Text>
          </View>
          <View style={styles.accountRow}>
            <Text style={styles.accountLabel}>Moneda</Text>
            <Text style={styles.accountValue}>{user?.currency || "MDL"}</Text>
          </View>
        </SectionCard>

        <SectionCard subtitle="Drepturile tale GDPR: acces la date si documente legale" title="Confidentialitate">
          <NavRow
            icon="file-document-outline"
            subtitle="Vizualizeaza versiunea curenta"
            title="Termeni si conditii"
            onPress={() => navigation.navigate("LegalDocument", { type: "terms" })}
          />
          <NavRow
            icon="shield-account-outline"
            subtitle="Cum prelucram datele tale"
            title="Politica de confidentialitate"
            onPress={() => navigation.navigate("LegalDocument", { type: "privacy" })}
          />
          <GhostButton
            icon="download-outline"
            label={exportLoading ? "Se pregateste exportul..." : "Exporta datele mele (JSON)"}
            loading={exportLoading}
            onPress={handleExportData}
          />
        </SectionCard>

        <SectionCard subtitle="Inchide sesiunea curenta pe acest dispozitiv" title="Sesiune">
          <NavRow danger icon="logout" title="Deconectare" onPress={handleLogout} />
        </SectionCard>

        <SectionCard
          subtitle="Sterge definitiv contul. Soldul trebuie sa fie zero si sa nu existe o incarcare activa."
          title="Zona periculoasa"
        >
          {requiresPasswordToDelete ? (
            <View onLayout={registerInputLayout("delete_password")}>
              <IconField icon="lock-outline" label="Parola cont">
                <TextInput
                  autoCapitalize="none"
                  autoCorrect={false}
                  onChangeText={setDeletePassword}
                  onFocus={() => scrollToInput("delete_password")}
                  placeholder="Confirma cu parola"
                  placeholderTextColor={colors.textMuted}
                  secureTextEntry
                  style={styles.input}
                  value={deletePassword}
                />
              </IconField>
            </View>
          ) : null}
          <GhostButton
            danger
            disabled={deleteLoading || (requiresPasswordToDelete && !deletePassword.trim())}
            icon="account-remove-outline"
            label={deleteLoading ? "Se sterge..." : "Sterge contul definitiv"}
            loading={deleteLoading}
            onPress={handleDeleteAccount}
          />
        </SectionCard>

        <GhostButton icon="arrow-left" label="Inapoi la profil" onPress={() => navigation.goBack()} />
      </KeyboardAwareScroll>
    </View>
  );
}

const styles = StyleSheet.create({
  page: {
    flex: 1,
    backgroundColor: colors.bg,
  },
  content: {
    padding: layout.pagePadding,
    gap: 14,
  },
  input: {
    color: colors.text,
    fontSize: 14,
    fontWeight: "700",
    paddingVertical: 2,
  },
  readonlyText: {
    color: colors.text,
    fontSize: 14,
    fontWeight: "700",
    paddingVertical: 2,
  },
  accountRow: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    gap: 12,
    paddingVertical: 4,
  },
  accountLabel: {
    color: colors.textMuted,
    fontSize: 12,
    fontWeight: "700",
  },
  accountValue: {
    color: colors.text,
    fontSize: 13,
    fontWeight: "900",
    textAlign: "right",
    flex: 1,
  },
});
