import Constants from 'expo-constants';

export const APP_VERSION = Constants.expoConfig?.version ?? '0.1.10';
export const APP_BUILD = Constants.expoConfig?.android?.versionCode ?? 14;
export const APP_VERSION_LABEL = `v${APP_VERSION} (Build ${APP_BUILD})`;
