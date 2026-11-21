// Expo SDK 52+ mengonfigurasi Metro otomatis untuk monorepo (watchFolders, nodeModulesPaths).
// Jangan tambahkan watchFolders manual: dokumentasi Expo menyarankan menghapusnya.
// https://docs.expo.dev/guides/monorepos/
const { getDefaultConfig } = require('expo/metro-config');

module.exports = getDefaultConfig(__dirname);
