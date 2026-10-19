import React, { useState } from "react";
import { createBottomTabNavigator } from "@react-navigation/bottom-tabs";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { Pressable, StatusBar, StyleSheet, Text, View } from "react-native";
import { SafeAreaView, useSafeAreaInsets } from "react-native-safe-area-context";
import { useAuth } from "../context/AuthContext";
import { useAndroidNavButtons } from "../hooks/useAndroidNavButtons";
import ApiStatusBanner from "../components/ApiStatusBanner";
import StationsScreen from "../screens/StationsScreen";
import ChargingScreen from "../screens/ChargingScreen";
import HistorySessionsScreen from "../screens/HistorySessionsScreen";
import InvoicesScreen from "../screens/InvoicesScreen";
import ProfileScreen from "../screens/ProfileScreen";
import { navigate } from "./navigationRef";
import { colors, layout, shadows } from "../theme";

const Tab = createBottomTabNavigator();
const LIVE_TABS = ["Statii", "Incarcare", "Istoric", "Facturi"];

const TAB_LABELS = {
  Statii: "Statii",
  Istoric: "Istoric",
  Incarcare: "Scan",
  Facturi: "Facturi",
  Profil: "Profil",
};

function TabIcon({ icon, color, focused, label }) {
  return (
    <View style={[styles.iconShell, focused ? styles.iconShellActive : null]}>
      <MaterialCommunityIcons name={icon} size={focused ? 22 : 20} color={color} />
      {label ? (
        <Text style={[styles.tabLabel, focused && styles.tabLabelActive]} numberOfLines={1}>
          {label}
        </Text>
      ) : null}
    </View>
  );
}

export default function MainTabs() {
  const insets = useSafeAreaInsets();
  const { androidNavButtonsOffset } = useAndroidNavButtons();
  const { activeSession, sessionRedirectVersion, isAuthenticated } = useAuth();
  const initialRouteName = activeSession ? "Incarcare" : "Statii";
  const [activeTabName, setActiveTabName] = useState(initialRouteName);
  const tabBarBottom = Math.max(insets.bottom, 10) + androidNavButtonsOffset;

  const openLogin = () => {
    navigate("Login");
  };

  const guardTabPress = (e) => {
    if (isAuthenticated) {
      return;
    }
    e.preventDefault();
    openLogin();
  };

  return (
    <SafeAreaView style={styles.shell} edges={["top"]}>
      <StatusBar barStyle="light-content" />
      {LIVE_TABS.includes(activeTabName) ? <ApiStatusBanner /> : null}
      <View style={styles.tabsWrap}>
        <Tab.Navigator
        key={`tabs-${sessionRedirectVersion}`}
        initialRouteName={initialRouteName}
        screenListeners={{
          state: (event) => {
            const nextRoute = event?.data?.state?.routes?.[event.data.state.index];
            if (nextRoute?.name) {
              setActiveTabName(nextRoute.name);
            }
          },
        }}
        screenOptions={{
          headerShown: false,
          sceneStyle: { backgroundColor: colors.bg },
          tabBarShowLabel: false,
          tabBarStyle: {
            position: "absolute",
            left: 14,
            right: 14,
            bottom: tabBarBottom,
            backgroundColor: colors.glass,
            borderTopWidth: 0,
            height: layout.tabBarHeight,
            paddingBottom: 8,
            paddingTop: 6,
            borderRadius: 26,
            borderWidth: 1,
            borderColor: colors.borderSoft,
            ...shadows.float,
          },
          tabBarActiveTintColor: colors.accent,
          tabBarInactiveTintColor: colors.textMuted,
          tabBarItemStyle: {
            paddingVertical: 0,
          },
        }}
      >
        <Tab.Screen
          name="Statii"
          component={StationsScreen}
          options={{
            tabBarIcon: ({ color, focused }) => (
              <TabIcon color={color} focused={focused} icon="ev-station" label={TAB_LABELS.Statii} />
            ),
          }}
        />
        <Tab.Screen
          name="Istoric"
          component={HistorySessionsScreen}
          listeners={{
            tabPress: guardTabPress,
          }}
          options={{
            tabBarIcon: ({ color, focused }) => (
              <TabIcon color={color} focused={focused} icon="history" label={TAB_LABELS.Istoric} />
            ),
          }}
        />
        <Tab.Screen
          name="Incarcare"
          component={ChargingScreen}
          listeners={{
            tabPress: guardTabPress,
          }}
          options={{
            tabBarIcon: ({ focused }) => (
              <MaterialCommunityIcons name="qrcode-scan" size={26} color={colors.accentText} />
            ),
            tabBarButton: ({ children, onPress, accessibilityState }) => (
              <Pressable
                onPress={(event) => {
                  if (!isAuthenticated) {
                    openLogin();
                    return;
                  }
                  onPress?.(event);
                }}
                style={styles.chargeButtonWrap}
              >
                <View
                  style={[
                    styles.chargeButton,
                    accessibilityState?.selected ? styles.chargeButtonActive : null,
                  ]}
                >
                  {children}
                </View>
              </Pressable>
            ),
          }}
        />
        <Tab.Screen
          name="Facturi"
          component={InvoicesScreen}
          listeners={{
            tabPress: guardTabPress,
          }}
          options={{
            tabBarIcon: ({ color, focused }) => (
              <TabIcon color={color} focused={focused} icon="file-document-outline" label={TAB_LABELS.Facturi} />
            ),
          }}
        />
        <Tab.Screen
          name="Profil"
          component={ProfileScreen}
          options={{
            tabBarIcon: ({ color, focused }) => (
              <TabIcon color={color} focused={focused} icon="account-circle-outline" label={TAB_LABELS.Profil} />
            ),
          }}
        />
      </Tab.Navigator>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  shell: {
    flex: 1,
    backgroundColor: colors.bg,
  },
  tabsWrap: {
    flex: 1,
  },
  iconShell: {
    minWidth: 52,
    height: 44,
    borderRadius: 16,
    alignItems: "center",
    justifyContent: "center",
    gap: 2,
    paddingHorizontal: 4,
  },
  iconShellActive: {
    backgroundColor: colors.accentSoft,
  },
  tabLabel: {
    color: colors.textMuted,
    fontSize: 9,
    fontWeight: "800",
    letterSpacing: 0.2,
  },
  tabLabelActive: {
    color: colors.accent,
  },
  chargeButtonWrap: {
    position: "relative",
    alignItems: "center",
    justifyContent: "center",
  },
  chargeButton: {
    width: 60,
    height: 60,
    borderRadius: 30,
    backgroundColor: colors.accent,
    alignItems: "center",
    justifyContent: "center",
    borderWidth: 4,
    borderColor: colors.cardElevated,
    marginTop: -18,
    ...shadows.accent,
  },
  chargeButtonActive: {
    transform: [{ scale: 1.05 }],
    borderColor: colors.bg,
  },
});
