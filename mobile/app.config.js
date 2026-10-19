const appJson = require("./app.json");

const googleIosUrlScheme =
  process.env.EXPO_PUBLIC_GOOGLE_IOS_URL_SCHEME ||
  process.env.GOOGLE_IOS_URL_SCHEME ||
  "";

const plugins = [
  ...(appJson.expo.plugins || []).filter(
    (plugin) =>
      plugin !== "@react-native-google-signin/google-signin" &&
      !(Array.isArray(plugin) && plugin[0] === "@react-native-google-signin/google-signin")
  ),
  "expo-apple-authentication",
];

if (googleIosUrlScheme) {
  plugins.push([
    "@react-native-google-signin/google-signin",
    {
      iosUrlScheme: googleIosUrlScheme,
    },
  ]);
} else {
  // Android Google Sign-In still works; iOS needs iosUrlScheme from Google Cloud Console.
  plugins.push("@react-native-google-signin/google-signin");
}

module.exports = {
  expo: {
    ...appJson.expo,
    ios: {
      ...appJson.expo.ios,
      usesAppleSignIn: true,
    },
    plugins,
    extra: {
      ...appJson.expo.extra,
      googleWebClientId: process.env.EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID || "",
      googleIosClientId: process.env.EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID || "",
      googleAndroidClientId: process.env.EXPO_PUBLIC_GOOGLE_ANDROID_CLIENT_ID || "",
    },
  },
};
