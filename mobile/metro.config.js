const { getDefaultConfig } = require("expo/metro-config");

const config = getDefaultConfig(__dirname);

// Turf 7.3.x publishes ESM package exports that Metro can mis-resolve on Windows
// when consumed through MapLibre. Falling back to package "main" uses the CJS
// files that are present in the package and keeps native MapLibre imports stable.
config.resolver.unstable_enablePackageExports = false;

module.exports = config;
