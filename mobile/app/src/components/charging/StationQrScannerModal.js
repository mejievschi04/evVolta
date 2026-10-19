import React, { useCallback, useEffect, useRef, useState } from "react";
import {
  ActivityIndicator,
  Modal,
  Pressable,
  StyleSheet,
  Text,
  View,
} from "react-native";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { CameraView, useCameraPermissions } from "expo-camera";
import { colors } from "../../theme";

export default function StationQrScannerModal({
  visible,
  onClose,
  onCodeScanned,
  resolving = false,
}) {
  const [permission, requestPermission] = useCameraPermissions();
  const [errorMessage, setErrorMessage] = useState("");
  const handledRef = useRef(false);

  useEffect(() => {
    if (visible) {
      handledRef.current = false;
      setErrorMessage("");
    }
  }, [visible]);

  const handleBarcodeScanned = useCallback(
    async ({ data }) => {
      const code = String(data || "").trim();
      if (!code || handledRef.current || resolving) {
        return;
      }

      handledRef.current = true;
      setErrorMessage("");

      try {
        await onCodeScanned(code);
      } catch (error) {
        handledRef.current = false;
        setErrorMessage(error?.message || "Codul QR nu a putut fi procesat.");
      }
    },
    [onCodeScanned, resolving]
  );

  const requestCameraAccess = async () => {
    const result = await requestPermission();
    if (!result?.granted) {
      setErrorMessage("Accesul la camera este necesar pentru scanare.");
    }
  };

  return (
    <Modal animationType="slide" onRequestClose={onClose} transparent visible={visible}>
      <View style={styles.backdrop}>
        <View style={styles.sheet}>
          <View style={styles.header}>
            <View>
              <Text style={styles.title}>Scaneaza QR statie</Text>
              <Text style={styles.subtitle}>Indreapta camera spre codul de pe statie.</Text>
            </View>
            <Pressable
              accessibilityLabel="Inchide scannerul QR"
              onPress={onClose}
              style={({ pressed }) => [styles.closeBtn, pressed && styles.pressed]}
            >
              <MaterialCommunityIcons color={colors.text} name="close" size={22} />
            </Pressable>
          </View>

          <View style={styles.cameraShell}>
            {!permission ? (
              <View style={styles.centerState}>
                <ActivityIndicator color={colors.accent} />
              </View>
            ) : !permission.granted ? (
              <View style={styles.centerState}>
                <MaterialCommunityIcons color={colors.accent} name="camera-off" size={36} />
                <Text style={styles.stateText}>Permite accesul la camera pentru scanare.</Text>
                <Pressable
                  onPress={() => {
                    void requestCameraAccess();
                  }}
                  style={({ pressed }) => [styles.permissionBtn, pressed && styles.pressed]}
                >
                  <Text style={styles.permissionBtnText}>Permite camera</Text>
                </Pressable>
              </View>
            ) : (
              <CameraView
                barcodeScannerSettings={{
                  barcodeTypes: ["qr"],
                }}
                facing="back"
                onBarcodeScanned={resolving ? undefined : handleBarcodeScanned}
                style={styles.camera}
              />
            )}

            <View pointerEvents="none" style={styles.frame}>
              <View style={styles.frameCornerTopLeft} />
              <View style={styles.frameCornerTopRight} />
              <View style={styles.frameCornerBottomLeft} />
              <View style={styles.frameCornerBottomRight} />
            </View>

            {resolving ? (
              <View style={styles.overlay}>
                <ActivityIndicator color={colors.accentText} size="large" />
                <Text style={styles.overlayText}>Identific statia...</Text>
              </View>
            ) : null}
          </View>

          {errorMessage ? <Text style={styles.errorText}>{errorMessage}</Text> : null}

          <Text style={styles.hint}>
            Daca scanarea nu functioneaza, foloseste modul test din ecranul anterior.
          </Text>
        </View>
      </View>
    </Modal>
  );
}

const frameColor = colors.accent;

const styles = StyleSheet.create({
  backdrop: {
    flex: 1,
    backgroundColor: "rgba(5, 7, 13, 0.92)",
    justifyContent: "flex-end",
  },
  sheet: {
    backgroundColor: colors.bgSoft,
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
    borderWidth: 1,
    borderColor: colors.borderSoft,
    paddingHorizontal: 16,
    paddingTop: 16,
    paddingBottom: 28,
    gap: 12,
  },
  header: {
    alignItems: "flex-start",
    flexDirection: "row",
    gap: 12,
    justifyContent: "space-between",
  },
  title: {
    color: colors.text,
    fontSize: 20,
    fontWeight: "800",
  },
  subtitle: {
    color: colors.textMuted,
    fontSize: 13,
    fontWeight: "600",
    lineHeight: 18,
    marginTop: 4,
  },
  closeBtn: {
    alignItems: "center",
    backgroundColor: colors.card,
    borderColor: colors.borderSoft,
    borderRadius: 12,
    borderWidth: 1,
    height: 40,
    justifyContent: "center",
    width: 40,
  },
  cameraShell: {
    aspectRatio: 0.82,
    backgroundColor: colors.card,
    borderColor: colors.borderSoft,
    borderRadius: 20,
    borderWidth: 1,
    overflow: "hidden",
    position: "relative",
  },
  camera: {
    flex: 1,
  },
  centerState: {
    alignItems: "center",
    flex: 1,
    gap: 12,
    justifyContent: "center",
    paddingHorizontal: 24,
  },
  stateText: {
    color: colors.textMuted,
    fontSize: 14,
    fontWeight: "600",
    textAlign: "center",
  },
  permissionBtn: {
    backgroundColor: colors.accent,
    borderRadius: 12,
    marginTop: 4,
    paddingHorizontal: 16,
    paddingVertical: 10,
  },
  permissionBtnText: {
    color: colors.accentText,
    fontSize: 14,
    fontWeight: "800",
  },
  frame: {
    ...StyleSheet.absoluteFillObject,
    alignItems: "center",
    justifyContent: "center",
  },
  frameCornerTopLeft: {
    borderColor: frameColor,
    borderLeftWidth: 3,
    borderTopWidth: 3,
    height: 42,
    left: "14%",
    position: "absolute",
    top: "18%",
    width: 42,
  },
  frameCornerTopRight: {
    borderColor: frameColor,
    borderRightWidth: 3,
    borderTopWidth: 3,
    height: 42,
    position: "absolute",
    right: "14%",
    top: "18%",
    width: 42,
  },
  frameCornerBottomLeft: {
    borderBottomWidth: 3,
    borderColor: frameColor,
    borderLeftWidth: 3,
    bottom: "18%",
    height: 42,
    left: "14%",
    position: "absolute",
    width: 42,
  },
  frameCornerBottomRight: {
    borderBottomWidth: 3,
    borderColor: frameColor,
    borderRightWidth: 3,
    bottom: "18%",
    height: 42,
    position: "absolute",
    right: "14%",
    width: 42,
  },
  overlay: {
    ...StyleSheet.absoluteFillObject,
    alignItems: "center",
    backgroundColor: "rgba(5, 7, 13, 0.72)",
    gap: 10,
    justifyContent: "center",
  },
  overlayText: {
    color: colors.accentText,
    fontSize: 14,
    fontWeight: "700",
  },
  errorText: {
    color: "#ff8f8f",
    fontSize: 13,
    fontWeight: "600",
    lineHeight: 18,
  },
  hint: {
    color: colors.textMuted,
    fontSize: 12,
    fontWeight: "600",
    lineHeight: 17,
  },
  pressed: {
    opacity: 0.86,
  },
});
