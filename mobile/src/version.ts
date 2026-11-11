import Constants from 'expo-constants';

export const APP_VERSION = Constants.expoConfig?.version ?? '0.1.8';
export const APP_BUILD = Constants.expoConfig?.android?.versionCode ?? 12;
export const APP_VERSION_LABEL = `v${APP_VERSION} (Build ${APP_BUILD})`;
