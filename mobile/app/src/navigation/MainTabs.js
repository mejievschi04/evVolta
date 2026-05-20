import React, { useState } from "react";
import { createBottomTabNavigator } from "@react-navigation/bottom-tabs";
import { MaterialCommunityIcons } from "@expo/vector-icons";
import { Pressable, StatusBar, View } from "react-native";
import { SafeAreaView, useSafeAreaInsets } from "react-native-safe-area-context";
import { useAuth } from "../context/AuthContext";
import ApiStatusBanner from "../components/ApiStatusBanner";
import StationsScreen from "../screens/StationsScreen";
import ChargingScreen from "../screens/ChargingScreen";
import HistorySessionsScreen from "../screens/HistorySessionsScreen";
import InvoicesScreen from "../screens/InvoicesScreen";
import ProfileScreen from "../screens/ProfileScreen";
import { colors } from "../theme";

const Tab = createBottomTabNavigator();
const LIVE_TABS = ["Statii", "Incarcare", "Istoric", "Facturi"];

function TabIcon({ icon, color, focused }) {
  return (
    <View style={[styles.iconShell, focused ? styles.iconShellActive : null]}>
      <MaterialCommunityIcons name={icon} size={focused ? 23 : 21} color={color} />
    </View>
  );
}

export default function MainTabs() {
  const insets = useSafeAreaInsets();
  const statusBarTop = StatusBar.currentHeight || 0;
  const contentTopOffset = Math.max(insets.top, statusBarTop, 10);
  const { activeSession, sessionRedirectVersion } = useAuth();
  const initialRouteName = activeSession ? "Incarcare" : "Statii";
  const [activeTabName, setActiveTabName] = useState(initialRouteName);

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: colors.bg }} edges={["top"]}>
      {LIVE_TABS.includes(activeTabName) ? <ApiStatusBanner /> : null}
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
          headerStyle: { backgroundColor: colors.bgSoft },
          headerTintColor: colors.text,
          headerTitleStyle: { color: colors.text, fontWeight: "700" },
          headerTitleAlign: "left",
          sceneStyle: { backgroundColor: colors.bg },
          tabBarShowLabel: false,
          tabBarStyle: {
            position: "absolute",
            left: 18,
            right: 18,
            bottom: Math.max(insets.bottom, 12),
            backgroundColor: colors.card,
            borderTopWidth: 0,
            height: 68,
            paddingBottom: 8,
            paddingTop: 8,
            borderRadius: 28,
            elevation: 14,
            shadowColor: "#000",
            shadowOpacity: 0.35,
            shadowOffset: { width: 0, height: 10 },
            shadowRadius: 20,
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
              <TabIcon icon="ev-station" color={color} focused={focused} />
            ),
          }}
        />
        <Tab.Screen
          name="Istoric"
          component={HistorySessionsScreen}
          options={{
            tabBarIcon: ({ color, focused }) => <TabIcon icon="history" color={color} focused={focused} />,
          }}
        />
        <Tab.Screen
          name="Incarcare"
          component={ChargingScreen}
          options={{
            tabBarIcon: ({ focused }) => (
              <TabIcon icon="qrcode-scan" color={colors.accentText} focused={focused} />
            ),
            tabBarButton: ({ children, onPress, accessibilityState }) => (
              <Pressable
                onPress={onPress}
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
          options={{
            tabBarIcon: ({ color, focused }) => (
              <TabIcon icon="file-document-outline" color={color} focused={focused} />
            ),
          }}
        />
        <Tab.Screen
          name="Profil"
          component={ProfileScreen}
          options={{
            tabBarIcon: ({ color, focused }) => <TabIcon icon="account-circle-outline" color={color} focused={focused} />,
          }}
        />
      </Tab.Navigator>
    </SafeAreaView>
  );
}

const styles = {
  iconShell: {
    width: 44,
    height: 38,
    borderRadius: 19,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "transparent",
  },
  iconShellActive: {
    backgroundColor: colors.bgSoft,
  },
  chargeButtonWrap: {
    position: "relative",
    alignItems: "center",
    justifyContent: "center",
  },
  chargeButton: {
    width: 58,
    height: 58,
    borderRadius: 29,
    backgroundColor: colors.accent,
    alignItems: "center",
    justifyContent: "center",
    borderWidth: 4,
    borderColor: colors.card,
    marginTop: -14,
    shadowColor: "#000",
    shadowOpacity: 0.28,
    shadowOffset: { width: 0, height: 8 },
    shadowRadius: 12,
    elevation: 10,
  },
  chargeButtonActive: {
    transform: [{ scale: 1.04 }],
  },
};
