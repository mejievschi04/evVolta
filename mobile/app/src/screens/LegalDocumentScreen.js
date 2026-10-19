import React, { useEffect, useState } from "react";
import { ActivityIndicator, StyleSheet, View } from "react-native";
import { WebView } from "react-native-webview";
import { useNavigation, useRoute } from "@react-navigation/native";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import { SubScreenHeader } from "../components/ui/ScreenUi";
import apiClient from "../api/client";
import { resolveLegalPageUrl } from "../utils/legal";
import { colors } from "../theme";

export default function LegalDocumentScreen() {
  const navigation = useNavigation();
  const route = useRoute();
  const insets = useSafeAreaInsets();
  const documentType = route.params?.type === "privacy" ? "privacy" : "terms";
  const [title, setTitle] = useState(documentType === "privacy" ? "Politica de confidentialitate" : "Termeni si conditii");
  const sourceUrl = resolveLegalPageUrl(documentType);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;

    void apiClient
      .get("/legal", { _skipAuthRefresh: true })
      .then((response) => {
        if (cancelled) {
          return;
        }

        const config = response.data || {};
        const document = documentType === "privacy" ? config.privacy : config.terms;
        if (document?.title) {
          setTitle(document.title);
        }
      })
      .catch(() => null);

    return () => {
      cancelled = true;
    };
  }, [documentType]);

  return (
    <View style={[styles.page, { paddingTop: insets.top }]}>
      <SubScreenHeader onBack={() => navigation.goBack()} title={title} />
      <View style={styles.content}>
        {loading ? (
          <View style={styles.loader}>
            <ActivityIndicator color={colors.accent} />
          </View>
        ) : null}
        <WebView
          onLoadEnd={() => setLoading(false)}
          originWhitelist={["*"]}
          source={{ uri: sourceUrl }}
          style={styles.webview}
        />
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  page: {
    flex: 1,
    backgroundColor: colors.bg,
  },
  content: {
    flex: 1,
  },
  webview: {
    flex: 1,
    backgroundColor: colors.bg,
  },
  loader: {
    ...StyleSheet.absoluteFillObject,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.bg,
    zIndex: 2,
  },
});
