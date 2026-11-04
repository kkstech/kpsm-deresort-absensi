const { withAppBuildGradle } = require('@expo/config-plugins');

module.exports = function withReleaseSigning(config) {
  return withAppBuildGradle(config, (mod) => {
    if (mod.modResults.language !== 'groovy') {
      throw new Error('Android release signing plugin expects Groovy build.gradle.');
    }

    let gradle = mod.modResults.contents;
    const signingConfigs = '    signingConfigs {\n';
    if (!gradle.includes(signingConfigs)) {
      throw new Error('Could not find Android signingConfigs in app/build.gradle.');
    }
    gradle = gradle.replace(signingConfigs, `${signingConfigs}        release {\n            def keystorePath = System.getenv('ANDROID_KEYSTORE_PATH')\n            if (!keystorePath) throw new GradleException('ANDROID_KEYSTORE_PATH is required for release builds')\n            storeFile file(keystorePath)\n            storePassword System.getenv('ANDROID_KEYSTORE_PASSWORD')\n            keyAlias System.getenv('ANDROID_KEY_ALIAS')\n            keyPassword System.getenv('ANDROID_KEY_PASSWORD')\n        }\n`);

    const buildTypes = gradle.indexOf('    buildTypes {');
    const release = gradle.indexOf('        release {', buildTypes);
    const debugSigning = gradle.indexOf('signingConfig signingConfigs.debug', release);
    if (buildTypes < 0 || release < 0 || debugSigning < 0) {
      throw new Error('Could not configure Android release signing.');
    }
    gradle = `${gradle.slice(0, debugSigning)}signingConfig signingConfigs.release${gradle.slice(debugSigning + 'signingConfig signingConfigs.debug'.length)}`;
    mod.modResults.contents = gradle;
    return mod;
  });
};
